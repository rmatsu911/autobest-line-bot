<?php
/**
 * LINE Webhook 受け口。
 *
 * 処理の順番に意味がある。
 *   1) 生ボディを読む      … 署名は「受け取ったバイト列そのもの」に対して計算されている
 *   2) 署名を検証する      … ここを通るまでDBに触らない・返信しない
 *   3) 先に200を返す       … LINEは応答が遅いとタイムアウト扱いにして再送してくる
 *   4) それからイベント処理 … 外部API・DB更新はクライアントとの接続を切った後で行う
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

use App\Config;
use App\Logger;
use App\Signature;
use App\WebhookHandler;

// -----------------------------------------------------------------------------
// 事前チェック
// -----------------------------------------------------------------------------

// Webhook は必ず POST。GET で叩かれたときに中身を動かさないための門番。
// （LINE Developers の「検証」ボタンも POST を投げる）
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

// php://input は生のリクエストボディ。
// json_decode → json_encode し直したものを署名計算に使ってはいけない。
// キーの順序・スラッシュのエスケープ・空白が変わり、必ず不一致になる。
$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $rawBody = '';
}

$signature = Signature::headerFromRequest();

// -----------------------------------------------------------------------------
// 署名検証
//
// このURLは誰でも叩ける。「LINEから来た」ことを保証するのは署名だけなので、
// 不一致なら中身を一切見ずに 403 で終える。
// 攻撃者に手掛かりを与えないよう、失敗理由は本文に書かずログにだけ残す。
// -----------------------------------------------------------------------------
if (!Signature::isValid($rawBody, $signature, Config::get('LINE_CHANNEL_SECRET'))) {
    Logger::warning('署名検証に失敗しました', [
        'ip'         => $_SERVER['REMOTE_ADDR'] ?? '-',
        'has_header' => $signature !== '',
        'length'     => strlen($rawBody),
    ]);
    http_response_code(403);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload) || !isset($payload['events']) || !is_array($payload['events'])) {
    // 署名は正しいので LINE 由来だが、こちらが解釈できない形。
    // 200 を返さないと再送され続けるため、200 にしてログだけ残す。
    Logger::warning('Webhookの本文を解釈できませんでした', ['body' => mb_substr($rawBody, 0, 300)]);
    respondOkAndDetach();
    exit;
}

// -----------------------------------------------------------------------------
// 先に200を返して接続を切る
// -----------------------------------------------------------------------------
respondOkAndDetach();

// -----------------------------------------------------------------------------
// ここから先はユーザーとの接続が切れた状態で動く。
// 画面に出す相手がいないので、例外は必ず捕まえてログへ送る。
// -----------------------------------------------------------------------------
try {
    (new WebhookHandler())->handleEvents($payload['events']);
} catch (Throwable $e) {
    Logger::error('Webhook処理で例外が発生しました', [
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
}

/**
 * 200 を返してレスポンスを確定させ、以降の処理をバックグラウンドで続ける。
 *
 * LINE は Webhook の応答が遅いと失敗とみなして再送する（目安は数秒）。
 * プロフィール取得やDB更新を挟むと簡単に超えるので、先にここで打ち切る。
 *
 * php-fpm なら fastcgi_finish_request() が使えるが、共用サーバーでは
 * CGI/suPHP で動いていて関数が無いこともある。その場合は
 * Content-Length と Connection: close を明示して出力を flush することで、
 * クライアント（LINE側）に「本文は届き切った」と判断させる。
 */
function respondOkAndDetach(): void
{
    // 途中でスクリプトが中断されても後処理を続ける。
    ignore_user_abort(true);

    $body = 'OK';

    // 既存の出力バッファを全部捨ててから本文を確定させる。
    // 残っていると Content-Length と実際の長さがずれて、
    // 「レスポンスが終わらない」状態になる。
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Length: ' . strlen($body));
        header('Connection: close');
    }

    echo $body;
    flush();

    if (function_exists('fastcgi_finish_request')) {
        // php-fpm 経路。ここでレスポンスが確定し、以降はPHPだけが動き続ける。
        fastcgi_finish_request();
    }

    // 接続を切った後の処理にも上限は必要（暴走したプロセスが残らないように）。
    // 共用サーバーでは set_time_limit が無効化されていることがあるので @ を付ける。
    @set_time_limit(30);
}

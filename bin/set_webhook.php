<?php
/**
 * LINE公式アカウントのWebhook URLを差し替える（CLI専用）。
 *
 *   php bin/set_webhook.php              … 現在の設定を表示するだけ
 *   php bin/set_webhook.php --apply      … .env の BOT_BASE_URL を元に差し替える
 *   php bin/set_webhook.php --apply --url=https://example.jp/webhook.php
 *   php bin/set_webhook.php --test       … 差し替えずに疎通確認だけ行う
 *
 * LINE Developers の画面で行う操作を API から実行する。
 *
 * 【重要】このスクリプトは .env のトークンが指すアカウントを書き換える。
 * 実行前に必ず Bot名を表示し、確認を求める。既存の友だちがいるアカウントの
 * Webhook を差し替えると、そのアカウントの利用者にこのbotが応答し始めるため。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';

use App\Config;
use App\LineClient;

$args   = array_slice($argv, 1);
$apply  = in_array('--apply', $args, true);
$test   = in_array('--test', $args, true);
$yes    = in_array('--yes', $args, true);

$urlArg = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--url=')) {
        $urlArg = substr($a, 6);
    }
}

$target = $urlArg ?? rtrim(Config::get('BOT_BASE_URL', ''), '/') . '/webhook.php';

if ($target === '/webhook.php' || $target === '') {
    fwrite(STDERR, ".env の BOT_BASE_URL が未設定です。--url= で直接指定してください。\n");
    exit(1);
}
if (!str_starts_with($target, 'https://')) {
    // LINEはhttpsのWebhookしか受け付けない。
    fwrite(STDERR, "Webhook URL は https:// で始まる必要があります: {$target}\n");
    exit(1);
}

$line = new LineClient();

// -----------------------------------------------------------------------------
// 1) どのアカウントのトークンなのかを必ず先に見せる
// -----------------------------------------------------------------------------
echo PHP_EOL . '=== 対象のLINE公式アカウント ===' . PHP_EOL;

$info = $line->botInfo();
if ($info === null) {
    fwrite(STDERR, "Bot情報を取得できませんでした。LINE_CHANNEL_ACCESS_TOKEN と通信経路を確認してください。\n");
    exit(1);
}

printf("  表示名     : %s%s", $info['displayName'] ?? '?', PHP_EOL);
printf("  ベーシックID: %s%s", $info['basicId'] ?? '?', PHP_EOL);
printf("  応答モード  : %s%s", $info['chatMode'] ?? '?', PHP_EOL);
if (($info['chatMode'] ?? '') !== 'bot') {
    echo '  ※ 応答モードが「チャット」です。botで応答させるにはLINE Official Account Managerで' . PHP_EOL;
    echo '     「Bot」に切り替えてください。' . PHP_EOL;
}

// -----------------------------------------------------------------------------
// 2) 現在のWebhook設定
// -----------------------------------------------------------------------------
echo PHP_EOL . '=== 現在のWebhook設定 ===' . PHP_EOL;

$current = $line->getWebhookEndpoint();
if ($current->ok()) {
    printf("  URL   : %s%s", $current->json['endpoint'] ?? '(未設定)', PHP_EOL);
    printf("  有効  : %s%s", ($current->json['active'] ?? false) ? 'はい' : 'いいえ', PHP_EOL);
} else {
    echo '  取得できませんでした（未設定の可能性があります）' . PHP_EOL;
}

echo PHP_EOL . "  差し替え先: {$target}" . PHP_EOL;

// -----------------------------------------------------------------------------
// 3) 疎通確認だけ
// -----------------------------------------------------------------------------
if ($test && !$apply) {
    echo PHP_EOL . '=== 疎通確認 ===' . PHP_EOL;
    reportTest($line->testWebhookEndpoint($target));
    exit(0);
}

if (!$apply) {
    echo PHP_EOL . '表示のみで終了しました。実際に差し替えるには --apply を付けてください。' . PHP_EOL . PHP_EOL;
    exit(0);
}

// -----------------------------------------------------------------------------
// 4) 確認 → 差し替え
// -----------------------------------------------------------------------------
if (!$yes) {
    echo PHP_EOL;
    echo '上記アカウントのWebhook URLを差し替えます。' . PHP_EOL;
    echo '既に友だちがいるアカウントの場合、その方々にこのbotが応答し始めます。' . PHP_EOL;
    echo '続けるにはベーシックID「' . ($info['basicId'] ?? '') . '」を入力してください: ';

    $answer = trim((string) fgets(STDIN));
    if ($answer !== (string) ($info['basicId'] ?? '')) {
        echo '入力が一致しないため中止しました。' . PHP_EOL . PHP_EOL;
        exit(1);
    }
}

echo PHP_EOL . '=== 差し替え ===' . PHP_EOL;

$res = $line->setWebhookEndpoint($target);
if (!$res->ok()) {
    fwrite(STDERR, '  失敗しました（HTTP ' . $res->status . '）: ' . mb_substr($res->body, 0, 300) . PHP_EOL);
    fwrite(STDERR, '  チャネルアクセストークンの権限を確認してください。' . PHP_EOL);
    exit(1);
}
echo '  Webhook URL を差し替えました。' . PHP_EOL;

echo PHP_EOL . '=== 疎通確認 ===' . PHP_EOL;
$exit = reportTest($line->testWebhookEndpoint());

echo PHP_EOL . '=== 次にやること ===' . PHP_EOL;
echo '  1. LINE Official Account Manager で「応答メッセージ」をオフにする' . PHP_EOL;
echo '  2. 同じく「あいさつメッセージ」をオフにする（follow時に自前で返すため）' . PHP_EOL;
echo '  3. アカウント名・アイコン・プロフィールをAUTOBEST用に変更する' . PHP_EOL;
echo '  4. 実機で友だち追加し、あいさつが届くか確認する' . PHP_EOL . PHP_EOL;

exit($exit);

/**
 * 疎通確認の結果を読める形で出す。
 * statusCode はこちらのサーバーが返した値なので、403ならWAF、
 * 404ならパス違い、500ならアプリのエラーと切り分けられる。
 */
function reportTest(\App\LineResponse $res): int
{
    if (!$res->ok()) {
        fwrite(STDERR, '  実行できませんでした（HTTP ' . $res->status . '）: ' . mb_substr($res->body, 0, 300) . PHP_EOL);
        return 1;
    }

    $j       = $res->json ?? [];
    $success = ($j['success'] ?? false) === true;
    $code    = (int) ($j['statusCode'] ?? 0);

    printf("  結果      : %s%s", $success ? '成功' : '失敗', PHP_EOL);
    printf("  応答コード : %d%s", $code, PHP_EOL);
    if (!empty($j['reason']))  { printf("  理由      : %s%s", $j['reason'], PHP_EOL); }
    if (!empty($j['detail']))  { printf("  詳細      : %s%s", $j['detail'], PHP_EOL); }

    if (!$success) {
        echo PHP_EOL . '  よくある原因:' . PHP_EOL;
        echo match (true) {
            $code === 403 => '    403 … WAFがブロックしています。サーバーパネルのWAF設定で' . PHP_EOL
                           . '          対象ドメインのSQL対策・XSS対策をOFFにしてください。' . PHP_EOL,
            $code === 404 => '    404 … URLが違います。webhook.php の設置場所を確認してください。' . PHP_EOL,
            $code === 405 => '    405 … POSTが届いていません。リライト設定を確認してください。' . PHP_EOL,
            $code === 500 => '    500 … アプリのエラーです。storage/logs を確認してください。' . PHP_EOL,
            default       => '    SSL証明書と、URLがhttpsで到達できるかを確認してください。' . PHP_EOL,
        };
        return 1;
    }
    return 0;
}

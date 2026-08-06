<?php
/**
 * 署名付きのダミーWebhookを自分のサーバーへ投げるテスト用スクリプト（CLI専用）。
 *
 *   php bin/send_test_webhook.php follow
 *   php bin/send_test_webhook.php message こんにちは
 *
 * LINEアプリを使わずに「署名検証 → 200即返し → DB書き込み」までを確認できる。
 * replyToken はダミーなので実際の返信は飛ばない（LINE側で Invalid reply token になる）。
 * 確認したいのは HTTPステータス200と、DB・ログに記録が残ることの2点。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';

use App\Config;

$type = $argv[1] ?? 'message';
$text = $argv[2] ?? 'テスト';

// 実在しないダミーのユーザーID。本番データと混ざらないよう固定値にしておく。
$userId = 'U00000000000000000000000000000test';

$event = match ($type) {
    'follow' => [
        'type'           => 'follow',
        'webhookEventId' => 'TEST' . bin2hex(random_bytes(8)),
        'replyToken'     => str_repeat('a', 32),
        'timestamp'      => (int) (microtime(true) * 1000),
        'source'         => ['type' => 'user', 'userId' => $userId],
    ],
    'unfollow' => [
        'type'           => 'unfollow',
        'webhookEventId' => 'TEST' . bin2hex(random_bytes(8)),
        'timestamp'      => (int) (microtime(true) * 1000),
        'source'         => ['type' => 'user', 'userId' => $userId],
    ],
    'postback' => [
        'type'           => 'postback',
        'webhookEventId' => 'TEST' . bin2hex(random_bytes(8)),
        'replyToken'     => str_repeat('a', 32),
        'timestamp'      => (int) (microtime(true) * 1000),
        'source'         => ['type' => 'user', 'userId' => $userId],
        'postback'       => ['data' => $text === 'テスト' ? 'action=faq' : $text],
    ],
    default => [
        'type'           => 'message',
        'webhookEventId' => 'TEST' . bin2hex(random_bytes(8)),
        'replyToken'     => str_repeat('a', 32),
        'timestamp'      => (int) (microtime(true) * 1000),
        'source'         => ['type' => 'user', 'userId' => $userId],
        'message'        => ['id' => '1', 'type' => 'text', 'text' => $text],
    ],
};

$payload = ['destination' => 'Udummy', 'events' => [$event]];

// 送信するバイト列をここで確定させ、同じ文字列に対して署名を作る。
// json_encode を2回呼ぶとエスケープが揺れて署名が合わなくなるので、必ず変数に取る。
$body      = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$signature = base64_encode(hash_hmac('sha256', $body, Config::get('LINE_CHANNEL_SECRET'), true));

$url = rtrim(Config::get('BOT_BASE_URL', 'https://bot.autobest.jp'), '/') . '/webhook.php';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-Line-Signature: ' . $signature,
        // LINEからのリクエストと同じUAにしておくと、WAFの挙動差の切り分けがしやすい。
        'User-Agent: LineBotWebhook/2.0',
    ],
]);

$start    = microtime(true);
$response = curl_exec($ch);
$status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$err      = curl_error($ch);
curl_close($ch);

$elapsed = round((microtime(true) - $start) * 1000);

echo PHP_EOL;
echo "送信先     : {$url}" . PHP_EOL;
echo "イベント種別: {$type}" . PHP_EOL;
echo "HTTPステータス: {$status}" . PHP_EOL;
echo "応答時間   : {$elapsed} ms" . PHP_EOL;
echo '本文       : ' . ($response === false ? "(失敗) {$err}" : trim((string) $response)) . PHP_EOL;
echo PHP_EOL;

echo match (true) {
    $status === 200 => "→ 正常です。storage/logs と line_users テーブルを確認してください。" . PHP_EOL,
    $status === 403 => "→ 署名検証に失敗しています。.env の LINE_CHANNEL_SECRET を確認してください。" . PHP_EOL
                     . "   （403がWAF由来の可能性もあります。サーバーパネルのWAFログを確認してください）" . PHP_EOL,
    $status === 405 => "→ POSTが届いていません。URLとリライト設定を確認してください。" . PHP_EOL,
    $status === 500 => "→ サーバー側エラーです。storage/logs/php-error.log を確認してください。" . PHP_EOL,
    default         => "→ 想定外のステータスです。アクセスログとWAFログを確認してください。" . PHP_EOL,
};

exit($status === 200 ? 0 : 1);

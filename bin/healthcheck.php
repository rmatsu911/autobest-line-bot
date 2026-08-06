<?php
/**
 * 設置後の動作確認スクリプト（CLI専用）。
 *
 *   php bin/healthcheck.php
 *
 * 確認するのは4点。
 *   1) .env が読めて必須項目が揃っているか
 *   2) DBに接続でき、必要なテーブルが存在するか
 *   3) チャネルアクセストークンが有効か（Botの情報を取得できるか）
 *   4) 署名検証ロジックが正しく動くか（自己テスト）
 *
 * 秘密情報は出力しない。値の有無と長さだけを表示する。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';

use App\Config;
use App\Db;
use App\LineClient;
use App\Signature;

$failed = 0;

function ok(string $label, string $detail = ''): void
{
    echo "  [OK]   {$label}" . ($detail !== '' ? "  {$detail}" : '') . PHP_EOL;
}

function ng(string $label, string $detail = ''): void
{
    global $failed;
    $failed++;
    echo "  [NG]   {$label}" . ($detail !== '' ? "  {$detail}" : '') . PHP_EOL;
}

echo PHP_EOL . '=== autobest.jp LINE bot ヘルスチェック ===' . PHP_EOL . PHP_EOL;

// -----------------------------------------------------------------------------
echo '1. 実行環境' . PHP_EOL;
// -----------------------------------------------------------------------------
version_compare(PHP_VERSION, '8.2.0', '>=')
    ? ok('PHPバージョン', PHP_VERSION)
    : ng('PHPバージョン', PHP_VERSION . ' （8.2以上が必要）');

foreach (['curl', 'pdo_mysql', 'mbstring', 'json'] as $ext) {
    extension_loaded($ext) ? ok("拡張 {$ext}") : ng("拡張 {$ext}", '未ロード');
}

// -----------------------------------------------------------------------------
echo PHP_EOL . '2. 設定（.env）' . PHP_EOL;
// -----------------------------------------------------------------------------
$required = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'LINE_CHANNEL_SECRET', 'LINE_CHANNEL_ACCESS_TOKEN'];
foreach ($required as $key) {
    $value = Config::get($key, '');
    $value !== ''
        ? ok($key, '設定あり（' . strlen($value) . '文字）')
        : ng($key, '未設定');
}

if (Config::get('DB_HOST', '') === 'localhost') {
    ng('DB_HOST', 'Xserverでは localhost は使えません。mysqlXXX.xserver.jp を指定してください');
}

// 書き込み先の確認
$logDir = APP_ROOT . '/storage/logs';
is_writable($logDir) ? ok('ログディレクトリ', $logDir) : ng('ログディレクトリ', $logDir . ' に書き込めません');

// -----------------------------------------------------------------------------
echo PHP_EOL . '3. データベース' . PHP_EOL;
// -----------------------------------------------------------------------------
try {
    $row = Db::one('SELECT VERSION() AS v, @@character_set_database AS cs, NOW() AS now_at');
    ok('接続', 'MySQL ' . ($row['v'] ?? '?') . ' / charset=' . ($row['cs'] ?? '?') . ' / NOW()=' . ($row['now_at'] ?? '?'));

    if (($row['cs'] ?? '') !== 'utf8mb4') {
        ng('文字コード', 'utf8mb4 ではありません（' . ($row['cs'] ?? '?') . '）');
    }

    $expected = ['line_users', 'cars', 'car_images', 'purchase_records', 'inquiries', 'message_queue', 'webhook_events', 'admin_users'];
    $existing = array_column(
        Db::all('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()'),
        't'
    );
    foreach ($expected as $table) {
        in_array($table, $existing, true)
            ? ok("テーブル {$table}")
            : ng("テーブル {$table}", 'sql/schema.sql を流し込んでください');
    }
} catch (Throwable $e) {
    ng('接続', $e->getMessage());
}

// -----------------------------------------------------------------------------
echo PHP_EOL . '4. LINE Messaging API' . PHP_EOL;
// -----------------------------------------------------------------------------
$info = LineClient::httpGet('https://api.line.me/v2/bot/info', [
    'Authorization: Bearer ' . Config::get('LINE_CHANNEL_ACCESS_TOKEN', ''),
]);
if ($info !== null && isset($info['basicId'])) {
    ok('アクセストークン', 'Bot: ' . ($info['displayName'] ?? '?') . ' (' . $info['basicId'] . ')');
    ok('Webhook URL（LINE側の設定）', (string) ($info['chatMode'] ?? '') === 'bot'
        ? '応答モード: Bot'
        : '応答モード: ' . ($info['chatMode'] ?? '?') . ' … Botに切り替えてください');
} else {
    ng('アクセストークン', 'Bot情報を取得できませんでした。トークンの有効期限と通信経路を確認してください');
}

// -----------------------------------------------------------------------------
echo PHP_EOL . '5. 署名検証の自己テスト' . PHP_EOL;
// -----------------------------------------------------------------------------
$secret = 'test-secret';
$body   = '{"events":[]}';
$valid  = base64_encode(hash_hmac('sha256', $body, $secret, true));

Signature::isValid($body, $valid, $secret)
    ? ok('正しい署名を受理する')
    : ng('正しい署名を受理する');

!Signature::isValid($body, $valid, 'wrong-secret')
    ? ok('誤ったシークレットを拒否する')
    : ng('誤ったシークレットを拒否する');

!Signature::isValid($body . ' ', $valid, $secret)
    ? ok('改ざんされた本文を拒否する')
    : ng('改ざんされた本文を拒否する');

!Signature::isValid($body, '', $secret)
    ? ok('署名なしを拒否する')
    : ng('署名なしを拒否する');

// -----------------------------------------------------------------------------
echo PHP_EOL . str_repeat('-', 50) . PHP_EOL;
if ($failed === 0) {
    echo '結果: すべて正常です。' . PHP_EOL . PHP_EOL;
    exit(0);
}
echo "結果: {$failed} 件の問題があります。上の [NG] を確認してください。" . PHP_EOL . PHP_EOL;
exit(1);

<?php
/**
 * 一斉配信をキューに積むCLI。
 *
 * このスクリプトはLINE APIへ直接送らない。共用サーバーの実行時間制限と
 * 無料枠の誤消費を避けるため、実送信は queue_worker.php に任せる。
 *
 * 例:
 *   php bin/broadcast.php --text="今週の入庫情報です" --estimated-recipients=120 --confirm
 *   php bin/broadcast.php --text="個別配信です" --to-file=storage/tmp/users.txt --confirm
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';

use App\LineClient;
use App\MessageQueue;

set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
});

$args = parseArgs(array_slice($argv, 1));

if (isset($args['help']) || isset($args['h'])) {
    usage(0);
}

$text = trim((string) ($args['text'] ?? ''));
if ($text === '') {
    fwrite(STDERR, "--text を指定してください。\n");
    usage(2);
}

$message = LineClient::text($text);
$confirm = isset($args['confirm']);
$to = recipientIds($args);
$mode = $to === [] ? 'broadcast' : 'multicast';
$estimate = $mode === 'broadcast'
    ? positiveInt($args['estimated-recipients'] ?? null, 'estimated-recipients')
    : count($to);

$line = new LineClient();
$quota = $line->messageQuotaStatus();
if ($quota === null) {
    fwrite(STDERR, "LINE通数の残量を取得できないため、キューには積みません。\n");
    exit(1);
}

$remainingText = ($quota['limited'] ?? true)
    ? (string) ($quota['remaining'] ?? 0)
    : '無制限';

echo "配信方式: {$mode}\n";
echo "消費見込み: {$estimate} 通\n";
echo "今月の残り: {$remainingText} 通\n";

if (($quota['limited'] ?? true) && $estimate > (int) ($quota['remaining'] ?? 0)) {
    fwrite(STDERR, "残り通数が足りないため、キューには積みません。\n");
    exit(1);
}

if (!$confirm) {
    fwrite(STDERR, "--confirm を付けると、この内容をキューに積みます。\n");
    exit(2);
}

$queued = 0;
if ($mode === 'broadcast') {
    MessageQueue::enqueue('broadcast', [
        'messages' => [$message],
        'estimated_recipients' => $estimate,
    ]);
    $queued = 1;
} else {
    foreach (array_chunk($to, 500) as $chunk) {
        MessageQueue::enqueue('multicast', [
            'to' => $chunk,
            'messages' => [$message],
        ]);
        $queued++;
    }
}

echo "キュー投入: {$queued} 件\n";
exit(0);

/**
 * @param array<int,string> $argv
 * @return array<string,string|bool>
 */
function parseArgs(array $argv): array
{
    $parsed = [];
    foreach ($argv as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }

        $body = substr($arg, 2);
        if (str_contains($body, '=')) {
            [$key, $value] = explode('=', $body, 2);
            $parsed[$key] = $value;
        } else {
            $parsed[$body] = true;
        }
    }
    return $parsed;
}

/**
 * @param array<string,string|bool> $args
 * @return array<int,string>
 */
function recipientIds(array $args): array
{
    $ids = [];

    if (isset($args['to']) && is_string($args['to'])) {
        $ids = array_merge($ids, preg_split('/\s*,\s*/', $args['to'], -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    if (isset($args['to-file']) && is_string($args['to-file'])) {
        $path = $args['to-file'];
        if (!preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) && !str_starts_with($path, '/')) {
            $path = APP_ROOT . '/' . ltrim($path, '/\\');
        }
        if (!is_readable($path)) {
            throw new RuntimeException('宛先ファイルを読めません: ' . $args['to-file']);
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $ids = array_merge($ids, array_map('trim', $lines));
    }

    $clean = [];
    foreach ($ids as $id) {
        $id = trim((string) $id);
        if ($id === '') {
            continue;
        }
        if (preg_match('/\AU[0-9a-fA-F]{32}\z/', $id) !== 1) {
            throw new RuntimeException('LINEユーザーIDの形式が不正です: ' . $id);
        }
        $clean[$id] = $id;
    }

    return array_values($clean);
}

/**
 * @param mixed $value
 */
function positiveInt($value, string $name): int
{
    if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
        throw new RuntimeException('--' . $name . ' には1以上の整数を指定してください。');
    }
    return (int) $value;
}

function usage(int $code): void
{
    $message = <<<TXT
使い方:
  php bin/broadcast.php --text="本文" --estimated-recipients=120 --confirm
  php bin/broadcast.php --text="本文" --to=U...,... --confirm
  php bin/broadcast.php --text="本文" --to-file=storage/tmp/users.txt --confirm

--estimated-recipients  宛先全員にbroadcastする場合の消費見込み
--to / --to-file        multicastするLINEユーザーID（500件ごとに分割してキュー投入）
--confirm               表示された見積もりでキュー投入する

TXT;
    $out = $code === 0 ? STDOUT : STDERR;
    fwrite($out, $message);
    exit($code);
}

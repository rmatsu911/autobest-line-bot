<?php
/**
 * message_queue を処理するcron用ワーカー。
 *
 * 例:
 *   * * * * * cd /home/xxx/apps/autobest-line-bot && php bin/queue_worker.php >> storage/logs/cron.log 2>&1
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';

use App\Logger;
use App\MessageQueue;

$args = parseArgs(array_slice($argv, 1));
$maxItems = intArg($args, 'max-items', MessageQueue::DEFAULT_MAX_ITEMS);
$maxSeconds = intArg($args, 'max-seconds', MessageQueue::DEFAULT_MAX_SECONDS);
$staleMinutes = intArg($args, 'stale-minutes', MessageQueue::STALE_MINUTES);

$lockPath = APP_ROOT . '/storage/queue_worker.lock';
$lockDir = dirname($lockPath);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0750, true);
}

$lock = @fopen($lockPath, 'c');
if ($lock === false) {
    fwrite(STDERR, "ロックファイルを開けません: {$lockPath}\n");
    exit(1);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo '[' . date('Y-m-d H:i:s') . "] queue_worker: already running\n";
    fclose($lock);
    exit(0);
}

try {
    $summary = MessageQueue::work(null, $maxItems, $maxSeconds, $staleMinutes);
    echo '[' . date('Y-m-d H:i:s') . '] queue_worker: '
        . 'processed=' . $summary['processed']
        . ' succeeded=' . $summary['succeeded']
        . ' retried=' . $summary['retried']
        . ' failed=' . $summary['failed']
        . ' quota_blocked=' . $summary['quota_blocked']
        . ' stale_requeued=' . $summary['stale_requeued']
        . ' elapsed=' . $summary['elapsed_seconds'] . "s\n";
    exit(0);
} catch (Throwable $e) {
    Logger::critical('queue_worker が停止しました', ['message' => $e->getMessage()]);
    fwrite(STDERR, 'queue_worker failed: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

/**
 * @param array<int,string> $argv
 * @return array<string,string>
 */
function parseArgs(array $argv): array
{
    $parsed = [];
    foreach ($argv as $arg) {
        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            continue;
        }
        [$key, $value] = explode('=', substr($arg, 2), 2);
        $parsed[$key] = $value;
    }
    return $parsed;
}

/**
 * @param array<string,string> $args
 */
function intArg(array $args, string $key, int $default): int
{
    if (!isset($args[$key]) || !ctype_digit($args[$key])) {
        return $default;
    }
    return (int) $args[$key];
}

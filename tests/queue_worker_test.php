<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/config/config.php';

use App\Db;
use App\LineClient;
use App\LineResponse;
use App\MessageQueue;

$pass = 0;
$fail = 0;

function check(string $name, bool $cond, string $extra = ''): void {
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ok   {$name}\n";
    } else {
        $fail++;
        echo "  FAIL {$name} {$extra}\n";
    }
}

function row(int $id): array {
    return Db::one('SELECT * FROM message_queue WHERE id = ?', [$id]) ?? [];
}

function clearQueue(): void {
    Db::exec('DELETE FROM message_queue');
}

function okResponse(): LineResponse {
    return new LineResponse(200, ['ok' => true], '{"ok":true}');
}

function errorResponse(int $status, string $message): LineResponse {
    return new LineResponse($status, ['message' => $message], json_encode(['message' => $message], JSON_UNESCAPED_UNICODE) ?: '');
}

echo "\n== 前提 ==\n";
// MessageQueue は「senderは LineResponse を返すこと」を約束事にしている。
// LineClient を読み込まずに LineResponse を使う呼び出し側があり得るので、
// 単体でオートロードできることを保証する（できないと sender が必ず失敗する）。
check('LineResponse を単体でオートロードできる', class_exists(LineResponse::class, true));

echo "\n== enqueue ==\n";
clearQueue();
$id = MessageQueue::enqueue('push', [
    'to' => 'Uqueue001',
    'messages' => [LineClient::text('査定のご案内')],
]);
$saved = row($id);
check('pendingで保存する', ($saved['status'] ?? '') === 'pending');
check('retry_keyを採番する', is_string($saved['retry_key'] ?? null) && strlen((string) $saved['retry_key']) === 36);
check('payloadはJSONで読める', (json_decode((string) $saved['payload'], true)['to'] ?? '') === 'Uqueue001');

echo "\n== 成功処理 ==\n";
clearQueue();
$id = MessageQueue::enqueue('push', [
    'to' => 'Uqueue002',
    'messages' => [LineClient::text('こんにちは')],
]);
$calls = [];
$summary = MessageQueue::work(
    static function (string $type, array $payload, string $retryKey) use (&$calls): LineResponse {
        $calls[] = compact('type', 'payload', 'retryKey');
        return okResponse();
    },
    10,
    10
);
$saved = row($id);
check('1件処理する', $summary['processed'] === 1);
check('doneにする', ($saved['status'] ?? '') === 'done');
check('attemptsを増やす', (int) ($saved['attempts'] ?? 0) === 1);
check('senderへretry_keyを渡す', ($calls[0]['retryKey'] ?? '') === ($saved['retry_key'] ?? ''));
check('senderへpayloadを渡す', ($calls[0]['payload']['to'] ?? '') === 'Uqueue002');

echo "\n== リトライ ==\n";
clearQueue();
$id = MessageQueue::enqueue('push', [
    'to' => 'Uqueue003',
    'messages' => [LineClient::text('後で再送')],
]);
$summary = MessageQueue::work(
    static fn(string $type, array $payload, string $retryKey): LineResponse => errorResponse(429, 'Too many requests'),
    10,
    10
);
$saved = row($id);
check('429はpendingに戻す', ($saved['status'] ?? '') === 'pending');
check('retriedを数える', $summary['retried'] === 1);
check('エラー内容を残す', str_contains((string) ($saved['last_error'] ?? ''), '429'));
check('次回実行時刻を先に送る', strcmp((string) ($saved['scheduled_at'] ?? ''), date('Y-m-d H:i:s')) > 0);

echo "\n== リトライ上限 ==\n";
Db::exec(
    'UPDATE message_queue SET attempts = ?, scheduled_at = ? WHERE id = ?',
    [MessageQueue::MAX_ATTEMPTS - 1, date('Y-m-d H:i:s', time() - 1), $id]
);
$summary = MessageQueue::work(
    static fn(string $type, array $payload, string $retryKey): LineResponse => errorResponse(500, 'Server error'),
    10,
    10
);
$saved = row($id);
check('上限到達でfailedにする', ($saved['status'] ?? '') === 'failed');
check('failedを数える', $summary['failed'] === 1);
check('attemptsは上限まで進む', (int) ($saved['attempts'] ?? 0) === MessageQueue::MAX_ATTEMPTS);

echo "\n== 4xxと不正payload ==\n";
clearQueue();
$id400 = MessageQueue::enqueue('push', [
    'to' => 'Uqueue004',
    'messages' => [LineClient::text('不正リクエスト')],
]);
MessageQueue::work(
    static fn(string $type, array $payload, string $retryKey): LineResponse => errorResponse(400, 'Bad request'),
    10,
    10
);
check('400は再試行しない', (row($id400)['status'] ?? '') === 'failed');

$invalid = MessageQueue::enqueue('push', ['to' => 'Uqueue005']);
$called = false;
MessageQueue::work(
    static function () use (&$called): LineResponse {
        $called = true;
        return okResponse();
    },
    10,
    10
);
check('不正payloadはsenderを呼ばない', !$called);
check('不正payloadはfailedにする', (row($invalid)['status'] ?? '') === 'failed');

echo "\n== stale processing ==\n";
clearQueue();
$old = date('Y-m-d H:i:s', time() - 3600);
$payload = json_encode(['to' => 'Uqueue006', 'messages' => [LineClient::text('古い処理')]], JSON_UNESCAPED_UNICODE) ?: '{}';
Db::exec(
    'INSERT INTO message_queue (type, payload, status, attempts, retry_key, scheduled_at, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
    ['push', $payload, 'processing', 1, '11111111-1111-4111-8111-111111111111', $old, $old, $old]
);
$staleId = Db::lastInsertId();
$count = MessageQueue::releaseStale(10);
check('古いprocessingを拾い直す', $count === 1);
check('pendingに戻す', (row($staleId)['status'] ?? '') === 'pending');

echo "\n== 実行上限と予定時刻 ==\n";
clearQueue();
$a = MessageQueue::enqueue('push', ['to' => 'Ulimit1', 'messages' => [LineClient::text('1')]]);
$b = MessageQueue::enqueue('push', ['to' => 'Ulimit2', 'messages' => [LineClient::text('2')]]);
$future = MessageQueue::enqueue(
    'push',
    ['to' => 'Ufuture', 'messages' => [LineClient::text('future')]],
    date('Y-m-d H:i:s', time() + 3600)
);
$summary = MessageQueue::work(
    static fn(string $type, array $payload, string $retryKey): LineResponse => okResponse(),
    1,
    10
);
check('maxItemsで1件だけ処理する', $summary['processed'] === 1);
$statuses = [row($a)['status'] ?? '', row($b)['status'] ?? '', row($future)['status'] ?? ''];
check('残りはpendingのまま', in_array('done', $statuses, true) && count(array_filter($statuses, fn($s) => $s === 'pending')) === 2);

echo "\n== multicast / broadcast ==\n";
clearQueue();
$multi = MessageQueue::enqueue('multicast', [
    'to' => ['U1', 'U2'],
    'messages' => [LineClient::text('一斉')],
]);
$broadcast = MessageQueue::enqueue('broadcast', [
    'messages' => [LineClient::text('全員')],
    'estimated_recipients' => 3,
]);
$sent = [];
MessageQueue::work(
    static function (string $type, array $payload, string $retryKey) use (&$sent): LineResponse {
        $sent[] = $type;
        return okResponse();
    },
    10,
    10
);
check('multicastを処理できる', (row($multi)['status'] ?? '') === 'done');
check('broadcastを処理できる', (row($broadcast)['status'] ?? '') === 'done');
check('両方senderに渡る', $sent === ['multicast', 'broadcast']);

$badBroadcast = MessageQueue::enqueue('broadcast', [
    'messages' => [LineClient::text('見積もりなし')],
]);
MessageQueue::work(
    static fn(string $type, array $payload, string $retryKey): LineResponse => okResponse(),
    10,
    10
);
check('broadcastは見積もりなしで送らない', (row($badBroadcast)['status'] ?? '') === 'failed');

echo "\n" . str_repeat('-', 46) . "\n";
echo "成功 {$pass} / 失敗 {$fail}\n\n";

exit($fail === 0 ? 0 : 1);

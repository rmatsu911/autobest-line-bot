<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
const APP = APP_ROOT;

require __DIR__ . '/fakes.php';       // 本物より先に Db / LineClient を差し替える
require APP . '/config/config.php';

use App\Config;
use App\Db;
use App\LineClient;
use App\Signature;
use App\WebhookHandler;

$pass = 0; $fail = 0;
function check(string $name, bool $cond, string $extra = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok   {$name}\n"; }
    else { $fail++; echo "  FAIL {$name} {$extra}\n"; }
}
function evt(array $o): array {
    return $o + ['webhookEventId' => 'E' . bin2hex(random_bytes(6)), 'timestamp' => 0];
}
function lastText(): string {
    $r = LineClient::$replies;
    if ($r === []) return '';
    return $r[count($r) - 1]['messages'][0]['text'] ?? '';
}

echo "\n== Config ==\n";
// MySQL構成/SQLite構成のどちらでも必ず存在するキーで判定する
check('.env が読める', Config::get('LINE_CHANNEL_SECRET', '') !== '');
check('既定値が効く', Config::get('NOT_EXIST_KEY', 'fallback') === 'fallback');
check('クォート除去', Config::get('QUOTED_VALUE', '') === 'quoted value');
check('コメント行を無視', Config::get('AFTER_COMMENT', '') === 'yes');

echo "\n== Signature ==\n";
$secret = 'abc123';
$body = '{"events":[{"type":"message"}]}';
$sig = base64_encode(hash_hmac('sha256', $body, $secret, true));
check('正しい署名を受理', Signature::isValid($body, $sig, $secret));
check('本文改ざんを拒否', !Signature::isValid($body . 'x', $sig, $secret));
check('別シークレットを拒否', !Signature::isValid($body, $sig, 'other'));
check('空の署名を拒否', !Signature::isValid($body, '', $secret));
check('空のシークレットを拒否', !Signature::isValid($body, $sig, ''));
check('再エンコードした本文は不一致になる（生ボディ必須の裏付け）',
    !Signature::isValid(json_encode(json_decode($body, true), JSON_UNESCAPED_SLASHES) . ' ', $sig, $secret));

echo "\n== follow ==\n";
Db::$calls = []; LineClient::$replies = [];
(new WebhookHandler())->handleEvents([evt([
    'type' => 'follow', 'replyToken' => str_repeat('a', 32),
    'source' => ['type' => 'user', 'userId' => 'U123'],
])]);
$sqls = array_column(Db::$calls, 'sql');
check('webhook_events に記録', str_contains($sqls[0] ?? '', 'INSERT IGNORE INTO webhook_events'));
check('line_users を upsert', (bool) array_filter($sqls, fn($s) => str_contains($s, 'INSERT INTO line_users')));
check('upsert で blocked を解除', (bool) array_filter($sqls, fn($s) => str_contains($s, 'blocked = 0')));
check('あいさつを返信', str_contains(lastText(), '友だち追加ありがとうございます'));
check('表示名を差し込む', str_contains(lastText(), 'テスト太郎さん'));
check('クイックリプライ付き', isset(LineClient::$replies[0]['messages'][0]['quickReply']));

echo "\n== 重複配信 ==\n";
LineClient::$replies = [];
$dup = evt(['type' => 'follow', 'replyToken' => str_repeat('a', 32), 'source' => ['userId' => 'U123']]);
Db::$affected = 1;  (new WebhookHandler())->handleEvents([$dup]);
$after1 = count(LineClient::$replies);
Db::$affected = 0;  (new WebhookHandler())->handleEvents([$dup]);   // INSERT IGNORE が0行＝再送
$after2 = count(LineClient::$replies);
check('2回目は返信しない', $after1 === 1 && $after2 === 1, "1回目={$after1} 2回目={$after2}");
Db::$affected = 1;

echo "\n== LINE検証ボタン ==\n";
LineClient::$replies = [];
(new WebhookHandler())->handleEvents([evt([
    'type' => 'message', 'replyToken' => str_repeat('0', 32),
    'source' => ['userId' => 'U123'], 'message' => ['type' => 'text', 'text' => 'hi'],
])]);
check('ダミーreplyTokenでは返信しない', LineClient::$replies === []);

echo "\n== unfollow ==\n";
Db::$calls = [];
(new WebhookHandler())->handleEvents([evt(['type' => 'unfollow', 'source' => ['userId' => 'U123']])]);
$sqls = array_column(Db::$calls, 'sql');
check('blocked=1 に更新', (bool) array_filter($sqls, fn($s) => str_contains($s, 'UPDATE line_users SET blocked = 1')));
check('行を削除しない', !array_filter($sqls, fn($s) => str_contains($s, 'DELETE')));

echo "\n== キーワード応答 ==\n";
$cases = [
    ['査定してほしい',         '無料査定を承ります'],
    ['ｻﾃｲ',                    '無料査定を承ります'],   // 半角カタカナ
    ['サテイ',                 '無料査定を承ります'],   // 全角カタカナ
    ['ｶﾞｲﾄｳ車ｱﾘﾏｽｶ',           '担当者が確認のうえ'],   // 濁点付き半角カタカナで落ちない
    ['ｻﾞｲｺ',                   '該当する車両がありません'], // 濁点の結合（ｻﾞ→ざ）
    ['クルマを売りたい',       '無料査定を承ります'],
    ['在庫はありますか',       '該当する車両がありません'],
    ['車を見たい',             '該当する車両がありません'],
    ['営業時間を教えて',       'autobest'],
    ['定休日は？',             'autobest'],
    ['電話番号',               '0120-000-000'],
    ['よくある質問',           'よくあるご質問'],
    ['ＦＡＱ',                 'よくあるご質問'],
    ['あああ',                 '担当者が確認のうえ'],
];
foreach ($cases as [$input, $expect]) {
    LineClient::$replies = [];
    (new WebhookHandler())->handleEvents([evt([
        'type' => 'message', 'replyToken' => str_repeat('a', 32),
        'source' => ['userId' => 'U1'], 'message' => ['type' => 'text', 'text' => $input],
    ])]);
    check("「{$input}」→ {$expect}", str_contains(lastText(), $expect), '実際: ' . mb_substr(lastText(), 0, 40));
}

echo "\n== テキスト以外 ==\n";
LineClient::$replies = [];
(new WebhookHandler())->handleEvents([evt([
    'type' => 'message', 'replyToken' => str_repeat('a', 32),
    'source' => ['userId' => 'U1'], 'message' => ['type' => 'image', 'id' => '1'],
])]);
check('画像には案内を返す', str_contains(lastText(), '無料査定を申し込む'));

echo "\n== postback ==\n";
foreach ([['action=faq', 'よくあるご質問'], ['action=cars&page=1', '該当する車両がありません'], ['action=nope', '受け付けられませんでした']] as [$data, $expect]) {
    LineClient::$replies = [];
    (new WebhookHandler())->handleEvents([evt([
        'type' => 'postback', 'replyToken' => str_repeat('a', 32),
        'source' => ['userId' => 'U1'], 'postback' => ['data' => $data],
    ])]);
    check("postback {$data} → {$expect}", str_contains(lastText(), $expect), '実際: ' . mb_substr(lastText(), 0, 40));
}

echo "\n== 異常系 ==\n";
LineClient::$replies = [];
(new WebhookHandler())->handleEvents(['not-an-array', [], evt(['type' => 'unknownEvent', 'source' => ['userId' => 'U1']])]);
check('不正な要素で落ちない', true);

LineClient::$replies = [];
(new WebhookHandler())->handleEvents([
    evt(['type' => 'message', 'replyToken' => str_repeat('a', 32), 'source' => ['userId' => 'U1'], 'message' => ['type' => 'text', 'text' => '在庫']]),
    evt(['type' => 'message', 'replyToken' => str_repeat('b', 32), 'source' => ['userId' => 'U2'], 'message' => ['type' => 'text', 'text' => '査定']]),
]);
check('複数イベントを個別に処理', count(LineClient::$replies) === 2, '件数=' . count(LineClient::$replies));

echo "\n== DB障害時 ==\n";
Db::$throw = true;
LineClient::$replies = [];
$crashed = false;
try {
    (new WebhookHandler())->handleEvents([
        evt(['type' => 'message', 'replyToken' => str_repeat('a', 32), 'source' => ['userId' => 'U1'], 'message' => ['type' => 'text', 'text' => '在庫']]),
        evt(['type' => 'unfollow', 'source' => ['userId' => 'U2']]),
    ]);
} catch (Throwable $e) {
    $crashed = true;
}
check('DBが落ちていても例外を外に漏らさない', !$crashed);
Db::$throw = false;

echo "\n== プレースホルダ ==\n";
Db::$calls = [];
(new WebhookHandler())->handleEvents([evt(['type' => 'unfollow', 'source' => ['userId' => "U1' OR 1=1 --"]])]);
$call = end(Db::$calls);
check('値はSQLに埋め込まずパラメータで渡す',
    !str_contains($call['sql'], 'OR 1=1') && in_array("U1' OR 1=1 --", $call['params'], true));

// SQLite分岐でも同じ振る舞いになることを確認する（MySQL/SQLiteで別SQLを流すため）
echo "\n== SQLite分岐 ==\n";
Db::$sqlite = true;
Db::$calls = []; LineClient::$replies = [];
(new WebhookHandler())->handleEvents([evt([
    'type' => 'follow', 'replyToken' => str_repeat('a', 32), 'source' => ['userId' => 'Usq'],
])]);
$sqls = array_column(Db::$calls, 'sql');
check('重複検出は INSERT OR IGNORE を使う', (bool) array_filter($sqls, fn($s) => str_contains($s, 'INSERT OR IGNORE INTO webhook_events')));
check('upsert は ON CONFLICT DO UPDATE を使う', (bool) array_filter($sqls, fn($s) => str_contains($s, 'ON CONFLICT(line_user_id) DO UPDATE')));
check('SQLiteでも blocked を解除する', (bool) array_filter($sqls, fn($s) => str_contains($s, 'blocked = 0')));
check('SQLiteでも時刻はJST（+9 hours）', (bool) array_filter($sqls, fn($s) => str_contains($s, "+9 hours")));
check('SQLiteでも MySQL構文が混ざらない', !array_filter($sqls, fn($s) => str_contains($s, 'ON DUPLICATE KEY') || str_contains($s, 'NOW()')));
check('SQLiteでもあいさつを返信', str_contains(lastText(), '友だち追加ありがとうございます'));

Db::$calls = []; LineClient::$replies = [];
$dup = evt(['type' => 'follow', 'replyToken' => str_repeat('a', 32), 'source' => ['userId' => 'Usq']]);
Db::$affected = 1; (new WebhookHandler())->handleEvents([$dup]);
Db::$affected = 0; (new WebhookHandler())->handleEvents([$dup]);
check('SQLiteでも重複配信は1回だけ処理', count(LineClient::$replies) === 1, '件数=' . count(LineClient::$replies));
Db::$affected = 1;
Db::$sqlite = false;

echo "\n" . str_repeat('-', 46) . "\n";
echo "成功 {$pass} / 失敗 {$fail}\n\n";
exit($fail === 0 ? 0 : 1);

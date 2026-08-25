<?php
declare(strict_types=1);

// LINEへは飛ばさず、返信内容を捕まえるためのスタブ
namespace App {
    final class LineResponse {
        public function __construct(public int $status = 200) {}
        public function ok(): bool { return true; }
    }
    final class LineClient {
        public static array $replies = [];
        /** push / multicast / broadcast は無料枠を消費する。呼ばれたら記録して落とす。 */
        public static array $pushes = [];
        public function __construct(?string $t = null) {}
        public function reply(string $token, array $m): LineResponse { self::$replies[] = $m; return new LineResponse(); }
        public function push(string $to, array $m, ?string $k = null): LineResponse { self::$pushes[] = ['push', $to]; return new LineResponse(); }
        public function multicast(array $to, array $m, ?string $k = null): LineResponse { self::$pushes[] = ['multicast', $to]; return new LineResponse(); }
        public function broadcast(array $m, ?string $k = null): LineResponse { self::$pushes[] = ['broadcast', null]; return new LineResponse(); }
        public function profile(string $u): ?array { return ['displayName' => 'テスト太郎']; }
        public static function text(string $t, ?array $q = null): array {
            $x = ['type'=>'text','text'=>mb_substr($t,0,5000)]; if ($q!==null) $x['quickReply']=$q; return $x;
        }
        public static function quickReply(array $items): array {
            return ['items'=>array_map(fn($i)=>['type'=>'action','action'=>['type'=>'postback','label'=>$i['label'],'data'=>$i['data']]], $items)];
        }
        public static function httpGet(string $u, array $h = []): ?array { return null; }
    }
}

namespace {
    define('APP_ROOT', dirname(__DIR__));
    require APP_ROOT . '/config/config.php';

    use App\Db;
    use App\LineClient;
    use App\NotificationRepository;
    use App\RichMenuDefinition;
    use App\WebhookHandler;

    $pass = 0; $fail = 0;
    function check(string $n, bool $c, string $x = ''): void {
        global $pass, $fail;
        if ($c) { $pass++; echo "  ok   {$n}\n"; } else { $fail++; echo "  FAIL {$n}   {$x}\n"; }
    }
    function fire(array $ev): array {
        LineClient::$replies = [];
        $ev += ['webhookEventId' => 'E' . bin2hex(random_bytes(6)), 'timestamp' => 0];
        (new WebhookHandler())->handleEvents([$ev]);
        return LineClient::$replies;
    }
    function pb(string $data, string $user = 'Utest001'): array {
        return fire(['type'=>'postback','replyToken'=>str_repeat('a',32),
                     'source'=>['type'=>'user','userId'=>$user],'postback'=>['data'=>$data]]);
    }
    function msg(string $text, string $user = 'Utest001'): array {
        return fire(['type'=>'message','replyToken'=>str_repeat('a',32),
                     'source'=>['type'=>'user','userId'=>$user],'message'=>['type'=>'text','text'=>$text]]);
    }
    /** 返信全体を1つの文字列にして中身を探す */
    function flat(array $replies): string {
        return json_encode($replies, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
    function bubbles(array $replies): int {
        foreach ($replies[0] ?? [] as $m) {
            if (($m['type'] ?? '') === 'flex') {
                return count($m['contents']['contents'] ?? []);
            }
        }
        return 0;
    }
    /** カルーセルに出た車両IDを拾う（詳細URLから読む） */
    function shownCarIds(array $replies): array {
        preg_match_all('/car\.php\?id=(\d+)/', flat($replies), $m);
        return array_values(array_unique(array_map('intval', $m[1])));
    }

    $uid = (int) Db::value("SELECT id FROM line_users WHERE line_user_id = 'Utest001'");
    if ($uid <= 0) { fwrite(STDERR, "line_users に Utest001 がありません\n"); exit(2); }

    echo "\n== 無料枠を消費しないこと ==\n";
    LineClient::$pushes = [];
    pb('action=new_arrivals'); pb('action=notify_settings'); msg('新着ありますか');
    check('新着の導線で push/multicast/broadcast を呼ばない', LineClient::$pushes === [],
        '呼ばれた: ' . json_encode(LineClient::$pushes));

    echo "\n== 条件が未設定のとき ==\n";
    NotificationRepository::clearCondition($uid);
    Db::exec('DELETE FROM notification_log WHERE line_user_id = ?', [$uid]);
    $r = pb('action=new_arrivals');
    check('全体の新着をそのまま見せる', bubbles($r) > 0, 'バブル=' . bubbles($r));
    check('未設定でも空振りしない', !str_contains(flat($r), '新着はありません'));
    check('見せただけでは既読にしない（条件未設定のため）',
        (int) Db::value('SELECT COUNT(*) FROM notification_log WHERE line_user_id = ?', [$uid]) === 0);
    $again = pb('action=new_arrivals');
    check('もう一度押しても同じ内容が出る', shownCarIds($r) === shownCarIds($again));

    echo "\n== 条件の設定 ==\n";
    $r = pb('action=notify_settings');
    check('設定画面が出る', str_contains(flat($r), '新着のお知らせ設定'));
    check('自動配信しないことを説明する', str_contains(flat($r), 'メニューの「新着入庫」'));
    check('いまの条件を表示する', str_contains(flat($r), '未設定'));
    $items = $r[0][0]['quickReply']['items'] ?? [];
    check('クイックリプライは13件以内', count($items) <= 13, '件数=' . count($items));
    check('条件を消すボタンがある', str_contains(flat($r), 'action=notify_clear'));

    $r = pb('action=notify_set&category=truck');
    check('車種を保存できる', str_contains(flat($r), '条件を保存しました') && str_contains(flat($r), 'トラック'));
    check('DBに入る', (string) Db::value('SELECT category FROM notification_conditions WHERE line_user_id = ?', [$uid]) === 'truck');

    pb('action=notify_set&location=kanagawa');
    check('拠点も足せる', (string) Db::value('SELECT location FROM notification_conditions WHERE line_user_id = ?', [$uid]) === 'kanagawa');
    check('先に入れた車種は消えない', (string) Db::value('SELECT category FROM notification_conditions WHERE line_user_id = ?', [$uid]) === 'truck');
    check('1人につき1件だけ持つ',
        (int) Db::value('SELECT COUNT(*) FROM notification_conditions WHERE line_user_id = ?', [$uid]) === 1);

    $r = pb('action=notify_settings');
    check('設定画面に条件が並ぶ', str_contains(flat($r), 'トラック') && str_contains(flat($r), '神奈川'));

    echo "\n== 選択肢の偽装 ==\n";
    foreach ([
        'action=notify_set&category=../../etc/passwd' => 'category',
        "action=notify_set&location=osaka'--"         => 'location',
        'action=notify_set&price_max=abc'             => 'price_max',
        'action=notify_set&price_max=1 OR 1=1'        => 'price_max',
    ] as $data => $column) {
        $before = Db::value("SELECT {$column} FROM notification_conditions WHERE line_user_id = ?", [$uid]);
        pb($data);
        $after = Db::value("SELECT {$column} FROM notification_conditions WHERE line_user_id = ?", [$uid]);
        check("偽装した {$column} は無視する", $before === $after, '変わった: ' . var_export($after, true));
    }

    echo "\n== 条件に合う新着 ==\n";
    Db::exec('DELETE FROM notification_log WHERE line_user_id = ?', [$uid]);
    $r = pb('action=new_arrivals');
    $ids = shownCarIds($r);
    check('条件に合う車両だけが出る', $ids !== [], '0件');
    $ng = Db::all("SELECT id FROM cars WHERE id IN (" . implode(',', $ids ?: [0]) . ")
                   AND (category <> 'truck' OR location <> 'kanagawa' OR status <> 'published')");
    check('条件に合わない車両は混ざらない', $ng === [], '混入: ' . json_encode($ng));
    check('見せた分を既読にする',
        (int) Db::value('SELECT COUNT(*) FROM notification_log WHERE line_user_id = ?', [$uid]) === count($ids),
        'ids=' . count($ids) . ' log=' . Db::value('SELECT COUNT(*) FROM notification_log WHERE line_user_id = ?', [$uid]));

    $r2 = pb('action=new_arrivals');
    check('2回目は同じ車両を出さない', shownCarIds($r2) === []);
    check('2回目は「新着はありません」と返す', str_contains(flat($r2), '新着はありません'));
    check('条件を変える導線を添える', str_contains(flat($r2), 'action=notify_settings'));

    echo "\n== 条件を消す ==\n";
    $r = pb('action=notify_clear');
    check('消した旨を返す', str_contains(flat($r), '条件を消しました'));
    check('DBから消える', (int) Db::value('SELECT COUNT(*) FROM notification_conditions WHERE line_user_id = ?', [$uid]) === 0);
    $r = pb('action=new_arrivals');
    check('消した後は全体の新着に戻る', bubbles($r) > 0, 'バブル=' . bubbles($r));

    echo "\n== キーワード ==\n";
    Db::exec('DELETE FROM notification_log WHERE line_user_id = ?', [$uid]);
    foreach (['新着ある？', 'あたらしい車', '入荷しましたか', '新着在庫ありますか'] as $word) {
        check("「{$word}」で新着を返す", bubbles(msg($word)) > 0);
    }
    foreach (['通知設定', 'お知らせの設定'] as $word) {
        check("「{$word}」で設定画面", str_contains(flat(msg($word)), '新着のお知らせ設定'));
    }
    check('「在庫ありますか」は通常の一覧のまま', !str_contains(flat(msg('在庫ありますか')), 'ご登録の条件'));

    echo "\n== 別のお客様と混ざらないこと ==\n";
    Db::exec("INSERT INTO line_users (line_user_id, display_name) VALUES ('Uother', '別のお客様')");
    $other = (int) Db::value("SELECT id FROM line_users WHERE line_user_id = 'Uother'");
    pb('action=notify_set&category=machinery', 'Uother');
    check('別の人の条件は別に保存される',
        (string) Db::value('SELECT category FROM notification_conditions WHERE line_user_id = ?', [$other]) === 'machinery');
    check('こちらの条件は空のまま',
        (int) Db::value('SELECT COUNT(*) FROM notification_conditions WHERE line_user_id = ?', [$uid]) === 0);
    pb('action=new_arrivals', 'Uother');
    $mine = (int) Db::value('SELECT COUNT(*) FROM notification_log WHERE line_user_id = ?', [$uid]);
    check('別の人の既読はこちらに影響しない', $mine === 0, '件数=' . $mine);

    echo "\n== 友だち登録が無いユーザー ==\n";
    $r = pb('action=new_arrivals', 'Uunknown999');
    check('落ちずに全体の新着を返す', bubbles($r) > 0 || str_contains(flat($r), '該当する車両'));
    $r = pb('action=notify_set&category=truck', 'Uunknown999');
    check('保存できない旨を返す', str_contains(flat($r), '保存できませんでした'));

    echo "\n== リッチメニュー ==\n";
    $menus = json_encode(RichMenuDefinition::all(), JSON_UNESCAPED_UNICODE) ?: '';
    check('「新着入庫」がその人向けの新着に繋がる', str_contains($menus, 'action=new_arrivals'));
    check('古い sort=new のボタンは残っていない', !str_contains($menus, 'action=cars&page=1&sort=new'));
    check('「通知設定」が残っている', str_contains($menus, 'action=notify_settings'));

    echo "\n== 準備中の扱い ==\n";
    check('通知設定はもう準備中ではない', !str_contains(flat(pb('action=notify_settings')), '準備中'));
    check('未実装の導線は準備中のまま', str_contains(flat(pb('action=my_reservations')), '準備中'));

    echo "\n" . str_repeat('-', 46) . "\n";
    echo "成功 {$pass} / 失敗 {$fail}\n\n";
    exit($fail === 0 ? 0 : 1);
}

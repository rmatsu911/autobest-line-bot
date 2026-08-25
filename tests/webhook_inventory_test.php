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
        public function __construct(?string $t = null) {}
        public function reply(string $token, array $m): LineResponse { self::$replies[] = $m; return new LineResponse(); }
        public function push(string $to, array $m, ?string $k = null): LineResponse { return new LineResponse(); }
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
    use App\LineClient;
    use App\WebhookHandler;

    $pass=0; $fail=0;
    function check(string $n, bool $c, string $x=''): void {
        global $pass,$fail;
        if ($c) { $pass++; echo "  ok   {$n}\n"; } else { $fail++; echo "  FAIL {$n} {$x}\n"; }
    }
    function fire(array $ev): array {
        LineClient::$replies = [];
        $ev += ['webhookEventId'=>'E'.bin2hex(random_bytes(6)), 'timestamp'=>0];
        (new WebhookHandler())->handleEvents([$ev]);
        return LineClient::$replies;
    }
    function pb(string $data): array {
        return fire(['type'=>'postback','replyToken'=>str_repeat('a',32),
                     'source'=>['type'=>'user','userId'=>'Utest001'],'postback'=>['data'=>$data]]);
    }
    function msg(string $text): array {
        return fire(['type'=>'message','replyToken'=>str_repeat('a',32),
                     'source'=>['type'=>'user','userId'=>'Utest001'],'message'=>['type'=>'text','text'=>$text]]);
    }

    echo "\n== 在庫カルーセル ==\n";
    $r = pb('action=cars&page=1');
    check('返信が1件', count($r) === 1);
    check('Flexで返る', ($r[0][0]['type'] ?? '') === 'flex');
    $bub = $r[0][0]['contents']['contents'];
    check('11バブル（10台＋もっと見る）', count($bub) === 11, '実際 '.count($bub));

    $r2 = pb('action=cars&page=2');
    $bub2 = $r2[0][0]['contents']['contents'];
    check('2ページ目は4台のみ（もっと見るなし）', count($bub2) === 4, '実際 '.count($bub2));

    $r3 = pb('action=cars&page=3');
    check('3ページ目は「これで最後です」', str_contains($r3[0][0]['text'] ?? '', 'これで最後'));

    echo "\n== 絞り込みの引き継ぎ ==\n";
    $r = pb('action=cars&page=1&category=passenger');
    $j = json_encode($r, JSON_UNESCAPED_UNICODE);
    check('乗用車で絞ると12台＋もっと見る', count($r[0][0]['contents']['contents']) === 11);
    check('次ページに category が引き継がれる', str_contains($j, 'page=2&category=passenger'), '');
    $r = pb('action=cars&page=1&category=machinery');
    check('重機は1台（もっと見るなし）', count($r[0][0]['contents']['contents']) === 1);
    $r = pb('action=cars&page=1&category=other');
    check('該当0件はテキスト＋条件変更', ($r[0][0]['type'] ?? '') === 'text' && isset($r[0][0]['quickReply']));

    echo "\n== お気に入り ==\n";
    App\Db::exec('DELETE FROM favorites');   // 何度流しても同じ結果になるようにする
    $r = pb('action=fav&car_id=1');
    check('追加できた', str_contains($r[0][0]['text'] ?? '', 'お気に入りに追加しました'), $r[0][0]['text'] ?? '');
    $r = pb('action=fav&car_id=1');
    check('二重追加は「すでに」', str_contains($r[0][0]['text'] ?? '', 'すでに'), $r[0][0]['text'] ?? '');
    $hidden = App\Db::value("SELECT id FROM cars WHERE stock_number='HID-draft'");
    $r = pb('action=fav&car_id='.$hidden);
    check('非公開車両はお気に入りにできない', str_contains($r[0][0]['text'] ?? '', '掲載が終了'), $r[0][0]['text'] ?? '');
    $r = pb('action=favorites');
    check('お気に入り一覧がFlexで返る', ($r[0][0]['type'] ?? '') === 'flex');
    check('お気に入りは1台', count($r[0][0]['contents']['contents']) === 1);

    echo "\n== タブ切替は無反応 ==\n";
    foreach (['tab=autobest-find','tab=autobest-sell&same=1','tab=autobest-support'] as $d) {
        check("「{$d}」で返信しない", pb($d) === []);
    }

    echo "\n== その他のpostback ==\n";
    check('stores で2拠点', str_contains(pb('action=stores')[0][0]['text'] ?? '', '福岡本社'));
    check('search_menu でクイックリプライ', isset(pb('action=search_menu')[0][0]['quickReply']));
    check('faq', str_contains(pb('action=faq')[0][0]['text'] ?? '', 'よくあるご質問'));
    // フェーズ4で査定・予約はフォームへ繋いだので、準備中なのは配信まわりだけ
    // 通知設定はフェーズ5で実装したので、準備中なのは予約確認などだけ
    check('未実装は準備中と返す', str_contains(pb('action=my_reservations')[0][0]['text'] ?? '', '準備中'));

    echo "\n== フェーズ4：申込フォームへの導線 ==\n";
    $a = pb('action=assessment')[0][0] ?? [];
    check('査定はFlexカードで返す', ($a['type'] ?? '') === 'flex');
    check('査定フォームのURLを載せる',
        str_contains(json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'assessment.php'));
    check('Flexが出せない環境向けの代替文がある', ($a['altText'] ?? '') !== '');

    $r = pb('action=reserve&car_id=3')[0][0] ?? [];
    $rj = json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    check('予約はFlexカードで返す', ($r['type'] ?? '') === 'flex');
    check('予約フォームに車両IDを引き継ぐ', str_contains($rj, 'reserve.php?car_id=3'), $rj);

    $i = pb('action=inquiry&car_id=3')[0][0] ?? [];
    check('在庫の問い合わせも予約フォームへ繋ぐ',
        str_contains(json_encode($i, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'reserve.php?car_id=3'));

    $sa = pb('action=cars&page=1');   // 既存の導線が壊れていないことの確認
    check('在庫カルーセルは今までどおり', ($sa[0][0]['type'] ?? '') === 'flex');
    check('未知のactionでも落ちない', str_contains(pb('action=nonexistent')[0][0]['text'] ?? '', '受け付けられません'));

    echo "\n== キーワードから実在庫へ ==\n";
    check('「在庫」でカルーセル', (msg('在庫ありますか')[0][0]['type'] ?? '') === 'flex');
    check('「トラック探してる」でトラック1台', count(msg('トラック探してる')[0][0]['contents']['contents'] ?? []) === 1);
    check('「重機を見たい」で重機1台', count(msg('重機を見たい')[0][0]['contents']['contents'] ?? []) === 1);
    check('「神奈川の在庫」で2台', count(msg('神奈川の在庫')[0][0]['contents']['contents'] ?? []) === 2);
    check('「お気に入り」で一覧', (msg('お気に入り')[0][0]['type'] ?? '') === 'flex');
    $sat = msg('査定したい')[0];
    check('「査定」は従来どおりの案内文', str_contains($sat[0]['text'] ?? '', '無料査定を承ります'));
    check('「査定」に申込ボタンも添える',
        ($sat[1]['type'] ?? '') === 'flex'
        && str_contains(json_encode($sat[1] ?? [], JSON_UNESCAPED_SLASHES), 'assessment.php'));
    check('「ユンボありますか」で重機', count(msg('ユンボありますか')[0][0]['contents']['contents'] ?? []) === 1);
    check('「ﾌｫｰｸﾘﾌﾄ」半角でも重機', count(msg('ﾌｫｰｸﾘﾌﾄ')[0][0]['contents']['contents'] ?? []) === 1);
    check('「ダンプ」でトラック', count(msg('ダンプ探してます')[0][0]['contents']['contents'] ?? []) === 1);
    check('「横浜で買いたい」で神奈川2台', count(msg('横浜で買いたい')[0][0]['contents']['contents'] ?? []) === 2);
    check('「アクセス」で店舗情報', str_contains(msg('アクセス教えて')[0][0]['text'] ?? '', 'autobest'));

    echo "\n".str_repeat('-',46)."\n成功 {$pass} / 失敗 {$fail}\n\n";
    exit($fail === 0 ? 0 : 1);
}

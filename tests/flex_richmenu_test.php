<?php
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/config/config.php';

use App\CarRepository;
use App\FlexBuilder;
use App\RichMenuDefinition;

$pass = 0; $fail = 0;
function check(string $n, bool $c, string $x = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$n}\n"; } else { $fail++; echo "  FAIL {$n} {$x}\n"; }
}

echo "\n== 公開中だけが出るか ==\n";
$r = CarRepository::published([], 1, 10);
check('1ページ目は10台', count($r['rows']) === 10, '実際 '.count($r['rows']));
check('次ページありと判定', $r['hasNext'] === true);
$all = [];
for ($p = 1; $p <= 3; $p++) {
    $x = CarRepository::published([], $p, 10);
    foreach ($x['rows'] as $row) $all[] = $row;
    if (!$x['hasNext']) break;
}
check('全ページで14台', count($all) === 14, '実際 '.count($all));
$statuses = array_unique(array_column($all, 'status'));
check('published 以外が混ざらない', $statuses === ['published'], implode(',', $statuses));
$names = implode('|', array_column($all, 'model_name'));
check('下書き・承認待ち・商談中・成約済みが漏れない', !str_contains($names, '非公開'));

echo "\n== 直接IDを指定しても非公開は出ないか ==\n";
foreach (['draft','pending','negotiating','sold'] as $st) {
    $row = \App\Db::one("SELECT id FROM cars WHERE stock_number = ?", ['HID-'.$st]);
    check("findPublished({$st}) が null", CarRepository::findPublished((int)$row['id']) === null);
}

echo "\n== 絞り込み ==\n";
check('重機だけ', count(CarRepository::published(['category'=>'machinery'],1,10)['rows']) === 1);
check('トラックだけ', count(CarRepository::published(['category'=>'truck'],1,10)['rows']) === 1);
check('神奈川は2台', count(CarRepository::published(['location'=>'kanagawa'],1,10)['rows']) === 2);
check('不正なカテゴリは無視され全件になる', count(CarRepository::published(['category'=>"' OR 1=1--"],1,20)['rows']) === 14);
$new = CarRepository::published(['sort'=>'new'],1,3)['rows'];
check('新着順が引ける', count($new) === 3);

echo "\n== Flexカルーセルの構造 ==\n";
$res = CarRepository::published([], 1, 10);
$flex = FlexBuilder::carousel($res['rows'], 1, $res['hasNext'], '');
check('type=flex', ($flex['type'] ?? '') === 'flex');
check('altTextがある', !empty($flex['altText']));
check('carousel', ($flex['contents']['type'] ?? '') === 'carousel');
$bubbles = $flex['contents']['contents'];
check('10台＋もっと見る＝11バブル', count($bubbles) === 11, '実際 '.count($bubbles));
check('上限12を超えない', count($bubbles) <= FlexBuilder::MAX_BUBBLES);
check('最後がもっと見る', str_contains(json_encode($bubbles[10], JSON_UNESCAPED_UNICODE), 'もっと見る'));
check('もっと見るは page=2 を指す', str_contains(json_encode($bubbles[10]), 'action=cars&page=2'));

// 並びは sort_order 順なので、画像を持つ車両（id=1）の位置を探す
$withHero = null; $withoutHero = null;
foreach (array_slice($bubbles, 0, 10) as $b) {
    if (isset($b['hero'])) { $withHero ??= $b; } else { $withoutHero ??= $b; }
}
$b0 = $withHero ?? $bubbles[0];
check('画像のある車両には hero が付く', $withHero !== null);
check('画像のない車両には hero が付かない', $withoutHero !== null);
check('写真なしの新着にも新着バッジが出る',
    $withoutHero !== null && str_contains(json_encode($withoutHero, JSON_UNESCAPED_UNICODE), '新着'));
check('bubble に body がある', isset($b0['body']));
check('bubble に footer がある', isset($b0['footer']));
$j0 = json_encode($b0, JSON_UNESCAPED_UNICODE);
check('詳細を見るのURLが車両IDを含む', str_contains($j0, 'car.php?id='));
check('問い合わせ postback', str_contains($j0, 'action=inquiry&car_id='));
check('お気に入り postback', str_contains($j0, 'action=fav&car_id='));
check('新着バッジが出る', str_contains($j0, '新着'));

echo "\n== 崩れやすい表示 ==\n";
$j = json_encode($flex, JSON_UNESCAPED_UNICODE);
check('長い車名でも wrap+maxLines が付く', str_contains($j, '"maxLines":2'));
$mach = FlexBuilder::carousel(CarRepository::published(['category'=>'machinery'],1,10)['rows'], 1, false);
$jm = json_encode($mach, JSON_UNESCAPED_UNICODE);
check('重機は稼働時間で表示', str_contains($jm, '稼働 1,240h'), $jm);
check('重機は価格応談で表示', str_contains($jm, '価格応談'));
check('応談のとき見出しが「価格」', str_contains($jm, '"text":"価格"'));
$truck = FlexBuilder::carousel(CarRepository::published(['category'=>'truck'],1,10)['rows'], 1, false);
check('トラックは万円表記', str_contains(json_encode($truck, JSON_UNESCAPED_UNICODE), '268万円'));

echo "\n== 0件 ==\n";
$empty = FlexBuilder::emptyResult('重機・作業車・フォークリフト ／ 福岡本社');
check('0件はテキスト', ($empty['type'] ?? '') === 'text');
check('条件を含む', str_contains($empty['text'], '重機'));
check('条件変更のクイックリプライ', count($empty['quickReply']['items'] ?? []) >= 4);

echo "\n== JSONとして送れるか ==\n";
$encoded = json_encode($flex, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('json_encode が成功', $encoded !== false);
check('再パースできる', json_decode((string)$encoded, true) !== null);
check('altText は400文字以内', mb_strlen($flex['altText']) <= 400);

echo "\n== リッチメニュー定義 ==\n";
foreach (RichMenuDefinition::all() as $alias => $def) {
    check("{$alias}: 領域は9個（タブ3＋ボタン6）", count($def['areas']) === 9, '実際 '.count($def['areas']));
    check("{$alias}: サイズ 2500x1686", $def['size']['width'] === 2500 && $def['size']['height'] === 1686);
    $labels = array_filter(array_column(array_column($def['areas'], 'action'), 'label'));
    check("{$alias}: ボタンラベルは20文字以内", count(array_filter($labels, fn($l) => mb_strlen($l) > 20)) === 0);
}
$find = RichMenuDefinition::all()[RichMenuDefinition::ALIAS_FIND];
check('タブ3つが richmenuswitch', count(array_filter(array_column($find['areas'],'action'), fn($a)=>$a['type']==='richmenuswitch')) === 3);
check('既定タブは selected=true', $find['selected'] === true);

echo "\n" . str_repeat('-', 46) . "\n";
echo "成功 {$pass} / 失敗 {$fail}\n\n";
exit($fail === 0 ? 0 : 1);

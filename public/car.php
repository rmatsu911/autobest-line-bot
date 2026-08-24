<?php
/**
 * 車両詳細ページ（LINE内ブラウザで開く）。
 *
 * カルーセルの「詳細を見る」から遷移する。UI設計の5枚目に対応。
 * LIFFではなく素のページにしているのは、写真を複数見せるだけならLINEログインの
 * 発行が不要で、フェーズ3だけで完結するため。
 *
 * 公開中（status='published'）以外はここから開いても表示しない。
 * 「未承認在庫と公開終了在庫がLINE経由でも漏れない」を守る要のひとつ。
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

use App\CarRepository;
use App\Config;

$id  = (int) ($_GET['id'] ?? 0);
$car = $id > 0 ? CarRepository::findPublished($id) : null;

header('Content-Type: text/html; charset=UTF-8');
// 在庫は成約で消えるため、ブラウザに長く残さない。
header('Cache-Control: no-cache, must-revalidate');
// 公開ページだが検索エンジンには載せない（在庫の正はWebサイト側）。
header('X-Robots-Tag: noindex');
header('X-Content-Type-Options: nosniff');

if ($car === null) {
    http_response_code(404);
    $shopTel = Config::get('SHOP_TEL', '');
    ?>
<!doctype html>
<html lang="ja"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>掲載終了 | AUTOBEST</title>
<style>
  body { margin:0; padding:48px 24px; font-family:'Hiragino Kaku Gothic ProN','Yu Gothic',Meiryo,sans-serif;
         background:#FDFAF3; color:#22262D; text-align:center; }
  h1 { font-size:19px; margin:0 0 12px; }
  p { font-size:14px; line-height:1.8; color:#5A5F68; margin:0; }
</style></head>
<body>
  <h1>この車両は掲載を終了しました</h1>
  <p>ご覧いただきありがとうございます。<br>
     ほかのお車はLINEのメニューからご覧いただけます。<?= $shopTel !== '' ? '<br>お電話：' . h($shopTel) : '' ?></p>
</body></html>
    <?php
    exit;
}

$images = CarRepository::images($id);
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title><?= h(($car['maker'] ?? '') . ' ' . ($car['model_name'] ?? '')) ?> | AUTOBEST</title>
<style>
  /* 配色は確定したUI設計に合わせる */
  :root {
    --navy:#1F3358; --blue:#1268C4; --blue-lt:#4FA3F0; --price:#C85A0E;
    --amber:#FFB020; --ivory:#FDFAF3; --line:#EAE4D6; --muted:#5A5F68; --text:#22262D;
  }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--ivory); color:var(--text);
         font-family:'Hiragino Kaku Gothic ProN','Yu Gothic',Meiryo,sans-serif;
         /* 下部固定ボタンとセーフエリアの分だけ余白を確保する */
         padding-bottom:calc(104px + env(safe-area-inset-bottom)); }
  header { height:52px; background:var(--navy); color:#fff; display:flex; align-items:center;
           padding:0 14px; font-size:16px; font-weight:700; }
  .gallery { display:flex; overflow-x:auto; scroll-snap-type:x mandatory; background:#F0ECE1; }
  .gallery img { width:100%; flex:0 0 100%; scroll-snap-align:center; aspect-ratio:20/13; object-fit:cover; display:block; }
  .noimg { aspect-ratio:20/13; display:flex; align-items:center; justify-content:center; color:#B4AC9C; font-size:13px; }
  main { padding:14px; display:flex; flex-direction:column; gap:10px; }
  .badges { display:flex; gap:7px; flex-wrap:wrap; align-items:center; }
  .badge { font-size:11px; border-radius:4px; padding:3px 8px; background:#EFEBE2; color:#44494F; }
  .badge.new { background:var(--amber); color:#16283F; font-weight:700; }
  .badge.loc { background:#EAF4FE; color:#1268C4; border:1px solid #BBD9F7; }
  h1 { font-size:19px; line-height:1.45; margin:0; text-wrap:pretty; }
  .card { background:#fff; border-radius:10px; padding:12px 14px; }
  .price { display:flex; align-items:flex-end; justify-content:space-between; gap:10px; }
  .price .total { font-size:27px; font-weight:900; color:var(--price); line-height:1.15; }
  .price .lbl { font-size:11px; color:var(--muted); }
  .price .body { font-size:15px; color:#44494F; }
  dl { margin:0; }
  .row { display:flex; padding:10px 0; border-bottom:1px solid #EFECE4; font-size:13px; }
  .row:last-child { border-bottom:0; }
  .row dt { width:104px; flex-shrink:0; margin:0; color:var(--muted); font-size:12.5px; }
  .row dd { margin:0; }
  .note { font-size:13px; line-height:1.8; color:#44494F; margin:6px 0 0; white-space:pre-wrap; }
  .h2 { font-size:13px; font-weight:700; margin:0; }
  footer { position:fixed; left:0; right:0; bottom:0; background:#fff; border-top:1px solid var(--line);
           padding:10px 14px calc(14px + env(safe-area-inset-bottom)); display:flex; flex-direction:column; gap:8px; }
  .btn { display:flex; align-items:center; justify-content:center; height:50px; border-radius:9px;
         font-size:15.5px; font-weight:900; text-decoration:none; border:0; width:100%;
         font-family:inherit; cursor:pointer; }
  .btn.primary { background:var(--blue); color:#fff; box-shadow:0 3px 0 #0E4E96; }
  .sub { display:flex; gap:8px; }
  .btn.ghost { height:44px; background:#fff; color:var(--text); border:2px solid #C9CDD6;
               font-size:13px; font-weight:700; }
  /* iPhoneの自動ズームを防ぐため、入力に関わる文字は16px以上にする */
  a { color:var(--blue); }
</style>
</head>
<body>

<header>車両詳細</header>

<div class="gallery">
<?php if ($images === []): ?>
  <div class="noimg" style="width:100%">写真は準備中です</div>
<?php else: foreach ($images as $img): ?>
  <img src="<?= h((string) $img['image_url']) ?>" alt="" loading="lazy">
<?php endforeach; endif; ?>
</div>

<main>
  <div class="badges">
    <?php if (car_is_new($car)): ?><span class="badge new">新着</span><?php endif; ?>
    <?php if (!empty($car['stock_number'])): ?>
      <span class="badge">在庫番号 <?= h((string) $car['stock_number']) ?></span>
    <?php endif; ?>
    <span class="badge loc"><?= h(car_location_label($car['location'] ?? null)) ?></span>
  </div>

  <h1><?= h(trim(($car['maker'] ?? '') . ' ' . ($car['model_name'] ?? '') . ' ' . ($car['grade'] ?? ''))) ?></h1>

  <div class="card price">
    <div>
      <div class="lbl"><?= (int) ($car['price_negotiable'] ?? 0) === 1 ? '価格' : '支払総額' ?></div>
      <div class="total"><?= h(car_price_short($car)) ?></div>
    </div>
    <?php if ((int) ($car['price_negotiable'] ?? 0) !== 1 && !empty($car['body_price'])): ?>
      <div style="text-align:right">
        <div class="lbl">車両本体</div>
        <div class="body"><?= h(car_price_short(['total_price' => $car['body_price'], 'price_negotiable' => 0])) ?></div>
      </div>
    <?php endif; ?>
  </div>

  <dl class="card">
    <div class="row"><dt>取扱区分</dt><dd><?= h(car_category_label($car['category'] ?? null)) ?></dd></div>
    <div class="row"><dt>年式</dt><dd><?= h(model_year($car['model_year'] ?? null)) ?></dd></div>
    <div class="row">
      <dt><?= ($car['category'] ?? '') === 'machinery' ? '稼働時間' : '走行距離' ?></dt>
      <dd><?= h(car_usage_text($car)) ?></dd>
    </div>
    <div class="row"><dt>車検</dt><dd><?= h(inspection($car['inspection_until'] === null ? null : (string) $car['inspection_until'])) ?></dd></div>
    <?php foreach (['transmission' => 'ミッション', 'fuel' => '燃料', 'body_type' => 'ボディタイプ', 'body_color' => 'ボディカラー'] as $key => $label): ?>
      <?php if (!empty($car[$key])): ?>
        <div class="row"><dt><?= h($label) ?></dt><dd><?= h((string) $car[$key]) ?></dd></div>
      <?php endif; ?>
    <?php endforeach; ?>
    <div class="row"><dt>拠点</dt><dd><?= h(car_location_label($car['location'] ?? null)) ?></dd></div>
  </dl>

  <?php if (!empty($car['note'])): ?>
    <div class="card">
      <p class="h2">車両の状態</p>
      <p class="note"><?= h((string) $car['note']) ?></p>
    </div>
  <?php endif; ?>
</main>

<footer>
  <div class="sub">
    <button type="button" class="btn ghost" onclick="send('action=fav&amp;car_id=<?= (int) $car['id'] ?>')">お気に入り</button>
    <button type="button" class="btn ghost" onclick="send('action=reserve&amp;car_id=<?= (int) $car['id'] ?>')">来店・商談予約</button>
  </div>
  <button type="button" class="btn primary" onclick="send('action=inquiry&amp;car_id=<?= (int) $car['id'] ?>')">この車について問い合わせる</button>
</footer>

<script>
// LINE内ブラウザから開いた場合、liff.sendMessages ではなく
// トークへ戻して postback を踏ませる方が確実。
// LIFF未導入のフェーズ3では、トークに戻る導線だけを提供する。
function send(data) {
  // 押した内容をトークで再現できるよう、URLスキームでトークへ戻す。
  var basicId = <?= json_encode(Config::get('LINE_BASIC_ID', ''), JSON_UNESCAPED_SLASHES) ?>;
  if (basicId) {
    location.href = 'https://line.me/R/ti/p/' + encodeURIComponent(basicId);
  } else {
    location.href = 'https://line.me/R/nv/chat';
  }
}
</script>
</body>
</html>

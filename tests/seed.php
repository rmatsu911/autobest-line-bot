<?php
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/config/config.php';
use App\Db;

Db::exec("INSERT INTO line_users (line_user_id, display_name, followed_at, blocked) VALUES (?,?,".Db::nowSql().",0)", ['Utest001','テスト太郎']);

// 公開12台（ページング確認のため10件超）＋各状態1台ずつ
$rows = [];
for ($i = 1; $i <= 12; $i++) {
    $rows[] = ['FK-'.(20000+$i), 'passenger', 'fukuoka', 'トヨタ', "ハイエースバン スーパーGL ダークプライムII 番{$i}", 2021, 48000, null, 3280000, 0, 'published', $i*10];
}
// 重機（稼働時間・価格応談）
$rows[] = ['FK-30001', 'machinery', 'kanagawa', 'コマツ', 'PC30MR-5 ミニ油圧ショベル', 2020, null, 1240, null, 1, 'published', 5];
// トラック
$rows[] = ['FK-30002', 'truck', 'kanagawa', 'いすゞ', 'エルフ 3t 平ボディ 全低床', 2019, 124000, null, 2680000, 0, 'published', 6];
// 出してはいけない4台
foreach (['draft','pending','negotiating','sold'] as $st) {
    $rows[] = ['HID-'.$st, 'passenger', 'fukuoka', 'ホンダ', "非公開 {$st}", 2022, 19000, null, 1480000, 0, $st, 1];
}
foreach ($rows as $r) {
    Db::exec("INSERT INTO cars (stock_number, category, location, maker, model_name, model_year, mileage_km, engine_hours, total_price, price_negotiable, status, sort_order, published_at)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?, CASE WHEN ? = 'published' THEN ".Db::nowSql()." ELSE NULL END)",
        array_merge($r, [$r[10]]));
}
// 代表画像（1台目のみ）
Db::exec("INSERT INTO car_images (car_id, image_url, position) VALUES (1, ?, 0)", ['https://img.example.jp/cars/1/a.jpg']);
echo "投入: 公開".Db::value("SELECT COUNT(*) FROM cars WHERE status='published'")."\n";

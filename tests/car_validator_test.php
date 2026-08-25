<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
const APP = APP_ROOT;
require __DIR__ . '/fakes.php';
require APP . '/config/config.php';

use App\CarValidator;

$pass = 0; $fail = 0;
function check(string $name, bool $cond, string $extra = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok   {$name}\n"; }
    else { $fail++; echo "  FAIL {$name} {$extra}\n"; }
}

// 妥当な入力の雛形
function base(array $over = []): array {
    return $over + [
        'maker' => 'トヨタ', 'model_name' => 'アクア', 'grade' => 'S',
        'model_year' => '2019', 'mileage_km' => '48000',
        'total_price' => '1280000', 'body_price' => '1180000',
        'inspection_until' => '2027-03-31', 'body_color' => 'パールホワイト',
        'fuel' => 'ハイブリッド', 'transmission' => 'CVT', 'body_type' => 'コンパクト',
        'note' => '禁煙車', 'status' => 'published', 'sort_order' => '10',
    ];
}

echo "\n== 出力エスケープ ==\n";
check('スクリプトタグを無害化', h('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;');
check('シングルクォートも変換（属性値を守る）', h("' onerror='x") === '&#039; onerror=&#039;x');
check('ダブルクォートも変換', h('a"b') === 'a&quot;b');
check('アンパサンド', h('a&b') === 'a&amp;b');
check('nullを空文字にする', h(null) === '');
check('不正なUTF-8で出力が空にならない', h("\xC3\x28abc") !== '', '実際: ' . var_export(h("\xC3\x28abc"), true));

echo "\n== 表示ヘルパ ==\n";
check('金額に3桁カンマ', yen(1280000) === '1,280,000円', yen(1280000));
check('金額未設定は応談', yen(null) === '応談');
check('走行距離1万km以上は万km表記', mileage(48000) === '4.8万km', mileage(48000));
check('端数のない万km', mileage(50000) === '5万km', mileage(50000));
check('1万km未満はkm表記', mileage(8500) === '8,500km', mileage(8500));
check('走行距離未設定', mileage(null) === '不明');
check('車検満了日', inspection('2027-03-31') === '2027年3月まで', inspection('2027-03-31'));
check('車検なし（NULL）', inspection(null) === '車検なし');
check('車検なし（ゼロ日付）', inspection('0000-00-00') === '車検なし');
check('ステータス表示', car_status_label('published') === '公開中' && car_status_label('sold') === '成約済み');

echo "\n== 入力検証：正常系 ==\n";
check('妥当な入力は通る', CarValidator::validate(base()) === [], json_encode(CarValidator::validate(base()), JSON_UNESCAPED_UNICODE));
check('任意項目が空でも下書きなら通る',
    CarValidator::validate(['maker' => 'ホンダ', 'model_name' => 'フィット', 'status' => 'draft']) === []);

echo "\n== 入力検証：必須 ==\n";
check('メーカー必須', isset(CarValidator::validate(base(['maker' => '']))['maker']));
check('車種名必須', isset(CarValidator::validate(base(['model_name' => '']))['model_name']));
check('空白のみは未入力扱い', isset(CarValidator::validate(base(['maker' => '   ']))['maker']));

echo "\n== 入力検証：数値 ==\n";
check('年式に文字は不可', isset(CarValidator::validate(base(['model_year' => '平成31']))['model_year']));
check('年式が過去すぎる', isset(CarValidator::validate(base(['model_year' => '1800']))['model_year']));
check('年式が未来すぎる', isset(CarValidator::validate(base(['model_year' => '2099']))['model_year']));
check('翌年までは許可', !isset(CarValidator::validate(base(['model_year' => (string)((int)date('Y')+1)]))['model_year']));
check('走行距離にカンマは不可', isset(CarValidator::validate(base(['mileage_km' => '48,000']))['mileage_km']));
check('走行距離に負数は不可', isset(CarValidator::validate(base(['mileage_km' => '-1']))['mileage_km']));
check('価格が非現実的に大きい', isset(CarValidator::validate(base(['total_price' => '999999999']))['total_price']));
check('支払総額 < 本体価格 を検出',
    isset(CarValidator::validate(base(['total_price' => '1000000', 'body_price' => '1180000']))['total_price']));
check('支払総額 = 本体価格 は許可',
    !isset(CarValidator::validate(base(['total_price' => '1180000', 'body_price' => '1180000']))['total_price']));

echo "\n== 入力検証：日付 ==\n";
check('存在しない日付を拒否', isset(CarValidator::validate(base(['inspection_until' => '2026-02-31']))['inspection_until']));
check('形式違いを拒否', isset(CarValidator::validate(base(['inspection_until' => '2027/03/31']))['inspection_until']));
check('空欄は許可（車検なし）', !isset(CarValidator::validate(base(['inspection_until' => '']))['inspection_until']));
check('うるう日は許可', !isset(CarValidator::validate(base(['inspection_until' => '2028-02-29']))['inspection_until']));

echo "\n== 入力検証：選択肢の偽装 ==\n";
check('燃料に選択肢外の値', isset(CarValidator::validate(base(['fuel' => '核融合']))['fuel']));
check('ステータスに選択肢外の値', isset(CarValidator::validate(base(['status' => 'hacked']))['status']));
check('SQL断片を選択肢に入れても弾く', isset(CarValidator::validate(base(['body_type' => "' OR 1=1--"]))['body_type']));

echo "\n== 入力検証：公開時の必須条件 ==\n";
check('公開には支払総額が必要',
    isset(CarValidator::validate(base(['status' => 'published', 'total_price' => '']))['total_price']));
check('公開には年式が必要',
    isset(CarValidator::validate(base(['status' => 'published', 'model_year' => '']))['model_year']));
check('下書きなら空欄でも通る',
    CarValidator::validate(base(['status' => 'draft', 'total_price' => '', 'model_year' => ''])) === []);

echo "\n== 入力検証：長さ ==\n";
check('メーカー64文字超', isset(CarValidator::validate(base(['maker' => str_repeat('あ', 65)]))['maker']));
check('メーカー64文字ちょうどは可', !isset(CarValidator::validate(base(['maker' => str_repeat('あ', 64)]))['maker']));
check('備考5000文字超', isset(CarValidator::validate(base(['note' => str_repeat('あ', 5001)]))['note']));

echo "\n" . str_repeat('-', 46) . "\n";
echo "成功 {$pass} / 失敗 {$fail}\n\n";
exit($fail === 0 ? 0 : 1);

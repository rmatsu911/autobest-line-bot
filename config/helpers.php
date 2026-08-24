<?php
/**
 * テンプレート用のヘルパ関数。あえてグローバル名前空間に置く。
 *
 * 出力エスケープは「書き忘れないこと」が最重要なので、
 * View::escape() のような長い記述ではなく h() で書けるようにしている。
 * 短ければ短いほど、素の <?= $v ?> を書いてしまう事故が減る。
 */

declare(strict_types=1);

/**
 * HTMLエスケープ。管理画面の出力は例外なくこれを通す。
 *
 * ENT_QUOTES     … シングルクォートも変換する（属性値を ' で囲っても破られない）
 * ENT_SUBSTITUTE … 不正なUTF-8を空文字ではなく U+FFFD に置換する。
 *                  これが無いと壊れたバイト列で出力全体が空になり、
 *                  「表示されないだけ」に見えて原因追跡が難しくなる。
 */
function h(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 3桁カンマ区切りの金額。未設定は「応談」と表示する。 */
function yen(int|string|null $value): string
{
    if ($value === null || $value === '') {
        return '応談';
    }
    return number_format((int) $value) . '円';
}

/** 走行距離。1万km以上は「◯.◯万km」の方が読みやすい。 */
function mileage(int|string|null $km): string
{
    if ($km === null || $km === '') {
        return '不明';
    }
    $km = (int) $km;
    return $km >= 10000
        ? rtrim(rtrim(number_format($km / 10000, 1), '0'), '.') . '万km'
        : number_format($km) . 'km';
}

/** 年式。 */
function model_year(int|string|null $year): string
{
    return ($year === null || $year === '') ? '不明' : (string) ((int) $year) . '年';
}

/** 車検満了日。 */
function inspection(?string $date): string
{
    if ($date === null || $date === '' || $date === '0000-00-00') {
        return '車検なし';
    }
    $ts = strtotime($date);
    return $ts === false ? '車検なし' : date('Y年n月', $ts) . 'まで';
}

/** 在庫ステータスの表示名。 */
function car_status_label(string $status): string
{
    return match ($status) {
        'draft'       => '下書き',
        'pending'     => '承認待ち',
        'published'   => '公開中',
        'negotiating' => '商談中',
        'sold'        => '成約済み',
        default       => $status,
    };
}

/**
 * フォームの再表示用。入力エラーで戻したときに値を保つ。
 * $posted が優先、無ければ既存レコードの値。
 */
function old(array $posted, array $record, string $key, string|int|null $default = ''): string
{
    if (array_key_exists($key, $posted)) {
        return (string) $posted[$key];
    }
    if (array_key_exists($key, $record) && $record[$key] !== null) {
        return (string) $record[$key];
    }
    return (string) $default;
}

// -----------------------------------------------------------------------------
// 車両の表示ヘルパ（フェーズ3で追加）
//   LINEのFlexメッセージ・車両詳細ページ・管理画面で同じ文言を使うため、
//   表示の決まりごとはここに集約する。
// -----------------------------------------------------------------------------

/** 取扱区分の表示名 */
function car_category_label(?string $category): string
{
    return match ($category) {
        'passenger' => '乗用車・軽自動車',
        'truck'     => 'トラック・バス',
        'machinery' => '重機・作業車・フォークリフト',
        'other'     => 'その他車両',
        default     => '',
    };
}

/** 拠点の表示名 */
function car_location_label(?string $location): string
{
    return match ($location) {
        'fukuoka'  => '福岡本社',
        'kanagawa' => '神奈川支店',
        default    => '',
    };
}

/**
 * 価格の表示。
 * 「応談」は未入力とは別物なので、price_negotiable を先に見る。
 * 金額を入れ忘れた車両を勝手に「応談」と出すと、後で値付けの齟齬になる。
 */
function car_price_text(array $car): string
{
    if ((int) ($car['price_negotiable'] ?? 0) === 1) {
        return '価格応談';
    }
    $total = $car['total_price'] ?? null;
    if ($total === null || $total === '') {
        return '価格応談';
    }
    return number_format((int) $total) . '円';
}

/** カルーセル用の短い価格表記（「328.0万円」）。桁が多いとバブルで折り返すため */
function car_price_short(array $car): string
{
    if ((int) ($car['price_negotiable'] ?? 0) === 1) {
        return '価格応談';
    }
    $total = $car['total_price'] ?? null;
    if ($total === null || $total === '') {
        return '価格応談';
    }
    $man = (int) $total / 10000;
    return rtrim(rtrim(number_format($man, 1), '0'), '.') . '万円';
}

/**
 * 使用状況の表記。
 * 重機・フォークリフトは走行距離ではなく稼働時間で状態を示すので、
 * 取扱区分によって出す項目を変える。
 */
function car_usage_text(array $car): string
{
    if (($car['category'] ?? '') === 'machinery') {
        $h = $car['engine_hours'] ?? null;
        return ($h === null || $h === '') ? '稼働時間不明' : '稼働 ' . number_format((int) $h) . 'h';
    }
    return mileage($car['mileage_km'] ?? null);
}

/**
 * 新着かどうか。published_at から $days 日以内。
 * created_at を使わないのは、下書きのまま寝かせた車両が
 * 公開した瞬間から「古い」扱いになってしまうため。
 */
function car_is_new(array $car, int $days = 14): bool
{
    $publishedAt = $car['published_at'] ?? null;
    if ($publishedAt === null || $publishedAt === '') {
        return false;
    }
    $ts = strtotime((string) $publishedAt);
    return $ts !== false && $ts >= strtotime("-{$days} days");
}

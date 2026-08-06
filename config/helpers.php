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
        'published' => '公開中',
        'draft'     => '下書き',
        'sold'      => '成約済み',
        default     => $status,
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

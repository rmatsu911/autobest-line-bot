<?php
/**
 * 在庫フォームの入力検証。
 *
 * 画面側と分けているのは、同じ規則をフェーズ4以降（CSV取り込みなど）でも
 * 使い回せるようにするため。エラーメッセージはそのまま画面に出してよい内容にする。
 */

declare(strict_types=1);

namespace App;

final class CarValidator
{
    public const FUELS         = ['ガソリン', 'ハイブリッド', 'ディーゼル', 'LPG', '電気', 'その他'];
    public const TRANSMISSIONS = ['AT', 'CVT', 'MT', 'その他'];
    public const BODY_TYPES    = ['軽自動車', 'コンパクト', 'セダン', 'ワゴン', 'ミニバン', 'SUV', 'クーペ', 'オープン', 'トラック', 'その他'];
    public const STATUSES      = ['draft', 'pending', 'published', 'negotiating', 'sold'];
    public const CATEGORIES    = ['passenger', 'truck', 'machinery', 'other'];
    public const LOCATIONS     = ['fukuoka', 'kanagawa'];

    /**
     * @return array<string,string> 列名 => エラーメッセージ。空配列なら問題なし。
     */
    public static function validate(array $input): array
    {
        $errors = [];

        // --- 必須 ---
        if (self::str($input, 'maker') === '') {
            $errors['maker'] = 'メーカーを入力してください。';
        } elseif (mb_strlen(self::str($input, 'maker')) > 64) {
            $errors['maker'] = 'メーカーは64文字以内で入力してください。';
        }

        if (self::str($input, 'model_name') === '') {
            $errors['model_name'] = '車種名を入力してください。';
        } elseif (mb_strlen(self::str($input, 'model_name')) > 128) {
            $errors['model_name'] = '車種名は128文字以内で入力してください。';
        }

        if (mb_strlen(self::str($input, 'grade')) > 128) {
            $errors['grade'] = 'グレードは128文字以内で入力してください。';
        }

        // --- 数値 ---
        $year = self::str($input, 'model_year');
        if ($year !== '') {
            if (!ctype_digit($year)) {
                $errors['model_year'] = '年式は半角数字で入力してください。';
            } elseif ((int) $year < 1950 || (int) $year > (int) date('Y') + 1) {
                $errors['model_year'] = '年式は1950〜' . ((int) date('Y') + 1) . 'の範囲で入力してください。';
            }
        }

        foreach ([
            'mileage_km'  => ['走行距離', 2_000_000],
            'total_price' => ['支払総額', 100_000_000],
            'body_price'  => ['車両本体価格', 100_000_000],
        ] as $column => [$label, $max]) {
            $value = self::str($input, $column);
            if ($value === '') {
                continue;
            }
            if (!ctype_digit($value)) {
                $errors[$column] = $label . 'は半角数字で入力してください（カンマ不要）。';
            } elseif ((int) $value > $max) {
                $errors[$column] = $label . 'の値が大きすぎます。';
            }
        }

        // 支払総額は車両本体価格以上のはず。逆転していたら入力ミスの可能性が高い。
        $total = self::str($input, 'total_price');
        $body  = self::str($input, 'body_price');
        if ($total !== '' && $body !== '' && ctype_digit($total) && ctype_digit($body) && (int) $total < (int) $body) {
            $errors['total_price'] = '支払総額が車両本体価格を下回っています。入力を確認してください。';
        }

        // --- 日付 ---
        $inspection = self::str($input, 'inspection_until');
        if ($inspection !== '' && !self::isDate($inspection)) {
            $errors['inspection_until'] = '車検満了日は YYYY-MM-DD の形式で入力してください。';
        }

        // --- 在庫番号 ---
        $stock = self::str($input, 'stock_number');
        if ($stock !== '') {
            // 半角英数とハイフンのみ。全角や空白が混ざるとUNIQUE制約をすり抜けた
            // 「見た目は同じだが別の値」が生まれる。
            if (preg_match('/\A[A-Za-z0-9-]{1,32}\z/', $stock) !== 1) {
                $errors['stock_number'] = '在庫番号は半角英数字とハイフンの32文字以内で入力してください。';
            }
        }

        // --- 稼働時間（重機） ---
        $hours = self::str($input, 'engine_hours');
        if ($hours !== '') {
            if (!ctype_digit($hours)) {
                $errors['engine_hours'] = '稼働時間は半角数字で入力してください。';
            } elseif ((int) $hours > 200000) {
                $errors['engine_hours'] = '稼働時間の値が大きすぎます。';
            }
        }

        // 重機は走行距離ではなく稼働時間で状態を示すので、公開時はどちらかを求める。
        if (self::str($input, 'status') === 'published' && self::str($input, 'category') === 'machinery'
            && $hours === '' && self::str($input, 'mileage_km') === '') {
            $errors['engine_hours'] = '重機を公開するには稼働時間の入力が必要です。';
        }

        // --- 選択肢 ---
        foreach ([
            'fuel'         => self::FUELS,
            'transmission' => self::TRANSMISSIONS,
            'body_type'    => self::BODY_TYPES,
            'status'       => self::STATUSES,
            'category'     => self::CATEGORIES,
            'location'     => self::LOCATIONS,
        ] as $column => $allowed) {
            $value = self::str($input, $column);
            if ($value !== '' && !in_array($value, $allowed, true)) {
                // 選択肢はセレクトボックスなので、通常ここには来ない。
                // 来るのはリクエストを直接作られた場合なので、DBに入れる前に落とす。
                $errors[$column] = '選択できない値が指定されました。';
            }
        }

        if (mb_strlen(self::str($input, 'body_color')) > 32) {
            $errors['body_color'] = 'ボディカラーは32文字以内で入力してください。';
        }
        if (mb_strlen(self::str($input, 'note')) > 5000) {
            $errors['note'] = '備考は5000文字以内で入力してください。';
        }

        // --- 公開するなら最低限の情報が揃っていること ---
        // 公開後にLINEのカルーセルへ出るので、価格と年式が空のまま出さない。
        if (self::str($input, 'status') === 'published') {
            // 「応談」を選んでいる場合は金額の入力を求めない。
            $negotiable = (string) ($input['price_negotiable'] ?? '0') === '1';
            if (!$negotiable && self::str($input, 'total_price') === '') {
                $errors['total_price'] = '公開するには支払総額の入力、または「価格応談」の指定が必要です。';
            }
            if (self::str($input, 'model_year') === '') {
                $errors['model_year'] = '公開するには年式の入力が必要です。';
            }
        }

        return $errors;
    }

    private static function str(array $input, string $key): string
    {
        return trim((string) ($input[$key] ?? ''));
    }

    /** 存在する日付かどうか（2026-02-31 のような日付を弾く） */
    private static function isDate(string $value): bool
    {
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) !== 1) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}

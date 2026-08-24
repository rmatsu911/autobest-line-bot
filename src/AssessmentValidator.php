<?php
/**
 * 査定申込フォームの入力検証。
 *
 * 必須は「お名前・電話番号・メーカー・車種」の4つだけにしている。
 * 年式や走行距離まで必須にすると、うろ覚えの人がそこで離脱する。
 * 足りない情報は折り返しの電話で埋める方が確実で、申込自体を取りこぼさない。
 */

declare(strict_types=1);

namespace App;

final class AssessmentValidator
{
    /**
     * @return array{errors: array<string,string>, values: array<string,mixed>}
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $v      = [];

        foreach (['contact_name', 'contact_tel', 'contact_email', 'contact_pref',
                  'maker', 'model_name', 'grade', 'model_year', 'mileage_km',
                  'inspection_until', 'category', 'message'] as $key) {
            $v[$key] = ContactRules::trim($input, $key);
        }

        // --- 連絡先 ---
        if (($e = ContactRules::nameError($v['contact_name'])) !== null) {
            $errors['contact_name'] = $e;
        }
        if (($e = ContactRules::telError($v['contact_tel'])) !== null) {
            $errors['contact_tel'] = $e;
        }
        if (($e = ContactRules::emailError($v['contact_email'])) !== null) {
            $errors['contact_email'] = $e;
        }
        if ($v['contact_pref'] !== '' && !in_array($v['contact_pref'], ContactRules::CONTACT_PREFS, true)) {
            $errors['contact_pref'] = 'ご希望の連絡時間帯をお選びください。';
        }

        // --- 車両 ---
        if ($v['maker'] === '') {
            $errors['maker'] = 'メーカーを入力してください。';
        } elseif (mb_strlen($v['maker']) > 64) {
            $errors['maker'] = 'メーカーは64文字以内で入力してください。';
        }

        if ($v['model_name'] === '') {
            $errors['model_name'] = '車種を入力してください。';
        } elseif (mb_strlen($v['model_name']) > 128) {
            $errors['model_name'] = '車種は128文字以内で入力してください。';
        }

        if (mb_strlen($v['grade']) > 128) {
            $errors['grade'] = 'グレードは128文字以内で入力してください。';
        }

        if ($v['model_year'] !== '') {
            // 全角で入れる人が多いので半角に寄せてから見る。
            $v['model_year'] = mb_convert_kana($v['model_year'], 'n');
            if (!ctype_digit($v['model_year'])) {
                $errors['model_year'] = '年式は半角数字4桁で入力してください。';
            } elseif ((int) $v['model_year'] < 1950 || (int) $v['model_year'] > (int) date('Y') + 1) {
                $errors['model_year'] = '年式は1950〜' . ((int) date('Y') + 1) . 'の範囲で入力してください。';
            }
        }

        if ($v['mileage_km'] !== '') {
            $v['mileage_km'] = str_replace(',', '', mb_convert_kana($v['mileage_km'], 'n'));
            if (!ctype_digit($v['mileage_km'])) {
                $errors['mileage_km'] = '走行距離は半角数字で入力してください（km）。';
            } elseif ((int) $v['mileage_km'] > 2_000_000) {
                $errors['mileage_km'] = '走行距離をご確認ください。';
            }
        }

        if ($v['inspection_until'] !== '' && !preg_match('/\A\d{4}-\d{2}\z/', $v['inspection_until'])) {
            $errors['inspection_until'] = '車検の満了は年月でお選びください。';
        }

        if ($v['category'] !== '' && !in_array($v['category'], CarValidator::CATEGORIES, true)) {
            $errors['category'] = '種別をお選びください。';
        }

        if (mb_strlen($v['message']) > 2000) {
            $errors['message'] = 'ご要望は2000文字以内で入力してください。';
        }

        return ['errors' => $errors, 'values' => $v];
    }

    /**
     * DBに入れる形に整える。
     * 車両情報は payload（JSON）にまとめる。査定は項目が増えやすく、
     * そのたびに列を足すと移行が増える一方なので、検索しない項目はJSONに置く。
     *
     * @param array<string,string> $v validate() の values
     */
    public static function toRecord(array $v): array
    {
        return [
            'kind'          => 'assessment',
            'source'        => 'web',
            'contact_name'  => $v['contact_name'],
            'contact_tel'   => ContactRules::normalizeTel($v['contact_tel']),
            'contact_email' => $v['contact_email'] !== '' ? $v['contact_email'] : null,
            'contact_pref'  => $v['contact_pref'] !== '' ? $v['contact_pref'] : null,
            'message'       => $v['message'] !== '' ? $v['message'] : null,
            'payload'       => array_filter([
                'メーカー'   => $v['maker'],
                '車種'       => $v['model_name'],
                'グレード'   => $v['grade'],
                '年式'       => $v['model_year'],
                '走行距離'   => $v['mileage_km'] !== '' ? $v['mileage_km'] . 'km' : '',
                '車検満了'   => $v['inspection_until'],
                '種別'       => $v['category'] !== '' ? car_category_label($v['category']) : '',
            ], static fn (string $value): bool => $value !== ''),
        ];
    }
}

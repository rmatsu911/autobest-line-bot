<?php
/**
 * 来店・商談予約フォームの入力検証。
 *
 * 第1希望だけを必須にしている。第2・第3を必須にすると入力が重くなり、
 * 「とりあえず日曜の午後に行きたい」という一番多い要望を弾いてしまう。
 */

declare(strict_types=1);

namespace App;

final class ReservationValidator
{
    /**
     * @return array{errors: array<string,string>, values: array<string,mixed>}
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $v      = [];

        foreach (['contact_name', 'contact_tel', 'contact_email', 'location', 'purpose',
                  'preferred_1', 'preferred_2', 'preferred_3', 'note', 'car_id'] as $key) {
            $v[$key] = ContactRules::trim($input, $key);
        }

        if (($e = ContactRules::nameError($v['contact_name'])) !== null) {
            $errors['contact_name'] = $e;
        }
        if (($e = ContactRules::telError($v['contact_tel'])) !== null) {
            $errors['contact_tel'] = $e;
        }
        if (($e = ContactRules::emailError($v['contact_email'])) !== null) {
            $errors['contact_email'] = $e;
        }

        if (!in_array($v['location'], ReservationRepository::LOCATIONS, true)) {
            $errors['location'] = 'ご希望の拠点をお選びください。';
        }
        if (!in_array($v['purpose'], ReservationRepository::PURPOSES, true)) {
            $v['purpose'] = 'visit';
        }

        $parsed = [];
        foreach ([1 => '第1希望', 2 => '第2希望', 3 => '第3希望'] as $n => $label) {
            [$value, $error] = ContactRules::parsePreferred($v["preferred_{$n}"], $label, $n === 1);
            if ($error !== null) {
                $errors["preferred_{$n}"] = $error;
            }
            $parsed["preferred_{$n}"] = $value;
        }

        // 同じ日時を2つ以上選んでも意味が無い（候補が実質1つに減る）ので気づかせる。
        $filled = array_filter([$parsed['preferred_1'], $parsed['preferred_2'], $parsed['preferred_3']]);
        if (count($filled) !== count(array_unique($filled))) {
            $errors['preferred_2'] = '同じ日時が重複しています。別の候補をお選びください。';
        }

        if ($v['car_id'] !== '' && !ctype_digit($v['car_id'])) {
            $v['car_id'] = '';
        }

        if (mb_strlen($v['note']) > 2000) {
            $errors['note'] = 'ご要望は2000文字以内で入力してください。';
        }

        // 並びに注意。PHPの + は「左側の値を優先」するので、
        // $v + $parsed だと画面から来た生の文字列（"2026-09-01T11:00" や ""）が残り、
        // 整形済みの値（"2026-09-01 11:00:00" / null）が捨てられる。
        // 空文字のまま DATETIME 列へ入れると MySQL は 1292 で拒否し、
        // SQLite は黙って空文字を保存してしまう（どちらも予約が壊れる）。
        return ['errors' => $errors, 'values' => $parsed + $v];
    }

    /** @param array<string,mixed> $v validate() の values */
    public static function toRecord(array $v): array
    {
        return [
            'source'        => 'web',
            'location'      => (string) $v['location'],
            'purpose'       => (string) $v['purpose'],
            'contact_name'  => (string) $v['contact_name'],
            'contact_tel'   => ContactRules::normalizeTel((string) $v['contact_tel']),
            'contact_email' => $v['contact_email'] !== '' ? (string) $v['contact_email'] : null,
            'preferred_1'   => (string) $v['preferred_1'],
            // 空文字は NULL にする。DATETIME 列に '' は入らない。
            'preferred_2'   => ($v['preferred_2'] ?? '') !== '' ? $v['preferred_2'] : null,
            'preferred_3'   => ($v['preferred_3'] ?? '') !== '' ? $v['preferred_3'] : null,
            'car_id'        => $v['car_id'] !== '' ? (int) $v['car_id'] : null,
            'note'          => $v['note'] !== '' ? (string) $v['note'] : null,
        ];
    }
}

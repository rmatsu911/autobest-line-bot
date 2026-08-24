<?php
/**
 * 公開フォームで共通に使う入力規則。
 *
 * 査定申込と来店予約で「氏名」「電話番号」「希望日時」の扱いがずれると、
 * 片方だけ全角ハイフンを通す、といった差が生まれるのでここに寄せる。
 */

declare(strict_types=1);

namespace App;

final class ContactRules
{
    /** 希望連絡時間帯の選択肢 */
    public const CONTACT_PREFS = ['いつでも可', '午前中', '14時〜17時', '17時以降', 'LINEで連絡'];

    /** 予約を受け付ける時間帯（時）。営業時間より少し広めに取り、細かい調整は電話で行う。 */
    private const OPEN_HOUR  = 9;
    private const CLOSE_HOUR = 19;

    /** 何日先まで予約を受け付けるか */
    private const MAX_DAYS_AHEAD = 90;

    /** 何時間先から受け付けるか（直前予約は準備が間に合わない） */
    private const MIN_HOURS_AHEAD = 2;

    /**
     * 入力を1つ取り出して前後の空白を落とす。
     *
     * trim() の第2引数を使わないのは、あれがバイト単位で削るため。
     * 全角スペース（U+3000 = E3 80 80）を削り文字に渡すと、
     * 「トヨタ」の先頭バイト E3 まで一緒に削られて文字化けする。
     * 正規表現の /u で文字単位に扱う。
     *
     * 壊れたUTF-8が混ざったまま先へ進めると、後で json_encode が false を返し、
     * 申込内容が空で保存される。ここで直しておく。
     */
    public static function trim(array $input, string $key): string
    {
        $value = $input[$key] ?? '';
        if (!is_scalar($value)) {
            return '';
        }
        $value = (string) $value;

        // 不正なバイト列を捨てる（文字化けのまま保存しない）
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = (string) mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        $trimmed = preg_replace('/\A[\s\x{3000}]+|[\s\x{3000}]+\z/u', '', $value);

        // preg_replace は失敗すると null を返す。黙って空にすると入力が消えるので元の値を使う。
        return $trimmed ?? trim($value);
    }

    /** 氏名。空なら「必須」、長すぎれば桁のエラーを返す。問題なければ null。 */
    public static function nameError(string $value, string $label = 'お名前'): ?string
    {
        if ($value === '') {
            return $label . 'を入力してください。';
        }
        if (mb_strlen($value) > 64) {
            return $label . 'は64文字以内で入力してください。';
        }
        return null;
    }

    /**
     * 電話番号。
     * 数字・ハイフン・括弧・+ のみを許す。桁は10〜11桁を基本としつつ、
     * 国際番号や内線付きも通せるよう数字10〜15桁で見る。
     */
    public static function telError(string $value): ?string
    {
        if ($value === '') {
            return '電話番号を入力してください。';
        }
        // 全角で入力されることが多いので、判定の前に半角へ寄せる。
        $normalized = mb_convert_kana($value, 'a');
        if (!preg_match('/\A[0-9+()\-\s]{10,24}\z/', $normalized)) {
            return '電話番号は半角数字とハイフンで入力してください。';
        }
        $digits = preg_replace('/\D/', '', $normalized) ?? '';
        if (strlen($digits) < 10 || strlen($digits) > 15) {
            return '電話番号の桁数をご確認ください。';
        }
        return null;
    }

    /** メールアドレス（任意項目）。空なら検証しない。 */
    public static function emailError(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > 191) {
            return 'メールアドレスが長すぎます。';
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return 'メールアドレスの形式をご確認ください。';
        }
        return null;
    }

    /** 電話番号を保存用に整える（全角→半角、余分な空白を除去） */
    public static function normalizeTel(string $value): string
    {
        $normalized = mb_convert_kana($value, 'a');
        return trim((string) preg_replace('/\s+/', '', $normalized));
    }

    /**
     * <input type="datetime-local"> の値を "Y-m-d H:i:00" に変換する。
     *
     * ブラウザは "2026-09-01T10:00" を送ってくるが、秒を付けてくる端末もある。
     * strtotime に任せず形を固定して読むのは、"next monday" のような
     * 文字列を投げ込まれても解釈しないようにするため。
     *
     * @return array{0:?string,1:?string} [保存用の値, エラーメッセージ]
     */
    public static function parsePreferred(string $value, string $label, bool $required): array
    {
        if ($value === '') {
            return [null, $required ? $label . 'を選んでください。' : null];
        }

        $at = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $value);

        if ($at === false) {
            return [null, $label . 'の形式をご確認ください。'];
        }

        $now = new \DateTimeImmutable('now');
        if ($at < $now->modify('+' . self::MIN_HOURS_AHEAD . ' hours')) {
            return [null, $label . 'は' . self::MIN_HOURS_AHEAD . '時間以上先の日時を選んでください。'];
        }
        if ($at > $now->modify('+' . self::MAX_DAYS_AHEAD . ' days')) {
            return [null, $label . 'は' . self::MAX_DAYS_AHEAD . '日以内で選んでください。'];
        }

        $hour = (int) $at->format('G');
        if ($hour < self::OPEN_HOUR || $hour >= self::CLOSE_HOUR) {
            return [null, $label . 'は' . self::OPEN_HOUR . ':00〜' . self::CLOSE_HOUR . ':00の間で選んでください。'];
        }

        return [$at->format('Y-m-d H:i:00'), null];
    }

    /** フォームの min / max に入れる値（過去日を選べないようにする） */
    public static function preferredMin(): string
    {
        return (new \DateTimeImmutable('now'))->modify('+' . self::MIN_HOURS_AHEAD . ' hours')->format('Y-m-d\TH:i');
    }

    public static function preferredMax(): string
    {
        return (new \DateTimeImmutable('now'))->modify('+' . self::MAX_DAYS_AHEAD . ' days')->format('Y-m-d\TH:i');
    }
}

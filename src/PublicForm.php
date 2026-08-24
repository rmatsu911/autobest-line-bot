<?php
/**
 * 公開フォーム（査定申込・来店予約）の共通処理。
 *
 * 管理画面の Auth と分けている理由：
 *   - セッション名が同じだと、店舗のPCで管理画面にログインしたまま
 *     フォームを開いたときに同じセッションを共有してしまう
 *   - 公開フォームには「ログイン」という概念が無く、必要なのは
 *     CSRF・いたずら送信対策・連投防止の3つだけ
 *
 * 迷惑送信への構えは3枚。どれか1枚をすり抜けても他が効くようにしている。
 *   1) ハニーポット … 人には見えない入力欄。自動投稿は素直に埋めてくる
 *   2) 経過時間     … フォーム表示から3秒未満の送信は人の入力ではない
 *   3) 連投制限     … 同じIPからの送信を1時間あたり N 件までに絞る
 *
 * CAPTCHA は入れていない。外部スクリプトを読み込むとXserverのWAFや
 * LINE内ブラウザで表示が崩れることがあり、来店予約の取りこぼしの方が痛いため。
 */

declare(strict_types=1);

namespace App;

final class PublicForm
{
    /** 人には見えない入力欄の名前。自動投稿はこれを埋めてしまう。 */
    private const HONEYPOT = 'company_name';

    /** これより早い送信は機械とみなす（秒） */
    private const MIN_SECONDS = 3;

    /** フォームの有効期限。長すぎると使い回されるので1時間で切る（秒） */
    private const MAX_SECONDS = 3600;

    /** 同じIPからの送信上限（1時間あたり） */
    private const MAX_PER_HOUR = 5;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('ABFORM');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => (($_SERVER['HTTPS'] ?? '') !== '') || (($_SERVER['SERVER_PORT'] ?? '') === '443'),
        ]);
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_start();
    }

    public static function token(): string
    {
        self::startSession();
        if (empty($_SESSION['form_token'])) {
            $_SESSION['form_token'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['form_token'];
    }

    /**
     * hidden 一式（トークン・発行時刻・ハニーポット）をまとめて出力する。
     * 3つに分けると、どれかを入れ忘れたフォームができるため1つにまとめている。
     */
    public static function fields(): string
    {
        $issued = (string) time();

        return '<input type="hidden" name="_token" value="' . h(self::token()) . '">'
            . '<input type="hidden" name="_issued" value="' . h($issued) . '">'
            . '<input type="hidden" name="_issued_sig" value="' . h(self::signIssued($issued)) . '">'
            // 見た目にも読み上げにも出さない。autocomplete=off でブラウザの自動入力も防ぐ。
            . '<div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;height:0;overflow:hidden">'
            . '<label>会社名<input type="text" name="' . self::HONEYPOT . '" tabindex="-1" autocomplete="off"></label>'
            . '</div>';
    }

    /**
     * 送信を検証する。問題なければ null、あれば画面に出す文言を返す。
     * 文言は「なぜ弾いたか」を書きすぎない。対策の内容を教える必要はない。
     */
    public static function verify(array $post): ?string
    {
        self::startSession();

        $sent = (string) ($post['_token'] ?? '');
        if ($sent === '' || !hash_equals((string) ($_SESSION['form_token'] ?? ''), $sent)) {
            Logger::warning('公開フォームのトークンが不正です', ['ip' => self::ip(), 'uri' => $_SERVER['REQUEST_URI'] ?? '-']);
            return '送信内容を確認できませんでした。お手数ですが、もう一度入力してください。';
        }

        if (($post[self::HONEYPOT] ?? '') !== '') {
            Logger::warning('ハニーポットに入力がありました', ['ip' => self::ip()]);
            return '送信できませんでした。お手数ですが、お電話でご連絡ください。';
        }

        $issued = (string) ($post['_issued'] ?? '');
        $sig    = (string) ($post['_issued_sig'] ?? '');
        if ($issued === '' || !hash_equals(self::signIssued($issued), $sig)) {
            return '送信内容を確認できませんでした。お手数ですが、もう一度入力してください。';
        }

        $elapsed = time() - (int) $issued;
        if ($elapsed < self::MIN_SECONDS) {
            Logger::warning('フォーム送信が速すぎます', ['ip' => self::ip(), 'elapsed' => $elapsed]);
            return '送信できませんでした。お手数ですが、もう一度お試しください。';
        }
        if ($elapsed > self::MAX_SECONDS) {
            return '入力画面を開いてから時間が経ちすぎています。お手数ですが、もう一度入力してください。';
        }

        return null;
    }

    /**
     * 同じIPからの連投を弾く。
     * 記録は audit_logs を使う（公開フォームからの送信は admin_id が NULL で入る）。
     * DBが落ちていたら「制限なし」で通す。査定の申込を取りこぼす方が損失が大きい。
     */
    public static function tooManyAttempts(string $action): bool
    {
        $ip = self::ip();
        if ($ip === null) {
            return false;
        }
        try {
            $since = Db::isSqlite()
                ? "datetime('now', '+9 hours', '-1 hour')"
                : 'DATE_SUB(NOW(), INTERVAL 1 HOUR)';
            $count = (int) Db::value(
                "SELECT COUNT(*) FROM audit_logs WHERE ip = ? AND action = ? AND created_at >= {$since}",
                [$ip, $action]
            );
            return $count >= self::MAX_PER_HOUR;
        } catch (\Throwable $e) {
            Logger::error('連投判定に失敗しました', ['message' => $e->getMessage()]);
            return false;
        }
    }

    /** 送信が通ったらトークンを作り直す。戻るボタンでの二重送信を防ぐ。 */
    public static function rotateToken(): void
    {
        self::startSession();
        $_SESSION['form_token'] = bin2hex(random_bytes(32));
    }

    /**
     * 発行時刻の改ざん防止。
     * セッションに入れずに署名で持たせるのは、フォームを複数タブで開いたとき
     * （査定と予約を同時に見るなど）にお互いの時刻を上書きしないため。
     */
    private static function signIssued(string $issued): string
    {
        return hash_hmac('sha256', 'form:' . $issued, (string) Config::get('LINE_CHANNEL_SECRET', 'autobest'));
    }

    public static function ip(): ?string
    {
        $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return mb_substr($first, 0, 45);
            }
        }
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return $remote === '' ? null : mb_substr($remote, 0, 45);
    }
}

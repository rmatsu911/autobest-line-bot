<?php
/**
 * 管理画面の認証とセッション管理、CSRFトークン。
 *
 * .htaccess の Basic認証・IP制限を第一の壁とし、その内側でこのクラスが
 * 「誰がログインしているか」を管理する。二重にするのは、Basic認証の
 * パスワードは共有されがちで、操作者を個人単位で残せないため。
 */

declare(strict_types=1);

namespace App;

final class Auth
{
    /** ログイン失敗がこの回数を超えたらロックする */
    private const MAX_ATTEMPTS = 5;

    /** ロック時間（分） */
    private const LOCK_MINUTES = 15;

    /** 無操作でログアウトするまでの秒数 */
    private const IDLE_TIMEOUT = 3600;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // 既定の PHPSESSID のままだと「PHPのサイト」と分かり、
        // 既知の脆弱性を狙った探索の的になりやすいので名前を変える。
        session_name('ABADMIN');

        session_set_cookie_params([
            'lifetime' => 0,        // ブラウザを閉じたら破棄
            'path'     => '/',
            'httponly' => true,     // JS から読めなくする（XSS でのセッション奪取対策）
            'samesite' => 'Lax',    // 他サイトからの POST にクッキーを付けない（CSRF対策の一枚目）
            // https 経由のときだけ Secure を付ける。開発時に http で確認できなくなるのを避ける。
            'secure'   => (($_SERVER['HTTPS'] ?? '') !== '') || (($_SERVER['SERVER_PORT'] ?? '') === '443'),
        ]);

        // URL にセッションIDを載せない（Referer 経由で漏れるのを防ぐ）
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');

        session_start();

        // 無操作タイムアウト。共用PCで開きっぱなしにされたときの保険。
        if (isset($_SESSION['last_active']) && (time() - (int) $_SESSION['last_active']) > self::IDLE_TIMEOUT) {
            self::logout();
            session_start();
        }
        $_SESSION['last_active'] = time();
    }

    /**
     * ログインを試みる。成功したら true。
     *
     * @return array{ok:bool,error:?string}
     */
    public static function attempt(string $loginId, string $password): array
    {
        self::startSession();

        $user = Db::one(
            'SELECT id, login_id, password_hash, display_name, is_active, failed_attempts, locked_until
             FROM admin_users WHERE login_id = ?',
            [$loginId]
        );

        // 存在しないIDでも同じだけ時間を使う。即座に返すと
        // 応答時間の差から「そのIDは存在する」ことが分かってしまう。
        if ($user === null) {
            password_verify($password, '$2y$12$usesomesillystringforsalt0123456789abcdefghijklmnopqrs');
            Logger::warning('管理画面のログインに失敗しました', ['login_id' => $loginId, 'reason' => 'ID不明']);
            return ['ok' => false, 'error' => 'IDまたはパスワードが違います。'];
        }

        if ((int) $user['is_active'] !== 1) {
            Logger::warning('無効なアカウントでのログイン試行', ['login_id' => $loginId]);
            return ['ok' => false, 'error' => 'このアカウントは利用できません。'];
        }

        if ($user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time()) {
            Logger::warning('ロック中のアカウントへのログイン試行', ['login_id' => $loginId]);
            return ['ok' => false, 'error' => '試行回数が上限に達しました。しばらく待ってからお試しください。'];
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            self::recordFailure((int) $user['id'], (int) $user['failed_attempts']);
            Logger::warning('管理画面のログインに失敗しました', ['login_id' => $loginId, 'reason' => 'パスワード不一致']);
            return ['ok' => false, 'error' => 'IDまたはパスワードが違います。'];
        }

        // --- 認証成功 ---

        // セッション固定化対策。ログイン前に攻撃者が仕込んだセッションIDを
        // そのまま昇格させないよう、必ずここでIDを作り直す。
        // 第1引数 true で古いセッションファイルも削除する。
        session_regenerate_id(true);

        $_SESSION['admin_id']    = (int) $user['id'];
        $_SESSION['admin_name']  = (string) ($user['display_name'] ?? $user['login_id']);
        $_SESSION['last_active'] = time();

        // ハッシュのコストが上がった場合に、ログイン時に静かに貼り替える。
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            Db::exec('UPDATE admin_users SET password_hash = ? WHERE id = ?', [
                password_hash($password, PASSWORD_DEFAULT),
                (int) $user['id'],
            ]);
        }

        Db::exec(
            'UPDATE admin_users SET last_login_at = ' . Db::nowSql() . ', failed_attempts = 0, locked_until = NULL WHERE id = ?',
            [(int) $user['id']]
        );
        Logger::info('管理画面にログインしました', ['login_id' => $loginId]);

        return ['ok' => true, 'error' => null];
    }

    private static function recordFailure(int $userId, int $current): void
    {
        $next = $current + 1;
        if ($next >= self::MAX_ATTEMPTS) {
            $lockedUntil = date('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60);
            Db::exec(
                'UPDATE admin_users SET failed_attempts = ?, locked_until = ? WHERE id = ?',
                [$next, $lockedUntil, $userId]
            );
            Logger::warning('ログイン試行回数の上限に達したためロックしました', ['admin_id' => $userId]);
            return;
        }
        Db::exec('UPDATE admin_users SET failed_attempts = ? WHERE id = ?', [$next, $userId]);
    }

    public static function check(): bool
    {
        self::startSession();
        return isset($_SESSION['admin_id']);
    }

    /** 未ログインならログイン画面へ飛ばす。各管理画面の先頭で呼ぶ。 */
    public static function requireLogin(): void
    {
        if (self::check()) {
            return;
        }
        // ログイン後に元のページへ戻せるよう、要求されたURLを控える。
        // 外部サイトへ飛ばされないよう、パス部分だけを保持する。
        $_SESSION['after_login'] = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '/admin/cars.php';
        header('Location: login.php');
        exit;
    }

    public static function id(): ?int
    {
        return self::check() ? (int) $_SESSION['admin_id'] : null;
    }

    public static function name(): string
    {
        return (string) ($_SESSION['admin_name'] ?? '');
    }

    public static function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
        }
        session_destroy();
    }

    // -------------------------------------------------------------------------
    // CSRF
    // -------------------------------------------------------------------------

    /**
     * CSRFトークン。セッションにつき1つ持ち回す。
     * フォームごとに変えると、複数タブを開いたときに片方が必ず失敗するため。
     */
    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf_token'];
    }

    /** hidden フィールドをそのまま出力する */
    public static function csrfField(): string
    {
        return '<input type="hidden" name="_token" value="' . h(self::csrfToken()) . '">';
    }

    /**
     * POST の検証。不一致なら 400 で止める。
     * 比較は hash_equals（トークンの推測を応答時間から助けないため）。
     */
    public static function requireValidCsrf(): void
    {
        self::startSession();
        $sent = (string) ($_POST['_token'] ?? '');
        if ($sent === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $sent)) {
            Logger::warning('CSRFトークンが不正です', [
                'uri' => $_SERVER['REQUEST_URI'] ?? '-',
                'ip'  => $_SERVER['REMOTE_ADDR'] ?? '-',
            ]);
            http_response_code(400);
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!doctype html><meta charset="utf-8"><p>不正なリクエストです。前の画面に戻ってやり直してください。</p>';
            exit;
        }
    }

    /** 一覧→編集などの画面間メッセージ */
    public static function flash(?string $message = null): ?string
    {
        self::startSession();
        if ($message !== null) {
            $_SESSION['flash'] = $message;
            return null;
        }
        $value = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $value;
    }
}

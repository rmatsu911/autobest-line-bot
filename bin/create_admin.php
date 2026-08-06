<?php
/**
 * 管理画面のログインユーザーを作成・更新する（CLI専用）。
 *
 *   php bin/create_admin.php owner "店長"
 *
 * パスワードは引数では受け取らない。コマンド履歴（~/.bash_history）と
 * ps の出力に平文が残ってしまうため、対話で入力させる。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';

use App\Db;

$loginId     = trim((string) ($argv[1] ?? ''));
$displayName = trim((string) ($argv[2] ?? ''));

if ($loginId === '') {
    fwrite(STDERR, "使い方: php bin/create_admin.php <ログインID> [表示名]\n");
    exit(1);
}
if (!preg_match('/\A[a-zA-Z0-9_.-]{3,64}\z/', $loginId)) {
    fwrite(STDERR, "ログインIDは半角英数字・ _ . - の3〜64文字で指定してください。\n");
    exit(1);
}

/** 入力をエコーせずに読む。端末に平文が残らないようにする。 */
function readSecret(string $prompt): string
{
    echo $prompt;
    // stty が使える環境ではエコーを止める。使えなければそのまま読む。
    $hasStty = false;
    exec('stty -g 2>/dev/null', $out, $code);
    if ($code === 0 && $out !== []) {
        $hasStty = true;
        $saved   = $out[0];
        exec('stty -echo 2>/dev/null');
    }
    $value = trim((string) fgets(STDIN));
    if ($hasStty) {
        exec('stty ' . escapeshellarg($saved) . ' 2>/dev/null');
        echo PHP_EOL;
    }
    return $value;
}

$password = readSecret('パスワード（12文字以上）: ');
$confirm  = readSecret('もう一度入力            : ');

if ($password !== $confirm) {
    fwrite(STDERR, "パスワードが一致しません。\n");
    exit(1);
}
// 管理画面はインターネットに面しているので、短いパスワードは受け付けない。
if (mb_strlen($password) < 12) {
    fwrite(STDERR, "パスワードは12文字以上にしてください。\n");
    exit(1);
}

// password_hash はソルトを内部で生成し、ハッシュ文字列に含める。
// 自前でソルトを用意する必要はない（用意すると逆に間違えやすい）。
$hash = password_hash($password, PASSWORD_DEFAULT);

$existing = Db::one('SELECT id FROM admin_users WHERE login_id = ?', [$loginId]);

if ($existing !== null) {
    Db::exec(
        'UPDATE admin_users
         SET password_hash = ?, display_name = ?, is_active = 1, failed_attempts = 0, locked_until = NULL
         WHERE id = ?',
        [$hash, $displayName !== '' ? $displayName : null, (int) $existing['id']]
    );
    echo "既存ユーザー「{$loginId}」のパスワードを更新しました。\n";
} else {
    Db::exec(
        'INSERT INTO admin_users (login_id, password_hash, display_name) VALUES (?, ?, ?)',
        [$loginId, $hash, $displayName !== '' ? $displayName : null]
    );
    echo "管理ユーザー「{$loginId}」を作成しました。\n";
}

echo "ログイン画面: " . rtrim(\App\Config::get('BOT_BASE_URL', 'https://bot.autobest.jp'), '/') . "/admin/login.php\n";
exit(0);

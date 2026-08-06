<?php
/**
 * 管理画面ログイン。
 *
 * .htaccess の Basic認証を通った先にあるフォーム。二段構えにしている理由は
 * README の「管理画面の保護」を参照。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';

use App\Auth;

Auth::startSession();

// ログイン済みなら素通りさせる
if (Auth::check()) {
    header('Location: cars.php');
    exit;
}

$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::requireValidCsrf();

    $result = Auth::attempt(
        trim((string) ($_POST['login_id'] ?? '')),
        (string) ($_POST['password'] ?? '')
    );

    if ($result['ok']) {
        // ログイン前に見ようとしていた画面へ戻す。
        // 保存しているのはパス部分のみなので、外部サイトへは飛ばない。
        $next = (string) ($_SESSION['after_login'] ?? 'cars.php');
        unset($_SESSION['after_login']);
        header('Location: ' . (str_starts_with($next, '/') ? $next : 'cars.php'));
        exit;
    }
    $error = $result['error'];
}

header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>ログイン | autobest 管理画面</title>
<style>
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
         background:#f5f7fa; font-family:-apple-system,"Hiragino Kaku Gothic ProN","Yu Gothic",Meiryo,sans-serif; }
  form { background:#fff; border:1px solid #d9dee5; border-radius:8px; padding:1.8rem; width:min(380px,92vw); }
  h1 { font-size:1.15rem; margin:0 0 1.2rem; }
  label { display:block; font-weight:600; font-size:.88rem; margin-bottom:.25rem; }
  input { width:100%; padding:.55rem; border:1px solid #d9dee5; border-radius:5px; font-size:1rem; margin-bottom:1rem; box-sizing:border-box; }
  button { width:100%; padding:.6rem; border:0; border-radius:5px; background:#1b6ec2; color:#fff; font-size:1rem; cursor:pointer; }
  .err { background:#fdecea; border:1px solid #f0b3ae; color:#a03027; padding:.6rem .8rem; border-radius:6px; margin-bottom:1rem; font-size:.9rem; }
</style>
</head>
<body>
<form method="post" autocomplete="off">
  <h1>autobest 管理画面</h1>
  <?php if ($error !== null): ?>
    <div class="err"><?= h($error) ?></div>
  <?php endif; ?>
  <?= Auth::csrfField() ?>
  <label for="login_id">ID</label>
  <input type="text" id="login_id" name="login_id" required autofocus
         value="<?= h((string) ($_POST['login_id'] ?? '')) ?>">
  <label for="password">パスワード</label>
  <input type="password" id="password" name="password" required>
  <button type="submit">ログイン</button>
</form>
</body>
</html>

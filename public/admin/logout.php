<?php
/**
 * ログアウト。
 *
 * GET でも受け付けるが、その場合はトークンを確認する。
 * リンク1本で他人をログアウトさせられる（ログアウトCSRF）のを防ぐため。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';

use App\Auth;

Auth::startSession();

$token = (string) ($_POST['_token'] ?? $_GET['_token'] ?? '');
if ($token !== '' && hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
    Auth::logout();
}

header('Location: login.php');
exit;

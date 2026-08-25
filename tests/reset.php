<?php
/**
 * テストの前提をDBに整える（CLI専用）。
 *
 *   php tests/reset.php
 *
 * MySQLなら表を空にし、どちらのドライバでも管理ユーザーを作る。
 *
 * ★これを run.php の中でやらず、別プロセスにしている理由
 *   SQLiteのDBファイルを消して作り直したあと、同じプロセスに残っている
 *   PDOのハンドルは「消えた方のファイル」を掴んだままになる。
 *   そこへ書き込むと no such table で落ちる（実際に踏んだ）。
 *   作り直すたびにプロセスごと分ければ、この問題は起こらない。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';

use App\Db;

if (!Db::isSqlite()) {
    // MySQLでは表を空にするだけ。DROPしない（本番DBを指していた場合に戻せない）。
    Db::conn()->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (Db::tableNames() as $table) {
        if ($table === 'schema_migrations') {
            continue;
        }
        Db::conn()->exec('TRUNCATE TABLE `' . $table . '`');
    }
    Db::conn()->exec('SET FOREIGN_KEY_CHECKS = 1');
}

// 管理ユーザー。form_admin_test.php がこのIDとパスワードでログインする。
$sql = Db::isSqlite()
    ? 'INSERT OR IGNORE INTO admin_users (login_id, password_hash, display_name) VALUES (?, ?, ?)'
    : 'INSERT IGNORE INTO admin_users (login_id, password_hash, display_name) VALUES (?, ?, ?)';
Db::exec($sql, ['owner', password_hash('Test1234abcd', PASSWORD_DEFAULT), '店長']);

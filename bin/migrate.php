<?php
/**
 * スキーマ移行の適用（CLI専用）。
 *
 *   php bin/migrate.php            … 未適用の移行を一覧表示するだけ
 *   php bin/migrate.php --apply    … 未適用の移行を順に適用する
 *
 * sql/migrations/ の NNN_名前_{mysql|sqlite}.sql を番号順に適用し、
 * 適用済みの番号を schema_migrations に記録する。
 * 同じ移行を二度流さないためのもので、既に本番へ入れたDBを壊さずに
 * 列を足していくのに使う。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';

use App\Db;
use App\Logger;

$args     = array_slice($argv, 1);
$apply    = in_array('--apply', $args, true);
// 新規インストール用。sql/schema.sql は最新の形なので、移行を「流さずに済み扱い」にする。
// これをしないと、既に存在する列を ALTER TABLE ADD しようとして失敗する。
$baseline = in_array('--baseline', $args, true);
$driver = Db::isSqlite() ? 'sqlite' : 'mysql';

// 適用済みを記録する表。これ自体は移行の対象外なので毎回CREATE IF NOT EXISTS。
Db::conn()->exec(Db::isSqlite()
    ? 'CREATE TABLE IF NOT EXISTS schema_migrations (
         version TEXT PRIMARY KEY,
         applied_at TEXT NOT NULL DEFAULT (datetime(\'now\', \'+9 hours\'))
       )'
    : 'CREATE TABLE IF NOT EXISTS schema_migrations (
         version VARCHAR(64) NOT NULL,
         applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (version)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$applied = array_column(Db::all('SELECT version FROM schema_migrations'), 'version');

// このドライバ向けのファイルだけを拾う
$files = glob(dirname(__DIR__) . "/sql/migrations/*_{$driver}.sql") ?: [];
sort($files);

echo PHP_EOL . "=== スキーマ移行（{$driver}） ===" . PHP_EOL;

$pending = [];
foreach ($files as $path) {
    // 001_phase3_mysql.sql → バージョンは 001_phase3
    $version = preg_replace('/_' . $driver . '\.sql$/', '', basename($path));
    if (in_array($version, $applied, true)) {
        echo "  [済] {$version}" . PHP_EOL;
        continue;
    }
    echo "  [未] {$version}" . PHP_EOL;
    $pending[$version] = $path;
}

if ($pending === []) {
    echo PHP_EOL . '未適用の移行はありません。' . PHP_EOL . PHP_EOL;
    exit(0);
}

if ($baseline) {
    // 実行はせず、適用済みとして記録するだけ。
    echo PHP_EOL . '=== 適用済みとして記録（--baseline）===' . PHP_EOL;
    foreach (array_keys($pending) as $version) {
        Db::exec('INSERT INTO schema_migrations (version) VALUES (?)', [$version]);
        echo "  記録: {$version}" . PHP_EOL;
    }
    echo PHP_EOL . 'sql/schema.sql から作った新しいDBはこれで最新の状態です。' . PHP_EOL . PHP_EOL;
    exit(0);
}

if (!$apply) {
    echo PHP_EOL . '適用するには --apply を付けてください。' . PHP_EOL;
    echo '※ 実行前にデータベースのバックアップを取ってください。' . PHP_EOL;
    echo '※ sql/schema.sql から作ったばかりのDBは、代わりに --baseline を使ってください' . PHP_EOL;
    echo '   （最新の形で作られているため、移行を流す必要がありません）。' . PHP_EOL . PHP_EOL;
    exit(0);
}

echo PHP_EOL . '=== 適用 ===' . PHP_EOL;

foreach ($pending as $version => $path) {
    $sql = (string) file_get_contents($path);
    try {
        // トランザクションで囲まない理由：
        // MySQLのDDLは暗黙にコミットされるため囲んでも巻き戻せず、
        // SQLiteの PRAGMA foreign_keys はトランザクション内では無効になる。
        // 失敗時はどこまで進んだかをログに残し、手で確認できるようにする。
        Db::conn()->exec($sql);
        Db::exec('INSERT INTO schema_migrations (version) VALUES (?)', [$version]);
        echo "  適用しました: {$version}" . PHP_EOL;
        Logger::info('スキーマ移行を適用しました', ['version' => $version, 'driver' => $driver]);
    } catch (Throwable $e) {
        echo "  失敗: {$version}" . PHP_EOL;
        echo '  ' . $e->getMessage() . PHP_EOL;
        Logger::error('スキーマ移行に失敗しました', ['version' => $version, 'message' => $e->getMessage()]);
        echo PHP_EOL . '中断しました。DBの状態を確認してから再実行してください。' . PHP_EOL . PHP_EOL;
        exit(1);
    }
}

echo PHP_EOL . '完了しました。php bin/healthcheck.php で確認してください。' . PHP_EOL . PHP_EOL;
exit(0);

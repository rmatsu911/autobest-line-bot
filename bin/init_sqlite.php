<?php
/**
 * SQLite schema initializer for Xserver fallback deployments.
 *
 *   php bin/init_sqlite.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';

use App\Config;
use App\Db;

if (strtolower(Config::get('DB_DRIVER', 'mysql')) !== 'sqlite') {
    fwrite(STDERR, "DB_DRIVER=sqlite のときだけ実行できます。\n");
    exit(1);
}

$schema = dirname(__DIR__) . '/sql/schema.sqlite.sql';
if (!is_readable($schema)) {
    fwrite(STDERR, "SQLiteスキーマが読めません: {$schema}\n");
    exit(1);
}

$pdo = Db::conn();
$pdo->exec((string) file_get_contents($schema));

echo "SQLiteスキーマを投入しました: " . Db::sqlitePath() . PHP_EOL;

// schema.sqlite.sql は常に最新の形なので、移行は「適用済み」として記録する。
// これをしないと、後で bin/migrate.php を流したときに
// 既にある列を作ろうとして失敗する。
$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    version TEXT PRIMARY KEY,
    applied_at TEXT NOT NULL DEFAULT (datetime('now', '+9 hours'))
)");
foreach (glob(dirname(__DIR__) . '/sql/migrations/*_sqlite.sql') ?: [] as $path) {
    $version = preg_replace('/_sqlite\.sql$/', '', basename($path));
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO schema_migrations (version) VALUES (?)');
    $stmt->execute([$version]);
}
echo "移行の基準点を記録しました（このDBは最新の形です）" . PHP_EOL;

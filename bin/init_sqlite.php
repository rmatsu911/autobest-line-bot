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

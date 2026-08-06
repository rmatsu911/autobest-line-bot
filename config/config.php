<?php
/**
 * 設定の読み込みと共通初期化。
 *
 * すべてのエントリポイント（public/webhook.php, bin/*.php, 管理画面）は
 * 最初にこのファイルを require する。ここで行うのは4つだけ。
 *   1) エラーを画面に出さず、ドキュメントルート外のログへ送る
 *   2) .env の読み込み（.env はリポジトリに含めない）
 *   3) src/ のオートローダ登録（Composer を使わないので自前）
 *   4) タイムゾーン固定
 *
 * このファイル自身はドキュメントルート外に置く（公開されるのは public/ のみ）。
 */

declare(strict_types=1);

namespace App;

// アプリのルート（このファイルの1つ上）。.env と storage/ はここ直下に置く。
// テストから別の場所を指せるよう、既に定義済みなら上書きしない。
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

/**
 * .env から読んだ値を保持する。
 *
 * getenv() を使わないのは、共用サーバーだと他のバーチャルホストの設定や
 * PHP-FPM の環境変数が混ざる余地があり、「どこから来た値か」を追いにくいため。
 * 値の出所を .env ファイル1つに固定する。
 */
final class Config
{
    /** @var array<string,string>|null */
    private static ?array $values = null;

    /**
     * .env を読み込む。フォーマットは KEY=VALUE の1行1件。
     * # で始まる行と空行は無視。値は '...' または "..." で囲ってよい。
     */
    public static function load(?string $envPath = null): void
    {
        if (self::$values !== null) {
            return;
        }
        $envPath ??= APP_ROOT . '/.env';
        self::$values = [];

        if (!is_readable($envPath)) {
            // .env が無い＝設定漏れ。詳細は画面に出さず、ログにだけ残して止める。
            Logger::critical('.env が読めません', ['path' => $envPath]);
            self::fail('設定ファイルを読み込めません');
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key   = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // 前後のクォートを1組だけ剥がす
            $len = strlen($value);
            if ($len >= 2
                && (($value[0] === '"' && $value[$len - 1] === '"')
                    || ($value[0] === "'" && $value[$len - 1] === "'"))
            ) {
                $value = substr($value, 1, -1);
            }
            self::$values[$key] = $value;
        }
    }

    /** 値を取得する。$default を省略した場合、未設定なら例外扱いで停止する。 */
    public static function get(string $key, ?string $default = null): string
    {
        self::load();
        if (isset(self::$values[$key]) && self::$values[$key] !== '') {
            return self::$values[$key];
        }
        if ($default !== null) {
            return $default;
        }
        Logger::critical('必須の環境変数が未設定です', ['key' => $key]);
        self::fail('設定が不足しています: ' . $key);
    }

    public static function getInt(string $key, ?int $default = null): int
    {
        return (int) self::get($key, $default === null ? null : (string) $default);
    }

    public static function isProduction(): bool
    {
        return self::get('APP_ENV', 'production') === 'production';
    }

    /**
     * 設定不備での停止。
     *
     * exit ではなく例外を投げる。呼び出し元がレスポンスを返した後でも安全に扱えるうえ、
     * cron からはスタックトレース付きでログに残るため原因を追いやすい。
     * Web から来た場合は display_errors=0 のまま未捕捉となり、
     * 本文を出さずに 500 になる（原因が外に漏れない）。
     */
    private static function fail(string $reason): never
    {
        throw new \RuntimeException($reason);
    }
}

// -----------------------------------------------------------------------------
// 1) エラー表示の抑止とログ出力先
//    共用サーバーの既定では display_errors が on のことがある。
//    警告文にDB接続情報やフルパスが載って外部に見えるのを防ぐため必ず off にする。
// -----------------------------------------------------------------------------
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$logDir = APP_ROOT . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}
ini_set('error_log', $logDir . '/php-error.log');

// -----------------------------------------------------------------------------
// 2) オートローダ（Composer 非使用）
//    App\Foo\Bar → src/Foo/Bar.php に対応させる。
// -----------------------------------------------------------------------------
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = APP_ROOT . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

// テンプレート用ヘルパ（h() など）。オートロードできない素の関数なので明示的に読む。
require_once APP_ROOT . '/config/helpers.php';

// -----------------------------------------------------------------------------
// 3) タイムゾーン
//    DATETIME を NOW() で入れるため、PHPとMySQLの時刻感覚を JST に揃える。
//    （MySQL 側は Db.php で time_zone を設定する）
// -----------------------------------------------------------------------------
date_default_timezone_set('Asia/Tokyo');

// -----------------------------------------------------------------------------
// 4) .env 読み込み
// -----------------------------------------------------------------------------
Config::load();

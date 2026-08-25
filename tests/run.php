<?php
/**
 * テストをまとめて走らせる（CLI専用）。
 *
 *   php tests/run.php              … SQLiteで全部走らせる
 *   php tests/run.php --keep       … 終了後もDBを残す（失敗の調査用）
 *
 * ここがやること
 *   1) テスト用のDBを作り直して種データを入れる
 *   2) HTTPを通すテストのためにPHPのビルトインサーバを立てる
 *   3) 各テストを順に実行し、成功／失敗の件数をまとめる
 *
 * ★DBを毎回作り直す理由
 *   テストは在庫の台数や公開状態を前提に数を数えている。
 *   前のテストが車両を「売却済み」にしたまま次を走らせると、
 *   product側は正しいのに件数が合わずに落ちる。
 *   実際にこれで何度も嘘の失敗を踏んだので、必ず作り直す。
 *
 * ★MySQLで走らせる場合
 *   .env を DB_DRIVER=mysql にしてスキーマを流し込んでから、
 *   TEST_DSN / TEST_USER / TEST_PASS を渡す。
 *     TEST_DSN="mysql:host=127.0.0.1;dbname=ab_test;charset=utf8mb4" \
 *     TEST_USER=xxx TEST_PASS=yyy php tests/run.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ROOT', dirname(__DIR__));

$keep = in_array('--keep', array_slice($argv, 1), true);
$port = (int) (getenv('TEST_PORT') ?: 8099);
$base = 'http://127.0.0.1:' . $port;

if (!is_file(ROOT . '/.env')) {
    fwrite(STDERR, ".env がありません。.env.example をコピーして作ってください。\n");
    exit(2);
}

// -----------------------------------------------------------------------------
// 1) DBの用意
// -----------------------------------------------------------------------------
echo PHP_EOL . '=== 準備 ===' . PHP_EOL;

$driver = strtolower((string) envValue('DB_DRIVER', 'sqlite'));

if ($driver === 'sqlite') {
    $dbPath = ROOT . '/' . ltrim((string) envValue('DB_PATH', 'storage/autobest.sqlite'), '/');
    @unlink($dbPath);
    run('php ' . escapeshellarg(ROOT . '/bin/init_sqlite.php'));
    echo '  SQLiteを作り直しました' . PHP_EOL;
} else {
    // MySQLはテスト側でDROPしない（本番DBを指していた場合に取り返しがつかない）。
    // 空のテスト用DBへスキーマを流し込んだ状態で呼ばれる前提。
    echo '  MySQL: 既存のスキーマを使います（テーブルは事前に作っておくこと）' . PHP_EOL;
}

// 査定写真の残骸を消す（前回の実行分が残っていると枚数が合わない）
foreach ((array) glob(ROOT . '/storage/assessments/*', GLOB_ONLYDIR) as $dir) {
    foreach ((array) glob($dir . '/*') as $file) {
        @unlink($file);
    }
    @rmdir($dir);
}

run('php ' . escapeshellarg(ROOT . '/tests/reset.php'));
run('php ' . escapeshellarg(ROOT . '/tests/seed.php'));
echo '  種データと管理ユーザーを入れました' . PHP_EOL;

// -----------------------------------------------------------------------------
// 2) ビルトインサーバ
// -----------------------------------------------------------------------------
$server = proc_open(
    'php -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg(ROOT . '/public'),
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes
);
if (!is_resource($server)) {
    fwrite(STDERR, "ビルトインサーバを起動できませんでした。\n");
    exit(2);
}

$up = false;
for ($i = 0; $i < 60; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
    if ($fp !== false) {
        fclose($fp);
        $up = true;
        break;
    }
    usleep(200_000);
}
if (!$up) {
    fwrite(STDERR, "ビルトインサーバが応答しません（ポート {$port}）。\n");
    proc_terminate($server);
    exit(2);
}
echo "  ビルトインサーバ起動: {$base}" . PHP_EOL;

// -----------------------------------------------------------------------------
// 3) 実行
// -----------------------------------------------------------------------------
// resetDb が true のものは、在庫の台数を数えるので直前にDBを作り直す。
$suites = [
    ['webhook_unit_test.php',      '署名・イベント処理（DBはスタブ）', false],
    ['car_validator_test.php',     '在庫フォームの入力検証',           false],
    ['flex_richmenu_test.php',     'Flex・リッチメニューの組み立て',   true],
    ['webhook_inventory_test.php', '在庫カルーセルとキーワード応答',   true],
    ['notify_test.php',            '新着のお知らせ（reply方式）',      true],
    ['form_admin_test.php',        '申込フォームと管理画面（HTTP）',   true],
];

$totalPass = 0;
$totalFail = 0;
$failed    = [];

foreach ($suites as [$file, $label, $resetDb]) {
    if ($resetDb) {
        resetData($driver);
    }

    echo PHP_EOL . '=== ' . $label . ' (' . $file . ') ===' . PHP_EOL;

    $env = 'TEST_BASE_URL=' . escapeshellarg($base)
         . ' TEST_CHANNEL_SECRET=' . escapeshellarg((string) envValue('LINE_CHANNEL_SECRET', 'abc123'));
    if (getenv('TEST_DSN')) {
        $env .= ' P4_DSN=' . escapeshellarg((string) getenv('TEST_DSN'))
              . ' P4_USER=' . escapeshellarg((string) getenv('TEST_USER'))
              . ' P4_PASS=' . escapeshellarg((string) getenv('TEST_PASS'));
    }

    $output = [];
    exec($env . ' php ' . escapeshellarg(ROOT . '/tests/' . $file) . ' 2>&1', $output);
    $text = implode(PHP_EOL, $output);

    foreach ($output as $line) {
        if (str_contains($line, 'FAIL') || str_starts_with($line, '成功')) {
            echo '  ' . trim($line) . PHP_EOL;
        }
    }

    if (preg_match('/成功 (\d+) \/ 失敗 (\d+)/u', $text, $m)) {
        $totalPass += (int) $m[1];
        $totalFail += (int) $m[2];
        if ((int) $m[2] > 0) {
            $failed[] = $file;
        }
    } else {
        echo '  ★ 結果を読み取れませんでした（下に出力）' . PHP_EOL;
        echo $text . PHP_EOL;
        $totalFail++;
        $failed[] = $file;
    }
}

proc_terminate($server);

echo PHP_EOL . str_repeat('-', 50) . PHP_EOL;
echo "合計 成功 {$totalPass} / 失敗 {$totalFail}" . PHP_EOL;
if ($failed !== []) {
    echo '失敗したファイル: ' . implode(', ', $failed) . PHP_EOL;
    echo '個別に走らせるときは、先に php tests/run.php で作った状態を壊さないよう注意すること。' . PHP_EOL;
}
if (!$keep && $driver === 'sqlite') {
    echo '（--keep を付けるとDBを残せます）' . PHP_EOL;
}
echo PHP_EOL;

exit($totalFail === 0 ? 0 : 1);

// -----------------------------------------------------------------------------

function run(string $command): void
{
    exec($command . ' 2>&1', $output, $code);
    if ($code !== 0) {
        fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
        exit(2);
    }
}

/** .env を読む。config.php を読み込むとオートローダまで走るので、ここでは自前で読む。 */
function envValue(string $key, string $default = ''): string
{
    static $values = null;
    if ($values === null) {
        $values = [];
        foreach ((array) file(ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $values[trim($k)] = trim(trim($v), "\"'");
        }
    }
    return $values[$key] ?? $default;
}

/**
 * テストの前提（在庫14台・お客様1人・管理ユーザー1人）に戻す。
 *
 * 作り直しも書き込みも別プロセスに任せる。
 * このプロセスがDBのハンドルを持つと、SQLiteのファイルを消したあとに
 * 「消えた方のファイル」を掴んだまま書き込んで落ちる。
 */
function resetData(string $driver): void
{
    if ($driver === 'sqlite') {
        @unlink(ROOT . '/' . ltrim(envValue('DB_PATH', 'storage/autobest.sqlite'), '/'));
        run('php ' . escapeshellarg(ROOT . '/bin/init_sqlite.php'));
    }
    run('php ' . escapeshellarg(ROOT . '/tests/reset.php'));
    run('php ' . escapeshellarg(ROOT . '/tests/seed.php'));
}

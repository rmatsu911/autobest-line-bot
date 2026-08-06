<?php
/**
 * PDO 接続。
 *
 * Xserver の共用サーバーでは接続ホストが localhost ではなく
 * mysqlXXX.xserver.jp 形式になる。ホスト名は .env の DB_HOST に入れる。
 */

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

final class Db
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            Config::get('DB_HOST'),
            Config::getInt('DB_PORT', 3306),
            Config::get('DB_NAME')
        );

        try {
            self::$pdo = new PDO($dsn, Config::get('DB_USER'), Config::get('DB_PASS'), [
                // 例外で落とす。既定の SILENT だと execute() の失敗に気づかず
                // 「保存できていないのに成功扱い」になる事故が起きる。
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // エミュレーションを切り、本物のプリペアドステートメントを使う。
                // これが on だと PDO が自前でクォートして SQL を組み立てるため、
                // 文字コードの取り扱い次第で injection の余地が残る。
                PDO::ATTR_EMULATE_PREPARES   => false,
                // LIMIT ? に整数を渡せるようにする（エミュレーション off の副作用対策）。
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::ATTR_PERSISTENT         => false,
            ]);

            // PHP 側は Asia/Tokyo。MySQL のセッションも +09:00 に合わせ、
            // NOW() と date() がずれないようにする（共用サーバーは UTC のことがある）。
            self::$pdo->exec("SET time_zone = '+09:00'");
        } catch (PDOException $e) {
            // 接続文字列やユーザー名が例外メッセージに載るので、そのまま外へ出さない。
            Logger::critical('DB接続に失敗しました', ['message' => $e->getMessage()]);
            // exit ではなく例外にする理由：webhook.php は既に200を返して接続を切っており、
            // ここで http_response_code()/header() を呼んでも「headers already sent」の
            // 警告になるだけで意味がない。さらに exit だと残りのイベント処理まで
            // 巻き込んで止まる。呼び出し側（webhook.php の try/catch、cron）に判断を委ねる。
            throw new \RuntimeException('データベースに接続できません', 0, $e);
        }

        return self::$pdo;
    }

    /** SELECT で複数行取得 */
    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** SELECT で1行取得。無ければ null */
    public static function one(string $sql, array $params = []): ?array
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * ページング付きの SELECT。
     *
     * LIMIT / OFFSET をプレースホルダで渡さない理由：
     * ATTR_EMULATE_PREPARES=false のとき execute($params) は全ての値を文字列として
     * 束縛するため、SQL が「LIMIT '10'」となり構文エラーになる。
     * ここは (int) キャストを通した値だけを埋め込む。キャスト後の値は数字以外を
     * 含み得ないので injection の余地は無く、「SQLは全てプリペアド」の原則は
     * WHERE 句の値（＝利用者入力）について維持されている。
     */
    public static function paged(string $sql, array $params, int $limit, int $offset): array
    {
        $limit  = max(1, min($limit, 200));   // 上限を設けて全件取得の暴発を防ぐ
        $offset = max(0, $offset);
        return self::all($sql . sprintf(' LIMIT %d OFFSET %d', $limit, $offset), $params);
    }

    /** COUNT(*) など単一の値を取る */
    public static function value(string $sql, array $params = []): mixed
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /** INSERT / UPDATE / DELETE。影響行数を返す */
    public static function exec(string $sql, array $params = []): int
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function lastInsertId(): int
    {
        return (int) self::conn()->lastInsertId();
    }

    /** トランザクション。$fn が例外を投げたらロールバックして再送出する */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::conn();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

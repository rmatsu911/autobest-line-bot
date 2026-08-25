<?php
declare(strict_types=1);

namespace App;

/** 本物より先に定義することで、オートローダに src/Db.php を読ませない */
final class Db
{
    public static array $calls = [];
    public static int $affected = 1;
    public static bool $throw = false;

    public static function exec(string $sql, array $params = []): int
    {
        self::$calls[] = ['sql' => preg_replace('/\s+/', ' ', trim($sql)), 'params' => $params];
        if (self::$throw) {
            throw new \RuntimeException('データベースに接続できません');
        }
        return self::$affected;
    }

    public static function all(string $sql, array $params = []): array { return []; }
    public static function paged(string $sql, array $params, int $limit, int $offset): array { return []; }
    public static function value(string $sql, array $params = []): mixed { return 0; }
    public static function one(string $sql, array $params = []): ?array { return null; }

    // SQLite対応で追加されたメソッド。両方の分岐を試せるよう切り替え可能にする。
    public static bool $sqlite = false;
    public static function isSqlite(): bool { return self::$sqlite; }
    public static function driver(): string { return self::$sqlite ? 'sqlite' : 'mysql'; }
    public static function nowSql(): string { return self::$sqlite ? "datetime('now', '+9 hours')" : 'NOW()'; }
}

final class LineResponse
{
    public function __construct(public readonly int $status = 200) {}
    public function ok(): bool { return true; }
}

final class LineClient
{
    public static array $replies = [];
    public static ?array $profileResult = ['displayName' => 'テスト太郎', 'userId' => 'U1'];

    public function __construct(?string $t = null) {}

    public function reply(string $replyToken, array $messages): LineResponse
    {
        self::$replies[] = ['token' => $replyToken, 'messages' => $messages];
        return new LineResponse();
    }

    public function push(string $to, array $m, ?string $k = null): LineResponse { return new LineResponse(); }
    public function profile(string $userId): ?array { return self::$profileResult; }

    public static function text(string $text, ?array $quickReply = null): array
    {
        $m = ['type' => 'text', 'text' => mb_substr($text, 0, 5000)];
        if ($quickReply !== null) { $m['quickReply'] = $quickReply; }
        return $m;
    }

    public static function quickReply(array $items): array
    {
        return ['items' => array_map(
            static fn(array $i): array => ['type' => 'action', 'action' => ['type' => 'postback', 'label' => $i['label'], 'data' => $i['data']]],
            $items
        )];
    }

    public static function httpGet(string $url, array $headers = []): ?array { return null; }
}

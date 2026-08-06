<?php
/**
 * 最小限のファイルロガー。
 *
 * 外部ライブラリを使えないので自前。Config には依存させない
 * （.env の読み込み失敗そのものをログに残す必要があるため）。
 * 出力先は APP_ROOT/storage/logs（＝ドキュメントルート外）。
 */

declare(strict_types=1);

namespace App;

final class Logger
{
    public static function debug(string $message, array $context = []): void
    {
        self::write('DEBUG', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARN', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::write('CRITICAL', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $dir = APP_ROOT . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $line = sprintf(
            "[%s] %s %s%s\n",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $context === []
                ? ''
                : ' ' . json_encode(self::mask($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        // LOCK_EX：cron と webhook が同時に書いても行が混ざらないようにする。
        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * ログに残ると困る値を伏せる。
     * ログファイル自体は外部から読めない場所にあるが、
     * 万一の閲覧・持ち出しでアクセストークンがそのまま漏れないようにする。
     */
    private static function mask(array $context): array
    {
        $secretKeys = ['token', 'access_token', 'secret', 'password', 'authorization', 'signature'];
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = self::mask($value);
                continue;
            }
            foreach ($secretKeys as $needle) {
                if (stripos((string) $key, $needle) !== false) {
                    $context[$key] = '***';
                    break;
                }
            }
        }
        return $context;
    }
}

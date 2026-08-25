<?php
/**
 * message_queue を使った非同期メッセージ処理。
 *
 * Xserver では常駐ワーカーを置けないため、cron が短時間だけ起動して
 * pending の行を少しずつ処理する。
 */

declare(strict_types=1);

namespace App;

final class MessageQueue
{
    public const DEFAULT_MAX_ITEMS = 50;
    public const DEFAULT_MAX_SECONDS = 50;
    public const STALE_MINUTES = 10;
    public const MAX_ATTEMPTS = 5;

    /**
     * キューに積む。
     *
     * @param array<string,mixed> $payload
     */
    public static function enqueue(
        string $type,
        array $payload,
        ?string $scheduledAt = null,
        ?string $retryKey = null
    ): int {
        self::assertValidType($type);

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false) {
            throw new \InvalidArgumentException('キューのpayloadをJSON化できません: ' . json_last_error_msg());
        }

        $retryKey ??= self::newRetryKey();

        Db::exec(
            'INSERT INTO message_queue (type, payload, retry_key, scheduled_at)
             VALUES (?, ?, ?, COALESCE(?, ' . Db::nowSql() . '))',
            [$type, $json, $retryKey, $scheduledAt]
        );

        return Db::lastInsertId();
    }

    /** 古い processing を pending に戻す。 */
    public static function releaseStale(int $minutes = self::STALE_MINUTES): int
    {
        $minutes = max(1, min($minutes, 1440));
        $cutoff = date('Y-m-d H:i:s', time() - ($minutes * 60));

        return Db::exec(
            'UPDATE message_queue
                SET status = ?,
                    last_error = ?,
                    updated_at = ' . Db::nowSql() . '
              WHERE status = ?
                AND updated_at < ?',
            ['pending', '前回の処理が途中で止まったため再実行します', 'processing', $cutoff]
        );
    }

    /**
     * 実行可能な行を processing として確保する。
     *
     * @return array<int,array<string,mixed>>
     */
    public static function claimPending(int $limit = 1): array
    {
        $limit = max(1, min($limit, 200));

        return Db::transaction(static function () use ($limit): array {
            $rows = Db::paged(
                'SELECT id
                   FROM message_queue
                  WHERE status = ?
                    AND scheduled_at <= ' . Db::nowSql() . '
                  ORDER BY scheduled_at ASC, id ASC',
                ['pending'],
                $limit,
                0
            );
            if ($rows === []) {
                return [];
            }

            $ids = array_map(static fn(array $row): int => (int) $row['id'], $rows);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            Db::exec(
                'UPDATE message_queue
                    SET status = ?,
                        attempts = attempts + 1,
                        updated_at = ' . Db::nowSql() . '
                  WHERE status = ?
                    AND id IN (' . $placeholders . ')',
                array_merge(['processing', 'pending'], $ids)
            );

            return Db::all(
                'SELECT *
                   FROM message_queue
                  WHERE status = ?
                    AND id IN (' . $placeholders . ')
                  ORDER BY scheduled_at ASC, id ASC',
                array_merge(['processing'], $ids)
            );
        });
    }

    /**
     * cron 1回分の処理を行う。
     *
     * @param callable|null $sender テスト用の差し替え口。
     *        fn(string $type, array $payload, string $retryKey): LineResponse
     * @return array<string,int|float>
     */
    public static function work(
        ?callable $sender = null,
        int $maxItems = self::DEFAULT_MAX_ITEMS,
        int $maxSeconds = self::DEFAULT_MAX_SECONDS,
        int $staleMinutes = self::STALE_MINUTES
    ): array {
        $maxItems = max(1, min($maxItems, 200));
        $maxSeconds = max(1, min($maxSeconds, 55));
        $started = microtime(true);
        $sender ??= self::defaultSender();

        $summary = [
            'processed' => 0,
            'succeeded' => 0,
            'retried' => 0,
            'failed' => 0,
            'stale_requeued' => self::releaseStale($staleMinutes),
            'elapsed_seconds' => 0.0,
        ];

        while ($summary['processed'] < $maxItems) {
            if ((microtime(true) - $started) >= $maxSeconds) {
                break;
            }

            $rows = self::claimPending(1);
            if ($rows === []) {
                break;
            }

            $result = self::processRow($rows[0], $sender);
            $summary['processed']++;
            $summary[$result]++;
        }

        $summary['elapsed_seconds'] = round(microtime(true) - $started, 3);
        return $summary;
    }

    /**
     * @param array<string,mixed> $row
     * @param callable $sender
     */
    private static function processRow(array $row, callable $sender): string
    {
        try {
            $payload = self::decodePayload($row);
            $type = (string) $row['type'];
            $retryKey = self::retryKeyForRow($row);

            self::validatePayload($type, $payload);
            $estimate = self::estimateConsumption($type, $payload);
            Logger::info('キューのメッセージを送信します', [
                'queue_id' => (int) $row['id'],
                'type' => $type,
                'estimated_messages' => $estimate,
            ]);

            $response = $sender($type, $payload, $retryKey);
            if (!$response instanceof LineResponse) {
                throw new \RuntimeException('sender が LineResponse を返しませんでした');
            }
        } catch (\InvalidArgumentException $e) {
            self::recordFailure($row, $e->getMessage(), false);
            return 'failed';
        } catch (\Throwable $e) {
            Logger::error('キュー処理で例外が発生しました', [
                'queue_id' => (int) ($row['id'] ?? 0),
                'message' => $e->getMessage(),
            ]);
            return self::recordFailure($row, $e->getMessage(), true);
        }

        if ($response->ok()) {
            self::markDone((int) $row['id']);
            return 'succeeded';
        }

        return self::recordFailure($row, self::responseError($response), $response->retryable());
    }

    private static function markDone(int $id): void
    {
        Db::exec(
            'UPDATE message_queue
                SET status = ?,
                    last_error = NULL,
                    updated_at = ' . Db::nowSql() . '
              WHERE id = ?',
            ['done', $id]
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function recordFailure(array $row, string $error, bool $retryable): string
    {
        $id = (int) $row['id'];
        $attempts = max(1, (int) ($row['attempts'] ?? 1));
        $message = mb_substr($error, 0, 1000);

        if ($retryable && $attempts < self::MAX_ATTEMPTS) {
            $delaySeconds = self::retryDelaySeconds($attempts);
            $scheduledAt = date('Y-m-d H:i:s', time() + $delaySeconds);

            Db::exec(
                'UPDATE message_queue
                    SET status = ?,
                        last_error = ?,
                        scheduled_at = ?,
                        updated_at = ' . Db::nowSql() . '
                  WHERE id = ?',
                ['pending', $message, $scheduledAt, $id]
            );

            return 'retried';
        }

        Db::exec(
            'UPDATE message_queue
                SET status = ?,
                    last_error = ?,
                    updated_at = ' . Db::nowSql() . '
              WHERE id = ?',
            ['failed', $message, $id]
        );

        return 'failed';
    }

    private static function retryDelaySeconds(int $attempts): int
    {
        $power = max(0, $attempts - 1);
        return min(3600, 60 * (2 ** $power));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function decodePayload(array $row): array
    {
        $decoded = json_decode((string) ($row['payload'] ?? ''), true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('キューのpayloadがJSONとして読めません');
        }
        return $decoded;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function estimateConsumption(string $type, array $payload): int
    {
        return match ($type) {
            'push', 'notify_owner' => 1,
            'multicast' => count(self::stringList($payload['to'] ?? $payload['userIds'] ?? null, 'to')),
            'broadcast' => self::positiveInt($payload['estimated_recipients'] ?? null, 'estimated_recipients'),
            default => throw new \InvalidArgumentException('未対応のキュー種別です: ' . $type),
        };
    }

    private static function defaultSender(): callable
    {
        $line = new LineClient();

        return static function (string $type, array $payload, string $retryKey) use ($line): LineResponse {
            return match ($type) {
                'push' => $line->push(
                    self::stringValue($payload['to'] ?? null, 'to'),
                    self::messages($payload),
                    $retryKey
                ),
                'notify_owner' => $line->push(
                    self::ownerLineUserId($payload),
                    self::messages($payload),
                    $retryKey
                ),
                'multicast' => $line->multicast(
                    self::stringList($payload['to'] ?? $payload['userIds'] ?? null, 'to'),
                    self::messages($payload),
                    $retryKey
                ),
                'broadcast' => $line->broadcast(
                    self::messages($payload),
                    $retryKey
                ),
                default => throw new \InvalidArgumentException('未対応のキュー種別です: ' . $type),
            };
        };
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function validatePayload(string $type, array $payload): void
    {
        match ($type) {
            'push' => [self::stringValue($payload['to'] ?? null, 'to'), self::messages($payload)],
            'notify_owner' => [self::ownerLineUserId($payload), self::messages($payload)],
            'multicast' => [self::stringList($payload['to'] ?? $payload['userIds'] ?? null, 'to'), self::messages($payload)],
            'broadcast' => [self::positiveInt($payload['estimated_recipients'] ?? null, 'estimated_recipients'), self::messages($payload)],
            default => throw new \InvalidArgumentException('未対応のキュー種別です: ' . $type),
        };
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    private static function messages(array $payload): array
    {
        $messages = $payload['messages'] ?? null;
        if (!is_array($messages) || $messages === []) {
            throw new \InvalidArgumentException('payload.messages がありません');
        }
        return array_values($messages);
    }

    /**
     * @param mixed $value
     */
    private static function stringValue($value, string $name): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException('payload.' . $name . ' がありません');
        }
        return $value;
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private static function stringList($value, string $name): array
    {
        if (!is_array($value) || $value === []) {
            throw new \InvalidArgumentException('payload.' . $name . ' がありません');
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new \InvalidArgumentException('payload.' . $name . ' に不正な値があります');
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param mixed $value
     */
    private static function positiveInt($value, string $name): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new \InvalidArgumentException('payload.' . $name . ' がありません');
        }

        $int = (int) $value;
        if ($int < 1) {
            throw new \InvalidArgumentException('payload.' . $name . ' が不正です');
        }
        return $int;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function ownerLineUserId(array $payload): string
    {
        if (isset($payload['to'])) {
            return self::stringValue($payload['to'], 'to');
        }

        return self::stringValue(Config::get('OWNER_LINE_USER_ID', ''), 'OWNER_LINE_USER_ID');
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function retryKeyForRow(array $row): string
    {
        $retryKey = (string) ($row['retry_key'] ?? '');
        if ($retryKey !== '') {
            return $retryKey;
        }

        // 旧データや手作業投入でも二重送信防止を効かせるため、処理時に補う。
        $retryKey = self::newRetryKey();
        Db::exec(
            'UPDATE message_queue
                SET retry_key = ?,
                    updated_at = ' . Db::nowSql() . '
              WHERE id = ?',
            [$retryKey, (int) $row['id']]
        );
        return $retryKey;
    }

    private static function responseError(LineResponse $response): string
    {
        if ($response->error !== null && $response->error !== '') {
            return 'curl error: ' . $response->error;
        }

        $message = '';
        if (is_array($response->json) && isset($response->json['message'])) {
            $message = (string) $response->json['message'];
        } elseif ($response->body !== '') {
            $message = mb_substr($response->body, 0, 300);
        }

        return trim('HTTP ' . $response->status . ' ' . $message);
    }

    private static function assertValidType(string $type): void
    {
        if (preg_match('/\A[a-z0-9_:-]{1,32}\z/', $type) !== 1) {
            throw new \InvalidArgumentException('キュー種別が不正です');
        }
    }

    private static function newRetryKey(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }
}

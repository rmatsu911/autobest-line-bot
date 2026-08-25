<?php
/**
 * LINE APIの応答。
 *
 * LineClient.php の中に置いていたが、クラス名とファイル名が一致しないと
 * オートローダが見つけられない（config/config.php のオートローダは
 * App\Xxx を src/Xxx.php に対応させるだけの単純なもの）。
 *
 * MessageQueue が「senderは LineResponse を返すこと」を約束事にしたため、
 * LineClient を経由しない呼び出し側からも参照されるようになった。
 * その場合に「Class "App\LineResponse" not found」で落ちるので、
 * 自分のファイルに移して単体で読めるようにした。
 */

declare(strict_types=1);

namespace App;

final class LineResponse
{
    public int $status;
    public ?array $json;
    public string $body;
    public ?string $error;

    public function __construct(int $status, ?array $json, string $body, ?string $error = null)
    {
        $this->status = $status;
        $this->json = $json;
        $this->body = $body;
        $this->error = $error;
    }

    public function ok(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 300;
    }

    /**
     * 時間をおいて再送する価値があるか。
     * 429（レート制限）と 5xx は一時的な失敗なのでリトライ対象。
     * 400 番台の大半は内容不備なので、何度送っても通らない＝リトライしない。
     */
    public function retryable(): bool
    {
        return $this->error !== null || $this->status === 429 || $this->status >= 500;
    }
}

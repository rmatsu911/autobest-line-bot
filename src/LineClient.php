<?php
/**
 * LINE Messaging API クライアント。
 *
 * Composer が使えないので SDK は入れず、curl 関数で直接叩く。
 * 呼び出し側が扱いやすいよう、成功可否と本文を Response で返す。
 */

declare(strict_types=1);

namespace App;

/** API 応答をまとめた値オブジェクト */
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

final class LineClient
{
    private const ENDPOINT = 'https://api.line.me';
    private const TIMEOUT  = 10;

    private string $accessToken;

    public function __construct(?string $accessToken = null)
    {
        $this->accessToken = $accessToken ?? Config::get('LINE_CHANNEL_ACCESS_TOKEN');
    }

    // -------------------------------------------------------------------------
    // メッセージ送信
    // -------------------------------------------------------------------------

    /**
     * 応答メッセージ。replyToken は1回きり・発行から短時間で失効する。
     * 重い処理の後に呼ぶと間に合わないことがあるので、返信は先に済ませる。
     */
    public function reply(string $replyToken, array $messages): LineResponse
    {
        return $this->post('/v2/bot/message/reply', [
            'replyToken' => $replyToken,
            'messages'   => array_slice($messages, 0, 5), // 1回に送れるのは5件まで
        ]);
    }

    /** 任意のタイミングで1人に送る */
    public function push(string $to, array $messages, ?string $retryKey = null): LineResponse
    {
        return $this->post('/v2/bot/message/push', [
            'to'       => $to,
            'messages' => array_slice($messages, 0, 5),
        ], $retryKey);
    }

    /**
     * 複数ユーザーへ一斉送信。1リクエスト500件までなので呼び出し側で分割する
     * （分割とレート制御は bin/broadcast.php の責務）。
     */
    public function multicast(array $userIds, array $messages, ?string $retryKey = null): LineResponse
    {
        return $this->post('/v2/bot/message/multicast', [
            'to'       => array_values(array_slice($userIds, 0, 500)),
            'messages' => array_slice($messages, 0, 5),
        ], $retryKey);
    }

    /** 友だち全員への配信 */
    public function broadcast(array $messages, ?string $retryKey = null): LineResponse
    {
        return $this->post('/v2/bot/message/broadcast', [
            'messages' => array_slice($messages, 0, 5),
        ], $retryKey);
    }

    // -------------------------------------------------------------------------
    // プロフィール
    // -------------------------------------------------------------------------

    /** 表示名などを取得。ブロック済みユーザーでは 403/404 になるので null を返す。 */
    public function profile(string $userId): ?array
    {
        $res = $this->request('GET', '/v2/bot/profile/' . rawurlencode($userId));
        return $res->ok() ? $res->json : null;
    }

    // -------------------------------------------------------------------------
    // リッチメニュー（フェーズ3の bin/setup_richmenu.php から使う）
    // -------------------------------------------------------------------------

    public function createRichMenu(array $definition): LineResponse
    {
        return $this->post('/v2/bot/richmenu', $definition);
    }

    /** リッチメニュー画像のアップロード。JSON ではなく画像バイナリを送るので専用処理。 */
    public function uploadRichMenuImage(string $richMenuId, string $imagePath): LineResponse
    {
        $mime = match (strtolower(pathinfo($imagePath, PATHINFO_EXTENSION))) {
            'png'          => 'image/png',
            'jpg', 'jpeg'  => 'image/jpeg',
            default        => '',
        };
        if ($mime === '' || !is_readable($imagePath)) {
            return new LineResponse(0, null, '', 'リッチメニュー画像が不正です: ' . $imagePath);
        }

        // 画像アップロードだけ api ではなく api-data ドメイン。
        return $this->raw(
            'POST',
            'https://api-data.line.me/v2/bot/richmenu/' . rawurlencode($richMenuId) . '/content',
            (string) file_get_contents($imagePath),
            ['Content-Type: ' . $mime]
        );
    }

    /** 全友だちの既定メニューにする */
    public function setDefaultRichMenu(string $richMenuId): LineResponse
    {
        return $this->request('POST', '/v2/bot/user/all/richmenu/' . rawurlencode($richMenuId));
    }

    public function listRichMenus(): LineResponse
    {
        return $this->request('GET', '/v2/bot/richmenu/list');
    }

    public function deleteRichMenu(string $richMenuId): LineResponse
    {
        return $this->request('DELETE', '/v2/bot/richmenu/' . rawurlencode($richMenuId));
    }

    // -------------------------------------------------------------------------
    // メッセージ組み立てヘルパ
    // -------------------------------------------------------------------------

    public static function text(string $text, ?array $quickReply = null): array
    {
        // テキストは5000文字まで。超過分は捨てずに末尾を切って送る。
        $message = ['type' => 'text', 'text' => mb_substr($text, 0, 5000)];
        if ($quickReply !== null) {
            $message['quickReply'] = $quickReply;
        }
        return $message;
    }

    /**
     * クイックリプライを組み立てる。
     *
     * @param array<int,array{label:string,data:string}> $items
     */
    public static function quickReply(array $items): array
    {
        $buttons = [];
        foreach (array_slice($items, 0, 13) as $item) { // 上限13件
            $buttons[] = [
                'type'   => 'action',
                'action' => [
                    'type'  => 'postback',
                    'label' => mb_substr($item['label'], 0, 20),
                    'data'  => $item['data'],
                    // displayText を付けるとトーク画面に押した内容が残り、
                    // ユーザーが「何を選んだか」を後から追えるようになる。
                    'displayText' => $item['label'],
                ],
            ];
        }
        return ['items' => $buttons];
    }

    // -------------------------------------------------------------------------
    // 内部：HTTP
    // -------------------------------------------------------------------------

    private function post(string $path, array $payload, ?string $retryKey = null): LineResponse
    {
        $headers = [];
        if ($retryKey !== null) {
            // 同じリトライキーで再送すると LINE 側が二重送信を弾いてくれる。
            // cron の再試行で同じメッセージが2回届くのを防ぐ要。
            $headers[] = 'X-Line-Retry-Key: ' . $retryKey;
        }
        return $this->request('POST', $path, $payload, $headers);
    }

    private function request(string $method, string $path, ?array $payload = null, array $extraHeaders = []): LineResponse
    {
        $body = $payload === null
            ? null
            // JSON_UNESCAPED_UNICODE：日本語を \uXXXX に展開しないことで本文が短くなり、
            // 5000文字制限やレスポンス速度の面で有利。
            : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = array_merge(['Content-Type: application/json'], $extraHeaders);
        return $this->raw($method, self::ENDPOINT . $path, $body, $headers);
    }

    private function raw(string $method, string $url, ?string $body, array $headers): LineResponse
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            // 証明書検証は絶対に切らない。切ると中間者にトークンを渡すことになる。
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => array_merge(
                ['Authorization: Bearer ' . $this->accessToken],
                $headers
            ),
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $status       = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError    = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        curl_close($ch);

        if ($responseBody === false) {
            Logger::error('LINE APIへの接続に失敗しました', ['url' => $url, 'error' => $curlError]);
            return new LineResponse(0, null, '', $curlError ?? 'curl error');
        }

        $json = json_decode((string) $responseBody, true);
        $res  = new LineResponse($status, is_array($json) ? $json : null, (string) $responseBody, $curlError);

        if (!$res->ok()) {
            // 失敗はログに残す。message には理由（例：Invalid reply token）が入る。
            Logger::error('LINE APIがエラーを返しました', [
                'url'    => $url,
                'status' => $status,
                'body'   => mb_substr((string) $responseBody, 0, 500),
            ]);
        }

        return $res;
    }

    /**
     * 認証不要 / 任意ヘッダの GET。LIFF トークン検証から使う。
     * インスタンスのアクセストークンを載せたくないので static で分けている。
     */
    public static function httpGet(string $url, array $headers = []): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            return null;
        }
        $json = json_decode((string) $body, true);
        return is_array($json) ? $json : null;
    }
}

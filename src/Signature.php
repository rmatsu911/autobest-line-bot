<?php
/**
 * LINE Webhook の署名検証。
 *
 * Webhook の URL は誰でも叩ける。署名検証だけが「本当に LINE から来たか」を
 * 判断する唯一の手段なので、これを通る前にDBへ書いたり返信したりしてはいけない。
 */

declare(strict_types=1);

namespace App;

final class Signature
{
    /**
     * X-Line-Signature を検証する。
     *
     * @param string $rawBody         php://input で読んだ生のリクエストボディ。
     *                                json_decode して再度 encode したものではダメ
     *                                （キー順・エスケープ・空白が変わり署名が一致しなくなる）。
     * @param string $signatureHeader X-Line-Signature ヘッダの値（Base64）
     * @param string $channelSecret   チャネルシークレット
     */
    public static function isValid(string $rawBody, string $signatureHeader, string $channelSecret): bool
    {
        if ($signatureHeader === '' || $channelSecret === '') {
            return false;
        }

        // HMAC-SHA256 のダイジェストを Base64 にしたものが署名。
        // 第4引数 true でバイナリのまま受け取り、そのまま base64_encode する。
        $expected = base64_encode(hash_hmac('sha256', $rawBody, $channelSecret, true));

        // 比較は必ず hash_equals。=== だと文字列長・先頭一致で処理時間が変わり、
        // 応答時間の差から署名を1文字ずつ推測されうる（タイミング攻撃）。
        return hash_equals($expected, $signatureHeader);
    }

    /**
     * リクエストから X-Line-Signature を取り出す。
     *
     * getallheaders() は環境によって存在しない/大文字小文字が揺れるため、
     * $_SERVER を第一候補にする（Apache + CGI/FastCGI いずれでも HTTP_ 接頭辞で入る）。
     */
    public static function headerFromRequest(): string
    {
        if (isset($_SERVER['HTTP_X_LINE_SIGNATURE'])) {
            return (string) $_SERVER['HTTP_X_LINE_SIGNATURE'];
        }
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'X-Line-Signature') === 0) {
                    return (string) $value;
                }
            }
        }
        return '';
    }

    /**
     * LIFF のアクセストークンをサーバー側で検証し、userId を得る（フェーズ4で使用）。
     *
     * クライアントから送られてきた userId をそのまま信用すると、
     * 他人の userId を騙って問い合わせを作れてしまう。必ずここを通す。
     *
     * @return array{userId:string,displayName:?string}|null 検証失敗時は null
     */
    public static function verifyLiffAccessToken(string $accessToken, string $channelId): ?array
    {
        if ($accessToken === '') {
            return null;
        }

        // 1) トークンがこのチャネル向けに発行されたものか確認する。
        //    これを省くと、別チャネルで取得した有効なトークンを流用されうる。
        $verify = LineClient::httpGet(
            'https://api.line.me/oauth2/v2.1/verify?access_token=' . rawurlencode($accessToken)
        );
        if ($verify === null || ($verify['client_id'] ?? '') !== $channelId) {
            Logger::warning('LIFFトークンの検証に失敗しました', ['reason' => 'client_id 不一致または検証エラー']);
            return null;
        }
        if ((int) ($verify['expires_in'] ?? 0) <= 0) {
            Logger::warning('LIFFトークンの検証に失敗しました', ['reason' => '期限切れ']);
            return null;
        }

        // 2) プロフィールを取得して userId を確定させる。
        $profile = LineClient::httpGet('https://api.line.me/v2/profile', [
            'Authorization: Bearer ' . $accessToken,
        ]);
        if ($profile === null || empty($profile['userId'])) {
            return null;
        }

        return [
            'userId'      => (string) $profile['userId'],
            'displayName' => isset($profile['displayName']) ? (string) $profile['displayName'] : null,
        ];
    }
}

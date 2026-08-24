<?php
/**
 * LIFF（LINEログイン）との橋渡し。
 *
 * ★現状は「配線だけ済ませて眠らせてある」状態。
 *   LINEログインチャネルを作って .env の LINE_LOGIN_CHANNEL_ID を入れると
 *   自動的に有効になり、それまでは常に null を返して素のWebフォームとして動く。
 *
 * 何のためにあるか：
 *   査定フォームをLINEのメニューから開いたとき、誰の申込かを自動で紐づける。
 *   紐づくと、その後の連絡をLINEのトークで返せる（電話番号の打ち間違いに強い）。
 *
 * 紐づかない場合でも申込自体は必ず通す。
 * LINEの設定不備でお客様の申込が消えるのが一番まずい。
 */

declare(strict_types=1);

namespace App;

final class LiffBridge
{
    /** LINEログインチャネルが設定されているか（フォーム側でSDKを読むか決めるのに使う） */
    public static function isConfigured(): bool
    {
        return Config::get('LINE_LOGIN_CHANNEL_ID', '') !== '';
    }

    /**
     * LIFFのアクセストークンから line_users.id を得る。
     * 検証に失敗したら null（=素のWebフォーム扱い）。
     */
    public static function resolveUserRowId(?string $accessToken): ?int
    {
        $channelId = Config::get('LINE_LOGIN_CHANNEL_ID', '');
        if ($channelId === '' || $accessToken === null || $accessToken === '') {
            return null;
        }

        try {
            $profile = Signature::verifyLiffAccessToken($accessToken, $channelId);
            if ($profile === null) {
                return null;
            }
            return self::upsertUser($profile['userId'], $profile['displayName']);
        } catch (\Throwable $e) {
            Logger::error('LIFFの紐づけに失敗しました', ['message' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * line_users に行が無ければ作る。
     * followed_at は触らない。LIFFを開いただけでは友だち追加とは限らないため。
     */
    private static function upsertUser(string $lineUserId, ?string $displayName): ?int
    {
        if (Db::isSqlite()) {
            Db::exec(
                'INSERT INTO line_users (line_user_id, display_name) VALUES (?, ?)
                 ON CONFLICT(line_user_id) DO UPDATE SET
                   display_name = COALESCE(excluded.display_name, line_users.display_name)',
                [$lineUserId, $displayName]
            );
        } else {
            Db::exec(
                'INSERT INTO line_users (line_user_id, display_name) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE display_name = COALESCE(VALUES(display_name), display_name)',
                [$lineUserId, $displayName]
            );
        }

        $id = Db::value('SELECT id FROM line_users WHERE line_user_id = ?', [$lineUserId]);
        return $id === null ? null : (int) $id;
    }
}

<?php
/**
 * Webhook イベントの振り分け。
 *
 * webhook.php は「署名検証して200を返す」ところまでを担当し、
 * イベントの中身の解釈はこのクラスに閉じる。
 *
 * フェーズ1の対応範囲：follow / unfollow / message(text) / postback（最小）。
 * 在庫カルーセルと査定LIFFはフェーズ3・4で差し込む。
 */

declare(strict_types=1);

namespace App;

final class WebhookHandler
{
    private LineClient $line;

    public function __construct(?LineClient $line = null)
    {
        $this->line = $line ?? new LineClient();
    }

    /** @param array<int,array> $events */
    public function handleEvents(array $events): void
    {
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            try {
                $this->handleEvent($event);
            } catch (\Throwable $e) {
                // 1件の失敗で残りのイベントを落とさない。
                Logger::error('イベント処理に失敗しました', [
                    'type'    => $event['type'] ?? '-',
                    'message' => $e->getMessage(),
                    'line'    => $e->getLine(),
                ]);
            }
        }
    }

    private function handleEvent(array $event): void
    {
        $type = (string) ($event['type'] ?? '');

        // 重複配信の検出。LINEは応答が遅い/失敗したイベントを再送するため、
        // 「あいさつが2通届く」「問い合わせが二重登録される」を防ぐ必要がある。
        $eventId = (string) ($event['webhookEventId'] ?? '');
        if ($eventId !== '' && !$this->markEventSeen($eventId, $type)) {
            Logger::info('重複イベントのためスキップしました', ['event_id' => $eventId, 'type' => $type]);
            return;
        }

        // LINE Developers の「検証」ボタンは replyToken が固定のダミー値で届く。
        // これで reply を呼ぶとエラーになるので、返信せずに終える。
        $replyToken = (string) ($event['replyToken'] ?? '');
        $isVerify   = in_array($replyToken, ['00000000000000000000000000000000', 'ffffffffffffffffffffffffffffffff'], true);

        match ($type) {
            'follow'   => $this->onFollow($event, $isVerify),
            'unfollow' => $this->onUnfollow($event),
            'message'  => $this->onMessage($event, $isVerify),
            'postback' => $this->onPostback($event, $isVerify),
            default    => Logger::info('未対応のイベントを受信しました', ['type' => $type]),
        };
    }

    // -------------------------------------------------------------------------
    // follow / unfollow
    // -------------------------------------------------------------------------

    private function onFollow(array $event, bool $isVerify): void
    {
        $lineUserId = (string) ($event['source']['userId'] ?? '');
        if ($lineUserId === '') {
            return;
        }

        // 表示名はプロフィールAPIから取る。取得できなくても登録は続行する
        // （友だち登録の記録の方が優先で、名前は後から埋め直せる）。
        $profile     = $this->line->profile($lineUserId);
        $displayName = $profile['displayName'] ?? null;

        $this->upsertUser($lineUserId, $displayName);

        if ($isVerify || ($event['replyToken'] ?? '') === '') {
            return;
        }

        $name = $displayName !== null ? $displayName . 'さん、' : '';
        $greeting = <<<TXT
        {$name}友だち追加ありがとうございます。
        中古車の買取・販売 autobest.jp です。

        下のメニューからご利用ください。
        ・無料査定を申し込む
        ・買取実績を見る
        ・販売中の車を見る
        ・条件で探す
        ・よくある質問
        ・電話する

        「査定」「在庫」「営業時間」などのことばを送っていただいても大丈夫です。
        TXT;

        $this->line->reply((string) $event['replyToken'], [
            LineClient::text($greeting, LineClient::quickReply([
                ['label' => '販売中の車を見る', 'data' => 'action=cars&page=1'],
                ['label' => '買取実績を見る',   'data' => 'action=purchases&page=1'],
                ['label' => 'よくある質問',     'data' => 'action=faq'],
            ])),
        ]);
    }

    private function onUnfollow(array $event): void
    {
        $lineUserId = (string) ($event['source']['userId'] ?? '');
        if ($lineUserId === '') {
            return;
        }

        // 行は消さない。再フォロー時に履歴（問い合わせ）と繋げ直せるようにするため。
        Db::exec(
            'UPDATE line_users SET blocked = 1 WHERE line_user_id = ?',
            [$lineUserId]
        );
        Logger::info('ブロックされました', ['line_user_id' => $lineUserId]);
    }

    // -------------------------------------------------------------------------
    // message
    // -------------------------------------------------------------------------

    private function onMessage(array $event, bool $isVerify): void
    {
        $lineUserId = (string) ($event['source']['userId'] ?? '');
        if ($lineUserId !== '') {
            // ブロック解除後の再送信でも行が無いことがあるので、ここでも upsert する。
            $this->upsertUser($lineUserId, null);
        }

        $replyToken = (string) ($event['replyToken'] ?? '');
        if ($isVerify || $replyToken === '') {
            return;
        }

        $messageType = (string) ($event['message']['type'] ?? '');
        if ($messageType !== 'text') {
            // 画像・スタンプなど。査定用の写真はLIFFから受け取る方針なので、
            // ここでは案内だけ返す。
            $this->line->reply($replyToken, [
                LineClient::text(
                    "メッセージを受け取りました。\nお車の査定をご希望の場合は、メニューの「無料査定を申し込む」からお願いします。"
                ),
            ]);
            return;
        }

        $text = trim((string) ($event['message']['text'] ?? ''));
        $this->line->reply($replyToken, $this->replyForKeyword($text));
    }

    /**
     * キーワードから返信内容を決める。
     *
     * フェーズ1は固定文言。フェーズ3で「在庫」系を FlexBuilder のカルーセルに差し替える。
     *
     * @return array<int,array> messages
     */
    private function replyForKeyword(string $text): array
    {
        $normalized = $this->normalize($text);

        $matches = static fn(array $words): bool => (bool) array_filter(
            $words,
            static fn(string $w): bool => str_contains($normalized, $w)
        );

        // normalize() でひらがなに寄せているので、キーワードはひらがなか漢字で並べる
        // （「サテイ」「ｻﾃｲ」はここに書かなくても「さてい」で拾える）。
        if ($matches(['査定', 'さてい', '買取', 'かいとり', '売りたい', 'うりたい', '見積', 'みつも'])) {
            return [LineClient::text(
                "無料査定を承ります。\nメニューの「無料査定を申し込む」から、お車の情報と写真をお送りください。最短で当日中にお見積りをご連絡します。"
            )];
        }

        if ($matches(['在庫', 'ざいこ', '販売', '買いたい', 'かいたい', '探し', 'さがし', '車を見', 'くるまを見'])) {
            return [LineClient::text(
                "販売中のお車をご案内します。\nメニューの「販売中の車を見る」または「条件で探す」からご覧ください。",
                LineClient::quickReply([
                    ['label' => '販売中の車を見る', 'data' => 'action=cars&page=1'],
                    ['label' => '買取実績を見る',   'data' => 'action=purchases&page=1'],
                ])
            )];
        }

        if ($matches(['営業', '時間', '定休', '休み', '場所', '住所', 'アクセス', '地図'])) {
            return [LineClient::text($this->shopInfoText())];
        }

        if ($matches(['電話', 'でんわ', 'tel', '連絡'])) {
            return [LineClient::text(
                'お電話でのお問い合わせは ' . Config::get('SHOP_TEL', '') . " へどうぞ。\n"
                . Config::get('SHOP_HOURS', '') . ' に受け付けております。'
            )];
        }

        if ($matches(['faq', 'よくある', '質問', 'わからない', '使い方'])) {
            return [LineClient::text($this->faqText())];
        }

        // 該当なし。「分かりません」で終わらせず、次の行動を選べるようにする。
        return [LineClient::text(
            "お問い合わせありがとうございます。\n担当者が確認のうえご連絡します。\nお急ぎの場合は下のボタンかお電話（"
            . Config::get('SHOP_TEL', '') . "）をご利用ください。",
            LineClient::quickReply([
                ['label' => '無料査定について',   'data' => 'action=assessment_info'],
                ['label' => '販売中の車を見る',   'data' => 'action=cars&page=1'],
                ['label' => 'よくある質問',       'data' => 'action=faq'],
            ])
        )];
    }

    // -------------------------------------------------------------------------
    // postback
    // -------------------------------------------------------------------------

    private function onPostback(array $event, bool $isVerify): void
    {
        $replyToken = (string) ($event['replyToken'] ?? '');
        if ($isVerify || $replyToken === '') {
            return;
        }

        // data は "action=cars&page=1" 形式。parse_str でそのまま配列にする。
        parse_str((string) ($event['postback']['data'] ?? ''), $params);
        $action = (string) ($params['action'] ?? '');

        $messages = match ($action) {
            'faq'             => [LineClient::text($this->faqText())],
            'assessment_info' => [LineClient::text(
                "無料査定は、メニューの「無料査定を申し込む」からお申し込みいただけます。\nメーカー・車種・年式・走行距離とお車の写真をお送りいただければ、お見積りをご連絡します。"
            )],
            // フェーズ3・4で実装する導線。今は準備中と明示して黙って落とさない。
            'cars', 'purchases', 'inquiry' => [LineClient::text(
                "ただいま準備中の機能です。\nお手数ですが、メッセージまたはお電話（" . Config::get('SHOP_TEL', '') . "）でお問い合わせください。"
            )],
            default => [LineClient::text('操作を受け付けられませんでした。メニューからもう一度お試しください。')],
        };

        if ($action === '') {
            Logger::warning('空のpostbackを受信しました', ['data' => $event['postback']['data'] ?? '']);
        }

        $this->line->reply($replyToken, $messages);
    }

    // -------------------------------------------------------------------------
    // 共通
    // -------------------------------------------------------------------------

    /**
     * line_users への upsert。
     *
     * SELECT してから INSERT/UPDATE を分けると、同じユーザーのイベントが
     * ほぼ同時に届いたときに二重INSERTになる。UNIQUE制約 + ON DUPLICATE KEY UPDATE で
     * 1文にまとめ、判断をDBに任せる。
     */
    private function upsertUser(string $lineUserId, ?string $displayName): void
    {
        Db::exec(
            'INSERT INTO line_users (line_user_id, display_name, followed_at, blocked)
             VALUES (:line_user_id, :display_name, NOW(), 0)
             ON DUPLICATE KEY UPDATE
                -- 再フォローなのでブロック状態は必ず解除する
                blocked = 0,
                -- 表示名は取得できたときだけ更新（APIが失敗しても既存の名前を消さない）
                display_name = COALESCE(VALUES(display_name), display_name),
                -- followed_at は初回の値を保つ
                followed_at = COALESCE(followed_at, NOW())',
            [
                ':line_user_id' => $lineUserId,
                ':display_name' => $displayName,
            ]
        );
    }

    /**
     * イベントを「処理済み」として記録する。初めてなら true。
     *
     * SELECT で存在確認してから INSERT すると、再送が同時に届いたときに
     * 両方とも「未処理」と判定してすり抜ける。UNIQUE制約に判定を任せ、
     * INSERT IGNORE の影響行数で見分ける。
     */
    private function markEventSeen(string $eventId, string $type): bool
    {
        $affected = Db::exec(
            'INSERT IGNORE INTO webhook_events (event_id, event_type) VALUES (?, ?)',
            [$eventId, $type !== '' ? $type : null]
        );
        return $affected > 0;
    }

    /**
     * 表記ゆれを吸収する。
     *
     * ユーザーは「査定」「サテイ」「ｻﾃｲ」「さてい」を区別せずに送ってくる。
     * 変換記号の意味：
     *   a … 全角の英数字・記号を半角へ（「ＦＡＱ」→「FAQ」）
     *   s … 全角スペースを半角へ
     *   K … 半角カタカナを全角カタカナへ（「ｻﾃｲ」→「サテイ」）
     *   V … 濁点・半濁点を1文字に結合（「ｻﾞ」→「ザ」。K と併用する必要がある）
     *   c … 全角カタカナをひらがなへ（「サテイ」→「さてい」）
     *
     * 2回に分けて呼ぶ理由：mb_convert_kana は変換結果を再変換しない。
     * 'asKVc' と一度に渡すと、c が見るのは「元から全角カタカナだった文字」だけで、
     * K で全角化されたばかりの「サテイ」はひらがなにならず取りこぼす。
     * 最後にひらがなへ寄せるので、キーワード側はひらがなか漢字で書く。
     */
    private function normalize(string $text): string
    {
        $text = mb_convert_kana($text, 'asKV');  // 英数字を半角へ、半角カナを全角カナへ
        $text = mb_convert_kana($text, 'c');     // 全角カタカナをひらがなへ
        return mb_strtolower($text);
    }

    private function shopInfoText(): string
    {
        return implode("\n", array_filter([
            Config::get('SHOP_NAME', 'autobest.jp'),
            Config::get('SHOP_ADDRESS', '') !== '' ? '住所: ' . Config::get('SHOP_ADDRESS', '') : '',
            Config::get('SHOP_TEL', '') !== ''     ? '電話: ' . Config::get('SHOP_TEL', '') : '',
            Config::get('SHOP_HOURS', '') !== ''   ? '営業時間: ' . Config::get('SHOP_HOURS', '') : '',
            Config::get('SHOP_HOLIDAY', '') !== '' ? '定休日: ' . Config::get('SHOP_HOLIDAY', '') : '',
        ]));
    }

    private function faqText(): string
    {
        return <<<TXT
        よくあるご質問

        Q. 査定は無料ですか？
        A. 無料です。お申し込み後に費用が発生することはありません。

        Q. 車検が切れていても買い取ってもらえますか？
        A. お預かりできます。引き取りもご相談ください。

        Q. ローンが残っていても売れますか？
        A. 可能です。残債の精算方法をあわせてご案内します。

        Q. 販売中の車は現車確認できますか？
        A. できます。ご来店の日時をメッセージでお知らせください。

        その他のご質問は、このトークにそのままお送りください。
        TXT;
    }
}

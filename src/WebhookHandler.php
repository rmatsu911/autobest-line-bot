<?php
/**
 * Webhook イベントの振り分け。
 *
 * webhook.php は「署名検証して200を返す」ところまでを担当し、
 * イベントの中身の解釈はこのクラスに閉じる。
 *
 * 対応範囲：follow / unfollow / message(text) / postback。
 * 在庫カルーセルはフェーズ3、査定・予約フォームへの導線はフェーズ4で差し込んだ。
 * フォームはLIFFではなく素のWebページなので、LINEログインチャネルが無くても動く。
 */

declare(strict_types=1);

namespace App;

final class WebhookHandler
{
    private LineClient $line;

    /** 処理中イベントの送信者。キーワード応答からお気に入りを引くのに使う */
    private string $currentUserId = '';

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

        $this->currentUserId = $lineUserId;
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
            // 文言だけでなく申込フォームのボタンも一緒に返す。
            // 「メニューから探してください」と案内するより、その場で開ける方が申込が残る。
            return [
                LineClient::text(
                    "無料査定を承ります。\nメニューの「無料査定を申し込む」から、お車の情報と写真をお送りください。最短で当日中にお見積りをご連絡します。"
                ),
                $this->assessmentGuide(),
            ];
        }

        // 「新着」「通知」は在庫の判定より前に見る。
        // 後ろに置くと「新着在庫ある？」が「在庫」に先に拾われ、
        // その人の条件に合った新着ではなく通常の一覧が出てしまう。
        if ($matches(['通知', 'つうち', 'おしらせ', 'お知らせ'])) {
            return $this->notifySettings($this->currentUserId);
        }
        if ($matches(['新着', 'しんちゃく', '新しい', 'あたらしい', '入荷', 'にゅうか', '入庫'])) {
            return $this->newArrivals($this->currentUserId);
        }

        // 在庫系のことばは、案内文ではなく実際のカルーセルを返す。
        // 「メニューから選んでください」と一段挟むと離脱するため。
        // 車種そのものを挙げる人も在庫を探している。
        // 「重機を見たい」のように「在庫」という語を使わない聞き方を拾うため、
        // 車種の語も在庫検索のきっかけに含める。
        $isTruck     = $matches(['とらっく', 'ばす', 'だんぷ', '平ぼでぃ', 'ゆにっく']);
        $isMachinery = $matches(['重機', 'じゅうき', 'ゆんぼ', 'ふぉーくりふと', 'しょべる', 'ゆあつ', '建機', 'けんき']);

        if ($isTruck || $isMachinery
            || $matches(['在庫', 'ざいこ', '販売', '買いたい', 'かいたい', '探し', 'さがし', '車を見', 'くるまを見'])) {
            $filters = ['page' => 1];
            if ($isTruck) {
                $filters['category'] = 'truck';
            } elseif ($isMachinery) {
                $filters['category'] = 'machinery';
            }
            if ($matches(['福岡', 'ふくおか'])) {
                $filters['location'] = 'fukuoka';
            } elseif ($matches(['神奈川', 'かながわ', '横浜', 'よこはま'])) {
                $filters['location'] = 'kanagawa';
            }
            return $this->carsCarousel($filters);
        }

        if ($matches(['営業', '時間', '定休', '休み', '場所', '住所', 'あくせす', '地図'])) {
            return [LineClient::text($this->shopInfoText())];
        }

        if ($matches(['電話', 'でんわ', 'tel', '連絡'])) {
            return [LineClient::text(
                'お電話でのお問い合わせは ' . Config::get('SHOP_TEL', '') . " へどうぞ。\n"
                . Config::get('SHOP_HOURS', '') . ' に受け付けております。'
            )];
        }

        if ($matches(['お気に入り', 'おきにいり'])) {
            return $this->favoritesCarousel($this->currentUserId);
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
        $action     = (string) ($params['action'] ?? '');
        $lineUserId = (string) ($event['source']['userId'] ?? '');

        // タブ切替（richmenuswitch）も postback として飛んでくる。
        // メニューが切り替わること自体が結果なので、返信はしない。
        // ここで何か返すと、タブを押すたびにトークが埋まる。
        if (isset($params['tab'])) {
            return;
        }

        if ($action === '') {
            Logger::warning('空のpostbackを受信しました', ['data' => $event['postback']['data'] ?? '']);
        }

        $messages = match ($action) {
            'cars'            => $this->carsCarousel($params),
            'fav'             => $this->addFavorite($lineUserId, (int) ($params['car_id'] ?? 0)),
            'favorites'       => $this->favoritesCarousel($lineUserId),
            'stores'          => [LineClient::text($this->storesText())],
            'search_menu'     => [$this->searchMenu()],
            'faq'             => [LineClient::text($this->faqText())],
            'assessment_info' => [LineClient::text(
                "無料査定は、メニューの「無料買取査定」からお申し込みいただけます。\nメーカー・車種・年式・走行距離とお車の写真をお送りいただければ、お見積りをご連絡します。"
            )],
            // フェーズ4で用意した申込フォーム。LINEログインが無くても使える素のページ。
            'assessment'      => [$this->assessmentGuide()],
            'reserve'         => [$this->reserveGuide((int) ($params['car_id'] ?? 0))],
            'inquiry'         => [$this->inquiryGuide((int) ($params['car_id'] ?? 0))],
            // 新着のお知らせ。自動配信ではなく、押されたときにその人向けの新着を返す。
            'new_arrivals'    => $this->newArrivals($lineUserId),
            'notify_settings' => $this->notifySettings($lineUserId),
            'notify_set'      => $this->notifySet($lineUserId, $params),
            'notify_clear'    => $this->notifyClear($lineUserId),
            // フェーズ5で実装する導線。今は準備中と明示して黙って落とさない。
            'contact', 'consult',
            'my_reservations', 'purchases',
            'why_us', 'flow', 'documents' => [LineClient::text(
                "ただいま準備中の機能です。\nお手数ですが、このトークにメッセージを送っていただくか、お電話（"
                . Config::get('SHOP_TEL', '') . "）でお問い合わせください。担当者が確認してご返信します。"
            )],
            default => [LineClient::text('操作を受け付けられませんでした。メニューからもう一度お試しください。')],
        };

        $this->line->reply($replyToken, $messages);
    }

    /**
     * 査定申込フォームへの案内。
     *
     * LIFFではなく素のWebページを開かせている。LINEログインチャネルが無くても
     * 動くうえ、店頭のQRコードやWebサイトからも同じフォームを使えるため。
     */
    private function assessmentGuide(): array
    {
        return FlexBuilder::linkCard(
            '無料査定のお申し込み',
            "お車の情報と写真をお送りいただければ、担当者が査定額をご連絡します。\n入力は1分ほどで終わります。",
            '査定を申し込む',
            FlexBuilder::assessmentUrl()
        );
    }

    /** 来店・商談予約フォームへの案内 */
    private function reserveGuide(int $carId): array
    {
        return FlexBuilder::linkCard(
            '来店・商談のご予約',
            "ご希望の日時を第3希望までお選びください。\n担当者が確認して、確定した日時をご連絡します。",
            '予約する',
            FlexBuilder::reserveUrl($carId)
        );
    }

    /**
     * 在庫の問い合わせ。
     *
     * 専用フォームは作らず、来店予約フォームへ寄せている。
     * 「この車が見たい」の次にお客様がしたいのは来店であって、
     * 質問フォームを1枚挟むとそこで止まってしまうため。
     * 文章で聞きたい人向けに、トークにそのまま書ける旨も添える。
     */
    private function inquiryGuide(int $carId): array
    {
        return FlexBuilder::linkCard(
            'この車について',
            "見学のご予約はこちらから。\nご質問だけの場合は、このトークにそのままお書きください。担当者が確認してご返信します。",
            '来店・商談を予約する',
            FlexBuilder::reserveUrl($carId)
        );
    }

    /**
     * その人向けの新着。
     *
     * 無料プランの200通を使い切らないよう、自動配信はしない。
     * 条件を覚えておいて、押されたときに reply で返す（reply は通数に入らない）。
     *
     * 条件が未設定の人には全体の新着をそのまま見せる。
     * 「まず押してみたら空だった」で終わらせないため。
     *
     * @return array<int,array>
     */
    private function newArrivals(string $lineUserId): array
    {
        $userRowId = $this->userRowId($lineUserId);
        if ($userRowId === null) {
            // 友だち追加の記録が無いときは、条件なしの新着一覧で代替する。
            return $this->carsCarousel(['page' => 1, 'sort' => 'new']);
        }

        if (!NotificationRepository::hasCondition($userRowId)) {
            return $this->carsCarousel(['page' => 1, 'sort' => 'new']);
        }

        $result = NotificationRepository::newArrivals($userRowId);
        $row    = NotificationRepository::conditionFor($userRowId);
        $cond   = NotificationRepository::conditionText($row);

        if ($result['rows'] === []) {
            return [LineClient::text(
                "いまのところ、ご登録の条件に合う新着はありません。\n条件：{$cond}\n新しく入庫したらここでお知らせします。",
                LineClient::quickReply([
                    ['label' => '条件を変える',   'data' => 'action=notify_settings'],
                    ['label' => 'すべての在庫',   'data' => 'action=cars&page=1'],
                    ['label' => '全体の新着',     'data' => 'action=cars&page=1&sort=new'],
                ])
            )];
        }

        // 見せた分だけを記録する。次ページ判定用に多く取った1件は含めない。
        NotificationRepository::markSeen(
            $userRowId,
            array_map(static fn (array $car): int => (int) $car['id'], $result['rows'])
        );

        $messages = [LineClient::text("ご登録の条件（{$cond}）に合う新着です。")];
        // 「もっと見る」は同じactionでよい。今見せた分は記録済みなので、次は続きが出る。
        $messages[] = FlexBuilder::carousel($result['rows'], 1, false, '');

        if ($result['hasNext']) {
            $messages[] = LineClient::text('続きもあります。',
                LineClient::quickReply([
                    ['label' => '続きを見る',   'data' => 'action=new_arrivals'],
                    ['label' => '条件を変える', 'data' => 'action=notify_settings'],
                ]));
        }

        return $messages;
    }

    /**
     * 新着のお知らせ設定。
     *
     * 「自動で送らない」ことを必ず書く。黙って押し待ちにすると
     * 「登録したのに通知が来ない」という問い合わせを生むため。
     *
     * @return array<int,array>
     */
    private function notifySettings(string $lineUserId): array
    {
        $userRowId = $this->userRowId($lineUserId);
        $row       = $userRowId === null ? null : NotificationRepository::conditionFor($userRowId);
        $cond      = NotificationRepository::conditionText($row);

        $text = "【新着のお知らせ設定】\n"
              . "いまの条件：{$cond}\n\n"
              . "条件に合う新しい在庫が入ったら、メニューの「新着入庫」からご覧いただけます。\n"
              . "下のボタンで条件を変えられます。";

        return [LineClient::text($text, LineClient::quickReply([
            ['label' => '新着を見る',       'data' => 'action=new_arrivals'],
            ['label' => '乗用車・軽',       'data' => 'action=notify_set&category=passenger'],
            ['label' => 'トラック・バス',   'data' => 'action=notify_set&category=truck'],
            ['label' => '重機・作業車',     'data' => 'action=notify_set&category=machinery'],
            ['label' => 'その他車両',       'data' => 'action=notify_set&category=other'],
            ['label' => '車種の指定なし',   'data' => 'action=notify_set&category='],
            ['label' => '福岡本社',         'data' => 'action=notify_set&location=fukuoka'],
            ['label' => '神奈川支店',       'data' => 'action=notify_set&location=kanagawa'],
            ['label' => '拠点の指定なし',   'data' => 'action=notify_set&location='],
            ['label' => '100万円以下',      'data' => 'action=notify_set&price_max=1000000'],
            ['label' => '300万円以下',      'data' => 'action=notify_set&price_max=3000000'],
            ['label' => '予算の指定なし',   'data' => 'action=notify_set&price_max='],
            ['label' => '条件をすべて消す', 'data' => 'action=notify_clear'],
        ]))];
    }

    /**
     * 条件を1項目だけ変える。
     *
     * 値が空文字（例：action=notify_set&location=）なら、その項目を外す。
     * isset() ではなく array_key_exists() で見るのは、
     * 「指定なしにする」と「そもそも送られていない」を区別するため
     * （parse_str は空文字を作るので isset は両方 true になる）。
     *
     * @return array<int,array>
     */
    private function notifySet(string $lineUserId, array $params): array
    {
        $userRowId = $this->userRowId($lineUserId);
        if ($userRowId === null) {
            return [LineClient::text('設定を保存できませんでした。お手数ですが、もう一度お試しください。')];
        }

        $changes = [];
        foreach (['category', 'location', 'price_max'] as $key) {
            if (!array_key_exists($key, $params)) {
                continue;
            }
            $value = (string) $params[$key];

            // 選択肢の偽装を弾く。ここを通す値だけがDBに入る。
            if ($key === 'category' && $value !== '' && !in_array($value, CarValidator::CATEGORIES, true)) {
                continue;
            }
            if ($key === 'location' && $value !== '' && !in_array($value, CarValidator::LOCATIONS, true)) {
                continue;
            }
            if ($key === 'price_max' && $value !== '' && !ctype_digit($value)) {
                continue;
            }
            $changes[$key] = $value;
        }

        if ($changes === []) {
            return $this->notifySettings($lineUserId);
        }

        NotificationRepository::updateCondition($userRowId, $changes);

        $cond = NotificationRepository::conditionText(NotificationRepository::conditionFor($userRowId));

        return [LineClient::text(
            "条件を保存しました。\n条件：{$cond}",
            LineClient::quickReply([
                ['label' => '新着を見る',   'data' => 'action=new_arrivals'],
                ['label' => '条件を変える', 'data' => 'action=notify_settings'],
            ])
        )];
    }

    /** @return array<int,array> */
    private function notifyClear(string $lineUserId): array
    {
        $userRowId = $this->userRowId($lineUserId);
        if ($userRowId !== null) {
            NotificationRepository::clearCondition($userRowId);
        }

        return [LineClient::text(
            "条件を消しました。これからは全体の新着をお見せします。",
            LineClient::quickReply([
                ['label' => '新着を見る',   'data' => 'action=new_arrivals'],
                ['label' => '条件を決める', 'data' => 'action=notify_settings'],
            ])
        )];
    }

    /** LINEのuserId から line_users.id を引く。無ければ null。 */
    private function userRowId(string $lineUserId): ?int
    {
        if ($lineUserId === '') {
            return null;
        }
        $row = Db::one('SELECT id FROM line_users WHERE line_user_id = ?', [$lineUserId]);
        return $row === null ? null : (int) $row['id'];
    }

    /**
     * 在庫カルーセル。postback の絞り込みをそのまま次ページへ引き継ぐ。
     *
     * @return array<int,array>
     */
    private function carsCarousel(array $params): array
    {
        $page = max(1, (int) ($params['page'] ?? 1));

        $filters = [];
        $carry   = [];
        foreach (['category', 'location', 'sort'] as $key) {
            $value = (string) ($params[$key] ?? '');
            if ($value !== '') {
                $filters[$key] = $value;
                $carry[]       = $key . '=' . rawurlencode($value);
            }
        }

        $result = CarRepository::published($filters, $page, FlexBuilder::PER_PAGE);

        if ($result['rows'] === []) {
            // 2ページ目以降で空になるのは「最後まで見終わった」状態。
            // 0件の案内ではなく、終わりであることを伝える。
            if ($page > 1) {
                return [LineClient::text('これで最後です。ほかの条件でもお探しいただけます。',
                    LineClient::quickReply([['label' => 'すべての在庫', 'data' => 'action=cars&page=1']]))];
            }
            return [FlexBuilder::emptyResult($this->conditionText($filters))];
        }

        return [FlexBuilder::carousel($result['rows'], $page, $result['hasNext'], implode('&', $carry))];
    }

    /** お気に入りへの追加。二重登録はDBのUNIQUE制約に任せる */
    private function addFavorite(string $lineUserId, int $carId): array
    {
        if ($lineUserId === '' || $carId <= 0) {
            return [LineClient::text('お気に入りに追加できませんでした。')];
        }

        $car = CarRepository::findPublished($carId);
        if ($car === null) {
            // 公開が終わった車両。存在を伏せず、終了したことを伝える。
            return [LineClient::text('この車両は掲載が終了しました。')];
        }

        $userRow = Db::one('SELECT id FROM line_users WHERE line_user_id = ?', [$lineUserId]);
        if ($userRow === null) {
            return [LineClient::text('お気に入りに追加できませんでした。')];
        }

        $sql = Db::isSqlite()
            ? 'INSERT OR IGNORE INTO favorites (line_user_id, car_id) VALUES (?, ?)'
            : 'INSERT IGNORE INTO favorites (line_user_id, car_id) VALUES (?, ?)';
        $added = Db::exec($sql, [(int) $userRow['id'], $carId]);

        $name = trim(($car['maker'] ?? '') . ' ' . ($car['model_name'] ?? ''));

        return [LineClient::text(
            $added > 0
                ? "「{$name}」をお気に入りに追加しました。"
                : "「{$name}」はすでにお気に入りに入っています。",
            LineClient::quickReply([
                ['label' => 'お気に入りを見る', 'data' => 'action=favorites'],
                ['label' => '在庫をもっと見る', 'data' => 'action=cars&page=1'],
            ])
        )];
    }

    /** お気に入り一覧をカルーセルで返す */
    private function favoritesCarousel(string $lineUserId): array
    {
        if ($lineUserId === '') {
            return [LineClient::text('お気に入りを取得できませんでした。')];
        }

        $rows = Db::all(
            'SELECT c.*,
                    (SELECT image_url FROM car_images WHERE car_id = c.id ORDER BY position, id LIMIT 1) AS thumb_url
             FROM favorites f
             JOIN cars c ON c.id = f.car_id
             JOIN line_users u ON u.id = f.line_user_id
             WHERE u.line_user_id = ? AND c.status = \'published\'
             ORDER BY f.created_at DESC',
            [$lineUserId]
        );

        if ($rows === []) {
            return [LineClient::text(
                "お気に入りはまだありません。\n気になるお車のカードから「お気に入り」を押すと保存できます。",
                LineClient::quickReply([['label' => '販売在庫を見る', 'data' => 'action=cars&page=1']])
            )];
        }

        return [FlexBuilder::carousel(array_slice($rows, 0, FlexBuilder::PER_PAGE), 1, false)];
    }

    /** 0件メッセージに出す条件の文言 */
    private function conditionText(array $filters): string
    {
        $parts = array_filter([
            car_category_label($filters['category'] ?? null),
            car_location_label($filters['location'] ?? null),
        ]);
        return implode(' ／ ', $parts);
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
        if (Db::isSqlite()) {
            Db::exec(
                "INSERT INTO line_users (line_user_id, display_name, followed_at, blocked)
                 VALUES (:line_user_id, :display_name, " . Db::nowSql() . ", 0)
                 ON CONFLICT(line_user_id) DO UPDATE SET
                    blocked = 0,
                    display_name = COALESCE(excluded.display_name, line_users.display_name),
                    followed_at = COALESCE(line_users.followed_at, " . Db::nowSql() . "),
                    updated_at = " . Db::nowSql(),
                [
                    ':line_user_id' => $lineUserId,
                    ':display_name' => $displayName,
                ]
            );
            return;
        }

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
        if (Db::isSqlite()) {
            $affected = Db::exec(
                'INSERT OR IGNORE INTO webhook_events (event_id, event_type) VALUES (?, ?)',
                [$eventId, $type !== '' ? $type : null]
            );
            return $affected > 0;
        }

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
     * 最後にひらがなへ寄せるので、キーワード側は必ずひらがなか漢字で書く。
     * カタカナのまま書くと入力側がひらがなに変換済みで、永久に一致しない。
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

    /** 2拠点の案内。要件書の「福岡・神奈川の店舗」に対応する */
    private function storesText(): string
    {
        $lines = ['AUTOBEST の拠点'];

        foreach ([
            ['福岡本社',   'SHOP_FUKUOKA_ADDRESS', 'SHOP_FUKUOKA_TEL'],
            ['神奈川支店', 'SHOP_KANAGAWA_ADDRESS', 'SHOP_KANAGAWA_TEL'],
        ] as [$label, $addrKey, $telKey]) {
            $addr = Config::get($addrKey, '');
            $tel  = Config::get($telKey, Config::get('SHOP_TEL', ''));
            $lines[] = '';
            $lines[] = '■ ' . $label;
            if ($addr !== '') { $lines[] = $addr; }
            if ($tel !== '')  { $lines[] = 'TEL: ' . $tel; }
        }

        $hours = Config::get('SHOP_HOURS', '');
        if ($hours !== '') {
            $lines[] = '';
            $lines[] = '営業時間: ' . $hours;
        }
        $holiday = Config::get('SHOP_HOLIDAY', '');
        if ($holiday !== '') {
            $lines[] = '定休日: ' . $holiday;
        }

        return implode("\n", $lines);
    }

    /**
     * 「条件から検索」。LIFFを使わず、クイックリプライで絞り込ませる。
     * フェーズ4でLIFFの検索画面に差し替える。
     */
    private function searchMenu(): array
    {
        return LineClient::text(
            "お探しの条件をお選びください。",
            LineClient::quickReply([
                ['label' => '乗用車・軽',     'data' => 'action=cars&page=1&category=passenger'],
                ['label' => 'トラック・バス', 'data' => 'action=cars&page=1&category=truck'],
                ['label' => '重機・作業車',   'data' => 'action=cars&page=1&category=machinery'],
                ['label' => 'その他車両',     'data' => 'action=cars&page=1&category=other'],
                ['label' => '福岡本社',       'data' => 'action=cars&page=1&location=fukuoka'],
                ['label' => '神奈川支店',     'data' => 'action=cars&page=1&location=kanagawa'],
                ['label' => '新着順で見る',   'data' => 'action=cars&page=1&sort=new'],
                ['label' => 'すべての在庫',   'data' => 'action=cars&page=1'],
            ])
        );
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

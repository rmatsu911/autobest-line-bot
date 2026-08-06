# autobest.jp LINE公式アカウント bot

中古車の買取・販売「autobest.jp」の LINE公式アカウントを bot 化し、
オーナーが管理画面から在庫・買取情報を更新できるようにするシステム。

エックスサーバーの**共用**レンタルサーバーで動かす前提のため、次の制約を設計に織り込んでいる。

| 制約 | 対応 |
|---|---|
| Composer が使えない | 外部ライブラリ非依存。`curl` で LINE API を直接叩き、オートローダは自前（`config/config.php`） |
| 常駐プロセスが使えない | ワーカー・キューデーモン・WebSocket は不使用。非同期処理は `message_queue` テーブル＋cron |
| WAF の誤検知がある | bot 用サブドメイン側で WAF を緩める前提。防御はアプリ側（署名検証・プリペアドステートメント・エスケープ）で自前実装 |
| PHP の実行時間制限がある | 一斉配信は cron でバッチ化。Webhook は重い処理の前に 200 を返す |
| DB ホストが `localhost` でない | 接続先は `.env` の `DB_HOST`（`mysqlXXX.xserver.jp` 形式） |

---

## 開発状況

| フェーズ | 内容 | 状態 |
|---|---|---|
| 1 | `schema.sql` / `Db` / `Signature` / `LineClient` / `webhook.php`（follow・単純なtext応答） | **完了** |
| 2 | 管理画面の在庫CRUD＋画像アップロード | 未着手 |
| 3 | `FlexBuilder` と在庫カルーセル、リッチメニュー登録スクリプト | 未着手 |
| 4 | 査定申込LIFF＋問い合わせ管理 | 未着手 |
| 5 | cronバッチ（キュー処理・一斉配信） | 未着手 |

在庫データは**管理画面から手入力**する方針（中古車ポータルからのデータ流用は行わない）。
既存サイト `autobest.jp` とは分離し、bot 側で独立した DB を持つ。

---

## ディレクトリ構成

```
autobest-line-bot/
├── .env                      # 認証情報（コミットしない。600）
├── public/                   # ← ここだけがドキュメントルート
│   ├── .htaccess
│   └── webhook.php           # LINE Webhook 受け口
├── src/
│   ├── Db.php                # PDO 接続
│   ├── Logger.php            # ファイルロガー
│   ├── Signature.php         # 署名検証 / LIFFトークン検証
│   ├── LineClient.php        # reply / push / multicast / richmenu
│   └── WebhookHandler.php    # イベント振り分け
├── bin/
│   ├── healthcheck.php       # 設置後の動作確認
│   └── send_test_webhook.php # 署名付きダミーWebhookの送信
├── config/
│   └── config.php            # .env読み込み・オートローダ・エラー設定
├── sql/
│   └── schema.sql
└── storage/logs/             # ログ（Webから到達できない場所）
```

`public/` 以外はドキュメントルートの外に置く。設置方法は次章のとおり。

---

## Xserver への設置手順（フェーズ1）

以下、サーバーアカウントを `xsvacct`、対象ドメインを `autobest.jp` として記載する。

### 1) サブドメインの作成

サーバーパネル → **サブドメイン設定** → `autobest.jp` を選択 → 追加。

- サブドメイン: `bot`
- 無料独自SSL: **利用する**（LINE の Webhook URL は https 必須）

これで `/home/xsvacct/autobest.jp/public_html/bot/` が自動生成される。
反映とSSL発行に最大1時間ほどかかる。

同じ手順で `img` サブドメイン（`img.autobest.jp`）も作成しておく（フェーズ2で使用）。

### 2) データベースの作成

サーバーパネル → **MySQL設定**。

1. 「MySQL追加」: DB名 `xsvacct_autobest` / 文字コード **UTF-8（utf8mb4）**
2. 「MySQLユーザ追加」: ユーザ名 `xsvacct_bot` とパスワードを作成
3. 「MySQL一覧」でユーザにアクセス権を付与
4. **同じ画面に表示される「MySQLホスト名」（`mysqlXXX.xserver.jp`）を控える** ← `.env` に入れる値

> `localhost` では接続できない。必ずこのホスト名を使う。

### 3) ソースの配置

ドキュメントルートの**外**に置き、`public/` だけを公開する。
SSH（ポート **10022**）で接続して作業する。

```bash
ssh -p 10022 xsvacct@xsvacct.xsrv.jp

mkdir -p ~/apps && cd ~/apps
git clone <このリポジトリのURL> autobest-line-bot
cd autobest-line-bot

# 自動生成されたドキュメントルートを public/ へのシンボリックリンクに差し替える
rm -rf ~/autobest.jp/public_html/bot
ln -s ~/apps/autobest-line-bot/public ~/autobest.jp/public_html/bot
```

これで `https://bot.autobest.jp/webhook.php` が `public/webhook.php` を指し、
`src/` `config/` `.env` は Web から到達できなくなる。

> **シンボリックリンクが使えない場合のフォールバック**
> `public/` の中身を `~/autobest.jp/public_html/bot/` へコピーし、
> 各 PHP の `require_once dirname(__DIR__) . '/config/config.php'` を
> `require_once '/home/xsvacct/apps/autobest-line-bot/config/config.php'` に書き換える。
> アプリ本体（`src/` `config/`）は `~/apps/` に置いたままにすること。

### 4) パーミッション

```bash
cd ~/apps/autobest-line-bot
find . -type d -exec chmod 755 {} \;
find . -type f -name '*.php' -exec chmod 644 {} \;
chmod 700 storage storage/logs      # ログは本人だけが読める状態に
chmod 600 .env                       # 認証情報
```

### 5) `.env` の作成

```bash
cp .env.example .env
chmod 600 .env
vi .env
```

最低限、次の6項目を埋める。

| キー | 取得元 |
|---|---|
| `DB_HOST` | サーバーパネル「MySQL設定」の MySQLホスト名 |
| `DB_NAME` / `DB_USER` / `DB_PASS` | 手順2で作成した値 |
| `LINE_CHANNEL_SECRET` | LINE Developers → 対象チャネル → チャネル基本設定 |
| `LINE_CHANNEL_ACCESS_TOKEN` | LINE Developers → Messaging API設定 → 長期のチャネルアクセストークン |

`SHOP_TEL` などの店舗情報も bot の返信文に出るので埋めておく。

### 6) スキーマの投入

```bash
mysql -h mysqlXXX.xserver.jp -u xsvacct_bot -p xsvacct_autobest < sql/schema.sql
```

phpMyAdmin（サーバーパネル → phpmyadmin）から `sql/schema.sql` をインポートしてもよい。

### 7) WAF の調整

サーバーパネル → **WAF設定** → `autobest.jp`。

LINE から届く JSON には、SQLインジェクションや XSS のパターンとして
検知される文字列（`<`, `'`, `union`, `select` など）がユーザーの入力として普通に含まれる。
そのままだと正当なリクエストが 403 になる。

- `bot.autobest.jp` に対して **SQL対策** と **XSS対策** を OFF にする
- 他のドメイン・サブドメインの設定は変更しない

WAF を緩める代わりに、アプリ側で以下を必ず担保している（実装済み）。

- Webhook は **署名検証を通らないリクエストを一切処理しない**（`src/Signature.php`）
- SQL は例外なくプリペアドステートメント（`src/Db.php`、文字列連結は使わない）
- 出力エスケープは管理画面実装時に `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')` で統一する

### 8) LINE 側の設定

LINE Developers → 対象チャネル → **Messaging API設定**。

- Webhook URL: `https://bot.autobest.jp/webhook.php`
- Webhookの利用: **オン**
- 応答メッセージ: **オフ**（オンのままだと bot の返信と定型文が二重に届く）
- あいさつメッセージ: **オフ**（`follow` イベントで自前のあいさつを返すため）

---

## 動作確認（フェーズ1）

### 手順1: ヘルスチェック

```bash
cd ~/apps/autobest-line-bot
php bin/healthcheck.php
```

PHPバージョン・拡張・`.env`・DB接続・テーブルの有無・アクセストークンの有効性・
署名検証ロジックを順に確認する。すべて `[OK]` になれば設置は完了。
`[NG]` が出た項目だけを直す。秘密情報は出力されない。

### 手順2: 署名検証が効いているか（外から）

```bash
# 署名なし → 403 が返るのが正しい
curl -i -X POST -H 'Content-Type: application/json' \
     -d '{"events":[]}' https://bot.autobest.jp/webhook.php

# GET → 405
curl -i https://bot.autobest.jp/webhook.php
```

ここで **403 ではなく WAF のブロック画面が返る場合は手順7の WAF 設定を見直す**。
`storage/logs/app-YYYY-MM-DD.log` に「署名検証に失敗しました」が記録されていれば、
リクエストはアプリまで届いている（＝WAFは通過している）と判断できる。

### 手順3: 正しい署名で通ること

```bash
php bin/send_test_webhook.php follow
php bin/send_test_webhook.php message 在庫はありますか
php bin/send_test_webhook.php postback
```

期待する結果:

- `HTTPステータス: 200`
- **応答時間が概ね1秒未満**（LINE のタイムアウトに対する余裕。重い処理の前に200を返しているため）
- `line_users` テーブルにダミーユーザー `U00000000000000000000000000000test` の行ができる
- 同じコマンドを2回実行すると、2回目は `webhookEventId` は毎回変わるので両方処理される
  （重複検出の確認は手順4で行う）

確認クエリ:

```sql
SELECT id, line_user_id, display_name, blocked, followed_at FROM line_users ORDER BY id DESC LIMIT 5;
SELECT event_id, event_type, received_at FROM webhook_events ORDER BY id DESC LIMIT 5;
```

### 手順4: 実機で確認

スマートフォンで LINE公式アカウントを友だち追加する。

| 操作 | 期待する動作 |
|---|---|
| 友だち追加 | あいさつメッセージ＋クイックリプライ3件が届く。`line_users` に行が追加され `blocked=0` |
| ブロック→再追加 | あいさつが再度届き、`blocked` が 1 → 0 に戻る。行は増えない |
| 「査定したい」と送信 | 無料査定の案内が返る |
| 「ｻﾃｲ」と送信 | 同じ案内が返る（半角カタカナを正規化している） |
| 「在庫」と送信 | 販売中の車の案内＋クイックリプライが返る |
| 「営業時間」と送信 | `.env` の店舗情報が返る |
| 意味のない文字列を送信 | 汎用の受付メッセージ＋選択肢が返る |
| スタンプ・画像を送信 | 査定申込への案内が返る |
| ブロック | `line_users.blocked` が 1 になる（行は残る） |

### 手順5: ログの確認

```bash
tail -f ~/apps/autobest-line-bot/storage/logs/app-$(date +%F).log
tail -f ~/apps/autobest-line-bot/storage/logs/php-error.log
```

`php-error.log` に何も出ていないことを確認する。
何か出ている場合は、そこが次に直すべき箇所。

### 手順6: 公開範囲の確認

以下が **すべて 403 または 404** になることを確認する（中身が見えてはいけない）。

```bash
curl -i https://bot.autobest.jp/../.env
curl -i https://bot.autobest.jp/.env
curl -i https://bot.autobest.jp/../src/Db.php
curl -i https://bot.autobest.jp/../sql/schema.sql
curl -i https://bot.autobest.jp/../storage/logs/
```

---

## フェーズ1で実装したこと

### 署名検証（`src/Signature.php`）

Webhook の URL は誰でも叩ける。「本当に LINE から来たか」を判断できるのは署名だけなので、
検証を通る前に DB へ書いたり返信したりしない。

- 検証対象は `php://input` で読んだ**生のボディ**。`json_decode` → `json_encode` し直したものでは
  キー順・スラッシュのエスケープ・空白が変わり、必ず不一致になる。
- 比較は `hash_equals()`。`===` だと一致した文字数で処理時間が変わり、
  応答時間の差から署名を1文字ずつ推測されうる（タイミング攻撃）。
- 失敗理由はレスポンス本文に書かず、ログにだけ残す。

### 即時200返し（`public/webhook.php`）

LINE は Webhook の応答が遅いと失敗とみなして再送してくる。
プロフィール取得や DB 更新を挟むと簡単に超えるため、検証直後に 200 を返して接続を切る。

- php-fpm なら `fastcgi_finish_request()`。
- 共用サーバーで CGI/suPHP のため関数が無い場合に備え、
  出力バッファを捨てて `Content-Length` と `Connection: close` を明示し `flush()` するフォールバックを用意している。
- 接続を切った後は画面に出す相手がいないので、例外は必ず捕まえてログへ送る。
  DB障害時も `Db` は `exit` せず例外を投げ、残りのイベント処理を巻き込まない。

### 重複配信の検出（`webhook_events`）

`webhookEventId` を UNIQUE 制約付きで保存し、`INSERT IGNORE` の影響行数で初回かどうかを判定する。
`SELECT` で存在確認してから `INSERT` すると、再送が同時に届いたときに両方すり抜けるため、
判定は必ず DB の制約に任せる。

### 表記ゆれの吸収（`WebhookHandler::normalize()`）

ユーザーは「査定」「サテイ」「ｻﾃｲ」「さてい」を区別せずに送ってくる。
`mb_convert_kana()` を **2回に分けて**呼び、最終的にひらがなへ寄せている
（1回で `asKVc` を渡すと、`K` で全角化されたばかりの文字を `c` が見ないため取りこぼす）。

---

## 次のフェーズに進む前に決めること

- LINE公式アカウントの料金プラン（一斉配信の想定通数）→ フェーズ5の分割・レート制御の設計に影響
- 販売在庫の想定台数と1台あたりの写真枚数 → フェーズ2の画像保存とページングの設計に影響

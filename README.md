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
| 2 | 管理画面の在庫CRUD＋画像アップロード | **完了** |
| 3 | `FlexBuilder` と在庫カルーセル、リッチメニュー登録スクリプト | **完了** |
| 4 | 査定申込LIFF＋問い合わせ管理 | 未着手 |
| 5 | cronバッチ（キュー処理・一斉配信） | 未着手 |

### 確定している前提

- 在庫データは**管理画面から手入力**する（中古車ポータルからのデータ流用は行わない）
- 既存サイト `autobest.jp` とは分離し、bot 側で独立した DB を持つ
- 写真は**1台あたり4枚程度**（上限10枚）
- LINE公式アカウントは**無料プラン（月200通）**
- 取扱区分は**乗用車・軽／トラック・バス／重機・作業車／その他**の4種
- 拠点は**福岡本社・神奈川支店**の2拠点
- 重機は走行距離ではなく**稼働時間(h)**で表示する
- 車両詳細は**LIFFではなく素のWebページ**（`public/car.php`）。LINE内ブラウザで開く

### 無料プランを前提とした設計方針

無料プランの「月200通」は **プッシュ配信（push / multicast / broadcast）だけ**が対象で、
**応答メッセージ（Reply API）とあいさつメッセージは通数に数えられない**。
そのため、bot の導線はすべて「ユーザーの操作 → 応答」で完結させる。

| 送信方法 | 通数 | 本システムでの使い方 |
|---|---|---|
| reply（応答） | **消費しない** | 在庫カルーセル、FAQ、査定案内など**ほぼ全ての導線** |
| push（個別） | 1通/人 | オーナーへの新規問い合わせ通知のみ。メール通知への切替も可 |
| multicast / broadcast | 人数分 | 一斉配信。友だち100人なら1回で100通＝月2回で上限 |

つまり **一斉配信は無料プランでは実用にならない**。フェーズ5では機能自体は作るが、
送信前に「今月の残通数」と「今回の消費通数」を管理画面で警告する作りにする。

在庫の詳細表示も、追加のメッセージを送らずに**車両詳細ページを開く**方式にする
（カードの「詳しく見る」→ LINE内ブラウザでページ表示）。
写真4枚をまとめて見せられるうえ、通数を一切消費しない。

---

## ディレクトリ構成

```
autobest-line-bot/
├── .env                      # 認証情報（コミットしない。600）
├── public/                   # ← ここだけがドキュメントルート
│   ├── .htaccess
│   ├── webhook.php           # LINE Webhook 受け口
│   └── admin/                # 管理画面
│       ├── .htaccess         # Basic認証・IP制限の設定場所
│       ├── _layout.php       # 共通レイアウト（直接アクセス不可）
│       ├── login.php / logout.php
│       ├── cars.php          # 在庫一覧・状態切替・削除
│       └── car_edit.php      # 在庫の登録/編集・写真の追加/並替/削除
├── src/
│   ├── Db.php                # PDO 接続
│   ├── Logger.php            # ファイルロガー
│   ├── Signature.php         # 署名検証 / LIFFトークン検証
│   ├── LineClient.php        # reply / push / multicast / richmenu
│   ├── WebhookHandler.php    # イベント振り分け
│   ├── Auth.php              # 管理画面の認証・セッション・CSRF
│   ├── CarRepository.php     # 在庫のデータアクセス（SQLはここに集約）
│   ├── CarValidator.php      # 在庫フォームの入力検証
│   └── ImageUploader.php     # 画像の検証・縮小・保存
├── bin/
│   ├── healthcheck.php       # 設置後の動作確認
│   ├── create_admin.php      # 管理ユーザーの作成
│   └── send_test_webhook.php # 署名付きダミーWebhookの送信
├── config/
│   ├── config.php            # .env読み込み・オートローダ・エラー設定
│   └── helpers.php           # h() などテンプレート用ヘルパ
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

### 8-a) 既存アカウントに切り替える場合（bin/set_webhook.php）

すでにあるLINE公式アカウント（例：「抽選」）をこのbotに繋ぎ替えるときは、
サーバー上で次を実行する。LINE Developers の画面で行う操作をAPIから実行する。

```bash
cd ~/apps/autobest-line-bot

# 1) まず現状を確認する（差し替えはしない）
php bin/set_webhook.php

# 2) 問題なければ差し替える
php bin/set_webhook.php --apply
```

`--apply` は実行前に **Bot名・ベーシックID・現在のWebhook URL** を表示し、
ベーシックIDの入力を求める。意図しないアカウントを書き換えないための確認なので、
表示された名前が目的のアカウントであることを必ず確かめる。

差し替え後は LINE 側から実際に疎通させ、応答コードを表示する。
403 ならWAF、404ならパス違い、500ならアプリのエラーと切り分けられる。

```bash
php bin/set_webhook.php --test   # 差し替えずに疎通確認だけ
```

> **既存アカウントを流用するときの注意**
> そのアカウントの友だちは、別の目的（抽選など）で登録した人たちである。
> 切り替えた瞬間から、その人たちのメッセージにこのbotが応答し始める。
> 用途が変わることを事前に告知するか、新規アカウントを作るかを決めてから実行する。
> チャネルシークレットとアクセストークンは**必ず同一チャネルのもの**を `.env` に入れる
> （片方だけ差し替えると署名検証が全て失敗し、Webhookが403になる）。

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

---

## フェーズ2：管理画面（在庫CRUD＋画像アップロード）

### 追加の設置手順

#### 1) 画像置き場の作成

```bash
# img.autobest.jp のドキュメントルート配下に cars/ を作る
mkdir -p ~/autobest.jp/public_html/img/cars
chmod 755 ~/autobest.jp/public_html/img/cars
```

`.env` の `IMG_UPLOAD_DIR` にこの**実パス**を、`IMG_BASE_URL` に `https://img.autobest.jp` を設定する。
初回アップロード時、`cars/` の直上に PHP の実行を禁じる `.htaccess` が自動生成される。

#### 2) 管理ユーザーの作成

```bash
cd ~/apps/autobest-line-bot
php bin/create_admin.php owner "店長"
```

パスワードは対話で入力する（引数で渡すと `~/.bash_history` と `ps` の出力に平文が残るため）。
12文字以上が必要。同じIDで再実行するとパスワードの変更になる。

#### 3) Basic認証の追加（強く推奨）

```bash
cd ~/apps/autobest-line-bot
htpasswd -c .htpasswd owner     # ドキュメントルート外に置く
chmod 600 .htpasswd
```

`public/admin/.htaccess` の `AuthType` 以下4行のコメントを外し、
`AuthUserFile` のパスを実際のフルパスに書き換える。
店舗の固定IPがあれば、同ファイルの `Require ip` も併せて有効にする。

#### 4) PHPのアップロード上限

サーバーパネル →「php.ini設定」で、スマホの写真1枚が通るサイズにする。

| 項目 | 推奨値 | 理由 |
|---|---|---|
| `upload_max_filesize` | 12M | 1枚あたりの上限。アプリ側でも10MBで弾いている |
| `post_max_size` | 60M | 4枚同時アップロードでも足りる値 |
| `max_file_uploads` | 20 | 同時に選べる枚数 |

### 動作確認（フェーズ2）

| 手順 | 期待する動作 |
|---|---|
| `https://bot.autobest.jp/admin/cars.php` を開く | Basic認証 → ログイン画面へ転送される |
| 誤ったパスワードで6回ログイン | 6回目に「試行回数が上限に達しました」。15分間は正しいパスワードでも入れない |
| 正しいIDとパスワードでログイン | 在庫一覧が表示される |
| 「＋ 新規登録」→ メーカーと車種名だけ入力して保存 | 下書きとして登録される |
| 公開状態を「公開中」にして保存 | 支払総額と年式が未入力ならエラーになる（LINEに不完全な情報を出さないため） |
| 走行距離に `48,000` と入力 | 「カンマ不要」のエラーになる |
| 車検満了日に `2026-02-31` を入力 | 存在しない日付としてエラーになる |
| 写真を4枚アップロード | 一覧にサムネイルと枚数が出る。長辺1600pxを超える写真は自動縮小される |
| 写真の並び順を変更して保存 | 0から振り直され、先頭が代表画像になる |
| 拡張子だけ `.jpg` にしたPHPファイルをアップロード | 「対応していない画像形式です」で拒否される |
| 車両を削除 | 確認ダイアログの後、DBの行と画像ファイルの両方が消える |
| 一覧の検索欄に `%` を入力 | 全件ヒットしない（LIKEのワイルドカードとして解釈されない） |

確認クエリ:

```sql
SELECT id, maker, model_name, status, sort_order FROM cars ORDER BY sort_order;
SELECT car_id, position, image_url FROM car_images ORDER BY car_id, position;
```

### フェーズ2で実装したこと

**入力は生のまま保存し、出力時にエスケープする（`config/helpers.php`）**
DBには利用者が入力したそのままの文字列を入れ、表示する瞬間に `h()`（= `htmlspecialchars`）を通す。
保存時にエスケープすると、二重エスケープや「LINEに送るときは生が欲しい」場面で必ず破綻する。
`h()` という短い名前にしているのは、書き忘れを減らすため。

**ステータス変更・削除は必ず POST + CSRFトークン**
GET で更新できると、`<img src="...cars.php?action=delete&car_id=1">` を仕込んだページを
管理者に踏ませるだけで在庫が消える。トークンの比較は `hash_equals()`。

**セッション固定化対策（`src/Auth.php`）**
ログイン成功時に `session_regenerate_id(true)` を呼ぶ。
これが無いと、攻撃者が用意したセッションIDのまま権限だけが昇格する。
クッキーは `httponly` + `samesite=Lax`、https のときだけ `secure` を付ける。

**アップロードは拡張子ではなく中身で判定する（`src/ImageUploader.php`）**
`$_FILES['type']` はブラウザの自己申告なので信用しない。`finfo` で MIME を見たうえで
`getimagesize()` が通ることまで確認する。ファイル名は元の名前を捨てて乱数で作り直すため、
二重拡張子やパス区切りを含む名前の問題そのものが発生しない。
保存先には PHP の実行を禁じる `.htaccess` を自動生成する。

**画像は長辺1600pxに縮小する**
スマホの写真をそのまま置くとLINEでの読み込みが遅い。GDが無い環境では縮小せず原本を保存する
（縮小は品質向上であって、機能の前提ではない）。

**画像操作は必ず car_id と対で確認する（`CarRepository::findImage()`）**
画像IDだけで削除できると、他の車両の写真を消すリクエストを作れてしまう。


---

## フェーズ3：在庫カルーセルとリッチメニュー

### スキーマの更新

フェーズ3で `cars` に列が増え、テーブルが4つ増えた。**既に運用しているDBがある場合**は移行を流す。

```bash
git pull
php bin/migrate.php            # 未適用の移行を一覧表示（変更しない）
php bin/migrate.php --apply    # 適用する
```

`sql/schema.sql` / `sql/schema.sqlite.sql` から**新しく作ったDB**は最初から最新の形なので、
移行は流さず「適用済み」として記録するだけでよい。

```bash
php bin/migrate.php --baseline
```

SQLite の `bin/init_sqlite.php` はこれを自動で行うため、追加の操作は不要。

| 追加した列（cars） | 用途 |
|---|---|
| `stock_number` | 在庫番号。詳細ページに表示（UNIQUE） |
| `category` | 乗用車・軽／トラック・バス／重機・作業車／その他 |
| `location` | 福岡本社／神奈川支店 |
| `engine_hours` | 稼働時間(h)。重機で走行距離の代わりに使う |
| `price_negotiable` | 価格応談。未入力とは別の状態として持つ |
| `published_at` | 「新着」判定と新着順。`created_at` だと下書きで寝かせた車両が公開直後から古く見える |

`status` に `pending`（承認待ち）と `negotiating`（商談中）を追加した。

| 追加したテーブル | 用途 |
|---|---|
| `favorites` | お気に入り。`UNIQUE(line_user_id, car_id)` で二重登録を防ぐ |
| `reservations` | 来店・商談予約。第1〜第3希望と確定日時を分けて持つ |
| `notification_conditions` | 新着通知の条件。`enabled=0` で即時に配信停止 |
| `notification_log` | 同一車両の重複通知を `UNIQUE` で防ぐ |

### リッチメニューの登録

```bash
php bin/setup_richmenu.php            # 定義と領域の重なりを確認（LINEには触らない）
php bin/setup_richmenu.php --apply    # 作成・画像アップロード・タブ設定・既定化
php bin/setup_richmenu.php --list     # 現在の登録状況
php bin/setup_richmenu.php --clean    # このスクリプトが作ったものを削除
```

**画像を先に用意する。** `bin/richmenu/` に `find.png` / `sell.png` / `support.png` を
**2500×1686**（PNG）で置く。無いと `--apply` は中断する。

`--apply` の前に、全領域が重ならず画像からはみ出さないことを自動で確認する。
受入条件の「押せない見せかけのボタンがない」をここで担保している。

タブの切替は**リッチメニューエイリアス**（`autobest-find` / `autobest-sell` / `autobest-support`）を使う。
メニューを作り直してもエイリアスIDは変わらないので、各ボタンの定義を書き換えずに済む。

### 動作確認（フェーズ3）

| 手順 | 期待する動作 |
|---|---|
| メニューの「販売在庫を探す」 | 公開中の在庫がカルーセルで返る（10台＋「もっと見る」） |
| 「もっと見る」を押す | 2ページ目が返る。絞り込み条件は引き継がれる |
| 最終ページで「もっと見る」 | 「これで最後です」と返る |
| タブを押す | メニューが切り替わる。**トークにメッセージは残らない** |
| 「重機を見たい」と送信 | 重機だけが返る（「在庫」という語がなくても拾う） |
| 「ﾕﾝﾎﾞ」「ダンプ」と送信 | 半角・全角カタカナのどちらでも同じ結果 |
| 「横浜で買いたい」 | 神奈川支店の在庫が返る |
| カードの「詳細を見る」 | LINE内ブラウザで車両詳細ページが開く |
| カードの「お気に入り」 | 追加される。2回押すと「すでに」と返る |
| 下書き・承認待ち・商談中・成約済みの車両ID | カルーセルに出ず、`car.php?id=◯` でも「掲載を終了しました」 |
| 写真のない新着車両 | カード本文の先頭に「新着」バッジが出る |
| 重機のカード | 「稼働 1,240h」「価格応談」で表示される |

確認クエリ:

```sql
SELECT id, stock_number, category, location, status, published_at FROM cars ORDER BY sort_order;
SELECT u.display_name, c.model_name FROM favorites f
  JOIN line_users u ON u.id = f.line_user_id JOIN cars c ON c.id = f.car_id;
```

### フェーズ3で実装したこと

**公開中以外は経路を問わず出さない（`CarRepository::published` / `findPublished`）**
カルーセルも詳細ページも `status = 'published'` を SQL の条件に固定している。
下書き・承認待ち・商談中・成約済みは、車両IDを直接指定しても表示されない。

**次ページの有無は1件多く取って判断する**
`COUNT(*)` を別に投げると往復が増える。`LIMIT 11` で取って11件目の有無で判定する。

**「新着」は published_at で判定する**
`created_at` を使うと、下書きのまま寝かせた車両が公開した瞬間から古い扱いになる。
また公開↔下書きを往復しても `published_at` は最初の1回しか入らないので、
同じ車両が繰り返し新着通知の対象にならない。

**写真がない新着車両**
新着バッジは hero 画像に重ねているため、写真がないと出ない。
その場合はカード本文の先頭にバッジを出して、新着だと分かるようにしている。

**タブ切替は返信しない**
`richmenuswitch` も postback として飛んでくる。ここで何か返すと、
タブを押すたびにトークが埋まる。`tab=` を見て黙って終える。

**キーワードは必ずひらがなか漢字で書く**
`normalize()` が入力をひらがなへ寄せるため、キーワード側にカタカナを書くと
永久に一致しない条件になる。実際に「トラック」「ユンボ」等が死んだ条件になっていたので修正した。

---

## 次のフェーズに進む前に決めること

フェーズ3（在庫カルーセル）の詳細表示の方式を決める必要がある。README冒頭の方針では
「カードの『詳しく見る』→ 車両詳細ページ」を想定しているが、実装前に確認したい。

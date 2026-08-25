# 引き継ぎ資料（実装担当者向け）

この文書だけ読めば作業に入れるように書いてある。
まず **「絶対に外せない制約」** と **「踏んだ落とし穴」** に目を通してほしい。
どちらも、知らずに書くと動くコードを書いたつもりで壊れる類のもの。

- リポジトリ: `rmatsu911/autobest-line-bot`（Private）
- 作業ブランチ: `claude/new-session-v1z7i9`
- 直近のコミット: `bc987d0`

---

## 1. これは何か

autobest.jp（中古車の買取・販売）のLINE公式アカウント用bot。
LINEのトークから在庫を探し、査定を申し込み、来店を予約できる。
店側は管理画面で在庫と問い合わせを扱う。

**動く場所はエックスサーバーの共用レンタルサーバー。** ここが設計のほぼ全てを決めている。

---

## 2. 絶対に外せない制約

| 制約 | 理由と結果 |
|---|---|
| **Composerを使わない** | 共用サーバーにcomposerが無い前提。外部ライブラリは一切使わず、HTTPは素の`curl`、オートローダは`config/config.php`に自前で書いてある。**`composer require` は選択肢に無い** |
| **常駐プロセスを立てられない** | ワーカーやデーモンは動かせない。非同期処理は「DBのキュー表 + cron」で作る |
| **PHPの実行時間に上限がある** | 一斉配信のような長い処理は、1回のcronで全部やらず必ず分割する |
| **DBホストは `localhost` ではない** | エックスサーバーのMySQLは `mysqlXXX.xserver.jp` 形式。`bin/healthcheck.php` が `localhost` を検出して警告する |
| **WAFが誤検知する** | 管理画面のPOSTがWAFに弾かれることがある。アプリ側で防御を完結させる（署名検証・プリペアドステートメント・出力エスケープ） |
| **LINEは無料プラン（月200通）** | **通数に入るのは push / multicast / broadcast だけ。reply は無料。** だから機能はすべて「お客様の操作に返す」形で作ってある。pushを足す提案をする前に、必ず通数の見積もりを出すこと |
| **MySQLとSQLiteの両対応** | 本番はMySQLの想定だが、現在の設置先はSQLiteで動いている。**両方で動くSQLしか書けない**（後述） |

### いま動いている環境

お客様の設置先: `https://xxxtrw77777.xsrv.jp/auto-beast/`
サブディレクトリ設置なので、**リポジトリ全体が `public_html` の下にある**。
つまり `storage/` も `src/` もURLで叩ける可能性がある。
`.htaccess` で塞いであるが、**新しく機密を置くディレクトリを作るときは必ず `.htaccess` も置くこと。**

---

## 3. いま出来ていること

| フェーズ | 内容 | 状態 |
|---|---|---|
| 1 | Webhook受信・署名検証・キーワード応答 | 完了 |
| 2 | 管理画面（在庫CRUD・画像アップロード） | 完了 |
| 3 | 在庫カルーセル（Flex）・リッチメニュー定義 | 完了 |
| 4 | 査定申込／来店予約フォーム・問い合わせ管理・操作履歴 | 完了 |
| 5 | 新着のお知らせ（reply方式） | 完了 |
| 5 | **cronバッチ（キュー処理・一斉配信）** | **未着手 ← 次はここ** |

### ディレクトリの読み方

```
src/                 アプリ本体（namespace App、PSR-4風の自前オートローダ）
  Db.php             PDOの薄いラッパ。MySQL/SQLiteの差はここに寄せる
  Signature.php      Webhook署名の検証／LIFFトークンの検証
  LineClient.php     LINE APIをcurlで叩く。reply/push/multicast/broadcast
  WebhookHandler.php イベントの振り分け。botの応答はほぼ全部ここ
  FlexBuilder.php    Flexメッセージの組み立て
  RichMenuDefinition.php リッチメニューの座標とアクション定義
  Auth.php           管理画面のログイン・CSRF・ロックアウト
  PublicForm.php     公開フォームのCSRF・いたずら送信対策・連投制限
  ImageUploader.php  画像の検証と保存（公開用・非公開用の両方）
  AuditLog.php       管理操作の証跡
  *Repository.php    DBアクセス
  *Validator.php     入力検証
public/              ドキュメントルート（ここだけが外から見える想定）
  webhook.php        LINEからの受け口
  car.php            車両詳細（LINE内ブラウザ）
  assessment.php     査定申込フォーム
  reserve.php        来店・商談予約フォーム
  admin/             管理画面
bin/                 CLIスクリプト（設置・確認・登録）
sql/                 schema.sql（MySQL）/ schema.sqlite.sql / migrations/
tests/               テスト一式（後述）
docs/HANDOVER.md     この文書
```

---

## 4. 開発環境の作り方

```bash
git clone <repo> && cd autobest-line-bot
git checkout claude/new-session-v1z7i9

cp .env.example .env
# 最低限これだけ埋めればテストは走る
#   DB_DRIVER=sqlite
#   DB_PATH=storage/autobest.sqlite
#   LINE_CHANNEL_SECRET=abc123        ← テストがこの値を前提にしている
#   LINE_CHANNEL_ACCESS_TOKEN=dummy-token
#   BOT_BASE_URL=http://127.0.0.1:8099

php bin/init_sqlite.php     # スキーマ投入 + 移行の基準点を記録
php bin/healthcheck.php     # 設置確認（LINE APIの項目だけNGになるのは正常）
```

`bin/healthcheck.php` の「4. LINE Messaging API」は、ネットワークが無い環境や
ダミートークンでは必ずNGになる。それ以外がOKなら問題ない。

---

## 5. テストの走らせ方（重要）

```bash
php tests/run.php
```

これだけで **361件** が走る。DBの作り直し、種データ投入、
ビルトインサーバの起動まで全部やる。**変更したら必ずこれを通してから出すこと。**

MySQLでも同じものを走らせられる。

```bash
mysql -e "CREATE DATABASE ab_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql ab_test < sql/schema.sql
# .env を DB_DRIVER=mysql に切り替えてから
php bin/migrate.php --baseline
TEST_DSN="mysql:host=127.0.0.1;dbname=ab_test;charset=utf8mb4" \
  TEST_USER=xxx TEST_PASS=yyy php tests/run.php
```

**MySQLでも必ず走らせること。** SQLiteだけ通ってMySQLで落ちる不具合を実際に出している（後述）。

### テストの中身

| ファイル | 見ているもの |
|---|---|
| `webhook_unit_test.php` | 署名検証、重複配信の無視、キーワード応答、DB障害時の挙動（DBはスタブ） |
| `car_validator_test.php` | 在庫フォームの入力検証、選択肢の偽装 |
| `flex_richmenu_test.php` | FlexのJSON妥当性、リッチメニューの座標とラベル長 |
| `webhook_inventory_test.php` | 在庫カルーセル、ページング、お気に入り、未公開在庫が漏れないこと |
| `notify_test.php` | 新着のお知らせ、条件の保存、**pushを呼んでいないこと** |
| `form_admin_test.php` | 実際にHTTPを通す結合テスト。フォーム送信、XSS、CSRF、写真の配信制限、削除 |

### テストを書き足すときの注意

- **件数を数えるテストは、DBを作り直してから走らせる。**
  在庫の台数や公開状態を前提に数えているので、前のテストが車両を「売却済み」にしたまま
  次を走らせると、product側は正しいのに落ちる。`tests/run.php` の `$suites` の
  第3要素を `true` にすると直前に作り直す。
- **`$x ?? 'x' === null` は書かない。** `??` はnullで発火するので必ずfalseになる。
  null判定は `$x === null` か `array_key_exists()` で。
- **公開フォームは送信が通るとトークンを作り直す**（二重送信対策）。
  連続で送るテストは毎回フォームを取り直すこと。
- 失敗が出たら、**まずテスト側のバグを疑う。** 上の3つは実際に嘘の失敗を出した原因。

---

## 6. 次にやること（フェーズ5の残り）

### 6-1. キュー処理のcron（`bin/queue_worker.php`）

**なぜ要るか**
Webhookは3秒以内に200を返さないとLINEが再送してくる。
時間のかかる処理（画像の取得、複数人への送信、外部への通知）を
Webhookの中でやると間に合わない。
だから「やることをDBに積んで、cronが後で片付ける」形にする。

**表はもうある**（`message_queue`）。

```
id / type / payload(JSON) / status(pending|processing|done|failed)
attempts / last_error / retry_key / scheduled_at / created_at / updated_at
```

**作るもの**

```
bin/queue_worker.php     … pendingを拾って処理する。cronで1分ごとに叩く
src/MessageQueue.php     … enqueue() と、ワーカーが使う取得・完了・失敗の記録
```

**満たすこと**

1. **多重起動を防ぐ。** cronは前回が終わる前でも次を起動する。
   ロックファイル（`flock`）で1本に絞る。`.gitignore` に `*.lock` は既にある。
2. **1回の実行に上限を設ける。** 件数と経過時間の両方で切る
   （例：50件または50秒で打ち切り、残りは次のcronへ）。PHPの実行時間制限に当てるため。
3. **`processing` のまま放置された行を拾い直す。** 途中で落ちるとその状態で残る。
   `updated_at` が一定時間より古い `processing` は `pending` に戻す。
4. **リトライは回数上限を決めて指数的に間隔を空ける。**
   `LineResponse::retryable()` が既にある（429と5xxだけリトライ、4xxはしない）。
   上限に達したら `failed` にして `last_error` を残す。**黙って消さない。**
5. **`retry_key` を使う。** LINEのAPIは `X-Line-Retry-Key` で二重送信を防げる。
   `LineClient::push()` などが引数で受け取るようになっている。
6. **通数を消費する処理は、実行前に見積もりを出す。** 下の 6-2 と同じ理由。

**cronの登録例**（エックスサーバーのサーバーパネル）

```
* * * * * cd /home/xxx/apps/autobest-line-bot && php bin/queue_worker.php >> storage/logs/cron.log 2>&1
```

### 6-2. 一斉配信（`bin/broadcast.php`）

**先に読むこと**
無料プランは**月200通**。`broadcast` は友だち全員に送るので、
友だち150人なら1回で150通、つまり月に1回しか送れない。
**この機能は「作ってあるが、使うと枠を使い切る」ものになる。**
だから次を必ず入れること。

1. **送信前に残り通数を確認する。**
   `GET https://api.line.me/v2/bot/message/quota` と
   `GET https://api.line.me/v2/bot/message/quota/consumption` で取れる。
   `LineClient::httpGet()` がそのまま使える。
2. **残りが足りなければ送らずに止める。** 中途半端に送るのが一番まずい。
3. **管理画面から送る場合は、送信前に「この配信で◯通消費します。残り◯通です」と出して確認させる。**
4. `multicast` は1リクエスト500件まで。分割とレート制御は呼び出し側の責務
   （`LineClient::multicast()` のコメントにもそう書いてある）。
5. 配信の実行は `message_queue` に積んでワーカーに任せる。
   管理画面のリクエストの中で送り切ろうとしない（時間切れになる）。

### 6-3. 「準備中」のまま残っている導線

`src/WebhookHandler.php` の postback 分岐に、まだ準備中と返すだけのものがある。

```
contact, consult, my_reservations, purchases, why_us, flow, documents
```

このうち **`my_reservations`（予約確認）と `purchases`（買取実績）は
既にDBもデータもあるので、すぐ実装できる。**

- `purchases` … `purchase_records` 表と `PurchaseRepository` がある。
  `published = 1` のものをカルーセルかテキストで返す。
- `my_reservations` … `reservations` 表がある。`line_user_id` から自分の予約を引いて、
  日時と状態（仮予約／確定）を返す。**他人の予約が見えないよう、必ず `line_user_id` で絞る。**

`why_us` / `flow` / `documents` は文言だけなので、原稿をもらってから固定文言で返せばよい。

---

## 7. コードの約束事

- **コメントは日本語で、「なぜそうしたか」を書く。** 「何をしているか」はコードを読めば分かる。
  難しい箇所・直感に反する箇所には必ず理由を書く。既存のコードがその調子なので合わせること。
- **出力は必ず `h()` を通す。** `config/helpers.php` のグローバル関数。
  `htmlspecialchars($x, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` の短縮。
- **SQLは必ずプリペアドステートメント。** 値を文字列連結でSQLに入れない。
  例外は `Db::paged()` の `LIMIT`/`OFFSET`（後述の理由で `(int)` キャストして埋め込んでいる）。
- **並び順やカラム名にユーザー入力を使わない。** 許可した文字列にしか分岐しない
  （`CarRepository::published()` の `$orderSql` が手本）。
- **管理画面の更新系POSTには必ず `Auth::requireValidCsrf()`。**
- **公開フォームの送信には必ず `PublicForm::verify()`。**
- **例外を握りつぶさない。** ログに残して、利用者には状況が分かる日本語を返す。
- 新しい定数・選択肢は、対応する `*Validator` か `*Repository` の定数に足して
  そこだけを正とする（画面とDBで選択肢がずれるのを防ぐため）。

---

## 8. 踏んだ落とし穴（同じ穴を掘らないこと）

ここは実際に動かして見つけたもの。**読まずに書くと必ず踏む。**

### DB関連

**`Db::paged()` の `LIMIT ?` は使えない。**
`ATTR_EMULATE_PREPARES => false` なので `LIMIT ?` は文字列としてバインドされ、
MySQLが構文エラーを出す。だから `Db::paged()` が `(int)` キャストして埋め込んでいる。
**新しくページングを書くときは `Db::paged()` を使うこと。**

**SQLiteの `CURRENT_TIMESTAMP` はUTC。**
アプリはJSTで書くので、混ぜると同じ表の中で9時間ずれる。
SQLite側の既定値とトリガは全て `datetime('now', '+9 hours')` にしてある。
**新しい表を足すときも必ずこれに揃えること。**

**MySQLとSQLiteで書き分けが要るもの**（`Db::isSqlite()` で分岐する）

| やること | MySQL | SQLite |
|---|---|---|
| 重複を無視して挿入 | `INSERT IGNORE` | `INSERT OR IGNORE` |
| upsert | `ON DUPLICATE KEY UPDATE` | `ON CONFLICT(x) DO UPDATE ... excluded.x` |
| 現在時刻 | `NOW()` | `datetime('now','+9 hours')` … `Db::nowSql()` |
| 相対時刻 | `DATE_SUB(NOW(), INTERVAL 1 HOUR)` | `datetime('now','+9 hours','-1 hour')` |
| LIKEのエスケープ | 文字列中の `\` は1文字 | 文字列中の `\` は2文字 |

最後の行が厄介で、`ESCAPE '\\'` と書くと両者で意味が変わる。
**`ESCAPE '!'` のようにバックスラッシュ以外を使うこと**（`NotificationRepository` が手本）。

**空文字を DATETIME 列に入れない。**
MySQLは 1292 で拒否、SQLiteは黙って空文字を保存する。どちらも壊れる。
未入力は必ず `null` にすること。
`ReservationValidator` で `$v + $parsed` と書いて生の空文字が残り、これを踏んだ。
**PHPの `+` は左側の値を優先する。** 整形済みの値を優先したいなら `$parsed + $v`。

**SQLiteは列のNULL可・CHECK制約を後から変えられない。**
表を作り直して詰め替える（`sql/migrations/002_phase4_sqlite.sql` が手本）。
**表をDROPするとトリガも消えるので、張り直しを忘れないこと。**

**新規インストールに移行を流さない。**
`sql/schema.sql` は常に最新の形。新しいDBには `php bin/migrate.php --baseline`
（実行せず適用済みとして記録）。`--apply` を流すと「列が既にある」で落ちる。
`bin/init_sqlite.php` は自動で `--baseline` する。

### PHP関連

**`trim()` の第2引数はバイト単位で削る。**
全角スペース（U+3000 = `E3 80 80`）を削り文字に渡すと、
「トヨタ」の先頭バイト `E3` まで削れて文字化けする。
文字単位で扱うなら `preg_replace('/\A[\s\x{3000}]+|[\s\x{3000}]+\z/u', '', $s)`。

**壊れたUTF-8が1バイトでも混ざると `json_encode()` は `false` を返す。**
上の文字化けと組み合わさって、査定の申込内容がまるごと空で保存されていた。
入力の時点で `mb_check_encoding()` で直し、`json_encode` にも
`JSON_INVALID_UTF8_SUBSTITUTE` と失敗時の退避を入れてある。

**`mb_convert_kana()` はモードを重ねても連鎖しない。**
`'asKVc'` と一度に書いても、半角カナ→全角カナ→ひらがな とは進まない。
`'asKV'` と `'c'` の2回に分ける（`WebhookHandler::normalize()`）。

**`normalize()` は入力をひらがなに寄せる。**
だからキーワード側にカタカナを書くと**永久に一致しない**。
「トラック」「ユンボ」などが死んだ条件になっていたのを修正済み。
**キーワードを足すときは、ひらがなか漢字で書くこと。**

**セッションはHTMLを1文字でも出す前に開始する。**
出力後に `session_start()` してもヘッダは送り終えているので `Set-Cookie` が飛ばない。
結果、**全ての送信がCSRFで弾かれる**。実際にこれで全フォームが死んでいた。
`public/assessment.php` と `public/reserve.php` の冒頭に `PublicForm::startSession()` がある。

**`isset()` では「空文字」と「未送信」を区別できない。**
`parse_str()` は空文字を作るので `isset()` は両方 true。
`action=notify_set&location=`（＝指定を外す）を扱うには `array_key_exists()` を使う。

**SQLiteのファイルを消したら、そのプロセスのPDOハンドルは捨てる。**
消えた方のinodeを掴んだままになり、書き込みが `no such table` で落ちる。
`tests/run.php` はDBを作り直すたびに別プロセスへ逃がしている。

### Webhook関連

**`Config::fail()` と `Db` の失敗は `exit` や `header()` をしない。**
Webhookは既に200を返し終えているので、`header()` は「headers already sent」になり、
`exit` は**残りのイベントの処理を巻き込んで止める**。必ず例外を投げる。

**リッチメニューのタブ切替（`richmenuswitch`）もpostbackとして飛んでくる。**
返信すると押すたびにトークが埋まるので、`isset($params['tab'])` で早期returnしている。

**LINEの検証ボタンはダミーの `replyToken`（ゼロ埋め）を送ってくる。** 返信してはいけない。

---

## 9. やってはいけないこと

- **`composer require` を提案しない。** 動かせない。
- **常駐プロセス・supervisor・systemd を前提にしない。** cronだけ。
- **`push` / `multicast` / `broadcast` を安易に足さない。** 月200通。
  足すなら通数の見積もりを添えて相談すること。
- **`storage/` の中身を公開領域から読めるようにしない。**
  査定写真は個人情報。配信口は `public/admin/inquiry_image.php` だけ。
- **`audit_logs` に削除の口を作らない。** 消せる証跡は証跡ではない。
- **`.env` をコミットしない。** `.gitignore` 済みだが、新しい設定ファイルを作るときも同様に。
- **管理画面から管理ユーザーを作れるようにしない。**
  そこを乗っ取られると攻撃者が正規の入口を作れる。作成は `bin/create_admin.php`（CLI）だけ。
- **未公開の在庫を外に出さない。** `CarRepository::published()` / `findPublished()` が
  `status = 'published'` を強制している。ここを迂回するクエリを書かないこと。

---

## 10. まだ決まっていないこと（勝手に決めないこと）

1. **在庫の共有API・お客様向けWebサイト**
   要件書10章には「在庫DBを共有し、Webサイトとbotの両方から参照する」とあるが、
   現状は**別リポジトリ・別DB・管理画面から手入力**で作っている（当初の合意どおり）。
   共有するなら、どちらを正とするかを先に決める必要がある。**確認せずに着手しないこと。**

2. **LINEログインチャネル（LIFF連携）**
   まだ発行されていない。査定・予約フォームは素のWebページとして動いているので急がない。
   `.env` に `LINE_LOGIN_CHANNEL_ID` を入れると `src/LiffBridge.php` が自動的に効き、
   申込に `line_users` が紐づくようになる。**配線済みなので、値を入れる以外の作業は不要。**

3. **`why_us` / `flow` / `documents` の文言** … 原稿待ち。

---

## 11. お客様側でまだ終わっていない作業

実装とは別に、設置先で対応が必要なものが残っている。
作業中に気づいたら念のため確認してほしい。

- [ ] 管理画面のパスワードが `admin123`（8文字）のまま。`php bin/create_admin.php` で12文字以上に
- [ ] `public/admin/.htaccess` のBasic認証がコメントアウトのまま
- [ ] 外から `.env` / `src/` / `storage/` が読めないか未確認（`curl -i` で403か404になること）
- [ ] リッチメニューの画像（`bin/richmenu/{find,sell,support}.png`、2500×1686）が未提供のため未登録
      ※フェーズ5で「新着入庫」ボタンの遷移先を `action=new_arrivals` に変えたので、
        登録済みの場合は `php bin/setup_richmenu.php --apply` で再登録が要る

---

## 12. 参考

- 詳しい経緯と各フェーズの設計判断は `README.md` に書いてある。
  特に「フェーズNで実装したこと」の節に、なぜその形にしたかが残っている。
- LINE APIの仕様で迷ったら、`src/LineClient.php` のコメントに
  「1回5件まで」「multicastは500件まで」などの上限が書いてある。

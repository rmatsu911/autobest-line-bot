-- =============================================================================
-- autobest.jp LINE bot スキーマ
--
-- 文字コードは utf8mb4（絵文字を含むユーザー入力・LINE表示名を欠損なく保持するため）。
-- 照合順序は utf8mb4_unicode_ci（Xserver の MySQL/MariaDB の既定 general_ci でも動くが、
-- 「ー」「－」など日本語の記号比較が安定する unicode_ci を明示する）。
--
-- 実行方法（Xserver）:
--   phpMyAdmin から本ファイルをインポートするか、SSH で
--   mysql -h mysqlXXX.xserver.jp -u <DBユーザー> -p <DB名> < sql/schema.sql
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- line_users : 友だち追加したLINEユーザー
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS line_users (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  line_user_id  VARCHAR(64)     NOT NULL COMMENT 'LINEのuserId（Uから始まる33文字）',
  display_name  VARCHAR(128)        NULL COMMENT 'プロフィールAPIで取得した表示名',
  followed_at   DATETIME            NULL COMMENT '初回フォロー日時',
  blocked       TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1=ブロック中（unfollow受信）',
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- 同じユーザーの重複登録を DB 側で防ぐ。アプリは INSERT ... ON DUPLICATE KEY UPDATE で
  -- upsert するので、この UNIQUE が無いと follow の再送で行が増える。
  UNIQUE KEY uq_line_users_line_user_id (line_user_id),
  KEY idx_line_users_blocked (blocked)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- cars : 販売在庫
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cars (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  stock_number     VARCHAR(32)         NULL COMMENT '店舗管理用の在庫番号',
  category         ENUM('passenger','truck','machinery','other') NOT NULL DEFAULT 'passenger'
                   COMMENT '乗用車・軽/トラック・バス/重機・作業車/その他',
  location         ENUM('fukuoka','kanagawa') NOT NULL DEFAULT 'fukuoka' COMMENT '福岡本社/神奈川支店',
  maker            VARCHAR(64)     NOT NULL COMMENT 'メーカー',
  model_name       VARCHAR(128)    NOT NULL COMMENT '車種名',
  grade            VARCHAR(128)        NULL COMMENT 'グレード',
  model_year       SMALLINT UNSIGNED   NULL COMMENT '年式（西暦）',
  mileage_km       INT UNSIGNED        NULL COMMENT '走行距離(km)',
  engine_hours     INT UNSIGNED        NULL COMMENT '稼働時間(h)。重機で使用',
  total_price      INT UNSIGNED        NULL COMMENT '支払総額(円)',
  body_price       INT UNSIGNED        NULL COMMENT '車両本体価格(円)',
  -- 「応談」は未入力とは別の状態。NULL だけでは区別できないので独立させる。
  price_negotiable TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1=価格応談として表示',
  inspection_until DATE                NULL COMMENT '車検満了日。NULL=車検なし/抹消',
  body_color       VARCHAR(32)         NULL,
  fuel             VARCHAR(16)         NULL COMMENT 'ガソリン/ディーゼル/ハイブリッド など',
  transmission     VARCHAR(16)         NULL COMMENT 'AT/MT/CVT など',
  body_type        VARCHAR(32)         NULL COMMENT '軽/セダン/SUV など',
  note             TEXT                NULL,
  status           ENUM('draft','pending','published','negotiating','sold') NOT NULL DEFAULT 'draft'
                   COMMENT '下書き/承認待ち/公開中/商談中/成約済み',
  sort_order       INT             NOT NULL DEFAULT 0 COMMENT '小さいほど先頭',
  -- 「新着」バッジと新着順に使う。created_at だと下書きのまま寝かせた車両が
  -- 公開直後から古く見えてしまう。
  published_at     DATETIME            NULL COMMENT '公開に切り替えた日時',
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cars_stock_number (stock_number),
  -- カルーセルは「status='published' を sort_order 順」で必ず引くので複合インデックスを張る。
  -- 列の順序が (status, sort_order) なのは、等値で絞ってから並べ替えるとソートを省けるため。
  KEY idx_cars_status_sort (status, sort_order),
  KEY idx_cars_published_at (status, published_at),
  KEY idx_cars_category_location (status, category, location),
  KEY idx_cars_search (status, body_type, total_price)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- car_images : 在庫の画像。実体は img.autobest.jp 配下、DBはURLのみ保持する
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS car_images (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  car_id     BIGINT UNSIGNED NOT NULL,
  image_url  VARCHAR(512)    NOT NULL COMMENT 'https://img.autobest.jp/... の絶対URL',
  position   INT             NOT NULL DEFAULT 0 COMMENT '0が代表画像',
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_car_images_car_position (car_id, position),
  CONSTRAINT fk_car_images_car FOREIGN KEY (car_id) REFERENCES cars (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- purchase_records : 買取実績
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_records (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  maker          VARCHAR(64)     NOT NULL,
  model_name     VARCHAR(128)    NOT NULL,
  model_year     SMALLINT UNSIGNED NULL,
  mileage_km     INT UNSIGNED      NULL,
  purchase_price INT UNSIGNED      NULL COMMENT '買取金額(円)',
  area           VARCHAR(64)       NULL COMMENT 'お住まいの地域（市区町村まで）',
  purchased_on   DATE              NULL,
  note           TEXT              NULL,
  published      TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1=LINEで公開',
  created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_purchase_published_date (published, purchased_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- inquiries : 問い合わせ（査定申込 / 在庫問い合わせ / 来店予約）
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inquiries (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  line_user_id BIGINT UNSIGNED NOT NULL COMMENT 'line_users.id へのFK（LINEのuserId文字列ではない）',
  car_id       BIGINT UNSIGNED     NULL COMMENT '在庫問い合わせのときのみ',
  kind         ENUM('assessment','car','visit') NOT NULL,
  payload      JSON                NULL COMMENT '査定フォームの入力内容',
  message      TEXT                NULL,
  status       ENUM('new','in_progress','done') NOT NULL DEFAULT 'new',
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- 管理画面の既定表示が「未対応を新しい順」なのでこの並びで張る。
  KEY idx_inquiries_status_created (status, created_at),
  KEY idx_inquiries_line_user (line_user_id),
  KEY idx_inquiries_car (car_id),
  CONSTRAINT fk_inquiries_line_user FOREIGN KEY (line_user_id) REFERENCES line_users (id) ON DELETE CASCADE,
  -- 在庫を消しても問い合わせ履歴は残す（実績・トラブル対応のため）。よってFKはSET NULL。
  CONSTRAINT fk_inquiries_car FOREIGN KEY (car_id) REFERENCES cars (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- message_queue : 非同期処理用のキュー
--   常駐ワーカーが使えない共用サーバーなので、cron が1分ごとにこの表を掃く。
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS message_queue (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type          VARCHAR(32)     NOT NULL COMMENT 'push / multicast / broadcast / notify_owner など',
  payload       JSON            NOT NULL,
  status        ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
  attempts      INT UNSIGNED    NOT NULL DEFAULT 0,
  last_error    TEXT                NULL,
  retry_key     CHAR(36)            NULL COMMENT 'X-Line-Retry-Key。再送時も同じ値を使い二重送信を防ぐ',
  scheduled_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'この時刻以降に実行',
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- cron の取り出しクエリ（status='pending' AND scheduled_at <= NOW() ORDER BY id）に対応。
  KEY idx_queue_status_scheduled (status, scheduled_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- webhook_events : 重複配信の検出用
--   LINEは応答が遅い/失敗したイベントを再送することがある。webhookEventId を
--   UNIQUE で持ち、INSERT IGNORE の成否で「初回かどうか」を判定する。
--   SELECT してから INSERT すると再送が同時に届いたときにすり抜けるため、
--   判定は必ず UNIQUE 制約に任せる。
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webhook_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id    VARCHAR(64)     NOT NULL COMMENT 'events[].webhookEventId',
  event_type  VARCHAR(32)         NULL,
  received_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_webhook_events_event_id (event_id),
  KEY idx_webhook_events_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- admin_users : 管理画面のログインユーザー（フェーズ2で使用）
--   password_hash は password_hash() の出力をそのまま入れる。平文は保存しない。
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_users (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  login_id        VARCHAR(64)     NOT NULL,
  password_hash   VARCHAR(255)    NOT NULL COMMENT 'password_hash() の出力。平文は保存しない',
  display_name    VARCHAR(128)        NULL,
  is_active       TINYINT(1)      NOT NULL DEFAULT 1,
  -- 総当たり対策。.htaccess の Basic認証・IP制限が第一の壁だが、
  -- そこを通過された場合に備えてアプリ側でも試行回数を数える。
  failed_attempts INT UNSIGNED    NOT NULL DEFAULT 0,
  locked_until    DATETIME            NULL COMMENT 'この時刻まではログインを受け付けない',
  last_login_at   DATETIME            NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_users_login_id (login_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- favorites / reservations / notification_* : フェーズ3以降で使う
--   定義の意図は sql/migrations/001_phase3_mysql.sql のコメントを参照。
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS favorites (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  line_user_id BIGINT UNSIGNED NOT NULL,
  car_id       BIGINT UNSIGNED NOT NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_favorites (line_user_id, car_id),
  KEY idx_favorites_user (line_user_id, created_at),
  CONSTRAINT fk_favorites_user FOREIGN KEY (line_user_id) REFERENCES line_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_favorites_car  FOREIGN KEY (car_id)       REFERENCES cars (id)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reservations (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  line_user_id  BIGINT UNSIGNED NOT NULL,
  car_id        BIGINT UNSIGNED     NULL COMMENT '車両を指定しない相談もあるためNULL可',
  location      ENUM('fukuoka','kanagawa') NOT NULL,
  preferred_1   DATETIME        NOT NULL COMMENT '第1希望',
  preferred_2   DATETIME            NULL,
  preferred_3   DATETIME            NULL,
  confirmed_at  DATETIME            NULL COMMENT '担当者が確定した日時',
  status        ENUM('tentative','confirmed','changed','cancelled') NOT NULL DEFAULT 'tentative'
                COMMENT '仮予約/確定/変更/キャンセル',
  note          TEXT                NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reservations_status (status, preferred_1),
  KEY idx_reservations_user (line_user_id),
  CONSTRAINT fk_reservations_user FOREIGN KEY (line_user_id) REFERENCES line_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_reservations_car  FOREIGN KEY (car_id)       REFERENCES cars (id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_conditions (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  line_user_id  BIGINT UNSIGNED NOT NULL,
  category      ENUM('passenger','truck','machinery','other') NULL COMMENT 'NULL=すべて',
  maker         VARCHAR(64)         NULL,
  keyword       VARCHAR(128)        NULL COMMENT '車種名の部分一致',
  price_max     INT UNSIGNED        NULL COMMENT '支払総額の上限(円)',
  location      ENUM('fukuoka','kanagawa') NULL COMMENT 'NULL=両拠点',
  enabled       TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '0=配信停止。即時反映する',
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notify_enabled (enabled),
  KEY idx_notify_user (line_user_id),
  CONSTRAINT fk_notify_user FOREIGN KEY (line_user_id) REFERENCES line_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  line_user_id BIGINT UNSIGNED NOT NULL,
  car_id       BIGINT UNSIGNED NOT NULL,
  sent_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- 同一車両の重複通知をDBの制約で防ぐ
  UNIQUE KEY uq_notification_log (line_user_id, car_id),
  CONSTRAINT fk_notiflog_user FOREIGN KEY (line_user_id) REFERENCES line_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_notiflog_car  FOREIGN KEY (car_id)       REFERENCES cars (id)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

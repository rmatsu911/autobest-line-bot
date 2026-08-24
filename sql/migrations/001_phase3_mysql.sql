-- =============================================================================
-- 001 フェーズ3：UI設計で使う項目を cars に足し、お気に入り・予約・通知条件を追加する
-- 対象: MySQL / MariaDB
-- =============================================================================

-- 在庫番号。LINEの車両詳細で「在庫番号 FK-20641」として表示する。
ALTER TABLE cars ADD COLUMN stock_number VARCHAR(32) NULL COMMENT '店舗管理用の在庫番号' AFTER id;
ALTER TABLE cars ADD UNIQUE KEY uq_cars_stock_number (stock_number);

-- 取扱区分。乗用車と重機で表示する項目（走行距離／稼働時間）が変わる。
ALTER TABLE cars ADD COLUMN category ENUM('passenger','truck','machinery','other')
  NOT NULL DEFAULT 'passenger' COMMENT '乗用車・軽/トラック・バス/重機・作業車/その他' AFTER stock_number;

-- 拠点。福岡本社・神奈川支店の2拠点で在庫を分けて持つ。
ALTER TABLE cars ADD COLUMN location ENUM('fukuoka','kanagawa')
  NOT NULL DEFAULT 'fukuoka' COMMENT '福岡本社/神奈川支店' AFTER category;

-- 稼働時間。重機・フォークリフトは走行距離ではなく稼働時間で状態を示す。
ALTER TABLE cars ADD COLUMN engine_hours INT UNSIGNED NULL COMMENT '稼働時間(h)。重機で使用' AFTER mileage_km;

-- 価格応談。0円ではなく「応談」として出すためのフラグ。
-- total_price を NULL にするだけだと「未入力」と区別できないため独立させる。
ALTER TABLE cars ADD COLUMN price_negotiable TINYINT(1)
  NOT NULL DEFAULT 0 COMMENT '1=価格応談として表示' AFTER body_price;

-- 公開日時。「新着」バッジと新着順の並びに使う。
-- created_at では下書きのまま放置した車両が公開直後に古く見えてしまう。
ALTER TABLE cars ADD COLUMN published_at DATETIME NULL COMMENT '公開に切り替えた日時' AFTER sort_order;

-- 承認フローと商談中を状態に追加する。
ALTER TABLE cars MODIFY COLUMN status
  ENUM('draft','pending','published','negotiating','sold') NOT NULL DEFAULT 'draft'
  COMMENT '下書き/承認待ち/公開中/商談中/成約済み';

-- 新着順・カテゴリ絞り込み用
CREATE INDEX idx_cars_published_at ON cars (status, published_at);
CREATE INDEX idx_cars_category_location ON cars (status, category, location);

-- -----------------------------------------------------------------------------
-- favorites : LINEユーザーごとのお気に入り車両
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS favorites (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  line_user_id BIGINT UNSIGNED NOT NULL,
  car_id       BIGINT UNSIGNED NOT NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- 同じ車両を二重に登録させない。アプリは INSERT IGNORE で追加する。
  UNIQUE KEY uq_favorites (line_user_id, car_id),
  KEY idx_favorites_user (line_user_id, created_at),
  CONSTRAINT fk_favorites_user FOREIGN KEY (line_user_id) REFERENCES line_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_favorites_car  FOREIGN KEY (car_id)       REFERENCES cars (id)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- reservations : 来店・商談予約
--   送信時点では仮予約。担当者が確認して確定日時を入れる。
-- -----------------------------------------------------------------------------
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

-- -----------------------------------------------------------------------------
-- notification_conditions : 新着通知の条件
--   無料プランでは配信通数に限りがあるため、enabled と条件で対象を絞る。
-- -----------------------------------------------------------------------------
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

-- -----------------------------------------------------------------------------
-- notification_log : 同一車両の重複通知を防ぐ
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  line_user_id BIGINT UNSIGNED NOT NULL,
  car_id       BIGINT UNSIGNED NOT NULL,
  sent_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- 「同一車両の重複通知を防ぐ」をDBの制約で担保する。
  UNIQUE KEY uq_notification_log (line_user_id, car_id),
  CONSTRAINT fk_notiflog_user FOREIGN KEY (line_user_id) REFERENCES line_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_notiflog_car  FOREIGN KEY (car_id)       REFERENCES cars (id)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

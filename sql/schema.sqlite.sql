-- =============================================================================
-- autobest.jp LINE bot スキーマ（SQLite版）
--
-- Xserver のサーバーパネルで MySQL をまだ用意できない場合の暫定運用向け。
-- MySQL版（schema.sql）と同じ制約を、SQLite の書き方で再現している。
--   ENUM        → TEXT + CHECK (x IN (...))
--   JSON        → TEXT
--   ON UPDATE CURRENT_TIMESTAMP → AFTER UPDATE トリガ
--
-- 時刻は全て JST で保存する。
-- SQLite の CURRENT_TIMESTAMP は「常に UTC」を返すため、そのまま使うと
-- created_at / updated_at だけが UTC になり、アプリが JST で書く
-- followed_at・last_login_at・scheduled_at と9時間ずれる。
-- 同じ表の中で時刻の基準が2つあると、一覧の並び順や予約配信の発火時刻が
-- 静かに狂うので、既定値もトリガも datetime('now', '+9 hours') に揃える。
-- （MySQL版は Db.php が SET time_zone='+09:00' するため NOW() が JST になる）
-- =============================================================================

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS line_users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  line_user_id  TEXT    NOT NULL,
  display_name  TEXT        NULL,
  followed_at   TEXT        NULL,
  blocked       INTEGER NOT NULL DEFAULT 0 CHECK (blocked IN (0, 1)),
  created_at    TEXT    NOT NULL DEFAULT (datetime('now', '+9 hours')),
  updated_at    TEXT    NOT NULL DEFAULT (datetime('now', '+9 hours')),
  UNIQUE (line_user_id)
);
CREATE INDEX IF NOT EXISTS idx_line_users_blocked ON line_users (blocked);

CREATE TABLE IF NOT EXISTS cars (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  stock_number     TEXT        NULL,
  category         TEXT    NOT NULL DEFAULT 'passenger' CHECK (category IN ('passenger','truck','machinery','other')),
  location         TEXT    NOT NULL DEFAULT 'fukuoka'   CHECK (location IN ('fukuoka','kanagawa')),
  maker            TEXT    NOT NULL,
  model_name       TEXT    NOT NULL,
  grade            TEXT        NULL,
  model_year       INTEGER     NULL,
  mileage_km       INTEGER     NULL,
  engine_hours     INTEGER     NULL,
  total_price      INTEGER     NULL,
  body_price       INTEGER     NULL,
  price_negotiable INTEGER NOT NULL DEFAULT 0 CHECK (price_negotiable IN (0, 1)),
  inspection_until TEXT        NULL,
  body_color       TEXT        NULL,
  fuel             TEXT        NULL,
  transmission     TEXT        NULL,
  body_type        TEXT        NULL,
  note             TEXT        NULL,
  status           TEXT    NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','pending','published','negotiating','sold')),
  sort_order       INTEGER NOT NULL DEFAULT 0,
  published_at     TEXT        NULL,
  created_at       TEXT    NOT NULL DEFAULT (datetime('now', '+9 hours')),
  updated_at       TEXT    NOT NULL DEFAULT (datetime('now', '+9 hours'))
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_cars_stock_number       ON cars (stock_number);
CREATE INDEX        IF NOT EXISTS idx_cars_published_at      ON cars (status, published_at);
CREATE INDEX        IF NOT EXISTS idx_cars_category_location ON cars (status, category, location);
CREATE INDEX IF NOT EXISTS idx_cars_status_sort ON cars (status, sort_order);
CREATE INDEX IF NOT EXISTS idx_cars_search ON cars (status, body_type, total_price);

CREATE TABLE IF NOT EXISTS car_images (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  car_id     INTEGER NOT NULL,
  image_url  TEXT    NOT NULL,
  position   INTEGER NOT NULL DEFAULT 0,
  created_at TEXT    NOT NULL DEFAULT (datetime('now', '+9 hours')),
  FOREIGN KEY (car_id) REFERENCES cars (id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_car_images_car_position ON car_images (car_id, position);

CREATE TABLE IF NOT EXISTS purchase_records (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  maker          TEXT    NOT NULL,
  model_name     TEXT    NOT NULL,
  model_year     INTEGER     NULL,
  mileage_km     INTEGER     NULL,
  purchase_price INTEGER     NULL,
  area           TEXT        NULL,
  purchased_on   TEXT        NULL,
  note           TEXT        NULL,
  published      INTEGER NOT NULL DEFAULT 0 CHECK (published IN (0, 1)),
  created_at     TEXT    NOT NULL DEFAULT (datetime('now', '+9 hours')),
  updated_at     TEXT    NOT NULL DEFAULT (datetime('now', '+9 hours'))
);
CREATE INDEX IF NOT EXISTS idx_purchase_published_date ON purchase_records (published, purchased_on);

CREATE TABLE IF NOT EXISTS inquiries (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  line_user_id      INTEGER     NULL,
  car_id            INTEGER     NULL,
  kind              TEXT    NOT NULL CHECK (kind IN ('assessment', 'car', 'visit')),
  source            TEXT    NOT NULL DEFAULT 'line' CHECK (source IN ('line', 'web', 'liff')),
  contact_name      TEXT        NULL,
  contact_tel       TEXT        NULL,
  contact_email     TEXT        NULL,
  contact_pref      TEXT        NULL,
  payload           TEXT        NULL,
  message           TEXT        NULL,
  status            TEXT    NOT NULL DEFAULT 'new' CHECK (status IN ('new', 'in_progress', 'done')),
  assigned_admin_id INTEGER     NULL,
  created_at        TEXT    NOT NULL DEFAULT (datetime('now', '+9 hours')),
  updated_at        TEXT    NOT NULL DEFAULT (datetime('now', '+9 hours')),
  FOREIGN KEY (line_user_id)      REFERENCES line_users (id)  ON DELETE SET NULL,
  FOREIGN KEY (car_id)            REFERENCES cars (id)        ON DELETE SET NULL,
  FOREIGN KEY (assigned_admin_id) REFERENCES admin_users (id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_inquiries_assigned ON inquiries (assigned_admin_id, status);
CREATE INDEX IF NOT EXISTS idx_inquiries_status_created ON inquiries (status, created_at);
CREATE INDEX IF NOT EXISTS idx_inquiries_line_user ON inquiries (line_user_id);
CREATE INDEX IF NOT EXISTS idx_inquiries_car ON inquiries (car_id);

CREATE TABLE IF NOT EXISTS message_queue (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  type          TEXT    NOT NULL,
  payload       TEXT    NOT NULL,
  status        TEXT    NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'processing', 'done', 'failed')),
  attempts      INTEGER NOT NULL DEFAULT 0,
  last_error    TEXT        NULL,
  retry_key     TEXT        NULL,
  scheduled_at  TEXT    NOT NULL DEFAULT (datetime('now', '+9 hours')),
  created_at    TEXT    NOT NULL DEFAULT (datetime('now', '+9 hours')),
  updated_at    TEXT    NOT NULL DEFAULT (datetime('now', '+9 hours'))
);
CREATE INDEX IF NOT EXISTS idx_queue_status_scheduled ON message_queue (status, scheduled_at, id);

CREATE TABLE IF NOT EXISTS webhook_events (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id    TEXT NOT NULL,
  event_type  TEXT     NULL,
  received_at TEXT NOT NULL DEFAULT (datetime('now', '+9 hours')),
  UNIQUE (event_id)
);
CREATE INDEX IF NOT EXISTS idx_webhook_events_received ON webhook_events (received_at);

CREATE TABLE IF NOT EXISTS admin_users (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  login_id        TEXT    NOT NULL,
  password_hash   TEXT    NOT NULL,
  display_name    TEXT        NULL,
  is_active       INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
  failed_attempts INTEGER NOT NULL DEFAULT 0,
  locked_until    TEXT        NULL,
  last_login_at   TEXT        NULL,
  created_at      TEXT    NOT NULL DEFAULT (datetime('now', '+9 hours')),
  updated_at      TEXT    NOT NULL DEFAULT (datetime('now', '+9 hours')),
  UNIQUE (login_id)
);

CREATE TRIGGER IF NOT EXISTS trg_line_users_updated_at
AFTER UPDATE ON line_users
FOR EACH ROW
BEGIN
  UPDATE line_users SET updated_at = datetime('now', '+9 hours') WHERE id = OLD.id;
END;

CREATE TRIGGER IF NOT EXISTS trg_cars_updated_at
AFTER UPDATE ON cars
FOR EACH ROW
BEGIN
  UPDATE cars SET updated_at = datetime('now', '+9 hours') WHERE id = OLD.id;
END;

CREATE TRIGGER IF NOT EXISTS trg_purchase_records_updated_at
AFTER UPDATE ON purchase_records
FOR EACH ROW
BEGIN
  UPDATE purchase_records SET updated_at = datetime('now', '+9 hours') WHERE id = OLD.id;
END;

CREATE TRIGGER IF NOT EXISTS trg_inquiries_updated_at
AFTER UPDATE ON inquiries
FOR EACH ROW
BEGIN
  UPDATE inquiries SET updated_at = datetime('now', '+9 hours') WHERE id = OLD.id;
END;

CREATE TRIGGER IF NOT EXISTS trg_message_queue_updated_at
AFTER UPDATE ON message_queue
FOR EACH ROW
BEGIN
  UPDATE message_queue SET updated_at = datetime('now', '+9 hours') WHERE id = OLD.id;
END;

CREATE TRIGGER IF NOT EXISTS trg_admin_users_updated_at
AFTER UPDATE ON admin_users
FOR EACH ROW
BEGIN
  UPDATE admin_users SET updated_at = datetime('now', '+9 hours') WHERE id = OLD.id;
END;

-- -----------------------------------------------------------------------------
-- favorites / reservations / notification_* : フェーズ3以降で使う
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS favorites (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  line_user_id INTEGER NOT NULL,
  car_id       INTEGER NOT NULL,
  created_at   TEXT    NOT NULL DEFAULT (datetime('now', '+9 hours')),
  UNIQUE (line_user_id, car_id),
  FOREIGN KEY (line_user_id) REFERENCES line_users (id) ON DELETE CASCADE,
  FOREIGN KEY (car_id)       REFERENCES cars (id)       ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_favorites_user ON favorites (line_user_id, created_at);

CREATE TABLE IF NOT EXISTS reservations (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  line_user_id      INTEGER     NULL,
  car_id            INTEGER     NULL,
  location          TEXT    NOT NULL CHECK (location IN ('fukuoka','kanagawa')),
  source            TEXT    NOT NULL DEFAULT 'line' CHECK (source IN ('line', 'web', 'liff')),
  purpose           TEXT    NOT NULL DEFAULT 'visit' CHECK (purpose IN ('visit', 'consult')),
  contact_name      TEXT        NULL,
  contact_tel       TEXT        NULL,
  contact_email     TEXT        NULL,
  preferred_1       TEXT    NOT NULL,
  preferred_2       TEXT        NULL,
  preferred_3       TEXT        NULL,
  confirmed_at      TEXT        NULL,
  status            TEXT    NOT NULL DEFAULT 'tentative' CHECK (status IN ('tentative','confirmed','changed','cancelled')),
  assigned_admin_id INTEGER     NULL,
  note              TEXT        NULL,
  created_at        TEXT    NOT NULL DEFAULT (datetime('now', '+9 hours')),
  updated_at        TEXT    NOT NULL DEFAULT (datetime('now', '+9 hours')),
  FOREIGN KEY (line_user_id)      REFERENCES line_users (id)  ON DELETE SET NULL,
  FOREIGN KEY (car_id)            REFERENCES cars (id)        ON DELETE SET NULL,
  FOREIGN KEY (assigned_admin_id) REFERENCES admin_users (id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_reservations_assigned ON reservations (assigned_admin_id, status);
CREATE INDEX IF NOT EXISTS idx_reservations_status ON reservations (status, preferred_1);
CREATE INDEX IF NOT EXISTS idx_reservations_user   ON reservations (line_user_id);

CREATE TABLE IF NOT EXISTS notification_conditions (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  line_user_id INTEGER NOT NULL,
  category     TEXT        NULL CHECK (category IS NULL OR category IN ('passenger','truck','machinery','other')),
  maker        TEXT        NULL,
  keyword      TEXT        NULL,
  price_max    INTEGER     NULL,
  location     TEXT        NULL CHECK (location IS NULL OR location IN ('fukuoka','kanagawa')),
  enabled      INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
  created_at   TEXT    NOT NULL DEFAULT (datetime('now', '+9 hours')),
  updated_at   TEXT    NOT NULL DEFAULT (datetime('now', '+9 hours')),
  FOREIGN KEY (line_user_id) REFERENCES line_users (id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_notify_enabled ON notification_conditions (enabled);
CREATE INDEX IF NOT EXISTS idx_notify_user    ON notification_conditions (line_user_id);

CREATE TABLE IF NOT EXISTS notification_log (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  line_user_id INTEGER NOT NULL,
  car_id       INTEGER NOT NULL,
  sent_at      TEXT    NOT NULL DEFAULT (datetime('now', '+9 hours')),
  UNIQUE (line_user_id, car_id),
  FOREIGN KEY (line_user_id) REFERENCES line_users (id) ON DELETE CASCADE,
  FOREIGN KEY (car_id)       REFERENCES cars (id)       ON DELETE CASCADE
);

CREATE TRIGGER IF NOT EXISTS trg_reservations_updated_at
AFTER UPDATE ON reservations FOR EACH ROW
BEGIN
  UPDATE reservations SET updated_at = datetime('now', '+9 hours') WHERE id = OLD.id;
END;

-- -----------------------------------------------------------------------------
-- inquiry_notes / inquiry_images / audit_logs : フェーズ4
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inquiry_notes (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  inquiry_id INTEGER NOT NULL,
  admin_id   INTEGER     NULL,
  admin_name TEXT        NULL,
  kind       TEXT    NOT NULL DEFAULT 'note' CHECK (kind IN ('note','call','reply','status')),
  body       TEXT    NOT NULL,
  created_at TEXT    NOT NULL DEFAULT (datetime('now', '+9 hours')),
  FOREIGN KEY (inquiry_id) REFERENCES inquiries (id)   ON DELETE CASCADE,
  FOREIGN KEY (admin_id)   REFERENCES admin_users (id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_inquiry_notes_inquiry ON inquiry_notes (inquiry_id, created_at);

-- 査定写真はお客様の個人資産の写真なので公開領域（img.autobest.jp）に置かない。
-- 実体は storage/assessments/ に置き、DBにはファイル名だけを持つ。
CREATE TABLE IF NOT EXISTS inquiry_images (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  inquiry_id INTEGER NOT NULL,
  file_name  TEXT    NOT NULL,
  position   INTEGER NOT NULL DEFAULT 0,
  created_at TEXT    NOT NULL DEFAULT (datetime('now', '+9 hours')),
  FOREIGN KEY (inquiry_id) REFERENCES inquiries (id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_inquiry_images_inquiry ON inquiry_images (inquiry_id, position);

-- admin_id にFKを張らない（張ると管理者削除時に証跡まで消える or 消せなくなる）。
-- 代わりに admin_name を文字列で焼き込む。
CREATE TABLE IF NOT EXISTS audit_logs (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  admin_id    INTEGER     NULL,
  admin_name  TEXT        NULL,
  action      TEXT    NOT NULL,
  target_type TEXT        NULL,
  target_id   INTEGER     NULL,
  summary     TEXT        NULL,
  ip          TEXT        NULL,
  created_at  TEXT    NOT NULL DEFAULT (datetime('now', '+9 hours'))
);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs (created_at);
CREATE INDEX IF NOT EXISTS idx_audit_target  ON audit_logs (target_type, target_id);
CREATE INDEX IF NOT EXISTS idx_audit_admin   ON audit_logs (admin_id, created_at);

-- =============================================================================
-- 001 フェーズ3（SQLite版）
--
-- SQLite は ALTER TABLE で列の型やCHECK制約を変更できない。
-- status に 'pending'（承認待ち）と 'negotiating'（商談中）を足すには
-- 表を作り直して詰め替える必要があるため、cars だけ再構築している。
-- 時刻は全てJST（datetime('now','+9 hours')）で揃える。
-- =============================================================================

PRAGMA foreign_keys = OFF;

-- --- cars を作り直す ---------------------------------------------------------
CREATE TABLE cars_new (
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

-- 既存データを引き継ぐ。新設列は既定値のまま。
INSERT INTO cars_new (id, maker, model_name, grade, model_year, mileage_km, total_price, body_price,
                      inspection_until, body_color, fuel, transmission, body_type, note, status,
                      sort_order, created_at, updated_at)
SELECT id, maker, model_name, grade, model_year, mileage_km, total_price, body_price,
       inspection_until, body_color, fuel, transmission, body_type, note, status,
       sort_order, created_at, updated_at
FROM cars;

DROP TABLE cars;
ALTER TABLE cars_new RENAME TO cars;

CREATE UNIQUE INDEX IF NOT EXISTS uq_cars_stock_number      ON cars (stock_number);
CREATE INDEX        IF NOT EXISTS idx_cars_status_sort      ON cars (status, sort_order);
CREATE INDEX        IF NOT EXISTS idx_cars_published_at     ON cars (status, published_at);
CREATE INDEX        IF NOT EXISTS idx_cars_category_location ON cars (status, category, location);

CREATE TRIGGER IF NOT EXISTS trg_cars_updated_at
AFTER UPDATE ON cars FOR EACH ROW
BEGIN
  UPDATE cars SET updated_at = datetime('now', '+9 hours') WHERE id = OLD.id;
END;

-- --- 新しい表 ---------------------------------------------------------------
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
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  line_user_id INTEGER NOT NULL,
  car_id       INTEGER     NULL,
  location     TEXT    NOT NULL CHECK (location IN ('fukuoka','kanagawa')),
  preferred_1  TEXT    NOT NULL,
  preferred_2  TEXT        NULL,
  preferred_3  TEXT        NULL,
  confirmed_at TEXT        NULL,
  status       TEXT    NOT NULL DEFAULT 'tentative' CHECK (status IN ('tentative','confirmed','changed','cancelled')),
  note         TEXT        NULL,
  created_at   TEXT    NOT NULL DEFAULT (datetime('now', '+9 hours')),
  updated_at   TEXT    NOT NULL DEFAULT (datetime('now', '+9 hours')),
  FOREIGN KEY (line_user_id) REFERENCES line_users (id) ON DELETE CASCADE,
  FOREIGN KEY (car_id)       REFERENCES cars (id)       ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_reservations_status ON reservations (status, preferred_1);
CREATE INDEX IF NOT EXISTS idx_reservations_user   ON reservations (line_user_id);
CREATE TRIGGER IF NOT EXISTS trg_reservations_updated_at
AFTER UPDATE ON reservations FOR EACH ROW
BEGIN
  UPDATE reservations SET updated_at = datetime('now', '+9 hours') WHERE id = OLD.id;
END;

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

PRAGMA foreign_keys = ON;

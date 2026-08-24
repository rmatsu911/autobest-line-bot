-- =============================================================================
-- 002 フェーズ4（SQLite版）
--
-- MySQL版と同じ結果にするが、SQLite は
--   ・NOT NULL を後から外せない
--   ・CHECK制約を後から変えられない
--   ・外部キーの定義を後から差し替えられない
-- ため、inquiries と reservations は作り直して詰め替える（001 の cars と同じ手）。
--
-- 表を DROP するとその表に付いていたトリガも消えるので、最後に張り直す。
-- 時刻は全て JST（datetime('now','+9 hours')）で揃える。
-- =============================================================================

PRAGMA foreign_keys = OFF;

-- --- inquiries を作り直す ----------------------------------------------------
DROP TRIGGER IF EXISTS trg_inquiries_updated_at;

CREATE TABLE inquiries_new (
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

INSERT INTO inquiries_new (id, line_user_id, car_id, kind, payload, message, status, created_at, updated_at)
SELECT id, line_user_id, car_id, kind, payload, message, status, created_at, updated_at FROM inquiries;

DROP TABLE inquiries;
ALTER TABLE inquiries_new RENAME TO inquiries;

CREATE INDEX IF NOT EXISTS idx_inquiries_status_created ON inquiries (status, created_at);
CREATE INDEX IF NOT EXISTS idx_inquiries_line_user      ON inquiries (line_user_id);
CREATE INDEX IF NOT EXISTS idx_inquiries_car            ON inquiries (car_id);
CREATE INDEX IF NOT EXISTS idx_inquiries_assigned       ON inquiries (assigned_admin_id, status);

CREATE TRIGGER IF NOT EXISTS trg_inquiries_updated_at
AFTER UPDATE ON inquiries FOR EACH ROW
BEGIN
  UPDATE inquiries SET updated_at = datetime('now', '+9 hours') WHERE id = OLD.id;
END;

-- --- reservations を作り直す -------------------------------------------------
DROP TRIGGER IF EXISTS trg_reservations_updated_at;

CREATE TABLE reservations_new (
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

INSERT INTO reservations_new (id, line_user_id, car_id, location, preferred_1, preferred_2, preferred_3,
                              confirmed_at, status, note, created_at, updated_at)
SELECT id, line_user_id, car_id, location, preferred_1, preferred_2, preferred_3,
       confirmed_at, status, note, created_at, updated_at FROM reservations;

DROP TABLE reservations;
ALTER TABLE reservations_new RENAME TO reservations;

CREATE INDEX IF NOT EXISTS idx_reservations_status   ON reservations (status, preferred_1);
CREATE INDEX IF NOT EXISTS idx_reservations_user     ON reservations (line_user_id);
CREATE INDEX IF NOT EXISTS idx_reservations_assigned ON reservations (assigned_admin_id, status);

CREATE TRIGGER IF NOT EXISTS trg_reservations_updated_at
AFTER UPDATE ON reservations FOR EACH ROW
BEGIN
  UPDATE reservations SET updated_at = datetime('now', '+9 hours') WHERE id = OLD.id;
END;

-- --- 新しい表 ---------------------------------------------------------------
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

-- 査定写真はお客様の個人資産の写真なので公開領域に置かない。
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

PRAGMA foreign_keys = ON;

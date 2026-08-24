-- =============================================================================
-- 002 フェーズ4（MySQL版）
--
-- 目的は3つ。
--   1) 査定申込・来店予約を「LINE以外（素のWebフォーム）」からも受け付ける
--      → line_user_id を NULL 可にし、氏名・電話などの連絡先欄を足す
--   2) 問い合わせを「誰が担当し、どう対応したか」まで管理画面で追えるようにする
--      → assigned_admin_id / inquiry_notes / inquiry_images
--   3) 管理操作の証跡を残す（要件書6章「誰がいつ返信・変更・配信したか」）
--      → audit_logs
--
-- line_user_id のFKを CASCADE から SET NULL に変えているのは、
-- LINEユーザーの行を消しても問い合わせ履歴だけは残したいため。
-- （履歴が消えると、トラブル対応時に「言った・言わない」を確認できない）
-- =============================================================================

-- --- inquiries ---------------------------------------------------------------
ALTER TABLE inquiries
  DROP FOREIGN KEY fk_inquiries_line_user;

ALTER TABLE inquiries
  MODIFY line_user_id BIGINT UNSIGNED NULL COMMENT 'line_users.id へのFK。Webフォーム経由はNULL',
  ADD COLUMN source           ENUM('line','web','liff') NOT NULL DEFAULT 'line' AFTER kind,
  ADD COLUMN contact_name     VARCHAR(64)  NULL AFTER source,
  ADD COLUMN contact_tel      VARCHAR(32)  NULL AFTER contact_name,
  ADD COLUMN contact_email    VARCHAR(191) NULL AFTER contact_tel,
  ADD COLUMN contact_pref     VARCHAR(64)  NULL COMMENT '連絡方法・時間帯の希望' AFTER contact_email,
  ADD COLUMN assigned_admin_id BIGINT UNSIGNED NULL AFTER status,
  ADD KEY idx_inquiries_assigned (assigned_admin_id, status),
  ADD CONSTRAINT fk_inquiries_line_user FOREIGN KEY (line_user_id) REFERENCES line_users (id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_inquiries_admin     FOREIGN KEY (assigned_admin_id) REFERENCES admin_users (id) ON DELETE SET NULL;

-- --- reservations ------------------------------------------------------------
ALTER TABLE reservations
  DROP FOREIGN KEY fk_reservations_user;

ALTER TABLE reservations
  MODIFY line_user_id BIGINT UNSIGNED NULL COMMENT 'Webフォーム経由はNULL',
  ADD COLUMN source            ENUM('line','web','liff') NOT NULL DEFAULT 'line' AFTER location,
  ADD COLUMN contact_name      VARCHAR(64)  NULL AFTER source,
  ADD COLUMN contact_tel       VARCHAR(32)  NULL AFTER contact_name,
  ADD COLUMN contact_email     VARCHAR(191) NULL AFTER contact_tel,
  ADD COLUMN purpose           ENUM('visit','consult') NOT NULL DEFAULT 'visit' COMMENT '来店/オンライン相談' AFTER source,
  ADD COLUMN assigned_admin_id BIGINT UNSIGNED NULL AFTER status,
  ADD KEY idx_reservations_assigned (assigned_admin_id, status),
  ADD CONSTRAINT fk_reservations_user  FOREIGN KEY (line_user_id) REFERENCES line_users (id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_reservations_admin FOREIGN KEY (assigned_admin_id) REFERENCES admin_users (id) ON DELETE SET NULL;

-- --- inquiry_notes : 対応履歴 -------------------------------------------------
-- 1件の問い合わせに対する「電話した」「見積を送った」を時系列で積む。
CREATE TABLE IF NOT EXISTS inquiry_notes (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  inquiry_id BIGINT UNSIGNED NOT NULL,
  admin_id   BIGINT UNSIGNED     NULL COMMENT '担当者を消しても履歴は残すためNULL可',
  admin_name VARCHAR(128)        NULL COMMENT '記録時点の表示名。後から管理者を消しても誰が書いたか分かる',
  kind       ENUM('note','call','reply','status') NOT NULL DEFAULT 'note',
  body       TEXT            NOT NULL,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_inquiry_notes_inquiry (inquiry_id, created_at),
  CONSTRAINT fk_inquiry_notes_inquiry FOREIGN KEY (inquiry_id) REFERENCES inquiries (id) ON DELETE CASCADE,
  CONSTRAINT fk_inquiry_notes_admin   FOREIGN KEY (admin_id)   REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- inquiry_images : 査定写真 -----------------------------------------------
-- 在庫写真（car_images）と違い、これはお客様の個人資産の写真なので公開領域に置かない。
-- 実体は storage/assessments/ に置き、DBにはファイル名だけを持つ。
-- URLではなくファイル名にしているのは、配信を管理画面のログイン必須スクリプト
-- （admin/inquiry_image.php）に限定し、パスを外から組み立てさせないため。
CREATE TABLE IF NOT EXISTS inquiry_images (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  inquiry_id BIGINT UNSIGNED NOT NULL,
  file_name  VARCHAR(64)     NOT NULL COMMENT '乱数32桁 + 拡張子。パスは含めない',
  position   INT UNSIGNED    NOT NULL DEFAULT 0,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_inquiry_images_inquiry (inquiry_id, position),
  CONSTRAINT fk_inquiry_images_inquiry FOREIGN KEY (inquiry_id) REFERENCES inquiries (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- audit_logs : 管理操作の証跡 ---------------------------------------------
-- admin_id にFKを張らない（張ると管理者削除時に証跡まで消える or 消せなくなる）。
-- 代わりに admin_name を文字列で焼き込む。
CREATE TABLE IF NOT EXISTS audit_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id    BIGINT UNSIGNED     NULL,
  admin_name  VARCHAR(128)        NULL,
  action      VARCHAR(64)     NOT NULL COMMENT 'car.publish / inquiry.assign など',
  target_type VARCHAR(32)         NULL,
  target_id   BIGINT UNSIGNED     NULL,
  summary     VARCHAR(255)        NULL,
  ip          VARCHAR(45)         NULL COMMENT 'IPv6も入るため45文字',
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_created (created_at),
  KEY idx_audit_target (target_type, target_id),
  KEY idx_audit_admin (admin_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

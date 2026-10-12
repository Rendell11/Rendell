-- CAPS: Relocation updates (Admin + Resident App)
-- Run once on barangay_db (phpMyAdmin → SQL). Self-contained: also creates resident_relocations
-- and residents.ResidentStatus if they do not exist yet.
-- CAPS also applies these automatically (relocation_helper.php → rl_migrate,
-- staffbpso_common.php → staffbpso_migrate_schema). Safe to run more than once.

-- 0) Base relocation tables (same as 2026_10_12_resident_relocation.sql), in case that file was not run yet.
-- Resident status: 'Active' (default) or 'Relocated' (moved to another barangay).
-- (Works on MySQL and MariaDB; does nothing if the column already exists.)
SET @rl_has := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'residents' AND COLUMN_NAME = 'ResidentStatus');
SET @rl_sql := IF(@rl_has = 0,
  'ALTER TABLE residents ADD COLUMN ResidentStatus VARCHAR(20) NOT NULL DEFAULT ''Active'', ADD INDEX idx_residents_status (ResidentStatus)',
  'SELECT 1');
PREPARE rl_stmt FROM @rl_sql;
EXECUTE rl_stmt;
DEALLOCATE PREPARE rl_stmt;

-- Audit trail: one row per move out / move back.
CREATE TABLE IF NOT EXISTS resident_relocations (
  relocation_id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  resident_id            INT UNSIGNED NOT NULL,
  direction              ENUM('Out','Back') NOT NULL,
  survey_id              INT UNSIGNED NULL COMMENT 'household left (Out) or joined (Back)',
  household_code         VARCHAR(30) NULL,
  household_role         VARCHAR(100) NULL,
  region_name            VARCHAR(150) NULL,
  province_name          VARCHAR(150) NULL,
  municipality_name      VARCHAR(150) NULL,
  barangay_name          VARCHAR(150) NULL,
  psgc_region_code       VARCHAR(20) NULL,
  psgc_province_code     VARCHAR(20) NULL,
  psgc_municipality_code VARCHAR(20) NULL,
  psgc_barangay_code     VARCHAR(20) NULL,
  zip_code               VARCHAR(10) NULL,
  address_line           VARCHAR(500) NULL,
  moved_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  moved_by               VARCHAR(150) NULL,
  notes                  VARCHAR(500) NULL,
  PRIMARY KEY (relocation_id),
  KEY idx_rr_resident (resident_id, moved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1) Previous address kept on every move (View Resident Details, Old/New Address on documents).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resident_relocations' AND COLUMN_NAME = 'previous_address');
SET @q := IF(@c = 0, 'ALTER TABLE resident_relocations ADD COLUMN previous_address VARCHAR(600) NULL', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resident_relocations' AND COLUMN_NAME = 'previous_address_json');
SET @q := IF(@c = 0, 'ALTER TABLE resident_relocations ADD COLUMN previous_address_json TEXT NULL', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) Staff / BPSO relocation: note + blocked account (separate from End Term / Remove).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff' AND COLUMN_NAME = 'AccountStatus');
SET @q := IF(@c = 0, 'ALTER TABLE staff
    ADD COLUMN AccountStatus  VARCHAR(20)  NOT NULL DEFAULT ''Active'',
    ADD COLUMN RelocationNote VARCHAR(500) NULL,
    ADD COLUMN RelocatedAt    DATETIME     NULL,
    ADD COLUMN RelocatedBy    VARCHAR(255) NULL,
    ADD COLUMN ReactivatedAt  DATETIME     NULL,
    ADD COLUMN ReactivatedBy  VARCHAR(255) NULL', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

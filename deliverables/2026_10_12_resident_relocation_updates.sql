-- CAPS: Relocation updates (Admin + Resident App)
-- Run once on barangay_db (phpMyAdmin → SQL), after 2026_10_12_resident_relocation.sql (file names sort in run order).
-- CAPS also applies these automatically (relocation_helper.php → rl_migrate,
-- staffbpso_common.php → staffbpso_migrate_schema). Safe to run more than once.

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

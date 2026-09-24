-- ============================================================================
--  Fix_Barangay_Profile.sql
--  Run ONCE in phpMyAdmin (SQL tab) on a barangay_db that was created with the
--  earlier Rebuild_Database script. Adds the barangay_profile columns that were
--  missing (the cause of "Unable to save the default barangay address").
--  Safe to run more than once — existing columns are skipped. No data is lost.
-- ============================================================================
SET NAMES utf8mb4;
USE barangay_db;
SET @db = DATABASE();

SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='barangay_profile' AND COLUMN_NAME='about')=0,
  'ALTER TABLE barangay_profile ADD COLUMN `about` TEXT NULL', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='barangay_profile' AND COLUMN_NAME='vision')=0,
  'ALTER TABLE barangay_profile ADD COLUMN `vision` TEXT NULL', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='barangay_profile' AND COLUMN_NAME='mission')=0,
  'ALTER TABLE barangay_profile ADD COLUMN `mission` TEXT NULL', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='barangay_profile' AND COLUMN_NAME='office_hours')=0,
  'ALTER TABLE barangay_profile ADD COLUMN `office_hours` VARCHAR(512) NOT NULL DEFAULT ''Monday – Friday, 8:00 AM – 5:00 PM''', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='barangay_profile' AND COLUMN_NAME='address')=0,
  'ALTER TABLE barangay_profile ADD COLUMN `address` VARCHAR(512) NULL', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='barangay_profile' AND COLUMN_NAME='email')=0,
  'ALTER TABLE barangay_profile ADD COLUMN `email` VARCHAR(255) NULL', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='barangay_profile' AND COLUMN_NAME='facebook_url')=0,
  'ALTER TABLE barangay_profile ADD COLUMN `facebook_url` VARCHAR(512) NULL', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='barangay_profile' AND COLUMN_NAME='region_name')=0,
  'ALTER TABLE barangay_profile ADD COLUMN `region_name` VARCHAR(150) NULL', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='barangay_profile' AND COLUMN_NAME='province_name')=0,
  'ALTER TABLE barangay_profile ADD COLUMN `province_name` VARCHAR(150) NULL', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='barangay_profile' AND COLUMN_NAME='municipality_name')=0,
  'ALTER TABLE barangay_profile ADD COLUMN `municipality_name` VARCHAR(150) NULL', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='barangay_profile' AND COLUMN_NAME='barangay_name')=0,
  'ALTER TABLE barangay_profile ADD COLUMN `barangay_name` VARCHAR(150) NULL', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='barangay_profile' AND COLUMN_NAME='psgc_region_code')=0,
  'ALTER TABLE barangay_profile ADD COLUMN `psgc_region_code` VARCHAR(20) NULL', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='barangay_profile' AND COLUMN_NAME='psgc_province_code')=0,
  'ALTER TABLE barangay_profile ADD COLUMN `psgc_province_code` VARCHAR(20) NULL', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='barangay_profile' AND COLUMN_NAME='psgc_municipality_code')=0,
  'ALTER TABLE barangay_profile ADD COLUMN `psgc_municipality_code` VARCHAR(20) NULL', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='barangay_profile' AND COLUMN_NAME='psgc_barangay_code')=0,
  'ALTER TABLE barangay_profile ADD COLUMN `psgc_barangay_code` VARCHAR(20) NULL', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='barangay_profile' AND COLUMN_NAME='zip_code')=0,
  'ALTER TABLE barangay_profile ADD COLUMN `zip_code` VARCHAR(10) NULL', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT IGNORE INTO barangay_profile (id, brgy_name) VALUES (1, 'Barangay Biñang 2nd');

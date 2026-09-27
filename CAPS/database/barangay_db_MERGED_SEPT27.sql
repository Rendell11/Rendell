-- ============================================================================
--  Barangay DB — Rebuild Script (merged)
--  = your Rebuild_Database (incl. Officials + Staff/BPSO revision, SMS config)
--  + barangay_profile with EVERY column the app uses (address, about, vision,
--    mission, office_hours, email, facebook_url, region/province/municipality/
--    barangay names, PSGC codes, zip_code). Without them Manage Area →
--    "Save Default Address" failed with "Unknown column 'address'".
--  + SET NAMES utf8mb4 so "Biñang" is not garbled when imported.
--
--  MERGED SEPT 27: Rebuild_Database + UPDATED_DB_SEPT27 in one file.
--   • Base = UPDATED_DB_SEPT27 (revision columns already inside CREATE TABLE,
--     final Blotter module, portal_preferences).
--   • No duplicates: every table is created once; the old placeholder
--     `blotter` / second `id_sequences` of the Rebuild script are gone.
--   • Safe to run again: CREATE ... IF NOT EXISTS, INSERT IGNORE, no
--     DROP TABLE (existing data is never wiped).
--   • Added the tables both files were missing (the last line
--     "ALTER TABLE emergency_chats" failed because the table never existed):
--     emergency_chats, emergency_hotlines, chat_office_hours,
--     chat_auto_msg_log, access_request_logs, household_history,
--     user_preferences, announcement_reads.
-- ============================================================================
SET NAMES utf8mb4;
CREATE DATABASE IF NOT EXISTS barangay_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE barangay_db;


CREATE TABLE IF NOT EXISTS `admin` (
  `AdminID` int unsigned NOT NULL AUTO_INCREMENT,
  `Username` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `Email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `Name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `Password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`AdminID`),
  UNIQUE KEY `Username` (`Username`),
  UNIQUE KEY `Email` (`Email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `admin` VALUES (1,'admin','admin@barangaydaungan.ph','System Administrator','$2y$10$I3uhSZtQjpcDoKsB2ZBUUu3mf2Ijxtgdwfmk/XTfcmc1RcBvwab8S','2026-07-01 11:54:13','2026-07-01 11:54:13');


CREATE TABLE IF NOT EXISTS `admin_preferences` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int unsigned NOT NULL,
  `preference_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `preference_value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_pref` (`admin_id`,`preference_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIX #1: utf8mb4_0900_ai_ci -> utf8mb4_unicode_ci (MariaDB/XAMPP-compatible)
CREATE TABLE IF NOT EXISTS `staff` (
  `EmployeeID` varchar(50) NOT NULL,
  `Name` varchar(255) DEFAULT NULL,
  `ResidentID` varchar(20) DEFAULT NULL,
  `Position` varchar(100) DEFAULT NULL,
  `WorkEmail` varchar(150) DEFAULT NULL,
  `Password` varchar(255) DEFAULT NULL,
  `WorkLoad` text,
  `CreatedAt` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `EffectiveStart` date         DEFAULT NULL,   -- assignment start
  `EffectiveEnd`   date         DEFAULT NULL,   -- set on End Term (soft end, no delete)
  `RecordedBy`     varchar(255) DEFAULT NULL,
  `RecordedByID`   varchar(50)  DEFAULT NULL,
  `EndReason`      varchar(255) DEFAULT NULL,
  `EndedBy`        varchar(255) DEFAULT NULL,
  `EndedByID`      varchar(50)  DEFAULT NULL,
  `EndedAt`        datetime     DEFAULT NULL,
  `WorkStart`      time         NOT NULL DEFAULT '08:00:00',   -- regular staff work schedule
  `WorkEnd`        time         NOT NULL DEFAULT '17:00:00',
  `WorkDays`       varchar(20)  NOT NULL DEFAULT '1,2,3,4,5',
  PRIMARY KEY (`EmployeeID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- FIX #2: `residents` — the core table the whole resident module needs.
-- Column list assembled from every INSERT/UPDATE/SELECT against `residents`
-- across admin/backend/process_resident.php, admin/backend/residents.php,
-- admin/backend/get_resident_info.php, admin/backend/delete_resident.php,
-- admin/backend/dashboard.php, admin/backend/get_eligible_residents.php,
-- admin/backend/get_residents_in_radius.php, admin/backend/get_total_resident.php,
-- admin/backend/check_duplicate.php, admin/backend/resident_generate_report.php,
-- and the resident self-service portal (login/resident_*.php,
-- login/process_complete_profile.php, login/request_access.php,
-- login/set_password.php).
-- ============================================================================
CREATE TABLE IF NOT EXISTS `residents` (
  `ResidentID`            int unsigned NOT NULL AUTO_INCREMENT,
  `ResidentCode`          varchar(20)  DEFAULT NULL,               -- e.g. RES-2026-0001
  `FirstName`             varchar(100) NOT NULL,
  `MiddleName`            varchar(100) DEFAULT NULL,
  `LastName`              varchar(100) NOT NULL,
  `Suffix`                varchar(10)  DEFAULT NULL,
  `Sex`                   enum('Male','Female') DEFAULT NULL,
  `BirthDate`             date         DEFAULT NULL,
  `BirthPlace`            varchar(255) DEFAULT NULL,
  `CivilStatus`           varchar(30)  DEFAULT 'Single',
  `ContactNumber`         varchar(11)  DEFAULT NULL,
  `Email`                 varchar(150) DEFAULT NULL,
  `HouseNumber`           varchar(50)  DEFAULT NULL,
  `StreetName`            varchar(150) DEFAULT NULL,
  `Purok`                 varchar(100) DEFAULT NULL,
  `Latitude`              decimal(10,7) DEFAULT NULL,
  `Longitude`             decimal(10,7) DEFAULT NULL,

  `IsHead`                tinyint(1)   NOT NULL DEFAULT 0,
  `RelationshipToHead`    varchar(100) DEFAULT NULL,
  `FamilyHeadID`          int unsigned DEFAULT NULL,

  `EmploymentStatus`      varchar(50)  DEFAULT 'Unemployed',
  `EmploymentStatusOther` varchar(100) DEFAULT NULL,
  `Occupation`            varchar(150) DEFAULT NULL,
  `SourceOfIncome`        varchar(255) DEFAULT NULL,
  `SourceOfIncomeOther`   varchar(150) DEFAULT NULL,
  `TotalHouseholdIncome`  decimal(12,2) NOT NULL DEFAULT 0.00,
  `EducationLevel`        varchar(50)  DEFAULT NULL,

  `IsPWD`                 tinyint(1)   NOT NULL DEFAULT 0,
  `PWDClassification`     varchar(100) DEFAULT NULL,
  `PWDID`                 varchar(50)  DEFAULT NULL,
  `IsSenior`              tinyint(1)   NOT NULL DEFAULT 0,
  `IsSoloParent`          tinyint(1)   NOT NULL DEFAULT 0,
  `IsVoter`               tinyint(1)   NOT NULL DEFAULT 0,
  `VoterNumber`           varchar(50)  DEFAULT NULL,
  `Religion`              varchar(100) DEFAULT NULL,
  `Nationality`           varchar(100) DEFAULT NULL,
  `HasPhilhealth`         tinyint(1)   NOT NULL DEFAULT 0,
  `HasSSSGSIS`            tinyint(1)   NOT NULL DEFAULT 0,
  `Has4Ps`                tinyint(1)   NOT NULL DEFAULT 0,
  `IsSSSMember`           tinyint(1)   NOT NULL DEFAULT 0,
  `IsGSISMember`          tinyint(1)   NOT NULL DEFAULT 0,
  `IsPagibigMember`       tinyint(1)   NOT NULL DEFAULT 0,

  `IsDeceased`            tinyint(1)   NOT NULL DEFAULT 0,
  `DateOfDeath`           date         DEFAULT NULL,
  `PlaceOfDeath`          varchar(255) DEFAULT NULL,
  `CauseOfDeath`          varchar(255) DEFAULT NULL,
  `DeceasedRemarks`       text         DEFAULT NULL,
  `DeathCertificate`      varchar(255) DEFAULT NULL,
  `DeathDateReported`     datetime     DEFAULT NULL,
  `DeathReportedBy`       varchar(255) DEFAULT NULL,

  -- Resident portal / self-service account fields
  `Password`              varchar(255) DEFAULT NULL,
  `access_status`         enum('None','Pending','Active','Disabled') NOT NULL DEFAULT 'None',
  `ResetToken`            varchar(128) DEFAULT NULL,
  `TokenExpiry`           datetime     DEFAULT NULL,

  `CreatedAt`             timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt`             timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`ResidentID`),
  UNIQUE KEY `ResidentCode` (`ResidentCode`),
  UNIQUE KEY `uq_residents_contact` (`ContactNumber`),
  UNIQUE KEY `uq_residents_email` (`Email`),
  KEY `idx_residents_family_head` (`FamilyHeadID`),
  KEY `idx_residents_purok` (`Purok`),
  KEY `idx_residents_reset_token` (`ResetToken`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- FIX #3a: `access_requests` — resident portal sign-up requests.
-- (admin/backend/residents.php creates this itself with CREATE TABLE IF NOT EXISTS IF NOT
-- EXISTS at runtime, but it's included here so the DB is complete/consistent
-- from the start and matches every column referenced in login/request_access.php,
-- login/process_access_request.php, login/resident_profiling.php, and
-- login/set_password.php.)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `access_requests` (
  `id`              int NOT NULL AUTO_INCREMENT,
  `request_id`      int DEFAULT NULL,   -- kept equal to `id` by trigger below (see note)
  `fullname`        varchar(255) NOT NULL,
  `first_name`      varchar(100) NOT NULL,
  `middle_name`     varchar(100) DEFAULT NULL,
  `last_name`       varchar(100) NOT NULL,
  -- Compatibility columns: admin/backend/residents.php's pending-requests
  -- query selects `firstname`/`middlename`/`lastname` (no underscore) with
  -- NO try/catch around it, while every other file in the app writes to
  -- `first_name`/`middle_name`/`last_name` (with underscore). That mismatch
  -- is what threw the uncaught "Unknown column 'firstname'" PDOException
  -- and produced the HTTP 500 on admin/frontend/residents.php. These three
  -- virtual columns mirror the real ones under the other spelling so both
  -- naming conventions used across the codebase resolve to the same data.
  `firstname`       varchar(100) GENERATED ALWAYS AS (`first_name`)  VIRTUAL,
  `middlename`      varchar(100) GENERATED ALWAYS AS (`middle_name`) VIRTUAL,
  `lastname`        varchar(100) GENERATED ALWAYS AS (`last_name`)   VIRTUAL,
  `email`           varchar(255) NOT NULL,
  `contact_number`  varchar(15)  DEFAULT NULL,
  `birthdate`       date         DEFAULT NULL,
  `house_no`        varchar(50)  DEFAULT NULL,
  `street`          varchar(100) DEFAULT NULL,
  `purok`           varchar(50)  DEFAULT NULL,       -- used by login/request_access.php
  `valid_id_path`   varchar(500) DEFAULT NULL,
  `selfie_image`    varchar(512) DEFAULT NULL,       -- used by login/request_access.php
  `request_message` text         DEFAULT NULL,
  `status`          enum('Pending','Approved','Disapproved','Matched','For Profiling','For Correction','Rejected') NOT NULL DEFAULT 'Pending',
  `token`           varchar(128) DEFAULT NULL,
  `token_expiry`    datetime     DEFAULT NULL,
  `admin_reason`    text         DEFAULT NULL,
  `resident_id`     int          DEFAULT NULL,
  `matched_resident_id` int      DEFAULT NULL,
  `processed_at`    datetime     DEFAULT NULL,       -- used by login/resident_profiling.php
  `processed_by`    varchar(150) DEFAULT NULL,       -- used by login/resident_profiling.php
  `submitted_at`    timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at`      timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_request_id` (`request_id`),
  KEY `idx_email`  (`email`),
  KEY `idx_status` (`status`),
  KEY `idx_token`  (`token`(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTE on id / request_id:
-- The app itself is inconsistent about the row-id column name:
--   - login/process_access_request.php reads/writes `id`
--   - admin/backend/residents.php, login/request_access.php, and
--     login/set_password.php all read/write `request_id`
-- Two things were tried and rejected before this one:
--   1. `request_id` as a GENERATED column referencing `id` -> MySQL
--      rejects any generated column that refers to an AUTO_INCREMENT
--      column (Error 3109).
--   2. An AFTER INSERT trigger that UPDATEs the same row/table -> MySQL
--      and MariaDB both reject a trigger modifying the very table whose
--      statement fired it ("Can't update table ... already used by
--      statement", Error 1442).
-- This BEFORE INSERT trigger avoids both restrictions: it reads the
-- table's next AUTO_INCREMENT value from information_schema (a
-- different "table" as far as the engine's mutation check is concerned)
-- and assigns it to NEW.request_id before the row is written, so `id`
-- and `request_id` land equal on every insert without touching
-- access_requests itself mid-statement.
DROP TRIGGER IF EXISTS `trg_access_requests_before_insert`;
DELIMITER $$
CREATE TRIGGER `trg_access_requests_before_insert`
BEFORE INSERT ON `access_requests`
FOR EACH ROW
BEGIN
    IF NEW.`request_id` IS NULL THEN
        SET NEW.`request_id` = (
            SELECT `AUTO_INCREMENT` FROM `information_schema`.`TABLES`
            WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'access_requests'
        );
    END IF;
END$$
DELIMITER ;

-- ============================================================================
-- FIX #3b: `staff_permissions` — RBAC used by permission_helper.php's
-- require_permission()/staff_can(). Without this, any non-admin staff
-- account gets a DB error the moment it tries to save a resident.
-- ============================================================================
CREATE TABLE IF NOT EXISTS `staff_permissions` (
  `id`         int unsigned NOT NULL AUTO_INCREMENT,
  `EmployeeID` varchar(50)  NOT NULL,
  `ModuleKey`  varchar(50)  NOT NULL,
  `CanCreate`  tinyint(1)   NOT NULL DEFAULT 0,
  `CanRead`    tinyint(1)   NOT NULL DEFAULT 0,
  `CanUpdate`  tinyint(1)   NOT NULL DEFAULT 0,
  `CanDelete`  tinyint(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_module` (`EmployeeID`,`ModuleKey`),
  CONSTRAINT `fk_staff_permissions_employee` FOREIGN KEY (`EmployeeID`) REFERENCES `staff` (`EmployeeID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- FIX #3c: `activity_logs` — audit trail written by activity_log_helper.php
-- every time a resident is added/edited/deleted (fails silently without it,
-- so the module "works" but every action goes unlogged).
-- ============================================================================
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id`          int unsigned NOT NULL AUTO_INCREMENT,
  `user_id`     varchar(50)  DEFAULT NULL,
  `full_name`   varchar(255) DEFAULT NULL,
  `role`        varchar(50)  DEFAULT NULL,
  `module`      varchar(100) DEFAULT NULL,
  `action`      varchar(100) DEFAULT NULL,
  `description` text         DEFAULT NULL,
  `ip_address`  varchar(45)  DEFAULT NULL,
  `device_info` varchar(255) DEFAULT NULL,
  `created_at`  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_module` (`module`),
  KEY `idx_activity_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- FIX #3d: `puroks` — dropdown source used on the resident add/registration
-- forms (login/resident_profiling.php, login/request_access.php). The code
-- already falls back gracefully if this is missing, but having it means the
-- dropdown is actually populated instead of empty.
-- ============================================================================
CREATE TABLE IF NOT EXISTS `puroks` (
  `id`         int unsigned NOT NULL AUTO_INCREMENT,
  `purok_name` varchar(100) NOT NULL,
  `status`     enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `CreatedAt`  timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_purok_name` (`purok_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- FIX #3e: `barangay_profile` — letterhead/logo used by
-- admin/backend/resident_generate_report.php. That file queries this table
-- with no try/catch, so a resident report request would hard-crash without it.
-- ============================================================================
CREATE TABLE IF NOT EXISTS `barangay_profile` (
  `id`                     int unsigned NOT NULL AUTO_INCREMENT,
  `brgy_name`              varchar(255) NOT NULL DEFAULT 'Barangay Biñang 2nd',
  `logo_path`              varchar(512) DEFAULT NULL,
  `about`                  text         DEFAULT NULL,
  `vision`                 text         DEFAULT NULL,
  `mission`                text         DEFAULT NULL,
  `office_hours`           varchar(512) NOT NULL DEFAULT 'Monday – Friday, 8:00 AM – 5:00 PM',
  `address`                varchar(512) DEFAULT NULL,   -- Manage Area "Barangay address" + report letterheads
  `email`                  varchar(255) DEFAULT NULL,
  `facebook_url`           varchar(512) DEFAULT NULL,
  `region_name`            varchar(150) DEFAULT NULL,   -- Manage Area → Default Barangay Address
  `province_name`          varchar(150) DEFAULT NULL,
  `municipality_name`      varchar(150) DEFAULT NULL,
  `barangay_name`          varchar(150) DEFAULT NULL,
  `psgc_region_code`       varchar(20)  DEFAULT NULL,
  `psgc_province_code`     varchar(20)  DEFAULT NULL,
  `psgc_municipality_code` varchar(20)  DEFAULT NULL,
  `psgc_barangay_code`     varchar(20)  DEFAULT NULL,
  `zip_code`               varchar(10)  DEFAULT NULL,
  `UpdatedAt`              timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_at`             timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `barangay_profile` (`id`, `brgy_name`, `logo_path`) VALUES (1, 'Barangay Biñang 2nd', NULL);

-- ============================================================================
-- FIX #3f: `request_logs` — used by login/resident_profiling.php to record
-- when a self-service profiling request is completed. The insert is already
-- wrapped in try/catch in that file (so its absence wasn't crashing
-- anything), but without this table those log entries were just silently
-- disappearing.
-- ============================================================================
CREATE TABLE IF NOT EXISTS `request_logs` (
  `id`         int unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int          DEFAULT NULL,
  `action`     varchar(100) DEFAULT NULL,
  `action_by`  varchar(150) DEFAULT NULL,
  `remarks`    text         DEFAULT NULL,
  `created_at` timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_request_logs_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE IF NOT EXISTS resident_streets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  psgc_barangay_code VARCHAR(20) NOT NULL,
  street_name VARCHAR(150) NOT NULL,
  status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_resident_street (psgc_barangay_code, street_name),
  KEY idx_street_barangay (psgc_barangay_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS resident_areas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  psgc_barangay_code VARCHAR(20) NOT NULL,
  area_type ENUM('Purok','Subdivision','Village','Sitio') NOT NULL,
  area_name VARCHAR(150) NOT NULL,
  status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_resident_area (psgc_barangay_code, area_type, area_name),
  KEY idx_area_barangay (psgc_barangay_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS psgc_zip_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  municipality_code VARCHAR(20) NOT NULL,
  municipality_name VARCHAR(150) NOT NULL,
  zip_code VARCHAR(10) NOT NULL,
  status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  PRIMARY KEY (id),
  UNIQUE KEY uq_zip_municipality (municipality_code),
  KEY idx_zip_name (municipality_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @db = DATABASE();

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='residents' AND COLUMN_NAME='BuildingName')=0,
  'ALTER TABLE residents ADD COLUMN BuildingName VARCHAR(150) NULL AFTER HouseNumber',
  'SELECT 1'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='residents' AND COLUMN_NAME='AreaName')=0,
  'ALTER TABLE residents ADD COLUMN AreaName VARCHAR(150) NULL AFTER Purok',
  'SELECT 1'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='residents' AND COLUMN_NAME='AreaType')=0,
  'ALTER TABLE residents ADD COLUMN AreaType VARCHAR(30) NULL AFTER AreaName',
  'SELECT 1'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='residents' AND COLUMN_NAME='BarangayName')=0,
  'ALTER TABLE residents ADD COLUMN BarangayName VARCHAR(150) NULL AFTER AreaType',
  'SELECT 1'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='residents' AND COLUMN_NAME='CityMunicipalityName')=0,
  'ALTER TABLE residents ADD COLUMN CityMunicipalityName VARCHAR(150) NULL AFTER BarangayName',
  'SELECT 1'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='residents' AND COLUMN_NAME='ProvinceName')=0,
  'ALTER TABLE residents ADD COLUMN ProvinceName VARCHAR(150) NULL AFTER CityMunicipalityName',
  'SELECT 1'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='residents' AND COLUMN_NAME='RegionName')=0,
  'ALTER TABLE residents ADD COLUMN RegionName VARCHAR(150) NULL AFTER ProvinceName',
  'SELECT 1'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='residents' AND COLUMN_NAME='ZipCode')=0,
  'ALTER TABLE residents ADD COLUMN ZipCode VARCHAR(10) NULL AFTER RegionName',
  'SELECT 1'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='residents' AND COLUMN_NAME='PSGCRegionCode')=0,
  'ALTER TABLE residents ADD COLUMN PSGCRegionCode VARCHAR(20) NULL AFTER ZipCode',
  'SELECT 1'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='residents' AND COLUMN_NAME='PSGCProvinceCode')=0,
  'ALTER TABLE residents ADD COLUMN PSGCProvinceCode VARCHAR(20) NULL AFTER PSGCRegionCode',
  'SELECT 1'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='residents' AND COLUMN_NAME='PSGCMunicipalityCode')=0,
  'ALTER TABLE residents ADD COLUMN PSGCMunicipalityCode VARCHAR(20) NULL AFTER PSGCProvinceCode',
  'SELECT 1'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='residents' AND COLUMN_NAME='PSGCBarangayCode')=0,
  'ALTER TABLE residents ADD COLUMN PSGCBarangayCode VARCHAR(20) NULL AFTER PSGCMunicipalityCode',
  'SELECT 1'
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT IGNORE INTO resident_areas (psgc_barangay_code, area_type, area_name)
SELECT '0301404006', 'Purok', TRIM(purok_name)
FROM puroks
WHERE status='Active' AND TRIM(purok_name) <> '';

INSERT IGNORE INTO resident_areas (psgc_barangay_code, area_type, area_name)
SELECT DISTINCT '0301404006', 'Purok', TRIM(Purok)
FROM residents
WHERE Purok IS NOT NULL AND TRIM(Purok) <> '';

-- Common ZIP mapping for Bocaue; the field remains editable in the form.
INSERT IGNORE INTO psgc_zip_codes (municipality_code, municipality_name, zip_code)
VALUES ('0301404000','Bocaue','3018');





CREATE TABLE IF NOT EXISTS `household_survey` (
  `SurveyID` int unsigned NOT NULL AUTO_INCREMENT,
  `HouseholdID` varchar(30) NOT NULL,
  `ResidentID` int unsigned DEFAULT NULL,
  `head_name` varchar(255) NOT NULL,
  `address` text NOT NULL,
  `civil_status` varchar(30) NOT NULL,
  `sex` enum('Male','Female') NOT NULL,
  `contact_number` varchar(30) NOT NULL,
  `educational_background` varchar(255) DEFAULT NULL,
  `income_bracket` varchar(50) NOT NULL,
  `is_indigenous_people` tinyint(1) NOT NULL DEFAULT 0,
  `is_migrant_family` tinyint(1) NOT NULL DEFAULT 0,
  `housing_tenure` varchar(80) DEFAULT NULL,
  `housing_tenure_other` varchar(255) DEFAULT NULL,
  `has_electricity` tinyint(1) DEFAULT NULL,
  `has_internet` tinyint(1) DEFAULT NULL,
  `waste_disposal` varchar(80) DEFAULT NULL,
  `waste_disposal_other` varchar(255) DEFAULT NULL,
  `water_source` varchar(255) DEFAULT NULL,
  `water_source_other` varchar(255) DEFAULT NULL,
  `toilet_facilities` varchar(255) DEFAULT NULL,
  `toilet_other` varchar(255) DEFAULT NULL,
  `household_gardening` varchar(255) DEFAULT NULL,
  `gardening_other` varchar(255) DEFAULT NULL,
  `pets` varchar(255) DEFAULT NULL,
  `pets_other` varchar(255) DEFAULT NULL,
  `prone_to_flooding` tinyint(1) DEFAULT NULL,
  `monitors_flood_updates` varchar(50) DEFAULT NULL,
  `has_cctv` tinyint(1) DEFAULT NULL,
  `avg_sleep_hours` varchar(20) DEFAULT NULL,
  `sleep_hours_other` varchar(255) DEFAULT NULL,
  `poor_sleep_reasons` varchar(255) DEFAULT NULL,
  `poor_sleep_reasons_other` varchar(255) DEFAULT NULL,
  `avg_meals_per_day` int DEFAULT NULL,
  `nutrition_concerns` tinyint(1) DEFAULT NULL,
  `weekly_food_budget` decimal(10,2) DEFAULT NULL,
  `food_types_purchased` text,
  `children_snacks` text,
  `food_storage` varchar(80) DEFAULT NULL,
  `food_storage_other` varchar(255) DEFAULT NULL,
  `health_center_visit_reason` varchar(80) DEFAULT NULL,
  `health_center_visit_other` varchar(255) DEFAULT NULL,
  `first_consulted` varchar(80) DEFAULT NULL,
  `first_consulted_other` varchar(255) DEFAULT NULL,
  `family_skipped_consult` tinyint(1) DEFAULT NULL,
  `skip_consult_reasons` varchar(255) DEFAULT NULL,
  `skip_consult_reasons_other` varchar(255) DEFAULT NULL,
  `has_healthcare_access` tinyint(1) DEFAULT NULL,
  `has_health_insurance` tinyint(1) DEFAULT NULL,
  `health_insurance_detail` varchar(255) DEFAULT NULL,
  `has_illness` tinyint(1) DEFAULT NULL,
  `illness_detail` varchar(255) DEFAULT NULL,
  `has_pregnant_member` tinyint(1) DEFAULT NULL,
  `pregnancy_age` varchar(100) DEFAULT NULL,
  `had_prenatal` tinyint(1) DEFAULT NULL,
  `prenatal_provider` varchar(255) DEFAULT NULL,
  `had_recent_birth` tinyint(1) DEFAULT NULL,
  `delivery_attendant` varchar(80) DEFAULT NULL,
  `delivery_attendant_other` varchar(255) DEFAULT NULL,
  `place_of_delivery` varchar(80) DEFAULT NULL,
  `place_of_delivery_other` varchar(255) DEFAULT NULL,
  `baby_vaccinated` tinyint(1) DEFAULT NULL,
  `vaccinations_received` text,
  `all_children_enrolled` tinyint(1) DEFAULT NULL,
  `no_enrollment_reasons` varchar(255) DEFAULT NULL,
  `no_enrollment_other` varchar(255) DEFAULT NULL,
  `school_aged_children` int DEFAULT NULL,
  `children_enrolled` int DEFAULT NULL,
  `income_sources` varchar(255) DEFAULT NULL,
  `income_sources_other` varchar(255) DEFAULT NULL,
  `income_sufficient` tinyint(1) DEFAULT NULL,
  `govt_assistance` varchar(80) DEFAULT NULL,
  `govt_assistance_other` varchar(255) DEFAULT NULL,
  `in_community_org` tinyint(1) DEFAULT NULL,
  `community_org_detail` varchar(255) DEFAULT NULL,
  `has_senior_citizen` tinyint(1) DEFAULT NULL,
  `has_pwd` tinyint(1) DEFAULT NULL,
  `pwd_detail` varchar(255) DEFAULT NULL,
  `transport_mode` varchar(80) DEFAULT NULL,
  `transport_mode_other` varchar(255) DEFAULT NULL,
  `devices_at_home` varchar(255) DEFAULT NULL,
  `devices_other` varchar(255) DEFAULT NULL,
  `final_observation` text,
  `name_of_interviewee` varchar(255) DEFAULT NULL,
  `name_of_interviewer` varchar(255) DEFAULT NULL,
  `interviewer_position` enum('Student','Employee','Resident') DEFAULT NULL,
  `date_of_interview` date DEFAULT NULL,
  `DateCreated` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `DateUpdated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_removed` tinyint(1) NOT NULL DEFAULT 0,
  `removal_reason` text,
  `removed_at` datetime DEFAULT NULL,
  `household_name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`SurveyID`),
  UNIQUE KEY `uq_household_survey_household_id` (`HouseholdID`),
  KEY `idx_household_survey_resident` (`ResidentID`),
  CONSTRAINT `fk_caps_household_survey_resident`
    FOREIGN KEY (`ResidentID`) REFERENCES `residents` (`ResidentID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `household_survey_members` (
  `MemberID` int unsigned NOT NULL AUTO_INCREMENT,
  `SurveyID` int unsigned NOT NULL,
  `ResidentID` int unsigned DEFAULT NULL,
  `MemberNumber` tinyint unsigned NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `sex` enum('Male','Female') DEFAULT NULL,
  `age` int DEFAULT NULL,
  `relationship` varchar(100) DEFAULT NULL,
  `civil_status` varchar(30) DEFAULT NULL,
  `civil_status_other` varchar(255) DEFAULT NULL,
  `education` varchar(255) DEFAULT NULL,
  `monthly_income` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`MemberID`),
  KEY `idx_household_survey_members_survey` (`SurveyID`),
  KEY `idx_household_survey_members_resident` (`ResidentID`),
  CONSTRAINT `fk_caps_household_member_survey`
    FOREIGN KEY (`SurveyID`) REFERENCES `household_survey` (`SurveyID`) ON DELETE CASCADE,
  CONSTRAINT `fk_caps_household_member_resident`
    FOREIGN KEY (`ResidentID`) REFERENCES `residents` (`ResidentID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `officials` (
  `OfficialID` int unsigned NOT NULL AUTO_INCREMENT,
  `ResidentID` int unsigned NOT NULL,
  `Position` varchar(120) NOT NULL,
  `TermStart` date NOT NULL,
  `TermEnd` date NOT NULL,
  `Photo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `RecordedBy`      varchar(255) DEFAULT NULL,   -- who added the official
  `RecordedByID`    varchar(50)  DEFAULT NULL,
  `ExpectedTermEnd` date         DEFAULT NULL,   -- original TermEnd before End Term
  `ActualEndDate`   date         DEFAULT NULL,   -- date the term was ended
  `EndReason`       varchar(255) DEFAULT NULL,
  `EndedBy`         varchar(255) DEFAULT NULL,
  `EndedByID`       varchar(50)  DEFAULT NULL,
  `EndedAt`         datetime     DEFAULT NULL,
  PRIMARY KEY (`OfficialID`),
  KEY `idx_official_resident` (`ResidentID`),
  KEY `idx_official_position_term` (`Position`,`TermEnd`),
  CONSTRAINT `fk_official_resident` FOREIGN KEY (`ResidentID`) REFERENCES `residents` (`ResidentID`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `official_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `history_year` smallint unsigned NOT NULL,
  `source_type` enum('official','support') NOT NULL,
  `source_key` varchar(100) NOT NULL,
  `resident_id` int unsigned DEFAULT NULL,
  `employee_id` varchar(50) DEFAULT NULL,
  `full_name` varchar(255) NOT NULL,
  `position` varchar(120) DEFAULT NULL,
  `term_start` date DEFAULT NULL,
  `term_end` date DEFAULT NULL,
  `snapshot_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_history_snapshot` (`history_year`,`source_type`,`source_key`),
  KEY `idx_history_year` (`history_year`),
  KEY `idx_history_resident` (`resident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS tanod_schedules (
 id int NOT NULL AUTO_INCREMENT,
 ResidentID int NOT NULL,
 schedule_date date NOT NULL,
 shift_start time NOT NULL,
 shift_end time NOT NULL,
 required_count int NOT NULL DEFAULT 1,
 created_at timestamp NULL DEFAULT CURRENT_TIMESTAMP,
 status varchar(20) NOT NULL DEFAULT 'Scheduled',
 time_in datetime DEFAULT NULL,
 time_out datetime DEFAULT NULL,
 area varchar(255) DEFAULT NULL,
 notes text,
 created_by int DEFAULT NULL,
 updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `time_in_by`       varchar(255) DEFAULT NULL,
  `time_in_by_id`    varchar(50)  DEFAULT NULL,
  `time_out_by`      varchar(255) DEFAULT NULL,
  `time_out_by_id`   varchar(50)  DEFAULT NULL,
  `is_early_out`     tinyint(1)   NOT NULL DEFAULT 0,
  `early_out_reason` varchar(255) DEFAULT NULL,
  `absent_reason`    varchar(255) DEFAULT NULL,
  `absent_at`        datetime     DEFAULT NULL,
  `absent_by`        varchar(255) DEFAULT NULL,
  `absent_by_id`     varchar(50)  DEFAULT NULL,
 PRIMARY KEY(id), KEY idx_tanod_resident(ResidentID), KEY idx_tanod_date(schedule_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `complaints` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `complaint_id` varchar(30) NOT NULL,
  `resident_id` int unsigned DEFAULT NULL,
  `purok` varchar(100) DEFAULT NULL,
  `is_anonymous` tinyint(1) NOT NULL DEFAULT 0,
  `complainant_name` varchar(255) DEFAULT NULL,
  `category` varchar(100) NOT NULL,
  `other_category_specify` varchar(255) DEFAULT NULL,
  `address_location` text NOT NULL,
  `priority_level` enum('Low','Medium','High (Urgent)') NOT NULL DEFAULT 'Medium',
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `attachment_path` varchar(500) DEFAULT NULL,
  `status` enum('Pending','Ongoing','Resolved') NOT NULL DEFAULT 'Pending',
  `admin_reply` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notif_read` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_complaint_id` (`complaint_id`),
  KEY `idx_complaint_resident` (`resident_id`),
  KEY `idx_complaint_status` (`status`),
  KEY `idx_complaint_category` (`category`),
  KEY `idx_complaint_created` (`created_at`),
  CONSTRAINT `fk_complaints_resident`
    FOREIGN KEY (`resident_id`) REFERENCES `residents` (`ResidentID`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `announcements` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ann_id` int unsigned DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` enum('General','Health Advisory','Health','Community Event','Emergency Notice','Others') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'General',
  `category_other` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('Published','Draft','Scheduled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Published',
  `date_posted` date NOT NULL,
  `date_start` date DEFAULT NULL,
  `date_end` date DEFAULT NULL,
  `time_start` time DEFAULT NULL,
  `time_end` time DEFAULT NULL,
  `sms_sent` tinyint(1) NOT NULL DEFAULT '0',
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deleted_by_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deleted_by_role` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status_before_delete` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Active | Scheduled | Ended | Expired | Draft - state when moved to Trash',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  `fb_post_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fb_pending` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = post to Facebook when scheduled time is reached',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_dates` (`date_start`,`date_end`),
  KEY `idx_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: announcement_attachments
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `announcement_attachments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `announcement_id` int unsigned NOT NULL,
  `original_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_ext` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int unsigned NOT NULL DEFAULT '0',
  `is_image` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ann_id` (`announcement_id`),
  CONSTRAINT `fk_att_ann` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: disaster_alerts  (ann.php's Disaster Analytics/Active Disaster cards,
-- process_disaster.php's "Issue/Edit/Deactivate Alert" flow, disaster.php)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `disaster_alerts` (
  `AlertID` int NOT NULL AUTO_INCREMENT,
  `Type` varchar(50) DEFAULT NULL,
  `Severity` varchar(20) DEFAULT NULL,
  `Title` varchar(255) DEFAULT NULL,
  `Message` text,
  `IncidentLocation` varchar(255) DEFAULT NULL COMMENT 'Required for Fire and Flood alert types',
  `HazardLat` decimal(10,6) DEFAULT NULL COMMENT 'Pinned hazard latitude (Fire/Flood only)',
  `HazardLng` decimal(10,6) DEFAULT NULL COMMENT 'Pinned hazard longitude (Fire/Flood only)',
  `HazardRadius` int unsigned DEFAULT '200' COMMENT 'Hazard radius in metres (Fire/Flood only)',
  `linked_hazard_id` int DEFAULT NULL COMMENT 'FK -> hazards.id for auto-created hazard zone',
  `EvacuationCenter` varchar(255) DEFAULT NULL,
  `Status` varchar(20) DEFAULT NULL,
  `CreatedAt` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `notify_app` tinyint(1) DEFAULT '0',
  `notify_sms` tinyint(1) DEFAULT '0',
  `notif_read_global` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Admin-level read flag',
  `notif_read` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`AlertID`),
  KEY `idx_da_linked_hazard` (`linked_hazard_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: disaster_reports  (ann.php's Disaster Reports table/printout,
-- disaster_reports.php, disaster_ai_summary.php)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `disaster_reports` (
  `ReportID` int NOT NULL AUTO_INCREMENT,
  `ReportNo` varchar(20) DEFAULT NULL,
  `AlertID` int DEFAULT NULL,
  `Type` varchar(100) DEFAULT NULL,
  `Title` varchar(255) DEFAULT NULL,
  `AffectedResidents` int DEFAULT NULL,
  `Evacuees` int DEFAULT NULL,
  `Injuries` int DEFAULT NULL,
  `Casualties` int DEFAULT NULL,
  `PropertyDamage` text,
  `ResponseActions` text,
  `Status` varchar(50) DEFAULT NULL,
  `DutyOfficer` varchar(255) DEFAULT NULL,
  `CreatedAt` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ReportID`),
  UNIQUE KEY `uniq_disaster_report_no` (`ReportNo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: hazards  (disaster.php's Risk Map pins; disaster_alerts.linked_hazard_id
-- points here)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hazards` (
  `id` int NOT NULL AUTO_INCREMENT,
  `Title` varchar(255) NOT NULL,
  `Type` enum('Flood','Structural','Safe Point','Fire','Earthquake') NOT NULL,
  `Description` text,
  `Lat` decimal(10,8) NOT NULL,
  `Lng` decimal(11,8) NOT NULL,
  `Radius` int DEFAULT '100' COMMENT 'Radius in meters',
  `CreatedAt` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `Severity` tinyint unsigned DEFAULT NULL COMMENT '1=Low 2=Medium 3=High 4=Critical',
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_hz_deleted` (`is_deleted`,`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: sms_logs  (ann.php's "Live SMS Status Panel"; written by
-- disaster_sms_worker.php)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sms_logs` (
  `LogID` int NOT NULL AUTO_INCREMENT,
  `AlertID` int DEFAULT NULL,
  `total_recipients` int DEFAULT NULL,
  `sent_count` int DEFAULT '0',
  `status` varchar(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`LogID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: sms_queue  (async job queue: process_disaster.php inserts,
-- disaster_sms_worker.php claims/processes)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sms_queue` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `alert_id` int DEFAULT NULL COMMENT 'FK -> disaster_alerts.AlertID',
  `log_id` int DEFAULT NULL COMMENT 'FK -> sms_logs.LogID',
  `action` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'create' COMMENT 'create | update',
  `recipients` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'JSON array of {ResidentID, ContactNumber}',
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Full SMS message body',
  `total` int unsigned NOT NULL DEFAULT '0' COMMENT 'Total recipients count',
  `sent` int unsigned NOT NULL DEFAULT '0' COMMENT 'Successfully sent count',
  `failed` int unsigned NOT NULL DEFAULT '0' COMMENT 'Failed count',
  `status` enum('pending','processing','done','failed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_error` varchar(255) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `heartbeat_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sms_queue_log` (`log_id`),
  KEY `idx_status` (`status`),
  KEY `idx_alert_id` (`alert_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Async SMS job queue for disaster alert broadcasting';

-- ----------------------------------------------------------------------------
-- Table: sms_configurations  (Settings → SMS Configuration; read by
-- announcement/backend/process_disaster.php and disaster_sms_worker.php)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sms_configurations` (
  `id`                 int unsigned NOT NULL AUTO_INCREMENT,
  `configuration_name` varchar(100) NOT NULL,
  `api_key`            varchar(255) NOT NULL,
  `from_number`        varchar(30)  NOT NULL,
  `device_id`          varchar(100) NOT NULL,
  `api_url`            varchar(500) NOT NULL DEFAULT 'https://api.infinireach.io/api/v1/messages',
  `status`             enum('Active','Inactive') NOT NULL DEFAULT 'Inactive',
  `created_at`         timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
--  Barangay DB — Officials + Staff/BPSO module revision
--  Run AFTER the original "Rebuild Database" script (schema changes only,
--  no sample data). Brings the original DB up to the revised modules:
--    * Officials   : End Term audit trail (who recorded / ended, when, why)
--    * Staff/BPSO  : End Term audit trail, work schedule, position history,
--                    BPSO duty (Time In / Deploy / Return / Time Out / Absent),
--                    Staff attendance
-- ============================================================================


-- ----------------------------------------------------------------------------
-- 1. OFFICIALS — term audit trail
-- ----------------------------------------------------------------------------
-- (`officials` revision columns merged into CREATE TABLE IF NOT EXISTS above)

-- ----------------------------------------------------------------------------
-- 2. STAFF — assignment term, audit trail, work schedule
-- ----------------------------------------------------------------------------
-- (`staff` revision columns merged into CREATE TABLE IF NOT EXISTS above)

-- Earlier positions of a staff record (closed when Edit changes the Position).
CREATE TABLE IF NOT EXISTS `staff_position_history` (
  `id`         int          NOT NULL AUTO_INCREMENT,
  `EmployeeID` varchar(50)  NOT NULL,
  `ResidentID` varchar(20)  DEFAULT NULL,
  `Name`       varchar(255) DEFAULT NULL,
  `Position`   varchar(100) DEFAULT NULL,
  `StartDate`  date         DEFAULT NULL,
  `EndDate`    date         DEFAULT NULL,
  `EndReason`  varchar(255) DEFAULT NULL,
  `RecordedBy` varchar(255) DEFAULT NULL,
  `RecordedAt` datetime     DEFAULT NULL,
  `EndedBy`    varchar(255) DEFAULT NULL,
  `EndedByID`  varchar(50)  DEFAULT NULL,
  `EndedAt`    datetime     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sph_employee` (`EmployeeID`),
  KEY `idx_sph_resident` (`ResidentID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Regular Barangay Staff attendance (one row per staff per day).
CREATE TABLE IF NOT EXISTS `staff_attendance` (
  `id`               int          NOT NULL AUTO_INCREMENT,
  `EmployeeID`       varchar(50)  NOT NULL,
  `attendance_date`  date         NOT NULL,
  `shift_start`      time         NOT NULL,
  `shift_end`        time         NOT NULL,
  `status`           varchar(20)  NOT NULL DEFAULT 'Scheduled',  -- Scheduled / On Duty / Completed / Early Out / Absent
  `time_in`          datetime     DEFAULT NULL,
  `time_in_by`       varchar(255) DEFAULT NULL,
  `time_in_by_id`    varchar(50)  DEFAULT NULL,
  `time_out`         datetime     DEFAULT NULL,
  `time_out_by`      varchar(255) DEFAULT NULL,
  `time_out_by_id`   varchar(50)  DEFAULT NULL,
  `is_early_out`     tinyint(1)   NOT NULL DEFAULT 0,
  `early_out_reason` varchar(255) DEFAULT NULL,
  `absent_reason`    varchar(255) DEFAULT NULL,
  `absent_at`        datetime     DEFAULT NULL,
  `absent_by`        varchar(255) DEFAULT NULL,
  `absent_by_id`     varchar(50)  DEFAULT NULL,
  `created_at`       timestamp    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_attendance_day` (`EmployeeID`,`attendance_date`),
  KEY `idx_staff_attendance_date` (`attendance_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. BPSO / TANOD DUTY — who did each action, Early Out, Absent
-- ----------------------------------------------------------------------------
-- (`tanod_schedules` revision columns merged into CREATE TABLE IF NOT EXISTS above)

-- One row per Deploy -> Return cycle (a duty can have several).
CREATE TABLE IF NOT EXISTS `tanod_deployments` (
  `id`             int          NOT NULL AUTO_INCREMENT,
  `schedule_id`    int          NOT NULL,                 -- tanod_schedules.id
  `location`       varchar(255) NOT NULL,
  `reason`         varchar(255) NOT NULL,
  `deployed_at`    datetime     NOT NULL,
  `deployed_by`    varchar(255) DEFAULT NULL,
  `deployed_by_id` varchar(50)  DEFAULT NULL,
  `returned_at`    datetime     DEFAULT NULL,
  `returned_by`    varchar(255) DEFAULT NULL,
  `returned_by_id` varchar(50)  DEFAULT NULL,
  `created_at`     timestamp    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tanod_deployments_schedule` (`schedule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (`sms_queue` log_id / last_error / created_by / heartbeat_at / 'cancelled' merged into CREATE TABLE above)

-- 2) Per-resident SMS log (was only ALTERed here before it existed → "Table 'sms_recipient_logs' doesn't exist"
--    stopped the whole import, so every table after this point was never created). Full table instead:
CREATE TABLE IF NOT EXISTS `sms_recipient_logs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `log_id` int DEFAULT NULL COMMENT 'FK -> sms_logs.LogID',
  `alert_id` int DEFAULT NULL COMMENT 'FK -> disaster_alerts.AlertID',
  `resident_id` int unsigned DEFAULT NULL COMMENT 'FK -> residents.ResidentID',
  `resident_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `area_label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_number` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pending','processing','retry','sent','failed','invalid','no_number','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `detail` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `delivery` enum('pending','confirmed','failed') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checked_at` datetime DEFAULT NULL,
  `attempts` tinyint unsigned NOT NULL DEFAULT '0',
  `last_attempt_at` datetime(3) DEFAULT NULL,
  `next_attempt_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_srl_alert_resident` (`alert_id`,`resident_id`),
  KEY `idx_srl_log_status` (`log_id`,`status`),
  KEY `idx_srl_log_attempt` (`log_id`,`last_attempt_at`),
  KEY `idx_srl_alert` (`alert_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  Barangay DB — Certificate module revision (run AFTER Rebuild_Database)
--  Optional: the module creates/updates all of this by itself on first load
--  (cert_common.php → cert_migrate). Use this file if you prefer to set up
--  the database by hand. Works on MySQL 8 (Workbench) and MariaDB (XAMPP).
-- ============================================================================

-- A. Shared ID numbering: PREFIX-YEAR-#### (DOC-2026-0001, REF-2026-0001 ...)
CREATE TABLE IF NOT EXISTS `id_sequences` (
  `prefix`     VARCHAR(10) NOT NULL,
  `year`       SMALLINT UNSIGNED NOT NULL,
  `last_value` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`prefix`, `year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- B. Document types (Save Draft is real now)
CREATE TABLE IF NOT EXISTS `custom_document_types` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `doc_type` VARCHAR(100) NOT NULL,
  `doc_code` VARCHAR(20) NULL,
  `description` VARCHAR(500) NULL,
  `icon` VARCHAR(50) NOT NULL DEFAULT 'description',
  `color` VARCHAR(20) NOT NULL DEFAULT 'blue',
  `default_body` LONGTEXT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_draft` TINYINT(1) NOT NULL DEFAULT 0,          -- 1 = "Not finished": hidden from Issue Walk-In / online
  `draft_step` TINYINT NOT NULL DEFAULT 1,           -- wizard step to resume at
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_custom_doc_type` (`doc_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- B. Templates: Prefilled only + paper size
CREATE TABLE IF NOT EXISTS `certificate_templates` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `doc_type` VARCHAR(100) NOT NULL DEFAULT 'General',
  `template_name` VARCHAR(100) NOT NULL DEFAULT 'Default',
  `header_text` LONGTEXT NULL,
  `body_text` LONGTEXT NULL,
  `footer_text` LONGTEXT NULL,
  `background_image_path` VARCHAR(500) NULL,
  `bg_opacity` DECIMAL(3,2) NOT NULL DEFAULT 1.00,
  `paper_size` VARCHAR(20) NOT NULL DEFAULT 'a4',    -- letter | a4 | long (8.5x13) | legal (8.5x14)
  `custom_layout_elements` LONGTEXT NULL,            -- old "Custom Layout" docs: printed read-only
  `layout_json` LONGTEXT NULL,                       -- {"selected":[field keys]}
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_template_name` (`template_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `certificate_field_positions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `template_id` INT NOT NULL,
  `field_key` VARCHAR(80) NOT NULL,
  `field_label` VARCHAR(150) NOT NULL,
  `pos_x` DECIMAL(6,2) NOT NULL DEFAULT 50.00,       -- % of page width
  `pos_y` DECIMAL(6,2) NOT NULL DEFAULT 50.00,       -- % of page height (middle of the line)
  `width` DECIMAL(6,2) NULL,                         -- % of page width, NULL = auto
  `font_size` INT NOT NULL DEFAULT 14,
  `font_weight` VARCHAR(20) DEFAULT 'normal',
  `text_align` VARCHAR(20) DEFAULT 'center',
  `text_color` VARCHAR(20) DEFAULT '#000000',
  `uppercase` TINYINT(1) NOT NULL DEFAULT 0,
  `is_visible` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_template_field` (`template_id`, `field_key`),
  KEY `idx_cfp_template` (`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_requirements` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `doc_type` VARCHAR(100) NOT NULL,
  `requirement` VARCHAR(255) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_doc_type` (`doc_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- B. Extra Information Fields (saved in the DB now)
CREATE TABLE IF NOT EXISTS `document_extra_fields` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `doc_type` VARCHAR(100) NOT NULL,
  `field_key` VARCHAR(60) NOT NULL,
  `label` VARCHAR(150) NOT NULL,
  `input_type` ENUM('text','number','date','textarea','select') NOT NULL DEFAULT 'text',
  `options` LONGTEXT NULL,                           -- JSON array for select
  `is_required` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doc_extra_field` (`doc_type`, `field_key`),
  KEY `idx_def_doc_type` (`doc_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- C/D. Requests (every column in one CREATE — no ALTER needed)
CREATE TABLE IF NOT EXISTS `document_requests` (
  `RequestID` INT NOT NULL AUTO_INCREMENT,
  `ResidentID` INT NULL,
  `ReferenceNo` VARCHAR(50) NULL,                    -- REF-2026-0001
  `doc_number` VARCHAR(50) NULL,                     -- DOC-2026-0001 (on Generate / Accept)
  `DocType` VARCHAR(100) NULL,
  `Purpose` TEXT NULL,
  `business_name` VARCHAR(255) NULL,                 -- old SOE columns, kept readable
  `business_address` VARCHAR(500) NULL,
  `nature_of_business` VARCHAR(255) NULL,
  `years_of_residency` VARCHAR(20) NULL,
  `employment_purpose` VARCHAR(255) NULL,
  `photo_path` VARCHAR(500) NULL,
  `requirements_checked` LONGTEXT NULL,              -- JSON list
  `extra_data` LONGTEXT NULL,                        -- JSON {field_key: value}
  `layout_override` LONGTEXT NULL,                   -- JSON positions for THIS document only
  `render_snapshot` LONGTEXT NULL,                   -- JSON layout + values frozen at generation
  `generated_at` DATETIME NULL,
  `generated_by` VARCHAR(150) NULL,
  `previewed_at` DATETIME NULL,
  `printed_at` DATETIME NULL,
  `printed_by` VARCHAR(150) NULL,
  `released_by` VARCHAR(150) NULL,
  `Status` VARCHAR(30) NOT NULL DEFAULT 'Pending',   -- Pending|Review|Rejected|Ready to Pick Up|Preview|Released|Expired
  `request_type` ENUM('walk-in','online') NOT NULL DEFAULT 'online',
  `release_date` DATETIME NULL,
  `release_time` TIME NULL,
  `DateRequested` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `online_submitted_at` DATETIME NULL,
  `reviewed_at` DATETIME NULL,
  `reviewed_by` VARCHAR(150) NULL,
  `approved_at` DATETIME NULL,                       -- expiry counts 15 days from here
  `approved_by` VARCHAR(150) NULL,
  `rejected_at` DATETIME NULL,
  `rejected_by` VARCHAR(150) NULL,
  `rejection_reason` VARCHAR(500) NULL,
  `expired_at` DATETIME NULL,
  `blotter_cases` INT NOT NULL DEFAULT 0,
  `blotter_override_by` VARCHAR(150) NULL,           -- who proceeded despite an active blotter
  `blotter_override_at` DATETIME NULL,
  `Remarks` TEXT NULL,
  `PickupDate` DATE NULL,
  `DateCreated` DATETIME NULL,
  `notif_read` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`RequestID`),
  KEY `idx_reference_no` (`ReferenceNo`),
  KEY `idx_request_type` (`request_type`),
  KEY `idx_status` (`Status`),
  KEY `idx_ld_resident` (`ResidentID`),
  KEY `idx_ld_doctype` (`DocType`),
  KEY `idx_ld_date` (`DateRequested`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- D. Status log shown in View
CREATE TABLE IF NOT EXISTS `document_request_logs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `request_id` INT NOT NULL,
  `status` VARCHAR(30) NOT NULL,
  `note` VARCHAR(500) NULL,
  `by_name` VARCHAR(150) NULL,
  `by_id` VARCHAR(50) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_drl_request` (`request_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Online requirement uploads (resident portal)
CREATE TABLE IF NOT EXISTS `document_request_files` (
  `FileID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `RequestID` INT NOT NULL,
  `RequirementLabel` VARCHAR(255) NOT NULL,
  `FilePath` VARCHAR(500) NOT NULL,
  `FileType` VARCHAR(100) NULL,
  `UploadedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`FileID`),
  KEY `idx_drf_request` (`RequestID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Resident notifications (Accept / Reject / Released)
CREATE TABLE IF NOT EXISTS `resident_notifications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `resident_id` INT NOT NULL,
  `notif_type` VARCHAR(50) NOT NULL DEFAULT 'system',
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `ref_table` VARCHAR(80) NULL,
  `ref_id` INT UNSIGNED NULL,
  `action_url` VARCHAR(500) NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `read_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_resident_unread` (`resident_id`, `is_read`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  BLOTTER / CASE MANAGEMENT — complete database (final, all revisions merged)
--  Every table and column the updated admin/blotter module uses, plus the
--  default settings, incident types and location.
--
--  In the Rebuild script: DELETE the old block
--      "-- Blotter (same structure as the SOE blotter module ...)"
--      CREATE TABLE IF NOT EXISTS `blotter` ( ... );
--  and paste this whole file in its place.
--  (Needs the tables already in the Rebuild script: residents, officials, staff,
--   id_sequences, barangay_profile.)
--
--  An EXISTING database does not need this file: the Blotter page upgrades it
--  by itself (sql/blotter.sql). This file is for a fresh rebuild.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. blotter — one row per case (Blotter ID BLO-YYYY-####). Replaces the old `blotter` table of the Rebuild script.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `blotter` (
  `BlotterID` int NOT NULL AUTO_INCREMENT,
  `CaseNumber` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ComplainantID` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `RespondentID` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `IncidentType` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `IncidentTypeOther` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Narrative` text COLLATE utf8mb4_unicode_ci,
  `EvidencePath` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `IncidentDate` date DEFAULT NULL,
  `IncidentTime` time DEFAULT NULL,
  `Status` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT 'Filed',
  `CurrentStage` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Initial',
  `HearingCount` int DEFAULT NULL,
  `Phase` tinyint NOT NULL DEFAULT '1',
  `TransferLocation` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `AssignedOfficer` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `HearingDate` date DEFAULT NULL,
  `HearingTime` time DEFAULT NULL,
  `Details` text COLLATE utf8mb4_unicode_ci,
  `CreatedAt` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `CaseYear` smallint unsigned DEFAULT NULL,
  `CaseSeq` int unsigned DEFAULT NULL,
  `ComplainantName` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `RespondentName` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `LocationOption` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `LocationOther` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `HearingLocation` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `RuleType` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'hearing',
  `MaxDays` int DEFAULT NULL,
  `MaxHearings` int DEFAULT NULL,
  `DeadlineDate` date DEFAULT NULL,
  `TransferDestination` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `TransferredAt` datetime DEFAULT NULL,
  `ResolvedAt` datetime DEFAULT NULL,
  `ClosedAt` datetime DEFAULT NULL,
  `CancelledAt` datetime DEFAULT NULL,
  `CancelReason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `FiledAt` datetime DEFAULT NULL,
  `FiledBy` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `FiledByID` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `UpdatedBy` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ComplaintSignedAt` datetime DEFAULT NULL,
  `ComplaintSignedBy` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ComplaintSignedFile` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ComplaintSignedName` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ComplaintSignedType` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `TransferRecordAt` datetime DEFAULT NULL,
  `TransferRecordBy` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `TransferRecordFile` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `TransferRecordName` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `TransferRecordType` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`BlotterID`),
  KEY `idx_blotter_status` (`Status`),
  KEY `idx_blotter_complainant` (`ComplainantID`),
  KEY `idx_blotter_respondent` (`RespondentID`),
  KEY `idx_blotter_case_number` (`CaseNumber`),
  KEY `idx_blotter_filed` (`FiledAt`),
  KEY `idx_blotter_type` (`IncidentType`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. Settings — case limit rule, transfer destination + transfer letter template, minutes per hearing
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `blotter_settings` (
  `id` tinyint unsigned NOT NULL,
  `rule_type` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'hearing',
  `max_days` int NOT NULL DEFAULT '15',
  `max_hearings` int NOT NULL DEFAULT '3',
  `noshow_counts` tinyint(1) NOT NULL DEFAULT '1',
  `transfer_destination` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BPSO',
  `transfer_destination_other` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transfer_template_id` int unsigned DEFAULT NULL,
  `default_hearing_location` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Barangay Hall',
  `hearing_minutes` int NOT NULL DEFAULT '60',
  `updated_by` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. Incident types (File New Case)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `blotter_incident_types` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_blotter_incident_type` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. Extra incident locations
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `blotter_locations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_blotter_location` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. Complainants / respondents of a case
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `blotter_parties` (
  `party_id` int unsigned NOT NULL AUTO_INCREMENT,
  `blotter_id` int NOT NULL,
  `role` enum('Complainant','Respondent') COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int NOT NULL DEFAULT '0',
  `party_type` enum('Resident','Non-Resident','Unknown') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Resident',
  `resident_id` int unsigned DEFAULT NULL,
  `first_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `middle_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `suffix` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alias` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sex` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `contact_number` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `house_street` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `region_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `region_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `province_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `province_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `municipality_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `municipality_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `barangay_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `barangay_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `full_address` varchar(600) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `physical_description` text COLLATE utf8mb4_unicode_ci,
  `clothing` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vehicle` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `plate_number` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `other_details` text COLLATE utf8mb4_unicode_ci,
  `is_identified` tinyint(1) NOT NULL DEFAULT '1',
  `identified_at` datetime DEFAULT NULL,
  `created_by` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`party_id`),
  KEY `idx_bp_blotter` (`blotter_id`,`role`),
  KEY `idx_bp_resident` (`resident_id`),
  KEY `idx_bp_plate` (`plate_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. Previous respondent details (Update Respondent)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `blotter_party_history` (
  `history_id` int unsigned NOT NULL AUTO_INCREMENT,
  `party_id` int unsigned NOT NULL,
  `blotter_id` int NOT NULL,
  `previous_data` longtext COLLATE utf8mb4_unicode_ci,
  `new_data` longtext COLLATE utf8mb4_unicode_ci,
  `changed_fields` varchar(600) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_by` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_by_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_bph_blotter` (`blotter_id`),
  KEY `idx_bph_party` (`party_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7. Evidence files
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `blotter_attachments` (
  `attachment_id` int unsigned NOT NULL AUTO_INCREMENT,
  `blotter_id` int NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_ext` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int unsigned NOT NULL DEFAULT '0',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `uploaded_by` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_by_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`attachment_id`),
  KEY `idx_ba_blotter` (`blotter_id`,`is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 8. Hearings
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `blotter_hearings` (
  `hearing_id` int unsigned NOT NULL AUTO_INCREMENT,
  `blotter_id` int NOT NULL,
  `hearing_no` int NOT NULL,
  `hearing_date` date NOT NULL,
  `hearing_time` time NOT NULL,
  `location` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `agenda` text COLLATE utf8mb4_unicode_ci,
  `status` enum('Scheduled','Completed','Cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Scheduled',
  `cancel_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notice_required` tinyint(1) NOT NULL DEFAULT '0',
  `created_by` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`hearing_id`),
  UNIQUE KEY `uq_bh_case_no` (`blotter_id`,`hearing_no`),
  KEY `idx_bh_status_date` (`status`,`hearing_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 9. Hearing results (attendance, presided by, personnel …)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `blotter_hearing_results` (
  `result_id` int unsigned NOT NULL AUTO_INCREMENT,
  `hearing_id` int unsigned NOT NULL,
  `blotter_id` int NOT NULL,
  `outcome` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `counts_toward_limit` tinyint(1) NOT NULL DEFAULT '0',
  `next_status` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recorded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `recorded_by` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recorded_by_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `auto_recorded` tinyint(1) NOT NULL DEFAULT '0',
  `attendance` longtext COLLATE utf8mb4_unicode_ci,
  `companions` longtext COLLATE utf8mb4_unicode_ci,
  `presided_by` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `presided_position` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signed_file_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signed_file_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signed_file_type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signed_uploaded_at` datetime DEFAULT NULL,
  `signed_uploaded_by` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `personnel` longtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`result_id`),
  UNIQUE KEY `uq_bhr_hearing` (`hearing_id`),
  KEY `idx_bhr_blotter` (`blotter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 10. Settlement / kasunduan
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `blotter_resolutions` (
  `resolution_id` int unsigned NOT NULL AUTO_INCREMENT,
  `blotter_id` int NOT NULL,
  `hearing_id` int unsigned DEFAULT NULL,
  `resolution_details` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `resolved_at` datetime NOT NULL,
  `recorded_by` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recorded_by_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recorded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`resolution_id`),
  KEY `idx_br_blotter` (`blotter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 11. Transfers (with proof)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `blotter_transfers` (
  `transfer_id` int unsigned NOT NULL AUTO_INCREMENT,
  `blotter_id` int NOT NULL,
  `transfer_date` date NOT NULL,
  `transfer_time` time NOT NULL,
  `destination` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `reference_no` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `receiving_officer` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `receiving_details` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transferred_by` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transferred_by_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `proof_file_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `proof_file_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `proof_file_type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`transfer_id`),
  KEY `idx_bt_blotter` (`blotter_id`),
  KEY `idx_bt_date` (`transfer_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 12. Notice templates (uploaded form or Word-like text letter)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `blotter_notice_templates` (
  `template_id` int unsigned NOT NULL AUTO_INCREMENT,
  `notice_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mode` enum('text','image') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  `background_image_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_pdf_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `page_images` longtext COLLATE utf8mb4_unicode_ci,
  `extra_fields` longtext COLLATE utf8mb4_unicode_ci,
  `bg_opacity` decimal(3,2) NOT NULL DEFAULT '1.00',
  `paper_size` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'a4',
  `text_config` longtext COLLATE utf8mb4_unicode_ci,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_by` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`template_id`),
  UNIQUE KEY `uq_bnt_type_name` (`notice_type`,`name`),
  KEY `idx_bnt_type` (`notice_type`,`is_active`,`is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 13. Field positions of uploaded-form templates
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `blotter_template_fields` (
  `field_id` int unsigned NOT NULL AUTO_INCREMENT,
  `template_id` int unsigned NOT NULL,
  `field_key` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `field_label` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `pos_x` decimal(6,2) NOT NULL DEFAULT '50.00',
  `pos_y` decimal(6,2) NOT NULL DEFAULT '50.00',
  `width` decimal(6,2) DEFAULT NULL,
  `font_size` int NOT NULL DEFAULT '14',
  `font_family` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Times New Roman',
  `font_weight` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `font_style` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `underline` tinyint(1) NOT NULL DEFAULT '0',
  `text_align` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'left',
  `line_height` decimal(4,2) NOT NULL DEFAULT '1.25',
  `letter_spacing` decimal(5,2) NOT NULL DEFAULT '0.00',
  `text_color` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#000000',
  `uppercase` tinyint(1) NOT NULL DEFAULT '0',
  `custom_text` text COLLATE utf8mb4_unicode_ci,
  `page_no` smallint unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`field_id`),
  UNIQUE KEY `uq_btf_template_field` (`template_id`,`field_key`),
  CONSTRAINT `fk_btf_template` FOREIGN KEY (`template_id`) REFERENCES `blotter_notice_templates` (`template_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 14. Generated letters / notices (Draft → Generated → Issued / Unserved / Cancelled)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `blotter_notices` (
  `notice_id` int unsigned NOT NULL AUTO_INCREMENT,
  `notice_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `blotter_id` int NOT NULL,
  `hearing_id` int unsigned DEFAULT NULL,
  `purpose` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'hearing',
  `template_id` int unsigned DEFAULT NULL,
  `notice_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_party_id` int unsigned DEFAULT NULL,
  `recipient_party_ids` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `render_snapshot` longtext COLLATE utf8mb4_unicode_ci,
  `status` enum('Draft','Generated','Issued','Unserved','Cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Generated',
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `generated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `generated_by` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `issued_at` datetime DEFAULT NULL,
  `issued_by` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `issue_remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `issued_file_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `issued_file_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `issued_file_type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `edited_at` datetime DEFAULT NULL,
  `edited_by` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`notice_id`),
  UNIQUE KEY `uq_bn_notice_no` (`notice_no`),
  KEY `idx_bn_blotter` (`blotter_id`),
  KEY `idx_bn_hearing` (`hearing_id`,`status`),
  KEY `idx_bn_template` (`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 15. Signed copies of hearing outcomes
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `blotter_result_files` (
  `file_id` int unsigned NOT NULL AUTO_INCREMENT,
  `hearing_id` int unsigned NOT NULL,
  `blotter_id` int NOT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `uploaded_by` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`file_id`),
  KEY `idx_brf_hearing` (`hearing_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 16. Case timeline (insert only)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `blotter_timeline` (
  `timeline_id` int unsigned NOT NULL AUTO_INCREMENT,
  `blotter_id` int NOT NULL,
  `action` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `ref_table` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ref_id` int unsigned DEFAULT NULL,
  `recorded_by` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recorded_by_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recorded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`timeline_id`),
  KEY `idx_btl_blotter` (`blotter_id`,`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Default data (kept if already there)
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `blotter_settings` (`id`, `rule_type`, `max_days`, `max_hearings`, `noshow_counts`, `transfer_destination`, `transfer_destination_other`, `transfer_template_id`, `default_hearing_location`, `hearing_minutes`, `updated_by`) VALUES (1,'hearing',15,3,1,'BPSO',NULL,NULL,'Barangay Hall',60,NULL);
INSERT IGNORE INTO `blotter_incident_types` (`id`, `name`, `is_active`, `sort_order`) VALUES (1,'Physical Injury',1,1);
INSERT IGNORE INTO `blotter_incident_types` (`id`, `name`, `is_active`, `sort_order`) VALUES (2,'Theft',1,2);
INSERT IGNORE INTO `blotter_incident_types` (`id`, `name`, `is_active`, `sort_order`) VALUES (3,'Trespassing',1,3);
INSERT IGNORE INTO `blotter_incident_types` (`id`, `name`, `is_active`, `sort_order`) VALUES (4,'Unjust Vexation',1,4);
INSERT IGNORE INTO `blotter_incident_types` (`id`, `name`, `is_active`, `sort_order`) VALUES (5,'Oral Defamation / Slander',1,5);
INSERT IGNORE INTO `blotter_incident_types` (`id`, `name`, `is_active`, `sort_order`) VALUES (6,'Threats',1,6);
INSERT IGNORE INTO `blotter_incident_types` (`id`, `name`, `is_active`, `sort_order`) VALUES (7,'Damage to Property',1,7);
INSERT IGNORE INTO `blotter_incident_types` (`id`, `name`, `is_active`, `sort_order`) VALUES (8,'Noise Disturbance',1,8);
INSERT IGNORE INTO `blotter_incident_types` (`id`, `name`, `is_active`, `sort_order`) VALUES (9,'Family / Domestic Dispute',1,9);
INSERT IGNORE INTO `blotter_incident_types` (`id`, `name`, `is_active`, `sort_order`) VALUES (10,'Unpaid Debt',1,10);
INSERT IGNORE INTO `blotter_incident_types` (`id`, `name`, `is_active`, `sort_order`) VALUES (11,'Boundary / Land Dispute',1,11);
INSERT IGNORE INTO `blotter_incident_types` (`id`, `name`, `is_active`, `sort_order`) VALUES (12,'Animal-related Incident',1,12);
INSERT IGNORE INTO `blotter_incident_types` (`id`, `name`, `is_active`, `sort_order`) VALUES (13,'Other',1,13);
INSERT IGNORE INTO `blotter_locations` (`id`, `name`, `is_active`, `sort_order`) VALUES (1,'Barangay Hall',1,1);


-- ============================================================================
--  portal_preferences — appearance for the RESIDENT PORTAL entry screens
--  (the app's Login / Request Access / Forgot Password).
--  Global key/value settings the admin configures in settings.php; the resident
--  app reads them via user/backend/theme.php. Separate from admin_preferences
--  (which is per-admin panel personalisation).
--  Run once on barangay_db.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `portal_preferences` (
  `id`               int unsigned NOT NULL AUTO_INCREMENT,
  `preference_key`   varchar(64)  NOT NULL,
  `preference_value` varchar(255) NOT NULL DEFAULT '',
  `updated_at`       timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP
                         ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_portal_pref` (`preference_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed defaults (kept if the row already exists).
INSERT INTO `portal_preferences` (`preference_key`, `preference_value`) VALUES
  ('portal_accent_color', '#1D63DA'),
  ('portal_color_mode',   'light')
ON DUPLICATE KEY UPDATE `preference_value` = `preference_value`;

-- ============================================================================
--  Tables the app/admin use that neither file created before (same
--  definitions the PHP code self-creates, so nothing conflicts).
-- ============================================================================

-- Emergency Chat (admin/chat + resident app chat.php)
CREATE TABLE IF NOT EXISTS `emergency_chats` (
  `ChatID`     int NOT NULL AUTO_INCREMENT,
  `ResidentID` int NOT NULL,
  `SenderRole` enum('Resident','Staff','System') NOT NULL DEFAULT 'Resident',
  `Message`    text NOT NULL,
  `Image`      varchar(500) DEFAULT NULL,
  `Category`   varchar(50) DEFAULT 'General',
  `Status`     enum('Pending','Ongoing','Resolved','Closed') NOT NULL DEFAULT 'Pending',
  `IsRead`     tinyint(1) NOT NULL DEFAULT 0,
  `Timestamp`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ChatID`),
  KEY `idx_res` (`ResidentID`),
  KEY `idx_time` (`Timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `emergency_hotlines` (
  `id`     int NOT NULL AUTO_INCREMENT,
  `name`   varchar(100) NOT NULL,
  `number` varchar(50) NOT NULL,
  `color`  varchar(20) DEFAULT 'blue',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `emergency_hotlines` (`id`, `name`, `number`, `color`) VALUES
  (1, 'Barangay Hall',    '(044) 123-4567', 'blue'),
  (2, 'Police / PNP',     '117',            'indigo'),
  (3, 'Fire / BFP',       '(044) 111-2222', 'red'),
  (4, 'Medical / MDRRMO', '(044) 333-4444', 'emerald');

-- Chat staff availability (admin/chat/backend/chat_office_hours_api.php), single row id = 1
CREATE TABLE IF NOT EXISTS `chat_office_hours` (
  `id`          tinyint unsigned NOT NULL DEFAULT 1,
  `start_time`  time NOT NULL DEFAULT '08:00:00',
  `end_time`    time NOT NULL DEFAULT '17:00:00',
  `days_active` varchar(40) NOT NULL DEFAULT 'Mon,Tue,Wed,Thu,Fri',
  `hotline`     varchar(100) DEFAULT NULL,
  `welcome_msg` text,
  `ooo_msg`     text,
  `is_enabled`  tinyint(1) NOT NULL DEFAULT 1,
  `updated_at`  timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `chat_office_hours` (`id`) VALUES (1);

-- One auto-message per resident / type / day (admin/chat/backend/chat_auto_message.php)
CREATE TABLE IF NOT EXISTS `chat_auto_msg_log` (
  `id`           int unsigned NOT NULL AUTO_INCREMENT,
  `ResidentID`   int NOT NULL,
  `msg_type`     varchar(30) NOT NULL,
  `session_date` date NOT NULL,
  `created_at`   timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_resident_type_date` (`ResidentID`, `msg_type`, `session_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Access request history (admin/residents/frontend/access_requests.php)
CREATE TABLE IF NOT EXISTS `access_request_logs` (
  `log_id`      int NOT NULL AUTO_INCREMENT,
  `request_id`  int NOT NULL,
  `action`      varchar(50) NOT NULL,
  `action_by`   varchar(100) DEFAULT NULL,
  `action_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `remarks`     text,
  PRIMARY KEY (`log_id`),
  KEY `idx_req` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Household history (admin/household/backend/household_common.php)
CREATE TABLE IF NOT EXISTS `household_history` (
  `HistoryID`  int unsigned NOT NULL AUTO_INCREMENT,
  `SurveyID`   int unsigned NOT NULL,
  `ActionType` varchar(50) NOT NULL,
  `Description` text,
  `OldValue`   text,
  `NewValue`   text,
  `OldAddress` text,
  `NewAddress` text,
  `ActorName`  varchar(255) DEFAULT NULL,
  `CreatedAt`  timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`HistoryID`),
  KEY `idx_household_history_survey` (`SurveyID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Resident app settings: language, theme, colour, text size (user/backend/preferences.php)
CREATE TABLE IF NOT EXISTS `user_preferences` (
  `id`               bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id`          bigint unsigned NOT NULL,
  `preference_key`   varchar(60) NOT NULL,
  `preference_value` varchar(255) NOT NULL DEFAULT '',
  `created_at`       timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_pref` (`user_id`, `preference_key`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "New" badge per resident in the app (user/backend/announcements.php)
CREATE TABLE IF NOT EXISTS `announcement_reads` (
  `resident_id`     int NOT NULL,
  `announcement_id` int unsigned NOT NULL,
  `read_at`         datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`resident_id`, `announcement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

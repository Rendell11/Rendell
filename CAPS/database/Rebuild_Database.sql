-- ============================================================================
--  Barangay DB — Rebuild Script (merged)
--  = your Rebuild_Database (incl. Officials + Staff/BPSO revision, SMS config)
--  + barangay_profile with EVERY column the app uses (address, about, vision,
--    mission, office_hours, email, facebook_url, region/province/municipality/
--    barangay names, PSGC codes, zip_code). Without them Manage Area →
--    "Save Default Address" failed with "Unknown column 'address'".
--  + SET NAMES utf8mb4 so "Biñang" is not garbled when imported.
-- ============================================================================
SET NAMES utf8mb4;
create database barangay_db;
use barangay_db;


CREATE TABLE `admin` (
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

INSERT INTO `admin` VALUES (1,'admin','admin@barangaydaungan.ph','System Administrator','<bcrypt hash removed — set via phpMyAdmin>','2026-07-01 11:54:13','2026-07-01 11:54:13');


CREATE TABLE `admin_preferences` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int unsigned NOT NULL,
  `preference_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `preference_value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_pref` (`admin_id`,`preference_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIX #1: utf8mb4_0900_ai_ci -> utf8mb4_unicode_ci (MariaDB/XAMPP-compatible)
CREATE TABLE `staff` (
  `EmployeeID` varchar(50) NOT NULL,
  `Name` varchar(255) DEFAULT NULL,
  `ResidentID` varchar(20) DEFAULT NULL,
  `Position` varchar(100) DEFAULT NULL,
  `WorkEmail` varchar(150) DEFAULT NULL,
  `Password` varchar(255) DEFAULT NULL,
  `WorkLoad` text,
  `CreatedAt` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
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
CREATE TABLE `residents` (
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
-- (admin/backend/residents.php creates this itself with CREATE TABLE IF NOT
-- EXISTS at runtime, but it's included here so the DB is complete/consistent
-- from the start and matches every column referenced in login/request_access.php,
-- login/process_access_request.php, login/resident_profiling.php, and
-- login/set_password.php.)
-- ============================================================================
CREATE TABLE `access_requests` (
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
CREATE TABLE `staff_permissions` (
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
CREATE TABLE `activity_logs` (
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
CREATE TABLE `puroks` (
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
CREATE TABLE `barangay_profile` (
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

INSERT INTO `barangay_profile` (`id`, `brgy_name`, `logo_path`) VALUES (1, 'Barangay Biñang 2nd', NULL);

-- ============================================================================
-- FIX #3f: `request_logs` — used by login/resident_profiling.php to record
-- when a self-service profiling request is completed. The insert is already
-- wrapped in try/catch in that file (so its absence wasn't crashing
-- anything), but without this table those log entries were just silently
-- disappearing.
-- ============================================================================
CREATE TABLE `request_logs` (
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




USE barangay_db;

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



SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `announcements`;
CREATE TABLE `announcements` (
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
DROP TABLE IF EXISTS `announcement_attachments`;
CREATE TABLE `announcement_attachments` (
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
DROP TABLE IF EXISTS `disaster_alerts`;
CREATE TABLE `disaster_alerts` (
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
DROP TABLE IF EXISTS `disaster_reports`;
CREATE TABLE `disaster_reports` (
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
DROP TABLE IF EXISTS `hazards`;
CREATE TABLE `hazards` (
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
DROP TABLE IF EXISTS `sms_logs`;
CREATE TABLE `sms_logs` (
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
DROP TABLE IF EXISTS `sms_queue`;
CREATE TABLE `sms_queue` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `alert_id` int DEFAULT NULL COMMENT 'FK -> disaster_alerts.AlertID',
  `action` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'create' COMMENT 'create | update',
  `recipients` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'JSON array of {ResidentID, ContactNumber}',
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Full SMS message body',
  `total` int unsigned NOT NULL DEFAULT '0' COMMENT 'Total recipients count',
  `sent` int unsigned NOT NULL DEFAULT '0' COMMENT 'Successfully sent count',
  `failed` int unsigned NOT NULL DEFAULT '0' COMMENT 'Failed count',
  `status` enum('pending','processing','done','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
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

-- ----------------------------------------------------------------------------
-- Table: sms_recipient_logs  (one row per resident targeted by a disaster SMS:
-- sent / failed / invalid / no_number — used by SMS Live → View breakdown)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sms_recipient_logs` (
  `id`             int unsigned NOT NULL AUTO_INCREMENT,
  `log_id`         int          DEFAULT NULL,
  `alert_id`       int          DEFAULT NULL,
  `resident_id`    int unsigned DEFAULT NULL,
  `resident_name`  varchar(255) DEFAULT NULL,
  `area_label`     varchar(255) DEFAULT NULL,
  `contact_number` varchar(30)  DEFAULT NULL,
  `status`         enum('sent','failed','invalid','no_number') NOT NULL,
  `detail`         varchar(255) DEFAULT NULL,
  `created_at`     timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_srl_log` (`log_id`),
  KEY `idx_srl_alert` (`alert_id`)
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

USE barangay_db;

-- ----------------------------------------------------------------------------
-- 1. OFFICIALS — term audit trail
-- ----------------------------------------------------------------------------
ALTER TABLE `officials`
  ADD COLUMN `RecordedBy`      varchar(255) DEFAULT NULL,   -- who added the official
  ADD COLUMN `RecordedByID`    varchar(50)  DEFAULT NULL,
  ADD COLUMN `ExpectedTermEnd` date         DEFAULT NULL,   -- original TermEnd before End Term
  ADD COLUMN `ActualEndDate`   date         DEFAULT NULL,   -- date the term was ended
  ADD COLUMN `EndReason`       varchar(255) DEFAULT NULL,
  ADD COLUMN `EndedBy`         varchar(255) DEFAULT NULL,
  ADD COLUMN `EndedByID`       varchar(50)  DEFAULT NULL,
  ADD COLUMN `EndedAt`         datetime     DEFAULT NULL;

-- ----------------------------------------------------------------------------
-- 2. STAFF — assignment term, audit trail, work schedule
-- ----------------------------------------------------------------------------
ALTER TABLE `staff`
  ADD COLUMN `EffectiveStart` date         DEFAULT NULL,   -- assignment start
  ADD COLUMN `EffectiveEnd`   date         DEFAULT NULL,   -- set on End Term (soft end, no delete)
  ADD COLUMN `RecordedBy`     varchar(255) DEFAULT NULL,
  ADD COLUMN `RecordedByID`   varchar(50)  DEFAULT NULL,
  ADD COLUMN `EndReason`      varchar(255) DEFAULT NULL,
  ADD COLUMN `EndedBy`        varchar(255) DEFAULT NULL,
  ADD COLUMN `EndedByID`      varchar(50)  DEFAULT NULL,
  ADD COLUMN `EndedAt`        datetime     DEFAULT NULL,
  ADD COLUMN `WorkStart`      time         NOT NULL DEFAULT '08:00:00',   -- regular staff work schedule
  ADD COLUMN `WorkEnd`        time         NOT NULL DEFAULT '17:00:00',
  ADD COLUMN `WorkDays`       varchar(20)  NOT NULL DEFAULT '1,2,3,4,5'; -- 1 = Mon ... 7 = Sun

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
ALTER TABLE `tanod_schedules`
  ADD COLUMN `time_in_by`       varchar(255) DEFAULT NULL,
  ADD COLUMN `time_in_by_id`    varchar(50)  DEFAULT NULL,
  ADD COLUMN `time_out_by`      varchar(255) DEFAULT NULL,
  ADD COLUMN `time_out_by_id`   varchar(50)  DEFAULT NULL,
  ADD COLUMN `is_early_out`     tinyint(1)   NOT NULL DEFAULT 0,
  ADD COLUMN `early_out_reason` varchar(255) DEFAULT NULL,
  ADD COLUMN `absent_reason`    varchar(255) DEFAULT NULL,
  ADD COLUMN `absent_at`        datetime     DEFAULT NULL,
  ADD COLUMN `absent_by`        varchar(255) DEFAULT NULL,
  ADD COLUMN `absent_by_id`     varchar(50)  DEFAULT NULL;

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


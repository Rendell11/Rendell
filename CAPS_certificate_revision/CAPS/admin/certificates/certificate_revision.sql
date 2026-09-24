-- ============================================================================
--  Barangay DB — Certificate module revision (run AFTER Rebuild_Database)
--  Optional: the module creates/updates all of this by itself on first load
--  (cert_common.php → cert_migrate). Use this file if you prefer to set up
--  the database by hand. Works on MySQL 8 (Workbench) and MariaDB (XAMPP).
-- ============================================================================
SET NAMES utf8mb4;
USE barangay_db;

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

-- Blotter (same structure as the SOE blotter module; read by the eligibility check)
CREATE TABLE IF NOT EXISTS `blotter` (
  `BlotterID` INT NOT NULL AUTO_INCREMENT,
  `CaseNumber` VARCHAR(50) NULL,
  `ComplainantID` VARCHAR(20) NULL,
  `RespondentID` VARCHAR(20) NULL,
  `IncidentType` VARCHAR(100) NULL,
  `Narrative` TEXT NULL,
  `EvidencePath` VARCHAR(500) NULL,
  `IncidentDate` DATE NULL,
  `IncidentTime` TIME NULL,
  `Status` VARCHAR(40) DEFAULT 'Filed',
  `CurrentStage` VARCHAR(100) NOT NULL DEFAULT 'Initial',
  `HearingCount` INT NULL,
  `Phase` TINYINT NOT NULL DEFAULT 1,
  `TransferLocation` VARCHAR(255) NULL,
  `Location` VARCHAR(255) NULL,
  `AssignedOfficer` VARCHAR(255) NULL,
  `HearingDate` DATE NULL,
  `HearingTime` TIME NULL,
  `Details` TEXT NULL,
  `CreatedAt` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`BlotterID`),
  KEY `idx_blotter_status` (`Status`),
  KEY `idx_blotter_complainant` (`ComplainantID`),
  KEY `idx_blotter_respondent` (`RespondentID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

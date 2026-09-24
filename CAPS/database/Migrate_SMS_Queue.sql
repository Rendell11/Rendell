-- ============================================================================
-- Migrate_SMS_Queue.sql
-- Background SMS sending for Disaster Alerts — upgrades an EXISTING database
-- in place (no DROP, no data loss). Run once in phpMyAdmin → barangay_db → SQL.
--
-- The system also applies these changes by itself the first time the
-- Announcements page / SMS worker runs, so this file is optional.
-- If a line fails with "Duplicate column name" / "Duplicate key name",
-- that change is already applied — skip it and run the rest.
-- ============================================================================

-- 1) Job table: link to the sms_logs broadcast, heartbeat, error, cancel
ALTER TABLE sms_queue
  ADD COLUMN log_id INT DEFAULT NULL COMMENT 'FK -> sms_logs.LogID' AFTER alert_id,
  ADD COLUMN last_error VARCHAR(255) DEFAULT NULL,
  ADD COLUMN created_by VARCHAR(100) DEFAULT NULL,
  ADD COLUMN heartbeat_at DATETIME DEFAULT NULL,
  MODIFY COLUMN status ENUM('pending','processing','done','failed','cancelled') NOT NULL DEFAULT 'pending';
ALTER TABLE sms_queue ADD UNIQUE KEY uq_sms_queue_log (log_id);

-- 2) Per-resident status: queue states, retries, timestamps
ALTER TABLE sms_recipient_logs
  MODIFY COLUMN status ENUM('pending','processing','retry','sent','failed','invalid','no_number','cancelled') NOT NULL DEFAULT 'pending',
  ADD COLUMN attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN last_attempt_at DATETIME(3) DEFAULT NULL,
  ADD COLUMN next_attempt_at DATETIME DEFAULT NULL,
  ADD COLUMN sent_at DATETIME DEFAULT NULL;

-- 3) Indexes for the live status queries
ALTER TABLE sms_recipient_logs
  ADD KEY idx_srl_log_status (log_id, status),
  ADD KEY idx_srl_log_attempt (log_id, last_attempt_at);

-- 4) Duplicate protection: one SMS per alert per resident
--    (fails only if old test data already has duplicates — delete those rows first)
ALTER TABLE sms_recipient_logs ADD UNIQUE KEY uq_srl_alert_resident (alert_id, resident_id);

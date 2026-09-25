<?php
/**
 * cert_common.php — shared logic for the Certificates (Legal Documents) module.
 *
 * Include after admin/db.php (+ auth_check.php on pages/endpoints):
 *   require_once __DIR__.'/cert_common.php';
 *   cert_migrate($pdo);
 *
 * Contents
 *   - CSRF, actor, permission helpers
 *   - self-healing schema (CREATE TABLE IF NOT EXISTS / ensure column) + status normalization
 *   - status rules (which action is allowed in which status) — enforced server-side
 *   - document types, templates, requirements, extra information fields, paper sizes
 *   - dynamic field catalog built from the current `residents` table
 *   - value builder + render snapshot (issued documents never change when a template changes)
 *   - eligibility: verified / active / active blotter cases / unclaimed documents
 *   - 15-day expiry of unclaimed "Ready to Pick Up" documents, status log, resident notifications
 */

require_once __DIR__ . '/../../id_helper.php';
require_once __DIR__ . '/../../activity_log_helper.php';

if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

/* ───────────────────────── Status model ───────────────────────── */

const CERT_ST_PENDING  = 'Pending';          // online, not opened yet
const CERT_ST_REVIEW   = 'Review';           // online, opened by staff
const CERT_ST_REJECTED = 'Rejected';         // online
const CERT_ST_READY    = 'Ready to Pick Up'; // online, accepted — printed only when the resident comes
const CERT_ST_PREVIEW  = 'Preview';          // walk-in, generated but not printed
const CERT_ST_RELEASED = 'Released';         // printed & released (both)
const CERT_ST_EXPIRED  = 'Expired';          // online, not picked up within CERT_PICKUP_DAYS
const CERT_PICKUP_DAYS = 15;

function cert_statuses(): array {
    return [CERT_ST_PENDING, CERT_ST_REVIEW, CERT_ST_REJECTED, CERT_ST_READY, CERT_ST_PREVIEW, CERT_ST_RELEASED, CERT_ST_EXPIRED];
}

/** Pill style + label per status (soft background + colored text). */
function cert_status_meta(string $status): array {
    $map = [
        CERT_ST_PENDING  => ['bg-amber-50 text-amber-700 border-amber-200', 'schedule'],
        CERT_ST_REVIEW   => ['bg-amber-50 text-amber-700 border-amber-200', 'rate_review'],
        CERT_ST_READY    => ['bg-sky-50 text-sky-700 border-sky-200', 'inventory_2'],
        CERT_ST_PREVIEW  => ['bg-indigo-50 text-indigo-700 border-indigo-200', 'preview'],
        CERT_ST_RELEASED => ['bg-emerald-50 text-emerald-700 border-emerald-200', 'task_alt'],
        CERT_ST_REJECTED => ['bg-red-50 text-red-700 border-red-200', 'block'],
        CERT_ST_EXPIRED  => ['bg-slate-100 text-slate-500 border-slate-200', 'hourglass_disabled'],
    ];
    [$cls, $icon] = $map[$status] ?? ['bg-slate-100 text-slate-600 border-slate-200', 'help'];
    return ['class' => $cls, 'icon' => $icon, 'label' => $status];
}

/**
 * The one action button each row gets (see revision "Status → action").
 * Returns: preview | review | view
 */
function cert_row_action(array $r): string {
    switch ($r['Status']) {
        case CERT_ST_PREVIEW:
        case CERT_ST_READY:   return 'preview';
        case CERT_ST_PENDING:
        case CERT_ST_REVIEW:  return 'review';
        default:              return 'view';
    }
}

/** Can this request still be edited / printed & released? (server-side rule) */
function cert_can_print(array $r): bool {
    return in_array($r['Status'], [CERT_ST_PREVIEW, CERT_ST_READY], true);
}

/* ───────────────────────── Session / security ───────────────────────── */

function cert_csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    if (empty($_SESSION['cert_csrf'])) $_SESSION['cert_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['cert_csrf'];
}

/** For JSON endpoints: token from POST field or X-CSRF-Token header. */
function cert_csrf_verify(): void {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $sent = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = (string)($_SESSION['cert_csrf'] ?? '');
    if ($sent !== '' && $expected !== '' && hash_equals($expected, $sent)) return;
    cert_json(['success' => false, 'message' => 'Your session expired. Please refresh the page and try again.'], 419);
}

function cert_json(array $data, int $code = 200): void {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Display name of the logged-in admin/staff (same resolution as the report letterheads). */
function cert_actor_name(PDO $pdo): string {
    static $name = null;
    if ($name !== null) return $name;
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $role = $_SESSION['role'] ?? '';
    try {
        if ($role === 'admin' && !empty($_SESSION['admin_id'])) {
            $s = $pdo->prepare('SELECT Name FROM admin WHERE AdminID = ?');
            $s->execute([(int)$_SESSION['admin_id']]);
            if ($n = $s->fetchColumn()) return $name = (string)$n;
        } elseif (!empty($_SESSION['employee_id'])) {
            $s = $pdo->prepare('SELECT Name FROM staff WHERE EmployeeID = ?');
            $s->execute([$_SESSION['employee_id']]);
            if ($n = $s->fetchColumn()) return $name = (string)$n;
        }
    } catch (Throwable $e) {}
    return $name = (string)($_SESSION['admin_name'] ?? $_SESSION['staff_name'] ?? 'Barangay Staff');
}

function cert_actor_id(): ?string {
    $id = $_SESSION['admin_id'] ?? $_SESSION['employee_id'] ?? null;
    return $id !== null ? (string)$id : null;
}

/**
 * Action-level permission (permission_helper.php): admin always passes; staff need the
 * 'certificates' module in their workload (auth_check) AND the action flag.
 */
function cert_can(PDO $pdo, string $action): bool {
    if (($_SESSION['role'] ?? '') === 'admin') return true;
    $helper = __DIR__ . '/../../permission_helper.php';
    if (is_file($helper)) require_once $helper;
    if (function_exists('staff_can')) {
        try { return staff_can($pdo, 'certificates', $action); } catch (Throwable $e) { return false; }
    }
    return true;
}

function cert_require(PDO $pdo, string $action): void {
    if (!cert_can($pdo, $action)) {
        cert_json(['success' => false, 'message' => 'You do not have permission to ' . $action . ' certificates.'], 403);
    }
}

function cert_log_activity(string $action, string $description): void {
    if (function_exists('log_activity')) log_activity('Certificates', $action, $description);
}

/* ───────────────────────── Schema ───────────────────────── */

function cert_column_exists(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    if (!isset($cache[$table])) {
        $s = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $s->execute([$table]);
        $cache[$table] = array_flip($s->fetchAll(PDO::FETCH_COLUMN));
    }
    return isset($cache[$table][$column]);
}

function cert_ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
    if (!cert_column_exists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function cert_migrate(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    id_helper_migrate($pdo); // DDL must run before any transaction (it would commit it)

    $pdo->exec("CREATE TABLE IF NOT EXISTS `custom_document_types` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `doc_type` VARCHAR(100) NOT NULL,
        `doc_code` VARCHAR(20) NULL,
        `description` VARCHAR(500) NULL,
        `icon` VARCHAR(50) NOT NULL DEFAULT 'description',
        `color` VARCHAR(20) NOT NULL DEFAULT 'blue',
        `default_body` LONGTEXT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `is_draft` TINYINT(1) NOT NULL DEFAULT 0,
        `draft_step` TINYINT NOT NULL DEFAULT 1,
        `sort_order` INT NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_custom_doc_type` (`doc_type`)
    ) $cs");
    cert_ensure_column($pdo, 'custom_document_types', 'doc_code', "VARCHAR(20) NULL");
    cert_ensure_column($pdo, 'custom_document_types', 'description', "VARCHAR(500) NULL");
    cert_ensure_column($pdo, 'custom_document_types', 'is_draft', "TINYINT(1) NOT NULL DEFAULT 0");
    cert_ensure_column($pdo, 'custom_document_types', 'draft_step', "TINYINT NOT NULL DEFAULT 1");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `certificate_templates` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `doc_type` VARCHAR(100) NOT NULL DEFAULT 'General',
        `template_name` VARCHAR(100) NOT NULL DEFAULT 'Default',
        `header_text` LONGTEXT NULL,
        `body_text` LONGTEXT NULL,
        `footer_text` LONGTEXT NULL,
        `background_image_path` VARCHAR(500) NULL,
        `bg_opacity` DECIMAL(3,2) NOT NULL DEFAULT 1.00,
        `paper_size` VARCHAR(20) NOT NULL DEFAULT 'a4',
        `custom_layout_elements` LONGTEXT NULL,
        `layout_json` LONGTEXT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_template_name` (`template_name`)
    ) $cs");
    cert_ensure_column($pdo, 'certificate_templates', 'paper_size', "VARCHAR(20) NOT NULL DEFAULT 'a4'");
    cert_ensure_column($pdo, 'certificate_templates', 'custom_layout_elements', "LONGTEXT NULL");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `certificate_field_positions` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `template_id` INT NOT NULL,
        `field_key` VARCHAR(80) NOT NULL,
        `field_label` VARCHAR(150) NOT NULL,
        `pos_x` DECIMAL(6,2) NOT NULL DEFAULT 50.00,
        `pos_y` DECIMAL(6,2) NOT NULL DEFAULT 50.00,
        `width` DECIMAL(6,2) NULL,
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
    ) $cs");
    cert_ensure_column($pdo, 'certificate_field_positions', 'width', "DECIMAL(6,2) NULL");
    cert_ensure_column($pdo, 'certificate_field_positions', 'uppercase', "TINYINT(1) NOT NULL DEFAULT 0");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `document_requirements` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `doc_type` VARCHAR(100) NOT NULL,
        `requirement` VARCHAR(255) NOT NULL,
        `sort_order` INT NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_doc_type` (`doc_type`)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `document_extra_fields` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `doc_type` VARCHAR(100) NOT NULL,
        `field_key` VARCHAR(60) NOT NULL,
        `label` VARCHAR(150) NOT NULL,
        `input_type` ENUM('text','number','date','textarea','select') NOT NULL DEFAULT 'text',
        `options` LONGTEXT NULL COMMENT 'JSON array of choices for input_type = select',
        `is_required` TINYINT(1) NOT NULL DEFAULT 0,
        `sort_order` INT NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_doc_extra_field` (`doc_type`, `field_key`),
        KEY `idx_def_doc_type` (`doc_type`)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `document_requests` (
        `RequestID` INT NOT NULL AUTO_INCREMENT,
        `ResidentID` INT NULL,
        `ReferenceNo` VARCHAR(50) NULL,
        `doc_number` VARCHAR(50) NULL,
        `DocType` VARCHAR(100) NULL,
        `Purpose` TEXT NULL,
        `business_name` VARCHAR(255) NULL,
        `business_address` VARCHAR(500) NULL,
        `nature_of_business` VARCHAR(255) NULL,
        `years_of_residency` VARCHAR(20) NULL,
        `employment_purpose` VARCHAR(255) NULL,
        `photo_path` VARCHAR(500) NULL,
        `requirements_checked` LONGTEXT NULL,
        `extra_data` LONGTEXT NULL,
        `layout_override` LONGTEXT NULL,
        `render_snapshot` LONGTEXT NULL,
        `generated_at` DATETIME NULL,
        `generated_by` VARCHAR(150) NULL,
        `previewed_at` DATETIME NULL,
        `printed_at` DATETIME NULL,
        `printed_by` VARCHAR(150) NULL,
        `released_by` VARCHAR(150) NULL,
        `Status` VARCHAR(30) NOT NULL DEFAULT 'Pending',
        `request_type` ENUM('walk-in','online') NOT NULL DEFAULT 'online',
        `release_date` DATETIME NULL,
        `release_time` TIME NULL,
        `DateRequested` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        `online_submitted_at` DATETIME NULL,
        `reviewed_at` DATETIME NULL,
        `reviewed_by` VARCHAR(150) NULL,
        `approved_at` DATETIME NULL,
        `approved_by` VARCHAR(150) NULL,
        `rejected_at` DATETIME NULL,
        `rejected_by` VARCHAR(150) NULL,
        `rejection_reason` VARCHAR(500) NULL,
        `expired_at` DATETIME NULL,
        `blotter_cases` INT NOT NULL DEFAULT 0,
        `blotter_override_by` VARCHAR(150) NULL,
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
    ) $cs");
    foreach ([
        'request_type' => "ENUM('walk-in','online') NOT NULL DEFAULT 'walk-in'",
        'doc_number' => "VARCHAR(50) NULL", 'business_name' => "VARCHAR(255) NULL", 'business_address' => "VARCHAR(500) NULL",
        'nature_of_business' => "VARCHAR(255) NULL", 'years_of_residency' => "VARCHAR(20) NULL", 'employment_purpose' => "VARCHAR(255) NULL",
        'photo_path' => "VARCHAR(500) NULL", 'requirements_checked' => "LONGTEXT NULL",
        'extra_data' => "LONGTEXT NULL", 'layout_override' => "LONGTEXT NULL", 'render_snapshot' => "LONGTEXT NULL",
        'generated_at' => "DATETIME NULL", 'generated_by' => "VARCHAR(150) NULL", 'previewed_at' => "DATETIME NULL",
        'printed_at' => "DATETIME NULL", 'printed_by' => "VARCHAR(150) NULL", 'released_by' => "VARCHAR(150) NULL",
        'release_date' => "DATETIME NULL", 'release_time' => "TIME NULL", 'online_submitted_at' => "DATETIME NULL",
        'reviewed_at' => "DATETIME NULL", 'reviewed_by' => "VARCHAR(150) NULL",
        'approved_at' => "DATETIME NULL", 'approved_by' => "VARCHAR(150) NULL",
        'rejected_at' => "DATETIME NULL", 'rejected_by' => "VARCHAR(150) NULL", 'rejection_reason' => "VARCHAR(500) NULL",
        'expired_at' => "DATETIME NULL", 'blotter_cases' => "INT NOT NULL DEFAULT 0",
        'blotter_override_by' => "VARCHAR(150) NULL", 'blotter_override_at' => "DATETIME NULL",
        'notif_read' => "TINYINT(1) NOT NULL DEFAULT 0",
    ] as $col => $def) cert_ensure_column($pdo, 'document_requests', $col, $def);

    // Old SOE databases have Status as an ENUM of the old values — widen it once, then normalize.
    $type = $pdo->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                         AND TABLE_NAME = 'document_requests' AND COLUMN_NAME = 'Status'")->fetchColumn();
    if ($type === 'enum') $pdo->exec("ALTER TABLE document_requests MODIFY `Status` VARCHAR(30) NOT NULL DEFAULT 'Pending'");
    cert_normalize_statuses($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS `document_request_logs` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `request_id` INT NOT NULL,
        `status` VARCHAR(30) NOT NULL,
        `note` VARCHAR(500) NULL,
        `by_name` VARCHAR(150) NULL,
        `by_id` VARCHAR(50) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_drl_request` (`request_id`, `created_at`)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `document_request_files` (
        `FileID` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `RequestID` INT NOT NULL,
        `RequirementLabel` VARCHAR(255) NOT NULL,
        `FilePath` VARCHAR(500) NOT NULL,
        `FileType` VARCHAR(100) NULL,
        `UploadedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`FileID`),
        KEY `idx_drf_request` (`RequestID`)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `resident_notifications` (
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
    ) $cs");

    // Blotter cases (same structure as the SOE blotter module) — read by the eligibility check.
    $pdo->exec("CREATE TABLE IF NOT EXISTS `blotter` (
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
    ) $cs");

    cert_expire_overdue($pdo);
}

/** Old status values (SOE) → the revised set. Safe to run on every load. */
function cert_normalize_statuses(PDO $pdo): void {
    $pdo->exec("UPDATE document_requests SET Status = 'Released'
                WHERE Status = 'Printed'");
    $pdo->exec("UPDATE document_requests SET Status = 'Preview'
                WHERE request_type = 'walk-in' AND Status IN ('Generated','Previewed','Approved','Ready for Pickup','Pending','Under Review')");
    $pdo->exec("UPDATE document_requests SET Status = 'Review'   WHERE request_type = 'online' AND Status = 'Under Review'");
    $pdo->exec("UPDATE document_requests SET Status = 'Ready to Pick Up', approved_at = COALESCE(approved_at, generated_at, DateRequested)
                WHERE request_type = 'online' AND Status IN ('Approved','Ready for Pickup','Generated','Previewed')");
    $pdo->exec("UPDATE document_requests SET Status = 'Rejected', rejection_reason = COALESCE(NULLIF(rejection_reason,''), 'Cancelled')
                WHERE Status = 'Cancelled'");
}

/**
 * Online documents accepted but not released within CERT_PICKUP_DAYS become Expired.
 * Runs on page load / list query — no cron needed.
 */
function cert_expire_overdue(PDO $pdo): int {
    $ids = $pdo->query("SELECT RequestID FROM document_requests
                        WHERE Status = 'Ready to Pick Up'
                          AND COALESCE(approved_at, generated_at, DateRequested) < (NOW() - INTERVAL " . CERT_PICKUP_DAYS . " DAY)")
               ->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) return 0;
    $in = implode(',', array_map('intval', $ids));
    $pdo->exec("UPDATE document_requests SET Status = 'Expired', expired_at = NOW() WHERE RequestID IN ($in) AND Status = 'Ready to Pick Up'");
    foreach ($ids as $id) {
        cert_status_log($pdo, (int)$id, CERT_ST_EXPIRED, 'Not picked up within ' . CERT_PICKUP_DAYS . ' days of approval.', 'System', null);
    }
    return count($ids);
}

function cert_status_log(PDO $pdo, int $requestId, string $status, string $note = '', ?string $byName = null, ?string $byId = null): void {
    try {
        $pdo->prepare("INSERT INTO document_request_logs (request_id, status, note, by_name, by_id) VALUES (?,?,?,?,?)")
            ->execute([$requestId, $status, mb_substr($note, 0, 500), $byName ?? cert_actor_name($pdo), $byId ?? cert_actor_id()]);
    } catch (Throwable $e) { error_log('[Certificates] status log failed: ' . $e->getMessage()); }
}

function cert_notify_resident(PDO $pdo, int $residentId, string $type, string $title, string $message, int $requestId): void {
    try {
        $pdo->prepare("INSERT INTO resident_notifications (resident_id, notif_type, title, message, ref_table, ref_id)
                       VALUES (?,?,?,?, 'document_requests', ?)")
            ->execute([$residentId, $type, $title, $message, $requestId]);
    } catch (Throwable $e) { error_log('[Certificates] notify failed: ' . $e->getMessage()); }
}

/* ───────────────────────── Paper sizes ───────────────────────── */

/** CSS pixel size at 96 dpi (what the preview, editor and print all use). */
function cert_paper_sizes(): array {
    return [
        'letter' => ['label' => 'Letter (8.5 × 11 in)',        'w' => 816, 'h' => 1056, 'css' => '8.5in 11in'],
        'a4'     => ['label' => 'A4 (210 × 297 mm)',           'w' => 794, 'h' => 1123, 'css' => '210mm 297mm'],
        'long'   => ['label' => 'Legal / Long (8.5 × 13 in)',  'w' => 816, 'h' => 1248, 'css' => '8.5in 13in'],
        'legal'  => ['label' => 'US Legal (8.5 × 14 in)',      'w' => 816, 'h' => 1344, 'css' => '8.5in 14in'],
    ];
}

function cert_paper(string $key): array {
    $all = cert_paper_sizes();
    return ['key' => isset($all[$key]) ? $key : 'a4'] + ($all[$key] ?? $all['a4']);
}

/* ───────────────────────── Dynamic field catalog ───────────────────────── */

/**
 * Fields available on a certificate, built from the columns that actually exist in
 * `residents` (+ computed/system values). Returns [key => ['label','group','sample']].
 */
function cert_field_catalog(PDO $pdo): array {
    $has = fn($c) => cert_column_exists($pdo, 'residents', $c);
    $f = [];
    $add = function ($key, $label, $sample, $group = 'resident') use (&$f) { $f[$key] = ['label' => $label, 'group' => $group, 'sample' => $sample]; };

    $add('full_name', 'Full Name', 'JUAN SANTOS DELA CRUZ');
    $add('first_name', 'First Name', 'Juan');
    if ($has('MiddleName')) $add('middle_name', 'Middle Name', 'Santos');
    $add('last_name', 'Last Name', 'Dela Cruz');
    if ($has('Suffix')) $add('suffix', 'Suffix', 'Jr.');
    if ($has('Sex')) $add('sex', 'Sex', 'Male');
    if ($has('BirthDate')) { $add('birth_date', 'Birth Date', 'January 5, 1990'); $add('age', 'Age', '36'); }
    if ($has('BirthPlace')) $add('birth_place', 'Birth Place', 'Bocaue, Bulacan');
    if ($has('CivilStatus')) $add('civil_status', 'Civil Status', 'Single');
    if ($has('Nationality')) $add('nationality', 'Nationality', 'Filipino');
    $add('complete_address', 'Complete Address', 'Blk 1 Lot 2 Mabini St., Purok 3, Biñang 2nd, Bocaue, Bulacan');
    if ($has('HouseNumber')) $add('house_number', 'House No.', 'Blk 1 Lot 2');
    if ($has('StreetName')) $add('street', 'Street', 'Mabini St.');
    if ($has('Purok')) $add('purok', 'Purok', 'Purok 3');
    if ($has('ContactNumber')) $add('contact_number', 'Contact Number', '09171234567');
    if ($has('Email')) $add('email', 'Email', 'juan@email.com');
    if ($has('ResidentCode')) $add('resident_id', 'Resident ID', 'RES-2026-0001');
    if ($has('IsVoter')) $add('voter_status', 'Voter Status', 'Registered Voter');
    if ($has('Occupation')) $add('occupation', 'Occupation', 'Driver');
    foreach (['YearsOfResidency', 'ResidencySince', 'YearStartedResiding'] as $c) {
        if ($has($c)) { $add('years_of_residency', 'Years of Residency', '12'); break; }
    }

    $add('document_number', 'Document Number', 'DOC-2026-0001', 'system');
    $add('date_issued', 'Date Issued', date('F j, Y'), 'system');
    $add('day_issued', 'Day Issued (ordinal)', date('jS'), 'system');
    $add('month_year_issued', 'Month & Year Issued', date('F Y'), 'system');
    $add('month_issued', 'Month Issued', date('F'), 'system');
    $add('year_issued', 'Year Issued', date('Y'), 'system');
    $add('year_issued_short', 'Year Issued (last 2 digits, for "20__")', date('y'), 'system');
    $add('purpose', 'Purpose', 'Employment', 'system');
    $add('barangay_name', 'Barangay Name', 'Barangay Biñang 2nd', 'system');
    $add('captain_name', 'Barangay Captain', 'HON. MARIA SANTOS', 'system');
    $add('issuing_officer', 'Issuing Officer', 'Barangay Secretary', 'system');
    $add('reference_no', 'Reference No.', 'REF-2026-0001', 'system');
    return $f;
}

/** Old (SOE) field keys → current keys, so templates made before this revision still render. */
function cert_legacy_key_map(): array {
    return [
        'resident_name' => 'full_name', 'fullname' => 'full_name', 'name' => 'full_name',
        'issue_date' => 'date_issued', 'date' => 'date_issued',
        'barangay_captain' => 'captain_name',
        'birthdate' => 'birth_date', 'gender' => 'sex', 'contact_no' => 'contact_number',
        'citizenship' => 'nationality', 'address' => 'complete_address',
        'doc_number' => 'document_number', 'barangay' => 'barangay_name', 'reference_number' => 'reference_no',
        // removed fields: kept as empty so an old layout doesn't show a raw key
        'or_number' => null, 'secretary' => null,
        // one-column-per-field values from old requests now live in extra information fields
        'business_name' => 'extra.business_name', 'business_address' => 'extra.business_address',
        'nature_of_business' => 'extra.nature_of_business', 'employment_purpose' => 'extra.employment_purpose',
    ];
}

function cert_canonical_key(string $key): ?string {
    $map = cert_legacy_key_map();
    return array_key_exists($key, $map) ? $map[$key] : $key;
}

/* ───────────────────────── Document types / templates ───────────────────────── */

/** @param bool $issuable true = only active, finished (non-draft) types — for Issue Walk-In and online requests */
function cert_doc_types(PDO $pdo, bool $issuable = true): array {
    $sql = "SELECT * FROM custom_document_types" . ($issuable ? " WHERE is_active = 1 AND is_draft = 0" : "") . " ORDER BY sort_order, doc_type";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function cert_doc_type(PDO $pdo, string $docType): ?array {
    $s = $pdo->prepare("SELECT * FROM custom_document_types WHERE doc_type = ?");
    $s->execute([$docType]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function cert_template_for(PDO $pdo, string $docType): ?array {
    $s = $pdo->prepare("SELECT * FROM certificate_templates WHERE doc_type = ? AND is_active = 1 ORDER BY id DESC LIMIT 1");
    $s->execute([$docType]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function cert_positions(PDO $pdo, int $templateId): array {
    $s = $pdo->prepare("SELECT field_key, field_label, pos_x, pos_y, width, font_size, font_weight, text_align, text_color, uppercase
                        FROM certificate_field_positions WHERE template_id = ? AND is_visible = 1 ORDER BY id");
    $s->execute([$templateId]);
    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $key = cert_canonical_key($p['field_key']);
        if ($key === null) continue;
        $out[] = cert_clean_position(['field_key' => $key] + $p);
    }
    return $out;
}

/** Validate one position object (from DB or from the editor). */
function cert_clean_position(array $p): array {
    $num = fn($v, $min, $max, $def) => is_numeric($v) ? max($min, min($max, round((float)$v, 2))) : $def;
    $align = in_array($p['text_align'] ?? '', ['left', 'center', 'right'], true) ? $p['text_align'] : 'center';
    $color = preg_match('/^#[0-9a-f]{3,8}$/i', (string)($p['text_color'] ?? '')) ? $p['text_color'] : '#000000';
    return [
        'field_key'   => substr(preg_replace('/[^a-z0-9_.]/i', '', (string)($p['field_key'] ?? '')), 0, 80),
        'field_label' => mb_substr(trim((string)($p['field_label'] ?? '')), 0, 150),
        'pos_x'       => $num($p['pos_x'] ?? 50, 0, 100, 50),
        'pos_y'       => $num($p['pos_y'] ?? 50, 0, 100, 50),
        'width'       => (isset($p['width']) && $p['width'] !== '' && $p['width'] !== null) ? $num($p['width'], 2, 100, null) : null,
        'font_size'   => (int)$num($p['font_size'] ?? 14, 6, 96, 14),
        'font_weight' => ($p['font_weight'] ?? '') === 'bold' ? 'bold' : 'normal',
        'text_align'  => $align,
        'text_color'  => $color,
        'uppercase'   => !empty($p['uppercase']) ? 1 : 0,
    ];
}

function cert_requirements(PDO $pdo, string $docType): array {
    $s = $pdo->prepare("SELECT requirement FROM document_requirements WHERE doc_type = ? ORDER BY sort_order, id");
    $s->execute([$docType]);
    return $s->fetchAll(PDO::FETCH_COLUMN);
}

function cert_extra_fields(PDO $pdo, string $docType): array {
    $s = $pdo->prepare("SELECT field_key, label, input_type, options, is_required FROM document_extra_fields WHERE doc_type = ? ORDER BY sort_order, id");
    $s->execute([$docType]);
    return array_map(function ($r) {
        $r['options'] = $r['options'] ? (json_decode($r['options'], true) ?: []) : [];
        $r['is_required'] = (int)$r['is_required'];
        return $r;
    }, $s->fetchAll(PDO::FETCH_ASSOC));
}

/** Initials of the name, unique in custom_document_types.doc_code (e.g. "Barangay Clearance" → BC, BC2 …). */
function cert_auto_doc_code(PDO $pdo, string $name, ?int $exceptId = null): string {
    $words = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY);
    $base = strtoupper(implode('', array_map(fn($w) => $w[0], $words))) ?: 'DOC';
    $base = substr($base, 0, 8);
    $code = $base; $n = 1;
    $q = $pdo->prepare("SELECT COUNT(*) FROM custom_document_types WHERE doc_code = ? AND id <> ?");
    while (true) {
        $q->execute([$code, $exceptId ?? 0]);
        if (!(int)$q->fetchColumn()) return $code;
        $code = $base . (++$n);
    }
}

/* ───────────────────────── Residents, officials, barangay ───────────────────────── */

function cert_barangay(PDO $pdo): array {
    static $b = null;
    if ($b !== null) return $b;
    try { $row = $pdo->query("SELECT * FROM barangay_profile WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e) { $row = []; }
    $name = $row['brgy_name'] ?? '';
    return $b = [
        'name' => $name !== '' ? $name : 'Barangay',
        'barangay' => $row['barangay_name'] ?? '',
        'municipality' => $row['municipality_name'] ?? '',
        'province' => $row['province_name'] ?? '',
        'logo_path' => $row['logo_path'] ?? null,
    ];
}

function cert_current_captain(PDO $pdo): string {
    static $name = null;
    if ($name !== null) return $name;
    $sqls = [
        "SELECT r.FirstName, r.MiddleName, r.LastName, r.Suffix FROM officials o JOIN residents r ON r.ResidentID = o.ResidentID
         WHERE o.Position = 'Barangay Captain' AND o.ActualEndDate IS NULL AND (o.TermEnd IS NULL OR o.TermEnd >= CURDATE()) ORDER BY o.TermStart DESC LIMIT 1",
        "SELECT r.FirstName, r.MiddleName, r.LastName, r.Suffix FROM officials o JOIN residents r ON r.ResidentID = o.ResidentID
         WHERE o.Position LIKE '%Captain%' AND (o.TermEnd IS NULL OR o.TermEnd >= CURDATE()) ORDER BY o.TermStart DESC LIMIT 1",
    ];
    foreach ($sqls as $sql) {
        try {
            $r = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
            if ($r) return $name = 'HON. ' . strtoupper(cert_person_name($r));
            break;
        } catch (Throwable $e) { continue; }
    }
    return $name = '';
}

function cert_person_name(array $r, bool $withMiddle = true): string {
    return trim(preg_replace('/\s+/', ' ', implode(' ', [
        $r['FirstName'] ?? '', $withMiddle ? ($r['MiddleName'] ?? '') : '', $r['LastName'] ?? '', $r['Suffix'] ?? '',
    ])));
}

function cert_resident(PDO $pdo, int $id): ?array {
    $s = $pdo->prepare("SELECT * FROM residents WHERE ResidentID = ?");
    $s->execute([$id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    unset($r['Password'], $r['ResetToken'], $r['TokenExpiry']);
    return $r;
}

function cert_resident_address(PDO $pdo, array $r): string {
    $b = cert_barangay($pdo);
    $line1 = trim(($r['HouseNumber'] ?? '') . ' ' . ($r['StreetName'] ?? ''));
    $purok = trim((string)($r['Purok'] ?? ''));
    if ($purok !== '' && stripos($purok, 'purok') !== 0) $purok = 'Purok ' . $purok;
    $brgy = $b['barangay'] !== '' ? $b['barangay'] : $b['name'];
    return implode(', ', array_filter([$line1, $purok, $brgy, $b['municipality'], $b['province']], fn($x) => trim((string)$x) !== ''));
}

/**
 * Eligibility shown in Issue Walk-In step 4 and in the online review.
 *  - verified: the resident has a complete registered profile (Resident ID, name, birth date, sex, address)
 *              and the portal account is not Disabled — CAPS has no separate "verified" flag.
 *  - active:   not deceased
 */
function cert_eligibility(PDO $pdo, array $r): array {
    $missing = [];
    foreach (['ResidentCode' => 'Resident ID', 'FirstName' => 'First name', 'LastName' => 'Last name',
              'BirthDate' => 'Birth date', 'Sex' => 'Sex'] as $col => $lbl) {
        if (array_key_exists($col, $r) && trim((string)$r[$col]) === '') $missing[] = $lbl;
    }
    if (trim(($r['HouseNumber'] ?? '') . ($r['StreetName'] ?? '') . ($r['Purok'] ?? '')) === '') $missing[] = 'Address';
    $disabled = ($r['access_status'] ?? '') === 'Disabled';
    $deceased = !empty($r['IsDeceased']);
    $blotter = cert_active_blotters($pdo, $r);
    return [
        'verified' => !$missing && !$disabled,
        'verified_note' => $disabled ? 'Resident account is disabled.' : ($missing ? 'Incomplete profile: ' . implode(', ', $missing) : 'Complete registered profile'),
        'active' => !$deceased,
        'active_note' => $deceased ? 'Resident is recorded as deceased.' : 'Active resident',
        'blotter' => $blotter,
        'unclaimed' => cert_unclaimed($pdo, (int)$r['ResidentID']),
        'history' => cert_recent_requests($pdo, (int)$r['ResidentID']),
    ];
}

/** Statuses that mean a blotter case is still open. */
function cert_blotter_active_statuses(): array {
    return ['Filed', 'Pending', 'Scheduled', 'Under Conciliation', 'Under Mediation', 'Eligible for Transfer', 'Transfer Approved'];
}

function cert_active_blotters(PDO $pdo, array $r): array {
    $ids = array_values(array_filter([(string)$r['ResidentID'], (string)($r['ResidentCode'] ?? '')]));
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = cert_blotter_active_statuses();
    $sin = implode(',', array_fill(0, count($st), '?'));
    try {
        $s = $pdo->prepare("SELECT BlotterID, CaseNumber, ComplainantID, RespondentID, IncidentType, IncidentDate, Status, CreatedAt
                            FROM blotter WHERE (ComplainantID IN ($in) OR RespondentID IN ($in)) AND Status IN ($sin)
                            ORDER BY COALESCE(IncidentDate, DATE(CreatedAt)) DESC");
        $s->execute(array_merge($ids, $ids, $st));
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
    return array_map(function ($b) use ($ids) {
        $role = in_array((string)$b['ComplainantID'], $ids, true) ? 'Complainant' : 'Respondent';
        $date = $b['IncidentDate'] ?: substr((string)$b['CreatedAt'], 0, 10);
        return [
            'id' => (int)$b['BlotterID'],
            'blotter_no' => $b['CaseNumber'] ?: ('BLT-' . substr((string)$date, 0, 4) . '-' . str_pad((string)$b['BlotterID'], 4, '0', STR_PAD_LEFT)),
            'role' => $role,
            'date' => $date ? date('M j, Y', strtotime($date)) : '—',
            'case' => $b['IncidentType'] ?: '—',
            'status' => $b['Status'],
        ];
    }, $rows);
}

/** Documents the resident never picked up (Ready to Pick Up or Expired). */
function cert_unclaimed(PDO $pdo, int $residentId, ?int $exceptRequestId = null): array {
    $s = $pdo->prepare("SELECT RequestID, ReferenceNo, doc_number, DocType, Status, approved_at, expired_at
                        FROM document_requests WHERE ResidentID = ? AND Status IN ('Ready to Pick Up','Expired') AND RequestID <> ?
                        ORDER BY RequestID DESC LIMIT 10");
    $s->execute([$residentId, $exceptRequestId ?? 0]);
    return array_map(function ($u) {
        $u['approved_label'] = $u['approved_at'] ? date('M j, Y', strtotime($u['approved_at'])) : '—';
        return $u;
    }, $s->fetchAll(PDO::FETCH_ASSOC));
}

function cert_recent_requests(PDO $pdo, int $residentId, ?int $exceptRequestId = null): array {
    $s = $pdo->prepare("SELECT RequestID, ReferenceNo, DocType, Status, request_type, DATE_FORMAT(DateRequested, '%b %e, %Y') AS date_label
                        FROM document_requests WHERE ResidentID = ? AND RequestID <> ? AND DateRequested >= (NOW() - INTERVAL 30 DAY)
                        ORDER BY DateRequested DESC LIMIT 10");
    $s->execute([$residentId, $exceptRequestId ?? 0]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

/* ───────────────────────── Values + render snapshot ───────────────────────── */

/** Every field value for one request (resident + system + extra information fields). */
function cert_field_values(PDO $pdo, array $req, array $res): array {
    $b = cert_barangay($pdo);
    $bd = !empty($res['BirthDate']) ? strtotime($res['BirthDate']) : null;
    $issued = strtotime($req['generated_at'] ?? '') ?: time();
    $purok = trim((string)($res['Purok'] ?? ''));
    $v = [
        'full_name' => cert_person_name($res),
        'first_name' => $res['FirstName'] ?? '', 'middle_name' => $res['MiddleName'] ?? '',
        'last_name' => $res['LastName'] ?? '', 'suffix' => $res['Suffix'] ?? '',
        'sex' => $res['Sex'] ?? '',
        'birth_date' => $bd ? date('F j, Y', $bd) : '',
        'age' => $bd ? (string)(new DateTime(date('Y-m-d', $bd)))->diff(new DateTime(date('Y-m-d', $issued)))->y : '',
        'birth_place' => $res['BirthPlace'] ?? '',
        'civil_status' => $res['CivilStatus'] ?? '',
        'nationality' => $res['Nationality'] ?? '',
        'complete_address' => cert_resident_address($pdo, $res),
        'house_number' => $res['HouseNumber'] ?? '', 'street' => $res['StreetName'] ?? '',
        'purok' => ($purok !== '' && stripos($purok, 'purok') !== 0) ? 'Purok ' . $purok : $purok,
        'contact_number' => $res['ContactNumber'] ?? '', 'email' => $res['Email'] ?? '',
        'resident_id' => $res['ResidentCode'] ?? '',
        'voter_status' => array_key_exists('IsVoter', $res) ? (!empty($res['IsVoter']) ? 'Registered Voter' : 'Non-Voter') : '',
        'occupation' => $res['Occupation'] ?? '',
        'document_number' => $req['doc_number'] ?? '',
        'date_issued' => date('F j, Y', $issued),
        'day_issued' => date('jS', $issued),
        'month_year_issued' => date('F Y', $issued),
        'month_issued' => date('F', $issued),
        'year_issued' => date('Y', $issued),
        'year_issued_short' => date('y', $issued),
        'purpose' => $req['Purpose'] ?? '',
        'barangay_name' => $b['name'],
        'captain_name' => cert_current_captain($pdo),
        'issuing_officer' => $req['generated_by'] ?? '',
        'reference_no' => $req['ReferenceNo'] ?? '',
    ];
    foreach (['YearsOfResidency' => null, 'ResidencySince' => 'since', 'YearStartedResiding' => 'since'] as $c => $kind) {
        if (!empty($res[$c])) {
            $v['years_of_residency'] = $kind === 'since' ? (string)max(0, (int)date('Y', $issued) - (int)substr((string)$res[$c], 0, 4)) : (string)$res[$c];
            break;
        }
    }
    // Extra information fields + the old per-field columns (read-only, for requests made before this revision).
    $extra = json_decode((string)($req['extra_data'] ?? ''), true) ?: [];
    foreach (['business_name', 'business_address', 'nature_of_business', 'employment_purpose', 'years_of_residency'] as $old) {
        if (!empty($req[$old]) && !isset($extra[$old])) $extra[$old] = $req[$old];
    }
    foreach ($extra as $k => $val) $v['extra.' . $k] = is_scalar($val) ? (string)$val : '';
    if (!isset($v['years_of_residency']) && isset($extra['years_of_residency'])) $v['years_of_residency'] = (string)$extra['years_of_residency'];
    return array_map(fn($x) => (string)$x, $v);
}

/** Labels for keys in a layout (catalog + this document's extra fields). */
function cert_field_labels(PDO $pdo, string $docType): array {
    $labels = array_map(fn($f) => $f['label'], cert_field_catalog($pdo));
    foreach (cert_extra_fields($pdo, $docType) as $ef) $labels['extra.' . $ef['field_key']] = $ef['label'];
    return $labels;
}

/**
 * Freeze the template layout + all values at generation time, so a later template
 * edit never changes a document that was already issued.
 */
function cert_build_snapshot(PDO $pdo, array $req): array {
    $res = cert_resident($pdo, (int)$req['ResidentID']) ?? [];
    $tpl = cert_template_for($pdo, (string)$req['DocType']);
    $snap = [
        'template_id' => $tpl['id'] ?? null,
        'doc_type' => $req['DocType'],
        'paper_size' => cert_paper($tpl['paper_size'] ?? 'a4')['key'],
        'bg_image' => $tpl['background_image_path'] ?? null,
        'bg_opacity' => isset($tpl['bg_opacity']) ? (float)$tpl['bg_opacity'] : 1.0,
        'positions' => $tpl ? cert_positions($pdo, (int)$tpl['id']) : [],
        'legacy_blocks' => [],
        'values' => cert_field_values($pdo, $req, $res),
        'labels' => cert_field_labels($pdo, (string)$req['DocType']),
        'created_at' => date('Y-m-d H:i:s'),
    ];
    // Read-only fallback for documents designed with the removed "Custom Layout" option.
    if ($tpl && !$snap['positions'] && !empty($tpl['custom_layout_elements'])) {
        $blocks = json_decode((string)$tpl['custom_layout_elements'], true);
        if (is_array($blocks)) {
            $tokens = [];
            foreach ($snap['values'] as $k => $val) $tokens['{{' . $k . '}}'] = $val;
            foreach (cert_legacy_key_map() as $old => $new) $tokens['{{' . $old . '}}'] = $new ? ($snap['values'][$new] ?? '') : '';
            foreach ($blocks as $el) {
                if (!is_array($el)) continue;
                $snap['legacy_blocks'][] = [
                    'x' => (float)($el['x'] ?? 0), 'y' => (float)($el['y'] ?? 0), 'width' => (float)($el['width'] ?? 50),
                    'font_size' => (float)($el['fontSize'] ?? 14), 'font_weight' => (string)($el['fontWeight'] ?? 'normal'),
                    'text_align' => (string)($el['textAlign'] ?? 'left'), 'color' => (string)($el['color'] ?? '#000'),
                    'text' => strtr((string)($el['content'] ?? ''), $tokens),
                ];
            }
        }
    }
    return $snap;
}

/** What the preview / editor / print draws for one request. */
function cert_render_model(PDO $pdo, array $req): array {
    $snap = json_decode((string)($req['render_snapshot'] ?? ''), true);
    if (!is_array($snap)) $snap = cert_build_snapshot($pdo, $req);
    $override = json_decode((string)($req['layout_override'] ?? ''), true);
    $positions = is_array($override) ? $override : ($snap['positions'] ?? []);
    $paper = cert_paper($snap['paper_size'] ?? 'a4');
    $fields = [];
    foreach ($positions as $p) {
        $p = cert_clean_position($p);
        if ($p['field_key'] === '') continue;
        $p['value'] = $snap['values'][$p['field_key']] ?? '';
        if ($p['field_label'] === '') $p['field_label'] = $snap['labels'][$p['field_key']] ?? $p['field_key'];
        $fields[] = $p;
    }
    return [
        'paper' => $paper,
        'bg_image' => cert_bg_url($snap['bg_image'] ?? null),
        'bg_opacity' => (float)($snap['bg_opacity'] ?? 1),
        'fields' => $fields,
        'legacy_blocks' => $snap['legacy_blocks'] ?? [],
        'values' => $snap['values'] ?? [],
        'labels' => $snap['labels'] ?? [],
        'has_override' => is_array($override),
    ];
}

/** Template images are stored relative to the CAPS root (upload/certificates/...). */
function cert_bg_url(?string $path): ?string {
    if (!$path) return null;
    if (preg_match('#^https?://#i', $path)) return $path;
    $path = preg_replace('#^(\.\./)+#', '', $path);
    return '/CAPS/' . ltrim($path, '/');
}

/* ───────────────────────── Requests ───────────────────────── */

function cert_request(PDO $pdo, int $id): ?array {
    $s = $pdo->prepare("SELECT dr.*, r.FirstName, r.MiddleName, r.LastName, r.Suffix, r.ResidentCode, r.Purok
                        FROM document_requests dr LEFT JOIN residents r ON r.ResidentID = dr.ResidentID
                        WHERE dr.RequestID = ?");
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function cert_request_logs(PDO $pdo, int $id): array {
    $s = $pdo->prepare("SELECT status, note, by_name, DATE_FORMAT(created_at, '%b %e, %Y %l:%i %p') AS at
                        FROM document_request_logs WHERE request_id = ? ORDER BY created_at, id");
    $s->execute([$id]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Create an online request (used by the resident portal and by tests).
 * Status starts at Pending; no document number until it is accepted.
 */
function cert_create_online_request(PDO $pdo, int $residentId, string $docType, string $purpose, array $extra = [], array $requirements = []): int {
    $ref = next_record_id($pdo, 'REF', ['table' => 'document_requests', 'column' => 'ReferenceNo']);
    $pdo->prepare("INSERT INTO document_requests (ResidentID, ReferenceNo, DocType, Purpose, extra_data, requirements_checked,
                   Status, request_type, online_submitted_at, DateRequested, notif_read)
                   VALUES (?,?,?,?,?,?, 'Pending', 'online', NOW(), NOW(), 0)")
        ->execute([$residentId, $ref, $docType, $purpose, json_encode($extra, JSON_UNESCAPED_UNICODE), json_encode(array_values($requirements), JSON_UNESCAPED_UNICODE)]);
    $id = (int)$pdo->lastInsertId();
    cert_status_log($pdo, $id, CERT_ST_PENDING, 'Online request submitted by the resident.', 'Resident', null);
    return $id;
}

/** Printable reports reuse the Officials module's report building blocks. */
function cert_require_report_common(): void {
    foreach ([__DIR__ . '/../../officials/backend/report_common.php', __DIR__ . '/../../officials/officials/backend/report_common.php'] as $f) {
        if (is_file($f)) { require_once $f; return; }
    }
    throw new RuntimeException('officials/backend/report_common.php is missing.');
}

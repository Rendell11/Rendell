<?php
/**
 * blotter_common.php — shared logic for the Blotter / Case Management module.
 *
 * Include after admin/db.php (+ auth_check.php on pages/endpoints):
 *   require_once __DIR__.'/blotter_common.php';
 *   blt_migrate($pdo);
 *
 * Contents
 *   - status model + the case workflow rules (configurable time-based / hearing-based limit)
 *   - CSRF, actor, permission ('blotter' module key) helpers
 *   - schema bootstrap: runs sql/blotter.sql once when the Blotter tables are missing
 *   - Blotter ID (BLO-YYYY-####) through the shared id_helper.php
 *   - parties (complainants / respondents), addresses, contact / date validation
 *   - evidence upload rules (same limits as the other CAPS uploads) + upload folders
 *   - notice (Summons / Notice of Hearing) field catalog, values and render snapshot
 *   - case timeline (insert only)
 */

require_once __DIR__ . '/../../id_helper.php';
require_once __DIR__ . '/../../activity_log_helper.php';

if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

/* ───────────────────────── Status model ───────────────────────── */

const BLT_ST_FILED          = 'Filed';
const BLT_ST_FOR_HEARING    = 'For Hearing';        // needs a (new) hearing schedule, e.g. after a cancelled hearing
const BLT_ST_SCHEDULED      = 'Hearing Scheduled';
const BLT_ST_NOTICE_PENDING = 'Notice Pending';     // notice generated, not yet served
const BLT_ST_NOTICE_ISSUED  = 'Notice Issued';
const BLT_ST_HEARING_DONE   = 'Hearing Completed';  // recorded on the timeline when a result is saved
const BLT_ST_NEXT_HEARING   = 'For Next Hearing';
const BLT_ST_RESOLVED       = 'Resolved';
const BLT_ST_CLOSED         = 'Closed';
const BLT_ST_FOR_TRANSFER   = 'For Transfer';
const BLT_ST_TRANSFERRED    = 'Transferred';
const BLT_ST_CANCELLED      = 'Cancelled';
const BLT_ST_WITHDRAWN      = 'Withdrawn';

const BLT_MAX_UPLOAD = 10 * 1024 * 1024; // same limit as Announcements attachments

function blt_statuses(): array {
    return [BLT_ST_FILED, BLT_ST_FOR_HEARING, BLT_ST_SCHEDULED, BLT_ST_NOTICE_PENDING, BLT_ST_NOTICE_ISSUED, BLT_ST_HEARING_DONE,
            BLT_ST_NEXT_HEARING, BLT_ST_RESOLVED, BLT_ST_CLOSED, BLT_ST_FOR_TRANSFER, BLT_ST_TRANSFERRED, BLT_ST_CANCELLED, BLT_ST_WITHDRAWN];
}

/** Open cases (still being handled by the barangay). */
function blt_active_statuses(): array {
    return [BLT_ST_FILED, BLT_ST_FOR_HEARING, BLT_ST_SCHEDULED, BLT_ST_NOTICE_PENDING, BLT_ST_NOTICE_ISSUED, BLT_ST_HEARING_DONE,
            BLT_ST_NEXT_HEARING, BLT_ST_FOR_TRANSFER];
}

/** A new hearing may be scheduled only in these statuses. */
function blt_can_schedule_statuses(): array {
    return [BLT_ST_FILED, BLT_ST_FOR_HEARING, BLT_ST_NEXT_HEARING, BLT_ST_HEARING_DONE];
}

/** A scheduled hearing waits for its result in these statuses. */
function blt_hearing_pending_statuses(): array {
    return [BLT_ST_SCHEDULED, BLT_ST_NOTICE_PENDING, BLT_ST_NOTICE_ISSUED];
}

/** Statuses shown in filters — only the ones that apply to the configured workflow. */
function blt_workflow_statuses(PDO $pdo): array {
    $list = blt_statuses();
    $hasNotice = false;
    try { $hasNotice = (bool)blt_available_notice_types($pdo); } catch (Throwable $e) {}
    $used = [];
    try { $used = $pdo->query("SELECT DISTINCT Status FROM blotter")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
    return array_values(array_filter($list, function ($s) use ($hasNotice, $used) {
        if (in_array($s, [BLT_ST_NOTICE_PENDING, BLT_ST_NOTICE_ISSUED], true) && !$hasNotice && !in_array($s, $used, true)) return false;
        if ($s === BLT_ST_HEARING_DONE && !in_array($s, $used, true)) return false; // transient: only on the timeline
        return true;
    }));
}

function blt_status_meta(string $status): array {
    $map = [
        BLT_ST_FILED          => ['bg-slate-100 text-slate-600 border-slate-200', 'note_add'],
        BLT_ST_FOR_HEARING    => ['bg-amber-50 text-amber-700 border-amber-200', 'event_busy'],
        BLT_ST_SCHEDULED      => ['bg-indigo-50 text-indigo-700 border-indigo-200', 'event'],
        BLT_ST_NOTICE_PENDING => ['bg-amber-50 text-amber-700 border-amber-200', 'mark_email_unread'],
        BLT_ST_NOTICE_ISSUED  => ['bg-sky-50 text-sky-700 border-sky-200', 'mark_email_read'],
        BLT_ST_HEARING_DONE   => ['bg-violet-50 text-violet-700 border-violet-200', 'gavel'],
        BLT_ST_NEXT_HEARING   => ['bg-orange-50 text-orange-700 border-orange-200', 'update'],
        BLT_ST_RESOLVED       => ['bg-emerald-50 text-emerald-700 border-emerald-200', 'handshake'],
        BLT_ST_CLOSED         => ['bg-emerald-50 text-emerald-800 border-emerald-300', 'task_alt'],
        BLT_ST_FOR_TRANSFER   => ['bg-red-50 text-red-700 border-red-200', 'forward'],
        BLT_ST_TRANSFERRED    => ['bg-rose-50 text-rose-700 border-rose-200', 'local_police'],
        BLT_ST_CANCELLED      => ['bg-slate-100 text-slate-500 border-slate-200', 'block'],
        BLT_ST_WITHDRAWN      => ['bg-slate-100 text-slate-500 border-slate-200', 'undo'],
    ];
    [$cls, $icon] = $map[$status] ?? ['bg-slate-100 text-slate-600 border-slate-200', 'help'];
    return ['class' => $cls, 'icon' => $icon, 'label' => $status];
}

function blt_transfer_destinations(): array {
    return ['BPSO' => 'BPSO / Tanod Chief', 'Police' => 'Police (PNP)', 'Other' => 'Other Authority'];
}

/* ───────────────────────── Session / security ───────────────────────── */

function blt_csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    if (empty($_SESSION['blt_csrf'])) $_SESSION['blt_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['blt_csrf'];
}

/** For JSON endpoints: token from POST field or X-CSRF-Token header. */
function blt_csrf_verify(): void {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $sent = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = (string)($_SESSION['blt_csrf'] ?? '');
    if ($sent !== '' && $expected !== '' && hash_equals($expected, $sent)) return;
    blt_json(['success' => false, 'message' => 'Your session expired. Please refresh the page and try again.'], 419);
}

function blt_json(array $data, int $code = 200): void {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Display name of the logged-in admin/staff. */
function blt_actor_name(PDO $pdo): string {
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

function blt_actor_id(): ?string {
    $id = $_SESSION['admin_id'] ?? $_SESSION['employee_id'] ?? null;
    return $id !== null ? (string)$id : null;
}

function blt_is_admin(): bool {
    return ($_SESSION['role'] ?? '') === 'admin';
}

/**
 * Action-level permission (permission_helper.php): admin always passes; staff need the
 * 'blotter' module in their workload (auth_check) AND the action flag.
 */
function blt_can(PDO $pdo, string $action): bool {
    if (blt_is_admin()) return true;
    $helper = __DIR__ . '/../../permission_helper.php';
    if (is_file($helper)) require_once $helper;
    if (function_exists('staff_can')) {
        try { return staff_can($pdo, 'blotter', $action); } catch (Throwable $e) { return false; }
    }
    return true;
}

function blt_require(PDO $pdo, string $action): void {
    if (!blt_can($pdo, $action)) {
        blt_json(['success' => false, 'message' => 'You do not have permission to ' . $action . ' blotter records.'], 403);
    }
}

function blt_require_admin(): void {
    if (!blt_is_admin()) blt_json(['success' => false, 'message' => 'Only the administrator can change this.'], 403);
}

function blt_log_activity(string $action, string $description): void {
    if (function_exists('log_activity')) log_activity('Blotter', $action, $description);
}

/* ───────────────────────── Schema ───────────────────────── */

function blt_table_exists(PDO $pdo, string $table): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $s->execute([$table]);
    return (bool)$s->fetchColumn();
}

function blt_column_exists(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    if (!isset($cache[$table])) {
        $s = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $s->execute([$table]);
        $cache[$table] = array_flip($s->fetchAll(PDO::FETCH_COLUMN));
    }
    return isset($cache[$table][$column]);
}

/**
 * Blotter tables come from sql/blotter.sql (the one schema source). If they are not
 * imported yet, the script is run here once (DDL — must be called before any transaction).
 */
function blt_migrate(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    id_helper_migrate($pdo);
    $ready = false;
    try {
        // Schema of the latest blotter.sql (Revision 1: free-text notice type + text styles / text boxes; Revision 2: source PDF; Revision 3: IncidentTypeOther; Revision 4: page_images, extra_fields, page_no; Revision 5: notice per hearing, edited / signed notice, auto no-show; Revision 6: attendance, companions, presided by, signed outcome; Revision 7: personnel, several signed copies; Revision 8: Draft letters; Revision 9: Unserved letters; Revision 10: transfer letters, signed complaint / transfer record, proof of transfer; Revision 11: transfer letter template in Settings; Revision 12: one copy for several recipients; Revision 13: minutes per hearing).
        $ready = blt_table_exists($pdo, 'blotter_timeline') && blt_table_exists($pdo, 'blotter_notices')
              && (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blotter' AND COLUMN_NAME = 'CaseYear'")->fetchColumn()
              && (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blotter_template_fields' AND COLUMN_NAME = 'custom_text'")->fetchColumn()
              && (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blotter_notice_templates' AND COLUMN_NAME = 'notice_type' AND DATA_TYPE = 'varchar'")->fetchColumn()
              && (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blotter_notice_templates' AND COLUMN_NAME = 'source_pdf_path'")->fetchColumn()
              && (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blotter' AND COLUMN_NAME = 'IncidentTypeOther'")->fetchColumn()
              && (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blotter_notice_templates' AND COLUMN_NAME = 'extra_fields'")->fetchColumn()
              && (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blotter_template_fields' AND COLUMN_NAME = 'page_no'")->fetchColumn()
              && (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blotter_notices' AND COLUMN_NAME = 'edited_by'")->fetchColumn()
              && (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blotter_hearings' AND COLUMN_NAME = 'notice_required'")->fetchColumn()
              && (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blotter_hearing_results' AND COLUMN_NAME = 'signed_uploaded_by'")->fetchColumn()
              && blt_table_exists($pdo, 'blotter_result_files')
              && (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blotter_notices' AND COLUMN_NAME = 'status' AND COLUMN_TYPE LIKE '%Unserved%'")->fetchColumn()
              && (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blotter_notices' AND COLUMN_NAME = 'purpose'")->fetchColumn()
              && (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blotter' AND COLUMN_NAME = 'TransferRecordType'")->fetchColumn()
              && (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blotter_transfers' AND COLUMN_NAME = 'proof_file_type'")->fetchColumn()
              && (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blotter_settings' AND COLUMN_NAME = 'transfer_template_id'")->fetchColumn()
              && (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blotter_notices' AND COLUMN_NAME = 'recipient_party_ids'")->fetchColumn()
              && (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blotter_settings' AND COLUMN_NAME = 'hearing_minutes'")->fetchColumn();
    } catch (Throwable $e) {}
    if (!$ready) blt_run_sql_file($pdo, __DIR__ . '/../../../sql/blotter.sql');
    blt_upload_dirs();
    blt_flag_overdue($pdo);
    blt_auto_noshow($pdo);
}

function blt_run_sql_file(PDO $pdo, string $file): void {
    if (!is_readable($file)) throw new RuntimeException('sql/blotter.sql is missing. Import it into the CAPS database.');
    $sql = (string)file_get_contents($file);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);          // line comments
    // Split on ';' outside quoted strings (also splits the one-line "PREPARE …; EXECUTE …; DEALLOCATE …" groups).
    $stmts = []; $buf = ''; $quote = null; $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($quote !== null) {
            $buf .= $ch;
            if ($ch === '\\' && $i + 1 < $len) { $buf .= $sql[++$i]; continue; }
            if ($ch === $quote) {
                if ($i + 1 < $len && $sql[$i + 1] === $quote) { $buf .= $sql[++$i]; continue; } // '' escape
                $quote = null;
            }
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') { $quote = $ch; $buf .= $ch; continue; }
        if ($ch === ';') { $stmts[] = $buf; $buf = ''; continue; }
        $buf .= $ch;
    }
    $stmts[] = $buf;
    foreach ($stmts as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        $pdo->exec($stmt);
    }
}

/* ───────────────────────── Upload folders ───────────────────────── */

/** Absolute path of an upload sub-folder (evidence | notices | templates) under the CAPS root. */
function blt_upload_dir(string $sub): string {
    return realpath(__DIR__ . '/../../..') . '/upload/blotter/' . $sub;
}

/**
 * upload/blotter/evidence and /notices are private (served only through attachments.php /
 * notices.php after the login check). /templates only blocks scripts, like upload/certificates.
 */
function blt_upload_dirs(): void {
    $rules = [
        'evidence'  => "Options -Indexes\nRequire all denied\n",
        'notices'   => "Options -Indexes\nRequire all denied\n",
        'templates' => "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|php[0-9]?|pht)$\">\n  Require all denied\n</FilesMatch>\n",
    ];
    foreach ($rules as $sub => $ht) {
        $dir = blt_upload_dir($sub);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (is_dir($dir) && !is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', $ht);
    }
}

/** Allowed evidence files: MIME → extension (checked from the file content, not the name). */
function blt_evidence_types(): array {
    return [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/plain' => 'txt',
        'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov',
        'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav',
    ];
}

function blt_evidence_accept(): string {
    return 'image/jpeg,image/png,image/gif,image/webp,application/pdf,.doc,.docx,.xls,.xlsx,.txt,video/mp4,video/webm,video/quicktime,audio/mpeg,audio/mp4,audio/wav';
}

/**
 * Validate + move one uploaded file. Returns the attachment columns.
 * $file = one entry of $_FILES (name, tmp_name, size, error).
 */
function blt_store_evidence(array $file, int $caseId): array {
    $name = (string)($file['name'] ?? 'file');
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new RuntimeException('"' . $name . '" could not be uploaded.');
    }
    if ((int)$file['size'] > BLT_MAX_UPLOAD) throw new RuntimeException('"' . $name . '" is larger than 10 MB.');
    if ((int)$file['size'] <= 0) throw new RuntimeException('"' . $name . '" is empty.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $types = blt_evidence_types();
    if (!isset($types[$mime])) throw new RuntimeException('"' . $name . '" is not an allowed file type (photo, PDF, Word, Excel, text, video or audio).');
    $ext = $types[$mime];
    if (str_starts_with($mime, 'image/') && !@getimagesize($file['tmp_name'])) throw new RuntimeException('"' . $name . '" is not a valid picture.');
    $dir = blt_upload_dir('evidence');
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('Cannot create the evidence upload folder.');
    $stored = 'ev_' . $caseId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) throw new RuntimeException('Could not save "' . $name . '".');
    return [
        'original_name' => mb_substr(basename(str_replace('\\', '/', $name)), 0, 255),
        'stored_name' => $stored,
        'file_path' => 'upload/blotter/evidence/' . $stored,
        'file_type' => $mime,
        'file_ext' => $ext,
        'file_size' => (int)$file['size'],
    ];
}

/** Normalize $_FILES['x'] (single or multiple) into a list of file arrays. */
function blt_files_list(string $field): array {
    if (empty($_FILES[$field])) return [];
    $f = $_FILES[$field];
    if (!is_array($f['name'])) return ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$f];
    $out = [];
    foreach ($f['name'] as $i => $n) {
        if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $out[] = ['name' => $n, 'type' => $f['type'][$i] ?? '', 'tmp_name' => $f['tmp_name'][$i] ?? '', 'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $f['size'][$i] ?? 0];
    }
    return $out;
}

/** Stream a stored private file (evidence / notice PDF) after the caller checked access. */
function blt_stream_file(string $relPath, string $mime, string $downloadName, bool $inline = true): void {
    $root = realpath(__DIR__ . '/../../..');
    $abs = realpath($root . '/' . ltrim($relPath, '/'));
    if (!$abs || strpos($abs, $root . DIRECTORY_SEPARATOR . 'upload') !== 0 || !is_file($abs)) {
        http_response_code(404); exit('File not found.');
    }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($abs));
    header('X-Content-Type-Options: nosniff');
    $safe = preg_replace('/[^\w .()\-]/u', '_', $downloadName) ?: 'file';
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safe . '"');
    readfile($abs);
    exit;
}

/* ───────────────────────── Settings / options ───────────────────────── */

function blt_settings(PDO $pdo, bool $fresh = false): array {
    static $s = null;
    if ($s !== null && !$fresh) return $s;
    $row = $pdo->query("SELECT * FROM blotter_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) { $pdo->exec("INSERT IGNORE INTO blotter_settings (id) VALUES (1)"); $row = $pdo->query("SELECT * FROM blotter_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: []; }
    $row['rule_type'] = ($row['rule_type'] ?? 'hearing') === 'time' ? 'time' : 'hearing';
    $row['max_days'] = max(1, (int)($row['max_days'] ?? 15));
    $row['max_hearings'] = max(1, (int)($row['max_hearings'] ?? 3));
    $row['noshow_counts'] = (int)($row['noshow_counts'] ?? 1);
    $row['hearing_minutes'] = max(5, min(480, (int)($row['hearing_minutes'] ?? 60)));
    return $s = $row;
}

/** Destination label from the settings (BPSO / Tanod Chief, Police, or the typed other authority). */
function blt_settings_destination(array $set): string {
    $d = blt_transfer_destinations();
    if (($set['transfer_destination'] ?? '') === 'Other') return trim((string)($set['transfer_destination_other'] ?? '')) ?: 'Other Authority';
    return $d[$set['transfer_destination'] ?? 'BPSO'] ?? 'BPSO / Tanod Chief';
}

function blt_rule_label(string $ruleType, ?int $maxDays, ?int $maxHearings): string {
    return $ruleType === 'time' ? 'Time-based: resolve within ' . (int)$maxDays . ' day(s)' : 'Hearing-based: up to ' . (int)$maxHearings . ' hearing(s)';
}

/** "Other" is always offered in File New Case; the admin then types the incident type (blotter.IncidentTypeOther). */
const BLT_TYPE_OTHER = 'Other';

function blt_is_other_type(?string $type): bool {
    return strcasecmp(trim((string)$type), BLT_TYPE_OTHER) === 0;
}

/** Incident type shown on screens / notices: the typed type when "Other" was chosen. */
function blt_case_type_label(array $c): string {
    $t = trim((string)($c['IncidentType'] ?? ''));
    $o = trim((string)($c['IncidentTypeOther'] ?? ''));
    return blt_is_other_type($t) && $o !== '' ? $o : $t;
}

function blt_incident_types(PDO $pdo, bool $activeOnly = true): array {
    return $pdo->query("SELECT id, name, is_active FROM blotter_incident_types" . ($activeOnly ? " WHERE is_active = 1" : "") . " ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Incident Location choices: the barangay's streets and areas (Residents › Manage Area)
 * plus the extra places configured for the Blotter. "Other" is added by the form.
 */
function blt_location_groups(PDO $pdo): array {
    $groups = [];
    $code = '';
    try { $code = (string)$pdo->query("SELECT psgc_barangay_code FROM barangay_profile WHERE id = 1")->fetchColumn(); } catch (Throwable $e) {}
    try {
        $places = $pdo->query("SELECT name FROM blotter_locations WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_COLUMN);
        if ($places) $groups[] = ['label' => 'Places', 'items' => $places];
    } catch (Throwable $e) {}
    if ($code !== '') {
        try {
            $s = $pdo->prepare("SELECT area_name FROM resident_areas WHERE psgc_barangay_code = ? AND status = 'Active' ORDER BY area_type, area_name");
            $s->execute([$code]);
            $areas = $s->fetchAll(PDO::FETCH_COLUMN);
            if ($areas) $groups[] = ['label' => 'Subdivision / Village / Sitio / Purok', 'items' => array_values(array_unique($areas))];
        } catch (Throwable $e) {}
        try {
            $s = $pdo->prepare("SELECT street_name FROM resident_streets WHERE psgc_barangay_code = ? AND status = 'Active' ORDER BY street_name");
            $s->execute([$code]);
            $streets = $s->fetchAll(PDO::FETCH_COLUMN);
            if ($streets) $groups[] = ['label' => 'Streets', 'items' => $streets];
        } catch (Throwable $e) {}
    }
    return $groups;
}

function blt_location_allowed(PDO $pdo, string $value): bool {
    foreach (blt_location_groups($pdo) as $g) if (in_array($value, $g['items'], true)) return true;
    return false;
}

/* ───────────────────────── Barangay / officials ───────────────────────── */

function blt_barangay(PDO $pdo): array {
    static $b = null;
    if ($b !== null) return $b;
    try { $row = $pdo->query("SELECT * FROM barangay_profile WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e) { $row = []; }
    $name = trim((string)($row['brgy_name'] ?? ''));
    $address = trim((string)($row['address'] ?? ''));
    if ($address === '') {
        $address = implode(', ', array_filter([$row['barangay_name'] ?? '', $row['municipality_name'] ?? '', $row['province_name'] ?? ''], fn($x) => trim((string)$x) !== ''));
    }
    $logo = $row['logo_path'] ?? null;
    return $b = [
        'name' => $name !== '' ? $name : 'Barangay',
        'address' => $address,
        'barangay' => $row['barangay_name'] ?? '',
        'municipality' => $row['municipality_name'] ?? '',
        'province' => $row['province_name'] ?? '',
        'logo_path' => $logo,
        'logo_url' => $logo && is_file(realpath(__DIR__ . '/../../..') . '/' . ltrim((string)$logo, '/')) ? '/CAPS/' . ltrim((string)$logo, '/') : null,
        'psgc_barangay_code' => $row['psgc_barangay_code'] ?? '',
    ];
}

/** Present-day Barangay Captain, "HON. FIRST M. LAST" (empty when no captain is recorded). */
function blt_current_captain(PDO $pdo): string {
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
            if ($r) return $name = 'HON. ' . strtoupper(blt_person_name($r));
            break;
        } catch (Throwable $e) { continue; }
    }
    return $name = '';
}

/* ───────────────────────── People / addresses ───────────────────────── */

function blt_person_name(array $r, bool $withMiddle = true): string {
    return trim(preg_replace('/\s+/', ' ', implode(' ', [
        $r['FirstName'] ?? $r['first_name'] ?? '', $withMiddle ? ($r['MiddleName'] ?? $r['middle_name'] ?? '') : '',
        $r['LastName'] ?? $r['last_name'] ?? '', $r['Suffix'] ?? $r['suffix'] ?? '',
    ])));
}

function blt_resident(PDO $pdo, int $id): ?array {
    $s = $pdo->prepare("SELECT * FROM residents WHERE ResidentID = ?");
    $s->execute([$id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    unset($r['Password'], $r['ResetToken'], $r['TokenExpiry']);
    return $r;
}

/** Registered residents live in this barangay: house/street, purok + the barangay address. */
function blt_resident_address(PDO $pdo, array $r): string {
    $b = blt_barangay($pdo);
    $line1 = trim(($r['HouseNumber'] ?? '') . ' ' . ($r['StreetName'] ?? ''));
    $purok = trim((string)($r['Purok'] ?? ''));
    if ($purok !== '' && stripos($purok, 'purok') !== 0 && ctype_digit($purok)) $purok = 'Purok ' . $purok;
    $brgy = $b['barangay'] !== '' ? $b['barangay'] : $b['name'];
    $city = $b['municipality'];
    $prov = $b['province'];
    // Relocated to another barangay: use the resident's own barangay / city / province.
    if (blt_resident_moved_out($pdo, $r)) {
        $brgy = trim((string)($r['BarangayName'] ?? ''));
        $city = trim((string)($r['CityMunicipalityName'] ?? ''));
        $prov = trim((string)($r['ProvinceName'] ?? ''));
    }
    return implode(', ', array_filter([$line1, $purok, $brgy, $city, $prov], fn($x) => trim((string)$x) !== ''));
}

/** True when the resident's address on record is in another barangay (relocated out). */
function blt_resident_moved_out(PDO $pdo, array $r): bool {
    $b = blt_barangay($pdo);
    $code = trim((string)($r['PSGCBarangayCode'] ?? ''));
    $own = trim((string)$b['psgc_barangay_code']);
    if ($code !== '' && $own !== '') return $code !== $own;
    $name = trim((string)($r['BarangayName'] ?? ''));
    $ownName = trim((string)($b['barangay'] !== '' ? $b['barangay'] : $b['name']));
    return $name !== '' && $ownName !== '' && blt_addr_key($name) !== blt_addr_key($ownName)
        && stripos(blt_addr_key($ownName), blt_addr_key($name)) === false;
}

/** Address compared without case, spaces or punctuation. */
function blt_addr_key(string $a): string {
    return preg_replace('/[^a-z0-9]/', '', strtolower(str_replace(['ñ', 'Ñ'], 'n', $a)));
}

/** Name shown for a party; unknown respondents show their alias / description instead. */
function blt_party_name(array $p): string {
    $n = blt_person_name($p);
    if ($n !== '') return $n;
    if (trim((string)($p['alias'] ?? '')) !== '') return trim((string)$p['alias']);
    return ($p['role'] ?? '') === 'Respondent' ? 'Unidentified Respondent' : 'Unknown';
}

/** One-line description of an unknown / partly known respondent, e.g. "Unknown Male – motorcycle plate ABC-1234". */
function blt_party_label(array $p): string {
    $name = blt_party_name($p);
    if (!empty($p['is_identified'])) return $name;
    $bits = array_filter([
        trim((string)($p['sex'] ?? '')),
        mb_substr(trim((string)($p['physical_description'] ?? '')), 0, 60),
        trim((string)($p['vehicle'] ?? '')),
        trim((string)($p['plate_number'] ?? '')) !== '' ? 'plate ' . trim((string)$p['plate_number']) : '',
    ]);
    return $bits ? $name . ' – ' . implode(', ', $bits) : $name;
}

/**
 * Address for new letters / notices. A registered resident who relocated after the case
 * was filed gets the address on their resident profile now (current_address).
 */
function blt_party_address(array $p): string {
    if (trim((string)($p['current_address'] ?? '')) !== '') return trim((string)$p['current_address']);
    return blt_party_filed_address($p);
}

/** Address recorded on the case when the party was filed / identified (never changes). */
function blt_party_filed_address(array $p): string {
    if (trim((string)($p['full_address'] ?? '')) !== '') return trim((string)$p['full_address']);
    return implode(', ', array_filter([$p['house_street'] ?? '', $p['barangay_name'] ?? '', $p['municipality_name'] ?? '', $p['province_name'] ?? ''], fn($x) => trim((string)$x) !== ''));
}

const BLT_PARTY_RES_COLS = ['HouseNumber', 'StreetName', 'Purok', 'BarangayName', 'CityMunicipalityName', 'ProvinceName', 'PSGCBarangayCode'];

function blt_parties(PDO $pdo, int $caseId): array {
    $s = $pdo->prepare("SELECT p.*, r.ResidentCode, r.ResidentID AS r_ResidentID,
                               r.HouseNumber AS r_HouseNumber, r.StreetName AS r_StreetName, r.Purok AS r_Purok,
                               r.BarangayName AS r_BarangayName, r.CityMunicipalityName AS r_CityMunicipalityName,
                               r.ProvinceName AS r_ProvinceName, r.PSGCBarangayCode AS r_PSGCBarangayCode
                        FROM blotter_parties p LEFT JOIN residents r ON r.ResidentID = p.resident_id
                        WHERE p.blotter_id = ? ORDER BY p.role = 'Respondent', p.sort_order, p.party_id");
    $s->execute([$caseId]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$p) {
        $p['current_address'] = '';
        $p['address_changed'] = false;
        $p['moved_out'] = false;
        $res = [];
        foreach (BLT_PARTY_RES_COLS as $k) { $res[$k] = $p['r_' . $k]; unset($p['r_' . $k]); }
        if (!empty($p['resident_id']) && $p['r_ResidentID'] !== null) {
            $now = blt_resident_address($pdo, $res);
            if ($now !== '' && blt_addr_key($now) !== blt_addr_key(blt_party_filed_address($p))) {
                $p['current_address'] = $now;
                $p['address_changed'] = true;
                $p['moved_out'] = blt_resident_moved_out($pdo, $res);
            }
        }
        unset($p['r_ResidentID']);
    }
    unset($p);
    return $rows;
}

/* ───────────────────────── Validation ───────────────────────── */

/** Contact number: numbers only, exactly 11 digits (e.g. 09171234567). */
function blt_valid_contact(string $v): bool {
    return preg_match('/^\d{11}$/', $v) === 1;
}

function blt_valid_date(?string $d): bool {
    if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    [$y, $m, $day] = array_map('intval', explode('-', $d));
    return checkdate($m, $day, $y);
}

function blt_valid_time(?string $t): bool {
    return is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $t) === 1;
}

/**
 * Incident date cannot be in the future; when it is today the time cannot be later than now.
 * Returns an error message or ''.
 */
function blt_incident_datetime_error(string $date, string $time): string {
    if (!blt_valid_date($date)) return 'Enter a valid incident date.';
    if (!blt_valid_time($time)) return 'Enter a valid incident time.';
    $today = date('Y-m-d');
    if ($date > $today) return 'Incident date cannot be in the future.';
    if ($date === $today && substr($time, 0, 5) > date('H:i')) return 'Incident time cannot be later than the current time (' . date('g:i A') . ').';
    return '';
}

function blt_clean(?string $v, int $max = 255): ?string {
    $v = trim((string)$v);
    if ($v === '') return null;
    return mb_substr(preg_replace('/\s+/u', ' ', $v), 0, $max);
}

function blt_clean_text(?string $v, int $max = 5000): ?string {
    $v = trim(str_replace("\r\n", "\n", (string)$v));
    return $v === '' ? null : mb_substr($v, 0, $max);
}

/* ───────────────────────── Parties (complainant / respondent) ───────────────────────── */

/** Columns of blotter_parties that come from the form. */
function blt_party_columns(): array {
    return ['party_type', 'resident_id', 'first_name', 'middle_name', 'last_name', 'suffix', 'alias', 'sex', 'birth_date', 'contact_number',
            'house_street', 'region_code', 'region_name', 'province_code', 'province_name', 'municipality_code', 'municipality_name',
            'barangay_code', 'barangay_name', 'full_address', 'physical_description', 'clothing', 'vehicle', 'plate_number', 'other_details', 'is_identified'];
}

/**
 * Validate one complainant / respondent from the form and return the blotter_parties values.
 *   Complainant: registered resident or non-resident; full name, birth date, address and an
 *                11-digit contact number are required.
 *   Respondent:  resident or non-resident; only what is known is required (a name, or at least one
 *                identifying detail). "Unknown" was merged into Non-Resident: a respondent without a name is
 *                saved as a not-yet-identified non-resident and can be updated once identified.
 * Non-residents use the same PSGC address (Region → Province → City/Municipality → Barangay)
 * as the Residents module. Errors are appended to $errors.
 */
function blt_build_party(PDO $pdo, array $in, string $role, string $label, array &$errors): array {
    $type = $in['party_type'] ?? '';
    if ($type === 'Unknown') $type = 'Non-Resident';   // older forms: Unknown = Non-Resident without a name
    $type = in_array($type, ['Resident', 'Non-Resident'], true) ? $type : '';
    $err = function (string $m) use (&$errors, $label) { $errors[] = $label . ': ' . $m; };
    if ($type === '') { $err('choose Existing Resident or Non-Resident.'); return []; }
    $p = array_fill_keys(blt_party_columns(), null);
    $p['party_type'] = $type;
    $p['is_identified'] = 1;
    $contact = preg_replace('/\s+/', '', (string)($in['contact_number'] ?? ''));
    $bd = trim((string)($in['birth_date'] ?? ''));
    $checkBirth = function (string $bd) use ($err): bool {
        if (!blt_valid_date($bd)) { $err('enter a valid date of birth.'); return false; }
        if ($bd > date('Y-m-d')) { $err('date of birth cannot be in the future.'); return false; }
        if ($bd < date('Y-m-d', strtotime('-120 years'))) { $err('date of birth is not valid.'); return false; }
        return true;
    };
    $checkContact = function (string $c) use ($err): bool {
        if (!blt_valid_contact($c)) { $err('contact number must be exactly 11 digits (numbers only).'); return false; }
        return true;
    };

    if ($type === 'Resident') {
        $res = blt_resident($pdo, (int)($in['resident_id'] ?? 0));
        if (!$res) { $err('select the resident from the list.'); return []; }
        if (!empty($res['IsDeceased'])) { $err('the selected resident is recorded as deceased.'); return []; }
        $p['resident_id'] = (int)$res['ResidentID'];
        foreach (['first_name' => 'FirstName', 'middle_name' => 'MiddleName', 'last_name' => 'LastName', 'suffix' => 'Suffix', 'sex' => 'Sex'] as $k => $col) $p[$k] = blt_clean($res[$col] ?? null, 100);
        $p['birth_date'] = $res['BirthDate'] ?: null;
        if (!$p['birth_date'] && $bd !== '' && $checkBirth($bd)) $p['birth_date'] = $bd;
        // Contact number of a registered resident: always the one in the resident profile (not typed here); none = no contact.
        $p['contact_number'] = trim((string)($res['ContactNumber'] ?? '')) !== '' ? trim((string)$res['ContactNumber']) : null;
        $b = blt_barangay($pdo);
        $p['house_street'] = blt_clean(trim(($res['HouseNumber'] ?? '') . ' ' . ($res['StreetName'] ?? '')), 255);
        $p['barangay_name'] = $b['barangay'] ?: $b['name'];
        $p['municipality_name'] = $b['municipality'] ?: null;
        $p['province_name'] = $b['province'] ?: null;
        $p['barangay_code'] = $b['psgc_barangay_code'] ?: null;
        $p['full_address'] = blt_clean(blt_resident_address($pdo, $res), 600);
        if ($role === 'Complainant') {
            if (!$p['birth_date']) $err('the resident has no date of birth on record — enter it.');
            if (!$p['house_street'] && !trim((string)$res['Purok'])) $err('the resident has no address on record — update the resident profile first.');
        }
        return $p;
    }

    // Non-resident / unknown: everything typed in the form.
    foreach (['first_name', 'middle_name', 'last_name', 'alias'] as $k) $p[$k] = blt_clean($in[$k] ?? null, $k === 'alias' ? 150 : 100);
    $p['suffix'] = blt_clean($in['suffix'] ?? null, 10);
    $p['sex'] = in_array($in['sex'] ?? '', ['Male', 'Female'], true) ? $in['sex'] : null;
    $p['house_street'] = blt_clean($in['house_street'] ?? null, 255);
    foreach (['region', 'province', 'municipality', 'barangay'] as $lvl) {
        $code = preg_replace('/\D/', '', (string)($in[$lvl . '_code'] ?? ''));
        $p[$lvl . '_code'] = $code !== '' ? $code : null;
        $p[$lvl . '_name'] = $code !== '' ? blt_clean($in[$lvl . '_name'] ?? null, 150) : null;
    }
    $validation = __DIR__ . '/../../residents/backend/address_validation.php';
    // Same PSGC hierarchy check as the Residents module (when the full address is picked).
    if (is_file($validation) && $p['barangay_code'] && $p['municipality_code'] && $p['province_code'] && $p['region_code']) {
        require_once $validation;
        if (function_exists('validateGeoHierarchy')) {
            try { validateGeoHierarchy($pdo, (string)$p['region_code'], (string)$p['province_code'], (string)$p['municipality_code'], (string)$p['barangay_code']); }
            catch (Throwable $e) { $err($e->getMessage()); }
        }
    }
    $p['full_address'] = blt_clean(implode(', ', array_filter([$p['house_street'], $p['barangay_name'], $p['municipality_name'], $p['province_name']])), 600);
    foreach (['physical_description', 'other_details'] as $k) $p[$k] = blt_clean_text($in[$k] ?? null, 2000);
    foreach (['clothing', 'vehicle'] as $k) $p[$k] = blt_clean($in[$k] ?? null, 255);
    $p['plate_number'] = blt_clean(strtoupper((string)($in['plate_number'] ?? '')), 30);

    if ($role === 'Complainant') {
        if (!$p['first_name'] || !$p['last_name']) $err('enter the first and last name.');
        if ($bd === '') $err('enter the date of birth.'); elseif ($checkBirth($bd)) $p['birth_date'] = $bd;
        if ($contact === '') $err('enter the contact number.'); elseif ($checkContact($contact)) $p['contact_number'] = $contact;
        if (!$p['region_code'] || !$p['province_code'] || !$p['municipality_code'] || !$p['barangay_code']) $err('select the region, province, city/municipality and barangay.');
        if (!$p['house_street']) $err('enter the house no. / street.');
        return $p;
    }

    // Respondent: nothing is required except something that identifies the person.
    if ($bd !== '' && $checkBirth($bd)) $p['birth_date'] = $bd;
    if ($contact !== '' && $checkContact($contact)) $p['contact_number'] = $contact;
    $hasName = $p['first_name'] || $p['last_name'];
    $hasDetail = $p['alias'] || $p['physical_description'] || $p['clothing'] || $p['vehicle'] || $p['plate_number'] || $p['other_details'];
    $p['is_identified'] = $hasName ? 1 : 0;   // no name yet = not yet identified (update the respondent later)
    if (!$hasName && !$hasDetail) $err('enter the name, or describe the respondent (alias, appearance, clothing, vehicle, plate number…).');
    return $p;
}

/** Insert one party row for a case. */
function blt_insert_party(PDO $pdo, int $caseId, string $role, array $p, bool $primary, int $order): int {
    $cols = blt_party_columns();
    $sql = "INSERT INTO blotter_parties (blotter_id, role, is_primary, sort_order, " . implode(', ', $cols) . ", identified_at, created_by, created_at)
            VALUES (?,?,?,?," . implode(',', array_fill(0, count($cols), '?')) . ", ?, ?, NOW())";
    $args = [$caseId, $role, $primary ? 1 : 0, $order];
    foreach ($cols as $c) $args[] = $p[$c] ?? null;
    $args[] = !empty($p['is_identified']) ? date('Y-m-d H:i:s') : null;
    $args[] = blt_actor_name($pdo);
    $pdo->prepare($sql)->execute($args);
    return (int)$pdo->lastInsertId();
}

/* ───────────────────────── Case helpers ───────────────────────── */

/** Reserve the next Blotter ID: BLO-[YEAR]-[SEQUENCE]; never reused, never changed. */
function blt_next_case_number(PDO $pdo): array {
    $no = next_record_id($pdo, 'BLO', ['table' => 'blotter', 'column' => 'CaseNumber']);
    $parts = explode('-', $no);
    return ['number' => $no, 'year' => (int)$parts[1], 'seq' => (int)$parts[2]];
}

function blt_case(PDO $pdo, int $id, bool $lock = false): ?array {
    $s = $pdo->prepare("SELECT * FROM blotter WHERE BlotterID = ?" . ($lock ? " FOR UPDATE" : ""));
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function blt_need_case(?array $c, array $allowed = [], string $what = 'do this'): array {
    if (!$c) blt_json(['success' => false, 'message' => 'Case not found.'], 404);
    if ($allowed && !in_array($c['Status'], $allowed, true)) {
        blt_json(['success' => false, 'message' => 'Cannot ' . $what . ': the case is ' . $c['Status'] . '.'], 409);
    }
    return $c;
}

/** Insert-only timeline entry (exact date + time, who, remarks). */
function blt_timeline(PDO $pdo, int $caseId, string $action, ?string $status, string $remarks = '', ?string $refTable = null, ?int $refId = null, ?string $byName = null, ?string $byId = null): void {
    $pdo->prepare("INSERT INTO blotter_timeline (blotter_id, action, status, remarks, ref_table, ref_id, recorded_by, recorded_by_id, recorded_at)
                   VALUES (?,?,?,?,?,?,?,?, NOW())")
        ->execute([$caseId, mb_substr($action, 0, 150), $status, $remarks !== '' ? mb_substr($remarks, 0, 5000) : null, $refTable, $refId,
                   $byName ?? blt_actor_name($pdo), $byId ?? blt_actor_id()]);
}

function blt_set_status(PDO $pdo, int $caseId, string $status, array $extra = []): void {
    $sets = ['Status = ?', 'UpdatedBy = ?']; $args = [$status, blt_actor_name($pdo)];
    foreach ($extra as $col => $val) {
        if (!preg_match('/^\w+$/', $col)) continue;
        $sets[] = "`$col` = ?"; $args[] = $val;
    }
    $args[] = $caseId;
    $pdo->prepare("UPDATE blotter SET " . implode(', ', $sets) . " WHERE BlotterID = ?")->execute($args);
}

function blt_hearings(PDO $pdo, int $caseId): array {
    $s = $pdo->prepare("SELECT h.*, r.outcome, r.remarks AS result_remarks, r.recorded_at AS result_at, r.recorded_by AS result_by, r.counts_toward_limit, r.next_status,
                               r.auto_recorded, r.attendance, r.companions, r.presided_by, r.presided_position, r.signed_file_path, r.signed_file_name, r.signed_uploaded_at, r.signed_uploaded_by, r.personnel
                        FROM blotter_hearings h LEFT JOIN blotter_hearing_results r ON r.hearing_id = h.hearing_id
                        WHERE h.blotter_id = ? ORDER BY h.hearing_no");
    $s->execute([$caseId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Where the case stands against its limit (the rule copied onto the case when it was filed).
 * hearing-based: completed hearings that count (unresolved, and no-shows when configured).
 * time-based:    days since filing vs MaxDays (DeadlineDate).
 */
function blt_limit_state(PDO $pdo, array $case): array {
    $rule = ($case['RuleType'] ?? 'hearing') === 'time' ? 'time' : 'hearing';
    $s = $pdo->prepare("SELECT COUNT(*) FROM blotter_hearing_results WHERE blotter_id = ? AND outcome <> 'Resolved' AND counts_toward_limit = 1");
    $s->execute([(int)$case['BlotterID']]);
    $used = (int)$s->fetchColumn();
    if ($rule === 'time') {
        // Time-based: a number of days to settle the case (the deadline may be extended when it ends).
        $filed = substr((string)($case['FiledAt'] ?: $case['CreatedAt']), 0, 10);
        $deadline = $case['DeadlineDate'] ?: date('Y-m-d', strtotime($filed . ' +' . (int)($case['MaxDays'] ?: 15) . ' days'));
        $daysLeft = (int)floor((strtotime($deadline) - strtotime(date('Y-m-d'))) / 86400);
        $total = max(1, (int)round((strtotime($deadline) - strtotime($filed)) / 86400));
        return ['rule' => 'time', 'max' => $total, 'used' => $used, 'deadline' => $deadline, 'days_left' => $daysLeft,
                'reached' => $daysLeft < 0, 'expired' => $daysLeft < 0, 'extended' => $total > (int)($case['MaxDays'] ?: 15),
                'label' => blt_rule_label('time', (int)($case['MaxDays'] ?: 15), null)];
    }
    $max = (int)($case['MaxHearings'] ?: 3);
    return ['rule' => 'hearing', 'max' => $max, 'used' => $used, 'deadline' => null, 'days_left' => null,
            'reached' => $used >= $max, 'label' => blt_rule_label('hearing', null, $max)];
}

/**
 * Time-based cases past their deadline with no hearing waiting become "For Transfer".
 * Runs on page load / list query (no cron needed), like the Certificates expiry.
 */
function blt_flag_overdue(PDO $pdo): int {
    // Time-based: when the days end, the case is NOT moved by itself — Case Details asks to Extend (more days) or
    // mark it For Transfer. Only a timeline note is added once per deadline.
    try {
        $in = "'" . implode("','", [BLT_ST_FILED, BLT_ST_FOR_HEARING, BLT_ST_NEXT_HEARING, BLT_ST_HEARING_DONE]) . "'";
        $rows = $pdo->query("SELECT b.BlotterID, b.DeadlineDate, b.Status FROM blotter b WHERE b.RuleType = 'time' AND b.DeadlineDate IS NOT NULL AND b.DeadlineDate < CURDATE() AND b.Status IN ($in)
                               AND NOT EXISTS (SELECT 1 FROM blotter_timeline t WHERE t.blotter_id = b.BlotterID AND t.action = 'Resolution Period Ended' AND t.ref_table = 'deadline' AND t.remarks LIKE CONCAT('%', DATE_FORMAT(b.DeadlineDate, '%M %e, %Y'), '%'))")
                    ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return 0; }
    foreach ($rows as $c) {
        try {
            blt_timeline($pdo, (int)$c['BlotterID'], 'Resolution Period Ended', $c['Status'], 'The days to settle the case ended on ' . date('F j, Y', strtotime($c['DeadlineDate'])) . ' without a settlement. Extend the period or mark the case For Transfer.', 'deadline', null, 'System', null);
        } catch (Throwable $e) { error_log('[Blotter] overdue note failed: ' . $e->getMessage()); }
    }
    return count($rows);
}

/**
 * Word-like letter of a text-based notice: only simple formatting is kept (paragraphs, bold / italic / underline, sizes,
 * alignment, lists, tables, lines) and images only as <img data-token="SIGNATURE|BARANGAY_LOGO"> (the picture is added
 * when drawn). Scripts, links, events and outside images are removed.
 */
function blt_clean_doc_html(string $html): string {
    $html = trim($html);
    if ($html === '') return '';
    if (strlen($html) > 150000) $html = substr($html, 0, 150000);
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="__nd">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    $root = $doc->getElementById('__nd');
    if (!$root) return '';
    blt_doc_clean_node($root);
    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    return trim($out);
}
function blt_doc_clean_node(DOMNode $n): void {
    static $allow = ['p', 'div', 'span', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'sup', 'sub', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th',
                     'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'hr', 'img', 'font', 'center', 'blockquote'];
    static $drop = ['script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'form', 'input', 'button', 'textarea', 'select', 'svg', 'math', 'template', 'title', 'head', 'video', 'audio', 'canvas'];
    foreach (iterator_to_array($n->childNodes) as $c) {
        if ($c instanceof DOMComment || $c instanceof DOMProcessingInstruction) { $n->removeChild($c); continue; }
        if (!$c instanceof DOMElement) continue;
        $tag = strtolower($c->nodeName);
        if (in_array($tag, $drop, true)) { $n->removeChild($c); continue; }
        blt_doc_clean_node($c);
        if (!in_array($tag, $allow, true)) { while ($c->firstChild) $n->insertBefore($c->firstChild, $c); $n->removeChild($c); continue; }
        foreach (iterator_to_array($c->attributes) as $a) {
            $name = strtolower($a->name); $v = (string)$a->value;
            if ($name === 'style') { $st = blt_doc_clean_style($v); if ($st === '') $c->removeAttribute($a->name); else $c->setAttribute('style', $st); continue; }
            if (($name === 'colspan' || $name === 'rowspan') && ctype_digit($v) && (int)$v >= 1 && (int)$v <= 20) continue;
            if ($name === 'data-token' && $tag === 'img' && in_array($v, ['SIGNATURE', 'BARANGAY_LOGO'], true)) continue;
            if ($name === 'size' && $tag === 'font' && ctype_digit($v)) continue;
            if ($name === 'class' && $v === 'nd-box') continue;   // a freely placed part of the letter
            $c->removeAttribute($a->name);
        }
        if ($tag === 'img' && !$c->hasAttribute('data-token')) $n->removeChild($c);
    }
}
function blt_doc_clean_style(string $s): string {
    static $ok = ['font-size', 'font-weight', 'font-style', 'font-family', 'text-decoration', 'text-decoration-line', 'text-align', 'text-indent', 'text-transform', 'letter-spacing',
                  'line-height', 'color', 'background-color', 'vertical-align', 'width', 'height', 'min-width', 'max-width', 'min-height', 'margin', 'margin-top', 'margin-bottom',
                  'margin-left', 'margin-right', 'padding', 'padding-top', 'padding-bottom', 'padding-left', 'padding-right', 'border', 'border-top', 'border-bottom', 'border-left',
                  'border-right', 'border-collapse', 'border-radius', 'border-width', 'border-style', 'border-color', 'display', 'flex', 'flex-shrink', 'flex-grow', 'gap',
                  'justify-content', 'align-items', 'object-fit', 'white-space', 'list-style-type', 'position', 'left', 'top', 'right', 'bottom', 'z-index'];
    $out = [];
    foreach (explode(';', $s) as $d) {
        $p = explode(':', $d, 2);
        if (count($p) < 2) continue;
        $k = strtolower(trim($p[0])); $v = trim($p[1]);
        if ($k === '' || $v === '' || !in_array($k, $ok, true) || strlen($v) > 200) continue;
        if ($k === 'position' && !in_array(strtolower($v), ['absolute', 'relative'], true)) continue;   // never fixed / sticky
        if (preg_match('/url\s*\(|expression|javascript:|behavior|[<>"\\\\]/i', $v)) continue;
        $out[] = $k . ':' . $v;
    }
    return implode(';', $out);
}

/** Why the case is transferred (letter for the transfer, transfer form). */
function blt_transfer_reason(PDO $pdo, array $case): string {
    $l = blt_limit_state($pdo, $case);
    return $l['rule'] === 'time' ? 'No settlement within the ' . $l['max'] . '-day period (until ' . date('F j, Y', strtotime($l['deadline'])) . ').'
                                 : 'No settlement after ' . $l['used'] . ' hearing(s).';
}

/**
 * Before a case can be marked Transferred: the transfer record signed by both parties is uploaded, and a letter for the
 * transfer was made and marked issued (signed by the receiving office).
 */
function blt_transfer_ready(PDO $pdo, array $case): array {
    // 1. every complainant and respondent has a letter (for transfer) marked issued — signed copy attached
    $q = $pdo->prepare("SELECT recipient_party_id, recipient_party_ids FROM blotter_notices WHERE blotter_id = ? AND purpose = 'transfer_party' AND status = 'Issued'");
    $q->execute([(int)$case['BlotterID']]);
    $issued = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $issued[] = (int)$r['recipient_party_id']; foreach (array_filter(explode(',', (string)$r['recipient_party_ids'])) as $x) $issued[] = (int)$x; }
    $missing = [];
    foreach (blt_parties($pdo, (int)$case['BlotterID']) as $p) if (!in_array((int)$p['party_id'], $issued, true)) $missing[] = blt_party_label($p) . ' (' . $p['role'] . ')';
    // 2. the letter for the transfer (to the receiving office) was saved
    $q = $pdo->prepare("SELECT COUNT(*) FROM blotter_notices WHERE blotter_id = ? AND purpose = 'transfer_office' AND status IN ('Generated','Issued')");
    $q->execute([(int)$case['BlotterID']]);
    $office = (int)$q->fetchColumn() > 0;
    $q = $pdo->prepare("SELECT COUNT(*) FROM blotter_notices WHERE blotter_id = ? AND purpose = 'transfer_office' AND status <> 'Cancelled'");
    $q->execute([(int)$case['BlotterID']]);
    return ['parties' => !$missing, 'missing' => $missing, 'office' => $office, 'office_made' => (int)$q->fetchColumn() > 0, 'ready' => !$missing && $office];
}

/** How long one hearing takes (Blotter Settings, default 60): two hearings (any case) may not start within this many minutes of each other. */
function blt_hearing_minutes(PDO $pdo): int { return (int)blt_settings($pdo)['hearing_minutes']; }

/** The scheduled hearing that overlaps date + time (null = the slot is free). */
function blt_hearing_conflict(PDO $pdo, string $date, string $time, int $excludeHearingId = 0): ?array {
    $s = $pdo->prepare("SELECT h.hearing_id, h.hearing_no, h.hearing_time, h.location, b.CaseNumber FROM blotter_hearings h JOIN blotter b ON b.BlotterID = h.blotter_id
                        WHERE h.status = 'Scheduled' AND h.hearing_date = ? AND h.hearing_id <> ?
                          AND ABS(TIME_TO_SEC(TIMEDIFF(h.hearing_time, ?))) < ? ORDER BY h.hearing_time LIMIT 1");
    $s->execute([$date, $excludeHearingId, strlen($time) === 5 ? $time . ':00' : $time, blt_hearing_minutes($pdo) * 60]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** A closed case can only be viewed, printed or saved as PDF (the timeline still records the prints). */
function blt_deny_if_closed(PDO $pdo, ?array $c): void {
    if ($c && $c['Status'] === BLT_ST_CLOSED) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        blt_json(['success' => false, 'message' => 'The case is closed: it can only be viewed, printed or saved as PDF.'], 409);
    }
}

/**
 * Store a signed copy / proof (photo, scan or PDF, max 10 MB) in upload/blotter/notices/.
 * Returns [relative path, original name, mime] or throws RuntimeException.
 */
function blt_store_signed(array $f, string $prefix): array {
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'] ?? '')) throw new RuntimeException('The file could not be uploaded.');
    if ($f['size'] > BLT_MAX_UPLOAD) throw new RuntimeException('The file must be 10 MB or smaller.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime] ?? null;
    if (!$ext) throw new RuntimeException('Use a photo (JPG / PNG) or a PDF.');
    $dir = blt_upload_dir('notices');
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = preg_replace('/[^A-Za-z0-9\-_]/', '', $prefix) . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('Could not save the file.');
    return ['upload/blotter/notices/' . $name, mb_substr(basename((string)$f['name']), 0, 255), $mime];
}

/**
 * Every current barangay official and staff member (BPSO / tanod included) with their position — for
 * "who sat with the parties" and the other personnel present at a hearing. [['name','position','source'], …]
 */
function blt_personnel(PDO $pdo): array {
    $out = [];
    try {
        $rows = $pdo->query("SELECT r.FirstName, r.MiddleName, r.LastName, r.Suffix, o.Position FROM officials o JOIN residents r ON r.ResidentID = o.ResidentID
                             WHERE o.ActualEndDate IS NULL AND (o.TermEnd IS NULL OR o.TermEnd >= CURDATE()) ORDER BY o.Position = 'Barangay Captain' DESC, o.Position, r.LastName")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $out[] = ['name' => blt_person_name($r), 'position' => (string)$r['Position'], 'source' => 'Official'];
    } catch (Throwable $e) {}
    try {
        $rows = $pdo->query("SELECT Name, Position FROM staff WHERE EffectiveEnd IS NULL OR EffectiveEnd >= CURDATE() ORDER BY Position, Name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) if (trim((string)$r['Name']) !== '') $out[] = ['name' => trim((string)$r['Name']), 'position' => trim((string)$r['Position']) ?: 'Staff', 'source' => 'Staff'];
    } catch (Throwable $e) {
        try {   // older staff table without EffectiveEnd
            foreach ($pdo->query("SELECT Name, Position FROM staff ORDER BY Position, Name")->fetchAll(PDO::FETCH_ASSOC) as $r)
                if (trim((string)$r['Name']) !== '') $out[] = ['name' => trim((string)$r['Name']), 'position' => trim((string)$r['Position']) ?: 'Staff', 'source' => 'Staff'];
        } catch (Throwable $e2) {}
    }
    $seen = [];
    return array_values(array_filter($out, function ($p) use (&$seen) { $k = mb_strtolower($p['name'] . '|' . $p['position']); if (isset($seen[$k])) return false; return $seen[$k] = true; }));
}

/**
 * Every respondent is ready for a hearing: a registered resident, or a non-resident with first + last name and
 * an address (house / street or barangay) — the letter has to reach the person.
 */
function blt_respondents_identified(PDO $pdo, int $caseId): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM blotter_parties WHERE blotter_id = ? AND role = 'Respondent' AND resident_id IS NULL
                          AND (is_identified = 0 OR COALESCE(TRIM(first_name), '') = '' OR COALESCE(TRIM(last_name), '') = ''
                               OR (COALESCE(TRIM(house_street), '') = '' AND COALESCE(TRIM(barangay_name), '') = ''))");
    $s->execute([$caseId]);
    return (int)$s->fetchColumn() === 0;
}

/** From the day of a hearing onward (held or not), the respondents can no longer be changed. */
function blt_respondents_locked(PDO $pdo, int $caseId): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM blotter_hearings WHERE blotter_id = ? AND status IN ('Scheduled','Completed') AND hearing_date <= CURDATE()");
    $s->execute([$caseId]);
    return (int)$s->fetchColumn() > 0;
}

/**
 * Save the result of a scheduled hearing and move the case on (Resolved / For Next Hearing / For Transfer).
 * Used by Record Result (hearings.php) and by the automatic no-show (blt_auto_noshow). Call inside a transaction
 * with the hearing and the case locked. Returns ['status' => next case status, 'message' => …].
 */
function blt_record_hearing_result(PDO $pdo, array $h, array $case, string $outcome, ?string $remarks, ?string $resDetails = null, ?string $resolvedAt = null,
                                   ?string $byName = null, ?string $byId = null, bool $auto = false, array $info = []): array {
    $caseId = (int)$h['blotter_id'];
    $byName = $byName ?? blt_actor_name($pdo);
    $byId = $auto ? '' : ($byId ?? blt_actor_id());   // '' = the system (blt_timeline would use the viewer)
    $set = blt_settings($pdo);
    $isNoShow = str_contains($outcome, 'No Show');
    $counts = $outcome === 'Unresolved' ? 1 : ($isNoShow ? (int)$set['noshow_counts'] : 0);
    $pdo->prepare("UPDATE blotter_hearings SET status = 'Completed', updated_at = NOW() WHERE hearing_id = ?")->execute([(int)$h['hearing_id']]);
    $pdo->prepare("INSERT INTO blotter_hearing_results (hearing_id, blotter_id, outcome, remarks, counts_toward_limit, recorded_at, recorded_by, recorded_by_id, auto_recorded)
                   VALUES (?,?,?,?,?, NOW(), ?, ?, ?)")
        ->execute([(int)$h['hearing_id'], $caseId, $outcome, $remarks, $counts, $byName, $byId, $auto ? 1 : 0]);
    $rid = (int)$pdo->lastInsertId();
    // Attendance (who came / reason of the absent), companions and who presided (info from Record Result).
    if ($info) {
        $pdo->prepare("UPDATE blotter_hearing_results SET attendance = ?, companions = ?, presided_by = ?, presided_position = ?, personnel = ? WHERE result_id = ?")
            ->execute([isset($info['attendance']) ? json_encode($info['attendance'], JSON_UNESCAPED_UNICODE) : null,
                       !empty($info['companions']) ? json_encode($info['companions'], JSON_UNESCAPED_UNICODE) : null,
                       $info['presided_by'] ?? null, $info['presided_position'] ?? null,
                       !empty($info['personnel']) ? json_encode($info['personnel'], JSON_UNESCAPED_UNICODE) : null, $rid]);
    }
    blt_timeline($pdo, $caseId, 'Hearing #' . $h['hearing_no'] . ' — ' . $outcome . ($auto ? ' (automatic)' : ''), BLT_ST_HEARING_DONE, (string)$remarks, 'blotter_hearing_results', $rid, $byName, $byId);
    // Letters of this hearing: a Draft was never saved (cancelled); a saved letter with no signed copy returned is "Unserved".
    $pdo->prepare("UPDATE blotter_notices SET status = 'Cancelled' WHERE hearing_id = ? AND status = 'Draft'")->execute([(int)$h['hearing_id']]);
    $un = $pdo->prepare("UPDATE blotter_notices SET status = 'Unserved' WHERE hearing_id = ? AND status = 'Generated'");
    $un->execute([(int)$h['hearing_id']]);
    if ($un->rowCount()) blt_timeline($pdo, $caseId, 'Notice Unserved', BLT_ST_HEARING_DONE, $un->rowCount() . ' letter(s) of hearing #' . $h['hearing_no'] . ' had no signed copy returned before the hearing.', null, null, $byName, $byId);
    if ($outcome === 'Resolved') {
        $pdo->prepare("INSERT INTO blotter_resolutions (blotter_id, hearing_id, resolution_details, remarks, resolved_at, recorded_by, recorded_by_id, recorded_at)
                       VALUES (?,?,?,?,?,?,?, NOW())")
            ->execute([$caseId, (int)$h['hearing_id'], $resDetails, $remarks, $resolvedAt, $byName, $byId]);
        // Resolved → closed automatically: from now on the case is read-only (view / print / save PDF).
        $next = BLT_ST_CLOSED;
        $pdo->prepare("UPDATE blotter SET Status = ?, UpdatedBy = ?, ResolvedAt = ?, ClosedAt = NOW(), CurrentStage = 'Closed', HearingDate = NULL, HearingTime = NULL, HearingLocation = NULL WHERE BlotterID = ?")
            ->execute([$next, $byName, $resolvedAt, $caseId]);
        blt_timeline($pdo, $caseId, 'Case Resolved', BLT_ST_RESOLVED, 'Resolved on ' . date('F j, Y g:i A', strtotime($resolvedAt)) . '. ' . $resDetails, 'blotter_resolutions', null, $byName, $byId);
        blt_timeline($pdo, $caseId, 'Case Closed', $next, 'Closed automatically after the settlement. The case can no longer be changed (view, print and save PDF only).', null, null, $byName, $byId);
        $msg = 'Case resolved and closed. Print the outcome for signing and upload the signed copy.';
    } else {
        $limit = blt_limit_state($pdo, $case);   // counts the result just saved
        // Hearing-based: the last allowed hearing → For Transfer. Time-based: the case stays (Extend or For Transfer is decided in Case Details).
        $next = $limit['reached'] && $limit['rule'] === 'hearing' ? BLT_ST_FOR_TRANSFER : BLT_ST_NEXT_HEARING;
        $pdo->prepare("UPDATE blotter SET Status = ?, UpdatedBy = ?, CurrentStage = ?, HearingDate = NULL, HearingTime = NULL, HearingLocation = NULL WHERE BlotterID = ?")
            ->execute([$next, $byName, $next, $caseId]);
        if ($limit['reached'] && $limit['rule'] === 'time') {
            blt_timeline($pdo, $caseId, 'For Next Hearing', $next, 'The days to settle the case ended on ' . date('F j, Y', strtotime($limit['deadline'])) . '. Extend the period or mark the case For Transfer.', null, null, $byName, $byId);
            $msg = 'Hearing recorded. The days to settle the case have ended — extend the period or mark it For Transfer.';
        } elseif ($limit['reached']) {
            $why = $limit['rule'] === 'time'
                ? 'The resolution period (' . $limit['max'] . ' days, until ' . date('F j, Y', strtotime($limit['deadline'])) . ') has ended.'
                : $limit['used'] . ' of ' . $limit['max'] . ' hearings used without settlement.';
            blt_timeline($pdo, $caseId, 'Limit Reached — For Transfer', $next, $why . ' Transfer to ' . ($case['TransferDestination'] ?: blt_settings_destination($set)) . '.', null, null, $byName, $byId);
            $msg = 'Hearing recorded. The case reached its limit and is now For Transfer.';
        } else {
            $left = $limit['rule'] === 'time' ? $limit['days_left'] . ' day(s) left until ' . date('F j, Y', strtotime($limit['deadline'])) : ($limit['max'] - $limit['used']) . ' hearing(s) left';
            blt_timeline($pdo, $caseId, 'For Next Hearing', $next, ($counts ? '' : 'This no-show does not count toward the limit. ') . $left . '.', null, null, $byName, $byId);
            $msg = 'Hearing recorded. Schedule the next hearing (' . $left . ').';
        }
    }
    $pdo->prepare("UPDATE blotter_hearing_results SET next_status = ? WHERE result_id = ?")->execute([$next, $rid]);
    return ['status' => $next, 'message' => $msg];
}

const BLT_OUTCOME_NO_HEARING = 'Both No Show';

/** Remark used when no hearing took place (neither party appeared). */
function blt_no_hearing_remark(string $when, bool $letterNotServed = false): string {
    return 'No hearing took place on ' . $when . ': neither the complainant nor the respondent appeared, so there was no mediation and no official sat with the parties.'
        . ($letterNotServed ? ' The letter (notice of hearing) was not confirmed as delivered to the respondent before the hearing.' : '');
}

/**
 * A hearing that needed a notice (letter / summons) whose DAY passed (the result can still be recorded on the
 * hearing day, even with a last-minute proof) without any notice marked Issued and without a recorded result is
 * recorded automatically as "Both No Show" — any scheduled hearing with no result by the day after (presiding: None,
 * remark: no hearing took place). Runs on page load, like
 * blt_flag_overdue (no cron needed).
 */
function blt_auto_noshow(PDO $pdo): int {
    try {
        $ids = $pdo->query("SELECT h.hearing_id FROM blotter_hearings h WHERE h.status = 'Scheduled' AND h.hearing_date < CURDATE()")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return 0; }
    $done = 0;
    foreach ($ids as $hid) {
        try {
            $pdo->beginTransaction();
            $s = $pdo->prepare("SELECT * FROM blotter_hearings WHERE hearing_id = ? AND status = 'Scheduled' FOR UPDATE");
            $s->execute([(int)$hid]);
            $h = $s->fetch(PDO::FETCH_ASSOC);
            $case = $h ? blt_case($pdo, (int)$h['blotter_id'], true) : null;
            if (!$h || !$case || !in_array($case['Status'], blt_hearing_pending_statuses(), true)) { $pdo->rollBack(); continue; }
            $issued = $pdo->prepare("SELECT COUNT(*) FROM blotter_notices WHERE hearing_id = ? AND status = 'Issued'");
            $issued->execute([(int)$hid]);
            $wasIssued = (int)$issued->fetchColumn() > 0;
            $when = date('F j, Y g:i A', strtotime($h['hearing_date'] . ' ' . $h['hearing_time']));
            blt_record_hearing_result($pdo, $h, $case, BLT_OUTCOME_NO_HEARING,
                blt_no_hearing_remark($when, !empty($h['notice_required']) && !$wasIssued) . ' Recorded automatically the next day.',
                null, null, 'System', null, true, ['presided_by' => 'None', 'presided_position' => null]);
            $pdo->commit();
            $done++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[Blotter] auto no-show failed: ' . $e->getMessage());
        }
    }
    return $done;
}

/**
 * Generate the notices of a scheduled hearing from the notice type's default template: ONE per person
 * (each is issued / signed on its own, so it is clear who has not been served yet). A person who already has
 * a notice for the hearing is skipped — never a duplicate. Later changes are made with Edit (that notice only,
 * never the template). Call inside a transaction with the hearing and the case locked. Returns the new ids.
 */
function blt_generate_notices(PDO $pdo, array $case, ?array $hearing, string $type, array $recipientIds, array $extraIn, string $actor, int $templateId = 0, string $purpose = 'hearing', bool $joint = false): array {
    // purpose "transfer_party": the letters to the complainant / respondent when the case is For Transfer (one each, issued = signed);
    // "transfer_office": THE letter for the transfer, addressed to the receiving office (PNP / BPSO …) — no party recipient,
    // template from Settings. Neither is tied to a hearing; the hearing values come from the last hearing (if any).
    if ($purpose === 'transfer') $purpose = 'transfer_party';
    $transfer = $purpose === 'transfer_party' || $purpose === 'transfer_office';
    $office = $purpose === 'transfer_office';
    if ($transfer) {
        $lh = $pdo->prepare("SELECT * FROM blotter_hearings WHERE blotter_id = ? ORDER BY hearing_no DESC LIMIT 1");
        $lh->execute([(int)$case['BlotterID']]);
        $hearing = $lh->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$transfer && !$hearing) throw new RuntimeException('No hearing for this letter.');
    $hv = $hearing ?: ['hearing_no' => '', 'hearing_date' => null, 'hearing_time' => null, 'location' => '', 'agenda' => ''];
    $tpl = null;
    if ($templateId) {   // the exact template chosen on Add Hearing / Reschedule / Cancel
        $s = $pdo->prepare("SELECT t.* FROM blotter_notice_templates t WHERE t.template_id = ? AND " . blt_usable_template_sql('t'));
        $s->execute([$templateId]);
        $tpl = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$tpl) throw new RuntimeException('The chosen template is not available (disabled or has no fields).');
        $type = $tpl['notice_type'];
    }
    $type = blt_clean_notice_type($type);
    if (!$type || !in_array($type, blt_available_notice_types($pdo), true)) throw new RuntimeException('Choose a notice type that has a template.');
    $tpl = $tpl ?: blt_template_for($pdo, $type);
    if (!$tpl) throw new RuntimeException('No ' . $type . ' template yet. Create one in Notice Templates.');
    $extra = [];
    foreach (blt_template_extras($tpl) as $x) {
        $v = blt_clean((string)($extraIn[$x['key']] ?? ''), 500);
        if (!$v) throw new RuntimeException('Enter the extra information: ' . $x['label'] . '.');
        $extra[$x['key']] = $v;
    }
    $recipientIds = $office ? [0] : array_values(array_unique(array_map('intval', $recipientIds)));
    $parties = [];
    foreach (blt_parties($pdo, (int)$case['BlotterID']) as $p) $parties[(int)$p['party_id']] = $p;
    // One notice per person per hearing — a person who already has one (not cancelled) never gets a second.
    if ($office) {     // only one letter to the receiving office
        $has = $pdo->prepare("SELECT COUNT(*) FROM blotter_notices WHERE blotter_id = ? AND purpose = 'transfer_office' AND status <> 'Cancelled'");
        $has->execute([(int)$case['BlotterID']]);
        if ((int)$has->fetchColumn()) throw new RuntimeException('The letter for the transfer was already made.');
        $recipientIds = [0];
    } elseif ($transfer) {   // one letter per person (not cancelled)
        $has = $pdo->prepare("SELECT recipient_party_id, recipient_party_ids FROM blotter_notices WHERE blotter_id = ? AND purpose = 'transfer_party' AND status <> 'Cancelled'");
        $has->execute([(int)$case['BlotterID']]);
    } else {
        $has = $pdo->prepare("SELECT recipient_party_id, recipient_party_ids FROM blotter_notices WHERE hearing_id = ? AND purpose = 'hearing' AND status <> 'Cancelled'");
        $has->execute([(int)$hearing['hearing_id']]);
    }
    // People already covered (a letter of their own, or a joint copy listing them in recipient_party_ids).
    $already = [];
    if (!$office) foreach ($has->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['recipient_party_id']) $already[] = (int)$r['recipient_party_id'];
        foreach (array_filter(explode(',', (string)($r['recipient_party_ids'] ?? ''))) as $x) $already[] = (int)$x;
    }
    $dest = $case['TransferDestination'] ?: blt_settings_destination(blt_settings($pdo));
    $ins = $pdo->prepare("INSERT INTO blotter_notices (notice_no, blotter_id, hearing_id, purpose, template_id, notice_type, recipient_party_id, recipient_party_ids, recipient_name, render_snapshot, status, generated_at, generated_by)
                          VALUES (?,?,?,?,?,?,?,?,?,?, 'Draft', NOW(), ?)");   // Draft until saved from the preview
    $ids = []; $names = [];
    // Who gets a letter: one each, one joint copy for everyone chosen ($joint), or the receiving office.
    $todo = $office ? [[null]] : [];
    if (!$office) {
        $people = array_values(array_filter($recipientIds, fn($pid) => isset($parties[$pid]) && !in_array($pid, $already, true)));
        $todo = $joint && count($people) > 1 ? [$people] : array_map(fn($pid) => [$pid], $people);
    }
    $join = function (array $l) { $l = array_values(array_unique(array_filter($l, fn($x) => trim((string)$x) !== ''))); return count($l) < 2 ? ($l[0] ?? '') : implode(', ', array_slice($l, 0, -1)) . ' and ' . end($l); };
    foreach ($todo as $group) {
        $grp = $office ? [] : array_map(fn($pid) => $parties[$pid], $group);
        $p = count($grp) === 1 ? $grp[0] : null;
        $no = next_record_id($pdo, 'NTC', ['table' => 'blotter_notices', 'column' => 'notice_no']);
        $vals = blt_notice_values($pdo, $case, $hv, $p, $no, $actor, $extra);
        if ($office) { $vals['RECIPIENT_NAME'] = $dest; $vals['RECIPIENT_ADDRESS'] = ''; }
        elseif (count($grp) > 1) { $vals['RECIPIENT_NAME'] = $join(array_map('blt_party_label', $grp)); $vals['RECIPIENT_ADDRESS'] = implode('; ', array_unique(array_filter(array_map('blt_party_address', $grp)))); }
        $snap = blt_build_notice_snapshot($pdo, $tpl, $vals);
        $label = $office ? $dest : (count($grp) > 1 ? $join(array_map('blt_party_label', $grp)) . ' (one copy)' : blt_party_label($p));
        $ins->execute([$no, (int)$case['BlotterID'], $transfer ? null : (int)$hearing['hearing_id'], $purpose === 'hearing' ? 'hearing' : $purpose, (int)$tpl['template_id'], $type,
                       $p ? (int)$p['party_id'] : null, $office ? null : implode(',', $group), mb_substr($label, 0, 255), json_encode($snap, JSON_UNESCAPED_UNICODE), $actor]);
        $ids[] = (int)$pdo->lastInsertId();
        $names[] = $no . ' → ' . ($office ? $dest . ' (receiving office)' : ($p ? blt_party_label($p) . ' (' . $p['role'] . ')' : $label));
    }
    if (!$ids) throw new RuntimeException($recipientIds ? ($transfer ? 'Every chosen person already has a letter for the transfer.' : 'Every chosen person already has a notice for this hearing.') : 'Choose at least one recipient.');
    $status = $case['Status'];
    if (!$transfer) {
        $pdo->prepare("UPDATE blotter_hearings SET notice_required = 1 WHERE hearing_id = ?")->execute([(int)$hearing['hearing_id']]);
        $status = $case['Status'] === BLT_ST_SCHEDULED ? BLT_ST_NOTICE_PENDING : $case['Status'];
        if ($status !== $case['Status']) blt_set_status($pdo, (int)$case['BlotterID'], $status);
    }
    blt_timeline($pdo, (int)$case['BlotterID'], 'Notice Generated', $status, $type . ($office ? ' — letter for the transfer to ' . $dest : ($transfer ? ' — letter to the parties (for transfer)' : ' for hearing #' . $hearing['hearing_no'])) . ' (template: ' . $tpl['name'] . '): ' . implode('; ', $names), 'blotter_notices', $ids[0]);
    return $ids;
}

/**
 * Rescheduled hearing: its notice keeps the same Notice No. and edits, only the hearing date / time / place are
 * refreshed. A notice that was already issued goes back to "Generated" (it must be served again).
 * Returns the number of notices updated.
 */
function blt_refresh_notice_schedule(PDO $pdo, array $hearing): int {
    $s = $pdo->prepare("SELECT notice_id, status, render_snapshot FROM blotter_notices WHERE hearing_id = ? AND status <> 'Cancelled' FOR UPDATE");
    $s->execute([(int)$hearing['hearing_id']]);
    $n = 0;
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $snap = json_decode((string)$row['render_snapshot'], true) ?: [];
        $snap['values']['HEARING_DATE'] = date('F j, Y', strtotime($hearing['hearing_date']));
        $snap['values']['HEARING_TIME'] = date('g:i A', strtotime('2000-01-01 ' . $hearing['hearing_time']));
        $snap['values']['HEARING_LOCATION'] = (string)$hearing['location'];
        $snap['values']['HEARING_AGENDA'] = (string)($hearing['agenda'] ?? '');
        $pdo->prepare("UPDATE blotter_notices SET render_snapshot = ?, status = 'Generated', issued_at = NULL, issued_by = NULL, issue_remarks = NULL,
                              issued_file_path = NULL, issued_file_name = NULL, issued_file_type = NULL, file_path = NULL WHERE notice_id = ?")
            ->execute([json_encode($snap, JSON_UNESCAPED_UNICODE), (int)$row['notice_id']]);
        $n++;
    }
    return $n;
}

/** Refresh the display copies on `blotter` (primary names + resident ids) from blotter_parties. */
function blt_sync_party_cache(PDO $pdo, int $caseId): void {
    $parties = blt_parties($pdo, $caseId);
    $pick = function (string $role) use ($parties) {
        $list = array_values(array_filter($parties, fn($p) => $p['role'] === $role));
        usort($list, fn($a, $b) => ((int)$b['is_primary'] <=> (int)$a['is_primary']) ?: ((int)$a['sort_order'] <=> (int)$b['sort_order']));
        return $list;
    };
    $c = $pick('Complainant'); $r = $pick('Respondent');
    $names = fn($list) => mb_substr(implode(', ', array_map('blt_party_label', $list)), 0, 255);
    $pdo->prepare("UPDATE blotter SET ComplainantID = ?, RespondentID = ?, ComplainantName = ?, RespondentName = ? WHERE BlotterID = ?")
        ->execute([
            ($c && $c[0]['resident_id']) ? (string)$c[0]['resident_id'] : null,
            ($r && $r[0]['resident_id']) ? (string)$r[0]['resident_id'] : null,
            $c ? $names($c) : null, $r ? $names($r) : null, $caseId,
        ]);
}

/* ───────────────────────── Paper sizes (same as Certificates) ───────────────────────── */

function blt_paper_sizes(): array {
    return [
        'letter' => ['label' => 'Letter (8.5 × 11 in)',        'w' => 816, 'h' => 1056, 'css' => '8.5in 11in'],
        'a4'     => ['label' => 'A4 (210 × 297 mm)',           'w' => 794, 'h' => 1123, 'css' => '210mm 297mm'],
        'long'   => ['label' => 'Legal / Long (8.5 × 13 in)',  'w' => 816, 'h' => 1248, 'css' => '8.5in 13in'],
        'legal'  => ['label' => 'US Legal (8.5 × 14 in)',      'w' => 816, 'h' => 1344, 'css' => '8.5in 14in'],
    ];
}

function blt_paper(string $key): array {
    $all = blt_paper_sizes();
    return ['key' => isset($all[$key]) ? $key : 'a4'] + ($all[$key] ?? $all['a4']);
}

/* ───────────────────────── Notice templates + dynamic fields ───────────────────────── */

/**
 * Notice types are free text: the barangay picks an existing one from the drop-down or adds a new one
 * (Summons, Notice of Hearing, Subpoena, … — as many as they use). Returns the clean name or ''.
 */
function blt_clean_notice_type($v): string {
    $v = trim(preg_replace('/\s+/u', ' ', (string)$v));
    return (mb_strlen($v) >= 2 && mb_strlen($v) <= 100) ? $v : '';
}

/** Every notice type that has a template (for the builder drop-down). */
function blt_notice_type_list(PDO $pdo): array {
    return $pdo->query("SELECT notice_type FROM blotter_notice_templates GROUP BY notice_type ORDER BY MIN(template_id)")->fetchAll(PDO::FETCH_COLUMN);
}

/** Fonts available in the notice editors (same list as notice_render.js). */
function blt_notice_fonts(): array {
    return ['Times New Roman', 'Georgia', 'Book Antiqua', 'Arial', 'Calibri', 'Tahoma', 'Verdana', 'Courier New', 'Old English'];
}

/**
 * Dynamic fields for Summons / Notice of Hearing. Printed as {{KEY}} in text templates,
 * or placed by key on image templates. Only fields whose source exists in the database
 * / configuration are listed. Returns [key => ['label','group','sample','image'?]].
 */
function blt_notice_catalog(PDO $pdo): array {
    $f = [];
    $add = function ($key, $label, $sample, $group) use (&$f) { $f[$key] = ['label' => $label, 'group' => $group, 'sample' => $sample]; };
    $add('BLOTTER_ID', 'Blotter ID', 'BLO-' . date('Y') . '-0001', 'Case');
    $add('CASE_TYPE', 'Case Type', 'Physical Injury', 'Case');
    $add('INCIDENT_DATE', 'Incident Date', date('F j, Y'), 'Case');
    $add('INCIDENT_TIME', 'Incident Time', '7:30 PM', 'Case');
    $add('INCIDENT_LOCATION', 'Incident Location', 'Mabini Street', 'Case');
    $add('DATE_FILED', 'Date Filed', date('F j, Y'), 'Case');
    $add('COMPLAINANT_NAME', 'Complainant Name', 'JUAN DELA CRUZ', 'Complainant');
    $add('COMPLAINANT_ADDRESS', 'Complainant Address', 'Blk 1 Lot 2 Mabini St., Purok 3', 'Complainant');
    $add('RESPONDENT_NAME', 'Respondent Name', 'PEDRO SANTOS', 'Respondent');
    $add('RESPONDENT_ADDRESS', 'Respondent Address', 'Rizal St., Purok 5', 'Respondent');
    $add('RECIPIENT_NAME', 'Recipient Name', 'PEDRO SANTOS', 'Recipient');
    $add('RECIPIENT_ADDRESS', 'Recipient Address', 'Rizal St., Purok 5', 'Recipient');
    $add('HEARING_NUMBER', 'Hearing Number', '1', 'Hearing');
    $add('HEARING_DATE', 'Hearing Date', date('F j, Y', strtotime('+5 days')), 'Hearing');
    $add('HEARING_TIME', 'Hearing Time', '2:00 PM', 'Hearing');
    $add('HEARING_LOCATION', 'Hearing Location', 'Barangay Hall', 'Hearing');
    $add('HEARING_AGENDA', 'Purpose / Agenda', 'Mediation of the complaint', 'Hearing');
    // Transfer: the letter that goes with the case to the receiving office (signed by whoever receives it there).
    $add('TRANSFER_DESTINATION', 'Transfer To (receiving office)', 'Police (PNP)', 'Transfer');
    $add('TRANSFER_REASON', 'Reason for Transfer', 'No settlement after 3 hearing(s)', 'Transfer');
    $add('TRANSFER_DATE', 'Transfer Date', date('F j, Y'), 'Transfer');
    $add('HEARINGS_HELD', 'Hearings Held', '3', 'Transfer');
    $add('RECEIVED_BY', 'Received By (signature line)', 'Received by: ______________________  Date: __________', 'Transfer');
    $b = blt_barangay($pdo);
    $add('BARANGAY_NAME', 'Barangay Name', $b['name'], 'Barangay');
    if ($b['address'] !== '' || blt_column_exists($pdo, 'barangay_profile', 'address')) $add('BARANGAY_ADDRESS', 'Barangay Address', $b['address'] ?: 'Barangay Hall address', 'Barangay');
    if (blt_column_exists($pdo, 'barangay_profile', 'logo_path')) { $add('BARANGAY_LOGO', 'Barangay Logo', '[logo]', 'Barangay'); $f['BARANGAY_LOGO']['image'] = true; }
    if (blt_table_exists($pdo, 'officials')) $add('BARANGAY_CAPTAIN', 'Barangay Captain', blt_current_captain($pdo) ?: 'HON. BARANGAY CAPTAIN', 'Barangay');
    $add('CURRENT_DATE', 'Current Date', date('F j, Y'), 'System');
    $add('PREPARED_BY', 'Prepared By', 'Barangay Secretary', 'System');
    $add('NOTICE_NUMBER', 'Notice Number', 'NTC-' . date('Y') . '-0001', 'System');
    return $f;
}

/** Catalog grouped for the editors: [{title, items:[{key,label,sample,image}]}]. $textOnly false = drop image fields. */
function blt_catalog_groups(PDO $pdo, bool $withImages = true): array {
    $g = [];
    foreach (blt_notice_catalog($pdo) as $k => $f) {
        if (!$withImages && !empty($f['image'])) continue;
        $g[$f['group']]['title'] = $f['group'];
        $g[$f['group']]['items'][] = ['key' => $k, 'label' => $f['label'], 'sample' => $f['sample'], 'image' => !empty($f['image'])];
    }
    return array_values($g);
}

/** Default letter for a new text-based template (wording picked from the notice type name). */
function blt_default_text_config(string $type): array {
    $summons = stripos($type, 'summon') !== false;
    $hearing = !$summons && stripos($type, 'hearing') !== false;
    return [
        'show_logo' => 1, 'show_barangay_name' => 1, 'show_address' => 1, 'show_date' => 1, 'show_case_block' => 1, 'show_hearing_block' => 1,
        'header_text' => "Republic of the Philippines\nOffice of the Punong Barangay",
        'title' => mb_strtoupper($type !== '' ? $type : 'NOTICE'),
        'recipient' => "TO: {{RECIPIENT_NAME}}\n{{RECIPIENT_ADDRESS}}",
        'subject' => 'Barangay Case No. {{BLOTTER_ID}} — {{CASE_TYPE}}',
        'body' => $summons
            ? "You are hereby summoned to appear before this office in person, together with your witnesses, on {{HEARING_DATE}} at {{HEARING_TIME}} at {{HEARING_LOCATION}}, to answer the complaint filed against you by {{COMPLAINANT_NAME}} regarding {{CASE_TYPE}} (Hearing No. {{HEARING_NUMBER}})."
            : ($hearing
                ? "You are hereby notified that the hearing of Barangay Case No. {{BLOTTER_ID}} between {{COMPLAINANT_NAME}} (complainant) and {{RESPONDENT_NAME}} (respondent) is set on {{HEARING_DATE}} at {{HEARING_TIME}} at {{HEARING_LOCATION}}. Please be present on time."
                : "This is to inform you regarding Barangay Case No. {{BLOTTER_ID}} ({{CASE_TYPE}}) between {{COMPLAINANT_NAME}} and {{RESPONDENT_NAME}}. Please appear on {{HEARING_DATE}} at {{HEARING_TIME}} at {{HEARING_LOCATION}}."),
        'instructions' => $summons
            ? "Failure to appear without a valid reason may lead to the filing of the appropriate action. Bring a valid ID and any document related to this case."
            : "Bring a valid ID and any document or witness related to this case.",
        'signature_image' => null,
        'signatory_name' => '{{BARANGAY_CAPTAIN}}',
        'signatory_title' => 'Punong Barangay',
        'footer' => 'Prepared by: {{PREPARED_BY}} · Notice No. {{NOTICE_NUMBER}}',
        'font_size' => 14,
        'font_family' => 'Times New Roman',
        'body_align' => 'justify',
        'line_height' => 1.55,
        // Word-like letter (Notice Templates → text-based editor). Empty = the older form-based letter above.
        'html' => '',
        'pages' => 1,   // A4 pages of the Word-like letter (1–5)
    ];
}

/** Keep only known keys / safe values of a text template config. */
function blt_clean_text_config(array $in, string $type): array {
    $def = blt_default_text_config($type);
    $out = [];
    foreach ($def as $k => $v) {
        if (str_starts_with($k, 'show_')) { $out[$k] = !empty($in[$k]) ? 1 : 0; continue; }
        if ($k === 'font_size') { $out[$k] = max(10, min(20, (int)($in[$k] ?? 14))); continue; }
        if ($k === 'font_family') { $out[$k] = in_array($in[$k] ?? '', blt_notice_fonts(), true) ? $in[$k] : $v; continue; }
        if ($k === 'body_align') { $out[$k] = in_array($in[$k] ?? '', ['left', 'center', 'right', 'justify'], true) ? $in[$k] : $v; continue; }
        if ($k === 'line_height') { $out[$k] = is_numeric($in[$k] ?? null) ? max(1.0, min(3.0, round((float)$in[$k], 2))) : $v; continue; }
        if ($k === 'html') { $out[$k] = blt_clean_doc_html((string)($in[$k] ?? '')); continue; }
        if ($k === 'pages') { $out[$k] = max(1, min(5, (int)($in[$k] ?? 1))); continue; }
        if ($k === 'signature_image') { $out[$k] = isset($in[$k]) && is_string($in[$k]) && preg_match('#^upload/blotter/templates/[\w.\-]+$#', $in[$k]) ? $in[$k] : null; continue; }
        $out[$k] = mb_substr(str_replace("\r\n", "\n", (string)($in[$k] ?? '')), 0, 3000);
    }
    return $out;
}

function blt_clean_position(array $p): array {
    $num = fn($v, $min, $max, $def) => is_numeric($v) ? max($min, min($max, round((float)$v, 2))) : $def;
    $align = in_array($p['text_align'] ?? '', ['left', 'center', 'right', 'justify'], true) ? $p['text_align'] : 'left';
    $color = preg_match('/^#[0-9a-f]{3,8}$/i', (string)($p['text_color'] ?? '')) ? $p['text_color'] : '#000000';
    $key = substr(preg_replace('/[^A-Z0-9_]/', '', strtoupper((string)($p['field_key'] ?? ''))), 0, 80);
    return [
        'field_key'      => $key,
        'field_label'    => mb_substr(trim((string)($p['field_label'] ?? '')), 0, 150),
        'pos_x'          => $num($p['pos_x'] ?? 50, 0, 100, 50),
        'pos_y'          => $num($p['pos_y'] ?? 50, 0, 100, 50),
        'width'          => (isset($p['width']) && $p['width'] !== '' && $p['width'] !== null) ? $num($p['width'], 2, 100, null) : null,
        'font_size'      => (int)$num($p['font_size'] ?? 14, 6, 96, 14),
        'font_family'    => in_array($p['font_family'] ?? '', blt_notice_fonts(), true) ? $p['font_family'] : 'Times New Roman',
        'font_weight'    => ($p['font_weight'] ?? '') === 'bold' ? 'bold' : 'normal',
        'font_style'     => ($p['font_style'] ?? '') === 'italic' ? 'italic' : 'normal',
        'underline'      => !empty($p['underline']) ? 1 : 0,
        'text_align'     => $align,
        'line_height'    => is_numeric($p['line_height'] ?? null) ? max(0.8, min(3, round((float)$p['line_height'], 2))) : 1.25,
        'letter_spacing' => is_numeric($p['letter_spacing'] ?? null) ? max(-2, min(20, round((float)$p['letter_spacing'], 2))) : 0,
        'text_color'     => $color,
        'uppercase'      => !empty($p['uppercase']) ? 1 : 0,
        // Admin text boxes (TEXT_n) keep their words; may contain {{FIELD}} values.
        'custom_text'    => blt_is_text_box($key) ? mb_substr(str_replace("\r\n", "\n", (string)($p['custom_text'] ?? '')), 0, 3000) : null,
        'page_no'        => max(1, min(BLT_MAX_PAGES, (int)($p['page_no'] ?? $p['page'] ?? 1))),
    ];
}

/** Most pages kept from an uploaded form (PDF / Word). */
const BLT_MAX_PAGES = 10;

/** Page images of an image template: page 1 = background_image_path, then the other pages (page_images JSON). */
function blt_template_pages(array $tpl): array {
    $list = json_decode((string)($tpl['page_images'] ?? ''), true);
    $list = is_array($list) ? array_values(array_filter($list, fn($x) => is_string($x) && preg_match('#^upload/blotter/templates/[\w.\-]+$#', $x))) : [];
    if (!$list && !empty($tpl['background_image_path'])) $list = [$tpl['background_image_path']];
    return array_slice($list, 0, BLT_MAX_PAGES);
}

/** Admin's own "Extra information" fields of a template: [['key' => 'EXTRA_1', 'label' => 'Lupon member assigned'], …]. */
function blt_is_extra_key(string $key): bool {
    return (bool)preg_match('/^EXTRA_\d{1,3}$/', $key);
}
function blt_clean_extra_fields($list): array {
    if (is_string($list)) $list = json_decode($list, true);
    $out = []; $seen = [];
    foreach (is_array($list) ? $list : [] as $x) {
        $key = strtoupper(trim((string)($x['key'] ?? '')));
        $label = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($x['label'] ?? ''))), 0, 100);
        if (!blt_is_extra_key($key) || $label === '' || isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = ['key' => $key, 'label' => $label];
        if (count($out) >= 30) break;
    }
    return $out;
}
function blt_template_extras(array $tpl): array {
    return blt_clean_extra_fields($tpl['extra_fields'] ?? null);
}

/** Field keys of the admin's own text boxes on image templates: TEXT_1, TEXT_2, … */
function blt_is_text_box(string $key): bool {
    return (bool)preg_match('/^TEXT_\d{1,3}$/', $key);
}

/** Replace {{KEY}} in plain text with the notice values. */
function blt_fill_tokens(string $text, array $values): string {
    return preg_replace_callback('/\{\{\s*([A-Z0-9_]+)\s*\}\}/', fn($m) => $m[1] === 'BARANGAY_LOGO' ? '' : (string)($values[$m[1]] ?? ''), $text);
}

/** Template images are stored relative to the CAPS root (upload/blotter/templates/...). */
function blt_file_url(?string $path): ?string {
    if (!$path) return null;
    return '/CAPS/' . ltrim(preg_replace('#^(\.\./)+#', '', $path), '/');
}

function blt_template(PDO $pdo, int $id): ?array {
    $s = $pdo->prepare("SELECT * FROM blotter_notice_templates WHERE template_id = ?");
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** SQL condition: an active template that can be printed (text letter, or an image with placed fields). */
function blt_usable_template_sql(string $alias = 't'): string {
    return "$alias.is_active = 1 AND ($alias.mode = 'text' OR EXISTS (SELECT 1 FROM blotter_template_fields f WHERE f.template_id = $alias.template_id))";
}

/** The template Generate Notice uses for a notice type (the default one, else the newest usable one). */
function blt_template_for(PDO $pdo, string $type): ?array {
    $s = $pdo->prepare("SELECT t.* FROM blotter_notice_templates t WHERE t.notice_type = ? AND " . blt_usable_template_sql('t') . "
                        ORDER BY t.is_default DESC, t.updated_at DESC, t.template_id DESC LIMIT 1");
    $s->execute([$type]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Notice types that have a usable template right now. */
function blt_available_notice_types(PDO $pdo): array {
    return $pdo->query("SELECT DISTINCT t.notice_type FROM blotter_notice_templates t WHERE " . blt_usable_template_sql('t'))->fetchAll(PDO::FETCH_COLUMN);
}

function blt_template_positions(PDO $pdo, int $templateId): array {
    $s = $pdo->prepare("SELECT field_key, field_label, pos_x, pos_y, width, font_size, font_family, font_weight, font_style, underline,
                               text_align, line_height, letter_spacing, text_color, uppercase, custom_text, page_no
                        FROM blotter_template_fields WHERE template_id = ? ORDER BY page_no, field_id");
    $s->execute([$templateId]);
    return array_map('blt_clean_position', $s->fetchAll(PDO::FETCH_ASSOC));
}

/** Every dynamic value for one notice (real database / configuration values). */
function blt_notice_values(PDO $pdo, array $case, array $hearing, ?array $recipient, string $noticeNo, string $preparedBy, array $extra = []): array {
    $parties = blt_parties($pdo, (int)$case['BlotterID']);
    $comp = array_values(array_filter($parties, fn($p) => $p['role'] === 'Complainant'));
    $resp = array_values(array_filter($parties, fn($p) => $p['role'] === 'Respondent'));
    $join = function (array $list, callable $fn) {
        $vals = array_values(array_filter(array_map($fn, $list), fn($x) => trim((string)$x) !== ''));
        if (count($vals) <= 1) return $vals[0] ?? '';
        return implode(', ', array_slice($vals, 0, -1)) . ' and ' . end($vals);
    };
    $b = blt_barangay($pdo);
    $d = fn($v, $f) => $v ? date($f, strtotime((string)$v)) : '';
    return array_map(fn($x) => (string)$x, [
        'BLOTTER_ID' => $case['CaseNumber'],
        'CASE_TYPE' => blt_case_type_label($case),
        'INCIDENT_DATE' => $d($case['IncidentDate'], 'F j, Y'),
        'INCIDENT_TIME' => $d($case['IncidentTime'] ? '2000-01-01 ' . $case['IncidentTime'] : null, 'g:i A'),
        'INCIDENT_LOCATION' => $case['Location'] ?? '',
        'DATE_FILED' => $d($case['FiledAt'] ?: $case['CreatedAt'], 'F j, Y'),
        'COMPLAINANT_NAME' => $join($comp, 'blt_party_name'),
        'COMPLAINANT_ADDRESS' => $join($comp, 'blt_party_address'),
        'RESPONDENT_NAME' => $join($resp, 'blt_party_label'),
        'RESPONDENT_ADDRESS' => $join($resp, 'blt_party_address'),
        'RECIPIENT_NAME' => $recipient ? blt_party_label($recipient) : $join(array_merge($comp, $resp), 'blt_party_name'),
        'RECIPIENT_ADDRESS' => $recipient ? blt_party_address($recipient) : '',
        'HEARING_NUMBER' => $hearing['hearing_no'],
        'HEARING_DATE' => $d($hearing['hearing_date'], 'F j, Y'),
        'HEARING_TIME' => $hearing['hearing_time'] ? $d('2000-01-01 ' . $hearing['hearing_time'], 'g:i A') : '',
        'HEARING_LOCATION' => $hearing['location'],
        'HEARING_AGENDA' => $hearing['agenda'] ?? '',
        'TRANSFER_DESTINATION' => $case['TransferDestination'] ?: blt_settings_destination(blt_settings($pdo)),
        'TRANSFER_REASON' => blt_transfer_reason($pdo, $case),
        'TRANSFER_DATE' => date('F j, Y'),
        'HEARINGS_HELD' => (function () use ($pdo, $case) { $q = $pdo->prepare("SELECT COUNT(*) FROM blotter_hearings WHERE blotter_id = ? AND status = 'Completed'"); $q->execute([(int)$case['BlotterID']]); return (string)$q->fetchColumn(); })(),
        'RECEIVED_BY' => 'Received by: ______________________  Date: __________',
        'BARANGAY_NAME' => $b['name'],
        'BARANGAY_ADDRESS' => $b['address'],
        'BARANGAY_LOGO' => $b['logo_url'] ?? '',
        'BARANGAY_CAPTAIN' => blt_current_captain($pdo),
        'CURRENT_DATE' => date('F j, Y'),
        'PREPARED_BY' => $preparedBy,
        'NOTICE_NUMBER' => $noticeNo,
    ] + $extra);   // the template's Extra information, typed in before generating
}

/**
 * Freeze the template + values when a notice is generated, so a later template edit
 * never changes a notice that was already generated / issued.
 */
function blt_build_notice_snapshot(PDO $pdo, array $tpl, array $values): array {
    $labels = array_map(fn($f) => $f['label'], blt_notice_catalog($pdo));
    foreach (blt_template_extras($tpl) as $x) $labels[$x['key']] = $x['label'];
    $cfg = $tpl['mode'] === 'text' ? blt_clean_text_config(json_decode((string)$tpl['text_config'], true) ?: [], $tpl['notice_type']) : null;
    return [
        'template_id' => (int)$tpl['template_id'],
        'template_name' => $tpl['name'],
        'notice_type' => $tpl['notice_type'],
        'mode' => $tpl['mode'],
        'paper_size' => blt_paper($tpl['paper_size'] ?? 'a4')['key'],
        'bg_image' => $tpl['mode'] === 'image' ? $tpl['background_image_path'] : null,
        'pages' => $tpl['mode'] === 'image' ? blt_template_pages($tpl) : [],
        'bg_opacity' => (float)($tpl['bg_opacity'] ?? 1),
        'positions' => $tpl['mode'] === 'image' ? blt_template_positions($pdo, (int)$tpl['template_id']) : [],
        'text_config' => $cfg,
        'values' => $values,
        'labels' => $labels,
        'created_at' => date('Y-m-d H:i:s'),
    ];
}

/** What notice_render.js draws (preview = print = PDF). */
function blt_notice_render_model(array $snap): array {
    $paper = blt_paper($snap['paper_size'] ?? 'a4');
    $fields = [];
    foreach ($snap['positions'] ?? [] as $p) {
        $p = blt_clean_position($p);
        if ($p['field_key'] === '') continue;
        $p['value'] = blt_is_text_box($p['field_key'])
            ? blt_fill_tokens((string)$p['custom_text'], $snap['values'] ?? [])
            : ($snap['values'][$p['field_key']] ?? '');
        $fields[] = $p;
    }
    $cfg = $snap['text_config'] ?? null;
    return [
        'mode' => $snap['mode'] ?? 'text',
        'paper' => $paper,
        'bg_image' => blt_file_url($snap['bg_image'] ?? null),
        // Every page of a multi-page form (older notices: only bg_image = one page).
        'pages' => array_map(fn($p) => ['bg_image' => blt_file_url($p)], $snap['pages'] ?? []),
        'bg_opacity' => (float)($snap['bg_opacity'] ?? 1),
        'fields' => $fields,
        'config' => $cfg,
        'signature_url' => $cfg && !empty($cfg['signature_image']) ? blt_file_url($cfg['signature_image']) : null,
        'values' => $snap['values'] ?? [],
        'notice_type' => $snap['notice_type'] ?? '',
        // Reference no. printed on every page of every letter (unless the template already shows the Notice No.)
        'ref_no' => (string)($snap['values']['NOTICE_NUMBER'] ?? ''),
    ];
}

/* ───────────────────────── Reports ───────────────────────── */

/** Printable reports reuse the Officials module's report building blocks (same as Certificates). */
function blt_require_report_common(): void {
    foreach ([__DIR__ . '/../../officials/backend/report_common.php', __DIR__ . '/../../officials/officials/backend/report_common.php'] as $f) {
        if (is_file($f)) { require_once $f; return; }
    }
    throw new RuntimeException('officials/backend/report_common.php is missing.');
}

function blt_fmt_duration(?float $minutes): string {
    if ($minutes === null) return '—';
    if ($minutes < 60) return round($minutes) . ' min';
    if ($minutes < 60 * 24) return round($minutes / 60, 1) . ' h';
    return round($minutes / 1440, 1) . ' days';
}

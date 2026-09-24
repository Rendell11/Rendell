<?php
/**
 * ANNOUNCEMENT MODULE - DEVELOPMENT ERROR DIAGNOSTIC
 *
 * Shows the exact PHP error, file, and line instead of a generic HTTP 500.
 * Keep this while debugging locally; disable detailed errors on production.
 */
if (!function_exists('announcement_error_page')) {
    function announcement_error_page(
        string $title,
        string $message,
        ?string $file = null,
        ?int $line = null,
        ?string $trace = null
    ): void {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }

        $safe = static function ($value): string {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };

        $fileLine = ($file ? $safe($file) : 'N/A')
            . ($line !== null ? ':' . $safe($line) : '');

        echo '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Announcement Module Error</title>
<style>
body{margin:0;background:#f8fafc;font-family:Arial,sans-serif;color:#0f172a}
.wrap{max-width:1000px;margin:60px auto;padding:0 20px}
.box{background:#fff;border:1px solid #fecaca;border-radius:14px;padding:24px;box-shadow:0 10px 30px rgba(15,23,42,.08)}
h1{margin:0 0 8px;color:#b91c1c;font-size:22px}
p{color:#64748b}
.label{font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#64748b;margin:20px 0 7px}
pre{white-space:pre-wrap;word-break:break-word;background:#0f172a;color:#e2e8f0;border-radius:9px;padding:14px;overflow:auto;font-size:13px;line-height:1.5}
.hint{margin-top:18px;padding:12px 14px;background:#fff7ed;border:1px solid #fed7aa;border-radius:9px;color:#9a3412;font-size:13px}
</style>
</head>
<body>
<div class="wrap">
<div class="box">
<h1>' . $safe($title) . '</h1>
<p>This page stopped because PHP encountered an error. The details below identify the exact problem.</p>
<div class="label">Error message</div>
<pre>' . $safe($message) . '</pre>
<div class="label">File / Line</div>
<pre>' . $fileLine . '</pre>';

        if ($trace) {
            echo '<div class="label">Trace</div><pre>' . $safe($trace) . '</pre>';
        }

        echo '<div class="hint"><strong>Debug rule:</strong> If this screen appears, send me a screenshot showing the <strong>Error message</strong> and <strong>File / Line</strong> sections. Do not hide those sections.</div>
</div>
</div>
</body>
</html>';
    }
}

set_exception_handler(function (Throwable $e): void {
    announcement_error_page(
        'Announcement Module Error',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
    exit;
});

register_shutdown_function(function (): void {
    $error = error_get_last();

    if (!$error) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    if (in_array($error['type'], $fatalTypes, true)) {
        announcement_error_page(
            'Announcement Module Error',
            $error['message'] ?? 'Unknown fatal PHP error.',
            $error['file'] ?? null,
            isset($error['line']) ? (int) $error['line'] : null
        );
    }
});

function announcement_require(string $path): void
{
    if (!is_file($path)) {
        throw new RuntimeException(
            "Required file was not found: {$path}
"
            . "Check the relative path from ann.php and make sure the file exists."
        );
    }

    require_once $path;
}

announcement_require(__DIR__ . '/../../db.php');
// ── Uniform disaster reference number: DIS-YYYY-0001 ─────────────────────────
// Uses the stored ReportNo column when it exists, otherwise derives it from the
// report's year + padded ReportID so old rows still display a uniform ID.
if (!function_exists('disaster_report_no')) {
    function disaster_report_no(array $row): string
    {
        if (!empty($row['ReportNo']))
            return $row['ReportNo'];
        $year = !empty($row['CreatedAt']) ? date('Y', strtotime($row['CreatedAt'])) : date('Y');
        return sprintf('DIS-%s-%04d', $year, (int) ($row['ReportID'] ?? 0));
    }
}


require_once __DIR__ . '/../../auth_check.php';

// The permission helper requires a real PDO object.  Some versions of the
// authentication/bootstrap files may expose the connection under a different
// variable or may not leave $pdo populated.  Fail here with a useful diagnostic
// instead of allowing require_permission() to throw a confusing TypeError.
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $connectionFile = __DIR__ . '/../../db.php';
    if (is_file($connectionFile)) {
        // db.php is already require_once'd, so only include it if it has not
        // populated the expected PDO connection.
        require $connectionFile;
    }
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    throw new RuntimeException(
        'Database PDO connection is not available before permission check. '
        . 'Expected $pdo from: ' . __DIR__ . '/../../db.php'
    );
}

require_once __DIR__ . '/../../permission_helper.php';
require_permission($pdo, 'announcements', 'read');
announcement_require(__DIR__ . '/../backend/csrf_helper.php');
require_once __DIR__ . '/../../theme_loader.php';

$current_page = "Announcements";
$_theme_head_loaded = true; // We include theme_head.php ourselves inside <head>

$today = date('Y-m-d');
$now_dt = date('Y-m-d H:i:s');

// ── Soft-cron note ────────────────────────────────────────────────────────────
// Scheduled announcements are activated (and Facebook posts fired for fb_pending
// rows) by run_scheduler.php, which is called asynchronously by JS below.
// We do NOT run a blind UPDATE here because that would clear fb_pending without
// actually posting to Facebook — the Facebook step must happen in run_scheduler.php.

// Flash messages
$success = $_SESSION['ann_success'] ?? null;
$error = $_SESSION['ann_error'] ?? null;
unset($_SESSION['ann_success'], $_SESSION['ann_error']);

try {
    // Fetch all non-deleted announcements including fb_post_id
    $stmt = $pdo->query("
        SELECT a.*,
               COUNT(at.id)                      AS attachment_count,
               SUM(at.is_image)                  AS image_count
        FROM   announcements a
        LEFT JOIN announcement_attachments at ON at.announcement_id = a.id
        WHERE  a.deleted_at IS NULL
        GROUP  BY a.id
        ORDER  BY a.date_posted DESC, a.created_at DESC
    ");
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Announcements currently sitting in Trash (soft-deleted, recoverable)
    $trash_count = (int) $pdo->query("SELECT COUNT(*) FROM announcements WHERE deleted_at IS NOT NULL")->fetchColumn();

    // Stats
    $total_count = count($announcements);
    $active_count = 0;
    $upcoming_count = 0;
    $draft_count = 0;

    $scheduled_count = 0; // announcements with status = 'Scheduled' (not yet live)

    foreach ($announcements as $a) {
        if ($a['status'] === 'Draft') {
            // Draft with a past date_end = manually ended; plain Draft = just unpublished (skip both in active count)
            $has_end = !empty($a['date_end']) && $a['date_end'] <= $today;
            if ($has_end)
                $draft_count++; // ended count
            continue;
        }
        if ($a['status'] === 'Ended') {
            $draft_count++;
            continue;
        } // draft_count reused as ended_count

        // Announcements stored as 'Scheduled' in DB — not yet live
        if ($a['status'] === 'Scheduled') {
            $scheduled_count++;
            $upcoming_count++;
            continue;
        }

        // Published announcements — compute active vs expired by date
        $started = !empty($a['date_start']) ? ($a['date_start'] <= $today) : true;
        $ended = !empty($a['date_end']) && $a['date_end'] < $today;
        if ($started && !$ended)
            $active_count++;
    }

} catch (PDOException $e) {
    $announcements = [];
    $trash_count = 0;
    $total_count = $active_count = $upcoming_count = $draft_count = 0;
}

// Category style map
$cat_map = [
    'General' => 'bg-slate-50 text-slate-500 border-slate-100',
    'Health Advisory' => 'bg-blue-50 text-blue-600 border-blue-100',
    'Community Event' => 'bg-purple-50 text-purple-600 border-purple-100',
    'Emergency Notice' => 'bg-orange-50 text-orange-600 border-orange-100',
    'Others' => 'bg-teal-50 text-teal-600 border-teal-100',
];

// ── Issue Alert SMS targeting data ───────────────────────────────────────────
// SOURCE OF TRUTH: the same Resident Management address data.
// Streets: resident_streets + residents.StreetName
// Areas:   resident_areas + residents.AreaName/AreaType
// Legacy:  residents.Purok
//
// IMPORTANT: each query is isolated so one optional/statistics query cannot
// blank the Street/Area lists. This prevents the whole selector from becoming
// empty just because an older database is missing an optional column such as
// vulnerability_type.
$street_counts = [];
$areas_list = [];
$area_counts = [];
$total_active_residents = 0;
$total_sms_residents = 0;
$vulnerable_counts = ['senior' => 0, 'pwd' => 0, 'infant' => 0];
$area_debug_errors = [];
$announcement_barangay_code = '';

// Resident Management scopes Streets/Areas by the active barangay PSGC code.
// Use the same barangay_profile source here so Announcement cannot mix records
// from another barangay if resident_areas/resident_streets ever contain them.
try {
    $profileStmt = $pdo->query("SELECT psgc_barangay_code FROM barangay_profile WHERE id = 1 LIMIT 1");
    $announcement_barangay_code = preg_replace('/\D/', '', (string) $profileStmt->fetchColumn());
} catch (Throwable $e) {
    $area_debug_errors[] = 'Barangay profile lookup: ' . $e->getMessage();
}

try {
    // Resident Management's managed Street list.
    if ($announcement_barangay_code !== '') {
        $stmt = $pdo->prepare("SELECT street_name
             FROM resident_streets
             WHERE status = 'Active' AND psgc_barangay_code = ?
             ORDER BY street_name ASC");
        $stmt->execute([$announcement_barangay_code]);
    } else {
        $stmt = $pdo->query("SELECT street_name
             FROM resident_streets
             WHERE status = 'Active'
             ORDER BY street_name ASC");
    }
    $managedStreets = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Count active residents with SMS contact per street.
    $streetSql = "SELECT StreetName, COUNT(*) AS count
         FROM residents
         WHERE IsDeceased = 0
           AND ContactNumber IS NOT NULL AND TRIM(ContactNumber) <> ''
           AND StreetName IS NOT NULL AND TRIM(StreetName) <> ''";
    $streetParams = [];
    if ($announcement_barangay_code !== '') {
        $streetSql .= " AND PSGCBarangayCode = ?";
        $streetParams[] = $announcement_barangay_code;
    }
    $streetSql .= " GROUP BY StreetName";
    $stmt = $pdo->prepare($streetSql);
    $stmt->execute($streetParams);
    $residentStreetCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    foreach ($managedStreets as $streetName) {
        $streetName = trim((string) $streetName);
        if ($streetName !== '') {
            $street_counts[$streetName] = (int) ($residentStreetCounts[$streetName] ?? 0);
        }
    }

    // Backward compatibility: if a resident has a street saved but the
    // managed master list was not populated, still show the street.
    foreach ($residentStreetCounts as $streetName => $count) {
        $streetName = trim((string) $streetName);
        if ($streetName !== '' && !array_key_exists($streetName, $street_counts)) {
            $street_counts[$streetName] = (int) $count;
        }
    }
    ksort($street_counts, SORT_NATURAL | SORT_FLAG_CASE);
} catch (Throwable $e) {
    $area_debug_errors[] = 'Street lookup: ' . $e->getMessage();
}

try {
    // EXACT same table used by Resident Management > Manage Area, scoped to
    // the same PSGC barangay as the Resident Management address controls.
    if ($announcement_barangay_code !== '') {
        $stmt = $pdo->prepare("SELECT id, area_type, area_name
             FROM resident_areas
             WHERE status = 'Active' AND psgc_barangay_code = ?
             ORDER BY FIELD(area_type, 'Subdivision', 'Village', 'Sitio', 'Purok'), area_name ASC");
        $stmt->execute([$announcement_barangay_code]);
    } else {
        $stmt = $pdo->query("SELECT id, area_type, area_name
             FROM resident_areas
             WHERE status = 'Active'
             ORDER BY FIELD(area_type, 'Subdivision', 'Village', 'Sitio', 'Purok'), area_name ASC");
    }
    $areas_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $area_debug_errors[] = 'Managed area lookup: ' . $e->getMessage();
    $areas_list = [];
}

try {
    // Count active residents with SMS contact for AreaName + AreaType.
    $areaCountSql = "SELECT AreaType, AreaName, COUNT(*) AS count
         FROM residents
         WHERE IsDeceased = 0
           AND ContactNumber IS NOT NULL AND TRIM(ContactNumber) <> ''
           AND AreaName IS NOT NULL AND TRIM(AreaName) <> ''
           AND AreaType IS NOT NULL AND TRIM(AreaType) <> ''";
    $areaCountParams = [];
    if ($announcement_barangay_code !== '') {
        $areaCountSql .= " AND PSGCBarangayCode = ?";
        $areaCountParams[] = $announcement_barangay_code;
    }
    $areaCountSql .= " GROUP BY AreaType, AreaName";
    $stmt = $pdo->prepare($areaCountSql);
    $stmt->execute($areaCountParams);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = trim((string) $row['AreaType']) . '|' . trim((string) $row['AreaName']);
        $area_counts[$key] = (int) $row['count'];
    }
} catch (Throwable $e) {
    $area_debug_errors[] = 'Resident area count: ' . $e->getMessage();
}

try {
    // Legacy Purok is only a fallback when the resident has no managed AreaName.
    // This avoids double-counting a resident who already has AreaName/AreaType.
    $legacyPurokSql = "SELECT TRIM(Purok) AS area_name, COUNT(*) AS count
         FROM residents
         WHERE IsDeceased = 0
           AND ContactNumber IS NOT NULL AND TRIM(ContactNumber) <> ''
           AND (AreaName IS NULL OR TRIM(AreaName) = '')
           AND Purok IS NOT NULL AND TRIM(Purok) <> ''";
    $legacyPurokParams = [];
    if ($announcement_barangay_code !== '') {
        $legacyPurokSql .= " AND PSGCBarangayCode = ?";
        $legacyPurokParams[] = $announcement_barangay_code;
    }
    $legacyPurokSql .= " GROUP BY TRIM(Purok) ORDER BY area_name ASC";
    $stmt = $pdo->prepare($legacyPurokSql);
    $stmt->execute($legacyPurokParams);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = trim((string) $row['area_name']);
        if ($name !== '') {
            $key = 'Purok|' . $name;
            $area_counts[$key] = max($area_counts[$key] ?? 0, (int) $row['count']);

            $found = false;
            foreach ($areas_list as $existing) {
                if (($existing['area_type'] ?? '') === 'Purok' && ($existing['area_name'] ?? '') === $name) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $areas_list[] = ['id' => 0, 'area_type' => 'Purok', 'area_name' => $name];
            }
        }
    }
} catch (Throwable $e) {
    $area_debug_errors[] = 'Legacy Purok lookup: ' . $e->getMessage();
}

try {
    $total_active_residents = (int) $pdo->query(
        "SELECT COUNT(*) FROM residents WHERE IsDeceased = 0"
    )->fetchColumn();
} catch (Throwable $e) {
    $area_debug_errors[] = 'Active resident total: ' . $e->getMessage();
}

try {
    $total_sms_residents = (int) $pdo->query(
        "SELECT COUNT(*) FROM residents
         WHERE IsDeceased = 0
           AND ContactNumber IS NOT NULL AND TRIM(ContactNumber) <> ''"
    )->fetchColumn();
} catch (Throwable $e) {
    $area_debug_errors[] = 'SMS resident total: ' . $e->getMessage();
}

try {
    $vulnerable_counts['senior'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM residents
         WHERE IsDeceased = 0 AND IsSenior = 1
           AND ContactNumber IS NOT NULL AND TRIM(ContactNumber) <> ''"
    )->fetchColumn();
} catch (Throwable $e) {
    $area_debug_errors[] = 'Senior count: ' . $e->getMessage();
}

try {
    $vulnerable_counts['pwd'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM residents
         WHERE IsDeceased = 0 AND IsPWD = 1
           AND ContactNumber IS NOT NULL AND TRIM(ContactNumber) <> ''"
    )->fetchColumn();
} catch (Throwable $e) {
    $area_debug_errors[] = 'PWD count: ' . $e->getMessage();
}

// Do NOT query vulnerability_type here. It is optional/legacy and is not
// required for Street or Subdivision/Village/Sitio/Purok targeting.
$vulnerable_counts['infant'] = 0;

if (isset($_GET['debug']) && $_GET['debug'] === '1' && $area_debug_errors) {
    error_log('[Announcement Area Debug] ' . implode(' | ', $area_debug_errors));
}

// Keep the UI ordering consistent with Resident Management.
usort($areas_list, static function ($a, $b) {
    $order = ['Subdivision' => 1, 'Village' => 2, 'Sitio' => 3, 'Purok' => 4];
    $ta = $order[$a['area_type'] ?? ''] ?? 99;
    $tb = $order[$b['area_type'] ?? ''] ?? 99;
    if ($ta !== $tb)
        return $ta <=> $tb;
    return strnatcasecmp((string) ($a['area_name'] ?? ''), (string) ($b['area_name'] ?? ''));
});

// ── Active Disaster card data (moved over from disaster.php) ─────────────────
$disaster_limit = 4;
$disaster_page = isset($_GET['dpage']) ? (int) $_GET['dpage'] : 1;
if ($disaster_page < 1)
    $disaster_page = 1;
$disaster_offset = ($disaster_page - 1) * $disaster_limit;

try {
    $total_alerts_stmt = $pdo->query("SELECT COUNT(*) FROM disaster_alerts WHERE Status = 'active'");
    $total_alerts = $total_alerts_stmt->fetchColumn();
    $disaster_total_pages = ceil($total_alerts / $disaster_limit);

    $stmt = $pdo->prepare("SELECT * FROM disaster_alerts WHERE Status = 'active' ORDER BY CreatedAt DESC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', (int) $disaster_limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int) $disaster_offset, PDO::PARAM_INT);
    $stmt->execute();
    $active_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $total_alerts = 0;
    $disaster_total_pages = 0;
    $active_alerts = [];
}

// ── SMS Live card data ──────────────────────────────────────────────────────
// Pulled from sms_logs (one row per SMS broadcast, tagged with the disaster's
// AlertID) joined to disaster_alerts for the alert's title/type/severity, so
// each entry can show which disaster it belongs to. sms_logs rows are never
// deleted when a disaster is deactivated, so "Live" (recent, limit 8) plus
// "History" (everything) both stay accurate even after deactivation.
// each entry can show which disaster it belongs to. Live is limited to active
// alerts; a log automatically moves into History after its alert is deactivated.
try {
    $logs = $pdo->query(
        "SELECT sl.LogID, sl.AlertID, sl.total_recipients, sl.sent_count, sl.status,
                TIMESTAMPDIFF(MINUTE, sl.created_at, NOW()) as mins_ago,
                da.Title, da.Type, da.Severity, da.Status as AlertStatus
         FROM sms_logs sl
         INNER JOIN disaster_alerts da ON da.AlertID = sl.AlertID
         WHERE da.Status = 'active'
         ORDER BY sl.created_at DESC
         LIMIT 8"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $logs = [];
}

try {
    $sms_history = $pdo->query(
        "SELECT sl.LogID, sl.AlertID, sl.total_recipients, sl.sent_count, sl.status,
                TIMESTAMPDIFF(MINUTE, sl.created_at, NOW()) as mins_ago,
                da.Title, da.Type, da.Severity, da.Status as AlertStatus
         FROM sms_logs sl
         INNER JOIN disaster_alerts da ON da.AlertID = sl.AlertID
         WHERE da.Status <> 'active'
         ORDER BY sl.created_at DESC
         LIMIT 100"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $sms_history = [];
}

if (!function_exists('render_sms_log_entry')) {
    function render_sms_log_entry(array $log): string
    {
        $progress = ($log['total_recipients'] > 0) ? round(($log['sent_count'] / $log['total_recipients']) * 100) : 0;
        $alertId = $log['AlertID'] ?? null;
        $title = $log['Title'] ?? null;
        $type = $log['Type'] ?? null;
        $severity = $log['Severity'] ?? 'Medium';
        $isDeactivated = isset($log['AlertStatus']) && $log['AlertStatus'] !== 'active';

        $label = $alertId
            ? 'Disaster #' . htmlspecialchars($alertId) . ($type ? ' · ' . htmlspecialchars(strtoupper($type)) : '')
            : 'DISASTER ALERT';

        ob_start();
        ?>
        <div class="p-5">
            <div class="flex justify-between items-start mb-2 gap-2">
                <div class="min-w-0">
                    <h4 class="text-[9px] font-black text-rose-600 uppercase tracking-wider"><?= $label ?></h4>
                    <?php if ($title): ?>
                        <p class="text-[10px] font-bold text-slate-600 truncate mt-0.5"><?= htmlspecialchars($title) ?></p>
                    <?php endif; ?>
                </div>
                <div class="flex flex-col items-end gap-1 flex-shrink-0">
                    <span class="text-[9px] font-medium text-slate-400"><?= format_time_ago((int) $log['mins_ago']) ?></span>
                    <?php if ($isDeactivated): ?>
                        <span
                            class="px-2 py-0.5 rounded-full text-[8px] font-black uppercase tracking-widest bg-slate-100 text-slate-400">Deactivated</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="w-full bg-slate-100 h-1.5 rounded-full overflow-hidden mb-2">
                <div class="h-full bar-fill transition-all duration-1000"
                    style="width: <?= $progress ?>%; background: var(--accent-600);"></div>
            </div>
            <div class="flex justify-between text-[9px] font-bold uppercase">
                <span class="text-slate-400"><?= $progress ?>% Sent
                    (<?= (int) $log['sent_count'] ?>/<?= (int) $log['total_recipients'] ?>)</span>
                <span style="color: var(--accent-600);" class="font-black"><?= htmlspecialchars($log['status']) ?></span>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('format_time_ago')) {
    function format_time_ago(int $mins): string
    {
        if ($mins < 1)
            return 'Just now';
        if ($mins < 60)
            return $mins . 'm ago';
        $hours = floor($mins / 60);
        if ($hours < 24)
            return $hours . ($hours === 1 ? ' hour ago' : ' hours ago');
        $days = floor($hours / 24);
        if ($days < 7)
            return $days . ($days === 1 ? ' day ago' : ' days ago');
        $weeks = floor($days / 7);
        if ($weeks < 5)
            return $weeks . ($weeks === 1 ? ' week ago' : ' weeks ago');
        $months = floor($days / 30);
        if ($months < 12)
            return $months . ($months === 1 ? ' month ago' : ' months ago');
        $years = floor($days / 365);
        return $years . ($years === 1 ? ' year ago' : ' years ago');
    }
}

// ── Disaster Analytics card count ──────────────────────────────────────────────
// The full analytics (charts, AI, disaster reports table) moved to
// disaster_analytics.php; the card only needs the number of reports logged.
try {
    $total_disaster_reports = (int) $pdo->query("SELECT COUNT(*) FROM disaster_reports")->fetchColumn();
} catch (PDOException $e) {
    $total_disaster_reports = 0;
}

// ── Barangay identity + signatories used on the disaster report printout ─────
// Name and logo come from barangay_profile; the Barangay Captain comes from the
// officials table (falls back to the linked resident's name when Name is null).
$brgy_name = 'Barangay';
$brgy_address = '';
$brgy_logo_url = '';
$brgy_captain = 'Barangay Captain';

try {
    $bp = $pdo->query("SELECT brgy_name, logo_path, address FROM barangay_profile WHERE id = 1 LIMIT 1")
        ->fetch(PDO::FETCH_ASSOC);
    if ($bp) {
        if (!empty($bp['brgy_name']))
            $brgy_name = $bp['brgy_name'];
        $brgy_address = $bp['address'] ?? '';

        if (!empty($bp['logo_path']) && file_exists(__DIR__ . '/' . $bp['logo_path'])) {
            // Absolute URL — the print window and html2canvas can't resolve relative paths
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $baseDir = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '')), '/');
            $brgy_logo_url = $scheme . '://' . $host . $baseDir . '/' . ltrim($bp['logo_path'], '/');
        }
    }
} catch (PDOException $e) { /* keep defaults */
}

try {
    $capStmt = $pdo->query(
        "SELECT COALESCE(NULLIF(TRIM(o.Name), ''),
                         NULLIF(TRIM(CONCAT_WS(' ', r.FirstName, r.LastName, r.Suffix)), '')) AS CaptainName
         FROM officials o
         LEFT JOIN residents r ON r.ResidentID = o.ResidentID
         WHERE o.Position LIKE '%Captain%'
         ORDER BY (o.TermEnd IS NULL OR o.TermEnd >= CURDATE()) DESC, o.OfficialID DESC
         LIMIT 1"
    );
    $cap = $capStmt ? $capStmt->fetch(PDO::FETCH_ASSOC) : null;
    if (!empty($cap['CaptainName']))
        $brgy_captain = $cap['CaptainName'];
} catch (PDOException $e) { /* keep default */
}
?>
<!DOCTYPE html>
<html <?php echo $theme_attrs['html']; ?>>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcement Management — Barangay Biñang 2nd</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,400&family=DM+Mono:wght@400;500&display=swap"
        rel="stylesheet">
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
        rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <?php include __DIR__ . '/../../theme_head.php'; ?>
    <script>
        const CSRF_TOKEN = '<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>';

        // Barangay identity + signatories pulled from the database (barangay_profile / officials).
        // Used by the disaster report printout and the PDF so both stay in sync with settings.
        const BRGY = <?php echo json_encode([
            'name' => $brgy_name,
            'address' => $brgy_address,
            'logo' => $brgy_logo_url,
            'captain' => $brgy_captain,
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: { DEFAULT: 'var(--accent-600)', light: 'var(--accent-500)', dark: 'var(--accent-700)' },
                        accent: { DEFAULT: 'var(--accent-500)', light: 'var(--accent-400)' },
                        surface: 'var(--accent-50, #f0f4ff)',
                    },
                    fontFamily: { sans: ['"Plus Jakarta Sans"', 'sans-serif'], mono: ['"DM Mono"', 'monospace'] }
                }
            }
        }
    </script>
    <style>
        :root {
            --sidebar-w: 288px;
            --nav-h: 64px;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--page-bg, #eef2fb);
            -webkit-font-smoothing: antialiased;
        }

        /* ── Layout — mirrors dashboard.php exactly ─────────────────────────── */
        .main-wrapper {
            margin-left: 272px;
            width: calc(100% - 272px);
            transition: margin-left .3s ease, width .3s ease;
        }

        body.sidebar-collapsed .main-wrapper {
            margin-left: 68px;
            width: calc(100% - 68px);
        }

        @media (max-width: 1024px) {
            .main-wrapper {
                margin-left: 0 !important;
                width: 100% !important;
            }
        }

        /* ── Dark mode — mirrors dashboard.php ──────────────────────────────── */
        html.dark body {
            background: #0f172a;
            color: #e2e8f0;
        }

        html.dark .bg-white {
            background: #1e293b !important;
        }

        html.dark .border-slate-100,
        html.dark .border-slate-200,
        html.dark .border-slate-200\/60 {
            border-color: #334155 !important;
        }

        html.dark .text-slate-800 {
            color: #f1f5f9 !important;
        }

        html.dark .text-slate-700 {
            color: #e2e8f0 !important;
        }

        html.dark .text-slate-600 {
            color: #94a3b8 !important;
        }

        html.dark .text-slate-500 {
            color: #64748b !important;
        }

        html.dark .text-slate-400 {
            color: #475569 !important;
        }

        html.dark .bg-slate-50 {
            background: #0f172a !important;
        }

        html.dark .bg-slate-100 {
            background: #1e293b !important;
        }

        .stat-card {
            transition: transform .25s ease, box-shadow .25s ease;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 40px -12px rgba(26, 53, 112, .18);
        }

        /* ── Cards that open a modal on click ────────────────────────────────── */
        .card-clickable {
            transition: transform .25s ease, box-shadow .25s ease, border-color .2s ease;
        }

        .card-clickable:hover {
            border-color: rgba(99, 102, 241, .35);
        }

        .card-clickable:focus-visible {
            outline: 2px solid var(--accent-500, #6366f1);
            outline-offset: 2px;
        }

        .card-clickable .card-info-btn {
            transition: transform .2s ease;
        }

        .card-clickable:hover .card-info-btn {
            transform: scale(1.1);
        }

        .card-accent-bar::before {
            content: '';
            position: absolute;
            inset: 0 0 auto 0;
            height: 3px;
            border-radius: 16px 16px 0 0;
        }

        .card-blue::before {
            background: linear-gradient(90deg, #3b82f6, #6366f1);
        }

        .card-green::before {
            background: linear-gradient(90deg, #10b981, #06b6d4);
        }

        .card-indigo::before {
            background: linear-gradient(90deg, #6366f1, #8b5cf6);
        }

        .card-amber::before {
            background: linear-gradient(90deg, #f59e0b, #f05a00);
        }

        .card-rose::before {
            background: linear-gradient(90deg, #f43f5e, #f97316);
        }

        /* ── Disaster card "!" info trigger ──────────────────────────────────── */
        .card-info-btn {
            width: 28px;
            height: 28px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 13px;
            transition: transform .15s ease, box-shadow .15s ease;
        }

        .card-info-btn:hover {
            transform: scale(1.08);
        }

        .pulse-ring {
            animation: cardRing 1.8s ease infinite;
        }

        @keyframes cardRing {

            0%,
            100% {
                box-shadow: 0 0 0 0 rgba(244, 63, 94, .4);
            }

            50% {
                box-shadow: 0 0 0 8px rgba(244, 63, 94, 0);
            }
        }

        .chart-box {
            position: relative;
            width: 100%;
        }

        .section-title {
            font-size: .65rem;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #94a3b8;
        }

        .hero-gradient {
            background: linear-gradient(135deg, var(--accent-700) 0%, var(--accent-600) 50%, var(--accent-700) 100%);
            position: relative;
            overflow: hidden;
        }

        .hero-gradient::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse at 80% 50%, rgba(var(--accent-500-rgb, 99, 102, 241), .25) 0%, transparent 65%),
                radial-gradient(ellipse at 10% 80%, rgba(99, 102, 241, .2) 0%, transparent 60%);
            pointer-events: none;
        }

        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 99px;
        }

        .table-container::-webkit-scrollbar {
            height: 6px;
        }

        .table-container::-webkit-scrollbar-thumb {
            background: #e2e8f0;
            border-radius: 10px;
        }

        .modal-backdrop {
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(4px);
        }

        .gallery-img {
            object-fit: cover;
            width: 100%;
            height: 100%;
            border-radius: 12px;
        }

        .slide-nav {
            transition: opacity .2s;
        }

        /* Facebook toggle switch */
        .fb-toggle input[type="checkbox"] {
            display: none;
        }

        .fb-toggle .track {
            width: 44px;
            height: 24px;
            border-radius: 12px;
            background: #e2e8f0;
            transition: background .25s;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            padding: 2px;
            flex-shrink: 0;
        }

        .fb-toggle input:checked+.track {
            background: #1877f2;
        }

        .fb-toggle .thumb {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: white;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .25);
            transition: transform .25s;
        }

        .fb-toggle input:checked+.track .thumb {
            transform: translateX(20px);
        }

        .fb-posted-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
            padding: 3px 10px;
            border-radius: 999px;
            background: #e7f0ff;
            color: #1877f2;
            border: 1px solid #c2d6ff;
        }

        /* Issue Alert modal — location picker map */
        .leaflet-container {
            font-family: 'Plus Jakarta Sans', sans-serif;
            z-index: 0;
            border-radius: 1rem;
        }
    </style>
</head>

<body <?php echo $theme_attrs['body']; ?>>

    <div class="flex min-h-screen">
        <?php include __DIR__ . '/../../sidebar.php'; ?>

        <div class="flex-1 flex flex-col min-w-0 main-wrapper">
            <?php include __DIR__ . '/../../header.php'; ?>

            <main class="p-4 md:p-6 lg:p-8 space-y-8">

                <?php if ($success): ?>
                    <script>document.addEventListener('DOMContentLoaded', function () { showToast('success', <?php echo json_encode($success); ?>); });</script>
                <?php endif; ?>
                <?php if ($error): ?>
                    <script>document.addEventListener('DOMContentLoaded', function () { showToast('error', <?php echo json_encode($error); ?>); });</script>
                <?php endif; ?>

                <!-- ── Hero Band — matched to Resident Management ───────────────── -->
                <div class="rounded-2xl p-6 md:p-8 text-white relative overflow-hidden"
                    style="background: linear-gradient(135deg, var(--accent-700) 0%, var(--accent-600) 50%, var(--accent-700) 100%);">
                    <div class="absolute -right-12 -top-12 w-64 h-64 opacity-10 rounded-full blur-3xl pointer-events-none"
                        style="background: var(--accent-400);"></div>
                    <div class="absolute left-1/3 bottom-0 w-48 h-48 opacity-10 rounded-full blur-2xl pointer-events-none"
                        style="background: var(--accent-300);"></div>
                    <div class="relative z-10 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div>
                            <h1 class="text-2xl md:text-3xl font-black tracking-tight leading-none">Announcement
                                Management</h1>
                            <p class="text-white/60 text-sm mt-2 font-medium">Keep your residents informed and safe
                                through scheduled alerts and community updates.</p>
                        </div>
                        <div class="flex flex-wrap gap-3 flex-shrink-0">
                            <button onclick="openModal('issueAlertModal')"
                                class="flex items-center gap-2 bg-white/10 hover:bg-white/20 border border-white/20 text-white px-5 py-2.5 rounded-xl font-bold text-xs uppercase tracking-wider transition-all">
                                <span class="material-symbols-outlined text-lg">crisis_alert</span>
                                Issue Alert
                            </button>
                            <button onclick="openCreateAnnouncementModal()"
                                class="flex items-center gap-2 bg-accent hover:bg-accent-light text-white px-5 py-2.5 rounded-xl font-bold text-xs uppercase tracking-wider transition-all shadow-lg">
                                <span class="material-symbols-outlined text-lg">campaign</span>
                                New Announcement
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ── Stats Cards — same visual treatment as residents.php ─────── -->
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white p-6 rounded-[32px] border border-slate-100 shadow-sm">
                        <div
                            class="w-10 h-10 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center mb-4">
                            <span class="material-symbols-outlined">campaign</span>
                        </div>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Total Announcements
                        </p>
                        <h3 class="text-2xl font-bold text-slate-800 mt-1"><?php echo number_format($total_count); ?>
                        </h3>
                    </div>

                    <div class="bg-white p-6 rounded-[32px] border border-slate-100 shadow-sm">
                        <div
                            class="w-10 h-10 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center mb-4">
                            <span class="material-symbols-outlined">rss_feed</span>
                        </div>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Active Broadcasts</p>
                        <h3 class="text-2xl font-bold text-slate-800 mt-1"><?php echo number_format($active_count); ?>
                        </h3>
                    </div>

                    <div class="bg-white p-6 rounded-[32px] border border-slate-100 shadow-sm">
                        <div
                            class="w-10 h-10 bg-amber-50 text-amber-600 rounded-xl flex items-center justify-center mb-4">
                            <span class="material-symbols-outlined">schedule_send</span>
                        </div>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Scheduled</p>
                        <h3 class="text-2xl font-bold text-slate-800 mt-1">
                            <?php echo number_format($scheduled_count); ?></h3>
                    </div>

                    <div class="bg-white p-6 rounded-[32px] border border-slate-100 shadow-sm">
                        <div
                            class="w-10 h-10 bg-rose-50 text-rose-600 rounded-xl flex items-center justify-center mb-4">
                            <span class="material-symbols-outlined">stop_circle</span>
                        </div>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Ended</p>
                        <h3 class="text-2xl font-bold text-slate-800 mt-1"><?php echo number_format($draft_count); ?>
                        </h3>
                    </div>
                </div>

                <!-- ── Disaster Cards (moved over from Disaster module) ──────────────── -->
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 md:gap-5">

                    <!-- Active Disaster -->
                    <div class="stat-card card-clickable card-accent-bar card-rose bg-white rounded-2xl p-5 shadow-sm border border-slate-200/60 relative overflow-hidden cursor-pointer"
                        role="button" tabindex="0" onclick="openModal('activeDisasterModal')"
                        onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openModal('activeDisasterModal');}"
                        title="View all active disasters">
                        <div class="absolute -right-4 -bottom-4 w-24 h-24 bg-rose-50 rounded-full opacity-60"></div>
                        <div class="relative z-10">
                            <div class="flex items-center justify-between mb-4">
                                <span class="section-title flex items-center gap-2">
                                    <span
                                        class="w-2 h-2 rounded-full bg-rose-500 animate-pulse pulse-ring flex-shrink-0"></span>
                                    Active Disaster
                                </span>
                                <span class="card-info-btn bg-rose-50 text-rose-600">
                                    <span class="material-symbols-outlined" style="font-size:16px">crisis_alert</span>
                                </span>
                            </div>
                            <p class="text-4xl font-black font-mono text-slate-800"><?php echo (int) $total_alerts; ?>
                            </p>
                            <p class="text-[10px] font-bold text-slate-400 uppercase mt-1">Currently active</p>
                        </div>
                    </div>

                    <!-- SMS Live -->
                    <div class="stat-card card-clickable card-accent-bar card-blue bg-white rounded-2xl p-5 shadow-sm border border-slate-200/60 relative overflow-hidden cursor-pointer"
                        role="button" tabindex="0" onclick="openModal('smsLiveModal')"
                        onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openModal('smsLiveModal');}"
                        title="View SMS broadcast status">
                        <div class="absolute -right-4 -bottom-4 w-24 h-24 bg-blue-50 rounded-full opacity-60"></div>
                        <div class="relative z-10">
                            <div class="flex items-center justify-between mb-4">
                                <span class="section-title flex items-center gap-2">
                                    <span
                                        class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse flex-shrink-0"></span>
                                    SMS Live
                                </span>
                                <span class="card-info-btn bg-blue-50 text-blue-600">
                                    <span class="material-symbols-outlined" style="font-size:16px">sms</span>
                                </span>
                            </div>
                            <p class="text-4xl font-black font-mono text-slate-800"><?php echo count($logs); ?></p>
                            <p class="text-[10px] font-bold text-slate-400 uppercase mt-1">Recent broadcasts</p>
                        </div>
                    </div>

                    <!-- Disaster Analytics -->
                    <div class="stat-card card-clickable card-accent-bar card-indigo bg-white rounded-2xl p-5 shadow-sm border border-slate-200/60 relative overflow-hidden cursor-pointer"
                        role="button" tabindex="0"
                        onclick="window.location.href='disaster_analytics.php'"
                        onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();window.location.href='disaster_analytics.php';}"
                        title="Open the Disaster Analytics page">
                        <div class="absolute -right-4 -bottom-4 w-24 h-24 bg-indigo-50 rounded-full opacity-60"></div>
                        <div class="relative z-10">
                            <div class="flex items-center justify-between mb-4">
                                <span class="section-title">Disaster Analytics</span>
                                <span class="card-info-btn bg-indigo-50 text-indigo-600">
                                    <span class="material-symbols-outlined" style="font-size:16px">analytics</span>
                                </span>
                            </div>
                            <p class="text-4xl font-black font-mono text-slate-800">
                                <?php echo $total_disaster_reports; ?></p>
                            <p class="text-[10px] font-bold text-slate-400 uppercase mt-1">Disaster reports logged</p>
                        </div>
                    </div>
                </div>

                <!-- ── Announcement Archive Table ──────────────────────────────────── -->
                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                    <div
                        class="p-6 border-b border-slate-100 flex flex-col md:flex-row justify-between items-center gap-4">
                        <div>
                            <p class="section-title mb-0.5">Archive</p>
                            <h2 class="text-sm font-bold text-slate-700">Announcement Archive</h2>
                        </div>
                        <div class="flex items-center gap-3 w-full md:w-auto">
                            <div class="relative flex-1 md:w-64">
                                <span
                                    class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"
                                    style="font-size:18px">search</span>
                                <input type="text" id="searchInput" placeholder="Search archive..."
                                    class="w-full pl-10 pr-4 py-2 bg-slate-50 border-none rounded-xl text-sm focus:ring-2 focus:ring-primary/20 font-medium">
                            </div>
                            <select id="filterStatus"
                                class="bg-slate-50 border-none rounded-xl text-xs font-bold text-slate-700 px-3 py-2 focus:ring-2 focus:ring-primary/20 cursor-pointer">
                                <option value="">All Status</option>
                                <option value="ACTIVE">Active</option>
                                <option value="SCHEDULED">Scheduled</option>
                                <option value="EXPIRED">Expired</option>
                                <option value="ENDED">Ended</option>
                            </select>
                            <!-- Announcement / Meta analytics (not Disaster Analytics) -->
                            <a href="announcement_analytics.php"
                                class="flex items-center gap-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-600 rounded-xl text-xs font-bold px-3 py-2 whitespace-nowrap transition-colors"
                                title="Announcement posting & Facebook engagement analytics">
                                <span class="material-symbols-outlined" style="font-size:16px">analytics</span>
                                View Analytics
                            </a>
                        </div>
                    </div>

                    <div class="table-container overflow-x-auto">
                        <table class="w-full text-left border-collapse" id="annTable">
                            <thead>
                                <tr class="bg-slate-50/50">
                                    <th class="px-6 py-4 section-title">ID</th>
                                    <th class="px-6 py-4 section-title">Title</th>
                                    <th class="px-6 py-4 section-title">Category</th>
                                    <th class="px-6 py-4 section-title">Posted On</th>
                                    <th class="px-6 py-4 section-title">Status</th>
                                    <th class="px-6 py-4 section-title">Facebook</th>
                                    <th class="px-6 py-4 section-title">Attachments</th>
                                    <th class="px-6 py-4 section-title text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <?php if (!empty($announcements)): ?>
                                    <?php foreach ($announcements as $ann):
                                        $cat_style = $cat_map[$ann['category']] ?? 'bg-slate-50 text-slate-500 border-slate-100';

                                        if ($ann['status'] === 'Ended') {
                                            $status_label = 'ENDED';
                                            $status_color = 'text-amber-500';
                                            $dot = 'bg-amber-400';
                                        } elseif ($ann['status'] === 'Draft') {
                                            // Draft with a past/present date_end = was manually ended; plain Draft = unpublished
                                            $has_end_date = !empty($ann['date_end']) && $ann['date_end'] <= $today;
                                            if ($has_end_date) {
                                                $status_label = 'ENDED';
                                                $status_color = 'text-amber-500';
                                                $dot = 'bg-amber-400';
                                            } else {
                                                $status_label = 'DRAFT';
                                                $status_color = 'text-slate-400';
                                                $dot = 'bg-slate-400';
                                            }
                                        } elseif ($ann['status'] === 'Scheduled') {
                                            // DB says Scheduled — cron hasn't fired yet, never show as Active
                                            $status_label = 'SCHEDULED';
                                            $status_color = 'text-indigo-500';
                                            $dot = 'bg-indigo-400';
                                        } elseif (!empty($ann['date_end']) && $ann['date_end'] < $today) {
                                            $status_label = 'EXPIRED';
                                            $status_color = 'text-slate-400';
                                            $dot = 'bg-slate-400';
                                        } else {
                                            $status_label = 'ACTIVE';
                                            $status_color = 'text-emerald-500';
                                            $dot = 'bg-emerald-500';
                                        }

                                        $fb_post_id = $ann['fb_post_id'] ?? null;
                                        $fb_pending = !empty($ann['fb_pending']) && $ann['status'] === 'Scheduled';
                                        ?>
                                        <tr class="hover:bg-slate-50/50 transition-colors group ann-row"
                                            data-ann-id="<?php echo $ann['id']; ?>"
                                            data-title="<?php echo strtolower(htmlspecialchars($ann['title'])); ?>"
                                            data-title-raw="<?php echo htmlspecialchars($ann['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-status="<?php echo $status_label; ?>">
                                            <td class="px-6 py-5">
                                                <span class="text-primary font-bold text-sm font-mono">ANN-
                                                    <?php
                                                    $ann_year = !empty($ann['created_at']) ? date('Y', strtotime($ann['created_at'])) : date('Y', strtotime($ann['date_posted']));
                                                    echo $ann_year . '-' . str_pad((int) ($ann['ann_id'] ?? 0), 4, '0', STR_PAD_LEFT);
                                                    ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-5 font-semibold text-slate-700 text-sm max-w-xs truncate">
                                                <?php echo htmlspecialchars($ann['title']); ?></td>
                                            <td class="px-6 py-5">
                                                <span
                                                    class="px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase border <?php echo $cat_style; ?>">
                                                    <?php
                                                    $cat_display = ($ann['category'] === 'Others' && !empty($ann['category_other']))
                                                        ? $ann['category_other']
                                                        : $ann['category'];
                                                    echo htmlspecialchars($cat_display);
                                                    ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-5 text-slate-500 text-xs font-medium">
                                                <?php echo date('M d, Y', strtotime($ann['date_posted'])); ?></td>
                                            <td class="px-6 py-5 ann-status-cell">
                                                <div
                                                    class="flex items-center gap-2 <?php echo $status_color; ?> text-[10px] font-bold uppercase">
                                                    <span class="w-1.5 h-1.5 rounded-full <?php echo $dot; ?>"></span>
                                                    <?php echo $status_label; ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-5">
                                                <?php if ($fb_post_id): ?>
                                                    <span class="fb-posted-pill">
                                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="#1877f2">
                                                            <path
                                                                d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.312 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z" />
                                                        </svg>
                                                        Posted
                                                    </span>
                                                <?php elseif ($fb_pending): ?>
                                                    <span
                                                        style="display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;padding:3px 10px;border-radius:999px;background:#fff7ed;color:#f59e0b;border:1px solid #fde68a;">
                                                        <span class="material-symbols-outlined"
                                                            style="font-size:12px">schedule</span>
                                                        Queued
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-slate-300 text-xs">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-5">
                                                <?php if ($ann['attachment_count'] > 0): ?>
                                                    <div class="flex items-center gap-2 text-slate-500 text-xs font-semibold">
                                                        <?php if ($ann['image_count'] > 0): ?>
                                                            <span
                                                                class="flex items-center gap-1 bg-blue-50 text-blue-600 px-2 py-1 rounded-lg">
                                                                <span class="material-symbols-outlined"
                                                                    style="font-size:14px">image</span>
                                                                <?php echo (int) $ann['image_count']; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php $file_count = $ann['attachment_count'] - $ann['image_count']; ?>
                                                        <?php if ($file_count > 0): ?>
                                                            <span
                                                                class="flex items-center gap-1 bg-slate-50 text-slate-500 px-2 py-1 rounded-lg">
                                                                <span class="material-symbols-outlined"
                                                                    style="font-size:14px">attach_file</span>
                                                                <?php echo (int) $file_count; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-slate-300 text-xs">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-5">
                                                <div
                                                    class="flex justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                                    <button onclick='openView(<?php echo $ann["id"]; ?>)'
                                                        class="p-2 bg-indigo-50 text-indigo-600 rounded-xl hover:bg-indigo-100 transition-all"
                                                        title="View">
                                                        <span class="material-symbols-outlined"
                                                            style="font-size:17px">visibility</span>
                                                    </button>
                                                    <?php if (in_array($status_label, ['ENDED', 'EXPIRED'], true) && staff_can($pdo, 'announcements', 'update')): ?>
                                                        <button onclick="openRecoverPost(<?php echo (int) $ann['id']; ?>)"
                                                            class="p-2 bg-emerald-50 text-emerald-600 rounded-xl hover:bg-emerald-100 transition-all"
                                                            title="Recover Post">
                                                            <span class="material-symbols-outlined"
                                                                style="font-size:17px">replay</span>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (staff_can($pdo, 'announcements', 'delete')): ?>
                                                        <button
                                                            onclick="confirmDelete(<?php echo $ann['id']; ?>, '<?php echo addslashes(htmlspecialchars($ann['title'])); ?>')"
                                                            class="p-2 bg-rose-50 text-rose-500 rounded-xl hover:bg-rose-100 transition-all"
                                                            title="Delete">
                                                            <span class="material-symbols-outlined"
                                                                style="font-size:17px">delete</span>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="px-6 py-20 text-center text-slate-400 italic text-sm">No
                                            announcements found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="px-6 py-4 border-t border-slate-100 flex justify-between items-center">
                        <span id="tableCount" class="section-title">Showing <?php echo count($announcements); ?>
                            entries</span>
                    </div>
                </div>



            </main>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
     VIEW MODAL
═══════════════════════════════════════════ -->
    <div id="viewModal"
        class="hidden fixed inset-0 z-50 modal-backdrop flex items-start justify-center pt-8 pb-8 px-4 overflow-y-auto">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl relative" id="viewModalBox">

            <div class="flex items-start justify-between p-6 md:p-8 border-b border-slate-100">
                <div>
                    <p class="section-title text-primary mb-1" id="vm_id">#ANN-0000</p>
                    <h2 class="text-xl font-bold text-slate-800 leading-tight" id="vm_title">—</h2>
                    <div class="flex items-center gap-3 mt-2 flex-wrap">
                        <span id="vm_category_badge"
                            class="px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase border bg-slate-50 text-slate-500 border-slate-100">General</span>
                        <span id="vm_status_badge"
                            class="px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase bg-emerald-50 text-emerald-600">Active</span>
                        <span id="vm_fb_posted_badge" class="hidden fb-posted-pill">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="#1877f2">
                                <path
                                    d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.312 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z" />
                            </svg>
                            Posted to Facebook
                        </span>
                    </div>
                </div>
                <button onclick="closeView()"
                    class="p-2 hover:bg-slate-100 rounded-xl transition-colors text-slate-400 ml-4 shrink-0">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>

            <div class="p-6 md:p-8 space-y-6">
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4 text-xs" id="vm_dates_row1">
                        <div class="bg-slate-50 rounded-xl p-4" id="vm_posted_block">
                            <p class="section-title mb-1">Posted</p>
                            <p class="font-bold text-slate-700" id="vm_date_posted">—</p>
                        </div>
                        <div class="bg-slate-50 rounded-xl p-4 hidden" id="vm_scheduled_date_block">
                            <p class="section-title mb-1">Scheduled Date</p>
                            <p class="font-bold text-slate-700" id="vm_scheduled_date">—</p>
                        </div>
                    </div>
                    <div class="bg-slate-50 rounded-xl p-4 hidden" id="vm_end_date_block">
                        <p class="section-title mb-1">End Date</p>
                        <p class="font-bold text-slate-700" id="vm_end_date">—</p>
                    </div>
                </div>
                <!-- Scheduled banner — shown only for Scheduled announcements -->
                <div id="vm_scheduled_banner"
                    class="hidden flex items-start gap-3 p-4 bg-indigo-50 border border-indigo-100 rounded-xl">
                    <span class="material-symbols-outlined text-indigo-400 shrink-0"
                        style="font-size:20px">schedule_send</span>
                    <div>
                        <p class="text-xs font-bold text-indigo-700">Pending Auto-Publish</p>
                        <p class="text-[11px] text-indigo-500 mt-0.5" id="vm_scheduled_note">This announcement will be
                            published automatically on the scheduled date and time.</p>
                    </div>
                </div>

                <div>
                    <p class="section-title mb-3">Content</p>
                    <div class="text-slate-600 text-sm leading-relaxed whitespace-pre-line bg-slate-50 rounded-xl p-5 max-h-52 overflow-y-auto"
                        id="vm_details">—</div>
                </div>

                <div id="vm_gallery_section" class="hidden">
                    <p class="section-title mb-3">Images</p>
                    <div class="relative">
                        <div class="w-full aspect-video bg-slate-100 rounded-xl overflow-hidden relative"
                            id="vm_gallery_main">
                            <img id="vm_gallery_img" src="" alt="" class="gallery-img">
                            <button id="vm_prev" onclick="galleryNav(-1)"
                                class="slide-nav absolute left-3 top-1/2 -translate-y-1/2 w-8 h-8 bg-white/90 rounded-full flex items-center justify-center text-slate-700 shadow-md hover:bg-white transition-all">
                                <span class="material-symbols-outlined" style="font-size:18px">chevron_left</span>
                            </button>
                            <button id="vm_next" onclick="galleryNav(1)"
                                class="slide-nav absolute right-3 top-1/2 -translate-y-1/2 w-8 h-8 bg-white/90 rounded-full flex items-center justify-center text-slate-700 shadow-md hover:bg-white transition-all">
                                <span class="material-symbols-outlined" style="font-size:18px">chevron_right</span>
                            </button>
                            <div id="vm_gallery_counter"
                                class="absolute bottom-3 right-3 bg-black/60 text-white text-[10px] font-bold px-2.5 py-1 rounded-full tracking-wide">
                            </div>
                        </div>
                        <div id="vm_thumbnails" class="flex gap-2 mt-3 flex-wrap"></div>
                    </div>
                </div>

                <div id="vm_files_section" class="hidden">
                    <p class="section-title mb-3">Attachments</p>
                    <div id="vm_files_list" class="space-y-2"></div>
                </div>

                <!-- Facebook Post Section -->
                <div class="border border-slate-100 rounded-xl overflow-hidden">
                    <div class="flex items-center justify-between px-5 py-4 bg-slate-50 border-b border-slate-100">
                        <div class="flex items-center gap-3">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="#1877f2">
                                <path
                                    d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.312 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z" />
                            </svg>
                            <span class="text-sm font-bold text-slate-700">Post to Facebook</span>
                        </div>
                        <label class="fb-toggle">
                            <input type="checkbox" id="fbToggle" onchange="handleFbToggle(this)">
                            <span class="track"><span class="thumb"></span></span>
                        </label>
                    </div>

                    <div id="fbPanel" class="hidden p-5 space-y-4">
                        <div>
                            <p class="section-title mb-2">Post Preview</p>
                            <div class="bg-slate-50 border border-slate-100 rounded-xl p-4">
                                <div class="flex items-center gap-3 mb-3">
                                    <div
                                        class="w-9 h-9 bg-primary rounded-full flex items-center justify-center text-white font-bold text-xs shrink-0">
                                        B2</div>
                                    <div>
                                        <p class="text-xs font-bold text-slate-700">Barangay Biñang 2nd</p>
                                        <p class="text-[10px] text-slate-400">Just now &middot; 🌐</p>
                                    </div>
                                </div>
                                <p class="text-xs text-slate-600 leading-relaxed whitespace-pre-line"
                                    id="fb_preview_text">—</p>
                                <div id="fb_preview_img_wrap"
                                    class="hidden mt-3 rounded-lg overflow-hidden max-h-40 bg-slate-200">
                                    <img id="fb_preview_img" src="" alt="" class="w-full object-cover max-h-40">
                                </div>
                            </div>
                        </div>

                        <div id="fbActionArea">
                            <div id="fbPostedState"
                                class="hidden flex items-center gap-3 p-4 bg-blue-50 rounded-xl border border-blue-100">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="#1877f2">
                                    <path
                                        d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.312 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z" />
                                </svg>
                                <div class="flex-1">
                                    <p class="text-xs font-bold text-[#1877f2]">Your announcement is already posted to
                                        Facebook</p>
                                    <p class="text-[10px] text-slate-500 font-medium">Post ID: <span
                                            id="fb_post_id_display" class="font-mono">—</span></p>
                                </div>
                                <a id="fb_view_link" href="#" target="_blank"
                                    class="text-[11px] font-bold text-[#1877f2] hover:underline flex items-center gap-1">
                                    View <span class="material-symbols-outlined"
                                        style="font-size:13px">open_in_new</span>
                                </a>
                            </div>

                            <div id="fbQueuedState"
                                class="hidden flex items-start gap-3 p-4 bg-indigo-50 rounded-xl border border-indigo-100">
                                <span class="material-symbols-outlined text-indigo-500 shrink-0"
                                    style="font-size:20px">schedule_send</span>
                                <div class="flex-1">
                                    <p class="text-xs font-bold text-indigo-700">Your post is already queued on Facebook
                                    </p>
                                    <p class="text-[11px] text-indigo-500 mt-0.5">Scheduled for <span
                                            id="fb_queued_date" class="font-semibold">—</span>. It will publish
                                        automatically together with the announcement — no further action needed.</p>
                                </div>
                            </div>

                            <div id="fbReadyState">
                                <p class="text-[11px] text-slate-500 mb-3">The announcement is already saved. Clicking
                                    below will publish it immediately to your Facebook Page via the Graph API.</p>
                                <?php if (staff_can($pdo, 'announcements', 'update')): ?>
                                    <button id="fbPostBtn" onclick="postToFacebook()"
                                        class="w-full flex items-center justify-center gap-2 bg-[#1877f2] hover:bg-[#166fe5] text-white font-bold text-sm py-3 rounded-xl transition-all active:scale-95 shadow-md shadow-blue-200">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="white">
                                            <path
                                                d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.312 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z" />
                                        </svg>
                                        Publish to Facebook Page
                                    </button>
                                <?php endif; ?>
                            </div>

                            <div id="fbErrorState" class="hidden mt-3 p-4 bg-rose-50 border border-rose-100 rounded-xl">
                                <p class="text-xs font-bold text-rose-600 flex items-center gap-2">
                                    <span class="material-symbols-outlined" style="font-size:15px">error</span>
                                    Facebook API Error
                                </p>
                                <p class="text-[11px] text-rose-500 mt-1" id="fbErrorMsg">—</p>
                                <button onclick="retryFbPost()"
                                    class="mt-2 text-[11px] font-bold text-rose-600 hover:underline">Retry →</button>
                            </div>

                            <div id="fbLoadingState"
                                class="hidden flex items-center justify-center gap-3 py-4 text-slate-400">
                                <svg class="animate-spin w-5 h-5 text-[#1877f2]" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                        stroke-width="4" />
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z" />
                                </svg>
                                <span class="text-sm font-medium">Posting to Facebook…</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Manual End Section (shown for announcements without an end date that are active) -->
            <div id="vm_manual_end_section" class="hidden px-6 md:px-8 pb-4">
                <div class="flex items-start gap-3 p-4 bg-amber-50 border border-amber-100 rounded-xl">
                    <span class="material-symbols-outlined text-amber-500 shrink-0" style="font-size:20px">info</span>
                    <div class="flex-1">
                        <p class="text-xs font-bold text-amber-700">No end date set</p>
                        <p class="text-[11px] text-amber-600 mt-0.5">This announcement will remain active until you
                            manually end it.</p>
                    </div>
                </div>
            </div>

            <div class="px-6 md:px-8 pb-6 flex justify-end gap-3">
                <?php if (staff_can($pdo, 'announcements', 'update')): ?>
                    <button id="vm_end_btn" onclick="confirmEndPosting()"
                        class="hidden px-5 py-2.5 bg-amber-500 text-white rounded-xl font-bold text-sm hover:bg-amber-600 transition-all flex items-center gap-2">
                        <span class="material-symbols-outlined" style="font-size:16px">stop_circle</span>
                        End Posting
                    </button>
                    <button id="vm_recover_btn" onclick="openRecoverPostFromView()"
                        class="hidden px-5 py-2.5 bg-emerald-600 text-white rounded-xl font-bold text-sm hover:bg-emerald-700 transition-all flex items-center gap-2">
                        <span class="material-symbols-outlined" style="font-size:16px">replay</span>
                        Recover Post
                    </button>
                    <a id="vm_edit_link" href="#"
                        class="px-5 py-2.5 bg-slate-100 text-slate-700 rounded-xl font-bold text-sm hover:bg-slate-200 transition-all">Edit</a>
                <?php endif; ?>
                <button onclick="closeView()"
                    class="px-5 py-2.5 bg-primary text-white rounded-xl font-bold text-sm hover:bg-primary-light transition-all">Close</button>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
     DELETE CONFIRM MODAL
═══════════════════════════════════════════ -->
    <div id="deleteModal" class="hidden fixed inset-0 z-50 modal-backdrop flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-8 text-center">
            <div class="w-16 h-16 bg-rose-50 rounded-2xl flex items-center justify-center mx-auto mb-5">
                <span class="material-symbols-outlined text-rose-500" style="font-size:32px">delete_forever</span>
            </div>
            <h3 class="text-lg font-bold text-slate-800 mb-2">Delete Announcement?</h3>
            <p class="text-slate-500 text-sm mb-2">You are about to delete:</p>
            <p class="text-slate-700 font-bold text-sm mb-4 px-2" id="del_title_label"></p>
            <div class="text-left bg-rose-50 border border-rose-100 rounded-xl p-3 mb-6">
                <p class="text-xs text-rose-700 font-semibold leading-relaxed">
                    This announcement will be deleted and moved to <span class="font-black">Trash</span>.
                    It will no longer be visible to residents, and it will be recorded that you deleted it.
                </p>
                <p class="text-[11px] text-rose-500 mt-1.5 leading-relaxed">It is not permanently erased &mdash; you can
                    recover it anytime from the Trash section.</p>
            </div>
            <div class="flex gap-3">
                <button onclick="closeDelete()"
                    class="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 rounded-xl font-bold text-sm hover:bg-slate-200 transition-all">Cancel</button>
                <button id="del_confirm_btn" onclick="executeDelete()"
                    class="flex-1 px-4 py-2.5 bg-rose-600 text-white rounded-xl font-bold text-sm hover:bg-rose-700 transition-all flex items-center justify-center gap-2">
                    <span id="del_btn_icon" class="material-symbols-outlined" style="font-size:16px">delete</span>
                    <span id="del_btn_text">Delete &amp; Move to Trash</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
     END POSTING CONFIRM MODAL
═══════════════════════════════════════════ -->
    <div id="endModal" class="hidden fixed inset-0 z-50 modal-backdrop flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-8 text-center">
            <div class="w-16 h-16 bg-amber-50 rounded-2xl flex items-center justify-center mx-auto mb-5">
                <span class="material-symbols-outlined text-amber-500" style="font-size:32px">stop_circle</span>
            </div>
            <h3 class="text-lg font-bold text-slate-800 mb-2">End Announcement Posting?</h3>
            <p class="text-slate-500 text-sm mb-2">You are about to end:</p>
            <p class="text-slate-700 font-bold text-sm mb-6 px-2" id="end_title_label"></p>
            <p class="text-slate-400 text-xs mb-6">This will mark the announcement as ended and hide it from residents
                immediately.</p>
            <div class="flex gap-3">
                <button onclick="closeEndModal()"
                    class="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 rounded-xl font-bold text-sm hover:bg-slate-200 transition-all">Cancel</button>
                <button id="end_confirm_btn" onclick="executeEndPosting()"
                    class="flex-1 px-4 py-2.5 bg-amber-500 text-white rounded-xl font-bold text-sm hover:bg-amber-600 transition-all flex items-center justify-center gap-2">
                    <span id="end_btn_icon" class="material-symbols-outlined" style="font-size:16px">stop_circle</span>
                    <span id="end_btn_text">End Posting</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
     RECOVER POST MODAL  (Ended / Expired → Posted/Active)
═══════════════════════════════════════════ -->
    <div id="recoverPostModal" class="hidden fixed inset-0 z-[70] modal-backdrop flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-8">
            <div class="w-16 h-16 bg-emerald-50 rounded-2xl flex items-center justify-center mx-auto mb-5">
                <span class="material-symbols-outlined text-emerald-600" style="font-size:32px">replay</span>
            </div>
            <h3 class="text-lg font-bold text-slate-800 mb-2 text-center">Recover Post?</h3>
            <p class="text-slate-500 text-sm mb-1 text-center">You are about to re-post:</p>
            <p class="text-slate-700 font-bold text-sm mb-4 px-2 text-center" id="rp_title_label"></p>
            <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-3 mb-5">
                <p class="text-xs text-emerald-700 font-semibold leading-relaxed">
                    This <span id="rp_state_label">ended</span> announcement will go back to <span
                        class="font-black">Posted / Active</span>
                    and become visible to residents again. It keeps its original ID.
                </p>
            </div>
            <p class="section-title mb-2">New end date &amp; time <span
                    class="normal-case font-semibold text-slate-400">(optional)</span></p>
            <div class="grid grid-cols-2 gap-3 mb-2">
                <input type="date" id="rp_end_date"
                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-sm font-medium text-slate-700 focus:ring-2 focus:ring-primary/20">
                <input type="time" id="rp_end_time"
                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-sm font-medium text-slate-700 focus:ring-2 focus:ring-primary/20">
            </div>
            <p class="text-[11px] text-slate-400 mb-6">Leave blank to keep it posted until you end it manually.</p>
            <div class="flex gap-3">
                <button onclick="closeRecoverPost()"
                    class="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 rounded-xl font-bold text-sm hover:bg-slate-200 transition-all">Cancel</button>
                <button id="rp_confirm_btn" onclick="executeRecoverPost()"
                    class="flex-1 px-4 py-2.5 bg-emerald-600 text-white rounded-xl font-bold text-sm hover:bg-emerald-700 transition-all flex items-center justify-center gap-2">
                    <span id="rp_btn_icon" class="material-symbols-outlined" style="font-size:16px">replay</span>
                    <span id="rp_btn_text">Recover Post</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
     TRASH MODAL  (list of deleted announcements)
═══════════════════════════════════════════ -->
    <div id="trashModal"
        class="hidden fixed inset-0 z-50 modal-backdrop flex items-start justify-center pt-8 pb-8 px-4 overflow-y-auto">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-5xl relative">
            <div class="flex items-start justify-between p-6 md:p-8 border-b border-slate-100">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-rose-50 rounded-2xl flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-rose-500" style="font-size:26px">delete_sweep</span>
                    </div>
                    <div>
                        <p class="section-title text-rose-500 mb-0.5">Trash</p>
                        <h2 class="text-xl font-bold text-slate-800 leading-tight">Deleted Announcements</h2>
                        <p class="text-xs text-slate-400 mt-1">Deleted announcements are kept here so they can be viewed
                            or recovered.</p>
                    </div>
                </div>
                <button onclick="closeTrash()"
                    class="p-2 hover:bg-slate-100 rounded-xl transition-colors text-slate-400 ml-4 shrink-0">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="table-container overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50/50">
                            <th class="px-6 py-4 section-title">ID</th>
                            <th class="px-6 py-4 section-title">Title</th>
                            <th class="px-6 py-4 section-title">Original Status</th>
                            <th class="px-6 py-4 section-title">Deleted By</th>
                            <th class="px-6 py-4 section-title">Deleted On</th>
                            <th class="px-6 py-4 section-title text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="trashTbody" class="divide-y divide-slate-50">
                        <tr>
                            <td colspan="6" class="px-6 py-16 text-center text-slate-400 italic text-sm">Loading&hellip;
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="px-6 py-4 border-t border-slate-100 flex justify-between items-center">
                <span id="trashCountLabel" class="section-title">0 in Trash</span>
                <button onclick="closeTrash()"
                    class="px-5 py-2.5 bg-primary text-white rounded-xl font-bold text-sm hover:bg-primary-light transition-all">Close</button>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
     TRASH — VIEW DELETED ANNOUNCEMENT
═══════════════════════════════════════════ -->
    <div id="trashViewModal"
        class="hidden fixed inset-0 z-[55] modal-backdrop flex items-start justify-center pt-8 pb-8 px-4 overflow-y-auto">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl relative">
            <div class="flex items-start justify-between p-6 md:p-8 border-b border-slate-100">
                <div>
                    <p class="section-title text-primary mb-1" id="tv_ref">#ANN-0000</p>
                    <h2 class="text-xl font-bold text-slate-800 leading-tight" id="tv_title">--</h2>
                    <div class="flex items-center gap-3 mt-2 flex-wrap">
                        <span id="tv_category"
                            class="px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase border bg-slate-50 text-slate-500 border-slate-100">General</span>
                        <span id="tv_prev_status"
                            class="px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase bg-slate-100 text-slate-500">--</span>
                        <span
                            class="px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase bg-rose-50 text-rose-600 flex items-center gap-1">
                            <span class="material-symbols-outlined" style="font-size:12px">delete</span> In Trash
                        </span>
                    </div>
                </div>
                <button onclick="closeTrashView()"
                    class="p-2 hover:bg-slate-100 rounded-xl transition-colors text-slate-400 ml-4 shrink-0">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>

            <div class="p-6 md:p-8 space-y-6">
                <!-- Who / when deleted (Deleted By comes from the logged-in account at the time of deletion) -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                    <div class="bg-rose-50 border border-rose-100 rounded-xl p-4">
                        <p class="section-title mb-1 text-rose-500">Deleted By</p>
                        <p class="font-bold text-slate-700 text-sm" id="tv_deleted_by">--</p>
                    </div>
                    <div class="bg-rose-50 border border-rose-100 rounded-xl p-4">
                        <p class="section-title mb-1 text-rose-500">Deleted On</p>
                        <p class="font-bold text-slate-700 text-sm" id="tv_deleted_at">--</p>
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-xs">
                    <div class="bg-slate-50 rounded-xl p-4">
                        <p class="section-title mb-1">Posted</p>
                        <p class="font-bold text-slate-700" id="tv_date_posted">--</p>
                    </div>
                    <div class="bg-slate-50 rounded-xl p-4" id="tv_start_block">
                        <p class="section-title mb-1">Start</p>
                        <p class="font-bold text-slate-700" id="tv_start">--</p>
                    </div>
                    <div class="bg-slate-50 rounded-xl p-4" id="tv_end_block">
                        <p class="section-title mb-1">End</p>
                        <p class="font-bold text-slate-700" id="tv_end">--</p>
                    </div>
                </div>

                <div>
                    <p class="section-title mb-3">Content</p>
                    <div class="text-slate-600 text-sm leading-relaxed whitespace-pre-line bg-slate-50 rounded-xl p-5 max-h-60 overflow-y-auto"
                        id="tv_details">--</div>
                </div>

                <div id="tv_images_section" class="hidden">
                    <p class="section-title mb-3">Images</p>
                    <div id="tv_images" class="grid grid-cols-2 sm:grid-cols-3 gap-3"></div>
                </div>
                <div id="tv_files_section" class="hidden">
                    <p class="section-title mb-3">Attachments</p>
                    <div id="tv_files" class="space-y-2"></div>
                </div>

                <div id="tv_fb_row"
                    class="hidden flex items-center gap-2 text-[11px] font-semibold text-[#1877f2] bg-blue-50 border border-blue-100 rounded-xl px-4 py-3">
                    <span class="material-symbols-outlined" style="font-size:16px">info</span>
                    This announcement was posted to Facebook. The Facebook post is not affected by deleting or
                    recovering it here.
                </div>
            </div>

            <div class="px-6 md:px-8 pb-6 flex justify-end gap-3">
                <button id="tv_recover_btn" onclick="openRestoreFromView()"
                    class="px-5 py-2.5 bg-emerald-600 text-white rounded-xl font-bold text-sm hover:bg-emerald-700 transition-all flex items-center gap-2">
                    <span class="material-symbols-outlined" style="font-size:16px">restore_from_trash</span>
                    Recover
                </button>
                <button onclick="closeTrashView()"
                    class="px-5 py-2.5 bg-slate-100 text-slate-700 rounded-xl font-bold text-sm hover:bg-slate-200 transition-all">Close</button>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
     TRASH — RECOVER (restore) CONFIRM
     Scheduled announcements must be given a NEW start date/time here.
═══════════════════════════════════════════ -->
    <div id="restoreModal" class="hidden fixed inset-0 z-[70] modal-backdrop flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-8">
            <div class="w-16 h-16 bg-emerald-50 rounded-2xl flex items-center justify-center mx-auto mb-5">
                <span class="material-symbols-outlined text-emerald-600"
                    style="font-size:32px">restore_from_trash</span>
            </div>
            <h3 class="text-lg font-bold text-slate-800 mb-2 text-center">Recover Announcement?</h3>
            <p class="text-slate-700 font-bold text-sm mb-1 px-2 text-center" id="rs_title_label"></p>
            <p class="text-[11px] text-slate-400 mb-4 text-center">Original status: <span id="rs_prev_label"
                    class="font-bold text-slate-500">--</span></p>
            <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-3 mb-5">
                <p class="text-xs text-emerald-700 font-semibold leading-relaxed" id="rs_outcome_text"></p>
            </div>

            <div id="rs_schedule_box" class="hidden mb-5">
                <p class="section-title mb-2">New start <span class="text-rose-500">*</span></p>
                <div class="grid grid-cols-2 gap-3 mb-4">
                    <input type="date" id="rs_start_date"
                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-sm font-medium text-slate-700 focus:ring-2 focus:ring-primary/20">
                    <input type="time" id="rs_start_time"
                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-sm font-medium text-slate-700 focus:ring-2 focus:ring-primary/20">
                </div>
                <p class="section-title mb-2">End date &amp; time <span
                        class="normal-case font-semibold text-slate-400">(optional)</span></p>
                <div class="grid grid-cols-2 gap-3">
                    <input type="date" id="rs_end_date"
                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-sm font-medium text-slate-700 focus:ring-2 focus:ring-primary/20">
                    <input type="time" id="rs_end_time"
                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-sm font-medium text-slate-700 focus:ring-2 focus:ring-primary/20">
                </div>
            </div>

            <div class="flex gap-3">
                <button onclick="closeRestore()"
                    class="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 rounded-xl font-bold text-sm hover:bg-slate-200 transition-all">Cancel</button>
                <button id="rs_confirm_btn" onclick="executeRestore()"
                    class="flex-1 px-4 py-2.5 bg-emerald-600 text-white rounded-xl font-bold text-sm hover:bg-emerald-700 transition-all flex items-center justify-center gap-2">
                    <span id="rs_btn_icon" class="material-symbols-outlined"
                        style="font-size:16px">restore_from_trash</span>
                    <span id="rs_btn_text">Recover</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ── Issue Alert Modal (moved from Disaster module) ─────────────────────────── -->
    <div id="issueAlertModal"
        class="fixed inset-0 z-[100] hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-6">
        <div
            class="bg-white rounded-[3.5rem] shadow-2xl w-full max-w-2xl overflow-hidden border border-white/20 transform transition-all max-h-[95vh] overflow-y-auto">
            <div class="px-12 pt-12 pb-6 flex justify-between items-center">
                <div>
                    <h3 class="text-2xl font-black tracking-tight text-slate-900">Issue Disaster Alert</h3>
                    <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-1">Broadcast emergency
                        protocols to residents</p>
                </div>
                <button onclick="closeModal('issueAlertModal')"
                    class="h-12 w-12 flex items-center justify-center rounded-full bg-slate-50 text-slate-400 hover:text-slate-900 transition-all">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>

            <form action="../backend/process_disaster.php" method="POST" class="px-12 pb-14 space-y-5">
                <input type="hidden" name="action" value="create">
                <?php echo csrf_token(); ?>

                <!-- Notification Channels -->
                <div class="flex gap-4 p-4 bg-slate-50 rounded-2xl">
                    <label class="flex items-center gap-2 cursor-pointer group">
                        <input type="checkbox" name="notify_app" value="1" id="issue_notify_app"
                            class="rounded border-slate-300" style="accent-color: var(--accent-600);">
                        <span
                            class="text-[10px] font-black uppercase text-slate-500 tracking-wider group-hover:text-[var(--accent-600)] transition-colors">App
                            Notification</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer group">
                        <input type="checkbox" name="notify_sms" value="1" id="issue_notify_sms"
                            onchange="toggleSMSOptions('issue')" class="rounded border-slate-300"
                            style="accent-color: var(--accent-600);">
                        <span
                            class="text-[10px] font-black uppercase text-slate-500 tracking-wider group-hover:text-[var(--accent-600)] transition-colors">SMS
                            Notification</span>
                    </label>
                </div>

                <!-- SMS Options (hidden by default) -->
                <div id="issue_sms_options" class="hidden space-y-3">
                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">SMS
                        Recipients</label>
                    <div class="grid grid-cols-2 gap-3" id="issue_sms_audience_grid">
                        <label class="p-3 border border-slate-100 bg-slate-50 rounded-2xl cursor-pointer block"
                            id="issue_sms_all_label">
                            <input type="radio" name="sms_audience" value="all" checked
                                onchange="toggleSMSAudienceSections('issue')">
                            <span class="text-[10px] font-bold block mt-1">All Residents
                                (<?= $total_active_residents ?>)</span>
                        </label>
                        <label class="p-3 border border-slate-100 bg-slate-50 rounded-2xl cursor-pointer block">
                            <input type="radio" name="sms_audience" value="area"
                                onchange="toggleSMSAudienceSections('issue')">
                            <span class="text-[10px] font-bold block mt-1">By Areas</span>
                        </label>
                    </div>

                    <p class="text-[9px] font-semibold text-slate-400 px-2">SMS is sent only to active residents with a
                        registered contact number. Areas come from Resident Management.</p>

                    <div id="issue_area_list"
                        class="hidden space-y-4 p-4 bg-slate-50 rounded-2xl border border-slate-100">
                        <!-- Streets: sourced from the same resident address data used by Resident Management. -->
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <span
                                    class="text-[9px] font-black uppercase tracking-widest text-slate-400">Street</span>
                                <span class="text-[8px] font-semibold text-slate-300">Residents with registered
                                    contact</span>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <?php foreach ($street_counts as $streetName => $streetCount): ?>
                                    <label
                                        class="flex items-center justify-between p-3 bg-white rounded-xl border border-slate-100 cursor-pointer hover:border-[var(--accent-500)]/40 transition-colors">
                                        <div class="flex items-center gap-2 min-w-0">
                                            <input type="checkbox" name="selected_streets[]"
                                                value="<?= htmlspecialchars($streetName) ?>"
                                                class="rounded text-[var(--accent-600)]"
                                                style="accent-color: var(--accent-600);">
                                            <span
                                                class="text-[10px] font-bold truncate"><?= htmlspecialchars($streetName) ?></span>
                                        </div>
                                        <span
                                            class="text-[9px] text-slate-400 font-bold ml-2"><?= (int) $streetCount ?></span>
                                    </label>
                                <?php endforeach; ?>
                                <?php if (empty($street_counts)): ?>
                                    <p class="col-span-2 text-center text-[10px] font-bold text-slate-400 py-3">No resident
                                        streets found.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Local areas: Subdivision / Village / Sitio / Purok. -->
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-[9px] font-black uppercase tracking-widest text-slate-400">Subdivision
                                    / Village / Sitio / Purok</span>
                                <span class="text-[8px] font-semibold text-slate-300">Residents with registered
                                    contact</span>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <?php foreach ($areas_list as $area):
                                    $areaType = trim((string) ($area['area_type'] ?? ''));
                                    $areaName = trim((string) ($area['area_name'] ?? ''));
                                    if ($areaName === '')
                                        continue;
                                    $areaKey = $areaType . '|' . $areaName;
                                    $count = $area_counts[$areaKey] ?? 0;
                                    $areaValue = $areaType . '|' . $areaName;
                                    ?>
                                    <label
                                        class="flex items-center justify-between p-3 bg-white rounded-xl border border-slate-100 cursor-pointer hover:border-[var(--accent-500)]/40 transition-colors">
                                        <div class="flex items-center gap-2 min-w-0">
                                            <input type="checkbox" name="selected_areas[]"
                                                value="<?= htmlspecialchars($areaValue) ?>"
                                                class="rounded text-[var(--accent-600)]"
                                                style="accent-color: var(--accent-600);">
                                            <div class="min-w-0">
                                                <span
                                                    class="text-[10px] font-bold truncate block"><?= htmlspecialchars($areaName) ?></span>
                                                <span
                                                    class="text-[8px] font-semibold text-slate-400 uppercase"><?= htmlspecialchars($areaType) ?></span>
                                            </div>
                                        </div>
                                        <span class="text-[9px] text-slate-400 font-bold ml-2"><?= (int) $count ?></span>
                                    </label>
                                <?php endforeach; ?>
                                <?php if (empty($areas_list)): ?>
                                    <p class="col-span-2 text-center text-[10px] font-bold text-slate-400 py-3">No active
                                        subdivision, village, sitio or purok found.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <p class="text-[9px] font-semibold text-slate-400 pt-1">Select one or more streets and/or local
                            areas. If both are selected, residents matching either selection will receive the SMS.</p>
                    </div>
                </div>

                <!-- Alert Type -->
                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase text-slate-400 tracking-widest ml-2">Alert
                        Type</label>
                    <select name="type" id="issue_type" required
                        class="w-full bg-slate-50 border-none rounded-2xl py-4 px-6 font-bold text-slate-700 focus:ring-2 focus:ring-[var(--accent-500)]/20">
                        <option value="" disabled selected>Select Disaster Alert</option>
                        <option value="Typhoon">Typhoon</option>
                        <option value="Flood">Flood</option>
                        <option value="Fire">Fire</option>
                        <option value="Earthquake">Earthquake</option>
                    </select>
                </div>

                <!-- Severity -->
                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase text-slate-400 tracking-widest ml-2">Severity
                        Level</label>
                    <select name="severity" required
                        class="w-full bg-slate-50 border-none rounded-2xl py-4 px-6 font-bold text-slate-700 focus:ring-2 focus:ring-[var(--accent-500)]/20">
                        <option value="" disabled selected>Select severity</option>
                        <option value="Low">Low</option>
                        <option value="Medium">Medium</option>
                        <option value="High">High</option>
                        <option value="Critical">Critical</option>
                    </select>
                </div>

                <!-- Alert Title -->
                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase text-slate-400 tracking-widest ml-2">Alert
                        Title</label>
                    <input type="text" name="title" id="issue_title" placeholder="Enter alert title" required
                        class="w-full bg-slate-50 border-none rounded-2xl py-4 px-6 font-bold placeholder:text-slate-300 focus:ring-2 focus:ring-[var(--accent-500)]/20">
                </div>

                <!-- Message -->
                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase text-slate-400 tracking-widest ml-2">Message /
                        Emergency Instructions</label>
                    <textarea name="message" id="issue_message" rows="4" placeholder="Enter instructions..." required
                        class="w-full bg-slate-50 border-none rounded-2xl py-4 px-6 font-bold placeholder:text-slate-300 focus:ring-2 focus:ring-[var(--accent-500)]/20 resize-none"></textarea>
                </div>

                <button type="submit"
                    class="w-full bg-[#0f172a] text-white py-5 rounded-2xl font-black uppercase tracking-[0.2em] text-[11px] shadow-xl hover:bg-black hover:-translate-y-1 transition-all duration-300 mt-4 flex items-center justify-center gap-3">
                    <span class="material-symbols-outlined !text-lg">campaign</span> Issue Alert Now
                </button>
            </form>
        </div>
    </div>

    <!-- ── Active Disaster Modal (card "!" — all active disasters) ────────────────── -->
    <div id="activeDisasterModal"
        class="fixed inset-0 z-[100] hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-6">
        <div
            class="bg-white rounded-[2rem] shadow-2xl w-full max-w-2xl overflow-hidden border border-white/20 max-h-[90vh] flex flex-col">
            <div class="px-8 pt-8 pb-4 flex items-center justify-between border-b border-slate-100 flex-shrink-0">
                <div class="flex items-center gap-3">
                    <div class="w-2 h-2 rounded-full bg-rose-500 animate-pulse pulse-ring flex-shrink-0"></div>
                    <div>
                        <h3 class="text-lg font-black tracking-tight text-slate-900">Active Disaster Alerts</h3>
                        <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-0.5">
                            <?php echo (int) $total_alerts; ?> Active</p>
                    </div>
                </div>
                <button onclick="closeModal('activeDisasterModal')"
                    class="h-10 w-10 flex items-center justify-center rounded-full bg-slate-50 text-slate-400 hover:text-slate-900 transition-all">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="p-5 flex flex-col gap-3 overflow-y-auto">
                <?php if (empty($active_alerts)): ?>
                    <div class="flex flex-col items-center justify-center py-12 opacity-30">
                        <div class="w-14 h-14 rounded-2xl bg-slate-100 flex items-center justify-center mb-3">
                            <span class="material-symbols-outlined text-slate-400"
                                style="font-size:28px">notifications_off</span>
                        </div>
                        <p class="text-xs font-black uppercase tracking-widest text-slate-400">No Active Emergencies</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($active_alerts as $alert):
                        $icon = 'warning';
                        $color = 'text-slate-500';
                        $bg = 'bg-slate-50';
                        $border = 'border-slate-100';
                        if ($alert['Type'] == 'Typhoon') {
                            $icon = 'cyclone';
                            $color = 'text-sky-600';
                            $bg = 'bg-sky-50';
                            $border = 'border-sky-100';
                        } elseif ($alert['Type'] == 'Flood') {
                            $icon = 'waves';
                            $color = 'text-cyan-600';
                            $bg = 'bg-cyan-50';
                            $border = 'border-cyan-100';
                        } elseif ($alert['Type'] == 'Fire') {
                            $icon = 'local_fire_department';
                            $color = 'text-rose-600';
                            $bg = 'bg-rose-50';
                            $border = 'border-rose-100';
                        } elseif ($alert['Type'] == 'Earthquake') {
                            $icon = 'landslide';
                            $color = 'text-violet-600';
                            $bg = 'bg-violet-50';
                            $border = 'border-violet-100';
                        }
                        ?>
                        <div
                            class="flex items-center justify-between gap-4 px-4 py-3.5 rounded-xl border border-slate-100 hover:border-slate-200 hover:bg-slate-50/50 transition-all group">
                            <div class="flex items-center gap-4 min-w-0">
                                <div
                                    class="h-10 w-10 rounded-xl <?php echo $bg; ?> border <?php echo $border; ?> flex items-center justify-center flex-shrink-0 <?php echo $color; ?>">
                                    <span class="material-symbols-outlined" style="font-size:20px"><?php echo $icon; ?></span>
                                </div>
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <p class="text-sm font-semibold text-slate-800 truncate">
                                            <?php echo htmlspecialchars($alert['Title']); ?></p>
                                        <span
                                            class="px-2 py-0.5 rounded-md text-[9px] font-bold text-white <?php echo ($alert['Severity'] == 'Critical') ? 'bg-rose-500' : 'bg-orange-500'; ?>">
                                            <?php echo strtoupper($alert['Severity']); ?>
                                        </span>
                                    </div>
                                    <p class="text-[11px] text-slate-400 font-medium mt-0.5 truncate">
                                        <?php echo htmlspecialchars($alert['Message']); ?></p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 flex-shrink-0">
                                <button onclick="openViewModal(<?php echo htmlspecialchars(json_encode($alert)); ?>)"
                                    class="p-2 bg-indigo-50 text-indigo-600 rounded-lg hover:bg-indigo-100 transition-colors">
                                    <span class="material-symbols-outlined" style="font-size:16px">visibility</span>
                                </button>
                                <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($alert)); ?>)"
                                    class="p-2 bg-slate-100 text-slate-500 rounded-lg hover:bg-slate-200 transition-colors">
                                    <span class="material-symbols-outlined" style="font-size:16px">edit_square</span>
                                </button>
                                <button onclick="openDeactivateModal(<?php echo $alert['AlertID']; ?>)"
                                    class="px-3 py-2 bg-rose-50 text-rose-600 rounded-lg text-[9px] font-black uppercase hover:bg-rose-100 transition-colors">
                                    Deactivate
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($disaster_total_pages > 1): ?>
                        <div class="flex justify-center items-center gap-3 mt-2 pt-4 border-t border-slate-50">
                            <?php if ($disaster_page > 1): ?>
                                <a href="?dpage=<?= $disaster_page - 1 ?>#"
                                    class="px-4 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-[10px] font-bold text-slate-600 hover:bg-slate-100 transition-colors">←
                                    Prev</a>
                            <?php endif; ?>
                            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Page
                                <?= $disaster_page ?> of <?= $disaster_total_pages ?></span>
                            <?php if ($disaster_page < $disaster_total_pages): ?>
                                <a href="?dpage=<?= $disaster_page + 1 ?>#"
                                    class="px-4 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-[10px] font-bold text-slate-600 hover:bg-slate-100 transition-colors">Next
                                    →</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── SMS Live Modal (card "!" — live SMS status per disaster) ────────────────── -->
    <div id="smsLiveModal"
        class="fixed inset-0 z-[100] hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-6">
        <div
            class="bg-white rounded-[2rem] shadow-2xl w-full max-w-md overflow-hidden border border-white/20 max-h-[90vh] flex flex-col">
            <div class="px-8 pt-8 pb-4 flex items-center justify-between border-b border-slate-100 flex-shrink-0">
                <div>
                    <h3 class="text-lg font-black tracking-tight text-slate-900" id="smsLiveModalTitle">SMS Notification
                        Live Status</h3>
                    <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-0.5"
                        id="smsLiveModalSubtitle">Per-disaster send progress</p>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                    <button type="button" onclick="toggleSMSHistory()" id="smsHistoryToggleBtn"
                        class="h-10 px-4 flex items-center gap-1.5 rounded-full bg-slate-50 text-slate-500 hover:bg-slate-100 transition-all text-[10px] font-black uppercase tracking-wider">
                        <span class="material-symbols-outlined" style="font-size:16px">history</span>
                        History
                    </button>
                    <button onclick="closeModal('smsLiveModal')"
                        class="h-10 w-10 flex items-center justify-center rounded-full bg-slate-50 text-slate-400 hover:text-slate-900 transition-all">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
            </div>

            <!-- Live view (recent broadcasts) -->
            <div id="smsLiveList" class="divide-y divide-slate-50 overflow-y-auto">
                <?php if (empty($logs)): ?>
                    <div class="p-6 text-center opacity-30">
                        <span class="material-symbols-outlined text-slate-400" style="font-size:24px">sms</span>
                        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mt-2">No SMS Logs</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($logs as $log):
                        echo render_sms_log_entry($log); endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- History view (all broadcasts, incl. deactivated disasters) -->
            <div id="smsHistoryList" class="hidden divide-y divide-slate-50 overflow-y-auto">
                <?php if (empty($sms_history)): ?>
                    <div class="p-6 text-center opacity-30">
                        <span class="material-symbols-outlined text-slate-400" style="font-size:24px">history</span>
                        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mt-2">No SMS History</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($sms_history as $log):
                        echo render_sms_log_entry($log); endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Disaster Analytics + View Disaster Report now live on disaster_analytics.php -->

    <!-- ── Edit Alert Modal (moved from disaster_modals.php) ───────────────────────── -->
    <div id="editAlertModal"
        class="fixed inset-0 z-[100] hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-6">
        <div
            class="bg-white rounded-[3.5rem] shadow-2xl w-full max-w-2xl overflow-hidden border border-white/20 transform transition-all max-h-[95vh] overflow-y-auto">
            <div class="px-12 pt-12 pb-6 flex justify-between items-center">
                <div>
                    <h3 class="text-2xl font-black tracking-tight text-slate-900">Update Alert</h3>
                    <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-1">Modify existing
                        emergency broadcast</p>
                </div>
                <button onclick="closeModal('editAlertModal')"
                    class="h-12 w-12 flex items-center justify-center rounded-full bg-slate-50 text-slate-400 hover:text-slate-900 transition-all">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>

            <form action="../backend/process_disaster.php" method="POST" class="px-12 pb-14 space-y-5">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="alert_id" id="edit_alert_id">
                <?php echo csrf_token(); ?>

                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase text-slate-400 tracking-widest ml-2">Alert
                        Type</label>
                    <select name="type" id="edit_type" required
                        class="w-full bg-slate-50 border-none rounded-2xl py-4 px-6 font-bold text-slate-700 focus:ring-2 focus:ring-[var(--accent-500)]/20">
                        <option value="Typhoon">Typhoon</option>
                        <option value="Flood">Flood</option>
                        <option value="Fire">Fire</option>
                        <option value="Earthquake">Earthquake</option>
                    </select>
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase text-slate-400 tracking-widest ml-2">Severity
                        Level</label>
                    <select name="severity" id="edit_severity" required
                        class="w-full bg-slate-50 border-none rounded-2xl py-4 px-6 font-bold text-slate-700 focus:ring-2 focus:ring-[var(--accent-500)]/20">
                        <option value="Low">Low</option>
                        <option value="Medium">Medium</option>
                        <option value="High">High</option>
                        <option value="Critical">Critical</option>
                    </select>
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase text-slate-400 tracking-widest ml-2">Alert
                        Title</label>
                    <input type="text" name="title" id="edit_title" required
                        class="w-full bg-slate-50 border-none rounded-2xl py-4 px-6 font-bold focus:ring-2 focus:ring-[var(--accent-500)]/20">
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase text-slate-400 tracking-widest ml-2">Message /
                        Emergency Instructions</label>
                    <textarea name="message" id="edit_message" rows="4" required
                        class="w-full bg-slate-50 border-none rounded-2xl py-4 px-6 font-bold focus:ring-2 focus:ring-[var(--accent-500)]/20 resize-none"></textarea>
                </div>

                <button type="submit"
                    class="w-full text-white py-5 rounded-2xl font-black uppercase tracking-[0.2em] text-[11px] shadow-xl hover:-translate-y-1 transition-all duration-300 mt-4 flex items-center justify-center gap-3"
                    style="background: var(--accent-600);">
                    <span class="material-symbols-outlined !text-lg">published_with_changes</span> Save Changes
                </button>
            </form>
        </div>
    </div>

    <!-- ── Deactivate / Report Modal (moved from disaster_modals.php) ──────────────── -->
    <div id="deactivateModal"
        class="fixed inset-0 z-[100] hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div
            class="bg-white rounded-[3rem] shadow-2xl w-full max-w-lg overflow-hidden border border-white/20 transform transition-all max-h-[95vh] overflow-y-auto">
            <div class="px-10 pt-10 pb-4 flex justify-between items-center border-b border-slate-100">
                <div>
                    <h3 class="text-xl font-black tracking-tight text-slate-900">Resolve Disaster Alert</h3>
                    <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-1">Submit a final
                        disaster report</p>
                </div>
                <button onclick="closeModal('deactivateModal')"
                    class="h-10 w-10 flex items-center justify-center rounded-full bg-slate-50 text-slate-400 hover:text-slate-900 transition-all">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>

            <form action="../backend/process_disaster.php" method="POST" class="px-10 py-8 space-y-5">
                <input type="hidden" name="action" value="deactivate_with_report">
                <input type="hidden" name="alert_id" id="deactivate_alert_id">
                <?php echo csrf_token(); ?>

                <div class="grid grid-cols-3 gap-3">
                    <div class="space-y-1.5">
                        <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Affected
                            Residents</label>
                        <input type="number" name="affected_residents" placeholder="0" required
                            class="w-full bg-slate-50 border-none rounded-xl py-3 px-5 font-bold text-slate-700 focus:ring-2 focus:ring-red-500/20 text-sm">
                    </div>
                    <div class="space-y-1.5">
                        <label
                            class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Evacuees</label>
                        <input type="number" name="evacuees" placeholder="0" required
                            class="w-full bg-slate-50 border-none rounded-xl py-3 px-5 font-bold text-slate-700 focus:ring-2 focus:ring-red-500/20 text-sm">
                    </div>
                    <div class="space-y-1.5">
                        <label
                            class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Injuries</label>
                        <input type="number" name="injuries" placeholder="0" required
                            class="w-full bg-slate-50 border-none rounded-xl py-3 px-5 font-bold text-slate-700 focus:ring-2 focus:ring-red-500/20 text-sm">
                    </div>
                </div>
                <div class="space-y-1.5">
                    <label
                        class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Casualties</label>
                    <input type="number" name="casualties" placeholder="0" required
                        class="w-full bg-slate-50 border-none rounded-xl py-3 px-5 font-bold text-slate-700 focus:ring-2 focus:ring-red-500/20 text-sm">
                </div>

                <div class="space-y-4">
                    <div class="space-y-1.5">
                        <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Damage
                            Assessment</label>
                        <input type="text" name="property_damage" placeholder="Assessment of damage..." required
                            class="w-full bg-slate-50 border-none rounded-xl py-3 px-5 font-bold text-slate-700 focus:ring-2 focus:ring-red-500/20 text-sm">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Response
                            Actions & Remarks</label>
                        <textarea name="response_actions" rows="2" placeholder="Describe actions taken..." required
                            class="w-full bg-slate-50 border-none rounded-xl py-3 px-5 font-bold text-slate-700 focus:ring-2 focus:ring-red-500/20 resize-none text-sm"></textarea>
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Final
                            Status</label>
                        <select name="status" required
                            class="w-full bg-slate-50 border-none rounded-xl py-3 px-5 font-bold text-slate-700 focus:ring-2 focus:ring-red-500/20 text-sm">
                            <option value="Resolved" selected>Resolved (Successfully Managed)</option>
                            <option value="Closed">Closed (Observation Period Ended)</option>
                            <option value="Cancelled">Cancelled (False Alarm)</option>
                        </select>
                    </div>
                </div>

                <div class="flex gap-4 mt-8 pb-4">
                    <button type="button" onclick="closeModal('deactivateModal')"
                        class="flex-1 bg-slate-100 text-slate-500 py-4 rounded-xl font-black uppercase tracking-[0.2em] text-[10px] hover:bg-slate-200 transition-all">Cancel</button>
                    <button type="submit"
                        class="flex-[2] bg-red-600 text-white py-4 rounded-xl font-black uppercase tracking-[0.2em] text-[10px] shadow-xl shadow-red-500/20 hover:bg-red-700 hover:-translate-y-1 transition-all duration-300">Confirm
                        Deactivation</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── View Alert Modal (moved from disaster_modals.php) ───────────────────────── -->
    <div id="viewAlertModal"
        class="fixed inset-0 z-[100] hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-6">
        <div
            class="bg-white rounded-[3.5rem] shadow-2xl w-full max-w-lg overflow-hidden border border-white/20 transform transition-all">
            <div class="px-12 pt-12 pb-6 flex justify-between items-start">
                <div>
                    <div id="view_severity_badge"
                        class="inline-block px-3 py-1 rounded-full text-[8px] font-black text-white mb-3"></div>
                    <h3 id="view_title" class="text-2xl font-black tracking-tight text-slate-900 leading-tight"></h3>
                    <p id="view_type"
                        class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-2 flex items-center gap-1">
                    </p>
                </div>
                <button onclick="closeModal('viewAlertModal')"
                    class="h-12 w-12 flex items-center justify-center rounded-full bg-slate-50 text-slate-400 hover:text-slate-900 transition-all">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>

            <div class="px-12 pb-14 space-y-8">
                <div class="p-6 bg-slate-50 rounded-3xl border border-slate-100">
                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-[0.2em] block mb-3">Emergency
                        Message & Instructions</label>
                    <p id="view_message" class="text-slate-700 font-medium leading-relaxed"></p>
                </div>

                <div class="flex flex-col gap-1 text-right">
                    <span class="text-[9px] font-black text-slate-300 uppercase tracking-widest">Date Issued</span>
                    <span id="view_date" class="text-[11px] font-bold text-slate-600"></span>
                </div>
                <button onclick="closeModal('viewAlertModal')"
                    class="w-full bg-slate-900 text-white py-5 rounded-2xl font-black uppercase tracking-[0.2em] text-[11px] hover:bg-black transition-all">Close
                    View</button>
            </div>
        </div>
    </div>

    <script>
        // ─── Issue Alert Modal (moved from Disaster module) ───────────────────────────
        function openModal(id) { document.getElementById(id).classList.remove('hidden'); }

        // "New Announcement" button — the create form is its own page (new_ann.php),
        // the same way Edit opens edit_ann.php. This function was referenced by the
        // button but never defined, so clicking it did nothing.
        function openCreateAnnouncementModal() { window.location.href = 'new_ann.php'; }
        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

        // ─── SMS Live ↔ History toggle ─────────────────────────────────────────────
        function toggleSMSHistory() {
            const liveList = document.getElementById('smsLiveList');
            const historyList = document.getElementById('smsHistoryList');
            const btn = document.getElementById('smsHistoryToggleBtn');
            const title = document.getElementById('smsLiveModalTitle');
            const subtitle = document.getElementById('smsLiveModalSubtitle');
            const showingHistory = !historyList.classList.contains('hidden');

            if (showingHistory) {
                historyList.classList.add('hidden');
                liveList.classList.remove('hidden');
                btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px">history</span>History';
                title.textContent = 'SMS Notification Live Status';
                subtitle.textContent = 'Per-disaster send progress';
            } else {
                liveList.classList.add('hidden');
                historyList.classList.remove('hidden');
                btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px">bolt</span>Live';
                title.textContent = 'SMS Broadcast History';
                subtitle.textContent = 'All disasters, including deactivated';
            }
        }

        function toggleSMSOptions(prefix) {
            const checkbox = document.getElementById(prefix + '_notify_sms');
            const smsOptions = document.getElementById(prefix + '_sms_options');
            checkbox.checked ? smsOptions.classList.remove('hidden') : smsOptions.classList.add('hidden');
        }

        function toggleSMSAudienceSections(prefix) {
            const selected = document.querySelector('#' + prefix + '_sms_options input[name="sms_audience"]:checked');
            const areaList = document.getElementById(prefix + '_area_list');
            if (!selected || !areaList) return;
            areaList.classList.toggle('hidden', selected.value !== 'area');
        }

        // ─── Edit / View / Deactivate Alert Modals ───────────────────────────────────
        const disasterIconMap = {
            Typhoon: 'cyclone',
            Flood: 'waves',
            Fire: 'local_fire_department',
            Earthquake: 'landslide'
        };

        function openEditModal(alertData) {
            document.getElementById('edit_alert_id').value = alertData.AlertID;
            document.getElementById('edit_type').value = alertData.Type;
            document.getElementById('edit_severity').value = alertData.Severity;
            document.getElementById('edit_title').value = alertData.Title;
            document.getElementById('edit_message').value = alertData.Message;
            openModal('editAlertModal');
        }

        function openViewModal(alertData) {
            document.getElementById('view_title').innerText = alertData.Title;
            document.getElementById('view_message').innerText = alertData.Message;
            const icon = disasterIconMap[alertData.Type] || 'emergency';
            document.getElementById('view_type').innerHTML =
                `<span class="material-symbols-outlined !text-sm">${icon}</span> ${alertData.Type}`;

            const date = new Date(alertData.CreatedAt);
            document.getElementById('view_date').innerText = date.toLocaleDateString('en-US', {
                month: 'long', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit'
            });

            const badge = document.getElementById('view_severity_badge');
            badge.innerText = alertData.Severity;
            badge.className = 'inline-block px-3 py-1 rounded-full text-[8px] font-black text-white mb-3 '
                + (alertData.Severity === 'Critical' ? 'bg-red-500' : 'bg-orange-500');

            openModal('viewAlertModal');
        }

        function openDeactivateModal(id) {
            document.getElementById('deactivate_alert_id').value = id;
            openModal('deactivateModal');
        }

        // Disaster Analytics (chart, AI analytics, disaster reports, single-report
        // view / print / PDF) now lives on its own page: disaster_analytics.php

        // Reset the form to a clean state every time the modal is opened
        document.addEventListener('DOMContentLoaded', function () {
            // Pagination reloads ann.php; retain the Active Disaster modal when a
            // disaster page was explicitly requested so Next/Prev feels continuous.
            if (new URLSearchParams(window.location.search).has('dpage')) {
                openModal('activeDisasterModal');
            }

            const issueBtn = document.querySelector('[onclick="openModal(\'issueAlertModal\')"]');
            if (issueBtn) {
                issueBtn.addEventListener('click', function () {
                    document.getElementById('issue_type').value = '';
                });
            }
        });

        // Close disaster-related modals on backdrop click
        window.addEventListener('click', function (event) {
            ['issueAlertModal', 'activeDisasterModal', 'smsLiveModal',
                'editAlertModal', 'deactivateModal', 'viewAlertModal'].forEach(function (id) {
                    if (event.target === document.getElementById(id)) closeModal(id);
                });
        });

        // ─── Search & Filter ─────────────────────────────────────────────────────────
        const searchInput = document.getElementById('searchInput');
        const filterStatus = document.getElementById('filterStatus');
        const rows = document.querySelectorAll('.ann-row');
        const countEl = document.getElementById('tableCount');

        function applyFilter() {
            const q = searchInput.value.toLowerCase();
            const st = filterStatus.value.toUpperCase();
            let shown = 0;
            rows.forEach(row => {
                const title = row.dataset.title || '';
                const status = row.dataset.status || '';
                const matchQ = !q || title.includes(q);
                const matchSt = !st || status === st;
                row.style.display = (matchQ && matchSt) ? '' : 'none';
                if (matchQ && matchSt) shown++;
            });
            countEl.textContent = `Showing ${shown} entries`;
        }
        searchInput.addEventListener('input', applyFilter);
        filterStatus.addEventListener('change', applyFilter);

        // ─── State ────────────────────────────────────────────────────────────────────
        let galleryImages = [];
        let galleryIndex = 0;
        let currentAnnId = null;
        let currentAnnData = null;
        let currentFbPostId = null;

        // ─── View Modal ───────────────────────────────────────────────────────────────
        function openView(id) {
            fetch('../backend/get_ann.php?id=' + id)
                .then(r => {
                    if (!r.ok) {
                        return r.text().then(txt => {
                            showToast('error', '[HTTP ' + r.status + '] ' + (txt.substring(0, 150) || 'Server error'));
                            throw new Error('HTTP ' + r.status);
                        });
                    }
                    return r.text().then(txt => {
                        try {
                            return JSON.parse(txt);
                        } catch (e) {
                            showToast('error', '[JSON Error] get_ann.php returned: ' + txt.substring(0, 200));
                            throw new Error('JSON parse failed');
                        }
                    });
                })
                .then(data => {
                    if (!data) return;
                    if (!data.success) { showToast('error', '[get_ann.php] ' + (data.message || 'Unknown error')); return; }
                    const a = data.announcement;
                    const atts = data.attachments;

                    currentAnnId = a.id;
                    currentAnnData = a;
                    currentFbPostId = a.fb_post_id || null;

                    const annYear = a.created_at ? a.created_at.substring(0, 4) : (a.date_posted ? a.date_posted.substring(0, 4) : new Date().getFullYear());
                    document.getElementById('vm_id').textContent = '#ANN-' + annYear + '-' + String(a.ann_id || 0).padStart(4, '0');
                    document.getElementById('vm_title').textContent = a.title;
                    document.getElementById('vm_details').textContent = a.details;
                    document.getElementById('vm_date_posted').textContent = formatDate(a.date_posted);

                    // Scheduling info only applies to announcements that actually used the
                    // scheduling toggle (date_start is only ever saved when it was on) —
                    // this stays true even after a scheduled post has already gone live,
                    // since status flips to Published but date_start is untouched.
                    const postedBlock = document.getElementById('vm_posted_block');
                    const schedDateBlock = document.getElementById('vm_scheduled_date_block');
                    const endDateBlock = document.getElementById('vm_end_date_block');
                    const scheduledBanner = document.getElementById('vm_scheduled_banner');
                    const scheduledNote = document.getElementById('vm_scheduled_note');
                    const wasScheduled = !!a.date_start;

                    if (wasScheduled) {
                        postedBlock.classList.remove('col-span-2');
                        schedDateBlock.classList.remove('hidden');
                        document.getElementById('vm_scheduled_date').textContent =
                            formatDate(a.date_start) + (a.time_start ? ' · ' + fmtTime(a.time_start) : '');

                        endDateBlock.classList.remove('hidden');
                        document.getElementById('vm_end_date').textContent = a.date_end
                            ? formatDate(a.date_end) + (a.time_end ? ' · ' + fmtTime(a.time_end) : '')
                            : 'No end date';
                    } else {
                        postedBlock.classList.add('col-span-2');
                        schedDateBlock.classList.add('hidden');
                        endDateBlock.classList.add('hidden');
                    }

                    // Pending-auto-publish banner — only while it hasn't actually gone live yet
                    if (a.status === 'Scheduled') {
                        scheduledBanner.classList.remove('hidden');
                        const pubTime = fmtTime(a.time_start);
                        scheduledNote.textContent = 'Scheduled to auto-publish on '
                            + formatDate(a.date_start) + (pubTime ? ' at ' + pubTime : '') + '.';
                    } else {
                        scheduledBanner.classList.add('hidden');
                    }

                    const catBadge = document.getElementById('vm_category_badge');
                    catBadge.textContent = (a.category === 'Others' && a.category_other) ? a.category_other : a.category;
                    const catColors = {
                        'General': 'bg-slate-50 text-slate-500 border-slate-100',
                        'Health Advisory': 'bg-blue-50 text-blue-600 border-blue-100',
                        'Community Event': 'bg-purple-50 text-purple-600 border-purple-100',
                        'Emergency Notice': 'bg-orange-50 text-orange-600 border-orange-100',
                        'Others': 'bg-teal-50 text-teal-600 border-teal-100',
                    };
                    catBadge.className = 'px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase border ' +
                        (catColors[a.category] || catColors['General']);

                    const stBadge = document.getElementById('vm_status_badge');
                    const today = new Date().toISOString().split('T')[0];
                    let stLabel, stClass;
                    const isSchd = a.status === 'Scheduled';
                    const isExpired = !isSchd && a.date_end && a.date_end < today;
                    // Draft with a past date_end = manually ended via end_announcement.php
                    const isDraftEnded = a.status === 'Draft' && a.date_end && a.date_end <= today;
                    const hasEnded = a.status === 'Ended' || isDraftEnded;
                    if (hasEnded) { stLabel = 'Ended'; stClass = 'bg-amber-50 text-amber-600'; }
                    else if (a.status === 'Draft') { stLabel = 'Draft'; stClass = 'bg-slate-100 text-slate-400'; }
                    else if (isSchd) { stLabel = 'Scheduled'; stClass = 'bg-indigo-50 text-indigo-600'; }
                    else if (isExpired) { stLabel = 'Expired'; stClass = 'bg-slate-100 text-slate-400'; }
                    else { stLabel = 'Active'; stClass = 'bg-emerald-50 text-emerald-600'; }
                    stBadge.textContent = stLabel;
                    stBadge.className = 'px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase ' + stClass;

                    // "End Posting" button: only for Active announcements
                    const isActive = stLabel === 'Active';
                    const noEndDate = !a.date_end;
                    const endBtn = document.getElementById('vm_end_btn');
                    const manualEndSec = document.getElementById('vm_manual_end_section');
                    if (endBtn) {
                        if (isActive) {
                            endBtn.classList.remove('hidden');
                        } else {
                            endBtn.classList.add('hidden');
                        }
                    }
                    if (manualEndSec) {
                        if (isActive) {
                            manualEndSec.classList.toggle('hidden', !noEndDate);
                        } else {
                            manualEndSec.classList.add('hidden');
                        }
                    }
                    const endTitleLabel = document.getElementById('end_title_label');
                    if (endTitleLabel) endTitleLabel.textContent = a.title;

                    // "Recover Post": only for Ended / Expired announcements
                    const recoverBtn = document.getElementById('vm_recover_btn');
                    if (recoverBtn) recoverBtn.classList.toggle('hidden', !(stLabel === 'Ended' || stLabel === 'Expired'));

                    const fbPostedBadge = document.getElementById('vm_fb_posted_badge');
                    if (fbPostedBadge) fbPostedBadge.classList.toggle('hidden', !currentFbPostId);

                    // Edit link — hidden for Ended/Expired announcements
                    const editLink = document.getElementById('vm_edit_link');
                    if (editLink) {
                        if (stLabel === 'Ended' || stLabel === 'Expired') {
                            editLink.classList.add('hidden');
                        } else {
                            editLink.classList.remove('hidden');
                            editLink.href = 'edit_ann.php?id=' + a.id;
                        }
                    }

                    galleryImages = atts.filter(f => f.is_image == 1);
                    const files = atts.filter(f => f.is_image == 0);

                    const galSec = document.getElementById('vm_gallery_section');
                    if (galleryImages.length > 0) {
                        galSec.classList.remove('hidden');
                        galleryIndex = 0;
                        renderGallery();
                        const thumbsCont = document.getElementById('vm_thumbnails');
                        thumbsCont.innerHTML = '';
                        galleryImages.forEach((img, i) => {
                            const t = document.createElement('button');
                            t.className = 'w-14 h-14 rounded-xl overflow-hidden border-2 transition-all ' +
                                (i === 0 ? 'border-primary' : 'border-transparent opacity-60');
                            t.innerHTML = `<img src="${img.file_path}" class="w-full h-full object-cover" alt="">`;
                            t.onclick = () => { galleryIndex = i; renderGallery(); };
                            thumbsCont.appendChild(t);
                        });
                    } else {
                        galSec.classList.add('hidden');
                    }

                    const fileSec = document.getElementById('vm_files_section');
                    const fileList = document.getElementById('vm_files_list');
                    if (files.length > 0) {
                        fileSec.classList.remove('hidden');
                        fileList.innerHTML = '';
                        files.forEach(f => {
                            const extIcons = {
                                pdf: 'picture_as_pdf', doc: 'description', docx: 'description',
                                xls: 'table_chart', xlsx: 'table_chart', txt: 'text_snippet', zip: 'folder_zip'
                            };
                            const icon = extIcons[f.file_ext.toLowerCase()] || 'attach_file';
                            const size = formatSize(f.file_size);
                            fileList.innerHTML += `
                        <a href="${f.file_path}" download="${f.original_name}" target="_blank"
                           class="flex items-center gap-4 p-4 bg-slate-50 rounded-xl hover:bg-blue-50 transition-colors group">
                            <div class="w-10 h-10 bg-blue-100 rounded-xl flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-primary" style="font-size:20px">${icon}</span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-bold text-slate-700 truncate">${escHtml(f.original_name)}</p>
                                <p class="text-[10px] text-slate-400 font-medium uppercase">${f.file_ext.toUpperCase()} · ${size}</p>
                            </div>
                            <span class="material-symbols-outlined text-slate-300 group-hover:text-primary transition-colors">download</span>
                        </a>`;
                        });
                    } else {
                        fileSec.classList.add('hidden');
                    }

                    resetFbPanel();
                    buildFbPreview(a, galleryImages);
                    applyFbLockState(a);

                    document.getElementById('viewModal').classList.remove('hidden');
                    document.body.style.overflow = 'hidden';
                })
                .catch(err => { if (!['JSON parse failed'].includes(err.message) && !err.message.startsWith('HTTP ')) showToast('error', '[Network Error] ' + err.message); });
        }

        // Decides whether the Post-to-Facebook toggle is a live, clickable control or a
        // locked read-only status. It only stays interactive when the announcement has
        // neither already been posted to Facebook nor is already queued to auto-post
        // with a scheduled announcement — in both of those cases, posting again (or
        // re-queuing) isn't possible, so we lock the toggle and explain why instead.
        function applyFbLockState(a) {
            const toggleInput = document.getElementById('fbToggle');
            const toggleLabel = toggleInput.closest('.fb-toggle');
            const panel = document.getElementById('fbPanel');

            const isPosted = !!currentFbPostId;
            const isQueued = !isPosted && !!a.fb_pending && a.status === 'Scheduled';

            if (isPosted || isQueued) {
                toggleInput.checked = true;
                toggleInput.disabled = true;
                if (toggleLabel) toggleLabel.classList.add('opacity-50', 'cursor-not-allowed');
                panel.classList.remove('hidden');

                if (isPosted) {
                    document.getElementById('fb_post_id_display').textContent = currentFbPostId;
                    const parts = currentFbPostId.split('_');
                    document.getElementById('fb_view_link').href = parts.length === 2
                        ? `https://www.facebook.com/permalink.php?story_fbid=${parts[1]}&id=${parts[0]}`
                        : 'https://www.facebook.com';
                    showFbState('posted');
                } else {
                    const pubTime = fmtTime(a.time_start);
                    document.getElementById('fb_queued_date').textContent =
                        formatDate(a.date_start) + (pubTime ? ' at ' + pubTime : '');
                    showFbState('queued');
                }
            } else {
                toggleInput.disabled = false;
                if (toggleLabel) toggleLabel.classList.remove('opacity-50', 'cursor-not-allowed');
                showFbState('ready');
            }
        }

        function resetFbPanel() {
            const fbToggleEl = document.getElementById('fbToggle');
            const fbPanelEl = document.getElementById('fbPanel');
            if (fbToggleEl) {
                fbToggleEl.checked = false;
                fbToggleEl.disabled = false;
                const label = fbToggleEl.closest('.fb-toggle');
                if (label) label.classList.remove('opacity-50', 'cursor-not-allowed');
            }
            if (fbPanelEl) fbPanelEl.classList.add('hidden');
            showFbState('ready');
        }

        function handleFbToggle(checkbox) {
            const panel = document.getElementById('fbPanel');
            if (checkbox.checked) {
                panel.classList.remove('hidden');
                if (!currentFbPostId) showFbState('ready');
            } else {
                panel.classList.add('hidden');
            }
        }

        function buildFbPreview(a, images) {
            const category = (a.category || 'GENERAL').toUpperCase();
            const dateStart = a.date_start ? formatDate(a.date_start) : '';
            const dateEnd = a.date_end ? formatDate(a.date_end) : '';

            let msg = `📣 [${category}] ${a.title}\n\n${a.details}`;
            if (dateStart || dateEnd) {
                msg += '\n\n';
                if (dateStart && dateEnd && dateStart !== dateEnd)
                    msg += `📅 Valid: ${dateStart} – ${dateEnd}`;
                else if (dateStart)
                    msg += `📅 Date: ${dateStart}`;
            }
            msg += '\n\n—\nBarangay Biñang 2nd Official Announcement\n#BarangayBinang2nd #OfficialAnnouncement';

            document.getElementById('fb_preview_text').textContent = msg;

            const imgWrap = document.getElementById('fb_preview_img_wrap');
            const imgEl = document.getElementById('fb_preview_img');
            if (images && images.length > 0) {
                imgEl.src = images[0].file_path;
                imgWrap.classList.remove('hidden');
            } else {
                imgWrap.classList.add('hidden');
            }
        }

        function showFbState(state) {
            document.getElementById('fbReadyState').classList.toggle('hidden', state !== 'ready');
            document.getElementById('fbLoadingState').classList.toggle('hidden', state !== 'loading');
            document.getElementById('fbPostedState').classList.toggle('hidden', state !== 'posted');
            document.getElementById('fbQueuedState').classList.toggle('hidden', state !== 'queued');
            document.getElementById('fbErrorState').classList.toggle('hidden', state !== 'error');
        }

        function postToFacebook() {
            if (!currentAnnId) return;
            showFbState('loading');

            fetch('../backend/post_to_facebook.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ announcement_id: currentAnnId })
            })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        currentFbPostId = res.post_id;
                        document.getElementById('fb_post_id_display').textContent = res.post_id;

                        const parts = res.post_id.split('_');
                        document.getElementById('fb_view_link').href = parts.length === 2
                            ? `https://www.facebook.com/permalink.php?story_fbid=${parts[1]}&id=${parts[0]}`
                            : 'https://www.facebook.com';

                        showFbState('posted');
                        document.getElementById('vm_fb_posted_badge').classList.remove('hidden');
                        updateTableRowFbPill(currentAnnId);
                    } else {
                        document.getElementById('fbErrorMsg').textContent = res.error || 'Unknown error.';
                        showFbState('error');
                    }
                })
                .catch(err => {
                    document.getElementById('fbErrorMsg').textContent = 'Network error: ' + err.message;
                    showFbState('error');
                });
        }

        function retryFbPost() { showFbState('ready'); }

        function updateTableRowFbPill(annId) {
            document.querySelectorAll('.ann-row').forEach(row => {
                const btn = row.querySelector(`button[onclick*="openView(${annId})"]`);
                if (btn) {
                    const cells = row.querySelectorAll('td');
                    if (cells[5]) {
                        cells[5].innerHTML = `<span class="fb-posted-pill">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="#1877f2">
                        <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.312 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>
                    </svg>
                    Posted
                </span>`;
                    }
                }
            });
        }

        function renderGallery() {
            if (!galleryImages.length) return;
            const img = galleryImages[galleryIndex];
            const total = galleryImages.length;
            document.getElementById('vm_gallery_img').src = img.file_path;
            document.getElementById('vm_gallery_img').alt = img.original_name;

            // Counter — show "1 / 3" only if multiple images
            const counter = document.getElementById('vm_gallery_counter');
            counter.textContent = total > 1 ? (galleryIndex + 1) + ' / ' + total : '';

            // Prev/Next — hide entirely if only 1 image, else show/dim at boundaries
            const prevBtn = document.getElementById('vm_prev');
            const nextBtn = document.getElementById('vm_next');
            if (total <= 1) {
                prevBtn.style.display = 'none';
                nextBtn.style.display = 'none';
            } else {
                prevBtn.style.display = '';
                nextBtn.style.display = '';
                prevBtn.style.opacity = galleryIndex > 0 ? '1' : '0.35';
                prevBtn.style.pointerEvents = galleryIndex > 0 ? '' : 'none';
                nextBtn.style.opacity = galleryIndex < total - 1 ? '1' : '0.35';
                nextBtn.style.pointerEvents = galleryIndex < total - 1 ? '' : 'none';
            }

            document.querySelectorAll('#vm_thumbnails button').forEach((b, i) => {
                b.className = 'w-14 h-14 rounded-xl overflow-hidden border-2 transition-all ' +
                    (i === galleryIndex ? 'border-primary scale-105' : 'border-transparent opacity-60 hover:opacity-90');
            });
        }
        function galleryNav(dir) {
            galleryIndex = Math.max(0, Math.min(galleryImages.length - 1, galleryIndex + dir));
            renderGallery();
        }

        function closeView() {
            document.getElementById('viewModal').classList.add('hidden');
            document.body.style.overflow = '';
        }
        let pendingDeleteId = null;

        function confirmDelete(id, title) {
            pendingDeleteId = id;
            document.getElementById('del_title_label').textContent = title;
            // Reset button state
            document.getElementById('del_btn_icon').textContent = 'delete';
            document.getElementById('del_btn_text').textContent = 'Delete & Move to Trash';
            document.getElementById('del_confirm_btn').disabled = false;
            document.getElementById('deleteModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }
        function closeDelete() {
            document.getElementById('deleteModal').classList.add('hidden');
            document.body.style.overflow = '';
            pendingDeleteId = null;
        }
        function executeDelete() {
            if (!pendingDeleteId) return;

            // Capture the id NOW before closeDelete() nulls pendingDeleteId
            const deletedId = pendingDeleteId;

            // Show loading state
            const btn = document.getElementById('del_confirm_btn');
            document.getElementById('del_btn_icon').textContent = 'hourglass_empty';
            document.getElementById('del_btn_text').textContent = 'Deleting…';
            btn.disabled = true;

            fetch('../backend/delete_announcement.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'id=' + deletedId + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
            })
                .then(r => r.json())
                .then(data => {
                    closeDelete(); // nulls pendingDeleteId — but we already have deletedId
                    if (data.success) {
                        // Remove row using data-ann-id attribute (reliable, no regex issues)
                        const row = document.querySelector(`.ann-row[data-ann-id="${deletedId}"]`);
                        if (row) row.remove();
                        // Update count
                        const remaining = document.querySelectorAll('.ann-row').length;
                        document.getElementById('tableCount').textContent = 'Showing ' + remaining + ' entries';
                        setTrashBadge(data.trash_count);
                        showToast('success', data.message || 'Announcement moved to Trash.');
                        // refresh stat cards (Active / Scheduled / Ended totals)
                        setTimeout(() => window.location.reload(), 1300);
                    } else {
                        showToast('error', data.message || 'Failed to delete announcement.');
                    }
                })
                .catch(() => {
                    closeDelete();
                    showToast('error', 'Network error. Please try again.');
                });
        }
        function showToast(type, msg) {
            const existing = document.getElementById('ann_toast');
            if (existing) existing.remove();

            const colors = type === 'success'
                ? 'bg-emerald-50 border-emerald-100 text-emerald-700'
                : type === 'warning'
                    ? 'bg-amber-50 border-amber-100 text-amber-700'
                    : 'bg-rose-50 border-rose-100 text-rose-700';
            const icon = type === 'success' ? 'check_circle' : type === 'warning' ? 'warning' : 'error';
            const iconColor = type === 'success' ? 'text-emerald-500' : type === 'warning' ? 'text-amber-500' : 'text-rose-500';

            const toast = document.createElement('div');
            toast.id = 'ann_toast';
            toast.className = `fixed top-6 right-6 z-[9999] flex items-center gap-3 px-5 py-4 rounded-2xl border shadow-lg text-sm font-semibold ${colors} transition-all`;
            toast.innerHTML = `<span class="material-symbols-outlined ${iconColor}">${icon}</span>${msg}
        <button onclick="this.parentElement.remove()" class="ml-2 opacity-50 hover:opacity-100">
            <span class="material-symbols-outlined" style="font-size:16px">close</span>
        </button>`;
            document.body.appendChild(toast);
            setTimeout(() => { if (toast.parentElement) toast.remove(); }, 5000);
        }

        ['viewModal', 'deleteModal', 'endModal', 'recoverPostModal', 'trashModal', 'trashViewModal', 'restoreModal'].forEach(id => {
            document.getElementById(id).addEventListener('click', function (e) {
                if (e.target === this) {
                    this.classList.add('hidden');
                    document.body.style.overflow = '';
                }
            });
        });

        // ─── End Posting ─────────────────────────────────────────────────────────────
        function confirmEndPosting() {
            // title is already set in openView; just show the modal
            document.getElementById('end_btn_icon').textContent = 'stop_circle';
            document.getElementById('end_btn_text').textContent = 'End Posting';
            document.getElementById('end_confirm_btn').disabled = false;
            document.getElementById('endModal').classList.remove('hidden');
        }
        function closeEndModal() {
            document.getElementById('endModal').classList.add('hidden');
        }
        function executeEndPosting() {
            if (!currentAnnId) return;
            const endedId = currentAnnId; // capture before any async closure issues
            const btn = document.getElementById('end_confirm_btn');
            document.getElementById('end_btn_icon').textContent = 'hourglass_empty';
            document.getElementById('end_btn_text').textContent = 'Ending…';
            btn.disabled = true;

            fetch('../backend/end_announcement.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'id=' + endedId
            })
                .then(r => r.json())
                .then(data => {
                    closeEndModal();
                    closeView();
                    if (data.success) {
                        showToast('success', data.message || 'Announcement ended successfully.');
                        // Update the status pill in the table row
                        document.querySelectorAll('.ann-row').forEach(row => {
                            if (row.querySelector(`button[onclick*="openView(${endedId})"]`) ||
                                row.querySelector(`button[onclick="openView(${endedId})"]`)) {
                                const statusCell = row.querySelector('.ann-status-cell');
                                if (statusCell) {
                                    statusCell.innerHTML = `<div class="flex items-center gap-2 text-amber-500 text-[10px] font-bold uppercase"><span class="w-1.5 h-1.5 rounded-full bg-amber-400 inline-block"></span>ENDED</div>`;
                                }
                                row.dataset.status = 'ENDED';
                            }
                        });
                        // Update ended count stat card
                        const endedEl = document.querySelector('.card-amber .text-4xl');
                        if (endedEl) endedEl.textContent = parseInt(endedEl.textContent || '0') + 1;
                        // Decrement active count
                        const activeEl = document.querySelector('.card-green .text-4xl');
                        if (activeEl && parseInt(activeEl.textContent) > 0) activeEl.textContent = parseInt(activeEl.textContent) - 1;
                    } else {
                        showToast('error', data.message || 'Failed to end announcement.');
                        // Re-enable button so user can retry
                        document.getElementById('end_btn_icon').textContent = 'stop_circle';
                        document.getElementById('end_btn_text').textContent = 'End Posting';
                        btn.disabled = false;
                    }
                })
                .catch(() => {
                    closeEndModal();
                    showToast('error', 'Network error. Please try again.');
                });
        }

        function formatDate(d) {
            if (!d) return '—';
            const [y, m, day] = d.split('-');
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            return months[parseInt(m) - 1] + ' ' + day + ', ' + y;
        }
        function fmtTime(t) {
            if (!t || t === '00:00:00') return '';
            const [h, min] = t.split(':');
            const hr = parseInt(h);
            return (hr > 12 ? hr - 12 : hr || 12) + ':' + min + ' ' + (hr >= 12 ? 'PM' : 'AM');
        }
        function formatSize(bytes) {
            if (!bytes) return '0 B';
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1024 / 1024).toFixed(1) + ' MB';
        }
        function escHtml(str) {
            return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }


        // ═══════════════════════════════════════════════════════════════════════════
        //  Recover Post · Trash · Recover from Trash
        // ═══════════════════════════════════════════════════════════════════════════
        function setTrashBadge(n) {
            const b = document.getElementById('trashCountBadge');
            if (!b || n === undefined || n === null) return;
            b.textContent = n;
            b.classList.toggle('hidden', !(parseInt(n) > 0));
        }
        function postForm(url, fields) {
            const body = new URLSearchParams(fields);
            body.set('csrf_token', CSRF_TOKEN);
            return fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: body.toString()
            }).then(r => r.text().then(t => {
                try { return JSON.parse(t); }
                catch (e) { return { success: false, message: 'Unexpected server response.' }; }
            }));
        }
        function fmtDateTime(dt) {
            if (!dt) return '--';
            const [d, t] = dt.split(' ');
            const time = t ? fmtTime(t.substring(0, 8)) : '';
            return formatDate(d) + (time ? ' · ' + time : '');
        }
        function annRef(a) {
            const y = a.created_at ? a.created_at.substring(0, 4) : (a.date_posted ? a.date_posted.substring(0, 4) : new Date().getFullYear());
            return 'ANN-' + y + '-' + String(a.ann_id || 0).padStart(4, '0');
        }
        function todayLocalISO() {
            const d = new Date();
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        }
        const PREV_LABELS = {
            Active: 'Posted / Active', Scheduled: 'Scheduled', Ended: 'Ended',
            Expired: 'Expired', Draft: 'Draft'
        };
        const PREV_CLASSES = {
            Active: 'bg-emerald-50 text-emerald-600', Scheduled: 'bg-indigo-50 text-indigo-600',
            Ended: 'bg-amber-50 text-amber-600', Expired: 'bg-slate-100 text-slate-500',
            Draft: 'bg-slate-100 text-slate-500'
        };

        // --- Recover Post (Ended / Expired -> Posted/Active) -------------------------
        let recoverPostId = null;
        function openRecoverPost(id, title, stateLabel) {
            recoverPostId = id;
            const row = document.querySelector(`.ann-row[data-ann-id="${id}"]`);
            title = title || (row ? row.dataset.titleRaw : '') || '';
            stateLabel = stateLabel || (row ? row.dataset.status : '') || 'ended';
            document.getElementById('rp_title_label').textContent = title;
            document.getElementById('rp_state_label').textContent = String(stateLabel).toLowerCase();
            const d = document.getElementById('rp_end_date');
            d.value = ''; d.min = todayLocalISO();
            document.getElementById('rp_end_time').value = '';
            document.getElementById('rp_btn_icon').textContent = 'replay';
            document.getElementById('rp_btn_text').textContent = 'Recover Post';
            document.getElementById('rp_confirm_btn').disabled = false;
            document.getElementById('recoverPostModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }
        function openRecoverPostFromView() {
            if (!currentAnnData) return;
            const st = document.getElementById('vm_status_badge').textContent.trim();
            openRecoverPost(currentAnnData.id, currentAnnData.title, st);
        }
        function closeRecoverPost() {
            document.getElementById('recoverPostModal').classList.add('hidden');
            // keep page scroll locked if the View modal is still open underneath
            if (document.getElementById('viewModal').classList.contains('hidden')) document.body.style.overflow = '';
            recoverPostId = null;
        }
        function executeRecoverPost() {
            if (!recoverPostId) return;
            const id = recoverPostId;
            const btn = document.getElementById('rp_confirm_btn');
            document.getElementById('rp_btn_icon').textContent = 'hourglass_empty';
            document.getElementById('rp_btn_text').textContent = 'Recovering...';
            btn.disabled = true;

            postForm('../backend/recover_post.php', {
                id: id,
                end_date: document.getElementById('rp_end_date').value,
                end_time: document.getElementById('rp_end_time').value
            }).then(data => {
                if (data.success) {
                    closeRecoverPost();
                    closeView();
                    showToast('success', data.message || 'Announcement recovered.');
                    setTimeout(() => window.location.reload(), 1300);
                } else {
                    showToast('error', data.message || 'Failed to recover the post.');
                    document.getElementById('rp_btn_icon').textContent = 'replay';
                    document.getElementById('rp_btn_text').textContent = 'Recover Post';
                    btn.disabled = false;
                }
            }).catch(() => {
                showToast('error', 'Network error. Please try again.');
                document.getElementById('rp_btn_icon').textContent = 'replay';
                document.getElementById('rp_btn_text').textContent = 'Recover Post';
                btn.disabled = false;
            });
        }

        // --- Trash list ----------------------------------------------------------------
        let trashItems = [];
        function openTrash() {
            document.getElementById('trashModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            loadTrash();
        }
        function closeTrash() {
            document.getElementById('trashModal').classList.add('hidden');
            document.body.style.overflow = '';
        }
        function loadTrash() {
            const tb = document.getElementById('trashTbody');
            tb.innerHTML = '<tr><td colspan="6" class="px-6 py-16 text-center text-slate-400 italic text-sm">Loading&hellip;</td></tr>';
            fetch('../backend/get_trash.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        tb.innerHTML = '<tr><td colspan="6" class="px-6 py-16 text-center text-rose-500 text-sm font-semibold">' + escHtml(data.message || data.error || 'Failed to load Trash.') + '</td></tr>';
                        return;
                    }
                    trashItems = data.items || [];
                    setTrashBadge(trashItems.length);
                    document.getElementById('trashCountLabel').textContent = trashItems.length + ' in Trash';
                    if (!trashItems.length) {
                        tb.innerHTML = '<tr><td colspan="6" class="px-6 py-16 text-center text-slate-400 italic text-sm">Trash is empty.</td></tr>';
                        return;
                    }
                    tb.innerHTML = trashItems.map(a => {
                        const prev = a.status_before_delete || '';
                        return `
                <tr class="hover:bg-slate-50/50 transition-colors">
                    <td class="px-6 py-4"><span class="text-primary font-bold text-sm font-mono">${escHtml(a.ref || '')}</span></td>
                    <td class="px-6 py-4 font-semibold text-slate-700 text-sm max-w-xs truncate">${escHtml(a.title || '')}</td>
                    <td class="px-6 py-4"><span class="px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase ${PREV_CLASSES[prev] || 'bg-slate-100 text-slate-500'}">${escHtml(PREV_LABELS[prev] || prev || '--')}</span></td>
                    <td class="px-6 py-4 text-slate-600 text-xs font-semibold">${escHtml(a.deleted_by_name || '--')}</td>
                    <td class="px-6 py-4 text-slate-500 text-xs font-medium whitespace-nowrap">${escHtml(fmtDateTime(a.deleted_at))}</td>
                    <td class="px-6 py-4">
                        <div class="flex justify-end gap-2">
                            <button onclick="openTrashView(${parseInt(a.id)})" class="p-2 bg-indigo-50 text-indigo-600 rounded-xl hover:bg-indigo-100 transition-all" title="View">
                                <span class="material-symbols-outlined" style="font-size:17px">visibility</span>
                            </button>
                            <button onclick="openRestore(${parseInt(a.id)})" class="p-2 bg-emerald-50 text-emerald-600 rounded-xl hover:bg-emerald-100 transition-all" title="Recover">
                                <span class="material-symbols-outlined" style="font-size:17px">restore_from_trash</span>
                            </button>
                        </div>
                    </td>
                </tr>`;
                    }).join('');
                })
                .catch(() => {
                    tb.innerHTML = '<tr><td colspan="6" class="px-6 py-16 text-center text-rose-500 text-sm font-semibold">Network error while loading Trash.</td></tr>';
                });
        }

        // --- Trash: view deleted announcement ---------------------------------------------
        let trashViewId = null;
        function openTrashView(id) {
            fetch('../backend/get_ann.php?trash=1&id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) { showToast('error', data.message || 'Could not load this announcement.'); return; }
                    const a = data.announcement, atts = data.attachments || [];
                    trashViewId = a.id;

                    document.getElementById('tv_ref').textContent = '#' + annRef(a);
                    document.getElementById('tv_title').textContent = a.title;
                    document.getElementById('tv_details').textContent = a.details || '';

                    const cat = document.getElementById('tv_category');
                    cat.textContent = (a.category === 'Others' && a.category_other) ? a.category_other : a.category;

                    const prev = a.status_before_delete || '';
                    const pv = document.getElementById('tv_prev_status');
                    pv.textContent = 'Was: ' + (PREV_LABELS[prev] || prev || '--');
                    pv.className = 'px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase ' + (PREV_CLASSES[prev] || 'bg-slate-100 text-slate-500');

                    document.getElementById('tv_deleted_by').textContent =
                        (a.deleted_by_name || 'Unknown') + (a.deleted_by_role ? ' (' + a.deleted_by_role + ')' : '');
                    document.getElementById('tv_deleted_at').textContent = fmtDateTime(a.deleted_at);

                    document.getElementById('tv_date_posted').textContent = formatDate(a.date_posted);
                    const st = document.getElementById('tv_start');
                    st.textContent = a.date_start ? formatDate(a.date_start) + (a.time_start ? ' · ' + fmtTime(a.time_start) : '') : '--';
                    const en = document.getElementById('tv_end');
                    en.textContent = a.date_end ? formatDate(a.date_end) + (a.time_end ? ' · ' + fmtTime(a.time_end) : '') : 'No end date';

                    const imgs = atts.filter(f => f.is_image == 1), files = atts.filter(f => f.is_image != 1);
                    const imgSec = document.getElementById('tv_images_section');
                    imgSec.classList.toggle('hidden', !imgs.length);
                    document.getElementById('tv_images').innerHTML = imgs.map(f =>
                        `<a href="${escHtml(f.file_path)}" target="_blank" class="block aspect-video bg-slate-100 rounded-xl overflow-hidden">
                    <img src="${escHtml(f.file_path)}" alt="" class="w-full h-full object-cover"></a>`).join('');
                    const fileSec = document.getElementById('tv_files_section');
                    fileSec.classList.toggle('hidden', !files.length);
                    document.getElementById('tv_files').innerHTML = files.map(f =>
                        `<a href="${escHtml(f.file_path)}" download="${escHtml(f.original_name)}" target="_blank"
                    class="flex items-center gap-4 p-4 bg-slate-50 rounded-xl hover:bg-blue-50 transition-colors">
                    <span class="material-symbols-outlined text-primary" style="font-size:20px">attach_file</span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-slate-700 truncate">${escHtml(f.original_name)}</p>
                        <p class="text-[10px] text-slate-400 font-medium uppercase">${escHtml((f.file_ext || '').toUpperCase())} · ${formatSize(f.file_size)}</p>
                    </div></a>`).join('');

                    document.getElementById('tv_fb_row').classList.toggle('hidden', !a.fb_post_id);
                    document.getElementById('trashViewModal').classList.remove('hidden');
                })
                .catch(() => showToast('error', 'Network error. Please try again.'));
        }
        function closeTrashView() {
            document.getElementById('trashViewModal').classList.add('hidden');
            trashViewId = null;
        }
        function openRestoreFromView() { if (trashViewId) openRestore(trashViewId); }

        // --- Trash: recover deleted announcement ---------------------------------------------
        let restoreId = null, restorePrev = null;
        const RESTORE_OUTCOME = {
            Active: 'It will return as Posted / Active and become visible to residents again.',
            Ended: 'It will return as Ended (archived) and stay hidden from residents. To post it again, use Recover Post afterwards.',
            Expired: 'It will return as Expired and stay hidden from residents. To post it again, use Recover Post afterwards.',
            Scheduled: 'The old schedule will NOT be reused. Choose a new Start Date and Time below (End is optional) and it will return as Scheduled.',
            Draft: 'It will return as Draft.'
        };
        function openRestore(id) {
            const item = trashItems.find(x => parseInt(x.id) === parseInt(id));
            if (!item) { showToast('error', 'Announcement not found in Trash.'); return; }
            restoreId = id;
            restorePrev = item.status_before_delete || 'Draft';
            document.getElementById('rs_title_label').textContent = item.title;
            document.getElementById('rs_prev_label').textContent = PREV_LABELS[restorePrev] || restorePrev;
            document.getElementById('rs_outcome_text').textContent = RESTORE_OUTCOME[restorePrev] || '';
            const box = document.getElementById('rs_schedule_box');
            box.classList.toggle('hidden', restorePrev !== 'Scheduled');
            ['rs_start_date', 'rs_start_time', 'rs_end_date', 'rs_end_time'].forEach(k => document.getElementById(k).value = '');
            const today = todayLocalISO();
            document.getElementById('rs_start_date').min = today;
            document.getElementById('rs_end_date').min = today;
            document.getElementById('rs_btn_icon').textContent = 'restore_from_trash';
            document.getElementById('rs_btn_text').textContent = restorePrev === 'Scheduled' ? 'Recover & Schedule' : 'Recover';
            document.getElementById('rs_confirm_btn').disabled = false;
            document.getElementById('restoreModal').classList.remove('hidden');
        }
        function closeRestore() {
            document.getElementById('restoreModal').classList.add('hidden');
            restoreId = null;
        }
        function executeRestore() {
            if (!restoreId) return;
            const fields = { id: restoreId };
            if (restorePrev === 'Scheduled') {
                const sd = document.getElementById('rs_start_date').value;
                const stt = document.getElementById('rs_start_time').value;
                if (!sd || !stt) { showToast('warning', 'Please choose a new Start Date and Start Time.'); return; }
                fields.start_date = sd; fields.start_time = stt;
                fields.end_date = document.getElementById('rs_end_date').value;
                fields.end_time = document.getElementById('rs_end_time').value;
            }
            const btn = document.getElementById('rs_confirm_btn');
            const label = document.getElementById('rs_btn_text').textContent;
            document.getElementById('rs_btn_icon').textContent = 'hourglass_empty';
            document.getElementById('rs_btn_text').textContent = 'Recovering...';
            btn.disabled = true;

            postForm('../backend/restore_announcement.php', fields).then(data => {
                if (data.success) {
                    closeRestore(); closeTrashView(); closeTrash();
                    setTrashBadge(data.trash_count);
                    showToast('success', data.message || 'Announcement recovered.');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showToast(data.needs_schedule ? 'warning' : 'error', data.message || 'Failed to recover the announcement.');
                    document.getElementById('rs_btn_icon').textContent = 'restore_from_trash';
                    document.getElementById('rs_btn_text').textContent = label;
                    btn.disabled = false;
                }
            }).catch(() => {
                showToast('error', 'Network error. Please try again.');
                document.getElementById('rs_btn_icon').textContent = 'restore_from_trash';
                document.getElementById('rs_btn_text').textContent = label;
                btn.disabled = false;
            });
        }

        // ─── Soft-cron: check for due Scheduled announcements on every page load ──────
        // This fires silently so even if the server cron is not configured, any
        // announcement whose scheduled time has passed will auto-publish within
        // one page load of ann.php.
        (function softCronCheck() {
            fetch('../backend/run_scheduler.php', { method: 'POST' })
                .then(r => r.json())
                .then(data => {
                    if (!data.activated || data.activated < 1) return;

                    // One or more announcements were just activated → refresh the page
                    // so the table shows the correct Active status and updated stat cards.
                    // We use a small delay so any in-flight UI operations finish first.
                    showToast('success',
                        data.activated === 1
                            ? `"${data.items[0].title}" has been auto-published.`
                            : `${data.activated} scheduled announcements have been auto-published.`
                    );
                    setTimeout(() => { window.location.reload(); }, 2200);
                })
                .catch(() => {
                    // Silent failure — soft cron is best-effort
                });
        })();
    </script>

</body>

</html>
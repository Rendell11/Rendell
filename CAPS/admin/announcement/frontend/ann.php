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
// Just issued an alert with SMS → open "View SMS Live" for that broadcast
$sms_live_open = (int) ($_SESSION['sms_live_open'] ?? 0);
unset($_SESSION['sms_live_open']);

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

// Per-resident breakdown (targeted · sent · failed · no number) for each broadcast
require_once __DIR__ . '/../backend/sms_queue.php'; // also loads sms_recipient_log.php
$sms_counts = sms_breakdown_counts($pdo, array_merge(array_column($logs, 'LogID'), array_column($sms_history, 'LogID')));

// ── Active Disaster Alerts modal: summary + latest SMS result per alert ──────
$active_summary = ['critical_high' => 0, 'oldest' => null, 'by_type' => [], 'sms_sent' => 0, 'sms_targeted' => 0];
$alert_sms = []; // AlertID => ['log_id', 'sent', 'total', 'status', 'targeted', 'no_number', 'failed']
try {
    foreach ($pdo->query("SELECT AlertID, Type, Severity, CreatedAt FROM disaster_alerts WHERE Status = 'active'")->fetchAll(PDO::FETCH_ASSOC) as $a) {
        if (in_array($a['Severity'], ['High', 'Critical', 'Extreme'], true)) {
            $active_summary['critical_high']++;
        }
        $t = $a['Type'] ?: 'Other';
        $active_summary['by_type'][$t] = ($active_summary['by_type'][$t] ?? 0) + 1;
        if (!$active_summary['oldest'] || $a['CreatedAt'] < $active_summary['oldest']) {
            $active_summary['oldest'] = $a['CreatedAt'];
        }
    }
    arsort($active_summary['by_type']);

    $ids = array_map('intval', array_column($active_alerts, 'AlertID'));
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT LogID, AlertID, total_recipients, sent_count, status FROM sms_logs WHERE AlertID IN ($ph) ORDER BY created_at DESC");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
            if (isset($alert_sms[(int) $l['AlertID']])) continue; // keep the latest broadcast only
            $alert_sms[(int) $l['AlertID']] = ['log_id' => (int) $l['LogID'], 'sent' => (int) $l['sent_count'], 'total' => (int) $l['total_recipients'], 'status' => $l['status']];
        }
        $bdc = sms_breakdown_counts($pdo, array_column($alert_sms, 'log_id'));
        foreach ($alert_sms as &$as) {
            $b = $bdc[$as['log_id']] ?? null;
            $as['targeted'] = $b ? $b['targeted'] : $as['total'];
            $as['no_number'] = $b ? $b['no_number'] : 0;
            $as['failed'] = $b ? $b['failed'] : max(0, $as['total'] - $as['sent']);
            $as['has_breakdown'] = (bool) $b;
            $as['pending'] = $b ? $b['pending'] : 0;
            $as['confirmed'] = $b ? $b['confirmed'] : 0;
        }
        unset($as);
    }
    // Residents reached by SMS across every active alert (latest broadcast each)
    $st = $pdo->query(
        "SELECT sl.AlertID, sl.LogID, sl.sent_count, sl.total_recipients
           FROM sms_logs sl
           JOIN disaster_alerts da ON da.AlertID = sl.AlertID AND da.Status = 'active'
          ORDER BY sl.created_at DESC"
    );
    $seen = [];
    $latest = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        if (isset($seen[$l['AlertID']])) continue;
        $seen[$l['AlertID']] = true;
        $latest[(int) $l['LogID']] = $l;
    }
    $bdc = sms_breakdown_counts($pdo, array_keys($latest));
    foreach ($latest as $lid => $l) {
        $active_summary['sms_sent'] += (int) $l['sent_count'];
        $active_summary['sms_targeted'] += isset($bdc[$lid]) ? $bdc[$lid]['targeted'] : (int) $l['total_recipients'];
    }
} catch (Throwable $e) {
    error_log('[ann] active summary: ' . $e->getMessage());
}

if (!function_exists('active_duration')) {
    /** "3h 20m" / "2 days 4h" since the alert was issued. */
    function active_duration(?string $since): string
    {
        if (!$since) return '—';
        $mins = max(0, (int) floor((time() - strtotime($since)) / 60));
        if ($mins < 60) return $mins . 'm';
        $h = intdiv($mins, 60);
        if ($h < 24) return $h . 'h ' . ($mins % 60) . 'm';
        $d = intdiv($h, 24);
        return $d . ($d === 1 ? ' day ' : ' days ') . ($h % 24) . 'h';
    }
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
        global $sms_counts;
        $bd = $sms_counts[(int) ($log['LogID'] ?? 0)] ?? null;

        $label = $alertId
            ? 'Disaster #' . htmlspecialchars($alertId) . ($type ? ' · ' . htmlspecialchars(strtoupper($type)) : '')
            : 'DISASTER ALERT';

        ob_start();
        ?>
        <div class="rounded-[24px] border border-slate-100 p-6 bg-white" data-sms-log="<?= (int) ($log['LogID'] ?? 0) ?>"
            data-checkable="<?= (int) ($bd['checkable'] ?? 0) ?>"
            data-sending="<?= $bd && ($bd['queued'] + $bd['processing']) > 0 ? 1 : 0 ?>">
            <div class="flex justify-between items-start mb-4 gap-3">
                <div class="min-w-0">
                    <h4 class="text-xs font-black text-rose-600 uppercase tracking-widest"><?= $label ?></h4>
                    <?php if ($title): ?>
                        <p class="text-lg font-black text-slate-800 truncate mt-1 tracking-tight"><?= htmlspecialchars($title) ?></p>
                    <?php endif; ?>
                </div>
                <div class="flex flex-col items-end gap-1 flex-shrink-0">
                    <span class="text-xs font-semibold text-slate-400"><?= format_time_ago((int) $log['mins_ago']) ?></span>
                    <?php if ($isDeactivated): ?>
                        <span
                            class="px-2 py-0.5 rounded-full text-[8px] font-black uppercase tracking-widest bg-slate-100 text-slate-400">Deactivated</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($bd):
                $bdTotal = $bd['targeted'] - $bd['no_number'];
                $bdDone = $bd['sent'] + $bd['failed'] + $bd['cancelled'];
                $bdPct = $bdTotal > 0 ? round($bdDone / $bdTotal * 100, 1) : 100;
                $bdLive = ($bd['queued'] + $bd['processing']) > 0;
                $bdState = $bdLive ? (in_array($log['status'], ['Queued'], true) ? 'Queued' : 'Sending…') : ($log['status'] ?: 'Completed');
                ?>
                <div class="w-full bg-slate-100 h-2.5 rounded-full overflow-hidden mb-2">
                    <div class="h-full bar-fill transition-all duration-700" data-live="bar"
                        style="width: <?= $bdPct ?>%; background: var(--accent-600);"></div>
                </div>
                <div class="flex justify-between text-[11px] font-bold uppercase gap-3">
                    <span class="text-slate-400" data-live="progress"><?= $bdPct ?>% processed
                        (<?= (int) $bdDone ?>/<?= (int) $bdTotal ?> with a number)</span>
                    <span style="color: var(--accent-600);" class="font-black flex items-center gap-1" data-live="state">
                        <?php if ($bdLive): ?><span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span><?php endif; ?>
                        <?= htmlspecialchars($bdState) ?>
                    </span>
                </div>
                <!-- Accepted by the gateway, but the gateway phone could not send (e.g. SIM has no load) -->
                <div class="sms-phone-fail mt-5 rounded-2xl border border-rose-100 bg-rose-50 p-4 flex gap-3 <?= $bd['phone_failed'] ? '' : 'hidden' ?>">
                    <span class="material-symbols-outlined text-rose-600">signal_cellular_connected_no_internet_0_bar</span>
                    <div class="min-w-0">
                        <p class="text-xs font-black text-rose-700 uppercase"><span data-k="phone_failed"><?= (int) $bd['phone_failed'] ?></span> SMS not sent — failed on the gateway phone</p>
                        <p class="text-xs text-rose-600 font-semibold mt-0.5">Possibly <b>no load / promo</b>, no signal, or the phone is off. Reload the SIM, then re-send or reach these residents another way.</p>
                        <p class="text-[10px] text-rose-500 font-semibold mt-1 truncate" data-k="phone_reason"><?= htmlspecialchars($bd['phone_reason']) ?></p>
                    </div>
                </div>
                <p class="sms-check-status text-[10px] font-bold uppercase tracking-widest text-sky-600 mt-4 hidden"></p>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mt-4">
                    <?php foreach ([
                        ['total', 'Total', $bdTotal, 'bg-white text-slate-800', $bd['targeted'] . ' in the audience'],
                        ['sent', 'Sent', $bd['sent'], 'bg-emerald-50 text-emerald-700', $bd['confirmed'] . ' confirmed by phone'],
                        ['pending', 'Pending', $bd['queued'] + $bd['processing'], 'bg-sky-50 text-sky-700', $bd['processing'] . ' sending · ' . $bd['retry'] . ' retrying'],
                        ['failed', 'Failed', $bd['failed'], 'bg-rose-50 text-rose-700', $bd['phone_failed'] ? $bd['phone_failed'] . ' no load / phone failed' : 'Rejected / invalid no.'],
                        ['no_number', 'No number', $bd['no_number'], 'bg-amber-50 text-amber-700', 'Not included in the send'],
                    ] as [$key, $lbl, $num, $cls, $note]): ?>
                        <div class="rounded-2xl border border-slate-100 p-4 <?= $cls ?>" data-card="<?= $key ?>">
                            <p class="text-[10px] font-bold uppercase tracking-widest opacity-70"><?= $lbl ?></p>
                            <p class="text-2xl font-black mt-1" data-num><?= number_format((int) $num) ?></p>
                            <p class="text-[10px] font-semibold opacity-70 mt-0.5" data-note><?= htmlspecialchars($note) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                    <button type="button" onclick="openSmsMonitor(<?= (int) $log['LogID'] ?>)"
                        class="flex items-center justify-center gap-2 py-3 rounded-2xl text-white text-xs font-black uppercase btn-accent shadow-sm active:scale-95 transition-all">
                        <span class="material-symbols-outlined" style="font-size:18px">monitoring</span>
                        View SMS Live
                    </button>
                    <button type="button" onclick="openSmsBreakdown(<?= (int) $log['LogID'] ?>)"
                        class="flex items-center justify-center gap-2 py-3 rounded-2xl border border-slate-200 text-xs font-black uppercase text-slate-500 hover:border-indigo-300 hover:text-indigo-600 transition-all">
                        <span class="material-symbols-outlined" style="font-size:18px">table_view</span>
                        Breakdown by purok / area
                    </button>
                </div>
            <?php else: ?>
                <div class="w-full bg-slate-100 h-2.5 rounded-full overflow-hidden mb-2">
                    <div class="h-full bar-fill transition-all duration-1000"
                        style="width: <?= $progress ?>%; background: var(--accent-600);"></div>
                </div>
                <div class="flex justify-between text-[11px] font-bold uppercase">
                    <span class="text-slate-400"><?= $progress ?>% Accepted by gateway
                        (<?= (int) $log['sent_count'] ?>/<?= (int) $log['total_recipients'] ?> with a number)</span>
                    <span style="color: var(--accent-600);" class="font-black"><?= htmlspecialchars($log['status']) ?></span>
                </div>
            <?php endif; ?>
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
        $hours = (int) floor($mins / 60);
        if ($hours < 24)
            return $hours . ($hours === 1 ? ' hour ago' : ' hours ago');
        $days = (int) floor($hours / 24);
        if ($days < 7)
            return $days . ($days === 1 ? ' day ago' : ' days ago');
        $weeks = (int) floor($days / 7);
        if ($weeks < 5)
            return $weeks . ($weeks === 1 ? ' week ago' : ' weeks ago');
        $months = (int) floor($days / 30);
        if ($months < 12)
            return $months . ($months === 1 ? ' month ago' : ' months ago');
        $years = (int) floor($days / 365);
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
        const SMS_LIVE_OPEN = <?php echo (int) $sms_live_open; ?>;

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

        /* Accent button — same as Resident Management */
        .btn-accent { background: var(--accent-600); color: #fff; }
        .btn-accent:hover { background: var(--accent-700); }

        .modal-backdrop {
            background: rgba(15, 23, 42, 0.8);
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
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-6">
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

                <!-- ── Disaster Cards — same card style as Resident Management stats ── -->
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-6">
                    <div class="bg-white p-6 rounded-[32px] border border-slate-100 shadow-sm cursor-pointer transition-all hover:shadow-md hover:border-rose-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
                        role="button" tabindex="0" onclick="openModal('activeDisasterModal');"
                        onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openModal('activeDisasterModal');}"
                        title="View all active disasters">
                        <div class="flex items-start justify-between mb-4">
                            <div class="w-10 h-10 bg-rose-50 text-rose-600 rounded-xl flex items-center justify-center">
                                <span class="material-symbols-outlined">crisis_alert</span>
                            </div>
                            <span class="material-symbols-outlined text-slate-300 text-lg">arrow_outward</span>
                        </div>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-rose-500 animate-pulse"></span>Active Disaster</p>
                        <h3 class="text-2xl font-bold text-slate-800 mt-1"><?php echo (int) $total_alerts; ?></h3>
                        <p class="text-[10px] text-slate-400 font-semibold mt-0.5">Currently active</p>
                        <div class="mt-4 pt-3 border-t border-slate-100 flex items-start gap-2">
                            <span class="material-symbols-outlined text-rose-400 text-base leading-none mt-px">info</span>
                            <p class="text-[11px] text-slate-500 font-medium leading-snug">Disaster alerts that are still ongoing. Click to view, edit or <strong class="font-bold text-slate-600">deactivate</strong> an alert and submit its final report.</p>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-[32px] border border-slate-100 shadow-sm cursor-pointer transition-all hover:shadow-md hover:border-blue-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
                        role="button" tabindex="0" onclick="openModal('smsLiveModal');"
                        onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openModal('smsLiveModal');}"
                        title="View SMS broadcast status">
                        <div class="flex items-start justify-between mb-4">
                            <div class="w-10 h-10 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center">
                                <span class="material-symbols-outlined">sms</span>
                            </div>
                            <span class="material-symbols-outlined text-slate-300 text-lg">arrow_outward</span>
                        </div>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>SMS Live</p>
                        <h3 class="text-2xl font-bold text-slate-800 mt-1"><?php echo count($logs); ?></h3>
                        <p class="text-[10px] text-slate-400 font-semibold mt-0.5">Recent broadcasts</p>
                        <div class="mt-4 pt-3 border-t border-slate-100 flex items-start gap-2">
                            <span class="material-symbols-outlined text-blue-400 text-base leading-none mt-px">info</span>
                            <p class="text-[11px] text-slate-500 font-medium leading-snug">SMS sent to residents for the active alerts. Click to see the delivery progress of each broadcast and the SMS history.</p>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-[32px] border border-slate-100 shadow-sm cursor-pointer transition-all hover:shadow-md hover:border-indigo-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
                        role="button" tabindex="0" onclick="window.location.href='disaster_analytics.php';"
                        onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();window.location.href='disaster_analytics.php';}"
                        title="Open the Disaster Analytics page">
                        <div class="flex items-start justify-between mb-4">
                            <div class="w-10 h-10 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center">
                                <span class="material-symbols-outlined">analytics</span>
                            </div>
                            <span class="material-symbols-outlined text-slate-300 text-lg">arrow_outward</span>
                        </div>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest flex items-center gap-1.5">Disaster Analytics</p>
                        <h3 class="text-2xl font-bold text-slate-800 mt-1"><?php echo (int) $total_disaster_reports; ?></h3>
                        <p class="text-[10px] text-slate-400 font-semibold mt-0.5">Disaster reports logged</p>
                        <div class="mt-4 pt-3 border-t border-slate-100 flex items-start gap-2">
                            <span class="material-symbols-outlined text-indigo-400 text-base leading-none mt-px">info</span>
                            <p class="text-[11px] text-slate-500 font-medium leading-snug">Final reports filed when alerts were deactivated. Click to open Disaster Analytics — charts, AI insights, print and PDF.</p>
                        </div>
                    </div>
                </div>

                <!-- ── Announcement Archive Table ──────────────────────────────────── -->
                <!-- Search & filter row — same controls as Resident Management -->
                <div class="grid grid-cols-12 gap-4">
                    <div class="col-span-12 md:col-span-7 relative">
                        <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-xl">search</span>
                        <input type="text" id="searchInput" placeholder="Search announcements by title…"
                            class="w-full pl-11 pr-4 py-2.5 bg-white border border-slate-200 rounded-2xl text-sm focus:outline-none focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all shadow-sm">
                    </div>
                    <div class="col-span-6 md:col-span-3">
                        <select id="filterStatus"
                            class="w-full border-slate-200 rounded-2xl py-2.5 text-sm font-bold text-slate-600 focus:ring-indigo-500 transition-all shadow-sm">
                            <option value="">All Status</option>
                            <option value="ACTIVE">Active</option>
                            <option value="SCHEDULED">Scheduled</option>
                            <option value="EXPIRED">Expired</option>
                            <option value="ENDED">Ended</option>
                        </select>
                    </div>
                    <div class="col-span-6 md:col-span-2">
                        <!-- Announcement / Meta analytics (not Disaster Analytics) -->
                        <a href="announcement_analytics.php"
                            class="w-full h-full flex items-center justify-center gap-2 bg-white border border-slate-200 rounded-2xl py-2.5 text-sm font-bold text-indigo-600 hover:border-indigo-300 hover:bg-indigo-50 transition-all shadow-sm whitespace-nowrap"
                            title="Announcement posting & Facebook engagement analytics">
                            <span class="material-symbols-outlined text-lg">analytics</span>
                            View Analytics
                        </a>
                    </div>
                </div>

                <div class="bg-white rounded-[32px] shadow-sm border border-slate-100 overflow-hidden">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 px-8 py-5 border-b border-slate-50">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center">
                                <span class="material-symbols-outlined text-xl">campaign</span>
                            </div>
                            <div>
                                <h2 class="text-sm font-black text-slate-800 uppercase tracking-tight">Announcement Archive</h2>
                                <p class="text-[10px] text-slate-400 font-bold mt-0.5">All announcements posted to residents and the Facebook Page</p>
                            </div>
                        </div>
                    </div>

                    <div class="table-container overflow-x-auto">
                        <table class="w-full text-left border-collapse" id="annTable">
                            <thead>
                                <tr class="bg-slate-50/50 text-[10px] font-bold text-slate-400 uppercase tracking-widest border-b border-slate-50">
                                    <th class="px-6 py-5">ID</th>
                                    <th class="px-4 py-5">Title</th>
                                    <th class="px-4 py-5">Category</th>
                                    <th class="px-4 py-5">Posted On</th>
                                    <th class="px-4 py-5">Status</th>
                                    <th class="px-4 py-5">Facebook</th>
                                    <th class="px-4 py-5">Attachments</th>
                                    <th class="px-4 py-5 text-right">Actions</th>
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
                                                <span class="text-[11px] font-bold text-slate-500 uppercase tracking-tight whitespace-nowrap">ANN-<?php
                                                    $ann_year = !empty($ann['created_at']) ? date('Y', strtotime($ann['created_at'])) : date('Y', strtotime($ann['date_posted']));
                                                    echo $ann_year . '-' . str_pad((int) ($ann['ann_id'] ?? 0), 4, '0', STR_PAD_LEFT);
                                                    ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-5 text-sm font-bold text-slate-700 max-w-[220px] truncate">
                                                <?php echo htmlspecialchars($ann['title']); ?></td>
                                            <td class="px-4 py-5">
                                                <span
                                                    class="px-2 py-0.5 text-[9px] font-bold rounded-md uppercase border whitespace-nowrap <?php echo $cat_style; ?>">
                                                    <?php
                                                    $cat_display = ($ann['category'] === 'Others' && !empty($ann['category_other']))
                                                        ? $ann['category_other']
                                                        : $ann['category'];
                                                    echo htmlspecialchars($cat_display);
                                                    ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-5 text-xs font-semibold text-slate-600 whitespace-nowrap">
                                                <?php echo date('M d, Y', strtotime($ann['date_posted'])); ?></td>
                                            <td class="px-4 py-5 ann-status-cell">
                                                <div
                                                    class="flex items-center gap-2 <?php echo $status_color; ?> text-[10px] font-bold uppercase">
                                                    <span class="w-1.5 h-1.5 rounded-full <?php echo $dot; ?>"></span>
                                                    <?php echo $status_label; ?>
                                                </div>
                                            </td>
                                            <td class="px-4 py-5">
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
                                            <td class="px-4 py-5">
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
                                            <td class="px-4 py-5 text-right">
                                                <div
                                                    class="flex justify-end gap-2 opacity-0 group-hover:opacity-100 transition-all">
                                                    <button onclick='openView(<?php echo $ann["id"]; ?>)'
                                                        class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all"
                                                        title="View">
                                                        <span class="material-symbols-outlined" text-xl">visibility</span>
                                                    </button>
                                                    <?php if (in_array($status_label, ['ENDED', 'EXPIRED'], true) && staff_can($pdo, 'announcements', 'update')): ?>
                                                        <button onclick="openRecoverPost(<?php echo (int) $ann['id']; ?>)"
                                                            class="p-2 text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg transition-all"
                                                            title="Recover Post">
                                                            <span class="material-symbols-outlined" text-xl">replay</span>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (staff_can($pdo, 'announcements', 'delete')): ?>
                                                        <button
                                                            onclick="confirmDelete(<?php echo $ann['id']; ?>, '<?php echo addslashes(htmlspecialchars($ann['title'])); ?>')"
                                                            class="p-2 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-all"
                                                            title="Delete">
                                                            <span class="material-symbols-outlined" text-xl">delete</span>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-16 text-slate-400">
                                            <span class="material-symbols-outlined text-4xl block mb-2 text-slate-200">campaign</span>
                                            No announcements found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="px-8 py-4 border-t border-slate-50 flex justify-between items-center">
                        <span id="tableCount" class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Showing <?php echo count($announcements); ?>
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
        <div class="bg-white rounded-[2.5rem] shadow-2xl w-full max-w-3xl relative overflow-hidden" id="viewModalBox">

            <div class="flex items-start justify-between px-8 md:px-10 pt-8 md:pt-10 pb-6 border-b border-slate-100">
                <div>
                    <p class="text-xs text-primary font-bold uppercase tracking-widest mb-1" id="vm_id">#ANN-0000</p>
                    <h2 class="text-2xl font-black text-slate-900 tracking-tight leading-tight" id="vm_title">—</h2>
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
                    class="p-2 hover:bg-orange-50 rounded-full text-slate-400 hover:text-primary transition-colors shrink-0 ml-4">
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
                            <p class="text-[10px] font-bold text-slate-400 uppercase ml-1 mb-1.5">Post Preview</p>
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
                        class="hidden px-6 py-3.5 bg-amber-500 text-white text-xs font-black uppercase rounded-2xl shadow-lg hover:bg-amber-600 active:scale-95 transition-all flex items-center gap-2">
                        <span class="material-symbols-outlined" style="font-size:16px">stop_circle</span>
                        End Posting
                    </button>
                    <button id="vm_recover_btn" onclick="openRecoverPostFromView()"
                        class="hidden px-6 py-3.5 bg-emerald-600 text-white text-xs font-black uppercase rounded-2xl shadow-lg hover:bg-emerald-700 active:scale-95 transition-all flex items-center gap-2">
                        <span class="material-symbols-outlined" style="font-size:16px">replay</span>
                        Recover Post
                    </button>
                    <a id="vm_edit_link" href="#"
                        class="px-6 py-3.5 text-xs font-black uppercase text-slate-400 hover:text-slate-700 border border-slate-200 hover:border-slate-300 rounded-2xl transition-all">Edit</a>
                <?php endif; ?>
                <button onclick="closeView()"
                    class="px-6 py-3.5 btn-accent text-white text-xs font-black uppercase rounded-2xl shadow-lg active:scale-95 transition-all">Close</button>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
     DELETE CONFIRM MODAL
═══════════════════════════════════════════ -->
    <div id="deleteModal" class="hidden fixed inset-0 z-50 modal-backdrop flex items-center justify-center p-4">
        <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-md p-8">
            <div class="flex items-start gap-4 mb-5">
                <div class="w-12 h-12 rounded-2xl flex items-center justify-center shrink-0 bg-rose-50">
                    <span class="material-symbols-outlined text-rose-500 text-2xl">delete</span>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="text-base font-black text-slate-800 leading-tight tracking-tight">Delete Announcement?</h3>
                    <p class="text-xs text-slate-500 font-medium mt-1.5 leading-relaxed">You are about to delete:
                        <span class="font-bold text-slate-700" id="del_title_label"></span></p>
                </div>
            </div>
            <div class="bg-rose-50 border border-rose-100 rounded-2xl p-4 mb-6">
                <p class="text-xs text-rose-700 font-semibold leading-relaxed">
                    This announcement will be deleted and moved to <span class="font-black">Trash</span>.
                    It will no longer be visible to residents, and it will be recorded that you deleted it.
                </p>
                <p class="text-[11px] text-rose-500 mt-1.5 leading-relaxed">It is not permanently erased &mdash; you can
                    recover it anytime from the Trash section.</p>
            </div>
            <div class="flex gap-3">
                <button onclick="closeDelete()"
                    class="flex-1 py-3.5 text-xs font-black uppercase text-slate-400 hover:text-slate-700 border border-slate-200 hover:border-slate-300 rounded-2xl transition-all">Cancel</button>
                <button id="del_confirm_btn" onclick="executeDelete()"
                    class="flex-[2] py-3.5 bg-rose-600 text-white text-xs font-black uppercase rounded-2xl shadow-lg hover:bg-rose-700 active:scale-95 transition-all flex items-center justify-center gap-2">
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
        <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-md p-8">
            <div class="flex items-start gap-4 mb-5">
                <div class="w-12 h-12 rounded-2xl flex items-center justify-center shrink-0 bg-amber-50">
                    <span class="material-symbols-outlined text-amber-500 text-2xl">stop_circle</span>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="text-base font-black text-slate-800 leading-tight tracking-tight">End Announcement Posting?</h3>
                    <p class="text-xs text-slate-500 font-medium mt-1.5 leading-relaxed">You are about to end:
                        <span class="font-bold text-slate-700" id="end_title_label"></span></p>
                    <p class="text-xs text-slate-400 font-medium mt-1.5 leading-relaxed">This will mark the announcement as
                        ended and hide it from residents immediately.</p>
                </div>
            </div>
            <div class="flex gap-3">
                <button onclick="closeEndModal()"
                    class="flex-1 py-3.5 text-xs font-black uppercase text-slate-400 hover:text-slate-700 border border-slate-200 hover:border-slate-300 rounded-2xl transition-all">Cancel</button>
                <button id="end_confirm_btn" onclick="executeEndPosting()"
                    class="flex-[2] py-3.5 bg-amber-500 text-white text-xs font-black uppercase rounded-2xl shadow-lg hover:bg-amber-600 active:scale-95 transition-all flex items-center justify-center gap-2">
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
        <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-md p-8">
            <div class="flex items-start gap-4 mb-5">
                <div class="w-12 h-12 rounded-2xl flex items-center justify-center shrink-0 bg-emerald-50">
                    <span class="material-symbols-outlined text-emerald-600 text-2xl">replay</span>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="text-base font-black text-slate-800 leading-tight tracking-tight">Recover Post?</h3>
                    <p class="text-xs text-slate-500 font-medium mt-1.5 leading-relaxed">You are about to re-post:
                        <span class="font-bold text-slate-700" id="rp_title_label"></span></p>
                </div>
            </div>
            <div class="bg-emerald-50 border border-emerald-100 rounded-2xl p-4 mb-5">
                <p class="text-xs text-emerald-700 font-semibold leading-relaxed">
                    This <span id="rp_state_label">ended</span> announcement will go back to <span
                        class="font-black">Posted / Active</span>
                    and become visible to residents again. It keeps its original ID.
                </p>
            </div>
            <p class="text-[10px] font-bold text-slate-400 uppercase ml-1 mb-1.5">New end date &amp; time <span
                    class="normal-case font-semibold text-slate-400">(optional)</span></p>
            <div class="grid grid-cols-2 gap-3 mb-2">
                <input type="date" id="rp_end_date"
                    class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20">
                <input type="time" id="rp_end_time"
                    class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20">
            </div>
            <p class="text-[11px] text-slate-400 mb-6">Leave blank to keep it posted until you end it manually.</p>
            <div class="flex gap-3">
                <button onclick="closeRecoverPost()"
                    class="flex-1 py-3.5 text-xs font-black uppercase text-slate-400 hover:text-slate-700 border border-slate-200 hover:border-slate-300 rounded-2xl transition-all">Cancel</button>
                <button id="rp_confirm_btn" onclick="executeRecoverPost()"
                    class="flex-[2] py-3.5 bg-emerald-600 text-white text-xs font-black uppercase rounded-2xl shadow-lg hover:bg-emerald-700 active:scale-95 transition-all flex items-center justify-center gap-2">
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
        <div class="bg-white rounded-[2.5rem] shadow-2xl w-full max-w-5xl relative overflow-hidden">
            <div class="flex items-start justify-between px-8 md:px-10 pt-8 md:pt-10 pb-6 border-b border-slate-100">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-rose-50 rounded-2xl flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-rose-500" style="font-size:26px">delete_sweep</span>
                    </div>
                    <div>
                        <p class="text-xs text-rose-500 font-bold uppercase tracking-widest mb-0.5">Trash</p>
                        <h2 class="text-2xl font-black text-slate-900 tracking-tight leading-tight">Deleted Announcements</h2>
                        <p class="text-xs text-slate-400 mt-1">Deleted announcements are kept here so they can be viewed
                            or recovered.</p>
                    </div>
                </div>
                <button onclick="closeTrash()"
                    class="p-2 hover:bg-orange-50 rounded-full text-slate-400 hover:text-primary transition-colors shrink-0 ml-4">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="table-container overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50/50">
                            <th class="px-6 py-4 section-title">ID</th>
                            <th class="px-6 py-5">Title</th>
                            <th class="px-6 py-4 section-title">Original Status</th>
                            <th class="px-6 py-4 section-title">Deleted By</th>
                            <th class="px-6 py-4 section-title">Deleted On</th>
                            <th class="px-8 py-5 text-right">Actions</th>
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
            <div class="px-8 py-4 border-t border-slate-50 flex justify-between items-center">
                <span id="trashCountLabel" class="section-title">0 in Trash</span>
                <button onclick="closeTrash()"
                    class="px-6 py-3.5 btn-accent text-white text-xs font-black uppercase rounded-2xl shadow-lg active:scale-95 transition-all">Close</button>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
     TRASH — VIEW DELETED ANNOUNCEMENT
═══════════════════════════════════════════ -->
    <div id="trashViewModal"
        class="hidden fixed inset-0 z-[55] modal-backdrop flex items-start justify-center pt-8 pb-8 px-4 overflow-y-auto">
        <div class="bg-white rounded-[2.5rem] shadow-2xl w-full max-w-3xl relative overflow-hidden">
            <div class="flex items-start justify-between px-8 md:px-10 pt-8 md:pt-10 pb-6 border-b border-slate-100">
                <div>
                    <p class="text-xs text-primary font-bold uppercase tracking-widest mb-1" id="tv_ref">#ANN-0000</p>
                    <h2 class="text-2xl font-black text-slate-900 tracking-tight leading-tight" id="tv_title">--</h2>
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
                    class="p-2 hover:bg-orange-50 rounded-full text-slate-400 hover:text-primary transition-colors shrink-0 ml-4">
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
                    class="px-6 py-3.5 bg-emerald-600 text-white text-xs font-black uppercase rounded-2xl shadow-lg hover:bg-emerald-700 active:scale-95 transition-all flex items-center gap-2">
                    <span class="material-symbols-outlined" style="font-size:16px">restore_from_trash</span>
                    Recover
                </button>
                <button onclick="closeTrashView()"
                    class="px-6 py-3.5 text-xs font-black uppercase text-slate-400 hover:text-slate-700 border border-slate-200 hover:border-slate-300 rounded-2xl transition-all">Close</button>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
     TRASH — RECOVER (restore) CONFIRM
     Scheduled announcements must be given a NEW start date/time here.
═══════════════════════════════════════════ -->
    <div id="restoreModal" class="hidden fixed inset-0 z-[70] modal-backdrop flex items-center justify-center p-4">
        <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-md p-8">
            <div class="flex items-start gap-4 mb-5">
                <div class="w-12 h-12 rounded-2xl flex items-center justify-center shrink-0 bg-emerald-50">
                    <span class="material-symbols-outlined text-emerald-600 text-2xl">restore_from_trash</span>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="text-base font-black text-slate-800 leading-tight tracking-tight">Recover Announcement?</h3>
                    <p class="text-xs font-bold text-slate-700 mt-1.5" id="rs_title_label"></p>
                    <p class="text-xs text-slate-400 font-medium mt-1">Original status: <span id="rs_prev_label"
                            class="font-bold text-slate-500">--</span></p>
                </div>
            </div>
            <div class="bg-emerald-50 border border-emerald-100 rounded-2xl p-4 mb-5">
                <p class="text-xs text-emerald-700 font-semibold leading-relaxed" id="rs_outcome_text"></p>
            </div>

            <div id="rs_schedule_box" class="hidden mb-5">
                <p class="text-[10px] font-bold text-slate-400 uppercase ml-1 mb-1.5">New start <span class="text-rose-500">*</span></p>
                <div class="grid grid-cols-2 gap-3 mb-4">
                    <input type="date" id="rs_start_date"
                        class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20">
                    <input type="time" id="rs_start_time"
                        class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20">
                </div>
                <p class="text-[10px] font-bold text-slate-400 uppercase ml-1 mb-1.5">End date &amp; time <span
                        class="normal-case font-semibold text-slate-400">(optional)</span></p>
                <div class="grid grid-cols-2 gap-3">
                    <input type="date" id="rs_end_date"
                        class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20">
                    <input type="time" id="rs_end_time"
                        class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20">
                </div>
            </div>

            <div class="flex gap-3">
                <button onclick="closeRestore()"
                    class="flex-1 py-3.5 text-xs font-black uppercase text-slate-400 hover:text-slate-700 border border-slate-200 hover:border-slate-300 rounded-2xl transition-all">Cancel</button>
                <button id="rs_confirm_btn" onclick="executeRestore()"
                    class="flex-[2] py-3.5 bg-emerald-600 text-white text-xs font-black uppercase rounded-2xl shadow-lg hover:bg-emerald-700 active:scale-95 transition-all flex items-center justify-center gap-2">
                    <span id="rs_btn_icon" class="material-symbols-outlined"
                        style="font-size:16px">restore_from_trash</span>
                    <span id="rs_btn_text">Recover</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ── Issue Alert Modal (moved from Disaster module) ─────────────────────────── -->
    <div id="issueAlertModal"
        class="fixed inset-0 z-[100] hidden bg-slate-900/80 flex items-center justify-center p-6">
        <div
            class="bg-white rounded-[2.5rem] shadow-2xl w-full max-w-2xl overflow-hidden transform transition-all max-h-[95vh] overflow-y-auto">
            <div class="px-10 pt-10 pb-6 flex justify-between items-center border-b border-slate-100">
                <div>
                    <h3 class="text-2xl font-black tracking-tight text-slate-900">Issue Disaster Alert</h3>
                    <p class="text-xs text-primary font-bold uppercase tracking-widest mt-1">Broadcast emergency
                        protocols to residents</p>
                </div>
                <button onclick="closeModal('issueAlertModal')"
                    class="p-2 hover:bg-orange-50 rounded-full text-slate-400 hover:text-primary transition-colors shrink-0">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>

            <form action="../backend/process_disaster.php" method="POST" class="px-10 py-8 space-y-5" id="issueAlertForm"
                onsubmit="return lockIssueAlertForm(this)">
                <input type="hidden" name="action" value="create">
                <!-- one-time token: a double click / resubmit can never issue (and SMS) the same alert twice -->
                <input type="hidden" name="issue_token" value="<?php echo bin2hex(random_bytes(16)); ?>">
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
                    <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">SMS
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
                    <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Alert
                        Type</label>
                    <select name="type" id="issue_type" required
                        class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20 ">
                        <option value="" disabled selected>Select Disaster Alert</option>
                        <option value="Typhoon">Typhoon</option>
                        <option value="Flood">Flood</option>
                        <option value="Fire">Fire</option>
                        <option value="Earthquake">Earthquake</option>
                    </select>
                </div>

                <!-- Severity -->
                <div class="space-y-2">
                    <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Severity
                        Level</label>
                    <select name="severity" required
                        class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20 ">
                        <option value="" disabled selected>Select severity</option>
                        <option value="Low">Low</option>
                        <option value="Medium">Medium</option>
                        <option value="High">High</option>
                        <option value="Critical">Critical</option>
                    </select>
                </div>

                <!-- Alert Title -->
                <div class="space-y-2">
                    <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Alert
                        Title</label>
                    <input type="text" name="title" id="issue_title" placeholder="Enter alert title" required
                        class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20 placeholder:text-slate-300 ">
                </div>

                <!-- Message -->
                <div class="space-y-2">
                    <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Message /
                        Emergency Instructions</label>
                    <textarea name="message" id="issue_message" rows="4" placeholder="Enter instructions..." required
                        class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20 placeholder:text-slate-300  resize-none"></textarea>
                </div>

                <button type="submit" id="issueAlertSubmit"
                    class="w-full py-3.5 btn-accent text-white text-xs font-black uppercase rounded-2xl shadow-lg active:scale-95 transition-all mt-4 flex items-center justify-center gap-3 disabled:opacity-60 disabled:cursor-wait">
                    <span class="material-symbols-outlined !text-lg">campaign</span> Issue Alert Now
                </button>
            </form>
        </div>
    </div>

    <!-- ── Active Disaster Modal (card "!" — all active disasters) ────────────────── -->
    <div id="activeDisasterModal"
        class="fixed inset-0 z-[100] hidden bg-slate-900/80 flex items-center justify-center p-4">
        <div
            class="bg-white rounded-[2.5rem] shadow-2xl w-full max-w-3xl overflow-hidden max-h-[92vh] flex flex-col">
            <div class="px-8 md:px-10 pt-8 md:pt-10 pb-6 flex items-start justify-between border-b border-slate-100 flex-shrink-0">
                <div>
                    <h3 class="text-2xl font-black tracking-tight text-slate-900 flex items-center gap-3">
                        <span class="w-2.5 h-2.5 rounded-full bg-rose-500 animate-pulse pulse-ring flex-shrink-0"></span>
                        Active Disaster Alerts
                    </h3>
                    <p class="text-xs text-primary font-bold uppercase tracking-widest mt-1">
                        <?php echo (int) $total_alerts; ?> ongoing · view, update or deactivate</p>
                </div>
                <button onclick="closeModal('activeDisasterModal')"
                    class="p-2 hover:bg-orange-50 rounded-full text-slate-400 hover:text-primary transition-colors shrink-0">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="px-8 md:px-10 py-6 flex flex-col gap-4 overflow-y-auto">
                <?php if (empty($active_alerts)): ?>
                    <div class="flex flex-col items-center justify-center py-14 opacity-40">
                        <div class="w-16 h-16 rounded-2xl bg-slate-100 flex items-center justify-center mb-3">
                            <span class="material-symbols-outlined text-slate-400"
                                style="font-size:32px">notifications_off</span>
                        </div>
                        <p class="text-xs font-black uppercase tracking-widest text-slate-400">No Active Emergencies</p>
                    </div>
                <?php else: ?>
                    <!-- Summary -->
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div class="rounded-2xl border border-slate-100 p-4 bg-rose-50 text-rose-700">
                            <p class="text-[10px] font-bold uppercase tracking-widest opacity-70">Active Alerts</p>
                            <p class="text-2xl font-black mt-1"><?php echo (int) $total_alerts; ?></p>
                            <p class="text-[10px] font-semibold opacity-70 mt-0.5 truncate">
                                <?php echo htmlspecialchars(implode(' · ', array_map(fn($t, $n) => "$n $t", array_keys($active_summary['by_type']), $active_summary['by_type'])) ?: '—'); ?></p>
                        </div>
                        <div class="rounded-2xl border border-slate-100 p-4 bg-orange-50 text-orange-700">
                            <p class="text-[10px] font-bold uppercase tracking-widest opacity-70">High / Critical</p>
                            <p class="text-2xl font-black mt-1"><?php echo (int) $active_summary['critical_high']; ?></p>
                            <p class="text-[10px] font-semibold opacity-70 mt-0.5">Need close monitoring</p>
                        </div>
                        <div class="rounded-2xl border border-slate-100 p-4 bg-white text-slate-800">
                            <p class="text-[10px] font-bold uppercase tracking-widest opacity-70">Longest Running</p>
                            <p class="text-2xl font-black mt-1"><?php echo active_duration($active_summary['oldest']); ?></p>
                            <p class="text-[10px] font-semibold opacity-70 mt-0.5">Since the oldest alert</p>
                        </div>
                        <div class="rounded-2xl border border-slate-100 p-4 bg-emerald-50 text-emerald-700">
                            <p class="text-[10px] font-bold uppercase tracking-widest opacity-70">Accepted by SMS gateway</p>
                            <p class="text-2xl font-black mt-1"><?php echo (int) $active_summary['sms_sent']; ?><span class="text-sm font-bold opacity-60">/<?php echo (int) $active_summary['sms_targeted']; ?></span></p>
                            <p class="text-[10px] font-semibold opacity-70 mt-0.5">Latest broadcasts · confirm in SMS Live</p>
                        </div>
                    </div>

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
                        <?php
                        $sev = $alert['Severity'] ?: 'Medium';
                        $sevCls = in_array($sev, ['Critical', 'Extreme'], true) ? 'bg-rose-500' : ($sev === 'High' ? 'bg-orange-500' : ($sev === 'Medium' ? 'bg-amber-500' : 'bg-emerald-500'));
                        $accent = in_array($sev, ['Critical', 'Extreme'], true) ? 'border-l-rose-400' : ($sev === 'High' ? 'border-l-orange-400' : ($sev === 'Medium' ? 'border-l-amber-400' : 'border-l-emerald-400'));
                        $sms = $alert_sms[(int) $alert['AlertID']] ?? null;
                        $pct = ($sms && $sms['targeted'] > 0) ? round($sms['sent'] / $sms['targeted'] * 100) : 0;
                        ?>
                        <div class="rounded-[24px] border border-slate-100 border-l-4 <?php echo $accent; ?> p-6 bg-white hover:shadow-md transition-all">
                            <!-- Title row -->
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex items-start gap-4 min-w-0">
                                    <div class="h-12 w-12 rounded-2xl <?php echo $bg; ?> border <?php echo $border; ?> flex items-center justify-center flex-shrink-0 <?php echo $color; ?>">
                                        <span class="material-symbols-outlined" style="font-size:24px"><?php echo $icon; ?></span>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-xs font-black uppercase tracking-widest <?php echo $color; ?>">
                                            Disaster #<?php echo (int) $alert['AlertID']; ?> · <?php echo htmlspecialchars(strtoupper($alert['Type'] ?? '')); ?></p>
                                        <div class="flex items-center gap-2 flex-wrap mt-1">
                                            <p class="text-lg font-black text-slate-800 tracking-tight truncate">
                                                <?php echo htmlspecialchars($alert['Title']); ?></p>
                                            <span class="px-2 py-0.5 rounded-md text-[9px] font-bold text-white <?php echo $sevCls; ?>">
                                                <?php echo htmlspecialchars(strtoupper($sev)); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Active for</p>
                                    <p class="text-sm font-black text-rose-600"><?php echo active_duration($alert['CreatedAt'] ?? null); ?></p>
                                </div>
                            </div>

                            <!-- Message -->
                            <p class="text-sm text-slate-600 font-medium leading-relaxed mt-4 bg-slate-50 rounded-2xl px-4 py-3 line-clamp-3">
                                <?php echo nl2br(htmlspecialchars($alert['Message'] ?: 'No message.')); ?></p>

                            <!-- Details -->
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-4">
                                <div>
                                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Issued</p>
                                    <p class="text-xs font-bold text-slate-700 mt-0.5"><?php echo !empty($alert['CreatedAt']) ? date('M j, Y · g:i A', strtotime($alert['CreatedAt'])) : '—'; ?></p>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Location</p>
                                    <p class="text-xs font-bold text-slate-700 mt-0.5 truncate" title="<?php echo htmlspecialchars($alert['IncidentLocation'] ?? ''); ?>">
                                        <?php echo htmlspecialchars(($alert['IncidentLocation'] ?? '') ?: 'Barangay-wide'); ?>
                                        <?php if (!empty($alert['HazardRadius']) && !empty($alert['HazardLat'])): ?><span class="text-slate-400 font-semibold">· <?php echo (int) $alert['HazardRadius']; ?> m</span><?php endif; ?></p>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Evacuation</p>
                                    <p class="text-xs font-bold text-slate-700 mt-0.5 truncate"><?php echo htmlspecialchars((($alert['EvacuationCenter'] ?? '') && $alert['EvacuationCenter'] !== 'None') ? $alert['EvacuationCenter'] : 'None set'); ?></p>
                                </div>
                                <div>
                                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Channels</p>
                                    <div class="flex gap-1.5 mt-1">
                                        <span class="px-2 py-0.5 text-[9px] font-bold rounded-md uppercase border <?php echo !empty($alert['notify_app']) ? 'bg-indigo-50 text-indigo-600 border-indigo-100' : 'bg-slate-50 text-slate-300 border-slate-100 line-through'; ?>">App</span>
                                        <span class="px-2 py-0.5 text-[9px] font-bold rounded-md uppercase border <?php echo !empty($alert['notify_sms']) ? 'bg-emerald-50 text-emerald-600 border-emerald-100' : 'bg-slate-50 text-slate-300 border-slate-100 line-through'; ?>">SMS</span>
                                    </div>
                                </div>
                            </div>

                            <!-- SMS result -->
                            <?php if ($sms): ?>
                                <div class="mt-4 rounded-2xl border border-slate-100 px-4 py-3">
                                    <div class="flex items-center justify-between gap-3 mb-2">
                                        <p class="text-[11px] font-bold text-slate-500 uppercase">
                                            SMS: <?php if ($sms['has_breakdown']): ?><span class="text-emerald-600"><?php echo $sms['confirmed']; ?> confirmed sent</span><?php if ($sms['pending']): ?> · <span class="text-sky-600"><?php echo $sms['pending']; ?> awaiting</span><?php endif; ?>
                                            <?php else: ?><span class="text-emerald-600"><?php echo $sms['sent']; ?> accepted</span><?php endif; ?>
                                            of <?php echo $sms['targeted']; ?> targeted
                                            <?php if ($sms['no_number']): ?> · <span class="text-amber-600"><?php echo $sms['no_number']; ?> no number</span><?php endif; ?>
                                            <?php if ($sms['failed']): ?> · <span class="text-rose-600"><?php echo $sms['failed']; ?> failed</span><?php endif; ?>
                                        </p>
                                        <?php if ($sms['has_breakdown']): ?>
                                            <button type="button" onclick="openSmsBreakdown(<?php echo $sms['log_id']; ?>)"
                                                class="text-[10px] font-black uppercase text-indigo-600 hover:underline flex-shrink-0">Breakdown →</button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                                        <div class="h-full bg-emerald-500" style="width: <?php echo $pct; ?>%"></div>
                                    </div>
                                </div>
                            <?php elseif (!empty($alert['notify_sms'])): ?>
                                <p class="mt-4 text-[11px] font-bold text-amber-600 bg-amber-50 border border-amber-100 rounded-2xl px-4 py-3">
                                    SMS was requested but no broadcast was recorded (check Settings → SMS Configuration).</p>
                            <?php endif; ?>

                            <!-- Actions -->
                            <div class="flex items-center justify-end gap-2 mt-5 pt-4 border-t border-slate-100">
                                <button onclick="openViewModal(<?php echo htmlspecialchars(json_encode($alert)); ?>)"
                                    class="flex items-center gap-1.5 px-4 py-2.5 rounded-2xl border border-slate-200 text-xs font-black uppercase text-slate-500 hover:border-indigo-300 hover:text-indigo-600 transition-all">
                                    <span class="material-symbols-outlined" style="font-size:16px">visibility</span> View
                                </button>
                                <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($alert)); ?>)"
                                    class="flex items-center gap-1.5 px-4 py-2.5 rounded-2xl border border-slate-200 text-xs font-black uppercase text-slate-500 hover:border-indigo-300 hover:text-indigo-600 transition-all">
                                    <span class="material-symbols-outlined" style="font-size:16px">edit_square</span> Update
                                </button>
                                <button onclick="openDeactivateModal(<?php echo $alert['AlertID']; ?>)"
                                    class="flex items-center gap-1.5 px-4 py-2.5 rounded-2xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-black uppercase shadow-lg active:scale-95 transition-all">
                                    <span class="material-symbols-outlined" style="font-size:16px">task_alt</span> Deactivate
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($disaster_total_pages > 1): ?>
                        <div class="flex justify-center items-center gap-3 mt-2 pt-4 border-t border-slate-50">
                            <?php if ($disaster_page > 1): ?>
                                <a href="?dpage=<?= $disaster_page - 1 ?>#"
                                    class="px-4 py-2 border border-slate-200 rounded-2xl text-[10px] font-black uppercase text-slate-500 hover:border-indigo-300 hover:text-indigo-600 transition-all">←
                                    Prev</a>
                            <?php endif; ?>
                            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Page
                                <?= $disaster_page ?> of <?= $disaster_total_pages ?></span>
                            <?php if ($disaster_page < $disaster_total_pages): ?>
                                <a href="?dpage=<?= $disaster_page + 1 ?>#"
                                    class="px-4 py-2 border border-slate-200 rounded-2xl text-[10px] font-black uppercase text-slate-500 hover:border-indigo-300 hover:text-indigo-600 transition-all">Next
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
        class="fixed inset-0 z-[100] hidden bg-slate-900/80 flex items-center justify-center p-4">
        <div
            class="bg-white rounded-[2.5rem] shadow-2xl w-full max-w-3xl overflow-hidden max-h-[92vh] flex flex-col">
            <div class="px-8 md:px-10 pt-8 md:pt-10 pb-6 flex items-start justify-between border-b border-slate-100 flex-shrink-0">
                <div>
                    <h3 class="text-2xl font-black tracking-tight text-slate-900" id="smsLiveModalTitle">SMS Notification Live Status</h3>
                    <p class="text-xs text-primary font-bold uppercase tracking-widest mt-1"
                        id="smsLiveModalSubtitle">Per-disaster send progress</p>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                    <button type="button" onclick="toggleSMSHistory()" id="smsHistoryToggleBtn"
                        class="flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-slate-200 text-[10px] font-black uppercase text-slate-500 hover:border-indigo-300 hover:text-indigo-600 transition-all">
                        <span class="material-symbols-outlined" style="font-size:16px">history</span>
                        History
                    </button>
                    <button onclick="closeModal('smsLiveModal')"
                        class="p-2 hover:bg-orange-50 rounded-full text-slate-400 hover:text-primary transition-colors shrink-0">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
            </div>

            <!-- Live view (recent broadcasts) -->
            <div id="smsLiveList" class="px-8 md:px-10 py-6 space-y-4 overflow-y-auto">
                <?php if (empty($logs)): ?>
                    <div class="py-12 text-center opacity-40">
                        <span class="material-symbols-outlined text-slate-400" style="font-size:36px">sms</span>
                        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mt-2">No SMS Logs</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($logs as $log):
                        echo render_sms_log_entry($log); endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- History view (all broadcasts, incl. deactivated disasters) -->
            <div id="smsHistoryList" class="hidden px-8 md:px-10 py-6 space-y-4 overflow-y-auto">
                <?php if (empty($sms_history)): ?>
                    <div class="py-12 text-center opacity-40">
                        <span class="material-symbols-outlined text-slate-400" style="font-size:36px">history</span>
                        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mt-2">No SMS History</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($sms_history as $log):
                        echo render_sms_log_entry($log); endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>


    <!-- ── SMS Breakdown Modal (per purok / area + residents not reached) ──────────── -->
    <div id="smsBreakdownModal" class="fixed inset-0 z-[120] hidden bg-slate-900/80 flex items-center justify-center p-4">
        <div class="bg-white rounded-[2.5rem] shadow-2xl w-full max-w-3xl overflow-hidden max-h-[92vh] flex flex-col">
            <div class="px-8 md:px-10 pt-8 md:pt-10 pb-6 flex items-start justify-between border-b border-slate-100 flex-shrink-0">
                <div class="min-w-0">
                    <p class="text-xs text-primary font-bold uppercase tracking-widest mb-1" id="sbd_kicker">SMS Breakdown</p>
                    <h3 class="text-2xl font-black tracking-tight text-slate-900 leading-tight truncate" id="sbd_title">—</h3>
                    <p class="text-xs text-slate-400 font-semibold mt-1" id="sbd_meta">—</p>
                </div>
                <button onclick="closeModal('smsBreakdownModal')"
                    class="p-2 hover:bg-orange-50 rounded-full text-slate-400 hover:text-primary transition-colors shrink-0 ml-4">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="px-8 md:px-10 py-6 overflow-y-auto space-y-6" id="sbd_body">
                <div class="py-10 flex justify-center"><div class="w-6 h-6 border-4 border-slate-200 border-t-slate-500 rounded-full animate-spin"></div></div>
            </div>
        </div>
    </div>

    <!-- ── View SMS Live: real-time monitor of one disaster SMS broadcast ─────────── -->
    <div id="smsMonitorModal" class="fixed inset-0 z-[110] hidden bg-slate-900/80 flex items-center justify-center p-4">
        <div class="bg-white rounded-[2.5rem] shadow-2xl w-full max-w-5xl overflow-hidden max-h-[94vh] flex flex-col">
            <div class="px-8 md:px-10 pt-8 md:pt-10 pb-6 flex items-start justify-between border-b border-slate-100 flex-shrink-0 gap-4">
                <div class="min-w-0">
                    <p class="text-xs text-primary font-bold uppercase tracking-widest mb-1" id="smm_kicker">Disaster Alert SMS Status</p>
                    <h3 class="text-2xl font-black tracking-tight text-slate-900 leading-tight truncate" id="smm_title">—</h3>
                    <p class="text-xs text-slate-400 font-semibold mt-1" id="smm_meta">—</p>
                </div>
                <div class="flex items-center gap-3 shrink-0">
                    <span id="smm_state" class="px-3 py-1.5 rounded-full text-[10px] font-black uppercase tracking-widest bg-slate-100 text-slate-500 flex items-center gap-1.5">—</span>
                    <button onclick="closeSmsMonitor()"
                        class="p-2 hover:bg-orange-50 rounded-full text-slate-400 hover:text-primary transition-colors">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
            </div>
            <div class="px-8 md:px-10 py-6 overflow-y-auto space-y-6" id="smm_body">
                <div class="py-10 flex justify-center"><div class="w-6 h-6 border-4 border-slate-200 border-t-slate-500 rounded-full animate-spin"></div></div>
            </div>
        </div>
    </div>

    <!-- Disaster Analytics + View Disaster Report now live on disaster_analytics.php -->

    <!-- ── Edit Alert Modal (moved from disaster_modals.php) ───────────────────────── -->
    <div id="editAlertModal"
        class="fixed inset-0 z-[100] hidden bg-slate-900/80 flex items-center justify-center p-6">
        <div
            class="bg-white rounded-[2.5rem] shadow-2xl w-full max-w-2xl overflow-hidden transform transition-all max-h-[95vh] overflow-y-auto">
            <div class="px-10 pt-10 pb-6 flex justify-between items-center border-b border-slate-100">
                <div>
                    <h3 class="text-2xl font-black tracking-tight text-slate-900">Update Alert</h3>
                    <p class="text-xs text-primary font-bold uppercase tracking-widest mt-1">Modify existing
                        emergency broadcast</p>
                </div>
                <button onclick="closeModal('editAlertModal')"
                    class="p-2 hover:bg-orange-50 rounded-full text-slate-400 hover:text-primary transition-colors shrink-0">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>

            <form action="../backend/process_disaster.php" method="POST" class="px-10 py-8 space-y-5">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="alert_id" id="edit_alert_id">
                <?php echo csrf_token(); ?>

                <div class="space-y-2">
                    <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Alert
                        Type</label>
                    <select name="type" id="edit_type" required
                        class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20 ">
                        <option value="Typhoon">Typhoon</option>
                        <option value="Flood">Flood</option>
                        <option value="Fire">Fire</option>
                        <option value="Earthquake">Earthquake</option>
                    </select>
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Severity
                        Level</label>
                    <select name="severity" id="edit_severity" required
                        class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20 ">
                        <option value="Low">Low</option>
                        <option value="Medium">Medium</option>
                        <option value="High">High</option>
                        <option value="Critical">Critical</option>
                    </select>
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Alert
                        Title</label>
                    <input type="text" name="title" id="edit_title" required
                        class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20 ">
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Message /
                        Emergency Instructions</label>
                    <textarea name="message" id="edit_message" rows="4" required
                        class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20  resize-none"></textarea>
                </div>

                <button type="submit"
                    class="w-full py-3.5 text-white text-xs font-black uppercase rounded-2xl shadow-lg active:scale-95 transition-all mt-4 flex items-center justify-center gap-3"
                    style="background: var(--accent-600);">
                    <span class="material-symbols-outlined !text-lg">published_with_changes</span> Save Changes
                </button>
            </form>
        </div>
    </div>

    <!-- ── Deactivate / Report Modal (moved from disaster_modals.php) ──────────────── -->
    <div id="deactivateModal"
        class="fixed inset-0 z-[100] hidden bg-slate-900/80 flex items-center justify-center p-4">
        <div
            class="bg-white rounded-[2.5rem] shadow-2xl w-full max-w-lg overflow-hidden transform transition-all max-h-[95vh] overflow-y-auto">
            <div class="px-10 pt-10 pb-4 flex justify-between items-center border-b border-slate-100">
                <div>
                    <h3 class="text-2xl font-black tracking-tight text-slate-900">Resolve Disaster Alert</h3>
                    <p class="text-xs text-primary font-bold uppercase tracking-widest mt-1">Submit a final
                        disaster report</p>
                </div>
                <button onclick="closeModal('deactivateModal')"
                    class="p-2 hover:bg-orange-50 rounded-full text-slate-400 hover:text-primary transition-colors shrink-0">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>

            <form action="../backend/process_disaster.php" method="POST" class="px-10 py-8 space-y-5">
                <input type="hidden" name="action" value="deactivate_with_report">
                <input type="hidden" name="alert_id" id="deactivate_alert_id">
                <?php echo csrf_token(); ?>

                <div class="grid grid-cols-3 gap-3">
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Affected
                            Residents</label>
                        <input type="number" name="affected_residents" placeholder="0" required
                            class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20">
                    </div>
                    <div class="space-y-1.5">
                        <label
                            class="text-[10px] font-bold text-slate-400 uppercase ml-1">Evacuees</label>
                        <input type="number" name="evacuees" placeholder="0" required
                            class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20">
                    </div>
                    <div class="space-y-1.5">
                        <label
                            class="text-[10px] font-bold text-slate-400 uppercase ml-1">Injuries</label>
                        <input type="number" name="injuries" placeholder="0" required
                            class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20">
                    </div>
                </div>
                <div class="space-y-1.5">
                    <label
                        class="text-[10px] font-bold text-slate-400 uppercase ml-1">Casualties</label>
                    <input type="number" name="casualties" placeholder="0" required
                        class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20">
                </div>

                <div class="space-y-4">
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Damage
                            Assessment</label>
                        <input type="text" name="property_damage" placeholder="Assessment of damage..." required
                            class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Response
                            Actions & Remarks</label>
                        <textarea name="response_actions" rows="2" placeholder="Describe actions taken..." required
                            class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20 resize-none"></textarea>
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Final
                            Status</label>
                        <select name="status" required
                            class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20">
                            <option value="Resolved" selected>Resolved (Successfully Managed)</option>
                            <option value="Closed">Closed (Observation Period Ended)</option>
                            <option value="Cancelled">Cancelled (False Alarm)</option>
                        </select>
                    </div>
                </div>

                <div class="flex gap-4 mt-8 pb-4">
                    <button type="button" onclick="closeModal('deactivateModal')"
                        class="flex-1 py-3.5 text-xs font-black uppercase text-slate-400 hover:text-slate-700 border border-slate-200 hover:border-slate-300 rounded-2xl transition-all">Cancel</button>
                    <button type="submit"
                        class="flex-[2] py-3.5 bg-rose-600 text-white text-xs font-black uppercase rounded-2xl shadow-lg hover:bg-rose-700 active:scale-95 transition-all">Confirm
                        Deactivation</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── View Alert Modal (moved from disaster_modals.php) ───────────────────────── -->
    <div id="viewAlertModal"
        class="fixed inset-0 z-[100] hidden bg-slate-900/80 flex items-center justify-center p-6">
        <div
            class="bg-white rounded-[2.5rem] shadow-2xl w-full max-w-lg overflow-hidden transform transition-all">
            <div class="px-10 pt-10 pb-6 flex justify-between items-start border-b border-slate-100">
                <div>
                    <div id="view_severity_badge"
                        class="inline-block px-3 py-1 rounded-full text-[8px] font-black text-white mb-3"></div>
                    <h3 id="view_title" class="text-2xl font-black tracking-tight text-slate-900 leading-tight"></h3>
                    <p id="view_type"
                        class="text-xs text-primary font-bold uppercase tracking-widest mt-2 flex items-center gap-1">
                    </p>
                </div>
                <button onclick="closeModal('viewAlertModal')"
                    class="p-2 hover:bg-orange-50 rounded-full text-slate-400 hover:text-primary transition-colors shrink-0">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>

            <div class="px-10 py-8 space-y-8">
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
                    class="w-full py-3.5 btn-accent text-white text-xs font-black uppercase rounded-2xl shadow-lg active:scale-95 transition-all">Close
                    View</button>
            </div>
        </div>
    </div>

    <script>
        // ─── Issue Alert Modal (moved from Disaster module) ───────────────────────────
        function openModal(id) {
            document.getElementById(id).classList.remove('hidden');
            if (id === 'smsLiveModal') { refreshSmsLiveDelivery(); startSmsCardPolling(); }
        }

        // SMS Live: ask the gateway whether accepted SMS were really sent by the gateway phone,
        // so a SIM with no load shows up as Failed right on the card (not only in the breakdown).
        let smsLiveChecking = false;
        async function refreshSmsLiveDelivery() {
            if (smsLiveChecking) return;
            smsLiveChecking = true;
            const ids = [...new Set([...document.querySelectorAll('[data-sms-log]')]
                .filter(el => +el.dataset.checkable > 0).map(el => el.dataset.smsLog))].slice(0, 10);
            for (const id of ids) {
                const cards = document.querySelectorAll(`[data-sms-log="${id}"]`);
                cards.forEach(c => { const s = c.querySelector('.sms-check-status'); if (s) { s.textContent = 'Checking delivery with the gateway phone…'; s.classList.remove('hidden'); } });
                try {
                    const res = await fetch('../backend/check_sms_delivery.php', { method: 'POST', body: new URLSearchParams({ log_id: id, csrf_token: CSRF_TOKEN }) });
                    const d = await res.json();
                    if (!d.success) throw new Error(d.error);
                    cards.forEach(c => updateSmsLiveCard(c, d));
                } catch (err) {
                    cards.forEach(c => { const s = c.querySelector('.sms-check-status'); if (s) s.textContent = 'Could not check delivery: ' + (err.message || 'gateway unreachable'); });
                }
            }
            smsLiveChecking = false;
        }

        // Puts fresh counters on an SMS Live card (from the delivery check or the live poll)
        function applySmsCardCounts(c, t, extra = {}) {
            const total = t.total ?? (t.targeted - t.no_number);
            const done = t.done ?? (t.sent + t.failed + (t.cancelled || 0));
            const pct = total > 0 ? Math.round(done / total * 1000) / 10 : 100;
            const phoneFailed = extra.phone_failed ?? t.phone_failed ?? 0;
            const nf = n => Number(n || 0).toLocaleString('en-US');
            const set = (key, n, note) => {
                const card = c.querySelector(`[data-card="${key}"]`);
                if (!card) return;
                card.querySelector('[data-num]').textContent = nf(n);
                if (note != null) card.querySelector('[data-note]').textContent = note;
            };
            set('total', total, nf(t.targeted) + ' in the audience');
            set('sent', t.sent, nf(t.confirmed) + ' confirmed by phone');
            set('pending', (t.queued || 0) + (t.processing || 0), nf(t.processing) + ' sending · ' + nf(t.retry) + ' retrying');
            set('failed', t.failed, phoneFailed ? phoneFailed + ' no load / phone failed' : 'Rejected / invalid no.');
            set('no_number', t.no_number);
            const bar = c.querySelector('[data-live="bar"]');
            if (bar) bar.style.width = pct + '%';
            const prog = c.querySelector('[data-live="progress"]');
            if (prog) prog.textContent = `${pct}% processed (${nf(done)}/${nf(total)} with a number)`;
            const sending = ((t.queued || 0) + (t.processing || 0)) > 0;
            c.dataset.sending = sending ? 1 : 0;
            const st = c.querySelector('[data-live="state"]');
            if (st && extra.state_label) {
                st.innerHTML = (sending ? '<span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>' : '') + sbdEsc(extra.state_label);
            }
            const warn = c.querySelector('.sms-phone-fail');
            if (warn) {
                warn.classList.toggle('hidden', !phoneFailed);
                warn.querySelector('[data-k="phone_failed"]').textContent = phoneFailed;
                if (extra.phone_reason != null) warn.querySelector('[data-k="phone_reason"]').textContent = extra.phone_reason || '';
            }
        }

        function updateSmsLiveCard(c, d) {
            applySmsCardCounts(c, d.totals, { phone_failed: d.phone_failed, phone_reason: d.phone_reason });
            c.dataset.checkable = d.checkable;
            const s = c.querySelector('.sms-check-status');
            if (s) {
                if (d.check && d.check.error) s.textContent = d.check.error;
                else if (d.totals.pending > 0 && !+c.dataset.sending) s.textContent = d.totals.pending + ' still waiting for the gateway phone — reopen SMS Live to check again';
                else s.classList.add('hidden');
            }
        }

        // ── Live polling of the SMS Live cards while the modal is open ─────────
        const SMS_STATE_LABEL = { queued: 'Queued', sending: 'Sending…', completed: 'Completed', cancelled: 'Cancelled' };
        let smsCardTimer = null, smsCardBusy = false;
        function startSmsCardPolling() {
            clearInterval(smsCardTimer);
            smsCardTimer = setInterval(pollSmsCards, 2000);
            pollSmsCards();
        }
        async function pollSmsCards() {
            if (document.getElementById('smsLiveModal').classList.contains('hidden')) { clearInterval(smsCardTimer); return; }
            const ids = [...new Set([...document.querySelectorAll('[data-sms-log][data-sending="1"]')].map(el => el.dataset.smsLog))];
            if (!ids.length || smsCardBusy) return;
            smsCardBusy = true;
            try {
                const res = await fetch('../backend/sms_live_status.php?log_ids=' + ids.join(','), { headers: { Accept: 'application/json' } });
                const d = await res.json();
                if (!d.success) return;
                let finished = false;
                for (const [id, l] of Object.entries(d.logs)) {
                    document.querySelectorAll(`[data-sms-log="${id}"]`).forEach(c => {
                        applySmsCardCounts(c, l.counts, { state_label: l.state === 'completed' ? (l.log_status || 'Completed') : SMS_STATE_LABEL[l.state] });
                        if (l.state !== 'queued' && l.state !== 'sending' && l.counts.pending > 0) { c.dataset.checkable = l.counts.pending; finished = true; }
                    });
                }
                // sending just finished → ask the gateway which SMS the phone really sent (no load…)
                if (finished) setTimeout(refreshSmsLiveDelivery, 3000);
            } catch (e) { /* keep polling */ } finally { smsCardBusy = false; }
        }

        // ── View SMS Live (per-broadcast monitor) ─────────────────────────────
        const smm = { logId: null, timer: null, busy: false, filter: '', page: 1, last: null };
        const SMM_STATUS = {
            pending: ['Pending', 'bg-slate-100 text-slate-500 border-slate-200'],
            processing: ['Processing', 'bg-sky-50 text-sky-700 border-sky-100'],
            retry: ['Retry', 'bg-amber-50 text-amber-700 border-amber-100'],
            sent: ['Sent', 'bg-emerald-50 text-emerald-700 border-emerald-100'],
            failed: ['Failed', 'bg-rose-50 text-rose-600 border-rose-100'],
            invalid: ['Failed', 'bg-rose-50 text-rose-600 border-rose-100'],
            no_number: ['No number', 'bg-amber-50 text-amber-700 border-amber-100'],
            cancelled: ['Cancelled', 'bg-slate-100 text-slate-400 border-slate-200'],
        };
        const smmFmtDate = v => v ? new Date(String(v).replace(' ', 'T')).toLocaleString('en-US', { month: 'long', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : '—';
        const smmFmtTime = v => v ? new Date(String(v).replace(' ', 'T')).toLocaleTimeString('en-US', { hour12: false }) : '';
        function smmAgo(v, now) {
            if (!v) return '—';
            const s = Math.max(0, Math.round((new Date(String(now).replace(' ', 'T')) - new Date(String(v).replace(' ', 'T'))) / 1000));
            return s < 5 ? 'Just now' : s < 60 ? s + 's ago' : s < 3600 ? Math.floor(s / 60) + 'm ago' : Math.floor(s / 3600) + 'h ago';
        }

        function openSmsMonitor(logId) {
            smm.logId = logId; smm.filter = ''; smm.page = 1; smm.last = null;
            document.getElementById('smm_title').textContent = '—';
            document.getElementById('smm_meta').textContent = '—';
            document.getElementById('smm_body').innerHTML = '<div class="py-10 flex justify-center"><div class="w-6 h-6 border-4 border-slate-200 border-t-slate-500 rounded-full animate-spin"></div></div>';
            openModal('smsMonitorModal');
            clearInterval(smm.timer);
            smm.timer = setInterval(pollSmsMonitor, 2000);
            pollSmsMonitor();
        }
        function closeSmsMonitor() {
            clearInterval(smm.timer); smm.timer = null; smm.logId = null;
            closeModal('smsMonitorModal');
        }
        function smmFilter(f) { smm.filter = f; smm.page = 1; pollSmsMonitor(true); }
        function smmPage(p) { smm.page = p; pollSmsMonitor(true); }

        async function pollSmsMonitor(force = false) {
            if (!smm.logId || (smm.busy && !force)) return;
            if (document.getElementById('smsMonitorModal').classList.contains('hidden')) { closeSmsMonitor(); return; }
            smm.busy = true;
            const logId = smm.logId;
            try {
                const q = new URLSearchParams({ log_id: logId, filter: smm.filter, page: smm.page });
                const res = await fetch('../backend/sms_live_status.php?' + q, { headers: { Accept: 'application/json' } });
                const d = await res.json();
                if (logId !== smm.logId) return;
                if (!d.success) throw new Error(d.error || 'Could not load the SMS status.');
                renderSmsMonitor(d);
                // mirror onto the SMS Live card behind the modal
                document.querySelectorAll(`[data-sms-log="${logId}"]`).forEach(c =>
                    applySmsCardCounts(c, d.counts, { state_label: d.state === 'completed' ? (d.log.log_status || 'Completed') : SMS_STATE_LABEL[d.state] }));
                if (d.state === 'completed' || d.state === 'cancelled') { clearInterval(smm.timer); smm.timer = null; }
                else if (!smm.timer) { smm.timer = setInterval(pollSmsMonitor, 2000); }
            } catch (err) {
                if (!smm.last) document.getElementById('smm_body').innerHTML = `<div class="py-8 text-center text-sm font-bold text-rose-600">${sbdEsc(err.message)}</div>`;
            } finally { smm.busy = false; }
        }

        async function cancelSmsBroadcast() {
            if (!smm.logId || !confirm('Stop sending the remaining SMS for this alert? Messages already sent are not affected.')) return;
            try {
                const res = await fetch('../backend/sms_cancel.php', { method: 'POST', headers: { Accept: 'application/json' }, body: new URLSearchParams({ log_id: smm.logId, csrf_token: CSRF_TOKEN }) });
                const d = await res.json();
                showToast(d.success ? 'success' : 'error', d.message || d.error);
                pollSmsMonitor(true);
            } catch (e) { showToast('error', 'Could not cancel the SMS broadcast.'); }
        }

        function renderSmsMonitor(d) {
            smm.last = d;
            const c = d.counts, l = d.log, nf = n => Number(n || 0).toLocaleString('en-US');
            document.getElementById('smm_kicker').textContent = 'Disaster Alert SMS Status · Disaster #' + l.alert_id + (l.type ? ' · ' + l.type : '');
            document.getElementById('smm_title').textContent = l.title || 'Disaster alert';
            document.getElementById('smm_meta').textContent = 'Queued ' + smmFmtDate(l.created_at) + (l.started_at ? ' · Started ' + smmFmtTime(l.started_at) : '');

            const pill = document.getElementById('smm_state');
            const pills = {
                queued: ['Queued', 'bg-slate-100 text-slate-500', 'schedule'],
                sending: ['Sending…', 'bg-sky-50 text-sky-700', null],
                completed: ['Completed', 'bg-emerald-50 text-emerald-700', 'task_alt'],
                cancelled: ['Cancelled', 'bg-slate-100 text-slate-500', 'block'],
            };
            const [pl, pc, pi] = pills[d.state] || pills.completed;
            pill.className = 'px-3 py-1.5 rounded-full text-[10px] font-black uppercase tracking-widest flex items-center gap-1.5 ' + pc;
            pill.innerHTML = (pi ? `<span class="material-symbols-outlined" style="font-size:14px">${pi}</span>` : '<span class="w-2 h-2 rounded-full bg-sky-500 animate-pulse"></span>') + pl;

            const tile = (label, n, cls, note) => `
                <div class="rounded-2xl border border-slate-100 p-4 ${cls}">
                    <p class="text-[10px] font-bold uppercase tracking-widest opacity-70">${label}</p>
                    <p class="text-2xl font-black mt-1">${nf(n)}</p>
                    <p class="text-[10px] font-semibold opacity-70 mt-0.5">${note}</p>
                </div>`;
            const current = d.state === 'queued' ? 'Waiting for the SMS worker to start…'
                : d.state === 'sending' ? (c.processing ? 'Sending SMS…' : (c.retry ? 'Waiting to retry ' + nf(c.retry) + ' SMS…' : 'Sending SMS…'))
                : d.state === 'cancelled' ? 'Stopped by an administrator' : 'All SMS processed';
            const lastUpdate = d.activity.length ? d.activity[0].time : (l.finished_at || l.created_at);

            const doneBox = (d.state === 'completed' || d.state === 'cancelled') ? `
                <div class="rounded-2xl border ${d.state === 'completed' ? 'border-emerald-100 bg-emerald-50 text-emerald-800' : 'border-slate-200 bg-slate-50 text-slate-700'} p-5 flex flex-col md:flex-row md:items-center gap-4">
                    <span class="material-symbols-outlined text-3xl">${d.state === 'completed' ? 'task_alt' : 'block'}</span>
                    <div class="flex-1">
                        <p class="text-sm font-black uppercase tracking-wide">${d.state === 'completed' ? 'SMS sending complete' : 'SMS sending cancelled'}</p>
                        <p class="text-xs font-semibold opacity-80 mt-0.5">Total recipients: <b>${nf(c.total)}</b> · Sent: <b>${nf(c.sent)}</b> · Failed: <b>${nf(c.failed)}</b>${c.cancelled ? ' · Cancelled: <b>' + nf(c.cancelled) + '</b>' : ''} · Completed: <b>${smmFmtDate(l.finished_at || lastUpdate)}</b></p>
                    </div>
                </div>` : '';
            const errBox = l.job_error && d.state !== 'completed' ? `<div class="rounded-2xl border border-rose-100 bg-rose-50 p-4 text-xs font-bold text-rose-700">${sbdEsc(l.job_error)}</div>` : '';
            const loadBox = c.phone_failed ? `
                <div class="rounded-2xl border border-rose-100 bg-rose-50 p-4 flex gap-3">
                    <span class="material-symbols-outlined text-rose-600">signal_cellular_connected_no_internet_0_bar</span>
                    <p class="text-xs text-rose-700 font-semibold"><b>${nf(c.phone_failed)} SMS failed on the gateway phone</b> after the gateway accepted them — possibly no load / promo or no signal on the gateway SIM.</p>
                </div>` : '';

            const act = d.activity.map(a => {
                const icon = { sent: ['check_circle', 'text-emerald-600'], failed: ['error', 'text-rose-600'], invalid: ['error', 'text-rose-600'], retry: ['replay', 'text-amber-600'], processing: ['sync', 'text-sky-600'], cancelled: ['block', 'text-slate-400'] }[a.status] || ['radio_button_unchecked', 'text-slate-400'];
                const text = { sent: 'SMS sent to ', failed: 'SMS failed for ', invalid: 'Invalid number for ', retry: 'Retrying ', processing: 'Sending to ', cancelled: 'Cancelled for ' }[a.status] || '';
                return `<li class="flex gap-3 py-2">
                    <span class="text-[11px] font-mono font-bold text-slate-400 w-16 shrink-0 pt-0.5">${smmFmtTime(a.time)}</span>
                    <span class="material-symbols-outlined ${icon[1]} shrink-0" style="font-size:16px">${icon[0]}</span>
                    <div class="min-w-0"><p class="text-xs font-bold text-slate-700 truncate">${text}${sbdEsc(a.name)}</p>
                    ${a.detail ? `<p class="text-[10px] text-slate-400 font-semibold truncate">${sbdEsc(a.detail)}</p>` : ''}</div>
                </li>`;
            }).join('');

            const chips = [['', 'All', c.targeted], ['pending', 'Pending', c.queued - c.retry], ['processing', 'Processing', c.processing], ['sent', 'Sent', c.sent],
                ['retry', 'Retry', c.retry], ['failed', 'Failed', c.failed], ['no_number', 'No number', c.no_number]]
                .concat(c.cancelled ? [['cancelled', 'Cancelled', c.cancelled]] : [])
                .map(([k, lbl, n]) => `<button type="button" onclick="smmFilter('${k}')" class="px-3 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-wider border transition-all ${smm.filter === k ? 'btn-accent text-white border-transparent' : 'border-slate-200 text-slate-500 hover:border-slate-300'}">${lbl} <span class="opacity-70">${nf(n)}</span></button>`).join('');
            const rows = d.recipients.map(r => {
                const [lbl, cls] = SMM_STATUS[r.status] || [r.status, ''];
                return `<tr class="hover:bg-slate-50/50">
                    <td class="px-5 py-2.5"><p class="text-xs font-bold text-slate-700">${sbdEsc(r.name)}</p><p class="text-[10px] text-slate-400 font-semibold">${sbdEsc(r.area || '')}</p></td>
                    <td class="px-4 py-2.5 text-xs text-slate-500 font-mono">${sbdEsc(r.mobile)}</td>
                    <td class="px-5 py-2.5"><span class="px-2 py-0.5 text-[9px] font-bold rounded-md uppercase border ${cls}">${lbl}</span>
                        ${r.attempts > 1 ? `<span class="text-[9px] font-bold text-slate-400 ml-1">${r.attempts} tries</span>` : ''}
                        ${r.status === 'sent' && r.delivery === 'confirmed' ? '<span class="text-[9px] font-bold text-emerald-600 ml-1">✓ phone</span>' : ''}
                        ${r.detail ? `<p class="text-[10px] text-slate-400 font-semibold mt-1">${sbdEsc(r.detail)}</p>` : ''}</td>
                </tr>`;
            }).join('');
            const pager = d.pages > 1 ? `<div class="flex items-center justify-between px-5 py-3 border-t border-slate-100 text-[10px] font-bold uppercase text-slate-400">
                    <span>Page ${d.page} of ${d.pages} · ${nf(d.rows_total)} residents</span>
                    <div class="flex gap-2">
                        <button type="button" ${d.page <= 1 ? 'disabled' : ''} onclick="smmPage(${d.page - 1})" class="px-3 py-1.5 rounded-lg border border-slate-200 disabled:opacity-40">Prev</button>
                        <button type="button" ${d.page >= d.pages ? 'disabled' : ''} onclick="smmPage(${d.page + 1})" class="px-3 py-1.5 rounded-lg border border-slate-200 disabled:opacity-40">Next</button>
                    </div></div>` : '';

            // keep the scroll position of the lists while re-rendering every 2s
            const body = document.getElementById('smm_body');
            const keep = { body: body.scrollTop, act: (body.querySelector('#smm_act') || {}).scrollTop || 0, tbl: (body.querySelector('#smm_tbl') || {}).scrollTop || 0 };

            body.innerHTML = `
                ${doneBox}${errBox}${loadBox}
                <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                    ${tile('Total', c.total, 'bg-white text-slate-800', nf(c.no_number) + ' without a number not included')}
                    ${tile('Sent', c.sent, 'bg-emerald-50 text-emerald-700', nf(c.confirmed) + ' confirmed by phone')}
                    ${tile('Pending', c.queued, 'bg-slate-50 text-slate-700', nf(c.retry) + ' waiting to retry')}
                    ${tile('Processing', c.processing, 'bg-sky-50 text-sky-700', 'Being sent right now')}
                    ${tile('Failed', c.failed, 'bg-rose-50 text-rose-700', c.phone_failed ? nf(c.phone_failed) + ' no load / phone failed' : 'Rejected, invalid, or ' + <?php echo (int) SMS_MAX_ATTEMPTS; ?> + ' tries used')}
                </div>
                <div>
                    <div class="flex items-end justify-between mb-2">
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Progress</p>
                        <p class="text-2xl font-black text-slate-800">${c.progress}%</p>
                    </div>
                    <div class="w-full bg-slate-100 h-3 rounded-full overflow-hidden flex">
                        <div class="h-full bg-emerald-500 transition-all duration-700" style="width:${c.total ? c.sent / c.total * 100 : 0}%"></div>
                        <div class="h-full bg-rose-400 transition-all duration-700" style="width:${c.total ? (c.failed + c.cancelled) / c.total * 100 : 0}%"></div>
                    </div>
                    <p class="text-[10px] font-bold text-slate-400 uppercase mt-2">${nf(c.sent)} sent + ${nf(c.failed + c.cancelled)} failed/cancelled of ${nf(c.total)} · ${nf(c.queued + c.processing)} remaining</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div class="rounded-2xl border border-slate-100 p-4"><p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Current status</p>
                        <p class="text-sm font-black text-slate-800 mt-1">${current}</p></div>
                    <div class="rounded-2xl border border-slate-100 p-4"><p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Last sent</p>
                        <p class="text-sm font-black text-slate-800 mt-1 truncate">${d.last_sent ? sbdEsc(d.last_sent.name) : '—'}</p>
                        <p class="text-[11px] font-mono font-bold text-slate-400">${d.last_sent ? sbdEsc(d.last_sent.mobile) : ''}</p></div>
                    <div class="rounded-2xl border border-slate-100 p-4"><p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Last update</p>
                        <p class="text-sm font-black text-slate-800 mt-1">${smmAgo(lastUpdate, d.server_time)}</p>
                        <p class="text-[11px] font-bold text-slate-400">${smmFmtTime(lastUpdate)}</p></div>
                </div>
                <div class="grid grid-cols-1 lg:grid-cols-5 gap-4">
                    <div class="lg:col-span-2">
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2">Live activity</p>
                        <div class="rounded-2xl border border-slate-100 px-4 max-h-80 overflow-y-auto" id="smm_act">
                            <ul class="divide-y divide-slate-50">${act || '<li class="py-6 text-center text-xs font-semibold text-slate-400">No SMS sent yet.</li>'}</ul>
                        </div>
                    </div>
                    <div class="lg:col-span-3">
                        <div class="flex flex-wrap gap-2 mb-2">${chips}</div>
                        <div class="rounded-2xl border border-slate-100 overflow-hidden">
                            <div class="max-h-80 overflow-y-auto" id="smm_tbl"><table class="w-full text-left">
                                <thead class="sticky top-0"><tr class="bg-slate-50 text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                                    <th class="px-5 py-3">Resident</th><th class="px-4 py-3">Mobile</th><th class="px-5 py-3">Status</th></tr></thead>
                                <tbody class="divide-y divide-slate-50">${rows || '<tr><td colspan="3" class="px-5 py-6 text-center text-xs text-slate-400">No residents in this list.</td></tr>'}</tbody>
                            </table></div>
                            ${pager}
                        </div>
                    </div>
                </div>
                <div class="flex flex-col md:flex-row gap-3 pt-2">
                    ${d.can_cancel ? `<button type="button" onclick="cancelSmsBroadcast()" class="flex-1 flex items-center justify-center gap-2 py-3.5 rounded-2xl border border-rose-200 text-rose-600 text-xs font-black uppercase hover:bg-rose-50 transition-all"><span class="material-symbols-outlined" style="font-size:18px">block</span>Cancel remaining SMS</button>` : ''}
                    <button type="button" onclick="openSmsBreakdown(${l.log_id})" class="flex-1 flex items-center justify-center gap-2 py-3.5 rounded-2xl border border-slate-200 text-slate-500 text-xs font-black uppercase hover:border-indigo-300 hover:text-indigo-600 transition-all"><span class="material-symbols-outlined" style="font-size:18px">table_view</span>Breakdown by purok / area</button>
                </div>`;
            body.scrollTop = keep.body;
            const actEl = body.querySelector('#smm_act'), tblEl = body.querySelector('#smm_tbl');
            if (actEl) actEl.scrollTop = keep.act;
            if (tblEl) tblEl.scrollTop = keep.tbl;
        }

        function lockIssueAlertForm(form) {
            if (form.dataset.submitting === '1') return false; // second click → ignored
            form.dataset.submitting = '1';
            const btn = document.getElementById('issueAlertSubmit');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="material-symbols-outlined animate-spin !text-lg">progress_activity</span> Issuing alert…';
            }
            return true;
        }

        document.addEventListener('DOMContentLoaded', () => {
            if (SMS_LIVE_OPEN) {
                openModal('smsLiveModal');
                openSmsMonitor(SMS_LIVE_OPEN);
            }
        });

        // "New Announcement" button — the create form is its own page (new_ann.php),
        // the same way Edit opens edit_ann.php. This function was referenced by the
        // button but never defined, so clicking it did nothing.
        function openCreateAnnouncementModal() { window.location.href = 'new_ann.php'; }
        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

        // ─── SMS Live ↔ History toggle ─────────────────────────────────────────────

        // ─── SMS breakdown: who was targeted / sent / failed / had no number, per area ──
        function sbdEsc(v) { return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

        let sbdLogId = null;

        async function openSmsBreakdown(logId) {
            sbdLogId = logId;
            const body = document.getElementById('sbd_body');
            body.innerHTML = '<div class="py-10 flex justify-center"><div class="w-6 h-6 border-4 border-slate-200 border-t-slate-500 rounded-full animate-spin"></div></div>';
            document.getElementById('sbd_title').textContent = '—';
            document.getElementById('sbd_meta').textContent = '—';
            openModal('smsBreakdownModal');
            try {
                const res = await fetch('../backend/get_sms_breakdown.php?log_id=' + encodeURIComponent(logId));
                const d = await res.json();
                if (!d.success) throw new Error(d.error || 'Could not load the breakdown.');
                renderSmsBreakdown(d);
                // Accepted ≠ sent: ask the gateway right away whether the phone really sent them.
                if (d.checkable > 0) checkSmsDelivery(true);
            } catch (err) {
                body.innerHTML = `<div class="py-8 text-center text-sm font-bold text-rose-600">${sbdEsc(err.message)}</div>`;
            }
        }

        async function checkSmsDelivery(auto = false) {
            const logId = sbdLogId;
            const btn = document.getElementById('sbd_check_btn');
            if (btn) { btn.disabled = true; btn.innerHTML = '<span class="material-symbols-outlined animate-spin" style="font-size:16px">progress_activity</span>Checking…'; }
            try {
                const fd = new URLSearchParams({ log_id: logId, csrf_token: CSRF_TOKEN });
                const res = await fetch('../backend/check_sms_delivery.php', { method: 'POST', body: fd });
                const d = await res.json();
                if (logId !== sbdLogId) return; // modal switched to another broadcast meanwhile
                if (!d.success) throw new Error(d.error || 'Could not check the delivery status.');
                renderSmsBreakdown(d);
                document.querySelectorAll(`[data-sms-log="${logId}"]`).forEach(card => updateSmsLiveCard(card, d));
                const c = d.check;
                if (c.error) showToast('error', c.error);
                else if (c.failed > 0) showToast('error', c.failed + ' SMS could not be sent by the gateway phone (e.g. no load / no signal).');
                else if (!auto) showToast('success', 'Delivery status updated: ' + c.confirmed + ' confirmed, ' + c.pending + ' still waiting.');
            } catch (err) {
                showToast('error', err.message);
                if (btn) { btn.disabled = false; btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px">sync</span>Check delivery'; }
            }
        }

        function renderSmsBreakdown(d) {
            const body = document.getElementById('sbd_body');
            const l = d.log, t = d.totals;
            document.getElementById('sbd_kicker').textContent = 'SMS Breakdown · Disaster #' + (l.AlertID || '—') + (l.Type ? ' · ' + l.Type : '');
            document.getElementById('sbd_title').textContent = l.Title || 'Disaster alert';
            const fmt = v => new Date(String(v).replace(' ', 'T')).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
            document.getElementById('sbd_meta').textContent = 'Sent ' + fmt(l.created_at) + (d.last_checked ? ' · Delivery last checked ' + fmt(d.last_checked) : '');

            const pct = n => t.targeted ? Math.round(n / t.targeted * 100) : 0;
            const card = (label, n, cls, note) => `
                <div class="rounded-2xl border border-slate-100 p-4 ${cls}">
                    <p class="text-[10px] font-bold uppercase tracking-widest opacity-70">${label}</p>
                    <p class="text-2xl font-black mt-1">${n}</p>
                    <p class="text-[10px] font-semibold opacity-70 mt-0.5">${note}</p>
                </div>`;
            const areaRows = d.areas.map(a => {
                const p = a.targeted ? Math.round(a.confirmed / a.targeted * 100) : 0;
                const pp = a.targeted ? Math.round(a.pending / a.targeted * 100) : 0;
                return `<tr class="hover:bg-slate-50/50">
                    <td class="px-5 py-3 text-xs font-bold text-slate-700">${sbdEsc(a.area)}</td>
                    <td class="px-3 py-3 text-xs font-bold text-slate-700 text-right">${a.targeted}</td>
                    <td class="px-3 py-3 text-xs font-bold text-emerald-600 text-right">${a.confirmed}</td>
                    <td class="px-3 py-3 text-xs font-bold text-sky-600 text-right">${a.pending}</td>
                    <td class="px-3 py-3 text-xs font-bold text-rose-600 text-right">${a.failed}</td>
                    <td class="px-3 py-3 text-xs font-bold text-amber-600 text-right">${a.no_number}</td>
                    <td class="px-5 py-3 w-32"><div class="w-full bg-slate-100 h-1.5 rounded-full overflow-hidden flex"><div class="h-full bg-emerald-500" style="width:${p}%"></div><div class="h-full bg-sky-300" style="width:${pp}%"></div></div>
                        <p class="text-[9px] font-bold text-slate-400 mt-1">${p}% confirmed</p></td>
                </tr>`;
            }).join('');
            const reasonCls = { no_number: 'bg-amber-50 text-amber-700 border-amber-100', invalid: 'bg-rose-50 text-rose-600 border-rose-100', failed: 'bg-rose-50 text-rose-600 border-rose-100', pending: 'bg-sky-50 text-sky-700 border-sky-100' };
            const personRows = list => list.map(r => `<tr>
                    <td class="px-5 py-2.5 text-xs font-bold text-slate-700">${sbdEsc(r.name)}</td>
                    <td class="px-4 py-2.5 text-xs text-slate-500 font-semibold">${sbdEsc(r.area)}</td>
                    <td class="px-4 py-2.5 text-xs text-slate-500 font-mono">${sbdEsc(r.contact || '—')}</td>
                    <td class="px-5 py-2.5"><span class="px-2 py-0.5 text-[9px] font-bold rounded-md uppercase border ${reasonCls[r.status] || ''}">${sbdEsc(r.reason)}</span>
                        ${r.detail && r.status !== 'no_number' ? `<p class="text-[10px] text-slate-400 font-semibold mt-1">${sbdEsc(r.detail)}</p>` : ''}</td>
                </tr>`).join('');
            const peopleTable = (rows, empty) => `
                <div class="rounded-2xl border border-slate-100 overflow-hidden max-h-72 overflow-y-auto"><table class="w-full text-left">
                    <thead class="sticky top-0"><tr class="bg-slate-50 text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                        <th class="px-5 py-3">Resident</th><th class="px-4 py-3">Purok / Area</th><th class="px-4 py-3">Contact</th><th class="px-5 py-3">Status</th></tr></thead>
                    <tbody class="divide-y divide-slate-50">${rows || empty}</tbody>
                </table></div>`;

            const loadBanner = d.phone_failed > 0 ? `
                <div class="rounded-2xl border border-rose-100 bg-rose-50 p-4 flex gap-3">
                    <span class="material-symbols-outlined text-rose-600">signal_cellular_connected_no_internet_0_bar</span>
                    <div><p class="text-xs font-black text-rose-700 uppercase">${d.phone_failed} SMS failed on the gateway phone</p>
                    <p class="text-xs text-rose-600 font-semibold mt-0.5">The gateway accepted them but the phone could not send. Usually the SIM has no load / promo, no signal, or the phone is off. Reload the SIM, then re-send the alert or reach these residents another way.</p>
                    ${d.phone_reason ? `<p class="text-[10px] text-rose-500 font-semibold mt-1">${sbdEsc(d.phone_reason)}</p>` : ''}</div>
                </div>` : '';
            const pendingBanner = t.pending > 0 ? `
                <div class="rounded-2xl border border-sky-100 bg-sky-50 p-4 flex flex-col md:flex-row md:items-center gap-3">
                    <span class="material-symbols-outlined text-sky-600">hourglass_top</span>
                    <p class="text-xs text-sky-700 font-semibold flex-1"><b>${t.pending}</b> SMS were accepted by the gateway but are <b>not yet confirmed sent</b> by the gateway phone. They will turn into “Confirmed” or “Failed” (e.g. no load) when you check again.</p>
                    ${d.checkable > 0 ? `<button id="sbd_check_btn" type="button" onclick="checkSmsDelivery()" class="shrink-0 flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-white border border-sky-200 text-[11px] font-black uppercase text-sky-700 hover:bg-sky-100 transition-all"><span class="material-symbols-outlined" style="font-size:16px">sync</span>Check delivery</button>` : ''}
                </div>` : '';

            body.innerHTML = `
                ${loadBanner}
                <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                    ${card('Targeted', t.targeted, 'bg-white text-slate-800', 'Residents in the audience')}
                    ${card('Confirmed sent', t.confirmed, 'bg-emerald-50 text-emerald-700', pct(t.confirmed) + '% of targeted')}
                    ${card('Awaiting', t.pending, 'bg-sky-50 text-sky-700', 'Accepted, not confirmed')}
                    ${card('Failed', t.failed, 'bg-rose-50 text-rose-700', 'Rejected / no load / invalid')}
                    ${card('No number', t.no_number, 'bg-amber-50 text-amber-700', 'No contact number on file')}
                </div>
                ${pendingBanner}
                <div>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2">By Purok / Area</p>
                    <div class="rounded-2xl border border-slate-100 overflow-x-auto"><table class="w-full text-left">
                        <thead><tr class="bg-slate-50/50 text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                            <th class="px-5 py-3">Purok / Area</th><th class="px-3 py-3 text-right">Targeted</th><th class="px-3 py-3 text-right">Confirmed</th>
                            <th class="px-3 py-3 text-right">Awaiting</th><th class="px-3 py-3 text-right">Failed</th><th class="px-3 py-3 text-right">No no.</th><th class="px-5 py-3">Reached</th></tr></thead>
                        <tbody class="divide-y divide-slate-50">${areaRows || '<tr><td colspan="7" class="px-5 py-6 text-center text-xs text-slate-400">No data</td></tr>'}</tbody>
                    </table></div>
                </div>
                <div>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2">Residents not reached (${d.not_reached.length})
                        <span class="normal-case tracking-normal font-semibold text-slate-400">— contact them another way (house visit, PA system, barangay tanod)</span></p>
                    ${peopleTable(personRows(d.not_reached), '<tr><td colspan="4" class="px-5 py-6 text-center text-xs font-bold text-emerald-600">No failed sends and no missing numbers.</td></tr>')}
                </div>
                ${d.pending && d.pending.length ? `<div>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2">Awaiting confirmation (${d.pending.length})</p>
                    ${peopleTable(personRows(d.pending), '')}
                </div>` : ''}`;
        }

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
            ['issueAlertModal', 'activeDisasterModal', 'smsLiveModal', 'smsBreakdownModal',
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
                            t.innerHTML = `<img src="${img.url || img.file_path}" class="w-full h-full object-cover" alt="">`;
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
                        <a href="${f.url || f.file_path}" download="${f.original_name}" target="_blank"
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
                imgEl.src = images[0].url || images[0].file_path;
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
            document.getElementById('vm_gallery_img').src = img.url || img.file_path;
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
        // Toast — same look as Resident Management (top-right, colored, progress bar).
        // Signature kept as showToast(type, msg) because the rest of this page uses it.
        (function () {
            if (document.getElementById('ann-toast-style')) return;
            const st = document.createElement('style');
            st.id = 'ann-toast-style';
            st.textContent = `
    #toast-container { position: fixed; top: 1.25rem; right: 1.25rem; z-index: 99999; display: flex; flex-direction: column; gap: .6rem; pointer-events: none; }
    .toast { display: flex; align-items: center; gap: .75rem; padding: .85rem 1.1rem; border-radius: 1rem; box-shadow: 0 8px 28px rgba(0,0,0,.14); font-family: 'Plus Jakarta Sans', sans-serif; font-size: .75rem; font-weight: 700; min-width: 280px; max-width: 380px; pointer-events: all; transform: translateX(110%); opacity: 0; transition: transform .3s cubic-bezier(.34,1.56,.64,1), opacity .3s ease; position: relative; overflow: hidden; }
    .toast.show { transform: translateX(0); opacity: 1; }
    .toast.hide { transform: translateX(110%); opacity: 0; }
    .toast-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
    .toast-error   { background: #fff1f2; border: 1px solid #fecaca; color: #991b1b; }
    .toast-warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
    .toast-info    { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
    .toast-icon    { font-size: 1.1rem; flex-shrink: 0; }
    .toast-msg     { flex: 1; line-height: 1.4; }
    .toast-close   { background: none; border: none; cursor: pointer; opacity: .5; padding: 0; font-size: 1rem; line-height: 1; flex-shrink: 0; color: inherit; }
    .toast-close:hover { opacity: 1; }
    .toast-bar     { position: absolute; bottom: 0; left: 0; height: 3px; border-radius: 0 0 1rem 1rem; animation: toastProgress linear forwards; }
    .toast-success .toast-bar { background: #10b981; }
    .toast-error   .toast-bar { background: #ef4444; }
    .toast-warning .toast-bar { background: #f59e0b; }
    .toast-info    .toast-bar { background: #3b82f6; }
    @keyframes toastProgress { from { width: 100%; } to { width: 0%; } }`;
            document.head.appendChild(st);
        })();

        function showToast(type, msg, duration = 5000) {
            let container = document.getElementById('toast-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'toast-container';
                document.body.appendChild(container);
            }
            const kind = ['success', 'error', 'warning', 'info'].includes(type) ? type : 'info';
            const icons = { success: 'check_circle', error: 'error', warning: 'warning', info: 'info' };
            const toast = document.createElement('div');
            toast.id = 'ann_toast';
            toast.className = `toast toast-${kind}`;
            toast.innerHTML = `<span class="material-symbols-outlined toast-icon">${icons[kind]}</span>
                <span class="toast-msg">${msg}</span>
                <button class="toast-close" onclick="this.parentElement.remove()">&times;</button>
                <div class="toast-bar" style="animation-duration:${duration}ms"></div>`;
            container.appendChild(toast);
            requestAnimationFrame(() => requestAnimationFrame(() => toast.classList.add('show')));
            setTimeout(() => { toast.classList.add('hide'); setTimeout(() => toast.remove(), 350); }, duration);
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
                            <button onclick="openTrashView(${parseInt(a.id)})" class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all" title="View">
                                <span class="material-symbols-outlined" text-xl">visibility</span>
                            </button>
                            <button onclick="openRestore(${parseInt(a.id)})" class="p-2 text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg transition-all" title="Recover">
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
                        `<a href="${escHtml(f.url || f.file_path)}" target="_blank" class="block aspect-video bg-slate-100 rounded-xl overflow-hidden">
                    <img src="${escHtml(f.url || f.file_path)}" alt="" class="w-full h-full object-cover"></a>`).join('');
                    const fileSec = document.getElementById('tv_files_section');
                    fileSec.classList.toggle('hidden', !files.length);
                    document.getElementById('tv_files').innerHTML = files.map(f =>
                        `<a href="${escHtml(f.url || f.file_path)}" download="${escHtml(f.original_name)}" target="_blank"
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
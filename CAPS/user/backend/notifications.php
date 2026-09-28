<?php
/**
 * user/backend/notifications.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Notifications for the Flutter app (lib/notifications/). One list built from:
 *   • resident_notifications — written by the admin (certificate ready /
 *     rejected / released, complaint status updates, …)
 *   • new announcements (last 30 days) — "read" = announcement_reads, the same
 *     table as the NEW badge in Announcements
 *   • disaster alerts (active, or issued in the last 7 days)
 *   • blotter case updates for cases the resident is part of (hearing
 *     scheduled, notice issued, resolved, …) and a reminder the day before a
 *     hearing
 * Read state of the last three lives in resident_notification_seen.
 *
 * Actions (login token required, see auth.php):
 *   • list                    → items (newest first) + unread count
 *   • count                   → unread count only (dashboard badge)
 *   • read      (key)         → mark one as read   (key = "<source>:<id>")
 *   • read_all                → mark everything in the list as read
 *   • register_device (push_token) → save this device's Firebase token (push)
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';
handle_preflight();

$rid    = require_resident();
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$pdo    = db();

const NOTIF_DAYS = 30;

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS resident_notification_seen (
        resident_id INT UNSIGNED NOT NULL,
        source      VARCHAR(20) NOT NULL,
        source_id   INT UNSIGNED NOT NULL,
        seen_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (resident_id, source, source_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS announcement_reads (
        resident_id     INT NOT NULL,
        announcement_id INT UNSIGNED NOT NULL,
        read_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (resident_id, announcement_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    error_log('[notifications.php migrate] ' . $e->getMessage());
}

function notif_has(PDO $pdo, string $table): bool
{
    static $c = [];
    if (!isset($c[$table])) {
        $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $s->execute([$table]);
        $c[$table] = (int) $s->fetchColumn() > 0;
    }
    return $c[$table];
}

/** Blotter cases the resident is part of: BlotterID => CaseNumber. */
function notif_my_cases(PDO $pdo, int $rid): array
{
    if (!notif_has($pdo, 'blotter')) return [];
    $ids = [];
    if (notif_has($pdo, 'blotter_parties')) {
        $s = $pdo->prepare("SELECT DISTINCT blotter_id FROM blotter_parties WHERE resident_id = ?");
        $s->execute([$rid]);
        $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    }
    $s = $pdo->prepare("SELECT BlotterID FROM blotter WHERE ComplainantID = ? OR RespondentID = ?");
    $s->execute([(string) $rid, (string) $rid]);
    $ids = array_unique(array_merge($ids, array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN))));
    if (!$ids) return [];
    $out = [];
    foreach ($pdo->query("SELECT BlotterID, CaseNumber FROM blotter WHERE BlotterID IN (" . implode(',', $ids) . ")") as $r) {
        $out[(int) $r['BlotterID']] = $r['CaseNumber'] ?: ('#' . $r['BlotterID']);
    }
    return $out;
}

/** Every notification for the resident, newest first. */
function notif_build(PDO $pdo, int $rid): array
{
    $items = [];
    $seen = [];
    $s = $pdo->prepare("SELECT source, source_id FROM resident_notification_seen WHERE resident_id = ?");
    $s->execute([$rid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $x) $seen[$x['source'] . ':' . $x['source_id']] = true;

    // 1. From the admin (certificates, complaints …).
    if (notif_has($pdo, 'resident_notifications')) {
        $s = $pdo->prepare("SELECT id, notif_type, title, message, ref_table, ref_id, is_read, created_at
                            FROM resident_notifications
                            WHERE resident_id = ? AND created_at >= NOW() - INTERVAL 90 DAY
                            ORDER BY created_at DESC, id DESC LIMIT 100");
        $s->execute([$rid]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $n) {
            $items[] = [
                'key' => 'rn:' . $n['id'], 'source' => 'rn', 'type' => $n['notif_type'],
                'title' => $n['title'], 'message' => $n['message'],
                'ref_table' => $n['ref_table'], 'ref_id' => $n['ref_id'] !== null ? (int) $n['ref_id'] : null,
                'is_read' => (int) $n['is_read'] === 1, 'created_at' => $n['created_at'],
            ];
        }
    }

    // 2. New announcements (published, or scheduled and already started).
    if (notif_has($pdo, 'announcements')) {
        $s = $pdo->prepare("SELECT a.id, a.title, a.category, a.details,
                                   COALESCE(a.date_posted, a.created_at) AS at, ar.announcement_id AS is_read
                            FROM announcements a
                            LEFT JOIN announcement_reads ar ON ar.announcement_id = a.id AND ar.resident_id = ?
                            WHERE a.deleted_at IS NULL
                              AND (a.status = 'Published'
                                   OR (a.status = 'Scheduled' AND a.date_start IS NOT NULL
                                       AND CONCAT(a.date_start, ' ', COALESCE(a.time_start, '00:00:00')) <= NOW()))
                              AND COALESCE(a.date_posted, a.created_at) >= NOW() - INTERVAL " . NOTIF_DAYS . " DAY
                            ORDER BY at DESC LIMIT 30");
        $s->execute([$rid]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $items[] = [
                'key' => 'announcement:' . $a['id'], 'source' => 'announcement',
                'type' => $a['category'] === 'Emergency Notice' ? 'emergency_notice' : 'announcement',
                'title' => (string) $a['title'],
                'message' => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string) $a['details']))), 0, 160),
                'ref_table' => 'announcements', 'ref_id' => (int) $a['id'],
                'is_read' => $a['is_read'] !== null, 'created_at' => $a['at'],
            ];
        }
    }

    // 3. Disaster alerts.
    if (notif_has($pdo, 'disaster_alerts')) {
        foreach ($pdo->query("SELECT AlertID, Type, Severity, Title, Message, Status, CreatedAt
                              FROM disaster_alerts
                              WHERE LOWER(Status) = 'active' OR CreatedAt >= NOW() - INTERVAL 7 DAY
                              ORDER BY CreatedAt DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $key = 'alert:' . $d['AlertID'];
            $items[] = [
                'key' => $key, 'source' => 'alert', 'type' => 'disaster_alert',
                'title' => trim(($d['Severity'] ? $d['Severity'] . ' · ' : '') . ($d['Title'] ?: $d['Type'])),
                'message' => (string) $d['Message'],
                'ref_table' => 'disaster_alerts', 'ref_id' => (int) $d['AlertID'],
                'is_read' => isset($seen[$key]), 'created_at' => $d['CreatedAt'],
                'is_active' => strtolower((string) $d['Status']) === 'active',
            ];
        }
    }

    // 4. Blotter: case updates + reminder the day before a hearing.
    $cases = notif_my_cases($pdo, $rid);
    if ($cases && notif_has($pdo, 'blotter_timeline')) {
        $in = implode(',', array_keys($cases));
        foreach ($pdo->query("SELECT timeline_id, blotter_id, action, status, recorded_at FROM blotter_timeline
                              WHERE blotter_id IN ($in) AND recorded_at >= NOW() - INTERVAL " . NOTIF_DAYS . " DAY
                                AND status IN ('Hearing Scheduled','Notice Issued','For Next Hearing','Resolved',
                                               'Closed','For Transfer','Transferred','Cancelled','Withdrawn')
                              ORDER BY recorded_at DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $key = 'blotter:' . $t['timeline_id'];
            $items[] = [
                'key' => $key, 'source' => 'blotter', 'type' => 'blotter_update',
                'title' => $cases[(int) $t['blotter_id']] . ' · ' . $t['status'],
                'message' => (string) $t['action'],
                'ref_table' => 'blotter', 'ref_id' => (int) $t['blotter_id'],
                'is_read' => isset($seen[$key]), 'created_at' => $t['recorded_at'],
            ];
        }
    }
    if ($cases && notif_has($pdo, 'blotter_hearings')) {
        $in = implode(',', array_keys($cases));
        foreach ($pdo->query("SELECT hearing_id, blotter_id, hearing_no, hearing_date, hearing_time, location
                              FROM blotter_hearings
                              WHERE blotter_id IN ($in) AND status = 'Scheduled'
                                AND hearing_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 1 DAY")->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $key = 'hearing:' . $h['hearing_id'];
            $when = date('M j, Y', strtotime((string) $h['hearing_date']))
                  . ($h['hearing_time'] ? ' ' . date('g:i A', strtotime((string) $h['hearing_time'])) : '');
            $items[] = [
                'key' => $key, 'source' => 'hearing', 'type' => 'hearing_reminder',
                'title' => L('Paalala: Pagdinig #', 'Reminder: Hearing #') . $h['hearing_no'] . ' · ' . $cases[(int) $h['blotter_id']],
                'message' => $when . ($h['location'] ? ' · ' . $h['location'] : ''),
                'ref_table' => 'blotter', 'ref_id' => (int) $h['blotter_id'],
                'is_read' => isset($seen[$key]),
                'created_at' => date('Y-m-d H:i:s', strtotime((string) $h['hearing_date'] . ' -1 day 08:00')),
            ];
        }
    }

    usort($items, fn($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));
    return array_slice($items, 0, 150);
}

function notif_mark(PDO $pdo, int $rid, string $key): void
{
    if (!preg_match('/^(rn|announcement|alert|blotter|hearing):(\d+)$/', $key, $m)) return;
    [$source, $id] = [$m[1], (int) $m[2]];
    if ($source === 'rn') {
        $pdo->prepare("UPDATE resident_notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND resident_id = ?")
            ->execute([$id, $rid]);
    } elseif ($source === 'announcement') {
        $pdo->prepare("INSERT IGNORE INTO announcement_reads (resident_id, announcement_id) VALUES (?, ?)")->execute([$rid, $id]);
    } else {
        $pdo->prepare("INSERT IGNORE INTO resident_notification_seen (resident_id, source, source_id) VALUES (?, ?, ?)")
            ->execute([$rid, $source, $id]);
    }
}

try {
    if ($action === 'list' || $action === 'count') {
        $items = notif_build($pdo, $rid);
        $unread = count(array_filter($items, fn($i) => !$i['is_read']));
        $alerts = array_values(array_filter($items, fn($i) => !empty($i['is_active'])));
        // Deliver waiting push notifications while an app is open (see push_worker.php).
        if (is_file(__DIR__ . '/push_lib.php')) {
            require_once __DIR__ . '/push_lib.php';
            push_run_throttled($pdo);
        }
        respond(true, '', $action === 'count'
            ? ['unread' => $unread, 'active_alerts' => count($alerts)]
            : ['items' => $items, 'unread' => $unread]);
    }
    if ($action === 'read') {
        notif_mark($pdo, $rid, (string) ($_POST['key'] ?? $_GET['key'] ?? ''));
        respond(true, '');
    }
    if ($action === 'read_all') {
        foreach (notif_build($pdo, $rid) as $i) {
            if (!$i['is_read']) notif_mark($pdo, $rid, $i['key']);
        }
        respond(true, '');
    }
    if ($action === 'register_device') {
        $token = trim((string) ($_POST['push_token'] ?? ''));
        $session = auth_session();
        if ($session) {
            if ($token !== '') {
                // A device token belongs to one session only (e.g. after re-login).
                $pdo->prepare("UPDATE resident_sessions SET push_token = NULL WHERE push_token = ? AND id <> ?")
                    ->execute([$token, (int) $session['id']]);
            }
            $pdo->prepare("UPDATE resident_sessions SET push_token = ? WHERE id = ?")
                ->execute([$token !== '' ? mb_substr($token, 0, 255) : null, (int) $session['id']]);
        }
        respond(true, '');
    }
} catch (Throwable $e) {
    error_log('[notifications.php] ' . $e->getMessage());
    respond(false, L('Hindi ma-load ang mga abiso.', 'Could not load the notifications.'),
        isset($_GET['debug']) ? ['error' => $e->getMessage()] : null, 500);
}

respond(false, L('Hindi wastong action.', 'Invalid action.'), null, 400);

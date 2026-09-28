<?php
/**
 * user/backend/announcements.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Barangay announcements — JSON API for the Flutter app (lib/announcements/).
 * Ported from the SOE resident page user/announcements.php and aligned with
 * the admin module admin/announcement/ (same tables):
 *   • not-deleted announcements that are Published, or Scheduled with a start
 *     date/time that has already passed (Drafts and future Scheduled posts
 *     stay hidden)
 *   • Emergency Notices first, then newest date_posted
 *   • attachments from announcement_attachments (images + files)
 *   • "New" badge per resident, stored in announcement_reads
 *
 * Actions (GET ?action= or POST action=):
 *   • list  (resident_id?)            → announcements + new_count
 *   • read  (resident_id, id)         → mark one as seen
 *
 * Add &debug=1 to see the exact database error when loading fails.
 *
 * Attachment URLs are relative to this backend folder (the admin stores files
 * in admin/announcement/backend/uploads/announcements/).
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php'; // respond(), handle_preflight(), db(), L()
handle_preflight();

const ANN_FILES_BASE_DIR = __DIR__ . '/../../admin/announcement/backend/';
const ANN_FILES_BASE_URL = '../../admin/announcement/backend/';

$pdo    = db();
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$rid    = require_resident(); // verified login token (auth.php), not the resident_id sent by the app

// Per-resident "seen" list (the SOE portal kept this only in the session).
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS announcement_reads (
            resident_id     INT NOT NULL,
            announcement_id INT UNSIGNED NOT NULL,
            read_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (resident_id, announcement_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Throwable $e) {
    error_log('[announcements.php migrate] ' . $e->getMessage());
}

function ann_file_url(string $path): ?string
{
    $path = ltrim($path, '/');
    if ($path === '' || strpos($path, '..') !== false) return null;
    return is_file(ANN_FILES_BASE_DIR . $path) ? ANN_FILES_BASE_URL . $path : null;
}

// ── READ (mark seen) ─────────────────────────────────────────────────────────
if ($action === 'read') {
    $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($rid <= 0 || $id <= 0) {
        respond(false, L('Kailangan ang resident_id at id.', 'resident_id and id are required.'), null, 400);
    }
    try {
        $pdo->prepare("INSERT IGNORE INTO announcement_reads (resident_id, announcement_id) VALUES (?, ?)")
            ->execute([$rid, $id]);
        respond(true, '');
    } catch (Throwable $e) {
        error_log('[announcements.php read] ' . $e->getMessage());
        respond(false, L('Hindi na-save.', 'Not saved.'), null, 500);
    }
}

// ── LIST ─────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    try {
        $cols = $pdo->query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $otherCol = in_array('category_other', $cols, true) ? 'a.category_other' : 'NULL';

        $rows = $pdo->query(
            "SELECT a.id, a.title, a.category, $otherCol AS category_other, a.details,
                    a.date_posted, a.date_start, a.date_end, a.time_start, a.time_end,
                    a.created_at
             FROM announcements a
             WHERE a.deleted_at IS NULL
               AND (a.status = 'Published'
                    -- Scheduled posts whose start time has passed are live even
                    -- if the admin scheduler (run on admin page load) hasn't
                    -- flipped them to Published yet — same rule as ann_scheduler.
                    OR (a.status = 'Scheduled' AND a.date_start IS NOT NULL
                        AND CONCAT(a.date_start, ' ', COALESCE(a.time_start, '00:00:00')) <= NOW()))
             ORDER BY CASE a.category WHEN 'Emergency Notice' THEN 0 ELSE 1 END,
                      a.date_posted DESC, a.created_at DESC, a.id DESC
             LIMIT 200"
        )->fetchAll(PDO::FETCH_ASSOC);

        $atts = [];
        if ($rows) {
            $ids = array_map(fn($r) => (int) $r['id'], $rows);
            $in  = implode(',', $ids);
            try {
                foreach ($pdo->query(
                    "SELECT announcement_id, original_name, file_path, file_type, file_ext,
                            file_size, is_image
                     FROM announcement_attachments
                     WHERE announcement_id IN ($in)
                     ORDER BY announcement_id, sort_order, id"
                )->fetchAll(PDO::FETCH_ASSOC) as $a) {
                    $url = ann_file_url((string) $a['file_path']);
                    if ($url === null) continue;
                    $atts[(int) $a['announcement_id']][] = [
                        'name'     => $a['original_name'],
                        'url'      => $url,
                        'ext'      => strtolower((string) $a['file_ext']),
                        'size'     => (int) $a['file_size'],
                        'is_image' => (int) $a['is_image'] === 1,
                    ];
                }
            } catch (Throwable $e) { /* no attachments table */ }
        }

        $seen = [];
        if ($rid > 0) {
            $s = $pdo->prepare("SELECT announcement_id FROM announcement_reads WHERE resident_id = ?");
            $s->execute([$rid]);
            $seen = array_flip(array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)));
        }

        $today = date('Y-m-d');
        $out = [];
        $new = 0;
        foreach ($rows as $r) {
            $id    = (int) $r['id'];
            $isNew = $rid > 0 && !isset($seen[$id]);
            if ($isNew) $new++;
            $out[] = [
                'id'          => $id,
                'title'       => (string) $r['title'],
                'category'    => $r['category'] === 'Others' && !empty($r['category_other'])
                                    ? (string) $r['category_other'] : (string) $r['category'],
                'is_emergency'=> $r['category'] === 'Emergency Notice',
                'details'     => (string) $r['details'],
                'date_posted' => $r['date_posted'],
                'date_start'  => $r['date_start'],
                'date_end'    => $r['date_end'],
                'time_start'  => $r['time_start'],
                'time_end'    => $r['time_end'],
                'is_ended'    => !empty($r['date_end']) && $r['date_end'] < $today,
                'is_new'      => $isNew,
                'attachments' => $atts[$id] ?? [],
            ];
        }
        respond(true, '', ['announcements' => $out, 'new_count' => $new]);
    } catch (Throwable $e) {
        error_log('[announcements.php list] ' . $e->getMessage());
        respond(false, L('Hindi ma-load ang mga anunsyo.', 'Could not load the announcements.'),
            isset($_GET['debug']) ? ['error' => $e->getMessage()] : null, 500);
    }
}

respond(false, L('Hindi wastong action.', 'Invalid action.'), null, 400);

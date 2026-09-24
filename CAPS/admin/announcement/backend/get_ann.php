<?php
// Suppress any PHP notices/warnings from corrupting JSON output
ob_start();
error_reporting(0);

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../permission_helper.php';
require_permission($pdo, 'announcements', !empty($_GET['trash']) ? 'delete' : 'read');
require_once __DIR__ . '/ann_state_helper.php';

/** Browser URL (relative to admin/announcement/frontend/) for a stored attachment path. */
function ann_attachment_url(string $path): string
{
    if ($path === '' || preg_match('#^(https?:)?//#i', $path) || $path[0] === '/' || strpos($path, '../') === 0) {
        return $path; // already absolute / already frontend-relative
    }
    return '../backend/' . ltrim($path, './');
}

// Discard any accidental output before our JSON
ob_end_clean();
header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
// ?trash=1 → read an announcement that is currently IN Trash (deleted_at IS NOT NULL)
$inTrash = !empty($_GET['trash']);
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT * FROM announcements WHERE id = ? AND deleted_at IS " . ($inTrash ? "NOT NULL" : "NULL") . " LIMIT 1"
    );
    $stmt->execute([$id]);
    $ann = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ann) {
        echo json_encode(['success' => false, 'message' => 'Not found']);
        exit;
    }

    $stmt2 = $pdo->prepare("SELECT * FROM announcement_attachments WHERE announcement_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt2->execute([$id]);
    $attachments = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    // file_path is stored relative to THIS backend folder (uploads/announcements/...),
    // which is where the file really is. The pages that show attachments live in
    // ../frontend/, so give them a URL that points back into backend/.
    foreach ($attachments as &$att) {
        $att['url'] = ann_attachment_url((string) ($att['file_path'] ?? ''));
    }
    unset($att);

    if ($inTrash && empty($ann['status_before_delete'])) {
        $ann['status_before_delete'] = ann_display_state($ann);   // rows deleted before Trash existed
    }

    echo json_encode([
        'success'     => true,
        'announcement'=> $ann,
        'attachments' => $attachments,
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB error']);
}
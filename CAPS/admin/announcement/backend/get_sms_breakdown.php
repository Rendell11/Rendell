<?php
/**
 * get_sms_breakdown.php — JSON for the "View breakdown" panel in SMS Live (ann.php).
 * ?log_id=<sms_logs.LogID> → totals, per purok/area rows and residents not reached.
 */
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../permission_helper.php';
require_permission($pdo, 'announcements', 'read');
require_once __DIR__ . '/sms_recipient_log.php';

header('Content-Type: application/json; charset=utf-8');

$logId = (int) ($_GET['log_id'] ?? 0);
if ($logId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid broadcast.']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT sl.LogID, sl.AlertID, sl.total_recipients, sl.sent_count, sl.status, sl.created_at,
                da.Title, da.Type, da.Severity
           FROM sms_logs sl
           LEFT JOIN disaster_alerts da ON da.AlertID = sl.AlertID
          WHERE sl.LogID = ?"
    );
    $stmt->execute([$logId]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$log) {
        echo json_encode(['success' => false, 'error' => 'Broadcast not found.']);
        exit;
    }
    echo json_encode(['success' => true, 'log' => $log] + sms_breakdown_detail($pdo, $logId), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[get_sms_breakdown] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not load the SMS breakdown.']);
}

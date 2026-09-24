<?php
/**
 * check_sms_delivery.php — "Check delivery" in the SMS breakdown (ann.php).
 * POST log_id=<sms_logs.LogID>, csrf_token → asks the SMS gateway whether each
 * accepted message was really sent by the gateway phone, then returns the
 * refreshed breakdown. Messages the phone could not send (e.g. no load on the
 * SIM) become "failed" and appear under "Residents not reached".
 */
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../permission_helper.php';
require_permission($pdo, 'announcements', 'read');
require_once __DIR__ . '/sms_recipient_log.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Your session has expired. Please refresh the page.']);
    exit;
}

$logId = (int) ($_POST['log_id'] ?? 0);
if ($logId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid broadcast.']);
    exit;
}

try {
    $config = $pdo->query("SELECT * FROM sms_configurations WHERE status = 'Active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$config) {
        echo json_encode(['success' => false, 'error' => 'No active SMS configuration. Set one in Settings → SMS Configuration.']);
        exit;
    }
    $summary = sms_check_delivery($pdo, $logId, $config);
    $stmt = $pdo->prepare(
        "SELECT sl.LogID, sl.AlertID, sl.total_recipients, sl.sent_count, sl.status, sl.created_at,
                da.Title, da.Type, da.Severity
           FROM sms_logs sl
           LEFT JOIN disaster_alerts da ON da.AlertID = sl.AlertID
          WHERE sl.LogID = ?"
    );
    $stmt->execute([$logId]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['LogID' => $logId];
    echo json_encode(['success' => true, 'check' => $summary, 'log' => $log] + sms_breakdown_detail($pdo, $logId), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[check_sms_delivery] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not check the delivery status.']);
}

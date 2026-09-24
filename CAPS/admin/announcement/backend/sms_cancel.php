<?php
/**
 * sms_cancel.php — "Cancel remaining SMS" in View SMS Live.
 * POST log_id, csrf_token. SMS already sent stay sent; everything still waiting
 * in the queue becomes 'cancelled'. The worker checks the job before every SMS.
 */
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../permission_helper.php';
require_permission($pdo, 'announcements', 'update');
require_once __DIR__ . '/../../activity_log_helper.php';
require_once __DIR__ . '/sms_queue.php';

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

try {
    sms_queue_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM sms_queue WHERE log_id = ?");
    $st->execute([$logId]);
    $job = $st->fetch(PDO::FETCH_ASSOC);
    if (!$job || !in_array($job['status'], ['pending', 'processing'], true)) {
        echo json_encode(['success' => false, 'error' => 'This SMS broadcast is no longer sending.']);
        exit;
    }
    $pdo->prepare("UPDATE sms_queue SET status = 'cancelled' WHERE id = ? AND status IN ('pending','processing')")->execute([$job['id']]);
    $up = $pdo->prepare("UPDATE sms_recipient_logs SET status = 'cancelled', detail = 'Cancelled by an administrator'
                          WHERE log_id = ? AND status IN ('pending','retry')");
    $up->execute([$logId]);
    $job['status'] = 'cancelled';
    sms_finalize_job($pdo, $job); // no-op while one SMS is still being sent; the worker finishes it
    log_activity('Disaster and Risk Map', 'Cancel Disaster SMS', "Cancelled {$up->rowCount()} queued SMS of SMS log {$logId} (alert ID {$job['alert_id']}).");
    echo json_encode(['success' => true, 'message' => $up->rowCount() . ' queued SMS cancelled. Messages already sent are not affected.']);
} catch (Throwable $e) {
    error_log('[sms_cancel] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not cancel the SMS broadcast.']);
}

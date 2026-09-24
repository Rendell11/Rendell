<?php
/**
 * sms_live_status.php — JSON for "View SMS Live" (ann.php), polled every ~2 seconds.
 *
 *   GET ?log_id=<sms_logs.LogID>[&filter=sent|pending|processing|retry|failed|no_number|cancelled][&page=N]
 *        → counters, progress, current state, last sent, live activity, recipients page
 *   GET ?log_ids=1,2,3   → compact counters for several SMS Live cards at once
 *
 * If a broadcast is still queued but its worker stopped (server restart, loopback
 * request blocked…), the worker is started again from here.
 */
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../permission_helper.php';
require_permission($pdo, 'announcements', 'read');
require_once __DIR__ . '/sms_queue.php';

session_write_close(); // polling must never block the admin's other requests
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sls_out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // ── Compact mode for the SMS Live cards ─────────────────────────────────
    if (isset($_GET['log_ids'])) {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $_GET['log_ids'])), static fn($v) => $v > 0))), 0, 50);
        $out = [];
        $kick = false;
        foreach ($ids as $id) {
            $s = sms_live_status($pdo, $id, '', 1, 10);
            if (!$s) {
                continue;
            }
            $kick = $kick || $s['stale'];
            $out[$id] = ['state' => $s['state'], 'counts' => $s['counts'], 'log_status' => $s['log']['log_status']];
        }
        if ($kick) {
            sms_trigger_worker();
        }
        sls_out(['success' => true, 'logs' => $out]);
    }

    $logId = (int) ($_GET['log_id'] ?? 0);
    if ($logId <= 0) {
        sls_out(['success' => false, 'error' => 'Invalid broadcast.'], 400);
    }
    $filter = (string) ($_GET['filter'] ?? '');
    $page = (int) ($_GET['page'] ?? 1);
    $s = sms_live_status($pdo, $logId, $filter, $page);
    if (!$s) {
        sls_out(['success' => false, 'error' => 'Broadcast not found.'], 404);
    }
    if ($s['stale']) {
        sms_trigger_worker();
    }
    $s['can_cancel'] = in_array($s['state'], ['queued', 'sending'], true) && staff_can($pdo, 'announcements', 'update');
    sls_out(['success' => true] + $s);
} catch (Throwable $e) {
    error_log('[sms_live_status] ' . $e->getMessage());
    sls_out(['success' => false, 'error' => 'Could not load the SMS status.'], 500);
}

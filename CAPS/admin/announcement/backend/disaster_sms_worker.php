<?php
/**
 * disaster_sms_worker.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Background SMS Worker for Disaster Alert Broadcasting
 *
 * HOW IT WORKS:
 *   1. process_disaster.php saves all recipients + message into `sms_queue`
 *      (status = 'pending'), then immediately redirects the admin.
 *   2. process_disaster.php fires a non-blocking HTTP request to THIS file.
 *   3. This worker claims the job (status → 'processing'), sends every SMS
 *      using cURL, then marks the job 'done' or 'failed'.
 *
 * SECURITY:
 *   Only callable from localhost (127.0.0.1 / ::1) or via the internal
 *   fire-and-forget trigger. Not meant to be accessed directly by browsers.
 *
 * COMPATIBLE WITH: XAMPP / standard PHP-FPM / mod_php — no extra extensions
 *                  required (no pcntl, no pthreads, no Redis, no Beanstalkd).
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ── Allow the worker to run as long as it needs ──────────────────────────────
ignore_user_abort(true);
set_time_limit(0);
ini_set('max_execution_time', 0);

// ── Only allow calls from localhost ──────────────────────────────────────────
$caller_ip = $_SERVER['REMOTE_ADDR'] ?? '';
$allowed   = ['127.0.0.1', '::1', 'localhost'];
if (!in_array($caller_ip, $allowed, true)) {
    http_response_code(403);
    exit('Forbidden');
}

// ── Immediately send a 200 OK and close the HTTP connection ──────────────────
// This lets process_disaster.php's cURL fire-and-forget call return instantly
// while this worker continues running in the background.
if (function_exists('fastcgi_finish_request')) {
    // PHP-FPM path — fastest close
    header('Content-Type: text/plain');
    header('Content-Length: 2');
    echo 'OK';
    fastcgi_finish_request();
} else {
    // mod_php / standard CGI path
    header('Connection: close');
    header('Content-Type: text/plain');
    $body = 'OK';
    header('Content-Length: ' . strlen($body));
    echo $body;
    if (ob_get_level()) {
        ob_end_flush();
    }
    flush();
}

// ── HTTP connection is now closed. Admin sees the redirect. ──────────────────
// Everything below runs silently in the background.

require_once __DIR__ . '/../../db.php';

// ── Claim a pending job (use UPDATE to lock it atomically) ───────────────────
try {
    $lock = $pdo->prepare(
        "UPDATE sms_queue
         SET status = 'processing', started_at = NOW()
         WHERE status = 'pending'
         ORDER BY id ASC
         LIMIT 1"
    );
    $lock->execute();

    if ($lock->rowCount() === 0) {
        // No pending jobs — nothing to do
        exit;
    }

    // Fetch the job we just claimed
    $job = $pdo->query(
        "SELECT * FROM sms_queue WHERE status = 'processing' ORDER BY id ASC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        exit;
    }

} catch (PDOException $e) {
    error_log("SMS Worker: DB claim error — " . $e->getMessage());
    exit;
}

// ── Load active SMS configuration ────────────────────────────────────────────
try {
    $config = $pdo->prepare(
        "SELECT api_key, from_number, device_id, api_url, configuration_name
         FROM sms_configurations
         WHERE status = 'Active'
         LIMIT 1"
    );
    $config->execute();
    $smsConfig = $config->fetch(PDO::FETCH_ASSOC);

    if (!$smsConfig) {
        error_log("SMS Worker: No active SMS configuration found. Job ID " . $job['id'] . " aborted.");
        $pdo->prepare("UPDATE sms_queue SET status = 'failed', finished_at = NOW() WHERE id = ?")
            ->execute([$job['id']]);
        exit;
    }
} catch (PDOException $e) {
    error_log("SMS Worker: Config load error — " . $e->getMessage());
    exit;
}

$api_key     = $smsConfig['api_key'];
$from_number = $smsConfig['from_number'];
$device_id   = $smsConfig['device_id'];
$api_url     = $smsConfig['api_url'];
$sms_message = $job['message'];

error_log("SMS Worker: Starting job ID {$job['id']} — {$job['total']} recipients | Config: " . ($smsConfig['configuration_name'] ?? 'unknown'));

// ── Decode recipients list ────────────────────────────────────────────────────
$recipients = json_decode($job['recipients'], true);
if (empty($recipients)) {
    error_log("SMS Worker: Job ID {$job['id']} has no recipients. Marking done.");
    $pdo->prepare("UPDATE sms_queue SET status = 'done', finished_at = NOW() WHERE id = ?")
        ->execute([$job['id']]);
    exit;
}

// ── Send SMS to each recipient ────────────────────────────────────────────────
$sent   = 0;
$failed = 0;

foreach ($recipients as $resident) {
    if (empty($resident['ContactNumber'])) {
        $failed++;
        continue;
    }

    // Normalize Philippine mobile number to E.164
    $phone = preg_replace('/[^0-9]/', '', $resident['ContactNumber']);

    if (strlen($phone) < 10) {
        error_log("SMS Worker: Skipping invalid number for ResidentID " . ($resident['ResidentID'] ?? '?'));
        $failed++;
        continue;
    }

    if (substr($phone, 0, 2) === '63') {
        $formatted_to = '+' . $phone;
    } elseif (substr($phone, 0, 1) === '0') {
        $formatted_to = '+63' . substr($phone, 1);
    } else {
        $formatted_to = '+63' . $phone;
    }

    $payload = [
        "to"        => $formatted_to,
        "message"   => $sms_message,
        "channel"   => "sms",
        "device_id" => $device_id,
        "e164From"  => $from_number,
    ];

    $ch = curl_init($api_url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => [
            "X-API-Key: $api_key",
            "Content-Type: application/json",
        ],
        CURLOPT_POST           => 1,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $result    = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    $response  = json_decode($result, true);
    curl_close($ch);

    $is_success = false;
    if ($http_code >= 200 && $http_code < 300) {
        if (!empty($response['id'])) {
            $is_success = true;
        } elseif (isset($response['status']) && !in_array(strtolower($response['status']), ['failed', 'error', 'rejected'])) {
            $is_success = true;
        } elseif (is_array($response) && !isset($response['error'])) {
            $is_success = true;
        }
    }

    if ($is_success) {
        $sent++;
        error_log("SMS Worker ✓ | TO: $formatted_to | HTTP: $http_code");
    } else {
        $failed++;
        error_log("SMS Worker ✗ | TO: $formatted_to | HTTP: $http_code | cURL: $curl_err | Body: $result");
    }

    // ── Update progress in DB after each send so the UI can poll it ──────────
    try {
        $pdo->prepare(
            "UPDATE sms_queue SET sent = ?, failed = ? WHERE id = ?"
        )->execute([$sent, $failed, $job['id']]);
    } catch (PDOException $e) {
        error_log("SMS Worker: Progress update error — " . $e->getMessage());
    }

    // Small delay to avoid rate-limiting — 150ms is enough for InfiniReach
    usleep(150000);
}

// ── Mark job complete ─────────────────────────────────────────────────────────
try {
    $final_status = ($failed > 0 && $sent === 0) ? 'failed' : 'done';
    $pdo->prepare(
        "UPDATE sms_queue
         SET status = ?, sent = ?, failed = ?, finished_at = NOW()
         WHERE id = ?"
    )->execute([$final_status, $sent, $failed, $job['id']]);

    // ── Update sms_logs table (used by the Live SMS Status Panel) ────────────
    if (!empty($job['alert_id'])) {
        $pdo->prepare(
            "UPDATE sms_logs
             SET sent_count = ?, status = ?
             WHERE AlertID = ?
             ORDER BY LogID DESC
             LIMIT 1"
        )->execute([$sent, $final_status === 'done' ? 'Completed' : 'Failed', $job['alert_id']]);
    }

    error_log("SMS Worker: Job ID {$job['id']} finished. Sent: $sent | Failed: $failed | Status: $final_status");

} catch (PDOException $e) {
    error_log("SMS Worker: Final update error — " . $e->getMessage());
}

// ── Check if there are more pending jobs and self-trigger if so ──────────────
try {
    $more = $pdo->query("SELECT COUNT(*) FROM sms_queue WHERE status = 'pending'")->fetchColumn();
    if ($more > 0) {
        // Fire another worker instance for the next job (non-blocking)
        // Build from this file's own URL (the old hard-coded path pointed to
        // a non-existent "announcements" folder, so queued jobs never continued).
        $worker_url = 'http://127.0.0.1' . str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/CAPS/admin/announcement/backend/disaster_sms_worker.php');
        $ch = curl_init($worker_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 1,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
} catch (PDOException $e) {
    // Non-critical — ignore
}
<?php
/**
 * sms_queue.php — background SMS sending for Disaster Alerts.
 *
 *   "Issue Disaster Alert Now" (process_disaster.php)
 *        → sms_enqueue_broadcast(): one sms_logs row + one sms_queue job
 *          + one sms_recipient_logs row per resident (status 'pending')
 *        → sms_trigger_worker(): non-blocking kick of disaster_sms_worker.php
 *        → admin is redirected to ann.php immediately
 *   disaster_sms_worker.php sends the queue one SMS at a time (rate limited),
 *   retries temporary failures, and keeps the counters current so
 *   sms_live_status.php can show live progress.
 *
 * Tables (all existing, extended in place):
 *   sms_logs            one row per broadcast (SMS Live / History cards)
 *   sms_queue           one job per broadcast (worker state, heartbeat)
 *   sms_recipient_logs  one row per targeted resident — UNIQUE (alert_id, resident_id)
 *                       so the same alert can never be queued twice for a resident.
 */

require_once __DIR__ . '/sms_recipient_log.php';

const SMS_MAX_ATTEMPTS = 3;          // 1 try + 2 retries for temporary failures
const SMS_RETRY_DELAY_SECONDS = 15;  // × attempt number (15s, 30s)
const SMS_SEND_DELAY_MS = 250;       // pause between gateway requests (provider rate limit)
const SMS_BATCH_SIZE = 25;           // recipients claimed per batch
const SMS_WORKER_STALE_SECONDS = 45; // no heartbeat for this long → the worker is restarted

if (!function_exists('sms_queue_ensure')) {
    function sms_queue_ensure(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        sms_recipient_log_ensure($pdo);
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sms_queue (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                alert_id    INT DEFAULT NULL COMMENT 'FK -> disaster_alerts.AlertID',
                log_id      INT DEFAULT NULL COMMENT 'FK -> sms_logs.LogID',
                action      VARCHAR(30) NOT NULL DEFAULT 'create' COMMENT 'create | update',
                recipients  MEDIUMTEXT NOT NULL COMMENT 'Legacy JSON list; recipients now live in sms_recipient_logs',
                message     TEXT NOT NULL COMMENT 'Full SMS message body',
                total       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Recipients with a contact number',
                sent        INT UNSIGNED NOT NULL DEFAULT 0,
                failed      INT UNSIGNED NOT NULL DEFAULT 0,
                status      ENUM('pending','processing','done','failed','cancelled') NOT NULL DEFAULT 'pending',
                last_error  VARCHAR(255) DEFAULT NULL,
                created_by  VARCHAR(100) DEFAULT NULL,
                heartbeat_at DATETIME DEFAULT NULL,
                started_at  DATETIME DEFAULT NULL,
                finished_at DATETIME DEFAULT NULL,
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_sms_queue_log (log_id),
                KEY idx_status (status),
                KEY idx_alert_id (alert_id),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Async SMS job queue for disaster alert broadcasting'
        ");
        $cols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM sms_queue")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cols[$c['Field']] = $c['Type'];
        }
        $alter = [];
        if (isset($cols['status']) && strpos($cols['status'], "'cancelled'") === false) {
            $alter[] = "MODIFY COLUMN status ENUM('pending','processing','done','failed','cancelled') NOT NULL DEFAULT 'pending'";
        }
        foreach ([
            'log_id' => "ADD COLUMN log_id INT DEFAULT NULL COMMENT 'FK -> sms_logs.LogID' AFTER alert_id",
            'last_error' => "ADD COLUMN last_error VARCHAR(255) DEFAULT NULL",
            'created_by' => "ADD COLUMN created_by VARCHAR(100) DEFAULT NULL",
            'heartbeat_at' => "ADD COLUMN heartbeat_at DATETIME DEFAULT NULL",
        ] as $col => $sql) {
            if (!isset($cols[$col])) {
                $alter[] = $sql;
            }
        }
        if ($alter) {
            $pdo->exec("ALTER TABLE sms_queue " . implode(', ', $alter));
        }
        $keys = array_column($pdo->query("SHOW INDEX FROM sms_queue")->fetchAll(PDO::FETCH_ASSOC), 'Key_name');
        if (!in_array('uq_sms_queue_log', $keys, true)) {
            $pdo->exec("ALTER TABLE sms_queue ADD UNIQUE KEY uq_sms_queue_log (log_id)");
        }
        $done = true;
    }
}

if (!function_exists('sms_normalize_number')) {
    /** Philippine mobile number → E.164 (+639…), or null when it is too short to be valid. */
    function sms_normalize_number(?string $raw): ?string
    {
        $phone = preg_replace('/[^0-9]/', '', (string) $raw);
        if (strlen($phone) < 10) {
            return null;
        }
        if (substr($phone, 0, 2) === '63') {
            return '+' . $phone;
        }
        if (substr($phone, 0, 1) === '0') {
            return '+63' . substr($phone, 1);
        }
        return '+63' . $phone;
    }
}

if (!function_exists('sms_mask_number')) {
    /** 09171234567 → 0917•••4567 (for live monitoring screens) */
    function sms_mask_number(?string $n): string
    {
        $n = trim((string) $n);
        if ($n === '') {
            return '—';
        }
        return strlen($n) <= 7 ? str_repeat('•', strlen($n)) : substr($n, 0, 4) . '•••' . substr($n, -4);
    }
}

if (!function_exists('sms_active_config')) {
    function sms_active_config(PDO $pdo): ?array
    {
        $row = $pdo->query(
            "SELECT id, api_key, from_number, device_id, api_url, configuration_name
               FROM sms_configurations WHERE status = 'Active' LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('sms_gateway_send')) {
    /**
     * One SMS through the active provider (InfiniReach) — the same request
     * process_disaster.php and Settings → Test SMS use.
     * Returns ['ok', 'temporary', 'message_id', 'http', 'detail'].
     *   temporary = true → network error / timeout / 408 / 429 / 5xx: worth retrying.
     */
    function sms_gateway_send(array $config, string $to, string $message): array
    {
        $ch = curl_init($config['api_url']);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['X-API-Key: ' . $config['api_key'], 'Content-Type: application/json'],
            CURLOPT_POST => true,
            // InfiniReach requires the registered sender number in `from`.
            CURLOPT_POSTFIELDS => json_encode(['to' => $to, 'message' => $message, 'from' => $config['from_number'], 'channel' => 'sms']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false, // same as the rest of the project (XAMPP often lacks a CA bundle)
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '' || $body === false) {
            return ['ok' => false, 'temporary' => true, 'message_id' => null, 'http' => $code,
                'detail' => 'Could not reach the SMS gateway: ' . ($err ?: 'no response')];
        }
        $res = json_decode((string) $body, true);
        $ok = $code >= 200 && $code < 300 && (
            !empty($res['success']) || !empty($res['messageId']) || !empty($res['id'])
            || (isset($res['status']) && !in_array(strtolower((string) $res['status']), ['failed', 'error', 'rejected'], true))
            || (is_array($res) && !isset($res['error']) && !isset($res['message']))
        );
        if ($ok) {
            return ['ok' => true, 'temporary' => false, 'http' => $code, 'detail' => 'Accepted by the SMS gateway',
                'message_id' => $res['messageId'] ?? $res['id'] ?? $res['data']['messageId'] ?? $res['data']['id'] ?? null];
        }
        $msg = is_array($res) ? ($res['message'] ?? $res['error']['message'] ?? $res['error'] ?? '') : '';
        $msg = is_string($msg) ? $msg : json_encode($msg);
        return [
            'ok' => false,
            'temporary' => $code === 0 || $code === 408 || $code === 425 || $code === 429 || $code >= 500,
            'message_id' => null,
            'http' => $code,
            'detail' => mb_substr('HTTP ' . $code . ($msg !== '' ? ': ' . $msg : ''), 0, 200),
        ];
    }
}

if (!function_exists('sms_enqueue_broadcast')) {
    /**
     * Queues one disaster SMS broadcast. Fast: only database inserts, no gateway calls.
     *
     * @param array $targeted every resident in the audience (with or without a number)
     * @return array ['log_id', 'job_id', 'queued', 'no_number', 'invalid', 'duplicates']
     */
    function sms_enqueue_broadcast(PDO $pdo, int $alertId, string $message, array $targeted, string $createdBy = ''): array
    {
        sms_queue_ensure($pdo);

        // Residents already queued for this alert (double submit, retries of the request…)
        $already = $pdo->prepare("SELECT resident_id FROM sms_recipient_logs WHERE alert_id = ?");
        $already->execute([$alertId]);
        $skip = array_flip(array_map('intval', $already->fetchAll(PDO::FETCH_COLUMN)));

        $rows = [];
        $seen = [];
        $out = ['log_id' => 0, 'job_id' => 0, 'queued' => 0, 'no_number' => 0, 'invalid' => 0, 'duplicates' => 0];
        foreach ($targeted as $r) {
            $rid = (int) ($r['ResidentID'] ?? 0);
            if ($rid <= 0 || isset($seen[$rid]) || isset($skip[$rid])) {
                $out['duplicates'] += ($rid > 0 && (isset($seen[$rid]) || isset($skip[$rid]))) ? 1 : 0;
                continue;
            }
            $seen[$rid] = true;
            $phone = trim((string) ($r['ContactNumber'] ?? ''));
            if ($phone === '') {
                $status = 'no_number';
                $detail = 'No contact number on file';
                $out['no_number']++;
            } elseif (!sms_normalize_number($phone)) {
                $status = 'invalid';
                $detail = 'Number too short: ' . $phone;
                $out['invalid']++;
            } else {
                $status = 'pending';
                $detail = 'Waiting in the SMS queue';
                $out['queued']++;
            }
            $name = trim(($r['FirstName'] ?? '') . ' ' . ($r['LastName'] ?? ''));
            $rows[] = [$rid, $name !== '' ? mb_substr($name, 0, 255) : 'Resident #' . $rid, sms_area_label($r),
                $phone !== '' ? mb_substr($phone, 0, 30) : null, $status, $detail];
        }

        $withNumber = $out['queued'] + $out['invalid'];
        if (!$rows) {
            return $out; // nothing new to send (everyone was already queued for this alert)
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO sms_logs (AlertID, total_recipients, sent_count, status) VALUES (?, ?, 0, ?)")
                ->execute([$alertId, $withNumber, $out['queued'] > 0 ? 'Queued' : 'Failed']);
            $logId = (int) $pdo->lastInsertId();

            foreach (array_chunk($rows, 200) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?)'));
                $params = [];
                foreach ($chunk as $row) {
                    array_push($params, $logId, $alertId, ...$row);
                }
                // INSERT IGNORE + UNIQUE (alert_id, resident_id): a concurrent duplicate request cannot queue anyone twice
                $pdo->prepare("INSERT IGNORE INTO sms_recipient_logs
                        (log_id, alert_id, resident_id, resident_name, area_label, contact_number, status, detail)
                        VALUES {$ph}")->execute($params);
            }

            $jobId = 0;
            if ($out['queued'] > 0) {
                $pdo->prepare("INSERT INTO sms_queue (alert_id, log_id, action, recipients, message, total, status, created_by)
                               VALUES (?, ?, 'create', '[]', ?, ?, 'pending', ?)")
                    ->execute([$alertId, $logId, $message, $withNumber, mb_substr($createdBy, 0, 100)]);
                $jobId = (int) $pdo->lastInsertId();
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return ['log_id' => $logId, 'job_id' => $jobId] + $out;
    }
}

if (!function_exists('sms_trigger_worker')) {
    /**
     * Starts disaster_sms_worker.php without waiting for it (fire-and-forget HTTP call to
     * this same server). The worker answers immediately and keeps running on its own.
     * Safe to call often: the worker holds a database lock, so only one runs at a time.
     */
    function sms_trigger_worker(): void
    {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $port = (int) ($_SERVER['SERVER_PORT'] ?? ($https ? 443 : 80));
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/CAPS/admin/announcement/backend/x.php'));
        $url = ($https ? 'https' : 'http') . '://127.0.0.1:' . $port . rtrim($dir, '/') . '/disaster_sms_worker.php';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT_MS => 1000,
            CURLOPT_TIMEOUT_MS => 1500, // the worker replies "OK" right away; never block the admin longer than this
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}

if (!function_exists('sms_job_counts')) {
    /** Live counters for one broadcast, straight from sms_recipient_logs (indexed on log_id, status). */
    function sms_job_counts(PDO $pdo, int $logId): array
    {
        $st = $pdo->prepare("SELECT status, delivery, COUNT(*) n FROM sms_recipient_logs WHERE log_id = ? GROUP BY status, delivery");
        $st->execute([$logId]);
        $t = sms_empty_totals();
        $t['phone_failed'] = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            sms_add_to_totals($t, $r['status'], $r['delivery'], (int) $r['n']);
            if ($r['delivery'] === 'failed') {
                $t['phone_failed'] += (int) $r['n']; // accepted, then the gateway phone could not send (no load…)
            }
        }
        // Everyone with a number is part of the send; residents without one are reported separately.
        $t['total'] = $t['targeted'] - $t['no_number'];
        $t['done'] = $t['sent'] + $t['failed'] + $t['cancelled'];
        $t['progress'] = $t['total'] > 0 ? round($t['done'] / $t['total'] * 100, 1) : 100.0;
        return $t;
    }
}

if (!function_exists('sms_finalize_job')) {
    /** Writes the final counters to sms_queue + sms_logs once nothing is left to send. */
    function sms_finalize_job(PDO $pdo, array $job): array
    {
        $t = sms_job_counts($pdo, (int) $job['log_id']);
        if ($t['queued'] + $t['processing'] > 0) {
            return $t; // not finished
        }
        $cur = $pdo->prepare("SELECT status FROM sms_queue WHERE id = ?");
        $cur->execute([$job['id']]);
        $cancelled = $job['status'] === 'cancelled' || $cur->fetchColumn() === 'cancelled';
        $queueStatus = $cancelled ? 'cancelled' : ($t['sent'] === 0 && $t['failed'] > 0 ? 'failed' : 'done');
        $logStatus = $cancelled ? 'Cancelled'
            : ($t['sent'] >= $t['total'] && $t['total'] > 0 ? 'Completed' : ($t['sent'] > 0 ? 'Partial' : 'Failed'));

        $pdo->prepare("UPDATE sms_queue SET status = ?, sent = ?, failed = ?, finished_at = COALESCE(finished_at, NOW()) WHERE id = ?")
            ->execute([$queueStatus, $t['sent'], $t['failed'], $job['id']]);
        $pdo->prepare("UPDATE sms_logs SET sent_count = ?, status = ? WHERE LogID = ?")
            ->execute([$t['sent'], $logStatus, $job['log_id']]);
        return $t;
    }
}

if (!function_exists('sms_live_status')) {
    /**
     * Everything the "View SMS Live" monitor shows, for one broadcast.
     * $filter: '' | pending | processing | sent | retry | failed | no_number | cancelled
     */
    function sms_live_status(PDO $pdo, int $logId, string $filter = '', int $page = 1, int $perPage = 50): ?array
    {
        sms_queue_ensure($pdo);
        $st = $pdo->prepare(
            "SELECT sl.LogID, sl.AlertID, sl.total_recipients, sl.sent_count, sl.status, sl.created_at,
                    da.Title, da.Type, da.Severity,
                    q.id AS job_id, q.status AS job_status, q.started_at, q.finished_at, q.heartbeat_at, q.last_error,
                    TIMESTAMPDIFF(SECOND, q.heartbeat_at, NOW()) AS heartbeat_age,
                    TIMESTAMPDIFF(SECOND, q.created_at, NOW()) AS job_age
               FROM sms_logs sl
               LEFT JOIN disaster_alerts da ON da.AlertID = sl.AlertID
               LEFT JOIN sms_queue q ON q.log_id = sl.LogID
              WHERE sl.LogID = ?"
        );
        $st->execute([$logId]);
        $log = $st->fetch(PDO::FETCH_ASSOC);
        if (!$log) {
            return null;
        }
        $t = sms_job_counts($pdo, $logId);

        $running = in_array($log['job_status'], ['pending', 'processing'], true) && ($t['queued'] + $t['processing']) > 0;
        if (!$log['job_id']) {
            $state = 'completed'; // broadcast sent before the queue existed
        } elseif ($log['job_status'] === 'cancelled' && !$running) {
            $state = 'cancelled';
        } elseif ($running) {
            $state = ($log['job_status'] === 'pending' && $t['done'] === 0 && $t['processing'] === 0) ? 'queued' : 'sending';
        } else {
            $state = 'completed';
        }
        $stale = $running && (
            ($log['heartbeat_at'] === null && (int) $log['job_age'] > 5)
            || ($log['heartbeat_at'] !== null && (int) $log['heartbeat_age'] > SMS_WORKER_STALE_SECONDS)
        );

        // Last successful SMS
        $ls = $pdo->prepare("SELECT resident_name, contact_number, last_attempt_at FROM sms_recipient_logs
                              WHERE log_id = ? AND status = 'sent' ORDER BY last_attempt_at DESC, id DESC LIMIT 1");
        $ls->execute([$logId]);
        $last = $ls->fetch(PDO::FETCH_ASSOC) ?: null;

        // Live activity — newest first
        $act = $pdo->prepare("SELECT resident_name, status, attempts, detail, last_attempt_at FROM sms_recipient_logs
                               WHERE log_id = ? AND last_attempt_at IS NOT NULL ORDER BY last_attempt_at DESC, id DESC LIMIT 25");
        $act->execute([$logId]);
        $activity = [];
        foreach ($act->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $activity[] = [
                'time' => $a['last_attempt_at'],
                'name' => $a['resident_name'],
                'status' => $a['status'],
                'attempts' => (int) $a['attempts'],
                'detail' => in_array($a['status'], ['failed', 'retry', 'invalid'], true) ? $a['detail'] : '',
            ];
        }

        // Recipients table (paged, optional status filter)
        $where = 'log_id = ?';
        $params = [$logId];
        $map = ['pending' => ['pending'], 'processing' => ['processing'], 'sent' => ['sent'], 'retry' => ['retry'],
            'failed' => ['failed', 'invalid'], 'no_number' => ['no_number'], 'cancelled' => ['cancelled']];
        if (isset($map[$filter])) {
            $where .= ' AND status IN (' . implode(',', array_fill(0, count($map[$filter]), '?')) . ')';
            $params = array_merge($params, $map[$filter]);
        }
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM sms_recipient_logs WHERE {$where}");
        $cnt->execute($params);
        $rowsTotal = (int) $cnt->fetchColumn();
        $perPage = max(10, min(100, $perPage));
        $page = max(1, min($page, (int) ceil(max(1, $rowsTotal) / $perPage)));
        $rs = $pdo->prepare("SELECT resident_name, area_label, contact_number, status, delivery, attempts, detail, last_attempt_at
                               FROM sms_recipient_logs WHERE {$where}
                              ORDER BY FIELD(status,'processing','retry','pending','failed','invalid','sent','cancelled','no_number'), id
                              LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage));
        $rs->execute($params);
        $recipients = [];
        foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $recipients[] = [
                'name' => $r['resident_name'],
                'area' => $r['area_label'],
                'mobile' => sms_mask_number($r['contact_number']),
                'status' => $r['status'],
                'delivery' => $r['delivery'],
                'attempts' => (int) $r['attempts'],
                'detail' => in_array($r['status'], ['failed', 'retry', 'invalid'], true) ? $r['detail'] : '',
                'time' => $r['last_attempt_at'],
            ];
        }

        return [
            'log' => [
                'log_id' => (int) $log['LogID'],
                'alert_id' => (int) $log['AlertID'],
                'title' => $log['Title'],
                'type' => $log['Type'],
                'severity' => $log['Severity'],
                'created_at' => $log['created_at'],
                'started_at' => $log['started_at'],
                'finished_at' => $log['finished_at'],
                'log_status' => $log['status'],
                'job_error' => $log['last_error'],
            ],
            'state' => $state,
            'stale' => $stale,
            'counts' => $t,
            'last_sent' => $last ? ['name' => $last['resident_name'], 'mobile' => sms_mask_number($last['contact_number']), 'time' => $last['last_attempt_at']] : null,
            'activity' => $activity,
            'recipients' => $recipients,
            'rows_total' => $rowsTotal,
            'page' => $page,
            'pages' => (int) ceil(max(1, $rowsTotal) / $perPage),
            'server_time' => $pdo->query("SELECT NOW()")->fetchColumn(), // DB clock, same as the timestamps above
        ];
    }
}

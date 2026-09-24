<?php
/**
 * sms_recipient_log.php
 * Per-resident record of every disaster SMS broadcast.
 *
 * sms_logs only stores "total with a number" and "sent". This table keeps one
 * row per TARGETED resident — including residents without a contact number —
 * so the SMS Live panel can show, per purok / area:
 *     targeted 30 · sent 20 · failed 0 · no number 10
 * and list exactly who was not reached (to contact them another way).
 *
 * Statuses: sent | failed | invalid (number too short) | no_number
 *
 * "sent" only means the SMS gateway ACCEPTED the message (it answered with a
 * messageId). The gateway phone sends it afterwards — if its SIM has no load
 * or no signal, that later send fails. So every accepted row also keeps the
 * gateway message_id and a delivery state:
 *     pending   → accepted, not yet confirmed by the gateway phone
 *     confirmed → the gateway phone reported it sent / delivered
 *     failed    → the gateway phone could not send it (row becomes status=failed)
 * sms_check_delivery() asks the gateway for the real state of pending rows.
 */

if (!function_exists('sms_recipient_log_ensure')) {
    function sms_recipient_log_ensure(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sms_recipient_logs (
                id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                log_id          INT          DEFAULT NULL,
                alert_id        INT          DEFAULT NULL,
                resident_id     INT UNSIGNED DEFAULT NULL,
                resident_name   VARCHAR(255) DEFAULT NULL,
                area_label      VARCHAR(255) DEFAULT NULL,
                contact_number  VARCHAR(30)  DEFAULT NULL,
                status          ENUM('pending','processing','retry','sent','failed','invalid','no_number','cancelled') NOT NULL DEFAULT 'pending',
                detail          VARCHAR(255) DEFAULT NULL,
                message_id      VARCHAR(100) DEFAULT NULL,
                delivery        ENUM('pending','confirmed','failed') DEFAULT NULL,
                checked_at      DATETIME     DEFAULT NULL,
                attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
                last_attempt_at DATETIME(3)  DEFAULT NULL,
                next_attempt_at DATETIME     DEFAULT NULL,
                sent_at         DATETIME     DEFAULT NULL,
                created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_srl_alert_resident (alert_id, resident_id),
                KEY idx_srl_log_status (log_id, status),
                KEY idx_srl_log_attempt (log_id, last_attempt_at),
                KEY idx_srl_alert (alert_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // Tables created before delivery tracking / the SMS queue existed
        $cols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM sms_recipient_logs")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cols[$c['Field']] = $c['Type'];
        }
        $alter = [];
        if (isset($cols['status']) && strpos($cols['status'], "'processing'") === false) {
            $alter[] = "MODIFY COLUMN status ENUM('pending','processing','retry','sent','failed','invalid','no_number','cancelled') NOT NULL DEFAULT 'pending'";
        }
        foreach ([
            'message_id' => "ADD COLUMN message_id VARCHAR(100) DEFAULT NULL",
            'delivery' => "ADD COLUMN delivery ENUM('pending','confirmed','failed') DEFAULT NULL",
            'checked_at' => "ADD COLUMN checked_at DATETIME DEFAULT NULL",
            'attempts' => "ADD COLUMN attempts TINYINT UNSIGNED NOT NULL DEFAULT 0",
            'last_attempt_at' => "ADD COLUMN last_attempt_at DATETIME(3) DEFAULT NULL",
            'next_attempt_at' => "ADD COLUMN next_attempt_at DATETIME DEFAULT NULL",
            'sent_at' => "ADD COLUMN sent_at DATETIME DEFAULT NULL",
        ] as $col => $sql) {
            if (!isset($cols[$col])) {
                $alter[] = $sql;
            }
        }
        if ($alter) {
            $pdo->exec("ALTER TABLE sms_recipient_logs " . implode(', ', $alter));
        }
        $keys = array_unique(array_column($pdo->query("SHOW INDEX FROM sms_recipient_logs")->fetchAll(PDO::FETCH_ASSOC), 'Key_name'));
        foreach ([
            'idx_srl_log_status' => "ADD KEY idx_srl_log_status (log_id, status)",
            'idx_srl_log_attempt' => "ADD KEY idx_srl_log_attempt (log_id, last_attempt_at)",
            // Duplicate protection: one SMS per alert per resident, enforced by the database
            'uq_srl_alert_resident' => "ADD UNIQUE KEY uq_srl_alert_resident (alert_id, resident_id)",
        ] as $key => $sql) {
            if (!in_array($key, $keys, true)) {
                try {
                    $pdo->exec("ALTER TABLE sms_recipient_logs " . $sql);
                } catch (PDOException $e) {
                    // e.g. old test data already holds duplicates — the queue still checks before inserting
                    error_log('[sms_recipient_log] ' . $key . ': ' . $e->getMessage());
                }
            }
        }
        $done = true;
    }
}

if (!function_exists('sms_area_label')) {
    /** Purok / subdivision / street the resident belongs to, for grouping. */
    function sms_area_label(array $r): string
    {
        $areaName = trim((string) ($r['AreaName'] ?? ''));
        $areaType = trim((string) ($r['AreaType'] ?? ''));
        if ($areaName !== '') {
            return ($areaType !== '' ? $areaType . ' ' : '') . $areaName;
        }
        $purok = trim((string) ($r['Purok'] ?? ''));
        if ($purok !== '') {
            return stripos($purok, 'purok') === 0 ? $purok : 'Purok ' . $purok;
        }
        $street = trim((string) ($r['StreetName'] ?? ''));
        if ($street !== '') {
            return $street . ' (street)';
        }
        return 'No area recorded';
    }
}

if (!function_exists('sms_log_recipients')) {
    /**
     * @param array $targeted every resident the alert was aimed at (with or without a number)
     * @param array $results  ResidentID => ['status' => sent|failed|invalid, 'detail' => string, 'message_id' => ?string]
     *                        from sendDisasterSMS(); residents missing here had no number.
     */
    function sms_log_recipients(PDO $pdo, $alertId, $logId, array $targeted, array $results): void
    {
        try {
            sms_recipient_log_ensure($pdo);
            $stmt = $pdo->prepare(
                "INSERT INTO sms_recipient_logs
                    (log_id, alert_id, resident_id, resident_name, area_label, contact_number, status, detail, message_id, delivery)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($targeted as $r) {
                $rid = (int) ($r['ResidentID'] ?? 0);
                $phone = trim((string) ($r['ContactNumber'] ?? ''));
                $res = $results[$rid] ?? null;
                $status = $phone === '' ? 'no_number' : ($res['status'] ?? 'failed');
                $name = trim(($r['FirstName'] ?? '') . ' ' . ($r['LastName'] ?? ''));
                $stmt->execute([
                    $logId ?: null,
                    $alertId ?: null,
                    $rid ?: null,
                    $name !== '' ? $name : ('Resident #' . $rid),
                    sms_area_label($r),
                    $phone !== '' ? $phone : null,
                    $status,
                    $status === 'no_number' ? 'No contact number on file' : mb_substr((string) ($res['detail'] ?? ''), 0, 255),
                    $status === 'sent' && !empty($res['message_id']) ? mb_substr((string) $res['message_id'], 0, 100) : null,
                    $status === 'sent' ? 'pending' : null,
                ]);
            }
        } catch (Throwable $e) {
            error_log('[sms_recipient_log] ' . $e->getMessage());
        }
    }
}

if (!function_exists('sms_empty_totals')) {
    /**
     * sent      = accepted by the gateway (confirmed + pending)
     * confirmed = gateway phone reported it sent / delivered
     * pending   = accepted, not confirmed yet (still queued on the phone, or never checked)
     * failed    = rejected by the gateway, invalid number, or the phone failed to send (e.g. no load)
     */
    function sms_empty_totals(): array
    {
        return ['targeted' => 0, 'sent' => 0, 'confirmed' => 0, 'pending' => 0, 'failed' => 0, 'no_number' => 0,
            'queued' => 0, 'processing' => 0, 'retry' => 0, 'cancelled' => 0];
    }

    /**
     * Queue states (not sent yet):  queued = pending + retry, processing, cancelled
     * Result states:                sent (confirmed + pending), failed (+ invalid), no_number
     */
    function sms_add_to_totals(array &$t, string $status, ?string $delivery, int $n = 1): void
    {
        $t['targeted'] += $n;
        if ($status === 'sent') {
            $t['sent'] += $n;
            $t[$delivery === 'confirmed' ? 'confirmed' : 'pending'] += $n;
        } elseif ($status === 'no_number') {
            $t['no_number'] += $n;
        } elseif ($status === 'pending' || $status === 'retry') {
            $t['queued'] += $n;
            if ($status === 'retry') {
                $t['retry'] += $n;
            }
        } elseif ($status === 'processing') {
            $t['processing'] += $n;
        } elseif ($status === 'cancelled') {
            $t['cancelled'] += $n;
        } else {
            $t['failed'] += $n;
        }
    }
}

if (!function_exists('sms_breakdown_counts')) {
    /**
     * Totals per sms_logs.LogID — used to show "targeted · sent · failed · no number"
     * on each SMS Live entry. Returns [] for logs made before this feature existed.
     */
    function sms_breakdown_counts(PDO $pdo, array $logIds): array
    {
        $logIds = array_values(array_filter(array_map('intval', $logIds)));
        if (!$logIds) {
            return [];
        }
        try {
            sms_recipient_log_ensure($pdo);
            $ph = implode(',', array_fill(0, count($logIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT log_id, status, delivery, (message_id IS NOT NULL) AS has_id, COUNT(*) AS n, MAX(detail) AS sample_detail
                   FROM sms_recipient_logs WHERE log_id IN ($ph) GROUP BY log_id, status, delivery, has_id"
            );
            $stmt->execute($logIds);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (int) $row['log_id'];
                $out[$id] = $out[$id] ?? sms_empty_totals() + ['phone_failed' => 0, 'phone_reason' => '', 'checkable' => 0];
                $n = (int) $row['n'];
                sms_add_to_totals($out[$id], $row['status'], $row['delivery'], $n);
                if ($row['delivery'] === 'failed') {
                    // accepted by the gateway, then the gateway phone could not send (no load / no signal)
                    $out[$id]['phone_failed'] += $n;
                    $out[$id]['phone_reason'] = (string) $row['sample_detail'];
                } elseif ($row['status'] === 'sent' && $row['delivery'] !== 'confirmed' && $row['has_id']) {
                    $out[$id]['checkable'] += $n;
                }
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('sms_breakdown_detail')) {
    /** Full breakdown for one broadcast: totals, per-area rows and the residents not reached. */
    function sms_breakdown_detail(PDO $pdo, int $logId): array
    {
        sms_recipient_log_ensure($pdo);
        $stmt = $pdo->prepare(
            "SELECT resident_id, resident_name, area_label, contact_number, status, detail, message_id, delivery, checked_at
               FROM sms_recipient_logs WHERE log_id = ? ORDER BY area_label, resident_name"
        );
        $stmt->execute([$logId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totals = sms_empty_totals();
        $areas = [];
        $notReached = [];
        $pendingRows = [];
        $phoneFailed = 0;
        $phoneReason = '';
        $lastChecked = null;
        $checkable = 0;
        foreach ($rows as $r) {
            $area = $r['area_label'] ?: 'No area recorded';
            $areas[$area] = $areas[$area] ?? ['area' => $area] + sms_empty_totals();
            sms_add_to_totals($areas[$area], $r['status'], $r['delivery']);
            sms_add_to_totals($totals, $r['status'], $r['delivery']);
            if ($r['checked_at'] && (!$lastChecked || $r['checked_at'] > $lastChecked)) {
                $lastChecked = $r['checked_at'];
            }
            if (in_array($r['status'], ['pending', 'retry', 'processing'], true)) {
                $pendingRows[] = [
                    'name' => $r['resident_name'],
                    'area' => $area,
                    'contact' => $r['contact_number'],
                    'reason' => $r['status'] === 'processing' ? 'Sending now' : ($r['status'] === 'retry' ? 'Retrying' : 'In queue'),
                    'detail' => $r['status'] === 'retry' ? $r['detail'] : 'Still in the SMS queue',
                    'status' => 'pending',
                ];
                continue;
            }
            if ($r['status'] === 'sent') {
                if ($r['delivery'] !== 'confirmed') {
                    $pendingRows[] = [
                        'name' => $r['resident_name'],
                        'area' => $area,
                        'contact' => $r['contact_number'],
                        'reason' => 'Awaiting confirmation',
                        'detail' => $r['message_id'] ? 'Accepted by the gateway, not yet confirmed sent by the gateway phone.' : 'Accepted by the gateway (no message ID saved, cannot be checked).',
                        'status' => 'pending',
                    ];
                    if ($r['message_id']) {
                        $checkable++;
                    }
                }
                continue;
            }
            if ($r['delivery'] === 'failed') {
                $phoneFailed++;
                $phoneReason = $r['detail'];
            }
            $notReached[] = [
                'name' => $r['resident_name'],
                'area' => $area,
                'contact' => $r['contact_number'],
                'reason' => $r['status'] === 'no_number' ? 'No contact number'
                    : ($r['status'] === 'cancelled' ? 'Cancelled'
                    : ($r['status'] === 'invalid' ? 'Invalid number'
                    : ($r['delivery'] === 'failed' ? 'Phone could not send' : 'Failed to send'))),
                'detail' => $r['detail'],
                'status' => $r['status'],
            ];
        }
        uasort($areas, static fn($a, $b) => $b['targeted'] <=> $a['targeted']);

        return [
            'totals' => $totals,
            'areas' => array_values($areas),
            'not_reached' => $notReached,
            'pending' => $pendingRows,
            'checkable' => $checkable,
            'phone_failed' => $phoneFailed,
            'phone_reason' => $phoneReason,
            'last_checked' => $lastChecked,
            'has_detail' => (bool) $rows,
        ];
    }
}

if (!function_exists('sms_parse_delivery')) {
    /**
     * Reads a gateway status response defensively (the field can be top-level or under data/message).
     * Returns ['state' => pending|confirmed|failed, 'raw' => string, 'reason' => string].
     */
    function sms_parse_delivery($res): array
    {
        $pick = static function (array $keys) use ($res) {
            foreach ([$res, $res['data'] ?? null, $res['message'] ?? null, $res['result'] ?? null] as $node) {
                if (!is_array($node)) {
                    continue;
                }
                foreach ($keys as $k) {
                    if (isset($node[$k]) && is_scalar($node[$k]) && (string) $node[$k] !== '') {
                        return (string) $node[$k];
                    }
                }
            }
            return '';
        };
        if (!is_array($res)) {
            return ['state' => 'pending', 'raw' => '', 'reason' => ''];
        }
        $raw = strtolower($pick(['deliveryStatus', 'delivery_status', 'status', 'state']));
        $reason = $pick(['failureReason', 'failure_reason', 'errorMessage', 'error_message', 'errorCode', 'error', 'reason']);

        $state = 'pending';
        if (preg_match('/fail|error|reject|undeliver|expire|cancel|insufficient|no.?load/', $raw)) {
            $state = 'failed';
        } elseif (preg_match('/deliver|^sent$|success|complete/', $raw)) {
            $state = 'confirmed';
        }
        return ['state' => $state, 'raw' => $raw, 'reason' => $reason];
    }
}

if (!function_exists('sms_status_url')) {
    /** Status lookup for one message: {api_url}/{messageId} (e.g. …/api/v1/messages/msg_123). */
    function sms_status_url(string $apiUrl, string $messageId): string
    {
        return rtrim($apiUrl, '/') . '/' . rawurlencode($messageId);
    }
}

if (!function_exists('sms_check_delivery')) {
    /**
     * Asks the gateway for the real state of every accepted-but-unconfirmed SMS of a broadcast.
     * A message the gateway phone could not send (no load, no signal, SIM problem) is moved
     * to status=failed so it shows under "Residents not reached".
     */
    function sms_check_delivery(PDO $pdo, int $logId, array $config, int $limit = 150): array
    {
        sms_recipient_log_ensure($pdo);
        $stmt = $pdo->prepare(
            "SELECT id, message_id FROM sms_recipient_logs
              WHERE log_id = ? AND status = 'sent' AND message_id IS NOT NULL
                AND (delivery IS NULL OR delivery = 'pending')
              ORDER BY id LIMIT " . max(1, $limit)
        );
        $stmt->execute([$logId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary = ['checked' => 0, 'confirmed' => 0, 'failed' => 0, 'pending' => 0, 'error' => null];
        $upd = $pdo->prepare("UPDATE sms_recipient_logs SET delivery = ?, status = ?, detail = ?, checked_at = NOW() WHERE id = ?");
        $touch = $pdo->prepare("UPDATE sms_recipient_logs SET checked_at = NOW() WHERE id = ?");

        foreach ($rows as $row) {
            $ch = curl_init(sms_status_url((string) $config['api_url'], (string) $row['message_id']));
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => ['X-API-Key: ' . $config['api_key'], 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => false, // same as the send request (XAMPP often lacks a CA bundle)
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($err || $code === 401 || $code === 403 || $code >= 500) {
                // Gateway unreachable / key refused: stop, nothing is marked failed on our side.
                $summary['error'] = $err ? 'Could not reach the SMS gateway: ' . $err
                    : ($code >= 500 ? 'The SMS gateway had an error (HTTP ' . $code . ').' : 'The SMS gateway refused the status request (HTTP ' . $code . '). Check the API key in SMS Settings.');
                break;
            }
            $summary['checked']++;
            if ($code < 200 || $code >= 300) {
                // e.g. 404: the gateway does not know the message yet / has no status lookup
                $touch->execute([$row['id']]);
                $summary['pending']++;
                continue;
            }

            $d = sms_parse_delivery(json_decode((string) $body, true));
            if ($d['state'] === 'confirmed') {
                $upd->execute(['confirmed', 'sent', 'Confirmed ' . ($d['raw'] ?: 'sent') . ' by the gateway phone', $row['id']]);
                $summary['confirmed']++;
            } elseif ($d['state'] === 'failed') {
                $why = $d['reason'] !== '' ? $d['reason'] : $d['raw'];
                $upd->execute(['failed', 'failed', mb_substr('Gateway phone could not send' . ($why !== '' ? ': ' . $why : '')
                    . ' — check the gateway SIM load / signal', 0, 255), $row['id']]);
                $summary['failed']++;
            } else {
                $touch->execute([$row['id']]);
                $summary['pending']++;
            }
        }

        // Keep sms_logs.sent_count honest: only messages still accepted (not failed) count as sent.
        if ($summary['failed'] > 0) {
            $pdo->prepare(
                "UPDATE sms_logs SET sent_count = (SELECT COUNT(*) FROM sms_recipient_logs WHERE log_id = ? AND status = 'sent') WHERE LogID = ?"
            )->execute([$logId, $logId]);
        }
        return $summary;
    }
}

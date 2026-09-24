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
                id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                log_id         INT          DEFAULT NULL,
                alert_id       INT          DEFAULT NULL,
                resident_id    INT UNSIGNED DEFAULT NULL,
                resident_name  VARCHAR(255) DEFAULT NULL,
                area_label     VARCHAR(255) DEFAULT NULL,
                contact_number VARCHAR(30)  DEFAULT NULL,
                status         ENUM('sent','failed','invalid','no_number') NOT NULL,
                detail         VARCHAR(255) DEFAULT NULL,
                created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_srl_log (log_id),
                KEY idx_srl_alert (alert_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
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
     * @param array $results  ResidentID => ['status' => sent|failed|invalid, 'detail' => string]
     *                        from sendDisasterSMS(); residents missing here had no number.
     */
    function sms_log_recipients(PDO $pdo, $alertId, $logId, array $targeted, array $results): void
    {
        try {
            sms_recipient_log_ensure($pdo);
            $stmt = $pdo->prepare(
                "INSERT INTO sms_recipient_logs
                    (log_id, alert_id, resident_id, resident_name, area_label, contact_number, status, detail)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
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
                ]);
            }
        } catch (Throwable $e) {
            error_log('[sms_recipient_log] ' . $e->getMessage());
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
            $stmt = $pdo->prepare("SELECT log_id, status, COUNT(*) AS n FROM sms_recipient_logs WHERE log_id IN ($ph) GROUP BY log_id, status");
            $stmt->execute($logIds);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (int) $row['log_id'];
                $out[$id] = $out[$id] ?? ['targeted' => 0, 'sent' => 0, 'failed' => 0, 'no_number' => 0];
                $key = $row['status'] === 'invalid' ? 'failed' : $row['status'];
                $out[$id][$key] += (int) $row['n'];
                $out[$id]['targeted'] += (int) $row['n'];
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
            "SELECT resident_id, resident_name, area_label, contact_number, status, detail
               FROM sms_recipient_logs WHERE log_id = ? ORDER BY area_label, resident_name"
        );
        $stmt->execute([$logId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totals = ['targeted' => 0, 'sent' => 0, 'failed' => 0, 'no_number' => 0];
        $areas = [];
        $notReached = [];
        foreach ($rows as $r) {
            $key = $r['status'] === 'invalid' ? 'failed' : $r['status'];
            $area = $r['area_label'] ?: 'No area recorded';
            $areas[$area] = $areas[$area] ?? ['area' => $area, 'targeted' => 0, 'sent' => 0, 'failed' => 0, 'no_number' => 0];
            $areas[$area]['targeted']++;
            $areas[$area][$key]++;
            $totals['targeted']++;
            $totals[$key]++;
            if ($r['status'] !== 'sent') {
                $notReached[] = [
                    'name' => $r['resident_name'],
                    'area' => $area,
                    'contact' => $r['contact_number'],
                    'reason' => $r['status'] === 'no_number' ? 'No contact number'
                        : ($r['status'] === 'invalid' ? 'Invalid number' : 'Send failed'),
                    'detail' => $r['detail'],
                    'status' => $r['status'],
                ];
            }
        }
        uasort($areas, static fn($a, $b) => $b['targeted'] <=> $a['targeted']);

        return ['totals' => $totals, 'areas' => array_values($areas), 'not_reached' => $notReached, 'has_detail' => (bool) $rows];
    }
}

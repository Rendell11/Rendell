<?php
/**
 * staffbpso_common.php
 *
 * Self-migrates the `staff` table with the columns the Staff/BPSO module
 * relies on (EffectiveStart/EffectiveEnd track an assignment's active
 * window — set on registration, and stamped on "End Term" instead of
 * hard-deleting the row so Yearly History records stay intact — the same
 * audit-trail shape as officials/backend/officials_common.php: who
 * recorded the assignment, and who ended it, when, and why).
 * Same "ALTER TABLE ... try/catch, ignore if it already exists" pattern
 * used by officials/backend/officials_common.php. Safe to call every request.
 */

// Late / Early Out / Overtime compare PHP time against MySQL NOW(), so both
// must be on Philippine time (XAMPP's php.ini default is Europe/Berlin).
date_default_timezone_set('Asia/Manila');
if (isset($pdo) && $pdo instanceof PDO) {
    try { $pdo->exec("SET time_zone = '+08:00'"); } catch (Throwable $e) { /* keep server default */ }
}

/**
 * CSRF protection for every Staff/BPSO form and action. Pages put
 * staffbpso_csrf_token() in a hidden "csrf_token" field (or on the delete
 * link); backends call staffbpso_csrf_verify() before changing anything.
 * A request from another site cannot know the token, so it is rejected.
 */
if (!function_exists('staffbpso_csrf_token')) {
    function staffbpso_csrf_token(): string {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        if (empty($_SESSION['staffbpso_csrf'])) $_SESSION['staffbpso_csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['staffbpso_csrf'];
    }
}
if (!function_exists('staffbpso_csrf_verify')) {
    function staffbpso_csrf_verify(): void {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $sent = (string)($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');
        $expected = (string)($_SESSION['staffbpso_csrf'] ?? '');
        if ($sent !== '' && $expected !== '' && hash_equals($expected, $sent)) return;
        http_response_code(419);
        $back = htmlspecialchars($_SERVER['HTTP_REFERER'] ?? '/CAPS/admin/staffbpso/frontend/staff.php', ENT_QUOTES, 'UTF-8');
        exit('<!doctype html><meta charset="utf-8"><title>Request expired</title><div style="font-family:sans-serif;max-width:460px;margin:80px auto;padding:24px;border:1px solid #e2e8f0;border-radius:16px">'
            .'<h2 style="margin:0 0 8px">Request expired or invalid</h2><p style="color:#475569">For your security this action was not saved. Please go back, refresh the page, and try again.</p>'
            .'<a href="'.$back.'" style="display:inline-block;margin-top:8px;padding:10px 16px;background:#0f172a;color:#fff;border-radius:10px;text-decoration:none">Go back</a></div>');
    }
}

if (!function_exists('staffbpso_migrate_schema')) {
    function staffbpso_migrate_schema(PDO $pdo): void {
        static $done = false;
        if ($done) return; // once per request is enough
        $done = true;

        $cols = [
            "EffectiveStart DATE NULL DEFAULT NULL",
            "EffectiveEnd DATE NULL DEFAULT NULL",
            // Who registered this personnel record, and when (CreatedAt already tracks "when").
            "RecordedBy VARCHAR(255) NULL DEFAULT NULL",
            "RecordedByID VARCHAR(50) NULL DEFAULT NULL",
            // Populated only when the assignment is closed out via End Term.
            "EndReason VARCHAR(255) NULL DEFAULT NULL",
            "EndedBy VARCHAR(255) NULL DEFAULT NULL",
            "EndedByID VARCHAR(50) NULL DEFAULT NULL",
            "EndedAt DATETIME NULL DEFAULT NULL",
            // Regular staff work schedule, used by Staff Attendance (late / early out / overtime).
            "WorkStart TIME NOT NULL DEFAULT '08:00:00'",
            "WorkEnd TIME NOT NULL DEFAULT '17:00:00'",
            "WorkDays VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5'",
            // Block (e.g. relocated): record kept, no login / duty / attendance until unblocked.
            "IsBlocked TINYINT(1) NOT NULL DEFAULT 0",
            "BlockReason VARCHAR(255) NULL DEFAULT NULL",
            "BlockedBy VARCHAR(255) NULL DEFAULT NULL",
            "BlockedByID VARCHAR(50) NULL DEFAULT NULL",
            "BlockedAt DATETIME NULL DEFAULT NULL",
            "UnblockedBy VARCHAR(255) NULL DEFAULT NULL",
            "UnblockedAt DATETIME NULL DEFAULT NULL",
        ];

        foreach ($cols as $def) {
            try { $pdo->exec("ALTER TABLE staff ADD COLUMN $def"); }
            catch (PDOException $e) { /* column already exists — ignore */ }
        }

        // Earlier positions of a staff record. When Edit changes the Position,
        // the old position is closed here (ended, with reason / who / when) and
        // the staff row continues as the new position — same EmployeeID and login.
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS staff_position_history (
                id INT NOT NULL AUTO_INCREMENT,
                EmployeeID VARCHAR(50) NOT NULL,
                ResidentID VARCHAR(20) NULL DEFAULT NULL,
                Name VARCHAR(255) NULL DEFAULT NULL,
                Position VARCHAR(100) NULL DEFAULT NULL,
                StartDate DATE NULL DEFAULT NULL,
                EndDate DATE NULL DEFAULT NULL,
                EndReason VARCHAR(255) NULL DEFAULT NULL,
                RecordedBy VARCHAR(255) NULL DEFAULT NULL,
                RecordedAt DATETIME NULL DEFAULT NULL,
                EndedBy VARCHAR(255) NULL DEFAULT NULL,
                EndedByID VARCHAR(50) NULL DEFAULT NULL,
                EndedAt DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_sph_employee (EmployeeID),
                KEY idx_sph_resident (ResidentID)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (PDOException $e) { /* already exists — ignore */ }
    }
}

/**
 * Latest household relocation of a resident (Households → Remove → "Household relocated"),
 * or null. Only relocations on/after $since (the personnel's start date) count.
 * Returns: date, old_address, new_address, moved_out (now living in another barangay).
 */
if (!function_exists('staffbpso_relocation')) {
    function staffbpso_relocation(PDO $pdo, $residentId, ?string $since = null): ?array {
        static $brgy = null;
        if (!$residentId) return null;
        try {
            $q = $pdo->prepare("SELECT h.CreatedAt, h.OldAddress, h.NewAddress FROM household_history h
                WHERE h.ActionType = 'RELOCATED'
                  AND h.SurveyID IN (SELECT m.SurveyID FROM household_survey_members m WHERE m.ResidentID = ?
                                     UNION SELECT s.SurveyID FROM household_survey s WHERE s.ResidentID = ?)
                ORDER BY h.CreatedAt DESC, h.HistoryID DESC LIMIT 1");
            $q->execute([$residentId, $residentId]);
            $h = $q->fetch(PDO::FETCH_ASSOC);
            if (!$h || ($since && substr((string)$h['CreatedAt'], 0, 10) < $since)) return null;
            if ($brgy === null) {
                $brgy = $pdo->query("SELECT psgc_barangay_code, barangay_name FROM barangay_profile
                    ORDER BY (psgc_barangay_code IS NOT NULL AND psgc_barangay_code <> '') DESC, (id = 1) DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
            }
            $r = $pdo->prepare("SELECT PSGCBarangayCode, BarangayName FROM residents WHERE ResidentID = ?");
            $r->execute([$residentId]);
            $r = $r->fetch(PDO::FETCH_ASSOC) ?: [];
            $code = trim((string)($r['PSGCBarangayCode'] ?? '')); $own = trim((string)($brgy['psgc_barangay_code'] ?? ''));
            $key = fn($s) => preg_replace('/[^a-z0-9]/', '', strtolower(str_replace(['ñ', 'Ñ'], 'n', (string)$s)));
            if ($code !== '' && $own !== '') $out = $code !== $own;
            else $out = trim((string)($r['BarangayName'] ?? '')) !== '' && trim((string)($brgy['barangay_name'] ?? '')) !== ''
                && $key($r['BarangayName']) !== $key($brgy['barangay_name']);
            return ['date' => (string)$h['CreatedAt'], 'old_address' => (string)$h['OldAddress'], 'new_address' => (string)$h['NewAddress'], 'moved_out' => $out];
        } catch (Throwable $e) { return null; }
    }
}

/**
 * Unique auto-generated work email: firstname.lastname@barangay.local,
 * then firstname.lastname2@…, 3… if already used by another staff record.
 */
if (!function_exists('staffbpso_work_email')) {
    function staffbpso_work_email(PDO $pdo, string $first, string $last): string {
        $base = strtolower(preg_replace('/[^a-z0-9]/i', '', $first)).'.'.strtolower(preg_replace('/[^a-z0-9]/i', '', $last));
        $chk = $pdo->prepare('SELECT 1 FROM staff WHERE WorkEmail = ? LIMIT 1');
        for ($n = 1; ; $n++) {
            $email = $base.($n > 1 ? $n : '').'@barangay.local';
            $chk->execute([$email]);
            if (!$chk->fetch()) return $email;
        }
    }
}

/**
 * Self-migrates the columns/table the Today's Duty Schedule monitoring
 * (Time In / Deploy / Return / Time Out) relies on. `tanod_schedules`
 * already ships with time_in/time_out/status/area/notes from the base
 * schema — this only adds who-recorded-it columns plus the Early Out
 * fields, and a child table for deployment history since one duty day can
 * have multiple Deploy → Return cycles. Same idempotent
 * "ALTER TABLE ... try/catch" pattern as staffbpso_migrate_schema() above.
 * Safe to call every request.
 */
if (!function_exists('staffbpso_duty_migrate_schema')) {
    function staffbpso_duty_migrate_schema(PDO $pdo): void {
        static $done = false;
        if ($done) return;
        $done = true;

        $cols = [
            "time_in_by VARCHAR(255) NULL DEFAULT NULL",
            "time_in_by_id VARCHAR(50) NULL DEFAULT NULL",
            "time_out_by VARCHAR(255) NULL DEFAULT NULL",
            "time_out_by_id VARCHAR(50) NULL DEFAULT NULL",
            "is_early_out TINYINT(1) NOT NULL DEFAULT 0",
            "early_out_reason VARCHAR(255) NULL DEFAULT NULL",
            // Mark as Absent — final for that duty day, no Time In/Out needed.
            "absent_reason VARCHAR(255) NULL DEFAULT NULL",
            "absent_at DATETIME NULL DEFAULT NULL",
            "absent_by VARCHAR(255) NULL DEFAULT NULL",
            "absent_by_id VARCHAR(50) NULL DEFAULT NULL",
        ];
        foreach ($cols as $def) {
            try { $pdo->exec("ALTER TABLE tanod_schedules ADD COLUMN $def"); }
            catch (PDOException $e) { /* column already exists — ignore */ }
        }

        // One row per Deploy -> Return cycle. A duty day can have several,
        // so these can't live as single columns on tanod_schedules itself.
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS tanod_deployments (
                id INT NOT NULL AUTO_INCREMENT,
                schedule_id INT NOT NULL,
                location VARCHAR(255) NOT NULL,
                reason VARCHAR(255) NOT NULL,
                deployed_at DATETIME NOT NULL,
                deployed_by VARCHAR(255) NULL DEFAULT NULL,
                deployed_by_id VARCHAR(50) NULL DEFAULT NULL,
                returned_at DATETIME NULL DEFAULT NULL,
                returned_by VARCHAR(255) NULL DEFAULT NULL,
                returned_by_id VARCHAR(50) NULL DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_tanod_deployments_schedule (schedule_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (PDOException $e) { /* already exists — ignore */ }

        // Regular Barangay Staff attendance: one row per staff per day. Same
        // column names as tanod_schedules so Duty Logs can reuse duty_decorate_log().
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS staff_attendance (
                id INT NOT NULL AUTO_INCREMENT,
                EmployeeID VARCHAR(50) NOT NULL,
                attendance_date DATE NOT NULL,
                shift_start TIME NOT NULL,
                shift_end TIME NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'Scheduled',
                time_in DATETIME NULL DEFAULT NULL,
                time_in_by VARCHAR(255) NULL DEFAULT NULL,
                time_in_by_id VARCHAR(50) NULL DEFAULT NULL,
                time_out DATETIME NULL DEFAULT NULL,
                time_out_by VARCHAR(255) NULL DEFAULT NULL,
                time_out_by_id VARCHAR(50) NULL DEFAULT NULL,
                is_early_out TINYINT(1) NOT NULL DEFAULT 0,
                early_out_reason VARCHAR(255) NULL DEFAULT NULL,
                absent_reason VARCHAR(255) NULL DEFAULT NULL,
                absent_at DATETIME NULL DEFAULT NULL,
                absent_by VARCHAR(255) NULL DEFAULT NULL,
                absent_by_id VARCHAR(50) NULL DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_staff_attendance_day (EmployeeID, attendance_date),
                KEY idx_staff_attendance_date (attendance_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (PDOException $e) { /* already exists — ignore */ }
    }
}

/**
 * Ends every active Staff/BPSO assignment of deceased residents (reason
 * "Deceased"). Called right when a resident is marked deceased, and on the
 * Staff/BPSO pages as a safety net for anyone marked deceased elsewhere.
 * $residentId = null checks all residents. Returns how many were ended.
 */
if (!function_exists('staffbpso_end_deceased')) {
    function staffbpso_end_deceased(PDO $pdo, ?int $residentId = null, ?string $actorName = null): int {
        try {
            staffbpso_migrate_schema($pdo);
            $sql = "SELECT s.EmployeeID, s.Name, s.Position FROM staff s JOIN residents r ON r.ResidentID = s.ResidentID
                    WHERE r.IsDeceased = 1 AND s.EffectiveEnd IS NULL".($residentId ? " AND r.ResidentID = ?" : "");
            $st = $pdo->prepare($sql);
            $st->execute($residentId ? [$residentId] : []);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) return 0;
            $actor = $actorName ?: staffbpso_actor_name();
            $upd = $pdo->prepare("UPDATE staff SET EffectiveEnd = CURDATE(), EndReason = 'Deceased', EndedBy = ?, EndedByID = ?, EndedAt = NOW() WHERE EmployeeID = ? AND EffectiveEnd IS NULL");
            if (file_exists(__DIR__.'/../../activity_log_helper.php')) require_once __DIR__.'/../../activity_log_helper.php';
            foreach ($rows as $r) {
                $upd->execute([$actor, staffbpso_actor_id(), $r['EmployeeID']]);
                if (function_exists('log_activity')) log_activity('Staff', 'End Term', 'Ended personnel assignment EmployeeID: '.$r['EmployeeID'].' ('.$r['Position'].'). Reason: Deceased (resident marked deceased).');
            }
            return count($rows);
        } catch (Throwable $e) { error_log('[CAPS Staff deceased] '.$e->getMessage()); return 0; }
    }
}

/** Best-effort display name for the currently logged-in Admin/Staff account. */
if (!function_exists('staffbpso_actor_name')) {
    function staffbpso_actor_name(): string {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        return $_SESSION['full_name'] ?? $_SESSION['user_name'] ?? $_SESSION['Fullname']
            ?? $_SESSION['staff_name'] ?? $_SESSION['admin_name'] ?? $_SESSION['username'] ?? 'System';
    }
}

/** Best-effort account identifier for the currently logged-in Admin/Staff account. */
if (!function_exists('staffbpso_actor_id')) {
    function staffbpso_actor_id(): ?string {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $id = $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? $_SESSION['employee_id'] ?? null;
        return $id !== null ? (string)$id : null;
    }
}

/**
 * The scheduled end date+time for a tanod_schedules row, accounting for
 * overnight/graveyard shifts (shift_end <= shift_start means it ends the
 * next calendar day). Used to tell whether a Time Out is happening before
 * or after the shift was actually supposed to end.
 */
if (!function_exists('tanod_scheduled_end_datetime')) {
    function tanod_scheduled_end_datetime(string $scheduleDate, string $shiftStart, string $shiftEnd): DateTime {
        $end = new DateTime($scheduleDate.' '.$shiftEnd);
        if ($shiftEnd <= $shiftStart) $end->modify('+1 day');
        return $end;
    }
}

/** Display label + badge classes for a duty row's status (Today's Duty, Duty Logs, Daily Report). */
if (!function_exists('duty_status_meta')) {
    function duty_status_meta(string $status): array {
        switch ($status) {
            case 'On Duty':   return ['On Duty', 'bg-blue-50 text-blue-700'];
            case 'Deployed':  return ['Deployed', 'bg-amber-50 text-amber-700'];
            case 'Completed': return ['Completed', 'bg-emerald-50 text-emerald-700'];
            case 'Early Out': return ['Early Out', 'bg-rose-50 text-rose-700'];
            case 'Absent':    return ['Absent', 'bg-red-100 text-red-700'];
            default:          return ['Not Timed In', 'bg-slate-100 text-slate-500'];
        }
    }
}

/** Full date + exact time of a recorded action, e.g. "Sep 23, 2026 8:03:12 AM". */
if (!function_exists('duty_fmt_dt')) {
    function duty_fmt_dt($t): string { return $t ? date('M j, Y g:i:s A', strtotime($t)) : '—'; }
}

/** Minutes as "1 hr 30 mins", "45 mins", "2 hrs". */
if (!function_exists('duty_fmt_minutes')) {
    function duty_fmt_minutes(int $m): string {
        $h = intdiv($m, 60); $r = $m % 60; $parts = [];
        if ($h) $parts[] = $h.' '.($h === 1 ? 'hr' : 'hrs');
        if ($r || !$h) $parts[] = $r.' '.($r === 1 ? 'min' : 'mins');
        return implode(' ', $parts);
    }
}

/** Preset reasons in the Absent / Early Out dropdowns; anything else was typed under "Other". */
if (!function_exists('duty_reason_options')) {
    function duty_reason_options(string $kind): array {
        return $kind === 'early_out'
            ? ['Not Feeling Well', 'Family Emergency', 'Personal Reason', 'Official Business / Errand']
            : ['Sick', 'Family Emergency', 'Personal Reason', 'No Show / No Notice'];
    }
}

/** Positions that follow the BPSO/Tanod duty flow (Deploy/Return) instead of Staff attendance. */
if (!function_exists('staffbpso_tanod_positions')) {
    function staffbpso_tanod_positions(): array { return ['tanod', 'barangay tanod', 'barangay tanod chief', 'bpso']; }
}

/** Weekdays (1 = Mon … 7 = Sun) a regular staff member is expected to report. */
if (!function_exists('staff_work_days')) {
    function staff_work_days(array $s): array {
        $days = array_filter(array_map('intval', explode(',', (string)($s['WorkDays'] ?? '1,2,3,4,5'))), function($d) { return $d >= 1 && $d <= 7; });
        return array_values(array_unique($days));
    }
}

/**
 * Adds the derived fields Duty Logs / Daily Report / Analytics need to one
 * duty row. Works for both tanod_schedules and staff_attendance rows since
 * they share column names (schedule_date, shift_start, shift_end, time_in,
 * time_out, status, is_early_out, early_out_reason, absent_*, *_by).
 */
if (!function_exists('duty_decorate_log')) {
    function duty_decorate_log(array $log): array {
        $start = new DateTime($log['schedule_date'].' '.$log['shift_start']);
        $end = tanod_scheduled_end_datetime($log['schedule_date'], $log['shift_start'], $log['shift_end']);
        $log['_scheduled_start'] = $start;
        $log['_scheduled_end'] = $end;
        $log['_is_overnight'] = $log['shift_end'] <= $log['shift_start'];

        $log['_is_late'] = false; $log['_late_minutes'] = 0; $log['_early_in_minutes'] = 0;
        if (!empty($log['time_in'])) {
            $diff = strtotime($log['time_in']) - $start->getTimestamp();
            if ($diff > 0) { $log['_is_late'] = true; $log['_late_minutes'] = max(1, (int)floor($diff / 60)); }
            elseif ($diff <= -60) $log['_early_in_minutes'] = (int)floor(-$diff / 60); // came in before the scheduled Time In
        }

        // Compared against the scheduled Time Out: short = Early Out, past = Overtime.
        $log['_early_minutes'] = 0; $log['_overtime_minutes'] = 0;
        if (!empty($log['time_out'])) {
            $diff = strtotime($log['time_out']) - $end->getTimestamp();
            if ($diff < 0 && !empty($log['is_early_out'])) $log['_early_minutes'] = max(1, (int)ceil(-$diff / 60));
            elseif ($diff >= 60) $log['_overtime_minutes'] = (int)floor($diff / 60);
        }
        $log['_worked_minutes'] = (!empty($log['time_in']) && !empty($log['time_out']))
            ? max(0, (int)floor((strtotime($log['time_out']) - strtotime($log['time_in'])) / 60)) : 0;

        $log['_deployments'] = $log['_deployments'] ?? [];
        $log['_deployment_count'] = count($log['_deployments']);

        $events = [];
        if (!empty($log['absent_at'])) {
            $events[] = ['time' => $log['absent_at'], 'type' => 'absent', 'reason' => $log['absent_reason'], 'by' => $log['absent_by']];
        }
        if (!empty($log['time_in'])) {
            $events[] = ['time' => $log['time_in'], 'type' => 'time_in', 'late' => $log['_is_late'], 'late_minutes' => $log['_late_minutes'],
                         'early_in_minutes' => $log['_early_in_minutes'], 'scheduled' => $start->format('g:i A'), 'by' => $log['time_in_by']];
        }
        foreach ($log['_deployments'] as $i => $d) {
            $events[] = ['time' => $d['deployed_at'], 'type' => 'deploy', 'seq' => $i + 1, 'location' => $d['location'], 'reason' => $d['reason'], 'by' => $d['deployed_by']];
            if (!empty($d['returned_at'])) {
                $events[] = ['time' => $d['returned_at'], 'type' => 'return', 'seq' => $i + 1, 'location' => $d['location'], 'by' => $d['returned_by']];
            }
        }
        if (!empty($log['time_out'])) {
            $events[] = ['time' => $log['time_out'], 'type' => 'time_out', 'early' => !empty($log['is_early_out']), 'reason' => $log['early_out_reason'],
                         'early_minutes' => $log['_early_minutes'], 'overtime_minutes' => $log['_overtime_minutes'], 'by' => $log['time_out_by']];
        }
        usort($events, function($a, $b) { return strtotime($a['time']) <=> strtotime($b['time']); });
        $log['_events'] = $events;
        return $log;
    }
}

/** Summary counts + flattened chronological events for a list of decorated rows. */
if (!function_exists('duty_bundle')) {
    function duty_bundle(array $logs): array {
        $summary = ['scheduled' => count($logs), 'present' => 0, 'late' => 0, 'absent' => 0, 'early_out' => 0, 'completed' => 0,
                    'not_timed_in' => 0, 'on_duty' => 0, 'deployments' => 0, 'overtime' => 0, 'overtime_minutes' => 0];
        $allEvents = [];
        foreach ($logs as $log) {
            foreach ($log['_events'] as $ev) $allEvents[] = $ev + ['name' => $log['Name']];
            if (!empty($log['time_in'])) $summary['present']++;
            if ($log['_is_late']) $summary['late']++;
            if ($log['status'] === 'Absent') $summary['absent']++;
            if ($log['status'] === 'Early Out') $summary['early_out']++;
            if ($log['status'] === 'Completed') $summary['completed']++;
            if ($log['status'] === 'Scheduled') $summary['not_timed_in']++;
            if (in_array($log['status'], ['On Duty', 'Deployed'], true)) $summary['on_duty']++;
            if ($log['_overtime_minutes'] > 0) { $summary['overtime']++; $summary['overtime_minutes'] += $log['_overtime_minutes']; }
            $summary['deployments'] += $log['_deployment_count'];
        }
        usort($allEvents, function($a, $b) { return strtotime($a['time']) <=> strtotime($b['time']); });
        return ['logs' => $logs, 'summary' => $summary, 'events' => $allEvents];
    }
}

/**
 * BPSO/Tanod duty for one day, shared by Duty Logs and the Daily Report.
 * Returns ['logs', 'summary', 'events']. Throws on DB error.
 */
if (!function_exists('duty_logs_for_date')) {
    function duty_logs_for_date(PDO $pdo, string $dateStr): array {
        // GROUP BY ts.id: ResidentID is not unique in `staff`, see tanod_management.php.
        $st = $pdo->prepare("SELECT ts.*, MIN(s.Name) AS Name, MIN(s.Position) AS Position, MIN(s.EmployeeID) AS EmployeeID
            FROM tanod_schedules ts
            JOIN staff s ON s.ResidentID = ts.ResidentID
            WHERE ts.schedule_date = ?
            GROUP BY ts.id
            ORDER BY ts.shift_start, Name");
        $st->execute([$dateStr]);
        $logs = $st->fetchAll(PDO::FETCH_ASSOC);

        $deploymentsBySchedule = [];
        if ($logs) {
            $ids = array_column($logs, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $dst = $pdo->prepare("SELECT * FROM tanod_deployments WHERE schedule_id IN ($placeholders) ORDER BY deployed_at, id");
            $dst->execute($ids);
            foreach ($dst->fetchAll(PDO::FETCH_ASSOC) as $d) $deploymentsBySchedule[$d['schedule_id']][] = $d;
        }
        foreach ($logs as &$log) {
            $log['_deployments'] = $deploymentsBySchedule[$log['id']] ?? [];
            $log = duty_decorate_log($log);
        }
        unset($log);
        return duty_bundle($logs);
    }
}

/**
 * Regular Barangay Staff attendance for one day. Recorded rows come from
 * staff_attendance; staff expected that day (per WorkDays) with no record yet
 * are listed as 'Scheduled' using their current WorkStart/WorkEnd.
 * Same shape as duty_logs_for_date(). Throws on DB error.
 */
if (!function_exists('staff_logs_for_date')) {
    function staff_logs_for_date(PDO $pdo, string $dateStr): array {
        $st = $pdo->prepare("SELECT sa.*, sa.attendance_date AS schedule_date, s.Name, s.Position
            FROM staff_attendance sa JOIN staff s ON s.EmployeeID = sa.EmployeeID
            WHERE sa.attendance_date = ?");
        $st->execute([$dateStr]);
        $logs = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $logs[$r['EmployeeID']] = $r;

        if ($dateStr <= date('Y-m-d')) {
            staffbpso_migrate_schema($pdo);
            $weekday = (int)date('N', strtotime($dateStr));
            $in = implode(',', array_fill(0, count(staffbpso_tanod_positions()), '?'));
            $q = $pdo->prepare("SELECT * FROM staff
                WHERE LOWER(COALESCE(Position,'')) NOT IN ($in)
                  AND (EffectiveStart IS NULL OR EffectiveStart <= ?)
                  AND (EffectiveEnd IS NULL OR EffectiveEnd > ?)
                  AND COALESCE(IsBlocked, 0) = 0
                  AND DATE(CreatedAt) <= ?");
            $q->execute(array_merge(staffbpso_tanod_positions(), [$dateStr, $dateStr, $dateStr]));
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $s) {
                if (isset($logs[$s['EmployeeID']]) || !in_array($weekday, staff_work_days($s), true)) continue;
                $logs[$s['EmployeeID']] = [
                    'id' => 0, 'EmployeeID' => $s['EmployeeID'], 'Name' => $s['Name'], 'Position' => $s['Position'],
                    'schedule_date' => $dateStr, 'attendance_date' => $dateStr,
                    'shift_start' => $s['WorkStart'] ?? '08:00:00', 'shift_end' => $s['WorkEnd'] ?? '17:00:00',
                    'status' => 'Scheduled', 'time_in' => null, 'time_in_by' => null, 'time_out' => null, 'time_out_by' => null,
                    'is_early_out' => 0, 'early_out_reason' => null, 'absent_reason' => null, 'absent_at' => null, 'absent_by' => null,
                ];
            }
        }
        $logs = array_map('duty_decorate_log', array_values($logs));
        usort($logs, function($a, $b) { return [$a['shift_start'], $a['Name']] <=> [$b['shift_start'], $b['Name']]; });
        return duty_bundle($logs);
    }
}

/** Label + detail text for one duty event (used by Duty Logs and the Daily Report). */
if (!function_exists('duty_event_text')) {
    function duty_event_text(array $ev): array {
        switch ($ev['type']) {
            case 'absent':   return ['Marked Absent', 'Reason: '.($ev['reason'] ?: '—')];
            case 'time_in':
                $sch = !empty($ev['scheduled']) ? ' (scheduled '.$ev['scheduled'].')' : '';
                if ($ev['late']) return ['Time In (Late)', 'Late by '.duty_fmt_minutes($ev['late_minutes']).$sch];
                if (!empty($ev['early_in_minutes'])) return ['Time In (Early)', duty_fmt_minutes($ev['early_in_minutes']).' before scheduled time'.$sch];
                return ['Time In', 'On time'.$sch];
            case 'deploy':   return ['Deployed #'.$ev['seq'], 'Location: '.$ev['location'].' · Reason: '.$ev['reason']];
            case 'return':   return ['Returned #'.$ev['seq'], 'From: '.$ev['location']];
            case 'time_out':
                if ($ev['early']) return ['Time Out (Early Out)', ($ev['early_minutes'] ? duty_fmt_minutes($ev['early_minutes']).' early · ' : '').'Reason: '.($ev['reason'] ?: '—')];
                if ($ev['overtime_minutes'] > 0) return ['Time Out (Overtime)', duty_fmt_minutes($ev['overtime_minutes']).' overtime'];
                return ['Time Out', 'Completed duty'];
        }
        return [$ev['type'], ''];
    }
}

/**
 * Derives a human status for one `staff` row (a Staff/BPSO assignment term).
 * Returns 'Current' or 'Ended' — staff/BPSO assignments are open-ended
 * (no planned future EffectiveEnd like officials' TermEnd), so unlike
 * official_term_status() there's no "Ended Early" vs "Completed Term"
 * distinction to make; EndReason/EndedBy explain the how/who when Ended.
 */
if (!function_exists('staff_term_status')) {
    function staff_term_status(array $s): string {
        if (!empty($s['EffectiveEnd']) && $s['EffectiveEnd'] <= date('Y-m-d')) return 'Ended';
        return 'Current';
    }
}

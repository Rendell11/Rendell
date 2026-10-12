<?php
require_once __DIR__.'/../../db.php';
require_once __DIR__.'/../../auth_check.php';
require_once __DIR__.'/staffbpso_common.php'; // Asia/Manila time for date checks
staffbpso_migrate_schema($pdo);
staffbpso_csrf_verify(); // add (POST) and delete (link) both carry the token

function back($msg, $week = null, $status = null) {
    $week = $week ?: ($_POST['week'] ?? $_GET['week'] ?? date('Y-m-d'));
    // Anything other than a created/deleted confirmation is shown as an error.
    $status = $status ?: (in_array($msg, ['Duty schedule created.', 'Schedule deleted.'], true) ? 'success' : 'error');
    header('Location:/CAPS/admin/staffbpso/frontend/tanod_management.php?week='.urlencode($week).'&status='.$status.'&msg='.urlencode($msg));
    exit;
}

try {
    if (isset($_GET['delete_id'])) {
        // Past dates and duties already acted on (timed in / absent / completed) are
        // part of the duty record and can no longer be deleted.
        $sc = $pdo->prepare('SELECT schedule_date, status FROM tanod_schedules WHERE id=?');
        $sc->execute([(int)$_GET['delete_id']]);
        $row = $sc->fetch();
        if (!$row) back('Schedule not found.', null, 'error');
        if ($row['schedule_date'] < date('Y-m-d')) back('Past duty schedules can no longer be changed or deleted.', null, 'error');
        if (($row['status'] ?? 'Scheduled') !== 'Scheduled') back('This duty has already started or been recorded, so it can no longer be deleted.', null, 'error');
        $pdo->prepare("DELETE FROM tanod_schedules WHERE id=? AND status='Scheduled'")->execute([(int)$_GET['delete_id']]);
        back('Schedule deleted.');
    }

    if (isset($_POST['create_schedule'])) {
        $rid = (int)($_POST['ResidentID'] ?? 0);
        $d = trim($_POST['schedule_date'] ?? '');
        $a = trim($_POST['shift_start'] ?? '');
        $b = trim($_POST['shift_end'] ?? '');
        $week = $_POST['week'] ?? $d;

        if (!$rid || !$d || !$a || !$b) back('Please complete all duty schedule fields.', $week);

        $dateObj = DateTime::createFromFormat('Y-m-d', $d);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $d) back('Invalid duty date.', $week);

        $todayObj = new DateTime('today');
        if ($dateObj < $todayObj) back('Past dates cannot be scheduled.', $week);

        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $a) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $b)) {
            back('Invalid duty time.', $week);
        }

        if ($a === $b) back('Duty start and end time cannot be the same.', $week);

        $chk = $pdo->prepare("SELECT 1 FROM staff WHERE ResidentID=? AND LOWER(COALESCE(Position,'')) IN ('tanod','barangay tanod','barangay tanod chief','bpso') AND (EffectiveEnd IS NULL OR EffectiveEnd > CURDATE()) AND COALESCE(IsBlocked,0)=0");
        $chk->execute([$rid]);
        if (!$chk->fetch()) back('Selected person is not an active BPSO/Tanod (ended or blocked).', $week);

        // Real time-overlap check instead of "one duty per date": the same personnel can
        // have several duties on a day as long as the times don't overlap (overnight
        // shifts included). Duties marked Absent or already ended early/completed
        // before the new one starts don't block.
        $newStart = new DateTime($d.' '.$a);
        $newEnd = tanod_scheduled_end_datetime($d, $a, $b);
        $near = $pdo->prepare("SELECT schedule_date, shift_start, shift_end, status, time_out FROM tanod_schedules
            WHERE ResidentID=? AND schedule_date BETWEEN DATE_SUB(?, INTERVAL 1 DAY) AND DATE_ADD(?, INTERVAL 1 DAY) AND status <> 'Absent'");
        $near->execute([$rid, $d, $d]);
        foreach ($near->fetchAll() as $o) {
            $oStart = new DateTime($o['schedule_date'].' '.$o['shift_start']);
            $oEnd = !empty($o['time_out']) ? new DateTime($o['time_out']) : tanod_scheduled_end_datetime($o['schedule_date'], $o['shift_start'], $o['shift_end']);
            if ($newStart < $oEnd && $newEnd > $oStart) {
                back('Selected personnel already has a duty from '.$oStart->format('M j, g:i A').' to '.$oEnd->format('M j, g:i A').' that overlaps this time.', $week, 'error');
            }
        }

        $pdo->prepare("INSERT INTO tanod_schedules(ResidentID,schedule_date,shift_start,shift_end,required_count,status) VALUES(?,?,?,?,1,'Scheduled')")
            ->execute([$rid, $d, $a, $b]);

        require_once __DIR__.'/../../activity_log_helper.php';
        log_activity('BPSO','Schedule Created','Created BPSO duty schedule for ResidentID '.$rid.' on '.$d);
        back('Duty schedule created.', $week);
    }

    back('No action was selected.');
} catch (Throwable $e) {
    back($e->getMessage());
}

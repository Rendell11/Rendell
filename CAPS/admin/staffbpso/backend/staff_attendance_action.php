<?php
/**
 * Regular Barangay Staff attendance: Time In / Time Out (Early Out) / Mark as Absent.
 * No Deploy/Return here — that flow is BPSO/Tanod only (duty_action.php).
 * The staff_attendance row is created on the first action of the day, with the
 * staff member's WorkStart/WorkEnd snapshotted so later schedule edits don't
 * rewrite past late/early-out/overtime figures.
 */
require_once __DIR__.'/../../db.php';
require_once __DIR__.'/../../auth_check.php';
require_once __DIR__.'/staffbpso_common.php';
staffbpso_migrate_schema($pdo);
staffbpso_duty_migrate_schema($pdo);

function back($msg, $status = 'success') {
    header('Location:/CAPS/admin/staffbpso/frontend/today_attendance.php?status='.urlencode($status).'&msg='.urlencode($msg).'#staff-attendance');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') back('No action was selected.', 'error');
staffbpso_csrf_verify();

$action = trim($_POST['att_action'] ?? '');
$employeeId = trim($_POST['employee_id'] ?? '');
$date = trim($_POST['attendance_date'] ?? date('Y-m-d'));
if ($employeeId === '') back('Staff record not found.', 'error');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) back('Invalid attendance date.', 'error');

try {
    $st = $pdo->prepare('SELECT * FROM staff WHERE EmployeeID = ? LIMIT 1');
    $st->execute([$employeeId]);
    $staff = $st->fetch();
    if (!$staff) back('Staff record not found.', 'error');
    if (!empty($staff['IsBlocked'])) back('This staff is blocked. Unblock them in Staff and BPSO first.', 'error');
    if (in_array(strtolower((string)$staff['Position']), staffbpso_tanod_positions(), true)) back('BPSO/Tanod attendance is recorded in Today\'s BPSO Attendance.', 'error');

    $rs = $pdo->prepare('SELECT * FROM staff_attendance WHERE EmployeeID = ? AND attendance_date = ? LIMIT 1');
    $rs->execute([$employeeId, $date]);
    $row = $rs->fetch() ?: null;
    $status = $row['status'] ?? 'Scheduled';
    $shiftStart = $row['shift_start'] ?? ($staff['WorkStart'] ?? '08:00:00');
    $shiftEnd = $row['shift_end'] ?? ($staff['WorkEnd'] ?? '17:00:00');

    $actorName = staffbpso_actor_name();
    $actorId = staffbpso_actor_id();
    require_once __DIR__.'/../../activity_log_helper.php';
    $name = $staff['Name'];

    switch ($action) {

        case 'time_in':
            if ($status === 'Absent') back($name.' is already marked absent for this day.', 'error');
            if ($status !== 'Scheduled') back($name.' has already timed in.', 'error');
            $pdo->prepare("INSERT INTO staff_attendance (EmployeeID, attendance_date, shift_start, shift_end, status, time_in, time_in_by, time_in_by_id)
                VALUES (?, ?, ?, ?, 'On Duty', NOW(), ?, ?)
                ON DUPLICATE KEY UPDATE status = IF(status = 'Scheduled', 'On Duty', status),
                    time_in = IF(time_in IS NULL, VALUES(time_in), time_in),
                    time_in_by = IF(time_in_by IS NULL, VALUES(time_in_by), time_in_by),
                    time_in_by_id = IF(time_in_by_id IS NULL, VALUES(time_in_by_id), time_in_by_id)")
                ->execute([$employeeId, $date, $shiftStart, $shiftEnd, $actorName, $actorId]);
            log_activity('Staff', 'Time In', $name.' timed in ('.$employeeId.', '.$date.').');
            back('Time In recorded for '.$name.'.');

        case 'mark_absent':
            if ($status !== 'Scheduled' || !empty($row['time_in'])) back('Only staff who have not timed in can be marked absent.', 'error');
            $reason = trim($_POST['absent_reason'] ?? '');
            if ($reason === '') back('Please provide a reason for absence.', 'error');
            $pdo->prepare("INSERT INTO staff_attendance (EmployeeID, attendance_date, shift_start, shift_end, status, absent_reason, absent_at, absent_by, absent_by_id)
                VALUES (?, ?, ?, ?, 'Absent', ?, NOW(), ?, ?)
                ON DUPLICATE KEY UPDATE status = 'Absent', absent_reason = VALUES(absent_reason), absent_at = VALUES(absent_at),
                    absent_by = VALUES(absent_by), absent_by_id = VALUES(absent_by_id)")
                ->execute([$employeeId, $date, $shiftStart, $shiftEnd, mb_substr($reason, 0, 255), $actorName, $actorId]);
            log_activity('Staff', 'Mark Absent', $name.' marked absent ('.$date.'). Reason: '.$reason);
            back($name.' marked as absent.');

        case 'time_out':
            if (!$row || $status !== 'On Duty') back($name.' cannot time out right now.', 'error');
            $scheduledEnd = tanod_scheduled_end_datetime($date, $shiftStart, $shiftEnd);
            $isEarly = new DateTime() < $scheduledEnd;
            $reason = trim($_POST['early_out_reason'] ?? '');
            if ($isEarly && $reason === '') back('Please provide a reason for ending duty before the scheduled time.', 'error');
            if ($isEarly) {
                $pdo->prepare("UPDATE staff_attendance SET time_out = NOW(), time_out_by = ?, time_out_by_id = ?, is_early_out = 1, early_out_reason = ?, status = 'Early Out' WHERE id = ? AND status = 'On Duty'")
                    ->execute([$actorName, $actorId, mb_substr($reason, 0, 255), $row['id']]);
            } else {
                $pdo->prepare("UPDATE staff_attendance SET time_out = NOW(), time_out_by = ?, time_out_by_id = ?, status = 'Completed' WHERE id = ? AND status = 'On Duty'")
                    ->execute([$actorName, $actorId, $row['id']]);
            }
            log_activity('Staff', 'Time Out', $name.' timed out'.($isEarly ? ' early. Reason: '.$reason : '.').' ('.$employeeId.', '.$date.')');
            back('Time Out recorded for '.$name.($isEarly ? ' (Early Out).' : '.'));

        default:
            back('No action was selected.', 'error');
    }
} catch (Throwable $e) {
    back($e->getMessage(), 'error');
}

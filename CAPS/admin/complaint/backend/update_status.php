<?php
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../permission_helper.php';
require_once __DIR__ . '/../../activity_log_helper.php';
require_permission($pdo, 'complaints', 'update');

if (session_status() === PHP_SESSION_NONE) { @session_start(); }
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../frontend/complaint_rep.php');
    exit;
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!hash_equals((string)$_SESSION['csrf_token'], $token)) {
    $_SESSION['toast_msg'] = 'Invalid request. Please try again.';
    $_SESSION['toast_color'] = 'error';
    header('Location: ../frontend/complaint_rep.php');
    exit;
}

$complaint_id  = trim($_POST['complaint_id'] ?? '');
$new_status    = trim($_POST['status'] ?? '');
$admin_message = trim($_POST['admin_message'] ?? '');
$allowed_statuses = ['Pending', 'Ongoing', 'Resolved'];

if ($complaint_id === '' || !in_array($new_status, $allowed_statuses, true)) {
    $_SESSION['toast_msg'] = 'Invalid complaint update request.';
    $_SESSION['toast_color'] = 'error';
    header('Location: ../frontend/complaint_rep.php');
    exit;
}

try {
    $check = $pdo->prepare('SELECT title, status FROM complaints WHERE complaint_id = ? LIMIT 1');
    $check->execute([$complaint_id]);
    $complaint = $check->fetch(PDO::FETCH_ASSOC);

    if (!$complaint) {
        $_SESSION['toast_msg'] = 'Complaint record not found.';
        $_SESSION['toast_color'] = 'error';
        header('Location: ../frontend/complaint_rep.php');
        exit;
    }

    $stmt = $pdo->prepare('UPDATE complaints SET admin_reply = ?, status = ? WHERE complaint_id = ?');
    $stmt->execute([$admin_message, $new_status, $complaint_id]);

    log_activity(
        'Complaints',
        'Update Complaint Status',
        "Updated complaint ID {$complaint_id} status from \"{$complaint['status']}\" to \"{$new_status}\"." .
        ($admin_message !== '' ? " Admin reply: \"{$admin_message}\"" : '')
    );

    $_SESSION['toast_msg'] = 'Complaint status updated successfully.';
    $_SESSION['toast_color'] = 'success';
} catch (Throwable $e) {
    error_log('[CAPS Complaint] update_status.php: ' . $e->getMessage());
    $_SESSION['toast_msg'] = 'Unable to update the complaint. Please try again.';
    $_SESSION['toast_color'] = 'error';
}

header('Location: ../frontend/complaint_rep.php');
exit;

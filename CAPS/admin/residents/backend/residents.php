<?php
/**
 * ADMIN/residents/backend/residents.php
 * Residents Management — business logic only (POST handlers, queries, stats).
 * No HTML here — UI rendering lives in ADMIN/residents/frontend/residents.php,
 * which requires this file first, then just prints the variables set below.
 *
 * Ported from SOE/admin_int/residents.php — split into backend/frontend
 * to match the pattern already used by dashboard.php, settings.php, and
 * admin_profile.php.
 */
require_once __DIR__ . '/../../db.php';

// ── CSRF helper (graceful: define stubs if file missing) ──────────
if (file_exists(__DIR__ . '/csrf_helper.php')) {
    require_once __DIR__ . '/csrf_helper.php';
} else {
    if (!function_exists('csrf_token')) {
        function csrf_token(): string {
            if (session_status() === PHP_SESSION_NONE) session_start();
            if (empty($_SESSION['csrf_token'])) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
            return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token']) . '">';
        }
    }
    if (!function_exists('csrf_verify')) {
        function csrf_verify(): void { /* graceful no-op when helper missing */ }
    }
}

require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../permission_helper.php';
require_permission($pdo, 'residents', 'read');
require_once __DIR__ . '/../../theme_loader.php';
require_once __DIR__ . '/../../activity_log_helper.php';
$_theme_head_loaded = true; // We include theme_head.php ourselves inside <head>

// ── PHPMailer autoload (root vendor) ─────────────────────────────
$_phpmailer_paths = [
    __DIR__ . '/../../../vendor/autoload.php',                                  // Composer autoload (preferred)
    __DIR__ . '/../../../vendor/phpmailer/phpmailer/src/Exception.php',
    __DIR__ . '/../../../vendor/phpmailer/phpmailer/src/PHPMailer.php',
    __DIR__ . '/../../../vendor/phpmailer/phpmailer/src/SMTP.php',
];
if (file_exists(__DIR__ . '/../../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../../../vendor/phpmailer/phpmailer/src/PHPMailer.php')) {
    require_once __DIR__ . '/../../../vendor/phpmailer/phpmailer/src/Exception.php';
    require_once __DIR__ . '/../../../vendor/phpmailer/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/../../../vendor/phpmailer/phpmailer/src/SMTP.php';
}

// ── Inline mailer helper (used by both approve & disapprove) ──────
if (!function_exists('_residents_send_mail')) {
    function _residents_send_mail(string $to_email, string $to_name, string $subject, string $html_body): array {
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            return [false, 'PHPMailer not found. Check vendor directory.'];
        }
        $smtp_host  = 'smtp.gmail.com';
        $smtp_user  = 'rendellsubu65@gmail.com';
        $smtp_pass  = 'prnm nbty ckda jwbt';
        $smtp_port  = 587;
        $from_name  = 'Barangay Binang 2nd';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->CharSet = PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
            $mail->isSMTP();
            $mail->Host       = $smtp_host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $smtp_user;
            $mail->Password   = $smtp_pass;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $smtp_port;
            $mail->setFrom($smtp_user, $from_name);
            $mail->addAddress($to_email, $to_name);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html_body;
            $mail->AltBody = strip_tags(str_replace(['<br>','<br/>','<br />'], "\n", $html_body));
            $mail->send();
            return [true, ''];
        } catch (\Exception $e) {
            error_log('[residents.php] Mail error to ' . $to_email . ': ' . $mail->ErrorInfo);
            return [false, $mail->ErrorInfo];
        }
    }
}

// ══════════════════════════════════════════════════════════════════
//  ACCESS REQUEST SYSTEM — Bootstrap (safe, idempotent)
// ══════════════════════════════════════════════════════════════════

// Ensure access_requests table exists
$pdo->exec("
    CREATE TABLE IF NOT EXISTS access_requests (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        fullname        VARCHAR(255)  NOT NULL,
        first_name      VARCHAR(100)  NOT NULL,
        middle_name     VARCHAR(100)  NULL,
        last_name       VARCHAR(100)  NOT NULL,
        email           VARCHAR(255)  NOT NULL,
        contact_number  VARCHAR(15)   NULL,
        birthdate       DATE          NULL,
        house_no        VARCHAR(50)   NULL,
        street          VARCHAR(100)  NULL,
        valid_id_path   VARCHAR(500)  NULL,
        request_message TEXT          NULL,
        status          ENUM('Pending','Approved','Disapproved') NOT NULL DEFAULT 'Pending',
        token           VARCHAR(128)  NULL,
        token_expiry    DATETIME      NULL,
        admin_reason    TEXT          NULL,
        resident_id     INT           NULL,
        created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_email  (email),
        INDEX idx_status (status),
        INDEX idx_token  (token(32))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Safely add access_status to residents if missing
try {
    $pdo->exec("ALTER TABLE residents ADD COLUMN access_status ENUM('None','Pending','Active','Disabled') NOT NULL DEFAULT 'None'");
} catch (PDOException $e) { /* column already exists — ignore */ }

// ── Pending count badge ───────────────────────────────────────────
$pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM access_requests WHERE status = 'Pending'")->fetchColumn();

// ══════════════════════════════════════════════════════════════════
//  POST HANDLER — Approve / Disapprove / AJAX refresh
//  Must be before any HTML output
// ══════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Session guard — auth_check.php sets $_SESSION['role']; accept 'admin' or 'staff'
    // (Fallback also checks legacy keys in case login.php sets them differently)
    $isAdmin = !empty($_SESSION['role'])
        || !empty($_SESSION['admin_id'])
        || !empty($_SESSION['admin'])
        || !empty($_SESSION['user_id']);
    if (!$isAdmin) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in again.']);
        exit;
    }
    // Always output JSON for AJAX actions — set header early
    if (in_array($_POST['action'] ?? '', ['approve_request','disapprove_request','refresh_requests'], true)) {
        header('Content-Type: application/json');
    }

    $action    = $_POST['action'];
    $requestId = (int)($_POST['req_id'] ?? 0);

    /* ── AJAX refresh ── */
    if ($action === 'refresh_requests') {
        require_permission($pdo, 'residents', 'read');
        $rows = $pdo->query("
            SELECT request_id AS id, fullname,
                   firstname AS first_name, middlename AS middle_name, lastname AS last_name,
                   email, contact_number, birthdate, house_no, street,
                   valid_id_path, request_message,
                   status, submitted_at AS created_at, admin_reason, resident_id
            FROM   access_requests
            ORDER  BY FIELD(status,'Pending','Approved','Disapproved','Matched','For Profiling','For Correction','Rejected'), submitted_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }

    /* ── Approve ── */
    if ($action === 'approve_request' && $requestId > 0) {
        require_permission($pdo, 'residents', 'update');
        try {
            $req = $pdo->prepare("SELECT request_id AS id, fullname, firstname AS first_name, middlename AS middle_name, lastname AS last_name, email, contact_number, birthdate, house_no, street, valid_id_path, request_message, status, submitted_at AS created_at, admin_reason, resident_id, token, token_expiry FROM access_requests WHERE request_id = ?");
            $req->execute([$requestId]);
            $ar = $req->fetch(PDO::FETCH_ASSOC);

            if (!$ar || $ar['status'] !== 'Pending') {
                echo json_encode(['success' => false, 'message' => 'Request not found or already processed.']);
                exit;
            }

            // Validate that the email address is present and valid
            if (empty($ar['email']) || !filter_var($ar['email'], FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Cannot approve: the applicant has no valid email address on record. Please contact them directly.']);
                exit;
            }

            $rawToken    = bin2hex(random_bytes(32));
            $tokenExpiry = date('Y-m-d H:i:s', strtotime('+48 hours'));

            $upd = $pdo->prepare("
                UPDATE access_requests
                SET status = 'Approved', token = ?, token_expiry = ?, updated_at = NOW()
                WHERE request_id = ?
            ");
            $upd->execute([$rawToken, $tokenExpiry, $requestId]);

            if (!empty($ar['resident_id'])) {
                $pdo->prepare("UPDATE residents SET access_status = 'Active' WHERE ResidentID = ?")
                    ->execute([$ar['resident_id']]);
            } else {
                // Fallback: match by email
                $pdo->prepare("UPDATE residents SET access_status = 'Pending' WHERE Email = ? AND (IsDeceased = 0 OR IsDeceased IS NULL) LIMIT 1")
                    ->execute([$ar['email']]);
            }

            // Build password-setup link
            $proto           = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base            = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin_int/residents.php')), '/');
            $setPasswordLink = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base . '/set_password.php?token=' . urlencode($rawToken);

            $fullname = htmlspecialchars($ar['fullname']);
            $htmlBody = '<!DOCTYPE html><html><head><meta charset="utf-8">
<style>
  body{font-family:Arial,sans-serif;background:#f4f6fb;margin:0;padding:0}
  .wrap{max-width:580px;margin:30px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)}
  .hdr{background:linear-gradient(135deg,#1a3570,#2a4fa0);padding:36px 40px;text-align:center}
  .hdr h1{color:#fff;margin:0;font-size:22px;font-weight:800}
  .hdr p{color:rgba(255,255,255,.7);margin:6px 0 0;font-size:11px;text-transform:uppercase;letter-spacing:2px}
  .body{padding:40px;color:#334155;font-size:14px;line-height:1.7}
  .icon-box{width:56px;height:56px;background:#d1fae5;border-radius:14px;margin:0 auto 20px;text-align:center;line-height:56px;font-size:28px}
  h2{text-align:center;color:#1a3570;font-size:18px;margin:0 0 10px}
  .btn{display:inline-block;background:#1a3570;color:#fff!important;text-decoration:none;padding:14px 32px;border-radius:12px;font-weight:700;font-size:14px}
  .note{background:#f8fafc;border-radius:10px;padding:16px;margin-top:20px;font-size:11px;color:#94a3b8;word-break:break-all}
  .ftr{background:#f8fafc;padding:20px;text-align:center;font-size:11px;color:#94a3b8}
</style></head><body>
<div class="wrap">
  <div class="hdr"><h1>Barangay Bi&#241;ang 2nd</h1><p>Resident Portal</p></div>
  <div class="body">
    <div class="icon-box">&#9989;</div>
    <h2>Access Approved!</h2>
    <p style="text-align:center;color:#64748b;margin:0 0 24px">Dear <strong style="color:#1e293b">' . $fullname . '</strong>, your portal access request has been <strong style="color:#059669">approved</strong>.</p>
    <p>To complete your registration, create a secure password by clicking the button below. This link is valid for <strong>48 hours</strong>.</p>
    <p style="text-align:center;margin:28px 0"><a href="' . $setPasswordLink . '" class="btn">Set Up My Password</a></p>
    <div class="note">If the button does not work, copy this link into your browser:<br><span style="color:#1a3570">' . $setPasswordLink . '</span></div>
    <hr style="border:none;border-top:1px solid #f1f5f9;margin:28px 0">
    <p style="color:#94a3b8;font-size:11px;text-align:center;margin:0">Link expires on <strong>' . date('F j, Y g:i A', strtotime($tokenExpiry)) . '</strong>.<br>If you did not request this, please ignore this email.</p>
  </div>
  <div class="ftr">&#169; ' . date('Y') . ' Barangay Bi&#241;ang 2nd. All rights reserved.</div>
</div></body></html>';

            [$emailSent, $emailErr] = _residents_send_mail(
                $ar['email'], $ar['fullname'],
                'Your Portal Access Has Been Approved — Set Your Password',
                $htmlBody
            );

            echo json_encode([
                'success' => true,
                'message' => $emailSent
                    ? "Approved! Password setup email sent to {$ar['email']}."
                    : "Approved, but email could not be sent. Setup link: {$setPasswordLink}"
            ]);
            log_activity('Residents', 'Approve Access Request', "Approved access request of {$ar['fullname']} (Request ID: {$requestId}, Email: {$ar['email']}).");
        } catch (\Exception $e) {
            error_log('[residents.php] approve_request error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Server error during approval: ' . $e->getMessage()]);
        }
        exit;
    }

    /* ── Resend approval email ── */
    if ($action === 'resend_email' && $requestId > 0) {
        require_permission($pdo, 'residents', 'update');
        try {
            $req = $pdo->prepare("SELECT request_id AS id, fullname, firstname AS first_name, middlename AS middle_name, lastname AS last_name, email, contact_number, birthdate, house_no, street, valid_id_path, request_message, status, submitted_at AS created_at, admin_reason, resident_id, token, token_expiry FROM access_requests WHERE request_id = ?");
            $req->execute([$requestId]);
            $ar = $req->fetch(PDO::FETCH_ASSOC);

            if (!$ar || $ar['status'] !== 'Approved') {
                echo json_encode(['success' => false, 'message' => 'Request not found or not yet approved.']);
                exit;
            }

            if (empty($ar['email']) || !filter_var($ar['email'], FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'No valid email address on record.']);
                exit;
            }

            // Generate a fresh token + reset expiry
            $rawToken    = bin2hex(random_bytes(32));
            $tokenExpiry = date('Y-m-d H:i:s', strtotime('+48 hours'));
            $pdo->prepare("UPDATE access_requests SET token = ?, token_expiry = ?, updated_at = NOW() WHERE request_id = ?")
                ->execute([$rawToken, $tokenExpiry, $requestId]);

            $proto           = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base            = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin_int/residents.php')), '/');
            $setPasswordLink = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base . '/set_password.php?token=' . urlencode($rawToken);

            $fullname = htmlspecialchars($ar['fullname']);
            $htmlBody = '<!DOCTYPE html><html><head><meta charset="utf-8">
<style>
  body{font-family:Arial,sans-serif;background:#f4f6fb;margin:0;padding:0}
  .wrap{max-width:580px;margin:30px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)}
  .hdr{background:linear-gradient(135deg,#1a3570,#2a4fa0);padding:36px 40px;text-align:center}
  .hdr h1{color:#fff;margin:0;font-size:22px;font-weight:800}
  .hdr p{color:rgba(255,255,255,.7);margin:6px 0 0;font-size:11px;text-transform:uppercase;letter-spacing:2px}
  .body{padding:40px;color:#334155;font-size:14px;line-height:1.7}
  .icon-box{width:56px;height:56px;background:#d1fae5;border-radius:14px;margin:0 auto 20px;text-align:center;line-height:56px;font-size:28px}
  h2{text-align:center;color:#1a3570;font-size:18px;margin:0 0 10px}
  .btn{display:inline-block;background:#1a3570;color:#fff!important;text-decoration:none;padding:14px 32px;border-radius:12px;font-weight:700;font-size:14px}
  .note{background:#f8fafc;border-radius:10px;padding:16px;margin-top:20px;font-size:11px;color:#94a3b8;word-break:break-all}
  .ftr{background:#f8fafc;padding:20px;text-align:center;font-size:11px;color:#94a3b8}
</style></head><body>
<div class="wrap">
  <div class="hdr"><h1>Barangay Bi&#241;ang 2nd</h1><p>Resident Portal</p></div>
  <div class="body">
    <div class="icon-box">&#128231;</div>
    <h2>Password Setup Link (Resent)</h2>
    <p style="text-align:center;color:#64748b;margin:0 0 24px">Dear <strong style="color:#1e293b">' . $fullname . '</strong>, here is your updated password setup link for the Barangay Portal.</p>
    <p>Click the button below to create your password. This new link is valid for <strong>48 hours</strong>.</p>
    <p style="text-align:center;margin:28px 0"><a href="' . $setPasswordLink . '" class="btn">Set Up My Password</a></p>
    <div class="note">If the button does not work, copy this link into your browser:<br><span style="color:#1a3570">' . $setPasswordLink . '</span></div>
    <hr style="border:none;border-top:1px solid #f1f5f9;margin:28px 0">
    <p style="color:#94a3b8;font-size:11px;text-align:center;margin:0">Link expires on <strong>' . date('F j, Y g:i A', strtotime($tokenExpiry)) . '</strong>.<br>If you did not request this, please ignore this email.</p>
  </div>
  <div class="ftr">&#169; ' . date('Y') . ' Barangay Bi&#241;ang 2nd. All rights reserved.</div>
</div></body></html>';

            [$emailSent, $emailErr] = _residents_send_mail(
                $ar['email'], $ar['fullname'],
                'Your Barangay Portal Password Setup Link (Resent)',
                $htmlBody
            );

            echo json_encode([
                'success' => $emailSent,
                'message' => $emailSent
                    ? "Email resent successfully to {$ar['email']}."
                    : "Failed to resend email. Error: {$emailErr}"
            ]);
            if ($emailSent) {
                log_activity('Residents', 'Resend Approval Email', "Resent password setup email to {$ar['fullname']} (Request ID: {$requestId}, Email: {$ar['email']}).");
            }
        } catch (\Exception $e) {
            error_log('[residents.php] resend_email error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'disapprove_request' && $requestId > 0) {
        require_permission($pdo, 'residents', 'update');
        $rawReason = trim($_POST['admin_reason'] ?? '');
        if ($rawReason === '') {
            echo json_encode(['success' => false, 'message' => 'Please provide a reason for disapproval.']);
            exit;
        }
        $reason = htmlspecialchars(strip_tags($rawReason), ENT_QUOTES, 'UTF-8');
        if (strlen($reason) > 1000) $reason = substr($reason, 0, 1000);

        try {
            $req = $pdo->prepare("SELECT request_id AS id, fullname, firstname AS first_name, middlename AS middle_name, lastname AS last_name, email, contact_number, birthdate, house_no, street, valid_id_path, request_message, status, submitted_at AS created_at, admin_reason, resident_id, token, token_expiry FROM access_requests WHERE request_id = ?");
            $req->execute([$requestId]);
            $ar = $req->fetch(PDO::FETCH_ASSOC);

            if (!$ar || $ar['status'] !== 'Pending') {
                echo json_encode(['success' => false, 'message' => 'Request not found or already processed.']);
                exit;
            }

            $pdo->prepare("UPDATE access_requests SET status = 'Disapproved', admin_reason = ?, updated_at = NOW() WHERE request_id = ?")
                ->execute([$reason, $requestId]);

            // Update resident access_status
            try {
                $pdo->prepare("UPDATE residents SET access_status = 'None' WHERE Email = ? LIMIT 1")->execute([$ar['email']]);
            } catch (\Exception $e) { /* non-critical */ }

            $fullname = htmlspecialchars($ar['fullname']);
            $htmlBody = '<!DOCTYPE html><html><head><meta charset="utf-8">
<style>
  body{font-family:Arial,sans-serif;background:#f4f6fb;margin:0;padding:0}
  .wrap{max-width:580px;margin:30px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)}
  .hdr{background:linear-gradient(135deg,#991b1b,#b91c1c);padding:36px 40px;text-align:center}
  .hdr h1{color:#fff;margin:0;font-size:22px;font-weight:800}
  .hdr p{color:rgba(255,255,255,.7);margin:6px 0 0;font-size:11px;text-transform:uppercase;letter-spacing:2px}
  .body{padding:40px;color:#334155;font-size:14px;line-height:1.7}
  .reason-box{background:#fff1f2;border:1px solid #fecaca;border-radius:12px;padding:18px;margin:20px 0}
  .ftr{background:#f8fafc;padding:20px;text-align:center;font-size:11px;color:#94a3b8}
</style></head><body>
<div class="wrap">
  <div class="hdr"><h1>Barangay Bi&#241;ang 2nd</h1><p>Resident Portal</p></div>
  <div class="body">
    <p style="font-size:36px;text-align:center;margin:0 0 16px">&#128274;</p>
    <h2 style="text-align:center;color:#dc2626;font-size:18px;margin:0 0 16px">Access Request Not Approved</h2>
    <p>Dear <strong>' . $fullname . '</strong>, after reviewing your portal access request, we were unable to approve it at this time.</p>
    <div class="reason-box">
      <p style="color:#991b1b;font-weight:700;margin:0 0 8px;font-size:13px">Reason from the Barangay Office:</p>
      <p style="color:#7f1d1d;margin:0">' . nl2br(htmlspecialchars($reason)) . '</p>
    </div>
    <p>If you believe this is an error or wish to reapply with additional information, please visit the Barangay Hall personally or submit a new request.</p>
    <hr style="border:none;border-top:1px solid #f1f5f9;margin:28px 0">
    <p style="color:#94a3b8;font-size:11px;text-align:center;margin:0">Barangay Bi&#241;ang 2nd &bull; For concerns, please visit the Barangay Hall during office hours.</p>
  </div>
  <div class="ftr">&#169; ' . date('Y') . ' Barangay Bi&#241;ang 2nd. All rights reserved.</div>
</div></body></html>';

            [$emailSent, $emailErr] = _residents_send_mail(
                $ar['email'], $ar['fullname'],
                'Update on Your Barangay Portal Access Request',
                $htmlBody
            );

            echo json_encode([
                'success' => true,
                'message' => $emailSent
                    ? 'Request disapproved and notification email sent.'
                    : 'Request disapproved. (Email could not be sent — check SMTP settings.)'
            ]);
            log_activity('Residents', 'Disapprove Access Request', "Disapproved access request of {$ar['fullname']} (Request ID: {$requestId}). Reason: {$reason}");
        } catch (\Exception $e) {
            error_log('[residents.php] disapprove_request error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Server error during disapproval: ' . $e->getMessage()]);
        }
        exit;
    }
}

// ══════════════════════════════════════════════════════════════════
//  PUROK MANAGEMENT — Bootstrap (safe, idempotent)
//  Creates puroks table if it doesn't exist.
//  Ensures StreetName and Purok columns exist in residents.
// ══════════════════════════════════════════════════════════════════

// Create puroks table if missing
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `puroks` (
        `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
        `purok_name`  VARCHAR(100)    NOT NULL,
        `status`      ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_purok_name` (`purok_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Default barangay address used by Manage Area and resident registration ──
// barangay_profile is shared with Barangay Profile; these columns only extend it
// with the PSGC hierarchy needed for automatic resident addresses.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS barangay_profile (
        id TINYINT UNSIGNED NOT NULL DEFAULT 1,
        brgy_name VARCHAR(255) NOT NULL DEFAULT 'Barangay Biñang 2nd',
        logo_path VARCHAR(512) NULL, about TEXT NULL, vision TEXT NULL, mission TEXT NULL,
        office_hours VARCHAR(512) NOT NULL DEFAULT 'Monday – Friday, 8:00 AM – 5:00 PM',
        address VARCHAR(512) NULL, email VARCHAR(255) NULL, facebook_url VARCHAR(512) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT IGNORE INTO barangay_profile (id) VALUES (1)");
    foreach ([
        'region_name VARCHAR(150) NULL', 'province_name VARCHAR(150) NULL',
        'municipality_name VARCHAR(150) NULL', 'barangay_name VARCHAR(150) NULL',
        'psgc_region_code VARCHAR(20) NULL', 'psgc_province_code VARCHAR(20) NULL',
        'psgc_municipality_code VARCHAR(20) NULL', 'psgc_barangay_code VARCHAR(20) NULL',
        'zip_code VARCHAR(10) NULL',
        // older databases (e.g. an earlier Rebuild_Database script) lack these too
        'address VARCHAR(512) NULL', 'about TEXT NULL', 'vision TEXT NULL', 'mission TEXT NULL',
        'email VARCHAR(255) NULL', 'facebook_url VARCHAR(512) NULL',
        "office_hours VARCHAR(512) NOT NULL DEFAULT 'Monday – Friday, 8:00 AM – 5:00 PM'"
    ] as $definition) {
        try { $pdo->exec("ALTER TABLE barangay_profile ADD COLUMN {$definition}"); } catch (Throwable $ignore) {}
    }
} catch (Throwable $e) { error_log('[Residents] barangay_profile bootstrap: '.$e->getMessage()); }

// ── Resident address master lists: Streets + Areas ───────────────
// Each list is scoped to a PSGC barangay so admins cannot accidentally
// assign a street/area from another barangay.
$pdo->exec("CREATE TABLE IF NOT EXISTS resident_streets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    psgc_barangay_code VARCHAR(20) NOT NULL,
    street_name VARCHAR(100) NOT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_barangay_street (psgc_barangay_code, street_name),
    KEY idx_barangay_status (psgc_barangay_code, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS resident_areas (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    psgc_barangay_code VARCHAR(20) NOT NULL,
    area_type VARCHAR(30) NOT NULL,
    area_name VARCHAR(100) NOT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_barangay_area (psgc_barangay_code, area_type, area_name),
    KEY idx_barangay_area_status (psgc_barangay_code, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── Manage Area AJAX actions ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['address_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    require_permission($pdo, 'residents', 'update');
    $action = $_POST['address_action'];

    if ($action === 'profile_save') {
        $regionCode       = preg_replace('/\D/','',(string)($_POST['region_code'] ?? ''));
        $provinceCode     = preg_replace('/\D/','',(string)($_POST['province_code'] ?? ''));
        $municipalityCode = preg_replace('/\D/','',(string)($_POST['municipality_code'] ?? ''));
        $barangayCode     = preg_replace('/\D/','',(string)($_POST['barangay_code'] ?? ''));
        $clean = static fn($v,$max=150) => mb_substr(trim(strip_tags((string)$v)),0,$max);
        if (!$regionCode || !$provinceCode || !$municipalityCode || !$barangayCode) {
            echo json_encode(['success'=>false,'message'=>'Region, Province, City/Municipality and Barangay are required.']); exit;
        }
        try {
            $stmt=$pdo->prepare("UPDATE barangay_profile SET
                region_name=?, province_name=?, municipality_name=?, barangay_name=?,
                psgc_region_code=?, psgc_province_code=?, psgc_municipality_code=?, psgc_barangay_code=?,
                zip_code=?, address=? WHERE id=1");
            $stmt->execute([
                $clean($_POST['region_name']??''), $clean($_POST['province_name']??''),
                $clean($_POST['municipality_name']??''), $clean($_POST['barangay_name']??''),
                $regionCode,$provinceCode,$municipalityCode,$barangayCode,
                $clean($_POST['zip_code']??'',10), $clean($_POST['address']??'',512)
            ]);
            echo json_encode(['success'=>true,'message'=>'Default barangay address saved.'],JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('[Residents] profile_save: '.$e->getMessage());
            echo json_encode(['success'=>false,'message'=>'Unable to save the default barangay address.']);
        }
        exit;
    }

    $type = $_POST['address_type'] ?? '';
    $allowedTypes = ['street','area'];
    if (!in_array($type, $allowedTypes, true)) { echo json_encode(['success'=>false,'message'=>'Invalid address type.']); exit; }
    $barangay = preg_replace('/\D/', '', (string)($_POST['barangay'] ?? ''));
    if ($action === 'add') {
        $name = trim(strip_tags((string)($_POST['name'] ?? '')));
        if ($barangay === '' || $name === '') { echo json_encode(['success'=>false,'message'=>'Barangay and name are required.']); exit; }
        if (mb_strlen($name) > 100) { echo json_encode(['success'=>false,'message'=>'Maximum 100 characters.']); exit; }
        try {
            if ($type === 'street') {
                $st=$pdo->prepare('INSERT INTO resident_streets (psgc_barangay_code,street_name) VALUES (?,?)'); $st->execute([$barangay,$name]);
            } else {
                $areaType=trim(strip_tags((string)($_POST['area_type'] ?? 'Subdivision')));
                if (!in_array($areaType,['Subdivision','Village','Sitio','Purok'],true)) $areaType='Subdivision';
                $st=$pdo->prepare('INSERT INTO resident_areas (psgc_barangay_code,area_type,area_name) VALUES (?,?,?)'); $st->execute([$barangay,$areaType,$name]);
            }
            echo json_encode(['success'=>true,'message'=>ucfirst($type).' added.'],JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) { echo json_encode(['success'=>false,'message'=>$e->getCode()==='23000'?'Duplicate entry.':'Database error.']); }
        exit;
    }
    if ($action === 'edit') {
        $id=(int)($_POST['id']??0); $name=trim(strip_tags((string)($_POST['name']??'')));
        if($id<=0||$name===''){echo json_encode(['success'=>false,'message'=>'Invalid record.']);exit;}
        try {
            if($type==='street'){$st=$pdo->prepare('UPDATE resident_streets SET street_name=? WHERE id=?');$st->execute([$name,$id]);}
            else{$areaType=trim(strip_tags((string)($_POST['area_type']??'Subdivision')));if(!in_array($areaType,['Subdivision','Village','Sitio','Purok'],true))$areaType='Subdivision';$st=$pdo->prepare('UPDATE resident_areas SET area_name=?, area_type=? WHERE id=?');$st->execute([$name,$areaType,$id]);}
            echo json_encode(['success'=>true,'message'=>ucfirst($type).' updated.'],JSON_UNESCAPED_UNICODE);
        } catch(PDOException $e){echo json_encode(['success'=>false,'message'=>$e->getCode()==='23000'?'Duplicate entry.':'Database error.']);}
        exit;
    }
    if ($action === 'delete') {
        $id=(int)($_POST['id']??0); if($id<=0){echo json_encode(['success'=>false,'message'=>'Invalid record.']);exit;}
        try { $table=$type==='street'?'resident_streets':'resident_areas'; $pdo->prepare("DELETE FROM {$table} WHERE id=?")->execute([$id]); echo json_encode(['success'=>true,'message'=>ucfirst($type).' deleted.']); }
        catch(PDOException $e){echo json_encode(['success'=>false,'message'=>'Unable to delete record.']);}
        exit;
    }
    echo json_encode(['success'=>false,'message'=>'Unknown address action.']); exit;
}

// Ensure StreetName column exists in residents (already in schema, safety guard)
try {
    $pdo->exec("ALTER TABLE residents ADD COLUMN StreetName VARCHAR(100) NULL DEFAULT NULL AFTER HouseNumber");
} catch (PDOException $e) { /* column already exists */ }

// Ensure Purok column exists in residents (already in schema, safety guard)
try {
    $pdo->exec("ALTER TABLE residents ADD COLUMN Purok VARCHAR(100) NULL DEFAULT NULL AFTER StreetName");
} catch (PDOException $e) { /* column already exists */ }

// ── New columns: Religion, VoterNumber, ResidentCode ─────────────
try { $pdo->exec("ALTER TABLE residents ADD COLUMN Religion VARCHAR(100) NULL DEFAULT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE residents ADD COLUMN VoterNumber VARCHAR(100) NULL DEFAULT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE residents ADD COLUMN ResidentCode VARCHAR(30) NULL DEFAULT NULL"); } catch (PDOException $e) {}

// ── New columns: Socio-Economic Profile (Employment / Occupation / Source of Income) ─
try { $pdo->exec("ALTER TABLE residents ADD COLUMN Occupation VARCHAR(150) NULL DEFAULT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE residents ADD COLUMN EmploymentStatusOther VARCHAR(150) NULL DEFAULT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE residents ADD COLUMN SourceOfIncome VARCHAR(255) NULL DEFAULT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE residents ADD COLUMN SourceOfIncomeOther VARCHAR(150) NULL DEFAULT NULL"); } catch (PDOException $e) {}

// ── New columns: Government & Social Program Membership ──────────
try { $pdo->exec("ALTER TABLE residents ADD COLUMN IsSSSMember TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE residents ADD COLUMN IsGSISMember TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE residents ADD COLUMN IsPagibigMember TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}

// ── New columns: Deceased Record (kept permanently for history — never deleted) ─
try { $pdo->exec("ALTER TABLE residents ADD COLUMN DateOfDeath DATE NULL DEFAULT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE residents ADD COLUMN PlaceOfDeath VARCHAR(255) NULL DEFAULT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE residents ADD COLUMN CauseOfDeath VARCHAR(255) NULL DEFAULT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE residents ADD COLUMN DeceasedRemarks TEXT NULL DEFAULT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE residents ADD COLUMN DeathDateReported DATETIME NULL DEFAULT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE residents ADD COLUMN DeathReportedBy VARCHAR(150) NULL DEFAULT NULL"); } catch (PDOException $e) {}

// ── Generate ResidentCode for existing residents that don't have one ─
try {
    $missingCodes = $pdo->query("SELECT ResidentID FROM residents WHERE ResidentCode IS NULL OR ResidentCode = '' ORDER BY ResidentID ASC")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($missingCodes as $idx => $rid) {
        $year = date('Y', strtotime((string)(intval($rid) > 0 ? 'now' : 'now')));
        // Use the ResidentID order to assign sequence
        $seq = str_pad($idx + 1, 4, '0', STR_PAD_LEFT);
        $code = 'RES-' . date('Y') . '-' . $seq;
        $pdo->prepare("UPDATE residents SET ResidentCode = ? WHERE ResidentID = ?")->execute([$code, $rid]);
    }
} catch (PDOException $e) { /* non-critical */ }

// Seed puroks table from existing resident data (migration: one-time, idempotent)
try {
    $pdo->exec("
        INSERT IGNORE INTO puroks (purok_name)
        SELECT DISTINCT TRIM(Purok) FROM residents
        WHERE Purok IS NOT NULL AND TRIM(Purok) != ''
    ");
} catch (PDOException $e) { /* non-critical */ }

// ── Handle purok AJAX actions (add / edit / delete / list) ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purok_action'])) {
    header('Content-Type: application/json');

    $purokAction = $_POST['purok_action'];

    if ($purokAction === 'list') {
        try {
            $rows = $pdo->query("SELECT id, purok_name, status FROM puroks ORDER BY purok_name ASC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $rows]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Failed to fetch puroks.']);
        }
        exit;
    }

    if ($purokAction === 'list_active') {
        try {
            $rows = $pdo->query("SELECT id, purok_name FROM puroks WHERE status='Active' ORDER BY purok_name ASC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $rows]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Failed to fetch puroks.']);
        }
        exit;
    }

    if ($purokAction === 'add') {
        require_permission($pdo, 'residents', 'create');
        $rawName = trim($_POST['purok_name'] ?? '');
        if ($rawName === '') { echo json_encode(['success' => false, 'message' => 'Purok name is required.']); exit; }
        if (strlen($rawName) > 100) { echo json_encode(['success' => false, 'message' => 'Max 100 characters.']); exit; }
        $purokName = htmlspecialchars(strip_tags($rawName), ENT_QUOTES, 'UTF-8');
        try {
            $check = $pdo->prepare("SELECT id FROM puroks WHERE LOWER(purok_name) = LOWER(?)");
            $check->execute([$purokName]);
            if ($check->fetch()) { echo json_encode(['success' => false, 'message' => "Purok \"{$purokName}\" already exists."]); exit; }
            $stmt = $pdo->prepare("INSERT INTO puroks (purok_name) VALUES (?)");
            $stmt->execute([$purokName]);
            log_activity('Residents', 'Add Purok', "Added new purok \"{$purokName}\".");
            echo json_encode(['success' => true, 'message' => "Purok \"{$purokName}\" added.", 'id' => (int)$pdo->lastInsertId(), 'purok_name' => $purokName]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => ($e->getCode() === '23000') ? "Purok already exists." : 'DB error.']);
        }
        exit;
    }

    if ($purokAction === 'edit') {
        require_permission($pdo, 'residents', 'update');
        $purokId = (int)($_POST['id'] ?? 0);
        $rawName = trim($_POST['purok_name'] ?? '');
        if ($purokId <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid ID.']); exit; }
        if ($rawName === '') { echo json_encode(['success' => false, 'message' => 'Purok name is required.']); exit; }
        $purokName = htmlspecialchars(strip_tags($rawName), ENT_QUOTES, 'UTF-8');
        try {
            $check = $pdo->prepare("SELECT id FROM puroks WHERE LOWER(purok_name) = LOWER(?) AND id != ?");
            $check->execute([$purokName, $purokId]);
            if ($check->fetch()) { echo json_encode(['success' => false, 'message' => "Purok \"{$purokName}\" already exists."]); exit; }
            $oldRow = $pdo->prepare("SELECT purok_name FROM puroks WHERE id = ?");
            $oldRow->execute([$purokId]);
            $old = $oldRow->fetch(PDO::FETCH_ASSOC);
            if (!$old) { echo json_encode(['success' => false, 'message' => 'Purok not found.']); exit; }
            $pdo->prepare("UPDATE puroks SET purok_name = ? WHERE id = ?")->execute([$purokName, $purokId]);
            $pdo->prepare("UPDATE residents SET Purok = ? WHERE Purok = ?")->execute([$purokName, $old['purok_name']]);
            log_activity('Residents', 'Edit Purok', "Renamed purok \"{$old['purok_name']}\" to \"{$purokName}\".");
            echo json_encode(['success' => true, 'message' => "Purok renamed to \"{$purokName}\".", 'old_name' => $old['purok_name'], 'new_name' => $purokName]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => ($e->getCode() === '23000') ? "Already exists." : 'DB error.']);
        }
        exit;
    }

    if ($purokAction === 'delete') {
        require_permission($pdo, 'residents', 'delete');
        $purokId = (int)($_POST['id'] ?? 0);
        if ($purokId <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid ID.']); exit; }
        try {
            $row = $pdo->prepare("SELECT purok_name FROM puroks WHERE id = ?");
            $row->execute([$purokId]);
            $purok = $row->fetch(PDO::FETCH_ASSOC);
            if (!$purok) { echo json_encode(['success' => false, 'message' => 'Purok not found.']); exit; }
            $pdo->prepare("UPDATE residents SET Purok = NULL WHERE Purok = ?")->execute([$purok['purok_name']]);
            $pdo->prepare("DELETE FROM puroks WHERE id = ?")->execute([$purokId]);
            log_activity('Residents', 'Delete Purok', "Deleted purok \"{$purok['purok_name']}\".");
            echo json_encode(['success' => true, 'message' => "Purok \"{$purok['purok_name']}\" deleted."]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Failed to delete.']);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown purok action.']);
    exit;
}

// ── Load puroks for dropdowns ─────────────────────────────────────
try {
    $puroks_list = $pdo->query("SELECT id, purok_name FROM puroks WHERE status='Active' ORDER BY purok_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $puroks_list = [];
}

// ── Helper: render purok <option> tags (used in Add/Edit modal) ───
function render_purok_options(array $puroks_list, string $selected = ''): void {
    echo '<option value="">-- Select Purok --</option>';
    foreach ($puroks_list as $p) {
        $sel = ($selected !== '' && strtolower($selected) === strtolower($p['purok_name'])) ? ' selected' : '';
        echo '<option value="' . htmlspecialchars($p['purok_name']) . '"' . $sel . '>'
            . htmlspecialchars($p['purok_name']) . '</option>';
    }
}

// ══════════════════════════════════════════════════════════════════
//  PAGINATION CONFIG
// ══════════════════════════════════════════════════════════════════
define('RESIDENTS_PER_PAGE', 5);

$resSearch  = trim($_GET['res_search'] ?? '');
$resPage    = max(1, (int)($_GET['res_page'] ?? 1));

// Total count for pagination
if ($resSearch !== '') {
    $like = '%' . $resSearch . '%';
    $stmtCount = $pdo->prepare("
        SELECT COUNT(*) FROM residents
        WHERE (FirstName LIKE ? OR LastName LIKE ? OR MiddleName LIKE ?
               OR Email LIKE ? OR ContactNumber LIKE ?)
    ");
    $stmtCount->execute([$like, $like, $like, $like, $like]);
} else {
    $stmtCount = $pdo->query("SELECT COUNT(*) FROM residents");
}
$totalResidents = (int)$stmtCount->fetchColumn();
$totalResPages  = max(1, (int)ceil($totalResidents / RESIDENTS_PER_PAGE));
$resPage        = min($resPage, $totalResPages);
$resOffset      = ($resPage - 1) * RESIDENTS_PER_PAGE;

// ══════════════════════════════════════════════════════════════════
//  ORIGINAL RESIDENT QUERY — kept intact for stats / modals
//  $residents_data is used by stats cards, edit modal, head dropdowns
// ══════════════════════════════════════════════════════════════════
try {
    $sql = "SELECT r.*, 
            CONCAT(r.FirstName, ' ', COALESCE(r.MiddleName, ''), ' ', r.LastName, ' ', COALESCE(r.Suffix, '')) as FullName,
            (SELECT COUNT(*) FROM residents m WHERE m.FamilyHeadID = r.ResidentID) as MemberCount,
            (SELECT SUM(TotalHouseholdIncome) FROM residents i WHERE i.FamilyHeadID = r.ResidentID OR i.ResidentID = r.ResidentID) as ComputedHouseholdIncome
            FROM residents r 
            ORDER BY r.LastName ASC";
            
    $stmt = $pdo->query($sql);
    $residents_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("DB error in " . basename(__FILE__) . ": " . $e->getMessage());
    http_response_code(500);
    die("A server error occurred.");
}

// ── Paginated resident query (for the table display only) ─────────
if ($resSearch !== '') {
    $like = '%' . $resSearch . '%';
    $stmtPage = $pdo->prepare("
        SELECT r.*,
               CONCAT(r.FirstName,' ',COALESCE(r.MiddleName,''),' ',r.LastName,' ',COALESCE(r.Suffix,'')) AS FullName,
               (SELECT COUNT(*) FROM residents m WHERE m.FamilyHeadID = r.ResidentID) AS MemberCount
        FROM residents r
        WHERE (r.FirstName LIKE ? OR r.LastName LIKE ? OR r.MiddleName LIKE ?
               OR r.Email LIKE ? OR r.ContactNumber LIKE ?)
        ORDER BY r.LastName ASC
        LIMIT ? OFFSET ?
    ");
    $stmtPage->execute([$like, $like, $like, $like, $like, RESIDENTS_PER_PAGE, $resOffset]);
} else {
    $stmtPage = $pdo->prepare("
        SELECT r.*,
               CONCAT(r.FirstName,' ',COALESCE(r.MiddleName,''),' ',r.LastName,' ',COALESCE(r.Suffix,'')) AS FullName,
               (SELECT COUNT(*) FROM residents m WHERE m.FamilyHeadID = r.ResidentID) AS MemberCount
        FROM residents r
        ORDER BY r.LastName ASC
        LIMIT ? OFFSET ?
    ");
    $stmtPage->execute([RESIDENTS_PER_PAGE, $resOffset]);
}
$pagedResidents = $stmtPage->fetchAll(PDO::FETCH_ASSOC);

// ── Access requests list ──────────────────────────────────────────
$accessRequests = $pdo->query("
    SELECT request_id AS id, fullname,
           firstname AS first_name, middlename AS middle_name, lastname AS last_name,
           email, contact_number, birthdate, house_no, street,
           valid_id_path, request_message,
           status, submitted_at AS created_at, admin_reason, resident_id
    FROM   access_requests
    ORDER  BY FIELD(status,'Pending','Approved','Disapproved','Matched','For Profiling','For Correction','Rejected'), submitted_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Dashboard Statistics (unchanged) ─────────────────────────────
$total_pop = count($residents_data);
$seniors   = count(array_filter($residents_data, fn($r) => (int)$r['IsSenior'] === 1));
$pwds      = count(array_filter($residents_data, fn($r) => (int)$r['IsPWD'] === 1));
$heads     = count(array_filter($residents_data, fn($r) => (int)$r['IsHead'] === 1));
$deceased  = count(array_filter($residents_data, fn($r) => (int)$r['IsDeceased'] === 1));

// Aggregated data for the clickable Total Population analytics modal.
$paMale = $paFemale = $paUnder18 = $paAdult = $paSeniorAge = 0;
$todayAnalytics = new DateTime();
foreach ($residents_data as $row) {
    $sex = strtolower(trim((string)($row['Sex'] ?? '')));
    if ($sex === 'male') $paMale++;
    elseif ($sex === 'female') $paFemale++;
    if (!empty($row['BirthDate'])) {
        try {
            $age = $todayAnalytics->diff(new DateTime((string)$row['BirthDate']))->y;
            if ($age < 18) $paUnder18++; elseif ($age < 60) $paAdult++; else $paSeniorAge++;
        } catch (Throwable $ignore) {}
    }
}
$populationAnalytics = [
    'total'=>$total_pop, 'male'=>$paMale, 'female'=>$paFemale,
    'other'=>max(0,$total_pop-$paMale-$paFemale), 'deceased'=>$deceased,
    'seniors'=>$seniors, 'pwd'=>$pwds, 'heads'=>$heads,
    'under18'=>$paUnder18, 'adult'=>$paAdult, 'senior'=>$paSeniorAge
];

$current_page = 'Residents';
?>
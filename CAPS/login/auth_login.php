<?php
session_start();
// ─── DB connection ────────────────────────────────────────────────────────────
// More robust version:
// db.php lives under ADMIN/backend/ now (shared with the dashboard),
// and it already require_once's security.php, which is where
// caps_log_security() comes from — no more separate security_log.php.
require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/security_log.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function redirect_error(string $code): never {
    header("Location: login.php?error=$code");
    exit;
}

function sanitize(string $value): string {
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

// ─── 1. CSRF Validation ───────────────────────────────────────────────────────
if (
    empty($_POST['csrf_token']) ||
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
    redirect_error('csrf');
}
// Rotate token immediately after use
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// ─── 2. Input collection & sanitization ──────────────────────────────────────
$identifier = sanitize($_POST['identifier'] ?? '');
$password   = $_POST['password'] ?? '';     // raw; verified with password_verify()
$remember   = isset($_POST['remember_me']);

if ($identifier === '' || $password === '') {
    redirect_error('invalid');
}

// ─── 3. Rate limiting (brute-force protection) ────────────────────────────────
// Uses PHP session to track per-IP attempt counts.
$ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$key = 'login_attempts_' . md5($ip);

if (!isset($_SESSION[$key])) {
    $_SESSION[$key] = ['count' => 0, 'first' => time(), 'locked_until' => 0];
}

$attempts = &$_SESSION[$key];

// Check if currently locked
if ($attempts['locked_until'] > time()) {
    redirect_error('locked');
}

// Reset window if older than 15 minutes
if (time() - $attempts['first'] > 900) {
    $attempts = ['count' => 0, 'first' => time(), 'locked_until' => 0];
}

// ─── 4. Try ADMIN login first ─────────────────────────────────────────────────
$admin = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM admin WHERE Username = ? OR Email = ? LIMIT 1");
    $stmt->execute([$identifier, $identifier]);
    $admin = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Auth admin query error: " . $e->getMessage());
}

if ($admin && password_verify($password, $admin['Password'])) {
    // ── Admin authenticated ──────────────────────────────────────────────────

    // Reset failed attempts
    $attempts = ['count' => 0, 'first' => time(), 'locked_until' => 0];

    // Regenerate session to prevent fixation
    session_regenerate_id(true);

    // Set session variables
    $_SESSION['admin_id']   = $admin['AdminID'];
    $_SESSION['admin_name'] = sanitize($admin['Name'] ?? $admin['Username']);
    $_SESSION['role']       = 'admin';
    $_SESSION['ip']         = $ip;
    $_SESSION['ua']         = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $_SESSION['login_time'] = time();

    // Remember me cookie (admin)
    if ($remember) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['remember_token'] = $token;
        setcookie(
            'remember_admin',
            $token,
            [
                'expires'  => time() + (7 * 24 * 3600),
                'path'     => '/',
                'secure'   => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    log_security_event('LOGIN_SUCCESS', 'Admin login: ' . ($admin['Name'] ?? $identifier), $admin['Name'] ?? $identifier);
    header('Location: /CAPS/admin/dashboard/frontend/dashboard.php');
    exit;
}

// ─── 5. Try STAFF login ───────────────────────────────────────────────────────
$staff = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM staff WHERE WorkEmail = ? LIMIT 1");
    $stmt->execute([$identifier]);
    $staff = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Auth staff query error: " . $e->getMessage());
}

if ($staff && !empty($staff['Password']) && password_verify($password, $staff['Password'])) {
    // ── Staff authenticated ──────────────────────────────────────────────────

    // Blocked (e.g. relocated) or ended assignment: the account cannot be used.
    if (!empty($staff['IsBlocked'])) {
        log_security_event('LOGIN_BLOCKED', 'Blocked staff login: ' . $staff['Name'], $staff['Name']);
        redirect_error('blocked');
    }
    if (!empty($staff['EffectiveEnd']) && $staff['EffectiveEnd'] <= date('Y-m-d')) {
        log_security_event('LOGIN_BLOCKED', 'Ended staff login: ' . $staff['Name'], $staff['Name']);
        redirect_error('ended');
    }

    // Reset failed attempts
    $attempts = ['count' => 0, 'first' => time(), 'locked_until' => 0];

    // Regenerate session
    session_regenerate_id(true);

    // Set session variables
    $_SESSION['employee_id'] = $staff['EmployeeID'];
    $_SESSION['staff_name']  = sanitize($staff['Name']);
    $_SESSION['workload']    = $staff['WorkLoad'];   // comma-separated string
    $_SESSION['role']        = 'staff';
    $_SESSION['ip']          = $ip;
    $_SESSION['ua']          = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $_SESSION['login_time']  = time();

    // Remember me cookie (staff)
    if ($remember) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['remember_token'] = $token;
        setcookie(
            'remember_staff',
            $token,
            [
                'expires'  => time() + (7 * 24 * 3600),
                'path'     => '/',
                'secure'   => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    log_security_event('LOGIN_SUCCESS', 'Staff login: ' . $staff['Name'], $staff['Name']);

    // RBAC: staff now lands on the SAME shared dashboard as admin —
    // there is no separate staff/dashboard.php anymore. The dashboard
    // file itself uses get_visible_modules()/staff_has_module_access()
    // to show only the widgets/shortcuts for this staff's assigned
    // modules. This is what makes "one shared codebase, no duplicated
    // module files" actually true.
    header('Location: /CAPS/admin/dashboard/frontend/dashboard.php');
    exit;
}

// ─── 6. Authentication failed ─────────────────────────────────────────────────
$attempts['count']++;

if ($attempts['count'] >= 5) {
    $attempts['locked_until'] = time() + 900;
    log_security_event('BRUTE_FORCE_LOCKOUT', 'Account locked after 5 failed attempts: ' . $identifier, $identifier);
    redirect_error('locked');
}

log_security_event('LOGIN_FAILED', 'Invalid credentials for: ' . $identifier, $identifier);
redirect_error('invalid');
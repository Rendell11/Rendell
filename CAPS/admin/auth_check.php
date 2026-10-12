<?php
// db.php already does require_once __DIR__.'/security.php', so we only
// need to pull in db.php here — no more duplicate admin_int/ path.
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    // Harden session cookie before starting
    session_set_cookie_params([
        'lifetime' => 0,           // expires when browser closes
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,        // not accessible via JS
        'samesite' => 'Strict',    // prevent CSRF via cookie
    ]);
    session_start();
}

// ── 1. Basic authentication check ────────────────────────────────────────────
if (empty($_SESSION['role']) || empty($_SESSION['ip'])) {
    header('Location: /CAPS/login/login.php?error=session');
    exit;
}

// ── 2. Session hijacking protection ──────────────────────────────────────────
$current_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$current_ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

if (
    $_SESSION['ip'] !== $current_ip ||
    $_SESSION['ua'] !== $current_ua
) {
    // Destroy suspicious session
    session_unset();
    session_destroy();
    header('Location: /CAPS/login/login.php?error=session');
    exit;
}

// ── 3. Session timeout (2 hours of inactivity) ────────────────────────────────
$session_timeout = 7200;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $session_timeout) {
    session_unset();
    session_destroy();
    header('Location: /CAPS/login/login.php?error=session');
    exit;
}
$_SESSION['last_activity'] = time();

// ── 3b. Staff blocked / ended while logged in → log out ──────────────────────
if (($_SESSION['role'] ?? '') === 'staff' && !empty($_SESSION['employee_id']) && isset($pdo)) {
    try {
        $st = $pdo->prepare('SELECT * FROM staff WHERE EmployeeID = ? LIMIT 1');
        $st->execute([$_SESSION['employee_id']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $why = !$row ? 'session'
            : (!empty($row['IsBlocked']) ? 'blocked'
            : ((!empty($row['EffectiveEnd']) && $row['EffectiveEnd'] <= date('Y-m-d')) ? 'ended' : ''));
        if ($why !== '') {
            session_unset();
            session_destroy();
            header('Location: /CAPS/login/login.php?error=' . $why);
            exit;
        }
    } catch (Throwable $e) { /* staff table unavailable — keep the session */ }
}

// ── 4. Role check ─────────────────────────────────────────────────────────────
// $required_role may be 'admin', 'staff', or null (any authenticated user)
if (!empty($required_role) && $_SESSION['role'] !== $required_role) {
    header('Location: /CAPS/login/login.php?error=unauthorized');
    exit;
}

// ── 5. Build allowed modules array for staff ──────────────────────────────────
$allowed_modules = [];

if ($_SESSION['role'] === 'staff' && !empty($_SESSION['workload'])) {
    $allowed_modules = array_map('trim', explode(',', $_SESSION['workload']));
    $allowed_modules = array_filter($allowed_modules); // remove empties
    $allowed_modules = array_values($allowed_modules);
}

// ── 6. Module permission check ────────────────────────────────────────────────
// $required_module may be set by the including page
if (!empty($required_module) && $_SESSION['role'] === 'staff') {
    if (!in_array($required_module, $allowed_modules)) {
        error_log('[CAPS-SECURITY] UNAUTHORIZED_ACCESS | Module:' . ($required_module ?? 'unknown') . ' | Staff:' . ($_SESSION['employee_id'] ?? 'unknown') . ' | IP:' . ($_SERVER['REMOTE_ADDR'] ?? ''));
        header('Location: /CAPS/login/unauthorized.php');
        exit;
    }
}

// ── 7. Helper function ────────────────────────────────────────────────────────
if (!function_exists('staff_has_module')) {
    function staff_has_module(string $module): bool {
        global $allowed_modules;
        return in_array(strtolower(trim($module)), array_map('strtolower', $allowed_modules));
    }
}
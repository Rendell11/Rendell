<?php
/**
 * user/backend/logout.php — end the app session.
 *   POST                → revoke this device's login token
 *   POST all=1          → revoke every token of the resident (log out all devices)
 */
declare(strict_types=1);
require __DIR__ . '/config.php';
handle_preflight();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') respond(false, L('POST lang ang tinatanggap.', 'Only POST is accepted.'), null, 405);
$session = auth_session();
if (!$session) respond(true, ''); // already signed out
try {
    if (($_POST['all'] ?? '') === '1') {
        db()->prepare('UPDATE resident_sessions SET revoked_at = NOW() WHERE resident_id = ? AND revoked_at IS NULL')
            ->execute([(int) $session['resident_id']]);
        respond(true, L('Naka-log out ka na sa lahat ng device.', 'You are logged out on all devices.'));
    }
    db()->prepare('UPDATE resident_sessions SET revoked_at = NOW() WHERE id = ?')->execute([(int) $session['id']]);
    respond(true, '');
} catch (Throwable $e) {
    error_log('[logout] ' . $e->getMessage());
    respond(false, 'Server error.', null, 500);
}

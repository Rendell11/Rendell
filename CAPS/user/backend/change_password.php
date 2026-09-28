<?php
// JSON API — Settings → Change password (resident_id, current_password, new_password).
declare(strict_types=1);
require __DIR__ . '/lib.php';
handle_preflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, L('POST lang ang tinatanggap.', 'Only POST is accepted.'), null, 405);
try {
    $rid = require_resident();
    $r = change_resident_password(
        $rid,
        (string)($_POST['current_password'] ?? ''),
        (string)($_POST['new_password'] ?? '')
    );
} catch (Throwable $e) {
    respond(false, 'Server error.', null, 500);
}
if ($r['ok']) {
    // Sign out every other device that used the old password.
    try {
        db()->prepare('UPDATE resident_sessions SET revoked_at = NOW()
                       WHERE resident_id = ? AND revoked_at IS NULL AND id <> ?')
            ->execute([$rid, (int) (auth_session()['id'] ?? 0)]);
    } catch (Throwable $e) { /* ignore */ }
}
respond($r['ok'], $r['message'], $r['data'], $r['ok'] ? 200 : 422);

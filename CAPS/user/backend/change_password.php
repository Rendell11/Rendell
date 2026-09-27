<?php
// JSON API — Settings → Change password (resident_id, current_password, new_password).
declare(strict_types=1);
require __DIR__ . '/lib.php';
handle_preflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, L('POST lang ang tinatanggap.', 'Only POST is accepted.'), null, 405);
try {
    $r = change_resident_password(
        (int)($_POST['resident_id'] ?? 0),
        (string)($_POST['current_password'] ?? ''),
        (string)($_POST['new_password'] ?? '')
    );
} catch (Throwable $e) {
    respond(false, 'Server error.', null, 500);
}
respond($r['ok'], $r['message'], $r['data'], $r['ok'] ? 200 : 422);

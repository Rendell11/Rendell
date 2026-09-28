<?php
// JSON API — STEP 4.
declare(strict_types=1);
require __DIR__ . '/lib.php';
handle_preflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, L('POST lang ang tinatanggap.', 'Only POST is accepted.'), null, 405);
try {
    $r = resident_login((string)post('identifier', ''), (string)($_POST['password'] ?? ''));
} catch (Throwable $e) {
    respond(false, 'Server error.', null, 500);
}
if ($r['ok']) {
    try {
        // Login token for every later request (auth.php, sent as X-Auth-Token).
        $r['data']['token'] = auth_issue_token((int) $r['data']['ResidentID'], post('device'), post('platform'));
    } catch (Throwable $e) {
        error_log('[login] token: ' . $e->getMessage());
        respond(false, 'Server error.', null, 500);
    }
}
respond($r['ok'], $r['message'], $r['data'], $r['ok'] ? 200 : 401);

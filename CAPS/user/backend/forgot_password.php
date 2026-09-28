<?php
// JSON API — forgot password with a 6-digit email code (lib.php):
//   POST email                              → sends the code
//   POST action=reset, email, code, password → sets the new password
declare(strict_types=1);
require __DIR__ . '/lib.php';
handle_preflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, L('POST lang ang tinatanggap.', 'Only POST is accepted.'), null, 405);
try {
    if (post('action', '') === 'reset') {
        $r = reset_password_with_code((string) post('email', ''), (string) post('code', ''), (string) ($_POST['password'] ?? ''));
    } else {
        $r = request_password_reset((string) post('email', ''));
    }
} catch (Throwable $e) {
    error_log('[forgot_password] ' . $e->getMessage());
    respond(false, 'Server error.', null, 500);
}
respond($r['ok'], $r['message'], $r['data'], $r['ok'] ? 200 : 422);

<?php
// JSON API — STEP 3.
declare(strict_types=1);
require __DIR__ . '/lib.php';
handle_preflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, L('POST lang ang tinatanggap.', 'Only POST is accepted.'), null, 405);
try {
    $r = set_resident_password((string)post('token', ''), (string)($_POST['password'] ?? ''));
} catch (Throwable $e) {
    respond(false, 'Server error.', null, 500);
}
respond($r['ok'], $r['message'], $r['data'], $r['ok'] ? 200 : 400);

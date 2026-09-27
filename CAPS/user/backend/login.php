<?php
// JSON API — STEP 4.
declare(strict_types=1);
require __DIR__ . '/lib.php';
handle_preflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, 'POST lang ang tinatanggap.', null, 405);
try {
    $r = resident_login((string)post('identifier', ''), (string)($_POST['password'] ?? ''));
} catch (Throwable $e) {
    respond(false, 'Server error.', null, 500);
}
respond($r['ok'], $r['message'], $r['data'], $r['ok'] ? 200 : 401);

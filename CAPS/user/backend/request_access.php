<?php
// JSON API for the Flutter app — STEP 1. Delegates to create_access_request().
declare(strict_types=1);
require __DIR__ . '/lib.php';
handle_preflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, 'POST lang ang tinatanggap.', null, 405);
try {
    $r = create_access_request($_POST);
} catch (Throwable $e) {
    respond(false, 'Server error.', null, 500);
}
respond($r['ok'], $r['message'], $r['data'], $r['ok'] ? 200 : 422);

<?php
// JSON API — purok dropdown options for the request form.
declare(strict_types=1);
require __DIR__ . '/lib.php';
handle_preflight();
try {
    respond(true, 'OK', list_puroks());
} catch (Throwable $e) {
    respond(false, 'Server error.', null, 500);
}

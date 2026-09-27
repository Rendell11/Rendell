<?php
// JSON API — admin-configured appearance for the pre-login screens.
// Returns { accent_color: "#RRGGBB", color_mode: "light"|"dark" }.
declare(strict_types=1);
require __DIR__ . '/lib.php';
handle_preflight();
try {
    $adminId = isset($_GET['admin_id']) ? (int)$_GET['admin_id'] : 1;
    respond(true, 'OK', get_public_theme($adminId));
} catch (Throwable $e) {
    respond(true, 'OK', ['accent_color' => '#1D63DA', 'color_mode' => 'light']);
}

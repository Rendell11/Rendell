<?php
// JSON API — admin-managed barangay address data for the request form.
//   ?action=profile          → default barangay address (barangay_profile)
//   ?action=streets&barangay= → active streets (resident_streets)
//   ?action=areas&barangay=   → active areas/puroks (resident_areas)
// Mirrors admin/backend/address_api.php but public (no admin session needed).
declare(strict_types=1);
require __DIR__ . '/lib.php';
handle_preflight();

$action   = $_GET['action'] ?? 'profile';
$barangay = trim((string)($_GET['barangay'] ?? ''));

try {
    switch ($action) {
        case 'profile':
            $p = get_barangay_profile();
            respond($p !== null, $p !== null ? 'OK' : 'Hindi pa naka-configure ang barangay address.', $p);
            break;
        case 'streets':
            respond(true, 'OK', list_streets($barangay));
            break;
        case 'areas':
            respond(true, 'OK', list_areas($barangay));
            break;
        default:
            respond(false, 'Unknown action.', null, 400);
    }
} catch (Throwable $e) {
    respond(false, 'Server error.', null, 500);
}

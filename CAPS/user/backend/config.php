<?php
/**
 * Shared bootstrap + JSON helpers for the resident-access module.
 *
 * Used by BOTH:
 *   - backend/*.php  → JSON API for the Flutter mobile app
 *   - frontend/*.php → server-rendered pages you open in a browser
 *
 * The actual data logic lives in lib.php (so both callers share one source of
 * truth). Adjust the DB credentials below to match your XAMPP/MySQL setup.
 * The database is the one built by the "Rebuild Database" script (barangay_db).
 */

declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_NAME = 'barangay_db';
const DB_USER = 'root';
const DB_PASS = '';          // XAMPP default is empty
const DB_CHARSET = 'utf8mb4';

/** Where uploaded valid-ID / selfie images are written (relative to backend/). */
const UPLOAD_DIR = __DIR__ . '/uploads';
/** Public URL prefix for those images, relative to the CAPS project root. */
const UPLOAD_URL = 'user/backend/uploads';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // ── Prefer the project's shared db.php so the app ALWAYS talks to the same
    //    database as the admin site. The admin's db.php lives at CAPS/admin/db.php.
    //    From CAPS/user/backend/ that is ../../admin/db.php. Try the common spots.
    foreach ([
        __DIR__ . '/../../admin/db.php',   // CAPS/admin/db.php  (the admin's connection)
        __DIR__ . '/../../db.php',         // CAPS/db.php
        __DIR__ . '/../../../db.php',
        __DIR__ . '/../../staff/db.php',
    ] as $shared) {
        if (is_file($shared)) {
            try {
                ob_start();
                require $shared;              // expected to define $pdo
                ob_end_clean();
                if (isset($pdo) && $pdo instanceof PDO) {
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                    return $pdo;
                }
            } catch (Throwable $e) {
                if (ob_get_level() > 0) ob_end_clean();
                // fall through to the local constants below
            }
        }
    }

    // ── Fallback: connect with the constants above (used only if db.php
    //    cannot be found or did not define $pdo).
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

/** Send a uniform JSON envelope and stop (used by the API endpoints). */
function respond(bool $success, string $message, $data = null, int $code = 200): void
{
    if (!headers_sent()) {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($code);
    }
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Handle a CORS preflight for the API endpoints. */
function handle_preflight(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        http_response_code(204);
        exit;
    }
}

/** Trimmed POST field or a default. */
function post(string $key, ?string $default = null): ?string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

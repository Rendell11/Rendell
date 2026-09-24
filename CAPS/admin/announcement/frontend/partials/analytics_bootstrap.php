<?php
/**
 * partials/analytics_bootstrap.php
 * Common start-up for the two analytics pages in the Announcement module:
 *   - frontend/disaster_analytics.php
 *   - frontend/announcement_analytics.php
 *
 * Loads the DB, authentication, permission check, CSRF token and theme, then
 * exposes the barangay identity used on the printed / PDF report letterhead:
 *   $brgy_name, $brgy_address, $brgy_logo_url, $brgy_captain, $prepared_by
 */

require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../../../auth_check.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    throw new RuntimeException('Database PDO connection is not available. Expected $pdo from admin/db.php');
}

require_once __DIR__ . '/../../../permission_helper.php';
require_permission($pdo, 'announcements', 'read');
require_once __DIR__ . '/../../backend/csrf_helper.php';

// ── Barangay identity for the report letterhead (same sources as ann.php) ───
$brgy_name = 'Barangay';
$brgy_address = '';
$brgy_logo_url = '';
$brgy_captain = 'Barangay Captain';
$prepared_by = $_SESSION['admin_name'] ?? $_SESSION['staff_name'] ?? $_SESSION['username'] ?? 'Administrator';

try {
    $bp = $pdo->query("SELECT * FROM barangay_profile WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($bp) {
        if (!empty($bp['brgy_name'])) {
            $brgy_name = $bp['brgy_name'];
        }
        $brgy_address = $bp['address'] ?? '';

        // logo_path is stored relative to the frontend folder (same rule as ann.php)
        $frontendDir = dirname(__DIR__);
        if (!empty($bp['logo_path']) && file_exists($frontendDir . '/' . $bp['logo_path'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $baseDir = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '')), '/');
            $brgy_logo_url = $scheme . '://' . $host . $baseDir . '/' . ltrim($bp['logo_path'], '/');
        }
    }
} catch (PDOException $e) { /* keep defaults */
}

try {
    $cap = $pdo->query(
        "SELECT COALESCE(NULLIF(TRIM(CONCAT_WS(' ', r.FirstName, r.LastName, r.Suffix)), ''), '') AS CaptainName
           FROM officials o
           LEFT JOIN residents r ON r.ResidentID = o.ResidentID
          WHERE o.Position LIKE '%Captain%'
          ORDER BY (o.TermEnd IS NULL OR o.TermEnd >= CURDATE()) DESC, o.OfficialID DESC
          LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (!empty($cap['CaptainName'])) {
        $brgy_captain = $cap['CaptainName'];
    }
} catch (PDOException $e) { /* keep default */
}

require_once __DIR__ . '/../../../theme_loader.php';

$current_page = 'Announcements';
$_theme_head_loaded = true; // theme_head.php is included inside <head> by analytics_head.php

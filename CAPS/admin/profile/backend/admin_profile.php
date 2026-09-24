<?php
/**
 * ADMIN/backend/admin_profile.php
 * Barangay Profile Management — business logic only.
 *
 * Lets the admin update:
 *  • Barangay name & logo/photo
 *  • About / Description
 *  • Vision & Mission
 *  • Office Hours
 *  • Emergency Hotline Numbers (CRUD)
 *
 * DB table created on first load (self-bootstrapping migration).
 * No HTML here — UI rendering lives in ADMIN/frontend/admin_profile.php,
 * which requires this file first, then just prints the variables set below.
 */

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../activity_log_helper.php';
$current_page = 'Barangay Profile';
date_default_timezone_set('Asia/Manila');

// ── Self-healing migration: create barangay_profile table if absent ───────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `barangay_profile` (
            `id`           TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `brgy_name`    VARCHAR(255)     NOT NULL DEFAULT 'Barangay Biñang 2nd',
            `logo_path`    VARCHAR(512)     NULL,
            `about`        TEXT             NULL,
            `vision`       TEXT             NULL,
            `mission`      TEXT             NULL,
            `office_hours` VARCHAR(512)     NOT NULL DEFAULT 'Monday – Friday, 8:00 AM – 5:00 PM',
            `address`      VARCHAR(512)     NULL,
            `email`        VARCHAR(255)     NULL,
            `facebook_url` VARCHAR(512)     NULL,
            `updated_at`   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // Seed a single row if none exists
    $pdo->exec("INSERT IGNORE INTO `barangay_profile` (`id`) VALUES (1)");

    // The table may already exist without these columns (e.g. created by an
    // older Rebuild_Database script) — add whatever is missing.
    foreach ([
        'about' => 'TEXT NULL', 'vision' => 'TEXT NULL', 'mission' => 'TEXT NULL',
        'office_hours' => "VARCHAR(512) NOT NULL DEFAULT 'Monday – Friday, 8:00 AM – 5:00 PM'",
        'address' => 'VARCHAR(512) NULL', 'email' => 'VARCHAR(255) NULL', 'facebook_url' => 'VARCHAR(512) NULL',
    ] as $col => $def) {
        $has = $pdo->query("SHOW COLUMNS FROM `barangay_profile` LIKE " . $pdo->quote($col))->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE `barangay_profile` ADD COLUMN `{$col}` {$def}");
        }
    }
} catch (PDOException $e) {
    error_log('[admin_profile] migration: ' . $e->getMessage());
}

// ── Upload helper ─────────────────────────────────────────────────────────────
// Stored under the shared CAPS/upload/ root (sibling of ADMIN/, USER/, STAFF/)
// so it's reachable from any front-end that needs to display it — same
// convention as barangaylogo.png living at the CAPS root.
$upload_dir = __DIR__ . '/../../../upload/brgy_profile/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// ── Handle POST saves ─────────────────────────────────────────────────────────
$success_msg = '';
$error_msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Save main profile ─────────────────────────────────────────────────────
    if ($action === 'save_profile') {
        $brgy_name    = trim($_POST['brgy_name']    ?? '');
        $about        = trim($_POST['about']        ?? '');
        $vision       = trim($_POST['vision']       ?? '');
        $mission      = trim($_POST['mission']      ?? '');
        $office_hours = trim($_POST['office_hours'] ?? '');
        $address      = trim($_POST['address']      ?? '');
        $email        = trim($_POST['email']        ?? '');
        $facebook_url = trim($_POST['facebook_url'] ?? '');

        // Handle logo upload — supports both cropped base64 and raw file upload
        $logo_path = null;

        // Priority 1: cropped base64 from the crop modal
        $cropped_b64 = trim($_POST['logo_cropped_b64'] ?? '');
        if ($cropped_b64 !== '') {
            // Strip the data-URI header (e.g. "data:image/png;base64,")
            if (preg_match('/^data:image\/(\w+);base64,/', $cropped_b64, $m)) {
                $img_data = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $cropped_b64));
                if ($img_data !== false) {
                    $filename = 'brgy_logo_' . time() . '.png';
                    $dest     = $upload_dir . $filename;
                    if (file_put_contents($dest, $img_data) !== false) {
                        $logo_path = 'upload/brgy_profile/' . $filename;
                    } else {
                        $error_msg = 'Failed to save cropped logo. Check folder permissions.';
                    }
                } else {
                    $error_msg = 'Invalid cropped image data.';
                }
            } else {
                $error_msg = 'Unrecognised image format from cropper.';
            }
        }
        // Priority 2: plain file upload (no crop needed — image was within bounds)
        elseif (!empty($_FILES['logo']['name'])) {
            $file     = $_FILES['logo'];
            $allowed  = ['image/jpeg','image/png','image/webp','image/gif'];
            $max_size = 5 * 1024 * 1024; // 5 MB

            if (!in_array($file['type'], $allowed)) {
                $error_msg = 'Logo must be a JPG, PNG, WEBP, or GIF image.';
            } elseif ($file['size'] > $max_size) {
                $error_msg = 'Logo file must be under 5 MB.';
            } else {
                $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'brgy_logo_' . time() . '.' . $ext;
                $dest     = $upload_dir . $filename;
                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    $logo_path = 'upload/brgy_profile/' . $filename;
                } else {
                    $error_msg = 'Failed to upload logo. Check folder permissions.';
                }
            }
        }

        if (!$error_msg) {
            try {
                if ($logo_path) {
                    $stmt = $pdo->prepare("
                        UPDATE barangay_profile
                           SET brgy_name=?, logo_path=?, about=?, vision=?, mission=?,
                               office_hours=?, address=?, email=?, facebook_url=?
                         WHERE id=1
                    ");
                    $stmt->execute([$brgy_name,$logo_path,$about,$vision,$mission,$office_hours,$address,$email,$facebook_url]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE barangay_profile
                           SET brgy_name=?, about=?, vision=?, mission=?,
                               office_hours=?, address=?, email=?, facebook_url=?
                         WHERE id=1
                    ");
                    $stmt->execute([$brgy_name,$about,$vision,$mission,$office_hours,$address,$email,$facebook_url]);
                }
                $success_msg = 'Barangay profile saved successfully.';
                log_activity('Barangay Profile', 'Update Profile', 'Barangay profile updated (name, logo, contact info).');
            } catch (PDOException $e) {
                error_log('[admin_profile] save: ' . $e->getMessage());
                $error_msg = 'Database error. Please try again.';
            }
        }
    }

    // ── Add hotline ───────────────────────────────────────────────────────────
    if ($action === 'add_hotline') {
        $name   = trim($_POST['hotline_name']   ?? '');
        $number = trim($_POST['hotline_number'] ?? '');
        $color  = $_POST['hotline_color'] ?? 'blue';
        $allowed_colors = ['red','blue','emerald','purple','amber','rose','indigo'];
        if (!in_array($color, $allowed_colors)) $color = 'blue';

        if ($name && $number) {
            try {
                $pdo->prepare("INSERT INTO emergency_hotlines (name, number, color) VALUES (?,?,?)")
                    ->execute([$name, $number, $color]);
                $success_msg = 'Hotline added.';
                log_activity('Barangay Profile', 'Add Hotline', "Added emergency hotline: {$name} ({$number})");
            } catch (PDOException $e) {
                $error_msg = 'Could not add hotline.';
            }
        } else {
            $error_msg = 'Name and number are required.';
        }
    }

    // ── Edit hotline ──────────────────────────────────────────────────────────
    if ($action === 'edit_hotline') {
        $id     = (int)($_POST['hotline_id']     ?? 0);
        $name   = trim($_POST['hotline_name']   ?? '');
        $number = trim($_POST['hotline_number'] ?? '');
        $color  = $_POST['hotline_color'] ?? 'blue';
        $allowed_colors = ['red','blue','emerald','purple','amber','rose','indigo'];
        if (!in_array($color, $allowed_colors)) $color = 'blue';

        if ($id && $name && $number) {
            try {
                $pdo->prepare("UPDATE emergency_hotlines SET name=?, number=?, color=? WHERE id=?")
                    ->execute([$name, $number, $color, $id]);
                $success_msg = 'Hotline updated.';
                log_activity('Barangay Profile', 'Edit Hotline', "Updated emergency hotline ID {$id}: {$name} ({$number})");
            } catch (PDOException $e) {
                $error_msg = 'Could not update hotline.';
            }
        } else {
            $error_msg = 'All fields required.';
        }
    }

    // ── Delete hotline ────────────────────────────────────────────────────────
    if ($action === 'delete_hotline') {
        $id = (int)($_POST['hotline_id'] ?? 0);
        if ($id) {
            try {
                $pdo->prepare("DELETE FROM emergency_hotlines WHERE id=?")->execute([$id]);
                $success_msg = 'Hotline deleted.';
                log_activity('Barangay Profile', 'Delete Hotline', "Deleted emergency hotline ID {$id}.");
            } catch (PDOException $e) {
                $error_msg = 'Could not delete hotline.';
            }
        }
    }

    // Redirect to avoid resubmission (PRG pattern)
    $qs = $success_msg ? '?saved=1' : '?err=' . urlencode($error_msg);
    header('Location: ../frontend/admin_profile.php' . $qs);
    exit;
}

// ── Flash messages from redirect ──────────────────────────────────────────────
if (isset($_GET['saved']))  $success_msg = 'Barangay profile saved successfully.';
if (isset($_GET['err']))    $error_msg   = htmlspecialchars($_GET['err'], ENT_QUOTES, 'UTF-8');

// ── Fetch current profile ─────────────────────────────────────────────────────
$profile = [];
try {
    $profile = $pdo->query("SELECT * FROM barangay_profile WHERE id=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    error_log('[admin_profile] fetch: ' . $e->getMessage());
}

// ── Fetch hotlines ────────────────────────────────────────────────────────────
$hotlines = [];
try {
    $hotlines = $pdo->query("SELECT * FROM emergency_hotlines ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[admin_profile] hotlines: ' . $e->getMessage());
}

// Helpers
$p = fn(string $key, string $fallback = '') => htmlspecialchars($profile[$key] ?? $fallback, ENT_QUOTES, 'UTF-8');

// Color map for hotline badges
$color_map = [
    'red'     => ['bg' => '#fee2e2', 'fg' => '#dc2626', 'ring' => '#fca5a5'],
    'blue'    => ['bg' => '#dbeafe', 'fg' => '#2563eb', 'ring' => '#93c5fd'],
    'emerald' => ['bg' => '#d1fae5', 'fg' => '#059669', 'ring' => '#6ee7b7'],
    'purple'  => ['bg' => '#ede9fe', 'fg' => '#7c3aed', 'ring' => '#c4b5fd'],
    'amber'   => ['bg' => '#fef3c7', 'fg' => '#d97706', 'ring' => '#fcd34d'],
    'rose'    => ['bg' => '#ffe4e6', 'fg' => '#e11d48', 'ring' => '#fda4af'],
    'indigo'  => ['bg' => '#e0e7ff', 'fg' => '#4338ca', 'ring' => '#a5b4fc'],
];
?>

<?php
/**
 * ADMIN/settings/frontend/sms_settings.php  (ported from SOE admin_int/sms_settings.php)
 * Admin Portal — SMS Configuration Settings
 *
 * Manages SMS sender credentials used by the Disaster Alert Notification System.
 * Eliminates hardcoded credentials in process_disaster.php.
 *
 * DB table (auto-bootstrapped):
 *   CREATE TABLE IF NOT EXISTS sms_configurations (...)
 */

ob_start();

require_once __DIR__ . '/../../db.php';
$required_role = 'admin'; // SMS gateway credentials — admin only
require_once __DIR__ . '/../../auth_check.php';

// ── Theme ─────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../theme_loader.php';
require_once __DIR__ . '/../../activity_log_helper.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Sends ONE SMS through a gateway config — same request that
 * announcement/backend/process_disaster.php uses for disaster alerts, so a
 * successful test means disaster SMS will go through too.
 */
function sms_settings_send_test(array $cfg, string $to, string $message): array
{
    $phone = preg_replace('/[^0-9]/', '', $to);
    if (strlen($phone) < 10) {
        return ['ok' => false, 'detail' => 'Invalid mobile number.'];
    }
    if (substr($phone, 0, 2) === '63') {
        $to = '+' . $phone;
    } elseif (substr($phone, 0, 1) === '0') {
        $to = '+63' . substr($phone, 1);
    } else {
        $to = '+63' . $phone;
    }

    $ch = curl_init($cfg['api_url']);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['X-API-Key: ' . $cfg['api_key'], 'Content-Type: application/json'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['to' => $to, 'message' => $message, 'from' => $cfg['from_number'], 'channel' => 'sms']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false, // same as process_disaster.php (XAMPP often lacks a CA bundle)
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['ok' => false, 'detail' => 'Could not reach the SMS gateway: ' . $err];
    }
    $res = json_decode((string) $body, true);
    $ok = $code >= 200 && $code < 300 && (
        !empty($res['success']) || !empty($res['messageId']) || !empty($res['id'])
        || (isset($res['status']) && !in_array(strtolower((string) $res['status']), ['failed', 'error', 'rejected'], true))
    );
    $detail = is_array($res) ? ($res['message'] ?? $res['error']['message'] ?? $res['error'] ?? '') : '';
    return ['ok' => $ok, 'to' => $to, 'http' => $code, 'detail' => is_string($detail) ? $detail : json_encode($detail)];
}
$_theme_head_loaded = true; // theme_head.php included inside <head> below

// ── Bootstrap sms_configurations table ───────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sms_configurations (
            id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            configuration_name VARCHAR(100) NOT NULL,
            api_key            VARCHAR(255) NOT NULL,
            from_number        VARCHAR(30)  NOT NULL,
            device_id          VARCHAR(100) NOT NULL,
            api_url            VARCHAR(500) NOT NULL DEFAULT 'https://api.infinireach.io/api/v1/messages',
            status             ENUM('Active','Inactive') NOT NULL DEFAULT 'Inactive',
            created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (PDOException $e) {
    error_log('[SMSSettings] Table bootstrap: ' . $e->getMessage());
}

$current_page = 'Settings';
date_default_timezone_set('Asia/Manila');

$admin_id   = isset($_SESSION['admin_id'])    ? (int)$_SESSION['admin_id']
            : (isset($_SESSION['employee_id']) ? (int)$_SESSION['employee_id'] : 0);
$admin_name  = $_SESSION['admin_name']  ?? $_SESSION['username'] ?? 'Admin';
$admin_role  = $_SESSION['role']        ?? 'admin';

// ── Build prefs from theme (used by JS theme init) ────────────────────────────
$prefs = [
    'accent_color'      => $theme['accent_color']      ?? '#6366f1',
    'font_size'         => $theme['font_size']          ?? 'base',
    'animations'        => (int)($theme['animations']   ?? 1),
    'sidebar_collapsed' => (int)($theme['sidebar_collapsed'] ?? 0),
    'ui_density'        => $theme['ui_density']         ?? 'normal',
];

// ── AJAX / POST detection ─────────────────────────────────────────────────────
$_is_ajax = (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
);

$success = '';
$error   = '';

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])
    && (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token']))) {
    if ($_is_ajax) {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Your session has expired. Please refresh the page and try again.']);
        exit;
    }
    $error = 'Your session has expired. Please refresh the page and try again.';
    unset($_POST['action']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ── Send a test SMS with the active (or chosen) configuration ─────────────
    if ($_POST['action'] === 'test_sms') {
        $to = trim($_POST['test_number'] ?? '');
        $cfgId = (int) ($_POST['config_id'] ?? 0);
        try {
            $stmt = $cfgId
                ? $pdo->prepare("SELECT * FROM sms_configurations WHERE id = ?")
                : $pdo->prepare("SELECT * FROM sms_configurations WHERE status = 'Active' LIMIT 1");
            $stmt->execute($cfgId ? [$cfgId] : []);
            $cfg = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $cfg = null;
        }
        if (!$cfg) {
            $error = 'No SMS configuration to test. Add and activate one first.';
        } elseif ($to === '') {
            $error = 'Enter the mobile number that should receive the test SMS.';
        } else {
            $r = sms_settings_send_test($cfg, $to,
                'CAPS SMS test from Barangay Binang 2nd (' . date('M j, Y g:i A') . '). If you received this, disaster SMS alerts are working.');
            if ($r['ok']) {
                $success = 'Test SMS accepted by the gateway for ' . $r['to'] . ' using "' . $cfg['configuration_name'] . '". Check the phone.';
                log_activity('SMS Settings', 'Test SMS', "Sent a test SMS to {$r['to']} using configuration ID {$cfg['id']}.");
            } else {
                $error = 'Test SMS failed' . (!empty($r['http']) ? ' (HTTP ' . $r['http'] . ')' : '') . ': '
                    . ($r['detail'] !== '' ? $r['detail'] : 'the gateway did not accept the message. Check the API key, sender number and that the gateway phone/app is online.');
            }
        }
        if ($_is_ajax) {
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(!empty($success)
                ? ['success' => true,  'message' => $success]
                : ['success' => false, 'message' => $error]);
            exit;
        }
    }

    // ── Add Configuration ─────────────────────────────────────────────────────
    if ($_POST['action'] === 'add_config') {
        $cfg_name   = trim($_POST['configuration_name'] ?? '');
        $api_key    = trim($_POST['api_key']            ?? '');
        $from_num   = trim($_POST['from_number']        ?? '');
        $device_id  = trim($_POST['device_id']          ?? '');
        $api_url    = trim($_POST['api_url']            ?? '');
        $status     = ($_POST['status'] ?? 'Inactive') === 'Active' ? 'Active' : 'Inactive';

        if (empty($cfg_name) || empty($api_key) || empty($from_num) || empty($device_id) || empty($api_url)) {
            $error = 'All fields are required.';
        } else {
            try {
                $pdo->beginTransaction();

                // Deactivate all others if this one is Active
                if ($status === 'Active') {
                    $pdo->exec("UPDATE sms_configurations SET status = 'Inactive'");
                }

                $stmt = $pdo->prepare("
                    INSERT INTO sms_configurations
                        (configuration_name, api_key, from_number, device_id, api_url, status)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$cfg_name, $api_key, $from_num, $device_id, $api_url, $status]);
                $pdo->commit();
                $success = 'SMS configuration added successfully.';
                log_activity('SMS Settings', 'Add SMS Config', "Added SMS configuration: {$cfg_name} (status: {$status})");
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log('[SMSSettings] Add: ' . $e->getMessage());
                $error = 'Could not add configuration. Please try again.';
            }
        }
        if ($_is_ajax) {
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(!empty($success)
                ? ['success' => true,  'message' => $success]
                : ['success' => false, 'message' => $error]);
            exit;
        }
    }

    // ── Edit Configuration ────────────────────────────────────────────────────
    if ($_POST['action'] === 'edit_config') {
        $id         = (int)($_POST['config_id']          ?? 0);
        $cfg_name   = trim($_POST['configuration_name']  ?? '');
        $api_key    = trim($_POST['api_key']             ?? '');
        $from_num   = trim($_POST['from_number']         ?? '');
        $device_id  = trim($_POST['device_id']           ?? '');
        $api_url    = trim($_POST['api_url']             ?? '');
        $status     = ($_POST['status'] ?? 'Inactive') === 'Active' ? 'Active' : 'Inactive';

        if (!$id || empty($cfg_name) || empty($api_key) || empty($from_num) || empty($device_id) || empty($api_url)) {
            $error = 'All fields are required.';
        } else {
            try {
                $pdo->beginTransaction();

                if ($status === 'Active') {
                    $pdo->prepare("UPDATE sms_configurations SET status = 'Inactive' WHERE id != ?")
                        ->execute([$id]);
                }

                $pdo->prepare("
                    UPDATE sms_configurations
                    SET configuration_name = ?, api_key = ?, from_number = ?,
                        device_id = ?, api_url = ?, status = ?
                    WHERE id = ?
                ")->execute([$cfg_name, $api_key, $from_num, $device_id, $api_url, $status, $id]);

                $pdo->commit();
                $success = 'SMS configuration updated successfully.';
                log_activity('SMS Settings', 'Edit SMS Config', "Updated SMS configuration ID {$id}: {$cfg_name}");
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log('[SMSSettings] Edit: ' . $e->getMessage());
                $error = 'Could not update configuration. Please try again.';
            }
        }
        if ($_is_ajax) {
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(!empty($success)
                ? ['success' => true,  'message' => $success]
                : ['success' => false, 'message' => $error]);
            exit;
        }
    }

    // ── Activate Configuration ────────────────────────────────────────────────
    if ($_POST['action'] === 'activate_config') {
        $id = (int)($_POST['config_id'] ?? 0);
        if (!$id) {
            $error = 'Invalid configuration ID.';
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->exec("UPDATE sms_configurations SET status = 'Inactive'");
                $pdo->prepare("UPDATE sms_configurations SET status = 'Active' WHERE id = ?")
                    ->execute([$id]);
                $pdo->commit();
                $success = 'SMS configuration activated successfully.';
                log_activity('SMS Settings', 'Activate SMS Config', "Activated SMS configuration ID {$id}.");
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log('[SMSSettings] Activate: ' . $e->getMessage());
                $error = 'Could not activate configuration.';
            }
        }
        if ($_is_ajax) {
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(!empty($success)
                ? ['success' => true,  'message' => $success]
                : ['success' => false, 'message' => $error]);
            exit;
        }
    }

    // ── Delete Configuration ──────────────────────────────────────────────────
    if ($_POST['action'] === 'delete_config') {
        $id = (int)($_POST['config_id'] ?? 0);
        if (!$id) {
            $error = 'Invalid configuration ID.';
        } else {
            try {
                $pdo->prepare("DELETE FROM sms_configurations WHERE id = ?")
                    ->execute([$id]);
                $success = 'SMS configuration deleted.';
                log_activity('SMS Settings', 'Delete SMS Config', "Deleted SMS configuration ID {$id}.");
            } catch (PDOException $e) {
                error_log('[SMSSettings] Delete: ' . $e->getMessage());
                $error = 'Could not delete configuration.';
            }
        }
        if ($_is_ajax) {
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(!empty($success)
                ? ['success' => true,  'message' => $success]
                : ['success' => false, 'message' => $error]);
            exit;
        }
    }
}

// ── Load all configurations ───────────────────────────────────────────────────
$configs = [];
try {
    $configs = $pdo->query("SELECT * FROM sms_configurations ORDER BY status DESC, id ASC")
                   ->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[SMSSettings] Load: ' . $e->getMessage());
}

$active_config = null;
foreach ($configs as $c) {
    if ($c['status'] === 'Active') { $active_config = $c; break; }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
$name_parts = explode(' ', $admin_name);
$initials   = strtoupper(
    substr($name_parts[0], 0, 1) .
    (isset($name_parts[1]) ? substr($name_parts[1], 0, 1) : '')
);
?>
<!DOCTYPE html>
<html <?php echo $theme_attrs['html']; ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS Configuration — Admin Portal</title>

    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: { DEFAULT: 'var(--accent-600)', light: 'var(--accent-500)', dark: 'var(--accent-700)' },
                        accent:  { DEFAULT: 'var(--accent-500)', light: 'var(--accent-400)' },
                    },
                    fontFamily: { sans: ['Inter', 'sans-serif'] }
                }
            }
        }
    </script>

    <style>
        body { font-family: 'Inter', sans-serif; }
        .main-wrapper { margin-left: 272px; width: calc(100% - 272px); transition: margin-left .3s ease, width .3s ease; }
        body.sidebar-collapsed .main-wrapper { margin-left: 68px; width: calc(100% - 68px); }
        @media (max-width: 1023px) { .main-wrapper { margin-left: 0 !important; width: 100% !important; } }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .fade-up { animation: fadeInUp 0.4s ease both; }
        .d1 { animation-delay: 0.05s; }
        .d2 { animation-delay: 0.10s; }
        .d3 { animation-delay: 0.15s; }

        .settings-card {
            background: white;
            border: 1px solid #f1f5f9;
            border-radius: 1rem;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
            transition: box-shadow 0.2s ease;
        }
        .settings-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.07); }

        .tab-btn {
            display: flex; align-items: center; gap: 8px;
            padding: 10px 16px; border-radius: 10px;
            font-size: 13px; font-weight: 500;
            cursor: pointer; width: 100%; text-align: left;
            color: #64748b; transition: background 0.15s ease, color 0.15s ease;
            border: none; background: none;
        }
        .tab-btn:hover { background: #f8fafc; color: #1e293b; }
        .tab-btn.active { background: color-mix(in srgb, var(--accent-500) 10%, transparent); color: var(--accent-600); font-weight: 600; }
        .tab-btn .material-symbols-outlined { font-size: 18px; }
        .tab-btn-link {
            display: flex; align-items: center; gap: 8px;
            padding: 10px 16px; border-radius: 10px;
            font-size: 13px; font-weight: 500; font-family: inherit;
            width: 100%; text-align: left; box-sizing: border-box;
            color: #64748b; transition: background 0.15s ease, color 0.15s ease;
            text-decoration: none; outline: none;
            background: none; border: none; cursor: pointer;
        }
        .tab-btn-link:hover { background: #f8fafc; color: #1e293b; }
        .tab-btn-link.active { background: color-mix(in srgb, var(--accent-500) 10%, transparent); color: var(--accent-600); font-weight: 600; }
        .tab-btn-link .material-symbols-outlined { font-size: 18px; }

        /* Config table */
        .config-table { width: 100%; border-collapse: collapse; }
        .config-table th {
            padding: 10px 14px; text-align: left;
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.07em;
            color: #64748b; background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }
        .config-table td {
            padding: 14px; font-size: 13px; color: #334155;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        .config-table tr:last-child td { border-bottom: none; }
        .config-table tr:hover td { background: #fafbff; }

        /* Status badge */
        .badge-active   { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; background: #dcfce7; color: #166534; }
        .badge-inactive { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; background: #f1f5f9; color: #64748b; }
        .badge-dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; }
        .badge-active .badge-dot   { background: #16a34a; }
        .badge-inactive .badge-dot { background: #94a3b8; }

        /* Action buttons */
        .btn-action {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 6px 12px; border-radius: 9px; font-size: 11px; font-weight: 700;
            border: none; cursor: pointer; transition: all 0.2s;
            text-transform: uppercase; letter-spacing: 0.04em;
        }
        .btn-activate { background: color-mix(in srgb, var(--accent-600) 12%, transparent); color: var(--accent-700); }
        .btn-activate:hover { background: color-mix(in srgb, var(--accent-600) 22%, transparent); transform: translateY(-1px); }
        .btn-edit { background: #f1f5f9; color: #475569; }
        .btn-edit:hover { background: #e2e8f0; transform: translateY(-1px); }
        .btn-delete { background: #fff1f2; color: #be123c; }
        .btn-delete:hover { background: #ffe4e6; transform: translateY(-1px); }

        /* Modal */
        .modal-overlay {
            position: fixed; inset: 0; background: rgba(15,23,42,0.6);
            backdrop-filter: blur(6px); z-index: 1000;
            display: flex; align-items: center; justify-content: center;
            padding: 24px;
            opacity: 0; pointer-events: none;
            transition: opacity 0.2s ease;
        }
        .modal-overlay.open { opacity: 1; pointer-events: all; }
        .modal-box {
            background: white; border-radius: 1.5rem;
            box-shadow: 0 24px 64px rgba(0,0,0,0.18);
            width: 100%; max-width: 560px;
            max-height: 90vh;
            display: flex; flex-direction: column;
            overflow: hidden;
            transform: scale(0.96);
            transition: transform 0.25s ease, opacity 0.25s ease, max-width 0.3s ease;
            opacity: 0;
        }
        /* When side guide is open, widen the modal */
        .modal-box.guide-open { max-width: 960px; }
        .modal-overlay.open .modal-box { transform: scale(1); opacity: 1; }
        /* The <form> inside modal-box must also be a flex column so
           modal-body can scroll within the fixed modal height */
        .modal-box > form {
            display: flex; flex-direction: column;
            flex: 1; min-height: 0; overflow: hidden;
        }
        /* Inner split: form pane + guide pane side by side */
        .modal-split {
            display: flex; flex: 1; min-height: 0;
        }
        /* Left: the form */
        .modal-form-pane {
            flex: 0 0 auto; width: 560px;
            display: flex; flex-direction: column;
            min-height: 0;
        }
        .modal-form-pane .modal-body {
            flex: 1; min-height: 0; overflow-y: auto;
            padding: 24px 32px;
        }
        /* Right: guide side panel */
        .modal-guide-pane {
            flex: 0 0 0; width: 0; overflow: hidden;
            border-left: 1px solid transparent;
            transition: flex-basis 0.3s ease, width 0.3s ease, border-color 0.3s ease;
            display: flex; flex-direction: column;
        }
        .modal-box.guide-open .modal-guide-pane {
            flex-basis: 380px; width: 380px;
            border-color: #f1f5f9;
        }
        .guide-pane-inner {
            width: 380px; display: flex; flex-direction: column;
            height: 100%;
        }
        .guide-pane-header {
            flex-shrink: 0;
            display: flex; align-items: center; justify-content: space-between;
            padding: 18px 20px 14px;
            border-bottom: 1px solid #f1f5f9;
        }
        .guide-pane-stepper {
            flex-shrink: 0;
            display: flex; align-items: center;
            padding: 10px 20px;
            border-bottom: 1px solid #f1f5f9;
            gap: 0; overflow-x: auto;
        }
        .guide-pane-body {
            flex: 1; overflow-y: auto;
            padding: 16px 20px;
        }
        .guide-pane-footer {
            flex-shrink: 0;
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 20px;
            border-top: 1px solid #f1f5f9;
            background: rgba(248,250,252,0.5);
        }
        .modal-header {
            flex-shrink: 0;
            display: flex; align-items: center; justify-content: space-between;
            padding: 24px 32px;
            border-bottom: 1px solid #f1f5f9;
        }
        .modal-body {
            flex: 1; min-height: 0; overflow-y: auto;
            padding: 24px 32px;
        }
        .modal-footer {
            flex-shrink: 0;
            display: flex; align-items: center; justify-content: flex-end; gap: 12px;
            padding: 20px 32px;
            border-top: 1px solid #f1f5f9;
            background: rgba(248,250,252,0.5);
        }

        /* Form inputs — matches equipment.php detail modal style */
        .form-input {
            width: 100%;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 10px 14px;
            font-weight: 600;
            color: #334155;
            font-size: 13px;
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            box-sizing: border-box;
        }
        .form-input:focus {
            border-color: var(--accent-400);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent-500) 15%, transparent);
            background: white;
        }
        .form-input::placeholder { color: #cbd5e1; font-weight: 500; }
        .form-label {
            display: block;
            font-size: 9px; font-weight: 800;
            color: #94a3b8;
            margin-bottom: 6px;
            text-transform: uppercase; letter-spacing: 0.12em;
        }
        .form-group { display: flex; flex-direction: column; gap: 6px; }

        /* Active config banner */
        .active-banner {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border: 1.5px solid #86efac;
            border-radius: 12px;
            padding: 16px 20px;
        }

        /* Masked key */
        .masked-key {
            font-family: 'Courier New', monospace;
            font-size: 12px; color: #64748b;
            background: #f1f5f9;
            padding: 2px 8px; border-radius: 5px;
            letter-spacing: 0.04em;
        }

        /* Dark mode */
        html.dark .settings-card { background: #1e293b; border-color: #334155; }
        html.dark body { background: #0f172a; color: #e2e8f0; }
        html.dark .text-slate-800 { color: #f1f5f9; }
        html.dark .text-slate-700 { color: #e2e8f0; }
        html.dark .text-slate-600 { color: #cbd5e1; }
        html.dark .text-slate-500 { color: #94a3b8; }
        html.dark .text-slate-400 { color: #64748b; }
        html.dark .bg-slate-50    { background: #1e293b; }
        html.dark .bg-white       { background: #1e293b; }
        html.dark .border-slate-100 { border-color: #334155; }
        html.dark .border-slate-200 { border-color: #334155; }
        html.dark input, html.dark select, html.dark textarea {
            background: #0f172a !important;
            color: #e2e8f0 !important;
            border-color: #334155 !important;
        }
        html.dark .config-table th { background: #1e293b; color: #64748b; border-color: #334155; }
        html.dark .config-table td { color: #cbd5e1; border-color: #334155; }
        html.dark .config-table tr:hover td { background: #1e293b; }
        html.dark .modal-box { background: #1e293b; }
        html.dark .modal-header { border-color: #334155; }
        html.dark .modal-footer { border-color: #334155; background: rgba(15,23,42,0.5); }
        html.dark .form-input { background: #0f172a; color: #e2e8f0; border-color: #334155; }
        html.dark .form-input:focus { background: #0f172a; border-color: var(--accent-500); }
        html.dark .form-label { color: #64748b; }
        html.dark .tab-btn:hover { background: #1e293b; color: #e2e8f0; }
        html.dark .tab-btn.active { background: color-mix(in srgb, var(--accent-500) 15%, transparent); color: var(--accent-400); }
        html.dark .tab-btn-link:hover { background: #1e293b; color: #e2e8f0; }
        html.dark .tab-btn-link.active { background: color-mix(in srgb, var(--accent-500) 15%, transparent); color: var(--accent-400); }
        html.dark .active-banner { background: #052e16; border-color: #166534; }
        html.dark .masked-key { background: #334155; color: #94a3b8; }

        body, .settings-card, aside, header, input, select {
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
        }

        /* Toast */
        #toast-container { position: fixed; top: 1.25rem; right: 1.25rem; z-index: 99999; display: flex; flex-direction: column; gap: .6rem; pointer-events: none; }
        .toast { display: flex; align-items: center; gap: .75rem; padding: .85rem 1.1rem; border-radius: 1rem; box-shadow: 0 8px 28px rgba(0,0,0,.14); font-family: 'Inter', sans-serif; font-size: .75rem; font-weight: 700; min-width: 280px; max-width: 380px; pointer-events: all; transform: translateX(110%); opacity: 0; transition: transform .3s cubic-bezier(.34,1.56,.64,1), opacity .3s ease; }
        .toast.show { transform: translateX(0); opacity: 1; }
        .toast.hide { transform: translateX(110%); opacity: 0; }
        .toast-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .toast-error   { background: #fff1f2; border: 1px solid #fecaca; color: #991b1b; }
        .toast-warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .toast-info    { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
        .toast-icon    { font-size: 1.1rem; flex-shrink: 0; }
        .toast-msg     { flex: 1; line-height: 1.4; }
        .toast-close   { background: none; border: none; cursor: pointer; opacity: .5; padding: 0; font-size: 1rem; line-height: 1; flex-shrink: 0; color: inherit; }
        .toast-close:hover { opacity: 1; }
        .toast-bar     { position: absolute; bottom: 0; left: 0; height: 3px; border-radius: 0 0 1rem 1rem; animation: toastProgress linear forwards; }
        .toast-success .toast-bar { background: #10b981; }
        .toast-error   .toast-bar { background: #ef4444; }
        .toast-warning .toast-bar { background: #f59e0b; }
        .toast-info    .toast-bar { background: #3b82f6; }
        @keyframes toastProgress { from { width: 100%; } to { width: 0%; } }

        /* ── Setup Guide Panel ───────────────────────────────────────────── */
        .guide-step-title {
            font-size: 11px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.1em;
            color: var(--accent-700);
            margin-bottom: 10px;
        }
        .guide-ol {
            list-style: decimal; padding-left: 18px;
            display: flex; flex-direction: column; gap: 5px;
        }
        .guide-ol li { font-size: 12px; color: #475569; line-height: 1.55; }
        .guide-ol li strong { color: #1e293b; font-weight: 700; }
        .guide-code {
            font-family: 'Courier New', monospace; font-size: 11px;
            background: #f1f5f9; color: #475569;
            padding: 8px 12px; border-radius: 8px;
            border: 1px solid #e2e8f0;
            word-break: break-all; line-height: 1.5;
            margin-bottom: 10px;
        }
        .guide-example-label {
            font-size: 9px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.12em;
            color: #94a3b8; margin-bottom: 5px;
        }
        .guide-note {
            display: flex; align-items: flex-start; gap: 6px;
            padding: 8px 10px; border-radius: 8px;
            font-size: 11px; color: #475569; line-height: 1.5;
            background: color-mix(in srgb, var(--accent-500) 6%, transparent);
            border: 1px solid color-mix(in srgb, var(--accent-500) 18%, transparent);
        }
        .guide-path {
            display: inline-flex; align-items: center;
            background: #f1f5f9; color: #334155;
            font-size: 10px; font-weight: 700;
            padding: 1px 7px; border-radius: 5px;
            border: 1px solid #e2e8f0;
            font-family: 'Courier New', monospace;
        }
        .guide-link {
            color: var(--accent-600); font-weight: 700;
            text-decoration: underline; text-underline-offset: 2px;
            display: inline-flex; align-items: center; gap: 2px;
        }
        .guide-link:hover { color: var(--accent-700); }
        .guide-step-btn {
            color: #94a3b8; background: none; border: none; cursor: pointer;
            transition: color 0.15s, background 0.15s;
        }
        .guide-step-btn:hover { color: #334155; background: #f8fafc; }
        .guide-step-btn.guide-step-active { color: var(--accent-600); background: color-mix(in srgb, var(--accent-500) 10%, transparent); }
        .guide-field-hint {
            display: inline-flex; align-items: center; gap: 3px;
            font-size: 9px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.1em;
            color: var(--accent-600); background: none; border: none;
            cursor: pointer; padding: 0; line-height: 1;
            transition: color 0.15s;
        }
        .guide-field-hint:hover { color: var(--accent-700); }
        html.dark .guide-code { background: #0f172a; color: #94a3b8; border-color: #334155; }
        html.dark .guide-path { background: #0f172a; color: #cbd5e1; border-color: #334155; }
        html.dark .guide-ol li { color: #94a3b8; }
        html.dark .guide-ol li strong { color: #e2e8f0; }
        html.dark .guide-panel { border-color: #334155; }
        html.dark .modal-guide-pane { border-color: #334155 !important; }
        html.dark .guide-pane-header,
        html.dark .guide-pane-stepper,
        html.dark .guide-pane-footer { border-color: #334155; }
        html.dark .guide-pane-footer { background: rgba(15,23,42,0.5); }
    </style>
    <?php include __DIR__ . '/../../theme_head.php'; ?>
</head>

<body <?php echo $theme_attrs['body']; ?>>
<div id="toast-container"></div>
<div class="flex min-h-screen">

    <?php include __DIR__ . '/../../sidebar.php'; ?>

    <div class="flex-1 flex flex-col main-wrapper min-h-screen">

        <?php include __DIR__ . '/../../header.php'; ?>

        <main class="flex-1 p-4 sm:p-6 lg:p-8">

            <!-- Page heading -->
            <div class="mb-6 fade-up">
                <h1 class="text-2xl font-bold text-slate-800 tracking-tight">Settings</h1>
                <p class="text-slate-500 text-sm mt-1 font-medium">
                    Manage your account preferences and personalize your admin experience.
                </p>
            </div>

            <div class="flex flex-col lg:flex-row gap-6">

                <!-- ═══ Left: Tab navigation ═══ -->
                <div class="w-full lg:w-56 shrink-0 fade-up d1">
                    <div class="settings-card p-3 space-y-0.5">
                        <a href="settings.php?tab=appearance" class="tab-btn-link">
                            <span class="material-symbols-outlined">palette</span> Appearance
                        </a>
                        <a href="settings.php?tab=preferences" class="tab-btn-link">
                            <span class="material-symbols-outlined">tune</span> Preferences
                        </a>
                        <a href="settings.php?tab=security" class="tab-btn-link">
                            <span class="material-symbols-outlined">shield</span> Security
                        </a>
                        <a href="sms_settings.php" class="tab-btn-link active">
                            <span class="material-symbols-outlined">sms</span> SMS Configuration
                        </a>
                        <a href="settings.php?tab=facebook" class="tab-btn-link">
                            <span class="material-symbols-outlined">share</span> Facebook
                        </a>
                        <a href="settings.php?tab=system" class="tab-btn-link">
                            <span class="material-symbols-outlined">settings_applications</span> System
                        </a>
                        
                    </div>
                </div>

                <!-- ═══ Right: Content panel ═══ -->
                <div class="flex-1 min-w-0 space-y-5 fade-up d2">

                    <!-- Active Config Banner -->
                    <?php if ($active_config): ?>
                    <div class="active-banner flex items-start gap-3">
                        <span class="material-symbols-outlined text-green-600 text-[22px] mt-0.5">check_circle</span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-bold text-green-800">
                                Active: <?php echo htmlspecialchars($active_config['configuration_name']); ?>
                            </p>
                            <p class="text-xs text-green-700 mt-0.5">
                                Sender: <strong><?php echo htmlspecialchars($active_config['from_number']); ?></strong>
                                &nbsp;·&nbsp; Device ID: <code class="font-mono"><?php echo htmlspecialchars(substr($active_config['device_id'], 0, 8) . '…'); ?></code>
                                &nbsp;·&nbsp; API: <?php echo htmlspecialchars(parse_url($active_config['api_url'], PHP_URL_HOST)); ?>
                            </p>
                        </div>
                        <span class="text-xs text-green-600 font-semibold whitespace-nowrap">Disaster SMS Ready</span>
                    </div>
                    <?php else: ?>
                    <div class="flex items-start gap-3 p-4 bg-amber-50 border border-amber-200 rounded-xl">
                        <span class="material-symbols-outlined text-amber-500 text-[22px] mt-0.5">warning</span>
                        <div>
                            <p class="text-sm font-bold text-amber-800">No Active SMS Configuration</p>
                            <p class="text-xs text-amber-700 mt-0.5">
                                Disaster alert SMS notifications will not be sent until you activate a configuration below.
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Send Test SMS -->
                    <?php if (!empty($configs)): ?>
                    <div class="settings-card p-6">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="material-symbols-outlined text-[20px]" style="color:var(--accent-500)">send_to_mobile</span>
                            <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider">Send Test SMS</h2>
                        </div>
                        <p class="text-xs text-slate-500 mb-4">Sends one message through the gateway — the same way disaster alerts are sent — so you can confirm the setup works before an emergency.</p>
                        <form id="form-test" class="flex flex-wrap items-end gap-3" onsubmit="sendTestSms(event)">
                            <input type="hidden" name="action" value="test_sms">
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">Configuration</label>
                                <select name="config_id" class="form-input" style="min-width:220px">
                                    <?php foreach ($configs as $c): ?>
                                    <option value="<?php echo (int) $c['id']; ?>" <?php echo $c['status'] === 'Active' ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['configuration_name']) . ($c['status'] === 'Active' ? ' (Active)' : ''); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">Send to</label>
                                <input type="tel" name="test_number" class="form-input font-mono" placeholder="09XXXXXXXXX" required style="min-width:200px">
                            </div>
                            <button type="submit" id="btn-test-submit"
                                class="flex items-center gap-2 text-white px-5 py-2.5 rounded-xl font-bold text-[11px] uppercase tracking-widest shadow-lg" style="background:var(--accent-600);">
                                <span class="material-symbols-outlined text-[16px]">send</span> Send Test
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <!-- Configurations Table Card -->
                    <div class="settings-card overflow-hidden">
                        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-[20px]" style="color:var(--accent-500)">sim_card</span>
                                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider">SMS Configurations</h2>
                                <?php if (!empty($configs)): ?>
                                <span class="ml-1 text-xs bg-slate-100 text-slate-500 font-semibold px-2 py-0.5 rounded-full">
                                    <?php echo count($configs); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <button onclick="openAddModal()"
                                class="flex items-center gap-2 text-white px-5 py-2.5 rounded-xl font-bold text-[11px] uppercase tracking-widest transition-all shadow-lg hover:-translate-y-0.5" style="background:var(--accent-600);" onmouseover="this.style.background='var(--accent-700)'" onmouseout="this.style.background='var(--accent-600)'">
                                <span class="material-symbols-outlined text-[16px]">add</span>
                                Add Configuration
                            </button>
                        </div>

                        <?php if (empty($configs)): ?>
                        <div class="flex flex-col items-center justify-center py-16 text-center px-6">
                            <div class="w-16 h-16 rounded-2xl bg-slate-100 flex items-center justify-center mb-4">
                                <span class="material-symbols-outlined text-slate-400 text-[32px]">sms_failed</span>
                            </div>
                            <p class="text-sm font-bold text-slate-700 mb-1">No SMS configurations yet</p>
                            <p class="text-xs text-slate-400 max-w-xs mb-4">
                                Add your first SMS configuration to enable disaster alert notifications.
                            </p>
                            <button onclick="openAddModal()"
                                class="flex items-center gap-2 text-white px-5 py-2.5 rounded-xl font-bold text-[11px] uppercase tracking-widest transition-all shadow-lg hover:-translate-y-0.5" style="background:var(--accent-600);" onmouseover="this.style.background='var(--accent-700)'" onmouseout="this.style.background='var(--accent-600)'">
                                <span class="material-symbols-outlined text-[16px]">add</span>
                                Add Your First Configuration
                            </button>
                        </div>
                        <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="config-table">
                                <thead>
                                    <tr>
                                        <th>Configuration Name</th>
                                        <th>Sender Number</th>
                                        <th>Device ID</th>
                                        <th>API Key</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($configs as $cfg): ?>
                                    <tr>
                                        <td>
                                            <div class="font-semibold text-slate-800">
                                                <?php echo htmlspecialchars($cfg['configuration_name']); ?>
                                            </div>
                                            <div class="text-xs text-slate-400 mt-0.5">
                                                <?php echo htmlspecialchars(parse_url($cfg['api_url'], PHP_URL_HOST)); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="font-mono text-xs font-semibold text-slate-700">
                                                <?php echo htmlspecialchars($cfg['from_number']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="masked-key">
                                                <?php echo htmlspecialchars(substr($cfg['device_id'], 0, 8) . '…'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="masked-key">
                                                <?php
                                                $key = $cfg['api_key'];
                                                echo htmlspecialchars(substr($key, 0, 10) . '…' . substr($key, -4));
                                                ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($cfg['status'] === 'Active'): ?>
                                            <span class="badge-active">
                                                <span class="badge-dot"></span> Active
                                            </span>
                                            <?php else: ?>
                                            <span class="badge-inactive">
                                                <span class="badge-dot"></span> Inactive
                                            </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="flex items-center gap-1.5 flex-wrap">
                                                <?php if ($cfg['status'] !== 'Active'): ?>
                                                <button class="btn-action btn-activate"
                                                    onclick="activateConfig(<?php echo $cfg['id']; ?>, '<?php echo htmlspecialchars(addslashes($cfg['configuration_name'])); ?>')">
                                                    <span class="material-symbols-outlined text-[14px]">bolt</span>
                                                    Activate
                                                </button>
                                                <?php endif; ?>
                                                <button class="btn-action btn-edit"
                                                    onclick='openEditModal(<?php echo htmlspecialchars(json_encode($cfg), ENT_QUOTES); ?>)'>
                                                    <span class="material-symbols-outlined text-[14px]">edit</span>
                                                    Edit
                                                </button>
                                                <button class="btn-action btn-delete"
                                                    onclick="deleteConfig(<?php echo $cfg['id']; ?>, '<?php echo htmlspecialchars(addslashes($cfg['configuration_name'])); ?>')">
                                                    <span class="material-symbols-outlined text-[14px]">delete</span>
                                                    Delete
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Info Card -->
                    <div class="settings-card p-6">
                        <div class="flex items-center gap-2 mb-4">
                            <span class="material-symbols-outlined text-[20px]" style="color:var(--accent-500)">info</span>
                            <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider">How It Works</h2>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div class="flex gap-3">
                                <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0" style="background:color-mix(in srgb, var(--accent-500) 12%, transparent)">
                                    <span class="material-symbols-outlined text-[16px]" style="color:var(--accent-600)">bolt</span>
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-slate-700 mb-0.5">One Active Config</p>
                                    <p class="text-xs text-slate-500">Only one configuration is active at a time. Activating a new one automatically deactivates the previous.</p>
                                </div>
                            </div>
                            <div class="flex gap-3">
                                <div class="w-8 h-8 rounded-lg bg-green-100 flex items-center justify-center shrink-0">
                                    <span class="material-symbols-outlined text-green-600 text-[16px]">sim_card</span>
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-slate-700 mb-0.5">SIM Flexibility</p>
                                    <p class="text-xs text-slate-500">Switch to a different device or SIM card anytime without touching any source code.</p>
                                </div>
                            </div>
                            <div class="flex gap-3">
                                <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0" style="background:color-mix(in srgb, var(--accent-500) 12%, transparent)">
                                    <span class="material-symbols-outlined text-[16px]" style="color:var(--accent-600)">send</span>
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-slate-700 mb-0.5">Auto-Loaded</p>
                                    <p class="text-xs text-slate-500">Disaster alerts automatically load the active configuration when sending SMS notifications.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </main>
    </div>
</div>

<!-- ═══ Add Modal ═══ -->
<div id="modal-add" class="modal-overlay" onclick="if(event.target===this)closeModal('modal-add')">
    <div class="modal-box" style="max-width:680px">
        <!-- Header -->
        <div class="modal-header">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl flex items-center justify-center" style="background:color-mix(in srgb, var(--accent-500) 12%, transparent)">
                    <span class="material-symbols-outlined !text-lg" style="color:var(--accent-500)">add_circle</span>
                </div>
                <div>
                    <h3 class="text-sm font-extrabold text-slate-800 uppercase tracking-widest">Add SMS Configuration</h3>
                    <p class="text-[11px] text-slate-400 font-bold mt-0.5">Set up a new SMS sender configuration</p>
                </div>
            </div>
            <button onclick="closeModal('modal-add')"
                class="w-9 h-9 flex items-center justify-center rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-700 transition-colors">
                <span class="material-symbols-outlined !text-xl">close</span>
            </button>
        </div>

        <!-- Body -->
        <form id="form-add" onsubmit="submitForm(event,'add')">
            <div class="modal-body space-y-5">
                <input type="hidden" name="action" value="add_config">

                <!-- ── Setup Guide (collapsible) ───────────────────────────── -->
                <div class="guide-panel rounded-xl border border-slate-200 overflow-hidden">

                    <!-- Toggle button -->
                    <button type="button" id="guide-toggle"
                        onclick="toggleGuide()"
                        class="w-full flex items-center justify-between px-4 py-3 text-left transition-colors hover:bg-slate-50"
                        style="background:color-mix(in srgb, var(--accent-500) 6%, white)">
                        <div class="flex items-center gap-2.5">
                            <span class="material-symbols-outlined !text-base" style="color:var(--accent-500)">menu_book</span>
                            <span class="text-[11px] font-extrabold uppercase tracking-widest" style="color:var(--accent-700)">SMS Gateway Setup Guide</span>
                            <span class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">— How to configure a new device</span>
                        </div>
                        <span id="guide-chevron" class="material-symbols-outlined !text-base text-slate-400 transition-transform duration-200">expand_more</span>
                    </button>

                    <!-- Collapsible content -->
                    <div id="guide-content" class="hidden border-t border-slate-100">

                        <!-- Progress stepper strip -->
                        <div class="flex items-center gap-0 px-5 pt-4 pb-2 overflow-x-auto">
                            <?php
                            $steps = [
                                ['icon'=>'install_mobile',  'label'=>'Install App'],
                                ['icon'=>'person_add',      'label'=>'Create Account'],
                                ['icon'=>'key',             'label'=>'API Key'],
                                ['icon'=>'phone_android',   'label'=>'Device ID'],
                                ['icon'=>'sim_card',        'label'=>'Sender No.'],
                                ['icon'=>'link',            'label'=>'API URL'],
                                ['icon'=>'check_circle',    'label'=>'Activate'],
                            ];
                            foreach ($steps as $i => $s):
                            ?>
                            <div class="flex items-center shrink-0">
                                <button type="button"
                                    class="guide-step-btn flex flex-col items-center gap-1 px-2 py-1 rounded-lg transition-colors <?php echo $i===0?'guide-step-active':''; ?>"
                                    data-step="<?php echo $i+1; ?>"
                                    onclick="showGuideStep(<?php echo $i+1; ?>)">
                                    <span class="material-symbols-outlined !text-sm"><?php echo $s['icon']; ?></span>
                                    <span class="text-[8px] font-bold uppercase tracking-wider whitespace-nowrap"><?php echo $s['label']; ?></span>
                                </button>
                                <?php if ($i < count($steps)-1): ?>
                                <span class="text-slate-200 text-xs px-0.5">›</span>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Step panels -->
                        <div class="px-5 pb-5 pt-1">

                            <!-- Step 1: Install App -->
                            <div class="guide-step" id="gstep-1">
                                <p class="guide-step-title">Step 1 — Install the SMS Gateway App</p>
                                <ol class="guide-ol">
                                    <li>Download and install the <strong>SMS Gateway</strong> app on the Android device that will send SMS notifications.</li>
                                    <li>Insert an active SIM card with sufficient load or SMS credits.</li>
                                    <li>Ensure the device has a stable internet connection.</li>
                                    <li>Open the app and grant all required permissions:</li>
                                </ol>
                                <div class="grid grid-cols-2 gap-2 mt-2 ml-4">
                                    <?php foreach (['SMS Permission','Phone Permission','Notification Permission','Background Activity Permission','Battery Optimization Exemption (recommended)'] as $perm): ?>
                                    <div class="flex items-center gap-1.5">
                                        <span class="material-symbols-outlined !text-xs text-emerald-500">check_circle</span>
                                        <span class="text-[11px] text-slate-600"><?php echo $perm; ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Step 2: Create Account -->
                            <div class="guide-step hidden" id="gstep-2">
                                <p class="guide-step-title">Step 2 — Create an Infinireach Account</p>
                                <ol class="guide-ol">
                                    <li>Visit the <a href="https://app.infinireach.io" target="_blank" class="guide-link">Infinireach Dashboard <span class="material-symbols-outlined !text-[10px]">open_in_new</span></a>.</li>
                                    <li>Create an account or log in if you already have one.</li>
                                    <li>Verify your account if required.</li>
                                    <li>Navigate to <strong>API or Developer Settings</strong> after logging in.</li>
                                </ol>
                            </div>

                            <!-- Step 3: API Key -->
                            <div class="guide-step hidden" id="gstep-3">
                                <p class="guide-step-title">Step 3 — Obtain the API Key</p>
                                <ol class="guide-ol">
                                    <li>Log in to your <a href="https://app.infinireach.io" target="_blank" class="guide-link">Infinireach account <span class="material-symbols-outlined !text-[10px]">open_in_new</span></a>.</li>
                                    <li>From the left navigation, go to: <span class="guide-path">Automation → API Keys</span></li>
                                    <li>If no API key exists yet, click <strong>Generate API Key</strong>.</li>
                                    <li>Copy the generated key and paste it into the <strong>API Key</strong> field below.</li>
                                </ol>
                                <p class="guide-example-label">Example format</p>
                                <div class="guide-code">smsrelay_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx</div>
                                <div class="guide-note">
                                    <span class="material-symbols-outlined !text-xs shrink-0" style="color:var(--accent-500)">info</span>
                                    <span>Keep your API Key secure. If compromised, generate a new one from the Infinireach Dashboard and update this configuration.</span>
                                </div>
                            </div>

                            <!-- Step 4: Device ID -->
                            <div class="guide-step hidden" id="gstep-4">
                                <p class="guide-step-title">Step 4 — Obtain the Device ID</p>
                                <ol class="guide-ol">
                                    <li>Open the <strong>SMS Gateway app</strong> on the Android device.</li>
                                    <li>Link the device to your Infinireach account from within the app.</li>
                                    <li>After successful registration, locate the <strong>Device Information</strong> section.</li>
                                    <li>Copy the Device ID shown and paste it into the <strong>Device ID</strong> field below.</li>
                                </ol>
                                <p class="guide-example-label">Example format</p>
                                <div class="guide-code">c003d33c-db2c-43bb-9ce5-5e3b2b4a614a</div>
                            </div>

                            <!-- Step 5: Sender Number -->
                            <div class="guide-step hidden" id="gstep-5">
                                <p class="guide-step-title">Step 5 — Configure the Sender Number</p>
                                <ol class="guide-ol">
                                    <li>Use the mobile number of the SIM card installed in the SMS Gateway device.</li>
                                    <li>Always include the country code at the beginning.</li>
                                    <li>Enter this value in the <strong>Sender Number</strong> field below.</li>
                                </ol>
                                <p class="guide-example-label">Example format</p>
                                <div class="guide-code">+639123456789</div>
                            </div>

                            <!-- Step 6: API URL -->
                            <div class="guide-step hidden" id="gstep-6">
                                <p class="guide-step-title">Step 6 — API URL</p>
                                <p class="text-[12px] text-slate-600 mb-3">Use the default Infinireach endpoint unless specifically instructed otherwise. This field is pre-filled and should not need to be changed.</p>
                                <p class="guide-example-label">Default endpoint</p>
                                <div class="guide-code">https://api.infinireach.io/api/v1/messages</div>
                                <div class="guide-note">
                                    <span class="material-symbols-outlined !text-xs shrink-0" style="color:var(--accent-500)">info</span>
                                    <span>This field remains editable for future compatibility with other SMS gateway providers.</span>
                                </div>
                            </div>

                            <!-- Step 7: Activate -->
                            <div class="guide-step hidden" id="gstep-7">
                                <p class="guide-step-title">Step 7 — Activate the Configuration</p>
                                <ol class="guide-ol">
                                    <li>Fill in all required fields and click <strong>Save Configuration</strong>.</li>
                                    <li>From the configurations table, click <strong>Activate</strong> on the new entry.</li>
                                    <li>Only one configuration is active at a time — activating a new one deactivates others automatically.</li>
                                    <li>All Disaster Alert SMS notifications will use the active configuration.</li>
                                </ol>
                                <div class="mt-3 p-3 rounded-xl border border-slate-100 bg-slate-50 space-y-1.5">
                                    <p class="text-[9px] font-extrabold uppercase tracking-widest text-slate-400">Additional Notes</p>
                                    <?php foreach ([
                                        'The Android device must remain powered on at all times.',
                                        'The device must maintain an active internet connection.',
                                        'The SIM card must have sufficient load or SMS credits.',
                                        'If a SIM card is replaced, create a new configuration and activate it.',
                                        'No changes to process_disaster.php are required after setup.',
                                    ] as $note): ?>
                                    <div class="flex items-start gap-1.5">
                                        <span class="material-symbols-outlined !text-xs text-slate-400 mt-px shrink-0">arrow_right</span>
                                        <span class="text-[11px] text-slate-500"><?php echo $note; ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Step navigation -->
                            <div class="flex items-center justify-between mt-4 pt-3 border-t border-slate-100">
                                <button type="button" id="guide-prev"
                                    onclick="guideNav(-1)"
                                    class="flex items-center gap-1 px-3 py-1.5 rounded-lg border border-slate-200 text-slate-500 text-[10px] font-bold uppercase tracking-wider hover:bg-slate-50 transition-colors disabled:opacity-30 disabled:pointer-events-none">
                                    <span class="material-symbols-outlined !text-sm">chevron_left</span> Previous
                                </button>
                                <span id="guide-step-indicator" class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Step 1 of 7</span>
                                <button type="button" id="guide-next"
                                    onclick="guideNav(1)"
                                    class="flex items-center gap-1 px-3 py-1.5 rounded-lg text-white text-[10px] font-bold uppercase tracking-wider transition-all hover:-translate-y-px" style="background:var(--accent-600);" onmouseover="this.style.background='var(--accent-700)'" onmouseout="this.style.background='var(--accent-600)'">
                                    Next <span class="material-symbols-outlined !text-sm">chevron_right</span>
                                </button>
                            </div>

                        </div><!-- /px-5 -->
                    </div><!-- /guide-content -->
                </div><!-- /guide-panel -->

                <!-- ── Form fields ─────────────────────────────────────────── -->
                <div class="form-group">
                    <label class="form-label">Configuration Name <span class="text-red-400">*</span></label>
                    <input type="text" name="configuration_name" class="form-input"
                           placeholder="e.g. Main SIM – Globe" required>
                </div>
                <div class="form-group">
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="form-label !mb-0">API Key <span class="text-red-400">*</span></label>
                        <button type="button" onclick="showGuideStep(3);openGuide()" class="guide-field-hint">
                            <span class="material-symbols-outlined !text-[11px]">help</span> How to get this
                        </button>
                    </div>
                    <input type="text" name="api_key" class="form-input font-mono"
                           placeholder="smsrelay_…" required>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="form-group">
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="form-label !mb-0">Sender Number <span class="text-red-400">*</span></label>
                            <button type="button" onclick="showGuideStep(5);openGuide()" class="guide-field-hint">
                                <span class="material-symbols-outlined !text-[11px]">help</span> Help
                            </button>
                        </div>
                        <input type="text" name="from_number" class="form-input font-mono"
                               placeholder="+63912…" required>
                    </div>
                    <div class="form-group">
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="form-label !mb-0">Device ID <span class="text-red-400">*</span></label>
                            <button type="button" onclick="showGuideStep(4);openGuide()" class="guide-field-hint">
                                <span class="material-symbols-outlined !text-[11px]">help</span> Help
                            </button>
                        </div>
                        <input type="text" name="device_id" class="form-input font-mono"
                               placeholder="xxxxxxxx-xxxx-…" required>
                    </div>
                </div>
                <div class="form-group">
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="form-label !mb-0">API URL <span class="text-red-400">*</span></label>
                        <button type="button" onclick="showGuideStep(6);openGuide()" class="guide-field-hint">
                            <span class="material-symbols-outlined !text-[11px]">help</span> What is this?
                        </button>
                    </div>
                    <input type="url" name="api_url" class="form-input"
                           value="https://api.infinireach.io/api/v1/messages" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <div class="flex gap-4 p-3 bg-slate-50 rounded-xl border border-slate-100">
                        <label class="flex items-center gap-2 cursor-pointer group">
                            <input type="radio" name="status" value="Active" style="accent-color: var(--accent-600);">
                            <span class="text-[10px] font-bold uppercase text-slate-500 tracking-wider group-hover:text-slate-700 transition-colors">Active</span>
                            <span class="text-[9px] text-slate-400">(deactivates others)</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer group">
                            <input type="radio" name="status" value="Inactive" checked style="accent-color: var(--accent-600);">
                            <span class="text-[10px] font-bold uppercase text-slate-500 tracking-wider group-hover:text-slate-700 transition-colors">Inactive</span>
                        </label>
                    </div>
                </div>
            </div>
            <!-- Footer -->
            <div class="modal-footer">
                <button type="button" onclick="closeModal('modal-add')"
                    class="px-6 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold text-xs hover:bg-slate-100 transition-colors uppercase tracking-widest">Cancel</button>
                <button type="submit" id="btn-add-submit"
                    class="flex items-center gap-2 px-6 py-2.5 rounded-xl text-white font-bold text-xs uppercase tracking-widest shadow-sm transition-all hover:-translate-y-0.5" style="background:var(--accent-600);" onmouseover="this.style.background='var(--accent-700)'" onmouseout="this.style.background='var(--accent-600)'">
                    <span class="material-symbols-outlined !text-base">save</span> Save Configuration
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ Edit Modal ═══ -->
<div id="modal-edit" class="modal-overlay" onclick="if(event.target===this)closeModal('modal-edit')">
    <div class="modal-box">
        <!-- Header -->
        <div class="modal-header">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl flex items-center justify-center" style="background:color-mix(in srgb, var(--accent-500) 12%, transparent)">
                    <span class="material-symbols-outlined !text-lg" style="color:var(--accent-500)">edit</span>
                </div>
                <div>
                    <h3 class="text-sm font-extrabold text-slate-800 uppercase tracking-widest">Edit SMS Configuration</h3>
                    <p class="text-[11px] text-slate-400 font-bold mt-0.5">Modify existing SMS sender configuration</p>
                </div>
            </div>
            <button onclick="closeModal('modal-edit')"
                class="w-9 h-9 flex items-center justify-center rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-700 transition-colors">
                <span class="material-symbols-outlined !text-xl">close</span>
            </button>
        </div>
        <!-- Body -->
        <form id="form-edit" onsubmit="submitForm(event,'edit')">
            <div class="modal-body space-y-4">
                <input type="hidden" name="action" value="edit_config">
                <input type="hidden" name="config_id" id="edit-config-id">
                <div class="form-group">
                    <label class="form-label">Configuration Name <span class="text-red-400">*</span></label>
                    <input type="text" name="configuration_name" id="edit-cfg-name" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">API Key <span class="text-red-400">*</span></label>
                    <input type="text" name="api_key" id="edit-api-key" class="form-input font-mono" required>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="form-group">
                        <label class="form-label">Sender Number <span class="text-red-400">*</span></label>
                        <input type="text" name="from_number" id="edit-from-number" class="form-input font-mono" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Device ID <span class="text-red-400">*</span></label>
                        <input type="text" name="device_id" id="edit-device-id" class="form-input font-mono" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">API URL <span class="text-red-400">*</span></label>
                    <input type="url" name="api_url" id="edit-api-url" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <div class="flex gap-4 p-3 bg-slate-50 rounded-xl border border-slate-100">
                        <label class="flex items-center gap-2 cursor-pointer group">
                            <input type="radio" name="status" id="edit-status-active" value="Active" style="accent-color: var(--accent-600);">
                            <span class="text-[10px] font-bold uppercase text-slate-500 tracking-wider group-hover:text-slate-700 transition-colors">Active</span>
                            <span class="text-[9px] text-slate-400">(deactivates others)</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer group">
                            <input type="radio" name="status" id="edit-status-inactive" value="Inactive" style="accent-color: var(--accent-600);">
                            <span class="text-[10px] font-bold uppercase text-slate-500 tracking-wider group-hover:text-slate-700 transition-colors">Inactive</span>
                        </label>
                    </div>
                </div>
            </div>
            <!-- Footer -->
            <div class="modal-footer">
                <button type="button" onclick="closeModal('modal-edit')"
                    class="px-6 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold text-xs hover:bg-slate-100 transition-colors uppercase tracking-widest">Cancel</button>
                <button type="submit" id="btn-edit-submit"
                    class="flex items-center gap-2 px-6 py-2.5 rounded-xl text-white font-bold text-xs uppercase tracking-widest shadow-sm transition-all hover:-translate-y-0.5" style="background:var(--accent-600);" onmouseover="this.style.background='var(--accent-700)'" onmouseout="this.style.background='var(--accent-600)'">
                    <span class="material-symbols-outlined !text-base">published_with_changes</span> Update Configuration
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ Delete Confirm Modal ═══ -->
<div id="modal-delete" class="modal-overlay" onclick="if(event.target===this)closeModal('modal-delete')">
    <div class="modal-box" style="max-width:420px">
        <!-- Header -->
        <div class="modal-header">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-red-50 flex items-center justify-center">
                    <span class="material-symbols-outlined !text-lg text-red-500">delete_forever</span>
                </div>
                <div>
                    <h3 class="text-sm font-extrabold text-slate-800 uppercase tracking-widest">Delete Configuration</h3>
                    <p class="text-[11px] text-slate-400 font-bold mt-0.5">This action cannot be undone</p>
                </div>
            </div>
            <button onclick="closeModal('modal-delete')"
                class="w-9 h-9 flex items-center justify-center rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-700 transition-colors">
                <span class="material-symbols-outlined !text-xl">close</span>
            </button>
        </div>
        <!-- Body -->
        <div class="modal-body">
            <p class="text-sm text-slate-500">
                Are you sure you want to delete <strong id="delete-cfg-name" class="text-slate-700"></strong>?
                This configuration will be permanently removed and cannot be recovered.
            </p>
        </div>
        <!-- Footer -->
        <form id="form-delete" onsubmit="submitForm(event,'delete')">
            <input type="hidden" name="action" value="delete_config">
            <input type="hidden" name="config_id" id="delete-config-id">
            <div class="modal-footer">
                <button type="button" onclick="closeModal('modal-delete')"
                    class="px-6 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold text-xs hover:bg-slate-100 transition-colors uppercase tracking-widest">Cancel</button>
                <button type="submit"
                    class="flex items-center gap-2 px-6 py-2.5 rounded-xl bg-red-600 text-white font-bold text-xs uppercase tracking-widest shadow-sm hover:bg-red-700 hover:-translate-y-0.5 transition-all">
                    <span class="material-symbols-outlined !text-base">delete</span> Delete
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Toast ─────────────────────────────────────────────────────────────────────
function showToast(message, type = 'success', duration = 4500) {
    const icons = { success: 'check_circle', error: 'error', warning: 'warning', info: 'info' };
    const container = document.getElementById('toast-container');
    if (!container) return;
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.style.position = 'relative';
    toast.style.overflow = 'hidden';
    toast.innerHTML = `
        <span class="material-symbols-outlined toast-icon">${icons[type] || 'info'}</span>
        <span class="toast-msg">${message}</span>
        <button class="toast-close" onclick="dismissToast(this.parentElement)">&times;</button>
        <div class="toast-bar" style="animation-duration: ${duration}ms;"></div>`;
    container.appendChild(toast);
    requestAnimationFrame(() => { requestAnimationFrame(() => toast.classList.add('show')); });
    setTimeout(() => dismissToast(toast), duration);
}
function dismissToast(toast) {
    if (!toast || toast._dismissed) return;
    toast._dismissed = true;
    toast.classList.add('hide');
    setTimeout(() => toast.remove(), 350);
}

// ── Modal helpers ─────────────────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function openAddModal() {
    document.getElementById('form-add').reset();
    openModal('modal-add');
}

function openEditModal(cfg) {
    document.getElementById('edit-config-id').value    = cfg.id;
    document.getElementById('edit-cfg-name').value     = cfg.configuration_name;
    document.getElementById('edit-api-key').value      = cfg.api_key;
    document.getElementById('edit-from-number').value  = cfg.from_number;
    document.getElementById('edit-device-id').value    = cfg.device_id;
    document.getElementById('edit-api-url').value      = cfg.api_url;
    document.getElementById('edit-status-active').checked   = cfg.status === 'Active';
    document.getElementById('edit-status-inactive').checked = cfg.status !== 'Active';
    openModal('modal-edit');
}

function deleteConfig(id, name) {
    document.getElementById('delete-config-id').value = id;
    document.getElementById('delete-cfg-name').textContent = name;
    openModal('modal-delete');
}

// ── Activate (inline, no modal needed) ───────────────────────────────────────
function activateConfig(id, name) {
    if (!confirm(`Activate "${name}"?\n\nThis will deactivate all other SMS configurations.`)) return;

    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('action', 'activate_config');
    fd.append('config_id', id);

    fetch(location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(r => r.json())
        .then(data => {
            showToast(data.message, data.success ? 'success' : 'error');
            if (data.success) setTimeout(() => location.reload(), 1200);
        })
        .catch(() => showToast('Network error. Please try again.', 'error'));
}

const CSRF_TOKEN = <?php echo json_encode($_SESSION['csrf_token']); ?>;

// ── Send Test SMS ─────────────────────────────────────────────────────────────
function sendTestSms(e) {
    e.preventDefault();
    const form = document.getElementById('form-test');
    const btn = document.getElementById('btn-test-submit');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="material-symbols-outlined text-[16px] animate-spin">sync</span> Sending…';
    const fd = new FormData(form);
    fd.append('csrf_token', CSRF_TOKEN);
    fetch(location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(r => r.json())
        .then(data => showToast(data.message, data.success ? 'success' : 'error', data.success ? 6000 : 9000))
        .catch(() => showToast('Network error. Please try again.', 'error'))
        .finally(() => { btn.disabled = false; btn.innerHTML = orig; });
}

// ── Generic form submit ───────────────────────────────────────────────────────
function submitForm(e, type) {
    e.preventDefault();
    const formId = { add: 'form-add', edit: 'form-edit', delete: 'form-delete' }[type];
    const btnId  = { add: 'btn-add-submit', edit: 'btn-edit-submit' }[type];
    const form   = document.getElementById(formId);
    const btn    = btnId ? document.getElementById(btnId) : null;
    const origHTML = btn ? btn.innerHTML : '';

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="material-symbols-outlined text-[16px] animate-spin">sync</span> Saving…';
    }

    const fd = new FormData(form);
    fd.append('csrf_token', CSRF_TOKEN);
    fetch(location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(r => r.json())
        .then(data => {
            showToast(data.message, data.success ? 'success' : 'error');
            if (data.success) {
                closeModal('modal-add');
                closeModal('modal-edit');
                closeModal('modal-delete');
                setTimeout(() => location.reload(), 1200);
            }
        })
        .catch(() => showToast('Network error. Please try again.', 'error'))
        .finally(() => {
            if (btn) { btn.disabled = false; btn.innerHTML = origHTML; }
        });
}

// ── Flash PHP messages as toasts ──────────────────────────────────────────────
<?php if (!empty($success)): ?>
window.addEventListener('DOMContentLoaded', () =>
    showToast(<?php echo json_encode($success); ?>, 'success')
);
<?php elseif (!empty($error)): ?>
window.addEventListener('DOMContentLoaded', () =>
    showToast(<?php echo json_encode($error); ?>, 'error')
);
<?php endif; ?>

// ── Setup Guide logic ─────────────────────────────────────────────────────────
let _guideCurrentStep = 1;
const _guideTotalSteps = 7;

function toggleGuide() {
    const content  = document.getElementById('guide-content');
    const chevron  = document.getElementById('guide-chevron');
    const isHidden = content.classList.contains('hidden');
    content.classList.toggle('hidden', !isHidden);
    chevron.style.transform = isHidden ? 'rotate(180deg)' : '';
}

function openGuide() {
    const content = document.getElementById('guide-content');
    const chevron = document.getElementById('guide-chevron');
    if (content.classList.contains('hidden')) {
        content.classList.remove('hidden');
        chevron.style.transform = 'rotate(180deg)';
    }
    // Scroll guide into view inside the modal body
    setTimeout(() => content.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 50);
}

function showGuideStep(n) {
    n = Math.max(1, Math.min(_guideTotalSteps, n));
    _guideCurrentStep = n;

    // Show correct panel
    document.querySelectorAll('.guide-step').forEach((el, i) => {
        el.classList.toggle('hidden', i + 1 !== n);
    });

    // Highlight stepper button
    document.querySelectorAll('.guide-step-btn').forEach(btn => {
        btn.classList.toggle('guide-step-active', parseInt(btn.dataset.step) === n);
    });

    // Update indicator
    document.getElementById('guide-step-indicator').textContent = `Step ${n} of ${_guideTotalSteps}`;

    // Prev/next button state
    document.getElementById('guide-prev').disabled = n === 1;
    document.getElementById('guide-next').style.display = n === _guideTotalSteps ? 'none' : '';

    // Change next button label on last step
    if (n === _guideTotalSteps - 1) {
        document.getElementById('guide-next').innerHTML =
            'Done <span class="material-symbols-outlined !text-sm">check</span>';
    } else {
        document.getElementById('guide-next').innerHTML =
            'Next <span class="material-symbols-outlined !text-sm">chevron_right</span>';
    }
}

function guideNav(dir) {
    showGuideStep(_guideCurrentStep + dir);
}

// Reset guide state each time the Add modal opens
const _origOpenAddModal = window.openAddModal;
window.openAddModal = function() {
    _origOpenAddModal();
    _guideCurrentStep = 1;
    // Reset collapsed state
    const content = document.getElementById('guide-content');
    const chevron = document.getElementById('guide-chevron');
    if (content) { content.classList.add('hidden'); chevron.style.transform = ''; }
    showGuideStep(1);
};

function tryGetLS() {
    try { return JSON.parse(localStorage.getItem('adminThemePrefs') || '{}'); } catch(e) { return {}; }
}
function saveLS(key, val) {
    try { const d = tryGetLS(); d[key] = val; localStorage.setItem('adminThemePrefs', JSON.stringify(d)); } catch(e) {}
}

// ── Accent color ──────────────────────────────────────────────────────────────
function applyAccentColor(hex) {
    if (!hex || !/^#[0-9a-fA-F]{6}$/.test(hex)) return;
    const r = parseInt(hex.slice(1,3),16),
          g = parseInt(hex.slice(3,5),16),
          b = parseInt(hex.slice(5,7),16);
    const lighten = (r2,g2,b2,a) =>
        `rgba(${Math.round(r2+(255-r2)*a)},${Math.round(g2+(255-g2)*a)},${Math.round(b2+(255-b2)*a)},1)`;
    const darken = (r2,g2,b2,f) =>
        `rgb(${Math.round(r2*f)},${Math.round(g2*f)},${Math.round(b2*f)})`;
    const root = document.documentElement;
    root.style.setProperty('--accent-50',  lighten(r,g,b,0.92));
    root.style.setProperty('--accent-100', lighten(r,g,b,0.85));
    root.style.setProperty('--accent-200', lighten(r,g,b,0.70));
    root.style.setProperty('--accent-300', lighten(r,g,b,0.55));
    root.style.setProperty('--accent-400', lighten(r,g,b,0.30));
    root.style.setProperty('--accent-500', `rgb(${r},${g},${b})`);
    root.style.setProperty('--accent-600', darken(r,g,b,0.88));
    root.style.setProperty('--accent-700', darken(r,g,b,0.76));
    root.style.setProperty('--accent-800', darken(r,g,b,0.64));
    root.style.setProperty('--accent-900', darken(r,g,b,0.52));
}

// ── Init on DOMContentLoaded ──────────────────────────────────────────────────
window.addEventListener('DOMContentLoaded', function () {
    const phpAccent = <?php echo json_encode($prefs['accent_color']); ?>;
    const ls = tryGetLS();

    // Accent color — prefer localStorage so it matches the live preview
    const currentHex = (ls.accentHex && /^#[0-9a-fA-F]{6}$/.test(ls.accentHex))
        ? ls.accentHex
        : (/^#[0-9a-fA-F]{6}$/.test(phpAccent) ? phpAccent : '#6366f1');
    applyAccentColor(currentHex);

    // Font size
    const fontSize = ls.fontSize || <?php echo json_encode($prefs['font_size']); ?>;
    const fsPx = { sm: '13px', base: '15px', lg: '17px' }[fontSize] || '15px';
    document.documentElement.style.fontSize = fsPx;

    // UI density
    const density = ls.uiDensity || <?php echo json_encode($prefs['ui_density']); ?>;
    document.documentElement.setAttribute('data-density', density);

    // Animations
    const animations = ls.hasOwnProperty('animations') ? ls.animations : <?php echo json_encode((bool)$prefs['animations']); ?>;
    document.documentElement.classList.toggle('no-anim', !animations);
});
</script>

</body>
</html>
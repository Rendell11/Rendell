<?php
/**
 * ADMIN/settings/backend/sms_config.php
 * JSON backend for Settings → SMS Configuration (admin only).
 *
 *  GET  ?action=list        → all configurations (API key masked) + the active one
 *  POST action=save         → add (no config_id) or edit (config_id) a configuration
 *  POST action=activate     → make one configuration the only Active one
 *  POST action=delete       → delete a configuration
 *  POST action=test         → send one test SMS through a configuration
 *
 * The active row of `sms_configurations` is what
 * announcement/backend/process_disaster.php and disaster_sms_worker.php use.
 */

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../activity_log_helper.php';

header('Content-Type: application/json; charset=utf-8');

function smsc_out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    smsc_out(['success' => false, 'message' => 'Only administrators can manage the SMS configuration.'], 403);
}

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
    error_log('[sms_config] bootstrap: ' . $e->getMessage());
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

if ($action !== 'list') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        smsc_out(['success' => false, 'message' => 'Method not allowed.'], 405);
    }
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        smsc_out(['success' => false, 'message' => 'Your session has expired. Please refresh the page and try again.'], 403);
    }
}

function smsc_mask(string $key): string
{
    $len = strlen($key);
    return $len <= 10 ? str_repeat('•', max(4, $len)) : substr($key, 0, 9) . '…' . substr($key, -4);
}

/** Sends ONE SMS — same request as process_disaster.php's sendDisasterSMS(). */
function smsc_send(array $cfg, string $to, string $message): array
{
    $phone = preg_replace('/[^0-9]/', '', $to);
    if (strlen($phone) < 10) {
        return ['ok' => false, 'to' => $to, 'http' => 0, 'detail' => 'Invalid mobile number.'];
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
        return ['ok' => false, 'to' => $to, 'http' => 0, 'detail' => 'Could not reach the SMS gateway: ' . $err];
    }
    $res = json_decode((string) $body, true);
    $ok = $code >= 200 && $code < 300 && (
        !empty($res['success']) || !empty($res['messageId']) || !empty($res['id'])
        || (isset($res['status']) && !in_array(strtolower((string) $res['status']), ['failed', 'error', 'rejected'], true))
    );
    $detail = is_array($res) ? ($res['message'] ?? $res['error']['message'] ?? $res['error'] ?? '') : '';
    return ['ok' => $ok, 'to' => $to, 'http' => $code, 'detail' => is_string($detail) ? $detail : json_encode($detail)];
}

try {
    switch ($action) {

        case 'list':
            $rows = $pdo->query("SELECT * FROM sms_configurations ORDER BY (status = 'Active') DESC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
            $configs = [];
            $active = null;
            foreach ($rows as $r) {
                $c = [
                    'id' => (int) $r['id'],
                    'configuration_name' => $r['configuration_name'],
                    'from_number' => $r['from_number'],
                    'device_id' => $r['device_id'],
                    'api_url' => $r['api_url'],
                    'api_host' => parse_url($r['api_url'], PHP_URL_HOST) ?: $r['api_url'],
                    'api_key_masked' => smsc_mask((string) $r['api_key']),
                    'status' => $r['status'],
                    'updated_at' => $r['updated_at'] ?? $r['created_at'] ?? null,
                ];
                $configs[] = $c;
                if ($r['status'] === 'Active' && !$active) {
                    $active = $c;
                }
            }
            smsc_out(['success' => true, 'configs' => $configs, 'active' => $active]);

        case 'save':
            $id = (int) ($_POST['config_id'] ?? 0);
            $name = trim((string) ($_POST['configuration_name'] ?? ''));
            $key = trim((string) ($_POST['api_key'] ?? ''));
            $from = trim((string) ($_POST['from_number'] ?? ''));
            $device = trim((string) ($_POST['device_id'] ?? ''));
            $url = trim((string) ($_POST['api_url'] ?? ''));
            $makeActive = ($_POST['status'] ?? '') === 'Active';

            if ($name === '' || $from === '' || $device === '' || $url === '' || (!$id && $key === '')) {
                smsc_out(['success' => false, 'message' => 'Please fill in all fields.']);
            }
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                smsc_out(['success' => false, 'message' => 'The API URL is not a valid URL.']);
            }

            $pdo->beginTransaction();
            if ($makeActive) {
                $pdo->exec("UPDATE sms_configurations SET status = 'Inactive'");
            }
            if ($id) {
                // Leaving the API key blank on Edit keeps the saved key
                $sql = "UPDATE sms_configurations SET configuration_name = ?, from_number = ?, device_id = ?, api_url = ?"
                    . ($key !== '' ? ', api_key = ?' : '')
                    . ($makeActive ? ", status = 'Active'" : '')
                    . ' WHERE id = ?';
                $params = [$name, $from, $device, $url];
                if ($key !== '') {
                    $params[] = $key;
                }
                $params[] = $id;
                $pdo->prepare($sql)->execute($params);
                $msg = 'SMS configuration updated.';
                log_activity('SMS Settings', 'Edit SMS Config', "Updated SMS configuration ID {$id}: {$name}");
            } else {
                $pdo->prepare("INSERT INTO sms_configurations (configuration_name, api_key, from_number, device_id, api_url, status)
                               VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$name, $key, $from, $device, $url, $makeActive ? 'Active' : 'Inactive']);
                $msg = 'SMS configuration added' . ($makeActive ? ' and activated.' : '.');
                log_activity('SMS Settings', 'Add SMS Config', "Added SMS configuration: {$name}");
            }
            $pdo->commit();
            smsc_out(['success' => true, 'message' => $msg]);

        case 'activate':
            $id = (int) ($_POST['config_id'] ?? 0);
            $pdo->beginTransaction();
            $pdo->exec("UPDATE sms_configurations SET status = 'Inactive'");
            $pdo->prepare("UPDATE sms_configurations SET status = 'Active' WHERE id = ?")->execute([$id]);
            $pdo->commit();
            log_activity('SMS Settings', 'Activate SMS Config', "Activated SMS configuration ID {$id}.");
            smsc_out(['success' => true, 'message' => 'SMS configuration activated. Disaster alerts will use it from now on.']);

        case 'delete':
            $id = (int) ($_POST['config_id'] ?? 0);
            $pdo->prepare("DELETE FROM sms_configurations WHERE id = ?")->execute([$id]);
            log_activity('SMS Settings', 'Delete SMS Config', "Deleted SMS configuration ID {$id}.");
            smsc_out(['success' => true, 'message' => 'SMS configuration deleted.']);

        case 'test':
            $id = (int) ($_POST['config_id'] ?? 0);
            $to = trim((string) ($_POST['test_number'] ?? ''));
            $stmt = $id
                ? $pdo->prepare("SELECT * FROM sms_configurations WHERE id = ?")
                : $pdo->prepare("SELECT * FROM sms_configurations WHERE status = 'Active' LIMIT 1");
            $stmt->execute($id ? [$id] : []);
            $cfg = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$cfg) {
                smsc_out(['success' => false, 'message' => 'No SMS configuration to test. Add and activate one first.']);
            }
            if ($to === '') {
                smsc_out(['success' => false, 'message' => 'Enter the mobile number that should receive the test SMS.']);
            }
            $r = smsc_send($cfg, $to, 'CAPS SMS test from Barangay Binang 2nd (' . date('M j, Y g:i A')
                . '). If you received this, disaster SMS alerts are working.');
            if ($r['ok']) {
                log_activity('SMS Settings', 'Test SMS', "Sent a test SMS to {$r['to']} using configuration ID {$cfg['id']}.");
                smsc_out(['success' => true, 'message' => 'Test SMS accepted by the gateway for ' . $r['to'] . ' using "' . $cfg['configuration_name'] . '". Check the phone.']);
            }
            smsc_out(['success' => false, 'message' => 'Test SMS failed' . ($r['http'] ? ' (HTTP ' . $r['http'] . ')' : '') . ': '
                . ($r['detail'] !== '' ? $r['detail'] : 'the gateway did not accept the message. Check the API key, sender number and that the gateway phone/app is online.')]);

        default:
            smsc_out(['success' => false, 'message' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[sms_config] ' . $action . ': ' . $e->getMessage());
    smsc_out(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}

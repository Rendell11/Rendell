<?php
/**
 * user/backend/push_lib.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Push notifications to the resident app through Firebase Cloud Messaging
 * (HTTP v1 API). Nothing in the admin module has to change: push_pending()
 * looks for new items and sends them once (push_log remembers what was sent):
 *   • resident_notifications (certificate / complaint updates) → that resident
 *   • disaster_alerts with "Notify app" ticked (notify_app = 1)  → everyone
 *   • new announcements                                           → everyone
 *   • blotter case updates + hearing reminder the day before      → the parties
 * Only items from the last 24 hours are sent (no flood of old items).
 *
 * Setup: put the Firebase service-account key (Project settings → Service
 * accounts → Generate new private key) at
 *     user/backend/private/firebase_service_account.json
 * (the private/ folder is blocked from the web by its .htaccess). Without that file push is simply off. Delivery runs from push_worker.php
 * (Task Scheduler, every minute) and, as a fallback, whenever an app loads
 * its notifications (push_run_throttled).
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

// private/ is blocked from the web by private/.htaccess (the key must never be downloadable).
const PUSH_KEY_FILE   = __DIR__ . '/private/firebase_service_account.json';
const PUSH_CACHE_FILE = __DIR__ . '/private/fcm_access_token.json';
const PUSH_LOCK_FILE  = __DIR__ . '/private/push_last_run';

function push_config(): ?array
{
    if (!is_file(PUSH_KEY_FILE)) return null;
    $c = json_decode((string) file_get_contents(PUSH_KEY_FILE), true);
    return (is_array($c) && !empty($c['client_email']) && !empty($c['private_key']) && !empty($c['project_id'])) ? $c : null;
}

function push_b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function push_http(string $url, array $headers, string $body): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, (string) $out];
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body,
        'timeout' => 10, 'ignore_errors' => true,
    ]]);
    $out = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) $code = (int) $m[1];
    }
    return [$code, (string) $out];
}

/** OAuth access token for FCM (cached ~55 minutes). */
function push_access_token(array $cfg): ?string
{
    $cache = @json_decode((string) @file_get_contents(PUSH_CACHE_FILE), true);
    if (is_array($cache) && ($cache['exp'] ?? 0) > time() + 60 && !empty($cache['token'])) return $cache['token'];

    $now = time();
    $jwt = push_b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . push_b64url(json_encode([
        'iss' => $cfg['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
    ]));
    if (!openssl_sign($jwt, $sig, $cfg['private_key'], 'sha256WithRSAEncryption')) {
        error_log('[push] could not sign the token request (check firebase_service_account.json)');
        return null;
    }
    [$code, $out] = push_http('https://oauth2.googleapis.com/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                          'assertion' => $jwt . '.' . push_b64url($sig)]));
    $res = json_decode($out, true);
    if ($code !== 200 || empty($res['access_token'])) {
        error_log('[push] token request failed: HTTP ' . $code . ' ' . substr($out, 0, 200));
        return null;
    }
    @file_put_contents(PUSH_CACHE_FILE, json_encode(['token' => $res['access_token'], 'exp' => $now + (int) ($res['expires_in'] ?? 3600)]));
    return $res['access_token'];
}

/** Send one message. Returns 'ok', 'invalid' (token no longer valid) or 'error'. */
function push_send(array $cfg, string $access, string $deviceToken, string $title, string $body, array $data = []): string
{
    $msg = ['message' => [
        'token'        => $deviceToken,
        'notification' => ['title' => mb_substr($title, 0, 120), 'body' => mb_substr($body, 0, 240)],
        'data'         => array_map('strval', $data),
        'android'      => ['priority' => 'high', 'notification' => ['channel_id' => 'barangay_updates']],
    ]];
    [$code, $out] = push_http('https://fcm.googleapis.com/v1/projects/' . rawurlencode($cfg['project_id']) . '/messages:send',
        ['Authorization: Bearer ' . $access, 'Content-Type: application/json; charset=utf-8'],
        json_encode($msg, JSON_UNESCAPED_UNICODE));
    if ($code === 200) return 'ok';
    if ($code === 404 || ($code === 400 && str_contains($out, 'registration token'))) return 'invalid';
    error_log('[push] send failed: HTTP ' . $code . ' ' . substr($out, 0, 200));
    return 'error';
}

function push_migrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_log (
        source      VARCHAR(20) NOT NULL,
        source_id   INT UNSIGNED NOT NULL,
        resident_id INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = sent to everyone',
        sent        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'devices reached',
        pushed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (source, source_id, resident_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function push_table(PDO $pdo, string $t): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $s->execute([$t]);
    return (int) $s->fetchColumn() > 0;
}

/** Residents of a blotter case (parties + primary complainant/respondent). */
function push_case_residents(PDO $pdo, int $caseId): array
{
    $ids = [];
    if (push_table($pdo, 'blotter_parties')) {
        $s = $pdo->prepare("SELECT resident_id FROM blotter_parties WHERE blotter_id = ? AND resident_id IS NOT NULL");
        $s->execute([$caseId]);
        $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    }
    $s = $pdo->prepare("SELECT ComplainantID, RespondentID FROM blotter WHERE BlotterID = ?");
    $s->execute([$caseId]);
    foreach (($s->fetch(PDO::FETCH_NUM) ?: []) as $v) {
        if (ctype_digit((string) $v)) $ids[] = (int) $v;
    }
    return array_values(array_unique(array_filter($ids)));
}

/**
 * Send everything new. Returns the number of devices reached.
 * @param int $maxSeconds stop early so a web request is not slowed down for long
 */
function push_pending(PDO $pdo, int $maxSeconds = 20): int
{
    $cfg = push_config();
    if (!$cfg) return 0;
    auth_migrate($pdo);
    push_migrate($pdo);
    $access = push_access_token($cfg);
    if (!$access) return 0;
    $start = time();

    // Device tokens of signed-in residents.
    $tokens = [];
    foreach ($pdo->query("SELECT id, resident_id, push_token FROM resident_sessions
                          WHERE push_token IS NOT NULL AND revoked_at IS NULL AND expires_at > NOW()")->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $tokens[(int) $t['resident_id']][(int) $t['id']] = $t['push_token'];
    }
    if (!$tokens) return 0;

    $logged = $pdo->prepare("SELECT 1 FROM push_log WHERE source = ? AND source_id = ? AND resident_id = ?");
    $log = $pdo->prepare("INSERT IGNORE INTO push_log (source, source_id, resident_id, sent) VALUES (?, ?, ?, ?)");
    $sent = 0;

    // Send to one resident (or everyone when $rid = 0) once per item.
    $deliver = function (string $source, int $id, int $rid, string $title, string $body, array $data) use ($pdo, $cfg, $access, &$tokens, $logged, $log, &$sent, $start, $maxSeconds): void {
        if (time() - $start > $maxSeconds) return;
        $logged->execute([$source, $id, $rid]);
        if ($logged->fetchColumn()) return;
        if ($rid > 0) {
            $targets = $tokens[$rid] ?? [];
        } else {
            $targets = [];
            foreach ($tokens as $list) $targets += $list; // keyed by session id
        }
        $n = 0;
        foreach ($targets as $sessionId => $deviceToken) {
            $r = push_send($cfg, $access, $deviceToken, $title, $body, $data + ['source' => $source, 'id' => $id]);
            if ($r === 'ok') $n++;
            if ($r === 'invalid') {
                $pdo->prepare("UPDATE resident_sessions SET push_token = NULL WHERE id = ?")->execute([$sessionId]);
                foreach ($tokens as $k => $list) unset($tokens[$k][$sessionId]);
            }
        }
        $log->execute([$source, $id, $rid, $n]);
        $sent += $n;
    };

    if (push_table($pdo, 'resident_notifications')) {
        foreach ($pdo->query("SELECT id, resident_id, title, message, ref_table, ref_id FROM resident_notifications
                              WHERE created_at >= NOW() - INTERVAL 1 DAY ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $n) {
            $deliver('rn', (int) $n['id'], (int) $n['resident_id'], (string) $n['title'], (string) $n['message'],
                ['ref_table' => (string) $n['ref_table'], 'ref_id' => (string) $n['ref_id']]);
        }
    }
    if (push_table($pdo, 'disaster_alerts')) {
        foreach ($pdo->query("SELECT AlertID, Type, Severity, Title, Message FROM disaster_alerts
                              WHERE notify_app = 1 AND LOWER(Status) = 'active' AND CreatedAt >= NOW() - INTERVAL 1 DAY")->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $deliver('alert', (int) $a['AlertID'], 0, '⚠ ' . trim(($a['Severity'] ? $a['Severity'] . ' · ' : '') . ($a['Title'] ?: $a['Type'])),
                (string) $a['Message'], ['ref_table' => 'disaster_alerts', 'ref_id' => (string) $a['AlertID']]);
        }
    }
    if (push_table($pdo, 'announcements')) {
        foreach ($pdo->query("SELECT id, title, category, details FROM announcements
                              WHERE deleted_at IS NULL AND status = 'Published'
                                AND COALESCE(date_posted, created_at) >= NOW() - INTERVAL 1 DAY")->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $deliver('announcement', (int) $a['id'], 0, (string) $a['title'],
                mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string) $a['details']))), 0, 160),
                ['ref_table' => 'announcements', 'ref_id' => (string) $a['id']]);
        }
    }
    if (push_table($pdo, 'blotter_timeline')) {
        foreach ($pdo->query("SELECT t.timeline_id, t.blotter_id, t.action, t.status, b.CaseNumber
                              FROM blotter_timeline t JOIN blotter b ON b.BlotterID = t.blotter_id
                              WHERE t.recorded_at >= NOW() - INTERVAL 1 DAY
                                AND t.status IN ('Hearing Scheduled','Notice Issued','For Next Hearing','Resolved',
                                                 'Closed','For Transfer','Transferred','Cancelled','Withdrawn')")->fetchAll(PDO::FETCH_ASSOC) as $t) {
            foreach (push_case_residents($pdo, (int) $t['blotter_id']) as $r) {
                $deliver('blotter', (int) $t['timeline_id'], $r, ($t['CaseNumber'] ?: 'Blotter') . ' · ' . $t['status'],
                    (string) $t['action'], ['ref_table' => 'blotter', 'ref_id' => (string) $t['blotter_id']]);
            }
        }
    }
    if (push_table($pdo, 'blotter_hearings')) {
        foreach ($pdo->query("SELECT h.hearing_id, h.blotter_id, h.hearing_no, h.hearing_date, h.hearing_time, h.location, b.CaseNumber
                              FROM blotter_hearings h JOIN blotter b ON b.BlotterID = h.blotter_id
                              WHERE h.status = 'Scheduled' AND h.hearing_date = CURDATE() + INTERVAL 1 DAY")->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $when = date('M j, Y', strtotime((string) $h['hearing_date']))
                  . ($h['hearing_time'] ? ' ' . date('g:i A', strtotime((string) $h['hearing_time'])) : '');
            foreach (push_case_residents($pdo, (int) $h['blotter_id']) as $r) {
                $deliver('hearing', (int) $h['hearing_id'], $r,
                    'Hearing tomorrow · ' . ($h['CaseNumber'] ?: 'Blotter'),
                    $when . ($h['location'] ? ' · ' . $h['location'] : '') . '. Please bring a valid ID.',
                    ['ref_table' => 'blotter', 'ref_id' => (string) $h['blotter_id']]);
            }
        }
    }
    return $sent;
}

/** Run push_pending() at most every 30 seconds (called while apps are open). */
function push_run_throttled(PDO $pdo): void
{
    if (!is_file(PUSH_KEY_FILE)) return;
    $last = @filemtime(PUSH_LOCK_FILE) ?: 0;
    if (time() - $last < 30) return;
    @touch(PUSH_LOCK_FILE);
    try {
        push_pending($pdo, 5);
    } catch (Throwable $e) {
        error_log('[push] ' . $e->getMessage());
    }
}

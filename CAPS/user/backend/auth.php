<?php
/**
 * user/backend/auth.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Login tokens for the resident app. Loaded by config.php, so every endpoint
 * has these helpers.
 *
 *   • login.php issues a random token (only its SHA-256 hash is stored in
 *     resident_sessions) that expires after AUTH_SESSION_DAYS without use.
 *   • The app sends it on every request as the X-Auth-Token header
 *     (Authorization: Bearer … also works).
 *   • require_resident() checks it and returns the ResidentID of the token —
 *     the resident_id the app sends is ignored, so nobody can read or change
 *     another resident's data by editing it.
 *   • logout.php revokes the token (or every token of the resident).
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

const AUTH_SESSION_DAYS = 30; // sliding: renewed each time the app is used

function auth_migrate(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS resident_sessions (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            resident_id  INT UNSIGNED NOT NULL,
            token_hash   CHAR(64) NOT NULL,
            device       VARCHAR(120) NULL,
            platform     VARCHAR(20) NULL,
            push_token   VARCHAR(255) NULL COMMENT 'Firebase Cloud Messaging token of this device',
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NULL,
            expires_at   DATETIME NOT NULL,
            revoked_at   DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_rs_token (token_hash),
            KEY idx_rs_resident (resident_id, revoked_at),
            KEY idx_rs_push (push_token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/** Create a session for a resident who just logged in. Returns the plain token. */
function auth_issue_token(int $residentId, ?string $device = null, ?string $platform = null): string
{
    $pdo = db();
    auth_migrate($pdo);
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO resident_sessions (resident_id, token_hash, device, platform, last_used_at, expires_at)
                   VALUES (?, ?, ?, ?, NOW(), NOW() + INTERVAL " . AUTH_SESSION_DAYS . " DAY)")
        ->execute([$residentId, hash('sha256', $token),
                   $device !== null ? mb_substr($device, 0, 120) : null,
                   $platform !== null ? mb_substr($platform, 0, 20) : null]);
    // Housekeeping: drop sessions that ended more than 30 days ago.
    try {
        $pdo->exec("DELETE FROM resident_sessions WHERE expires_at < NOW() - INTERVAL 30 DAY
                    OR revoked_at < NOW() - INTERVAL 30 DAY");
    } catch (Throwable $e) { /* ignore */ }
    return $token;
}

/** Token sent by the app, or ''. */
function auth_request_token(): string
{
    $t = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    if ($t === '') {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (stripos($h, 'Bearer ') === 0) $t = substr($h, 7);
    }
    $t = trim((string) $t);
    return preg_match('/^[a-f0-9]{64}$/', $t) ? $t : '';
}

/** The session row of the current request, or null. */
function auth_session(): ?array
{
    static $cache = false;
    if ($cache !== false) return $cache;
    $token = auth_request_token();
    if ($token === '') return $cache = null;
    $pdo = db();
    auth_migrate($pdo);
    $s = $pdo->prepare("SELECT s.*, r.access_status, r.IsDeceased
                        FROM resident_sessions s JOIN residents r ON r.ResidentID = s.resident_id
                        WHERE s.token_hash = ? AND s.revoked_at IS NULL AND s.expires_at > NOW() LIMIT 1");
    $s->execute([hash('sha256', $token)]);
    $row = $s->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($row && (($row['access_status'] ?? '') !== 'Active' || !empty($row['IsDeceased']))) {
        $row = null; // account disabled after login
    }
    if ($row && (!$row['last_used_at'] || strtotime((string) $row['last_used_at']) < time() - 300)) {
        // Sliding expiry, written at most every 5 minutes.
        $pdo->prepare("UPDATE resident_sessions SET last_used_at = NOW(),
                       expires_at = NOW() + INTERVAL " . AUTH_SESSION_DAYS . " DAY WHERE id = ?")
            ->execute([(int) $row['id']]);
    }
    return $cache = $row;
}

/**
 * The logged-in resident, or a 401 response. Also overwrites resident_id in
 * $_GET / $_POST so older code that reads it gets the verified value.
 */
function require_resident(): int
{
    try {
        $row = auth_session();
    } catch (Throwable $e) {
        error_log('[auth] ' . $e->getMessage());
        respond(false, L('May problema sa server. Subukan muli.', 'Server problem. Please try again.'), null, 500);
    }
    if (!$row) {
        respond(false, L('Nag-expire ang iyong session. Mag-login muli.', 'Your session has expired. Please log in again.'),
            ['auth' => 'required'], 401);
    }
    $rid = (int) $row['resident_id'];
    $_GET['resident_id'] = $_POST['resident_id'] = (string) $rid;
    return $rid;
}

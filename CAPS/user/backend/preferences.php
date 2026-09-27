<?php
/**
 * user/backend/preferences.php
 * ─────────────────────────────────────────────────────────────────────────────
 * The resident's app preferences (Flutter app → Settings), stored in the same
 * `user_preferences` table the SOE resident web portal uses (user_id =
 * residents.ResidentID). App keys are prefixed `app_` so they never clash with
 * the web portal's own keys.
 *
 *   GET  ?resident_id=               → { app_language, app_theme_mode, ... }
 *   POST resident_id + any keys below → saves the valid ones
 *
 * Keys / allowed values:
 *   app_language    en | fil
 *   app_theme_mode  default | light | dark | system
 *   app_accent      default | #RRGGBB
 *   app_text_size   small | normal | large
 *
 * Same trust model as chat.php / complaint.php (resident_id from the app).
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php'; // respond(), handle_preflight(), db(), L()
handle_preflight();

$pdo = db();
$rid = (int) ($_GET['resident_id'] ?? $_POST['resident_id'] ?? 0);
if ($rid <= 0) {
    respond(false, L('Kailangan ang resident_id.', 'resident_id is required.'), null, 400);
}

$check = $pdo->prepare("SELECT access_status FROM residents WHERE ResidentID = ? LIMIT 1");
$check->execute([$rid]);
if ($check->fetchColumn() !== 'Active') {
    respond(false, L('Hindi aktibo o hindi nahanap ang resident account.',
        'The resident account is not active or was not found.'), null, 403);
}

// Same table the web portal uses; create it if this DB doesn't have it yet.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS user_preferences (
        id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id          BIGINT UNSIGNED NOT NULL,
        preference_key   VARCHAR(60)  NOT NULL,
        preference_value VARCHAR(255) NOT NULL DEFAULT '',
        created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_pref (user_id, preference_key),
        KEY idx_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

/** Validate one preference; returns the clean value or null. */
function pref_clean(string $key, string $value): ?string
{
    switch ($key) {
        case 'app_language':
            return in_array($value, ['en', 'fil'], true) ? $value : null;
        case 'app_theme_mode':
            return in_array($value, ['default', 'light', 'dark', 'system'], true) ? $value : null;
        case 'app_text_size':
            return in_array($value, ['small', 'normal', 'large'], true) ? $value : null;
        case 'app_accent':
            if ($value === 'default') return $value;
            return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) ? strtoupper($value) : null;
    }
    return null;
}

const PREF_KEYS = ['app_language', 'app_theme_mode', 'app_accent', 'app_text_size'];

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $upsert = $pdo->prepare(
            "INSERT INTO user_preferences (user_id, preference_key, preference_value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE preference_value = VALUES(preference_value),
                                     updated_at = CURRENT_TIMESTAMP"
        );
        $saved = [];
        foreach (PREF_KEYS as $k) {
            if (!isset($_POST[$k])) continue;
            $v = pref_clean($k, trim((string) $_POST[$k]));
            if ($v === null) continue;
            $upsert->execute([$rid, $k, $v]);
            $saved[$k] = $v;
        }
        if (!$saved) {
            respond(false, L('Walang wastong preference na naipadala.', 'No valid preferences were sent.'), null, 422);
        }
        respond(true, L('Na-save ang settings.', 'Settings saved.'), $saved);
    }

    $in   = implode(',', array_fill(0, count(PREF_KEYS), '?'));
    $stmt = $pdo->prepare(
        "SELECT preference_key, preference_value FROM user_preferences
         WHERE user_id = ? AND preference_key IN ($in)"
    );
    $stmt->execute(array_merge([$rid], PREF_KEYS));
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['preference_key']] = $r['preference_value'];
    }
    // Empty object (not []) so the app always gets a map.
    respond(true, '', (object) $out);
} catch (Throwable $e) {
    error_log('[preferences.php] ' . $e->getMessage());
    respond(false, L('Hindi ma-load ang settings.', 'Could not load the settings.'), null, 500);
}

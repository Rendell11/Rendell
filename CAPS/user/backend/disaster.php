<?php
/**
 * user/backend/disaster.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Disaster alerts for the Flutter app (lib/disaster/). Reads disaster_alerts,
 * which the admin creates with Announcements → "Issue Alert"
 * (admin/announcement/backend/process_disaster.php):
 *   • active alerts first, then the ones from the last 30 days
 *   • type, severity, title, message (+ affected area / evacuation center
 *     when an alert has them — the current Issue Alert form does not ask
 *     for them, and saves the evacuation center as 'None')
 *
 * The admin has no hazard / risk map page, so no map is sent to the app.
 *
 *   GET → { alerts }   (login token required)
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';
handle_preflight();

require_resident();

/** Empty / 'None' / 'N/A' → null. */
function dis_text($v): ?string
{
    $v = trim((string) ($v ?? ''));
    return ($v === '' || in_array(strtolower($v), ['none', 'n/a', 'na', '-'], true)) ? null : $v;
}

try {
    $pdo = db();
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'disaster_alerts'");
    $s->execute();
    $alerts = [];
    if ((int) $s->fetchColumn() > 0) {
        foreach ($pdo->query("SELECT AlertID, Type, Severity, Title, Message, IncidentLocation, EvacuationCenter, Status, CreatedAt
                              FROM disaster_alerts
                              WHERE LOWER(Status) = 'active' OR CreatedAt >= NOW() - INTERVAL 30 DAY
                              ORDER BY LOWER(Status) = 'active' DESC, CreatedAt DESC
                              LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $location = dis_text($a['IncidentLocation']);
            // Older alerts stored raw coordinates here ("14.80, 120.93") — not useful to residents.
            if ($location !== null && preg_match('/^-?\d+(\.\d+)?\s*,\s*-?\d+(\.\d+)?$/', $location)) $location = null;
            $alerts[] = [
                'id'         => (int) $a['AlertID'],
                'type'       => dis_text($a['Type']),
                'severity'   => dis_text($a['Severity']),
                'title'      => dis_text($a['Title']) ?? (string) $a['Type'],
                'message'    => dis_text($a['Message']),
                'location'   => $location,
                'evacuation' => dis_text($a['EvacuationCenter']),
                'is_active'  => strtolower((string) $a['Status']) === 'active',
                'created_at' => $a['CreatedAt'],
            ];
        }
    }
    respond(true, '', ['alerts' => $alerts]);
} catch (Throwable $e) {
    error_log('[disaster.php] ' . $e->getMessage());
    respond(false, L('Hindi ma-load ang mga alerto.', 'Could not load the alerts.'),
        isset($_GET['debug']) ? ['error' => $e->getMessage()] : null, 500);
}

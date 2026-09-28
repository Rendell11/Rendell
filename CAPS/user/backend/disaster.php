<?php
/**
 * user/backend/disaster.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Disaster alerts + hazard map for the Flutter app (lib/disaster/). Reads the
 * admin "Disaster and Risk Map" tables (admin/announcement/, process_disaster.php):
 *   • disaster_alerts — active alerts first, then the last 30 days
 *   • hazards         — flood / fire / structural / earthquake zones and
 *                       Safe Points (evacuation areas), not deleted
 *   • the resident's own household pin (residents.Latitude/Longitude of the
 *     head), so the map shows "your home" next to the hazards
 *
 *   GET → { alerts, hazards, home, center }   (login token required)
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';
handle_preflight();

$rid = require_resident();

function dis_has(PDO $pdo, string $table): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $s->execute([$table]);
    return (int) $s->fetchColumn() > 0;
}

function dis_num($v): ?float
{
    return ($v === null || $v === '' || (float) $v == 0.0) ? null : (float) $v;
}

try {
    $pdo = db();

    $alerts = [];
    if (dis_has($pdo, 'disaster_alerts')) {
        foreach ($pdo->query("SELECT AlertID, Type, Severity, Title, Message, IncidentLocation, EvacuationCenter,
                                     HazardLat, HazardLng, HazardRadius, Status, CreatedAt
                              FROM disaster_alerts
                              WHERE LOWER(Status) = 'active' OR CreatedAt >= NOW() - INTERVAL 30 DAY
                              ORDER BY LOWER(Status) = 'active' DESC, CreatedAt DESC
                              LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $alerts[] = [
                'id'          => (int) $a['AlertID'],
                'type'        => $a['Type'],
                'severity'    => $a['Severity'],
                'title'       => $a['Title'] ?: $a['Type'],
                'message'     => $a['Message'],
                'location'    => $a['IncidentLocation'],
                'evacuation'  => $a['EvacuationCenter'],
                'lat'         => dis_num($a['HazardLat']),
                'lng'         => dis_num($a['HazardLng']),
                'radius'      => (int) ($a['HazardRadius'] ?: 200),
                'is_active'   => strtolower((string) $a['Status']) === 'active',
                'created_at'  => $a['CreatedAt'],
            ];
        }
    }

    $hazards = [];
    if (dis_has($pdo, 'hazards')) {
        foreach ($pdo->query("SELECT id, Title, Type, Description, Lat, Lng, Radius, Severity
                              FROM hazards WHERE is_deleted = 0 OR is_deleted IS NULL
                              ORDER BY Type = 'Safe Point', id DESC")->fetchAll(PDO::FETCH_ASSOC) as $h) {
            if (dis_num($h['Lat']) === null || dis_num($h['Lng']) === null) continue;
            $hazards[] = [
                'id'          => (int) $h['id'],
                'title'       => $h['Title'],
                'type'        => $h['Type'],
                'description' => $h['Description'],
                'lat'         => (float) $h['Lat'],
                'lng'         => (float) $h['Lng'],
                'radius'      => (int) ($h['Radius'] ?: 100),
                'severity'    => $h['Severity'] !== null ? (int) $h['Severity'] : null,
            ];
        }
    }

    // The resident's home = their household head's pin (or their own).
    $home = null;
    $s = $pdo->prepare("SELECT r.Latitude, r.Longitude, h.Latitude AS HLat, h.Longitude AS HLng
                        FROM residents r LEFT JOIN residents h ON h.ResidentID = r.FamilyHeadID
                        WHERE r.ResidentID = ?");
    $s->execute([$rid]);
    if ($r = $s->fetch(PDO::FETCH_ASSOC)) {
        $lat = dis_num($r['Latitude']) ?? dis_num($r['HLat']);
        $lng = dis_num($r['Longitude']) ?? dis_num($r['HLng']);
        if ($lat !== null && $lng !== null) $home = ['lat' => $lat, 'lng' => $lng];
    }

    // Map center: home, else the average of the hazards, else Biñang 2nd.
    $center = $home;
    if (!$center && $hazards) {
        $center = ['lat' => array_sum(array_column($hazards, 'lat')) / count($hazards),
                   'lng' => array_sum(array_column($hazards, 'lng')) / count($hazards)];
    }
    $center ??= ['lat' => 14.8036, 'lng' => 120.9338];

    respond(true, '', ['alerts' => $alerts, 'hazards' => $hazards, 'home' => $home, 'center' => $center]);
} catch (Throwable $e) {
    error_log('[disaster.php] ' . $e->getMessage());
    respond(false, L('Hindi ma-load ang mga alerto.', 'Could not load the alerts.'),
        isset($_GET['debug']) ? ['error' => $e->getMessage()] : null, 500);
}

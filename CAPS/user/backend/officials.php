<?php
/**
 * user/backend/officials.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Barangay officials — read-only JSON API for the Flutter app (lib/officials/).
 * Same list as the admin Officials page (admin/officials/frontend/officials.php):
 * current officials only (TermEnd empty or today/future), in the same order.
 *
 *   GET → { officials: [ {official_id, name, position, group, term_start,
 *                          term_end, photo_url} ] }
 *
 * photo_url is relative to this backend folder (the admin stores photos in
 * admin/officials/uploads/officials/). Public data — no resident_id needed.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php'; // respond(), handle_preflight(), db(), L()
handle_preflight();

const OFFICIAL_PHOTO_DIR = __DIR__ . '/../../admin/officials/uploads/officials/';
const OFFICIAL_PHOTO_URL = '../../admin/officials/uploads/officials/';

/** captain | executive | kagawad | sk | other — used by the app for layout. */
function official_group(string $position): string
{
    $p = strtolower($position);
    if (strpos($p, 'captain') !== false || strpos($p, 'punong') !== false) return 'captain';
    if (strpos($p, 'kagawad') !== false || strpos($p, 'councilor') !== false) return 'kagawad';
    if (strpos($p, 'sk ') === 0 || strpos($p, 'sk chair') !== false) return 'sk';
    if (strpos($p, 'secretary') !== false || strpos($p, 'treasurer') !== false) return 'executive';
    return 'other';
}

function official_photo(?string $photo): ?string
{
    if (!$photo) return null;
    $file = basename($photo);
    return is_file(OFFICIAL_PHOTO_DIR . $file) ? OFFICIAL_PHOTO_URL . rawurlencode($file) : null;
}

try {
    $pdo  = db();
    $rows = $pdo->query(
        // o.* (not a column list): some CAPS databases don't have every
        // column, e.g. the old officials.Name.
        "SELECT o.*, r.FirstName, r.MiddleName, r.LastName, r.Suffix
         FROM officials o
         LEFT JOIN residents r ON r.ResidentID = o.ResidentID
         WHERE o.TermEnd IS NULL OR o.TermEnd >= CURDATE()
         ORDER BY FIELD(o.Position,
           'Barangay Captain','Barangay Secretary','Barangay Treasurer',
           'Kagawad - Health & Sanitation','Kagawad - Education','Kagawad - Social Services',
           'Kagawad - Peace & Order','Kagawad - Infrastructure','Kagawad - Agriculture',
           'Kagawad - Environment','SK Chairperson'), o.TermStart DESC, o.OfficialID ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $name = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter([
            $r['FirstName'] ?? '', $r['MiddleName'] ?? '', $r['LastName'] ?? '', $r['Suffix'] ?? '',
        ]))));
        if ($name === '') $name = trim((string) ($r['Name'] ?? ''));
        $position = (string) ($r['Position'] ?? '');
        $out[] = [
            'official_id' => (int) $r['OfficialID'],
            'name'        => $name,
            'position'    => $position,
            'group'       => official_group($position),
            'term_start'  => $r['TermStart'],
            'term_end'    => $r['TermEnd'],
            'photo_url'   => official_photo($r['Photo'] ?? null),
        ];
    }
    respond(true, '', ['officials' => $out]);
} catch (Throwable $e) {
    error_log('[officials.php] ' . $e->getMessage());
    // ?debug=1 shows the exact database error (for setup on XAMPP).
    respond(false, L('Hindi ma-load ang mga opisyal.', 'Could not load the officials.'),
        isset($_GET['debug']) ? ['error' => $e->getMessage()] : null, 500);
}

<?php
/**
 * user/backend/blotter.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Blotter / Incidents — read-only JSON API for the Flutter app (lib/blotter/).
 * Ported from the Blotter tab of the SOE resident page user/incidents.php and
 * reads the tables of the admin Blotter module (admin/blotter/):
 *   • cases where the resident is a complainant or respondent
 *     (blotter_parties.resident_id, or the primary ComplainantID/RespondentID)
 *   • hearings + results, notices issued to the resident, resolution,
 *     transfer and the case timeline
 *   • blotter reports are NOT filed online (same as SOE): the resident is told
 *     to go to the Barangay Hall
 *
 *   GET ?action=list&resident_id=           → cases + counts
 *   GET ?action=detail&resident_id=&id=     → one case (only if the resident is a party)
 *
 * Contact numbers / addresses of the other parties are not sent to the app.
 * Add &debug=1 to see the exact database error when loading fails.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php'; // respond(), handle_preflight(), db(), L()
handle_preflight();

$action = $_GET['action'] ?? 'list';
$rid    = require_resident(); // verified login token (auth.php)
if ($rid <= 0) {
    respond(false, L('Kailangan ang resident_id.', 'resident_id is required.'), null, 400);
}

/** Same list as admin blt_active_statuses(). */
const BLT_APP_ACTIVE = ['Filed', 'For Hearing', 'Hearing Scheduled', 'Notice Pending', 'Notice Issued',
                        'Hearing Completed', 'For Next Hearing', 'For Transfer',
                        'Pending', 'Scheduled']; // + SOE values

function blt_app_fail(Throwable $e): void
{
    error_log('[blotter.php] ' . $e->getMessage());
    respond(false, L('Hindi ma-load ang mga blotter record.', 'Could not load the blotter records.'),
        isset($_GET['debug']) ? ['error' => $e->getMessage()] : null, 500);
}

function blt_app_has(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (!isset($cache[$table])) {
        $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $s->execute([$table]);
        $cache[$table] = (int) $s->fetchColumn() > 0;
    }
    return $cache[$table];
}

function blt_app_name(array $p): string
{
    $n = trim(preg_replace('/\s+/', ' ', implode(' ', [
        $p['first_name'] ?? '', $p['middle_name'] ?? '', $p['last_name'] ?? '', $p['suffix'] ?? '',
    ])));
    if ($n !== '') return $n;
    if (trim((string) ($p['alias'] ?? '')) !== '') return trim((string) $p['alias']);
    return ($p['role'] ?? '') === 'Respondent' ? 'Unidentified Respondent' : 'Unknown';
}

function blt_app_type(array $c): string
{
    $t = trim((string) ($c['IncidentType'] ?? ''));
    $o = trim((string) ($c['IncidentTypeOther'] ?? ''));
    return strcasecmp($t, 'Other') === 0 && $o !== '' ? $o : $t;
}

/** BlotterID => my role, for every case the resident is part of. */
function blt_app_my_cases(PDO $pdo, int $rid): array
{
    $roles = [];
    $code = '';
    $s = $pdo->prepare("SELECT ResidentCode FROM residents WHERE ResidentID = ?");
    $s->execute([$rid]);
    $code = (string) ($s->fetchColumn() ?: '');

    if (blt_app_has($pdo, 'blotter_parties')) {
        $p = $pdo->prepare("SELECT blotter_id, role FROM blotter_parties WHERE resident_id = ? ORDER BY role = 'Respondent'");
        $p->execute([$rid]);
        foreach ($p->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $roles[(int) $x['blotter_id']] ??= $x['role'];
        }
    }
    // Primary complainant / respondent columns (SOE records store the ResidentID or code as text).
    $ids = array_values(array_filter([(string) $rid, $code]));
    $in = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare("SELECT BlotterID, ComplainantID FROM blotter WHERE ComplainantID IN ($in) OR RespondentID IN ($in)");
    $q->execute(array_merge($ids, $ids));
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) {
        $roles[(int) $x['BlotterID']] ??= in_array((string) $x['ComplainantID'], $ids, true) ? 'Complainant' : 'Respondent';
    }
    return $roles;
}

function blt_app_case_row(array $c, string $role, ?array $next): array
{
    $status = (string) ($c['Status'] ?? 'Filed');
    return [
        'id'            => (int) $c['BlotterID'],
        'case_number'   => $c['CaseNumber'] ?: ('BLO-' . substr((string) ($c['CreatedAt'] ?? date('Y')), 0, 4) . '-'
                               . str_pad((string) $c['BlotterID'], 4, '0', STR_PAD_LEFT)),
        'incident_type' => blt_app_type($c),
        'incident_date' => $c['IncidentDate'],
        'incident_time' => $c['IncidentTime'],
        'location'      => $c['Location'],
        'status'        => $status,
        'is_active'     => in_array($status, BLT_APP_ACTIVE, true),
        'my_role'       => $role,
        'complainant'   => $c['ComplainantName'] ?? null,
        'respondent'    => $c['RespondentName'] ?? null,
        'filed_at'      => $c['FiledAt'] ?? $c['CreatedAt'],
        'next_hearing'  => $next,
    ];
}

/** Next scheduled hearing per case (today or later). */
function blt_app_next_hearings(PDO $pdo, array $caseIds): array
{
    if (!$caseIds || !blt_app_has($pdo, 'blotter_hearings')) return [];
    $in = implode(',', array_map('intval', $caseIds));
    $out = [];
    foreach ($pdo->query("SELECT blotter_id, hearing_no, hearing_date, hearing_time, location
                          FROM blotter_hearings
                          WHERE blotter_id IN ($in) AND status = 'Scheduled' AND hearing_date >= CURDATE()
                          ORDER BY hearing_date, hearing_time")->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $out[(int) $h['blotter_id']] ??= [
            'no' => (int) $h['hearing_no'], 'date' => $h['hearing_date'],
            'time' => $h['hearing_time'], 'location' => $h['location'],
        ];
    }
    return $out;
}

try {
    $pdo = db();
    if (!blt_app_has($pdo, 'blotter')) {
        respond(true, '', ['cases' => [], 'counts' => ['total' => 0, 'active' => 0]]);
    }
    $roles = blt_app_my_cases($pdo, $rid);

    // ── LIST ────────────────────────────────────────────────────────────────
    if ($action === 'list') {
        $cases = [];
        if ($roles) {
            $in = implode(',', array_map('intval', array_keys($roles)));
            $rows = $pdo->query("SELECT * FROM blotter WHERE BlotterID IN ($in)
                                 ORDER BY COALESCE(FiledAt, CreatedAt) DESC, BlotterID DESC")->fetchAll(PDO::FETCH_ASSOC);
            $next = blt_app_next_hearings($pdo, array_keys($roles));
            foreach ($rows as $c) {
                $cases[] = blt_app_case_row($c, $roles[(int) $c['BlotterID']], $next[(int) $c['BlotterID']] ?? null);
            }
        }
        $active = count(array_filter($cases, fn($c) => $c['is_active']));
        respond(true, '', ['cases' => $cases, 'counts' => ['total' => count($cases), 'active' => $active]]);
    }

    // ── DETAIL ──────────────────────────────────────────────────────────────
    if ($action === 'detail') {
        $id = (int) ($_GET['id'] ?? 0);
        if (!isset($roles[$id])) {
            respond(false, L('Hindi nahanap ang kaso.', 'Case not found.'), null, 404);
        }
        $s = $pdo->prepare("SELECT * FROM blotter WHERE BlotterID = ?");
        $s->execute([$id]);
        $c = $s->fetch(PDO::FETCH_ASSOC);
        $next = blt_app_next_hearings($pdo, [$id]);
        $out = blt_app_case_row($c, $roles[$id], $next[$id] ?? null);
        $out['narrative'] = $c['Narrative'] ?: ($c['Details'] ?? null);

        // Parties: names and role only (no contact numbers / addresses of others).
        $parties = [];
        $myPartyIds = [];
        if (blt_app_has($pdo, 'blotter_parties')) {
            $p = $pdo->prepare("SELECT * FROM blotter_parties WHERE blotter_id = ?
                                ORDER BY role = 'Respondent', sort_order, party_id");
            $p->execute([$id]);
            foreach ($p->fetchAll(PDO::FETCH_ASSOC) as $x) {
                $isMe = (int) ($x['resident_id'] ?? 0) === $rid;
                if ($isMe) $myPartyIds[] = (int) $x['party_id'];
                $parties[] = [
                    'role'       => $x['role'],
                    'name'       => blt_app_name($x),
                    'type'       => $x['party_type'],
                    'is_primary' => (int) $x['is_primary'] === 1,
                    'is_me'      => $isMe,
                ];
            }
        }
        if (!$parties) { // SOE case without party rows
            if ($c['ComplainantName'] ?? null) $parties[] = ['role' => 'Complainant', 'name' => $c['ComplainantName'], 'type' => 'Resident', 'is_primary' => true, 'is_me' => $roles[$id] === 'Complainant'];
            if ($c['RespondentName'] ?? null) $parties[] = ['role' => 'Respondent', 'name' => $c['RespondentName'], 'type' => 'Resident', 'is_primary' => true, 'is_me' => $roles[$id] === 'Respondent'];
        }
        $out['parties'] = $parties;

        // Hearings + results.
        $out['hearings'] = [];
        if (blt_app_has($pdo, 'blotter_hearings')) {
            $hasRes = blt_app_has($pdo, 'blotter_hearing_results');
            $h = $pdo->prepare("SELECT h.*" . ($hasRes ? ", r.outcome, r.remarks AS result_remarks" : "") . "
                                FROM blotter_hearings h" .
                                ($hasRes ? " LEFT JOIN blotter_hearing_results r ON r.hearing_id = h.hearing_id" : "") . "
                                WHERE h.blotter_id = ? ORDER BY h.hearing_no, h.hearing_id");
            $h->execute([$id]);
            foreach ($h->fetchAll(PDO::FETCH_ASSOC) as $x) {
                $out['hearings'][] = [
                    'no'            => (int) $x['hearing_no'],
                    'date'          => $x['hearing_date'],
                    'time'          => $x['hearing_time'],
                    'location'      => $x['location'],
                    'status'        => $x['status'],
                    'cancel_reason' => $x['cancel_reason'] ?? null,
                    'outcome'       => $x['outcome'] ?? null,
                    'remarks'       => $x['result_remarks'] ?? null,
                ];
            }
        }

        // Notices issued to this resident (summons, notice of hearing …).
        $out['notices'] = [];
        if ($myPartyIds && blt_app_has($pdo, 'blotter_notices')) {
            $n = $pdo->prepare("SELECT n.*, h.hearing_date, h.hearing_time FROM blotter_notices n
                                LEFT JOIN blotter_hearings h ON h.hearing_id = n.hearing_id
                                WHERE n.blotter_id = ? AND n.status IN ('Issued','Generated')
                                ORDER BY COALESCE(n.issued_at, n.generated_at) DESC");
            $n->execute([$id]);
            foreach ($n->fetchAll(PDO::FETCH_ASSOC) as $x) {
                $to = array_filter(array_map('intval', explode(',', (string) ($x['recipient_party_ids'] ?? ''))));
                if ($x['recipient_party_id']) $to[] = (int) $x['recipient_party_id'];
                if (!array_intersect($to, $myPartyIds)) continue;
                $out['notices'][] = [
                    'notice_no'    => $x['notice_no'],
                    'type'         => $x['notice_type'],
                    'status'       => $x['status'],
                    'issued_at'    => $x['issued_at'] ?: $x['generated_at'],
                    'hearing_date' => $x['hearing_date'],
                    'hearing_time' => $x['hearing_time'],
                ];
            }
        }

        // Resolution / transfer.
        $out['resolution'] = null;
        if (blt_app_has($pdo, 'blotter_resolutions')) {
            $r = $pdo->prepare("SELECT resolution_details, resolved_at FROM blotter_resolutions
                                WHERE blotter_id = ? ORDER BY resolved_at DESC LIMIT 1");
            $r->execute([$id]);
            if ($x = $r->fetch(PDO::FETCH_ASSOC)) {
                $out['resolution'] = ['details' => $x['resolution_details'], 'at' => $x['resolved_at']];
            }
        }
        $out['transfer'] = null;
        if (blt_app_has($pdo, 'blotter_transfers')) {
            $t = $pdo->prepare("SELECT destination, transfer_date, reason FROM blotter_transfers
                                WHERE blotter_id = ? ORDER BY transfer_id DESC LIMIT 1");
            $t->execute([$id]);
            if ($x = $t->fetch(PDO::FETCH_ASSOC)) {
                $out['transfer'] = ['destination' => $x['destination'], 'date' => $x['transfer_date'], 'reason' => $x['reason']];
            }
        }
        if (!$out['transfer'] && ($c['Status'] ?? '') === 'For Transfer' && !empty($c['TransferDestination'])) {
            $out['transfer'] = ['destination' => $c['TransferDestination'], 'date' => null, 'reason' => null];
        }

        // Timeline (what happened, without staff names).
        $out['timeline'] = [];
        if (blt_app_has($pdo, 'blotter_timeline')) {
            $t = $pdo->prepare("SELECT action, status, recorded_at FROM blotter_timeline
                                WHERE blotter_id = ? ORDER BY recorded_at, timeline_id");
            $t->execute([$id]);
            foreach ($t->fetchAll(PDO::FETCH_ASSOC) as $x) {
                $out['timeline'][] = ['action' => $x['action'], 'status' => $x['status'], 'at' => $x['recorded_at']];
            }
        }
        respond(true, '', $out);
    }
} catch (Throwable $e) {
    blt_app_fail($e);
}

respond(false, L('Hindi wastong action.', 'Invalid action.'), null, 400);

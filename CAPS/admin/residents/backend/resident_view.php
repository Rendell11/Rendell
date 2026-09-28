<?php
/**
 * resident_view.php — everything the Residents → View modal shows for one resident.
 * GET ?id=<ResidentID>
 * → { success, resident: {...}, blotter: [...], complaints: [...], documents: [...], service: [...] }
 * Read-only. Only the personal record is editable (via the existing Edit form).
 * Each section is loaded on its own, so a missing module table never breaks the others.
 */
declare(strict_types=1);
// RV_AS_DATA: resident_report.php requires this file to reuse the same data (no JSON output).
if (!defined('RV_AS_DATA')) header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../permission_helper.php';

try { require_permission($pdo, 'residents', 'read'); }
catch (Throwable $e) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access denied.']); exit; }

$rid = (int)($_GET['id'] ?? 0);
if ($rid <= 0) { echo json_encode(['success' => false, 'message' => 'Missing resident ID.']); exit; }

function rv_date($v, string $f = 'M j, Y'): string {
    if (!$v || str_starts_with((string)$v, '0000')) return '';
    $t = strtotime((string)$v);
    return $t ? date($f, $t) : (string)$v;
}
function rv_rows(PDO $pdo, string $sql, array $args): array {
    try { $s = $pdo->prepare($sql); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { error_log('[Residents view] ' . $e->getMessage()); return []; }
}

$s = $pdo->prepare("SELECT * FROM residents WHERE ResidentID = ? LIMIT 1");
$s->execute([$rid]);
$r = $s->fetch(PDO::FETCH_ASSOC);
if (!$r) { echo json_encode(['success' => false, 'message' => 'Resident not found.']); exit; }
unset($r['Password'], $r['password'], $r['ResetToken'], $r['reset_token'], $r['TokenExpiry']);

/* ── Blotter cases (new multi-party table + older single-party columns) ── */
$blotter = [];
$seen = [];
foreach (rv_rows($pdo, "SELECT b.BlotterID, b.CaseNumber, b.Status, b.IncidentType, b.IncidentTypeOther, b.IncidentDate,
            b.FiledAt, b.CreatedAt, b.ComplainantName, b.RespondentName, p.role
        FROM blotter_parties p JOIN blotter b ON b.BlotterID = p.blotter_id
        WHERE p.resident_id = ?
        ORDER BY COALESCE(b.FiledAt, b.CreatedAt) DESC, b.BlotterID DESC", [$rid]) as $b) {
    $k = $b['BlotterID'] . '|' . $b['role'];
    if (isset($seen[$k])) continue;
    $seen[$k] = true;
    $blotter[] = $b;
}
foreach (rv_rows($pdo, "SELECT BlotterID, CaseNumber, Status, IncidentType, IncidentTypeOther, IncidentDate, FiledAt, CreatedAt,
            ComplainantName, RespondentName,
            CASE WHEN ComplainantID = ? THEN 'Complainant' ELSE 'Respondent' END AS role
        FROM blotter WHERE ComplainantID = ? OR RespondentID = ?", [(string)$rid, (string)$rid, (string)$rid]) as $b) {
    $k = $b['BlotterID'] . '|' . $b['role'];
    if (isset($seen[$k])) continue;
    $seen[$k] = true;
    $blotter[] = $b;
}
$blotter = array_map(fn($b) => [
    'id' => (int)$b['BlotterID'],
    'case_number' => $b['CaseNumber'] ?: ('#' . $b['BlotterID']),
    'role' => $b['role'],
    'type' => ($b['IncidentType'] === 'Other' && $b['IncidentTypeOther']) ? $b['IncidentTypeOther'] : ($b['IncidentType'] ?: '—'),
    'other_party' => ($b['role'] === 'Complainant' ? $b['RespondentName'] : $b['ComplainantName']) ?: '—',
    'incident' => rv_date($b['IncidentDate']),
    'filed' => rv_date($b['FiledAt'] ?: $b['CreatedAt']),
    'status' => $b['Status'] ?: 'Filed',
], $blotter);

/* ── Complaints ── */
$complaints = array_map(fn($c) => [
    'id' => $c['complaint_id'] ?: ('#' . $c['id']),
    'title' => $c['title'],
    'category' => ($c['category'] === 'Other' && $c['other_category_specify']) ? $c['other_category_specify'] : $c['category'],
    'priority' => $c['priority_level'],
    'status' => $c['status'],
    'anonymous' => (int)$c['is_anonymous'] === 1,
    'filed' => rv_date($c['created_at']),
], rv_rows($pdo, "SELECT id, complaint_id, title, category, other_category_specify, priority_level, status, is_anonymous, created_at
        FROM complaints WHERE resident_id = ? ORDER BY created_at DESC, id DESC", [$rid]));

/* ── Legal document requests ── */
$documents = array_map(fn($d) => [
    'reference' => $d['ReferenceNo'] ?: ('#' . $d['RequestID']),
    'doc_number' => $d['doc_number'] ?: '',
    'type' => $d['DocType'] ?: '—',
    'purpose' => $d['Purpose'] ?: '',
    'request_type' => $d['request_type'] ?: '',
    'status' => $d['Status'] ?: 'Pending',
    'requested' => rv_date($d['DateRequested'] ?: $d['DateCreated']),
    'released' => rv_date($d['release_date']),
], rv_rows($pdo, "SELECT RequestID, ReferenceNo, doc_number, DocType, Purpose, request_type, Status, DateRequested, DateCreated, release_date
        FROM document_requests WHERE ResidentID = ? ORDER BY COALESCE(DateRequested, DateCreated) DESC, RequestID DESC", [$rid]));

/* ── Official / Staff history (every term and assignment, current and past) ── */
$today = date('Y-m-d');
$service = [];
foreach (rv_rows($pdo, "SELECT * FROM officials WHERE ResidentID = ? ORDER BY TermStart DESC, OfficialID DESC", [$rid]) as $o) {
    if (!empty($o['ActualEndDate'])) $st = (!empty($o['ExpectedTermEnd']) && $o['ActualEndDate'] < $o['ExpectedTermEnd']) ? 'Ended Early' : 'Completed Term';
    elseif (!empty($o['TermEnd']) && $o['TermEnd'] < $today) $st = 'Completed Term';
    else $st = 'Current';
    $service[] = [
        'kind' => 'Official', 'position' => $o['Position'],
        'start' => rv_date($o['TermStart']), 'end' => rv_date($o['ActualEndDate'] ?: $o['TermEnd']),
        'status' => $st, 'reason' => $o['EndReason'] ?? '', '_sort' => (string)$o['TermStart'],
    ];
}
$staffRows = rv_rows($pdo, "SELECT * FROM staff WHERE ResidentID = ? ORDER BY COALESCE(EffectiveStart, DATE(CreatedAt)) DESC", [(string)$rid]);
$empIds = [];
foreach ($staffRows as $st) {
    $empIds[] = $st['EmployeeID'];
    $start = $st['EffectiveStart'] ?? null ?: substr((string)($st['CreatedAt'] ?? ''), 0, 10);
    $service[] = [
        'kind' => 'Staff', 'position' => ($st['Position'] ?: 'Staff') . ' · ' . $st['EmployeeID'],
        'start' => rv_date($start), 'end' => rv_date($st['EffectiveEnd'] ?? null),
        'status' => empty($st['EffectiveEnd']) ? 'Current' : 'Ended', 'reason' => $st['EndReason'] ?? '', '_sort' => (string)$start,
    ];
}
// Earlier positions of the same staff record (Edit changed the Position).
$sph = rv_rows($pdo, "SELECT * FROM staff_position_history WHERE ResidentID = ?" . ($empIds ? " OR EmployeeID IN (" . implode(',', array_fill(0, count($empIds), '?')) . ")" : '') . " ORDER BY StartDate DESC, id DESC",
    array_merge([(string)$rid], $empIds));
foreach ($sph as $h) {
    $service[] = [
        'kind' => 'Staff', 'position' => ($h['Position'] ?: 'Staff') . ' · ' . $h['EmployeeID'],
        'start' => rv_date($h['StartDate']), 'end' => rv_date($h['EndDate']),
        'status' => 'Position Changed', 'reason' => $h['EndReason'] ?? '', '_sort' => (string)$h['StartDate'],
    ];
}
usort($service, fn($a, $b) => strcmp($b['_sort'], $a['_sort']));
$service = array_map(function ($x) { unset($x['_sort']); return $x; }, $service);

/* ── Household (Head's household_survey record, HH-YYYY-#### via the Household module) ── */
$household = null;
$headId = (int)$r['IsHead'] === 1 ? $rid : (int)($r['FamilyHeadID'] ?? 0);
if ($headId > 0) {
    try {
        require_once __DIR__ . '/../../household/backend/household_common.php';
        $hq = $pdo->prepare("SELECT * FROM residents WHERE ResidentID = ? AND IsHead = 1 LIMIT 1");
        $hq->execute([$headId]);
        $head = $hq->fetch(PDO::FETCH_ASSOC);
        if ($head) {
            hh_ensure_schema($pdo);
            $surveyId = ((int)($head['IsDeceased'] ?? 0) === 1) ? 0 : hh_ensure_household_record($pdo, $headId);
            $hhCode = '';
            if ($surveyId > 0) {
                $hc = $pdo->prepare("SELECT HouseholdID FROM household_survey WHERE SurveyID = ?");
                $hc->execute([$surveyId]);
                $hhCode = (string)($hc->fetchColumn() ?: '');
            }
            $members = array_map(fn($m) => [
                'id' => (int)$m['ResidentID'],
                'name' => hh_full_name($m),
                'relationship' => $m['RelationshipToHead'] ?: 'Member',
            ], rv_rows($pdo, "SELECT ResidentID, FirstName, MiddleName, LastName, Suffix, RelationshipToHead FROM residents
                    WHERE FamilyHeadID = ? AND ResidentID <> ? AND (IsDeceased IS NULL OR IsDeceased = 0)
                    ORDER BY LastName, FirstName, ResidentID", [$headId, $headId]));
            $household = [
                'survey_id' => $surveyId,
                'household_id' => $hhCode,
                'head_id' => $headId,
                'head_name' => hh_full_name($head),
                'head_code' => $head['ResidentCode'] ?? '',
                'is_head' => $headId === $rid,
                'address' => hh_address($head),
                'members' => $members,
                'view_url' => '../../household/frontend/view_household.php?id=' . $headId,
            ];
        }
    } catch (Throwable $e) {
        error_log('[Residents view] household: ' . $e->getMessage());
    }
}

$rvPayload = [
    'success' => true,
    'resident' => $r,
    'household' => $household,
    'blotter' => $blotter,
    'complaints' => $complaints,
    'documents' => $documents,
    'service' => $service,
    'can_update' => staff_can($pdo, 'residents', 'update'),
    'can_blotter' => staff_can($pdo, 'blotter', 'read'),
    'can_household' => staff_can($pdo, 'households', 'read'),
];
if (defined('RV_AS_DATA')) return $rvPayload;
echo json_encode($rvPayload, JSON_UNESCAPED_UNICODE);

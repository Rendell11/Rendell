<?php
/**
 * blotter_cases.php — JSON endpoint for blotter cases.
 *
 * GET  ?action=counts                   card / tab counts
 * GET  ?action=list&tab=&status=&type=&q=&from=&to=   case list
 * GET  ?action=options                  incident types, locations, settings (File New Case form)
 * GET  ?action=search_resident&q=       resident search (Existing Resident)
 * GET  ?action=get_resident&id=         resident details for auto-fill
 * GET  ?action=get&id=                  everything the Case Details page shows
 * POST action=file                      File Case (multipart: complainants, respondents, incident fields, attachments[])
 *                                       incident_type "Other" needs incident_type_other (the typed type / category)
 * GET  ?action=case_summary&id=         short summary of a case (File New Case → active case → View)
 * POST action=close                     Resolved → Closed (a Resolved result closes the case automatically; this is for older cases)
 * POST action=sign_doc                  id, kind = complaint | transfer_record, signed_copy (photo / scan / PDF), remarks —
 *                                       complaint: the printed complaint signed (needed before the first hearing);
 *                                       transfer_record: the record signed by both parties (needed before Transfer Case)
 * GET  ?action=doc&id=&kind=            view that signed document
 * POST action=extend                    id, days, reason — time-based: the days ended → more days (new deadline from today)
 * POST action=for_transfer              id, reason — time-based: the days ended → For Transfer
 * POST action=cancel                    Cancelled / Withdrawn (reason required)
 * Every POST needs the CSRF token (csrf_token field or X-CSRF-Token header).
 */
ob_start();
require_once __DIR__ . '/../../db.php';
$required_module = 'blotter';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/blotter_common.php';

try { blt_migrate($pdo); }
catch (Throwable $e) { error_log('[Blotter] migrate: ' . $e->getMessage()); blt_json(['success' => false, 'message' => 'Database setup failed. Import sql/blotter.sql.'], 500); }

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function bc_row(array $r): array {
    $m = blt_status_meta((string)$r['Status']);
    $t = fn($v, $f) => $v ? date($f, strtotime((string)$v)) : '';
    return [
        'id' => (int)$r['BlotterID'],
        'case_number' => $r['CaseNumber'] ?: ('#' . $r['BlotterID']),
        'type' => blt_case_type_label($r) ?: '—',
        'complainant' => $r['ComplainantName'] ?: '—',
        'respondent' => $r['RespondentName'] ?: '—',
        'incident' => $t($r['IncidentDate'], 'M j, Y') . ($r['IncidentTime'] ? ' · ' . $t('2000-01-01 ' . $r['IncidentTime'], 'g:i A') : ''),
        'location' => $r['Location'] ?: '—',
        'filed' => $t($r['FiledAt'] ?: $r['CreatedAt'], 'M j, Y'),
        'filed_time' => $t($r['FiledAt'] ?: $r['CreatedAt'], 'g:i A'),
        'hearing' => in_array($r['Status'], blt_hearing_pending_statuses(), true) && $r['HearingDate']
            ? $t($r['HearingDate'], 'M j, Y') . ($r['HearingTime'] ? ' · ' . $t('2000-01-01 ' . $r['HearingTime'], 'g:i A') : '') : '',
        'status' => $r['Status'], 'status_class' => $m['class'], 'status_icon' => $m['icon'],
    ];
}

function bc_tab_where(string $tab): array {
    $in = fn(array $l) => ["Status IN (" . implode(',', array_fill(0, count($l), '?')) . ")", $l];
    switch ($tab) {
        case 'active':   return $in(array_values(array_diff(blt_active_statuses(), [BLT_ST_FOR_TRANSFER])));
        case 'hearing':  return $in(blt_hearing_pending_statuses());
        case 'resolved': return $in([BLT_ST_RESOLVED, BLT_ST_CLOSED]);
        case 'transfer': return $in([BLT_ST_FOR_TRANSFER, BLT_ST_TRANSFERRED]);
        case 'cancelled':return $in([BLT_ST_CANCELLED, BLT_ST_WITHDRAWN]);
        default:         return ['1=1', []];
    }
}

function bc_counts(PDO $pdo): array {
    $act = "'" . implode("','", array_diff(blt_active_statuses(), [BLT_ST_FOR_TRANSFER])) . "'";
    $hear = "'" . implode("','", blt_hearing_pending_statuses()) . "'";
    $r = $pdo->query("SELECT COUNT(*) AS total,
        SUM(Status IN ($act)) AS active,
        SUM(Status IN ($hear)) AS hearing,
        SUM(Status IN ('Resolved','Closed')) AS resolved,
        SUM(Status IN ('For Transfer','Transferred')) AS transfer,
        SUM(Status = 'For Transfer') AS for_transfer,
        SUM(Status IN ('Cancelled','Withdrawn')) AS cancelled,
        SUM(DATE(COALESCE(FiledAt, CreatedAt)) = CURDATE()) AS today
        FROM blotter")->fetch(PDO::FETCH_ASSOC);
    return array_map('intval', $r);
}

/* ───────── GET ───────── */

if ($action === 'counts') blt_json(['success' => true, 'counts' => bc_counts($pdo)]);

if ($action === 'list') {
    [$w, $args] = bc_tab_where((string)($_GET['tab'] ?? 'all'));
    $where = [$w];
    if (($st = trim((string)($_GET['status'] ?? ''))) !== '' && in_array($st, blt_statuses(), true)) { $where[] = 'Status = ?'; $args[] = $st; }
    if (($ty = trim((string)($_GET['type'] ?? ''))) !== '') { $where[] = 'IncidentType = ?'; $args[] = $ty; }
    if (blt_valid_date($_GET['from'] ?? null)) { $where[] = 'DATE(COALESCE(FiledAt, CreatedAt)) >= ?'; $args[] = $_GET['from']; }
    if (blt_valid_date($_GET['to'] ?? null)) { $where[] = 'DATE(COALESCE(FiledAt, CreatedAt)) <= ?'; $args[] = $_GET['to']; }
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = "(CaseNumber LIKE ? OR IncidentType LIKE ? OR IncidentTypeOther LIKE ? OR Location LIKE ? OR ComplainantName LIKE ? OR RespondentName LIKE ?
                     OR BlotterID IN (SELECT blotter_id FROM blotter_parties WHERE CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ?
                                      OR CONCAT_WS(' ', first_name, last_name) LIKE ? OR alias LIKE ? OR plate_number LIKE ?))";
        array_push($args, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
    }
    $s = $pdo->prepare("SELECT * FROM blotter WHERE " . implode(' AND ', $where) . " ORDER BY COALESCE(FiledAt, CreatedAt) DESC, BlotterID DESC LIMIT 1000");
    $s->execute($args);
    blt_json(['success' => true, 'rows' => array_map('bc_row', $s->fetchAll(PDO::FETCH_ASSOC)), 'counts' => bc_counts($pdo)]);
}

if ($action === 'options') {
    $set = blt_settings($pdo);
    $b = blt_barangay($pdo);
    $prof = [];
    try { $prof = $pdo->query("SELECT psgc_region_code, psgc_province_code, psgc_municipality_code, psgc_barangay_code FROM barangay_profile WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}
    blt_json(['success' => true,
        'incident_types' => (function ($l) { foreach ($l as $t) if (blt_is_other_type($t)) return $l; $l[] = BLT_TYPE_OTHER; return $l; })(array_column(blt_incident_types($pdo), 'name')),
        'locations' => blt_location_groups($pdo),
        'rule' => blt_rule_label($set['rule_type'], $set['max_days'], $set['max_hearings']),
        'barangay' => ['name' => $b['name'], 'region' => $prof['psgc_region_code'] ?? '', 'province' => $prof['psgc_province_code'] ?? '',
                       'municipality' => $prof['psgc_municipality_code'] ?? '', 'barangay' => $prof['psgc_barangay_code'] ?? ''],
        'max_upload_mb' => BLT_MAX_UPLOAD / 1048576,
        'evidence_types' => array_keys(blt_evidence_types()),
        'now' => ['date' => date('Y-m-d'), 'time' => date('H:i')],
    ]);
}

if ($action === 'search_resident') {
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') blt_json(['success' => true, 'residents' => []]);
    $like = '%' . $q . '%';
    $s = $pdo->prepare("SELECT ResidentID, ResidentCode, FirstName, MiddleName, LastName, Suffix, HouseNumber, StreetName, Purok
                        FROM residents
                        WHERE (IsDeceased = 0 OR IsDeceased IS NULL)
                          AND (CONCAT_WS(' ', FirstName, LastName) LIKE ? OR CONCAT_WS(' ', FirstName, MiddleName, LastName) LIKE ?
                               OR CONCAT_WS(', ', LastName, FirstName) LIKE ? OR ResidentCode LIKE ? OR CONCAT_WS(' ', HouseNumber, StreetName, Purok) LIKE ?)
                        ORDER BY (ResidentCode = ?) DESC, (LastName LIKE ?) DESC, LastName, FirstName LIMIT 20");
    $s->execute([$like, $like, $like, $like, $like, $q, $q . '%']);
    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['id' => (int)$r['ResidentID'], 'name' => blt_person_name($r), 'code' => $r['ResidentCode'] ?: ('#' . $r['ResidentID']), 'address' => blt_resident_address($pdo, $r)];
    }
    blt_json(['success' => true, 'residents' => $out]);
}

if ($action === 'get_resident') {
    $r = blt_resident($pdo, (int)($_GET['id'] ?? 0));
    if (!$r || !empty($r['IsDeceased'])) blt_json(['success' => false, 'message' => 'Resident not found.'], 404);
    $bd = $r['BirthDate'] ? strtotime($r['BirthDate']) : null;
    $active = [];
    try {
        $s = $pdo->prepare("SELECT DISTINCT b.BlotterID AS id, b.CaseNumber, b.Status, p.role FROM blotter b JOIN blotter_parties p ON p.blotter_id = b.BlotterID
                            WHERE p.resident_id = ? AND b.Status IN ('" . implode("','", blt_active_statuses()) . "') LIMIT 10");
        $s->execute([(int)$r['ResidentID']]);
        $active = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    blt_json(['success' => true, 'resident' => [
        'id' => (int)$r['ResidentID'], 'code' => $r['ResidentCode'] ?: '—', 'name' => blt_person_name($r),
        'first_name' => $r['FirstName'], 'middle_name' => $r['MiddleName'], 'last_name' => $r['LastName'], 'suffix' => $r['Suffix'],
        'sex' => $r['Sex'] ?: '', 'birth_date' => $r['BirthDate'] ?: '', 'birth_label' => $bd ? date('M j, Y', $bd) : '',
        'age' => $bd ? (new DateTime(date('Y-m-d', $bd)))->diff(new DateTime())->y : '',
        'contact' => trim((string)$r['ContactNumber']),   // from the resident profile (read-only on the form)
        'address' => blt_resident_address($pdo, $r),
        'has_address' => trim(($r['HouseNumber'] ?? '') . ($r['StreetName'] ?? '') . ($r['Purok'] ?? '')) !== '',
        'active_cases' => $active,
    ]]);
}

// Short summary of a case (File New Case → "Also in active case(s)" → View): the Blotter ID alone is hard to remember.
if ($action === 'case_summary') {
    $c = blt_case($pdo, (int)($_GET['id'] ?? 0));
    if (!$c) blt_json(['success' => false, 'message' => 'Case not found.'], 404);
    $d = fn($v, $f) => $v ? date($f, strtotime((string)$v)) : '';
    $hs = blt_hearings($pdo, (int)$c['BlotterID']);
    $last = $hs ? end($hs) : null;
    $limit = blt_limit_state($pdo, $c);
    blt_json(['success' => true, 'case' => [
        'id' => (int)$c['BlotterID'], 'case_number' => $c['CaseNumber'], 'status' => $c['Status'],
        'type' => blt_case_type_label($c), 'filed' => $d($c['FiledAt'] ?: $c['CreatedAt'], 'F j, Y g:i A'), 'filed_by' => $c['FiledBy'],
        'incident' => $d($c['IncidentDate'], 'F j, Y') . ($c['IncidentTime'] ? ' ' . date('g:i A', strtotime('2000-01-01 ' . $c['IncidentTime'])) : ''), 'location' => $c['Location'],
        'description' => mb_strimwidth((string)$c['Narrative'], 0, 600, '…'),
        'parties' => array_map(fn($p) => ['role' => $p['role'], 'name' => blt_party_label($p)], blt_parties($pdo, (int)$c['BlotterID'])),
        'hearings' => count($hs), 'rule' => $limit['label'],
        'last_hearing' => $last ? '#' . $last['hearing_no'] . ' · ' . date('M j, Y', strtotime($last['hearing_date'])) . ' · ' . ($last['outcome'] ?: $last['status']) : '',
    ]]);
}

// Weekly schedule (Blotter page calendar, Add Hearing): hearings from ?start=YYYY-MM-DD for ?days=7 (max 31).
if ($action === 'week') {
    $start = blt_valid_date($_GET['start'] ?? null) ? $_GET['start'] : date('Y-m-d', strtotime('monday this week'));
    $days = max(1, min(31, (int)($_GET['days'] ?? 7)));
    $end = date('Y-m-d', strtotime($start . ' +' . ($days - 1) . ' days'));
    $s = $pdo->prepare("SELECT h.hearing_id, h.hearing_no, h.hearing_date, h.hearing_time, h.location, h.agenda, h.status, r.outcome, b.BlotterID, b.CaseNumber, b.Status AS case_status,
                               b.ComplainantName, b.RespondentName, b.IncidentType, b.IncidentTypeOther
                        FROM blotter_hearings h JOIN blotter b ON b.BlotterID = h.blotter_id LEFT JOIN blotter_hearing_results r ON r.hearing_id = h.hearing_id
                        WHERE h.hearing_date BETWEEN ? AND ? AND h.status <> 'Cancelled' ORDER BY h.hearing_date, h.hearing_time");
    $s->execute([$start, $end]);
    blt_json(['success' => true, 'start' => $start, 'end' => $end, 'minutes' => blt_hearing_minutes($pdo), 'hearings' => array_map(fn($r) => [
        'id' => (int)$r['hearing_id'], 'case_id' => (int)$r['BlotterID'], 'case_number' => $r['CaseNumber'], 'no' => (int)$r['hearing_no'],
        'date' => $r['hearing_date'], 'time' => substr($r['hearing_time'], 0, 5), 'time_label' => date('g:i A', strtotime('2000-01-01 ' . $r['hearing_time'])),
        'location' => $r['location'], 'agenda' => $r['agenda'], 'status' => $r['status'], 'outcome' => $r['outcome'], 'case_status' => $r['case_status'],
        'complainant' => $r['ComplainantName'], 'respondent' => $r['RespondentName'], 'type' => blt_case_type_label($r),
    ], $s->fetchAll(PDO::FETCH_ASSOC))]);
}

if ($action === 'doc') {
    if (!blt_can($pdo, 'read')) { http_response_code(403); exit('Access denied.'); }
    $c = blt_case($pdo, (int)($_GET['id'] ?? 0));
    $p = ($_GET['kind'] ?? '') === 'transfer_record' ? 'TransferRecord' : 'ComplaintSigned';
    if (!$c || empty($c[$p . 'File'])) { http_response_code(404); exit('No signed copy.'); }
    blt_stream_file($c[$p . 'File'], $c[$p . 'Type'] ?: 'application/octet-stream', $c[$p . 'Name'] ?: 'signed', true);
}

if ($action === 'get') {
    $id = (int)($_GET['id'] ?? 0);
    $c = blt_case($pdo, $id);
    if (!$c) blt_json(['success' => false, 'message' => 'Case not found.'], 404);
    $d = fn($v, $f) => $v ? date($f, strtotime((string)$v)) : '';
    $tm = fn($v) => $v ? date('g:i A', strtotime('2000-01-01 ' . $v)) : '';
    $limit = blt_limit_state($pdo, $c);
    $set = blt_settings($pdo);

    $parties = array_map(function ($p) {
        return [
            'id' => (int)$p['party_id'], 'role' => $p['role'], 'is_primary' => (int)$p['is_primary'], 'type' => $p['party_type'],
            'resident_id' => $p['resident_id'] ? (int)$p['resident_id'] : null, 'resident_code' => $p['ResidentCode'] ?? null,
            'name' => blt_party_name($p), 'label' => blt_party_label($p), 'is_identified' => (int)$p['is_identified'],
            'first_name' => $p['first_name'], 'middle_name' => $p['middle_name'], 'last_name' => $p['last_name'], 'suffix' => $p['suffix'], 'alias' => $p['alias'],
            'sex' => $p['sex'], 'birth_date' => $p['birth_date'], 'birth_label' => $p['birth_date'] ? date('M j, Y', strtotime($p['birth_date'])) : '',
            'contact' => $p['contact_number'], 'address' => blt_party_address($p), 'filed_address' => blt_party_filed_address($p),
            'address_changed' => !empty($p['address_changed']), 'moved_out' => !empty($p['moved_out']), 'house_street' => $p['house_street'],
            'region_code' => $p['region_code'], 'region_name' => $p['region_name'], 'province_code' => $p['province_code'], 'province_name' => $p['province_name'],
            'municipality_code' => $p['municipality_code'], 'municipality_name' => $p['municipality_name'], 'barangay_code' => $p['barangay_code'], 'barangay_name' => $p['barangay_name'],
            'physical_description' => $p['physical_description'], 'clothing' => $p['clothing'], 'vehicle' => $p['vehicle'],
            'plate_number' => $p['plate_number'], 'other_details' => $p['other_details'],
            'updated' => $p['updated_at'] ? date('M j, Y g:i A', strtotime($p['updated_at'])) . ' · ' . $p['updated_by'] : '',
        ];
    }, blt_parties($pdo, $id));

    $hist = $pdo->prepare("SELECT history_id, party_id, previous_data, changed_fields, reason, updated_by, updated_at FROM blotter_party_history WHERE blotter_id = ? ORDER BY updated_at DESC, history_id DESC");
    $hist->execute([$id]);
    $history = array_map(function ($h) {
        $prev = json_decode((string)$h['previous_data'], true) ?: [];
        return ['party_id' => (int)$h['party_id'], 'previous' => $prev ? blt_party_label($prev) : '', 'previous_address' => $prev ? blt_party_address($prev) : '',
                'changed' => $h['changed_fields'], 'reason' => $h['reason'], 'by' => $h['updated_by'], 'at' => date('M j, Y g:i A', strtotime($h['updated_at']))];
    }, $hist->fetchAll(PDO::FETCH_ASSOC));

    $att = $pdo->prepare("SELECT * FROM blotter_attachments WHERE blotter_id = ? AND is_deleted = 0 ORDER BY attachment_id");
    $att->execute([$id]);
    $attachments = array_map(fn($a) => [
        'id' => (int)$a['attachment_id'], 'name' => $a['original_name'], 'type' => $a['file_type'], 'ext' => strtoupper($a['file_ext']),
        'size' => $a['file_size'] >= 1048576 ? round($a['file_size'] / 1048576, 1) . ' MB' : max(1, round($a['file_size'] / 1024)) . ' KB',
        'is_image' => str_starts_with($a['file_type'], 'image/'), 'description' => $a['description'],
        'url' => '../backend/attachments.php?action=view&id=' . (int)$a['attachment_id'],
        'uploaded' => date('M j, Y g:i A', strtotime($a['uploaded_at'])), 'by' => $a['uploaded_by'],
    ], $att->fetchAll(PDO::FETCH_ASSOC));

    // Signed copies of each hearing outcome (several per hearing).
    $rf = $pdo->prepare("SELECT file_id, hearing_id, file_name, file_type, uploaded_at, uploaded_by FROM blotter_result_files WHERE blotter_id = ? ORDER BY file_id");
    $rf->execute([$id]);
    $resultFiles = [];
    foreach ($rf->fetchAll(PDO::FETCH_ASSOC) as $f) $resultFiles[(int)$f['hearing_id']][] = ['id' => (int)$f['file_id'], 'name' => $f['file_name'], 'type' => $f['file_type'],
        'at' => date('M j, Y g:i A', strtotime($f['uploaded_at'])) . ' · ' . $f['uploaded_by'], 'url' => '../backend/hearings.php?action=signed&file_id=' . (int)$f['file_id']];
    $hearings = array_map(fn($h) => [
        'id' => (int)$h['hearing_id'], 'no' => (int)$h['hearing_no'], 'date' => $h['hearing_date'], 'time' => substr($h['hearing_time'], 0, 5),
        'date_label' => date('F j, Y', strtotime($h['hearing_date'])), 'time_label' => $tm($h['hearing_time']),
        'location' => $h['location'], 'agenda' => $h['agenda'], 'status' => $h['status'], 'cancel_reason' => $h['cancel_reason'],
        'outcome' => $h['outcome'], 'result_remarks' => $h['result_remarks'], 'result_by' => $h['result_by'],
        'result_at' => $h['result_at'] ? date('M j, Y g:i A', strtotime($h['result_at'])) : '',
        'is_due' => $h['status'] === 'Scheduled' && $h['hearing_date'] <= date('Y-m-d'),
        'created_by' => $h['created_by'], 'notice_required' => (int)($h['notice_required'] ?? 0),
        'auto' => (int)($h['auto_recorded'] ?? 0),
        'attendance' => json_decode((string)($h['attendance'] ?? ''), true) ?: [], 'companions' => json_decode((string)($h['companions'] ?? ''), true) ?: [],
        'presided_by' => $h['presided_by'] ?? null, 'presided_position' => $h['presided_position'] ?? null,
        'personnel' => json_decode((string)($h['personnel'] ?? ''), true) ?: [],
        'files' => array_merge(!empty($h['signed_file_path']) ? [['id' => 0, 'name' => $h['signed_file_name'], 'type' => '', 'at' => '', 'url' => '../backend/hearings.php?action=signed&hearing_id=' . (int)$h['hearing_id']]] : [],
                               $resultFiles[(int)$h['hearing_id']] ?? []),
        'has_signed' => !empty($h['signed_file_path']) || !empty($resultFiles[(int)$h['hearing_id']]), 'signed_name' => $h['signed_file_name'] ?? null,
        'signed_at' => !empty($h['signed_uploaded_at']) ? date('M j, Y g:i A', strtotime($h['signed_uploaded_at'])) . ' · ' . $h['signed_uploaded_by'] : '',
    ], blt_hearings($pdo, $id));

    $nt = $pdo->prepare("SELECT n.notice_id, n.notice_no, n.hearing_id, n.purpose, n.recipient_party_id, n.recipient_party_ids, h.status AS hearing_status, n.notice_type, n.recipient_name, n.status, n.file_path, n.generated_at, n.generated_by, n.issued_at, n.issued_by, n.issue_remarks,
                                n.issued_file_path, n.issued_file_name, n.edited_at, n.edited_by, h.hearing_no
                         FROM blotter_notices n LEFT JOIN blotter_hearings h ON h.hearing_id = n.hearing_id WHERE n.blotter_id = ? ORDER BY n.notice_id DESC");
    $nt->execute([$id]);
    $notices = array_map(fn($n) => [
        'id' => (int)$n['notice_id'], 'no' => $n['notice_no'], 'hearing_id' => (int)$n['hearing_id'], 'hearing_no' => (int)$n['hearing_no'], 'purpose' => $n['purpose'], 'party_id' => (int)$n['recipient_party_id'],
        // every party the letter covers (one copy for both parties covers several)
        'party_ids' => array_values(array_map('intval', array_filter(explode(',', (string)($n['recipient_party_ids'] ?: $n['recipient_party_id']))))), 'joint' => !$n['recipient_party_id'] && str_contains((string)$n['recipient_party_ids'], ','), 'hearing_status' => $n['hearing_status'],
        'type' => $n['notice_type'], 'recipient' => $n['recipient_name'], 'status' => $n['status'], 'has_pdf' => (bool)$n['file_path'],
        'generated' => date('M j, Y g:i A', strtotime($n['generated_at'])) . ' · ' . $n['generated_by'],
        'issued' => $n['issued_at'] ? date('M j, Y g:i A', strtotime($n['issued_at'])) . ' · ' . $n['issued_by'] : '', 'issue_remarks' => $n['issue_remarks'],
        'has_signed' => (bool)$n['issued_file_path'], 'signed_name' => $n['issued_file_name'],
        'edited' => $n['edited_at'] ? date('M j, Y g:i A', strtotime($n['edited_at'])) . ' · ' . $n['edited_by'] : '',
    ], $nt->fetchAll(PDO::FETCH_ASSOC));

    $res = $pdo->prepare("SELECT r.*, h.hearing_no FROM blotter_resolutions r LEFT JOIN blotter_hearings h ON h.hearing_id = r.hearing_id WHERE r.blotter_id = ?");
    $res->execute([$id]);
    $resolution = ($r = $res->fetch(PDO::FETCH_ASSOC)) ? [
        'details' => $r['resolution_details'], 'remarks' => $r['remarks'], 'hearing_no' => $r['hearing_no'],
        'resolved_at' => date('F j, Y g:i A', strtotime($r['resolved_at'])), 'by' => $r['recorded_by'],
        'recorded_at' => date('M j, Y g:i A', strtotime($r['recorded_at'])),
    ] : null;

    $tr = $pdo->prepare("SELECT * FROM blotter_transfers WHERE blotter_id = ? ORDER BY transfer_id DESC");
    $tr->execute([$id]);
    $transfers = array_map(fn($t) => [
        'date' => date('F j, Y', strtotime($t['transfer_date'])), 'time' => $tm($t['transfer_time']), 'destination' => $t['destination'],
        'reason' => $t['reason'], 'remarks' => $t['remarks'], 'reference_no' => $t['reference_no'], 'receiving_officer' => $t['receiving_officer'],
        'receiving_details' => $t['receiving_details'], 'by' => $t['transferred_by'],
        'proof_name' => $t['proof_file_name'] ?? null, 'proof_url' => !empty($t['proof_file_path']) ? '../backend/transfers.php?action=proof&id=' . (int)$t['transfer_id'] : null,
    ], $tr->fetchAll(PDO::FETCH_ASSOC));

    $tl = $pdo->prepare("SELECT * FROM blotter_timeline WHERE blotter_id = ? ORDER BY recorded_at, timeline_id");
    $tl->execute([$id]);
    $timeline = array_map(fn($t) => [
        // print = a Print / Save PDF entry (filter on the page); case = everything else
        'kind' => preg_match('/Printed|PDF/i', (string)$t['action']) ? 'print' : 'case',
        'action' => $t['action'], 'status' => $t['status'], 'remarks' => $t['remarks'], 'by' => $t['recorded_by'],
        'date' => date('M j, Y', strtotime($t['recorded_at'])), 'time' => date('g:i:s A', strtotime($t['recorded_at'])),
    ], $tl->fetchAll(PDO::FETCH_ASSOC));

    $pending = null;
    foreach ($hearings as $h) if ($h['status'] === 'Scheduled') { $pending = $h; break; }
    $status = $c['Status'];
    $closed = $status === BLT_ST_CLOSED;
    $canRecord = blt_can($pdo, 'update');           // signed copies of the outcome may still be uploaded after closing
    $canUpdate = $canRecord && !$closed;            // closed: view / print / save PDF only
    $noticeTypes = blt_available_notice_types($pdo);
    $hasTemplate = (bool)$noticeTypes;
    $identified = blt_respondents_identified($pdo, $id);
    $respLocked = blt_respondents_locked($pdo, $id);
    // One notice generation per hearing: once generated, it is opened (Preview → Edit / Print), not generated again.
    $pendingHasNotice = $pending ? (bool)array_filter($notices, fn($n) => $n['hearing_id'] === $pending['id'] && $n['status'] !== 'Cancelled') : false;
    $m = blt_status_meta($status);
    blt_json(['success' => true,
        'case' => [
            'id' => (int)$c['BlotterID'], 'case_number' => $c['CaseNumber'], 'status' => $status, 'status_class' => $m['class'], 'status_icon' => $m['icon'],
            'filed' => $d($c['FiledAt'] ?: $c['CreatedAt'], 'F j, Y'), 'filed_time' => $d($c['FiledAt'] ?: $c['CreatedAt'], 'g:i A'), 'filed_by' => $c['FiledBy'] ?: '—',
            'type' => blt_case_type_label($c), 'type_is_other' => blt_is_other_type($c['IncidentType']), 'incident_date' => $d($c['IncidentDate'], 'F j, Y'), 'incident_time' => $tm($c['IncidentTime']),
            'location' => $c['Location'], 'location_is_other' => ($c['LocationOption'] ?? '') === 'Other', 'description' => $c['Narrative'],
            'rule' => $limit['label'], 'limit' => $limit,
            'transfer_destination' => $c['TransferDestination'] ?: blt_settings_destination($set),
            // Reference no. of the transfer = Notice No. of the letter for the transfer
            'transfer_ref' => (function () use ($pdo, $id) { $q = $pdo->prepare("SELECT notice_no FROM blotter_notices WHERE blotter_id = ? AND purpose = 'transfer_office' AND status IN ('Generated','Issued') ORDER BY notice_id DESC LIMIT 1"); $q->execute([$id]); return $q->fetchColumn() ?: ''; })(),
            'resolved_at' => $d($c['ResolvedAt'], 'M j, Y g:i A'), 'closed_at' => $d($c['ClosedAt'], 'M j, Y g:i A'),
            'cancelled_at' => $d($c['CancelledAt'], 'M j, Y g:i A'), 'cancel_reason' => $c['CancelReason'],
        ],
        'parties' => $parties, 'party_history' => $history, 'attachments' => $attachments, 'hearings' => $hearings,
        'notices' => $notices, 'resolution' => $resolution, 'transfers' => $transfers, 'timeline' => $timeline,
        // Signed case documents shown in the Notices table: the complaint (always) and the transfer record (For Transfer / after).
        'documents' => array_values(array_filter([
            ['kind' => 'complaint', 'label' => 'Complaint record', 'hint' => 'Signed by the complainant — needed before the first hearing',
             'signed' => !empty($c['ComplaintSignedAt']), 'at' => $d($c['ComplaintSignedAt'], 'M j, Y g:i A'), 'by' => $c['ComplaintSignedBy'], 'name' => $c['ComplaintSignedName'],
             'url' => !empty($c['ComplaintSignedFile']) ? '../backend/blotter_cases.php?action=doc&kind=complaint&id=' . $id : null],
            !empty($c['TransferRecordFile']) ?
            ['kind' => 'transfer_record', 'label' => 'Transfer record', 'hint' => 'Signed by the complainant and the respondent — needed before Transfer Case',
             'signed' => !empty($c['TransferRecordFile']), 'at' => $d($c['TransferRecordAt'], 'M j, Y g:i A'), 'by' => $c['TransferRecordBy'], 'name' => $c['TransferRecordName'],
             'url' => !empty($c['TransferRecordFile']) ? '../backend/blotter_cases.php?action=doc&kind=transfer_record&id=' . $id : null] : null,
        ])),
        'next_hearing_no' => (int)$pdo->query("SELECT COALESCE(MAX(hearing_no), 0) + 1 FROM blotter_hearings WHERE blotter_id = " . (int)$id)->fetchColumn(),
        'default_hearing_location' => $set['default_hearing_location'],
        'noshow_counts' => (int)$set['noshow_counts'],
        // Every current official and staff member (BPSO / tanod included) — "who sat with the parties" + other personnel.
        'personnel' => blt_personnel($pdo),
        // Every usable, active notice template (the exact template is chosen on Add Hearing / Reschedule / Cancel).
        'letter_templates' => array_map(fn($t) => ['id' => (int)$t['template_id'], 'type' => $t['notice_type'], 'name' => $t['name'], 'is_default' => (int)$t['is_default'], 'extras' => blt_template_extras($t)],
            $pdo->query("SELECT t.* FROM blotter_notice_templates t WHERE " . blt_usable_template_sql('t') . " ORDER BY t.notice_type, t.is_default DESC, t.name")->fetchAll(PDO::FETCH_ASSOC)),
        // Suggestions for "who sat with the parties" on Record Result.
        'presider_suggestions' => array_values(array_unique(array_filter([preg_replace('/^HON\.\s*/', '', blt_current_captain($pdo)), blt_actor_name($pdo)]))),
        'can' => [
            'update' => $canUpdate,
            'delete' => blt_can($pdo, 'delete') && !$closed,
            'schedule' => $canUpdate && !$pending && $identified && !empty($c['ComplaintSignedAt']) && in_array($status, blt_can_schedule_statuses(), true) && !$limit['reached'],
            'respondents_identified' => $identified,
            // From the day of the hearing it can no longer be rescheduled or cancelled (record the result instead).
            'reschedule' => $canUpdate && $pending && !$pending['is_due'] && in_array($status, blt_hearing_pending_statuses(), true),
            'record_result' => $canUpdate && $pending && $pending['is_due'] && in_array($status, blt_hearing_pending_statuses(), true),
            // Notices are chosen on Add Hearing (one per person). Generate is offered only for people of the pending
            // hearing who have no notice yet — never a duplicate.
            // Letters are chosen inside the hearing flows (Add Hearing / Reschedule / Cancel / For Transfer) — no separate Generate button.
            'generate_notice' => false,
            'closed' => $closed,
            'upload_signed' => $canRecord,
            'has_template' => $hasTemplate,
            'notice_types' => $noticeTypes,
            // Extra information to type in before generating, per notice type (from the template Generate Notice uses).
            'notice_extras' => (function () use ($pdo, $noticeTypes) { $o = []; foreach ($noticeTypes as $t) { $tp = blt_template_for($pdo, $t); $o[$t] = $tp ? blt_template_extras($tp) : []; } return $o; })(),
            // Template each notice type uses (its default, else the newest usable one) — shown on Add Hearing.
            'notice_templates' => (function () use ($pdo, $noticeTypes) { $o = []; foreach ($noticeTypes as $t) { $tp = blt_template_for($pdo, $t); $o[$t] = $tp ? $tp['name'] : ''; } return $o; })(),
            'transfer' => $canUpdate && $status === BLT_ST_FOR_TRANSFER && blt_transfer_ready($pdo, $c)['ready'],
            // Resolved cases are closed automatically; older Resolved cases, and a case waiting at an office between two transfers, can be closed here.
            'close' => $canUpdate && $status === BLT_ST_RESOLVED,
            // Time-based: the days ended (no hearing waiting) → Extend the period, or mark the case For Transfer.
            'decide_period' => $canUpdate && $limit['rule'] === 'time' && $limit['reached'] && !$pending && in_array($status, blt_can_schedule_statuses(), true),
            'complaint_signed' => !empty($c['ComplaintSignedAt']),
            'sign_complaint' => $canUpdate && empty($c['ComplaintSignedAt']) && in_array($status, blt_active_statuses(), true),
            'transfer_ready' => $status === BLT_ST_FOR_TRANSFER ? ($tr = blt_transfer_ready($pdo, $c)) : null,
            // For Transfer: 1) letters to the complainant and respondent (marked issued) → 2) the letter for the transfer
            // to the receiving office (template from Settings; preview → Save) → 3) Transfer Case.
            'transfer_letter' => $canUpdate && $status === BLT_ST_FOR_TRANSFER && $hasTemplate && !empty($tr) && !$tr['parties'],
            'transfer_office' => $canUpdate && $status === BLT_ST_FOR_TRANSFER && !empty($tr) && $tr['parties'] && !$tr['office_made'] && !empty($set['transfer_template_id']),
            'transfer_template' => (function () use ($pdo, $set) { $t = !empty($set['transfer_template_id']) ? blt_template($pdo, (int)$set['transfer_template_id']) : null;
                return $t ? ['id' => (int)$t['template_id'], 'name' => $t['notice_type'] . ' — ' . $t['name'], 'extras' => blt_template_extras($t)] : null; })(),
            'cancel' => $canUpdate && in_array($status, blt_active_statuses(), true),
            'update_respondent' => $canUpdate && !$respLocked && !in_array($status, [BLT_ST_CANCELLED, BLT_ST_WITHDRAWN], true),
            'respondents_locked' => $respLocked,
            'add_evidence' => $canUpdate && !in_array($status, [BLT_ST_CANCELLED, BLT_ST_WITHDRAWN], true),
        ],
        'pending_hearing' => $pending,
    ]);
}

/* ───────── POST ───────── */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') blt_json(['success' => false, 'message' => 'Unknown action.'], 400);
blt_csrf_verify();
$actor = blt_actor_name($pdo);

if ($action === 'file') {
    blt_require($pdo, 'create');
    $errors = [];

    // Step 1 — complainant(s) and respondent(s)
    $comp = json_decode((string)($_POST['complainants'] ?? '[]'), true);
    $resp = json_decode((string)($_POST['respondents'] ?? '[]'), true);
    if (!is_array($comp) || !$comp) $errors[] = 'Add the complainant.';
    if (!is_array($resp) || !$resp) $errors[] = 'Add the respondent.';
    $comp = is_array($comp) ? array_slice($comp, 0, 10) : [];
    $resp = is_array($resp) ? array_slice($resp, 0, 10) : [];
    $cRows = []; $rRows = [];
    foreach ($comp as $i => $p) $cRows[] = blt_build_party($pdo, is_array($p) ? $p : [], 'Complainant', count($comp) > 1 ? 'Complainant ' . ($i + 1) : 'Complainant', $errors);
    foreach ($resp as $i => $p) $rRows[] = blt_build_party($pdo, is_array($p) ? $p : [], 'Respondent', count($resp) > 1 ? 'Respondent ' . ($i + 1) : 'Respondent', $errors);
    $ids = array_filter(array_merge(array_column($cRows, 'resident_id'), array_column($rRows, 'resident_id')));
    if (count($ids) !== count(array_unique($ids))) $errors[] = 'The same resident is listed more than once.';

    // Step 2 — incident
    $date = trim((string)($_POST['incident_date'] ?? ''));
    $time = trim((string)($_POST['incident_time'] ?? ''));
    if ($e = blt_incident_datetime_error($date, $time)) $errors[] = $e;
    $type = trim((string)($_POST['incident_type'] ?? ''));
    $typeOther = null;
    if (blt_is_other_type($type)) {
        $type = BLT_TYPE_OTHER;
        $typeOther = blt_clean($_POST['incident_type_other'] ?? null, 150);
        if (!$typeOther || mb_strlen($typeOther) < 2) $errors[] = 'Enter the incident type / category (Other).';
    } elseif (!in_array($type, array_column(blt_incident_types($pdo), 'name'), true)) $errors[] = 'Select the incident type.';
    $locOpt = trim((string)($_POST['location_option'] ?? ''));
    $locOther = blt_clean($_POST['location_other'] ?? null, 255);
    if ($locOpt === '') $errors[] = 'Select the incident location.';
    elseif ($locOpt === 'Other') { if (!$locOther) $errors[] = 'Enter the other location.'; }
    elseif (!blt_location_allowed($pdo, $locOpt)) $errors[] = 'Select a valid incident location.';
    $desc = blt_clean_text($_POST['description'] ?? null, 10000);
    if (!$desc || mb_strlen($desc) < 10) $errors[] = 'Describe the incident (at least 10 characters).';

    // Evidence — at least one file, each checked before anything is saved
    $files = blt_files_list('attachments');
    $descs = $_POST['attachment_desc'] ?? [];
    // Evidence is optional (it can be added later in Case Details).
    if (count($files) > 20) $errors[] = 'Attach at most 20 files per case.';
    foreach ($files as $f) {
        if (($f['error'] ?? 0) !== UPLOAD_ERR_OK) { $errors[] = '"' . $f['name'] . '" could not be uploaded (' . ((int)$f['error'] === UPLOAD_ERR_INI_SIZE ? 'too large for the server' : 'upload error') . ').'; continue; }
        if ($f['size'] > BLT_MAX_UPLOAD) $errors[] = '"' . $f['name'] . '" is larger than 10 MB.';
        $mime = @(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!isset(blt_evidence_types()[$mime])) $errors[] = '"' . $f['name'] . '" is not an allowed file type.';
    }
    if ($errors) blt_json(['success' => false, 'message' => $errors[0], 'errors' => $errors], 422);

    $set = blt_settings($pdo);
    $moved = [];
    try {
        $pdo->beginTransaction();
        $no = blt_next_case_number($pdo);
        $deadline = $set['rule_type'] === 'time' ? date('Y-m-d', strtotime('+' . $set['max_days'] . ' days')) : null;
        $pdo->prepare("INSERT INTO blotter (CaseNumber, CaseYear, CaseSeq, IncidentType, IncidentTypeOther, Narrative, IncidentDate, IncidentTime, LocationOption, LocationOther, Location,
                        Status, CurrentStage, HearingCount, Phase, RuleType, MaxDays, MaxHearings, DeadlineDate, TransferDestination, FiledAt, FiledBy, FiledByID, UpdatedBy, CreatedAt)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?, ?, 'Filed', 0, 1, ?,?,?,?,?, NOW(), ?, ?, ?, NOW())")
            ->execute([$no['number'], $no['year'], $no['seq'], $type, $typeOther, $desc, $date, strlen($time) === 5 ? $time . ':00' : $time,
                       $locOpt, $locOpt === 'Other' ? $locOther : null, $locOpt === 'Other' ? $locOther : $locOpt,
                       BLT_ST_FILED, $set['rule_type'], $set['max_days'], $set['max_hearings'], $deadline, blt_settings_destination($set),
                       $actor, blt_actor_id(), $actor]);
        $id = (int)$pdo->lastInsertId();
        foreach ($cRows as $i => $p) blt_insert_party($pdo, $id, 'Complainant', $p, $i === 0, $i + 1);
        foreach ($rRows as $i => $p) blt_insert_party($pdo, $id, 'Respondent', $p, $i === 0, $i + 1);
        blt_sync_party_cache($pdo, $id);

        $ins = $pdo->prepare("INSERT INTO blotter_attachments (blotter_id, original_name, stored_name, file_path, file_type, file_ext, file_size, description, uploaded_at, uploaded_by, uploaded_by_id)
                              VALUES (?,?,?,?,?,?,?,?, NOW(), ?, ?)");
        $names = [];
        foreach ($files as $i => $f) {
            $a = blt_store_evidence($f, $id);
            $moved[] = blt_upload_dir('evidence') . '/' . $a['stored_name'];
            $ins->execute([$id, $a['original_name'], $a['stored_name'], $a['file_path'], $a['file_type'], $a['file_ext'], $a['file_size'],
                           blt_clean(is_array($descs) ? ($descs[$i] ?? null) : null, 255), $actor, blt_actor_id()]);
            $names[] = $a['original_name'];
        }
        blt_timeline($pdo, $id, 'Case Filed', BLT_ST_FILED,
            'Blotter ' . $no['number'] . ' filed. ' . ($typeOther ? 'Other: ' . $typeOther : $type) . ' on ' . date('M j, Y', strtotime($date)) . ' ' . date('g:i A', strtotime('2000-01-01 ' . $time))
            . ' at ' . ($locOpt === 'Other' ? $locOther : $locOpt) . '. Rule: ' . blt_rule_label($set['rule_type'], $set['max_days'], $set['max_hearings']) . '.',
            'blotter', $id);
        if ($names) blt_timeline($pdo, $id, 'Evidence Attached', BLT_ST_FILED, count($names) . ' file(s): ' . implode(', ', $names), 'blotter_attachments', null);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($moved as $f) @unlink($f);
        error_log('[Blotter] file case: ' . $e->getMessage());
        blt_json(['success' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'The case could not be saved. Please try again.'], 500);
    }
    blt_log_activity('File Case', 'Filed blotter ' . $no['number'] . ' (' . ($typeOther ?: $type) . ')');
    blt_json(['success' => true, 'id' => $id, 'case_number' => $no['number'], 'message' => 'Case ' . $no['number'] . ' filed.']);
}

if ($action === 'close') {
    blt_require($pdo, 'update');
    $id = (int)($_POST['id'] ?? 0);
    $remarks = blt_clean_text($_POST['remarks'] ?? null, 2000);
    $pdo->beginTransaction();
    $c = blt_need_case(blt_case($pdo, $id, true), [BLT_ST_RESOLVED], 'close the case');
    blt_set_status($pdo, $id, BLT_ST_CLOSED, ['ClosedAt' => date('Y-m-d H:i:s'), 'CurrentStage' => 'Closed']);
    blt_timeline($pdo, $id, 'Case Closed', BLT_ST_CLOSED, $remarks ?? 'Closed after settlement.');
    $pdo->commit();
    blt_log_activity('Close Case', 'Closed blotter ' . $c['CaseNumber']);
    blt_json(['success' => true, 'message' => 'Case ' . $c['CaseNumber'] . ' closed.']);
}

if ($action === 'sign_doc') {
    blt_require($pdo, 'update');
    $id = (int)($_POST['id'] ?? 0);
    $kind = ($_POST['kind'] ?? '') === 'transfer_record' ? 'transfer_record' : 'complaint';
    $p = $kind === 'transfer_record' ? 'TransferRecord' : 'ComplaintSigned';
    $what = $kind === 'transfer_record' ? 'Transfer record' : 'Complaint record';
    $remarks = blt_clean($_POST['remarks'] ?? null, 500);
    $f = $_FILES['signed_copy'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) blt_json(['success' => false, 'message' => 'Attach the signed copy (photo / scan / PDF).'], 422);
    $pdo->beginTransaction();
    $c = blt_need_case(blt_case($pdo, $id, true), $kind === 'transfer_record' ? [BLT_ST_FOR_TRANSFER] : blt_active_statuses(), 'mark the ' . strtolower($what) . ' signed');
    if (!empty($c[$p . 'File'])) { $pdo->rollBack(); blt_json(['success' => false, 'message' => $what . ' is already signed.'], 409); }
    try { $doc = blt_store_signed($f, $kind . '_' . $c['CaseNumber']); }
    catch (RuntimeException $e) { $pdo->rollBack(); blt_json(['success' => false, 'message' => $e->getMessage()], 422); }
    $pdo->prepare("UPDATE blotter SET {$p}At = NOW(), {$p}By = ?, {$p}File = ?, {$p}Name = ?, {$p}Type = ? WHERE BlotterID = ?")->execute([$actor, $doc[0], $doc[1], $doc[2], $id]);
    blt_timeline($pdo, $id, $what . ' Signed', $c['Status'], $what . ' marked signed — ' . $doc[1] . ($remarks ? '. ' . $remarks : '') . '.');
    $pdo->commit();
    blt_log_activity('Sign ' . $what, $c['CaseNumber']);
    blt_json(['success' => true, 'message' => $what . ' marked signed.' . ($kind === 'complaint' ? ' Hearings can now be added (once the respondent details are complete).' : '')]);
}

if ($action === 'extend' || $action === 'for_transfer') {
    blt_require($pdo, 'update');
    $id = (int)($_POST['id'] ?? 0);
    $reason = blt_clean_text($_POST['reason'] ?? null, 500);
    $pdo->beginTransaction();
    $c = blt_need_case(blt_case($pdo, $id, true), blt_can_schedule_statuses(), $action === 'extend' ? 'extend the period' : 'mark the case For Transfer');
    $lim = blt_limit_state($pdo, $c);
    if ($lim['rule'] !== 'time' || !$lim['reached']) { $pdo->rollBack(); blt_json(['success' => false, 'message' => 'The days to settle this case have not ended yet.'], 409); }
    if ($action === 'extend') {
        $days = (int)($_POST['days'] ?? 0);
        if ($days < 1 || $days > 365) { $pdo->rollBack(); blt_json(['success' => false, 'message' => 'Enter 1 to 365 days.'], 422); }
        if (!$reason) { $pdo->rollBack(); blt_json(['success' => false, 'message' => 'Enter the reason for the extension.'], 422); }
        $new = date('Y-m-d', strtotime('+' . $days . ' days'));
        blt_set_status($pdo, $id, $c['Status'], ['DeadlineDate' => $new]);
        blt_timeline($pdo, $id, 'Resolution Period Extended', $c['Status'], '+' . $days . ' day(s): settle by ' . date('F j, Y', strtotime($new)) . ' (was ' . date('F j, Y', strtotime($lim['deadline'])) . '). Reason: ' . $reason);
        $msg = 'Extended by ' . $days . ' day(s) — settle by ' . date('F j, Y', strtotime($new)) . '.';
    } else {
        blt_set_status($pdo, $id, BLT_ST_FOR_TRANSFER, ['CurrentStage' => BLT_ST_FOR_TRANSFER]);
        blt_timeline($pdo, $id, 'For Transfer', BLT_ST_FOR_TRANSFER, 'The days to settle the case ended on ' . date('F j, Y', strtotime($lim['deadline'])) . ' without a settlement. Transfer to ' . ($c['TransferDestination'] ?: blt_settings_destination(blt_settings($pdo))) . '.' . ($reason ? ' ' . $reason : ''));
        $msg = 'The case is now For Transfer.';
    }
    $pdo->commit();
    blt_log_activity($action === 'extend' ? 'Extend Period' : 'Mark For Transfer', $c['CaseNumber']);
    blt_json(['success' => true, 'message' => $msg]);
}

if ($action === 'cancel') {
    blt_require($pdo, 'update');
    $id = (int)($_POST['id'] ?? 0);
    $kind = ($_POST['kind'] ?? '') === 'Withdrawn' ? BLT_ST_WITHDRAWN : BLT_ST_CANCELLED;
    $reason = blt_clean_text($_POST['reason'] ?? null, 500);
    if (!$reason) blt_json(['success' => false, 'message' => 'Enter the reason.'], 422);
    $pdo->beginTransaction();
    $c = blt_need_case(blt_case($pdo, $id, true), blt_active_statuses(), 'cancel the case');
    blt_set_status($pdo, $id, $kind, ['CancelledAt' => date('Y-m-d H:i:s'), 'CancelReason' => $reason, 'CurrentStage' => $kind]);
    $pdo->prepare("UPDATE blotter_hearings SET status = 'Cancelled', cancel_reason = ?, updated_at = NOW() WHERE blotter_id = ? AND status = 'Scheduled'")
        ->execute(['Case ' . strtolower($kind) . '.', $id]);
    blt_timeline($pdo, $id, $kind === BLT_ST_WITHDRAWN ? 'Complaint Withdrawn' : 'Case Cancelled', $kind, $reason);
    $pdo->commit();
    blt_log_activity($kind === BLT_ST_WITHDRAWN ? 'Withdraw Case' : 'Cancel Case', $c['CaseNumber'] . ': ' . $reason);
    blt_json(['success' => true, 'message' => 'Case ' . $c['CaseNumber'] . ' marked ' . $kind . '.']);
}

blt_json(['success' => false, 'message' => 'Unknown action.'], 400);

<?php
/**
 * cert_actions.php — JSON endpoint for the Certificates main page (legal_docu.php).
 *
 * GET  list            table rows for a tab (pending | queue | released | expired | walkin | all)
 * GET  counts          card numbers + the bubble count of new (unopened) online requests
 * GET  search_resident live resident search for Issue Walk-In step 1 (name / Resident ID / address)
 * GET  get_resident    resident + eligibility (verified, active, active blotter cases, unclaimed, 30-day history)
 * GET  blotter_case    full blotter case for the "View" pop-up
 * GET  doc_form        requirements + extra information fields of a document
 * GET  request         everything about one request (details, logs, eligibility, render model)
 * POST save_walkin     Issue Walk-In step 6 → status Preview
 * POST open_review     online Pending → Review (marks it read)
 * POST accept          online Pending/Review → Ready to Pick Up (document generated, not printed)
 * POST reject          online Pending/Review → Rejected (reason required)
 * POST save_override   per-document layout (this document only; template unchanged)
 * POST release         Print & Release: Preview / Ready to Pick Up → Released (+ one-time print token)
 * Status rules are enforced here, not only by hiding buttons.
 */
ob_start();
require_once __DIR__ . '/../../db.php';
$required_module = 'certificates';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/cert_common.php';

try { cert_migrate($pdo); }
catch (Throwable $e) { error_log('[Certificates] migrate: ' . $e->getMessage()); cert_json(['success' => false, 'message' => 'Database setup failed.'], 500); }

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function ca_row(array $r): array {
    $meta = cert_status_meta($r['Status']);
    return [
        'id' => (int)$r['RequestID'],
        'ref' => $r['ReferenceNo'] ?: '—',
        'doc_number' => $r['doc_number'] ?: '',
        'resident' => cert_person_name($r) ?: 'Unknown resident',
        'resident_code' => $r['ResidentCode'] ?: '',
        'doc_type' => $r['DocType'],
        'purpose' => $r['Purpose'] ?: '',
        'type' => $r['request_type'],
        'status' => $r['Status'],
        'status_class' => $meta['class'], 'status_icon' => $meta['icon'],
        'action' => cert_row_action($r),
        'is_new' => $r['request_type'] === 'online' && $r['Status'] === CERT_ST_PENDING && !(int)$r['notif_read'],
        'date' => $r['DateRequested'] ? date('M j, Y', strtotime($r['DateRequested'])) : '—',
        'time' => $r['DateRequested'] ? date('g:i A', strtotime($r['DateRequested'])) : '',
        'pickup_until' => ($r['Status'] === CERT_ST_READY && $r['approved_at']) ? date('M j, Y', strtotime($r['approved_at'] . ' +' . CERT_PICKUP_DAYS . ' days')) : '',
    ];
}

function ca_tab_where(string $tab): string {
    switch ($tab) {
        case 'pending':  return "dr.Status IN ('Pending','Review')";
        case 'queue':    return "dr.request_type = 'online' AND dr.Status = 'Ready to Pick Up'";
        case 'released': return "dr.Status = 'Released'";
        case 'expired':  return "dr.Status = 'Expired'";
        case 'walkin':   return "dr.request_type = 'walk-in'";
        case 'rejected': return "dr.Status = 'Rejected'";
        default:         return "1=1";
    }
}

function ca_counts(PDO $pdo): array {
    $r = $pdo->query("SELECT
        COUNT(*) AS total,
        SUM(Status IN ('Pending','Review')) AS pending,
        SUM(request_type = 'online' AND Status = 'Ready to Pick Up') AS queue,
        SUM(Status = 'Released') AS released,
        SUM(Status = 'Expired') AS expired,
        SUM(Status = 'Rejected') AS rejected,
        SUM(Status = 'Preview') AS preview,
        SUM(request_type = 'walk-in') AS walkin,
        SUM(request_type = 'online') AS online,
        SUM(request_type = 'online' AND Status = 'Pending' AND notif_read = 0) AS new_online,
        SUM(DATE(DateRequested) = CURDATE()) AS today
        FROM document_requests")->fetch(PDO::FETCH_ASSOC);
    return array_map('intval', $r);
}

function ca_need(?array $r, array $allowed = [], string $what = 'do this'): array {
    if (!$r) cert_json(['success' => false, 'message' => 'Request not found.'], 404);
    if ($allowed && !in_array($r['Status'], $allowed, true)) {
        cert_json(['success' => false, 'message' => 'Cannot ' . $what . ': this request is already ' . $r['Status'] . '.'], 409);
    }
    return $r;
}

/** Load a request row with a row lock (inside a transaction). */
function ca_lock(PDO $pdo, int $id): ?array {
    $s = $pdo->prepare("SELECT * FROM document_requests WHERE RequestID = ? FOR UPDATE");
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* ───────── GET ───────── */

if ($action === 'counts') cert_json(['success' => true, 'counts' => ca_counts($pdo)]);

if ($action === 'list') {
    $tab = (string)($_GET['tab'] ?? 'pending');
    $where = [ca_tab_where($tab)]; $args = [];
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $where[] = "(dr.ReferenceNo LIKE ? OR dr.doc_number LIKE ? OR dr.DocType LIKE ? OR r.ResidentCode LIKE ?
                     OR CONCAT_WS(' ', r.FirstName, r.MiddleName, r.LastName) LIKE ? OR CONCAT_WS(' ', r.FirstName, r.LastName) LIKE ?)";
        $like = '%' . $q . '%';
        array_push($args, $like, $like, $like, $like, $like, $like);
    }
    if (($dt = trim((string)($_GET['doc_type'] ?? ''))) !== '') { $where[] = "dr.DocType = ?"; $args[] = $dt; }
    $s = $pdo->prepare("SELECT dr.*, r.FirstName, r.MiddleName, r.LastName, r.Suffix, r.ResidentCode
                        FROM document_requests dr LEFT JOIN residents r ON r.ResidentID = dr.ResidentID
                        WHERE " . implode(' AND ', $where) . "
                        ORDER BY (dr.Status = 'Pending' AND dr.notif_read = 0) DESC, dr.DateRequested DESC, dr.RequestID DESC LIMIT 500");
    $s->execute($args);
    cert_json(['success' => true, 'rows' => array_map('ca_row', $s->fetchAll(PDO::FETCH_ASSOC)), 'counts' => ca_counts($pdo)]);
}

if ($action === 'search_resident') {
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') cert_json(['success' => true, 'residents' => []]);
    $like = '%' . $q . '%';
    $s = $pdo->prepare("SELECT ResidentID, ResidentCode, FirstName, MiddleName, LastName, Suffix, HouseNumber, StreetName, Purok, BirthDate
                        FROM residents
                        WHERE (IsDeceased = 0 OR IsDeceased IS NULL)
                          AND (CONCAT_WS(' ', FirstName, LastName) LIKE ? OR CONCAT_WS(' ', FirstName, MiddleName, LastName) LIKE ?
                               OR CONCAT_WS(', ', LastName, FirstName) LIKE ? OR ResidentCode LIKE ? OR CAST(ResidentID AS CHAR) = ?
                               OR CONCAT_WS(' ', HouseNumber, StreetName, Purok) LIKE ?)
                        ORDER BY (ResidentCode = ?) DESC, (LastName LIKE ?) DESC, LastName, FirstName
                        LIMIT 20");
    $s->execute([$like, $like, $like, $like, $q, $like, $q, $q . '%']);
    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['id' => (int)$r['ResidentID'], 'name' => cert_person_name($r), 'code' => $r['ResidentCode'] ?: ('#' . $r['ResidentID']),
                  'address' => cert_resident_address($pdo, $r)];
    }
    cert_json(['success' => true, 'residents' => $out]);
}

if ($action === 'get_resident') {
    $r = cert_resident($pdo, (int)($_GET['id'] ?? 0));
    if (!$r) cert_json(['success' => false, 'message' => 'Resident not found.'], 404);
    $bd = $r['BirthDate'] ? strtotime($r['BirthDate']) : null;
    cert_json(['success' => true, 'resident' => [
        'id' => (int)$r['ResidentID'], 'code' => $r['ResidentCode'] ?: '—', 'name' => cert_person_name($r),
        'address' => cert_resident_address($pdo, $r), 'sex' => $r['Sex'] ?: '—', 'civil_status' => $r['CivilStatus'] ?: '—',
        'birth_date' => $bd ? date('M j, Y', $bd) : '—', 'age' => $bd ? (new DateTime(date('Y-m-d', $bd)))->diff(new DateTime())->y : '—',
        'contact' => $r['ContactNumber'] ?: '—',
    ], 'eligibility' => cert_eligibility($pdo, $r)]);
}

if ($action === 'blotter_case') {
    $s = $pdo->prepare("SELECT * FROM blotter WHERE BlotterID = ?");
    $s->execute([(int)($_GET['id'] ?? 0)]);
    $b = $s->fetch(PDO::FETCH_ASSOC);
    if (!$b) cert_json(['success' => false, 'message' => 'Case not found.'], 404);
    $name = function ($id) use ($pdo) {
        if ($id === null || $id === '') return '—';
        $s = $pdo->prepare("SELECT FirstName, MiddleName, LastName, Suffix, ResidentCode FROM residents WHERE CAST(ResidentID AS CHAR) = ? OR ResidentCode = ? LIMIT 1");
        $s->execute([(string)$id, (string)$id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ? cert_person_name($r) . ' (' . ($r['ResidentCode'] ?: '#' . $id) . ')' : (string)$id;
    };
    $d = fn($v, $f) => $v ? date($f, strtotime($v)) : '—';
    cert_json(['success' => true, 'case' => [
        'blotter_no' => $b['CaseNumber'] ?: 'BLT-' . str_pad((string)$b['BlotterID'], 4, '0', STR_PAD_LEFT),
        'status' => $b['Status'], 'stage' => $b['CurrentStage'] ?? '', 'type' => $b['IncidentType'] ?: '—',
        'incident' => $d($b['IncidentDate'], 'M j, Y') . ($b['IncidentTime'] ? ' ' . $d($b['IncidentTime'], 'g:i A') : ''),
        'location' => $b['Location'] ?? '—', 'complainant' => $name($b['ComplainantID']), 'respondent' => $name($b['RespondentID']),
        'hearing' => ($b['HearingDate'] ?? null) ? $d($b['HearingDate'], 'M j, Y') . (($b['HearingTime'] ?? null) ? ' ' . $d($b['HearingTime'], 'g:i A') : '') : '—',
        'officer' => $b['AssignedOfficer'] ?? '—',
        'narrative' => $b['Narrative'] ?? ($b['Details'] ?? ''), 'details' => $b['Details'] ?? '',
        'filed' => $d($b['CreatedAt'] ?? null, 'M j, Y g:i A'),
    ]]);
}

if ($action === 'doc_form') {
    $dt = cert_doc_type($pdo, (string)($_GET['doc_type'] ?? ''));
    if (!$dt || !$dt['is_active'] || $dt['is_draft']) cert_json(['success' => false, 'message' => 'This document is not available for issuing.'], 404);
    cert_json(['success' => true, 'requirements' => cert_requirements($pdo, $dt['doc_type']), 'extra_fields' => cert_extra_fields($pdo, $dt['doc_type'])]);
}

if ($action === 'request') {
    $id = (int)($_GET['id'] ?? 0);
    $r = cert_request($pdo, $id);
    if (!$r) cert_json(['success' => false, 'message' => 'Request not found.'], 404);
    $res = cert_resident($pdo, (int)$r['ResidentID']);
    $extraDefs = cert_extra_fields($pdo, (string)$r['DocType']);
    $extra = json_decode((string)($r['extra_data'] ?? ''), true) ?: [];
    $extraRows = [];
    foreach ($extraDefs as $ef) $extraRows[] = ['label' => $ef['label'], 'value' => (string)($extra[$ef['field_key']] ?? '')];
    foreach (['business_name' => 'Business Name', 'business_address' => 'Business Address', 'nature_of_business' => 'Nature of Business',
              'years_of_residency' => 'Years of Residency', 'employment_purpose' => 'Employment Purpose'] as $col => $lbl) {
        if (!empty($r[$col])) $extraRows[] = ['label' => $lbl, 'value' => $r[$col]]; // old requests
    }
    $files = $pdo->prepare("SELECT RequirementLabel, FilePath, FileType FROM document_request_files WHERE RequestID = ?");
    $files->execute([$id]);
    $fmt = fn($v) => $v ? date('M j, Y g:i A', strtotime($v)) : null;
    $out = [
        'id' => (int)$r['RequestID'], 'ref' => $r['ReferenceNo'], 'doc_number' => $r['doc_number'], 'doc_type' => $r['DocType'],
        'purpose' => $r['Purpose'], 'type' => $r['request_type'], 'status' => $r['Status'],
        'status_class' => cert_status_meta($r['Status'])['class'],
        'resident' => $res ? ['id' => (int)$res['ResidentID'], 'name' => cert_person_name($res), 'code' => $res['ResidentCode'] ?: '—',
                              'address' => cert_resident_address($pdo, $res), 'contact' => $res['ContactNumber'] ?: '—',
                              'birth_date' => $res['BirthDate'] ? date('M j, Y', strtotime($res['BirthDate'])) : '—',
                              'civil_status' => $res['CivilStatus'] ?: '—', 'sex' => $res['Sex'] ?: '—'] : null,
        'requirements' => json_decode((string)($r['requirements_checked'] ?? ''), true) ?: [],
        'required' => cert_requirements($pdo, (string)$r['DocType']),
        'extra' => $extraRows,
        'files' => array_map(fn($f) => ['label' => $f['RequirementLabel'], 'url' => cert_bg_url($f['FilePath']), 'type' => $f['FileType']], $files->fetchAll(PDO::FETCH_ASSOC)),
        'photo' => $r['photo_path'] ? cert_bg_url($r['photo_path']) : null,
        'dates' => [
            'requested' => $fmt($r['DateRequested']), 'reviewed' => $fmt($r['reviewed_at']), 'approved' => $fmt($r['approved_at']),
            'generated' => $fmt($r['generated_at']), 'released' => $fmt($r['release_date']), 'rejected' => $fmt($r['rejected_at']),
            'expired' => $fmt($r['expired_at']),
            'pickup_until' => $r['approved_at'] ? date('M j, Y', strtotime($r['approved_at'] . ' +' . CERT_PICKUP_DAYS . ' days')) : null,
        ],
        'people' => ['generated_by' => $r['generated_by'], 'reviewed_by' => $r['reviewed_by'], 'approved_by' => $r['approved_by'],
                     'released_by' => $r['released_by'], 'rejected_by' => $r['rejected_by']],
        'rejection_reason' => $r['rejection_reason'],
        'blotter_cases' => (int)$r['blotter_cases'], 'blotter_override_by' => $r['blotter_override_by'],
        'logs' => cert_request_logs($pdo, $id),
        'can_print' => cert_can_print($r),
        'can_review' => in_array($r['Status'], [CERT_ST_PENDING, CERT_ST_REVIEW], true),
    ];
    if (in_array($r['Status'], [CERT_ST_PENDING, CERT_ST_REVIEW], true) && $res) {
        $el = cert_eligibility($pdo, $res);
        $el['unclaimed'] = cert_unclaimed($pdo, (int)$res['ResidentID'], $id);
        $el['history'] = cert_recent_requests($pdo, (int)$res['ResidentID'], $id);
        $out['eligibility'] = $el;
    }
    if (in_array($r['Status'], [CERT_ST_PREVIEW, CERT_ST_READY, CERT_ST_RELEASED, CERT_ST_EXPIRED], true)) {
        $out['render'] = cert_render_model($pdo, $r);
        if ($r['Status'] === CERT_ST_PREVIEW && !$r['previewed_at']) {
            $pdo->prepare("UPDATE document_requests SET previewed_at = NOW() WHERE RequestID = ?")->execute([$id]);
        }
    }
    cert_json(['success' => true, 'request' => $out]);
}

/* ───────── POST ───────── */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') cert_json(['success' => false, 'message' => 'Unknown action.'], 400);
cert_csrf_verify();
$actor = cert_actor_name($pdo);

if ($action === 'save_walkin') {
    cert_require($pdo, 'create');
    $rid = (int)($_POST['resident_id'] ?? 0);
    $res = cert_resident($pdo, $rid);
    if (!$res) cert_json(['success' => false, 'message' => 'Select a resident.'], 422);
    $dt = cert_doc_type($pdo, (string)($_POST['doc_type'] ?? ''));
    if (!$dt || !$dt['is_active'] || $dt['is_draft']) cert_json(['success' => false, 'message' => 'This document is not available for issuing.'], 422);
    $docType = $dt['doc_type'];
    $purpose = trim((string)($_POST['purpose'] ?? ''));
    if ($purpose === '' || mb_strlen($purpose) > 500) cert_json(['success' => false, 'message' => 'Enter the purpose (max 500 characters).'], 422);

    $el = cert_eligibility($pdo, $res);
    if (!$el['active']) cert_json(['success' => false, 'message' => 'Cannot issue: ' . $el['active_note']], 422);
    if (!$el['verified']) cert_json(['success' => false, 'message' => 'Cannot issue: resident is not verified. ' . $el['verified_note']], 422);

    // All requirements must be checked.
    $required = cert_requirements($pdo, $docType);
    $checked = json_decode((string)($_POST['requirements'] ?? '[]'), true);
    $checked = is_array($checked) ? array_values(array_intersect($required, array_map('strval', $checked))) : [];
    if (count($checked) !== count($required)) cert_json(['success' => false, 'message' => 'All requirements must be checked.'], 422);

    // Extra information fields.
    $extra = []; $sent = json_decode((string)($_POST['extra'] ?? '{}'), true) ?: [];
    foreach (cert_extra_fields($pdo, $docType) as $ef) {
        $v = trim((string)($sent[$ef['field_key']] ?? ''));
        if ($ef['is_required'] && $v === '') cert_json(['success' => false, 'message' => $ef['label'] . ' is required.'], 422);
        if ($v !== '' && $ef['input_type'] === 'number' && !is_numeric($v)) cert_json(['success' => false, 'message' => $ef['label'] . ' must be a number.'], 422);
        if ($v !== '' && $ef['input_type'] === 'date' && !strtotime($v)) cert_json(['success' => false, 'message' => $ef['label'] . ' must be a date.'], 422);
        if ($v !== '' && $ef['input_type'] === 'select' && !in_array($v, $ef['options'], true)) cert_json(['success' => false, 'message' => 'Choose a valid ' . $ef['label'] . '.'], 422);
        if ($v !== '' && $ef['input_type'] === 'date') $v = date('F j, Y', strtotime($v));
        $extra[$ef['field_key']] = mb_substr($v, 0, 1000);
    }

    $blotters = count($el['blotter']);
    if ($blotters && ($_POST['blotter_ack'] ?? '') !== '1') {
        cert_json(['success' => false, 'needs_blotter_ack' => true, 'message' => 'This resident has ' . $blotters . ' active blotter case(s). Confirm to proceed.'], 409);
    }

    // Optional applicant photo (kept from the old walk-in form).
    $photo = null;
    if (!empty($_FILES['photo']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['photo']['tmp_name']);
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? null;
        if (!$ext || $_FILES['photo']['size'] > 5 * 1024 * 1024 || !@getimagesize($_FILES['photo']['tmp_name'])) {
            cert_json(['success' => false, 'message' => 'Photo must be a PNG/JPG/WebP image up to 5 MB.'], 422);
        }
        $dir = realpath(__DIR__ . '/../../..') . '/upload/certificates/photos';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $name = 'photo_' . $rid . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $dir . '/' . $name)) $photo = 'upload/certificates/photos/' . $name;
    }

    $pdo->beginTransaction();
    try {
        $ref = next_record_id($pdo, 'REF', ['table' => 'document_requests', 'column' => 'ReferenceNo']);
        $docNo = next_record_id($pdo, 'DOC', ['table' => 'document_requests', 'column' => 'doc_number']);
        $pdo->prepare("INSERT INTO document_requests (ResidentID, ReferenceNo, doc_number, DocType, Purpose, extra_data, requirements_checked, photo_path,
                        Status, request_type, generated_at, generated_by, DateRequested, notif_read, blotter_cases, blotter_override_by, blotter_override_at)
                       VALUES (?,?,?,?,?,?,?,?, 'Preview', 'walk-in', NOW(), ?, NOW(), 1, ?, ?, ?)")
            ->execute([$rid, $ref, $docNo, $docType, $purpose, json_encode($extra, JSON_UNESCAPED_UNICODE), json_encode($checked, JSON_UNESCAPED_UNICODE), $photo,
                       $actor, $blotters, $blotters ? $actor : null, $blotters ? date('Y-m-d H:i:s') : null]);
        $id = (int)$pdo->lastInsertId();
        $row = ca_lock($pdo, $id);
        $pdo->prepare("UPDATE document_requests SET render_snapshot = ? WHERE RequestID = ?")
            ->execute([json_encode(cert_build_snapshot($pdo, $row), JSON_UNESCAPED_UNICODE), $id]);
        cert_status_log($pdo, $id, CERT_ST_PREVIEW, 'Walk-in document generated (' . $docNo . ').' . ($blotters ? ' Proceeded with ' . $blotters . ' active blotter case(s).' : ''));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[Certificates] save_walkin: ' . $e->getMessage());
        cert_json(['success' => false, 'message' => 'Could not generate the document. Please try again.'], 500);
    }
    cert_log_activity('Issue Walk-In', $docType . ' ' . $docNo . ' for ' . cert_person_name($res) . ' (' . ($res['ResidentCode'] ?: '#' . $rid) . ')' . ($blotters ? ' — proceeded with active blotter' : ''));
    cert_json(['success' => true, 'request_id' => $id, 'doc_number' => $docNo, 'reference_no' => $ref, 'message' => $docType . ' generated.']);
}

if ($action === 'open_review') {
    cert_require($pdo, 'read');
    $id = (int)($_POST['id'] ?? 0);
    $pdo->beginTransaction();
    $r = ca_lock($pdo, $id);
    if ($r && $r['Status'] === CERT_ST_PENDING) {
        $pdo->prepare("UPDATE document_requests SET Status = 'Review', notif_read = 1, reviewed_at = NOW(), reviewed_by = ? WHERE RequestID = ?")->execute([$actor, $id]);
        cert_status_log($pdo, $id, CERT_ST_REVIEW, 'Opened for review.');
        $pdo->commit();
        cert_log_activity('Review Online Request', 'Opened ' . ($r['ReferenceNo'] ?: '#' . $id) . ' (' . $r['DocType'] . ')');
        cert_json(['success' => true, 'status' => CERT_ST_REVIEW]);
    }
    $pdo->commit();
    cert_json(['success' => true, 'status' => $r['Status'] ?? null]);
}

if ($action === 'accept') {
    cert_require($pdo, 'update');
    $id = (int)($_POST['id'] ?? 0);
    $pdo->beginTransaction();
    try {
        $r = ca_need(ca_lock($pdo, $id), [CERT_ST_PENDING, CERT_ST_REVIEW], 'accept');
        if ($r['request_type'] !== 'online') throw new DomainException('Only online requests are accepted here.');
        $dt = cert_doc_type($pdo, (string)$r['DocType']);
        if (!$dt || $dt['is_draft']) throw new DomainException('The document type "' . $r['DocType'] . '" is not finished or no longer exists.');
        $res = cert_resident($pdo, (int)$r['ResidentID']);
        if (!$res) throw new DomainException('Resident record not found.');
        $el = cert_eligibility($pdo, $res);
        if (!$el['active']) throw new DomainException($el['active_note']);
        $blotters = count($el['blotter']);
        if ($blotters && ($_POST['blotter_ack'] ?? '') !== '1') {
            $pdo->rollBack();
            cert_json(['success' => false, 'needs_blotter_ack' => true, 'message' => 'This resident has ' . $blotters . ' active blotter case(s). Confirm to accept anyway.'], 409);
        }
        $docNo = $r['doc_number'] ?: next_record_id($pdo, 'DOC', ['table' => 'document_requests', 'column' => 'doc_number']);
        $pdo->prepare("UPDATE document_requests SET Status = 'Ready to Pick Up', doc_number = ?, approved_at = NOW(), approved_by = ?,
                       generated_at = NOW(), generated_by = ?, notif_read = 1, reviewed_at = COALESCE(reviewed_at, NOW()), reviewed_by = COALESCE(reviewed_by, ?),
                       blotter_cases = ?, blotter_override_by = ?, blotter_override_at = ? WHERE RequestID = ?")
            ->execute([$docNo, $actor, $actor, $actor, $blotters, $blotters ? $actor : null, $blotters ? date('Y-m-d H:i:s') : null, $id]);
        $row = ca_lock($pdo, $id);
        $pdo->prepare("UPDATE document_requests SET render_snapshot = ? WHERE RequestID = ?")
            ->execute([json_encode(cert_build_snapshot($pdo, $row), JSON_UNESCAPED_UNICODE), $id]);
        cert_status_log($pdo, $id, CERT_ST_READY, 'Accepted — document ' . $docNo . ' generated. Pick up within ' . CERT_PICKUP_DAYS . ' days.' . ($blotters ? ' Accepted with ' . $blotters . ' active blotter case(s).' : ''));
        $pdo->commit();
    } catch (DomainException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        cert_json(['success' => false, 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[Certificates] accept: ' . $e->getMessage());
        cert_json(['success' => false, 'message' => 'Could not accept the request.'], 500);
    }
    $until = date('F j, Y', strtotime('+' . CERT_PICKUP_DAYS . ' days'));
    cert_notify_resident($pdo, (int)$r['ResidentID'], 'document_ready_pickup', 'Document ready to pick up',
        'Your ' . $r['DocType'] . ' request (' . $r['ReferenceNo'] . ') was approved. Please pick it up at the barangay hall on or before ' . $until . '. Bring a valid ID.', $id);
    cert_log_activity('Accept Online Request', ($r['ReferenceNo'] ?: '#' . $id) . ' → Ready to Pick Up (' . $docNo . ')');
    cert_json(['success' => true, 'message' => 'Accepted. The document is now in the Online Queue (Ready to Pick Up).']);
}

if ($action === 'reject') {
    cert_require($pdo, 'update');
    $id = (int)($_POST['id'] ?? 0);
    $reasons = ['Incomplete requirements', 'Invalid information', 'Active blotter case', 'Duplicate request', 'Others'];
    $reason = (string)($_POST['reason'] ?? '');
    $other = trim((string)($_POST['other'] ?? ''));
    if (!in_array($reason, $reasons, true)) cert_json(['success' => false, 'message' => 'Choose a reason.'], 422);
    if ($reason === 'Others') {
        if ($other === '') cert_json(['success' => false, 'message' => 'Type the reason.'], 422);
        $reason = 'Others: ' . mb_substr($other, 0, 480);
    }
    $pdo->beginTransaction();
    $r = ca_need(ca_lock($pdo, $id), [CERT_ST_PENDING, CERT_ST_REVIEW], 'reject');
    $pdo->prepare("UPDATE document_requests SET Status = 'Rejected', rejection_reason = ?, rejected_at = NOW(), rejected_by = ?, notif_read = 1,
                   reviewed_at = COALESCE(reviewed_at, NOW()), reviewed_by = COALESCE(reviewed_by, ?) WHERE RequestID = ?")
        ->execute([$reason, $actor, $actor, $id]);
    cert_status_log($pdo, $id, CERT_ST_REJECTED, $reason);
    $pdo->commit();
    cert_notify_resident($pdo, (int)$r['ResidentID'], 'document_rejected', 'Document request rejected',
        'Your ' . $r['DocType'] . ' request (' . $r['ReferenceNo'] . ') was rejected. Reason: ' . $reason, $id);
    cert_log_activity('Reject Online Request', ($r['ReferenceNo'] ?: '#' . $id) . ' — ' . $reason);
    cert_json(['success' => true, 'message' => 'Request rejected. The resident was notified.']);
}

if ($action === 'save_override') {
    cert_require($pdo, 'update');
    $id = (int)($_POST['id'] ?? 0);
    $positions = json_decode((string)($_POST['positions'] ?? ''), true);
    if (!is_array($positions)) cert_json(['success' => false, 'message' => 'Invalid layout.'], 422);
    $pdo->beginTransaction();
    $r = ca_need(ca_lock($pdo, $id), [CERT_ST_PREVIEW, CERT_ST_READY], 'edit');
    $snap = json_decode((string)$r['render_snapshot'], true) ?: [];
    $labels = $snap['labels'] ?? [];
    $clean = [];
    foreach ($positions as $p) {
        if (!is_array($p) || !isset($labels[$p['field_key'] ?? ''])) continue;
        $clean[] = cert_clean_position($p);
    }
    $pdo->prepare("UPDATE document_requests SET layout_override = ? WHERE RequestID = ?")->execute([json_encode($clean), $id]);
    cert_status_log($pdo, $id, $r['Status'], 'Layout adjusted for this document only.');
    $pdo->commit();
    cert_log_activity('Edit Document Layout', ($r['doc_number'] ?: '#' . $id) . ' layout adjusted (template unchanged)');
    cert_json(['success' => true, 'message' => 'Layout saved for this document only.', 'render' => cert_render_model($pdo, cert_request($pdo, $id))]);
}

if ($action === 'release') {
    cert_require($pdo, 'update');
    $id = (int)($_POST['id'] ?? 0);
    $pdo->beginTransaction();
    $r = ca_need(ca_lock($pdo, $id), [CERT_ST_PREVIEW, CERT_ST_READY], 'print & release');
    if (!$r['render_snapshot']) {
        $pdo->prepare("UPDATE document_requests SET render_snapshot = ? WHERE RequestID = ?")
            ->execute([json_encode(cert_build_snapshot($pdo, $r), JSON_UNESCAPED_UNICODE), $id]);
    }
    $pdo->prepare("UPDATE document_requests SET Status = 'Released', printed_at = NOW(), printed_by = ?, release_date = NOW(), release_time = CURTIME(), released_by = ? WHERE RequestID = ?")
        ->execute([$actor, $actor, $id]);
    cert_status_log($pdo, $id, CERT_ST_RELEASED, 'Printed and released' . ($r['request_type'] === 'online' ? ' to the resident at the barangay hall.' : '.'));
    $pdo->commit();
    // One-time token: the print tab opened right now may print once; after that the document is view-only.
    $token = bin2hex(random_bytes(16));
    $_SESSION['cert_print_tokens'][$id] = ['t' => $token, 'exp' => time() + 300];
    if ($r['request_type'] === 'online') {
        cert_notify_resident($pdo, (int)$r['ResidentID'], 'document_released', 'Document released', 'Your ' . $r['DocType'] . ' (' . $r['doc_number'] . ') was released.', $id);
    }
    cert_log_activity('Print & Release', ($r['doc_number'] ?: '#' . $id) . ' ' . $r['DocType'] . ' released');
    cert_json(['success' => true, 'message' => 'Released. The document can no longer be edited or reprinted.', 'print_url' => '../backend/print_certificate.php?id=' . $id . '&token=' . $token]);
}

cert_json(['success' => false, 'message' => 'Unknown action.'], 400);

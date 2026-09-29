<?php
/**
 * user/backend/certificate.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Certificates / Legal Documents — JSON API for the Flutter app (lib/certificate/).
 * Ported from the SOE resident pages user/legal_docu.php + legal_docu_handler.php
 * and built on the admin module's own rules (admin/certificates/backend/cert_common.php):
 *   • document types, requirements and extra fields come from the admin setup
 *     (custom_document_types / document_requirements / document_extra_fields)
 *   • online request → Status 'Pending', Reference No. REF-YYYY-#### (id_sequences),
 *     status log, then staff move it to Review → Ready to Pick Up / Rejected;
 *     not picked up within 15 days → Expired (handled by cert_common)
 *   • limits from SOE: max 5 pending requests, one pending request per type
 *   • the resident may cancel a request while it is still Pending
 *
 * Actions (GET ?action= or POST action=), all need resident_id:
 *   • types                          → document types (+ requirements, extra fields)
 *   • list                           → my requests + counts
 *   • detail  (id)                   → one request + status history + files
 *   • notices                        → eligibility notes (active blotter cases, unclaimed documents)
 *   • submit  (doc_type, purpose, extra[JSON], requirements[JSON], files[] + labels[])
 *   • cancel  (id)                   → cancel a Pending request
 *
 * Add &debug=1 to see the exact database error when something fails.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php'; // respond(), handle_preflight(), db(), L()
handle_preflight();

const CERT_COMMON = __DIR__ . '/../../admin/certificates/backend/cert_common.php';
const CERT_FILE_DIR = __DIR__ . '/uploads/document_requests';
const CERT_FILE_REL = 'user/backend/uploads/document_requests'; // relative to CAPS/, like the admin expects
const CERT_FILE_MAX = 8 * 1024 * 1024;

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$rid    = require_resident(); // verified login token (auth.php), not the resident_id sent by the app

function cert_fail(string $fil, string $en, ?Throwable $e = null, int $code = 500): void
{
    if ($e) error_log('[certificate.php] ' . $e->getMessage());
    respond(false, L($fil, $en), ($e && isset($_GET['debug'])) ? ['error' => $e->getMessage()] : null, $code);
}

if (!is_file(CERT_COMMON)) {
    cert_fail('Wala pa ang Certificates module sa server.', 'The Certificates module is not installed on the server.', null, 500);
}
if ($rid <= 0) {
    respond(false, L('Kailangan ang resident_id.', 'resident_id is required.'), null, 400);
}

try {
    $pdo = db();
    require_once CERT_COMMON;
    cert_migrate($pdo); // same self-healing schema + 15-day expiry as the admin page
} catch (Throwable $e) {
    cert_fail('Hindi ma-load ang mga dokumento.', 'Could not load the documents.', $e);
}

/** One request row → app JSON. */
function cert_app_row(array $r): array
{
    $pickupUntil = null;
    if ($r['Status'] === CERT_ST_READY) {
        $from = $r['approved_at'] ?: ($r['generated_at'] ?: $r['DateRequested']);
        if ($from) $pickupUntil = date('Y-m-d', strtotime($from . ' +' . CERT_PICKUP_DAYS . ' days'));
    }
    return [
        'id'               => (int) $r['RequestID'],
        'reference_no'     => $r['ReferenceNo'],
        'doc_number'       => $r['doc_number'],
        'doc_type'         => $r['DocType'],
        'purpose'          => $r['Purpose'],
        'status'           => $r['Status'],
        'request_type'     => $r['request_type'],
        'requested_at'     => $r['DateRequested'],
        'approved_at'      => $r['approved_at'],
        'released_at'      => $r['release_date'],
        'rejected_at'      => $r['rejected_at'],
        'expired_at'       => $r['expired_at'],
        'rejection_reason' => $r['rejection_reason'],
        'pickup_until'     => $pickupUntil,
        'is_unread'        => (int) ($r['notif_read'] ?? 1) === 0 && $r['Status'] !== CERT_ST_PENDING,
    ];
}

// ── TYPES ───────────────────────────────────────────────────────────────────
if ($action === 'types') {
    try {
        $out = [];
        foreach (cert_doc_types($pdo, true) as $t) {
            $out[] = [
                'doc_type'     => $t['doc_type'],
                'code'         => $t['doc_code'],
                'description'  => $t['description'],
                'icon'         => $t['icon'],
                'color'        => $t['color'],
                'requirements' => cert_requirements($pdo, $t['doc_type']),
                'extra_fields' => array_map(fn($f) => [
                    'key'      => $f['field_key'],
                    'label'    => $f['label'],
                    'type'     => $f['input_type'],
                    'options'  => array_values(array_map('strval', $f['options'])),
                    'required' => (int) $f['is_required'] === 1,
                ], cert_extra_fields($pdo, $t['doc_type'])),
            ];
        }
        respond(true, '', ['types' => $out]);
    } catch (Throwable $e) {
        cert_fail('Hindi ma-load ang mga uri ng dokumento.', 'Could not load the document types.', $e);
    }
}

// ── LIST ────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    try {
        $s = $pdo->prepare("SELECT * FROM document_requests WHERE ResidentID = ?
                            ORDER BY COALESCE(DateRequested, DateCreated) DESC, RequestID DESC LIMIT 200");
        $s->execute([$rid]);
        $rows = array_map('cert_app_row', $s->fetchAll(PDO::FETCH_ASSOC));
        $count = ['total' => count($rows), 'pending' => 0, 'ready' => 0, 'released' => 0, 'rejected' => 0];
        foreach ($rows as $r) {
            if (in_array($r['status'], [CERT_ST_PENDING, CERT_ST_REVIEW], true)) $count['pending']++;
            elseif ($r['status'] === CERT_ST_READY) $count['ready']++;
            elseif ($r['status'] === CERT_ST_RELEASED) $count['released']++;
            elseif (in_array($r['status'], [CERT_ST_REJECTED, CERT_ST_EXPIRED], true)) $count['rejected']++;
        }
        respond(true, '', ['requests' => $rows, 'counts' => $count]);
    } catch (Throwable $e) {
        cert_fail('Hindi ma-load ang iyong mga request.', 'Could not load your requests.', $e);
    }
}

// ── DETAIL ──────────────────────────────────────────────────────────────────
if ($action === 'detail') {
    $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
    try {
        $s = $pdo->prepare("SELECT * FROM document_requests WHERE RequestID = ? AND ResidentID = ?");
        $s->execute([$id, $rid]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) respond(false, L('Hindi nahanap ang request.', 'Request not found.'), null, 404);

        // Seen by the resident (clears the "updated" dot).
        if ((int) ($r['notif_read'] ?? 1) === 0) {
            $pdo->prepare("UPDATE document_requests SET notif_read = 1 WHERE RequestID = ?")->execute([$id]);
        }

        $extra = [];
        $values = json_decode((string) ($r['extra_data'] ?? ''), true) ?: [];
        foreach (cert_extra_fields($pdo, (string) $r['DocType']) as $f) {
            $v = trim((string) ($values[$f['field_key']] ?? ''));
            if ($v !== '') $extra[] = ['label' => $f['label'], 'value' => $v];
        }
        foreach (['business_name' => 'Business Name', 'business_address' => 'Business Address',
                  'nature_of_business' => 'Nature of Business', 'years_of_residency' => 'Years of Residency',
                  'employment_purpose' => 'Employment Purpose'] as $col => $lbl) {
            if (!empty($r[$col])) $extra[] = ['label' => $lbl, 'value' => (string) $r[$col]]; // SOE requests
        }

        $logs = [];
        $l = $pdo->prepare("SELECT status, note, created_at FROM document_request_logs
                            WHERE request_id = ? ORDER BY created_at, id");
        $l->execute([$id]);
        foreach ($l->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $logs[] = ['status' => $x['status'], 'note' => $x['note'], 'at' => $x['created_at']];
        }

        $files = [];
        $f = $pdo->prepare("SELECT RequirementLabel, FilePath, FileType FROM document_request_files WHERE RequestID = ?");
        $f->execute([$id]);
        foreach ($f->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $files[] = ['label' => $x['RequirementLabel'], 'type' => $x['FileType'],
                        'is_image' => stripos((string) $x['FileType'], 'image') === 0];
        }

        respond(true, '', array_merge(cert_app_row($r), [
            'requirements_required' => cert_requirements($pdo, (string) $r['DocType']),
            'requirements_checked'  => json_decode((string) ($r['requirements_checked'] ?? ''), true) ?: [],
            'extra'                 => $extra,
            'files'                 => $files,
            'history'               => $logs,
            'can_cancel'            => $r['Status'] === CERT_ST_PENDING,
        ]));
    } catch (Throwable $e) {
        cert_fail('Hindi ma-load ang request.', 'Could not load the request.', $e);
    }
}

// ── NOTICES (what the staff will see on review) ─────────────────────────────
if ($action === 'notices') {
    try {
        $res = cert_resident($pdo, $rid);
        if (!$res) respond(false, L('Hindi nahanap ang iyong record.', 'Your record was not found.'), null, 404);
        $e = cert_eligibility($pdo, $res);
        respond(true, '', [
            'active_blotter_cases' => count($e['blotter']),
            'unclaimed'            => array_map(fn($u) => [
                'reference_no' => $u['ReferenceNo'], 'doc_type' => $u['DocType'], 'status' => $u['Status'],
            ], $e['unclaimed']),
            'profile_complete'     => (bool) $e['verified'],
        ]);
    } catch (Throwable $e) {
        cert_fail('Hindi ma-load.', 'Could not load.', $e);
    }
}

// ── SUBMIT ──────────────────────────────────────────────────────────────────
if ($action === 'submit') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond(false, L('POST lang ang tinatanggap.', 'Only POST is accepted.'), null, 405);
    }
    $docType = trim((string) ($_POST['doc_type'] ?? ''));
    $purpose = trim(strip_tags((string) ($_POST['purpose'] ?? '')));
    if ($docType === '') respond(false, L('Pumili ng dokumento.', 'Choose a document.'), null, 422);
    if ($purpose === '') respond(false, L('Ilagay ang layunin (purpose).', 'Please enter the purpose.'), null, 422);
    if (mb_strlen($purpose) > 500) $purpose = mb_substr($purpose, 0, 500);

    try {
        $type = cert_doc_type($pdo, $docType);
        if (!cert_type_issuable($type ?: null)) {   // saved, finished, active, not archived
            respond(false, L('Hindi available ang dokumentong ito.', 'This document is not available.'), null, 422);
        }
        $res = cert_resident($pdo, $rid);
        if (!$res) respond(false, L('Hindi nahanap ang iyong record.', 'Your record was not found.'), null, 404);
        if (!empty($res['IsDeceased']) || ($res['access_status'] ?? '') === 'Disabled') {
            respond(false, L('Hindi maaaring mag-request ang account na ito.', 'This account cannot make requests.'), null, 403);
        }

        // SOE limits: max 5 pending, one pending per document type.
        $c = $pdo->prepare("SELECT COUNT(*) FROM document_requests WHERE ResidentID = ? AND Status IN ('Pending','Review')");
        $c->execute([$rid]);
        if ((int) $c->fetchColumn() >= 5) {
            respond(false, L('Marami ka pang nakabinbing request. Hintayin munang maproseso ang mga ito.',
                             'You have too many pending requests. Please wait for them to be processed.'), null, 422);
        }
        $d = $pdo->prepare("SELECT COUNT(*) FROM document_requests WHERE ResidentID = ? AND DocType = ? AND Status IN ('Pending','Review')");
        $d->execute([$rid, $docType]);
        if ((int) $d->fetchColumn() > 0) {
            respond(false, L('May nakabinbin ka nang request para sa dokumentong ito.',
                             'You already have a pending request for this document.'), null, 422);
        }

        // Extra information fields (only the ones the admin set up).
        $sent = json_decode((string) ($_POST['extra'] ?? '{}'), true);
        $sent = is_array($sent) ? $sent : [];
        $extra = [];
        foreach (cert_extra_fields($pdo, $docType) as $f) {
            $v = trim(strip_tags((string) ($sent[$f['field_key']] ?? '')));
            if ($v === '' && (int) $f['is_required'] === 1) {
                respond(false, L('Kulang: ' . $f['label'], 'Missing: ' . $f['label']), null, 422);
            }
            if ($v !== '' && $f['input_type'] === 'select' && $f['options'] && !in_array($v, array_map('strval', $f['options']), true)) {
                respond(false, L('Hindi wastong pagpili: ' . $f['label'], 'Invalid choice: ' . $f['label']), null, 422);
            }
            if ($v !== '') $extra[$f['field_key']] = mb_substr($v, 0, 1000);
        }

        // Requirements the resident checked (only known ones).
        $known = cert_requirements($pdo, $docType);
        $checked = json_decode((string) ($_POST['requirements'] ?? '[]'), true);
        $checked = array_values(array_intersect($known, is_array($checked) ? array_map('strval', $checked) : []));

        $id = cert_create_online_request($pdo, $rid, $docType, $purpose, $extra, $checked);

        // Optional photos of the requirements (files[] + labels[]).
        if (!empty($_FILES['files']['name']) && is_array($_FILES['files']['name'])) {
            if (!is_dir(CERT_FILE_DIR)) @mkdir(CERT_FILE_DIR, 0775, true);
            $labels = $_POST['labels'] ?? [];
            $ins = $pdo->prepare("INSERT INTO document_request_files (RequestID, RequirementLabel, FilePath, FileType, UploadedAt)
                                  VALUES (?, ?, ?, ?, NOW())");
            $mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
            foreach ($_FILES['files']['name'] as $i => $orig) {
                if (($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                if (($_FILES['files']['size'][$i] ?? 0) > CERT_FILE_MAX) continue;
                $ext = strtolower(pathinfo((string) $orig, PATHINFO_EXTENSION));
                if (!isset($mimes[$ext])) continue;
                $name = 'docreq_' . $id . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                if (!move_uploaded_file($_FILES['files']['tmp_name'][$i], CERT_FILE_DIR . '/' . $name)) continue;
                $label = trim((string) ($labels[$i] ?? '')) ?: 'Requirement';
                $ins->execute([$id, mb_substr($label, 0, 255), CERT_FILE_REL . '/' . $name, $mimes[$ext]]);
            }
        }

        try {
            log_activity('Certificates', 'Online Request',
                "Resident #{$rid} requested {$docType}", $rid, cert_person_name($res), 'Resident');
        } catch (Throwable $e) { /* logging never blocks */ }

        $r = $pdo->prepare("SELECT * FROM document_requests WHERE RequestID = ?");
        $r->execute([$id]);
        $row = cert_app_row($r->fetch(PDO::FETCH_ASSOC));
        respond(true, L('Naipadala ang iyong request. Reference No.: ' . $row['reference_no'],
                        'Your request was sent. Reference No.: ' . $row['reference_no']), $row);
    } catch (Throwable $e) {
        cert_fail('Hindi naipadala ang request. Subukan muli.', 'The request was not sent. Please try again.', $e);
    }
}

// ── CANCEL ──────────────────────────────────────────────────────────────────
if ($action === 'cancel') {
    $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
    try {
        $s = $pdo->prepare("UPDATE document_requests
                            SET Status = 'Rejected', rejected_at = NOW(), rejected_by = 'Resident',
                                rejection_reason = 'Cancelled by the resident', notif_read = 1
                            WHERE RequestID = ? AND ResidentID = ? AND Status = 'Pending'");
        $s->execute([$id, $rid]);
        if ($s->rowCount() === 0) {
            respond(false, L('Hindi na maaaring kanselahin ang request na ito.', 'This request can no longer be cancelled.'), null, 409);
        }
        cert_status_log($pdo, $id, CERT_ST_REJECTED, 'Cancelled by the resident.', 'Resident', null);
        respond(true, L('Nakansela ang request.', 'The request was cancelled.'));
    } catch (Throwable $e) {
        cert_fail('Hindi nakansela ang request.', 'The request was not cancelled.', $e);
    }
}

respond(false, L('Hindi wastong action.', 'Invalid action.'), null, 400);

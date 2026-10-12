<?php
/**
 * Blotter Complaint Record — opened from Case Details with ?id=N&mode=print|pdf&evidence=0|1.
 * evidence=1: the Evidence section shows the files themselves (each with its description, uploader and date):
 * photos as images, PDFs page by page (pdf.js), text files as text; video / audio / Word files by name + description.
 * &hearings=all | 1,2,… (hearing numbers): the chosen hearings are added after the complaint — schedule, letters, attendance,
 * result, remarks, who presided, settlement (every hearing held so far, whatever the case rule).
 * &resolution=1: the settlement (kasunduan) of a resolved / closed case · &transfer=1: the transfer details of a transferred case.
 * Every Print / Save PDF is recorded on the case timeline with the logged-in user. Every printed page has a margin on all sides.
 * Print → Print Preview; Save PDF → PDF download. Same report layout as the other CAPS prints
 * (officials report_common.php): letterhead, title, sections, certification, footer strip.
 * Content: case info, complainant(s), respondent(s), incident, the full incident description and the
 * evidence list, then signature lines for every complainant and for the person filing / recording the
 * complaint (the user logged in to the system).
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../db.php';
$required_module = 'blotter';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/blotter_common.php';
blt_require_report_common();
blt_migrate($pdo);
if (!blt_can($pdo, 'read')) { http_response_code(403); exit('Access denied.'); }

$mode = report_mode();
$case = blt_case($pdo, (int)($_GET['id'] ?? 0));
if (!$case) { http_response_code(404); exit('Case not found.'); }
$id = (int)$case['BlotterID'];
$parties = blt_parties($pdo, $id);
$comp = array_values(array_filter($parties, fn($p) => $p['role'] === 'Complainant'));
$resp = array_values(array_filter($parties, fn($p) => $p['role'] === 'Respondent'));
$withEvidence = ($_GET['evidence'] ?? '0') === '1';
$att = $pdo->prepare("SELECT attachment_id, original_name, file_type, file_ext, file_size, file_path, description, uploaded_at, uploaded_by FROM blotter_attachments WHERE blotter_id = ? AND is_deleted = 0 ORDER BY attachment_id");
$att->execute([$id]);
$files = $att->fetchAll(PDO::FETCH_ASSOC);
// Hearings to include (none = the complaint record only).
$allH = blt_hearings($pdo, $id);
$hParam = trim((string)($_GET['hearings'] ?? ''));
$pick = $hParam === 'all' ? array_column($allH, 'hearing_no') : array_map('intval', array_filter(explode(',', $hParam), 'is_numeric'));
$hearings = array_values(array_filter($allH, fn($h) => in_array((int)$h['hearing_no'], array_map('intval', $pick), true)));
$withRes = ($_GET['resolution'] ?? '0') === '1';
$withTransfer = ($_GET['transfer'] ?? '0') === '1';
$resolution = null; $transfers = [];
if ($withRes) {
    $q = $pdo->prepare("SELECT r.*, h.hearing_no, hr.presided_by, hr.presided_position FROM blotter_resolutions r LEFT JOIN blotter_hearings h ON h.hearing_id = r.hearing_id
                        LEFT JOIN blotter_hearing_results hr ON hr.hearing_id = r.hearing_id WHERE r.blotter_id = ? ORDER BY r.resolution_id DESC LIMIT 1");
    $q->execute([$id]);
    $resolution = $q->fetch(PDO::FETCH_ASSOC) ?: null;
}
if ($withTransfer) {
    $q = $pdo->prepare("SELECT * FROM blotter_transfers WHERE blotter_id = ? ORDER BY transfer_id");
    $q->execute([$id]);
    $transfers = $q->fetchAll(PDO::FETCH_ASSOC);
}
$hNotices = []; $hRes = [];
if ($hearings) {
    $q = $pdo->prepare("SELECT hearing_id, notice_no, notice_type, recipient_name, status, issued_at, issue_remarks FROM blotter_notices WHERE blotter_id = ? AND status <> 'Draft' ORDER BY notice_id");
    $q->execute([$id]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $n) $hNotices[(int)$n['hearing_id']][] = $n;
    $q = $pdo->prepare("SELECT * FROM blotter_resolutions WHERE blotter_id = ?");
    $q->execute([$id]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $hRes[(int)$r['hearing_id']] = $r;
}

$brgy = report_barangay_profile($pdo);
$bAddr = blt_barangay($pdo)['address'];
$recordedBy = report_generated_by($pdo);
$recordedRole = ($_SESSION['role'] ?? '') === 'admin' ? 'Administrator' : 'Barangay Staff';
if ($mode !== '') {   // who printed / saved it (the logged-in user) goes on the case timeline
    blt_timeline($pdo, $id, $mode === 'pdf' ? 'Complaint Saved as PDF' : 'Complaint Printed', $case['Status'],
        'Complaint record' . ($withEvidence ? ' with evidence (' . count($files) . ' file(s))' : '') . ($hearings ? ' with hearing' . (count($hearings) > 1 ? 's' : '') . ' #' . implode(', #', array_column($hearings, 'hearing_no')) : '') . ($resolution ? ', with the settlement' : '') . ($transfers ? ', with the transfer details' : '') . '.', null, null, $recordedBy);
}
blt_log_activity('Print Complaint', ($mode === 'pdf' ? 'Saved PDF of ' : 'Printed ') . $case['CaseNumber'] . ($withEvidence ? ' with evidence' : ''));

$d = fn($v, $f) => $v ? date($f, strtotime((string)$v)) : '—';
$filedAt = $case['FiledAt'] ?: $case['CreatedAt'];
$type = blt_case_type_label($case);
$age = function (?string $bd): string {
    if (!$bd) return '—';
    return date('M j, Y', strtotime($bd)) . ' (' . (new DateTime($bd))->diff(new DateTime())->y . ' yrs)';
};
function cr_party_table(array $list): void {
    global $age;
    foreach ($list as $i => $p) {
        $rows = [
            ['Name', blt_party_name($p) . (!empty($p['ResidentCode']) ? ' (' . $p['ResidentCode'] . ')' : '')],
            ['Type', ($p['party_type'] === 'Resident' ? 'Resident' : 'Non-Resident') . ($p['role'] === 'Respondent' && empty($p['is_identified']) ? ' · not yet identified' : '')],
            ['Sex', $p['sex'] ?: '—'],
            ['Date of birth', $age($p['birth_date'])],
            ['Contact number', $p['contact_number'] ?: '—'],
        ];
        if (!empty($p['address_changed'])) {
            $rows[] = ['Address (when filed)', blt_party_filed_address($p) ?: '—'];
            $rows[] = ['New address (relocated)', blt_party_address($p)];
        } else {
            $rows[] = ['Address', blt_party_address($p) ?: '—'];
        }
        foreach ([['Alias', 'alias'], ['Physical appearance', 'physical_description'], ['Clothing', 'clothing'], ['Vehicle', 'vehicle'], ['Plate number', 'plate_number'], ['Other details', 'other_details']] as $x) {
            if (trim((string)($p[$x[1]] ?? '')) !== '') $rows[] = [$x[0], $p[$x[1]]];
        }
        if (count($list) > 1) echo '<p class="party-no">' . rh($p['role'] . ' ' . ($i + 1)) . '</p>';
        echo '<table class="kv"><tbody>';
        foreach ($rows as $r) echo '<tr><th>' . rh($r[0]) . '</th><td>' . nl2br(rh($r[1])) . '</td></tr>';
        echo '</tbody></table>';
    }
}

report_head('Blotter Complaint ' . $case['CaseNumber'], $mode);
$fileBase = 'blotter_' . preg_replace('/[^A-Za-z0-9\-]/', '', (string)$case['CaseNumber']) . ($withEvidence ? '_with_evidence' : '') . ($hearings ? '_hearings_' . implode('-', array_column($hearings, 'hearing_no')) : '');
report_toolbar('', $fileBase);   // functions only — this page starts Print / PDF itself once the evidence is drawn
?>
<style>
    .brgy-addr{text-align:center;font-size:11px;color:#64748b;margin:-12px 0 14px}
    table.kv th{width:30%;background:transparent;font-size:10.5px;color:#64748b;vertical-align:top}
    table.kv td{font-weight:600}
    .party-no{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#0d9488;margin:10px 0 2px}
    .narrative{font-size:12.5px;line-height:1.7;white-space:pre-wrap;margin:0}
    .sig-grid{margin-top:34px;display:grid;grid-template-columns:1fr 1fr;gap:34px 28px}
    .sig-grid .sig{text-align:center;page-break-inside:avoid;break-inside:avoid}
    .sig-grid .sig-role-top{font-size:9px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;margin:0 0 44px}
    .sig-grid .sig-line{border-top:1px solid #1e293b;padding-top:6px;font-weight:800;font-size:12px}
    .sig-grid .sig-role{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.04em;margin-top:2px}
    .sig-grid .sig-date{font-size:10px;color:#64748b;margin-top:10px}
    .section-wrap,tr,.sig,.certify-line{page-break-inside:avoid;break-inside:avoid}
    /* Evidence appendix */
    .ev-block{margin-top:18px;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;page-break-inside:avoid;break-inside:avoid}
    .ev-block.ev-long{page-break-inside:auto;break-inside:auto}
    .ev-head{font-size:12px;font-weight:800;margin:0}
    .ev-meta{font-size:10.5px;color:#64748b;margin:2px 0 8px}
    .ev-note{font-size:11.5px;margin:0 0 8px;white-space:pre-wrap}
    .ev-body img{display:block;max-width:100%;max-height:240mm;margin:0 auto;object-fit:contain}
    .ev-body .ev-page{margin:0 auto 10px;page-break-inside:avoid;break-inside:avoid}
    .ev-body pre{white-space:pre-wrap;font:11px/1.5 "Courier New",monospace;margin:0}
    .ev-skip{font-size:11px;color:#b45309;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:8px 10px}
    /* A margin on every printed page (all sides), so nothing is cut at the paper edge */
    @page{size:A4;margin:14mm 12mm}
    @media print{ .report-page{padding:0 !important} }
</style>
<div class="report-page" id="reportRoot">
    <?php report_letterhead($brgy, 'Office of the Punong Barangay · Blotter / Case Management'); ?>
    <?php if ($bAddr): ?><p class="brgy-addr"><?php echo rh($bAddr); ?></p><?php endif; ?>
    <?php report_title_block($transfers ? 'Blotter Case & Transfer Record' : ($resolution ? 'Blotter Case & Settlement Record' : ($hearings ? 'Blotter Complaint & Hearing Record' : 'Blotter Complaint Record')), 'Blotter ID ' . $case['CaseNumber'] . ' · Filed ' . $d($filedAt, 'F j, Y g:i A'), 'Katarungang Pambarangay'); ?>

    <?php report_section_open('Case Information'); ?>
    <table class="kv"><tbody>
        <tr><th>Blotter ID</th><td><?php echo rh($case['CaseNumber']); ?></td></tr>
        <tr><th>Status</th><td><?php echo rh($case['Status']); ?></td></tr>
        <tr><th>Date &amp; time filed</th><td><?php echo rh($d($filedAt, 'F j, Y g:i A')); ?></td></tr>
        <tr><th>Filed / recorded by</th><td><?php echo rh($case['FiledBy'] ?: '—'); ?></td></tr>
        <tr><th>Incident type</th><td><?php echo rh($type . (blt_is_other_type($case['IncidentType']) ? ' (Other)' : '')); ?></td></tr>
        <tr><th>Incident date &amp; time</th><td><?php echo rh($d($case['IncidentDate'], 'F j, Y') . ($case['IncidentTime'] ? ' · ' . date('g:i A', strtotime('2000-01-01 ' . $case['IncidentTime'])) : '')); ?></td></tr>
        <tr><th>Incident location</th><td><?php echo rh($case['Location'] ?: '—'); ?></td></tr>
    </tbody></table>
    <?php report_section_close(); ?>

    <?php report_section_open(count($comp) > 1 ? 'Complainants' : 'Complainant'); cr_party_table($comp); report_section_close(); ?>
    <?php report_section_open(count($resp) > 1 ? 'Respondents' : 'Respondent'); cr_party_table($resp); report_section_close(); ?>

    <?php report_section_open('Incident Description'); ?>
    <p class="narrative"><?php echo rh($case['Narrative'] ?: '—'); ?></p>
    <?php report_section_close(); ?>

    <?php report_section_open('Evidence (' . count($files) . ')'); ?>
    <?php if ($withEvidence && $files): ?>
    <div>
        <?php foreach ($files as $i => $f):
            $url = 'attachments.php?action=view&id=' . (int)$f['attachment_id'];
            $type = (string)$f['file_type'];
            $kind = str_starts_with($type, 'image/') ? 'image' : ($type === 'application/pdf' ? 'pdf' : ($type === 'text/plain' ? 'text' : 'other'));
            $size = $f['file_size'] >= 1048576 ? round($f['file_size'] / 1048576, 1) . ' MB' : max(1, round($f['file_size'] / 1024)) . ' KB'; ?>
        <div class="ev-block<?php echo $kind === 'pdf' || $kind === 'text' ? ' ev-long' : ''; ?>">
            <p class="ev-head">Evidence <?php echo $i + 1; ?> · <?php echo rh($f['original_name']); ?></p>
            <p class="ev-meta"><?php echo rh(strtoupper((string)$f['file_ext']) . ' · ' . $size . ' · uploaded ' . $d($f['uploaded_at'], 'F j, Y g:i A') . ($f['uploaded_by'] ? ' by ' . $f['uploaded_by'] : '')); ?></p>
            <?php if (trim((string)$f['description']) !== ''): ?><p class="ev-note"><strong>Details / notes:</strong> <?php echo rh($f['description']); ?></p><?php endif; ?>
            <div class="ev-body">
                <?php if ($kind === 'image'): ?><img src="<?php echo rh($url); ?>" alt="<?php echo rh($f['original_name']); ?>">
                <?php elseif ($kind === 'pdf'): ?><div data-pdf="<?php echo rh($url); ?>"><p class="ev-meta">Loading PDF pages…</p></div>
                <?php elseif ($kind === 'text'): ?><pre data-text="<?php echo rh($url); ?>">Loading…</pre>
                <?php else: ?><p class="ev-meta"><?php echo rh(str_starts_with($type, 'video/') ? 'Video file' : (str_starts_with($type, 'audio/') ? 'Audio recording' : 'Document')); ?> — shown by its file name and description (view it in Case Details → Evidence).</p>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <?php if ($files): ?>
    <table><thead><tr><th>#</th><th>File</th><th>Description</th><th>Uploaded</th></tr></thead><tbody>
        <?php foreach ($files as $i => $f): ?>
        <tr><td><?php echo $i + 1; ?></td><td><?php echo rh($f['original_name']); ?></td><td><?php echo rh($f['description'] ?: '—'); ?></td><td><?php echo rh($d($f['uploaded_at'], 'M j, Y')); ?></td></tr>
        <?php endforeach; ?>
    </tbody></table>
    <?php else: ?><p class="empty-note">No evidence attached.</p><?php endif; ?>
    <?php endif; ?>
    <?php report_section_close(); ?>

    <?php foreach ($hearings as $h):
        $hid = (int)$h['hearing_id'];
        $ha = json_decode((string)($h['attendance'] ?? ''), true) ?: [];
        $hc = json_decode((string)($h['companions'] ?? ''), true) ?: [];
        $hp = json_decode((string)($h['personnel'] ?? ''), true) ?: [];
        report_section_open('Hearing #' . $h['hearing_no'] . ($h['outcome'] ? ' — ' . $h['outcome'] : ' — ' . $h['status'])); ?>
    <table class="kv"><tbody>
        <tr><th>Schedule</th><td><?php echo rh($d($h['hearing_date'], 'F j, Y') . ' · ' . date('g:i A', strtotime('2000-01-01 ' . $h['hearing_time'])) . ' · ' . $h['location']); ?></td></tr>
        <?php if ($h['agenda']): ?><tr><th>Purpose / agenda</th><td><?php echo rh($h['agenda']); ?></td></tr><?php endif; ?>
        <tr><th>Status</th><td><?php echo rh($h['status'] . ($h['cancel_reason'] ? ' — ' . $h['cancel_reason'] : '')); ?></td></tr>
        <?php if ($h['outcome']): ?>
        <tr><th>Result</th><td><?php echo rh($h['outcome'] . (!empty($h['auto_recorded']) ? ' (recorded automatically)' : '')); ?></td></tr>
        <tr><th>Presided by</th><td><?php echo rh($h['presided_by'] ? $h['presided_by'] . ($h['presided_position'] ? ' — ' . $h['presided_position'] : '') : '—'); ?></td></tr>
        <tr><th>Recorded</th><td><?php echo rh($d($h['result_at'], 'F j, Y g:i A') . ' · ' . $h['result_by']); ?></td></tr>
        <?php endif; ?>
    </tbody></table>
    <?php if (!empty($hNotices[$hid])): ?>
    <p class="party-no">Letters / notices</p>
    <table><thead><tr><th>Notice No.</th><th>Type</th><th>Recipient</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($hNotices[$hid] as $n): ?><tr><td><?php echo rh($n['notice_no']); ?></td><td><?php echo rh($n['notice_type']); ?></td><td><?php echo rh($n['recipient_name']); ?></td>
            <td><?php echo rh(($n['status'] === 'Unserved' ? 'Unserved (no signed copy returned)' : $n['status']) . ($n['issued_at'] ? ' · ' . $d($n['issued_at'], 'M j, Y') : '')); ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
    <?php if ($ha): ?>
    <p class="party-no">Attendance</p>
    <table><thead><tr><th>Name</th><th>Role</th><th>Attendance</th><th>Reason (if absent)</th></tr></thead><tbody>
        <?php foreach ($ha as $a): ?><tr><td><?php echo rh($a['name']); ?></td><td><?php echo rh($a['role']); ?></td><td><?php echo !empty($a['present']) ? 'Present' : 'Absent'; ?></td><td><?php echo rh($a['reason'] ?: '—'); ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
    <?php if ($hc): ?><p class="ev-meta">Companions: <?php echo rh(implode(', ', array_map(fn($c) => $c['name'] . ($c['with'] ? ' (with ' . $c['with'] . ')' : ''), $hc))); ?></p><?php endif; ?>
    <?php if ($hp): ?><p class="ev-meta">Other barangay personnel present: <?php echo rh(implode(', ', array_map(fn($c) => $c['name'] . ($c['position'] ? ' (' . $c['position'] . ')' : ''), $hp))); ?></p><?php endif; ?>
    <?php if (!empty($hRes[$hid])): ?><p class="party-no">Agreement / settlement</p><p class="narrative"><?php echo rh($hRes[$hid]['resolution_details']); ?></p><?php endif; ?>
    <?php if (trim((string)$h['result_remarks']) !== ''): ?><p class="party-no">Remarks</p><p class="narrative"><?php echo rh($h['result_remarks']); ?></p><?php endif; ?>
    <?php report_section_close(); endforeach; ?>

    <?php if ($resolution): report_section_open('Settlement / Kasunduan'); ?>
    <table class="kv"><tbody>
        <tr><th>Resolved on</th><td><?php echo rh($d($resolution['resolved_at'], 'F j, Y g:i A')); ?></td></tr>
        <?php if ($resolution['hearing_no']): ?><tr><th>At hearing</th><td>#<?php echo (int)$resolution['hearing_no']; ?></td></tr><?php endif; ?>
        <?php if ($resolution['presided_by']): ?><tr><th>Presided by</th><td><?php echo rh($resolution['presided_by'] . ($resolution['presided_position'] ? ' — ' . $resolution['presided_position'] : '')); ?></td></tr><?php endif; ?>
        <tr><th>Case closed</th><td><?php echo rh($d($case['ClosedAt'], 'F j, Y g:i A')); ?></td></tr>
    </tbody></table>
    <p class="party-no">Kasunduan / Agreement</p><p class="narrative"><?php echo rh($resolution['resolution_details'] ?: '—'); ?></p>
    <?php if (trim((string)$resolution['remarks']) !== ''): ?><p class="party-no">Remarks</p><p class="narrative"><?php echo rh($resolution['remarks']); ?></p><?php endif; ?>
    <?php report_section_close(); endif; ?>

    <?php foreach ($transfers as $t): report_section_open('Transfer Details'); ?>
    <table class="kv"><tbody>
        <tr><th>Transferred to</th><td><?php echo rh($t['destination']); ?></td></tr>
        <tr><th>Date &amp; time</th><td><?php echo rh($d($t['transfer_date'], 'F j, Y') . ' · ' . date('g:i A', strtotime('2000-01-01 ' . $t['transfer_time']))); ?></td></tr>
        <tr><th>Reason</th><td><?php echo rh($t['reason']); ?></td></tr>
        <?php foreach ([['Reference no.', 'reference_no'], ['Received by', 'receiving_officer'], ['Receiving details', 'receiving_details'], ['Remarks', 'remarks'], ['Proof of transfer', 'proof_file_name'], ['Transferred by', 'transferred_by']] as $x):
            if (trim((string)($t[$x[1]] ?? '')) === '') continue; ?>
        <tr><th><?php echo rh($x[0]); ?></th><td><?php echo rh($t[$x[1]]); ?></td></tr>
        <?php endforeach; ?>
    </tbody></table>
    <?php report_section_close(); endforeach; ?>

    <p class="certify-line">I hereby certify that the statements above are true and correct to the best of my knowledge, and that I filed this complaint of my own free will before the Office of the Punong Barangay, <?php echo rh($brgy['brgy_name'] ?? 'Barangay'); ?>.</p>
    <div class="sig-grid">
        <?php foreach ($comp as $p): ?>
        <div class="sig">
            <p class="sig-role-top">Complainant</p>
            <div class="sig-line"><?php echo rh(mb_strtoupper(blt_party_name($p))); ?></div>
            <div class="sig-role">Signature over printed name</div>
            <div class="sig-date">Date: ______________________</div>
        </div>
        <?php endforeach; ?>
        <div class="sig">
            <p class="sig-role-top">Filed / Recorded By</p>
            <div class="sig-line"><?php echo rh(mb_strtoupper($recordedBy)); ?></div>
            <div class="sig-role"><?php echo rh($recordedRole); ?> · Signature over printed name</div>
            <div class="sig-date">Date: <?php echo rh(date('F j, Y')); ?></div>
        </div>
    </div>
    <?php report_footer_strip($brgy['brgy_name'] ?? 'Barangay'); ?>
</div>
<script>
(function(){
    // Draw the evidence (PDF pages, text files), wait for every image, then Print / Save PDF.
    const PDFJS = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
    const WORKER = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    const mode = <?php echo json_encode($mode); ?>;
    const filename = <?php echo json_encode($fileBase . '.pdf'); ?>;
    function script(src){ return new Promise((ok, fail) => { const s = document.createElement('script'); s.src = src; s.onload = ok; s.onerror = fail; document.head.appendChild(s); }); }
    async function drawPdfs(){
        const boxes = document.querySelectorAll('[data-pdf]');
        if (!boxes.length) return;
        try { await script(PDFJS); await script(WORKER); } catch (e) { boxes.forEach(b => b.innerHTML = '<p class="ev-skip">The PDF viewer could not be loaded (check the internet connection).</p>'); return; }
        pdfjsLib.GlobalWorkerOptions.workerSrc = WORKER;
        for (const box of boxes) {
            try {
                const data = await (await fetch(box.dataset.pdf, { credentials: 'same-origin' })).arrayBuffer();
                const doc = await pdfjsLib.getDocument({ data: new Uint8Array(data) }).promise;
                box.innerHTML = '';
                for (let n = 1; n <= Math.min(doc.numPages, 30); n++) {
                    const page = await doc.getPage(n);
                    const vp = page.getViewport({ scale: 1400 / page.getViewport({ scale: 1 }).width });
                    const c = document.createElement('canvas'); c.width = vp.width; c.height = vp.height;
                    const ctx = c.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
                    await page.render({ canvasContext: ctx, viewport: vp }).promise;
                    const img = new Image(); img.src = c.toDataURL('image/jpeg', 0.9); img.className = 'ev-page'; img.alt = 'Page ' + n;
                    box.appendChild(img);
                }
                if (doc.numPages > 30) box.insertAdjacentHTML('beforeend', '<p class="ev-skip">Only the first 30 of ' + doc.numPages + ' pages are included.</p>');
            } catch (e) { box.innerHTML = '<p class="ev-skip">This PDF could not be read. Open it in Case Details → Evidence.</p>'; }
        }
    }
    async function drawTexts(){
        for (const pre of document.querySelectorAll('[data-text]')) {
            try { pre.textContent = (await (await fetch(pre.dataset.text, { credentials: 'same-origin' })).text()).slice(0, 20000); }
            catch (e) { pre.textContent = 'This text file could not be read.'; }
        }
    }
    function imagesReady(){ return Promise.all(Array.from(document.images).map(i => i.complete ? null : new Promise(r => { i.onload = i.onerror = r; }))); }
    function savePdf(){
        // Same as the other CAPS reports, with a 12 mm margin on every page and no cut through a block.
        html2pdf().set({
            filename: filename, margin: [12, 12, 12, 12],
            image: { type: 'jpeg', quality: 0.95 },
            html2canvas: { scale: 2, useCORS: true, letterRendering: true },
            pagebreak: { mode: ['css', 'legacy'], avoid: ['tr', '.section-wrap', '.sig', '.certify-line', '.ev-block:not(.ev-long)', '.ev-page', '.ev-head', 'p'] },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
        }).from(document.getElementById('reportRoot')).outputPdf('blob').then(function(blob){
            const url = URL.createObjectURL(blob), a = document.createElement('a');
            a.href = url; a.download = filename; document.body.appendChild(a); a.click(); a.remove();
            setTimeout(() => URL.revokeObjectURL(url), 5000);
        });
    }
    window.addEventListener('load', async function(){
        await drawPdfs(); await drawTexts(); await imagesReady();
        await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(() => setTimeout(r, 150))));
        if (mode === 'print') { window.onafterprint = function(){ try { window.close(); } catch (e) {} }; window.print(); }
        else if (mode === 'pdf') savePdf();
    });
})();
</script>
<?php report_foot(); ?>

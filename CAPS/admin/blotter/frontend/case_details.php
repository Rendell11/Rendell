<?php
/**
 * Case Details — the management page of one blotter case.
 * Shows Blotter ID, status, filing info, complainants, respondents (Update / Remove Respondent + history),
 * incident + full incident description, evidence, hearing history, notices, resolution, transfer and the timeline.
 * Print / Save PDF: the complaint record with signature lines (../backend/case_report.php); asks whether to include the evidence files.
 * Actions follow the case status and the rule saved on the case:
 *   Add Hearing (only when every respondent is identified; choose the notice to generate — ONE notice per hearing,
 *   made with the hearing) → Preview → Edit (drag / values, this notice only) / Print / Save PDF → Mark Issued
 *   (proof of receipt required, shown in the preview) → Record Result. Rescheduling updates the same notice.
 *   A hearing with no result by the next day is recorded automatically as "Both No Show"; its letters with no returned signed
 *   copy become "Unserved". "Start the hearing now" is offered for the first hearing only.
 *   Record Result: a no-show fills in the remark (who did not appear, English or Tagalog); the last allowed hearing shows a
 *   "For Transfer" section (optional letter). Resolved → the case is closed automatically (view / print / save PDF only).
 *   Print / Save PDF: case record only, or with hearing 1, 2, … / all. Hearing History → View. Timeline filter (case / print).
 *   Limit reached → Transfer Case (time-based: several transfer stages) · Cancel / Withdraw
 * Data: ../backend/blotter_cases.php (+ hearings, notices, respondents, attachments, transfers).
 */
require_once __DIR__ . '/../../db.php';
$required_module = 'blotter';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../backend/blotter_common.php';

$current_page = 'Blotter';
$pageTitle = 'Case Details';
$id = (int)($_GET['id'] ?? 0);
$db_error = '';
try { blt_migrate($pdo); } catch (Throwable $e) { $db_error = 'Database setup failed. Import sql/blotter.sql.'; error_log('[Blotter] ' . $e->getMessage()); }
?>
<!doctype html>
<html <?php require_once __DIR__ . '/../../theme_loader.php'; echo $theme_attrs['html'] ?? ''; ?>>
<head>
    <?php require __DIR__ . '/partials/blotter_head.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
    <link rel="stylesheet" href="assets/notice_editor.css?v=<?php echo @filemtime(__DIR__ . '/assets/notice_editor.css'); ?>">
    <style>
        .box-title { display:flex; align-items:center; justify-content:space-between; gap:.75rem; padding:1.1rem 1.5rem; border-bottom:1px solid #f8fafc; }
        .box-title h3 { font-size:.85rem; font-weight:900; color:#1e293b; display:flex; align-items:center; gap:.5rem; }
        .box-title h3 .material-symbols-outlined { color:#fff; background:var(--accent-600); padding:.3rem; border-radius:.5rem; font-size:.95rem; }
        .limit-bar { height:8px; border-radius:99px; background:#e2e8f0; overflow:hidden; }
        .limit-bar > div { height:100%; border-radius:99px; }
        .nl-overlay { position:fixed; inset:0; z-index:75; background:#0f172a; display:none; flex-direction:column; }
        .nl-overlay.open { display:flex; }
        #nlHost { flex:1; min-height:0; }
        .proof-box { border:1px solid #d1fae5; background:#ecfdf5; border-radius:1rem; padding:.75rem 1rem; }
        .proof-box img { max-height:260px; border-radius:.75rem; border:1px solid #e2e8f0; background:#fff; }
        .ev-thumb { width:52px; height:52px; border-radius:.75rem; object-fit:cover; background:#f1f5f9; flex-shrink:0; display:flex; align-items:center; justify-content:center; color:#94a3b8; }
    </style>
</head>
<body <?php echo $theme_attrs['body'] ?? ''; ?>>
<div class="flex min-h-screen">
    <?php require __DIR__ . '/../../sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php require __DIR__ . '/../../header.php'; ?>
        <main class="p-4 md:p-6 lg:p-8 space-y-6" id="page">
            <?php if ($db_error): ?><div class="rounded-2xl border border-rose-200 bg-rose-50 text-rose-700 px-5 py-4 text-sm font-bold"><?php echo h($db_error); ?></div><?php endif; ?>
            <p class="text-sm text-slate-400">Loading case…</p>
        </main>
    </div>
</div>

<!-- Edit one generated notice: drag / restyle its fields (the template is not changed) -->
<div id="nlOverlay" class="nl-overlay">
  <div class="flex items-center justify-between gap-3 px-5 py-3 hero-band text-white">
    <div class="min-w-0"><p class="text-[10px] font-black uppercase tracking-[0.18em] text-white/60">Edit this notice only · the template is not changed</p><p class="font-black truncate" id="nlTitle"></p></div>
    <div class="flex items-center gap-2">
      <label class="hidden items-center gap-2 text-xs font-bold text-white/90 mr-2" id="nlAllWrap" title="One letter is made per person: apply the same layout to the letters of the other recipients of this hearing"><input type="checkbox" id="nlAll" checked class="w-4 h-4"><span id="nlAllTxt"></span></label>
      <button type="button" class="hero-btn" id="nlValues"><span class="material-symbols-outlined">edit_note</span>Edit values</button>
      <button type="button" class="hero-btn hero-btn-white" id="nlClose"><span class="material-symbols-outlined">close</span>Close</button>
    </div>
  </div>
  <div id="nlHost"></div>
</div>

<script src="assets/notice_render.js?v=<?php echo @filemtime(__DIR__ . '/assets/notice_render.js'); ?>"></script>
<script src="assets/notice_editor.js?v=<?php echo @filemtime(__DIR__ . '/assets/notice_editor.js'); ?>"></script>
<script src="assets/doc_editor.js?v=<?php echo @filemtime(__DIR__ . '/assets/doc_editor.js'); ?>"></script>
<script src="assets/blotter_party.js?v=<?php echo @filemtime(__DIR__ . '/assets/blotter_party.js'); ?>"></script>
<script>
const CASE_ID = <?php echo $id; ?>;
const API = '../backend/blotter_cases.php';
const esc = BLT.esc;
let D = null;

/* ───────── Generic form modal ───────── */
const Modal = (function(){
    let back = null;
    function open(o){
        close();
        back = document.createElement('div');
        back.className = 'modal-back open';
        back.innerHTML = '<div class="modal-box" style="max-width:' + (o.width || 640) + 'px"><div class="modal-head"><div><h3 class="modal-title">' + esc(o.title) + '</h3>' + (o.sub ? '<p class="modal-sub">' + esc(o.sub) + '</p>' : '') + '</div>' +
            '<button type="button" class="modal-close" data-x><span class="material-symbols-outlined">close</span></button></div>' +
            '<div class="modal-body space-y-4">' + o.html + '</div>' +
            '<div class="modal-foot"><p class="text-[11px] font-semibold text-rose-600 mr-auto whitespace-pre-line" data-msg></p>' + (o.extraButtons || '') +
            '<button type="button" class="btn btn-ghost" data-x>' + esc(o.cancel || 'Cancel') + '</button>' +
            (o.ok ? '<button type="button" class="btn ' + (o.danger ? 'btn-red' : (o.green ? 'btn-green' : 'btn-dark')) + '" data-ok><span class="material-symbols-outlined">' + (o.icon || 'check') + '</span>' + esc(o.ok) + '</button>' : '') + '</div></div>';
        document.body.appendChild(back);
        const root = back;
        root.querySelectorAll('[data-x]').forEach(b => b.addEventListener('click', close));
        const okBtn = root.querySelector('[data-ok]');
        if (okBtn) okBtn.addEventListener('click', async () => {
            const msg = root.querySelector('[data-msg]'); msg.textContent = '';
            okBtn.disabled = true;
            try {
                const r = await o.submit(root);
                if (r === false) return;
                if (r && r.success === false) { msg.textContent = (r.errors || [r.message]).join('\n'); return; }
                if (r && r.message) BLT.toast(r.message, 'success');
                close(); if (o.after) o.after(r); else load();
            } catch (e) { msg.textContent = e.message; }
            finally { okBtn.disabled = false; }
        });
        if (o.mounted) o.mounted(root);
        return root;
    }
    function close(){ if (back) { back.remove(); back = null; } }
    function error(root, m){ root.querySelector('[data-msg]').textContent = m; }
    return { open, close, error };
})();
const val = (root, sel) => (root.querySelector(sel) || {}).value?.trim?.() ?? '';
const today = () => { const d = new Date(), p = n => String(n).padStart(2, '0'); return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()); };
/**
 * Small dialog on top of any modal → Promise: null (cancelled) or { value, choice }.
 * o = { title, message, input: {label, placeholder} | null, choices: [[value, label, hint]] | null, ok, danger, icon }
 */
function Ask(o){
    return new Promise(resolve => {
        const back = document.createElement('div');
        back.className = 'modal-back open'; back.style.zIndex = 96;
        back.innerHTML = '<div class="bg-white rounded-[2rem] shadow-2xl w-full p-7 space-y-4" style="max-width:30rem">' +
            '<div class="flex items-start gap-3"><div class="w-11 h-11 rounded-2xl flex items-center justify-center shrink-0 ' + (o.danger ? 'bg-rose-50 text-rose-500' : 'bg-indigo-50 text-indigo-500') + '"><span class="material-symbols-outlined">' + esc(o.icon || 'help') + '</span></div>' +
            '<div class="flex-1 min-w-0"><h3 class="text-base font-black text-slate-800">' + esc(o.title) + '</h3>' + (o.message ? '<p class="text-xs text-slate-500 mt-1 whitespace-pre-line">' + o.message + '</p>' : '') + '</div></div>' +
            (o.choices ? '<div class="space-y-2">' + o.choices.map((c, i) => '<label class="choice"><input type="radio" name="askc" value="' + esc(c[0]) + '"' + (i === 0 ? ' checked' : '') + '><span><span class="block">' + esc(c[1]) + '</span>' + (c[2] ? '<span class="block text-[11px] font-semibold text-slate-500">' + esc(c[2]) + '</span>' : '') + '</span></label>').join('') + '</div>' : '') +
            (o.input ? '<div><label class="field-label">' + esc(o.input.label) + '</label><textarea class="input" rows="3" maxlength="500" data-ask placeholder="' + esc(o.input.placeholder || '') + '"></textarea><p class="field-error hidden" data-ask-err></p></div>' : '') +
            '<div class="flex gap-3 pt-1"><button type="button" class="flex-1 py-3 text-xs font-black uppercase text-slate-400 border border-slate-200 rounded-2xl" data-r="0">' + esc(o.cancel || 'Back') + '</button>' +
            '<button type="button" class="flex-[2] py-3 text-white text-xs font-black uppercase rounded-2xl ' + (o.danger ? 'bg-rose-500' : 'bg-primary') + '" data-r="1">' + esc(o.ok || 'Confirm') + '</button></div></div>';
        document.body.appendChild(back);
        const inp = back.querySelector('[data-ask]'); if (inp) setTimeout(() => inp.focus(), 50);
        back.addEventListener('click', e => {
            const b = e.target.closest('[data-r]'); if (!b) return;
            if (b.dataset.r === '1' && inp && !inp.value.trim()) { const er = back.querySelector('[data-ask-err]'); er.textContent = 'This is required.'; er.classList.remove('hidden'); inp.focus(); return; }
            const res = b.dataset.r === '1' ? { value: inp ? inp.value.trim() : '', choice: (back.querySelector('input[name=askc]:checked') || {}).value } : null;
            back.remove(); resolve(res);
        });
    });
}
/** Opens a print / PDF page; the case timeline (who printed it) is refreshed afterwards. */
function openPrint(url){ window.open(url, '_blank'); setTimeout(load, 2500); setTimeout(load, 8000); }
const nowTime = () => { const d = new Date(), p = n => String(n).padStart(2, '0'); return p(d.getHours()) + ':' + p(d.getMinutes()); };

/* ───────── Load + draw ───────── */
async function load(){
    try {
        const d = await BLT.getJSON(API + '?action=get&id=' + CASE_ID);
        if (!d.success) throw new Error(d.message);
        D = d; draw();
    } catch (e) { document.getElementById('page').innerHTML = '<div class="rounded-2xl border border-rose-200 bg-rose-50 text-rose-700 px-5 py-4 text-sm font-bold">' + esc(e.message) + ' <a class="underline" href="incidents.php">Back to Blotter</a></div>'; }
}

function kv(rows){ return '<dl class="kv">' + rows.filter(r => r[1] !== null && r[1] !== undefined && r[1] !== '').map(r => '<dt>' + esc(r[0]) + '</dt><dd>' + (r[2] ? r[1] : esc(r[1])) + '</dd>').join('') + '</dl>'; }
function box(icon, title, body, actions){ return '<section class="card overflow-hidden"><div class="box-title"><h3><span class="material-symbols-outlined">' + icon + '</span>' + title + '</h3><div class="flex flex-wrap gap-2">' + (actions || '') + '</div></div><div class="p-5 md:p-6">' + body + '</div></section>'; }
function pill(cls, icon, text){ return '<span class="pill ' + esc(cls) + '"><span class="material-symbols-outlined">' + esc(icon) + '</span>' + esc(text) + '</span>'; }
/** Letter status: Draft → Generated → Issued (signed copy returned) · Unserved (the hearing passed without it) · Cancelled */
function noticePill(st){
    const m = { Issued: ['bg-sky-50 text-sky-700 border-sky-200', 'mark_email_read', 'Issued'], Cancelled: ['bg-slate-100 text-slate-500 border-slate-200', 'block', 'Cancelled'],
        Draft: ['bg-violet-50 text-violet-700 border-violet-200', 'edit_note', 'Draft — not saved'], Unserved: ['bg-rose-50 text-rose-700 border-rose-200', 'unsubscribe', 'Unserved — no signed copy returned'],
        Generated: ['bg-amber-50 text-amber-700 border-amber-200', 'mark_email_unread', 'Generated'] }[st] || ['bg-slate-50 text-slate-600 border-slate-200', 'mail', st];
    return pill(m[0], m[1], m[2]);
}

function draw(){
    const c = D.case, can = D.can, L = c.limit;
    const act = (show, onclick, icon, label, cls) => show ? '<button type="button" onclick="' + onclick + '" class="hero-btn ' + (cls || '') + '"><span class="material-symbols-outlined">' + icon + '</span>' + label + '</button>' : '';
    let h = '<div class="hero-band rounded-2xl p-6 md:p-8 text-white relative overflow-hidden">' +
        '<div class="absolute -right-12 -top-12 w-64 h-64 opacity-10 rounded-full blur-3xl pointer-events-none" style="background:var(--accent-400);"></div>' +
        '<div class="relative z-10 flex flex-col xl:flex-row xl:items-center xl:justify-between gap-4"><div>' +
        '<p class="text-white/60 text-[10px] font-black uppercase tracking-[0.18em]">Blotter ID</p>' +
        '<h1 class="text-2xl md:text-3xl font-black tracking-tight leading-none font-mono">' + esc(c.case_number) + '</h1>' +
        '<div class="flex flex-wrap items-center gap-2 mt-3"><span class="pill bg-white text-slate-800 border-white !text-[10px]"><span class="material-symbols-outlined">' + esc(c.status_icon) + '</span>' + esc(c.status) + '</span>' +
        '<span class="text-white/70 text-xs font-semibold">Filed ' + esc(c.filed) + ' · ' + esc(c.filed_time) + ' by ' + esc(c.filed_by) + '</span></div></div>' +
        '<div class="flex flex-wrap gap-2">' +
        '<a href="incidents.php" class="hero-btn"><span class="material-symbols-outlined">arrow_back</span>Blotter</a>' +
        '<button type="button" onclick="Actions.report(\'print\')" class="hero-btn"><span class="material-symbols-outlined">print</span>Print</button>' +
        '<button type="button" onclick="Actions.report(\'pdf\')" class="hero-btn"><span class="material-symbols-outlined">picture_as_pdf</span>Save PDF</button>' +
        act(can.schedule, 'Actions.hearing()', 'event', 'Add Hearing', 'hero-btn-primary') +
        act(can.record_result, 'Actions.result()', 'gavel', 'Record Result', 'hero-btn-white') +
        act(can.transfer, 'Actions.transfer()', 'local_police', 'Transfer Case', 'hero-btn-white') +
        act(can.close, 'Actions.close()', 'task_alt', 'Close Case', 'hero-btn-white') +
        act(can.cancel, 'Actions.cancel()', 'block', 'Cancel / Withdraw') +
        '</div></div></div>';

    // Rule / limit
    let pct, txt, color;
    if (L.rule === 'time') {
        const used = Math.max(0, L.max - Math.max(L.days_left, 0));
        pct = Math.min(100, used / L.max * 100);
        const dl = esc(new Date(L.deadline + 'T00:00').toLocaleDateString([], { month:'long', day:'numeric', year:'numeric' }));
        // Live countdown to the end of the deadline day.
        txt = L.days_left >= 0 ? '<span class="font-black text-slate-700" data-countdown="' + esc(L.deadline) + '"></span> left · settle by ' + dl + (L.extended ? ' (extended)' : '') : 'The days to settle the case ended on ' + dl + '.';
    } else { pct = Math.min(100, L.used / L.max * 100); txt = L.used + ' of ' + L.max + ' hearing(s) used'; }
    color = pct >= 100 ? '#ef4444' : (pct >= 66 ? '#f97316' : '#10b981');
    const final = ['Resolved', 'Closed', 'Transferred', 'Cancelled', 'Withdrawn'].includes(c.status);
    h += '<div class="card p-5 flex flex-col md:flex-row md:items-center gap-4"><div class="flex items-center gap-3 min-w-[260px]"><span class="material-symbols-outlined text-white bg-primary p-2 rounded-xl">rule</span><div><p class="section-title">Case rule</p><p class="text-sm font-black text-slate-800">' + esc(c.rule) + '</p></div></div>' +
         '<div class="flex-1"><div class="limit-bar"><div style="width:' + pct + '%;background:' + color + '"></div></div><p class="text-[11px] font-bold text-slate-500 mt-1.5">' + (final ? esc('Case is ' + c.status + '.') : txt) + (L.reached && !final && L.rule === 'hearing' ? ' — limit reached, transfer to ' + esc(c.transfer_destination) + '.' : '') + '</p></div></div>';
    if (can.closed) h += '<div class="note note-info"><span class="material-symbols-outlined">lock</span><span>This case is <strong>closed</strong>: it can only be viewed, printed and saved as PDF (every print is still recorded on the timeline). The signed copy of the outcome can still be uploaded.</span></div>';

    // Time-based: the days ended → Extend or For Transfer.
    if (can.decide_period) {
        h += '<div class="note note-warn"><span class="material-symbols-outlined">timer_off</span><div class="flex-1"><p>The days to settle the case ended on <strong>' + esc(new Date(L.deadline + 'T00:00').toLocaleDateString([], { month:'long', day:'numeric', year:'numeric' })) + '</strong> without a settlement. Extend the period, or transfer the case?</p>' +
            '<div class="flex flex-wrap gap-2 mt-2"><button type="button" class="btn btn-dark btn-sm" onclick="Actions.extend()"><span class="material-symbols-outlined">more_time</span>Extend</button><button type="button" class="btn btn-red btn-sm" onclick="Actions.forTransfer()"><span class="material-symbols-outlined">local_police</span>For Transfer</button></div></div></div>';
    }
    // The complaint must be signed before the first hearing.
    if (!can.complaint_signed && can.sign_complaint) {
        h += '<div class="note note-warn"><span class="material-symbols-outlined">draw</span><div class="flex-1"><p>The complaint record is <strong>not signed yet</strong>. Print it, have the complainant sign it, then click <strong>Mark Signed</strong>. The first hearing can be added after that (and once the respondent details are complete).</p>' +
            '<div class="flex flex-wrap gap-2 mt-2"><button type="button" class="btn btn-ghost btn-sm" onclick="Actions.report(\'print\')"><span class="material-symbols-outlined">print</span>Print complaint</button><button type="button" class="btn btn-dark btn-sm" onclick="Docs.sign(\'complaint\')"><span class="material-symbols-outlined">verified</span>Mark Signed</button></div></div></div>';
    }
    // For Transfer: 1) letters to the complainant and respondent, marked issued → 2) the letter for the transfer → 3) Transfer Case.
    if (c.status === 'For Transfer' && can.transfer_ready) {
        const tr = can.transfer_ready, chk = (ok, t) => '<li class="flex items-start gap-2"><span class="material-symbols-outlined text-base ' + (ok ? 'text-emerald-600' : 'text-slate-400') + '">' + (ok ? 'check_circle' : 'radio_button_unchecked') + '</span><span' + (ok ? ' class="text-slate-400"' : '') + '>' + t + '</span></li>';
        h += '<div class="note note-info"><span class="material-symbols-outlined">local_police</span><div class="flex-1"><p class="font-black">Before Transfer Case (to ' + esc(c.transfer_destination) + ')</p><ul class="mt-1 space-y-1">' +
            chk(tr.parties, '1. Letter to the complainant and the respondent — each <strong>marked issued</strong> (signed copy attached)' + (tr.missing.length ? '<br><span class="text-[11px] text-rose-700">Not yet: ' + tr.missing.map(esc).join(', ') + '</span>' : '')) +
            chk(tr.office, '2. Letter for the transfer to ' + esc(c.transfer_destination) + ' — ' + (can.transfer_template ? 'template: ' + esc(can.transfer_template.name) : '<span class="text-rose-700">choose its template in Blotter Settings</span>') + ' · preview, then Save') +
            chk(false, '3. <strong>Transfer Case</strong> (beside Cancel / Withdraw) once the case was handed over, with the proof') + '</ul>' +
            '<div class="flex flex-wrap gap-2 mt-2">' + (can.transfer_letter ? '<button type="button" class="btn btn-dark btn-sm" onclick="Notice.transferLetter()"><span class="material-symbols-outlined">description</span>Letters to the parties</button>' : '') +
            (can.transfer_office ? '<button type="button" class="btn btn-dark btn-sm" onclick="Notice.officeLetter()"><span class="material-symbols-outlined">local_police</span>Letter for the transfer</button>' : '') + '</div></div></div>';
    }
    if (!can.respondents_identified && !final && !D.pending_hearing) {
        h += '<div class="note note-warn"><span class="material-symbols-outlined">person_search</span><span>The respondent details are not complete yet (a non-resident needs a first and last name and an address). A hearing can be added only after that — use <strong>Update Respondent</strong> below.</span></div>';
    }
    if (can.respondents_locked && !final) {
        h += '<div class="note note-info"><span class="material-symbols-outlined">lock</span><span>A hearing has reached its date, so the respondents can no longer be changed.</span></div>';
    }
    if (D.pending_hearing && !can.record_result && D.pending_hearing.status === 'Scheduled') {
        h += '<div class="note note-info"><span class="material-symbols-outlined">event</span><span>Hearing #' + D.pending_hearing.no + ' is on ' + esc(D.pending_hearing.date_label) + ' at ' + esc(D.pending_hearing.time_label) + ' — ' + esc(D.pending_hearing.location) + '. The result can be recorded on or after the hearing date.' + (!can.has_template ? ' Create a notice template in <a class="underline" href="notice_templates.php">Notice Templates</a> to generate notices.' : '') + '</span></div>';
    }

    h += '<div class="grid xl:grid-cols-3 gap-6"><div class="xl:col-span-2 space-y-6">';
    // Parties
    const comp = D.parties.filter(p => p.role === 'Complainant'), resp = D.parties.filter(p => p.role === 'Respondent');
    const ptype = p => p.type === 'Unknown' ? 'Non-Resident' : p.type;   // "Unknown" was merged into Non-Resident
    const partyBody = p => '<div class="rounded-2xl border border-slate-100 p-4 space-y-2">' +
        '<div class="flex flex-wrap items-center justify-between gap-2"><div><p class="text-sm font-black text-slate-800">' + esc(p.name) + (p.resident_code ? ' <span class="font-mono text-[11px] text-indigo-600">' + esc(p.resident_code) + '</span>' : '') + '</p>' +
        '<div class="flex flex-wrap gap-1 mt-1">' + pill(p.type === 'Resident' ? 'bg-indigo-50 text-indigo-700 border-indigo-100' : 'bg-slate-50 text-slate-600 border-slate-200', p.type === 'Resident' ? 'home' : 'person', ptype(p)) +
        (p.role === 'Respondent' && !p.is_identified ? pill('bg-rose-50 text-rose-700 border-rose-200', 'person_off', 'Not yet identified') : '') +
        (p.address_changed ? pill('bg-amber-50 text-amber-700 border-amber-200', 'move_location', p.moved_out ? 'Relocated (moved out)' : 'Relocated') : '') + '</div></div>' +
        (p.role === 'Respondent' && can.update_respondent ? '<div class="flex gap-1"><button type="button" class="btn btn-ghost btn-sm" onclick="Actions.respondent(' + p.id + ')"><span class="material-symbols-outlined">edit</span>Update Respondent</button>' +
            (resp.length > 1 ? '<button type="button" class="icon-btn danger" title="Remove from the case" onclick="Actions.removeRespondent(' + p.id + ')"><span class="material-symbols-outlined">person_remove</span></button>' : '') + '</div>' : '') + '</div>' +
        kv([['Date of birth', p.birth_label], ['Sex', p.sex], ['Contact number', p.contact]].concat(p.address_changed
            ? [['Address (when filed)', p.filed_address], ['New address (used on new letters)', p.address]]
            : [['Address', p.address]]).concat([['Alias', p.alias], ['Physical appearance', p.physical_description],
            ['Clothing', p.clothing], ['Vehicle', p.vehicle], ['Plate number', p.plate_number], ['Other details', p.other_details], ['Last updated', p.updated]])) +
        (D.party_history.filter(x => x.party_id === p.id).length ? '<details class="mt-2"><summary class="text-[11px] font-black uppercase tracking-widest text-indigo-600 cursor-pointer">Previous information (' + D.party_history.filter(x => x.party_id === p.id).length + ')</summary><ul class="mt-2 space-y-2">' +
            D.party_history.filter(x => x.party_id === p.id).map(x => '<li class="rounded-xl bg-slate-50 p-3 text-xs"><p class="font-bold text-slate-700">' + esc(x.previous) + '</p>' + (x.previous_address ? '<p class="text-slate-500">' + esc(x.previous_address) + '</p>' : '') +
            '<p class="text-[11px] text-slate-400 mt-1">Changed ' + esc(x.at) + ' by ' + esc(x.by) + ' — ' + esc(x.reason || '') + '</p></li>').join('') + '</ul></details>' : '') + '</div>';
    h += box('person', 'Complainant' + (comp.length > 1 ? 's' : ''), '<div class="space-y-3">' + comp.map(partyBody).join('') + '</div>');
    h += box('person_alert', 'Respondent' + (resp.length > 1 ? 's' : ''), '<div class="space-y-3">' + resp.map(partyBody).join('') + '</div>',
        can.update_respondent ? '<button type="button" class="btn btn-ghost btn-sm" onclick="Actions.respondent(0)"><span class="material-symbols-outlined">person_add</span>Add Respondent</button>' : '');

    // Incident
    h += box('report', 'Incident Information', kv([['Case type', c.type + (c.type_is_other ? ' (Other)' : '')], ['Incident date', c.incident_date], ['Incident time', c.incident_time], ['Location', c.location + (c.location_is_other ? ' (Other)' : '')]]));
    h += box('description', 'Incident Description', '<p class="text-sm text-slate-700 leading-relaxed whitespace-pre-line">' + esc(c.description || '—') + '</p>');

    // Evidence
    h += box('attach_file', 'Evidence (' + D.attachments.length + ')', D.attachments.length ? '<div class="space-y-2">' + D.attachments.map(a =>
        '<div class="flex items-center gap-3 rounded-2xl border border-slate-100 p-3">' + (a.is_image ? '<img class="ev-thumb" src="' + esc(a.url) + '" alt="">' : '<div class="ev-thumb"><span class="material-symbols-outlined">' + (a.type.startsWith('video/') ? 'movie' : (a.type.startsWith('audio/') ? 'graphic_eq' : (a.type === 'application/pdf' ? 'picture_as_pdf' : 'description'))) + '</span></div>') +
        '<div class="min-w-0 flex-1"><p class="text-sm font-bold text-slate-800 truncate">' + esc(a.name) + '</p><p class="text-[11px] text-slate-400">' + esc(a.ext) + ' · ' + esc(a.size) + ' · ' + esc(a.uploaded) + ' · ' + esc(a.by) + '</p>' + (a.description ? '<p class="text-[11px] text-slate-600">' + esc(a.description) + '</p>' : '') + '</div>' +
        '<a class="btn btn-ghost btn-sm" target="_blank" href="' + esc(a.url) + '"><span class="material-symbols-outlined">open_in_new</span>View</a>' +
        (can.delete ? '<button type="button" class="icon-btn danger" title="Remove" onclick="Actions.delEvidence(' + a.id + ')"><span class="material-symbols-outlined">delete</span></button>' : '') + '</div>').join('') + '</div>'
        : '<p class="text-sm text-slate-400">No evidence.</p>', can.add_evidence ? '<button type="button" class="btn btn-ghost btn-sm" onclick="Actions.evidence()"><span class="material-symbols-outlined">add</span>Add Evidence</button>' : '');

    // Hearings
    h += box('event', 'Hearing History', D.hearings.length ? '<div class="overflow-x-auto"><table class="mini-table"><thead><tr><th>#</th><th>Date &amp; time</th><th>Location / agenda</th><th>Status / result</th><th></th></tr></thead><tbody>' +
        D.hearings.map(x => '<tr><td class="font-black">' + x.no + '</td><td class="whitespace-nowrap">' + esc(x.date_label) + '<div class="text-[11px] text-slate-400">' + esc(x.time_label) + '</div></td>' +
            '<td>' + esc(x.location) + (x.agenda ? '<div class="text-[11px] text-slate-400">' + esc(x.agenda) + '</div>' : '') + (x.notice_required ? '<div class="text-[10px] font-bold text-indigo-600 mt-0.5">With notice / letter</div>' : '') + '</td>' +
            '<td>' + (x.outcome ? pill(x.outcome === 'Resolved' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : (x.outcome.includes('No Show') ? 'bg-orange-50 text-orange-700 border-orange-200' : 'bg-rose-50 text-rose-700 border-rose-200'), x.outcome === 'Resolved' ? 'handshake' : (x.outcome.includes('No Show') ? 'person_off' : 'close'), x.outcome) +
                '<div class="text-[11px] text-slate-500 mt-1 whitespace-pre-line">' + esc(x.result_remarks || '') + '</div>' +
                (x.attendance.length ? '<div class="text-[11px] mt-1">' + x.attendance.map(a => '<span class="' + (a.present ? 'text-emerald-700' : 'text-rose-700') + ' font-bold">' + esc(a.name) + ': ' + (a.present ? 'present' : 'absent' + (a.reason ? ' (' + esc(a.reason) + ')' : '')) + '</span>').join(' · ') + '</div>' : '') +
                (x.companions.length ? '<div class="text-[11px] text-slate-500">Companions: ' + x.companions.map(c => esc(c.name) + (c.with ? ' (with ' + esc(c.with) + ')' : '')).join(', ') + '</div>' : '') +
                (x.presided_by ? '<div class="text-[11px] text-slate-500">Presided by ' + esc(x.presided_by) + (x.presided_position ? ' — ' + esc(x.presided_position) : '') + '</div>' : '') +
                '<div class="text-[10px] text-slate-400">' + esc(x.result_at) + ' · ' + esc(x.result_by || '') + '</div>' +
                (x.personnel && x.personnel.length ? '<div class="text-[11px] text-slate-500">Also present: ' + x.personnel.map(q => esc(q.name) + (q.position ? ' (' + esc(q.position) + ')' : '')).join(', ') + '</div>' : '') +
                (x.files.length ? '<div class="text-[10px] text-emerald-700 font-bold">' + x.files.length + ' signed cop' + (x.files.length > 1 ? 'ies' : 'y') + ' uploaded</div>' : '')
                : pill(x.status === 'Cancelled' ? 'bg-slate-100 text-slate-500 border-slate-200' : 'bg-indigo-50 text-indigo-700 border-indigo-200', x.status === 'Cancelled' ? 'event_busy' : 'event', x.status) + (x.cancel_reason ? '<div class="text-[11px] text-slate-400 mt-1">' + esc(x.cancel_reason) + '</div>' : '')) + '</td>' +
            '<td class="text-right whitespace-nowrap">' +
                '<button type="button" class="icon-btn" title="View hearing details" onclick="Actions.viewHearing(' + x.id + ')"><span class="material-symbols-outlined">visibility</span></button>' +
                (x.outcome && !x.auto ? '<button type="button" class="icon-btn" title="Print outcome (for signing)" onclick="openPrint(\'../backend/outcome_report.php?mode=print&hearing_id=' + x.id + '\')"><span class="material-symbols-outlined">print</span></button>' +
                    '<button type="button" class="icon-btn" title="Save outcome as PDF" onclick="openPrint(\'../backend/outcome_report.php?mode=pdf&hearing_id=' + x.id + '\')"><span class="material-symbols-outlined">picture_as_pdf</span></button>' +
                    '<button type="button" class="icon-btn" title="Signed copies (' + x.files.length + ')" onclick="Actions.outcomeUpload(' + x.id + ')"><span class="material-symbols-outlined">' + (x.files.length ? 'verified' : 'upload_file') + '</span></button>' : '') +
                (x.status === 'Scheduled' && can.reschedule ? '<button type="button" class="icon-btn" title="Reschedule" onclick="Actions.hearing(' + x.id + ')"><span class="material-symbols-outlined">edit_calendar</span></button><button type="button" class="icon-btn danger" title="Cancel hearing" onclick="Actions.cancelHearing(' + x.id + ')"><span class="material-symbols-outlined">event_busy</span></button>' : '') + '</td></tr>').join('') +
        '</tbody></table></div>' : '<p class="text-sm text-slate-400">No hearing yet.' + (can.schedule ? ' Click <strong>Add Hearing</strong> to schedule the first one.' : '') + '</p>');

    // Notices
    const cancelledN = D.notices.filter(n => n.status === 'Cancelled').length;
    const shownN = D.notices.filter(n => n.status !== 'Cancelled' || window.SHOW_CANCELLED);
    const docRows = (D.documents || []).map(dc => '<tr class="bg-slate-50/60"><td class="font-bold text-slate-700">' + esc(dc.label) + '</td><td>Case document<div class="text-[11px] text-slate-400">' + esc(dc.hint) + '</div></td><td>—</td>' +
        '<td>' + (dc.signed ? pill('bg-emerald-50 text-emerald-700 border-emerald-200', 'verified', 'Signed') + '<div class="text-[10px] text-slate-400 mt-1">' + esc(dc.at + ' · ' + (dc.by || '')) + '</div>' : pill('bg-amber-50 text-amber-700 border-amber-200', 'draw', 'Not signed yet')) + '</td>' +
        '<td class="text-right whitespace-nowrap"><button type="button" class="btn btn-ghost btn-sm" onclick="Actions.report(\'print\')"><span class="material-symbols-outlined">print</span>Print</button>' +
        (dc.signed && dc.url ? '<a class="btn btn-ghost btn-sm" target="_blank" href="' + esc(dc.url) + '"><span class="material-symbols-outlined">attach_file</span>Signed copy</a>' : '') +
        (!dc.signed && dc.kind === 'complaint' && can.sign_complaint ? '<button type="button" class="btn btn-dark btn-sm" onclick="Docs.sign(\'' + dc.kind + '\')"><span class="material-symbols-outlined">verified</span>Mark Signed</button>' : '') + '</td></tr>').join('');
    h += box('mail', 'Notices', (shownN.length || docRows) ? '<div class="overflow-x-auto"><table class="mini-table"><thead><tr><th>Notice No.</th><th>Type / recipient</th><th>Hearing</th><th>Status</th><th></th></tr></thead><tbody>' + docRows +
        shownN.map(n => '<tr><td class="font-mono font-bold">' + esc(n.no) + '</td><td>' + esc(n.type) + '<div class="text-[11px] text-slate-400">' + esc(n.recipient) + '</div></td><td>' + (n.purpose === 'transfer_office' ? '<span class="font-bold text-rose-700">Transfer letter</span>' : (n.purpose === 'transfer_party' ? '<span class="font-bold text-rose-700">For transfer</span>' : '#' + n.hearing_no)) + '</td>' +
            '<td>' + noticePill(n.status) +
                '<div class="text-[10px] text-slate-400 mt-1">' + esc(n.issued ? 'Issued ' + n.issued + (n.issue_remarks ? ' — ' + n.issue_remarks : '') : 'Generated ' + n.generated) + '</div>' +
                (n.edited ? '<div class="text-[10px] text-amber-600">Edited ' + esc(n.edited) + '</div>' : '') + '</td>' +
            // Everything (Edit, Print, Save PDF, Mark Issued, proof) is inside the preview.
            '<td class="text-right whitespace-nowrap"><button type="button" class="btn btn-ghost btn-sm" onclick="Notice.preview([' + n.id + '])"><span class="material-symbols-outlined">visibility</span>Preview</button></td></tr>').join('') +
        '</tbody></table></div>' : '<p class="text-sm text-slate-400">No notice yet. Choose the notice when adding a hearing.</p>',
        (can.transfer_letter ? '<button type="button" class="btn btn-ghost btn-sm" onclick="Notice.transferLetter()"><span class="material-symbols-outlined">description</span>Letters to the parties</button>' : '') +
        (can.transfer_office ? '<button type="button" class="btn btn-ghost btn-sm" onclick="Notice.officeLetter()"><span class="material-symbols-outlined">local_police</span>Letter for the transfer</button>' : '') +
        (cancelledN ? '<button type="button" class="btn btn-ghost btn-sm" onclick="window.SHOW_CANCELLED=!window.SHOW_CANCELLED;draw()">' + (window.SHOW_CANCELLED ? 'Hide' : 'Show') + ' cancelled (' + cancelledN + ')</button>' : ''));

    // Resolution / transfer / cancellation
    if (D.resolution) h += box('handshake', 'Resolution', kv([['Resolved on', D.resolution.resolved_at], ['At hearing', D.resolution.hearing_no ? '#' + D.resolution.hearing_no : ''], ['Resolution details', D.resolution.details], ['Remarks', D.resolution.remarks], ['Recorded by', D.resolution.by + ' · ' + D.resolution.recorded_at], ['Closed', c.closed_at]]));
    if (D.transfers.length) h += box('local_police', 'Transfer', D.transfers.map(t => kv([['Destination', t.destination], ['Date & time', t.date + ' ' + t.time], ['Reason', t.reason], ['Remarks', t.remarks], ['Reference no.', t.reference_no], ['Received by', t.receiving_officer], ['Receiving details', t.receiving_details], ['Transferred by', t.by],
        ['Proof', t.proof_url ? '<a class="text-indigo-700 underline" target="_blank" href="' + esc(t.proof_url) + '">' + esc(t.proof_name || 'View') + '</a>' : '', 1]])).join('<hr class="my-4 border-slate-100">'));
    if (c.cancelled_at) h += box('block', c.status, kv([['Date', c.cancelled_at], ['Reason', c.cancel_reason]]));
    h += '</div>';

    // Timeline
    const tf = window.TL_FILTER || 'all';
    const tl = D.timeline.filter(t => tf === 'all' || t.kind === tf);
    h += '<div>' + box('timeline', 'Case Timeline', (tl.length ? '' : '<p class="text-sm text-slate-400">Nothing to show for this filter.</p>') + '<ol class="timeline">' + tl.map(t => '<li><p class="text-xs font-black text-slate-800">' + esc(t.action) + '</p>' +
        (t.status ? '<p class="text-[10px] font-bold uppercase tracking-wider text-indigo-600">' + esc(t.status) + '</p>' : '') +
        '<p class="text-[11px] text-slate-400">' + esc(t.date) + ' · ' + esc(t.time) + ' · ' + esc(t.by || 'System') + '</p>' +
        (t.remarks ? '<p class="text-[11px] text-slate-600 mt-1 whitespace-pre-line">' + esc(t.remarks) + '</p>' : '') + '</li>').join('') + '</ol>',
        '<select class="input !py-1 !text-xs !w-auto" title="Filter the timeline" onchange="window.TL_FILTER=this.value;draw()">' + [['all', 'All'], ['case', 'Case events'], ['print', 'Print & PDF']].map(o => '<option value="' + o[0] + '"' + (tf === o[0] ? ' selected' : '') + '>' + o[1] + ' (' + (o[0] === 'all' ? D.timeline.length : D.timeline.filter(t => t.kind === o[0]).length) + ')</option>').join('') + '</select>') + '</div></div>';
    document.getElementById('page').innerHTML = h;
    tickCountdown();
}
/** Time-based: live days / hours / minutes / seconds left until the end of the deadline day. */
function tickCountdown(){
    document.querySelectorAll('[data-countdown]').forEach(el => {
        let s = Math.max(0, Math.floor((new Date(el.dataset.countdown + 'T23:59:59').getTime() - Date.now()) / 1000));
        const d = Math.floor(s / 86400); s %= 86400;
        const p = n => String(n).padStart(2, '0');
        el.textContent = d + ' day' + (d === 1 ? '' : 's') + ' ' + p(Math.floor(s / 3600)) + 'h ' + p(Math.floor(s % 3600 / 60)) + 'm ' + p(s % 60) + 's';
    });
}
setInterval(tickCountdown, 1000);

/* ───────── Actions ───────── */
const Actions = {
    hearing(hid){
        const x = hid ? D.hearings.find(y => y.id === hid) : null;
        const types = D.can.notice_types || [];
        const withNoticeOpt = (D.letter_templates || []).length > 0;
        const askWhen = !x && D.next_hearing_no === 1;   // "Start now" only for the first hearing; the next ones are scheduled
        Modal.open({ title: x ? 'Reschedule Hearing #' + x.no : 'Add Hearing', sub: D.case.case_number, ok: x ? 'Save Schedule' : 'Save Hearing', icon: 'event', width: 760,
            html: (askWhen ? '<div><label class="field-label">When is the hearing? *</label><div class="grid sm:grid-cols-2 gap-2">' +
                    '<label class="choice"><input type="radio" name="hWhen" value="schedule" checked><span><span class="block">Schedule a hearing</span><span class="block text-[11px] font-semibold text-slate-500">Set a date and time; a letter can be sent to the parties.</span></span></label>' +
                    '<label class="choice"><input type="radio" name="hWhen" value="now"><span><span class="block">Start the hearing now</span><span class="block text-[11px] font-semibold text-slate-500">The parties are at the barangay — no letter needed; the result is recorded right after.</span></span></label></div></div>'
                  : (!x ? '<p class="text-[11px] font-bold text-slate-500">Hearing #' + D.next_hearing_no + ' is scheduled (date and time); a letter can be sent to the parties.</p>' : '')) +
                '<div class="grid sm:grid-cols-3 gap-4" id="hWhenBox"><div><label class="field-label">Hearing number</label><div class="input bg-slate-100 text-slate-500">#' + (x ? x.no : D.next_hearing_no) + '</div></div>' +
                '<div><label class="field-label">Hearing date *</label><input type="date" class="input" id="hDate" min="' + today() + '"' + (D.case.limit.rule === 'time' && D.case.limit.deadline ? ' max="' + D.case.limit.deadline + '"' : '') + ' value="' + esc(x ? x.date : '') + '"></div>' +
                '<div><label class="field-label">Hearing time *</label><input type="time" class="input" id="hTime" value="' + esc(x ? x.time : '14:00') + '"></div></div>' +
                '<div id="hDay" class="text-[11px]"></div>' +
                '<p class="text-[11px] font-bold text-emerald-700 hidden" id="hNowNote">The hearing starts now: <span id="hNowClock"></span></p>' +
                '<div><label class="field-label">Hearing location *</label><input class="input" id="hLoc" maxlength="255" value="' + esc(x ? x.location : D.default_hearing_location) + '"></div>' +
                '<div><label class="field-label">Purpose / agenda</label><textarea class="input" id="hAgenda" rows="3" maxlength="2000">' + esc(x ? (x.agenda || '') : (D.next_hearing_no === 1 ? 'Mediation of the complaint' : 'Continuation of mediation')) + '</textarea>' +
                '<p class="field-error hidden" id="hErr"></p></div>' +
                (x ? '<div><label class="field-label">Reason for rescheduling *</label><input class="input" id="hReason" maxlength="500"></div>' : '') +
                (D.case.limit.rule === 'time' ? '<p class="text-[11px] text-slate-400" id="hRuleNote">Time-based rule: the hearing must be on or before ' + esc(D.case.limit.deadline) + '.</p>' : '') +
                (withNoticeOpt ? '<div class="rounded-2xl border border-slate-100 p-4 space-y-4" id="hLetterBox"><div><label class="field-label">Send a letter / notice to the parties? *</label><div class="grid sm:grid-cols-2 gap-2">' +
                        '<label class="choice"><input type="radio" name="hLetter" value="1" checked><span>Yes, generate a letter</span></label><label class="choice"><input type="radio" name="hLetter" value="0"><span>No letter</span></label></div></div>' +
                    '<div id="hLetterForm" class="space-y-4">' + NoticeForm.html(types, x ? 'Letter for the new schedule' : 'Letter to generate', false) + '</div>' +
                    '<p class="text-[11px] text-slate-400">' + (x ? 'The letters of the old schedule are cancelled. ' : '') + 'One letter per chosen person, saved as a Draft until you check it in the preview and click Save.</p></div>'
                  : (!x ? '<p class="text-[11px] text-slate-400">No notice template yet — create one in <a class="underline" href="notice_templates.php">Notice Templates</a> to generate letters.</p>' : '')),
            mounted: root => {
                const now = () => (root.querySelector('input[name=hWhen]:checked') || {}).value === 'now';
                const letterOn = () => withNoticeOpt && !now() && (root.querySelector('input[name=hLetter]:checked') || {}).value === '1';
                // The date and time can never be in the past — shown right under Purpose.
                // Hearings already on the chosen day (any case): no two hearings may overlap.
                let day = { date: '', list: [], minutes: 60 };
                const loadDay = async () => {
                    const d = val(root, '#hDate'), box = root.querySelector('#hDay');
                    if (!d || now()) { box.innerHTML = ''; day = { date: '', list: [], minutes: 60 }; check(); return; }
                    try { const r = await BLT.getJSON(API + '?action=week&days=1&start=' + d);
                        day = { date: d, list: (r.hearings || []).filter(z => z.status === 'Scheduled' && (!x || z.id !== x.id)), minutes: r.minutes || 60 }; } catch (e) { day = { date: d, list: [], minutes: 60 }; }
                    box.innerHTML = day.list.length ? '<div class="rounded-xl bg-amber-50 border border-amber-200 px-3 py-2"><p class="font-black text-amber-800">Already scheduled on this date (' + day.minutes + ' min each):</p><ul class="list-disc pl-5 text-amber-900">' +
                        day.list.map(z => '<li>' + esc(z.time_label) + ' — ' + esc(z.case_number) + ' #' + z.no + ' · ' + esc((z.complainant || '') + ' vs. ' + (z.respondent || '')) + ' · ' + esc(z.location) + '</li>').join('') + '</ul></div>'
                        : '<p class="text-emerald-700 font-bold">No other hearing on this date.</p>';
                    check();
                };
                const mins = t => { const p = String(t || '').split(':'); return Number(p[0]) * 60 + Number(p[1] || 0); };
                const check = () => {
                    const e = root.querySelector('#hErr');
                    if (now()) { e.classList.add('hidden'); return ''; }
                    const d = val(root, '#hDate'), t = val(root, '#hTime');
                    const clash = d && t && day.date === d ? day.list.find(z => Math.abs(mins(z.time) - mins(t)) < day.minutes) : null;
                    const m = d && t && (d < today() || (d === today() && t < nowTime())) ? 'The hearing date and time cannot be in the past (now: ' + new Date().toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) + ').'
                        : (clash ? 'This time overlaps the hearing of ' + clash.case_number + ' at ' + clash.time_label + '. Choose a time at least ' + day.minutes + ' minutes before or after it.' : '');
                    e.textContent = m; e.classList.toggle('hidden', !m);
                    return m;
                };
                const sync = () => {
                    root.querySelector('#hWhenBox').classList.toggle('hidden', now());
                    root.querySelector('#hDay').style.display = now() ? 'none' : '';
                    root.querySelector('#hNowNote').classList.toggle('hidden', !now());
                    const lb = root.querySelector('#hLetterBox'); if (lb) lb.classList.toggle('hidden', now());
                    const lf = root.querySelector('#hLetterForm'); if (lf) lf.classList.toggle('hidden', !letterOn());
                    check();
                };
                const clock = setInterval(() => { const c = root.querySelector('#hNowClock'); if (!c) return clearInterval(clock); c.textContent = new Date().toLocaleString([], { dateStyle: 'medium', timeStyle: 'medium' }); }, 1000);
                root._check = check; root._now = now; root._letterOn = letterOn;
                ['#hDate', '#hTime'].forEach(sel => root.querySelector(sel).addEventListener('input', check));
                root.querySelector('#hDate').addEventListener('change', loadDay);
                if (val(root, '#hDate')) loadDay();
                root.querySelectorAll('input[name=hWhen], input[name=hLetter]').forEach(r => r.addEventListener('change', sync));
                if (withNoticeOpt) NoticeForm.mount(root);
                sync();
            },
            submit: async root => {
                const data = { action: x ? 'reschedule' : 'add', case_id: CASE_ID, hearing_id: x ? x.id : 0, location: val(root, '#hLoc'), agenda: val(root, '#hAgenda'), reason: val(root, '#hReason') };
                if (root._now()) {
                    data.start_now = 1;
                } else {
                    const d = val(root, '#hDate'), t = val(root, '#hTime');
                    if (!d || !t) return { success:false, message:'Enter the hearing date and time.' };
                    if (root._check()) return { success:false, message: root._check() };
                    Object.assign(data, { hearing_date: d, hearing_time: t });
                    if (root._letterOn()) {
                        const nf = NoticeForm.read(root);
                        if (nf.error) return { success:false, message: nf.error };
                        if (!nf.template_id) return { success:false, message:'Choose the letter, or pick "No letter".' };
                        NoticeForm.add(data, nf);
                    }
                }
                return BLT.post('../backend/hearings.php', data);
            },
            after: async r => {
                await load();
                if (r && r.notice_ids && r.notice_ids.length) Notice.preview(r.notice_ids, { fresh: true });
                else if (r && r.started_now && D.can.record_result) Actions.result();   // held right away
            } });
    },
    cancelHearing(hid){
        const x = D.hearings.find(y => y.id === hid);
        const withLetter = (D.letter_templates || []).length > 0;
        Modal.open({ title: 'Cancel Hearing #' + x.no, sub: D.case.case_number, ok: 'Cancel Hearing', danger: true, icon: 'event_busy', cancel: 'Keep', width: 760,
            html: '<p class="text-sm text-slate-600">' + esc(x.date_label + ' · ' + x.time_label + ' — ' + x.location) + '</p><div><label class="field-label">Reason *</label><textarea class="input" id="cr" rows="3" maxlength="500"></textarea></div>' +
                (withLetter ? '<div class="rounded-2xl border border-slate-100 p-4 space-y-4">' + NoticeForm.html([], 'Letter to the parties (e.g. notice of cancellation)') + '<p class="text-[11px] text-slate-400">The letters of this hearing are cancelled.</p></div>' : ''),
            mounted: root => { if (withLetter) NoticeForm.mount(root, 0, ''); },
            submit: async root => {
                const data = { action: 'cancel', hearing_id: hid, reason: val(root, '#cr') };
                if (!data.reason) return { success:false, message:'Enter the reason for cancelling the hearing.' };
                if (withLetter) { const nf = NoticeForm.read(root); if (nf.error) return { success:false, message: nf.error }; NoticeForm.add(data, nf); }
                if (!(await BLT.confirm({ title: 'Cancel hearing #' + x.no + '?', message: x.date_label + ' · ' + x.time_label + '. Its letters are cancelled. This cannot be undone.', ok: 'Cancel Hearing', danger: true, icon: 'event_busy' }))) return false;
                return BLT.post('../backend/hearings.php', data);
            },
            after: r => { load(); if (r && r.notice_ids && r.notice_ids.length) Notice.preview(r.notice_ids, { fresh: true }); } });
    },
    result(){
        const x = D.pending_hearing;
        const P = D.personnel || [];
        const KEY = 'blt_result_' + x.id;   // what was typed is kept if the window is closed (until saved or cleared)
        const NO_PROOF_TIP = 'The letter (notice of hearing) has not been confirmed as delivered to this person yet — no signed copy was attached. They may not know about this hearing. Attach the signed copy here if it was received at the last minute.';
        const letterOf = pid => D.notices.find(n => n.hearing_id === x.id && n.purpose === 'hearing' && (n.party_ids || [n.party_id]).includes(pid) && n.status !== 'Cancelled');
        const noProofOf = pid => { const n = letterOf(pid); return n && n.status !== 'Issued'; };
        const proofTag = p => { const n = letterOf(p.id); if (!n) return '';
            return n.status === 'Issued' ? '<span class="pill bg-emerald-50 text-emerald-700 border-emerald-200" title="The signed copy of the letter was attached — the person received the notice of hearing."><span class="material-symbols-outlined">verified</span>Letter received</span>'
                : '<span class="pill bg-rose-50 text-rose-700 border-rose-200 cursor-help" title="' + esc(NO_PROOF_TIP) + '"><span class="material-symbols-outlined">info</span>No proof of receipt</span>' +
                  '<label class="text-[11px] font-bold text-indigo-600 cursor-pointer ml-1">+ attach proof<input type="file" class="hidden" data-proof="' + n.id + '" accept="image/jpeg,image/png,image/webp,application/pdf"></label><span class="text-[10px] text-slate-500 ml-1" data-proof-name></span>'; };
        const pOpt = sel => '<option value="">— Select —</option>' + P.map((q, i) => '<option value="' + i + '"' + (sel === i ? ' selected' : '') + '>' + esc(q.name) + ' — ' + esc(q.position) + '</option>').join('') + '<option value="other">Other (type the name)</option>';
        // Early / late against the schedule.
        const sched = new Date(x.date + 'T' + x.time);
        const timing = () => { const m = Math.round((Date.now() - sched.getTime()) / 60000), a = Math.abs(m), t = a >= 60 ? Math.floor(a / 60) + ' h ' + (a % 60) + ' min' : a + ' min';
            return Math.abs(m) <= 5 ? ['On time', 'text-emerald-700'] : (m < 0 ? [t + ' early', 'text-sky-700'] : [t + ' late', 'text-amber-700']); };
        const nowLabel = () => new Date().toLocaleString([], { dateStyle: 'long', timeStyle: 'medium' });
        Modal.open({ title: 'Hearing #' + x.no + ' Result', sub: D.case.case_number + ' · ' + x.date_label, ok: 'Save Result', icon: 'gavel', width: 860,
            html: '<div class="flex flex-wrap items-center justify-between gap-2 rounded-2xl bg-slate-50 border border-slate-100 px-4 py-2">' +
                    '<p class="text-xs font-bold text-slate-600">Scheduled ' + esc(x.date_label + ' · ' + x.time_label) + ' · now <span id="rNow"></span> · <span id="rTiming"></span></p>' +
                    '<button type="button" class="btn btn-ghost btn-sm" id="rClear" title="Start over (clears what was typed)"><span class="material-symbols-outlined">restart_alt</span>Clear</button></div>' +
                '<p class="text-[11px] text-slate-400 -mt-2" id="rRestored"></p>' +
                '<div><p class="section-title mb-2">Attendance · check who came</p><div class="space-y-2">' +
                    D.parties.map(p => { const np = noProofOf(p.id);
                        return '<div class="rounded-2xl border p-3 ' + (np ? 'border-rose-200 bg-rose-50/40' : 'border-slate-100') + '" data-att="' + p.id + '"><label class="flex items-center gap-3 cursor-pointer"><input type="checkbox" data-present checked class="w-4 h-4">' +
                        '<span class="flex-1"><span class="block text-sm font-bold ' + (np ? 'text-rose-700' : 'text-slate-800') + '">' + esc(p.label) + '</span><span class="block text-[11px] font-semibold text-slate-500">' + esc(p.role) + '</span></span><span class="text-[11px] font-black" data-state>Present</span></label>' +
                        '<div class="flex flex-wrap items-center gap-1 mt-1">' + proofTag(p) + '</div>' +
                        '<div class="hidden mt-2" data-reason-wrap><label class="field-label">Reason for being absent *</label><input class="input" data-reason maxlength="300" placeholder="e.g. Sick, out of town, did not receive the letter"></div></div>'; }).join('') + '</div></div>' +
                '<div><p class="section-title mb-2">Companions · people who came with the parties</p><div class="space-y-2" id="compList"></div>' +
                    '<button type="button" class="btn btn-ghost btn-sm mt-2" id="compAdd"><span class="material-symbols-outlined">person_add</span>Add companion</button></div>' +
                '<div><p class="section-title mb-2">Result</p><div class="grid sm:grid-cols-2 gap-2">' + [['Resolved', 'handshake', 'Settled / agreement reached'], ['Unresolved', 'close', 'No settlement'], ['Complainant No Show', 'person_off', 'Complainant did not appear'], ['Respondent No Show', 'person_off', 'Respondent did not appear'], ['Both No Show', 'group_off', 'Neither party appeared — no hearing took place']]
                .map(o => '<label class="choice"><input type="radio" name="outcome" value="' + o[0] + '"><span><span class="block">' + o[0] + '</span><span class="block text-[11px] font-semibold text-slate-500">' + o[2] + '</span></span></label>').join('') + '</div></div>' +
                '<div id="resBlock" class="hidden space-y-3 rounded-2xl border border-emerald-200 bg-emerald-50/40 p-4"><div><label class="field-label">Kasunduan / Agreement *</label><textarea class="input" id="rDetails" rows="4" maxlength="10000" placeholder="Terms both parties agreed on, e.g. babayaran ni … ang halagang … sa loob ng … araw"></textarea><p class="text-[11px] text-slate-400 mt-1">Printed on the outcome for both parties to sign.</p></div>' +
                    '<p class="text-xs font-bold text-emerald-700">Resolved on: <span id="rResolvedAt"></span> <span class="font-semibold text-slate-400">(the moment you save)</span></p></div>' +
                '<div class="rounded-2xl border border-slate-100 p-4 space-y-3" id="perBox"><p class="section-title">Barangay officials / staff at the hearing</p>' +
                    '<div class="grid sm:grid-cols-2 gap-3"><div><label class="field-label" id="preLbl">Presiding official (sat with the parties)</label><select class="input" id="rPreSel">' + pOpt(P.findIndex(q => /captain|punong/i.test(q.position))) + '</select>' +
                        '<input class="input mt-2 hidden" id="rPreOther" maxlength="255" placeholder="Name"></div>' +
                    '<div><label class="field-label">Position</label><input class="input bg-slate-100" id="rPrePos" maxlength="150" readonly placeholder="Filled in from the selected person"></div></div>' +
                    '<p class="text-xs font-bold text-slate-500 hidden" id="rNone">None — no hearing took place, so no official sat with the parties.</p>' +
                    '<div id="perWrap"><label class="field-label">Other barangay personnel present (e.g. kagawad, lupon, tanod)</label><div class="grid sm:grid-cols-2 gap-1 max-h-48 overflow-auto" id="perList">' +
                        (P.length ? P.map((q, i) => '<label class="flex items-center gap-2 text-xs font-semibold text-slate-700 py-1"><input type="checkbox" data-per="' + i + '"> ' + esc(q.name) + ' <span class="text-slate-400">— ' + esc(q.position) + '</span></label>').join('') : '<p class="text-[11px] text-slate-400">No officials / staff on record.</p>') +
                    '</div></div></div>' +
                '<div><div class="flex flex-wrap items-center justify-between gap-2"><label class="field-label !mb-0" id="remLbl">Remarks / what was discussed</label>' +
                    '<div class="flex items-center gap-3 text-[11px] font-bold text-slate-500" title="Language of the remark filled in automatically for a no-show">Auto remark in: ' +
                    '<label class="flex items-center gap-1"><input type="radio" name="rLang" value="en">English</label><label class="flex items-center gap-1"><input type="radio" name="rLang" value="tl">Tagalog</label></div></div>' +
                    '<textarea class="input mt-1" id="rRem" rows="3" maxlength="5000"></textarea></div>' +
                // Limit reached with this result → For Transfer: optional letter for the transfer (chosen here, made as a Draft).
                '<div class="hidden rounded-2xl border border-rose-200 bg-rose-50/40 p-4 space-y-4" id="trBox"><p class="section-title !text-rose-700">For Transfer</p><p class="text-xs font-semibold text-slate-600" id="trNote"></p>' +
                    ((D.letter_templates || []).length ? '<div><label class="field-label">Letter to the complainant and the respondent? *</label><div class="grid sm:grid-cols-2 gap-2">' +
                        '<label class="choice"><input type="radio" name="trLetter" value="0" checked><span>Later</span></label><label class="choice"><input type="radio" name="trLetter" value="1"><span>Yes, generate now</span></label></div></div>' +
                        '<div id="trForm" class="hidden space-y-4">' + NoticeForm.html([], 'Letter to the complainant and the respondent', false) + '<p class="text-[11px] text-slate-400">One letter per person (Draft — check it, then Save; then Mark Issued with the signed copy). After all are issued, the letter for the transfer is made from Case Details.</p></div>'
                      : '<p class="text-[11px] text-slate-400">No letter template yet (Notice Templates).</p>') + '</div>' +
                '<div class="note note-warn hidden" id="rProofNote"><span class="material-symbols-outlined">info</span><span></span></div>' +
                '<p class="text-[11px] text-slate-400" id="ruleHint"></p>',
            mounted: root => {
                const $ = q => root.querySelector(q);
                // Live clock: current time, early / late, resolution time.
                const tick = () => { if (!root.isConnected) return clearInterval(timer);
                    $('#rNow').textContent = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' });
                    const t = timing(); $('#rTiming').textContent = t[0]; $('#rTiming').className = 'font-black ' + t[1];
                    $('#rResolvedAt').textContent = nowLabel(); };
                const timer = setInterval(tick, 1000); tick();
                const preSel = $('#rPreSel'), prePos = $('#rPrePos'), preOther = $('#rPreOther');
                const isNone = () => (root.querySelector('input[name=outcome]:checked') || {}).value === 'Both No Show';
                const syncPre = () => {
                    const v = preSel.value, other = v === 'other';
                    preOther.classList.toggle('hidden', !other);
                    prePos.readOnly = !other; prePos.classList.toggle('bg-slate-100', !other);
                    if (!other) prePos.value = v !== '' ? P[Number(v)].position : '';
                    root.querySelectorAll('[data-per]').forEach(c => { const me = c.dataset.per === v; c.disabled = me || isNone(); if (me) c.checked = false; });
                };
                // Present without a proof of receipt: a note only (never required).
                const proofNote = () => {
                    const list = Array.from(root.querySelectorAll('[data-att]')).filter(b => { const inp = b.querySelector('[data-proof]');
                        return noProofOf(Number(b.dataset.att)) && b.querySelector('[data-present]').checked && !(inp && inp.files[0]); }).map(b => D.parties.find(p => p.id === Number(b.dataset.att)).label);
                    $('#rProofNote').classList.toggle('hidden', !list.length);
                    $('#rProofNote span:last-child').textContent = 'Marked present without a proof of receipt: ' + list.join(', ') + '. This is allowed — the letter may have been received at the last minute (attach the signed copy beside the name if you have it).';
                };
                const setAtt = (box, present) => {
                    box.querySelector('[data-present]').checked = present;
                    box.querySelector('[data-reason-wrap]').classList.toggle('hidden', present);
                    box.querySelector('[data-state]').textContent = present ? 'Present' : 'Absent';
                    box.querySelector('[data-state]').style.color = present ? '#047857' : '#b91c1c';
                };
                const addComp = (name, withP) => {
                    const row = document.createElement('div'); row.className = 'grid grid-cols-[1fr_1fr_auto] gap-2'; row.dataset.comp = '1';
                    row.innerHTML = '<input class="input" maxlength="150" placeholder="Name of companion" data-cn><select class="input" data-cw><option value="">Came with…</option>' + D.parties.map(p => '<option>' + esc(p.label) + ' (' + p.role + ')</option>').join('') + '</select><button type="button" class="icon-btn danger" title="Remove"><span class="material-symbols-outlined">close</span></button>';
                    row.querySelector('button').onclick = () => { row.remove(); save(); };
                    $('#compList').appendChild(row);
                    if (name !== undefined) { row.querySelector('[data-cn]').value = name; row.querySelector('[data-cw]').value = withP || ''; } else row.querySelector('[data-cn]').focus();
                };
                /* ── automatic remark for a no-show: names who did not appear (English or Tagalog) ── */
                const lang = () => (root.querySelector('input[name=rLang]:checked') || {}).value || 'en';
                const absent = role => { const all = Array.from(root.querySelectorAll('[data-att]')).map(b => ({ p: D.parties.find(p => p.id === Number(b.dataset.att)), present: b.querySelector('[data-present]').checked, reason: b.querySelector('[data-reason]').value.trim() })).filter(a => a.p.role === role);
                    const out = all.filter(a => !a.present); return out.length ? out : all; };
                const join = (arr, and) => arr.length < 2 ? arr.join('') : arr.slice(0, -1).join(', ') + ' ' + and + ' ' + arr[arr.length - 1];
                const who = (list, tl) => join(list.map(a => a.p.label + (a.reason ? ' (' + (tl ? 'dahilan: ' : 'reason: ') + a.reason + ')' : '')), tl ? 'at' : 'and');
                const when = x.date_label + ' ' + x.time_label;
                const autoRemark = o => {
                    const tl = lang() === 'tl', c = absent('Complainant'), r = absent('Respondent');
                    // What happens next (hearing-based): the last allowed hearing → for transfer; otherwise the next hearing.
                    const counts = o === 'Unresolved' || (o.includes('No Show') && D.noshow_counts);
                    const tail = !counts || L0.rule !== 'hearing' ? '' : toTransfer(o)
                        ? (tl ? ' Ito na ang huling pagdinig na pinapayagan (' + (L0.used + 1) + ' sa ' + L0.max + '), kaya ililipat na ang kaso (for transfer) sa ' + D.case.transfer_destination + '.'
                              : ' This was the last hearing allowed (' + (L0.used + 1) + ' of ' + L0.max + '), so the case is now for transfer to ' + D.case.transfer_destination + '.')
                        : (tl ? ' Itutuloy ang kaso sa susunod na pagdinig (' + (L0.used + 1) + ' sa ' + L0.max + ' na pagdinig ang nagamit na).'
                              : ' The case goes to the next hearing (' + (L0.used + 1) + ' of ' + L0.max + ' hearings used).');
                    if (o === 'Resolved') return tl
                        ? 'Dumalo ang nagrereklamo (' + who(c, 1) + ') at ang inirereklamo (' + who(r, 1) + ') sa pagdinig noong ' + when + ' at nagkasundo sila nang maayos. Nakasulat sa kasunduan ang kanilang napagkasunduan.'
                        : 'The complainant (' + who(c) + ') and the respondent (' + who(r) + ') appeared at the hearing on ' + when + ' and settled the complaint amicably. The terms are written in the agreement (kasunduan).';
                    if (o === 'Unresolved') return (tl
                        ? 'Dumalo ang nagrereklamo (' + who(c, 1) + ') at ang inirereklamo (' + who(r, 1) + ') sa pagdinig noong ' + when + ' ngunit hindi sila nagkasundo.'
                        : 'The complainant (' + who(c) + ') and the respondent (' + who(r) + ') appeared at the hearing on ' + when + ' but did not reach a settlement.') + tail;
                    return noShow(o, tl, c, r) + (noShow(o, tl, c, r) ? tail : '');
                };
                const noShow = (o, tl, c, r) => {
                    if (o === 'Respondent No Show') return tl
                        ? 'Hindi dumalo ' + (r.length > 1 ? 'ang mga inirereklamo (respondents) na sina ' : 'ang inirereklamo (respondent) na si ') + who(r, 1) + ' sa pagdinig noong ' + when + '. Dumalo ang nagrereklamo (complainant).'
                        : 'The respondent' + (r.length > 1 ? 's, ' : ', ') + who(r) + ', did not appear at the hearing on ' + when + '. The complainant was present.';
                    if (o === 'Complainant No Show') return tl
                        ? 'Hindi dumalo ' + (c.length > 1 ? 'ang mga nagrereklamo (complainants) na sina ' : 'ang nagrereklamo (complainant) na si ') + who(c, 1) + ' sa pagdinig noong ' + when + '. Dumalo ang inirereklamo (respondent).'
                        : 'The complainant' + (c.length > 1 ? 's, ' : ', ') + who(c) + ', did not appear at the hearing on ' + when + '. The respondent was present.';
                    if (o === 'Both No Show') return tl
                        ? 'Walang naganap na pagdinig noong ' + when + ': hindi dumalo ang nagrereklamo (' + who(c, 1) + ') at ang inirereklamo (' + who(r, 1) + '), kaya walang naganap na pamamagitan (mediation) at walang opisyal na humarap sa mga partido.'
                        : 'No hearing took place on ' + when + ': neither the complainant (' + who(c) + ') nor the respondent (' + who(r) + ') appeared, so there was no mediation and no official sat with the parties.';
                    return '';
                };
                const fillRemark = () => {
                    const o = (root.querySelector('input[name=outcome]:checked') || {}).value || '';
                    const rem = $('#rRem'), auto = autoRemark(o);
                    if (auto && (!rem.value.trim() || rem.dataset.auto === '1')) { rem.value = auto; rem.dataset.auto = '1'; }
                    else if (!auto && rem.dataset.auto === '1') { rem.value = ''; delete rem.dataset.auto; }
                };
                const L0 = D.case.limit;
                // Hearing-based only (time-based: Extend or For Transfer is decided in Case Details when the days end).
                const toTransfer = o => !!o && o !== 'Resolved' && L0.rule === 'hearing' && (o === 'Unresolved' || (o.includes('No Show') && D.noshow_counts)) && L0.used + 1 >= L0.max;
                root._toTransfer = () => toTransfer((root.querySelector('input[name=outcome]:checked') || {}).value || '');
                const syncTransfer = () => {
                    const on = root._toTransfer();
                    $('#trBox').classList.toggle('hidden', !on);
                    $('#trNote').textContent = on ? (L0.rule === 'hearing' ? 'This is the last hearing allowed (' + (L0.used + 1) + ' of ' + L0.max + ').' : 'The resolution period has ended.') + ' The case goes to For Transfer → ' + D.case.transfer_destination + '.' : '';
                    const f = root.querySelector('#trForm'); if (f) f.classList.toggle('hidden', !(on && (root.querySelector('input[name=trLetter]:checked') || {}).value === '1'));
                };
                const syncOutcome = (fromUser) => {
                    const o = (root.querySelector('input[name=outcome]:checked') || {}).value || '';
                    const res = o === 'Resolved', ns = o.includes('No Show'), none = o === 'Both No Show';
                    $('#resBlock').classList.toggle('hidden', !res);
                    $('#remLbl').textContent = res ? 'Remarks / what was discussed' : 'Reason / remarks *';
                    $('#preLbl').textContent = 'Presiding official (sat with the parties)' + (none ? '' : ' *');
                    // Both No Show: no hearing took place → presiding official "None". Every result fills in the remark (English / Tagalog).
                    preSel.disabled = none; preOther.disabled = none; $('#rNone').classList.toggle('hidden', !none);
                    if (fromUser) fillRemark();
                    syncTransfer();
                    syncPre();
                    const L = D.case.limit;
                    $('#ruleHint').textContent = !o ? '' : res ? 'The case becomes Resolved and is closed automatically (view / print only); then print the outcome for signing and upload the signed copy.' :
                        (ns && !D.noshow_counts ? 'No-shows do not count toward the limit (Settings). The case goes to For Next Hearing.' :
                        (L.rule === 'hearing' ? 'This will be hearing ' + (L.used + 1) + ' of ' + L.max + '. ' + (L.used + 1 >= L.max ? 'The limit will be reached → For Transfer.' : 'The case goes to For Next Hearing.')
                                              : (L.days_left < 0 ? 'The days to settle the case have ended → after saving, choose Extend or For Transfer.' : L.days_left + ' day(s) left → For Next Hearing.')));
                };
                /* ── keep what was typed (localStorage) ── */
                const state = () => ({
                    att: Array.from(root.querySelectorAll('[data-att]')).map(b => [b.dataset.att, b.querySelector('[data-present]').checked ? 1 : 0, b.querySelector('[data-reason]').value]),
                    comp: Array.from(root.querySelectorAll('[data-comp]')).map(r => [r.querySelector('[data-cn]').value, r.querySelector('[data-cw]').value]),
                    outcome: (root.querySelector('input[name=outcome]:checked') || {}).value || '', details: $('#rDetails').value,
                    pre: preSel.value, preOther: preOther.value, prePos: prePos.value, per: Array.from(root.querySelectorAll('[data-per]:checked')).map(c => c.dataset.per),
                    rem: $('#rRem').value, remAuto: $('#rRem').dataset.auto || '', lang: lang() });
                const save = () => { try { localStorage.setItem(KEY, JSON.stringify(state())); } catch (e) {} };
                const restore = () => {
                    let st = null; try { st = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) {}
                    if (!st) return false;
                    (st.att || []).forEach(a => { const b = root.querySelector('[data-att="' + a[0] + '"]'); if (b) { setAtt(b, !!a[1]); b.querySelector('[data-reason]').value = a[2] || ''; } });
                    (st.comp || []).forEach(c => addComp(c[0], c[1]));
                    if (st.outcome) { const r = root.querySelector('input[name=outcome][value="' + st.outcome + '"]'); if (r) r.checked = true; }
                    $('#rDetails').value = st.details || ''; preSel.value = st.pre || ''; preOther.value = st.preOther || '';
                    syncPre(); if (st.pre === 'other') prePos.value = st.prePos || '';
                    (st.per || []).forEach(i => { const c = root.querySelector('[data-per="' + i + '"]'); if (c) c.checked = true; });
                    $('#rRem').value = st.rem || ''; if (st.remAuto) $('#rRem').dataset.auto = '1';
                    if (st.lang) root.querySelector('input[name=rLang][value="' + st.lang + '"]').checked = true;
                    return true;
                };
                root._clearSaved = () => { try { localStorage.removeItem(KEY); } catch (e) {} };
                /* ── events ── */
                preSel.addEventListener('change', syncPre);
                root.querySelectorAll('[data-proof]').forEach(inp => inp.addEventListener('change', () => {
                    inp.closest('[data-att]').querySelector('[data-proof-name]').textContent = inp.files[0] ? inp.files[0].name + ' (saved with the result)' : ''; proofNote(); }));
                root.querySelectorAll('[data-att]').forEach(box => box.querySelector('[data-present]').addEventListener('change', e => {
                    setAtt(box, e.target.checked);
                    const absent = r => Array.from(root.querySelectorAll('[data-att]')).filter(b => D.parties.find(p => p.id === Number(b.dataset.att)).role === r).every(b => !b.querySelector('[data-present]').checked);
                    const c = absent('Complainant'), rs = absent('Respondent');
                    const sug = c && rs ? 'Both No Show' : (c ? 'Complainant No Show' : (rs ? 'Respondent No Show' : ''));
                    if (sug) { root.querySelector('input[name=outcome][value="' + sug + '"]').checked = true; syncOutcome(true); }
                    else fillRemark();
                    proofNote();
                }));
                root.querySelectorAll('[data-reason]').forEach(i => i.addEventListener('input', fillRemark));
                root.querySelectorAll('input[name=rLang]').forEach(r => r.addEventListener('change', () => { try { localStorage.setItem('blt_remark_lang', r.value); } catch (e) {} fillRemark(); }));
                root.querySelectorAll('input[name=trLetter]').forEach(r => r.addEventListener('change', syncTransfer));
                if (root.querySelector('#trForm')) NoticeForm.mount(root, 0, undefined, true);
                $('#compAdd').addEventListener('click', () => addComp());
                root.querySelectorAll('input[name=outcome]').forEach(r => r.addEventListener('change', () => syncOutcome(true)));
                $('#rRem').addEventListener('input', () => { delete $('#rRem').dataset.auto; });
                root.addEventListener('input', save); root.addEventListener('change', save);
                $('#rClear').addEventListener('click', async () => {
                    if (!(await Ask({ title: 'Clear the result form?', message: 'Everything typed here (attendance, companions, result, remarks) will be removed so you can start over.', icon: 'restart_alt', ok: 'Clear', danger: true }))) return;
                    root._clearSaved(); Modal.close(); Actions.result();
                });
                { let l = 'en'; try { l = localStorage.getItem('blt_remark_lang') || 'en'; } catch (e) {} root.querySelector('input[name=rLang][value="' + (l === 'tl' ? 'tl' : 'en') + '"]').checked = true; }
                if (restore()) $('#rRestored').textContent = 'What you typed before was kept. Click Clear to start over.';
                syncOutcome(false); syncPre(); proofNote();
            },
            submit: async root => {
                const o = (root.querySelector('input[name=outcome]:checked') || {}).value;
                if (!o) return { success:false, message:'Choose the hearing result.' };
                const attendance = [];
                for (const box of root.querySelectorAll('[data-att]')) {
                    const present = box.querySelector('[data-present]').checked, reason = box.querySelector('[data-reason]').value.trim();
                    if (!present && !reason) { box.querySelector('[data-reason]').focus(); return { success:false, message:'Enter the reason why ' + D.parties.find(p => p.id === Number(box.dataset.att)).label + ' was absent.' }; }
                    attendance.push({ party_id: Number(box.dataset.att), present: present ? 1 : 0, reason: reason });
                }
                const none = o === 'Both No Show';
                const v = root.querySelector('#rPreSel').value;
                const presided = none ? 'None' : (v === 'other' ? val(root, '#rPreOther') : (v !== '' ? P[Number(v)].name : ''));
                if (!none && !presided) { root.querySelector('#rPreSel').focus(); return { success:false, message:'Select the presiding official (who sat with the parties).' }; }
                // Last-minute proofs: mark those letters issued first.
                for (const inp of root.querySelectorAll('[data-proof]')) {
                    if (!inp.files[0]) continue;
                    const fd = new FormData(); fd.append('action', 'issue'); fd.append('id', inp.dataset.proof); fd.append('remarks', 'Proof of receipt attached on the hearing day (Record Result).'); fd.append('signed_copy', inp.files[0], inp.files[0].name);
                    const r = await BLT.post('../backend/notices.php', fd);
                    if (!r.success) return r;
                }
                const companions = Array.from(root.querySelectorAll('[data-comp]')).map(r => ({ name: r.querySelector('[data-cn]').value.trim(), with: r.querySelector('[data-cw]').value })).filter(c => c.name);
                const personnel = none ? [] : Array.from(root.querySelectorAll('[data-per]:checked')).map(c => ({ name: P[Number(c.dataset.per)].name, position: P[Number(c.dataset.per)].position }));
                const data = { action: 'result', hearing_id: x.id, outcome: o, remarks: val(root, '#rRem'),
                    resolution_details: val(root, '#rDetails'),
                    attendance: attendance, companions: companions, presided_by: presided, presided_position: none ? '' : val(root, '#rPrePos'), personnel: personnel };
                // For Transfer: the letter chosen in that section (if any).
                if (root._toTransfer() && (root.querySelector('input[name=trLetter]:checked') || {}).value === '1') {
                    const nf = NoticeForm.read(root);
                    if (nf.error) return { success:false, message: nf.error };
                    if (!nf.template_id) return { success:false, message:'Choose the letter, or pick "Later".' };
                    NoticeForm.add(data, nf);
                }
                const r = await BLT.post('../backend/hearings.php', data);
                if (r && r.success) root._clearSaved();
                return r;
            },
            after: async r => { await load(); if (r && r.notice_ids && r.notice_ids.length) Notice.preview(r.notice_ids, { fresh: true }); } });
    },
    outcomeUpload(hid){
        const x = D.hearings.find(y => y.id === hid);
        Modal.open({ title: 'Signed Copies', sub: D.case.case_number + ' · Hearing #' + x.no, ok: 'Upload', icon: 'upload', width: 720,
            html: (x.files.length ? '<div class="space-y-2">' + x.files.map(f => '<div class="flex items-center gap-2 rounded-xl border border-slate-100 p-2"><span class="material-symbols-outlined text-slate-400">' + ((f.type || '').startsWith('image/') ? 'image' : 'description') + '</span>' +
                    '<a class="flex-1 text-sm font-bold text-indigo-700 truncate" target="_blank" href="' + esc(f.url) + '">' + esc(f.name) + '</a><span class="text-[10px] text-slate-400">' + esc(f.at) + '</span>' +
                    (f.id && D.can.delete ? '<button type="button" class="icon-btn danger" title="Remove" data-del="' + f.id + '"><span class="material-symbols-outlined">delete</span></button>' : '') + '</div>').join('') + '</div>' : '<p class="text-sm text-slate-400">No signed copy yet.</p>') +
                '<p class="text-sm text-slate-600">Add photos or scans of the printed outcome signed by the parties and ' + esc(x.presided_by || 'the presiding official') + ' — several files at once are allowed (e.g. one per person).</p>' +
                '<input type="file" id="soFile" multiple accept="image/jpeg,image/png,image/webp,application/pdf" class="text-sm"><p class="text-[11px] text-slate-400">JPG, PNG or PDF, up to 10 MB each.</p>',
            mounted: root => root.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', async () => {
                if (!(await Ask({ title: 'Remove this file?', icon: 'delete', danger: true, ok: 'Remove' }))) return;
                const r = await BLT.post('../backend/hearings.php', { action: 'delete_signed', file_id: b.dataset.del });
                BLT.toast(r.message, r.success ? 'success' : 'error'); Modal.close(); await load(); Actions.outcomeUpload(hid);
            })),
            submit: root => {
                const files = Array.from(root.querySelector('#soFile').files);
                if (!files.length) return { success:false, message:'Choose the signed copies.' };
                const big = files.find(f => f.size > 10 * 1048576);
                if (big) return { success:false, message:'"' + big.name + '" is larger than 10 MB.' };
                const fd = new FormData(); fd.append('action', 'upload_signed'); fd.append('hearing_id', hid);
                files.forEach(f => fd.append('signed_copy[]', f, f.name));
                return BLT.post('../backend/hearings.php', fd);
            } });
    },
    transfer(){
        const L = D.case.limit;
        Modal.open({ title: 'Transfer Case', sub: D.case.case_number + ' · ' + D.case.rule, ok: 'Mark as Transferred', icon: 'local_police', width: 720,
            html: '<div class="note note-warn"><span class="material-symbols-outlined">info</span><span>Mark the case <strong>Transferred</strong> only after it was actually handed over to the selected destination. The date and time are recorded the moment you save.</span></div>' +
                '<div class="rounded-2xl bg-slate-50 border border-slate-100 px-4 py-2 text-xs font-bold text-slate-600">Transfer date &amp; time (now): <span class="text-slate-800" id="tNow"></span></div>' +
                '<div class="grid sm:grid-cols-2 gap-4"><div><label class="field-label">Destination (Blotter Settings)</label><div class="input bg-slate-100 text-slate-600 flex items-center gap-2"><span class="material-symbols-outlined text-base">lock</span>' + esc(D.case.transfer_destination) + '</div></div>' +
                '<div><label class="field-label">Reference no. (letter for the transfer)</label><div class="input bg-slate-100 text-slate-600 font-mono flex items-center gap-2"><span class="material-symbols-outlined text-base">lock</span>' + esc(D.case.transfer_ref || '—') + '</div></div></div>' +
                '<div><label class="field-label">Reason *</label><textarea class="input" id="tReason" rows="3" maxlength="5000">' + esc(L.rule === 'time' ? 'No settlement within the ' + L.max + '-day period.' : L.used + ' hearing(s) held without settlement.') + '</textarea></div>' +
                '<p class="section-title">Additional information</p>' +
                '<div><label class="field-label">Received by *</label><input class="input" id="tOff" maxlength="150" placeholder="Name of the receiving officer"></div>' +
                '<div><label class="field-label">Receiving details</label><input class="input" id="tRecv" maxlength="500" placeholder="Office, station, desk officer, contact"></div>' +
                '<div><label class="field-label">Remarks</label><textarea class="input" id="tRem" rows="2" maxlength="5000"></textarea></div>' +
                '<div><label class="field-label">Proof that the case was transferred *</label><input type="file" id="tProof" accept="image/jpeg,image/png,image/webp,application/pdf" class="text-sm"><p class="text-[11px] text-slate-400 mt-1">Photo / scan of the receiving copy (stamped or signed by the destination) · JPG, PNG or PDF up to 10 MB.</p></div>',
            mounted: root => {
                const t = () => { const el = root.querySelector('#tNow'); if (!el || !root.isConnected) return clearInterval(tm); el.textContent = new Date().toLocaleString([], { dateStyle: 'long', timeStyle: 'medium' }); };
                const tm = setInterval(t, 1000); t();
            },
            submit: async root => {
                const f = root.querySelector('#tProof').files[0];
                if (!val(root, '#tOff')) { root.querySelector('#tOff').focus(); return { success:false, message:'Enter who received the case (Received by).' }; }
                if (!f) return { success:false, message:'Attach the proof that the case was received by the destination.' };
                if (f.size > 10 * 1048576) return { success:false, message:'The proof must be 10 MB or smaller.' };
                const ok = await BLT.confirm({ title: 'Mark ' + D.case.case_number + ' as Transferred?', message: 'Only if the case was already handed over. This is recorded on the timeline with the current date and time.', ok: 'Transferred', icon: 'local_police' });
                if (!ok) return false;
                const fd = new FormData();
                Object.entries({ action: 'transfer', case_id: CASE_ID, reason: val(root, '#tReason'),
                    remarks: val(root, '#tRem'), receiving_officer: val(root, '#tOff'), receiving_details: val(root, '#tRecv') }).forEach(([k, v]) => fd.append(k, v));
                fd.append('proof', f, f.name);
                return BLT.post('../backend/transfers.php', fd);
            } });
    },
    /** Time-based: the days ended → more days (countdown again from today). */
    extend(){
        Modal.open({ title: 'Extend the Period', sub: D.case.case_number, ok: 'Extend', icon: 'more_time',
            html: '<div><label class="field-label">Additional days *</label><input type="number" min="1" max="365" class="input" id="xDays" value="7"><p class="text-[11px] text-slate-400 mt-1" id="xNew"></p></div>' +
                '<div><label class="field-label">Reason *</label><textarea class="input" id="xReason" rows="3" maxlength="500" placeholder="e.g. The parties asked for more time to settle"></textarea></div>',
            mounted: root => { const u = () => { const n = Number(val(root, '#xDays')) || 0, d = new Date(); d.setDate(d.getDate() + n);
                root.querySelector('#xNew').textContent = n > 0 ? 'New deadline: ' + d.toLocaleDateString([], { month:'long', day:'numeric', year:'numeric' }) + ' (countdown starts again)' : ''; };
                root.querySelector('#xDays').addEventListener('input', u); u(); },
            submit: root => BLT.post(API, { action: 'extend', id: CASE_ID, days: val(root, '#xDays'), reason: val(root, '#xReason') }) });
    },
    forTransfer(){
        Modal.open({ title: 'For Transfer', sub: D.case.case_number, ok: 'Mark For Transfer', danger: true, icon: 'local_police',
            html: '<p class="text-sm text-slate-600">The case goes to <strong>For Transfer</strong> (to ' + esc(D.case.transfer_destination) + '). Then upload the transfer record signed by both parties and make the letter for the transfer — same steps as a hearing-based case.</p>' +
                '<div><label class="field-label">Remarks</label><textarea class="input" id="ftR" rows="2" maxlength="500"></textarea></div>',
            submit: root => BLT.post(API, { action: 'for_transfer', id: CASE_ID, reason: val(root, '#ftR') }) });
    },
    /** Everything about one hearing: schedule, letters, attendance, result, remarks, settlement, signed copies. */
    viewHearing(hid){
        const x = D.hearings.find(y => y.id === hid);
        const letters = D.notices.filter(n => n.hearing_id === hid);
        const res = D.resolution && Number(D.resolution.hearing_no) === x.no ? D.resolution : null;
        const sec = (t, b) => '<div><p class="section-title mb-2">' + t + '</p>' + b + '</div>';
        Modal.open({ title: 'Hearing #' + x.no, sub: D.case.case_number + ' · ' + x.date_label + ' ' + x.time_label, cancel: 'Close', width: 820,
            html: kv([['Date & time', x.date_label + ' · ' + x.time_label], ['Location', x.location], ['Purpose / agenda', x.agenda], ['Status', x.status + (x.cancel_reason ? ' — ' + x.cancel_reason : '')], ['Scheduled by', x.created_by]]) +
                sec('Letters / notices (' + letters.length + ')', letters.length ? '<div class="space-y-2">' + letters.map(n => '<div class="flex flex-wrap items-center gap-2 rounded-xl border border-slate-100 p-2"><span class="font-mono text-xs font-bold">' + esc(n.no) + '</span><span class="text-xs text-slate-600 flex-1">' + esc(n.type + ' → ' + n.recipient) + '</span>' + noticePill(n.status) +
                    '<button type="button" class="btn btn-ghost btn-sm" onclick="Modal.close();Notice.preview([' + n.id + '])"><span class="material-symbols-outlined">visibility</span>Preview</button></div>').join('') + '</div>' : '<p class="text-sm text-slate-400">No letter for this hearing.</p>') +
                (x.outcome ? sec('Result', kv([['Outcome', x.outcome + (x.auto ? ' (recorded automatically)' : '')], ['Presided by', x.presided_by ? x.presided_by + (x.presided_position ? ' — ' + x.presided_position : '') : ''], ['Recorded', x.result_at + ' · ' + (x.result_by || '')],
                        ['Remarks', x.result_remarks], ['Agreement / settlement', res ? res.details : ''], ['Resolved on', res ? res.resolved_at : '']])) +
                    (x.attendance.length ? sec('Attendance', '<div class="space-y-1">' + x.attendance.map(a => '<p class="text-xs"><span class="font-bold ' + (a.present ? 'text-emerald-700' : 'text-rose-700') + '">' + (a.present ? 'Present' : 'Absent') + '</span> · ' + esc(a.name) + ' <span class="text-slate-400">(' + esc(a.role) + ')</span>' + (a.reason ? ' — ' + esc(a.reason) : '') + '</p>').join('') + '</div>') : '') +
                    (x.companions.length ? sec('Companions', '<p class="text-xs">' + x.companions.map(c => esc(c.name) + (c.with ? ' (with ' + esc(c.with) + ')' : '')).join(', ') + '</p>') : '') +
                    (x.personnel.length ? sec('Other barangay personnel present', '<p class="text-xs">' + x.personnel.map(q => esc(q.name) + (q.position ? ' (' + esc(q.position) + ')' : '')).join(', ') + '</p>') : '') +
                    sec('Signed copies (' + x.files.length + ')', x.files.length ? '<div class="space-y-1">' + x.files.map(f => '<a class="block text-xs font-bold text-indigo-700" target="_blank" href="' + esc(f.url) + '">' + esc(f.name) + '</a>').join('') + '</div>' : '<p class="text-sm text-slate-400">None uploaded.</p>')
                  : '<p class="text-sm text-slate-400">No result recorded' + (x.status === 'Scheduled' ? ' yet.' : '.') + '</p>'),
            extraButtons: x.outcome && !x.auto ? '<button type="button" class="btn btn-ghost" onclick="openPrint(\'../backend/outcome_report.php?mode=print&hearing_id=' + x.id + '\')"><span class="material-symbols-outlined">print</span>Print outcome</button>' : '' });
    },
    close(){
        Modal.open({ title: 'Close Case', sub: D.case.case_number, ok: 'Close Case', green: true, icon: 'task_alt',
            html: '<p class="text-sm text-slate-600">The case was resolved. Closing it ends the case (Resolved → Closed).</p><div><label class="field-label">Remarks</label><textarea class="input" id="cRem" rows="3" maxlength="2000"></textarea></div>',
            submit: root => BLT.post(API, { action: 'close', id: CASE_ID, remarks: val(root, '#cRem') }) });
    },
    cancel(){
        Modal.open({ title: 'Cancel / Withdraw Case', sub: D.case.case_number, ok: 'Confirm', danger: true, icon: 'block', cancel: 'Keep Case',
            html: '<div class="grid sm:grid-cols-2 gap-2"><label class="choice"><input type="radio" name="kind" value="Withdrawn" checked>Withdrawn by the complainant</label><label class="choice"><input type="radio" name="kind" value="Cancelled">Cancelled by the barangay</label></div>' +
                '<div><label class="field-label">Reason *</label><textarea class="input" id="kRe" rows="3" maxlength="500"></textarea></div><p class="text-[11px] text-slate-400">Scheduled hearings are cancelled. The Blotter ID and history are kept.</p>',
            submit: async root => {
                const kind = (root.querySelector('input[name=kind]:checked') || {}).value, reason = val(root, '#kRe');
                if (!reason) return { success:false, message:'Enter the reason.' };
                if (!(await BLT.confirm({ title: (kind === 'Withdrawn' ? 'Withdraw' : 'Cancel') + ' case ' + D.case.case_number + '?', message: 'The case will be marked ' + kind + ' and its scheduled hearings cancelled. This cannot be undone.', ok: kind === 'Withdrawn' ? 'Withdraw Case' : 'Cancel Case', danger: true, icon: 'block' }))) return false;
                return BLT.post(API, { action: 'cancel', id: CASE_ID, kind: kind, reason: reason });
            } });
    },
    respondent(pid){
        const p = pid ? D.parties.find(x => x.id === pid) : null;
        let form = null;
        Modal.open({ title: p ? 'Update Respondent' : 'Add Respondent', sub: D.case.case_number + ' — the Blotter ID stays the same', ok: 'Save', icon: 'save', width: 900,
            html: '<div id="rpForm"></div>' + (p ? '<p class="text-[11px] text-slate-400">The previous information is kept in the case history. A resident replaced here is no longer linked to this case. The reason is asked when you save.</p>' : ''),
            mounted: root => {
                // Residents already in the case (other than this respondent) are not offered.
                const others = () => D.parties.filter(x => !p || x.id !== p.id).map(x => x.resident_id).filter(Boolean);
                form = BLTParty.mount(root.querySelector('#rpForm'), { role: 'Respondent', api: API, exclude: others, value: p ? Object.assign({}, p, { party_type: p.type }) : null });
            },
            submit: async root => {
                const e = form.validate();
                if (e.length) return { success:false, message: 'Respondent: ' + e.join('\n') };
                if (!p) return BLT.post('../backend/respondents.php', { action: 'add', case_id: CASE_ID, party: form.get() });
                const a = await Ask({ title: 'Save changes to the respondent?', message: 'Recorded on the case timeline; the previous information is kept in the history.', icon: 'person_edit', ok: 'Save',
                    input: { label: 'Reason for the update *', placeholder: 'e.g. Wrong person entered / identified by the complainant through CCTV' } });
                if (!a) return false;
                return BLT.post('../backend/respondents.php', { action: 'update', id: p.id, party: form.get(), reason: a.value });
            } });
    },
    report(mode){
        const n = D.attachments.length, H = D.hearings;
        Modal.open({ title: mode === 'pdf' ? 'Save PDF' : 'Print', sub: D.case.case_number + ' · complaint record', ok: mode === 'pdf' ? 'Save PDF' : 'Print', icon: mode === 'pdf' ? 'picture_as_pdf' : 'print', width: 720,
            html: (H.length ? '<div class="space-y-2"><p class="text-sm text-slate-600">What to include?</p><div class="grid sm:grid-cols-2 gap-2">' +
                    '<label class="choice"><input type="radio" name="hs" value="none" checked><span><span class="block">Case record only</span><span class="block text-[11px] font-semibold text-slate-500">The complaint with the signature lines</span></span></label>' +
                    '<label class="choice"><input type="radio" name="hs" value="pick"><span><span class="block">With the hearings</span><span class="block text-[11px] font-semibold text-slate-500">Choose hearing 1, 2, 3… or all (up to the present hearing)</span></span></label></div>' +
                    '<div id="hsList" class="hidden rounded-2xl border border-slate-100 p-3 space-y-1"><label class="flex items-center gap-2 text-sm font-black text-slate-700"><input type="checkbox" id="hsAll" checked> All hearings (' + H.length + ')</label>' +
                    H.map(x => '<label class="flex items-center gap-2 text-xs font-semibold text-slate-700 pl-5"><input type="checkbox" data-hs="' + x.no + '" checked> Hearing #' + x.no + ' · ' + esc(x.date_label) + ' · ' + esc(x.outcome || x.status) + '</label>').join('') + '</div></div>' : '') +
                (D.resolution || D.transfers.length ? '<div class="rounded-2xl border border-slate-100 p-3 space-y-1">' +
                    (D.resolution ? '<label class="flex items-center gap-2 text-sm font-bold text-slate-700"><input type="checkbox" id="rpRes" checked> Include the settlement (kasunduan / agreement)</label>' : '') +
                    (D.transfers.length ? '<label class="flex items-center gap-2 text-sm font-bold text-slate-700"><input type="checkbox" id="rpTr" checked> Include the transfer details</label>' : '') + '</div>' : '') +
                '<p class="text-sm text-slate-600">Include the evidence files in the ' + (mode === 'pdf' ? 'PDF' : 'printout') + '?</p>' +
                '<div class="grid sm:grid-cols-2 gap-2"><label class="choice"><input type="radio" name="ev" value="1"' + (n ? ' checked' : ' disabled') + '><span><span class="block">Yes, include evidence</span><span class="block text-[11px] font-semibold text-slate-500">' + (n ? n + ' file(s): photos and PDF pages with their details / notes' : 'No evidence attached') + '</span></span></label>' +
                '<label class="choice"><input type="radio" name="ev" value="0"' + (n ? '' : ' checked') + '><span><span class="block">No, record only</span><span class="block text-[11px] font-semibold text-slate-500">Complaint record with the signature lines</span></span></label></div>' +
                '<p class="text-[11px] text-slate-400">Word, video and audio files cannot be printed; they are listed with a note.</p>',
            mounted: root => {
                const list = root.querySelector('#hsList'); if (!list) return;
                root.querySelectorAll('input[name=hs]').forEach(r => r.addEventListener('change', () => list.classList.toggle('hidden', r.value !== 'pick' || !r.checked)));
                const all = root.querySelector('#hsAll'), each = () => Array.from(root.querySelectorAll('[data-hs]'));
                all.addEventListener('change', () => each().forEach(c => c.checked = all.checked));
                each().forEach(c => c.addEventListener('change', () => { all.checked = each().every(x => x.checked); }));
            },
            submit: root => {
                const ev = (root.querySelector('input[name=ev]:checked') || {}).value || '0';
                let hs = '';
                if ((root.querySelector('input[name=hs]:checked') || {}).value === 'pick') {
                    const nos = Array.from(root.querySelectorAll('[data-hs]:checked')).map(c => c.dataset.hs);
                    if (!nos.length) return { success:false, message:'Choose at least one hearing (or "Case record only").' };
                    hs = root.querySelector('#hsAll').checked ? 'all' : nos.join(',');
                }
                const on = id => { const c = root.querySelector(id); return c && c.checked; };
                openPrint('../backend/case_report.php?mode=' + mode + '&evidence=' + ev + (hs ? '&hearings=' + hs : '') + (on('#rpRes') ? '&resolution=1' : '') + (on('#rpTr') ? '&transfer=1' : '') + '&id=' + CASE_ID);
                return { success: true };
            }, after: () => {} });
    },
    removeRespondent(pid){
        const p = D.parties.find(x => x.id === pid);
        Modal.open({ title: 'Remove respondent?', sub: D.case.case_number, ok: 'Remove', danger: true, icon: 'person_remove', cancel: 'Keep',
            html: '<p class="text-sm text-slate-600"><strong>' + esc(p.label) + '</strong> will be taken off this case (e.g. entered by mistake). The case is no longer linked to this person; the removal is kept in the timeline.</p>' +
                '<div><label class="field-label">Reason *</label><input class="input" id="rmR" maxlength="500"></div>',
            submit: root => BLT.post('../backend/respondents.php', { action: 'remove', id: pid, reason: val(root, '#rmR') }) });
    },
    evidence(){
        Modal.open({ title: 'Add Evidence', sub: D.case.case_number, ok: 'Upload', icon: 'upload',
            html: '<input type="file" id="evFiles" multiple accept="<?php echo h(blt_evidence_accept()); ?>" class="text-sm"><p class="text-[11px] text-slate-400">Photos, documents, videos or audio · up to 10 MB each.</p><div><label class="field-label">Description (optional)</label><input class="input" id="evDesc" maxlength="255"></div>',
            submit: root => {
                const files = root.querySelector('#evFiles').files;
                if (!files.length) return { success:false, message:'Choose at least one file.' };
                const big = Array.from(files).find(f => f.size > 10 * 1048576);
                if (big) return { success:false, message:'"' + big.name + '" is larger than 10 MB.' };
                const fd = new FormData(); fd.append('action', 'add'); fd.append('case_id', CASE_ID);
                Array.from(files).forEach(f => { fd.append('attachments[]', f, f.name); fd.append('attachment_desc[]', val(root, '#evDesc')); });
                return BLT.post('../backend/attachments.php', fd);
            } });
    },
    delEvidence(aid){
        const a = D.attachments.find(x => x.id === aid);
        Modal.open({ title: 'Remove evidence?', sub: a.name, ok: 'Remove', danger: true, icon: 'delete', cancel: 'Keep',
            html: '<p class="text-sm text-slate-600">The file is hidden from the case but kept in the records and the timeline.</p><div><label class="field-label">Reason *</label><input class="input" id="dr" maxlength="500"></div>',
            submit: root => BLT.post('../backend/attachments.php', { action: 'delete', id: aid, reason: val(root, '#dr') }) });
    },
};

/* ───────── Signed case documents: the complaint record and the transfer record ───────── */
const Docs = {
    sign(kind){
        const t = kind === 'transfer_record' ? 'Transfer Record' : 'Complaint Record';
        Modal.open({ title: 'Mark ' + t + ' Signed', sub: D.case.case_number, ok: 'Mark Signed', icon: 'verified',
            html: '<p class="text-sm text-slate-600">' + (kind === 'transfer_record' ? 'Upload the printed record (e.g. with all hearings) <strong>signed by the complainant and the respondent</strong>. It is needed before the case can be transferred.' : 'Upload the printed complaint record <strong>signed by the complainant</strong>. Hearings can be added after this.') + '</p>' +
                '<div><label class="field-label">Signed copy *</label><input type="file" id="dFile" accept="image/jpeg,image/png,image/webp,application/pdf" class="text-sm"><p class="text-[11px] text-slate-400 mt-1">Photo / scan · JPG, PNG or PDF up to 10 MB.</p></div>' +
                '<div><label class="field-label">Remarks</label><input class="input" id="dRem" maxlength="500"></div>' +
                '<div class="note note-warn"><span class="material-symbols-outlined">lock</span><span>Once marked signed it can no longer be replaced.</span></div>',
            submit: root => {
                const f = root.querySelector('#dFile').files[0];
                if (!f) return { success:false, message:'Attach the signed copy.' };
                if (f.size > 10 * 1048576) return { success:false, message:'The file must be 10 MB or smaller.' };
                const fd = new FormData(); fd.append('action', 'sign_doc'); fd.append('id', CASE_ID); fd.append('kind', kind); fd.append('remarks', val(root, '#dRem')); fd.append('signed_copy', f, f.name);
                return BLT.post(API, fd);
            } });
    },
};

/* ───────── Notice type + recipients + extra information (Add Hearing and Generate Notice) ───────── */
const NoticeForm = {
    /** optional = a "No letter" choice (Add Hearing / Reschedule / Cancel); label = heading. */
    html(types, label, optional){
        const T = D.letter_templates || [];
        const groups = {};
        T.forEach(t => (groups[t.type] = groups[t.type] || []).push(t));
        return '<div><label class="field-label" for="ntype">' + esc(label || 'Letter to generate') + ' *</label><select class="input" id="ntype">' +
                (optional !== false ? '<option value="">No letter</option>' : '') +
                Object.keys(groups).map(g => '<optgroup label="' + esc(g) + '">' + groups[g].map(t => '<option value="' + t.id + '">' + esc(t.type + ' — ' + t.name) + (t.is_default ? ' (default)' : '') + (t.extras.length ? ' · ' + t.extras.length + ' extra info' : '') + '</option>').join('') + '</optgroup>').join('') +
                '</select><p class="text-[11px] text-slate-400 mt-1">Choose the exact template — its Extra information is asked below.</p></div>' +
            '<div data-nf><label class="field-label">Recipients *</label><div class="space-y-2" id="nRec"></div>' +
                '<div class="grid sm:grid-cols-2 gap-2 mt-2"><label class="choice"><input type="radio" name="nCopy" value="each" checked><span><span class="block">One letter each</span><span class="block text-[11px] font-semibold text-slate-500">Issued and signed per person</span></span></label>' +
                '<label class="choice"><input type="radio" name="nCopy" value="joint"><span><span class="block">One copy for all chosen</span><span class="block text-[11px] font-semibold text-slate-500">e.g. 1 copy for both parties — one Mark Issued</span></span></label></div></div>' +
            '<div id="nExtra" class="space-y-3" data-nf></div>';
    },
    mount(root, hearingId, preselect, allParties){
        // People who already have a letter for this hearing cannot get another one.
        const has = hearingId ? [].concat(...D.notices.filter(n => n.hearing_id === hearingId && n.purpose === 'hearing' && n.status !== 'Cancelled').map(n => n.party_ids || [n.party_id])) : [];
        const parties = D.parties;
        const tplOf = id => (D.letter_templates || []).find(t => t.id === Number(id));
        const defaultFor = t => !allParties && /summon/i.test(t ? t.type : '') ? parties.filter(p => p.role === 'Respondent') : parties;
        const sel = root.querySelector('#ntype');
        if (preselect !== undefined) sel.value = preselect;
        else { const d = (D.letter_templates || []).find(t => t.is_default); if (d) sel.value = d.id; }
        const typed = {};   // keep what was typed when switching template
        root.addEventListener('input', e => { if (e.target.dataset && e.target.dataset.x) typed[e.target.dataset.x] = e.target.value; });
        const draw = () => {
            const t = tplOf(sel.value);
            root.querySelectorAll('[data-nf]').forEach(el => el.style.display = t ? '' : 'none');
            if (!t) return;
            const def = defaultFor(t).map(p => p.id);
            root.querySelector('#nExtra').innerHTML = t.extras.length ? '<div class="rounded-2xl border border-violet-100 bg-violet-50/40 p-4 space-y-3"><p class="section-title">Extra information for this letter</p>' +
                '<div class="grid sm:grid-cols-2 gap-3">' + t.extras.map(x => '<div><label class="field-label">' + esc(x.label) + ' *</label><input class="input" maxlength="500" data-x="' + esc(x.key) + '" value="' + esc(typed[x.key] || '') + '"></div>').join('') + '</div></div>' : '';
            root.querySelector('#nRec').innerHTML = parties.map(p => { const done = has.includes(p.id);
                return '<label class="choice' + (done ? ' opacity-60' : '') + '"><input type="checkbox" value="' + p.id + '"' + (done ? ' disabled' : (def.includes(p.id) ? ' checked' : '')) + '><span><span class="block">' + esc(p.label) + '</span><span class="block text-[11px] font-semibold text-slate-500">' + esc(p.role) + (done ? ' · already has a letter for this hearing' : (p.address ? ' · ' + esc(p.address) : '')) + '</span></span></label>'; }).join('');
        };
        sel.addEventListener('change', draw); draw();
    },
    /** { template_id: 0 = no letter } */
    read(root){
        const tid = Number(root.querySelector('#ntype').value || 0);
        if (!tid) return { template_id: 0 };
        const recipients = Array.from(root.querySelectorAll('#nRec input:checked:not(:disabled)')).map(i => Number(i.value));
        if (!recipients.length) return { error: 'Choose at least one recipient of the letter.' };
        const extra = {};
        for (const inp of root.querySelectorAll('#nExtra [data-x]')) {
            if (!inp.value.trim()) { inp.focus(); return { error: 'Enter: ' + inp.closest('div').querySelector('label').textContent.replace(' *', '') + '.' }; }
            extra[inp.dataset.x] = inp.value.trim();
        }
        const joint = (root.querySelector('input[name=nCopy]:checked') || {}).value === 'joint' && recipients.length > 1;
        return { template_id: tid, recipients, extra, joint };
    },
    /** Adds the chosen letter to the POST data (nothing when "No letter"). */
    add(data, nf){ if (nf.template_id) Object.assign(data, { template_id: nf.template_id, recipients: nf.recipients, extra: nf.extra, joint: nf.joint ? 1 : 0 }); return data; },
};

/* ───────── Notices: Generate (once per hearing) → Preview → Edit / Print / Save PDF → Issue ───────── */
const Notice = {
    generate(hearingId){
        const x = hearingId ? D.hearings.find(h => h.id === hearingId) : D.pending_hearing, types = D.can.notice_types || [];
        if (!x) { BLT.toast('No scheduled hearing.', 'error'); return; }
        // Only reached from "Back" in a Draft preview (choose the letter again); letters are otherwise chosen inside the hearing flows.
        Modal.open({ title: 'Choose the Letter Again', sub: D.case.case_number + ' · Hearing #' + x.no + ' · ' + x.date_label + ' ' + x.time_label, ok: 'Generate & Preview', icon: 'description', width: 720,
            html: NoticeForm.html(types, 'Letter to generate', false) + '<p class="text-[11px] text-slate-400">Only for people of this hearing who have no letter yet (one each, never a duplicate). Changes are made with <strong>Edit</strong> in the preview.</p>',
            mounted: root => NoticeForm.mount(root, x.status === 'Completed' ? 0 : x.id),
            submit: root => {
                const nf = NoticeForm.read(root);
                if (nf.error) return { success:false, message: nf.error };
                if (!nf.template_id) return { success:false, message:'Choose the letter.' };
                return BLT.post('../backend/notices.php', NoticeForm.add({ action: 'generate', hearing_id: x.id }, nf));
            },
            after: r => { load(); Notice.preview(r.ids, { fresh: true }); } });
    },
    /** For Transfer, step 1: a letter to the complainant AND the respondent (one each); each is marked issued with the signed copy. */
    transferLetter(){
        Modal.open({ title: 'Letters to the Parties', sub: D.case.case_number + ' · For Transfer', ok: 'Generate & Preview', icon: 'description', width: 720,
            html: NoticeForm.html([], 'Letter to the complainant and the respondent', false) + '<p class="text-[11px] text-slate-400">One letter per person. After each is signed, click <strong>Mark Issued</strong> in its preview and attach the signed copy. When all are issued, the letter for the transfer can be made.</p>',
            mounted: root => NoticeForm.mount(root, 0, undefined, true),
            submit: root => {
                const nf = NoticeForm.read(root);
                if (nf.error) return { success:false, message: nf.error };
                if (!nf.template_id) return { success:false, message:'Choose the letter.' };
                return BLT.post('../backend/notices.php', NoticeForm.add({ action: 'generate', purpose: 'transfer_party', case_id: CASE_ID }, nf));
            },
            after: async r => { await load(); Notice.preview(r.ids, { fresh: true }); } });
    },
    /** For Transfer, step 2: THE letter for the transfer, to the receiving office (template from Blotter Settings; no recipient to choose). */
    officeLetter(){
        const t = D.can.transfer_template;
        if (!t) { BLT.toast('Choose the transfer letter template in Blotter Settings first.', 'error'); return; }
        Modal.open({ title: 'Letter for the Transfer', sub: D.case.case_number + ' · to ' + D.case.transfer_destination, ok: 'Generate & Preview', icon: 'local_police', width: 640,
            html: kv([['To', D.case.transfer_destination], ['Template', t.name + ' (Blotter Settings)']]) +
                (t.extras.length ? '<div class="rounded-2xl border border-violet-100 bg-violet-50/40 p-4 space-y-3"><p class="section-title">Extra information for this letter</p><div class="grid sm:grid-cols-2 gap-3">' +
                    t.extras.map(x => '<div><label class="field-label">' + esc(x.label) + ' *</label><input class="input" maxlength="500" data-ox="' + esc(x.key) + '"></div>').join('') + '</div></div>' : '') +
                '<p class="text-[11px] text-slate-400">Check it in the preview, then click Save — the <strong>Transfer Case</strong> button opens after that.</p>',
            submit: root => {
                const extra = {};
                for (const inp of root.querySelectorAll('input[data-ox]')) { if (!inp.value.trim()) { inp.focus(); return { success:false, message:'Enter: ' + inp.closest('div').querySelector('label').textContent.replace(' *', '') + '.' }; } extra[inp.dataset.ox] = inp.value.trim(); }
                return BLT.post('../backend/notices.php', { action: 'generate', purpose: 'transfer_office', case_id: CASE_ID, extra: extra });
            },
            after: async r => { await load(); Notice.preview(r.ids, { fresh: true }); } });
    },
    async preview(ids, opt){
        opt = opt || {};
        const models = [];
        for (const id of ids) { const d = await BLT.getJSON('../backend/notices.php?action=model&id=' + id); if (d.success) models.push(d); }
        if (!models.length) { BLT.toast('Notice not found.', 'error'); return; }
        let i = 0;
        const root = Modal.open({ title: 'Notice Preview', sub: D.case.case_number, width: 900, cancel: 'Close',
            html: '<div class="flex flex-wrap items-center justify-between gap-2"><p class="text-sm font-bold text-slate-700" id="pvTitle"></p><div class="flex items-center gap-2"><span id="pvZoom"></span>' + (models.length > 1 ? '<button type="button" class="icon-btn" id="pvPrev"><span class="material-symbols-outlined">chevron_left</span></button><button type="button" class="icon-btn" id="pvNext"><span class="material-symbols-outlined">chevron_right</span></button>' : '') + '</div></div>' +
                '<p class="text-[11px] text-slate-500" id="pvInfo"></p><div id="pvProof"></div><div class="notice-host" id="pvHost" style="max-height:62vh"></div>',
            extraButtons: '<button type="button" class="btn btn-ghost mr-auto" id="pvBack" title="Wrong letter? Discard it and choose again"><span class="material-symbols-outlined">arrow_back</span>Back</button>' +
                '<button type="button" class="btn btn-ghost" id="pvEdit"><span class="material-symbols-outlined">edit</span>Edit</button>' +
                '<button type="button" class="btn btn-dark" id="pvSave"><span class="material-symbols-outlined">save</span>Save</button>' +
                '<button type="button" class="btn btn-ghost" id="pvIssue"><span class="material-symbols-outlined">mark_email_read</span>Mark Issued</button>' +
                '<button type="button" class="btn btn-ghost" id="pvPdf"><span class="material-symbols-outlined">picture_as_pdf</span>Save PDF</button><button type="button" class="btn btn-dark" id="pvPrint"><span class="material-symbols-outlined">print</span>Print</button>' });
        let zoom = 1;
        const show = () => {
            const m = models[i];
            root.querySelector('#pvTitle').textContent = m.notice.type + ' · ' + m.notice.no + ' · ' + m.notice.recipient + (models.length > 1 ? ' (' + (i + 1) + ' of ' + models.length + ')' : '') + (m.notice.status === 'Cancelled' ? ' — CANCELLED' : '');
            // Edit / Mark Issued only while the notice is not issued yet; Print is always available (e.g. a spoiled printout).
            const canEdit = D.can.update && m.notice.editable;
            // Draft (just generated): only Back / Edit / Save. Saved (Generated): Edit / Print / Save PDF / Mark Issued.
            const draft = models.some(x => x.notice.is_draft);
            root.querySelector('#pvEdit').classList.toggle('hidden', !canEdit);
            root.querySelector('#pvBack').classList.toggle('hidden', !(draft && D.can.update));
            root.querySelector('#pvSave').classList.toggle('hidden', !(draft && D.can.update));
            ['#pvIssue', '#pvPrint', '#pvPdf'].forEach(q => root.querySelector(q).classList.toggle('hidden', draft || (q === '#pvIssue' && !canEdit)));
            root.querySelector('#pvInfo').textContent = (m.notice.is_draft ? 'DRAFT — check the letter, then click Save (Back = wrong letter, choose again). ' : '') + (m.notice.template_name ? 'Template: ' + m.notice.template_name + '. ' : '') + (m.notice.status === 'Issued' ? 'Issued — it can no longer be edited. ' : '') + (m.notice.status === 'Unserved' ? 'Unserved — the hearing passed without a returned signed copy. ' : '') + (m.notice.edited ? 'Edited ' + m.notice.edited + ' (this notice only).' : '');
            // Issued: the proof of receipt (signed copy) is shown here.
            const pr = root.querySelector('#pvProof');
            pr.innerHTML = m.notice.status === 'Issued' ? '<div class="proof-box space-y-2"><p class="text-xs font-black text-emerald-800 flex items-center gap-1"><span class="material-symbols-outlined text-base">verified</span>Issued ' + esc(m.notice.issued || '') + '</p>' +
                (m.notice.issue_remarks ? '<p class="text-xs text-emerald-900">' + esc(m.notice.issue_remarks) + '</p>' : '') +
                (m.notice.has_signed ? ((m.notice.signed_type || '').startsWith('image/') ? '<a target="_blank" href="../backend/' + esc(m.notice.signed_url) + '"><img src="../backend/' + esc(m.notice.signed_url) + '" alt="Proof of receipt"></a>' : '') +
                    '<a class="btn btn-ghost btn-sm" target="_blank" href="../backend/' + esc(m.notice.signed_url) + '"><span class="material-symbols-outlined">attach_file</span>Proof of receipt: ' + esc(m.notice.signed_name || 'file') + '</a>' : '<p class="text-[11px] text-emerald-800">No proof attached.</p>') + '</div>' : '';
            NoticeRender.into(root.querySelector('#pvHost'), m.model, { width: Math.min(840, root.querySelector('#pvHost').clientWidth - 28), zoom: zoom, noResize: true });
        };
        root.querySelector('#pvZoom').appendChild(NoticeRender.zoomBar(z => { zoom = z; show(); }, 1));
        if (models.length > 1) { root.querySelector('#pvPrev').onclick = () => { i = (i - 1 + models.length) % models.length; show(); }; root.querySelector('#pvNext').onclick = () => { i = (i + 1) % models.length; show(); }; }
        const idList = models.map(m => m.notice.id).join(',');
        // Issued with a proof of receipt → ask whether to include it (print: own pages; PDF: separate file).
        const out = async mode => {
            let proof = '0';
            if (models.some(m => m.notice.status === 'Issued' && m.notice.has_signed)) {
                const a = await Ask({ title: mode === 'pdf' ? 'Save PDF' : 'Print', icon: mode === 'pdf' ? 'picture_as_pdf' : 'print', ok: mode === 'pdf' ? 'Save PDF' : 'Print',
                    choices: [['1', 'Include the proof of receipt', mode === 'pdf' ? 'The proof is downloaded as its own file, next to the letter PDF.' : 'Printed after the letter, on its own page(s).'], ['0', 'Letter only', '']] });
                if (!a) return;
                proof = a.choice;
            }
            openPrint('../backend/print_notice.php?mode=' + mode + '&proof=' + proof + '&ids=' + idList);
        };
        root.querySelector('#pvPrint').onclick = () => out('print');
        root.querySelector('#pvSave').onclick = async () => {
            const r = await BLT.post('../backend/notices.php', { action: 'save', ids: models.filter(m => m.notice.is_draft).map(m => m.notice.id) });
            BLT.toast(r.message, r.success ? 'success' : 'error');
            if (r.success) { Modal.close(); await load(); }   // back to the case; print later from the Notices table
        };
        root.querySelector('#pvBack').onclick = async () => {
            const a = await Ask({ title: 'Discard ' + (models.length > 1 ? 'these letters' : 'this letter') + '?', icon: 'undo', ok: 'Discard & choose again', danger: true,
                message: 'Use this when the wrong letter or template was chosen. ' + models.map(m => m.notice.no).join(', ') + ' will be removed (not issued yet), then you can choose the letter again.' });
            if (!a) return;
            const r = await BLT.post('../backend/notices.php', { action: 'discard', ids: models.filter(m => m.notice.is_draft).map(m => m.notice.id) });
            if (!r.success) { BLT.toast(r.message, 'error'); return; }
            BLT.toast(r.message, 'success'); Modal.close(); await load();
            if (r.purpose === 'transfer_office') Notice.officeLetter(); else if (r.purpose === 'transfer_party') Notice.transferLetter(); else Notice.generate(r.hearing_id);
        };
        root.querySelector('#pvEdit').onclick = () => models[i].model.mode === 'image' ? Notice.layout(models[i], models.map(m => m.notice.id)) : Notice.edit(models[i], models.map(m => m.notice.id));
        root.querySelector('#pvIssue').onclick = () => Notice.issue(models[i].notice.id, models[i].notice);
        root.querySelector('#pvPdf').onclick = () => out('pdf');
        setTimeout(show, 0);
    },
    /** Drag / restyle the fields of THIS notice (uploaded-form notices). The template is never changed. */
    layout(m, reopen){
        Modal.close();
        const ov = document.getElementById('nlOverlay'), host = document.getElementById('nlHost');
        const labels = {}; (m.edit.fields || []).forEach(f => labels[f.key] = f.label);
        const fields = m.model.fields || [];
        const groups = [{ title: 'On this notice', items: fields.filter(f => !/^TEXT_\d+$/.test(f.field_key)).map(f => ({ key: f.field_key, label: labels[f.field_key] || f.field_label || f.field_key, sample: f.value })) }];
        document.getElementById('nlTitle').textContent = m.notice.type + ' · ' + m.notice.no + ' · ' + m.notice.recipient;
        // One letter per person: the same layout can be put on the other letters of this hearing (same template) at once.
        const sib = m.notice.siblings || 0, allWrap = document.getElementById('nlAllWrap');
        allWrap.classList.toggle('hidden', !sib); allWrap.style.display = sib ? 'flex' : 'none';
        document.getElementById('nlAll').checked = true;
        document.getElementById('nlAllTxt').textContent = 'Same layout on the other ' + sib + ' letter' + (sib > 1 ? 's' : '') + ' of this hearing';
        let ed = null;
        const close = async force => {
            if (!force && ed && ed.isDirty()) {
                // Unsaved moves are never lost silently: save them, or discard on purpose.
                const a = await Ask({ title: 'Save the layout changes?', icon: 'save', ok: 'Continue', cancel: 'Keep editing',
                    message: 'You moved or changed fields on this letter and they are not saved yet.',
                    choices: [['save', 'Save the changes', 'The printout will look exactly like this.'], ['discard', 'Discard the changes', 'The letter keeps its previous layout.']] });
                if (!a) return;
                if (a.choice === 'save') { host.querySelector('[data-ref=save]').click(); return; }   // closes after saving
            }
            if (ed) ed.destroy(); ov.classList.remove('open'); document.body.style.overflow = '';
            load(); Notice.preview(reopen);
        };
        ed = NoticeEditor.mount(host, {
            paper: m.model.paper, bg_image: m.model.bg_image, bg_opacity: m.model.bg_opacity, pages: m.model.pages,
            positions: fields.map(f => { const p = Object.assign({}, f); delete p.value; return p; }),
            groups: groups, values: m.model.values || {}, noExtras: true, saveLabel: 'Save this notice',
            note: 'Real values of this case are shown. Drag a field that is not aligned, change its font or size, then click Save — the printout follows this exact layout. The template is not changed.',
            onSave: async pos => {
                const r = await BLT.post('../backend/notices.php', { action: 'update_layout', id: m.notice.id, positions: pos, apply_all: sib && document.getElementById('nlAll').checked ? 1 : 0 });
                if (!r.success) throw new Error(r.message);
                BLT.toast(r.message, 'success'); setTimeout(() => close(true), 0); return true;
            },
        });
        document.getElementById('nlClose').onclick = () => close(false);
        document.getElementById('nlValues').onclick = () => { if (ed && ed.isDirty()) { BLT.toast('Save the layout first, then edit the values.', 'error'); return; } if (ed) ed.destroy(); ov.classList.remove('open'); document.body.style.overflow = ''; Notice.edit(m, reopen); };
        ov.classList.add('open'); document.body.style.overflow = 'hidden';
    },
    /** Edit THIS notice only (field values + the letter texts of a text notice). The template is never changed. */
    edit(m, reopen){
        const f = m.edit.fields, cfg = m.edit.text_config;
        const LETTER = [['title', 'Title', 1], ['recipient', 'Recipient', 2], ['subject', 'Subject', 1], ['body', 'Body (blank line = new paragraph)', 7], ['instructions', 'Instructions', 3], ['signatory_name', 'Signatory name', 1], ['signatory_title', 'Signatory title', 1], ['footer', 'Footer', 1]];
        const isDoc = !!(cfg && cfg.html);   // Word-like letter: edited right on the page
        let ed = null;
        const inp = (attr, v, rows) => rows > 1 ? '<textarea class="input" rows="' + rows + '" ' + attr + '>' + esc(v || '') + '</textarea>' : '<input class="input" ' + attr + ' value="' + esc(v || '') + '">';
        Modal.open({ title: 'Edit Notice ' + m.notice.no, sub: 'Only this notice changes — the template stays the same', ok: 'Save Changes', icon: 'save', width: isDoc ? 1000 : 820,
            html: '<div class="note note-info"><span class="material-symbols-outlined">info</span><span>Change what is printed on this notice (e.g. a wrong address). After saving, print it again. Once the notice is marked issued it can no longer be edited.</span></div>' +
                '<p class="section-title">Values on the notice</p><div class="grid sm:grid-cols-2 gap-3">' +
                f.map(x => '<div><label class="field-label">' + esc(x.label) + '</label>' + inp('data-v="' + esc(x.key) + '"', x.value, (x.value || '').length > 60 ? 2 : 1) + '</div>').join('') + '</div>' +
                (isDoc ? '<p class="section-title mt-2">Letter · edit like in Word ({{FIELD}} values are filled in when printed)</p><div id="neDoc" style="height:62vh;border:1px solid #e2e8f0;border-radius:1rem;overflow:hidden"></div>'
                  : (cfg ? '<p class="section-title mt-2">Letter text</p><p class="text-[11px] text-slate-400">{{FIELD}} values may be used.</p>' + LETTER.map(l => '<div><label class="field-label">' + l[1] + '</label>' + inp('data-c="' + l[0] + '"', cfg[l[0]], l[2]) + '</div>').join('') : '')),
            mounted: root => { if (isDoc) ed = DocEditor.mount(root.querySelector('#neDoc'), { html: cfg.html, paper: m.model.paper, pages: cfg.pages || 1, font: { family: cfg.font_family, size: cfg.font_size, line_height: cfg.line_height },
                images: { SIGNATURE: m.model.signature_url, BARANGAY_LOGO: (m.model.values || {}).BARANGAY_LOGO } }); },
            submit: root => {
                const values = {}, text = {};
                root.querySelectorAll('[data-v]').forEach(i => values[i.dataset.v] = i.value);
                root.querySelectorAll('[data-c]').forEach(i => text[i.dataset.c] = i.value);
                if (isDoc && ed) { text.html = ed.html(); text.pages = ed.pages(); }
                return BLT.post('../backend/notices.php', Object.assign({ action: 'update', id: m.notice.id, values: values }, cfg ? { text_config: text } : {}));
            },
            after: () => { load(); Notice.preview(reopen); } });
    },
    issue(nid, info){
        const n = info ? { no: info.no, type: info.type, recipient: info.recipient } : D.notices.find(x => x.id === nid);
        Modal.open({ title: 'Mark Notice Issued', sub: n.no + ' · ' + n.type, ok: 'Mark Issued', icon: 'mark_email_read',
            html: '<p class="text-sm text-slate-600">Recipient: <strong>' + esc(n.recipient) + '</strong></p><div><label class="field-label">How / to whom it was served *</label><input class="input" id="iRem" maxlength="500" placeholder="e.g. Personally received by the respondent; served by Tanod J. Cruz"></div>' +
                '<div><label class="field-label">Proof of receipt *</label><input type="file" id="iFile" accept="image/jpeg,image/png,image/webp,application/pdf" class="text-sm"><p class="text-[11px] text-slate-400 mt-1">Photo or scan of the notice signed by the respondent (or whoever received it) · JPG, PNG or PDF, up to 10 MB. It is shown in the notice preview.</p></div>' +
                '<div class="note note-warn"><span class="material-symbols-outlined">lock</span><span>After it is marked issued, the notice can no longer be edited (it can still be printed).</span></div>',
            submit: root => {
                const fd = new FormData(); fd.append('action', 'issue'); fd.append('id', nid); fd.append('remarks', val(root, '#iRem'));
                const f = root.querySelector('#iFile').files[0];
                if (!f) return { success:false, message:'Attach the proof of receipt (photo / scan of the signed notice).' };
                if (f.size > 10 * 1048576) return { success:false, message:'The proof must be 10 MB or smaller.' };
                fd.append('signed_copy', f, f.name);
                return BLT.post('../backend/notices.php', fd);
            } });
    },
};

load();
<?php if (!empty($_GET['filed'])): ?>BLT.toast('Case filed. Print the complaint record, have it signed, then click Mark Signed (Notices) before the first hearing.', 'success');<?php endif; ?>
</script>
</body>
</html>

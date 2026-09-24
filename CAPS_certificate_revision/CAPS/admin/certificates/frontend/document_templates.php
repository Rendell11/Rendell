<?php
/**
 * Template Builder — create / edit certificate document types.
 * Steps: 1 Document info · 2 Template (paper size, fields, image) · 3 Requirements ·
 *        4 Extra information fields · 5 Layout editor (live preview + field panel, AI Auto Detect).
 * "Save Draft" keeps a document "Not finished" (hidden from Issue Walk-In) until its layout is saved.
 */
require_once __DIR__ . '/../../db.php';
$required_module = 'certificates';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../backend/cert_common.php';

$current_page = 'Certificates';
$pageTitle = 'Certificate Templates';
$db_error = '';
try { cert_migrate($pdo); } catch (Throwable $e) { $db_error = 'Database setup failed. Check the PHP error log.'; error_log('[Certificates] ' . $e->getMessage()); }
$canCreate = cert_can($pdo, 'create');
$canUpdate = cert_can($pdo, 'update');
$openId = (int)($_GET['open'] ?? 0);
?>
<!doctype html>
<html <?php require_once __DIR__ . '/../../theme_loader.php'; echo $theme_attrs['html'] ?? ''; ?>>
<head>
    <?php require __DIR__ . '/partials/cert_head.php'; ?>
    <link rel="stylesheet" href="assets/cert_editor.css">
    <style>
        .type-card { transition:transform .2s, box-shadow .2s; }
        .type-card:hover { transform:translateY(-2px); box-shadow:0 16px 36px -14px rgba(15,23,42,.25); }
        .thumb { aspect-ratio: 8.5/11; background:#f8fafc; border-radius:.75rem; overflow:hidden; display:flex; align-items:center; justify-content:center; }
        .thumb img { width:100%; height:100%; object-fit:cover; object-position:top; }
        .fchk { display:flex; align-items:center; gap:.5rem; padding:.45rem .6rem; border:1px solid #e2e8f0; border-radius:.6rem; font-size:.78rem; font-weight:600; color:#334155; cursor:pointer; background:#fff; }
        .fchk:has(input:checked) { border-color:var(--accent-400,#60a5fa); background:#eff6ff; color:#1e3a8a; }
        .fchk input { accent-color:var(--accent-600); }
        #editorOverlay { position:fixed; inset:0; z-index:70; background:#0f172a; display:none; flex-direction:column; }
        #editorOverlay.open { display:flex; }
        #editorHost { flex:1; min-height:0; }
        .drop { border:2px dashed #cbd5e1; border-radius:1rem; padding:1rem; text-align:center; cursor:pointer; background:#f8fafc; }
        .drop:hover { border-color:var(--accent-400,#60a5fa); }
    </style>
</head>
<body <?php echo $theme_attrs['body'] ?? ''; ?>>
<div class="flex min-h-screen">
    <?php require __DIR__ . '/../../sidebar.php'; ?>
    <div class="flex-1 min-w-0 main-wrapper">
        <?php require __DIR__ . '/../../header.php'; ?>
        <main class="p-4 md:p-6 lg:p-8 space-y-4">
            <section class="hero-band rounded-2xl p-5 md:p-6 text-white relative overflow-hidden">
                <div class="absolute -right-12 -top-12 w-64 h-64 opacity-10 rounded-full blur-3xl pointer-events-none" style="background:var(--accent-400);"></div>
                <div class="relative z-10 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 text-white/60 text-[10px] font-black uppercase tracking-[0.18em] mb-1">
                            <span class="material-symbols-outlined text-base">design_services</span> Certificates
                        </div>
                        <h1 class="text-2xl font-black tracking-tight leading-none">Certificate Templates</h1>
                        <p class="text-white/65 text-xs mt-1.5 font-medium">Build the documents the barangay issues — template image, fields, requirements and layout.</p>
                    </div>
                    <div class="flex flex-wrap gap-2 shrink-0">
                        <?php if ($canCreate): ?>
                        <button type="button" onclick="Wizard.start()" class="inline-flex items-center gap-1.5 bg-white text-slate-900 hover:bg-white/90 px-4 py-2.5 rounded-xl font-black text-[10px] uppercase tracking-wider"><span class="material-symbols-outlined text-base">add</span>Add Document</button>
                        <?php endif; ?>
                        <a href="legal_docu.php" class="inline-flex items-center gap-1.5 bg-white/10 hover:bg-white/20 border border-white/20 text-white px-4 py-2.5 rounded-xl font-black text-[10px] uppercase tracking-wider"><span class="material-symbols-outlined text-base">arrow_back</span>Back</a>
                    </div>
                </div>
            </section>

            <?php if ($db_error): ?>
                <div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-700 px-4 py-3 text-sm font-semibold"><?php echo h($db_error); ?></div>
            <?php endif; ?>

            <section class="bg-white rounded-2xl card p-4">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                    <div><p class="section-title">Document Types</p><p class="text-[11px] text-slate-400">Click a document to open its layout. "Not finished" documents are hidden from Issue Walk-In and online requests.</p></div>
                    <div class="flex items-center gap-2 bg-slate-50 border border-slate-200 rounded-xl px-3">
                        <span class="material-symbols-outlined text-slate-400 text-lg">search</span>
                        <input id="typeSearch" type="search" placeholder="Search documents" class="border-0 bg-transparent text-sm py-2 focus:ring-0">
                    </div>
                </div>
                <div id="typeGrid" class="grid sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4 gap-3">
                    <p class="text-sm text-slate-400">Loading…</p>
                </div>
            </section>
        </main>
    </div>
</div>

<!-- Wizard (steps 1–4) -->
<div id="wizardModal" class="modal-back">
  <div class="modal-box" style="max-width:860px">
    <div class="modal-head">
      <div>
        <p class="section-title" id="wizEyebrow">Add Document</p>
        <h2 class="text-lg font-black text-slate-800" id="wizTitle">New document type</h2>
        <div class="steps mt-3" id="wizSteps"></div>
      </div>
      <button type="button" class="text-slate-400 hover:text-slate-700" onclick="Wizard.close()"><span class="material-symbols-outlined">close</span></button>
    </div>
    <div class="modal-body">
      <!-- Step 1 -->
      <div data-step="1" class="space-y-4">
        <div><label class="field-label" for="wName">Document name *</label><input id="wName" class="input" maxlength="100" placeholder="e.g. Barangay Clearance"></div>
        <div class="grid sm:grid-cols-2 gap-4">
          <div><label class="field-label" for="wCode">Document code (optional)</label><input id="wCode" class="input font-mono uppercase" maxlength="20" placeholder="Auto from the name, e.g. BC"><p class="text-[11px] text-slate-400 mt-1">Left empty, the initials of the name are used (kept unique).</p></div>
          <div><label class="field-label">Document number</label><div class="input bg-slate-100 text-slate-500">DOC-<?php echo date('Y'); ?>-#### (automatic)</div><p class="text-[11px] text-slate-400 mt-1">Every issued document gets the next number; it restarts at 0001 each year.</p></div>
        </div>
        <div><label class="field-label" for="wDesc">Short description (optional)</label><input id="wDesc" class="input" maxlength="500" placeholder="What this document is for"></div>
      </div>
      <!-- Step 2 -->
      <div data-step="2" class="space-y-4 hidden">
        <div class="rounded-xl bg-slate-50 border border-slate-100 p-3 text-[11px] text-slate-500 flex gap-2"><span class="material-symbols-outlined text-base text-slate-400">info</span>Prefilled template: upload the blank certificate (with the barangay's letterhead and lines). The checked fields are printed on top of it at the positions you set in the layout step.</div>
        <div class="grid sm:grid-cols-2 gap-4">
          <div><label class="field-label" for="wPaper">Paper size</label>
            <select id="wPaper" class="input"><?php foreach (cert_paper_sizes() as $k => $ps): ?><option value="<?php echo h($k); ?>"><?php echo h($ps['label']); ?></option><?php endforeach; ?></select></div>
        </div>
        <div>
          <label class="field-label">Fields to print on the certificate</label>
          <div id="wFields" class="space-y-3"></div>
          <p class="text-[11px] text-slate-400 mt-2">Extra information fields you add in step 4 can be printed too.</p>
        </div>
        <div>
          <label class="field-label">Template image *</label>
          <div class="grid sm:grid-cols-[1fr_160px] gap-3 items-start">
            <label class="drop" for="wImage">
              <span class="material-symbols-outlined text-3xl text-slate-400">upload_file</span>
              <p class="text-sm font-bold text-slate-700 mt-1">Choose template image</p>
              <p class="text-[11px] text-slate-400">PNG, JPG or WebP · max 5 MB · same proportion as the paper size</p>
              <p id="wImageName" class="text-[11px] font-bold text-indigo-600 mt-1"></p>
              <input id="wImage" type="file" accept="image/png,image/jpeg,image/webp" class="hidden">
            </label>
            <div class="thumb border border-slate-200" id="wThumb"><span class="material-symbols-outlined text-slate-300 text-4xl">image</span></div>
          </div>
        </div>
      </div>
      <!-- Step 3 -->
      <div data-step="3" class="space-y-3 hidden">
        <p class="text-sm text-slate-600">Requirements the resident must present. In Issue Walk-In <strong>all</strong> of them must be checked before continuing.</p>
        <div class="flex gap-2"><input id="wReqInput" class="input" maxlength="255" placeholder="e.g. Valid ID"><button type="button" class="btn btn-dark" onclick="Wizard.addReq()"><span class="material-symbols-outlined">add</span>Add</button></div>
        <ul id="wReqs" class="space-y-2"></ul>
      </div>
      <!-- Step 4 -->
      <div data-step="4" class="space-y-3 hidden">
        <p class="text-sm text-slate-600">Extra information asked when the document is issued — e.g. <em>Company Name</em> for a work-purpose clearance. The values are saved with the request.</p>
        <div id="wExtras" class="space-y-2"></div>
        <button type="button" class="btn btn-ghost" onclick="Wizard.addExtra()"><span class="material-symbols-outlined">add</span>Add field</button>
      </div>
    </div>
    <div class="modal-foot">
      <p id="wMsg" class="text-[11px] font-semibold text-rose-600 mr-auto"></p>
      <button type="button" class="btn btn-ghost" id="wBack" onclick="Wizard.back()"><span class="material-symbols-outlined">arrow_back</span>Back</button>
      <button type="button" class="btn btn-ghost" id="wDraft" onclick="Wizard.save(true)"><span class="material-symbols-outlined">draft</span>Save Draft</button>
      <button type="button" class="btn btn-dark" id="wNext" onclick="Wizard.next()">Next<span class="material-symbols-outlined">arrow_forward</span></button>
    </div>
  </div>
</div>

<!-- Step 5: Layout editor -->
<div id="editorOverlay">
  <div class="flex items-center justify-between gap-3 px-4 py-2.5 bg-slate-900 text-white">
    <div class="min-w-0">
      <p class="text-[10px] font-black uppercase tracking-[0.18em] text-white/50">Step 5 · Layout</p>
      <p class="font-black truncate" id="edTitle"></p>
    </div>
    <div class="flex items-center gap-2">
      <span class="text-[11px] text-white/60 hidden sm:inline" id="edPaper"></span>
      <button type="button" class="btn bg-white/10 text-white border border-white/20" onclick="Editor.details()"><span class="material-symbols-outlined">tune</span>Details</button>
      <button type="button" class="btn bg-white text-slate-900" onclick="Editor.close()"><span class="material-symbols-outlined">close</span>Close</button>
    </div>
  </div>
  <div id="editorHost"></div>
</div>

<script src="assets/cert_render.js"></script>
<script src="assets/cert_editor.js"></script>
<script>
const API = '../backend/template_actions.php';
const CAN_UPDATE = <?php echo $canUpdate ? 'true' : 'false'; ?>;
let TYPES = [];
let CATALOG = null;

async function loadCatalog(){
    if (!CATALOG) { const d = await CERT.getJSON(API + '?action=catalog'); CATALOG = d.groups; }
    return CATALOG;
}

/* ───────── List ───────── */
async function loadTypes(){
    const grid = document.getElementById('typeGrid');
    try {
        const d = await CERT.getJSON(API + '?action=list');
        TYPES = d.types || [];
        drawTypes();
    } catch (e) { grid.innerHTML = '<p class="text-sm text-rose-600 font-semibold">' + CERT.esc(e.message) + '</p>'; }
}
function drawTypes(){
    const q = (document.getElementById('typeSearch').value || '').toLowerCase();
    const grid = document.getElementById('typeGrid');
    const list = TYPES.filter(t => !q || t.doc_type.toLowerCase().includes(q) || (t.doc_code || '').toLowerCase().includes(q));
    if (!list.length) { grid.innerHTML = '<div class="col-span-full text-center py-10"><span class="material-symbols-outlined text-4xl text-slate-300">description</span><p class="text-sm text-slate-400 mt-2">' + (TYPES.length ? 'No document matches your search.' : 'No document types yet. Click <strong>Add Document</strong> to create one.') + '</p></div>'; return; }
    grid.innerHTML = list.map(t => {
        const badge = t.is_draft ? '<span class="pill bg-amber-50 text-amber-700 border-amber-200"><span class="material-symbols-outlined">edit_note</span>Not finished</span>'
            : (t.is_active ? '<span class="pill bg-emerald-50 text-emerald-700 border-emerald-200"><span class="material-symbols-outlined">check_circle</span>Active</span>'
                           : '<span class="pill bg-slate-100 text-slate-500 border-slate-200"><span class="material-symbols-outlined">block</span>Disabled</span>');
        return '<div class="type-card bg-white rounded-2xl card p-3 flex flex-col gap-3">' +
            '<button type="button" class="thumb border border-slate-100" onclick="openType(' + t.id + ')">' + (t.bg_image ? '<img src="' + CERT.esc(t.bg_image) + '" alt="">' : '<span class="material-symbols-outlined text-5xl text-slate-300">description</span>') + '</button>' +
            '<div class="flex items-start justify-between gap-2"><div class="min-w-0"><p class="font-black text-slate-800 truncate">' + CERT.esc(t.doc_type) + '</p>' +
            '<p class="text-[11px] text-slate-400 font-mono">' + CERT.esc(t.doc_code || '—') + ' · ' + CERT.esc(t.paper_label) + '</p></div>' + badge + '</div>' +
            '<div class="flex flex-wrap gap-1.5 text-[10px] font-bold text-slate-500">' +
            '<span class="px-2 py-1 rounded-lg bg-slate-50">' + t.field_count + ' fields</span>' +
            '<span class="px-2 py-1 rounded-lg bg-slate-50">' + t.req_count + ' requirements</span>' +
            '<span class="px-2 py-1 rounded-lg bg-slate-50">' + t.extra_count + ' extra info</span>' +
            '<span class="px-2 py-1 rounded-lg bg-slate-50">' + t.request_count + ' issued/requested</span></div>' +
            (CAN_UPDATE ? '<div class="flex gap-1.5 mt-auto">' +
                (t.is_draft ? '<button class="btn btn-dark flex-1" onclick="Wizard.edit(' + t.id + ')"><span class="material-symbols-outlined">play_arrow</span>Continue</button>'
                            : '<button class="btn btn-dark flex-1" onclick="Editor.open(' + t.id + ')"><span class="material-symbols-outlined">dashboard_customize</span>Layout</button>') +
                '<button class="btn btn-ghost" title="Edit details" onclick="Wizard.edit(' + t.id + ')"><span class="material-symbols-outlined">edit</span></button>' +
                (!t.is_draft ? '<button class="btn btn-ghost" title="' + (t.is_active ? 'Disable' : 'Enable') + '" onclick="toggleType(' + t.id + ',' + (t.is_active ? 0 : 1) + ')"><span class="material-symbols-outlined">' + (t.is_active ? 'toggle_on' : 'toggle_off') + '</span></button>' : '') +
                '<button class="btn btn-ghost text-red-600" title="Delete" onclick="deleteType(' + t.id + ')"><span class="material-symbols-outlined">delete</span></button>' +
            '</div>' : '') +
        '</div>';
    }).join('');
}
function openType(id){
    const t = TYPES.find(x => x.id === id);
    if (!CAN_UPDATE) return;
    if (t && t.is_draft) Wizard.edit(id); else Editor.open(id);
}
async function toggleType(id, active){
    const d = await CERT.post(API, { action:'toggle_active', id:id, active:active });
    CERT.toast(d.message, d.success ? 'success' : 'error'); loadTypes();
}
async function deleteType(id){
    const t = TYPES.find(x => x.id === id);
    const ok = await CERT.confirm({ title:'Delete "' + t.doc_type + '"?', message: t.request_count ? 'This document was already requested/issued, so it will be disabled instead (records are kept).' : 'This removes the document type, its requirements, extra fields and layout.', ok:'Delete', danger:true, icon:'delete' });
    if (!ok) return;
    const d = await CERT.post(API, { action:'delete_type', id:id });
    CERT.toast(d.message, d.success ? 'success' : 'error'); loadTypes();
}
document.getElementById('typeSearch').addEventListener('input', drawTypes);

/* ───────── Wizard (steps 1–4) ───────── */
const Wizard = (function(){
    const LABELS = ['Document', 'Template', 'Requirements', 'Extra Info', 'Layout'];
    let s = null, step = 1, file = null;

    function blank(){ return { id:0, doc_type:'', doc_code:'', description:'', paper_size:'a4', selected_fields:['full_name','complete_address','purpose','date_issued','document_number','captain_name'], requirements:[], extra_fields:[], bg_image:null, is_draft:1 }; }

    async function start(){ s = blank(); file = null; await openAt(1); }
    async function edit(id){
        const d = await CERT.getJSON(API + '?action=get&id=' + id);
        if (!d.success) { CERT.toast(d.message, 'error'); return; }
        s = d.type; file = null;
        if (s.is_draft && s.draft_step >= 5) { Editor.open(id); return; }
        await openAt(s.is_draft ? Math.max(1, Math.min(4, s.draft_step)) : 1);
    }
    async function openAt(n){
        await loadCatalog();
        document.getElementById('wizEyebrow').textContent = s.id ? (s.is_draft ? 'Continue Draft' : 'Edit Document') : 'Add Document';
        document.getElementById('wizTitle').textContent = s.id ? s.doc_type : 'New document type';
        document.getElementById('wName').value = s.doc_type || '';
        document.getElementById('wCode').value = s.doc_code || '';
        document.getElementById('wDesc').value = s.description || '';
        document.getElementById('wPaper').value = s.paper_size || 'a4';
        document.getElementById('wImage').value = '';
        document.getElementById('wImageName').textContent = '';
        document.getElementById('wThumb').innerHTML = s.bg_image ? '<img src="' + CERT.esc(s.bg_image) + '" alt="">' : '<span class="material-symbols-outlined text-slate-300 text-4xl">image</span>';
        document.getElementById('wDraft').classList.toggle('hidden', !!(s.id && !s.is_draft));
        drawFields(); drawReqs(); drawExtras();
        go(n);
        CERT.open('wizardModal');
    }
    function go(n){
        step = n;
        document.querySelectorAll('#wizardModal [data-step]').forEach(el => el.classList.toggle('hidden', Number(el.dataset.step) !== n));
        document.getElementById('wizSteps').innerHTML = LABELS.map((l, i) =>
            (i ? '<span class="step-line"></span>' : '') + '<span class="step-dot ' + (i + 1 === n ? 'active' : (i + 1 < n ? 'done' : '')) + '"><span class="n">' + (i + 1 < n ? '✓' : i + 1) + '</span>' + l + '</span>').join('');
        document.getElementById('wBack').classList.toggle('invisible', n === 1);
        document.getElementById('wNext').innerHTML = n === 4 ? 'Save &amp; Open Layout<span class="material-symbols-outlined">dashboard_customize</span>' : 'Next<span class="material-symbols-outlined">arrow_forward</span>';
        document.getElementById('wMsg').textContent = '';
    }
    function collect(){
        s.doc_type = document.getElementById('wName').value.trim();
        s.doc_code = document.getElementById('wCode').value.trim().toUpperCase();
        s.description = document.getElementById('wDesc').value.trim();
        s.paper_size = document.getElementById('wPaper').value;
        s.selected_fields = Array.from(document.querySelectorAll('#wFields input:checked')).map(i => i.value);
        // Extra fields marked "print" are kept in the selection.
        s.extra_fields.forEach(e => { const k = 'extra.' + (e.field_key || slug(e.label)); if (e.print && !s.selected_fields.includes(k)) s.selected_fields.push(k); });
    }
    function slug(l){ return (l || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 50) || 'field'; }
    function validate(n){
        if (n >= 1 && !s.doc_type) return 'Enter the document name.';
        if (n >= 2 && !s.bg_image && !file) return 'Upload the template image.';
        if (n >= 2 && !s.selected_fields.length) return 'Check at least one field to print.';
        return '';
    }
    async function save(draft, nextStep){
        collect();
        const err = draft ? (s.doc_type ? '' : 'Enter the document name.') : validate(step);
        if (err) { document.getElementById('wMsg').textContent = err; return null; }
        const fd = new FormData();
        fd.append('action', 'save_type'); fd.append('id', s.id || 0);
        fd.append('doc_type', s.doc_type); fd.append('doc_code', s.doc_code); fd.append('description', s.description);
        fd.append('paper_size', s.paper_size);
        fd.append('fields', JSON.stringify(s.selected_fields));
        fd.append('requirements', JSON.stringify(s.requirements));
        fd.append('extra_fields', JSON.stringify(s.extra_fields.map(e => ({ field_key:e.field_key || '', label:e.label, input_type:e.input_type, options:e.options || [], is_required:e.is_required ? 1 : 0 }))));
        fd.append('draft', draft || s.is_draft ? '1' : '0');
        fd.append('step', nextStep || step);
        if (file) fd.append('template_image', file);
        const btns = document.querySelectorAll('#wizardModal .modal-foot .btn'); btns.forEach(b => b.disabled = true);
        try {
            const d = await CERT.post(API, fd);
            if (!d.success) { document.getElementById('wMsg').textContent = d.message; return null; }
            const keepPrint = {}; s.extra_fields.forEach(e => keepPrint[e.label] = e.print);
            s = d.type; file = null;
            s.extra_fields.forEach(e => e.print = s.selected_fields.includes('extra.' + e.field_key));
            if (draft) { CERT.toast(d.message, 'success'); close(); }
            loadTypes();
            return d;
        } catch (e) { document.getElementById('wMsg').textContent = e.message; return null; }
        finally { btns.forEach(b => b.disabled = false); }
    }
    async function next(){
        collect();
        const err = validate(step);
        if (err) { document.getElementById('wMsg').textContent = err; return; }
        if (step < 4) {
            // Save quietly on every step so nothing is lost; new documents stay drafts until the layout is saved.
            const d = await save(false, step + 1);
            if (d) { drawFields(); drawExtras(); go(step + 1); }
            return;
        }
        const d = await save(false, 5);
        if (d) { close(); Editor.open(s.id); }
    }
    function back(){ collect(); if (step > 1) go(step - 1); }
    function close(){ CERT.close('wizardModal'); }

    function drawFields(){
        const host = document.getElementById('wFields');
        host.innerHTML = CATALOG.map(g => '<div><p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1.5">' + CERT.esc(g.title) + '</p><div class="grid grid-cols-2 sm:grid-cols-3 gap-1.5">' +
            g.items.map(i => '<label class="fchk"><input type="checkbox" value="' + CERT.esc(i.key) + '"' + (s.selected_fields.includes(i.key) ? ' checked' : '') + '>' + CERT.esc(i.label) + '</label>').join('') + '</div></div>').join('');
    }
    function drawReqs(){
        const ul = document.getElementById('wReqs');
        ul.innerHTML = s.requirements.length ? s.requirements.map((r, i) => '<li class="flex items-center gap-2 px-3 py-2 rounded-xl border border-slate-200 bg-white"><span class="material-symbols-outlined text-slate-400 text-lg">checklist</span><span class="flex-1 text-sm font-semibold text-slate-700">' + CERT.esc(r) + '</span><button type="button" class="text-slate-400 hover:text-red-600" onclick="Wizard.delReq(' + i + ')"><span class="material-symbols-outlined">close</span></button></li>').join('')
            : '<li class="text-sm text-slate-400">No requirements yet.</li>';
    }
    function addReq(){
        const inp = document.getElementById('wReqInput'); const v = inp.value.trim();
        if (!v) return;
        if (!s.requirements.some(r => r.toLowerCase() === v.toLowerCase())) s.requirements.push(v);
        inp.value = ''; drawReqs(); inp.focus();
    }
    function delReq(i){ s.requirements.splice(i, 1); drawReqs(); }
    function drawExtras(){
        const host = document.getElementById('wExtras');
        s.extra_fields.forEach(e => { if (e.print === undefined) e.print = s.selected_fields.includes('extra.' + e.field_key); });
        host.innerHTML = s.extra_fields.length ? s.extra_fields.map((e, i) =>
            '<div class="rounded-xl border border-slate-200 p-3 grid sm:grid-cols-[1fr_140px] gap-2 items-start">' +
            '<input class="input" maxlength="150" placeholder="Label, e.g. Company Name" value="' + CERT.esc(e.label) + '" oninput="Wizard.setExtra(' + i + ',\'label\',this.value)">' +
            '<select class="input" onchange="Wizard.setExtra(' + i + ',\'input_type\',this.value)">' + ['text','number','date','textarea','select'].map(t => '<option value="' + t + '"' + (e.input_type === t ? ' selected' : '') + '>' + ({text:'Text',number:'Number',date:'Date',textarea:'Long text',select:'Dropdown'})[t] + '</option>').join('') + '</select>' +
            (e.input_type === 'select' ? '<input class="input sm:col-span-2" placeholder="Choices, separated by commas" value="' + CERT.esc((e.options || []).join(', ')) + '" oninput="Wizard.setExtra(' + i + ',\'options\',this.value)">' : '') +
            '<div class="sm:col-span-2 flex flex-wrap items-center gap-4 text-xs font-semibold text-slate-600">' +
            '<label class="inline-flex items-center gap-1.5"><input type="checkbox"' + (e.is_required ? ' checked' : '') + ' onchange="Wizard.setExtra(' + i + ',\'is_required\',this.checked)">Required</label>' +
            '<label class="inline-flex items-center gap-1.5"><input type="checkbox"' + (e.print ? ' checked' : '') + ' onchange="Wizard.setExtra(' + i + ',\'print\',this.checked)">Print on the certificate</label>' +
            '<button type="button" class="ml-auto text-red-600 inline-flex items-center gap-1" onclick="Wizard.delExtra(' + i + ')"><span class="material-symbols-outlined text-base">delete</span>Remove</button></div></div>').join('')
            : '<p class="text-sm text-slate-400">No extra information fields. Purpose is always asked.</p>';
    }
    function addExtra(){ s.extra_fields.push({ field_key:'', label:'', input_type:'text', options:[], is_required:1, print:true }); drawExtras(); }
    function setExtra(i, k, v){
        const e = s.extra_fields[i];
        if (k === 'options') e.options = v.split(',').map(x => x.trim()).filter(Boolean); else e[k] = v;
        if (k === 'print' && !v) s.selected_fields = s.selected_fields.filter(x => x !== 'extra.' + (e.field_key || slug(e.label)));
        if (k === 'input_type') drawExtras();
    }
    function delExtra(i){ const e = s.extra_fields[i]; s.selected_fields = s.selected_fields.filter(x => x !== 'extra.' + e.field_key); s.extra_fields.splice(i, 1); drawExtras(); }

    document.getElementById('wImage').addEventListener('change', function(){
        const f = this.files[0]; if (!f) return;
        if (!/^image\/(png|jpeg|webp)$/.test(f.type)) { document.getElementById('wMsg').textContent = 'Use a PNG, JPG or WebP image.'; this.value = ''; return; }
        if (f.size > 5 * 1024 * 1024) { document.getElementById('wMsg').textContent = 'The image must be 5 MB or smaller.'; this.value = ''; return; }
        file = f;
        document.getElementById('wImageName').textContent = f.name;
        document.getElementById('wThumb').innerHTML = '<img src="' + URL.createObjectURL(f) + '" alt="">';
    });
    document.getElementById('wReqInput').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addReq(); } });
    return { start, edit, next, back, save, close, addReq, delReq, addExtra, setExtra, delExtra };
})();

/* ───────── Step 5: Layout editor ───────── */
const Editor = (function(){
    let ed = null, cur = null;
    async function open(id){
        const d = await CERT.getJSON(API + '?action=get&id=' + id);
        if (!d.success) { CERT.toast(d.message, 'error'); return; }
        cur = d.type;
        if (!cur.bg_image) { CERT.toast('Upload the template image first.', 'warning'); Wizard.edit(id); return; }
        document.getElementById('edTitle').textContent = cur.doc_type + (cur.is_draft ? ' · Not finished' : '');
        document.getElementById('edPaper').textContent = cur.paper.label;
        document.getElementById('editorOverlay').classList.add('open');
        document.body.style.overflow = 'hidden';
        const values = {};
        cur.groups.forEach(g => g.items.forEach(i => values[i.key] = i.sample));
        if (ed) ed.destroy();
        ed = CertEditor.mount(document.getElementById('editorHost'), {
            paper: cur.paper, bg_image: cur.bg_image, bg_opacity: cur.bg_opacity,
            positions: cur.positions, groups: cur.groups, values: values,
            saveLabel: cur.is_draft ? 'Save & Finish' : 'Save Layout',
            note: cur.legacy_custom_layout ? 'This document used the removed Custom Layout option. It keeps printing its old layout (read-only) until you save a layout here.' : 'Sample values are shown. Issued documents use the resident\'s real data.',
            onSave: async function(positions){
                const r = await CERT.post(API, { action:'save_layout', id:cur.id, positions:positions, finish:1 });
                if (!r.success) throw new Error(r.message);
                CERT.toast(r.message, 'success');
                cur = r.type; document.getElementById('edTitle').textContent = cur.doc_type;
                loadTypes();
                return true;
            },
            onAiDetect: async function(keys){
                const r = await CERT.post('../backend/cert_ai_detect.php', { id:cur.id, keys:keys });
                if (!r.success) throw new Error(r.message);
                return r.positions;
            },
        });
    }
    async function close(){
        if (ed && ed.isDirty()) {
            const ok = await CERT.confirm({ title:'Leave without saving?', message:'Your layout changes are not saved yet.', ok:'Leave', danger:true, icon:'warning' });
            if (!ok) return;
        }
        if (ed) { ed.destroy(); ed = null; }
        document.getElementById('editorOverlay').classList.remove('open');
        document.body.style.overflow = '';
    }
    async function details(){ const id = cur.id; await close(); if (!document.getElementById('editorOverlay').classList.contains('open')) Wizard.edit(id); }
    return { open, close, details };
})();

loadTypes().then(function(){ <?php if ($openId): ?>openType(<?php echo $openId; ?>);<?php endif; ?> });
</script>
</body>
</html>

<?php
/**
 * Template Builder — create / edit certificate document types.
 * Steps: 1 Document info · 2 Template (Word / PDF / image — every page kept, original page size) · 3 Requirements ·
 *        4 Extra information fields · 5 Layout editor (live preview + field panel, AI Auto Detect, Clear,
 *        Archive = save the progress as "Not finished" to continue later).
 * Fields to print are chosen in the layout editor (or by AI Auto Detect), not in the wizard.
 * "Save Draft" keeps a document "Not finished" (hidden from Issue Walk-In) until its layout is saved.
 * Only saved, finished and active document types can be issued.
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
    <link rel="stylesheet" href="assets/cert_editor.css?v=<?php echo @filemtime(__DIR__ . '/assets/cert_editor.css'); ?>">
    <style>
        .type-card { background:#fff; border-radius:32px; border:1px solid #f1f5f9; box-shadow:0 1px 2px rgba(15,23,42,.05); padding:1rem; display:flex; flex-direction:column; gap:.9rem; transition:transform .2s, box-shadow .2s; }
        .type-card:hover { transform:translateY(-2px); box-shadow:0 16px 36px -14px rgba(15,23,42,.18); }
        .thumb { height:240px; background:#f8fafc; border-radius:1.5rem; overflow:hidden; display:flex; align-items:center; justify-content:center; border:1px solid #f1f5f9; }
        .thumb img { width:100%; height:100%; object-fit:cover; object-position:top; }
        .fchk { display:flex; align-items:center; gap:.5rem; padding:.55rem .7rem; border:1px solid #f1f5f9; border-radius:.75rem; font-size:.78rem; font-weight:700; color:#334155; cursor:pointer; background:#f8fafc; transition:all .15s; }
        .fchk:has(input:checked) { border-color:#c7d2fe; background:#eef2ff; color:var(--accent-700); }
        .fchk input { accent-color:var(--accent-600); border-radius:.25rem; }
        #editorOverlay { position:fixed; inset:0; z-index:70; background:#0f172a; display:none; flex-direction:column; }
        #editorOverlay.open { display:flex; }
        #editorHost { flex:1; min-height:0; }
        .drop { border:2px dashed #cbd5e1; border-radius:1.5rem; padding:1.25rem; text-align:center; cursor:pointer; background:#f8fafc; transition:all .15s; }
        .drop:hover { border-color:var(--accent-400,#818cf8); background:#eef2ff; }
    </style>
</head>
<body <?php echo $theme_attrs['body'] ?? ''; ?>>
<div class="flex min-h-screen">
    <?php require __DIR__ . '/../../sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php require __DIR__ . '/../../header.php'; ?>
        <main class="p-4 md:p-6 lg:p-8 space-y-8">
            <!-- ── Hero Band (same as Resident Management) ── -->
            <div class="hero-band rounded-2xl p-6 md:p-8 text-white relative overflow-hidden">
                <div class="absolute -right-12 -top-12 w-64 h-64 opacity-10 rounded-full blur-3xl pointer-events-none" style="background:var(--accent-400);"></div>
                <div class="absolute left-1/3 bottom-0 w-48 h-48 opacity-10 rounded-full blur-2xl pointer-events-none" style="background:var(--accent-300);"></div>
                <div class="relative z-10 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div>
                        <h1 class="text-2xl md:text-3xl font-black tracking-tight leading-none">Certificate Templates</h1>
                        <p class="text-white/60 text-sm mt-2 font-medium">Build the documents the barangay issues — template image, fields, requirements and layout.</p>
                    </div>
                    <div class="flex flex-wrap gap-3 flex-shrink-0">
                        <a href="legal_docu.php" class="hero-btn"><span class="material-symbols-outlined">arrow_back</span>Back to Certificates</a>
                        <?php if ($canCreate): ?>
                        <button type="button" onclick="Wizard.start()" class="hero-btn hero-btn-primary"><span class="material-symbols-outlined">note_add</span>Add Document</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($db_error): ?>
                <div class="rounded-2xl border border-rose-200 bg-rose-50 text-rose-700 px-5 py-4 text-sm font-bold"><?php echo h($db_error); ?></div>
            <?php endif; ?>

            <!-- ── Search row ── -->
            <div class="grid grid-cols-12 gap-4">
                <div class="col-span-12 md:col-span-8 relative">
                    <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-xl">search</span>
                    <input id="typeSearch" type="search" placeholder="Search documents by name or code…" class="search-pill">
                </div>
                <div class="col-span-12 md:col-span-4">
                    <select id="typeStatus" class="select-pill">
                        <option value="">All Documents</option>
                        <option value="active">Active</option>
                        <option value="draft">Not finished</option>
                        <option value="disabled">Disabled</option>
                    </select>
                </div>
            </div>

            <div>
                <div class="flex items-center gap-3 mb-4">
                    <span class="material-symbols-outlined text-white bg-primary p-2 rounded-xl shadow-md">folder_open</span>
                    <div><h2 class="text-base font-black text-slate-800 leading-tight">Document Types</h2>
                        <p class="text-[10px] text-slate-400 font-bold mt-0.5">Click a document to open its layout. "Not finished" and disabled documents are hidden from Issue Walk-In and online requests.</p></div>
                </div>
                <div id="typeGrid" class="grid sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-6">
                    <p class="text-sm text-slate-400">Loading…</p>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Wizard (steps 1–4) -->
<div id="wizardModal" class="modal-back">
  <div class="modal-box" style="max-width:860px">
    <div class="modal-head">
      <div>
        <h3 class="modal-title" id="wizTitle">New document type</h3>
        <p class="modal-sub" id="wizEyebrow">Add Document</p>
        <div class="steps mt-4" id="wizSteps"></div>
      </div>
      <button type="button" class="modal-close" onclick="Wizard.close()"><span class="material-symbols-outlined">close</span></button>
    </div>
    <div class="modal-body">
      <!-- Step 1 -->
      <div data-step="1" class="space-y-4">
        <div class="sec-head"><span class="material-symbols-outlined">badge</span><h4>Document Information</h4></div>
        <div><label class="field-label" for="wName">Document name *</label><input id="wName" class="input" maxlength="100" placeholder="e.g. Barangay Clearance"></div>
        <div class="grid sm:grid-cols-2 gap-4">
          <div><label class="field-label" for="wCode">Document code (optional)</label><input id="wCode" class="input font-mono uppercase" maxlength="20" placeholder="Auto from the name, e.g. BC"><p class="text-[11px] text-slate-400 mt-1">Left empty, the initials of the name are used (kept unique).</p></div>
          <div><label class="field-label">Document number</label><div class="input bg-slate-100 text-slate-500">DOC-<?php echo date('Y'); ?>-#### (automatic)</div><p class="text-[11px] text-slate-400 mt-1">Every issued document gets the next number; it restarts at 0001 each year.</p></div>
        </div>
        <div><label class="field-label" for="wDesc">Short description (optional)</label><input id="wDesc" class="input" maxlength="500" placeholder="What this document is for"></div>
      </div>
      <!-- Step 2 -->
      <div data-step="2" class="space-y-4 hidden">
        <div class="sec-head"><span class="material-symbols-outlined">upload_file</span><h4>Choose Template</h4></div>
        <div class="rounded-xl bg-slate-50 border border-slate-100 p-3 text-[11px] text-slate-500 flex gap-2"><span class="material-symbols-outlined text-base text-slate-400">info</span>Upload the blank certificate (with the barangay's letterhead and lines). Every page of a Word or PDF file is kept at its original page size. The fields to print are chosen later in the layout editor — or by AI Auto Detect.</div>
        <div>
          <label class="field-label">Template file *</label>
          <div class="grid sm:grid-cols-[1fr_160px] gap-3 items-start">
            <label class="drop" for="wImage">
              <span class="material-symbols-outlined text-3xl text-slate-400">upload_file</span>
              <p class="text-sm font-bold text-slate-700 mt-1" id="wImageLbl">Choose template file</p>
              <p class="text-[11px] text-slate-400">Word (.doc / .docx), PDF, PNG or JPG · max 10 MB · up to 10 pages</p>
              <p id="wImageName" class="text-[11px] font-bold text-indigo-600 mt-1"></p>
              <input id="wImage" type="file" accept=".doc,.docx,.pdf,.png,.jpg,.jpeg,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/pdf,image/png,image/jpeg" class="hidden">
            </label>
            <div class="thumb border border-slate-200" id="wThumb"><span class="material-symbols-outlined text-slate-300 text-4xl">image</span></div>
          </div>
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
          <div><label class="field-label" for="wPaper">Page size</label>
            <select id="wPaper" class="input"><option value="original">Original template size</option><?php foreach (cert_paper_sizes() as $k => $ps): ?><option value="<?php echo h($k); ?>"><?php echo h($ps['label']); ?></option><?php endforeach; ?></select>
            <p class="text-[11px] text-slate-400 mt-1">Keep "Original template size" so the pages print exactly like the uploaded file.</p></div>
        </div>
      </div>
      <!-- Step 3 -->
      <div data-step="3" class="space-y-3 hidden">
        <div class="sec-head"><span class="material-symbols-outlined">checklist</span><h4>Requirements</h4></div>
        <p class="text-sm text-slate-600">Requirements the resident must present. In Issue Walk-In <strong>all</strong> of them must be checked before continuing.</p>
        <div class="flex gap-2"><input id="wReqInput" class="input" maxlength="255" placeholder="e.g. Valid ID"><button type="button" class="btn btn-dark" onclick="Wizard.addReq()"><span class="material-symbols-outlined">add</span>Add</button></div>
        <p id="wReqPending" style="display:none" class="text-[11px] font-bold text-rose-600 bg-rose-50 border border-rose-100 rounded-xl px-3 py-2 items-center gap-1.5"><span class="material-symbols-outlined text-base">error</span>This item has not been added yet. Please click Add before continuing.</p>
        <ul id="wReqs" class="space-y-2"></ul>
      </div>
      <!-- Step 4 -->
      <div data-step="4" class="space-y-3 hidden">
        <div class="sec-head"><span class="material-symbols-outlined">edit_note</span><h4>Extra Information Fields</h4></div>
        <p class="text-sm text-slate-600">Extra information asked when the document is issued — e.g. <em>Company Name</em> for a work-purpose clearance. The values are saved with the request.</p>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 space-y-3">
          <div class="grid sm:grid-cols-[1fr_150px_auto] gap-3 items-start">
            <input id="wExLabel" class="input" maxlength="150" placeholder="Label, e.g. Company Name">
            <select id="wExType" class="input"><option value="text">Text</option><option value="number">Number</option><option value="date">Date</option><option value="textarea">Long text</option><option value="select">Dropdown</option></select>
            <button type="button" class="btn btn-dark" onclick="Wizard.addExtra()"><span class="material-symbols-outlined">add</span>Add</button>
          </div>
          <input id="wExOptions" class="input hidden" placeholder="Choices, separated by commas">
          <label class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-600"><input type="checkbox" id="wExRequired" checked>Required</label>
          <p id="wExPending" style="display:none" class="text-[11px] font-bold text-rose-600 bg-rose-50 border border-rose-100 rounded-xl px-3 py-2 items-center gap-1.5"><span class="material-symbols-outlined text-base">error</span>This item has not been added yet. Please click Add before continuing.</p>
        </div>
        <div id="wExtras" class="space-y-2"></div>
        <p class="text-[11px] text-slate-400">Extra information fields can be placed on the certificate in the layout editor (step 5).</p>
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
  <div class="flex items-center justify-between gap-3 px-5 py-3 hero-band text-white">
    <div class="min-w-0">
      <p class="text-[10px] font-black uppercase tracking-[0.18em] text-white/60">Step 5 · Layout</p>
      <p class="font-black truncate" id="edTitle"></p>
    </div>
    <div class="flex items-center gap-2">
      <span class="text-[11px] text-white/60 hidden sm:inline" id="edPaper"></span>
      <button type="button" class="hero-btn" onclick="Editor.details()"><span class="material-symbols-outlined">tune</span>Details</button>
      <button type="button" class="hero-btn" onclick="Editor.clear()"><span class="material-symbols-outlined">layers_clear</span>Clear</button>
      <button type="button" class="hero-btn" id="edArchiveBtn" onclick="Editor.archive()"><span class="material-symbols-outlined">archive</span>Archive</button>
      <button type="button" class="hero-btn hero-btn-white" onclick="Editor.close()"><span class="material-symbols-outlined">close</span>Close</button>
    </div>
  </div>
  <div id="editorHost"></div>
</div>

<script src="../../blotter/frontend/assets/template_convert.js?v=<?php echo @filemtime(__DIR__ . '/../../blotter/frontend/assets/template_convert.js'); ?>"></script>
<script src="assets/cert_render.js?v=<?php echo @filemtime(__DIR__ . '/assets/cert_render.js'); ?>"></script>
<script src="assets/cert_editor.js?v=<?php echo @filemtime(__DIR__ . '/assets/cert_editor.js'); ?>"></script>
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
function typeState(t){ return t.is_draft ? 'draft' : (t.is_active ? 'active' : 'disabled'); }
function drawTypes(){
    const q = (document.getElementById('typeSearch').value || '').toLowerCase();
    const grid = document.getElementById('typeGrid');
    const st = document.getElementById('typeStatus').value;
    const list = TYPES.filter(t => (!q || t.doc_type.toLowerCase().includes(q) || (t.doc_code || '').toLowerCase().includes(q))
        && (!st || typeState(t) === st));
    if (!list.length) { grid.innerHTML = '<div class="col-span-full text-center py-16 bg-white rounded-[32px] border border-slate-100"><span class="material-symbols-outlined text-4xl block mb-2 text-slate-200">manage_search</span><p class="text-sm text-slate-400">' + (TYPES.length ? 'No document matches your search.' : 'No document types yet. Click <strong>Add Document</strong> to create one.') + '</p></div>'; return; }
    grid.innerHTML = list.map(t => {
        const s = typeState(t);
        const badge = s === 'draft' ? '<span class="pill bg-amber-50 text-amber-700 border-amber-200"><span class="material-symbols-outlined">edit_note</span>Not finished</span>'
            : (s === 'active' ? '<span class="pill bg-emerald-50 text-emerald-700 border-emerald-200"><span class="material-symbols-outlined">check_circle</span>Active</span>'
                              : '<span class="pill bg-slate-100 text-slate-500 border-slate-200"><span class="material-symbols-outlined">block</span>Disabled</span>');
        let actions = '';
        if (CAN_UPDATE) {
            actions = '<div class="flex items-center gap-1 mt-auto pt-2 border-t border-slate-50">' +
                (t.is_draft ? '<button class="btn btn-dark btn-sm flex-1" onclick="Wizard.edit(' + t.id + ')"><span class="material-symbols-outlined">play_arrow</span>Continue</button>'
                            : '<button class="btn btn-dark btn-sm flex-1" onclick="Editor.open(' + t.id + ')"><span class="material-symbols-outlined">dashboard_customize</span>Layout</button>') +
                '<button class="icon-btn" title="Edit details" onclick="Wizard.edit(' + t.id + ')"><span class="material-symbols-outlined text-xl">edit_square</span></button>' +
                (s === 'active' || s === 'disabled' ? '<button class="icon-btn" title="' + (t.is_active ? 'Disable' : 'Enable') + '" onclick="toggleType(' + t.id + ',' + (t.is_active ? 0 : 1) + ')"><span class="material-symbols-outlined text-xl">' + (t.is_active ? 'toggle_on' : 'toggle_off') + '</span></button>' : '') +
                '<button class="icon-btn danger" title="Delete" onclick="deleteType(' + t.id + ')"><span class="material-symbols-outlined text-xl">delete</span></button>' +
            '</div>';
        }
        return '<div class="type-card">' +
            '<button type="button" class="thumb" onclick="openType(' + t.id + ')">' + (t.bg_image ? '<img src="' + CERT.esc(t.bg_image) + '" alt="">' : '<span class="material-symbols-outlined text-5xl text-slate-200">description</span>') + '</button>' +
            '<div class="flex items-start justify-between gap-2 px-1"><div class="min-w-0"><p class="text-sm font-bold text-slate-700 leading-tight truncate">' + CERT.esc(t.doc_type) + '</p>' +
            '<p class="text-[10px] text-slate-400 font-bold uppercase tracking-tighter mt-0.5">' + CERT.esc(t.doc_code || '—') + ' | ' + CERT.esc(t.paper_label) + '</p></div>' + badge + '</div>' +
            '<div class="flex flex-wrap gap-1 px-1">' +
            (t.page_count > 1 ? '<span class="pill bg-sky-50 text-sky-600 border-sky-100">' + t.page_count + ' pages</span>' : '') +
            '<span class="pill bg-indigo-50 text-indigo-600 border-indigo-100">' + t.field_count + ' fields</span>' +
            '<span class="pill bg-emerald-50 text-emerald-600 border-emerald-100">' + t.req_count + ' requirements</span>' +
            '<span class="pill bg-amber-50 text-amber-600 border-amber-100">' + t.extra_count + ' extra info</span>' +
            '<span class="pill bg-slate-50 text-slate-500 border-slate-200">' + t.request_count + ' issued</span></div>' +
            actions +
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
document.getElementById('typeStatus').addEventListener('change', drawTypes);

/* ───────── Wizard (steps 1–4) ───────── */
const Wizard = (function(){
    const LABELS = ['Document', 'Template', 'Requirements', 'Extra Info', 'Layout'];
    const TYPE_LABEL = { text:'Text', number:'Number', date:'Date', textarea:'Long text', select:'Dropdown' };
    let s = null, step = 1, conv = null;   // conv = converted upload: { images: [File per page], pages, w, h }

    function blank(){ return { id:0, doc_type:'', doc_code:'', description:'', paper_size:'original', requirements:[], extra_fields:[], bg_image:null, pages:[], page_w:null, page_h:null, is_draft:1 }; }

    async function start(){ s = blank(); conv = null; await openAt(1); }
    async function edit(id){
        const d = await CERT.getJSON(API + '?action=get&id=' + id);
        if (!d.success) { CERT.toast(d.message, 'error'); return; }
        s = d.type; conv = null;
        if (s.is_draft && s.draft_step >= 5) { Editor.open(id); return; }
        await openAt(s.is_draft ? Math.max(1, Math.min(4, s.draft_step)) : 1);
    }
    function paperOption(){
        const o = document.querySelector('#wPaper option[value="original"]');
        const w = conv ? conv.w : s.page_w, h = conv ? conv.h : s.page_h;
        o.disabled = !(w && h);
        o.textContent = w && h ? 'Original template size (' + (Math.round(w / 96 * 100) / 100) + ' × ' + (Math.round(h / 96 * 100) / 100) + ' in)' : 'Original template size (upload a template first)';
        const sel = document.getElementById('wPaper');
        if (o.disabled && sel.value === 'original') sel.value = 'a4';
    }
    function drawThumb(){
        const n = conv ? conv.pages : (s.pages || []).length;
        const src = conv ? URL.createObjectURL(conv.images[0]) : s.bg_image;
        document.getElementById('wThumb').innerHTML = src ? '<img src="' + CERT.esc(src) + '" alt="">' : '<span class="material-symbols-outlined text-slate-300 text-4xl">image</span>';
        document.getElementById('wImageLbl').textContent = src ? 'Replace template file' : 'Choose template file';
        if (!conv) document.getElementById('wImageName').textContent = src ? (n > 1 ? n + ' pages' : '1 page') + ' · current template' : '';
    }
    async function openAt(n){
        await loadCatalog();
        document.getElementById('wizEyebrow').textContent = s.id ? (s.is_draft ? 'Continue Draft' : 'Edit Document') : 'Add Document';
        document.getElementById('wizTitle').textContent = s.id ? s.doc_type : 'New document type';
        document.getElementById('wName').value = s.doc_type || '';
        document.getElementById('wCode').value = s.doc_code || '';
        document.getElementById('wDesc').value = s.description || '';
        document.getElementById('wImage').value = '';
        document.getElementById('wImageName').textContent = '';
        document.getElementById('wPaper').value = s.paper_size || 'original';
        paperOption();
        drawThumb();
        document.getElementById('wDraft').classList.toggle('hidden', !!(s.id && !s.is_draft));
        ['wReqInput', 'wExLabel', 'wExOptions'].forEach(i => document.getElementById(i).value = '');
        document.getElementById('wExType').value = 'text'; document.getElementById('wExRequired').checked = true;
        document.getElementById('wExOptions').classList.add('hidden');
        hidePending();
        drawReqs(); drawExtras();
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
    }
    function validate(n){
        if (n >= 1 && !s.doc_type) return 'Enter the document name.';
        if (n >= 2 && !s.bg_image && !conv) return 'Upload the template file (Word, PDF or image).';
        return '';
    }
    /** Typed in Requirements / Extra Information but not added yet → stay on the step and say so near the field. */
    function pendingItem(n){
        if (n === 3 && document.getElementById('wReqInput').value.trim()) return ['wReqPending', 'wReqInput'];
        if (n === 4 && document.getElementById('wExLabel').value.trim()) return ['wExPending', 'wExLabel'];
        return null;
    }
    function hidePending(){ ['wReqPending', 'wExPending'].forEach(i => document.getElementById(i).style.display = 'none'); }
    async function save(draft, nextStep){
        collect();
        const err = draft ? (s.doc_type ? '' : 'Enter the document name.') : validate(step);
        if (err) { document.getElementById('wMsg').textContent = err; return null; }
        const fd = new FormData();
        fd.append('action', 'save_type'); fd.append('id', s.id || 0);
        fd.append('doc_type', s.doc_type); fd.append('doc_code', s.doc_code); fd.append('description', s.description);
        fd.append('paper_size', s.paper_size);
        fd.append('requirements', JSON.stringify(s.requirements));
        fd.append('extra_fields', JSON.stringify(s.extra_fields.map(e => ({ field_key:e.field_key || '', label:e.label, input_type:e.input_type, options:e.options || [], is_required:e.is_required ? 1 : 0 }))));
        fd.append('draft', draft || s.is_draft ? '1' : '0');
        fd.append('step', nextStep || step);
        if (conv) {
            // Every page of the template, in order (page 1 + the others).
            fd.append('template_image', conv.images[0]);
            conv.images.slice(1).forEach(f => fd.append('template_pages[]', f));
            if (conv.w && conv.h) { fd.append('page_w', conv.w); fd.append('page_h', conv.h); }
        }
        const btns = document.querySelectorAll('#wizardModal .modal-foot .btn'); btns.forEach(b => b.disabled = true);
        try {
            const d = await CERT.post(API, fd);
            if (!d.success) { document.getElementById('wMsg').textContent = d.message; return null; }
            s = d.type; conv = null;
            if (draft) { CERT.toast(d.message, 'success'); close(); }
            loadTypes();
            return d;
        } catch (e) { document.getElementById('wMsg').textContent = e.message; return null; }
        finally { btns.forEach(b => b.disabled = false); }
    }
    async function next(){
        collect();
        const pend = pendingItem(step);
        if (pend) {
            document.getElementById(pend[0]).style.display = 'flex';
            document.getElementById(pend[1]).focus();
            document.getElementById('wMsg').textContent = '';
            return;
        }
        const err = validate(step);
        if (err) { document.getElementById('wMsg').textContent = err; return; }
        if (step < 4) {
            // Save quietly on every step so nothing is lost; new documents stay drafts until the layout is saved.
            const d = await save(false, step + 1);
            if (d) { drawThumb(); paperOption(); drawExtras(); go(step + 1); }
            return;
        }
        const d = await save(false, 5);
        if (d) { close(); Editor.open(s.id); }
    }
    function back(){ collect(); hidePending(); if (step > 1) go(step - 1); }
    function close(){ CERT.close('wizardModal'); }

    function drawReqs(){
        const ul = document.getElementById('wReqs');
        ul.innerHTML = s.requirements.length ? s.requirements.map((r, i) => '<li class="flex items-center gap-2 px-4 py-3 rounded-2xl border border-slate-100 bg-slate-50"><span class="material-symbols-outlined text-slate-400 text-lg">checklist</span><span class="flex-1 text-sm font-semibold text-slate-700">' + CERT.esc(r) + '</span><button type="button" class="text-slate-400 hover:text-red-600" onclick="Wizard.delReq(' + i + ')"><span class="material-symbols-outlined">close</span></button></li>').join('')
            : '<li class="text-sm text-slate-400">No requirements yet.</li>';
    }
    function addReq(){
        const inp = document.getElementById('wReqInput'); const v = inp.value.trim();
        if (!v) return;
        if (!s.requirements.some(r => r.toLowerCase() === v.toLowerCase())) s.requirements.push(v);
        inp.value = ''; hidePending(); drawReqs(); inp.focus();
    }
    function delReq(i){ s.requirements.splice(i, 1); drawReqs(); }
    function drawExtras(){
        const host = document.getElementById('wExtras');
        host.innerHTML = s.extra_fields.length ? s.extra_fields.map((e, i) =>
            '<div class="rounded-2xl border border-slate-100 bg-slate-50/60 p-4 grid sm:grid-cols-[1fr_150px] gap-3 items-start">' +
            '<input class="input" maxlength="150" placeholder="Label, e.g. Company Name" value="' + CERT.esc(e.label) + '" oninput="Wizard.setExtra(' + i + ',\'label\',this.value)">' +
            '<select class="input" onchange="Wizard.setExtra(' + i + ',\'input_type\',this.value)">' + Object.keys(TYPE_LABEL).map(t => '<option value="' + t + '"' + (e.input_type === t ? ' selected' : '') + '>' + TYPE_LABEL[t] + '</option>').join('') + '</select>' +
            (e.input_type === 'select' ? '<input class="input sm:col-span-2" placeholder="Choices, separated by commas" value="' + CERT.esc((e.options || []).join(', ')) + '" oninput="Wizard.setExtra(' + i + ',\'options\',this.value)">' : '') +
            '<div class="sm:col-span-2 flex flex-wrap items-center gap-4 text-xs font-semibold text-slate-600">' +
            '<label class="inline-flex items-center gap-1.5"><input type="checkbox"' + (e.is_required ? ' checked' : '') + ' onchange="Wizard.setExtra(' + i + ',\'is_required\',this.checked)">Required</label>' +
            '<button type="button" class="ml-auto text-red-600 inline-flex items-center gap-1" onclick="Wizard.delExtra(' + i + ')"><span class="material-symbols-outlined text-base">delete</span>Remove</button></div></div>').join('')
            : '<p class="text-sm text-slate-400">No extra information fields yet. Purpose is always asked.</p>';
    }
    function addExtra(){
        const lbl = document.getElementById('wExLabel'), type = document.getElementById('wExType').value, opt = document.getElementById('wExOptions');
        const label = lbl.value.trim();
        if (!label) { lbl.focus(); return; }
        const options = type === 'select' ? opt.value.split(',').map(x => x.trim()).filter(Boolean) : [];
        if (type === 'select' && !options.length) { document.getElementById('wMsg').textContent = '"' + label + '" is a dropdown — enter its choices first.'; opt.focus(); return; }
        if (s.extra_fields.some(e => e.label.toLowerCase() === label.toLowerCase())) { document.getElementById('wMsg').textContent = '"' + label + '" is already added.'; lbl.focus(); return; }
        s.extra_fields.push({ field_key:'', label:label, input_type:type, options:options, is_required:document.getElementById('wExRequired').checked ? 1 : 0 });
        lbl.value = ''; opt.value = ''; document.getElementById('wMsg').textContent = '';
        hidePending(); drawExtras(); lbl.focus();
    }
    function setExtra(i, k, v){
        const e = s.extra_fields[i];
        if (k === 'options') e.options = v.split(',').map(x => x.trim()).filter(Boolean); else e[k] = v;
        if (k === 'input_type') drawExtras();
    }
    function delExtra(i){ s.extra_fields.splice(i, 1); drawExtras(); }

    /** Page size in CSS px (96 dpi) of page 1 of a PDF (pdf.js is loaded by TemplateConvert). */
    async function pdfPageSize(pdf){
        try {
            const doc = await window.pdfjsLib.getDocument({ data: new Uint8Array(await pdf.arrayBuffer()) }).promise;
            const vp = (await doc.getPage(1)).getViewport({ scale: 1 });
            doc.destroy();
            return { w: Math.round(vp.width * 96 / 72), h: Math.round(vp.height * 96 / 72) };
        } catch (e) { return null; }
    }
    function imageSize(file){
        return new Promise(ok => { const im = new Image(); im.onload = () => ok({ w: 816, h: Math.round(816 * im.naturalHeight / im.naturalWidth) }); im.onerror = () => ok(null); im.src = URL.createObjectURL(file); });
    }
    document.getElementById('wImage').addEventListener('change', async function(){
        const f = this.files[0]; if (!f) return;
        const msg = document.getElementById('wMsg'), name = document.getElementById('wImageName');
        msg.textContent = ''; name.textContent = 'Reading ' + f.name + '…';
        const btns = document.querySelectorAll('#wizardModal .modal-foot .btn'); btns.forEach(b => b.disabled = true);
        try {
            let src = f;
            if (/\.doc$/i.test(f.name)) {
                // Browsers cannot read old .doc files: the server converts it to PDF (needs LibreOffice there).
                const fd = new FormData(); fd.append('action', 'convert_doc'); fd.append('file', f); fd.append('csrf_token', CERT.csrf);
                const r = await fetch(API, { method:'POST', body:fd, credentials:'same-origin', headers:{ 'X-CSRF-Token':CERT.csrf } });
                if (!r.ok || !(r.headers.get('Content-Type') || '').includes('pdf')) { const j = await r.json().catch(() => ({})); throw new Error(j.message || 'The .doc file could not be converted. Save it as DOCX or PDF.'); }
                src = new File([await r.blob()], f.name.replace(/\.doc$/i, '.pdf'), { type:'application/pdf' });
            }
            const out = await TemplateConvert.convert(src);
            const size = out.pdf ? await pdfPageSize(out.pdf) : await imageSize(out.image);
            conv = { images: out.images, pages: out.images.length, w: size ? size.w : null, h: size ? size.h : null };
            name.textContent = f.name + ' · ' + (out.note || (conv.pages + ' page(s).'));
            paperOption();
            if (conv.w && conv.h) document.getElementById('wPaper').value = 'original';
            drawThumb();
        } catch (e) {
            conv = null; this.value = ''; name.textContent = ''; msg.textContent = e.message || 'Could not read the template file.';
            drawThumb();
        } finally { btns.forEach(b => b.disabled = false); }
    });
    document.getElementById('wReqInput').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addReq(); } });
    document.getElementById('wReqInput').addEventListener('input', hidePending);
    document.getElementById('wExLabel').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addExtra(); } });
    document.getElementById('wExLabel').addEventListener('input', hidePending);
    document.getElementById('wExType').addEventListener('change', function(){ document.getElementById('wExOptions').classList.toggle('hidden', this.value !== 'select'); });
    return { start, edit, next, back, save, close, addReq, delReq, addExtra, setExtra, delExtra };
})();

/* ───────── Step 5: Layout editor ───────── */
const Editor = (function(){
    let ed = null, cur = null;
    async function open(id){
        const d = await CERT.getJSON(API + '?action=get&id=' + id);
        if (!d.success) { CERT.toast(d.message, 'error'); return; }
        cur = d.type;
        if (!cur.bg_image) { CERT.toast('Upload the template first.', 'warning'); Wizard.edit(id); return; }
        document.getElementById('edTitle').textContent = cur.doc_type + (cur.is_draft ? ' · Not finished' : '');
        document.getElementById('edPaper').textContent = cur.paper.label + (cur.page_count > 1 ? ' · ' + cur.page_count + ' pages' : '');
        document.getElementById('editorOverlay').classList.add('open');
        document.body.style.overflow = 'hidden';
        const values = {};
        cur.groups.forEach(g => g.items.forEach(i => values[i.key] = i.sample));
        if (ed) ed.destroy();
        ed = CertEditor.mount(document.getElementById('editorHost'), {
            paper: cur.paper, bg_image: cur.bg_image, bg_opacity: cur.bg_opacity, pages: cur.pages,
            positions: cur.positions, groups: cur.groups, values: values,
            saveLabel: cur.is_draft ? 'Save & Finish' : 'Save Layout',
            note: cur.legacy_custom_layout ? 'This document used the removed Custom Layout option. It keeps printing its old layout (read-only) until you save a layout here.'
                : (cur.positions.length ? 'Sample values are shown. Issued documents use the resident\'s real data.'
                                        : 'Choose the fields to print from the list (click, then drag onto the blank) — or click AI Auto Detect to find them from the template.'),
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
                return { positions:r.positions, unplaced:r.unplaced || [] };
            },
        });
    }
    function shut(){
        if (ed) { ed.destroy(); ed = null; }
        document.getElementById('editorOverlay').classList.remove('open');
        document.body.style.overflow = '';
    }
    /** Close asks first; "Close" discards every unsaved change (nothing is written to the database). */
    async function close(){
        const ok = await CERT.confirm({ title:'Close layout?', message:'Your changes will not be saved and will be deleted. Do you want to close?', ok:'Close', cancel:'Cancel', danger:true, icon:'warning' });
        if (!ok) return false;
        shut();
        return true;
    }
    async function clear(){
        if (!ed) return;
        const ok = await CERT.confirm({ title:'Clear layout?', message:'Are you sure you want to clear the current layout? All unsaved layout changes will be removed.', ok:'Clear', cancel:'Cancel', danger:true, icon:'layers_clear' });
        if (ok) ed.clear();
    }
    /** Archive = save the current progress as "Not finished" (not issuable); continue it later from the Not finished list. */
    async function archive(){
        if (!ed || !cur) return;
        const ok = await CERT.confirm({ title:'Archive "' + cur.doc_type + '"', message:'You can continue working on this document type later. Your current progress will be saved as Not Finished.', ok:'Archive', cancel:'Cancel', icon:'archive' });
        if (!ok) return;
        const r = await CERT.post(API, { action:'archive', id:cur.id, positions:ed.positions() }).catch(e => ({ success:false, message:e.message }));
        CERT.toast(r.success ? 'You can continue working on this document type later. Your current progress was saved as Not Finished.' : r.message, r.success ? 'success' : 'error');
        if (!r.success) return;
        shut(); loadTypes();
    }
    async function details(){ const id = cur.id; if (await close()) Wizard.edit(id); }
    window.addEventListener('beforeunload', e => { if (ed && ed.isDirty()) { e.preventDefault(); e.returnValue = ''; } });
    return { open, close, clear, archive, details };
})();

loadTypes().then(function(){ <?php if ($openId): ?>openType(<?php echo $openId; ?>);<?php endif; ?> });
</script>
</body>
</html>

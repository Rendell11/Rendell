<?php
/**
 * Certificates (Legal Documents) — main page.
 * Cards (with the new-online-request bubble), tabs Pending · Online Queue · Released · Expired · Walk-in · All,
 * the 6-step Issue Walk-In, Preview (Edit / Print & Release), online Review (Accept / Reject) and View.
 * All data comes from ../backend/cert_actions.php; status rules are enforced there.
 */
require_once __DIR__ . '/../../db.php';
$required_module = 'certificates';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../backend/cert_common.php';

$current_page = 'Certificates';
$pageTitle = 'Certificates';
$db_error = '';
$docTypes = [];
try {
    cert_migrate($pdo);
    $docTypes = cert_doc_types($pdo, true);
    $allTypes = array_column(cert_doc_types($pdo, false), 'doc_type');
} catch (Throwable $e) { $db_error = 'Database setup failed. Check the PHP error log.'; error_log('[Certificates] ' . $e->getMessage()); $allTypes = []; }
$canCreate = cert_can($pdo, 'create');
$canUpdate = cert_can($pdo, 'update');
$tab = in_array($_GET['tab'] ?? '', ['pending', 'queue', 'released', 'expired', 'walkin', 'all'], true) ? $_GET['tab'] : 'pending';
?>
<!doctype html>
<html <?php require_once __DIR__ . '/../../theme_loader.php'; echo $theme_attrs['html'] ?? ''; ?>>
<head>
    <?php require __DIR__ . '/partials/cert_head.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
    <link rel="stylesheet" href="assets/cert_editor.css?v=<?php echo @filemtime(__DIR__ . '/assets/cert_editor.css'); ?>">
    <style>
        .stat { transition:transform .2s, box-shadow .2s; }
        .stat:hover { transform:translateY(-2px); box-shadow:0 16px 36px -14px rgba(15,23,42,.18); }
        .bubble { position:absolute; top:1.25rem; right:1.25rem; min-width:24px; height:24px; padding:0 7px; border-radius:999px; background:#ef4444; color:#fff; font-size:.7rem; font-weight:900; display:flex; align-items:center; justify-content:center; box-shadow:0 0 0 3px #fff; }
        .bubble.hidden { display:none; }
        .bubble.pulse { animation:pulse 1.6s infinite; }
        @keyframes pulse { 0%{box-shadow:0 0 0 3px #fff,0 0 0 3px rgba(239,68,68,.5)} 70%{box-shadow:0 0 0 3px #fff,0 0 0 12px rgba(239,68,68,0)} 100%{box-shadow:0 0 0 3px #fff,0 0 0 3px rgba(239,68,68,0)} }
        .tab { padding:.5rem 1rem; border-radius:.75rem; font-size:10px; font-weight:900; letter-spacing:.08em; text-transform:uppercase; color:#64748b; background:#f8fafc; border:1px solid #f1f5f9; display:inline-flex; align-items:center; gap:.45rem; white-space:nowrap; transition:all .15s; }
        .tab:hover { color:var(--accent-600); border-color:#e0e7ff; }
        .tab .cnt { background:#fff; color:#475569; border-radius:.4rem; padding:0 .4rem; font-size:10px; border:1px solid #e2e8f0; }
        .tab.active { background:var(--accent-600); border-color:var(--accent-600); color:#fff; box-shadow:0 10px 15px -3px rgba(99,102,241,.2); } .tab.active .cnt { background:rgba(255,255,255,.2); border-color:transparent; color:#fff; }
        .row-new { background:linear-gradient(90deg,rgba(239,68,68,.05),transparent 45%); }
        .check { display:flex; align-items:flex-start; gap:.75rem; padding:.9rem 1rem; border-radius:1.25rem; border:1px solid; }
        .check .material-symbols-outlined { font-size:20px; }
        .check.ok { background:#ecfdf5; border-color:#a7f3d0; color:#065f46; }
        .check.bad { background:#fff1f2; border-color:#fecaca; color:#991b1b; }
        .check.warn { background:#fffbeb; border-color:#fde68a; color:#92400e; }
        .req { display:flex; align-items:center; gap:.75rem; padding:.85rem 1rem; background:#f8fafc; border:1px solid #f1f5f9; border-radius:1rem; font-size:.85rem; font-weight:700; color:#334155; cursor:pointer; transition:all .15s; }
        .req:has(input:checked) { border-color:#a7f3d0; background:#ecfdf5; color:#065f46; }
        .req input { width:18px; height:18px; accent-color:#10b981; border-radius:.3rem; }
        .doc-opt { text-align:left; border:1px solid #f1f5f9; border-radius:1.5rem; padding:1.1rem; display:flex; gap:.85rem; align-items:flex-start; background:#fff; box-shadow:0 1px 2px rgba(15,23,42,.05); transition:all .15s; }
        .doc-opt:hover { border-color:#c7d2fe; }
        .doc-opt.sel { border-color:var(--accent-600); background:#eef2ff; box-shadow:0 0 0 3px rgba(99,102,241,.12); }
        .kv { display:grid; grid-template-columns:140px 1fr; gap:.5rem 1rem; font-size:.82rem; }
        .kv dt { color:#94a3b8; font-weight:700; font-size:10px; text-transform:uppercase; letter-spacing:.06em; padding-top:.15rem; }
        .kv dd { color:#1e293b; font-weight:700; margin:0; word-break:break-word; }
        .mini-table { width:100%; font-size:.78rem; }
        .mini-table th { text-align:left; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:#94a3b8; padding:.6rem .75rem; background:rgba(248,250,252,.7); }
        .mini-table td { padding:.65rem .75rem; border-top:1px solid #f8fafc; font-weight:600; color:#334155; }
        .ts-wrapper.single .ts-control { border-radius:.75rem !important; padding:.75rem 1rem !important; background:#f1f5f9 !important; border:0 !important; font-size:.875rem; font-weight:700; box-shadow:none !important; }
        .ts-wrapper.single.focus .ts-control { box-shadow:0 0 0 2px rgba(99,102,241,.2) !important; }
        .ts-dropdown { border-radius:1rem !important; border:1px solid #f1f5f9 !important; box-shadow:0 20px 40px -12px rgba(15,23,42,.2) !important; overflow:hidden; margin-top:.35rem !important; }
        .ts-dropdown .option { padding:.65rem 1rem !important; }
        .ts-dropdown .active { background:#eef2ff !important; color:inherit !important; }
        #previewHost, #viewDocHost { background:#f1f5f9; border-radius:1.5rem; padding:14px; }
        #editOverlay { position:fixed; inset:0; z-index:80; background:#0f172a; display:none; flex-direction:column; }
        #editOverlay.open { display:flex; }
        #editHost { flex:1; min-height:0; }
        .timeline li { position:relative; padding-left:1.4rem; padding-bottom:.9rem; }
        .timeline li::before { content:''; position:absolute; left:5px; top:6px; bottom:-2px; width:2px; background:#e2e8f0; }
        .timeline li:last-child::before { display:none; }
        .timeline li::after { content:''; position:absolute; left:0; top:4px; width:12px; height:12px; border-radius:999px; background:#fff; border:3px solid var(--accent-600); }
        .pager a, .pager button { display:inline-flex; align-items:center; gap:.25rem; padding:.375rem .75rem; border-radius:.75rem; font-size:10px; font-weight:900; text-transform:uppercase; letter-spacing:.05em; transition:all .15s; }
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
                        <h1 class="text-2xl md:text-3xl font-black tracking-tight leading-none">Certificate Management</h1>
                        <p class="text-white/60 text-sm mt-2 font-medium">Issue walk-in documents, review online requests and release printed certificates.</p>
                    </div>
                    <div class="flex flex-wrap gap-3 flex-shrink-0">
                        <a href="certificate_analytics.php" class="hero-btn"><span class="material-symbols-outlined">analytics</span>Analytics</a>
                        <a href="document_templates.php" class="hero-btn"><span class="material-symbols-outlined">design_services</span>Templates</a>
                        <?php if ($canCreate): ?>
                        <button type="button" onclick="WalkIn.open()" class="hero-btn hero-btn-primary"><span class="material-symbols-outlined">person_add</span>Issue Walk-In</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($db_error): ?>
                <div class="rounded-2xl border border-rose-200 bg-rose-50 text-rose-700 px-5 py-4 text-sm font-bold"><?php echo h($db_error); ?></div>
            <?php elseif (!$docTypes): ?>
                <div class="rounded-2xl border border-amber-200 bg-amber-50 text-amber-800 px-5 py-4 text-sm font-bold flex items-center gap-2"><span class="material-symbols-outlined">info</span>No finished document types yet. Open <a class="underline" href="document_templates.php">Templates</a> to add one before issuing.</div>
            <?php endif; ?>

            <!-- ── Search & Filter Row ── -->
            <div class="grid grid-cols-12 gap-4">
                <div class="col-span-12 md:col-span-6 xl:col-span-8 relative">
                    <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-xl">search</span>
                    <input id="q" type="search" placeholder="Search by resident name, Resident ID, reference or document no.…" class="search-pill">
                </div>
                <div class="col-span-6 md:col-span-3 xl:col-span-2">
                    <select id="docFilter" class="select-pill">
                        <option value="">All Documents</option>
                        <?php foreach ($allTypes as $t): ?><option value="<?php echo h($t); ?>"><?php echo h($t); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-span-6 md:col-span-3 xl:col-span-2">
                    <select id="typeFilter" class="select-pill">
                        <option value="">All Types</option>
                        <option value="walk-in">Walk-in</option>
                        <option value="online">Online</option>
                    </select>
                </div>
            </div>

            <!-- ── Stats Cards (Resident style) ── -->
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-6">
                <?php foreach ([
                    ['pending', 'Online Requests', 'online', 'cloud_download', 'bg-rose-50 text-rose-600', true],
                    ['pending', 'Pending / Review', 'pending', 'rate_review', 'bg-amber-50 text-amber-600', false],
                    ['queue', 'Ready to Pick Up', 'queue', 'inventory_2', 'bg-sky-50 text-sky-600', false],
                    ['released', 'Released', 'released', 'task_alt', 'bg-emerald-50 text-emerald-600', false],
                    ['expired', 'Expired', 'expired', 'hourglass_disabled', 'bg-slate-100 text-slate-500', false],
                ] as [$tabKey, $label, $count, $icon, $tile, $bubble]): ?>
                <button type="button" onclick="setTab('<?php echo $tabKey; ?>')" class="stat stat-card relative text-left">
                    <?php if ($bubble): ?><span id="onlineBubble" class="bubble hidden" title="New online requests"></span><?php endif; ?>
                    <div class="stat-icon <?php echo $tile; ?>"><span class="material-symbols-outlined"><?php echo $icon; ?></span></div>
                    <p class="stat-label"><?php echo h($label); ?></p>
                    <h3 class="stat-value" data-count="<?php echo $count; ?>">0</h3>
                </button>
                <?php endforeach; ?>
            </div>

            <!-- ── Requests Table (Resident table style) ── -->
            <div class="table-card">
                <div class="px-8 pt-6 pb-4 flex flex-wrap items-center justify-between gap-3 border-b border-slate-50">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-white bg-primary p-2 rounded-xl shadow-md">description</span>
                        <div><h2 class="text-base font-black text-slate-800 leading-tight">Document Requests</h2>
                            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-0.5"><span data-count="new_online">0</span> new online · <span data-count="preview">0</span> walk-in waiting to print</p></div>
                    </div>
                    <div class="flex gap-2 overflow-x-auto pb-1" id="tabs">
                        <?php foreach (['pending' => ['Pending', 'pending'], 'queue' => ['Online Queue', 'queue'], 'released' => ['Released', 'released'],
                                        'expired' => ['Expired', 'expired'], 'walkin' => ['Walk-in', 'walkin'], 'all' => ['All', 'total']] as $k => [$lbl, $cnt]): ?>
                        <button type="button" class="tab<?php echo $tab === $k ? ' active' : ''; ?>" data-tab="<?php echo $k; ?>" onclick="setTab('<?php echo $k; ?>')"><?php echo h($lbl); ?><span class="cnt" data-count="<?php echo $cnt; ?>">0</span></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead><tr><th>Resident Info</th><th>Document</th><th>Reference / Doc No.</th><th>Requested</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
                        <tbody id="rows" class="divide-y divide-slate-50"><tr><td colspan="6" class="text-center py-16 text-slate-400">Loading…</td></tr></tbody>
                    </table>
                </div>
                <div id="pager" class="pager hidden flex flex-col sm:flex-row items-center justify-between gap-3 px-8 py-4 border-t border-slate-50"></div>
            </div>
        </main>
    </div>
</div>

<!-- ═════════ Issue Walk-In (6 steps) ═════════ -->
<div id="walkInModal" class="modal-back">
  <div class="modal-box" style="max-width:900px">
    <div class="modal-head">
      <div class="min-w-0">
        <h3 class="modal-title" id="wiTitle">Search Resident</h3>
        <p class="modal-sub">Issue Walk-In · Certificate Request</p>
        <div class="steps mt-4" id="wiSteps"></div>
      </div>
      <button type="button" class="modal-close" onclick="WalkIn.close()"><span class="material-symbols-outlined">close</span></button>
    </div>
    <div class="modal-body">
      <div data-wi="1" class="space-y-4">
        <div class="sec-head"><span class="material-symbols-outlined">person_search</span><h4>Find the Resident</h4></div>
        <div><label class="field-label" for="wiResident">Resident *</label><select id="wiResident" placeholder="Type a name, Resident ID or address…"></select>
          <p class="text-[11px] text-slate-400 font-medium mt-1.5 ml-1">Results narrow as you type. Deceased residents are not listed.</p></div>
        <div id="wiResidentCard"></div>
      </div>
      <div data-wi="2" class="hidden">
        <div class="sec-head"><span class="material-symbols-outlined">description</span><h4>Choose the Document</h4></div>
        <p class="text-xs text-slate-500 font-medium mb-4">Only finished, active documents are listed.</p>
        <div id="wiDocs" class="grid sm:grid-cols-2 gap-3"></div>
      </div>
      <div data-wi="3" class="hidden space-y-3">
        <div class="flex items-center justify-between gap-2"><div class="sec-head !mb-0"><span class="material-symbols-outlined">checklist</span><h4>Requirements</h4></div><button type="button" class="btn btn-ghost btn-sm" onclick="WalkIn.checkAll()"><span class="material-symbols-outlined">done_all</span>Check all</button></div>
        <p class="text-xs text-slate-500 font-medium">All requirements must be presented and checked.</p>
        <div id="wiReqs" class="space-y-2"></div>
      </div>
      <div data-wi="4" class="hidden space-y-4" id="wiElig"></div>
      <div data-wi="5" class="hidden space-y-5">
        <div class="sec-head"><span class="material-symbols-outlined">edit_note</span><h4>Extra Information</h4></div>
        <div><label class="field-label" for="wiPurpose">Purpose *</label><input id="wiPurpose" class="input" maxlength="500" placeholder="e.g. Employment requirement" list="purposeList">
          <datalist id="purposeList"><option>Employment</option><option>Local employment</option><option>Scholarship</option><option>Bank requirement</option><option>School requirement</option><option>Business permit</option><option>Travel</option><option>Medical assistance</option><option>Financial assistance</option><option>Legal purposes</option></datalist></div>
        <div id="wiExtra" class="grid sm:grid-cols-2 gap-4"></div>
        <div><label class="field-label" for="wiPhoto">Applicant photo (optional)</label><input id="wiPhoto" type="file" accept="image/png,image/jpeg,image/webp" class="text-sm font-semibold text-slate-500 file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:bg-indigo-50 file:text-indigo-600 file:font-bold file:text-xs"></div>
      </div>
      <div data-wi="6" class="hidden" id="wiSummary"></div>
    </div>
    <div class="modal-foot">
      <p id="wiMsg" class="text-[11px] font-bold text-rose-600 mr-auto"></p>
      <button type="button" class="btn btn-ghost" id="wiBack" onclick="WalkIn.back()"><span class="material-symbols-outlined">arrow_back</span>Back</button>
      <button type="button" class="btn btn-dark" id="wiNext" onclick="WalkIn.next()">Next<span class="material-symbols-outlined">arrow_forward</span></button>
    </div>
  </div>
</div>

<!-- ═════════ Preview (Edit / Print & Release) ═════════ -->
<div id="previewModal" class="modal-back">
  <div class="modal-box" style="max-width:1000px">
    <div class="modal-head">
      <div class="min-w-0"><h3 class="modal-title truncate" id="pvTitle"></h3><p class="modal-sub" id="pvEyebrow">Preview</p><p class="text-xs text-slate-400 font-semibold mt-1" id="pvSub"></p></div>
      <button type="button" class="modal-close" onclick="CERT.close('previewModal')"><span class="material-symbols-outlined">close</span></button>
    </div>
    <div class="modal-body"><div id="pvNote"></div><div id="previewHost"></div></div>
    <div class="modal-foot">
      <p class="text-[11px] text-slate-400 font-semibold mr-auto" id="pvFootNote"></p>
      <button type="button" class="btn btn-ghost" onclick="CERT.close('previewModal')">Close</button>
      <?php if ($canUpdate): ?>
      <button type="button" class="btn btn-ghost" id="pvEdit" onclick="Preview.edit()"><span class="material-symbols-outlined">edit</span>Edit</button>
      <button type="button" class="btn btn-green" id="pvRelease" onclick="Preview.release()"><span class="material-symbols-outlined">print</span>Print &amp; Release</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Per-document layout editor -->
<div id="editOverlay">
  <div class="flex items-center justify-between gap-3 px-5 py-3 hero-band text-white">
    <div class="min-w-0"><p class="text-[10px] font-black uppercase tracking-[0.18em] text-white/60">Edit this document only · template is not changed</p><p class="font-black truncate" id="edTitle"></p></div>
    <button type="button" class="hero-btn hero-btn-white" onclick="Preview.closeEdit()"><span class="material-symbols-outlined">close</span>Back to preview</button>
  </div>
  <div id="editHost"></div>
</div>

<!-- ═════════ Online review (Accept / Reject) ═════════ -->
<div id="reviewModal" class="modal-back">
  <div class="modal-box" style="max-width:940px">
    <div class="modal-head">
      <div class="min-w-0"><h3 class="modal-title truncate" id="rvTitle"></h3><p class="modal-sub">Online Request · Review</p><p class="text-xs text-slate-400 font-semibold mt-1" id="rvSub"></p></div>
      <button type="button" class="modal-close" onclick="CERT.close('reviewModal')"><span class="material-symbols-outlined">close</span></button>
    </div>
    <div class="modal-body space-y-4" id="rvBody"></div>
    <div class="modal-foot">
      <button type="button" class="btn btn-ghost mr-auto" onclick="CERT.close('reviewModal')">Close</button>
      <?php if ($canUpdate): ?>
      <button type="button" class="btn btn-red" id="rvReject" onclick="Review.reject()"><span class="material-symbols-outlined">block</span>Reject</button>
      <button type="button" class="btn btn-green" id="rvAccept" onclick="Review.accept()"><span class="material-symbols-outlined">check</span>Accept</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<div id="rejectModal" class="modal-back" style="z-index:75">
  <div class="modal-box" style="max-width:480px">
    <div class="modal-head"><div><h3 class="modal-title">Reject Request</h3><p class="modal-sub">Reason for rejection</p></div>
      <button type="button" class="modal-close" onclick="CERT.close('rejectModal')"><span class="material-symbols-outlined">close</span></button></div>
    <div class="modal-body space-y-3">
      <div><label class="field-label" for="rjReason">Reason *</label>
      <select id="rjReason" class="input">
        <option value="">Choose a reason…</option>
        <option>Incomplete requirements</option><option>Invalid information</option><option>Active blotter case</option><option>Duplicate request</option><option>Others</option>
      </select></div>
      <textarea id="rjOther" class="input hidden" rows="3" maxlength="480" placeholder="Type the reason (required)"></textarea>
      <p class="text-[11px] text-slate-400 font-medium ml-1">The resident is notified with this reason.</p>
      <p id="rjMsg" class="text-[11px] font-bold text-rose-600 ml-1"></p>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="CERT.close('rejectModal')">Cancel</button><button type="button" class="btn btn-red" onclick="Review.confirmReject()"><span class="material-symbols-outlined">block</span>Reject Request</button></div>
  </div>
</div>

<!-- ═════════ View (details + status log) ═════════ -->
<div id="viewModal" class="modal-back">
  <div class="modal-box" style="max-width:1000px">
    <div class="modal-head">
      <div class="min-w-0"><h3 class="modal-title truncate" id="vwTitle"></h3><p class="modal-sub">View · read only</p><p class="text-xs text-slate-400 font-semibold mt-1" id="vwSub"></p></div>
      <button type="button" class="modal-close" onclick="CERT.close('viewModal')"><span class="material-symbols-outlined">close</span></button>
    </div>
    <div class="modal-body" id="vwBody"></div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="CERT.close('viewModal')">Close</button></div>
  </div>
</div>

<!-- Blotter case pop-up -->
<div id="blotterModal" class="modal-back" style="z-index:85">
  <div class="modal-box" style="max-width:640px">
    <div class="modal-head"><div><h3 class="modal-title" id="bcTitle"></h3><p class="modal-sub">Blotter Case</p></div>
      <button type="button" class="modal-close" onclick="CERT.close('blotterModal')"><span class="material-symbols-outlined">close</span></button></div>
    <div class="modal-body" id="bcBody"></div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="CERT.close('blotterModal')">Close</button></div>
  </div>
</div>

<script src="assets/cert_render.js?v=<?php echo @filemtime(__DIR__ . '/assets/cert_render.js'); ?>"></script>
<script src="assets/cert_editor.js?v=<?php echo @filemtime(__DIR__ . '/assets/cert_editor.js'); ?>"></script>
<script>
const API = '../backend/cert_actions.php';
const DOC_TYPES = <?php echo json_encode(array_map(fn($t) => ['doc_type' => $t['doc_type'], 'doc_code' => $t['doc_code'], 'description' => $t['description'] ?? ''], $docTypes), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
const CAN_UPDATE = <?php echo $canUpdate ? 'true' : 'false'; ?>;
const esc = CERT.esc;
let TAB = <?php echo json_encode($tab); ?>;

/* ───────── helpers ───────── */
function pill(status, cls){ return '<span class="pill ' + cls + '">' + esc(status) + '</span>'; }
function kv(rows){ return '<dl class="kv">' + rows.filter(r => r).map(r => '<dt>' + esc(r[0]) + '</dt><dd>' + (r[2] ? r[1] : esc(r[1] == null || r[1] === '' ? '—' : r[1])) + '</dd>').join('') + '</dl>'; }
function section(title, html, icon){ return '<section class="sec-box"><div class="sec-head"><span class="material-symbols-outlined">' + (icon || 'info') + '</span><h4>' + esc(title) + '</h4></div>' + html + '</section>'; }
function initials(name){ const p = String(name || '').trim().split(/\s+/); return ((p[0] || '').charAt(0) + (p.length > 1 ? p[p.length - 1].charAt(0) : '')).toUpperCase() || '?'; }

function eligibilityHTML(el, opts){
    opts = opts || {};
    const chk = (ok, title, note, warn) => '<div class="check ' + (ok ? 'ok' : (warn ? 'warn' : 'bad')) + '"><span class="material-symbols-outlined">' + (ok ? 'check_circle' : (warn ? 'warning' : 'cancel')) + '</span><div><p class="text-sm font-black">' + esc(title) + '</p><p class="text-[11px] opacity-80">' + esc(note) + '</p></div></div>';
    const b = el.blotter || [];
    let h = '<div class="grid sm:grid-cols-3 gap-2">' +
        chk(el.verified, 'Verified', el.verified_note) +
        chk(el.active, 'Active', el.active_note) +
        chk(!b.length, b.length ? 'Active blotter' : 'No active blotter', b.length ? b.length + ' active case(s) — review below' : 'No open blotter case', true) + '</div>';
    if (b.length) {
        h += section('Active blotter cases', '<div class="overflow-x-auto"><table class="mini-table"><thead><tr><th>Blotter ID</th><th>Role</th><th>Date</th><th>Case</th><th>Status</th><th></th></tr></thead><tbody>' +
            b.map(c => '<tr><td class="font-mono font-bold">' + esc(c.blotter_no) + '</td><td>' + esc(c.role) + '</td><td>' + esc(c.date) + '</td><td>' + esc(c.case) + '</td><td><span class="pill bg-amber-50 text-amber-700 border-amber-200">' + esc(c.status) + '</span></td>' +
                '<td class="text-right"><button type="button" class="btn btn-ghost btn-sm" onclick="Blotter.view(' + c.id + ')"><span class="material-symbols-outlined">visibility</span>View</button></td></tr>').join('') +
            '</tbody></table></div>', 'gavel');
    }
    if ((el.unclaimed || []).length) {
        h += '<div class="check warn"><span class="material-symbols-outlined">inventory</span><div class="flex-1"><p class="text-sm font-black">Unclaimed document(s)</p><p class="text-[11px] opacity-80 mb-1.5">This resident did not pick up:</p><ul class="text-[12px] font-semibold space-y-0.5">' +
            el.unclaimed.map(u => '<li>• ' + esc(u.DocType) + ' ' + esc(u.doc_number || u.ReferenceNo || '') + ' — <strong>' + esc(u.Status) + '</strong> (approved ' + esc(u.approved_label) + ')</li>').join('') + '</ul></div></div>';
    }
    h += section('Requests in the last 30 days', (el.history || []).length ? '<table class="mini-table"><thead><tr><th>Date</th><th>Document</th><th>Type</th><th>Status</th></tr></thead><tbody>' +
        el.history.map(r => '<tr><td>' + esc(r.date_label) + '</td><td>' + esc(r.DocType) + '</td><td class="capitalize">' + esc(r.request_type) + '</td><td>' + esc(r.Status) + '</td></tr>').join('') + '</tbody></table>'
        : '<p class="text-xs text-slate-400">No requests in the last 30 days.</p>', 'history');
    return h;
}

/* ───────── table + counts ───────── */
let listTimer = null;
function setTab(t){
    TAB = t;
    document.querySelectorAll('#tabs .tab').forEach(b => b.classList.toggle('active', b.dataset.tab === t));
    const u = new URL(location.href); u.searchParams.set('tab', t); history.replaceState(null, '', u);
    loadList();
}
function paintCounts(c){
    document.querySelectorAll('[data-count]').forEach(el => { const k = el.dataset.count; if (k in c) el.textContent = c[k]; });
    const b = document.getElementById('onlineBubble');
    b.textContent = c.new_online > 99 ? '99+' : c.new_online;
    b.classList.toggle('hidden', !c.new_online);
    b.classList.toggle('pulse', c.new_online > 0);
}
const PER_PAGE = 10;
let ROWS = [], PAGE = 1;
async function loadList(){
    const q = document.getElementById('q').value.trim(), dt = document.getElementById('docFilter').value;
    const tb = document.getElementById('rows');
    try {
        const d = await CERT.getJSON(API + '?action=list&tab=' + encodeURIComponent(TAB) + '&q=' + encodeURIComponent(q) + '&doc_type=' + encodeURIComponent(dt));
        if (!d.success) throw new Error(d.message);
        paintCounts(d.counts); lastNew = d.counts.new_online;
        ROWS = d.rows; PAGE = 1; drawRows();
    } catch (e) { tb.innerHTML = '<tr><td colspan="6" class="py-10 text-center text-rose-600 font-bold">' + esc(e.message) + '</td></tr>'; document.getElementById('pager').classList.add('hidden'); }
}
function drawRows(){
    const tb = document.getElementById('rows'), pager = document.getElementById('pager');
    const type = document.getElementById('typeFilter').value;
    const rows = ROWS.filter(r => !type || r.type === type);
    const pages = Math.max(1, Math.ceil(rows.length / PER_PAGE));
    PAGE = Math.min(PAGE, pages);
    if (!rows.length) {
        tb.innerHTML = '<tr><td colspan="6" class="text-center py-16 text-slate-400"><span class="material-symbols-outlined text-4xl block mb-2 text-slate-200">manage_search</span>' +
            (document.getElementById('q').value.trim() || type || document.getElementById('docFilter').value ? 'No requests match your search.' : 'No requests here.') + '</td></tr>';
        pager.classList.add('hidden'); return;
    }
    const btn = { preview:['Preview','preview','btn-dark'], review:['Review','rate_review','btn-dark'], view:['View','visibility','btn-ghost'] };
    tb.innerHTML = rows.slice((PAGE - 1) * PER_PAGE, PAGE * PER_PAGE).map(r => {
        const b = btn[r.action];
        const typePill = r.type === 'online'
            ? '<span class="pill bg-violet-50 text-violet-600 border-violet-100">Online</span>'
            : '<span class="pill bg-slate-50 text-slate-500 border-slate-200">Walk-in</span>';
        return '<tr class="group ' + (r.is_new ? 'row-new' : '') + '">' +
            '<td><div class="flex items-center gap-3"><div class="avatar">' + esc(initials(r.resident)) + '</div><div>' +
                '<p class="text-sm font-bold text-slate-700 leading-tight">' + esc(r.resident) + '</p>' +
                '<p class="text-[10px] text-slate-400 font-bold uppercase tracking-tighter mt-0.5">' + esc(r.resident_code || '—') + '</p></div></div></td>' +
            '<td><p class="text-xs font-semibold text-slate-700">' + esc(r.doc_type) + '</p>' +
                '<div class="flex items-center gap-1.5 mt-1">' + typePill + (r.purpose ? '<span class="text-[10px] text-slate-400 font-bold truncate max-w-[180px]">' + esc(r.purpose) + '</span>' : '') + '</div></td>' +
            '<td><p class="text-xs font-bold text-slate-700 font-mono">' + esc(r.ref) + (r.is_new ? ' <span class="pill bg-rose-50 text-rose-600 border-rose-100 ml-1">New</span>' : '') + '</p>' +
                '<p class="text-[10px] text-slate-400 font-bold font-mono mt-0.5">' + esc(r.doc_number || '—') + '</p></td>' +
            '<td class="whitespace-nowrap"><p class="text-xs font-semibold text-slate-700">' + esc(r.date) + '</p><p class="text-[10px] text-slate-400 font-bold mt-0.5">' + esc(r.time) + '</p></td>' +
            '<td><span class="pill ' + r.status_class + '"><span class="material-symbols-outlined">' + r.status_icon + '</span>' + esc(r.status) + '</span>' +
                (r.pickup_until ? '<p class="text-[10px] text-slate-400 font-bold mt-1">Pick up by ' + esc(r.pickup_until) + '</p>' : '') + '</td>' +
            '<td class="text-right"><button type="button" class="btn btn-sm ' + b[2] + '" onclick="openRow(' + r.id + ',\'' + r.action + '\')"><span class="material-symbols-outlined">' + b[1] + '</span>' + b[0] + '</button></td></tr>';
    }).join('');
    // Pagination (same look as the Resident table)
    if (pages <= 1) { pager.classList.add('hidden'); return; }
    const from = (PAGE - 1) * PER_PAGE + 1, to = Math.min(PAGE * PER_PAGE, rows.length);
    const nav = (p, label, dis, active) => '<button type="button" ' + (dis ? 'disabled ' : '') + 'onclick="goPage(' + p + ')" class="' +
        (active ? 'bg-primary text-white shadow' : (dis ? 'pointer-events-none text-slate-300 bg-slate-50' : 'text-slate-500 bg-white border border-slate-200 hover:border-indigo-300 hover:text-indigo-600')) + '">' + label + '</button>';
    let nums = '';
    for (let p = Math.max(1, PAGE - 2); p <= Math.min(pages, PAGE + 2); p++) nums += nav(p, p, false, p === PAGE);
    pager.innerHTML = '<p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Showing <strong class="text-slate-600">' + from + '</strong>–<strong class="text-slate-600">' + to + '</strong> of <strong class="text-slate-600">' + rows.length + '</strong> requests</p>' +
        '<nav class="flex items-center gap-1">' + nav(PAGE - 1, '<span class="material-symbols-outlined text-sm">chevron_left</span> Prev', PAGE <= 1) + nums + nav(PAGE + 1, 'Next <span class="material-symbols-outlined text-sm">chevron_right</span>', PAGE >= pages) + '</nav>';
    pager.classList.remove('hidden');
}
function goPage(p){ PAGE = p; drawRows(); }
function openRow(id, action){
    if (action === 'preview') Preview.open(id);
    else if (action === 'review') Review.open(id);
    else View.open(id);
}
document.getElementById('q').addEventListener('input', () => { clearTimeout(listTimer); listTimer = setTimeout(loadList, 250); });
document.getElementById('docFilter').addEventListener('change', loadList);
document.getElementById('typeFilter').addEventListener('change', () => { PAGE = 1; drawRows(); });

// Bubble: poll the number of new online requests every 45 s.
let lastNew = null;
setInterval(async function(){
    try {
        const d = await CERT.getJSON(API + '?action=counts');
        if (!d.success) return;
        if (lastNew !== null && d.counts.new_online > lastNew) {
            CERT.toast((d.counts.new_online - lastNew) + ' new online request(s).', 'info');
            if (TAB === 'pending' || TAB === 'all') loadList();
        }
        lastNew = d.counts.new_online; paintCounts(d.counts);
    } catch (e) {}
}, 45000);

/* ───────── Issue Walk-In ───────── */
const WalkIn = (function(){
    const TITLES = ['Search Resident', 'Choose Document', 'Requirements', 'Eligibility', 'Extra Information', 'Confirm & Generate'];
    const SHORT = ['Search', 'Document', 'Requirements', 'Eligibility', 'Extra Info', 'Generate'];
    let st = null, step = 1, ts = null;

    function reset(){ st = { resident:null, elig:null, doc:null, reqs:[], checked:new Set(), extraDefs:[], extra:{}, purpose:'', blotterAck:false }; }
    function open(){
        if (!DOC_TYPES.length) { CERT.toast('Add and finish a document type in Templates first.', 'warning'); return; }
        reset(); initSearch(); ts.clear(); ts.clearOptions();
        document.getElementById('wiResidentCard').innerHTML = '';
        document.getElementById('wiPurpose').value = '';
        document.getElementById('wiPhoto').value = '';
        drawDocs(); go(1); CERT.open('walkInModal');
        setTimeout(() => ts.focus(), 150);
    }
    function close(){ CERT.close('walkInModal'); }
    function go(n){
        step = n;
        document.querySelectorAll('#walkInModal [data-wi]').forEach(el => el.classList.toggle('hidden', Number(el.dataset.wi) !== n));
        document.getElementById('wiTitle').textContent = TITLES[n - 1];
        document.getElementById('wiSteps').innerHTML = SHORT.map((l, i) => (i ? '<span class="step-line"></span>' : '') + '<span class="step-dot ' + (i + 1 === n ? 'active' : (i + 1 < n ? 'done' : '')) + '"><span class="n">' + (i + 1 < n ? '✓' : i + 1) + '</span>' + l + '</span>').join('');
        document.getElementById('wiBack').classList.toggle('invisible', n === 1);
        document.getElementById('wiNext').innerHTML = n === 6 ? '<span class="material-symbols-outlined">description</span>Generate Document' : 'Next<span class="material-symbols-outlined">arrow_forward</span>';
        document.getElementById('wiNext').className = 'btn ' + (n === 6 ? 'btn-green' : 'btn-dark');
        msg('');
        if (n === 6) drawSummary();
    }
    function msg(t){ document.getElementById('wiMsg').textContent = t; }

    function initSearch(){
        if (ts) return;
        ts = new TomSelect('#wiResident', {
            valueField:'id', labelField:'name', searchField:['name','code','address'],
            maxOptions:20, loadThrottle:250, openOnFocus:true, preload:false, maxItems:1, create:false,
            placeholder:'Type a name, Resident ID or address…',
            shouldLoad: q => q.trim().length >= 1,
            score: () => () => 1, // keep the server's order
            load: function(q, cb){ this.clearOptions(); CERT.getJSON(API + '?action=search_resident&q=' + encodeURIComponent(q)).then(d => cb(d.residents || [])).catch(() => cb()); },
            render: {
                option: r => '<div><div class="font-bold text-slate-800">' + esc(r.name) + ' <span class="font-mono text-[11px] text-indigo-600">' + esc(r.code) + '</span></div><div class="text-[11px] text-slate-400">' + esc(r.address) + '</div></div>',
                item: r => '<div><span class="font-bold">' + esc(r.name) + '</span> <span class="font-mono text-[11px] text-indigo-600">' + esc(r.code) + '</span></div>',
                no_results: () => '<div class="no-results px-3 py-2 text-sm text-slate-400">No resident found.</div>',
                loading: () => '<div class="px-3 py-2 text-sm text-slate-400">Searching…</div>',
            },
            onChange: v => { if (v) pickResident(Number(v)); else { st.resident = null; document.getElementById('wiResidentCard').innerHTML = ''; } },
        });
    }
    async function pickResident(id){
        const card = document.getElementById('wiResidentCard');
        card.innerHTML = '<p class="text-sm text-slate-400">Loading resident…</p>';
        const d = await CERT.getJSON(API + '?action=get_resident&id=' + id);
        if (!d.success) { card.innerHTML = '<p class="text-sm text-rose-600">' + esc(d.message) + '</p>'; return; }
        st.resident = d.resident; st.elig = d.eligibility; st.blotterAck = false;
        const r = d.resident;
        card.innerHTML = '<div class="rounded-3xl border border-slate-100 bg-slate-50 p-5 flex gap-4 items-start"><span class="w-12 h-12 rounded-2xl bg-primary text-white flex items-center justify-center font-black text-sm shrink-0 shadow-md">' + esc(initials(r.name)) + '</span><div class="flex-1 min-w-0">' +
            '<p class="font-black text-slate-800">' + esc(r.name) + ' <span class="font-mono text-xs text-indigo-600">' + esc(r.code) + '</span></p><p class="text-xs text-slate-500">' + esc(r.address) + '</p>' +
            '<div class="flex flex-wrap gap-1.5 mt-3"><span class="pill bg-white text-slate-500 border-slate-200">' + esc(r.sex) + '</span><span class="pill bg-white text-slate-500 border-slate-200">' + esc(r.age) + ' yrs · born ' + esc(r.birth_date) + '</span><span class="pill bg-white text-slate-500 border-slate-200">' + esc(r.civil_status) + '</span><span class="pill bg-white text-slate-500 border-slate-200">' + esc(r.contact) + '</span></div></div></div>';
    }
    function drawDocs(){
        document.getElementById('wiDocs').innerHTML = DOC_TYPES.map((t, i) => '<button type="button" class="doc-opt' + (st.doc === t.doc_type ? ' sel' : '') + '" data-i="' + i + '"><span class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0"><span class="material-symbols-outlined">description</span></span><span class="min-w-0"><span class="block text-sm font-black text-slate-800">' + esc(t.doc_type) + '</span><span class="block text-[11px] text-slate-400">' + esc(t.description || t.doc_code || '') + '</span></span></button>').join('');
        document.querySelectorAll('#wiDocs .doc-opt').forEach(b => b.addEventListener('click', async () => {
            const t = DOC_TYPES[Number(b.dataset.i)];
            st.doc = t.doc_type; drawDocs();
            const d = await CERT.getJSON(API + '?action=doc_form&doc_type=' + encodeURIComponent(t.doc_type));
            if (!d.success) { msg(d.message); st.doc = null; drawDocs(); return; }
            st.reqs = d.requirements; st.extraDefs = d.extra_fields; st.checked = new Set(); st.extra = {};
        }));
    }
    function drawReqs(){
        const host = document.getElementById('wiReqs');
        host.innerHTML = st.reqs.length ? st.reqs.map((r, i) => '<label class="req"><input type="checkbox" data-i="' + i + '"' + (st.checked.has(r) ? ' checked' : '') + '><span class="flex-1">' + esc(r) + '</span></label>').join('')
            : '<p class="text-sm text-slate-400">This document has no requirements.</p>';
        host.querySelectorAll('input').forEach(c => c.addEventListener('change', () => { const r = st.reqs[Number(c.dataset.i)]; c.checked ? st.checked.add(r) : st.checked.delete(r); }));
    }
    function checkAll(){ st.reqs.forEach(r => st.checked.add(r)); drawReqs(); }
    function drawElig(){ document.getElementById('wiElig').innerHTML = eligibilityHTML(st.elig); }
    function drawExtra(){
        const host = document.getElementById('wiExtra');
        host.innerHTML = st.extraDefs.map(f => {
            const v = esc(st.extra[f.field_key] || ''); const id = 'wix_' + f.field_key; const req = f.is_required ? ' *' : '';
            let input;
            if (f.input_type === 'textarea') input = '<textarea id="' + id + '" class="input" rows="3" data-k="' + esc(f.field_key) + '">' + v + '</textarea>';
            else if (f.input_type === 'select') input = '<select id="' + id + '" class="input" data-k="' + esc(f.field_key) + '"><option value="">Choose…</option>' + f.options.map(o => '<option' + (st.extra[f.field_key] === o ? ' selected' : '') + '>' + esc(o) + '</option>').join('') + '</select>';
            else input = '<input id="' + id + '" class="input" type="' + (f.input_type === 'number' ? 'number' : (f.input_type === 'date' ? 'date' : 'text')) + '" value="' + v + '" data-k="' + esc(f.field_key) + '">';
            return '<div class="' + (f.input_type === 'textarea' ? 'sm:col-span-2' : '') + '"><label class="field-label" for="' + id + '">' + esc(f.label) + req + '</label>' + input + '</div>';
        }).join('');
        host.querySelectorAll('[data-k]').forEach(i => i.addEventListener('input', () => st.extra[i.dataset.k] = i.value));
        host.querySelectorAll('select[data-k]').forEach(i => i.addEventListener('change', () => st.extra[i.dataset.k] = i.value));
    }
    function drawSummary(){
        const r = st.resident, el = st.elig;
        const extraRows = st.extraDefs.map(f => [f.label, st.extra[f.field_key] || '']);
        document.getElementById('wiSummary').innerHTML = '<div class="grid md:grid-cols-2 gap-4">' +
            section('Resident', kv([['Name', r.name], ['Resident ID', r.code], ['Address', r.address]]), 'person') +
            section('Document', kv([['Document', st.doc], ['Purpose', st.purpose], ['Document No.', 'DOC-' + new Date().getFullYear() + '-#### (assigned on generate)'], ['Issuing officer', <?php echo json_encode(cert_actor_name($pdo)); ?>], ['Date', new Date().toLocaleDateString('en-US', { month:'long', day:'numeric', year:'numeric' })]]), 'description') +
            section('Requirements checked', st.reqs.length ? '<ul class="text-sm space-y-1">' + st.reqs.map(x => '<li class="flex items-center gap-1.5 text-emerald-700 font-semibold"><span class="material-symbols-outlined text-base">check_circle</span>' + esc(x) + '</li>').join('') + '</ul>' : '<p class="text-xs text-slate-400">None required.</p>', 'checklist') +
            section('Eligibility', kv([['Verified', el.verified ? 'Yes' : 'No'], ['Active', el.active ? 'Yes' : 'No'], ['Blotter', el.blotter.length ? el.blotter.length + ' active case(s) — proceeding (recorded)' : 'No active blotter']]) , 'verified_user') +
            (extraRows.length ? section('Extra information', kv(extraRows), 'edit_note') : '') + '</div>';
    }
    async function next(){
        msg('');
        if (step === 1) {
            if (!st.resident) return msg('Select a resident.');
            go(2); return;
        }
        if (step === 2) {
            if (!st.doc) return msg('Choose a document.');
            drawReqs(); go(3); return;
        }
        if (step === 3) {
            if (st.checked.size !== st.reqs.length) return msg('All requirements must be checked (' + st.checked.size + ' of ' + st.reqs.length + ').');
            drawElig(); go(4); return;
        }
        if (step === 4) {
            if (!st.elig.active) return msg('Cannot issue: ' + st.elig.active_note);
            if (!st.elig.verified) return msg('Cannot issue: resident is not verified. ' + st.elig.verified_note);
            if (st.elig.blotter.length && !st.blotterAck) {
                const ok = await CERT.confirm({ title:'Active blotter case', message:'This resident has ' + st.elig.blotter.length + ' active blotter case(s). Proceed anyway? Your name will be recorded on the request.', ok:'Proceed anyway', icon:'gavel', danger:true });
                if (!ok) return;
                st.blotterAck = true;
            }
            drawExtra(); go(5); return;
        }
        if (step === 5) {
            st.purpose = document.getElementById('wiPurpose').value.trim();
            if (!st.purpose) return msg('Enter the purpose.');
            for (const f of st.extraDefs) if (f.is_required && !(st.extra[f.field_key] || '').trim()) return msg(f.label + ' is required.');
            go(6); return;
        }
        if (step === 6) return generate();
    }
    function back(){ if (step > 1) go(step - 1); }
    async function generate(){
        const fd = new FormData();
        fd.append('action', 'save_walkin'); fd.append('resident_id', st.resident.id); fd.append('doc_type', st.doc);
        fd.append('purpose', st.purpose); fd.append('requirements', JSON.stringify(Array.from(st.checked)));
        fd.append('extra', JSON.stringify(st.extra)); fd.append('blotter_ack', st.blotterAck ? '1' : '0');
        const photo = document.getElementById('wiPhoto').files[0]; if (photo) fd.append('photo', photo);
        const btn = document.getElementById('wiNext'); btn.disabled = true; btn.innerHTML = 'Generating…';
        try {
            const d = await CERT.post(API, fd);
            if (!d.success) { msg(d.message); return; }
            close(); CERT.toast(d.message + ' ' + d.doc_number, 'success');
            loadList(); Preview.open(d.request_id);
        } catch (e) { msg(e.message); }
        // Restore the button only — go(6) would also clear the error message shown above.
        finally { btn.disabled = false; btn.innerHTML = '<span class="material-symbols-outlined">description</span>Generate Document'; }
    }
    return { open, close, next, back, checkAll };
})();

/* ───────── Preview / Edit / Print & Release ───────── */
const Preview = (function(){
    let cur = null, ed = null;
    async function open(id){
        const d = await CERT.getJSON(API + '?action=request&id=' + id);
        if (!d.success) { CERT.toast(d.message, 'error'); return; }
        cur = d.request;
        document.getElementById('pvEyebrow').textContent = cur.type === 'online' ? 'Online · Ready to Pick Up' : 'Walk-in · Preview';
        document.getElementById('pvTitle').textContent = cur.doc_type + ' · ' + (cur.doc_number || '');
        document.getElementById('pvSub').textContent = cur.resident ? cur.resident.name + ' (' + cur.resident.code + ') · ' + cur.ref : cur.ref;
        document.getElementById('pvNote').innerHTML = cur.type === 'online' && cur.status === 'Ready to Pick Up'
            ? '<div class="check warn mb-3"><span class="material-symbols-outlined">inventory_2</span><div><p class="text-sm font-black">Print only when the resident comes to pick it up</p><p class="text-[11px]">Sealed document — it cannot be sent online. Pick up until ' + esc(cur.dates.pickup_until || '') + '.</p></div></div>' : '';
        document.getElementById('pvFootNote').textContent = cur.render && cur.render.has_override ? 'This document has its own adjusted layout.' : '';
        const canPrint = cur.can_print && CAN_UPDATE;
        ['pvEdit', 'pvRelease'].forEach(i => { const b = document.getElementById(i); if (b) b.classList.toggle('hidden', !canPrint); });
        CERT.open('previewModal');
        draw();
    }
    function draw(){
        const host = document.getElementById('previewHost');
        if (!cur.render || (!cur.render.fields.length && !cur.render.legacy_blocks.length && !cur.render.bg_image)) { host.innerHTML = '<p class="text-sm text-slate-500 p-6 text-center">This document type has no template layout yet. Open Templates to set it up.</p>'; return; }
        CertRender.into(host, cur.render, { width: Math.min(cur.render.paper.w, host.clientWidth - 24) });
    }
    function edit(){
        const r = cur.render;
        const present = r.fields.map(f => f.field_key);
        const items = Object.keys(r.labels).map(k => ({ key:k, label:r.labels[k], sample:r.values[k] || '' }));
        const groups = [
            { title:'On this document', items: items.filter(i => present.includes(i.key)) },
            { title:'Resident data', items: items.filter(i => !present.includes(i.key) && !i.key.startsWith('extra.') && !['document_number','date_issued','day_issued','month_year_issued','month_issued','year_issued','year_issued_short','purpose','barangay_name','captain_name','captain_name_only','issuing_officer','reference_no'].includes(i.key)) },
            { title:'System', items: items.filter(i => !present.includes(i.key) && ['document_number','date_issued','day_issued','month_year_issued','month_issued','year_issued','year_issued_short','purpose','barangay_name','captain_name','captain_name_only','issuing_officer','reference_no'].includes(i.key)) },
            { title:'Extra information fields', items: items.filter(i => !present.includes(i.key) && i.key.startsWith('extra.')) },
        ].filter(g => g.items.length);
        document.getElementById('edTitle').textContent = cur.doc_type + ' · ' + cur.doc_number;
        document.getElementById('editOverlay').classList.add('open');
        document.body.style.overflow = 'hidden';
        if (ed) ed.destroy();
        ed = CertEditor.mount(document.getElementById('editHost'), {
            paper:r.paper, bg_image:r.bg_image, bg_opacity:r.bg_opacity, positions:r.fields, groups:groups, values:r.values,
            saveLabel:'Save for this document', note:'Changes apply to this document only. The template stays the same.',
            onSave: async function(positions){
                const d = await CERT.post(API, { action:'save_override', id:cur.id, positions:positions });
                if (!d.success) throw new Error(d.message);
                cur.render = d.render; CERT.toast(d.message, 'success');
                closeEdit(true); return true;
            },
        });
    }
    async function closeEdit(saved){
        if (saved !== true && ed && ed.isDirty()) {
            const ok = await CERT.confirm({ title:'Discard changes?', message:'Your layout changes for this document are not saved.', ok:'Discard', danger:true, icon:'warning' });
            if (!ok) return;
        }
        if (ed) { ed.destroy(); ed = null; }
        document.getElementById('editOverlay').classList.remove('open');
        document.body.style.overflow = '';
        draw();
    }
    async function release(){
        const ok = await CERT.confirm({ title:'Print & Release?', message:'The document will be marked Released. After this it cannot be edited or printed again.', ok:'Print & Release', icon:'print' });
        if (!ok) return;
        const w = window.open('', '_blank'); // opened now so the browser does not block it
        if (w) w.document.write('<p style="font:14px Arial;padding:24px">Preparing the document…</p>');
        const d = await CERT.post(API, { action:'release', id:cur.id }).catch(e => ({ success:false, message:e.message }));
        if (!d.success) { if (w) w.close(); CERT.toast(d.message, 'error'); return; }
        if (w) w.location = d.print_url; else CERT.toast('Allow pop-ups to print. Opening in this tab…', 'warning');
        if (!w) location.href = d.print_url;
        CERT.close('previewModal'); CERT.toast(d.message, 'success'); loadList();
    }
    return { open, edit, closeEdit, release };
})();

/* ───────── Online review ───────── */
const Review = (function(){
    let cur = null;
    async function open(id){
        await CERT.post(API, { action:'open_review', id:id }).catch(() => null);
        const d = await CERT.getJSON(API + '?action=request&id=' + id);
        if (!d.success) { CERT.toast(d.message, 'error'); return; }
        cur = d.request;
        if (!cur.can_review) { loadList(); View.open(id); return; }
        document.getElementById('rvTitle').textContent = cur.doc_type + ' · ' + cur.ref;
        document.getElementById('rvSub').textContent = 'Submitted ' + (cur.dates.requested || '') + ' · status ' + cur.status;
        const r = cur.resident || {};
        const reqs = cur.required.map(x => '<li class="flex items-center gap-1.5 ' + (cur.requirements.includes(x) ? 'text-emerald-700' : 'text-rose-600') + ' font-semibold"><span class="material-symbols-outlined text-base">' + (cur.requirements.includes(x) ? 'check_circle' : 'cancel') + '</span>' + esc(x) + '</li>').join('');
        const files = cur.files.length ? '<div class="grid sm:grid-cols-3 gap-2 mt-3">' + cur.files.map(f => '<a href="' + esc(f.url) + '" target="_blank" rel="noopener" class="rounded-xl border border-slate-200 p-2 text-xs font-bold text-indigo-600 flex items-center gap-1.5 hover:bg-slate-50"><span class="material-symbols-outlined text-base">attach_file</span>' + esc(f.label) + '</a>').join('') + '</div>' : '<p class="text-[11px] text-slate-400 mt-2">No files uploaded.</p>';
        document.getElementById('rvBody').innerHTML = '<div class="grid md:grid-cols-2 gap-4">' +
            section('Resident', kv([['Name', r.name], ['Resident ID', r.code], ['Address', r.address], ['Birth date', r.birth_date], ['Civil status', r.civil_status], ['Contact', r.contact]]), 'person') +
            section('Request', kv([['Document', cur.doc_type], ['Purpose', cur.purpose], ['Reference', cur.ref]].concat(cur.extra.map(e => [e.label, e.value]))), 'description') + '</div>' +
            section('Requirements submitted', (cur.required.length ? '<ul class="text-sm space-y-1">' + reqs + '</ul>' : '<p class="text-xs text-slate-400">None required.</p>') + files, 'checklist') +
            '<div class="space-y-3">' + eligibilityHTML(cur.eligibility) + '</div>';
        CERT.open('reviewModal'); loadList();
    }
    async function accept(ack){
        const ok = ack || await CERT.confirm({ title:'Accept this request?', message:'The document is generated and moved to the Online Queue as Ready to Pick Up. It is printed only when the resident comes (within <?php echo CERT_PICKUP_DAYS; ?> days).', ok:'Accept', icon:'check_circle' });
        if (!ok) return;
        const d = await CERT.post(API, { action:'accept', id:cur.id, blotter_ack: ack ? 1 : 0 });
        if (d.needs_blotter_ack) {
            const go = await CERT.confirm({ title:'Active blotter case', message:d.message, ok:'Accept anyway', danger:true, icon:'gavel' });
            if (go) return accept(true);
            return;
        }
        CERT.toast(d.message, d.success ? 'success' : 'error');
        if (d.success) { CERT.close('reviewModal'); loadList(); }
    }
    function reject(){
        document.getElementById('rjReason').value = ''; document.getElementById('rjOther').value = '';
        document.getElementById('rjOther').classList.add('hidden'); document.getElementById('rjMsg').textContent = '';
        CERT.open('rejectModal');
    }
    async function confirmReject(){
        const reason = document.getElementById('rjReason').value, other = document.getElementById('rjOther').value.trim();
        const m = document.getElementById('rjMsg');
        if (!reason) { m.textContent = 'Choose a reason.'; return; }
        if (reason === 'Others' && !other) { m.textContent = 'Type the reason.'; return; }
        const ok = await CERT.confirm({ title:'Reject this request?', message:'Reason: ' + (reason === 'Others' ? other : reason) + '. The resident will be notified.', ok:'Reject', danger:true, icon:'block' });
        if (!ok) return;
        const d = await CERT.post(API, { action:'reject', id:cur.id, reason:reason, other:other });
        if (!d.success) { m.textContent = d.message; return; }
        CERT.close('rejectModal'); CERT.close('reviewModal'); CERT.toast(d.message, 'success'); loadList();
    }
    document.getElementById('rjReason').addEventListener('change', function(){ document.getElementById('rjOther').classList.toggle('hidden', this.value !== 'Others'); });
    return { open, accept, reject, confirmReject };
})();

/* ───────── View (read only) ───────── */
const View = (function(){
    async function open(id){
        const d = await CERT.getJSON(API + '?action=request&id=' + id);
        if (!d.success) { CERT.toast(d.message, 'error'); return; }
        const c = d.request, r = c.resident || {};
        document.getElementById('vwTitle').textContent = c.doc_type + (c.doc_number ? ' · ' + c.doc_number : '');
        document.getElementById('vwSub').innerHTML = esc(c.ref) + ' · ' + esc(c.type) + ' · <span class="pill ' + c.status_class + '">' + esc(c.status) + '</span>';
        const people = c.people;
        const details = kv([
            ['Resident', r.name ? r.name + ' (' + r.code + ')' : '—'], ['Address', r.address], ['Purpose', c.purpose],
            ['Requested', c.dates.requested], c.dates.reviewed ? ['Reviewed', c.dates.reviewed + (people.reviewed_by ? ' · ' + people.reviewed_by : '')] : null,
            c.dates.approved ? ['Approved', c.dates.approved + (people.approved_by ? ' · ' + people.approved_by : '')] : null,
            c.dates.generated ? ['Generated', c.dates.generated + (people.generated_by ? ' · ' + people.generated_by : '')] : null,
            c.dates.released ? ['Released', c.dates.released + (people.released_by ? ' · ' + people.released_by : '')] : null,
            c.dates.rejected ? ['Rejected', c.dates.rejected + (people.rejected_by ? ' · ' + people.rejected_by : '')] : null,
            c.rejection_reason ? ['Reason', c.rejection_reason] : null,
            c.dates.expired ? ['Expired', c.dates.expired + ' (not picked up by ' + (c.dates.pickup_until || '') + ')'] : null,
            c.blotter_cases ? ['Blotter', c.blotter_cases + ' active case(s) at issue time — proceeded by ' + (c.blotter_override_by || '—')] : null,
        ].concat(c.extra.map(e => [e.label, e.value])));
        const logs = c.logs.length ? '<ul class="timeline">' + c.logs.map(l => '<li><p class="text-sm font-black text-slate-800">' + esc(l.status) + ' <span class="text-[11px] font-semibold text-slate-400">· ' + esc(l.at) + ' · ' + esc(l.by_name || '') + '</span></p>' + (l.note ? '<p class="text-xs text-slate-500">' + esc(l.note) + '</p>' : '') + '</li>').join('') + '</ul>' : '<p class="text-xs text-slate-400">No status history.</p>';
        document.getElementById('vwBody').innerHTML = '<div class="grid lg:grid-cols-[1fr_1.1fr] gap-4 items-start"><div class="space-y-4">' +
            section('Details', details, 'info') +
            section('Requirements', c.requirements.length ? '<ul class="text-sm space-y-1">' + c.requirements.map(x => '<li class="flex items-center gap-1.5 text-emerald-700 font-semibold"><span class="material-symbols-outlined text-base">check_circle</span>' + esc(x) + '</li>').join('') + '</ul>' : '<p class="text-xs text-slate-400">None recorded.</p>', 'checklist') +
            section('Status log', logs, 'timeline') + '</div>' +
            (c.render ? '<div><div class="flex items-center justify-between mb-2"><p class="section-title">Document (view only)</p><a class="btn btn-ghost !py-1.5" target="_blank" href="../backend/print_certificate.php?id=' + c.id + '"><span class="material-symbols-outlined">open_in_new</span>Open</a></div><div id="viewDocHost"></div></div>' : '<div class="rounded-2xl border border-dashed border-slate-200 p-8 text-center text-sm text-slate-400">No document was generated for this request.</div>') + '</div>';
        CERT.open('viewModal');
        if (c.render) { const host = document.getElementById('viewDocHost'); CertRender.into(host, c.render, { width: host.clientWidth - 24 }); }
    }
    return { open };
})();

/* ───────── Blotter case pop-up ───────── */
const Blotter = {
    async view(id){
        const d = await CERT.getJSON(API + '?action=blotter_case&id=' + id);
        if (!d.success) { CERT.toast(d.message, 'error'); return; }
        const c = d.case;
        document.getElementById('bcTitle').textContent = c.blotter_no;
        document.getElementById('bcBody').innerHTML = kv([['Status', c.status + (c.stage ? ' · ' + c.stage : '')], ['Case', c.type], ['Incident', c.incident], ['Location', c.location],
            ['Complainant', c.complainant], ['Respondent', c.respondent], ['Hearing', c.hearing], ['Assigned officer', c.officer], ['Filed', c.filed]]) +
            (c.narrative ? '<p class="section-title mt-4 mb-1">Narrative</p><p class="text-sm text-slate-700 whitespace-pre-line">' + esc(c.narrative) + '</p>' : '');
        CERT.open('blotterModal');
    }
};

loadList();
</script>
</body>
</html>

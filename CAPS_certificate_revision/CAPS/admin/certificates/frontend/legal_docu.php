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
        .stat:hover { transform:translateY(-2px); box-shadow:0 16px 36px -14px rgba(15,23,42,.2); }
        .bubble { position:absolute; top:-8px; right:-8px; min-width:24px; height:24px; padding:0 6px; border-radius:999px; background:#ef4444; color:#fff; font-size:.7rem; font-weight:900; display:flex; align-items:center; justify-content:center; box-shadow:0 0 0 3px #fff; }
        .bubble.hidden { display:none; }
        .bubble.pulse { animation:pulse 1.6s infinite; }
        @keyframes pulse { 0%{box-shadow:0 0 0 3px #fff,0 0 0 3px rgba(239,68,68,.5)} 70%{box-shadow:0 0 0 3px #fff,0 0 0 12px rgba(239,68,68,0)} 100%{box-shadow:0 0 0 3px #fff,0 0 0 3px rgba(239,68,68,0)} }
        .tab { padding:.5rem .9rem; border-radius:999px; font-size:.64rem; font-weight:800; letter-spacing:.07em; text-transform:uppercase; color:#64748b; background:#f1f5f9; display:inline-flex; align-items:center; gap:.4rem; white-space:nowrap; }
        .tab .cnt { background:#fff; color:#475569; border-radius:999px; padding:0 .45rem; font-size:.62rem; }
        .tab.active { background:#0f172a; color:#fff; } .tab.active .cnt { background:rgba(255,255,255,.18); color:#fff; }
        .row-new { background:linear-gradient(90deg,rgba(239,68,68,.06),transparent 40%); }
        .check { display:flex; align-items:flex-start; gap:.6rem; padding:.7rem .85rem; border-radius:.9rem; border:1px solid; }
        .check .material-symbols-outlined { font-size:20px; }
        .check.ok { background:#ecfdf5; border-color:#a7f3d0; color:#065f46; }
        .check.bad { background:#fff1f2; border-color:#fecaca; color:#991b1b; }
        .check.warn { background:#fffbeb; border-color:#fde68a; color:#92400e; }
        .req { display:flex; align-items:center; gap:.6rem; padding:.65rem .85rem; border:1px solid #e2e8f0; border-radius:.8rem; font-size:.85rem; font-weight:600; color:#334155; cursor:pointer; }
        .req:has(input:checked) { border-color:#a7f3d0; background:#ecfdf5; color:#065f46; }
        .req input { width:18px; height:18px; accent-color:#10b981; }
        .doc-opt { text-align:left; border:1px solid #e2e8f0; border-radius:1rem; padding:.85rem; display:flex; gap:.7rem; align-items:flex-start; background:#fff; }
        .doc-opt:hover { border-color:var(--accent-400,#60a5fa); }
        .doc-opt.sel { border-color:var(--accent-600); background:#eff6ff; box-shadow:0 0 0 3px rgba(59,130,246,.12); }
        .kv { display:grid; grid-template-columns:150px 1fr; gap:.35rem .9rem; font-size:.82rem; }
        .kv dt { color:#94a3b8; font-weight:700; font-size:.7rem; text-transform:uppercase; letter-spacing:.05em; padding-top:.1rem; }
        .kv dd { color:#1e293b; font-weight:600; margin:0; word-break:break-word; }
        .mini-table { width:100%; font-size:.78rem; }
        .mini-table th { text-align:left; font-size:.6rem; text-transform:uppercase; letter-spacing:.08em; color:#94a3b8; padding:.45rem .5rem; background:#f8fafc; }
        .mini-table td { padding:.5rem; border-top:1px solid #f1f5f9; }
        .ts-wrapper.single .ts-control { border-radius:.75rem !important; padding:.65rem .9rem !important; background:#f8fafc !important; border-color:#e2e8f0 !important; font-size:.9rem; }
        .ts-dropdown { border-radius:.75rem !important; box-shadow:0 12px 32px rgba(15,23,42,.14) !important; overflow:hidden; }
        .ts-dropdown .option { padding:.55rem .8rem !important; }
        .ts-dropdown .active { background:#eff6ff !important; color:inherit !important; }
        #previewHost, #viewDocHost { background:#e2e8f0; border-radius:1rem; padding:12px; }
        #editOverlay { position:fixed; inset:0; z-index:80; background:#0f172a; display:none; flex-direction:column; }
        #editOverlay.open { display:flex; }
        #editHost { flex:1; min-height:0; }
        .timeline li { position:relative; padding-left:1.35rem; padding-bottom:.8rem; }
        .timeline li::before { content:''; position:absolute; left:5px; top:6px; bottom:-2px; width:2px; background:#e2e8f0; }
        .timeline li:last-child::before { display:none; }
        .timeline li::after { content:''; position:absolute; left:0; top:4px; width:12px; height:12px; border-radius:999px; background:#fff; border:3px solid var(--accent-600); }
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
                            <span class="material-symbols-outlined text-base">gavel</span> Legal Documents
                        </div>
                        <h1 class="text-2xl font-black tracking-tight leading-none">Certificates</h1>
                        <p class="text-white/65 text-xs mt-1.5 font-medium">Issue walk-in documents, review online requests and release printed certificates.</p>
                    </div>
                    <div class="flex flex-wrap gap-2 shrink-0">
                        <?php if ($canCreate): ?>
                        <button type="button" onclick="WalkIn.open()" class="inline-flex items-center gap-1.5 bg-white text-slate-900 hover:bg-white/90 px-4 py-2.5 rounded-xl font-black text-[10px] uppercase tracking-wider"><span class="material-symbols-outlined text-base">person_add</span>Issue Walk-In</button>
                        <?php endif; ?>
                        <a href="document_templates.php" class="inline-flex items-center gap-1.5 bg-white/10 hover:bg-white/20 border border-white/20 text-white px-4 py-2.5 rounded-xl font-black text-[10px] uppercase tracking-wider"><span class="material-symbols-outlined text-base">design_services</span>Templates</a>
                        <a href="certificate_analytics.php" class="inline-flex items-center gap-1.5 bg-white/10 hover:bg-white/20 border border-white/20 text-white px-4 py-2.5 rounded-xl font-black text-[10px] uppercase tracking-wider"><span class="material-symbols-outlined text-base">monitoring</span>View Analytics</a>
                    </div>
                </div>
            </section>

            <?php if ($db_error): ?>
                <div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-700 px-4 py-3 text-sm font-semibold"><?php echo h($db_error); ?></div>
            <?php elseif (!$docTypes): ?>
                <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-800 px-4 py-3 text-sm font-semibold flex items-center gap-2"><span class="material-symbols-outlined">info</span>No finished document types yet. Open <a class="underline" href="document_templates.php">Templates</a> to add one before issuing.</div>
            <?php endif; ?>

            <!-- Cards -->
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-3">
                <button type="button" onclick="setTab('pending')" class="stat relative text-left bg-white rounded-2xl card p-4">
                    <span id="onlineBubble" class="bubble hidden"></span>
                    <div class="flex items-center justify-between"><p class="section-title">Online Requests</p><span class="w-8 h-8 rounded-xl bg-red-50 text-red-600 flex items-center justify-center"><span class="material-symbols-outlined text-lg">cloud_download</span></span></div>
                    <p class="text-2xl font-black text-slate-900 mt-1" data-count="online">0</p>
                    <p class="text-[11px] text-slate-400"><span data-count="new_online">0</span> new · click to review</p>
                </button>
                <button type="button" onclick="setTab('pending')" class="stat text-left bg-white rounded-2xl card p-4">
                    <div class="flex items-center justify-between"><p class="section-title">Pending / Review</p><span class="w-8 h-8 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center"><span class="material-symbols-outlined text-lg">rate_review</span></span></div>
                    <p class="text-2xl font-black text-amber-600 mt-1" data-count="pending">0</p><p class="text-[11px] text-slate-400">Waiting for Accept / Reject</p>
                </button>
                <button type="button" onclick="setTab('queue')" class="stat text-left bg-white rounded-2xl card p-4">
                    <div class="flex items-center justify-between"><p class="section-title">Ready to Pick Up</p><span class="w-8 h-8 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center"><span class="material-symbols-outlined text-lg">inventory_2</span></span></div>
                    <p class="text-2xl font-black text-sky-600 mt-1" data-count="queue">0</p><p class="text-[11px] text-slate-400">Expire after <?php echo CERT_PICKUP_DAYS; ?> days</p>
                </button>
                <button type="button" onclick="setTab('released')" class="stat text-left bg-white rounded-2xl card p-4">
                    <div class="flex items-center justify-between"><p class="section-title">Released</p><span class="w-8 h-8 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center"><span class="material-symbols-outlined text-lg">task_alt</span></span></div>
                    <p class="text-2xl font-black text-emerald-600 mt-1" data-count="released">0</p><p class="text-[11px] text-slate-400"><span data-count="preview">0</span> walk-in waiting to print</p>
                </button>
                <button type="button" onclick="setTab('expired')" class="stat text-left bg-white rounded-2xl card p-4">
                    <div class="flex items-center justify-between"><p class="section-title">Expired</p><span class="w-8 h-8 rounded-xl bg-slate-100 text-slate-500 flex items-center justify-center"><span class="material-symbols-outlined text-lg">hourglass_disabled</span></span></div>
                    <p class="text-2xl font-black text-slate-500 mt-1" data-count="expired">0</p><p class="text-[11px] text-slate-400">Not picked up in time</p>
                </button>
            </div>

            <!-- Table -->
            <section class="bg-white rounded-2xl card overflow-hidden">
                <div class="px-4 pt-4 pb-3 flex flex-col 2xl:flex-row 2xl:items-center gap-3 border-b border-slate-100">
                    <div class="flex gap-1.5 overflow-x-auto pb-1" id="tabs">
                        <?php foreach (['pending' => ['Pending', 'pending'], 'queue' => ['Online Queue', 'queue'], 'released' => ['Released', 'released'],
                                        'expired' => ['Expired', 'expired'], 'walkin' => ['Walk-in', 'walkin'], 'all' => ['All', 'total']] as $k => [$lbl, $cnt]): ?>
                        <button type="button" class="tab<?php echo $tab === $k ? ' active' : ''; ?>" data-tab="<?php echo $k; ?>" onclick="setTab('<?php echo $k; ?>')"><?php echo h($lbl); ?><span class="cnt" data-count="<?php echo $cnt; ?>">0</span></button>
                        <?php endforeach; ?>
                    </div>
                    <div class="flex gap-2 2xl:ml-auto">
                        <div class="flex items-center gap-1 bg-slate-50 border border-slate-200 rounded-xl px-3 flex-1">
                            <span class="material-symbols-outlined text-slate-400 text-lg">search</span>
                            <input id="q" type="search" placeholder="Name, Resident ID, reference, doc no." class="border-0 bg-transparent text-sm py-2 focus:ring-0 w-full min-w-[220px]">
                        </div>
                        <select id="docFilter" class="rounded-xl border-slate-200 bg-slate-50 text-sm font-semibold">
                            <option value="">All documents</option>
                            <?php foreach ($allTypes as $t): ?><option value="<?php echo h($t); ?>"><?php echo h($t); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-[10px] uppercase tracking-widest text-slate-400 text-left">
                            <tr><th class="px-4 py-3">Reference / Doc No.</th><th class="px-4 py-3">Resident</th><th class="px-4 py-3">Document</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Requested</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Action</th></tr>
                        </thead>
                        <tbody id="rows" class="divide-y divide-slate-100"><tr><td colspan="7" class="px-4 py-10 text-center text-slate-400">Loading…</td></tr></tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
</div>

<!-- ═════════ Issue Walk-In (6 steps) ═════════ -->
<div id="walkInModal" class="modal-back">
  <div class="modal-box" style="max-width:880px">
    <div class="modal-head">
      <div class="min-w-0">
        <p class="section-title">Issue Walk-In</p>
        <h2 class="text-lg font-black text-slate-800" id="wiTitle">Search Resident</h2>
        <div class="steps mt-3" id="wiSteps"></div>
      </div>
      <button type="button" class="text-slate-400 hover:text-slate-700" onclick="WalkIn.close()"><span class="material-symbols-outlined">close</span></button>
    </div>
    <div class="modal-body">
      <div data-wi="1" class="space-y-4">
        <div><label class="field-label" for="wiResident">Resident</label><select id="wiResident" placeholder="Type a name, Resident ID or address…"></select>
          <p class="text-[11px] text-slate-400 mt-1">Results narrow as you type. Deceased residents are not listed.</p></div>
        <div id="wiResidentCard"></div>
      </div>
      <div data-wi="2" class="hidden">
        <p class="text-sm text-slate-500 mb-3">Choose the document to issue. Only finished, active documents are listed.</p>
        <div id="wiDocs" class="grid sm:grid-cols-2 gap-2"></div>
      </div>
      <div data-wi="3" class="hidden space-y-3">
        <div class="flex items-center justify-between gap-2"><p class="text-sm text-slate-500">All requirements must be presented and checked.</p><button type="button" class="btn btn-ghost" onclick="WalkIn.checkAll()"><span class="material-symbols-outlined">done_all</span>Check all</button></div>
        <div id="wiReqs" class="space-y-2"></div>
      </div>
      <div data-wi="4" class="hidden space-y-4" id="wiElig"></div>
      <div data-wi="5" class="hidden space-y-4">
        <div><label class="field-label" for="wiPurpose">Purpose *</label><input id="wiPurpose" class="input" maxlength="500" placeholder="e.g. Employment requirement" list="purposeList">
          <datalist id="purposeList"><option>Employment</option><option>Local employment</option><option>Scholarship</option><option>Bank requirement</option><option>School requirement</option><option>Business permit</option><option>Travel</option><option>Medical assistance</option><option>Financial assistance</option><option>Legal purposes</option></datalist></div>
        <div id="wiExtra" class="grid sm:grid-cols-2 gap-4"></div>
        <div><label class="field-label" for="wiPhoto">Applicant photo (optional)</label><input id="wiPhoto" type="file" accept="image/png,image/jpeg,image/webp" class="text-sm"></div>
      </div>
      <div data-wi="6" class="hidden" id="wiSummary"></div>
    </div>
    <div class="modal-foot">
      <p id="wiMsg" class="text-[11px] font-semibold text-rose-600 mr-auto"></p>
      <button type="button" class="btn btn-ghost" id="wiBack" onclick="WalkIn.back()"><span class="material-symbols-outlined">arrow_back</span>Back</button>
      <button type="button" class="btn btn-dark" id="wiNext" onclick="WalkIn.next()">Next<span class="material-symbols-outlined">arrow_forward</span></button>
    </div>
  </div>
</div>

<!-- ═════════ Preview (Edit / Print & Release) ═════════ -->
<div id="previewModal" class="modal-back">
  <div class="modal-box" style="max-width:980px">
    <div class="modal-head">
      <div class="min-w-0"><p class="section-title" id="pvEyebrow">Preview</p><h2 class="text-lg font-black text-slate-800 truncate" id="pvTitle"></h2><p class="text-[11px] text-slate-400" id="pvSub"></p></div>
      <button type="button" class="text-slate-400 hover:text-slate-700" onclick="CERT.close('previewModal')"><span class="material-symbols-outlined">close</span></button>
    </div>
    <div class="modal-body"><div id="pvNote"></div><div id="previewHost"></div></div>
    <div class="modal-foot">
      <p class="text-[11px] text-slate-400 mr-auto" id="pvFootNote"></p>
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
  <div class="flex items-center justify-between gap-3 px-4 py-2.5 bg-slate-900 text-white">
    <div class="min-w-0"><p class="text-[10px] font-black uppercase tracking-[0.18em] text-white/50">Edit this document only · template is not changed</p><p class="font-black truncate" id="edTitle"></p></div>
    <button type="button" class="btn bg-white text-slate-900" onclick="Preview.closeEdit()"><span class="material-symbols-outlined">close</span>Back to preview</button>
  </div>
  <div id="editHost"></div>
</div>

<!-- ═════════ Online review (Accept / Reject) ═════════ -->
<div id="reviewModal" class="modal-back">
  <div class="modal-box" style="max-width:920px">
    <div class="modal-head">
      <div class="min-w-0"><p class="section-title">Online Request · Review</p><h2 class="text-lg font-black text-slate-800 truncate" id="rvTitle"></h2><p class="text-[11px] text-slate-400" id="rvSub"></p></div>
      <button type="button" class="text-slate-400 hover:text-slate-700" onclick="CERT.close('reviewModal')"><span class="material-symbols-outlined">close</span></button>
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
  <div class="modal-box" style="max-width:460px">
    <div class="modal-head"><div><p class="section-title">Reject Request</p><h2 class="text-lg font-black text-slate-800">Reason for rejection</h2></div>
      <button type="button" class="text-slate-400" onclick="CERT.close('rejectModal')"><span class="material-symbols-outlined">close</span></button></div>
    <div class="modal-body space-y-3">
      <select id="rjReason" class="input">
        <option value="">Choose a reason…</option>
        <option>Incomplete requirements</option><option>Invalid information</option><option>Active blotter case</option><option>Duplicate request</option><option>Others</option>
      </select>
      <textarea id="rjOther" class="input hidden" rows="3" maxlength="480" placeholder="Type the reason (required)"></textarea>
      <p class="text-[11px] text-slate-400">The resident is notified with this reason.</p>
      <p id="rjMsg" class="text-[11px] font-semibold text-rose-600"></p>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="CERT.close('rejectModal')">Cancel</button><button type="button" class="btn btn-red" onclick="Review.confirmReject()"><span class="material-symbols-outlined">block</span>Reject Request</button></div>
  </div>
</div>

<!-- ═════════ View (details + status log) ═════════ -->
<div id="viewModal" class="modal-back">
  <div class="modal-box" style="max-width:980px">
    <div class="modal-head">
      <div class="min-w-0"><p class="section-title">View · read only</p><h2 class="text-lg font-black text-slate-800 truncate" id="vwTitle"></h2><p class="text-[11px] text-slate-400" id="vwSub"></p></div>
      <button type="button" class="text-slate-400 hover:text-slate-700" onclick="CERT.close('viewModal')"><span class="material-symbols-outlined">close</span></button>
    </div>
    <div class="modal-body" id="vwBody"></div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" onclick="CERT.close('viewModal')">Close</button></div>
  </div>
</div>

<!-- Blotter case pop-up -->
<div id="blotterModal" class="modal-back" style="z-index:85">
  <div class="modal-box" style="max-width:620px">
    <div class="modal-head"><div><p class="section-title">Blotter Case</p><h2 class="text-lg font-black text-slate-800" id="bcTitle"></h2></div>
      <button type="button" class="text-slate-400" onclick="CERT.close('blotterModal')"><span class="material-symbols-outlined">close</span></button></div>
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
function section(title, html, icon){ return '<section class="rounded-2xl border border-slate-100 p-4"><p class="section-title mb-3 flex items-center gap-1.5">' + (icon ? '<span class="material-symbols-outlined text-sm">' + icon + '</span>' : '') + esc(title) + '</p>' + html + '</section>'; }

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
                '<td class="text-right"><button type="button" class="btn btn-ghost !py-1.5" onclick="Blotter.view(' + c.id + ')"><span class="material-symbols-outlined">visibility</span>View</button></td></tr>').join('') +
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
async function loadList(){
    const q = document.getElementById('q').value.trim(), dt = document.getElementById('docFilter').value;
    const tb = document.getElementById('rows');
    try {
        const d = await CERT.getJSON(API + '?action=list&tab=' + encodeURIComponent(TAB) + '&q=' + encodeURIComponent(q) + '&doc_type=' + encodeURIComponent(dt));
        if (!d.success) throw new Error(d.message);
        paintCounts(d.counts); lastNew = d.counts.new_online;
        if (!d.rows.length) { tb.innerHTML = '<tr><td colspan="7" class="px-4 py-12 text-center text-slate-400"><span class="material-symbols-outlined text-4xl text-slate-300">inbox</span><p class="mt-1">No requests here.</p></td></tr>'; return; }
        const btn = { preview:['Preview','preview','btn-dark'], review:['Review','rate_review','btn-accent'], view:['View','visibility','btn-ghost'] };
        tb.innerHTML = d.rows.map(r => {
            const b = btn[r.action];
            return '<tr class="' + (r.is_new ? 'row-new' : '') + '">' +
                '<td class="px-4 py-3"><div class="font-mono text-xs font-bold text-slate-800">' + esc(r.ref) + (r.is_new ? ' <span class="pill bg-red-50 text-red-600 border-red-200 ml-1">New</span>' : '') + '</div><div class="font-mono text-[11px] text-slate-400">' + esc(r.doc_number || '—') + '</div></td>' +
                '<td class="px-4 py-3"><div class="font-bold text-slate-800">' + esc(r.resident) + '</div><div class="text-[11px] text-slate-400 font-mono">' + esc(r.resident_code) + '</div></td>' +
                '<td class="px-4 py-3"><div class="font-semibold text-slate-700">' + esc(r.doc_type) + '</div><div class="text-[11px] text-slate-400 truncate max-w-[220px]">' + esc(r.purpose) + '</div></td>' +
                '<td class="px-4 py-3"><span class="pill ' + (r.type === 'online' ? 'bg-violet-50 text-violet-700 border-violet-200' : 'bg-slate-50 text-slate-600 border-slate-200') + '"><span class="material-symbols-outlined">' + (r.type === 'online' ? 'language' : 'directions_walk') + '</span>' + esc(r.type) + '</span></td>' +
                '<td class="px-4 py-3 whitespace-nowrap"><div class="text-slate-700 font-semibold">' + esc(r.date) + '</div><div class="text-[11px] text-slate-400">' + esc(r.time) + '</div></td>' +
                '<td class="px-4 py-3"><span class="pill ' + r.status_class + '"><span class="material-symbols-outlined">' + r.status_icon + '</span>' + esc(r.status) + '</span>' + (r.pickup_until ? '<div class="text-[10px] text-slate-400 mt-1">Pick up by ' + esc(r.pickup_until) + '</div>' : '') + '</td>' +
                '<td class="px-4 py-3 text-right"><button type="button" class="btn ' + b[2] + '" onclick="openRow(' + r.id + ',\'' + r.action + '\')"><span class="material-symbols-outlined">' + b[1] + '</span>' + b[0] + '</button></td></tr>';
        }).join('');
    } catch (e) { tb.innerHTML = '<tr><td colspan="7" class="px-4 py-8 text-center text-rose-600 font-semibold">' + esc(e.message) + '</td></tr>'; }
}
function openRow(id, action){
    if (action === 'preview') Preview.open(id);
    else if (action === 'review') Review.open(id);
    else View.open(id);
}
document.getElementById('q').addEventListener('input', () => { clearTimeout(listTimer); listTimer = setTimeout(loadList, 250); });
document.getElementById('docFilter').addEventListener('change', loadList);

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
        card.innerHTML = '<div class="rounded-2xl border border-slate-100 bg-slate-50 p-4 flex gap-4 items-start"><span class="w-12 h-12 rounded-2xl bg-indigo-600 text-white flex items-center justify-center font-black text-lg shrink-0">' + esc(r.name.charAt(0)) + '</span><div class="flex-1 min-w-0">' +
            '<p class="font-black text-slate-800">' + esc(r.name) + ' <span class="font-mono text-xs text-indigo-600">' + esc(r.code) + '</span></p><p class="text-xs text-slate-500">' + esc(r.address) + '</p>' +
            '<div class="flex flex-wrap gap-1.5 mt-2 text-[11px] font-bold text-slate-600"><span class="px-2 py-1 rounded-lg bg-white border border-slate-200">' + esc(r.sex) + '</span><span class="px-2 py-1 rounded-lg bg-white border border-slate-200">' + esc(r.age) + ' yrs · born ' + esc(r.birth_date) + '</span><span class="px-2 py-1 rounded-lg bg-white border border-slate-200">' + esc(r.civil_status) + '</span><span class="px-2 py-1 rounded-lg bg-white border border-slate-200">' + esc(r.contact) + '</span></div></div></div>';
    }
    function drawDocs(){
        document.getElementById('wiDocs').innerHTML = DOC_TYPES.map((t, i) => '<button type="button" class="doc-opt' + (st.doc === t.doc_type ? ' sel' : '') + '" data-i="' + i + '"><span class="w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0"><span class="material-symbols-outlined">description</span></span><span class="min-w-0"><span class="block font-black text-slate-800">' + esc(t.doc_type) + '</span><span class="block text-[11px] text-slate-400">' + esc(t.description || t.doc_code || '') + '</span></span></button>').join('');
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

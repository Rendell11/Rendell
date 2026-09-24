<?php
/**
 * Certificate Analytics — same structure as the other modules' analytics pages:
 * date range, cards, charts, tables, manual AI Analytics Overview, Save as PDF / Print.
 */
require_once __DIR__ . '/../../db.php';
$required_module = 'certificates';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../backend/cert_analytics_data.php';

$current_page = 'Certificates';
$pageTitle = 'Certificate Analytics';
$p = cert_analytics_params($_GET);
$db_error = '';
try { cert_migrate($pdo); $a = cert_analytics_compute($pdo, $p); }
catch (Throwable $e) { $db_error = 'Unable to load analytics.'; error_log('[Certificates] analytics: ' . $e->getMessage()); $a = null; }
$t = $a['totals'] ?? [];
$periodLabel = date('M j, Y', strtotime($p['from'])) . ' – ' . date('M j, Y', strtotime($p['to']));
$filterQuery = http_build_query(['from' => $p['from'], 'to' => $p['to']]);
$chart = $a ? cert_analytics_chart_data($a) : null;
?>
<!doctype html>
<html <?php require_once __DIR__ . '/../../theme_loader.php'; echo $theme_attrs['html'] ?? ''; ?>>
<head>
    <?php require __DIR__ . '/partials/cert_head.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        .chart-box { position:relative; height:200px; }
        .ai-shimmer { background:linear-gradient(90deg,#f1f5f9 0%,#e2e8f0 50%,#f1f5f9 100%); background-size:200% 100%; animation:sh 1.2s infinite; border-radius:.75rem; }
        @keyframes sh { 0%{background-position:200% 0} 100%{background-position:-200% 0} }
        html.dark .ai-shimmer { background:linear-gradient(90deg,#1e293b 0%,#334155 50%,#1e293b 100%); background-size:200% 100%; }
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
                        <div class="flex items-center gap-2 text-white/60 text-[10px] font-black uppercase tracking-[0.18em] mb-1"><span class="material-symbols-outlined text-base">monitoring</span> Legal Documents</div>
                        <h1 class="text-2xl font-black tracking-tight leading-none">Certificate Analytics</h1>
                        <p class="text-white/65 text-xs mt-1.5 font-medium">Requests, releases and turnaround from recorded certificate requests · <?php echo h($periodLabel); ?></p>
                    </div>
                    <div class="flex flex-wrap gap-2 shrink-0">
                        <button type="button" onclick="openReport('pdf')" class="inline-flex items-center gap-1.5 bg-white text-slate-900 hover:bg-white/90 px-4 py-2.5 rounded-xl font-black text-[10px] uppercase tracking-wider"><span class="material-symbols-outlined text-base">save</span>Save as PDF</button>
                        <button type="button" onclick="openReport('print')" class="inline-flex items-center gap-1.5 bg-white/10 hover:bg-white/20 border border-white/20 text-white px-4 py-2.5 rounded-xl font-black text-[10px] uppercase tracking-wider"><span class="material-symbols-outlined text-base">print</span>Print</button>
                        <a href="legal_docu.php" class="inline-flex items-center gap-1.5 bg-white/10 hover:bg-white/20 border border-white/20 text-white px-4 py-2.5 rounded-xl font-black text-[10px] uppercase tracking-wider"><span class="material-symbols-outlined text-base">arrow_back</span>Back</a>
                    </div>
                </div>
            </section>

            <form method="get" class="bg-white rounded-2xl p-4 card flex flex-wrap items-end gap-3">
                <div><label class="section-title" for="fromDate">Start Date</label><input type="date" name="from" id="fromDate" value="<?php echo h($p['from']); ?>" max="<?php echo date('Y-m-d'); ?>" required class="mt-1 block rounded-lg border-slate-200 text-sm font-semibold py-2"></div>
                <div><label class="section-title" for="toDate">End Date</label><input type="date" name="to" id="toDate" value="<?php echo h($p['to']); ?>" max="<?php echo date('Y-m-d'); ?>" required class="mt-1 block rounded-lg border-slate-200 text-sm font-semibold py-2"></div>
                <button class="px-4 py-2.5 rounded-lg bg-slate-900 text-white text-[10px] font-black uppercase tracking-wider">Apply</button>
                <p class="ml-auto text-[11px] text-slate-400">Default: last 30 days. Everything on this page follows the range.</p>
            </form>

            <?php if ($db_error): ?>
                <div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-700 px-4 py-3 text-sm font-semibold"><?php echo h($db_error); ?></div>
            <?php else: ?>

            <!-- AI Analytics Overview -->
            <section class="bg-white rounded-2xl card overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5">
                        <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500 to-fuchsia-500 text-white flex items-center justify-center"><span class="material-symbols-outlined text-lg">auto_awesome</span></span>
                        <div><p class="section-title">AI Analytics Overview</p><p id="aiMeta" class="text-[11px] text-slate-400">Checking for a saved analysis…</p></div>
                    </div>
                    <button type="button" id="aiRefresh" class="px-3 py-1.5 rounded-lg bg-slate-900 text-white text-[10px] font-black uppercase tracking-wider inline-flex items-center gap-1 disabled:opacity-40"><span class="material-symbols-outlined text-sm">auto_awesome</span><span id="aiBtnLabel">Generate AI Analytics</span></button>
                </div>
                <div id="aiBody" class="p-4"><p class="text-xs text-slate-400">AI analysis is not generated automatically. Click <strong>Generate AI Analytics</strong> to analyze <?php echo h($periodLabel); ?>.</p></div>
            </section>

            <!-- Cards -->
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-3">
                <?php foreach ([
                    ['Total Requests', $t['total'], 'text-slate-900', $a['days'] . ' day(s)'],
                    ['Walk-in vs Online', $t['walkin'] . ' / ' . $t['online'], 'text-indigo-600', $t['total'] ? round($t['online'] / $t['total'] * 100) . '% online' : '—'],
                    ['Released', $t['released'], 'text-emerald-600', $t['released_in_period'] . ' released in range'],
                    ['Pending / Review', $t['pending'], 'text-amber-600', 'Waiting for decision'],
                    ['Ready to Pick Up', $t['ready'], 'text-sky-600', 'Online, not yet claimed'],
                    ['Rejected', $t['rejected'], 'text-red-600', $t['rejection_rate'] !== null ? $t['rejection_rate'] . '% of online' : '—'],
                    ['Expired', $t['expired'], 'text-slate-500', 'Unclaimed rate ' . ($t['unclaimed_rate'] !== null ? $t['unclaimed_rate'] . '%' : '—')],
                    ['Avg Processing', cert_fmt_duration($t['avg_processing_min']), 'text-slate-900', 'Request → release'],
                    ['Walk-in Avg', cert_fmt_duration($t['avg_walkin_min']), 'text-slate-900', 'Online avg ' . cert_fmt_duration($t['avg_online_min'])],
                    ['Most Requested', $t['top_document'] ?: '—', 'text-slate-900 text-base', $t['top_document_n'] ? $t['top_document_n'] . ' request(s)' : 'None recorded'],
                ] as [$lbl, $val, $cls, $sub]): ?>
                <div class="card bg-white rounded-xl px-3.5 py-3"><p class="section-title"><?php echo h($lbl); ?></p><p class="text-xl font-black mt-1 truncate <?php echo $cls; ?>" title="<?php echo h($val); ?>"><?php echo h($val); ?></p><p class="text-[10px] text-slate-400"><?php echo h($sub); ?></p></div>
                <?php endforeach; ?>
            </div>

            <!-- Charts -->
            <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-3">
                <?php foreach ([
                    'time' => ['Requests Over Time', 'Walk-in vs online per ' . ($a['monthly'] ? 'month' : 'day'), 'md:col-span-2'],
                    'status' => ['Status Breakdown', 'Current status of requests in the range', ''],
                    'docs' => ['By Document Type', 'Requests per document', ''],
                    'purpose' => ['By Purpose', 'Top purposes', ''],
                    'reject' => ['Rejection Reasons', 'Hover "Others" to see the typed reasons', ''],
                    'weekday' => ['Busiest Day', 'Requests per weekday' . ($a['weekday']['busiest'] ? ' · peak ' . $a['weekday']['busiest'] : ''), ''],
                    'hour' => ['Busiest Hour', 'Requests per hour of day' . ($a['hours']['busiest'] ? ' · peak ' . $a['hours']['busiest'] : ''), ''],
                    'purok' => ['Top Puroks', 'Where requests come from', ''],
                    'proc' => ['Processing Time Trend', 'Average hours from request to release', 'md:col-span-2 xl:col-span-3'],
                ] as $key => [$title, $sub, $span]): ?>
                <section class="bg-white rounded-2xl card p-4 <?php echo $span; ?>">
                    <div class="mb-2"><p class="section-title"><?php echo h($title); ?></p><p class="text-[11px] text-slate-400"><?php echo h($sub); ?></p></div>
                    <div class="chart-box"><canvas id="chart-<?php echo $key; ?>"></canvas><p id="empty-<?php echo $key; ?>" class="hidden absolute inset-0 items-center justify-center text-xs text-slate-400">None recorded in this period.</p></div>
                </section>
                <?php endforeach; ?>
            </div>

            <!-- Tables -->
            <div class="grid xl:grid-cols-2 gap-3">
                <section class="bg-white rounded-2xl card overflow-hidden">
                    <div class="px-4 py-3 border-b border-slate-100"><p class="section-title">Top Documents</p></div>
                    <table class="min-w-full text-xs"><thead class="bg-slate-50 text-[10px] uppercase tracking-widest text-slate-400 text-left"><tr><th class="px-4 py-2.5">Document</th><th class="px-2 py-2.5 text-center">Requests</th><th class="px-2 py-2.5 text-center">Online</th><th class="px-2 py-2.5 text-center">Released</th><th class="px-4 py-2.5 text-center">Avg processing</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($a['by_document'] as $d): ?><tr><td class="px-4 py-2.5 font-bold text-slate-800"><?php echo h($d['label']); ?></td><td class="px-2 py-2.5 text-center font-bold"><?php echo $d['n']; ?></td><td class="px-2 py-2.5 text-center"><?php echo $d['online']; ?></td><td class="px-2 py-2.5 text-center text-emerald-600 font-bold"><?php echo $d['released']; ?></td><td class="px-4 py-2.5 text-center"><?php echo h($d['avg']); ?></td></tr><?php endforeach; ?>
                    <?php if (!$a['by_document']): ?><tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">None recorded in this period.</td></tr><?php endif; ?>
                    </tbody></table>
                </section>
                <section class="bg-white rounded-2xl card overflow-hidden">
                    <div class="px-4 py-3 border-b border-slate-100"><p class="section-title">Recent Releases</p></div>
                    <div class="overflow-x-auto"><table class="min-w-full text-xs"><thead class="bg-slate-50 text-[10px] uppercase tracking-widest text-slate-400 text-left"><tr><th class="px-4 py-2.5">Doc No.</th><th class="px-2 py-2.5">Resident</th><th class="px-2 py-2.5">Document</th><th class="px-2 py-2.5">Released</th><th class="px-4 py-2.5">Took</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($a['recent_releases'] as $r): ?><tr><td class="px-4 py-2.5 font-mono font-bold"><?php echo h($r['doc_number']); ?></td><td class="px-2 py-2.5"><div class="font-bold text-slate-800"><?php echo h($r['resident']); ?></div><div class="text-[10px] text-slate-400 font-mono"><?php echo h($r['code']); ?></div></td><td class="px-2 py-2.5"><?php echo h($r['doc_type']); ?> <span class="text-slate-400">(<?php echo h($r['type']); ?>)</span></td><td class="px-2 py-2.5 whitespace-nowrap"><?php echo h($r['released']); ?><div class="text-[10px] text-slate-400"><?php echo h($r['by']); ?></div></td><td class="px-4 py-2.5"><?php echo h($r['took']); ?></td></tr><?php endforeach; ?>
                    <?php if (!$a['recent_releases']): ?><tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">None recorded in this period.</td></tr><?php endif; ?>
                    </tbody></table></div>
                </section>
                <section class="bg-white rounded-2xl card overflow-hidden xl:col-span-2">
                    <div class="px-4 py-3 border-b border-slate-100"><p class="section-title">Expired / Unclaimed</p><p class="text-[11px] text-slate-400">Online documents not picked up (expire <?php echo CERT_PICKUP_DAYS; ?> days after approval)</p></div>
                    <div class="overflow-x-auto"><table class="min-w-full text-xs"><thead class="bg-slate-50 text-[10px] uppercase tracking-widest text-slate-400 text-left"><tr><th class="px-4 py-2.5">Doc / Ref No.</th><th class="px-2 py-2.5">Resident</th><th class="px-2 py-2.5">Contact</th><th class="px-2 py-2.5">Document</th><th class="px-2 py-2.5">Approved</th><th class="px-2 py-2.5">Pick up by</th><th class="px-4 py-2.5">Status</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($a['unclaimed'] as $u): $m = cert_status_meta($u['status']); ?><tr><td class="px-4 py-2.5 font-mono font-bold"><?php echo h($u['doc_number']); ?></td><td class="px-2 py-2.5"><div class="font-bold text-slate-800"><?php echo h($u['resident']); ?></div><div class="text-[10px] text-slate-400 font-mono"><?php echo h($u['code']); ?></div></td><td class="px-2 py-2.5"><?php echo h($u['contact'] ?: '—'); ?></td><td class="px-2 py-2.5"><?php echo h($u['doc_type']); ?></td><td class="px-2 py-2.5"><?php echo h($u['approved']); ?></td><td class="px-2 py-2.5"><?php echo h($u['deadline']); ?></td><td class="px-4 py-2.5"><span class="pill <?php echo h($m['class']); ?>"><?php echo h($u['status']); ?></span></td></tr><?php endforeach; ?>
                    <?php if (!$a['unclaimed']): ?><tr><td colspan="7" class="px-4 py-8 text-center text-slate-400">None recorded in this period.</td></tr><?php endif; ?>
                    </tbody></table></div>
                </section>
            </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Report options -->
<div id="reportModal" class="modal-back">
  <div class="modal-box" style="max-width:440px">
    <div class="modal-body">
      <div class="flex items-start justify-between"><div><p class="section-title">Certificate Analytics Report</p><h2 id="reportModalTitle" class="text-lg font-black text-slate-800 mt-0.5"></h2><p class="text-[11px] text-slate-400"><?php echo h($periodLabel); ?></p></div><button type="button" onclick="CERT.close('reportModal')" class="text-slate-400"><span class="material-symbols-outlined">close</span></button></div>
      <p class="text-sm font-bold text-slate-700 mt-5">Include AI Analytics findings?</p>
      <div class="mt-2 space-y-2">
        <label class="flex items-start gap-3 p-3 rounded-2xl bg-slate-50 border border-slate-100 cursor-pointer"><input type="radio" name="reportAi" value="1" class="mt-0.5"><span><span class="block text-sm font-bold text-slate-700">Yes, include AI findings</span><span class="block text-[11px] text-slate-400">Uses the AI analysis already generated on this page.</span></span></label>
        <label class="flex items-start gap-3 p-3 rounded-2xl bg-slate-50 border border-slate-100 cursor-pointer"><input type="radio" name="reportAi" value="0" checked class="mt-0.5"><span><span class="block text-sm font-bold text-slate-700">No, detailed report only</span><span class="block text-[11px] text-slate-400">Every section with a written explanation.</span></span></label>
      </div>
      <p id="reportAiWarn" class="hidden text-[11px] text-amber-600 font-semibold mt-2">No AI analysis generated yet for this period — click Generate AI Analytics first, or choose No.</p>
    </div>
    <div class="modal-foot"><button type="button" onclick="CERT.close('reportModal')" class="btn btn-ghost">Cancel</button><button type="button" id="reportGo" class="btn btn-dark">Continue</button></div>
  </div>
</div>

<script>
let _reportMode = 'pdf';
function openReport(mode){
    _reportMode = mode;
    document.getElementById('reportModalTitle').textContent = mode === 'pdf' ? 'Save as PDF' : 'Print';
    document.getElementById('reportAiWarn').classList.add('hidden');
    CERT.open('reportModal');
}
document.getElementById('reportGo').addEventListener('click', function(){
    const ai = document.querySelector('input[name="reportAi"]:checked').value;
    if (ai === '1' && !window.aiGenerated) { document.getElementById('reportAiWarn').classList.remove('hidden'); return; }
    window.open('../backend/cert_analytics_report.php?<?php echo $filterQuery; ?>&mode=' + _reportMode + '&ai=' + ai, '_blank');
    CERT.close('reportModal');
});
(function(){
    const from = document.getElementById('fromDate'), to = document.getElementById('toDate');
    function sync(){ to.min = from.value; from.max = to.value || from.max; }
    from.addEventListener('change', sync); to.addEventListener('change', sync); sync();
})();

<?php if ($chart): ?>
(function(){
    const d = <?php echo json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    const dark = document.documentElement.classList.contains('dark');
    Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
    Chart.defaults.font.size = 11;
    Chart.defaults.color = dark ? '#94a3b8' : '#64748b';
    Chart.defaults.borderColor = dark ? 'rgba(148,163,184,.15)' : 'rgba(148,163,184,.2)';
    const C = { green:'#10b981', orange:'#f97316', red:'#ef4444', sky:'#0ea5e9', indigo:'#6366f1', gray:'#94a3b8' };
    const PAL = [C.indigo, C.green, C.orange, C.sky, C.red, '#eab308', '#8b5cf6', '#14b8a6'];
    const STATUS = { 'Released':C.green, 'Pending':C.orange, 'Review':'#fb923c', 'Ready to Pick Up':C.sky, 'Preview':C.indigo, 'Rejected':C.red, 'Expired':C.gray };
    const base = { responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'bottom', labels:{ boxWidth:8, boxHeight:8, padding:10 } } } };
    const intAxis = { beginAtZero:true, ticks:{ precision:0 } };
    const noLegend = { ...base, plugins:{ legend:{ display:false } } };
    function bar(labels, data, color, horizontal){ return { type:'bar', data:{ labels:labels, datasets:[{ data:data, backgroundColor:color, borderRadius:4 }] }, options:{ ...noLegend, indexAxis: horizontal ? 'y' : 'x', scales: horizontal ? { x:intAxis } : { y:intAxis } } }; }
    const build = {
        time: () => ({ type:'line', data:{ labels:d.series.labels, datasets:[
            { label:'Walk-in', data:d.series.walkin, borderColor:C.indigo, backgroundColor:C.indigo + '22', fill:true, tension:.3, pointRadius:1.5 },
            { label:'Online', data:d.series.online, borderColor:C.orange, backgroundColor:C.orange + '22', fill:true, tension:.3, pointRadius:1.5 } ] }, options:{ ...base, scales:{ y:intAxis } } }),
        status: () => ({ type:'doughnut', data:{ labels:d.byStatus.labels, datasets:[{ data:d.byStatus.data, backgroundColor:d.byStatus.labels.map(l => STATUS[l] || C.gray), borderWidth:0 }] }, options:{ ...base, cutout:'62%', plugins:{ legend:{ position:'right', labels:{ boxWidth:8, boxHeight:8 } } } } }),
        docs: () => bar(d.byDocument.labels, d.byDocument.data, C.indigo, true),
        purpose: () => bar(d.byPurpose.labels, d.byPurpose.data, C.sky, true),
        reject: () => ({ type:'pie', data:{ labels:d.rejections.labels, datasets:[{ data:d.rejections.data, backgroundColor:d.rejections.labels.map((l, i) => l === 'Others' ? C.gray : [C.red, C.orange, '#eab308', C.sky][i % 4]), borderWidth:0 }] },
            options:{ ...base, plugins:{ legend:{ position:'right', labels:{ boxWidth:8, boxHeight:8 } }, tooltip:{ callbacks:{ afterLabel: ctx => ctx.label === 'Others' ? Object.keys(d.rejections.others).map(r => '• ' + r + ' (' + d.rejections.others[r] + ')') : '' } } } } }),
        weekday: () => bar(d.weekday.labels, d.weekday.data, C.green),
        hour: () => bar(d.hours.labels, d.hours.data, C.orange),
        purok: () => bar(d.puroks.labels, d.puroks.data, C.indigo, true),
        proc: () => ({ type:'line', data:{ labels:d.series.labels, datasets:[{ label:'Avg hours to release', data:d.series.processing_hours, borderColor:C.green, backgroundColor:C.green + '22', fill:true, spanGaps:true, tension:.3, pointRadius:2 }] }, options:{ ...noLegend, scales:{ y:{ beginAtZero:true, title:{ display:true, text:'hours' } } } } }),
    };
    const sum = a => a.reduce((x, y) => x + (y || 0), 0);
    const empty = {
        time: () => !sum(d.series.walkin) && !sum(d.series.online), status: () => !d.byStatus.data.length, docs: () => !d.byDocument.data.length,
        purpose: () => !d.byPurpose.data.length, reject: () => !d.rejections.data.length, weekday: () => !sum(d.weekday.data),
        hour: () => !sum(d.hours.data), purok: () => !d.puroks.data.length, proc: () => !d.series.processing_hours.some(v => v !== null),
    };
    Object.keys(build).forEach(k => {
        if (empty[k]()) { const e = document.getElementById('empty-' + k); e.classList.remove('hidden'); e.classList.add('flex'); return; }
        new Chart(document.getElementById('chart-' + k), build[k]());
    });
})();
<?php endif; ?>

<?php if (!$db_error): ?>
// AI Analytics Overview: only on request; Gemini is called server-side with aggregated numbers only.
(function(){
    const body = document.getElementById('aiBody'), meta = document.getElementById('aiMeta'), btn = document.getElementById('aiRefresh');
    const url = '../backend/cert_ai_analytics.php?<?php echo $filterQuery; ?>';
    const sev = { good:['bg-emerald-50 border-emerald-200','text-emerald-600','check_circle'], warning:['bg-amber-50 border-amber-200','text-amber-600','warning'], info:['bg-slate-50 border-slate-200','text-blue-600','insights'] };
    const esc = CERT.esc;
    function render(r){
        if (!r || !r.ok){ body.innerHTML = '<p class="text-sm text-rose-600 font-semibold">' + esc((r && r.error) || 'Unable to generate analysis.') + '</p>'; meta.textContent = 'Error'; return; }
        meta.textContent = r.ai ? ('Generated by Gemini' + (r.generated_at ? ' · ' + r.generated_at : '') + (r.cached ? ' · cached' : '')) : (r.note || 'Automatic insights');
        let html = '';
        if (r.summary) html += '<p class="text-sm text-slate-700 leading-relaxed mb-3">' + esc(r.summary) + '</p>';
        const head = (icon, title) => '<p class="section-title flex items-center gap-1.5 mb-2"><span class="material-symbols-outlined text-sm">' + icon + '</span>' + title + '</p>';
        const dir = { up:['trending_up','text-indigo-600'], down:['trending_down','text-indigo-600'], stable:['trending_flat','text-slate-500'] };
        const prio = { high:'bg-red-100 text-red-700', medium:'bg-amber-100 text-amber-700', low:'bg-slate-100 text-slate-600' };
        html += '<div class="grid lg:grid-cols-3 gap-3">';
        html += '<div>' + head('fact_check', 'Key Findings') + '<div class="space-y-2">' + (r.key_findings || []).map(i => { const s = sev[i.severity] || sev.info;
            return '<div class="rounded-xl border p-3 ' + s[0] + '"><div class="flex items-start gap-2"><span class="material-symbols-outlined text-base ' + s[1] + '">' + s[2] + '</span><div><p class="text-xs font-black text-slate-800">' + esc(i.title) + '</p><p class="text-[11px] text-slate-600 mt-0.5 leading-relaxed">' + esc(i.detail) + '</p></div></div></div>'; }).join('') + '</div></div>';
        html += '<div>' + head('insights', 'Trends') + '<div class="space-y-2">' + ((r.trends || []).length ? r.trends.map(i => { const x = dir[i.direction] || dir.stable;
            return '<div class="rounded-xl border border-slate-200 bg-slate-50 p-3"><div class="flex items-start gap-2"><span class="material-symbols-outlined text-base ' + x[1] + '">' + x[0] + '</span><div><p class="text-xs font-black text-slate-800">' + esc(i.title) + '</p><p class="text-[11px] text-slate-600 mt-0.5 leading-relaxed">' + esc(i.detail) + '</p></div></div></div>'; }).join('') : '<p class="text-[11px] text-slate-400">Not enough data to show trends.</p>') + '</div></div>';
        html += '<div>' + head('task_alt', 'Recommended Actions') + '<ol class="space-y-2">' + (r.actions || []).map((i, n) =>
            '<li class="rounded-xl border border-slate-200 p-3"><div class="flex items-start gap-2"><span class="w-5 h-5 shrink-0 rounded-full bg-slate-900 text-white text-[10px] font-black flex items-center justify-center">' + (n + 1) + '</span><div class="min-w-0"><div class="flex flex-wrap items-center gap-1.5"><p class="text-xs font-black text-slate-800">' + esc(i.action) + '</p><span class="px-1.5 py-0.5 rounded-full text-[8px] font-black uppercase tracking-wider ' + (prio[i.priority] || prio.medium) + '">' + esc(i.priority) + '</span></div><p class="text-[11px] text-slate-600 mt-0.5 leading-relaxed">' + esc(i.reason) + '</p></div></div></li>').join('') + '</ol></div>';
        body.innerHTML = html + '</div>';
    }
    const label = document.getElementById('aiBtnLabel');
    let hasResult = false;
    function load(opts){
        btn.disabled = true;
        CERT.getJSON(url + (opts.refresh ? '&refresh=1' : '') + (opts.cachedOnly ? '&cached_only=1' : ''))
            .then(r => {
                if (r && r.none) { meta.textContent = 'Not generated yet for this period.'; return; }
                render(r); hasResult = true; label.textContent = 'Re-generate';
                window.aiGenerated = !!(r && r.ok && r.ai);
            })
            .catch(() => render({ ok:false, error:'Unable to reach the AI analytics service.' }))
            .finally(() => { btn.disabled = false; });
    }
    btn.addEventListener('click', () => {
        meta.textContent = 'Analyzing…';
        body.innerHTML = '<div class="space-y-2.5"><div class="ai-shimmer h-4 w-3/4"></div><div class="grid md:grid-cols-3 gap-2.5"><div class="ai-shimmer h-16"></div><div class="ai-shimmer h-16"></div><div class="ai-shimmer h-16"></div></div></div>';
        load({ refresh: hasResult });
    });
    load({ cachedOnly: true });
})();
<?php endif; ?>
</script>
</body>
</html>

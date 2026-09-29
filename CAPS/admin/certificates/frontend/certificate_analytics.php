<?php
/**
 * Legal Document Analytics — same structure / behavior as Resident and Household Analytics.
 * Data: ../backend/cert_analytics_data.php (actual document_requests + residents rows).
 * Date range: both empty = all records; otherwise requests made within the range.
 * AI: ../backend/cert_ai_analytics.php — only when "Generate AI Analytics" is clicked.
 * Save PDF / Print: ../backend/cert_analytics_report.php (shared CAPS report layout).
 */
require_once __DIR__ . '/../../db.php';
$required_module = 'certificates';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../backend/cert_analytics_data.php';

$current_page = 'Certificates';
$pageTitle = 'Legal Document Analytics';
$p = cert_analytics_params($_GET);
try {
    cert_migrate($pdo);
    $a = cert_analytics_compute($pdo, $p);
    $a['interpretation'] = cert_analytics_interpretation($a);
} catch (Throwable $e) {
    error_log('[Certificates] analytics: ' . $e->getMessage());
    $a = null;
}
?>
<!doctype html>
<html <?php require_once __DIR__ . '/../../theme_loader.php'; echo $theme_attrs['html'] ?? ''; ?>>
<head>
    <?php require __DIR__ . '/partials/cert_head.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        .kpi { border:1px solid #eef2f7; border-radius:20px; padding:16px 18px; background:#fff }
        .kpi .k-label { font-size:9px; font-weight:900; text-transform:uppercase; letter-spacing:.08em; color:#64748b }
        .kpi .k-value { font-size:26px; font-weight:900; color:#0f172a; line-height:1.1; margin-top:4px; font-variant-numeric:tabular-nums; overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
        .kpi .k-sub { font-size:10px; font-weight:700; color:#94a3b8; margin-top:2px }
        .chart-card { border:1px solid #eef2f7; border-radius:24px; padding:20px; background:#fff; display:flex; flex-direction:column; min-width:0 }
        .chart-card h3 { font-size:14px; font-weight:900; color:#1e293b }
        .chart-card .c-sub { font-size:10px; font-weight:700; color:#94a3b8; margin-top:2px }
        .chart-box { position:relative; height:240px; margin-top:14px }
        details.tv { margin-top:10px }
        details.tv summary { cursor:pointer; font-size:10px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.06em }
        table.dt { width:100%; border-collapse:collapse; font-size:12px }
        table.dt th { text-align:left; font-size:9px; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; font-weight:900; padding:8px 10px; border-bottom:1px solid #eef2f7; background:#f8fafc }
        table.dt td { padding:8px 10px; border-bottom:1px solid #f1f5f9; color:#334155; font-weight:600 }
        table.dt td.num, table.dt th.num { text-align:right; font-variant-numeric:tabular-nums }
        table.dt tr.total td { font-weight:900; border-top:1.5px solid #cbd5e1; background:#f8fafc }
        .empty { font-size:12px; color:#94a3b8; font-style:italic; text-align:center; padding:40px 10px }
        html.dark .kpi, html.dark .chart-card { background:#1e293b; border-color:#334155 }
    </style>
</head>
<body <?php echo $theme_attrs['body'] ?? ''; ?>>
<div class="flex min-h-screen">
    <?php require __DIR__ . '/../../sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php require __DIR__ . '/../../header.php'; ?>
        <main class="p-4 md:p-6 lg:p-8 space-y-7">
            <section class="hero-band rounded-2xl p-6 md:p-8 text-white relative overflow-hidden">
                <div class="absolute -right-12 -top-12 w-64 h-64 opacity-10 rounded-full blur-3xl pointer-events-none" style="background:var(--accent-400);"></div>
                <div class="relative z-10 flex flex-col xl:flex-row xl:items-center xl:justify-between gap-5">
                    <div>
                        <div class="flex items-center gap-2 text-white/60 text-[10px] font-black uppercase tracking-[0.18em] mb-2"><span class="material-symbols-outlined text-base">analytics</span>Legal Documents</div>
                        <h1 class="text-2xl md:text-3xl font-black tracking-tight leading-none">Legal Document Analytics</h1>
                        <p class="text-white/65 text-sm mt-2 font-medium max-w-2xl">Requests, document types, status, processing time and requesters from the actual legal document records.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="button" onclick="openReport('pdf')" class="hero-btn hero-btn-white"><span class="material-symbols-outlined">picture_as_pdf</span>Save PDF</button>
                        <button type="button" onclick="openReport('print')" class="hero-btn"><span class="material-symbols-outlined">print</span>Print</button>
                        <a href="legal_docu.php" class="hero-btn"><span class="material-symbols-outlined">arrow_back</span>Back to Legal Documents</a>
                    </div>
                </div>
            </section>

            <!-- Date range -->
            <section class="card p-5 md:p-6">
                <form id="rangeForm" class="flex flex-wrap items-end gap-4" onsubmit="applyRange(event)">
                    <div><label class="field-label" for="startDate">Start Date</label><input type="date" id="startDate" max="9999-12-31" value="<?php echo h($p['start']); ?>" class="input !w-auto"></div>
                    <div><label class="field-label" for="endDate">End Date</label><input type="date" id="endDate" max="9999-12-31" value="<?php echo h($p['end']); ?>" class="input !w-auto"></div>
                    <button type="submit" class="btn btn-dark"><span class="material-symbols-outlined">filter_alt</span>Apply</button>
                    <button type="button" onclick="clearRange()" class="btn btn-ghost">Clear</button>
                    <div class="ml-auto text-right">
                        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Showing</p>
                        <p id="rangeLabel" class="text-sm font-black text-slate-800"></p>
                        <p id="rangeBasis" class="text-[10px] font-bold text-slate-400"></p>
                    </div>
                </form>
            </section>

            <!-- AI Analytics (manual only) -->
            <section class="card p-6 md:p-8">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-white bg-indigo-600 p-2 rounded-xl">auto_awesome</span>
                        <div><h2 class="text-lg font-black text-slate-800">AI Analytics</h2>
                            <p id="aiStatus" class="text-[10px] text-slate-400 font-bold">Not generated. AI runs only when you click the button.</p></div>
                    </div>
                    <button type="button" id="aiBtn" onclick="generateAi()" class="btn btn-dark"><span class="material-symbols-outlined">auto_awesome</span><span id="aiBtnText">Generate AI Analytics</span></button>
                </div>
                <div id="aiBody" class="mt-6"></div>
            </section>

            <!-- Data Interpretation (directly below AI Analytics) -->
            <div id="raInterp"></div>
            <div id="raBody" class="space-y-7"></div>
        </main>
    </div>
</div>

<!-- Save PDF / Print: include AI findings? -->
<div id="reportModal" class="fixed inset-0 z-[999] hidden bg-slate-900/70 items-center justify-center p-4" onclick="if (event.target === this) closeReport()">
    <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-md p-8" role="dialog" aria-modal="true" aria-labelledby="rmTitle">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h3 id="rmTitle" class="text-2xl font-black text-slate-900 tracking-tight">Save as PDF</h3>
                <p class="text-[11px] font-black text-indigo-600 uppercase tracking-widest mt-1">Legal Document Analytics Report</p>
                <p id="rmRange" class="text-xs font-bold text-slate-400 mt-1"></p>
            </div>
            <button type="button" onclick="closeReport()" class="p-1 text-slate-400 hover:text-slate-700" aria-label="Close"><span class="material-symbols-outlined">close</span></button>
        </div>
        <p class="text-sm font-bold text-slate-700 mt-6 mb-3">Include AI findings in the report?</p>
        <div class="space-y-3">
            <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 cursor-pointer hover:border-indigo-300">
                <input type="radio" name="rmAi" value="0" class="mt-1 text-indigo-600 focus:ring-indigo-500" checked>
                <span><span class="block text-sm font-black text-slate-800">No AI – Data and tables only</span>
                    <span class="block text-[11px] font-semibold text-slate-400">Summary, statistics, tables and data interpretation.</span></span>
            </label>
            <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 cursor-pointer hover:border-indigo-300">
                <input type="radio" name="rmAi" value="1" class="mt-1 text-indigo-600 focus:ring-indigo-500">
                <span><span class="block text-sm font-black text-slate-800">Include AI – Data, charts, and AI explanation</span>
                    <span class="block text-[11px] font-semibold text-slate-400">Uses the AI Analytics already generated on this page.</span></span>
            </label>
        </div>
        <p id="rmError" class="hidden mt-4 text-xs font-bold text-rose-600 bg-rose-50 border border-rose-100 rounded-xl px-3 py-2">Please generate AI Analytics first.</p>
        <div class="flex justify-end gap-3 mt-8">
            <button type="button" onclick="closeReport()" class="btn btn-ghost">Cancel</button>
            <button type="button" onclick="continueReport()" class="btn btn-dark">Continue</button>
        </div>
    </div>
</div>

<script>
const LA_ENDPOINT = '../backend/cert_analytics_data.php';
let LA = <?php echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
let LA_AI = null;
const charts = {};
const COLORS = { s1: '#2a78d6', s2: '#eb6834', s3: '#1baf7a', muted: '#94a3b8', grid: '#eef2f7' };
const STATUS_COLORS = { 'Pending': '#f59e0b', 'Review': '#fbbf24', 'Ready to Pick Up': '#0ea5e9', 'Preview': '#6366f1', 'Released': COLORS.s3, 'Rejected': '#ef4444', 'Expired': COLORS.muted };
const esc = CERT.esc;
const num = v => Number(v || 0).toLocaleString('en-US');
const pct = (v, d) => d ? (Math.round(Number(v || 0) / d * 1000) / 10) + '%' : '0%';
const sum = o => Object.values(o).reduce((s, v) => s + Number(v || 0), 0);

if (window.Chart) {
    Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
    Chart.defaults.font.size = 11;
    Chart.defaults.color = '#64748b';
    Chart.defaults.plugins.tooltip.backgroundColor = '#0f172a';
    Chart.defaults.plugins.tooltip.padding = 10;
    Chart.defaults.plugins.tooltip.cornerRadius = 10;
}

/* ───────── Rendering helpers ───────── */
function kpi(label, value, sub) { return `<div class="kpi"><p class="k-label">${esc(label)}</p><p class="k-value" title="${esc(value)}">${esc(value)}</p>${sub ? `<p class="k-sub">${esc(sub)}</p>` : ''}</div>`; }
function card(id, title, sub, opts = {}) {
    return `<div class="chart-card ${opts.span || ''}"><h3>${esc(title)}</h3><p class="c-sub">${esc(sub)}</p>
        ${opts.empty ? `<p class="empty">${esc(opts.empty)}</p>` : `<div class="chart-box"><canvas id="${id}" role="img" aria-label="${esc(title)}"></canvas></div>`}
        ${opts.table ? `<details class="tv"><summary>View table</summary><div class="mt-2 overflow-x-auto">${opts.table}</div></details>` : ''}</div>`;
}
function table(head, rows, numCols = []) {
    if (!rows.length) return '<p class="empty">No data for the selected range.</p>';
    return `<table class="dt"><thead><tr>${head.map((h, i) => `<th class="${numCols.includes(i) ? 'num' : ''}">${esc(h)}</th>`).join('')}</tr></thead><tbody>` +
        rows.map(r => `<tr class="${r[0] === 'Total' ? 'total' : ''}">${r.map((c, i) => `<td class="${numCols.includes(i) ? 'num' : ''}">${esc(c)}</td>`).join('')}</tr>`).join('') + '</tbody></table>';
}
function distRows(obj, base) {
    const rows = Object.entries(obj).map(([k, v]) => [k, num(v), pct(v, base)]);
    if (rows.length) rows.push(['Total', num(sum(obj)), pct(sum(obj), base)]);
    return rows;
}
function section(icon, title, sub, inner) {
    return `<section class="card p-6 md:p-8"><div class="flex items-center gap-3 mb-6"><span class="material-symbols-outlined text-white bg-primary p-2 rounded-xl">${icon}</span>
        <div><h2 class="text-lg font-black text-slate-800">${esc(title)}</h2><p class="text-[10px] text-slate-400 font-bold">${esc(sub)}</p></div></div>${inner}</section>`;
}
const axisCount = { beginAtZero: true, ticks: { precision: 0 }, grid: { color: COLORS.grid }, border: { display: false } };
const noGrid = { grid: { display: false }, border: { display: false } };
const legendTop = { position: 'top', align: 'end', labels: { boxWidth: 10, boxHeight: 10 } };
function barH(id, obj, label) {
    return new Chart(document.getElementById(id), { type: 'bar',
        data: { labels: Object.keys(obj), datasets: [{ label, data: Object.values(obj), backgroundColor: COLORS.s1, borderRadius: 4, maxBarThickness: 18 }] },
        options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: axisCount, y: noGrid } } });
}
function barV(id, obj, label) {
    return new Chart(document.getElementById(id), { type: 'bar',
        data: { labels: Object.keys(obj), datasets: [{ label, data: Object.values(obj), backgroundColor: COLORS.s1, borderRadius: 4, maxBarThickness: 36 }] },
        options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: noGrid, y: axisCount } } });
}
function donut(id, obj, colors, base) {
    const e = Object.entries(obj).filter(([, v]) => v > 0);
    return new Chart(document.getElementById(id), { type: 'doughnut',
        data: { labels: e.map(x => x[0]), datasets: [{ data: e.map(x => x[1]), backgroundColor: e.map(x => colors[x[0]] || COLORS.muted), borderColor: '#fff', borderWidth: 2 }] },
        options: { maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10 } },
            tooltip: { callbacks: { label: c => ` ${c.label}: ${num(c.raw)} (${pct(c.raw, base)})` } } } } });
}
const dur = m => m == null ? '—' : (m < 60 ? Math.round(m) + ' min' : (m < 1440 ? Math.round(m / 6) / 10 + ' h' : Math.round(m / 144) / 10 + ' days'));

/* ───────── Page ───────── */
function render() {
    Object.values(charts).forEach(c => c.destroy());
    for (const k in charts) delete charts[k];
    const body = document.getElementById('raBody');
    document.getElementById('raInterp').innerHTML = '';
    document.getElementById('rangeLabel').textContent = LA ? LA.period.label : '';
    document.getElementById('rangeBasis').textContent = LA && (LA.period.start || LA.period.end) ? 'Requests made within the range' : 'All document requests on record';
    if (!LA) { body.innerHTML = '<section class="card p-8"><p class="empty">Unable to load legal document analytics.</p></section>'; return; }
    const t = LA.totals, T = t.total;

    let html = section('description', 'Request Summary', 'Every document request in the selected range · ' + LA.period.label,
        `<div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4">
            ${kpi('Total Requests', num(T), num(t.residents) + ' resident(s)')}
            ${kpi('Walk-in', num(t.walkin), pct(t.walkin, T) + ' of requests')}
            ${kpi('Online', num(t.online), pct(t.online, T) + ' of requests')}
            ${kpi('Released', num(t.released), pct(t.released, T) + ' release rate')}
            ${kpi('Pending / Review', num(t.pending), 'online, waiting for decision')}
            ${kpi('Ready to Pick Up', num(t.ready), 'online, not yet claimed')}
            ${kpi('Not Yet Released', num(t.preview), 'walk-in, generated')}
            ${kpi('Rejected', num(t.rejected), t.rejection_rate != null ? t.rejection_rate + '% of online' : '—')}
            ${kpi('Expired Unclaimed', num(t.expired), t.unclaimed_rate != null ? t.unclaimed_rate + '% of accepted online' : '—')}
            ${kpi('Avg Processing', dur(t.avg_min), 'request → release')}
            ${kpi('Walk-in / Online Avg', dur(t.walkin_min) + ' / ' + dur(t.online_min), 'processing time')}
            ${kpi('Most Requested', t.top_document || '—', t.top_document_n ? num(t.top_document_n) + ' request(s)' : 'none')}
            ${kpi('Document Types', num(t.doc_types), 'requested in range')}
            ${kpi('Busiest Day', t.busiest_day || '—', t.busiest_hour ? 'peak ' + t.busiest_hour : '')}
        </div>`);

    // Status & type
    html += section('donut_large', 'Status Breakdown', 'Current status and request type',
        `<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            ${card('c-status', 'Requests by Status', 'Share of all requests', { span: 'lg:col-span-2', empty: T ? '' : 'No requests in this range.', table: table(['Status', 'Requests', 'Share'], distRows(LA.status, T), [1, 2]) })}
            ${card('c-type', 'Walk-in vs Online', 'Request type', { empty: T ? '' : 'No requests.', table: table(['Type', 'Requests', 'Share'], distRows(LA.type, T), [1, 2]) })}
        </div>`);

    // Documents
    const docRows = LA.documents.map(d => [d.label, num(d.total), pct(d.total, T), num(d.walkin), num(d.online), num(d.released), num(d.rejected), d.avg]);
    if (docRows.length) docRows.push(['Total', num(T), '100%', num(t.walkin), num(t.online), num(t.released), num(t.rejected), dur(t.avg_min)]);
    html += section('folder_open', 'Document Types', 'Requests, releases and processing time per document',
        `<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            ${card('c-docs', 'Requests per Document', 'Walk-in vs online', { empty: T ? '' : 'No requests.' })}
            ${card('c-purpose', 'Top Purposes', 'As recorded on the requests', { empty: T ? '' : 'No requests.', table: table(['Purpose', 'Requests', 'Share'], distRows(LA.purpose, T), [1, 2]) })}
        </div>
        <div class="mt-6 chart-card"><h3>Document Statistics</h3><p class="c-sub">Per document type</p>
            <div class="mt-3 overflow-x-auto">${table(['Document', 'Requests', 'Share', 'Walk-in', 'Online', 'Released', 'Rejected', 'Avg processing'], docRows, [1, 2, 3, 4, 5, 6])}</div></div>`);

    // Requesters
    const areaRows = LA.areas.map(x => [x.area, num(x.total), pct(x.total, T), num(x.residents), num(x.released)]);
    const sexObj = Object.fromEntries(Object.entries(LA.sex).filter(([k, v]) => k !== 'Not specified' || v > 0));
    html += section('groups', 'Requesters & Areas', 'Resident profile of each request',
        `<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            ${card('c-sex', 'Requests by Sex', 'Resident who requested', { empty: T ? '' : 'No data.', table: table(['Sex', 'Requests', 'Share'], distRows(sexObj, T), [1, 2]) })}
            ${card('c-age', 'Requests by Age Group', 'Age of the resident today', { span: 'lg:col-span-2', empty: T ? '' : 'No data.', table: table(['Age group', 'Requests', 'Share'], distRows(LA.age, T), [1, 2]) })}
            ${card('c-area', 'Requests per Area', 'Subdivision / Village / Sitio / Purok', { span: 'lg:col-span-3', empty: T ? '' : 'No data.', table: table(['Area', 'Requests', 'Share', 'Residents', 'Released'], areaRows, [1, 2, 3, 4]) })}
        </div>`);

    // Trend & comparison
    const keys = Object.keys(LA.series);
    let comparison = '';
    if (LA.comparison) comparison = `<div class="chart-card lg:col-span-2"><h3>Selected Period vs Previous Period</h3><p class="c-sub">Previous period: ${esc(LA.comparison.previous_label)}</p>
        <div class="chart-box"><canvas id="c-compare" role="img" aria-label="Period comparison"></canvas></div>
        <div class="mt-3 overflow-x-auto">${table(['Indicator', 'Selected', 'Previous', 'Change'], LA.comparison.rows.map(r => [r.metric, num(r.current), num(r.previous), (r.current - r.previous > 0 ? '+' : '') + num(r.current - r.previous)]), [1, 2, 3])}</div></div>`;
    html += section('insights', 'Trend & Comparison', 'Requests over time, busiest days and change from the previous period',
        `<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            ${card('c-time', 'Requests Over Time', 'Walk-in vs online per ' + (LA.series_daily ? 'day' : 'month'), { span: 'lg:col-span-2', empty: keys.length > 1 ? '' : 'Not enough data for a trend.', table: table([LA.series_daily ? 'Day' : 'Month', 'Walk-in', 'Online', 'Released'], keys.filter(k => LA.series[k].walkin || LA.series[k].online).map(k => [k, num(LA.series[k].walkin), num(LA.series[k].online), num(LA.series[k].released)]), [1, 2, 3]) })}
            ${card('c-weekday', 'Requests per Weekday', t.busiest_day ? 'Busiest: ' + t.busiest_day : '', { empty: T ? '' : 'No data.', table: table(['Day', 'Requests', 'Share'], distRows(LA.weekday, T), [1, 2]) })}
            ${card('c-reject', 'Rejection Reasons', num(t.rejected) + ' rejected', { empty: Object.keys(LA.rejections).length ? '' : 'No rejected requests.', table: table(['Reason', 'Rejected', 'Share'], distRows(LA.rejections, t.rejected), [1, 2]) })}
            ${comparison}
        </div>`);

    // Data tables
    html += section('table_view', 'Document Data', 'Latest releases and documents not yet claimed',
        `<div class="chart-card"><h3>Latest Releases</h3><p class="c-sub">Up to 10 most recent</p>
            <div class="mt-3 overflow-x-auto">${table(['Document No.', 'Document', 'Type', 'Resident', 'Released', 'Took'], LA.recent.map(r => [r.doc_number, r.doc_type, r.type, r.resident, r.released, r.took]))}</div></div>
         <div class="mt-6 chart-card"><h3>Unclaimed Documents</h3><p class="c-sub">Ready to pick up / expired (pick up within ${<?php echo CERT_PICKUP_DAYS; ?>} days)</p>
            <div class="mt-3 overflow-x-auto">${table(['Document / Ref.', 'Document', 'Resident', 'Approved', 'Pick up until', 'Status'], LA.unclaimed.map(r => [r.doc_number, r.doc_type, r.resident, r.approved, r.deadline, r.status]))}</div></div>`);

    document.getElementById('raInterp').innerHTML = section('fact_check', 'Data Interpretation', 'Automatic summary of the legal document figures below (no AI) · ' + LA.period.label,
        `<ol class="list-decimal pl-5 space-y-2 text-sm text-slate-600 font-medium">${(LA.interpretation || []).map(x => `<li>${esc(x)}</li>`).join('')}</ol>`);
    body.innerHTML = html;

    if (!window.Chart || !T) return;
    charts.status = barH('c-status', LA.status, 'Requests');
    charts.status.data.datasets[0].backgroundColor = Object.keys(LA.status).map(k => STATUS_COLORS[k] || COLORS.muted); charts.status.update();
    charts.type = donut('c-type', LA.type, { 'Walk-in': COLORS.s1, 'Online': COLORS.s2 }, T);
    const docs = LA.documents.slice(0, 10);
    charts.docs = new Chart(document.getElementById('c-docs'), { type: 'bar',
        data: { labels: docs.map(d => d.label), datasets: [
            { label: 'Walk-in', data: docs.map(d => d.walkin), backgroundColor: COLORS.s1, borderRadius: 4, maxBarThickness: 18 },
            { label: 'Online', data: docs.map(d => d.online), backgroundColor: COLORS.s2, borderRadius: 4, maxBarThickness: 18 }] },
        options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: legendTop }, scales: { x: Object.assign({ stacked: true }, axisCount), y: Object.assign({ stacked: true }, noGrid) } } });
    charts.purpose = barH('c-purpose', LA.purpose, 'Requests');
    charts.sex = donut('c-sex', sexObj, { Male: COLORS.s1, Female: COLORS.s2 }, T);
    charts.age = barV('c-age', LA.age, 'Requests');
    charts.area = barH('c-area', Object.fromEntries(LA.areas.slice(0, 12).map(x => [x.area, x.total])), 'Requests');
    charts.weekday = barV('c-weekday', LA.weekday, 'Requests');
    if (Object.keys(LA.rejections).length) charts.reject = barH('c-reject', LA.rejections, 'Rejected');
    if (keys.length > 1) charts.time = new Chart(document.getElementById('c-time'), { type: 'line',
        data: { labels: keys.map(k => new Date((k.length === 7 ? k + '-01' : k) + 'T00:00:00').toLocaleDateString('en-US', LA.series_daily ? { month: 'short', day: 'numeric' } : { month: 'short', year: 'numeric' })),
            datasets: [
                { label: 'Walk-in', data: keys.map(k => LA.series[k].walkin), borderColor: COLORS.s1, backgroundColor: COLORS.s1, borderWidth: 2, pointRadius: 3, tension: 0 },
                { label: 'Online', data: keys.map(k => LA.series[k].online), borderColor: COLORS.s2, backgroundColor: COLORS.s2, borderWidth: 2, pointRadius: 3, tension: 0 },
                { label: 'Released', data: keys.map(k => LA.series[k].released), borderColor: COLORS.s3, backgroundColor: COLORS.s3, borderWidth: 2, pointRadius: 3, tension: 0, borderDash: [4, 3] }] },
        options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: legendTop }, scales: { x: noGrid, y: axisCount } } });
    if (LA.comparison) charts.compare = new Chart(document.getElementById('c-compare'), { type: 'bar',
        data: { labels: LA.comparison.rows.map(r => r.metric), datasets: [
            { label: 'Selected period', data: LA.comparison.rows.map(r => r.current), backgroundColor: COLORS.s1, borderRadius: 4, maxBarThickness: 22 },
            { label: 'Previous period', data: LA.comparison.rows.map(r => r.previous), backgroundColor: COLORS.muted, borderRadius: 4, maxBarThickness: 22 }] },
        options: { maintainAspectRatio: false, plugins: { legend: legendTop }, scales: { x: noGrid, y: axisCount } } });
}

/* ───────── Date range ───────── */
async function applyRange(e) {
    if (e) e.preventDefault();
    const s = document.getElementById('startDate').value, en = document.getElementById('endDate').value;
    if (s && en && s > en) { CERT.toast('The Start Date must be on or before the End Date.', 'warning'); return; }
    const q = new URLSearchParams({ start: s, end: en });
    history.replaceState({}, '', location.pathname + (s || en ? '?' + q.toString() : ''));
    document.getElementById('raBody').style.opacity = '.5';
    try {
        const j = await CERT.getJSON(LA_ENDPOINT + '?' + q.toString());
        if (!j.success) throw new Error(j.error || 'Unable to load legal document analytics.');
        LA = j.data;
    } catch (err) { CERT.toast(err.message, 'error'); }
    document.getElementById('raBody').style.opacity = '';
    render(); resetAi(); loadCachedAi();
}
function clearRange() { document.getElementById('startDate').value = ''; document.getElementById('endDate').value = ''; applyRange(); }

/* ───────── AI Analytics (only on click) ───────── */
function resetAi() {
    LA_AI = null;
    document.getElementById('aiBody').innerHTML = '';
    document.getElementById('aiStatus').textContent = 'Not generated for this date range. AI runs only when you click the button.';
    document.getElementById('aiBtnText').textContent = 'Generate AI Analytics';
}
function renderAi(x) {
    LA_AI = x;
    document.getElementById('aiStatus').textContent = 'Generated ' + (x.generated_at || '') + ' · ' + (x.period?.label || '') + (x.cached ? ' · saved result' : '');
    document.getElementById('aiBtnText').textContent = 'Regenerate AI Analytics';
    const group = (title, icon, list) => list && list.length ? `<div class="rounded-2xl border border-indigo-100 bg-indigo-50/40 p-5"><div class="flex items-center gap-2 mb-3"><span class="material-symbols-outlined text-indigo-600">${icon}</span><h5 class="text-[10px] font-black uppercase tracking-widest text-indigo-700">${esc(title)}</h5></div>
        <ul class="space-y-2">${list.map(f => `<li class="text-xs leading-relaxed text-slate-600"><strong class="text-slate-800">${esc(f.title)}</strong> — ${esc(f.detail)}</li>`).join('')}</ul></div>` : '';
    const recs = (x.recommendations || []).map((f, i) => `<li class="text-xs leading-relaxed text-slate-600"><strong class="text-slate-800">${i + 1}. ${esc(f.action)}</strong>
        <span class="ml-1 px-2 py-0.5 rounded-full text-[9px] font-black uppercase ${f.priority === 'high' ? 'bg-rose-100 text-rose-700' : f.priority === 'low' ? 'bg-slate-100 text-slate-600' : 'bg-amber-100 text-amber-700'}">${esc(f.priority)}</span><br>${esc(f.reason)}</li>`).join('');
    document.getElementById('aiBody').innerHTML =
        (x.summary ? `<p class="text-sm font-semibold text-slate-700 mb-5">${esc(x.summary)}</p>` : '') +
        `<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">${group('Key Findings', 'insights', x.key_findings)}${group('Request Trends', 'trending_up', x.demographic_trends)}
         ${group('Significant Changes / Patterns', 'timeline', x.patterns)}${group('Service Observations', 'support_agent', x.population_observations)}</div>` +
        (recs ? `<div class="rounded-2xl border border-indigo-100 bg-white p-5 mt-4"><div class="flex items-center gap-2 mb-3"><span class="material-symbols-outlined text-indigo-600">task_alt</span><h5 class="text-[10px] font-black uppercase tracking-widest text-indigo-700">Recommended Actions</h5></div><ul class="space-y-3">${recs}</ul></div>` : '') +
        '<p class="text-[10px] text-slate-400 font-bold mt-4">AI-generated from aggregated legal document figures only. Verify against the tables below.</p>';
}
async function aiRequest(cachedOnly) {
    const data = { start: LA?.period?.start || '', end: LA?.period?.end || '' };
    if (cachedOnly) data.cached_only = '1';
    const j = await CERT.post('../backend/cert_ai_analytics.php', data);
    if (!j || !j.success) throw new Error(j?.error || j?.message || 'AI Analytics is unavailable right now.');
    return j;
}
async function generateAi() {
    const btn = document.getElementById('aiBtn');
    btn.disabled = true;
    document.getElementById('aiBtnText').textContent = 'Generating…';
    document.getElementById('aiStatus').textContent = 'Generating AI Analytics for ' + (LA?.period?.label || '') + '…';
    try { renderAi(await aiRequest(false)); }
    catch (e) { document.getElementById('aiStatus').textContent = e.message; document.getElementById('aiBtnText').textContent = LA_AI ? 'Regenerate AI Analytics' : 'Generate AI Analytics'; }
    btn.disabled = false;
}
// Shows an analysis generated earlier for the SAME data (no Gemini call).
async function loadCachedAi() { try { const j = await aiRequest(true); if (j.ai) renderAi(j); } catch (e) { } }

/* ───────── Save PDF / Print ───────── */
let _reportMode = 'pdf';
function openReport(mode) {
    _reportMode = mode;
    document.getElementById('rmTitle').textContent = mode === 'pdf' ? 'Save as PDF' : 'Print';
    document.getElementById('rmRange').textContent = LA?.period?.label || '';
    document.querySelector('input[name="rmAi"][value="0"]').checked = true;
    document.getElementById('rmError').classList.add('hidden');
    const m = document.getElementById('reportModal'); m.classList.remove('hidden'); m.classList.add('flex');
}
function closeReport() { const m = document.getElementById('reportModal'); m.classList.add('hidden'); m.classList.remove('flex'); }
function continueReport() {
    const withAi = document.querySelector('input[name="rmAi"]:checked')?.value === '1';
    // Never generates AI here — only uses the analysis already generated on this page.
    if (withAi && !LA_AI) { document.getElementById('rmError').classList.remove('hidden'); return; }
    const q = new URLSearchParams({ mode: _reportMode, start: LA?.period?.start || '', end: LA?.period?.end || '', ai: withAi ? '1' : '0' });
    closeReport();
    window.open('../backend/cert_analytics_report.php?' + q.toString(), '_blank');
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeReport(); });
document.querySelectorAll('input[name="rmAi"]').forEach(r => r.addEventListener('change', () => document.getElementById('rmError').classList.add('hidden')));

render(); resetAi(); loadCachedAi();
</script>
</body>
</html>

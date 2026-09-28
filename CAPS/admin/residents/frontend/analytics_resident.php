<?php
/**
 * ADMIN/residents/frontend/analytics_resident.php — Resident Analytics dashboard.
 * Data: ../backend/resident_analytics_data.php (actual residents / household records).
 * Date range: both empty = all records; otherwise residents registered within the range.
 * AI: ../backend/resident_ai_summary.php — only when "Generate AI Analytics" is clicked.
 * Save PDF / Print: ../backend/resident_analytics_report.php (shared CAPS report layout).
 */
require_once __DIR__ . '/../backend/residents.php';
require_once __DIR__ . '/../../permission_helper.php';
require_once __DIR__ . '/../../theme_loader.php';
require_once __DIR__ . '/../backend/resident_analytics_data.php';

$raParams = ra_params($_GET);
try {
    $raData = ra_compute($pdo, $raParams);
    $raData['interpretation'] = ra_interpretation($raData);
} catch (Throwable $e) {
    error_log('Resident analytics failed: ' . $e->getMessage());
    $raData = null;
}
?>
<!DOCTYPE html>
<html <?= $theme_attrs['html'] ?>>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Resident Analytics — Barangay Biñang 2nd</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=DM+Mono:wght@400;500&display=swap"
        rel="stylesheet">
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
        rel="stylesheet">
    <?php include __DIR__ . '/../../theme_head.php'; ?>
    <script>tailwind.config = { theme: { extend: { colors: { primary: { DEFAULT: 'var(--accent-600)', light: 'var(--accent-500)', dark: 'var(--accent-700)' } } } } };</script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--page-bg, #eef2fb) }
        .main-wrapper { margin-left: 288px; width: calc(100% - 288px) }
        .card { background: #fff; border: 1px solid #e8edf5; border-radius: 28px; box-shadow: 0 4px 18px rgba(15, 23, 42, .04) }
        .viz { --series-1: #2a78d6; --series-2: #eb6834; --series-3: #1baf7a; --muted: #94a3b8; --grid: #eef2f7 }
        .kpi { border: 1px solid #eef2f7; border-radius: 20px; padding: 16px 18px; background: #fff }
        .kpi .k-label { font-size: 9px; font-weight: 900; text-transform: uppercase; letter-spacing: .08em; color: #64748b }
        .kpi .k-value { font-size: 28px; font-weight: 900; color: #0f172a; line-height: 1.1; margin-top: 4px; font-variant-numeric: tabular-nums }
        .kpi .k-sub { font-size: 10px; font-weight: 700; color: #94a3b8; margin-top: 2px }
        .chart-card { border: 1px solid #eef2f7; border-radius: 24px; padding: 20px; background: #fff; display: flex; flex-direction: column; min-width: 0 }
        .chart-card h3 { font-size: 14px; font-weight: 900; color: #1e293b }
        .chart-card .c-sub { font-size: 10px; font-weight: 700; color: #94a3b8; margin-top: 2px }
        .chart-box { position: relative; height: 240px; margin-top: 14px }
        .chart-box.tall { height: 300px }
        details.tv { margin-top: 10px }
        details.tv summary { cursor: pointer; font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: .06em }
        table.dt { width: 100%; border-collapse: collapse; font-size: 12px }
        table.dt th { text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: .06em; color: #94a3b8; font-weight: 900; padding: 8px 10px; border-bottom: 1px solid #eef2f7; background: #f8fafc }
        table.dt td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; color: #334155; font-weight: 600 }
        table.dt td.num, table.dt th.num { text-align: right; font-variant-numeric: tabular-nums }
        table.dt tr.total td { font-weight: 900; border-top: 1.5px solid #cbd5e1; background: #f8fafc }
        .empty { font-size: 12px; color: #94a3b8; font-style: italic; text-align: center; padding: 40px 10px }
        @media(max-width:1024px) { .main-wrapper { margin-left: 0; width: 100% } }
    </style>
</head>

<body <?= $theme_attrs['body'] ?>>
    <div class="flex min-h-screen"><?php include __DIR__ . '/../../sidebar.php'; ?>
        <div class="flex-1 flex flex-col min-w-0 main-wrapper"><?php include __DIR__ . '/../../header.php'; ?>
            <main class="p-4 md:p-6 lg:p-8 space-y-7 viz">
                <?php echo csrf_token(); ?>
                <section class="rounded-2xl p-6 md:p-8 text-white relative overflow-hidden"
                    style="background:linear-gradient(135deg,var(--accent-700) 0%,var(--accent-600) 50%,var(--accent-700) 100%);">
                    <div class="relative z-10 flex flex-col xl:flex-row xl:items-center xl:justify-between gap-5">
                        <div>
                            <div class="flex items-center gap-2 text-white/60 text-[10px] font-black uppercase tracking-[0.18em] mb-2">
                                <span class="material-symbols-outlined text-base">analytics</span>Resident Management
                            </div>
                            <h1 class="text-2xl md:text-3xl font-black tracking-tight leading-none">Resident Analytics</h1>
                            <p class="text-white/65 text-sm mt-2 font-medium max-w-2xl">Population, demographic, household
                                and area statistics from the actual resident records.</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <button type="button" onclick="openResidentReport('pdf')"
                                class="inline-flex items-center gap-2 bg-white text-primary px-5 py-3 rounded-xl font-black text-xs uppercase tracking-wider"><span
                                    class="material-symbols-outlined text-lg">picture_as_pdf</span>Save PDF</button>
                            <button type="button" onclick="openResidentReport('print')"
                                class="inline-flex items-center gap-2 bg-white/10 hover:bg-white/20 border border-white/20 text-white px-5 py-3 rounded-xl font-black text-xs uppercase tracking-wider"><span
                                    class="material-symbols-outlined text-lg">print</span>Print</button>
                            <a href="residents.php"
                                class="inline-flex items-center gap-2 bg-white/10 hover:bg-white/20 border border-white/20 text-white px-5 py-3 rounded-xl font-black text-xs uppercase tracking-wider"><span
                                    class="material-symbols-outlined text-lg">arrow_back</span>Back to Residents</a>
                        </div>
                    </div>
                </section>

                <!-- Date range -->
                <section class="card p-5 md:p-6">
                    <form id="rangeForm" class="flex flex-wrap items-end gap-4" onsubmit="applyRange(event)">
                        <div>
                            <label for="startDate" class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">Start Date</label>
                            <input type="date" id="startDate" max="9999-12-31" value="<?= htmlspecialchars($raParams['start']) ?>"
                                class="bg-slate-100 border-none rounded-xl py-2.5 px-4 text-sm font-bold focus:ring-2 focus:ring-primary/20">
                        </div>
                        <div>
                            <label for="endDate" class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">End Date</label>
                            <input type="date" id="endDate" max="9999-12-31" value="<?= htmlspecialchars($raParams['end']) ?>"
                                class="bg-slate-100 border-none rounded-xl py-2.5 px-4 text-sm font-bold focus:ring-2 focus:ring-primary/20">
                        </div>
                        <button type="submit" class="inline-flex items-center gap-2 bg-primary text-white px-5 py-2.5 rounded-xl font-black text-xs uppercase tracking-wider">
                            <span class="material-symbols-outlined text-base">filter_alt</span>Apply</button>
                        <button type="button" onclick="clearRange()" class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-500 font-black text-xs uppercase tracking-wider hover:text-slate-700">Clear</button>
                        <div class="ml-auto text-right">
                            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Showing</p>
                            <p id="rangeLabel" class="text-sm font-black text-slate-800"></p>
                            <p id="rangeBasis" class="text-[10px] font-bold text-slate-400"></p>
                        </div>
                    </form>
                </section>

                <!-- AI Analytics (above Resident Summary) -->
                <section class="card p-6 md:p-8">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-white bg-indigo-600 p-2 rounded-xl">auto_awesome</span>
                            <div>
                                <h2 class="text-lg font-black text-slate-800">AI Analytics</h2>
                                <p id="aiStatus" class="text-[10px] text-slate-400 font-bold">Not generated. AI runs only when you click the button.</p>
                            </div>
                        </div>
                        <button type="button" id="aiBtn" onclick="generateAi()"
                            class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-3 rounded-xl font-black text-xs uppercase tracking-wider disabled:opacity-60">
                            <span class="material-symbols-outlined text-lg">auto_awesome</span><span id="aiBtnText">Generate AI Analytics</span></button>
                    </div>
                    <div id="aiBody" class="mt-6"></div>
                </section>

                <!-- Data Interpretation (directly below AI Analytics) -->
                <div id="raInterp"></div>

                <div id="raBody" class="space-y-7"></div>
            </main>
        </div>
    </div>

    <!-- Save PDF / Print: include AI analytics? -->
    <div id="reportModal" class="fixed inset-0 z-[999] hidden bg-slate-900/70 items-center justify-center p-4" onclick="if (event.target === this) closeReportModal()">
        <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-md p-8" role="dialog" aria-modal="true" aria-labelledby="rmTitle">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 id="rmTitle" class="text-2xl font-black text-slate-900 tracking-tight">Save as PDF</h3>
                    <p class="text-[11px] font-black text-indigo-600 uppercase tracking-widest mt-1">Resident Analytics Report</p>
                    <p id="rmRange" class="text-xs font-bold text-slate-400 mt-1"></p>
                </div>
                <button type="button" onclick="closeReportModal()" class="p-1 text-slate-400 hover:text-slate-700" aria-label="Close">
                    <span class="material-symbols-outlined">close</span></button>
            </div>
            <p class="text-sm font-bold text-slate-700 mt-6 mb-3">Include AI analytics in the report?</p>
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
                <button type="button" onclick="closeReportModal()" class="px-6 py-3 rounded-2xl border border-slate-200 text-xs font-black uppercase tracking-wider text-slate-500 hover:text-slate-700">Cancel</button>
                <button type="button" onclick="continueReport()" class="px-6 py-3 rounded-2xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-black uppercase tracking-wider shadow-lg">Continue</button>
            </div>
        </div>
    </div>

    <script>
        const RA_ENDPOINT = '../backend/resident_analytics_data.php';
        let RA = <?= json_encode($raData, JSON_UNESCAPED_UNICODE) ?>;
        let RA_AI = null;           // AI result for the data currently shown
        const charts = {};
        const COLORS = { s1: '#2a78d6', s2: '#eb6834', s3: '#1baf7a', muted: '#94a3b8', grid: '#eef2f7' };

        const esc = v => { const d = document.createElement('div'); d.textContent = v == null ? '' : String(v); return d.innerHTML; };
        const num = v => Number(v || 0).toLocaleString('en-US');
        const pct = (v, d) => d ? (Math.round(Number(v || 0) / d * 1000) / 10) + '%' : '0%';
        const csrf = () => document.querySelector('input[name="csrf_token"]')?.value || '';

        if (window.Chart) {
            Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
            Chart.defaults.font.size = 11;
            Chart.defaults.color = '#64748b';
            Chart.defaults.plugins.tooltip.backgroundColor = '#0f172a';
            Chart.defaults.plugins.tooltip.padding = 10;
            Chart.defaults.plugins.tooltip.cornerRadius = 10;
        }

        /* ───────── Rendering helpers ───────── */
        function kpi(label, value, sub) {
            return `<div class="kpi"><p class="k-label">${esc(label)}</p><p class="k-value">${esc(value)}</p>${sub ? `<p class="k-sub">${esc(sub)}</p>` : ''}</div>`;
        }
        function card(id, title, sub, opts = {}) {
            return `<div class="chart-card ${opts.span || ''}"><h3>${esc(title)}</h3><p class="c-sub">${esc(sub)}</p>
                ${opts.empty ? `<p class="empty">${esc(opts.empty)}</p>` : `<div class="chart-box ${opts.tall ? 'tall' : ''}"><canvas id="${id}" role="img" aria-label="${esc(title)}"></canvas></div>`}
                ${opts.table ? `<details class="tv"><summary>View table</summary><div class="mt-2 overflow-x-auto">${opts.table}</div></details>` : ''}</div>`;
        }
        function table(head, rows, numCols = []) {
            if (!rows.length) return '<p class="empty">No data for the selected range.</p>';
            return `<table class="dt"><thead><tr>${head.map((h, i) => `<th class="${numCols.includes(i) ? 'num' : ''}">${esc(h)}</th>`).join('')}</tr></thead><tbody>` +
                rows.map(r => `<tr class="${r[0] === 'Total' ? 'total' : ''}">${r.map((c, i) => `<td class="${numCols.includes(i) ? 'num' : ''}">${esc(c)}</td>`).join('')}</tr>`).join('') + '</tbody></table>';
        }
        function distRows(obj, base) {
            const rows = Object.entries(obj).map(([k, v]) => [k, num(v), pct(v, base)]);
            if (rows.length) rows.push(['Total', num(Object.values(obj).reduce((s, v) => s + v, 0)), pct(Object.values(obj).reduce((s, v) => s + v, 0), base)]);
            return rows;
        }
        function topN(obj, n) {
            const e = Object.entries(obj);
            if (e.length <= n) return obj;
            const out = Object.fromEntries(e.slice(0, n));
            out['Others'] = (out['Others'] || 0) + e.slice(n).reduce((s, [, v]) => s + v, 0);
            return out;
        }
        function section(icon, title, sub, inner) {
            return `<section class="card p-6 md:p-8"><div class="flex items-center gap-3 mb-6"><span class="material-symbols-outlined text-white bg-primary p-2 rounded-xl">${icon}</span>
                <div><h2 class="text-lg font-black text-slate-800">${esc(title)}</h2><p class="text-[10px] text-slate-400 font-bold">${esc(sub)}</p></div></div>${inner}</section>`;
        }

        const axisCount = { beginAtZero: true, ticks: { precision: 0 }, grid: { color: COLORS.grid }, border: { display: false } };
        const noGrid = { grid: { display: false }, border: { display: false } };
        function barH(id, obj, label) {       // one series → one color (slot 1)
            return new Chart(document.getElementById(id), {
                type: 'bar',
                data: { labels: Object.keys(obj), datasets: [{ label, data: Object.values(obj), backgroundColor: COLORS.s1, borderRadius: 4, maxBarThickness: 18 }] },
                options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: axisCount, y: noGrid } }
            });
        }
        function barV(id, obj, label, color) {
            return new Chart(document.getElementById(id), {
                type: 'bar',
                data: { labels: Object.keys(obj), datasets: [{ label, data: Object.values(obj), backgroundColor: color || COLORS.s1, borderRadius: 4, maxBarThickness: 36 }] },
                options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: noGrid, y: axisCount } }
            });
        }

        /* ───────── Page ───────── */
        function render() {
            Object.values(charts).forEach(c => c.destroy());
            for (const k in charts) delete charts[k];
            const body = document.getElementById('raBody');
            document.getElementById('raInterp').innerHTML = '';
            document.getElementById('rangeLabel').textContent = RA ? RA.period.label : '';
            document.getElementById('rangeBasis').textContent = RA && (RA.period.start || RA.period.end) ? 'Residents registered within the range' : 'All resident records';
            if (!RA) { body.innerHTML = '<section class="card p-8"><p class="empty">Unable to load resident analytics.</p></section>'; return; }

            const t = RA.totals, living = t.active;
            const ageKnown = Object.values(RA.age).reduce((s, b) => s + b.total, 0);
            const sexObj = Object.fromEntries(Object.entries(RA.sex).filter(([k, v]) => k !== 'Not specified' || v > 0));

            // Summary cards
            let html = section('groups', 'Resident Summary', 'Every record in the selected range · ' + RA.period.label,
                `<div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4">
                    ${kpi('Total Residents', num(t.total), 'records in range')}
                    ${kpi('Male', num(t.male), pct(t.male, t.total) + ' of total')}
                    ${kpi('Female', num(t.female), pct(t.female, t.total) + ' of total')}
                    ${kpi('Sex Not Specified', num(t.other_sex), pct(t.other_sex, t.total) + ' of total')}
                    ${kpi('Total Households', num(t.households), t.avg_household_size ? 'avg ' + t.avg_household_size + ' persons' : 'by Household Head')}
                    ${kpi('Household Heads', num(t.heads), pct(t.heads, living) + ' of living')}
                    ${kpi('Household Members', num(t.members), pct(t.members, living) + ' of living')}
                    ${kpi('Active Residents', num(t.active), pct(t.active, t.total) + ' of total')}
                    ${kpi('Deceased Records', num(t.deceased), pct(t.deceased, t.total) + ' of total')}
                    ${kpi('Not Linked to Household', num(t.unlinked), pct(t.unlinked, living) + ' of living')}
                </div>`);

            // Demographics
            const ageRows = Object.entries(RA.age).map(([k, b]) => [k, num(b.male), num(b.female), num(b.total), pct(b.total, ageKnown)]);
            if (ageKnown) ageRows.push(['Total', num(Object.values(RA.age).reduce((s, b) => s + b.male, 0)), num(Object.values(RA.age).reduce((s, b) => s + b.female, 0)), num(ageKnown), '100%']);
            const hsRows = Object.entries(RA.household_size).map(([k, v]) => [k + (k === '1' ? ' person' : ' persons'), num(v), pct(v, t.households)]);
            html += section('diversity_3', 'Demographic Analytics', 'Living residents (deceased records excluded), except Sex which covers every record',
                `<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    ${card('c-age', 'Age Distribution', 'By age group and sex · ' + num(ageKnown) + ' with a valid birth date' + (t.median_age !== null ? ' · median ' + t.median_age + ' yrs' : ''),
                        { span: 'lg:col-span-2', empty: ageKnown ? '' : 'No valid birth dates in this range.', table: table(['Age group', 'Male', 'Female', 'Total', 'Share'], ageRows, [1, 2, 3, 4]) })}
                    ${card('c-sex', 'Sex Distribution', 'Share of all records', { empty: t.total ? '' : 'No records.', table: table(['Sex', 'Count', 'Share'], distRows(RA.sex, t.total), [1, 2]) })}
                    ${card('c-civil', 'Civil Status', 'Living residents', { empty: living ? '' : 'No data.', table: table(['Civil status', 'Count', 'Share'], distRows(RA.civil_status, living), [1, 2]) })}
                    ${card('c-rel', 'Relationship to Household Head', 'Living residents', { empty: living ? '' : 'No data.', table: table(['Relationship', 'Count', 'Share'], distRows(RA.relationship, living), [1, 2]) })}
                    ${card('c-hsize', 'Household Size Distribution', 'Persons per household, Head included', { empty: t.households ? '' : 'No households in this range.', table: table(['Household size', 'Households', 'Share'], hsRows, [1, 2]) })}
                    ${Object.keys(RA.religion).length ? card('c-religion', 'Religion', 'Living residents with a recorded religion', { span: 'lg:col-span-3', table: table(['Religion', 'Count', 'Share'], distRows(RA.religion, Object.values(RA.religion).reduce((s, v) => s + v, 0)), [1, 2]) }) : ''}
                </div>`);

            // Address / location
            const areaRows = RA.areas.map(x => [x.area, num(x.total), pct(x.total, living), num(x.male), num(x.female), num(x.households), num(x.minors), num(x.seniors), num(x.pwd)]);
            if (areaRows.length) areaRows.push(['Total', num(living), '100%', num(RA.areas.reduce((s, x) => s + x.male, 0)), num(RA.areas.reduce((s, x) => s + x.female, 0)),
                num(RA.areas.reduce((s, x) => s + x.households, 0)), num(RA.areas.reduce((s, x) => s + x.minors, 0)), num(RA.areas.reduce((s, x) => s + x.seniors, 0)), num(RA.areas.reduce((s, x) => s + x.pwd, 0))]);
            const areaObj = topN(Object.fromEntries(RA.areas.map(x => [x.area, x.total])), 12);
            const hhRows = RA.households.slice(0, 15).map(h => [h.household_id, h.head, h.address, num(h.size)]);
            html += section('location_on', 'Address & Location Analytics', 'Living residents by Subdivision / Village / Sitio / Purok, street and household',
                `<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    ${card('c-area', 'Residents by Purok / Sitio / Area', 'Top areas by living residents', { empty: living ? '' : 'No data.' })}
                    ${card('c-street', 'Residents by Street', 'Top streets by living residents', { empty: living ? '' : 'No data.', table: table(['Street', 'Residents', 'Share'], distRows(topN(RA.streets, 15), living), [1, 2]) })}
                </div>
                <div class="mt-6 chart-card"><h3>Population per Area</h3><p class="c-sub">Residents, households and sectors per area</p>
                    <div class="mt-3 overflow-x-auto">${table(['Area', 'Residents', 'Share', 'Male', 'Female', 'Households', 'Minors', 'Seniors', 'PWD'], areaRows, [1, 2, 3, 4, 5, 6, 7, 8])}</div></div>
                <div class="mt-6 chart-card"><h3>Residents by Household</h3><p class="c-sub">${RA.households.length ? 'Largest ' + Math.min(15, RA.households.length) + ' of ' + num(RA.households.length) + ' households' : 'Households in the selected records'}</p>
                    <div class="mt-3 overflow-x-auto">${table(['Household ID', 'Household Head', 'Address', 'Members'], hhRows, [3])}</div></div>`);

            // Other available data
            const progObj = RA.programs;
            const progRows = Object.entries(progObj).map(([k, v]) => [k, num(v), pct(v, living), num(Math.max(0, living - v))]);
            const months = Object.keys(RA.monthly);
            let trend = '';
            if (months.length > 1) trend = card('c-month', 'Residents Registered per Month', 'Registration date (CreatedAt)', { span: 'lg:col-span-2', table: table(['Month', 'Registered'], months.map(m => [m, num(RA.monthly[m])]), [1]) });
            let comparison = '';
            if (RA.comparison) {
                comparison = `<div class="chart-card lg:col-span-2"><h3>Selected Period vs Previous Period</h3><p class="c-sub">Previous period: ${esc(RA.comparison.previous_label)}</p>
                    <div class="chart-box"><canvas id="c-compare" role="img" aria-label="Period comparison"></canvas></div>
                    <div class="mt-3 overflow-x-auto">${table(['Indicator', 'Selected', 'Previous', 'Change'], RA.comparison.rows.map(r => [r.metric, num(r.current), num(r.previous), (r.current - r.previous > 0 ? '+' : '') + num(r.current - r.previous)]), [1, 2, 3])}</div></div>`;
            }
            html += section('insights', 'Other Resident Data', 'Socio-economic status, sectors and programs of living residents, and registration trends',
                `<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    ${card('c-emp', 'Employment Status', 'Living residents', { empty: living ? '' : 'No data.', table: table(['Status', 'Count', 'Share'], distRows(RA.employment, living), [1, 2]) })}
                    ${card('c-prog', 'Sector & Program Coverage', '% of living residents', { empty: living ? '' : 'No data.', table: table(['Sector / program', 'Members', 'Coverage', 'Not covered'], progRows, [1, 2, 3]) })}
                    ${Object.keys(RA.education).length ? card('c-edu', 'Highest Education', 'Living residents with a recorded education level', { span: 'lg:col-span-2', table: table(['Education', 'Count', 'Share'], distRows(RA.education, Object.values(RA.education).reduce((s, v) => s + v, 0)), [1, 2]) }) : ''}
                    ${trend}${comparison}
                </div>`);

            document.getElementById('raInterp').innerHTML = section('fact_check', 'Data Interpretation', 'Automatic summary of the resident figures below (no AI) · ' + RA.period.label,
                `<ol class="list-decimal pl-5 space-y-2 text-sm text-slate-600 font-medium">${(RA.interpretation || []).map(x => `<li>${esc(x)}</li>`).join('')}</ol>`);
            body.innerHTML = html;

            if (!window.Chart) return;
            // Age: grouped bars, male (slot 1) / female (slot 2)
            if (ageKnown) charts.age = new Chart(document.getElementById('c-age'), {
                type: 'bar',
                data: { labels: Object.keys(RA.age), datasets: [
                    { label: 'Male', data: Object.values(RA.age).map(b => b.male), backgroundColor: COLORS.s1, borderRadius: 4, maxBarThickness: 22 },
                    { label: 'Female', data: Object.values(RA.age).map(b => b.female), backgroundColor: COLORS.s2, borderRadius: 4, maxBarThickness: 22 }] },
                options: { maintainAspectRatio: false, plugins: { legend: { position: 'top', align: 'end', labels: { boxWidth: 10, boxHeight: 10 } } }, scales: { x: noGrid, y: axisCount } }
            });
            if (t.total) charts.sex = new Chart(document.getElementById('c-sex'), {
                type: 'doughnut',
                data: { labels: Object.keys(sexObj), datasets: [{ data: Object.values(sexObj), backgroundColor: Object.keys(sexObj).map(k => k === 'Male' ? COLORS.s1 : k === 'Female' ? COLORS.s2 : COLORS.muted), borderColor: '#fff', borderWidth: 2 }] },
                options: { maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10 } },
                    tooltip: { callbacks: { label: c => ` ${c.label}: ${num(c.raw)} (${pct(c.raw, t.total)})` } } } }
            });
            if (living) {
                charts.civil = barH('c-civil', RA.civil_status, 'Residents');
                charts.rel = barH('c-rel', topN(RA.relationship, 10), 'Residents');
                charts.area = barH('c-area', areaObj, 'Residents');
                charts.street = barH('c-street', topN(RA.streets, 10), 'Residents');
                charts.emp = barH('c-emp', RA.employment, 'Residents');
                charts.prog = new Chart(document.getElementById('c-prog'), {
                    type: 'bar',
                    data: { labels: Object.keys(progObj), datasets: [{ label: 'Coverage %', data: Object.values(progObj).map(v => living ? Math.round(v / living * 1000) / 10 : 0), backgroundColor: COLORS.s1, borderRadius: 4, maxBarThickness: 18 }] },
                    options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => ` ${num(Object.values(progObj)[c.dataIndex])} residents (${c.raw}%)` } } },
                        scales: { x: { ...axisCount, max: 100, ticks: { callback: v => v + '%' } }, y: noGrid } }
                });
            }
            if (t.households) charts.hsize = barV('c-hsize', RA.household_size, 'Households');
            if (Object.keys(RA.religion).length) charts.religion = barH('c-religion', topN(RA.religion, 8), 'Residents');
            if (Object.keys(RA.education).length) charts.edu = barH('c-edu', RA.education, 'Residents');
            if (months.length > 1) charts.month = new Chart(document.getElementById('c-month'), {
                type: 'line',
                data: { labels: months.map(m => new Date(m + '-01T00:00:00').toLocaleDateString('en-US', { month: 'short', year: 'numeric' })),
                    datasets: [{ label: 'Registered', data: Object.values(RA.monthly), borderColor: COLORS.s1, backgroundColor: COLORS.s1, borderWidth: 2, pointRadius: 4, pointHoverRadius: 6, tension: 0 }] },
                options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { display: false } }, scales: { x: noGrid, y: axisCount } }
            });
            if (RA.comparison) charts.compare = new Chart(document.getElementById('c-compare'), {
                type: 'bar',
                data: { labels: RA.comparison.rows.map(r => r.metric), datasets: [
                    { label: 'Selected period', data: RA.comparison.rows.map(r => r.current), backgroundColor: COLORS.s1, borderRadius: 4, maxBarThickness: 22 },
                    { label: 'Previous period', data: RA.comparison.rows.map(r => r.previous), backgroundColor: COLORS.muted, borderRadius: 4, maxBarThickness: 22 }] },
                options: { maintainAspectRatio: false, plugins: { legend: { position: 'top', align: 'end', labels: { boxWidth: 10, boxHeight: 10 } } }, scales: { x: noGrid, y: axisCount } }
            });
        }

        /* ───────── Date range ───────── */
        function rangeQuery() {
            return new URLSearchParams({ start: document.getElementById('startDate').value, end: document.getElementById('endDate').value });
        }
        async function applyRange(e) {
            if (e) e.preventDefault();
            const s = document.getElementById('startDate').value, en = document.getElementById('endDate').value;
            if (s && en && s > en) { showMsg('The Start Date must be on or before the End Date.'); return; }
            const q = rangeQuery();
            history.replaceState({}, '', location.pathname + (s || en ? '?' + q.toString() : ''));
            document.getElementById('raBody').style.opacity = '.5';
            try {
                const r = await fetch(RA_ENDPOINT + '?' + q.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                const j = await r.json();
                if (!j.success) throw new Error(j.error || 'Unable to load resident analytics.');
                RA = j.data;
            } catch (err) { showMsg(err.message); }
            document.getElementById('raBody').style.opacity = '';
            render();
            resetAi();
            loadCachedAi();
        }
        function clearRange() {
            document.getElementById('startDate').value = '';
            document.getElementById('endDate').value = '';
            applyRange();
        }
        function showMsg(m) {
            if (typeof showToast === 'function') showToast(m, 'warning'); else alert(m);
        }

        /* ───────── AI Analytics (only on click) ───────── */
        function resetAi() {
            RA_AI = null;
            document.getElementById('aiBody').innerHTML = '';
            document.getElementById('aiStatus').textContent = 'Not generated for this date range. AI runs only when you click the button.';
            document.getElementById('aiBtnText').textContent = 'Generate AI Analytics';
        }
        function renderAi(x) {
            RA_AI = x;
            document.getElementById('aiStatus').textContent = 'Generated ' + (x.generated_at || '') + ' · ' + (x.period?.label || '') + (x.cached ? ' · saved result' : '');
            document.getElementById('aiBtnText').textContent = 'Regenerate AI Analytics';
            const group = (title, icon, list) => list && list.length ? `<div class="rounded-2xl border border-indigo-100 bg-indigo-50/40 p-5"><div class="flex items-center gap-2 mb-3"><span class="material-symbols-outlined text-indigo-600">${icon}</span><h5 class="text-[10px] font-black uppercase tracking-widest text-indigo-700">${esc(title)}</h5></div>
                <ul class="space-y-2">${list.map(f => `<li class="text-xs leading-relaxed text-slate-600"><strong class="text-slate-800">${esc(f.title)}</strong> — ${esc(f.detail)}</li>`).join('')}</ul></div>` : '';
            const recs = (x.recommendations || []).map((f, i) => `<li class="text-xs leading-relaxed text-slate-600"><strong class="text-slate-800">${i + 1}. ${esc(f.action)}</strong>
                <span class="ml-1 px-2 py-0.5 rounded-full text-[9px] font-black uppercase ${f.priority === 'high' ? 'bg-rose-100 text-rose-700' : f.priority === 'low' ? 'bg-slate-100 text-slate-600' : 'bg-amber-100 text-amber-700'}">${esc(f.priority)}</span><br>${esc(f.reason)}</li>`).join('');
            document.getElementById('aiBody').innerHTML =
                (x.summary ? `<p class="text-sm font-semibold text-slate-700 mb-5">${esc(x.summary)}</p>` : '') +
                `<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">${group('Key Findings', 'insights', x.key_findings)}${group('Demographic Trends', 'trending_up', x.demographic_trends)}
                 ${group('Significant Changes / Patterns', 'timeline', x.patterns)}${group('Population Observations', 'groups', x.population_observations)}</div>` +
                (recs ? `<div class="rounded-2xl border border-indigo-100 bg-white p-5 mt-4"><div class="flex items-center gap-2 mb-3"><span class="material-symbols-outlined text-indigo-600">task_alt</span><h5 class="text-[10px] font-black uppercase tracking-widest text-indigo-700">Recommended Actions</h5></div><ul class="space-y-3">${recs}</ul></div>` : '') +
                '<p class="text-[10px] text-slate-400 font-bold mt-4">AI-generated from aggregated resident figures only. Verify against the tables above.</p>';
        }
        async function aiRequest(cachedOnly) {
            const fd = new FormData();
            fd.append('csrf_token', csrf());
            fd.append('start', RA?.period?.start || '');
            fd.append('end', RA?.period?.end || '');
            if (cachedOnly) fd.append('cached_only', '1');
            const r = await fetch('../backend/resident_ai_summary.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const raw = await r.text();
            let j = null;
            try { j = raw ? JSON.parse(raw) : null; } catch (e) { throw new Error('The AI service returned an invalid response.'); }
            if (!j || !j.success) throw new Error(j?.error || 'AI Analytics is unavailable right now.');
            return j;
        }
        async function generateAi() {
            const btn = document.getElementById('aiBtn');
            btn.disabled = true;
            document.getElementById('aiBtnText').textContent = 'Generating…';
            document.getElementById('aiStatus').textContent = 'Generating AI Analytics for ' + (RA?.period?.label || '') + '…';
            try { renderAi(await aiRequest(false)); }
            catch (e) {
                document.getElementById('aiStatus').textContent = e.message;
                document.getElementById('aiBtnText').textContent = RA_AI ? 'Regenerate AI Analytics' : 'Generate AI Analytics';
            }
            btn.disabled = false;
        }
        // Shows an analysis generated earlier for the SAME data (no Gemini call).
        async function loadCachedAi() {
            try { const j = await aiRequest(true); if (j.ai) renderAi(j); } catch (e) { }
        }

        /* ───────── Save PDF / Print ───────── */
        let _reportMode = 'pdf';
        function openResidentReport(mode) {
            _reportMode = mode;
            document.getElementById('rmTitle').textContent = mode === 'pdf' ? 'Save as PDF' : 'Print';
            document.getElementById('rmRange').textContent = RA?.period?.label || '';
            document.querySelector('input[name="rmAi"][value="0"]').checked = true;
            document.getElementById('rmError').classList.add('hidden');
            const m = document.getElementById('reportModal');
            m.classList.remove('hidden'); m.classList.add('flex');
        }
        function closeReportModal() {
            const m = document.getElementById('reportModal');
            m.classList.add('hidden'); m.classList.remove('flex');
        }
        function continueReport() {
            const withAi = document.querySelector('input[name="rmAi"]:checked')?.value === '1';
            // Never generates AI here — only uses the analysis already generated on this page.
            if (withAi && !RA_AI) { document.getElementById('rmError').classList.remove('hidden'); return; }
            const q = new URLSearchParams({ mode: _reportMode, start: RA?.period?.start || '', end: RA?.period?.end || '', ai: withAi ? '1' : '0' });
            closeReportModal();
            window.open('../backend/resident_analytics_report.php?' + q.toString(), '_blank');
        }
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeReportModal(); });
        document.querySelectorAll('input[name="rmAi"]').forEach(r => r.addEventListener('change', () => document.getElementById('rmError').classList.add('hidden')));

        render();
        resetAi();
        loadCachedAi();
    </script>
</body>

</html>

<?php
/**
 * disaster_analytics.php — full-page Disaster Analytics (Announcement module)
 *
 * Opened from the "Disaster Analytics" card on ann.php (replaces the old popup panel).
 *  - Start Date / End Date / Apply filter → every card, chart, table, AI analytics
 *    and printout uses that same date range
 *  - AI analytics is generated ONLY when "Generate Analytics" is pressed
 *  - Print (automatic print preview) and Save as PDF share one layout, with the
 *    option to include or leave out the AI Analytics Overview
 */

require_once __DIR__ . '/partials/analytics_bootstrap.php';
require_once __DIR__ . '/../backend/disaster_analytics_data.php';

// ── AJAX: analytics data for the selected date range ─────────────────────────
if (($_GET['action'] ?? '') === 'data') {
    header('Content-Type: application/json; charset=utf-8');
    [$start, $end] = disaster_analytics_parse_range($_GET['start'] ?? null, $_GET['end'] ?? null);
    try {
        echo json_encode(['success' => true] + disaster_analytics_collect($pdo, $start, $end, (string) ($_GET['category'] ?? 'All')));
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Could not load the disaster analytics: ' . $e->getMessage()]);
    }
    exit;
}

[$init_start, $init_end] = disaster_analytics_parse_range($_GET['start'] ?? null, $_GET['end'] ?? null);
$init_category = in_array($_GET['category'] ?? '', disaster_analytics_categories(), true) ? $_GET['category'] : 'All';
$page_title = 'Disaster Analytics';
?>
<!DOCTYPE html>
<html <?php echo $theme_attrs['html']; ?>>

<head>
    <?php include __DIR__ . '/partials/analytics_head.php'; ?>
</head>

<body <?php echo $theme_attrs['body']; ?>>
    <div class="flex min-h-screen">
        <?php include __DIR__ . '/../../sidebar.php'; ?>

        <div class="flex-1 flex flex-col min-w-0 main-wrapper">
            <?php include __DIR__ . '/../../header.php'; ?>

            <main class="p-4 md:p-6 lg:p-8 space-y-6">

                <!-- ── Hero + Save as PDF / Print (upper part of the page) ───────── -->
                <div class="rounded-2xl p-6 md:p-8 text-white relative overflow-hidden"
                    style="background: linear-gradient(135deg, var(--accent-700) 0%, var(--accent-600) 50%, var(--accent-700) 100%);">
                    <div class="absolute -right-12 -top-12 w-64 h-64 opacity-10 rounded-full blur-3xl pointer-events-none" style="background: var(--accent-400);"></div>
                    <div class="relative z-10 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                        <div>
                            <a href="ann.php" class="inline-flex items-center gap-1 text-white/70 hover:text-white text-[11px] font-bold uppercase tracking-widest mb-3">
                                <span class="material-symbols-outlined" style="font-size:16px">arrow_back</span> Announcements
                            </a>
                            <h1 class="text-2xl md:text-3xl font-black tracking-tight leading-none">Disaster Analytics</h1>
                            <p class="text-white/60 text-sm mt-2 font-medium">Comprehensive disaster statistics, charts and records for the selected date range.</p>
                        </div>
                        <div class="flex flex-wrap gap-3 flex-shrink-0">
                            <button type="button" onclick="exportReport('pdf')" class="btn bg-white/10 hover:bg-white/20 border border-white/20 text-white">
                                <span class="material-symbols-outlined" style="font-size:18px">picture_as_pdf</span> Save as PDF
                            </button>
                            <button type="button" onclick="exportReport('print')" class="btn bg-white text-slate-900 hover:bg-slate-100 shadow-lg">
                                <span class="material-symbols-outlined" style="font-size:18px">print</span> Print
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ── Date Range Filter ───────────────────────────────────────── -->
                <div class="panel p-5">
                    <form id="rangeForm" class="flex flex-wrap items-end gap-3" onsubmit="event.preventDefault(); applyRange();">
                        <div>
                            <label for="startDate" class="section-title block mb-1.5">Start Date</label>
                            <input type="date" id="startDate" class="field" value="<?php echo htmlspecialchars($init_start); ?>" required>
                        </div>
                        <div>
                            <label for="endDate" class="section-title block mb-1.5">End Date</label>
                            <input type="date" id="endDate" class="field" value="<?php echo htmlspecialchars($init_end); ?>" required>
                        </div>
                        <div>
                            <label for="category" class="section-title block mb-1.5">Category</label>
                            <select id="category" class="field cursor-pointer pr-8">
                                <option value="All">All Categories</option>
                                <?php foreach (disaster_analytics_categories() as $c): ?>
                                    <option value="<?php echo $c; ?>" <?php echo $init_category === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-dark">
                            <span class="material-symbols-outlined" style="font-size:16px">filter_alt</span> Apply
                        </button>
                        <div class="flex flex-wrap gap-1.5 lg:ml-auto">
                            <button type="button" class="btn btn-light !px-3" onclick="presetRange('month')">This Month</button>
                            <button type="button" class="btn btn-light !px-3" onclick="presetRange('30')">Last 30 Days</button>
                            <button type="button" class="btn btn-light !px-3" onclick="presetRange('quarter')">Last 3 Months</button>
                            <button type="button" class="btn btn-light !px-3" onclick="presetRange('year')">This Year</button>
                        </div>
                    </form>
                    <p id="rangeError" class="hidden mt-3 text-xs font-bold text-rose-500"></p>
                    <p class="mt-3 text-[11px] text-slate-400 font-semibold">
                        Showing: <span id="rangeLabel" class="text-slate-600">—</span>
                        <span id="loadingDot" class="hidden ml-2 text-indigo-500"><span class="inline-flex items-center gap-1"><span class="w-3 h-3 border-2 border-indigo-200 border-t-indigo-600 rounded-full animate-spin"></span> Loading…</span></span>
                    </p>
                </div>

                <!-- ── Summary Cards ───────────────────────────────────────────── -->
                <div>
                    <p class="panel-desc mb-3">Overall totals for the selected period. Alerts are counted by the date they were issued; impact figures come from the final reports submitted when each disaster was deactivated.</p>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4" id="kpiGrid"></div>
                </div>

                <!-- Quick insights -->
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4" id="insightGrid"></div>

                <!-- ── AI Generated Analytics (on demand only) ─────────────────── -->
                <div class="panel overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                                <span class="material-symbols-outlined">insights</span>
                            </span>
                            <div>
                                <p class="text-sm font-black text-slate-800">AI Generated Analytics</p>
                                <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400" id="aiMeta">Not generated yet</p>
                            </div>
                        </div>
                        <button type="button" id="aiBtn" class="btn btn-ai" onclick="generateAnalytics()">
                            <span class="material-symbols-outlined" style="font-size:16px">auto_awesome</span>
                            <span data-label>Generate Analytics</span>
                        </button>
                    </div>
                    <div id="aiPanel" class="p-6 bg-indigo-50/30">
                        <div id="aiBody">
                            <p class="panel-desc">Press <strong>Generate Analytics</strong> to have the AI read the figures for the selected date range and produce <strong>Key Findings</strong>, <strong>Trends</strong>, <strong>Recommended Actions</strong> and <strong>Other Observations</strong>. It is not generated automatically, so no AI tokens are used until you ask for it.</p>
                        </div>
                        <p class="mt-4 text-[10px] text-slate-400 font-medium">AI-generated from the recorded figures only. Please verify before using it in an official report.</p>
                    </div>
                </div>

                <!-- ── Charts ─────────────────────────────────────────────────── -->
                <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                    <div class="panel p-6 xl:col-span-2">
                        <p class="section-title mb-1">Disaster Records by Category</p>
                        <p class="panel-desc mb-4">Number of disaster alerts issued per <span class="granularity">period</span>, split by category, within the selected dates. Rising lines show when a hazard became more frequent.</p>
                        <div class="chart-box" style="height:300px"><canvas id="trendChart"></canvas></div>
                    </div>
                    <div class="panel p-6">
                        <p class="section-title mb-1">Category Breakdown</p>
                        <p class="panel-desc mb-4">Share of all alerts in the period that belong to each disaster category.</p>
                        <div class="chart-box" style="height:300px"><canvas id="categoryChart"></canvas></div>
                    </div>
                </div>

                <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                    <div class="panel p-6 xl:col-span-2">
                        <p class="section-title mb-1">Impact by Category</p>
                        <p class="panel-desc mb-4">Affected residents, evacuees, injuries and casualties recorded in the final disaster reports, grouped by category.</p>
                        <div class="chart-box" style="height:280px"><canvas id="impactChart"></canvas></div>
                    </div>
                    <div class="panel p-6">
                        <p class="section-title mb-1">Report Status</p>
                        <p class="panel-desc mb-4">How the final disaster reports in the period were closed out (Resolved, Closed, Cancelled).</p>
                        <div class="chart-box" style="height:280px"><canvas id="statusChart"></canvas></div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="panel p-6">
                        <p class="section-title mb-1">Severity Levels</p>
                        <p class="panel-desc mb-4">Alerts grouped by the severity level set when they were issued.</p>
                        <div class="chart-box" style="height:230px"><canvas id="severityChart"></canvas></div>
                    </div>
                    <div class="panel p-6">
                        <p class="section-title mb-1">Notification Channels</p>
                        <p class="panel-desc mb-4">How residents were notified of each alert — in-app, SMS, both, or none.</p>
                        <div class="chart-box" style="height:230px"><canvas id="channelChart"></canvas></div>
                    </div>
                    <div class="panel p-6">
                        <p class="section-title mb-1">Alerts by Day of Week</p>
                        <p class="panel-desc mb-4">Which days of the week alerts were issued most often.</p>
                        <div class="chart-box" style="height:230px"><canvas id="weekdayChart"></canvas></div>
                    </div>
                </div>

                <!-- ── Category breakdown table ───────────────────────────────── -->
                <div class="panel overflow-hidden">
                    <div class="px-6 pt-6 pb-4">
                        <p class="section-title mb-1">Category Breakdown Summary</p>
                        <p class="panel-desc">Alerts issued, reports filed and total impact per disaster category for the selected period.</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full data-table">
                            <thead><tr><th>Category</th><th class="!text-right">Alerts</th><th class="!text-right">Reports</th><th class="!text-right">Affected</th><th class="!text-right">Evacuees</th><th class="!text-right">Injuries</th><th class="!text-right">Casualties</th></tr></thead>
                            <tbody id="typeTableBody"></tbody>
                        </table>
                    </div>
                </div>

                <!-- ── Disaster records ───────────────────────────────────────── -->
                <div class="panel overflow-hidden">
                    <div class="px-6 pt-6 pb-4 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p class="section-title mb-1">Disaster Records</p>
                            <p class="panel-desc">Every disaster report in the selected period. Click <strong>View</strong> to open, print or download a single report.</p>
                        </div>
                        <div class="relative w-full sm:w-64">
                            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" style="font-size:16px">search</span>
                            <input type="text" id="recordSearch" placeholder="Search title, type or ref no…" oninput="renderRecords()" class="field w-full !pl-9">
                        </div>
                    </div>
                    <div class="overflow-x-auto max-h-[560px] overflow-y-auto">
                        <table class="w-full data-table">
                            <thead class="sticky top-0 z-[1]"><tr><th>Disaster</th><th>Severity</th><th>Date Issued</th><th>Date Deactivated</th><th class="!text-right">Affected</th><th class="!text-right">Evacuees</th><th class="!text-right">Injuries</th><th class="!text-right">Casualties</th><th>Status</th><th class="!text-right">Actions</th></tr></thead>
                            <tbody id="recordsBody"></tbody>
                        </table>
                    </div>
                    <p class="px-6 py-3 text-[10px] font-bold text-slate-400 uppercase border-t border-slate-100" id="recordsCount">—</p>
                </div>
            </main>
        </div>
    </div>

    <!-- ── View Disaster Report Modal (Disaster ID · Title · Type · Severity · Message · Channels · SMS Live · Deactivation Report) ── -->
    <div id="viewDisasterReportModal"
        class="fixed inset-0 z-[110] hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-6">
        <div
            class="bg-white rounded-[2.5rem] shadow-2xl w-full max-w-2xl overflow-hidden border border-white/20 max-h-[92vh] flex flex-col">
            <div class="px-10 pt-10 pb-5 flex items-start justify-between border-b border-slate-100 flex-shrink-0">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-2">
                        <span id="drv_severity_badge"
                            class="inline-block px-3 py-1 rounded-full text-[8px] font-black text-white"></span>
                        <span id="drv_status_badge"
                            class="inline-block px-3 py-1 rounded-full text-[8px] font-black uppercase"></span>
                    </div>
                    <h3 id="drv_title" class="text-xl font-black tracking-tight text-slate-900 leading-tight truncate">
                    </h3>
                    <p
                        class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-1.5 flex items-center gap-3">
                        <span id="drv_id"></span>
                        <span class="text-slate-300">•</span>
                        <span id="drv_type" class="flex items-center gap-1"></span>
                    </p>
                </div>
                <button onclick="closeModal('viewDisasterReportModal')"
                    class="h-10 w-10 flex-shrink-0 flex items-center justify-center rounded-full bg-slate-50 text-slate-400 hover:text-slate-900 transition-all">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>

            <div class="p-8 overflow-y-auto space-y-5">
                <div class="p-5 bg-slate-50 rounded-2xl border border-slate-100">
                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-[0.2em] block mb-2">Message /
                        Emergency Instructions</label>
                    <p id="drv_message" class="text-slate-700 text-sm font-medium leading-relaxed"></p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="flex items-center gap-3 bg-slate-50 border border-slate-100 rounded-xl py-3.5 px-4">
                        <span class="material-symbols-outlined text-slate-400" style="font-size:18px">smartphone</span>
                        <div>
                            <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest">App Notification
                            </p>
                            <p id="drv_notify_app" class="text-xs font-black text-slate-700"></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 bg-slate-50 border border-slate-100 rounded-xl py-3.5 px-4">
                        <span class="material-symbols-outlined text-slate-400" style="font-size:18px">sms</span>
                        <div>
                            <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest">SMS Broadcast</p>
                            <p id="drv_notify_sms" class="text-xs font-black text-slate-700"></p>
                        </div>
                    </div>
                </div>

                <!-- SMS Live — shows whether the text for this disaster actually went out -->
                <div>
                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-[0.2em] block mb-2">SMS Live
                        Status</label>
                    <div id="drv_sms_live" class="space-y-2"></div>
                </div>

                <!-- Deactivation / final report form data submitted by admin -->
                <div class="pt-2 border-t border-slate-100">
                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-[0.2em] block mb-3">Final
                        Report (submitted on deactivation)</label>
                    <div class="grid grid-cols-4 gap-2 mb-3">
                        <div class="p-3 bg-slate-50 rounded-xl text-center">
                            <p class="text-[8px] font-bold text-slate-400 uppercase">Affected</p>
                            <p id="drv_affected" class="text-sm font-black text-slate-700"></p>
                        </div>
                        <div class="p-3 bg-slate-50 rounded-xl text-center">
                            <p class="text-[8px] font-bold text-slate-400 uppercase">Evacuees</p>
                            <p id="drv_evacuees" class="text-sm font-black text-slate-700"></p>
                        </div>
                        <div class="p-3 bg-amber-50 rounded-xl text-center">
                            <p class="text-[8px] font-bold text-amber-500 uppercase">Injuries</p>
                            <p id="drv_injuries" class="text-sm font-black text-amber-700"></p>
                        </div>
                        <div class="p-3 bg-rose-50 rounded-xl text-center">
                            <p class="text-[8px] font-bold text-rose-500 uppercase">Casualties</p>
                            <p id="drv_casualties" class="text-sm font-black text-rose-700"></p>
                        </div>
                    </div>
                    <div class="space-y-3">
                        <div class="p-4 bg-indigo-50/50 rounded-2xl border border-indigo-100">
                            <p class="text-[9px] font-bold text-indigo-600 uppercase tracking-widest mb-1">Damage
                                Assessment</p>
                            <p id="drv_damage" class="text-xs text-slate-600 font-medium"></p>
                        </div>
                        <div class="p-4 bg-emerald-50/50 rounded-2xl border border-emerald-100">
                            <p class="text-[9px] font-bold text-emerald-600 uppercase tracking-widest mb-1">Response
                                Actions</p>
                            <p id="drv_actions" class="text-xs text-slate-600 font-medium"></p>
                        </div>
                    </div>
                    <!-- Signatories — duty officer is the account that deactivated the disaster -->
                    <div class="grid grid-cols-2 gap-3 mt-3">
                        <div class="flex items-center gap-3 bg-slate-50 border border-slate-100 rounded-xl py-3.5 px-4">
                            <span class="material-symbols-outlined text-slate-400" style="font-size:18px">badge</span>
                            <div class="min-w-0">
                                <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest">Duty Officer
                                </p>
                                <p id="drv_duty_officer" class="text-xs font-black text-slate-700 truncate"></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 bg-slate-50 border border-slate-100 rounded-xl py-3.5 px-4">
                            <span class="material-symbols-outlined text-slate-400"
                                style="font-size:18px">workspace_premium</span>
                            <div class="min-w-0">
                                <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest">Barangay
                                    Captain</p>
                                <p id="drv_captain" class="text-xs font-black text-slate-700 truncate"></p>
                            </div>
                        </div>
                    </div>

                    <p class="text-right text-[10px] font-bold text-slate-400 uppercase mt-3">Date Deactivated: <span
                            id="drv_date"></span></p>
                </div>
            </div>

            <div class="p-6 bg-slate-50/50 border-t border-slate-100 flex-shrink-0 flex flex-wrap gap-3 justify-end">
                <button onclick="closeModal('viewDisasterReportModal')"
                    class="px-6 py-3 bg-white border border-slate-200 text-slate-600 rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-slate-100 transition-all">Close</button>
                <button onclick="downloadCurrentDisasterReportPDF()"
                    class="flex items-center gap-2 px-6 py-3 bg-indigo-50 text-indigo-600 rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-indigo-100 transition-all">
                    <span class="material-symbols-outlined" style="font-size:16px">picture_as_pdf</span> Download PDF
                </button>
                <button onclick="printCurrentDisasterReport()"
                    class="flex items-center gap-2 px-6 py-3 bg-slate-900 text-white rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-black transition-all">
                    <span class="material-symbols-outlined" style="font-size:16px">print</span> Print
                </button>
            </div>
        </div>
    </div>

    <script src="assets/analytics_report.js"></script>
    <script>
        const TYPE_COLORS = { Flood: '#3b82f6', Fire: '#ef4444', Earthquake: '#f97316', Typhoon: '#22c55e' };
        const EXTRA_COLORS = ['#6366f1', '#8b5cf6', '#14b8a6', '#eab308', '#ec4899', '#64748b'];
        const SEV_COLORS = { Low: '#10b981', Medium: '#f59e0b', High: '#f97316', Critical: '#e11d48', Extreme: '#9f1239' };
        const disasterIconMap = { Typhoon: 'cyclone', Flood: 'waves', Fire: 'local_fire_department', Earthquake: 'landslide' };
        const charts = {};
        let DATA = null;   // analytics for the applied range
        let AI = null;     // AI result for the SAME applied range (null until generated)
        const esc = AnalyticsReport.esc, num = AnalyticsReport.num;

        function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
        function showToast(type, msg) { alert(msg); }

        function typeColor(t, i) { return TYPE_COLORS[t] || EXTRA_COLORS[i % EXTRA_COLORS.length]; }
        function fmtDate(d, withTime) {
            if (!d) return '—';
            const dt = new Date(String(d).replace(' ', 'T'));
            if (isNaN(dt)) return d;
            const s = dt.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
            return withTime ? s + ' · ' + dt.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) : s;
        }
        function isoDate(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }

        // ── Filters ───────────────────────────────────────────────────────────
        function presetRange(kind) {
            const today = new Date();
            let s = new Date(today);
            if (kind === 'month') s = new Date(today.getFullYear(), today.getMonth(), 1);
            else if (kind === '30') s.setDate(today.getDate() - 29);
            else if (kind === 'quarter') s.setMonth(today.getMonth() - 3);
            else if (kind === 'year') s = new Date(today.getFullYear(), 0, 1);
            document.getElementById('startDate').value = isoDate(s);
            document.getElementById('endDate').value = isoDate(today);
            applyRange();
        }

        function applyRange() {
            const s = document.getElementById('startDate').value;
            const e = document.getElementById('endDate').value;
            const err = document.getElementById('rangeError');
            if (!s || !e) { err.textContent = 'Please choose both a Start Date and an End Date.'; err.classList.remove('hidden'); return; }
            if (s > e) { err.textContent = 'Start Date must be on or before End Date.'; err.classList.remove('hidden'); return; }
            err.classList.add('hidden');
            loadData();
        }

        async function loadData() {
            const params = new URLSearchParams({
                start: document.getElementById('startDate').value,
                end: document.getElementById('endDate').value,
                category: document.getElementById('category').value,
            });
            document.getElementById('loadingDot').classList.remove('hidden');
            try {
                const res = await fetch('disaster_analytics.php?action=data&' + params.toString());
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed to load analytics');
                DATA = data;
                history.replaceState(null, '', 'disaster_analytics.php?' + params.toString());
                resetAi();
                renderAll();
            } catch (e) {
                const err = document.getElementById('rangeError');
                err.textContent = e.message;
                err.classList.remove('hidden');
            } finally {
                document.getElementById('loadingDot').classList.add('hidden');
            }
        }

        // The AI result belongs to one range — clear it when the range changes
        function resetAi() {
            AI = null;
            document.getElementById('aiMeta').textContent = 'Not generated yet';
            document.getElementById('aiBody').innerHTML = `<p class="panel-desc">Press <strong>Generate Analytics</strong> to have the AI read the figures for
                <strong>${esc(DATA.range.label)}</strong>${DATA.category !== 'All' ? ' (' + esc(DATA.category) + ')' : ''} and produce <strong>Key Findings</strong>,
                <strong>Trends</strong>, <strong>Recommended Actions</strong> and <strong>Other Observations</strong>. It is not generated automatically,
                so no AI tokens are used until you ask for it.</p>`;
        }

        // ── Render ────────────────────────────────────────────────────────────
        function renderAll() {
            const d = DATA, s = d.summary;
            document.getElementById('rangeLabel').textContent = d.range.label + ' · ' + d.range.days + ' day(s)' + (d.category !== 'All' ? ' · ' + d.category : ' · All categories');
            document.querySelectorAll('.granularity').forEach(el => el.textContent = d.trend.granularity);

            const diff = s.total_alerts - s.prev_total;
            const cmp = diff === 0 ? 'Same as previous period' : `${diff > 0 ? '+' : ''}${diff} vs previous period`;
            const kpis = [
                ['Total Alerts', s.total_alerts, 'crisis_alert', 'indigo', cmp],
                ['Still Active', s.active_alerts, 'emergency', 'rose', `${num(s.deactivated_alerts)} deactivated`],
                ['Reports Filed', s.reports, 'description', 'blue', 'Final disaster reports'],
                ['Affected Residents', s.affected, 'groups', 'slate', 'From final reports'],
                ['Evacuees', s.evacuees, 'night_shelter', 'emerald', 'From final reports'],
                ['Injuries', s.injuries, 'personal_injury', 'amber', 'From final reports'],
                ['Casualties', s.casualties, 'heart_broken', 'rose', 'From final reports'],
                ['SMS Delivered', s.sms_sent, 'sms', 'cyan', `${num(s.sms_broadcasts)} broadcasts · ${num(s.sms_recipients)} recipients`],
            ];
            document.getElementById('kpiGrid').innerHTML = kpis.map(([label, val, icon, color, note]) => `
                <div class="kpi bg-white p-5 rounded-[24px] border border-slate-100 shadow-sm">
                    <div class="w-9 h-9 bg-${color}-50 text-${color}-600 rounded-xl flex items-center justify-center mb-3">
                        <span class="material-symbols-outlined" style="font-size:20px">${icon}</span></div>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">${label}</p>
                    <h3 class="text-2xl font-black text-slate-800 mt-1 font-mono">${num(val)}</h3>
                    <p class="text-[10px] font-semibold text-slate-400 mt-1">${esc(note)}</p>
                </div>`).join('');

            const insights = [
                ['Most Frequent', s.most_frequent || '—', 'leaderboard'],
                ['Peak ' + (d.trend.granularity === 'day' ? 'Day' : 'Month'), s.peak_label ? `${s.peak_label} (${s.peak_count})` : '—', 'show_chart'],
                ['High / Critical Alerts', num(s.high_severity), 'priority_high'],
                ['Avg. Alert → Final Report', s.avg_resolve_hours === null ? '—' : (s.avg_resolve_hours >= 48 ? (s.avg_resolve_hours / 24).toFixed(1) + ' days' : s.avg_resolve_hours + ' hrs'), 'timer'],
            ];
            document.getElementById('insightGrid').innerHTML = insights.map(([label, val, icon]) => `
                <div class="panel px-5 py-4 flex items-center gap-3">
                    <span class="material-symbols-outlined text-indigo-400">${icon}</span>
                    <div class="min-w-0"><p class="section-title">${label}</p><p class="text-sm font-black text-slate-800 truncate">${esc(val)}</p></div>
                </div>`).join('');

            renderCharts();
            renderTypeTable();
            renderRecords();
        }

        function makeChart(id, config) {
            if (charts[id]) charts[id].destroy();
            config.options = Object.assign({ responsive: true, maintainAspectRatio: false, animation: { duration: 400 } }, config.options || {});
            charts[id] = new Chart(document.getElementById(id), config);
        }

        function renderCharts() {
            const d = DATA;
            const tooltip = { backgroundColor: '#0f172a', padding: 10 };

            makeChart('trendChart', {
                type: 'line',
                data: {
                    labels: d.trend.labels,
                    datasets: d.trend.datasets.map((ds, i) => ({
                        label: ds.label, data: ds.data, borderColor: typeColor(ds.label, i), backgroundColor: typeColor(ds.label, i) + '1f',
                        borderWidth: 2.5, tension: .35, fill: true, pointRadius: d.trend.labels.length > 40 ? 0 : 3,
                    })),
                },
                options: {
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } }, tooltip },
                    scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f1f5f9' } } },
                },
            });

            const types = d.by_type.filter(t => t.alerts > 0);
            makeChart('categoryChart', {
                type: 'doughnut',
                data: {
                    labels: types.length ? types.map(t => t.type) : ['No alerts'],
                    datasets: [{ data: types.length ? types.map(t => t.alerts) : [1], backgroundColor: types.length ? types.map((t, i) => typeColor(t.type, i)) : ['#e2e8f0'], borderWidth: 2, borderColor: '#fff' }],
                },
                options: { cutout: '62%', plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } }, tooltip: types.length ? tooltip : { enabled: false } } },
            });

            const impactTypes = d.by_type;
            makeChart('impactChart', {
                type: 'bar',
                data: {
                    labels: impactTypes.map(t => t.type),
                    datasets: [
                        { label: 'Affected', data: impactTypes.map(t => t.affected), backgroundColor: '#6366f1', borderRadius: 6 },
                        { label: 'Evacuees', data: impactTypes.map(t => t.evacuees), backgroundColor: '#10b981', borderRadius: 6 },
                        { label: 'Injuries', data: impactTypes.map(t => t.injuries), backgroundColor: '#f59e0b', borderRadius: 6 },
                        { label: 'Casualties', data: impactTypes.map(t => t.casualties), backgroundColor: '#e11d48', borderRadius: 6 },
                    ],
                },
                options: {
                    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } }, tooltip },
                    scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f1f5f9' } } },
                },
            });

            const st = Object.entries(d.by_report_status);
            const stColors = { Resolved: '#10b981', Closed: '#3b82f6', Cancelled: '#94a3b8' };
            makeChart('statusChart', {
                type: 'doughnut',
                data: {
                    labels: st.length ? st.map(x => x[0]) : ['No reports'],
                    datasets: [{ data: st.length ? st.map(x => x[1]) : [1], backgroundColor: st.length ? st.map((x, i) => stColors[x[0]] || EXTRA_COLORS[i % 6]) : ['#e2e8f0'], borderWidth: 2, borderColor: '#fff' }],
                },
                options: { cutout: '62%', plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } }, tooltip: st.length ? tooltip : { enabled: false } } },
            });

            const sev = Object.entries(d.by_severity);
            makeChart('severityChart', {
                type: 'bar',
                data: { labels: sev.map(x => x[0]), datasets: [{ label: 'Alerts', data: sev.map(x => x[1]), backgroundColor: sev.map(x => SEV_COLORS[x[0]] || '#94a3b8'), borderRadius: 6 }] },
                options: { plugins: { legend: { display: false }, tooltip }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f1f5f9' } } } },
            });

            const ch = Object.entries(d.channels);
            const chTotal = ch.reduce((a, x) => a + x[1], 0);
            makeChart('channelChart', {
                type: 'doughnut',
                data: {
                    labels: chTotal ? ch.map(x => x[0]) : ['No alerts'],
                    datasets: [{ data: chTotal ? ch.map(x => x[1]) : [1], backgroundColor: chTotal ? ['#6366f1', '#06b6d4', '#10b981', '#cbd5e1'] : ['#e2e8f0'], borderWidth: 2, borderColor: '#fff' }],
                },
                options: { cutout: '60%', plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, font: { size: 10 } } }, tooltip: chTotal ? tooltip : { enabled: false } } },
            });

            const wd = Object.entries(d.weekday);
            makeChart('weekdayChart', {
                type: 'bar',
                data: { labels: wd.map(x => x[0]), datasets: [{ label: 'Alerts', data: wd.map(x => x[1]), backgroundColor: '#818cf8', borderRadius: 6 }] },
                options: { plugins: { legend: { display: false }, tooltip }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f1f5f9' } } } },
            });
        }

        function renderTypeTable() {
            const rows = DATA.by_type;
            const tb = document.getElementById('typeTableBody');
            if (!rows.length) {
                tb.innerHTML = '<tr><td colspan="7" class="!py-10 text-center text-slate-400 text-xs font-bold uppercase">No disaster records in this date range</td></tr>';
                return;
            }
            const tot = rows.reduce((a, r) => { ['alerts', 'reports', 'affected', 'evacuees', 'injuries', 'casualties'].forEach(k => a[k] = (a[k] || 0) + r[k]); return a; }, {});
            tb.innerHTML = rows.map((r, i) => `<tr>
                <td><span class="inline-flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full" style="background:${typeColor(r.type, i)}"></span>${esc(r.type)}</span></td>
                <td class="text-right">${num(r.alerts)}</td><td class="text-right">${num(r.reports)}</td><td class="text-right">${num(r.affected)}</td>
                <td class="text-right">${num(r.evacuees)}</td><td class="text-right">${num(r.injuries)}</td><td class="text-right">${num(r.casualties)}</td></tr>`).join('')
                + `<tr class="bg-slate-50"><td class="!font-black">Total</td><td class="text-right !font-black">${num(tot.alerts)}</td><td class="text-right !font-black">${num(tot.reports)}</td>
                <td class="text-right !font-black">${num(tot.affected)}</td><td class="text-right !font-black">${num(tot.evacuees)}</td><td class="text-right !font-black">${num(tot.injuries)}</td><td class="text-right !font-black">${num(tot.casualties)}</td></tr>`;
        }

        function filteredRecords() {
            const q = (document.getElementById('recordSearch').value || '').toLowerCase().trim();
            return (DATA ? DATA.reports : []).filter(r => !q || [r.Title, r.Type, r.ReportNo].some(v => String(v || '').toLowerCase().includes(q)));
        }

        function renderRecords() {
            if (!DATA) return;
            const list = filteredRecords();
            const tb = document.getElementById('recordsBody');
            document.getElementById('recordsCount').textContent = `Showing ${list.length} of ${DATA.reports.length} report(s)`;
            if (!list.length) {
                tb.innerHTML = `<tr><td colspan="10" class="!py-14 text-center"><span class="material-symbols-outlined !text-4xl text-slate-200">folder_off</span>
                    <p class="text-[10px] font-bold text-slate-400 uppercase mt-2 tracking-widest">No reports found</p></td></tr>`;
                return;
            }
            tb.innerHTML = list.map(r => {
                const sev = r.AlertSeverity || r.Severity || 'Medium';
                const idx = DATA.reports.indexOf(r);
                return `<tr class="border-l-4 ${drAccentClass(sev)}">
                    <td><div class="flex items-center gap-3">
                        <span class="w-8 h-8 rounded-xl bg-slate-100 text-slate-500 flex items-center justify-center flex-shrink-0"><span class="material-symbols-outlined" style="font-size:16px">${disasterIconMap[r.Type] || 'emergency'}</span></span>
                        <div class="min-w-0"><p class="text-xs font-bold text-slate-700 truncate max-w-[220px]">${esc(r.Title || 'Untitled Report')}</p>
                        <p class="text-[10px] font-medium text-slate-400 uppercase">${esc(r.Type || 'Unknown')} · ${esc(r.ReportNo)}</p></div></div></td>
                    <td><span class="pill ${drSeverityClass(sev)}">${esc(sev)}</span></td>
                    <td class="whitespace-nowrap">${fmtDate(r.AlertCreatedAt)}</td>
                    <td class="whitespace-nowrap">${fmtDate(r.CreatedAt, true)}</td>
                    <td class="text-right">${num(r.AffectedResidents || 0)}</td><td class="text-right">${num(r.Evacuees || 0)}</td>
                    <td class="text-right">${num(r.Injuries || 0)}</td><td class="text-right">${num(r.Casualties || 0)}</td>
                    <td><span class="pill ${drStatusClass(r.Status || 'Resolved')}">${esc(r.Status || 'Resolved')}</span></td>
                    <td class="text-right"><button type="button" onclick="openDisasterReportModal(DATA.reports[${idx}])"
                        class="inline-flex items-center gap-1 bg-indigo-50 text-indigo-600 hover:bg-indigo-100 rounded-lg px-3 py-1.5 text-[10px] font-black uppercase tracking-widest">
                        <span class="material-symbols-outlined" style="font-size:14px">visibility</span> View</button></td>
                </tr>`;
            }).join('');
        }

        // ── AI (only when the button is pressed) ──────────────────────────────
        async function generateAnalytics() {
            if (!DATA) return null;
            const res = await AnalyticsReport.generateAi({
                url: '../backend/disaster_ai_summary.php',
                params: { start_date: DATA.range.start, end_date: DATA.range.end, category: DATA.category },
                button: document.getElementById('aiBtn'),
                panel: document.getElementById('aiPanel'),
                body: document.getElementById('aiBody'),
                meta: document.getElementById('aiMeta'),
                loadingText: `Analysing the disaster records from ${DATA.range.label}…`,
            });
            AI = res;
            return res;
        }

        // ── Print / Save as PDF (same layout) ─────────────────────────────────
        async function exportReport(kind) {
            if (!DATA) return;
            const include = await AnalyticsReport.askIncludeAi(kind, !!AI);
            if (include === null) return;
            if (include && !AI) {
                document.getElementById('aiPanel').scrollIntoView({ behavior: 'smooth', block: 'center' });
                const res = await generateAnalytics();
                if (!res) { alert('The AI analytics could not be generated, so the report was not created. You can choose "No" to export without it.'); return; }
            }
            const html = buildExportDocument(include ? AI : null);
            const fname = `Disaster-Analytics_${DATA.range.start}_to_${DATA.range.end}`;
            if (kind === 'print') AnalyticsReport.print(html, fname);
            else await AnalyticsReport.pdf(html, fname + '.pdf');
        }

        function buildExportDocument(ai) {
            const d = DATA, s = d.summary, R = AnalyticsReport;
            const diff = s.total_alerts - s.prev_total;
            const statItems = [
                { label: 'Total Alerts', value: num(s.total_alerts), note: diff === 0 ? 'same as prev. period' : `${diff > 0 ? '+' : ''}${diff} vs prev. period` },
                { label: 'Still Active', value: num(s.active_alerts) },
                { label: 'Reports Filed', value: num(s.reports) },
                { label: 'Affected Residents', value: num(s.affected) },
                { label: 'Evacuees', value: num(s.evacuees) },
                { label: 'Injuries', value: num(s.injuries), color: '#d97706' },
                { label: 'Casualties', value: num(s.casualties), color: '#e11d48' },
                { label: 'SMS Delivered', value: num(s.sms_sent), note: `${num(s.sms_recipients)} recipients` },
            ];
            const insights = R.table(['Most Frequent Category', 'Peak ' + (d.trend.granularity === 'day' ? 'Day' : 'Month'), 'High / Critical Alerts', 'Avg. Alert → Final Report'], [[
                s.most_frequent || '—', s.peak_label ? `${s.peak_label} (${s.peak_count})` : '—', num(s.high_severity),
                s.avg_resolve_hours === null ? '—' : s.avg_resolve_hours + ' hours',
            ]]);

            const typeRows = d.by_type.map(t => [t.type, num(t.alerts), num(t.reports), num(t.affected), num(t.evacuees), num(t.injuries), num(t.casualties)]);
            const recRows = d.reports.map(r => [
                r.ReportNo, { html: `<strong>${esc(r.Title || 'Untitled')}</strong><br><span style="color:#94a3b8">${esc(r.Type || '')}</span>` },
                r.AlertSeverity || r.Severity || '—', fmtDate(r.AlertCreatedAt), fmtDate(r.CreatedAt),
                num(r.AffectedResidents || 0), num(r.Evacuees || 0), num(r.Injuries || 0), num(r.Casualties || 0), r.Status || 'Resolved',
            ]);

            return R.buildDocument({
                accent: '#4f46e5',
                office: 'Disaster Risk Reduction & Management Committee',
                docTitle: 'Disaster Analytics Report',
                subtitle: 'Summary of disaster alerts, impact and response for the reporting period.',
                rangeLabel: d.range.label,
                filterLabel: d.category === 'All' ? 'All categories' : 'Category: ' + d.category,
                preparedTitle: 'Duty Officer / Barangay Staff',
                ai,
                sections: [
                    { title: 'Summary', desc: 'Overall totals for the reporting period. Alerts are counted by the date they were issued; impact figures come from the final reports filed when each disaster was deactivated.', html: R.statGrid(statItems, 4) },
                    { title: 'Key Indicators', desc: 'Quick indicators: the most common category, the busiest period, how many alerts were High/Critical, and the average time from issuing an alert to filing its final report.', html: insights },
                    { title: 'Disaster Records by Category', desc: `Number of disaster alerts issued per ${d.trend.granularity}, split by category. Rising lines show when a hazard became more frequent.`, html: R.chartImage(charts.trendChart, 250) },
                    { title: 'Category Breakdown', desc: 'Share of alerts per category (left) and the alerts, reports and impact recorded for each category (right/below).', html: R.twoCol(R.chartImage(charts.categoryChart, 210), R.chartImage(charts.statusChart, 210)) + '<p class="rx-desc" style="margin-top:6px">Left: share of alerts per category. Right: status of the final reports (Resolved / Closed / Cancelled).</p>' + R.table(['Category', 'Alerts', 'Reports', 'Affected', 'Evacuees', 'Injuries', 'Casualties'], typeRows, { align: ['left', 'right', 'right', 'right', 'right', 'right', 'right'] }) },
                    { title: 'Impact by Category', desc: 'Affected residents, evacuees, injuries and casualties recorded in the final disaster reports, grouped by category.', html: R.chartImage(charts.impactChart, 230) },
                    { title: 'Severity Levels & Notification Channels', desc: 'Left: alerts grouped by their severity level. Right: how residents were notified — in-app, SMS, both, or none.', html: R.twoCol(R.chartImage(charts.severityChart, 190), R.chartImage(charts.channelChart, 190)) },
                    { title: 'Alerts by Day of Week', desc: 'Which days of the week alerts were issued most often during the period.', html: R.chartImage(charts.weekdayChart, 180) },
                    { title: 'Disaster Reports', keep: false, desc: `Complete list of the ${d.reports.length} disaster report(s) in the reporting period with their severity, dates, impact and status.`, html: R.table(['Ref No.', 'Disaster', 'Severity', 'Issued', 'Deactivated', 'Affected', 'Evac.', 'Inj.', 'Cas.', 'Status'], recRows, { align: ['left', 'left', 'left', 'left', 'left', 'right', 'right', 'right', 'right', 'left'], empty: 'No disaster reports in this date range.' }) },
                ],
            });
        }

        // ── Single disaster report: View / Print / Download PDF (moved from ann.php) ──
        let currentDisasterReport = null;
        const drTypeIcons = { Typhoon: 'cyclone', Flood: 'waves', Fire: 'local_fire_department', Earthquake: 'landslide' };

        function drSeverityClass(sev) {
            if (['Critical', 'Extreme'].includes(sev)) return 'bg-rose-100 text-rose-600';
            if (sev === 'High') return 'bg-orange-100 text-orange-600';
            if (sev === 'Medium') return 'bg-amber-100 text-amber-600';
            if (sev === 'Low') return 'bg-emerald-100 text-emerald-600';
            return 'bg-slate-100 text-slate-600';
        }
        function drAccentClass(sev) {
            if (['Critical', 'Extreme'].includes(sev)) return 'border-l-rose-400';
            if (sev === 'High') return 'border-l-orange-400';
            if (sev === 'Medium') return 'border-l-amber-400';
            return 'border-l-emerald-400';
        }
        function drStatusClass(status) {
            if (status === 'Resolved') return 'bg-emerald-100 text-emerald-600';
            if (status === 'Closed') return 'bg-blue-100 text-blue-600';
            if (status === 'Cancelled') return 'bg-slate-200 text-slate-500';
            return 'bg-slate-100 text-slate-500';
        }

        function escapeHtml(str) {
            return String(str ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }

        // ── View Disaster Report modal ──────────────────────────────────────────────
        function openDisasterReportModal(report) {
            currentDisasterReport = report;

            const type = report.Type || 'Unknown';
            const severity = report.AlertSeverity || report.Severity || 'Medium';
            const status = report.Status || 'Resolved';
            const icon = disasterIconMap[type] || 'emergency';

            document.getElementById('drv_title').innerText = report.Title || 'Untitled Report';
            document.getElementById('drv_id').innerText = drReportNo(report);
            document.getElementById('drv_type').innerHTML = `<span class="material-symbols-outlined !text-sm">${icon}</span> ${escapeHtml(type)}`;
            document.getElementById('drv_message').innerText = report.AlertMessage || 'No message on record for this disaster.';

            const sevBadge = document.getElementById('drv_severity_badge');
            sevBadge.innerText = severity;
            sevBadge.className = 'inline-block px-3 py-1 rounded-full text-[8px] font-black text-white '
                + (['Critical', 'Extreme'].includes(severity) ? 'bg-rose-500' : severity === 'High' ? 'bg-orange-500' : severity === 'Medium' ? 'bg-amber-500' : 'bg-emerald-500');

            const statusBadge = document.getElementById('drv_status_badge');
            statusBadge.innerText = status;
            statusBadge.className = 'inline-block px-3 py-1 rounded-full text-[8px] font-black uppercase ' + drStatusClass(status);

            const notifyApp = !!(report.AlertNotifyApp && report.AlertNotifyApp != 0);
            const notifySms = !!(report.AlertNotifySms && report.AlertNotifySms != 0);
            document.getElementById('drv_notify_app').innerText = notifyApp ? 'Sent' : 'Not used';
            document.getElementById('drv_notify_sms').innerText = notifySms ? 'Enabled' : 'Not used';

            // SMS Live — actual send status per broadcast for this disaster
            const smsLogs = report.SmsLogs || [];
            const smsContainer = document.getElementById('drv_sms_live');
            if (!smsLogs.length) {
                smsContainer.innerHTML = '<p class="text-[11px] font-bold text-slate-400 uppercase text-center py-3">No SMS broadcast recorded for this disaster.</p>';
            } else {
                smsContainer.innerHTML = smsLogs.map(l => {
                    const total = parseInt(l.total_recipients ?? 0, 10);
                    const sent = parseInt(l.sent_count ?? 0, 10);
                    const pct = total > 0 ? Math.round((sent / total) * 100) : 0;
                    const when = l.created_at ? new Date(l.created_at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';
                    return `<div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                <div class="flex justify-between items-center mb-1.5">
                    <span class="text-[10px] font-bold text-slate-500">${when}</span>
                    <span class="text-[9px] font-black uppercase ${sent >= total && total > 0 ? 'text-emerald-500' : 'text-amber-500'}">${escapeHtml(l.status || 'Unknown')}</span>
                </div>
                <div class="w-full bg-slate-200 h-1.5 rounded-full overflow-hidden mb-1">
                    <div class="h-full bg-emerald-500" style="width:${pct}%"></div>
                </div>
                <p class="text-[9px] font-bold text-slate-400">${pct}% sent (${sent}/${total})</p>
            </div>`;
                }).join('');
            }

            document.getElementById('drv_affected').innerText = report.AffectedResidents ?? '0';
            document.getElementById('drv_evacuees').innerText = report.Evacuees ?? '0';
            document.getElementById('drv_injuries').innerText = report.Injuries ?? '0';
            document.getElementById('drv_casualties').innerText = report.Casualties ?? '0';
            document.getElementById('drv_damage').innerText = report.PropertyDamage || 'No damage assessment on record.';
            document.getElementById('drv_actions').innerText = report.ResponseActions || 'No response actions on record.';
            document.getElementById('drv_date').innerText = report.CreatedAt ? new Date(report.CreatedAt).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) : '—';
            document.getElementById('drv_duty_officer').innerText = drDutyOfficer(report);
            document.getElementById('drv_captain').innerText = BRGY.captain || 'Barangay Captain';

            openModal('viewDisasterReportModal');
        }

        // ─── Shared report layout ────────────────────────────────────────────────────
        // Print and Download PDF both render THIS markup, so the printed page and the
        // saved PDF are always identical. Barangay name, logo and captain come from the
        // database (BRGY); the duty officer is whoever deactivated the disaster.

        function drReportNo(r) {
            if (!r) return '—';
            if (r.ReportNo) return r.ReportNo;
            const year = r.CreatedAt ? new Date(r.CreatedAt).getFullYear() : new Date().getFullYear();
            return 'DIS-' + year + '-' + String(r.ReportID ?? 0).padStart(4, '0');
        }

        function drDutyOfficer(r) {
            return (r && (r.DutyOfficer || r.duty_officer)) || 'Not recorded';
        }

        function drSeverityHex(sev) {
            if (['Critical', 'Extreme'].includes(sev)) return '#e11d48';
            if (sev === 'High') return '#f97316';
            if (sev === 'Medium') return '#f59e0b';
            return '#10b981';
        }

        function buildDisasterReportHTML(r) {
            const type = r.Type || 'Unknown';
            const severity = r.AlertSeverity || r.Severity || 'Medium';
            const status = r.Status || 'Resolved';
            const accent = drSeverityHex(severity);
            const refNo = drReportNo(r);
            const dateStr = r.CreatedAt
                ? new Date(r.CreatedAt).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })
                : '—';
            const generated = new Date().toLocaleString('en-US', {
                month: 'long', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit'
            });
            const brgyName = (BRGY.name || 'Barangay').toUpperCase();
            const captain = BRGY.captain || 'Barangay Captain';
            const officer = drDutyOfficer(r);
            const logoHtml = BRGY.logo
                ? `<img src="${BRGY.logo}" alt="" class="dr-logo">`
                : `<div class="dr-logo dr-logo-ph">${escapeHtml((BRGY.name || 'B').charAt(0).toUpperCase())}</div>`;

            const stat = (label, value, color) => `
        <div class="dr-stat">
            <div class="dr-stat-val" style="color:${color}">${value ?? 0}</div>
            <div class="dr-stat-label">${label}</div>
        </div>`;

            const section = (label, body) => `
        <div class="dr-section">
            <div class="dr-section-label"><span class="dr-bullet"></span>${label}</div>
            <div class="dr-section-body">${escapeHtml(body)}</div>
        </div>`;

            return `
    <div class="dr-page" style="--dr-accent:${accent}">
        <div class="dr-accentbar"></div>

        <div class="dr-letterhead">
            ${logoHtml}
            <div class="dr-lh-text">
                <p class="dr-lh-sup">Republic of the Philippines</p>
                ${BRGY.address ? `<p class="dr-lh-sup">${escapeHtml(BRGY.address)}</p>` : ''}
                <h1 class="dr-lh-name">${escapeHtml(brgyName)}</h1>
                <p class="dr-lh-sub">Disaster Risk Reduction &amp; Management Committee</p>
            </div>
        </div>

        <div class="dr-titleband">
            <div>
                <p class="dr-doctype">Official Disaster Report</p>
                <p class="dr-docsub">${escapeHtml(r.Title || 'Untitled Report')}</p>
            </div>
            <div class="dr-refbox">
                <span class="dr-reflabel">Reference No.</span>
                <span class="dr-refno">${escapeHtml(refNo)}</span>
            </div>
        </div>

        <div class="dr-meta">
            <div class="dr-meta-cell"><span class="dr-label">Disaster Type</span><span class="dr-value">${escapeHtml(type)}</span></div>
            <div class="dr-meta-cell"><span class="dr-label">Severity Level</span><span class="dr-value"><span class="dr-chip">${escapeHtml(severity)}</span></span></div>
            <div class="dr-meta-cell"><span class="dr-label">Report Status</span><span class="dr-value">${escapeHtml(status)}</span></div>
            <div class="dr-meta-cell"><span class="dr-label">Date of Report</span><span class="dr-value">${escapeHtml(dateStr)}</span></div>
        </div>

        ${section('Message / Emergency Instructions', r.AlertMessage || 'No message on record for this disaster.')}

        <div class="dr-section">
            <div class="dr-section-label"><span class="dr-bullet"></span>Impact Summary</div>
            <div class="dr-stats">
                ${stat('Affected Residents', r.AffectedResidents ?? 0, '#1e293b')}
                ${stat('Evacuees', r.Evacuees ?? 0, '#1e293b')}
                ${stat('Injuries', r.Injuries ?? 0, '#d97706')}
                ${stat('Casualties', r.Casualties ?? 0, '#e11d48')}
            </div>
        </div>

        ${section('Property & Damage Assessment', r.PropertyDamage || 'No significant property damage reported.')}
        ${section('Action Taken & Response', r.ResponseActions || 'Initial response protocols deployed by Barangay DRRMC.')}
        ${section('Notification Channels',
                'App Notification: ' + (r.AlertNotifyApp && r.AlertNotifyApp != 0 ? 'Sent' : 'Not used') +
                '   ·   SMS Broadcast: ' + (r.AlertNotifySms && r.AlertNotifySms != 0 ? 'Enabled' : 'Not used'))}

        <div class="dr-certify">
            This is to certify that the information contained in this report is true and correct
            based on the records of ${escapeHtml(BRGY.name || 'this barangay')}.
        </div>

        <div class="dr-signatures">
            <div class="dr-sign">
                <p class="dr-sign-role">Prepared &amp; Submitted by</p>
                <div class="dr-sign-line"></div>
                <p class="dr-sign-name">${escapeHtml(officer)}</p>
                <p class="dr-sign-title">Duty Officer</p>
            </div>
            <div class="dr-sign">
                <p class="dr-sign-role">Attested by</p>
                <div class="dr-sign-line"></div>
                <p class="dr-sign-name">${escapeHtml(captain)}</p>
                <p class="dr-sign-title">Barangay Captain</p>
            </div>
        </div>

        <div class="dr-footer">
            <span>${escapeHtml(refNo)} · ${escapeHtml(BRGY.name || 'Barangay')}</span>
            <span>Generated ${escapeHtml(generated)}</span>
        </div>
    </div>`;
        }

        function disasterReportStyles() {
            return `
    * { box-sizing: border-box; }
    body { margin: 0; background: #ffffff; }
    .dr-page {
        font-family: 'Plus Jakarta Sans', 'Segoe UI', sans-serif;
        width: 794px; margin: 0 auto; background: #fff;
        padding: 46px 56px 40px; color: #334155; position: relative;
    }
    .dr-accentbar { height: 6px; border-radius: 6px; background: var(--dr-accent); margin-bottom: 26px; }

    .dr-letterhead { display: flex; align-items: center; gap: 20px; padding-bottom: 18px; border-bottom: 2px solid #0f172a; }
    .dr-logo { width: 78px; height: 78px; object-fit: contain; border-radius: 50%; flex: 0 0 78px; }
    .dr-logo-ph {
        display: flex; align-items: center; justify-content: center;
        background: #0f172a; color: #fff; font-size: 30px; font-weight: 900;
    }
    .dr-lh-text { flex: 1; text-align: center; }
    .dr-lh-sup { margin: 0; font-size: 11px; font-weight: 600; color: #64748b; letter-spacing: .04em; }
    .dr-lh-name { margin: 3px 0 2px; font-size: 25px; font-weight: 900; color: #0f172a; letter-spacing: .04em; line-height: 1.15; }
    .dr-lh-sub { margin: 0; font-size: 10px; font-weight: 700; color: var(--dr-accent); text-transform: uppercase; letter-spacing: .18em; }

    .dr-titleband { display: flex; justify-content: space-between; align-items: flex-end; gap: 20px; margin: 22px 0 18px; }
    .dr-doctype { margin: 0; font-size: 17px; font-weight: 900; color: #0f172a; text-transform: uppercase; letter-spacing: .12em; }
    .dr-docsub { margin: 5px 0 0; font-size: 13px; font-weight: 700; color: #475569; }
    .dr-refbox {
        text-align: right; border: 1.5px solid #e2e8f0; border-radius: 12px;
        padding: 9px 14px; background: #f8fafc; white-space: nowrap;
    }
    .dr-reflabel { display: block; font-size: 8px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: .18em; }
    .dr-refno { display: block; font-size: 14px; font-weight: 900; color: #0f172a; letter-spacing: .06em; margin-top: 2px; }

    .dr-meta {
        display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 10px; margin-bottom: 24px;
    }
    .dr-meta-cell { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 11px 13px; }
    .dr-label { display: block; font-size: 8px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: .16em; margin-bottom: 5px; }
    .dr-value { display: block; font-size: 12px; font-weight: 800; color: #1e293b; }
    .dr-chip {
        display: inline-block;
        color: #0f172a;
        font-size: 12px; font-weight: 900; line-height: 1.3;
        text-transform: uppercase; letter-spacing: .06em;
    }

    .dr-section { margin-bottom: 20px; }
    .dr-section-label {
        display: flex; align-items: center; gap: 8px; font-size: 10px; font-weight: 900;
        color: #0f172a; text-transform: uppercase; letter-spacing: .16em; margin-bottom: 9px;
    }
    .dr-bullet { width: 14px; height: 3px; border-radius: 3px; background: var(--dr-accent); display: inline-block; }
    .dr-section-body {
        font-size: 12px; line-height: 1.75; color: #475569; font-weight: 500;
        background: #f8fafc; border-left: 3px solid #e2e8f0; border-radius: 0 12px 12px 0;
        padding: 12px 16px; white-space: pre-wrap;
    }

    .dr-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
    .dr-stat { border: 1px solid #e2e8f0; border-radius: 14px; padding: 14px 10px; text-align: center; background: #fff; }
    .dr-stat-val { font-size: 24px; font-weight: 900; line-height: 1; }
    .dr-stat-label { font-size: 8px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: .12em; margin-top: 6px; }

    .dr-certify {
        font-size: 11px; font-style: italic; color: #64748b; line-height: 1.7;
        border-top: 1px dashed #cbd5e1; padding-top: 14px; margin-top: 26px;
    }

    .dr-signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 50px; margin-top: 34px; }
    .dr-sign { text-align: center; }
    .dr-sign-role { margin: 0 0 42px; font-size: 9px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: .14em; }
    .dr-sign-line { border-top: 1.5px solid #0f172a; margin: 0 8px; }
    .dr-sign-name { margin: 7px 0 2px; font-size: 12px; font-weight: 900; color: #0f172a; text-transform: uppercase; letter-spacing: .04em; }
    .dr-sign-title { margin: 0; font-size: 9px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .12em; }

    .dr-footer {
        display: flex; justify-content: space-between; margin-top: 34px; padding-top: 12px;
        border-top: 1px solid #e2e8f0; font-size: 8.5px; font-weight: 700;
        color: #94a3b8; text-transform: uppercase; letter-spacing: .1em;
    }

    @page { size: A4; margin: 0; }
    @media print {
        .dr-page { width: 100%; padding: 34px 44px 30px; }
        .dr-section, .dr-signatures, .dr-stats { page-break-inside: avoid; }
    }`;
        }

        // Print — opens the browser's print dialog with the shared layout
        function printCurrentDisasterReport() {
            if (!currentDisasterReport) return;
            const r = currentDisasterReport;

            const printWindow = window.open('', '', 'height=900,width=1000');
            printWindow.document.write(`
        <html>
        <head>
            <meta charset="utf-8">
            <title>${escapeHtml(drReportNo(r))} — Disaster Report</title>
            <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
            <style>${disasterReportStyles()}</style>
        </head>
        <body>${buildDisasterReportHTML(r)}</body>
        </html>
    `);
            printWindow.document.close();
            printWindow.focus();
            // Give the webfont and logo a moment so the printed page matches the PDF
            setTimeout(() => { printWindow.print(); }, 600);
        }

        // Download PDF — rasterises the SAME layout, so the file matches the printout
        async function downloadCurrentDisasterReportPDF() {
            if (!currentDisasterReport) return;
            if (typeof window.jspdf === 'undefined' || typeof window.html2canvas === 'undefined') {
                showToast ? showToast('error', 'PDF library failed to load. Check your connection and try again.')
                    : alert('PDF library failed to load.');
                return;
            }

            const r = currentDisasterReport;
            const { jsPDF } = window.jspdf;

            // Offscreen render of the exact same markup used by Print
            const holder = document.createElement('div');
            holder.style.cssText = 'position:fixed;left:-10000px;top:0;width:794px;background:#fff;z-index:-1;';
            const styleTag = document.createElement('style');
            styleTag.textContent = disasterReportStyles();
            holder.appendChild(styleTag);
            holder.insertAdjacentHTML('beforeend', buildDisasterReportHTML(r));
            document.body.appendChild(holder);

            try {
                if (document.fonts && document.fonts.ready) { try { await document.fonts.ready; } catch (e) { } }

                const canvas = await html2canvas(holder.querySelector('.dr-page'), {
                    scale: 2, backgroundColor: '#ffffff', useCORS: true, logging: false,
                });

                const pdf = new jsPDF({ unit: 'pt', format: 'a4' });
                const pageW = pdf.internal.pageSize.getWidth();
                const pageH = pdf.internal.pageSize.getHeight();
                const imgH = (canvas.height * pageW) / canvas.width;
                const pxPerPage = (pageH * canvas.width) / pageW; // canvas pixels that fit on one page

                if (imgH <= pageH) {
                    pdf.addImage(canvas.toDataURL('image/jpeg', 0.95), 'JPEG', 0, 0, pageW, imgH, undefined, 'FAST');
                } else {
                    let offset = 0, page = 0;
                    while (offset < canvas.height) {
                        const sliceH = Math.min(pxPerPage, canvas.height - offset);
                        const slice = document.createElement('canvas');
                        slice.width = canvas.width;
                        slice.height = sliceH;
                        const sctx = slice.getContext('2d');
                        sctx.fillStyle = '#ffffff';
                        sctx.fillRect(0, 0, slice.width, slice.height);
                        sctx.drawImage(canvas, 0, offset, canvas.width, sliceH, 0, 0, canvas.width, sliceH);

                        if (page > 0) pdf.addPage();
                        pdf.addImage(slice.toDataURL('image/jpeg', 0.95), 'JPEG', 0, 0, pageW,
                            (sliceH * pageW) / canvas.width, undefined, 'FAST');
                        offset += sliceH;
                        page++;
                    }
                }

                pdf.save(`${drReportNo(r)}.pdf`);
            } catch (err) {
                console.error('Disaster report PDF error:', err);
                showToast ? showToast('error', 'Failed to generate the PDF. Please try again.')
                    : alert('Failed to generate the PDF.');
            } finally {
                holder.remove();
            }
        }

        window.addEventListener('click', e => { if (e.target === document.getElementById('viewDisasterReportModal')) closeModal('viewDisasterReportModal'); });
        document.addEventListener('DOMContentLoaded', loadData);
    </script>
</body>

</html>

<?php
/**
 * announcement_analytics.php — Announcement / Meta (Facebook) Analytics
 *
 * Opened from the "View Analytics" button beside the All Status filter of the
 * Announcement Archive table in ann.php. This is NOT the Disaster Analytics page.
 *
 *  - Posting statistics (posted, active / ended / scheduled, trends, categories)
 *  - Facebook views / reach / engagement for announcements posted to the Page
 *  - Start Date / End Date / Apply filter, on-demand AI analytics, Print + Save as PDF
 */

require_once __DIR__ . '/partials/analytics_bootstrap.php';
require_once __DIR__ . '/../backend/announcement_analytics_data.php';

$action = $_GET['action'] ?? '';
if ($action === 'data' || $action === 'fb') {
    header('Content-Type: application/json; charset=utf-8');
    [$start, $end] = disaster_analytics_parse_range($_GET['start'] ?? null, $_GET['end'] ?? null);
    try {
        $d = announcement_analytics_collect($pdo, $start, $end);
        if ($action === 'data') {
            echo json_encode(['success' => true] + $d);
        } else {
            echo json_encode(['success' => true] + announcement_fb_metrics($d['announcements'], !empty($_GET['refresh'])));
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Could not load the announcement analytics: ' . $e->getMessage()]);
    }
    exit;
}

[$init_start, $init_end] = disaster_analytics_parse_range($_GET['start'] ?? null, $_GET['end'] ?? null);
$page_title = 'Announcement Analytics';
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

                <!-- ── Hero + Save as PDF / Print ─────────────────────────────── -->
                <div class="rounded-2xl p-6 md:p-8 text-white relative overflow-hidden"
                    style="background: linear-gradient(135deg, var(--accent-700) 0%, var(--accent-600) 50%, var(--accent-700) 100%);">
                    <div class="absolute -right-12 -top-12 w-64 h-64 opacity-10 rounded-full blur-3xl pointer-events-none" style="background: var(--accent-400);"></div>
                    <div class="relative z-10 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                        <div>
                            <a href="ann.php" class="inline-flex items-center gap-1 text-white/70 hover:text-white text-[11px] font-bold uppercase tracking-widest mb-3">
                                <span class="material-symbols-outlined" style="font-size:16px">arrow_back</span> Announcements
                            </a>
                            <h1 class="text-2xl md:text-3xl font-black tracking-tight leading-none">Announcement Analytics</h1>
                            <p class="text-white/60 text-sm mt-2 font-medium">Posting activity and Facebook (Meta) views, reach and engagement for the selected date range.</p>
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
                    <form class="flex flex-wrap items-end gap-3" onsubmit="event.preventDefault(); applyRange();">
                        <div>
                            <label for="startDate" class="section-title block mb-1.5">Start Date</label>
                            <input type="date" id="startDate" class="field" value="<?php echo htmlspecialchars($init_start); ?>" required>
                        </div>
                        <div>
                            <label for="endDate" class="section-title block mb-1.5">End Date</label>
                            <input type="date" id="endDate" class="field" value="<?php echo htmlspecialchars($init_end); ?>" required>
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

                <!-- ── Posting summary cards ───────────────────────────────────── -->
                <div>
                    <p class="section-title mb-1">Posting Summary</p>
                    <p class="panel-desc mb-3">Announcements counted by their posting date. Status follows the same rules as the Announcement Archive table.</p>
                    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4" id="kpiGrid"></div>
                </div>

                <!-- ── Facebook / Meta engagement ─────────────────────────────── -->
                <div class="panel p-6">
                    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                        <div>
                            <p class="section-title mb-1 flex items-center gap-1.5">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="#1877f2"><path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.312 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z" /></svg>
                                Facebook Views, Reach &amp; Engagement
                            </p>
                            <p class="panel-desc">Read from the Facebook Page for announcements in this range that were posted to Facebook. <strong>Views</strong> = times the post was shown, <strong>Reach</strong> = unique people who saw it, <strong>Engagement</strong> = reactions + comments + shares + clicks.</p>
                        </div>
                        <button type="button" class="btn btn-soft" onclick="loadFb(true)" id="fbRefreshBtn">
                            <span class="material-symbols-outlined" style="font-size:16px">sync</span> Refresh from Facebook
                        </button>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3" id="fbGrid"></div>
                    <p id="fbNote" class="mt-3 text-[11px] font-semibold text-slate-400"></p>
                </div>

                <!-- ── AI Generated Analytics (on demand only) ─────────────────── -->
                <div class="panel overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center"><span class="material-symbols-outlined">insights</span></span>
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
                        <div id="aiBody"><p class="panel-desc">Press <strong>Generate Analytics</strong> for AI key findings, trends, recommended actions and other observations for the selected date range.</p></div>
                        <p class="mt-4 text-[10px] text-slate-400 font-medium">AI-generated from the recorded figures only. Please verify before using it in an official report.</p>
                    </div>
                </div>

                <!-- ── Charts ─────────────────────────────────────────────────── -->
                <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                    <div class="panel p-6 xl:col-span-2">
                        <p class="section-title mb-1">Posting Trend</p>
                        <p class="panel-desc mb-4">Announcements posted per <span class="granularity">period</span>, and how many of them were also posted to the Facebook Page.</p>
                        <div class="chart-box" style="height:300px"><canvas id="trendChart"></canvas></div>
                    </div>
                    <div class="panel p-6">
                        <p class="section-title mb-1">Status Breakdown</p>
                        <p class="panel-desc mb-4">Current status of the announcements posted in the period.</p>
                        <div class="chart-box" style="height:300px"><canvas id="statusChart"></canvas></div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="panel p-6">
                        <p class="section-title mb-1">By Category</p>
                        <p class="panel-desc mb-4">Which kinds of announcements were posted most.</p>
                        <div class="chart-box" style="height:240px"><canvas id="categoryChart"></canvas></div>
                    </div>
                    <div class="panel p-6">
                        <p class="section-title mb-1">Posting Day of Week</p>
                        <p class="panel-desc mb-4">Days of the week announcements were posted.</p>
                        <div class="chart-box" style="height:240px"><canvas id="weekdayChart"></canvas></div>
                    </div>
                    <div class="panel p-6">
                        <p class="section-title mb-1">Facebook Coverage</p>
                        <p class="panel-desc mb-4">Announcements posted to Facebook, queued for Facebook, or board-only.</p>
                        <div class="chart-box" style="height:240px"><canvas id="fbCoverageChart"></canvas></div>
                    </div>
                </div>

                <div class="panel p-6">
                    <p class="section-title mb-1">Top Facebook Posts by Engagement</p>
                    <p class="panel-desc mb-4">The announcements with the most reactions, comments and shares on the Facebook Page in this period.</p>
                    <div class="chart-box" style="height:300px"><canvas id="fbTopChart"></canvas></div>
                    <div class="overflow-x-auto mt-5">
                        <table class="w-full data-table">
                            <thead><tr><th>Announcement</th><th class="!text-right">Views</th><th class="!text-right">Reach</th><th class="!text-right">Reactions</th><th class="!text-right">Comments</th><th class="!text-right">Shares</th><th class="!text-right">Clicks</th><th class="!text-right">Eng. Rate</th><th></th></tr></thead>
                            <tbody id="fbTableBody"></tbody>
                        </table>
                    </div>
                </div>

                <!-- ── Announcement list ──────────────────────────────────────── -->
                <div class="panel overflow-hidden">
                    <div class="px-6 pt-6 pb-4">
                        <p class="section-title mb-1">Announcements in Range</p>
                        <p class="panel-desc">All announcements posted in the selected period (excluding those in Trash).</p>
                    </div>
                    <div class="overflow-x-auto max-h-[520px] overflow-y-auto">
                        <table class="w-full data-table">
                            <thead class="sticky top-0 z-[1]"><tr><th>ID</th><th>Title</th><th>Category</th><th>Posted On</th><th>Status</th><th>Facebook</th><th class="!text-right">Attachments</th></tr></thead>
                            <tbody id="annBody"></tbody>
                        </table>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="assets/analytics_report.js"></script>
    <script>
        const esc = AnalyticsReport.esc, num = AnalyticsReport.num;
        const STATUS_COLORS = { ACTIVE: '#10b981', SCHEDULED: '#6366f1', EXPIRED: '#94a3b8', ENDED: '#f59e0b', DRAFT: '#cbd5e1' };
        const CAT_COLORS = ['#6366f1', '#3b82f6', '#8b5cf6', '#f97316', '#14b8a6', '#ec4899', '#64748b'];
        const charts = {};
        let DATA = null, FB = null, AI = null;

        function fmtDate(d) {
            if (!d) return '—';
            const dt = new Date(String(d).replace(' ', 'T'));
            return isNaN(dt) ? d : dt.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
        }
        function isoDate(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
        function short(t, n = 34) { t = String(t || ''); return t.length > n ? t.slice(0, n - 1) + '…' : t; }

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
            const s = document.getElementById('startDate').value, e = document.getElementById('endDate').value;
            const err = document.getElementById('rangeError');
            if (!s || !e) { err.textContent = 'Please choose both a Start Date and an End Date.'; err.classList.remove('hidden'); return; }
            if (s > e) { err.textContent = 'Start Date must be on or before End Date.'; err.classList.remove('hidden'); return; }
            err.classList.add('hidden');
            loadData();
        }

        function rangeParams() {
            return new URLSearchParams({ start: document.getElementById('startDate').value, end: document.getElementById('endDate').value });
        }

        async function loadData() {
            const params = rangeParams();
            document.getElementById('loadingDot').classList.remove('hidden');
            try {
                const res = await fetch('announcement_analytics.php?action=data&' + params.toString());
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Failed to load analytics');
                DATA = data;
                FB = null;
                history.replaceState(null, '', 'announcement_analytics.php?' + params.toString());
                resetAi();
                renderAll();
                loadFb(false);
            } catch (e) {
                const err = document.getElementById('rangeError');
                err.textContent = e.message;
                err.classList.remove('hidden');
            } finally {
                document.getElementById('loadingDot').classList.add('hidden');
            }
        }

        async function loadFb(refresh) {
            const btn = document.getElementById('fbRefreshBtn');
            btn.disabled = true;
            document.getElementById('fbNote').textContent = 'Reading metrics from Facebook…';
            try {
                const p = rangeParams();
                if (refresh) p.set('refresh', '1');
                const res = await fetch('announcement_analytics.php?action=fb&' + p.toString());
                FB = await res.json();
            } catch (e) {
                FB = { success: false, available: false, error: 'Could not reach the server for Facebook metrics.' };
            } finally {
                btn.disabled = false;
            }
            renderFb();
        }

        function resetAi() {
            AI = null;
            document.getElementById('aiMeta').textContent = 'Not generated yet';
            document.getElementById('aiBody').innerHTML = `<p class="panel-desc">Press <strong>Generate Analytics</strong> to have the AI read the posting and
                Facebook figures for <strong>${esc(DATA.range.label)}</strong> and produce <strong>Key Findings</strong>, <strong>Trends</strong>,
                <strong>Recommended Actions</strong> and <strong>Other Observations</strong>. It is not generated automatically, so no AI tokens are used until you ask for it.</p>`;
        }

        function makeChart(id, config) {
            if (charts[id]) charts[id].destroy();
            config.options = Object.assign({ responsive: true, maintainAspectRatio: false, animation: { duration: 400 } }, config.options || {});
            charts[id] = new Chart(document.getElementById(id), config);
        }
        const tooltip = { backgroundColor: '#0f172a', padding: 10 };
        const legendBottom = { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } };
        const yAxis = { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f1f5f9' } };

        function renderAll() {
            const d = DATA, s = d.summary;
            document.getElementById('rangeLabel').textContent = d.range.label + ' · ' + d.range.days + ' day(s)';
            document.querySelectorAll('.granularity').forEach(el => el.textContent = d.trend.granularity);

            const diff = s.total - s.prev_total;
            const kpis = [
                ['Announcements Posted', s.total, 'campaign', 'indigo', diff === 0 ? 'Same as previous period' : `${diff > 0 ? '+' : ''}${diff} vs previous period`],
                ['Active', s.active, 'rss_feed', 'emerald', 'Currently live'],
                ['Scheduled', s.scheduled, 'schedule_send', 'violet', 'Not yet live'],
                ['Ended / Expired', s.ended + s.expired, 'stop_circle', 'amber', `${num(s.ended)} ended · ${num(s.expired)} expired`],
                ['Posted to Facebook', s.fb_posted, 'share', 'blue', `${num(s.fb_queued)} queued`],
                ['Avg. per Week', s.per_week, 'calendar_month', 'slate', s.top_category ? 'Top: ' + s.top_category : '—'],
            ];
            document.getElementById('kpiGrid').innerHTML = kpis.map(([label, val, icon, color, note]) => `
                <div class="kpi bg-white p-5 rounded-[24px] border border-slate-100 shadow-sm">
                    <div class="w-9 h-9 bg-${color}-50 text-${color}-600 rounded-xl flex items-center justify-center mb-3"><span class="material-symbols-outlined" style="font-size:20px">${icon}</span></div>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">${label}</p>
                    <h3 class="text-2xl font-black text-slate-800 mt-1 font-mono">${num(val)}</h3>
                    <p class="text-[10px] font-semibold text-slate-400 mt-1 truncate">${esc(note)}</p>
                </div>`).join('');

            makeChart('trendChart', {
                type: 'line',
                data: {
                    labels: d.trend.labels,
                    datasets: [
                        { label: d.trend.datasets[0].label, data: d.trend.datasets[0].data, borderColor: '#6366f1', backgroundColor: '#6366f11f', fill: true, tension: .35, borderWidth: 2.5, pointRadius: d.trend.labels.length > 40 ? 0 : 3 },
                        { label: d.trend.datasets[1].label, data: d.trend.datasets[1].data, borderColor: '#1877f2', backgroundColor: 'transparent', borderDash: [5, 4], tension: .35, borderWidth: 2, pointRadius: d.trend.labels.length > 40 ? 0 : 3 },
                    ],
                },
                options: { interaction: { mode: 'index', intersect: false }, plugins: { legend: legendBottom, tooltip }, scales: { x: { grid: { display: false } }, y: yAxis } },
            });

            const st = Object.entries(d.by_status).filter(x => x[1] > 0);
            makeChart('statusChart', {
                type: 'doughnut',
                data: { labels: st.length ? st.map(x => x[0][0] + x[0].slice(1).toLowerCase()) : ['No announcements'], datasets: [{ data: st.length ? st.map(x => x[1]) : [1], backgroundColor: st.length ? st.map(x => STATUS_COLORS[x[0]]) : ['#e2e8f0'], borderWidth: 2, borderColor: '#fff' }] },
                options: { cutout: '62%', plugins: { legend: legendBottom, tooltip: st.length ? tooltip : { enabled: false } } },
            });

            const cat = Object.entries(d.by_category);
            makeChart('categoryChart', {
                type: 'bar',
                data: { labels: cat.map(x => x[0]), datasets: [{ label: 'Announcements', data: cat.map(x => x[1]), backgroundColor: cat.map((x, i) => CAT_COLORS[i % CAT_COLORS.length]), borderRadius: 6 }] },
                options: { indexAxis: 'y', plugins: { legend: { display: false }, tooltip }, scales: { x: yAxis, y: { grid: { display: false } } } },
            });

            const wd = Object.entries(d.weekday);
            makeChart('weekdayChart', {
                type: 'bar',
                data: { labels: wd.map(x => x[0]), datasets: [{ label: 'Posted', data: wd.map(x => x[1]), backgroundColor: '#818cf8', borderRadius: 6 }] },
                options: { plugins: { legend: { display: false }, tooltip }, scales: { x: { grid: { display: false } }, y: yAxis } },
            });

            const fbc = d.facebook, fbTotal = fbc.posted + fbc.queued + fbc.not_posted;
            makeChart('fbCoverageChart', {
                type: 'doughnut',
                data: { labels: fbTotal ? ['Posted to Facebook', 'Queued', 'Board only'] : ['No announcements'], datasets: [{ data: fbTotal ? [fbc.posted, fbc.queued, fbc.not_posted] : [1], backgroundColor: fbTotal ? ['#1877f2', '#f59e0b', '#cbd5e1'] : ['#e2e8f0'], borderWidth: 2, borderColor: '#fff' }] },
                options: { cutout: '60%', plugins: { legend: legendBottom, tooltip: fbTotal ? tooltip : { enabled: false } } },
            });

            const statusCls = { ACTIVE: 'text-emerald-500', SCHEDULED: 'text-indigo-500', EXPIRED: 'text-slate-400', ENDED: 'text-amber-500', DRAFT: 'text-slate-400' };
            document.getElementById('annBody').innerHTML = d.announcements.length ? d.announcements.map(a => `<tr>
                <td class="font-mono text-primary !font-bold whitespace-nowrap">${esc(a.ref)}</td>
                <td class="max-w-xs truncate">${esc(a.title)}</td>
                <td>${esc(a.category)}</td>
                <td class="whitespace-nowrap">${fmtDate(a.date_posted)}</td>
                <td class="${statusCls[a.status] || ''} !text-[10px] !font-black uppercase">${a.status}</td>
                <td>${a.fb_post_id ? '<span class="pill bg-blue-50 text-blue-600">Posted</span>' : '<span class="text-slate-300">—</span>'}</td>
                <td class="text-right">${num(a.attachments)}</td></tr>`).join('')
                : '<tr><td colspan="7" class="!py-12 text-center text-slate-400 text-xs font-bold uppercase">No announcements posted in this date range</td></tr>';

            renderFb();
        }

        function renderFb() {
            const grid = document.getElementById('fbGrid');
            const note = document.getElementById('fbNote');
            const t = (FB && FB.available) ? FB.totals : null;
            const ins = FB && FB.insights_available;
            const cards = [
                ['Views', ins ? t.impressions : null, 'visibility'],
                ['Reach', ins ? t.reach : null, 'group'],
                ['Reactions', t ? t.reactions : null, 'thumb_up'],
                ['Comments', t ? t.comments : null, 'chat_bubble'],
                ['Shares', t ? t.shares : null, 'share'],
                ['Engagement Rate', (t && t.rate !== null) ? t.rate + '%' : null, 'percent'],
            ];
            grid.innerHTML = cards.map(([label, val, icon]) => `
                <div class="bg-slate-50 rounded-2xl border border-slate-100 p-4">
                    <p class="flex items-center gap-1.5 text-[10px] font-bold text-slate-400 uppercase tracking-widest"><span class="material-symbols-outlined text-blue-500" style="font-size:15px">${icon}</span>${label}</p>
                    <p class="text-xl font-black text-slate-800 mt-1.5 font-mono">${val === null || val === undefined ? '—' : (typeof val === 'number' ? num(val) : esc(val))}</p>
                </div>`).join('');

            if (!FB) { note.textContent = 'Reading metrics from Facebook…'; }
            else {
                const parts = [];
                if (FB.available) parts.push(`Based on ${FB.totals.posts_read} Facebook post(s) · updated ${FB.fetched_at}${FB.limited ? ' · only the 40 most recent posts are read' : ''}.`);
                if (FB.available && !ins) parts.push('Views and reach need the Page "read_insights" permission, so only reactions, comments and shares are shown.');
                if (FB.error) parts.push(FB.error);
                note.textContent = parts.join(' ');
            }

            const posts = (FB && FB.available) ? FB.posts : [];
            const top = posts.slice(0, 10);
            makeChart('fbTopChart', {
                type: 'bar',
                data: {
                    labels: top.length ? top.map(p => short(p.title)) : ['No Facebook data'],
                    datasets: [
                        { label: 'Reactions', data: top.map(p => p.reactions), backgroundColor: '#1877f2', borderRadius: 4 },
                        { label: 'Comments', data: top.map(p => p.comments), backgroundColor: '#8b5cf6', borderRadius: 4 },
                        { label: 'Shares', data: top.map(p => p.shares), backgroundColor: '#14b8a6', borderRadius: 4 },
                        { label: 'Clicks', data: top.map(p => p.clicks || 0), backgroundColor: '#f59e0b', borderRadius: 4 },
                    ],
                },
                options: { indexAxis: 'y', plugins: { legend: legendBottom, tooltip }, scales: { x: Object.assign({ stacked: true }, yAxis), y: { stacked: true, grid: { display: false } } } },
            });

            document.getElementById('fbTableBody').innerHTML = posts.length ? posts.map(p => `<tr>
                <td><p class="font-bold text-slate-700 truncate max-w-[260px]">${esc(p.title)}</p><p class="text-[10px] text-slate-400">${esc(p.ref)} · ${fmtDate(p.date_posted)}</p></td>
                <td class="text-right">${num(p.impressions)}</td><td class="text-right">${num(p.reach)}</td><td class="text-right">${num(p.reactions)}</td>
                <td class="text-right">${num(p.comments)}</td><td class="text-right">${num(p.shares)}</td><td class="text-right">${num(p.clicks)}</td>
                <td class="text-right">${p.rate === null ? '—' : p.rate + '%'}</td>
                <td class="text-right">${p.permalink ? `<a href="${esc(p.permalink)}" target="_blank" rel="noopener" class="text-blue-600 hover:underline text-[10px] font-black uppercase">Open</a>` : ''}</td></tr>`).join('')
                : '<tr><td colspan="9" class="!py-10 text-center text-slate-400 text-xs font-bold uppercase">No Facebook engagement data for this date range</td></tr>';
        }

        async function generateAnalytics() {
            if (!DATA) return null;
            AI = await AnalyticsReport.generateAi({
                url: '../backend/announcement_ai_summary.php',
                params: { start_date: DATA.range.start, end_date: DATA.range.end },
                button: document.getElementById('aiBtn'),
                panel: document.getElementById('aiPanel'),
                body: document.getElementById('aiBody'),
                meta: document.getElementById('aiMeta'),
                loadingText: `Analysing the announcements from ${DATA.range.label}…`,
            });
            return AI;
        }

        async function exportReport(kind) {
            if (!DATA) return;
            const include = await AnalyticsReport.askIncludeAi(kind, !!AI);
            if (include === null) return;
            if (include && !AI) {
                document.getElementById('aiPanel').scrollIntoView({ behavior: 'smooth', block: 'center' });
                if (!await generateAnalytics()) { alert('The AI analytics could not be generated, so the report was not created. You can choose "No" to export without it.'); return; }
            }
            const html = buildExportDocument(include ? AI : null);
            const fname = `Announcement-Analytics_${DATA.range.start}_to_${DATA.range.end}`;
            if (kind === 'print') AnalyticsReport.print(html, fname);
            else await AnalyticsReport.pdf(html, fname + '.pdf');
        }

        function buildExportDocument(ai) {
            const d = DATA, s = d.summary, R = AnalyticsReport;
            const diff = s.total - s.prev_total;
            const t = (FB && FB.available) ? FB.totals : null, ins = FB && FB.insights_available;

            const fbSection = t
                ? R.statGrid([
                    { label: 'Views', value: ins ? num(t.impressions) : '—' }, { label: 'Reach', value: ins ? num(t.reach) : '—' },
                    { label: 'Reactions', value: num(t.reactions) }, { label: 'Comments', value: num(t.comments) },
                    { label: 'Shares', value: num(t.shares) }, { label: 'Clicks', value: ins ? num(t.clicks) : '—' },
                    { label: 'Engagement', value: num(t.engagement) }, { label: 'Engagement Rate', value: t.rate === null ? '—' : t.rate + '%' },
                ], 4) + (ins ? '' : '<p class="rx-desc" style="margin-top:6px">Views, reach and clicks were not available (the Page token has no read_insights permission).</p>')
                : `<p class="rx-empty">${esc((FB && FB.error) || 'No Facebook engagement data for this date range.')}</p>`;

            const topRows = (FB && FB.available ? FB.posts : []).slice(0, 15).map(p => [
                { html: `<strong>${esc(p.title)}</strong><br><span style="color:#94a3b8">${esc(p.ref)} · ${fmtDate(p.date_posted)}</span>` },
                num(p.impressions), num(p.reach), num(p.reactions), num(p.comments), num(p.shares), p.rate === null ? '—' : p.rate + '%',
            ]);
            const catRows = Object.entries(d.by_category).map(([c, n]) => [c, num(n), s.total ? Math.round(n / s.total * 100) + '%' : '—']);
            const annRows = d.announcements.map(a => [a.ref, a.title, a.category, fmtDate(a.date_posted), a.status, a.fb_post_id ? 'Posted' : '—']);

            return R.buildDocument({
                accent: '#1877f2',
                office: 'Office of the Barangay — Public Information',
                docTitle: 'Announcement Analytics Report',
                subtitle: 'Posting activity and Facebook (Meta) engagement of barangay announcements for the reporting period.',
                rangeLabel: d.range.label,
                preparedTitle: 'Barangay Staff',
                ai,
                sections: [
                    {
                        title: 'Posting Summary', desc: 'Announcements counted by posting date, grouped by their current status (same rules as the Announcement Archive).', html: R.statGrid([
                            { label: 'Posted', value: num(s.total), note: diff === 0 ? 'same as prev. period' : `${diff > 0 ? '+' : ''}${diff} vs prev. period` },
                            { label: 'Active', value: num(s.active), color: '#059669' }, { label: 'Scheduled', value: num(s.scheduled), color: '#4f46e5' },
                            { label: 'Ended', value: num(s.ended), color: '#d97706' }, { label: 'Expired', value: num(s.expired) },
                            { label: 'Posted to Facebook', value: num(s.fb_posted), color: '#1877f2' }, { label: 'With Attachments', value: num(s.with_attachments) },
                            { label: 'Avg. per Week', value: num(s.per_week) },
                        ], 4)
                    },
                    { title: 'Facebook Views, Reach & Engagement', desc: 'Views = times shown, Reach = unique people who saw the posts, Engagement = reactions + comments + shares + clicks, Engagement Rate = engagement ÷ reach.', html: fbSection },
                    { title: 'Posting Trend', desc: `Announcements posted per ${d.trend.granularity}, and how many were also posted to the Facebook Page.`, html: R.chartImage(charts.trendChart, 240) },
                    { title: 'Status & Facebook Coverage', desc: 'Left: current status of the announcements posted in the period. Right: share posted to Facebook, queued, or board-only.', html: R.twoCol(R.chartImage(charts.statusChart, 200), R.chartImage(charts.fbCoverageChart, 200)) },
                    { title: 'Category Breakdown', desc: 'Which kinds of announcements were posted most, and on which days of the week.', html: R.twoCol(R.chartImage(charts.categoryChart, 200), R.chartImage(charts.weekdayChart, 200)) + R.table(['Category', 'Announcements', 'Share'], catRows, { align: ['left', 'right', 'right'], empty: 'No announcements in this date range.' }) },
                    { title: 'Top Facebook Posts', keep: false, desc: 'Announcements with the highest engagement on the Facebook Page in this period.', html: R.chartImage(charts.fbTopChart, 230) + '<div style="height:8px"></div>' + R.table(['Announcement', 'Views', 'Reach', 'Reactions', 'Comments', 'Shares', 'Eng. Rate'], topRows, { align: ['left', 'right', 'right', 'right', 'right', 'right', 'right'], empty: 'No Facebook engagement data for this date range.' }) },
                    { title: 'Announcements in Range', keep: false, desc: `Complete list of the ${d.announcements.length} announcement(s) posted in the reporting period.`, html: R.table(['ID', 'Title', 'Category', 'Posted On', 'Status', 'Facebook'], annRows, { empty: 'No announcements posted in this date range.' }) },
                ],
            });
        }

        document.addEventListener('DOMContentLoaded', loadData);
    </script>
</body>

</html>

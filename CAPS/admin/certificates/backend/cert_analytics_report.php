<?php
/**
 * Legal Document Analytics Report — opened from Legal Document Analytics with ?start&end&mode=print|pdf&ai=1|0.
 * Print → Print Preview; Save PDF → PDF download. Same shared CAPS report layout (officials report_common.php)
 * as the Resident and Household Analytics reports: data / table focused, with interpretation.
 *   ai=0 → "No AI – Data and tables only" (no charts)
 *   ai=1 → "Include AI – Data, charts, and AI explanation": supporting charts + the AI Analytics already
 *          generated on the page for the same data (AI cache — never calls Gemini).
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../db.php';
$required_module = 'certificates';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/cert_analytics_data.php';
require_once __DIR__ . '/../../ai_helper.php';
cert_require_report_common();
cert_migrate($pdo);

$mode = report_mode();
$p = cert_analytics_params($_GET);
$a = cert_analytics_compute($pdo, $p);
$t = $a['totals'];
$withAi = ($_GET['ai'] ?? '0') === '1';
$ai = $withAi ? ai_cache_get('certificates_analytics', cert_analytics_snapshot($a)) : null;
$showCharts = $ai !== null;
$notes = cert_analytics_interpretation($a);

$brgy = report_barangay_profile($pdo);
$bAddr = trim((string)($brgy['address'] ?? '')) ?: implode(', ', array_filter([
    $brgy['barangay_name'] ?? '', $brgy['municipality_name'] ?? '', $brgy['province_name'] ?? '', $brgy['region_name'] ?? '',
], fn($x) => trim((string)$x) !== ''));
$captain = report_current_captain($pdo);
$generatedBy = report_generated_by($pdo);
$generatedRole = ($_SESSION['role'] ?? '') === 'admin' ? 'Administrator' : 'Barangay Staff';
if ($mode !== '') cert_log_activity('Legal Document Analytics Report', ($mode === 'pdf' ? 'Saved PDF' : 'Printed') . ' legal document analytics report · ' . $p['label'] . ($ai ? ' · with AI findings' : ''));

$n = fn($v) => number_format((float)$v);
$pc = fn($v, $d) => ra_pct($v, $d) . '%';
$T = max(1, (int)$t['total']);
function car_table(array $head, array $rows, string $empty = 'No data for the selected range.', array $right = []): void {
    if (!$rows) { echo '<p class="empty-note">' . rh($empty) . '</p>'; return; }
    echo '<table><thead><tr>';
    foreach ($head as $i => $h) echo '<th' . (in_array($i, $right, true) ? ' class="num"' : '') . '>' . rh($h) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr' . (($row[0] ?? '') === 'Total' ? ' class="total"' : '') . '>';
        foreach (array_values($row) as $i => $c) echo '<td' . (in_array($i, $right, true) ? ' class="num"' : '') . '>' . rh($c) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}
$dist = function (array $counts, int $base) use ($n, $pc): array {
    $rows = [];
    foreach ($counts as $k => $v) $rows[] = [(string)$k, $n($v), $pc($v, $base)];
    if ($rows) $rows[] = ['Total', $n(array_sum($counts)), $pc(array_sum($counts), $base)];
    return $rows;
};
function car_note(string $text): void { echo '<p class="note">' . rh($text) . '</p>'; }

$title = 'Legal Document Analytics Report';
report_head($title, $mode);
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<?php report_toolbar($mode, 'legal_document_analytics_' . ($p['start'] ?: 'all') . '_to_' . ($p['end'] ?: 'all')); ?>
<style>
    .brgy-addr{text-align:center;font-size:11px;color:#64748b;margin:-12px 0 14px}
    .info-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 24px}
    table.kv th{width:42%;background:transparent;font-size:10.5px;color:#64748b}
    table.kv td{font-weight:700}
    td.num,th.num{text-align:right;font-variant-numeric:tabular-nums}
    tr.total td{font-weight:800;border-top:1.5px solid #94a3b8;background:#f8fafc}
    .note{margin:10px 0 0;font-size:11.5px;line-height:1.55;color:#334155}
    .sub{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#0d9488;margin:14px 0 4px}
    .sub:first-child{margin-top:0}
    .chart-wrap{position:relative;height:190px;margin:6px 0 4px}
    .chart-cap{font-size:10px;color:#64748b;margin:0 0 4px}
    ol.interp{margin:0;padding-left:18px;font-size:12px;line-height:1.6}
    .ai-item{padding:6px 0;border-bottom:1px solid #e2e8f0;font-size:12px}
    .ai-item:last-child{border-bottom:0}
    .ai-pri{display:inline-block;padding:1px 6px;border-radius:99px;font-size:9px;font-weight:800;text-transform:uppercase;margin-left:6px}
    .pri-high{background:#fee2e2;color:#b91c1c}.pri-medium{background:#fef3c7;color:#b45309}.pri-low{background:#f1f5f9;color:#475569}
    .section-wrap{page-break-inside:auto;break-inside:auto}
    tr,.chart-wrap,.stat-grid,.signature-block,.certify-line{page-break-inside:avoid;break-inside:avoid}
    h2.section{page-break-after:avoid;break-after:avoid}
</style>
<div class="report-page" id="reportRoot">
    <?php report_letterhead($brgy, 'Office of the Punong Barangay · Legal Documents'); ?>
    <?php if ($bAddr): ?><p class="brgy-addr"><?php echo rh($bAddr); ?></p><?php endif; ?>
    <?php report_title_block($title, 'Document request statistics · ' . $p['label'], 'Legal Documents'); ?>

    <?php report_section_open('Report Information'); ?>
    <div class="info-grid">
        <table class="kv"><tbody>
            <tr><th>Date range</th><td><?php echo rh($p['label']); ?></td></tr>
            <tr><th>Basis</th><td><?php echo $p['start'] || $p['end'] ? 'Requests made within the range' : 'All document requests on record'; ?></td></tr>
            <tr><th>Requests covered</th><td><?php echo $n($t['total']); ?></td></tr>
        </tbody></table>
        <table class="kv"><tbody>
            <tr><th>Generated by</th><td><?php echo rh($generatedBy); ?></td></tr>
            <tr><th>Date generated</th><td><?php echo rh(date('F j, Y g:i A')); ?></td></tr>
            <tr><th>Barangay Captain</th><td><?php echo rh($captain ? 'Hon. ' . $captain['full_name'] : 'Not assigned'); ?></td></tr>
        </tbody></table>
    </div>
    <?php report_section_close(); ?>

    <?php report_section_open('Request Summary'); ?>
    <?php report_stat_cards([
        ['value' => $n($t['total']), 'label' => 'Total Requests', 'variant' => 'a'],
        ['value' => $n($t['released']), 'label' => 'Released', 'variant' => 'c'],
        ['value' => $n($t['pending'] + $t['ready'] + $t['preview']), 'label' => 'Open (not released)', 'variant' => 'b'],
        ['value' => $n($t['rejected'] + $t['expired']), 'label' => 'Rejected / Expired', 'variant' => 'd'],
    ]); ?>
    <div style="margin-top:12px"></div>
    <?php car_table(['Indicator', 'Value', 'Share'], [
        ['Walk-in requests', $n($t['walkin']), $pc($t['walkin'], $T)],
        ['Online requests', $n($t['online']), $pc($t['online'], $T)],
        ['Residents who requested', $n($t['residents']), '—'],
        ['Document types requested', $n($t['doc_types']), '—'],
        ['Released', $n($t['released']), $pc($t['released'], $T)],
        ['Pending / under review (online)', $n($t['pending']), $pc($t['pending'], $T)],
        ['Ready to pick up (online)', $n($t['ready']), $pc($t['ready'], $T)],
        ['Generated, not yet released (walk-in)', $n($t['preview']), $pc($t['preview'], $T)],
        ['Rejected (share of online)', $n($t['rejected']), $t['rejection_rate'] !== null ? $t['rejection_rate'] . '%' : '—'],
        ['Expired unclaimed (share of accepted online)', $n($t['expired']), $t['unclaimed_rate'] !== null ? $t['unclaimed_rate'] . '%' : '—'],
        ['Average processing (request → release)', cert_fmt_duration($t['avg_min']), '—'],
        ['Average processing — walk-in / online', cert_fmt_duration($t['walkin_min']) . ' / ' . cert_fmt_duration($t['online_min']), '—'],
        ['Busiest day / hour', ($t['busiest_day'] ?: '—') . ' / ' . ($t['busiest_hour'] ?: '—'), '—'],
    ], 'No requests in this range.', [1, 2]); ?>
    <?php report_section_close(); ?>

    <?php report_section_open('Status Breakdown'); ?>
    <?php car_table(['Status', 'Requests', 'Share'], $dist($a['status'], $T), 'No requests.', [1, 2]); ?>
    <?php if ($showCharts && $t['total']): ?>
    <p class="chart-cap">Figure 1. Requests by status</p>
    <div class="chart-wrap"><canvas id="rc-status"></canvas></div>
    <?php endif; ?>
    <?php report_section_close(); ?>

    <?php report_section_open('Document Types'); ?>
    <?php $rows = array_map(fn($d) => [$d['label'], $n($d['total']), $pc($d['total'], $T), $n($d['walkin']), $n($d['online']), $n($d['released']), $n($d['rejected']), $d['avg']], $a['documents']);
    if ($rows) $rows[] = ['Total', $n($t['total']), '100%', $n($t['walkin']), $n($t['online']), $n($t['released']), $n($t['rejected']), cert_fmt_duration($t['avg_min'])];
    car_table(['Document', 'Requests', 'Share', 'Walk-in', 'Online', 'Released', 'Rejected', 'Avg processing'], $rows, 'No requests.', [1, 2, 3, 4, 5, 6]); ?>
    <?php report_section_close(); ?>

    <?php report_section_open('Purpose & Rejections'); ?>
    <p class="sub">Top purposes</p>
    <?php car_table(['Purpose', 'Requests', 'Share'], $dist($a['purpose'], $T), 'No purposes recorded.', [1, 2]); ?>
    <p class="sub">Rejection reasons</p>
    <?php car_table(['Reason', 'Rejected', 'Share'], $dist($a['rejections'], max(1, (int)$t['rejected'])), 'No rejected requests.', [1, 2]); ?>
    <?php report_section_close(); ?>

    <?php report_section_open('Requesters & Areas'); ?>
    <p class="sub">Requests by sex of the resident</p>
    <?php car_table(['Sex', 'Requests', 'Share'], $dist(array_filter($a['sex']), $T), 'No data.', [1, 2]); ?>
    <p class="sub">Requests by age of the resident</p>
    <?php car_table(['Age group', 'Requests', 'Share'], $dist(array_filter($a['age']), $T), 'No data.', [1, 2]); ?>
    <p class="sub">Requests per area (Subdivision / Village / Sitio / Purok)</p>
    <?php car_table(['Area', 'Requests', 'Share', 'Residents', 'Released'], array_map(fn($x) => [$x['area'], $n($x['total']), $pc($x['total'], $T), $n($x['residents']), $n($x['released'])], $a['areas']), 'No data.', [1, 2, 3, 4]); ?>
    <?php report_section_close(); ?>

    <?php report_section_open('Trend & Comparison'); ?>
    <p class="sub">Requests per <?php echo $a['series_daily'] ? 'day' : 'month'; ?></p>
    <?php $rows = [];
    foreach ($a['series'] as $k => $v) {
        if (!$v['walkin'] && !$v['online'] && $a['series_daily']) continue;   // daily: only days with requests
        $rows[] = [date($a['series_daily'] ? 'M j, Y' : 'F Y', strtotime(strlen($k) === 7 ? $k . '-01' : $k)), $n($v['walkin']), $n($v['online']), $n($v['walkin'] + $v['online']), $n($v['released'])];
    }
    car_table([$a['series_daily'] ? 'Day' : 'Month', 'Walk-in', 'Online', 'Total', 'Released'], $rows, 'No requests in this range.', [1, 2, 3, 4]); ?>
    <?php if ($showCharts && count($a['series']) > 1): ?>
    <p class="chart-cap">Figure 2. Requests over time</p>
    <div class="chart-wrap"><canvas id="rc-time"></canvas></div>
    <?php endif; ?>
    <?php if ($a['comparison']): ?>
    <p class="sub">Selected period vs previous period (<?php echo rh($a['comparison']['previous_label']); ?>)</p>
    <?php car_table(['Indicator', 'Selected period', 'Previous period', 'Change'], array_map(function ($r) use ($n) {
        $d = $r['current'] - $r['previous'];
        return [$r['metric'], $n($r['current']), $n($r['previous']), ($d > 0 ? '+' : '') . $n($d)];
    }, $a['comparison']['rows']), '', [1, 2, 3]); ?>
    <?php else: car_note('Set both a Start Date and an End Date to compare with the previous period of the same length.'); endif; ?>
    <p class="sub">Requests per weekday</p>
    <?php car_table(['Day', 'Requests', 'Share'], $dist($a['weekday'], $T), 'No data.', [1, 2]); ?>
    <?php report_section_close(); ?>

    <?php report_section_open('Document Data Tables'); ?>
    <p class="sub">Latest releases</p>
    <?php car_table(['Document No.', 'Document', 'Type', 'Resident', 'Released', 'Took'], array_map(fn($r) => [$r['doc_number'], $r['doc_type'], $r['type'], $r['resident'], $r['released'], $r['took']], $a['recent']), 'No released documents.'); ?>
    <p class="sub">Unclaimed documents (ready to pick up / expired)</p>
    <?php car_table(['Document / Ref.', 'Document', 'Resident', 'Approved', 'Pick up until', 'Status'], array_map(fn($r) => [$r['doc_number'], $r['doc_type'], $r['resident'], $r['approved'], $r['deadline'], $r['status']], $a['unclaimed']), 'No unclaimed documents.'); ?>
    <?php report_section_close(); ?>

    <?php report_section_open('Data Interpretation'); ?>
    <ol class="interp"><?php foreach ($notes as $x): ?><li><?php echo rh($x); ?></li><?php endforeach; ?></ol>
    <?php report_section_close(); ?>

    <?php if ($ai): ?>
    <?php report_section_open('AI Findings'); ?>
        <?php if (!empty($ai['summary'])) car_note($ai['summary']); ?>
        <?php foreach ([['Key Findings', 'key_findings'], ['Request Trends', 'demographic_trends'], ['Significant Changes / Patterns', 'patterns'], ['Service Observations', 'population_observations']] as [$lbl, $key]):
            if (empty($ai[$key])) continue; ?>
            <p class="sub"><?php echo rh($lbl); ?></p>
            <?php foreach ($ai[$key] as $f): ?><div class="ai-item"><strong><?php echo rh($f['title']); ?></strong> — <?php echo rh($f['detail']); ?></div><?php endforeach; ?>
        <?php endforeach; ?>
        <?php if (!empty($ai['recommendations'])): ?>
            <p class="sub">Recommended Actions</p>
            <?php foreach ($ai['recommendations'] as $i => $f): ?><div class="ai-item"><?php echo $i + 1; ?>. <strong><?php echo rh($f['action']); ?></strong><span class="ai-pri pri-<?php echo rh($f['priority']); ?>"><?php echo rh($f['priority']); ?></span><br><span style="color:#64748b"><?php echo rh($f['reason']); ?></span></div><?php endforeach; ?>
        <?php endif; ?>
        <p style="margin:8px 0 0;font-size:10px;color:#64748b">AI-generated (Gemini) · <?php echo rh($ai['generated_at'] ?? ''); ?> · based on the aggregated document requests of <?php echo rh($p['label']); ?>. Verify against the tables above.</p>
    <?php report_section_close(); ?>
    <?php elseif ($withAi): ?>
    <?php report_section_open('AI Findings'); car_note('No AI Analytics has been generated for this data yet.'); report_section_close(); ?>
    <?php endif; ?>

    <?php report_certify_and_signatures($brgy['brgy_name'] ?? 'Barangay', $generatedBy, $generatedRole, $captain); ?>
    <?php report_footer_strip($brgy['brgy_name'] ?? 'Barangay'); ?>
</div>
<script>
(function () {
    if (!window.Chart) return;
    Chart.defaults.animation = false;
    Chart.defaults.font.family = 'Arial, Helvetica, sans-serif';
    Chart.defaults.font.size = 10;
    Chart.defaults.color = '#475569';
    const grid = { color: '#e2e8f0' };
    const status = <?php echo json_encode($a['status'], JSON_UNESCAPED_UNICODE); ?>;
    const sEl = document.getElementById('rc-status');
    if (sEl) new Chart(sEl, { type: 'bar',
        data: { labels: Object.keys(status), datasets: [{ label: 'Requests', data: Object.values(status), backgroundColor: '#2a78d6', borderRadius: 3, maxBarThickness: 18 }] },
        options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 }, grid }, y: { grid: { display: false } } } } });
    const series = <?php echo json_encode($a['series']); ?>;
    const tEl = document.getElementById('rc-time');
    if (tEl) new Chart(tEl, { type: 'line',
        data: { labels: Object.keys(series), datasets: [
            { label: 'Walk-in', data: Object.values(series).map(x => x.walkin), borderColor: '#2a78d6', backgroundColor: '#2a78d6', borderWidth: 2, pointRadius: 2, tension: 0 },
            { label: 'Online', data: Object.values(series).map(x => x.online), borderColor: '#eb6834', backgroundColor: '#eb6834', borderWidth: 2, pointRadius: 2, tension: 0 }] },
        options: { maintainAspectRatio: false, plugins: { legend: { position: 'top', align: 'end', labels: { boxWidth: 10, boxHeight: 10 } } }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid } } } });
})();
</script>
<?php report_foot(); ?>

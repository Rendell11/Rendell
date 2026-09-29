<?php
/**
 * Household Analytics Report — opened from Household Analytics with ?start&end&mode=print|pdf&ai=1|0.
 * Print → Print Preview; Save PDF → PDF download. Same page, same layout, built on the shared
 * CAPS report layout (officials report_common.php), like the Resident Analytics report.
 * A formal data report: summary, statistics tables with percentages, income / socioeconomic
 * tables, comparisons and short interpretation.
 *   ai=0 → "No AI – Data and tables only" (no charts)
 *   ai=1 → "Include AI – Data, charts, and AI explanation": supporting charts + the AI Analytics
 *          already generated on the page for the same data (AI cache — never calls Gemini).
 */
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../permission_helper.php';
require_once __DIR__ . '/../../ai_helper.php';
require_once __DIR__ . '/../../officials/backend/report_common.php';
require_once __DIR__ . '/household_analytics_data.php';
require_permission($pdo, 'households', 'read');

$mode = report_mode();
$p = ra_params($_GET);
$a = hha_compute($pdo, $p);
$t = $a['totals'];
$withAi = ($_GET['ai'] ?? '0') === '1';
$ai = $withAi ? ai_cache_get('households', hha_snapshot($a)) : null;
$showCharts = $ai !== null;
$notes = hha_interpretation($a);

$brgy = report_barangay_profile($pdo);
$bAddr = trim((string) ($brgy['address'] ?? '')) ?: implode(', ', array_filter([
    $brgy['barangay_name'] ?? '', $brgy['municipality_name'] ?? '', $brgy['province_name'] ?? '', $brgy['region_name'] ?? '',
], fn($x) => trim((string) $x) !== ''));
$captain = report_current_captain($pdo);
$generatedBy = report_generated_by($pdo);
$generatedRole = ($_SESSION['role'] ?? '') === 'admin' ? 'Administrator' : 'Barangay Staff';

if ($mode !== '') {
    try {
        require_once __DIR__ . '/../../activity_log_helper.php';
        log_activity('Households', 'Household Analytics Report',
            ($mode === 'pdf' ? 'Saved PDF' : 'Printed') . ' household analytics report · ' . $p['label'] . ($ai ? ' · with AI findings' : ''));
    } catch (Throwable $e) {
        error_log('[Household analytics report] activity log: ' . $e->getMessage());
    }
}

$n = fn($v) => number_format((float) $v);
$peso = fn($v) => $v === null ? '—' : '₱' . number_format((float) $v, 2);
$pc = fn($v, $d) => ra_pct($v, $d) . '%';
$H = max(1, (int) $t['households']);
function har_table(array $head, array $rows, string $empty = 'No data for the selected range.', array $right = []): void {
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
    foreach ($counts as $k => $v) $rows[] = [(string) $k, $n($v), $pc($v, $base)];
    if ($rows) $rows[] = ['Total', $n(array_sum($counts)), $pc(array_sum($counts), $base)];
    return $rows;
};
function har_note(string $text): void { echo '<p class="note">' . rh($text) . '</p>'; }

$title = 'Household Analytics Report';
report_head($title, $mode);
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<?php report_toolbar($mode, 'household_analytics_' . ($p['start'] ?: 'all') . '_to_' . ($p['end'] ?: 'all')); ?>
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
    <?php report_letterhead($brgy, 'Office of the Punong Barangay · Household Records'); ?>
    <?php if ($bAddr): ?><p class="brgy-addr"><?php echo rh($bAddr); ?></p><?php endif; ?>
    <?php report_title_block($title, 'Household statistics · ' . $p['label'], 'Household Management'); ?>

    <?php report_section_open('Report Information'); ?>
    <div class="info-grid">
        <table class="kv"><tbody>
            <tr><th>Date range</th><td><?php echo rh($p['label']); ?></td></tr>
            <tr><th>Basis</th><td><?php echo $p['start'] || $p['end'] ? 'Active households registered within the range' : 'All active households'; ?></td></tr>
            <tr><th>Households covered</th><td><?php echo $n($t['households']); ?></td></tr>
        </tbody></table>
        <table class="kv"><tbody>
            <tr><th>Generated by</th><td><?php echo rh($generatedBy); ?></td></tr>
            <tr><th>Date generated</th><td><?php echo rh(date('F j, Y g:i A')); ?></td></tr>
            <tr><th>Barangay Captain</th><td><?php echo rh($captain ? 'Hon. ' . $captain['full_name'] : 'Not assigned'); ?></td></tr>
        </tbody></table>
    </div>
    <?php har_note('Income = combined monthly income of the Head and all living members. Income Status uses the combined income. Socioeconomic Status compares income per member with the poverty threshold of ' . $peso($t['poverty_line']) . ' per person per month (PIDS income classes).'); ?>
    <?php report_section_close(); ?>

    <?php report_section_open('Household Summary'); ?>
    <?php report_stat_cards([
        ['value' => $n($t['households']), 'label' => 'Total Households', 'variant' => 'a'],
        ['value' => $n($t['persons']), 'label' => 'Persons in Households', 'variant' => 'b'],
        ['value' => $t['avg_size'] ?? '—', 'label' => 'Average Household Size', 'variant' => 'c'],
        ['value' => $n($t['poor']), 'label' => 'Below Poverty Threshold', 'variant' => 'd'],
    ]); ?>
    <div style="margin-top:12px"></div>
    <?php har_table(['Indicator', 'Value', 'Share of households'], [
        ['Average combined monthly income', $peso($t['avg_income']), '—'],
        ['Median combined monthly income', $peso($t['median_income']), '—'],
        ['Total combined monthly income', $peso($t['total_income']), '—'],
        ['Average income per member', $peso($t['avg_per_capita']), '—'],
        ['Low Income households (combined income)', $n($t['low_income']), $pc($t['low_income'], $H)],
        ['Poor households (below poverty threshold)', $n($t['poor']), $pc($t['poor'], $H)],
        ['Households with no recorded income', $n($t['no_income']), $pc($t['no_income'], $H)],
        ['One-person households', $n($t['single_person']), $pc($t['single_person'], $H)],
        ['Female-headed households', $n($t['female_headed']), $pc($t['female_headed'], $H)],
        ['Households with a senior citizen', $n($t['with_senior']), $pc($t['with_senior'], $H)],
        ['Households with a minor (under 18)', $n($t['with_minor']), $pc($t['with_minor'], $H)],
        ['Households with a PWD', $n($t['with_pwd']), $pc($t['with_pwd'], $H)],
        ['Households with a 4Ps member', $n($t['with_4ps']), $pc($t['with_4ps'], $H)],
        ['Households with a map pin', $n($t['pinned']), $pc($t['pinned'], $H)],
        ['Households without a Head (current)', $n($t['headless']), '—'],
        ['Households set inactive in the range', $n($t['inactive']), '—'],
    ], 'No households in this range.', [1, 2]); ?>
    <?php report_section_close(); ?>

    <?php report_section_open('Household Size'); ?>
    <?php $rows = [];
    foreach ($a['size'] as $k => $v) $rows[] = [$k . ((string) $k === '1' ? ' person' : ' persons'), $n($v), $pc($v, $H)];
    if ($t['households']) $rows[] = ['Total', $n($t['households']), '100%'];
    har_table(['Household size (Head included)', 'Households', 'Share'], $t['households'] ? $rows : [], 'No households.', [1, 2]); ?>
    <?php har_note('Average size ' . ($t['avg_size'] ?? '—') . ', median ' . ($t['median_size'] ?? '—') . '; ' . $n($t['earners']) . ' member(s) have recorded income.'); ?>
    <?php report_section_close(); ?>

    <?php report_section_open('Income & Socioeconomic Status'); ?>
    <p class="sub">Income Status (combined monthly income)</p>
    <?php har_table(['Income Status', 'Households', 'Share'], $dist($a['income_class'], $H), 'No data.', [1, 2]); ?>
    <p class="sub">Socioeconomic Status (income per member vs poverty threshold)</p>
    <?php har_table(['Socioeconomic Status', 'Households', 'Share'], $dist($a['ses'], $H), 'No data.', [1, 2]); ?>
    <?php if ($showCharts && $t['households']): ?>
    <p class="chart-cap">Figure 1. Households by Socioeconomic Status</p>
    <div class="chart-wrap"><canvas id="rc-ses"></canvas></div>
    <?php endif; ?>
    <p class="sub">Combined monthly income brackets</p>
    <?php har_table(['Combined income', 'Households', 'Share'], $dist($a['brackets'], $H), 'No data.', [1, 2]); ?>
    <?php report_section_close(); ?>

    <?php report_section_open('Household Member Demographics'); ?>
    <?php $known = array_sum(array_map(fn($b) => $b['total'], $a['member_age']));
    $rows = [];
    foreach ($a['member_age'] as $band => $b) $rows[] = [$band, $n($b['male']), $n($b['female']), $n($b['total']), $pc($b['total'], max(1, $known))];
    if ($known) $rows[] = ['Total', $n(array_sum(array_column($a['member_age'], 'male'))), $n(array_sum(array_column($a['member_age'], 'female'))), $n($known), '100%'];
    ?>
    <p class="sub">Age of all household members (Head included)</p>
    <?php har_table(['Age group', 'Male', 'Female', 'Total', 'Share'], $known ? $rows : [], 'No valid birth dates.', [1, 2, 3, 4]); ?>
    <p class="sub">Household Heads by sex</p>
    <?php har_table(['Sex of Head', 'Households', 'Share'], $dist($a['head_sex'], $H), 'No data.', [1, 2]); ?>
    <p class="sub">Relationship of members to the Head</p>
    <?php har_table(['Relationship', 'Members', 'Share'], $dist(ra_top($a['relationship'], 12), max(1, (int) $t['members'])), 'No members.', [1, 2]); ?>
    <?php report_section_close(); ?>

    <?php report_section_open('Area & Housing'); ?>
    <?php $rows = [];
    foreach ($a['areas'] as $x) $rows[] = [$x['area'], $n($x['households']), $pc($x['households'], $H), $n($x['persons']), $peso($x['avg_income']), $n($x['low_income']), $n($x['poor'])];
    if ($rows) $rows[] = ['Total', $n($t['households']), '100%', $n($t['persons']), $peso($t['avg_income']), $n($t['low_income']), $n($t['poor'])];
    ?>
    <p class="sub">Households per area (Subdivision / Village / Sitio / Purok)</p>
    <?php har_table(['Area', 'Households', 'Share', 'Persons', 'Avg income', 'Low Income', 'Poor'], $rows, 'No data.', [1, 2, 3, 4, 5, 6]); ?>
    <p class="sub">Households by street</p>
    <?php har_table(['Street', 'Households', 'Share'], $dist(ra_top($a['streets'], 15), $H), 'No data.', [1, 2]); ?>
    <p class="sub">House type</p>
    <?php har_table(['House type', 'Households', 'Share'], $dist($a['house_type'], $H), 'No data.', [1, 2]); ?>
    <p class="sub">Tenure status</p>
    <?php har_table(['Tenure', 'Households', 'Share'], $dist($a['tenure'], $H), 'No data.', [1, 2]); ?>
    <?php report_section_close(); ?>

    <?php report_section_open('Household Data Tables'); ?>
    <?php $hrow = fn($r) => [$r['household_id'], $r['head'], $r['address'], $n($r['size']), $peso($r['income']), $peso($r['per_capita']), $r['ses']]; ?>
    <p class="sub">Lowest income per member (top <?php echo min(15, count($a['lowest_per_capita'])); ?>)</p>
    <?php har_table(['Household ID', 'Head', 'Address', 'Members', 'Combined income', 'Per member', 'Socioeconomic Status'], array_map($hrow, $a['lowest_per_capita']), 'No households.', [3, 4, 5]); ?>
    <p class="sub">Largest households (top <?php echo min(15, count($a['largest'])); ?>)</p>
    <?php har_table(['Household ID', 'Head', 'Address', 'Members', 'Combined income', 'Per member', 'Socioeconomic Status'], array_map($hrow, $a['largest']), 'No households.', [3, 4, 5]); ?>
    <?php report_section_close(); ?>

    <?php report_section_open('Comparison & Trend Data'); ?>
    <?php if ($a['comparison']): ?>
    <p class="sub">Selected period vs previous period (<?php echo rh($a['comparison']['previous_label']); ?>)</p>
    <?php har_table(['Indicator', 'Selected period', 'Previous period', 'Change'], array_map(function ($r) use ($n) {
        $d = $r['current'] - $r['previous'];
        return [$r['metric'], $n($r['current']), $n($r['previous']), ($d > 0 ? '+' : '') . $n($d)];
    }, $a['comparison']['rows']), '', [1, 2, 3]); ?>
    <?php else: har_note('Set both a Start Date and an End Date to compare with the previous period of the same length.'); endif; ?>
    <p class="sub">Households registered per month</p>
    <?php $rows = []; $prev = null;
    foreach ($a['monthly'] as $m => $v) { $rows[] = [date('F Y', strtotime($m . '-01')), $n($v), $pc($v, $H), $prev === null ? '—' : (($v - $prev > 0 ? '+' : '') . $n($v - $prev))]; $prev = $v; }
    har_table(['Month', 'Households', 'Share', 'Change vs previous month'], $rows, 'No registrations in this range.', [1, 2, 3]); ?>
    <?php if ($showCharts && count($a['monthly']) > 1): ?>
    <p class="chart-cap">Figure 2. Households registered per month</p>
    <div class="chart-wrap"><canvas id="rc-month"></canvas></div>
    <?php endif; ?>
    <?php report_section_close(); ?>

    <?php report_section_open('Data Interpretation'); ?>
    <ol class="interp"><?php foreach ($notes as $x): ?><li><?php echo rh($x); ?></li><?php endforeach; ?></ol>
    <?php report_section_close(); ?>

    <?php if ($ai): ?>
    <?php report_section_open('AI Findings'); ?>
        <?php if (!empty($ai['summary'])) har_note($ai['summary']); ?>
        <?php foreach ([['Key Findings', 'key_findings'], ['Demographic Trends', 'demographic_trends'], ['Significant Changes / Patterns', 'patterns'], ['Population Observations', 'population_observations']] as [$lbl, $key]):
            if (empty($ai[$key])) continue; ?>
            <p class="sub"><?php echo rh($lbl); ?></p>
            <?php foreach ($ai[$key] as $f): ?><div class="ai-item"><strong><?php echo rh($f['title']); ?></strong> — <?php echo rh($f['detail']); ?></div><?php endforeach; ?>
        <?php endforeach; ?>
        <?php if (!empty($ai['recommendations'])): ?>
            <p class="sub">Recommended Actions</p>
            <?php foreach ($ai['recommendations'] as $i => $f): ?><div class="ai-item"><?php echo $i + 1; ?>. <strong><?php echo rh($f['action']); ?></strong><span class="ai-pri pri-<?php echo rh($f['priority']); ?>"><?php echo rh($f['priority']); ?></span><br><span style="color:#64748b"><?php echo rh($f['reason']); ?></span></div><?php endforeach; ?>
        <?php endif; ?>
        <p style="margin:8px 0 0;font-size:10px;color:#64748b">AI-generated (Gemini) · <?php echo rh($ai['generated_at'] ?? ''); ?> · based on the aggregated household records of <?php echo rh($p['label']); ?>. Verify against the tables above.</p>
    <?php report_section_close(); ?>
    <?php elseif ($withAi): ?>
    <?php report_section_open('AI Findings'); har_note('No AI Analytics has been generated for this data yet.'); report_section_close(); ?>
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
    const ses = <?php echo json_encode($a['ses'], JSON_UNESCAPED_UNICODE); ?>;
    const sEl = document.getElementById('rc-ses');
    if (sEl) new Chart(sEl, { type: 'bar',
        data: { labels: Object.keys(ses), datasets: [{ label: 'Households', data: Object.values(ses), backgroundColor: '#2a78d6', borderRadius: 3, maxBarThickness: 18 }] },
        options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 }, grid }, y: { grid: { display: false } } } } });
    const monthly = <?php echo json_encode($a['monthly']); ?>;
    const mEl = document.getElementById('rc-month');
    if (mEl) new Chart(mEl, { type: 'line',
        data: { labels: Object.keys(monthly).map(m => new Date(m + '-01T00:00:00').toLocaleDateString('en-US', { month: 'short', year: 'numeric' })),
            datasets: [{ label: 'Households', data: Object.values(monthly), borderColor: '#2a78d6', backgroundColor: '#2a78d6', borderWidth: 2, pointRadius: 3, tension: 0 }] },
        options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid } } } });
})();
</script>
<?php report_foot(); ?>

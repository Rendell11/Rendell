<?php
/**
 * Resident Analytics Report — opened from Resident Analytics with ?start&end&mode=print|pdf&ai=1|0.
 * Print → Print Preview; Save PDF → PDF download. Both are this same page (same layout),
 * built on the shared CAPS report layout (officials report_common.php) used by the Blotter reports.
 * A formal data report: summary, statistics tables with percentages, comparisons and short
 * interpretation; charts are supporting visuals only.
 *   ai=1: includes the AI Analytics already generated on the page for the same data
 *         (read from the AI cache — never calls Gemini).
 */
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../permission_helper.php';
require_once __DIR__ . '/../../ai_helper.php';
require_once __DIR__ . '/../../officials/backend/report_common.php';
require_once __DIR__ . '/resident_analytics_data.php';
require_permission($pdo, 'residents', 'read');

$mode = report_mode();
$p = ra_params($_GET);
$a = ra_compute($pdo, $p);
$t = $a['totals'];
$withAi = ($_GET['ai'] ?? '0') === '1';
$ai = $withAi ? ai_cache_get('residents', ra_snapshot($a)) : null;
$notes = ra_interpretation($a);
// "No AI – Data and tables only" → no charts. "Include AI – Data, charts, and AI explanation" → charts + AI.
$showCharts = $ai !== null;

$brgy = report_barangay_profile($pdo);
$bAddr = trim((string)($brgy['address'] ?? '')) ?: implode(', ', array_filter([
    $brgy['barangay_name'] ?? '', $brgy['municipality_name'] ?? '', $brgy['province_name'] ?? '', $brgy['region_name'] ?? '',
], fn($x) => trim((string)$x) !== ''));
$captain = report_current_captain($pdo);
$generatedBy = report_generated_by($pdo);
$generatedRole = ($_SESSION['role'] ?? '') === 'admin' ? 'Administrator' : 'Barangay Staff';

if ($mode !== '') {
    try {
        require_once __DIR__ . '/../../activity_log_helper.php';
        log_activity('Residents', 'Resident Analytics Report',
            ($mode === 'pdf' ? 'Saved PDF' : 'Printed') . ' resident analytics report · ' . $p['label'] . ($ai ? ' · with AI findings' : ''));
    } catch (Throwable $e) {
        error_log('[Resident analytics report] activity log: ' . $e->getMessage());
    }
}

$n = fn($v) => number_format((float)$v);
$pc = fn($v, $d) => ra_pct($v, $d) . '%';
function rar_table(array $head, array $rows, string $empty = 'No data for the selected range.', array $right = []): void {
    if (!$rows) { echo '<p class="empty-note">' . rh($empty) . '</p>'; return; }
    echo '<table><thead><tr>';
    foreach ($head as $i => $h) echo '<th' . (in_array($i, $right, true) ? ' class="num"' : '') . '>' . rh($h) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $isTotal = ($row[0] ?? '') === 'Total';
        echo '<tr' . ($isTotal ? ' class="total"' : '') . '>';
        foreach (array_values($row) as $i => $c) echo '<td' . (in_array($i, $right, true) ? ' class="num"' : '') . '>' . rh($c) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}
/** [label => count] → rows with share of $base, plus a Total row. */
$dist = function (array $counts, int $base) use ($n, $pc): array {
    $rows = [];
    foreach ($counts as $k => $v) $rows[] = [(string)$k, $n($v), $pc($v, $base)];
    if ($rows) $rows[] = ['Total', $n(array_sum($counts)), $pc(array_sum($counts), $base)];
    return $rows;
};
function rar_note(string $text): void { echo '<p class="note">' . rh($text) . '</p>'; }

$living = max(0, (int)$t['active']);
$ageKnown = array_sum(array_map(fn($b) => $b['total'], $a['age']));

$title = 'Resident Analytics Report';
report_head($title, $mode);
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<?php report_toolbar($mode, 'resident_analytics_' . ($p['start'] ?: 'all') . '_to_' . ($p['end'] ?: 'all')); ?>
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
    <?php /* 1. Report Header */ report_letterhead($brgy, 'Office of the Punong Barangay · Resident Records'); ?>
    <?php if ($bAddr): ?><p class="brgy-addr"><?php echo rh($bAddr); ?></p><?php endif; ?>
    <?php report_title_block($title, 'Resident Profiling statistics · ' . $p['label'], 'Resident Management'); ?>

    <?php /* 2. Report Information / Date Range */ report_section_open('Report Information'); ?>
    <div class="info-grid">
        <table class="kv"><tbody>
            <tr><th>Date range</th><td><?php echo rh($p['label']); ?></td></tr>
            <tr><th>Basis</th><td><?php echo $p['start'] || $p['end'] ? 'Residents registered within the range' : 'All resident records'; ?></td></tr>
            <tr><th>Records covered</th><td><?php echo $n($t['total']); ?></td></tr>
        </tbody></table>
        <table class="kv"><tbody>
            <tr><th>Generated by</th><td><?php echo rh($generatedBy); ?></td></tr>
            <tr><th>Date generated</th><td><?php echo rh(date('F j, Y g:i A')); ?></td></tr>
            <tr><th>Barangay Captain</th><td><?php echo rh($captain ? 'Hon. ' . $captain['full_name'] : 'Not assigned'); ?></td></tr>
        </tbody></table>
    </div>
    <?php rar_note('Sex and status counts cover every record in the range. Age, household, relationship and area statistics cover living residents only (deceased records excluded).'); ?>
    <?php report_section_close(); ?>

    <?php /* 3. Resident Summary */ report_section_open('Resident Summary'); ?>
    <?php report_stat_cards([
        ['value' => $n($t['total']), 'label' => 'Total Residents', 'variant' => 'a'],
        ['value' => $n($t['active']), 'label' => 'Active Residents', 'variant' => 'c'],
        ['value' => $n($t['households']), 'label' => 'Total Households', 'variant' => 'b'],
        ['value' => $n($t['deceased']), 'label' => 'Deceased Records', 'variant' => 'd'],
    ]); ?>
    <div style="margin-top:12px"></div>
    <?php rar_table(['Indicator', 'Count', 'Share', 'Base'], [
        ['Male', $n($t['male']), $pc($t['male'], $t['total']), 'All records'],
        ['Female', $n($t['female']), $pc($t['female'], $t['total']), 'All records'],
        ['Sex not specified', $n($t['other_sex']), $pc($t['other_sex'], $t['total']), 'All records'],
        ['Active (living) residents', $n($t['active']), $pc($t['active'], $t['total']), 'All records'],
        ['Deceased records', $n($t['deceased']), $pc($t['deceased'], $t['total']), 'All records'],
        ['Household Heads', $n($t['heads']), $pc($t['heads'], $living), 'Living residents'],
        ['Household Members', $n($t['members']), $pc($t['members'], $living), 'Living residents'],
        ['Not linked to a household', $n($t['unlinked']), $pc($t['unlinked'], $living), 'Living residents'],
        ['Average household size', $t['avg_household_size'] !== null ? (string)$t['avg_household_size'] : '—', '—', 'Households'],
    ], 'No resident records in this range.', [1, 2]); ?>
    <?php report_section_close(); ?>

    <?php /* 4. Demographic Statistics */ report_section_open('Demographic Statistics'); ?>
    <p class="sub">Age distribution (living residents with a valid birth date)</p>
    <?php $ageRows = [];
    foreach ($a['age'] as $band => $b) $ageRows[] = [$band, $n($b['male']), $n($b['female']), $n($b['other']), $n($b['total']), $pc($b['total'], $ageKnown)];
    if ($ageKnown) $ageRows[] = ['Total', $n(array_sum(array_column($a['age'], 'male'))), $n(array_sum(array_column($a['age'], 'female'))),
        $n(array_sum(array_column($a['age'], 'other'))), $n($ageKnown), '100%'];
    rar_table(['Age group', 'Male', 'Female', 'Not specified', 'Total', 'Share'], $ageKnown ? $ageRows : [], 'No valid birth dates in this range.', [1, 2, 3, 4, 5]); ?>
    <?php if ($ageKnown && $showCharts): ?>
    <p class="chart-cap">Figure 1. Living residents by age group and sex</p>
    <div class="chart-wrap"><canvas id="rc-age"></canvas></div>
    <?php endif; ?>
    <?php rar_table(['Age indicator', 'Value'], [
        ['Minors (under 18)', $n($t['minors']) . ' (' . $pc($t['minors'], $ageKnown) . ')'],
        ['Working age (18–59)', $n($t['working_age']) . ' (' . $pc($t['working_age'], $ageKnown) . ')'],
        ['Elderly (60 and above)', $n($t['elderly']) . ' (' . $pc($t['elderly'], $ageKnown) . ')'],
        ['Average age', $t['avg_age'] !== null ? $t['avg_age'] . ' years' : '—'],
        ['Median age', $t['median_age'] !== null ? $t['median_age'] . ' years' : '—'],
        ['No valid birth date', $n($t['unknown_age'])],
    ], '', [1]); ?>

    <p class="sub">Civil status (living residents)</p>
    <?php rar_table(['Civil status', 'Count', 'Share'], $dist($a['civil_status'], $living), 'No data.', [1, 2]); ?>

    <?php if ($a['religion']): ?>
    <p class="sub">Religion (living residents with a recorded religion)</p>
    <?php rar_table(['Religion', 'Count', 'Share'], $dist(ra_top($a['religion'], 10), array_sum($a['religion'])), 'No data.', [1, 2]); ?>
    <?php endif; ?>

    <p class="sub">Relationship to household head (living residents)</p>
    <?php rar_table(['Relationship', 'Count', 'Share'], $dist($a['relationship'], $living), 'No data.', [1, 2]); ?>
    <?php report_section_close(); ?>

    <?php /* 5. Resident Data Tables */ report_section_open('Socio-Economic & Program Data (living residents)'); ?>
    <p class="sub">Employment status</p>
    <?php rar_table(['Employment status', 'Count', 'Share'], $dist($a['employment'], $living), 'No data.', [1, 2]); ?>
    <?php if ($a['education']): ?>
    <p class="sub">Highest education (residents with a recorded education level)</p>
    <?php rar_table(['Education level', 'Count', 'Share'], $dist($a['education'], array_sum($a['education'])), 'No data.', [1, 2]); ?>
    <?php endif; ?>
    <p class="sub">Sector / government program membership</p>
    <?php $progRows = [];
    foreach ($a['programs'] as $k => $v) $progRows[] = [$k, $n($v), $pc($v, $living), $n(max(0, $living - $v))];
    rar_table(['Sector / program', 'Members', 'Coverage', 'Not covered'], $progRows, 'No data.', [1, 2, 3]); ?>
    <?php report_section_close(); ?>

    <?php /* 6. Area / Household Statistics */ report_section_open('Area & Household Statistics (living residents)'); ?>
    <p class="sub">Population per area (Subdivision / Village / Sitio / Purok)</p>
    <?php $areaRows = [];
    foreach ($a['areas'] as $x) $areaRows[] = [$x['area'], $n($x['total']), $pc($x['total'], $living), $n($x['male']), $n($x['female']), $n($x['households']), $n($x['minors']), $n($x['seniors']), $n($x['pwd'])];
    if ($areaRows) $areaRows[] = ['Total', $n($living), '100%', $n(array_sum(array_column($a['areas'], 'male'))), $n(array_sum(array_column($a['areas'], 'female'))),
        $n(array_sum(array_column($a['areas'], 'households'))), $n(array_sum(array_column($a['areas'], 'minors'))), $n(array_sum(array_column($a['areas'], 'seniors'))), $n(array_sum(array_column($a['areas'], 'pwd')))];
    rar_table(['Area', 'Residents', 'Share', 'Male', 'Female', 'Households', 'Minors', 'Seniors', 'PWD'], $areaRows, 'No data.', [1, 2, 3, 4, 5, 6, 7, 8]); ?>

    <p class="sub">Residents by street</p>
    <?php rar_table(['Street', 'Residents', 'Share'], $dist(ra_top($a['streets'], 15), $living), 'No data.', [1, 2]); ?>

    <p class="sub">Household size distribution</p>
    <?php $hsRows = [];
    foreach ($a['household_size'] as $k => $v) $hsRows[] = [$k . ($k === '1' || $k === 1 ? ' person' : ' persons'), $n($v), $pc($v, $t['households'])];
    if ($t['households']) $hsRows[] = ['Total', $n($t['households']), '100%'];
    rar_table(['Household size (Head included)', 'Households', 'Share'], $t['households'] ? $hsRows : [], 'No households in this range.', [1, 2]); ?>

    <?php if ($a['households']): ?>
    <p class="sub">Largest households (top <?php echo min(15, count($a['households'])); ?> of <?php echo $n(count($a['households'])); ?>)</p>
    <?php rar_table(['Household ID', 'Household Head', 'Address', 'Members'],
        array_map(fn($h) => [$h['household_id'], $h['head'], $h['address'], $n($h['size'])], array_slice($a['households'], 0, 15)), '', [3]); ?>
    <?php endif; ?>
    <?php report_section_close(); ?>

    <?php /* 7. Comparison / Trend Data */ report_section_open('Comparison & Trend Data'); ?>
    <?php if ($a['comparison']): ?>
    <p class="sub">Selected period vs previous period (<?php echo rh($a['comparison']['previous_label']); ?>)</p>
    <?php rar_table(['Indicator', 'Selected period', 'Previous period', 'Change', 'Change %'], array_map(function ($r) use ($n) {
        $d = $r['current'] - $r['previous'];
        return [$r['metric'], $n($r['current']), $n($r['previous']), ($d > 0 ? '+' : '') . $n($d),
            $r['previous'] > 0 ? (($d > 0 ? '+' : '') . ra_pct($d, $r['previous']) . '%') : '—'];
    }, $a['comparison']['rows']), '', [1, 2, 3, 4]); ?>
    <?php else: ?>
    <?php rar_note('Set both a Start Date and an End Date to compare with the previous period of the same length.'); ?>
    <?php endif; ?>
    <p class="sub">Residents registered per month</p>
    <?php $mRows = []; $prev = null;
    foreach ($a['monthly'] as $m => $v) {
        $mRows[] = [date('F Y', strtotime($m . '-01')), $n($v), $pc($v, $t['total']), $prev === null ? '—' : (($v - $prev > 0 ? '+' : '') . $n($v - $prev))];
        $prev = $v;
    }
    if ($mRows) $mRows[] = ['Total', $n($t['total']), '100%', ''];
    rar_table(['Month', 'Registered', 'Share', 'Change vs previous month'], $mRows, 'No registrations in this range.', [1, 2, 3]); ?>
    <?php if (count($a['monthly']) > 1 && $showCharts): ?>
    <p class="chart-cap">Figure 2. Residents registered per month</p>
    <div class="chart-wrap"><canvas id="rc-month"></canvas></div>
    <?php endif; ?>
    <?php report_section_close(); ?>

    <?php /* 8. Short Data Interpretation */ report_section_open('Data Interpretation'); ?>
    <ol class="interp"><?php foreach ($notes as $x): ?><li><?php echo rh($x); ?></li><?php endforeach; ?></ol>
    <?php report_section_close(); ?>

    <?php /* 9. AI Findings — only if selected */ if ($ai): ?>
    <?php report_section_open('AI Findings'); ?>
        <?php if (!empty($ai['summary'])) rar_note($ai['summary']); ?>
        <?php foreach ([['Key Findings', 'key_findings'], ['Demographic Trends', 'demographic_trends'], ['Significant Changes / Patterns', 'patterns'], ['Population Observations', 'population_observations']] as [$lbl, $key]):
            if (empty($ai[$key])) continue; ?>
            <p class="sub"><?php echo rh($lbl); ?></p>
            <?php foreach ($ai[$key] as $f): ?><div class="ai-item"><strong><?php echo rh($f['title']); ?></strong> — <?php echo rh($f['detail']); ?></div><?php endforeach; ?>
        <?php endforeach; ?>
        <?php if (!empty($ai['recommendations'])): ?>
            <p class="sub">Recommended Actions</p>
            <?php foreach ($ai['recommendations'] as $i => $f): ?><div class="ai-item"><?php echo $i + 1; ?>. <strong><?php echo rh($f['action']); ?></strong><span class="ai-pri pri-<?php echo rh($f['priority']); ?>"><?php echo rh($f['priority']); ?></span><br><span style="color:#64748b"><?php echo rh($f['reason']); ?></span></div><?php endforeach; ?>
        <?php endif; ?>
        <p style="margin:8px 0 0;font-size:10px;color:#64748b">AI-generated (Gemini) · <?php echo rh($ai['generated_at'] ?? ''); ?> · based on the aggregated resident records of <?php echo rh($p['label']); ?>. Verify against the tables above.</p>
    <?php report_section_close(); ?>
    <?php elseif ($withAi): ?>
    <?php report_section_open('AI Findings'); rar_note('No AI Analytics has been generated for this data yet.'); report_section_close(); ?>
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
    const age = <?php echo json_encode($a['age'], JSON_UNESCAPED_UNICODE); ?>;
    const ageEl = document.getElementById('rc-age');
    if (ageEl) new Chart(ageEl, {
        type: 'bar',
        data: { labels: Object.keys(age), datasets: [
            { label: 'Male', data: Object.values(age).map(b => b.male), backgroundColor: '#2a78d6', borderRadius: 3 },
            { label: 'Female', data: Object.values(age).map(b => b.female), backgroundColor: '#eb6834', borderRadius: 3 }
        ] },
        options: { maintainAspectRatio: false, plugins: { legend: { position: 'top', align: 'end', labels: { boxWidth: 10 } } },
            scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid } } }
    });
    const monthly = <?php echo json_encode($a['monthly']); ?>;
    const mEl = document.getElementById('rc-month');
    if (mEl) new Chart(mEl, {
        type: 'line',
        data: { labels: Object.keys(monthly).map(m => new Date(m + '-01T00:00:00').toLocaleDateString('en-US', { month: 'short', year: 'numeric' })),
            datasets: [{ label: 'Registered', data: Object.values(monthly), borderColor: '#2a78d6', backgroundColor: '#2a78d6', borderWidth: 2, pointRadius: 3, tension: 0 }] },
        options: { maintainAspectRatio: false, plugins: { legend: { display: false } },
            scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid } } }
    });
})();
</script>
<?php report_foot(); ?>

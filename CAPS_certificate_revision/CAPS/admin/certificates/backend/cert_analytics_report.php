<?php
/**
 * Certificate Analytics Report — opened from Certificate Analytics with ?from&to&mode=print|pdf&ai=1|0.
 *   ai=1: includes the AI analysis already generated on the page (never calls Gemini).
 *   ai=0: detailed report of every section, each with a written explanation.
 * Print and PDF use the same page. PDF file: certificate_analytics_<from>_to_<to>.pdf
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
$withAi = ($_GET['ai'] ?? '0') === '1';
$periodLabel = date('F j, Y', strtotime($p['from'])) . ' – ' . date('F j, Y', strtotime($p['to']));
$a = cert_analytics_compute($pdo, $p);
$t = $a['totals'];
$ai = $withAi ? ai_cache_get('certificates', cert_analytics_snapshot($a)) : null;

$brgy = report_barangay_profile($pdo);
$captain = report_current_captain($pdo);
$generatedBy = report_generated_by($pdo);
cert_log_activity('Certificate Analytics Report', ($mode === 'pdf' ? 'Saved PDF' : 'Printed') . ' report ' . $p['from'] . ' to ' . $p['to'] . ($ai ? ' with AI findings' : ''));

$pct = fn($n, $d) => $d ? round($n / $d * 100, 1) . '%' : '0%';
$none = '<p class="empty-note">None recorded in this period.</p>';
function cr_table(array $head, array $rows, array $center = []): void {
    if (!$rows) { echo '<p class="empty-note">None recorded in this period.</p>'; return; }
    echo '<table><thead><tr>';
    foreach ($head as $i => $hd) echo '<th' . (in_array($i, $center, true) ? ' style="text-align:center"' : '') . '>' . rh($hd) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach (array_values($r) as $i => $c) echo '<td' . (in_array($i, $center, true) ? ' style="text-align:center"' : '') . '>' . rh($c) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}
function cr_explain(string $text): void { echo '<p style="margin:0 0 10px;font-size:12px;line-height:1.55;color:#334155">' . rh($text) . '</p>'; }

$sumW = array_sum($a['series']['walkin']); $sumO = array_sum($a['series']['online']);
$peakIdx = null; $peakVal = 0;
foreach ($a['series']['labels'] as $i => $l) { $v = $a['series']['walkin'][$i] + $a['series']['online'][$i]; if ($v > $peakVal) { $peakVal = $v; $peakIdx = $i; } }

report_head('Certificate Analytics Report', $mode);
report_toolbar($mode, 'certificate_analytics_' . $p['from'] . '_to_' . $p['to']);
?>
<style>
    .ai-item{padding:6px 0;border-bottom:1px solid #e2e8f0;font-size:12px}
    .ai-item:last-child{border-bottom:0}
    .ai-pri{display:inline-block;padding:1px 6px;border-radius:99px;font-size:9px;font-weight:800;text-transform:uppercase;margin-left:6px}
    .pri-high{background:#fee2e2;color:#b91c1c}.pri-medium{background:#fef3c7;color:#b45309}.pri-low{background:#f1f5f9;color:#475569}
    tr{page-break-inside:avoid}
</style>
<div class="report-page" id="reportRoot">
    <?php report_letterhead($brgy, 'Office of the Punong Barangay'); ?>
    <?php report_title_block('Certificate Analytics Report', 'Certificate and clearance requests · ' . $periodLabel, 'Legal Documents'); ?>
    <?php report_stat_cards([
        ['value' => $t['total'], 'label' => 'Total Requests', 'variant' => 'a'],
        ['value' => $t['released'], 'label' => 'Released', 'variant' => 'c'],
        ['value' => $t['pending'] + $t['ready'], 'label' => 'Pending / Ready', 'variant' => 'b'],
        ['value' => $t['rejected'] + $t['expired'], 'label' => 'Rejected / Expired', 'variant' => 'd'],
    ]); ?>

    <?php if ($ai): ?>
        <?php report_section_open('AI Key Findings'); ?>
            <?php if ($ai['summary']): cr_explain($ai['summary']); endif; ?>
            <?php foreach ($ai['key_findings'] as $f): ?><div class="ai-item"><strong><?php echo rh($f['title']); ?></strong> — <?php echo rh($f['detail']); ?></div><?php endforeach; ?>
        <?php report_section_close(); ?>
        <?php report_section_open('AI Trends'); ?>
            <?php foreach ($ai['trends'] as $f): ?><div class="ai-item"><?php echo ['up' => '▲', 'down' => '▼', 'stable' => '■'][$f['direction']] ?? '■'; ?> <strong><?php echo rh($f['title']); ?></strong> — <?php echo rh($f['detail']); ?></div><?php endforeach; ?>
            <?php if (!$ai['trends']) echo $none; ?>
        <?php report_section_close(); ?>
        <?php report_section_open('AI Recommended Actions'); ?>
            <?php foreach ($ai['actions'] as $i => $f): ?><div class="ai-item"><?php echo $i + 1; ?>. <strong><?php echo rh($f['action']); ?></strong><span class="ai-pri pri-<?php echo rh($f['priority']); ?>"><?php echo rh($f['priority']); ?></span><br><span style="color:#64748b"><?php echo rh($f['reason']); ?></span></div><?php endforeach; ?>
            <p style="margin:8px 0 0;font-size:10px;color:#64748b">Generated by Gemini · <?php echo rh($ai['generated_at'] ?? ''); ?></p>
        <?php report_section_close(); ?>
    <?php endif; ?>

    <?php report_section_open('Summary'); ?>
        <?php cr_explain($t['total']
            ? "From {$periodLabel}, the barangay received {$t['total']} certificate request(s): {$t['walkin']} walk-in (" . $pct($t['walkin'], $t['total']) . ") and {$t['online']} online (" . $pct($t['online'], $t['total']) . "). "
              . "{$t['released']} were released, {$t['pending']} are waiting for review, {$t['ready']} are ready to pick up, {$t['rejected']} were rejected and {$t['expired']} expired unclaimed. "
              . ($t['avg_processing_min'] !== null ? 'On average a document was released ' . cert_fmt_duration($t['avg_processing_min']) . ' after it was requested.' : 'No document was released in this period yet.')
            : 'No certificate requests were recorded in this period.'); ?>
        <?php cr_table(['Measure', 'Value'], [
            ['Total requests', $t['total']], ['Walk-in / Online', $t['walkin'] . ' / ' . $t['online']], ['Released', $t['released']],
            ['Pending / Review', $t['pending']], ['Ready to Pick Up', $t['ready']], ['Rejected (rate of online)', $t['rejected'] . ' (' . ($t['rejection_rate'] ?? 0) . '%)'],
            ['Expired (unclaimed rate)', $t['expired'] . ' (' . ($t['unclaimed_rate'] ?? 0) . '%)'], ['Average processing (all / walk-in / online)', cert_fmt_duration($t['avg_processing_min']) . ' / ' . cert_fmt_duration($t['avg_walkin_min']) . ' / ' . cert_fmt_duration($t['avg_online_min'])],
            ['Most requested document', $t['top_document'] ? $t['top_document'] . ' (' . $t['top_document_n'] . ')' : '—'],
        ], [1]); ?>
    <?php report_section_close(); ?>

    <?php report_section_open('Requests Over Time'); ?>
        <?php cr_explain($sumW + $sumO ? 'Requests per ' . ($a['monthly'] ? 'month' : 'day') . ' (only ' . ($a['monthly'] ? 'months' : 'days') . ' with requests are listed). The busiest was ' . $a['series']['labels'][$peakIdx] . ' with ' . $peakVal . ' request(s).' : 'No requests in this period.'); ?>
        <?php $rows = []; foreach ($a['series']['labels'] as $i => $l) { if ($a['series']['walkin'][$i] + $a['series']['online'][$i]) $rows[] = [$l, $a['series']['walkin'][$i], $a['series']['online'][$i], $a['series']['walkin'][$i] + $a['series']['online'][$i]]; }
        cr_table([$a['monthly'] ? 'Month' : 'Date', 'Walk-in', 'Online', 'Total'], $rows, [1, 2, 3]); ?>
    <?php report_section_close(); ?>

    <?php report_section_open('By Document Type'); ?>
        <?php cr_explain($a['by_document'] ? $a['by_document'][0]['label'] . ' was the most requested document with ' . $a['by_document'][0]['n'] . ' request(s) (' . $pct($a['by_document'][0]['n'], $t['total']) . ' of all requests).' : 'No requests in this period.'); ?>
        <?php cr_table(['Document', 'Requests', 'Online', 'Released', 'Avg processing'], array_map(fn($d) => [$d['label'], $d['n'], $d['online'], $d['released'], $d['avg']], $a['by_document']), [1, 2, 3, 4]); ?>
    <?php report_section_close(); ?>

    <?php report_section_open('By Purpose'); ?>
        <?php cr_explain($a['by_purpose'] ? 'The most common purpose was "' . $a['by_purpose'][0]['label'] . '" (' . $a['by_purpose'][0]['n'] . ').' : 'No requests in this period.'); ?>
        <?php cr_table(['Purpose', 'Requests', 'Share'], array_map(fn($d) => [$d['label'], $d['n'], $pct($d['n'], $t['total'])], $a['by_purpose']), [1, 2]); ?>
    <?php report_section_close(); ?>

    <?php report_section_open('Status Breakdown'); ?>
        <?php cr_explain('Current status of the requests made in this period. Online documents that are not picked up within ' . CERT_PICKUP_DAYS . ' days of approval become Expired.'); ?>
        <?php cr_table(['Status', 'Requests', 'Share'], array_map(fn($d) => [$d['label'], $d['n'], $pct($d['n'], $t['total'])], $a['by_status']), [1, 2]); ?>
    <?php report_section_close(); ?>

    <?php report_section_open('Rejection Reasons'); ?>
        <?php cr_explain($t['rejected'] ? $t['rejected'] . ' online request(s) were rejected. ' . ($a['rejections']['labels'] ? 'Most common reason: ' . $a['rejections']['labels'][0] . '.' : '') : 'No requests were rejected in this period.'); ?>
        <?php $rows = []; foreach ($a['rejections']['labels'] as $i => $l) $rows[] = [$l, $a['rejections']['data'][$i]];
        foreach ($a['rejections']['others'] as $txt => $n) $rows[] = ['  • Others: ' . $txt, $n];
        cr_table(['Reason', 'Count'], $rows, [1]); ?>
    <?php report_section_close(); ?>

    <?php report_section_open('Busiest Day and Hour'); ?>
        <?php cr_explain($a['weekday']['busiest'] ? 'Most requests came on ' . $a['weekday']['busiest'] . 's, and the busiest hour was ' . $a['hours']['busiest'] . '.' : 'No requests in this period.'); ?>
        <?php $rows = []; foreach ($a['weekday']['labels'] as $i => $l) $rows[] = [$l, $a['weekday']['data'][$i]];
        $hrs = []; foreach ($a['hours']['data'] as $h => $n) if ($n) $hrs[] = $a['hours']['labels'][$h] . ' (' . $n . ')';
        cr_table(['Weekday', 'Requests'], $rows, [1]); ?>
        <?php if ($hrs): ?><p style="margin:8px 0 0;font-size:11.5px;color:#334155"><strong>By hour:</strong> <?php echo rh(implode(' · ', $hrs)); ?></p><?php endif; ?>
    <?php report_section_close(); ?>

    <?php report_section_open('Top Puroks'); ?>
        <?php cr_explain($a['puroks'] ? $a['puroks'][0]['label'] . ' had the most requests (' . $a['puroks'][0]['n'] . ').' : 'No requests in this period.'); ?>
        <?php cr_table(['Purok', 'Requests', 'Share'], array_map(fn($d) => [$d['label'], $d['n'], $pct($d['n'], $t['total'])], $a['puroks']), [1, 2]); ?>
    <?php report_section_close(); ?>

    <?php report_section_open('Processing Time'); ?>
        <?php cr_explain($t['released_in_period'] ? $t['released_in_period'] . ' document(s) were released in this period. Average time from request to release: ' . cert_fmt_duration($t['avg_processing_min']) . ' (walk-in ' . cert_fmt_duration($t['avg_walkin_min']) . ', online ' . cert_fmt_duration($t['avg_online_min']) . ').' : 'No document was released in this period.'); ?>
        <?php $rows = []; foreach ($a['series']['labels'] as $i => $l) if ($a['series']['processing_hours'][$i] !== null) $rows[] = [$l, $a['series']['processing_hours'][$i] . ' h'];
        cr_table([$a['monthly'] ? 'Month' : 'Release date', 'Average hours to release'], $rows, [1]); ?>
    <?php report_section_close(); ?>

    <?php report_section_open('Recent Releases'); ?>
        <?php cr_explain($a['recent_releases'] ? 'The latest ' . count($a['recent_releases']) . ' document(s) released in this period.' : 'No document was released in this period.'); ?>
        <?php cr_table(['Doc No.', 'Resident', 'Document', 'Released', 'Took'], array_map(fn($r) => [$r['doc_number'], $r['resident'] . ' (' . $r['code'] . ')', $r['doc_type'], $r['released'] . ' · ' . $r['by'], $r['took']], $a['recent_releases'])); ?>
    <?php report_section_close(); ?>

    <?php report_section_open('Expired / Unclaimed'); ?>
        <?php cr_explain($a['unclaimed'] ? count($a['unclaimed']) . ' accepted online document(s) from this period are not picked up yet or already expired. Residents with expired documents are warned when they request again.' : 'Every accepted online document from this period was picked up.'); ?>
        <?php cr_table(['Doc / Ref No.', 'Resident', 'Document', 'Approved', 'Pick up by', 'Status'], array_map(fn($u) => [$u['doc_number'], $u['resident'] . ' (' . $u['code'] . ')', $u['doc_type'], $u['approved'], $u['deadline'], $u['status']], $a['unclaimed'])); ?>
    <?php report_section_close(); ?>

    <?php report_certify_and_signatures($brgy['brgy_name'] ?? 'the Barangay', $generatedBy, ($_SESSION['role'] ?? '') === 'admin' ? 'Administrator' : 'Barangay Staff', $captain); ?>
    <?php report_footer_strip($brgy['brgy_name'] ?? 'Barangay'); ?>
</div>
<?php report_foot(); ?>

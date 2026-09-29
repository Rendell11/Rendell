<?php
/**
 * Household Record — opened from View Household (Save PDF / Print) with
 * ?sid=N&mode=pdf|print&survey=1|0. Same data as View Household
 * (hh_household_view_data) and the same shared CAPS report layout
 * (officials report_common.php) as the Resident Profile Record.
 *   survey=0 → household record only
 *   survey=1 → household record + household survey answers
 */
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../permission_helper.php';
require_once __DIR__ . '/../../officials/backend/report_common.php';
require_once __DIR__ . '/household_common.php';
require_permission($pdo, 'households', 'read');

$sid = (int) ($_GET['sid'] ?? 0);
$st = $pdo->prepare('SELECT r.*, hs.* FROM household_survey hs LEFT JOIN residents r ON r.ResidentID = hs.ResidentID WHERE hs.SurveyID = ? LIMIT 1');
$st->execute([$sid]);
$row = $sid > 0 ? $st->fetch(PDO::FETCH_ASSOC) : false;
if (!$row) { http_response_code(404); exit('Household not found.'); }

$D = hh_household_view_data($pdo, $row);
$hh = $D['hh'];
$withSurvey = ($_GET['survey'] ?? '0') === '1';

$mode = report_mode();
$brgy = report_barangay_profile($pdo);
$captain = report_current_captain($pdo);
$generatedBy = report_generated_by($pdo);
$generatedRole = ($_SESSION['role'] ?? '') === 'admin' ? 'Administrator' : 'Barangay Staff';
$bAddr = trim((string) ($brgy['address'] ?? '')) ?: implode(', ', array_filter([
    $brgy['barangay_name'] ?? '', $brgy['municipality_name'] ?? '', $brgy['province_name'] ?? '',
], fn($x) => trim((string) $x) !== ''));

$dash = fn($v) => (trim((string) $v) !== '') ? (string) $v : '—';
$d = fn($v, string $f = 'F j, Y') => ($v && !str_starts_with((string) $v, '0000') && strtotime((string) $v)) ? date($f, strtotime((string) $v)) : '—';
$peso = fn($v) => '₱' . number_format((float) $v, 2);
$hhId = (string) ($hh['HouseholdID'] ?? '');
$isHeadless = !$D['isInactive'] && (empty($hh['ResidentID']) || (int) ($hh['IsHead'] ?? 0) !== 1);

function hr_kv(array $rows): void {
    echo '<table class="kv"><tbody>';
    foreach ($rows as $row) echo '<tr><th>' . rh($row[0]) . '</th><td>' . nl2br(rh($row[1])) . '</td></tr>';
    echo '</tbody></table>';
}
function hr_table(array $heads, array $rows, string $empty, array $right = []): void {
    if (!$rows) { echo '<p class="empty-note">' . rh($empty) . '</p>'; return; }
    echo '<table><thead><tr>';
    foreach ($heads as $i => $h) echo '<th' . (in_array($i, $right, true) ? ' class="num"' : '') . '>' . rh($h) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach (array_values($row) as $i => $c) echo '<td' . (in_array($i, $right, true) ? ' class="num"' : '') . '>' . rh($c) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

if ($mode !== '') {
    try {
        require_once __DIR__ . '/../../activity_log_helper.php';
        log_activity('Households', $mode === 'pdf' ? 'Save Household PDF' : 'Print Household',
            ($mode === 'pdf' ? 'Saved PDF of ' : 'Printed ') . "household record: $hhId · " . ($D['name'] ?: 'no Head') . ($withSurvey ? ' · with survey' : ''));
    } catch (Throwable $e) {
        error_log('[household_record_report] activity log: ' . $e->getMessage());
    }
}

$title = 'Household Record';
report_head($title . ' ' . $hhId, $mode);
report_toolbar($mode, 'household_' . preg_replace('/[^A-Za-z0-9\-]/', '', $hhId) . '_' . date('Ymd'));
?>
<style>
    .brgy-addr{text-align:center;font-size:11px;color:#64748b;margin:-12px 0 14px}
    table.kv th{width:32%;background:transparent;font-size:10.5px;color:#64748b;vertical-align:top}
    table.kv td{font-weight:600}
    td.num,th.num{text-align:right;font-variant-numeric:tabular-nums}
    .note{margin:10px 0 0;font-size:10.5px;line-height:1.5;color:#64748b}
    .section-wrap{page-break-inside:auto;break-inside:auto}
    tr,.stat-grid,.signature-block,.certify-line{page-break-inside:avoid;break-inside:avoid}
    h2.section{page-break-after:avoid;break-after:avoid}
</style>
<div class="report-page" id="reportRoot">
    <?php report_letterhead($brgy, 'Office of the Punong Barangay · Household Records'); ?>
    <?php if ($bAddr): ?><p class="brgy-addr"><?php echo rh($bAddr); ?></p><?php endif; ?>
    <?php report_title_block($title, 'Household ID ' . $hhId . ' · ' . ($D['name'] ?: 'No Head'), 'Household Management'); ?>

    <?php report_section_open('Household Information');
    $regDate = $hh['DateCreated'] ?? $hh['CreatedAt'] ?? null;
    $info = [
        ['Household ID', $dash($hhId)],
        ['Status', ucfirst($D['status'])],
        ['Household Head', $isHeadless ? 'No Head assigned' : $dash($D['name'])],
        ['Number of household members', $D['memberCount'] . ' (Head + ' . count($D['members']) . ' member' . (count($D['members']) === 1 ? '' : 's') . ')'],
        ['Complete address', $dash($D['address'])],
        ['Map coordinates', $D['hhLocation'] ? number_format($D['hhLocation']['lat'], 7) . ', ' . number_format($D['hhLocation']['lng'], 7) . ' (' . $D['hhLocation']['source'] . ')' : 'Not pinned'],
        ['Registered', $d($regDate)],
    ];
    if ($D['isInactive']) {
        $info[] = ['Inactive since', $d($hh['inactive_since'] ?? $hh['removed_at'] ?? null) . ' · By: ' . $dash($hh['inactive_by'] ?? 'System')];
        $info[] = ['Reason', $dash((($hh['removal_reason'] ?? '') === 'Other') ? ($hh['removal_reason_other'] ?? 'Other') : ($hh['removal_reason'] ?? ''))];
    }
    hr_kv($info);
    report_section_close(); ?>

    <?php report_section_open('Household Head');
    if ($isHeadless) {
        echo '<p class="empty-note">This household has no Head.</p>';
    } else {
        $tags = implode(', ', array_filter([
            !empty($hh['IsSenior']) ? 'Senior Citizen' : '', !empty($hh['IsPWD']) ? 'PWD' : '',
            !empty($hh['IsSoloParent']) ? 'Solo Parent' : '', !empty($hh['IsVoter']) ? 'Registered Voter' : '',
        ]));
        hr_kv([
            ['Name', $dash($D['name'])],
            ['Resident ID', !empty($hh['ResidentCode']) ? report_full_resident_code($hh['ResidentCode'], $D['headId']) : '#' . $D['headId']],
            ['Sex', $dash($hh['Sex'] ?? '')],
            ['Date of birth', $d($hh['BirthDate'] ?? null) . ($D['age'] !== '—' ? ' (' . $D['age'] . ' yrs)' : '')],
            ['Place of birth', $dash($hh['BirthPlace'] ?? '')],
            ['Civil status', $dash($hh['CivilStatus'] ?? '')],
            ['Contact number', $dash($hh['ContactNumber'] ?? '')],
            ['Email', $dash($hh['Email'] ?? '')],
            ['Religion', $dash($hh['Religion'] ?? '')],
            ['Nationality', $dash($hh['Nationality'] ?? '')],
            ['Educational attainment', $dash($hh['EducationLevel'] ?? '')],
            ['Employment status', $dash((($hh['EmploymentStatus'] ?? '') === 'Other' && !empty($hh['EmploymentStatusOther'])) ? $hh['EmploymentStatusOther'] : ($hh['EmploymentStatus'] ?? ''))],
            ['Occupation', $dash($hh['Occupation'] ?? '')],
            ["Head's own monthly income", $peso($D['headIncome'])],
            ['Classification', $dash($tags)],
        ]);
    }
    report_section_close(); ?>

    <?php report_section_open('Household Socio-Economic Profile');
    report_stat_cards([
        ['value' => $peso($D['income']), 'label' => 'Combined Monthly Income', 'variant' => 'a'],
        ['value' => $D['classification'], 'label' => 'Income Status', 'variant' => 'b'],
        ['value' => $D['ses']['label'] ?? '—', 'label' => 'Socioeconomic Status', 'variant' => 'c'],
        ['value' => $peso($D['ses']['per_capita'] ?? 0), 'label' => 'Income per Member', 'variant' => 'b'],
    ]);
    echo '<div style="margin-top:12px"></div>';
    hr_kv([
        ['Members with recorded income', $D['withIncome'] . ' of ' . $D['memberCount']],
        ['Income per member vs poverty line', number_format((float) ($D['ses']['ratio'] ?? 0), 2) . '× the poverty threshold of ' . $peso($D['ses']['poverty_line'] ?? 0) . ' per person per month'],
        ['House type', $dash($hh['house_type'] ?? '') === '—' ? 'Not recorded in survey' : $hh['house_type']],
        ['Tenure status', $dash($hh['tenure_status'] ?? $hh['housing_tenure'] ?? '') === '—' ? 'Not recorded in survey' : ($hh['tenure_status'] ?? $hh['housing_tenure'])],
    ]); ?>
    <p class="note">Socioeconomic Status = (combined monthly income ÷ number of members) ÷ poverty threshold. Below 1× Poor · 1–2× Low Income · 2–4× Lower Middle · 4–7× Middle · 7–12× Upper Middle · 12–20× Upper Income · 20× and above Rich (PIDS income classes).</p>
    <?php report_section_close(); ?>

    <?php report_section_open('Household Members (' . $D['memberCount'] . ')');
    $mrows = [];
    if (!$isHeadless) {
        $mrows[] = ['Head', $dash($D['name']), $dash($hh['Sex'] ?? ''), $D['age'] === '—' ? '—' : (string) $D['age'], 'Household Head', $dash($hh['ContactNumber'] ?? ''), $peso($D['headIncome'])];
    }
    foreach ($D['members'] as $i => $m) {
        $mAge = '—';
        if (!empty($m['BirthDate'])) {
            try { $mAge = (string) (new DateTime((string) $m['BirthDate']))->diff(new DateTime())->y; } catch (Throwable $e) { $mAge = '—'; }
        }
        $mrows[] = [(string) ($i + 1), $dash(hh_full_name($m) ?: ($m['head_name'] ?? '')), $dash($m['Sex'] ?? ''), $mAge,
            $dash($m['RelationshipToHead'] ?? 'Member'), $dash($m['ContactNumber'] ?? ''), $peso($m['TotalHouseholdIncome'] ?? 0)];
    }
    hr_table(['#', 'Name', 'Sex', 'Age', 'Relationship to Head', 'Contact', 'Monthly Income'], $mrows, 'No household members linked.', [3, 6]);
    report_section_close(); ?>

    <?php report_section_open('Household Timeline (' . count($D['history']) . ')');
    hr_table(['Date', 'Action', 'Details', 'By'], array_map(fn($x) => [$d($x['CreatedAt'] ?? null, 'M j, Y g:i A'), $dash($x['ActionType'] ?? 'Household Update'), $dash($x['Description'] ?? ''), $dash($x['ActorName'] ?? 'System')], $D['history']),
        'No recorded household changes.');
    report_section_close(); ?>

    <?php if ($withSurvey): report_section_open('Household Survey');
        if ($D['surveyAnswers']) {
            hr_kv(array_map(fn($k, $v) => [$k, $v], array_keys($D['surveyAnswers']), array_values($D['surveyAnswers'])));
        } else {
            echo '<p class="empty-note">No survey response available yet.</p>';
        }
    report_section_close(); endif; ?>

    <?php report_certify_and_signatures($brgy['brgy_name'] ?? 'Barangay', $generatedBy, $generatedRole, $captain); ?>
    <?php report_footer_strip($brgy['brgy_name'] ?? 'Barangay'); ?>
</div>
<?php report_foot(); ?>

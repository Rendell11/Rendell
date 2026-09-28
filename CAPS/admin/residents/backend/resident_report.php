<?php
/**
 * Resident Profile Record — opened from Residents → View with ?id=N&mode=print|pdf.
 * Print → Print Preview; Save PDF → PDF download. Same report layout as the Blotter
 * complaint record and the other CAPS prints (officials report_common.php):
 * letterhead (logo + barangay from the database), title, sections, certification
 * with the current Barangay Captain, footer strip.
 * Data comes from resident_view.php (the same data the View modal shows).
 */
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../permission_helper.php';
require_once __DIR__ . '/../../officials/backend/report_common.php';
require_permission($pdo, 'residents', 'read');

$rid = (int)($_GET['id'] ?? 0);
$exists = $pdo->prepare("SELECT COUNT(*) FROM residents WHERE ResidentID = ?");
$exists->execute([$rid]);
if ($rid <= 0 || !(int)$exists->fetchColumn()) { http_response_code(404); exit('Resident not found.'); }

define('RV_AS_DATA', true);
$D = require __DIR__ . '/resident_view.php';
$r = $D['resident'];
$hh = $D['household'];

$mode = report_mode();
$brgy = report_barangay_profile($pdo);
$captain = report_current_captain($pdo);
$generatedBy = report_generated_by($pdo);
$generatedRole = ($_SESSION['role'] ?? '') === 'admin' ? 'Administrator' : 'Barangay Staff';
$bAddr = trim((string)($brgy['address'] ?? '')) ?: implode(', ', array_filter([
    $brgy['barangay_name'] ?? '', $brgy['municipality_name'] ?? '', $brgy['province_name'] ?? '',
], fn($x) => trim((string)$x) !== ''));

$yes = fn($v) => (int)$v === 1;
$dash = fn($v) => (trim((string)$v) !== '') ? (string)$v : '—';
$d = fn($v, string $f = 'F j, Y') => ($v && !str_starts_with((string)$v, '0000') && strtotime((string)$v)) ? date($f, strtotime((string)$v)) : '—';
$other = fn($v, $o) => ($v === 'Other' || $v === 'Others') ? ($o ?: $v) : $v;
$fullName = trim(implode(' ', array_filter([$r['FirstName'] ?? '', $r['MiddleName'] ?? '', $r['LastName'] ?? '', $r['Suffix'] ?? ''])));
$code = report_full_resident_code($r['ResidentCode'] ?? '', $r['ResidentID']);
$age = '';
if (!empty($r['BirthDate']) && !str_starts_with((string)$r['BirthDate'], '0000')) {
    try { $age = (string)(new DateTime((string)$r['BirthDate']))->diff(new DateTime())->y; } catch (Throwable $e) { $age = ''; }
}
$address = implode(', ', array_filter([$r['HouseNumber'] ?? '', $r['BuildingName'] ?? '', $r['StreetName'] ?? '',
    ($r['AreaName'] ?? '') ?: ($r['Purok'] ?? ''), $r['BarangayName'] ?? '', $r['CityMunicipalityName'] ?? '',
    $r['ProvinceName'] ?? '', $r['RegionName'] ?? '', $r['ZipCode'] ?? ''], fn($x) => trim((string)$x) !== ''));
$programs = implode(', ', array_map(fn($x) => $x[0], array_filter([
    ['PhilHealth', $r['HasPhilhealth'] ?? 0], ['SSS/GSIS', $r['HasSSSGSIS'] ?? 0], ['4Ps', $r['Has4Ps'] ?? 0],
    ['SSS', $r['IsSSSMember'] ?? 0], ['GSIS', $r['IsGSISMember'] ?? 0], ['Pag-IBIG', $r['IsPagibigMember'] ?? 0],
], fn($x) => (int)$x[1] === 1)));
$classes = implode(', ', array_filter([
    $yes($r['IsHead'] ?? 0) ? 'Household Head' : (!empty($r['FamilyHeadID']) ? 'Household Member' : ''),
    $yes($r['IsSenior'] ?? 0) ? 'Senior Citizen' : '', $yes($r['IsPWD'] ?? 0) ? 'PWD' : '',
    $yes($r['IsSoloParent'] ?? 0) ? 'Solo Parent' : '', $yes($r['IsVoter'] ?? 0) ? 'Registered Voter' : '',
    $yes($r['IsDeceased'] ?? 0) ? 'Deceased' : '',
]));

function rr_kv(array $rows): void {
    echo '<table class="kv"><tbody>';
    foreach ($rows as $row) echo '<tr><th>' . rh($row[0]) . '</th><td>' . nl2br(rh($row[1])) . '</td></tr>';
    echo '</tbody></table>';
}
function rr_table(array $heads, array $rows, string $empty): void {
    if (!$rows) { echo '<p class="empty-note">' . rh($empty) . '</p>'; return; }
    echo '<table><thead><tr>';
    foreach ($heads as $h) echo '<th>' . rh($h) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $c) echo '<td>' . rh($c) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

if ($mode !== '') {
    try {
        require_once __DIR__ . '/../../activity_log_helper.php';
        log_activity('Residents', $mode === 'pdf' ? 'Save Resident PDF' : 'Print Resident',
            ($mode === 'pdf' ? 'Saved PDF of ' : 'Printed ') . "resident record: $fullName ($code)");
    } catch (Throwable $e) {
        error_log('[resident_report] activity log: ' . $e->getMessage());
    }
}

$title = 'Resident Profile Record';
report_head($title . ' ' . $code, $mode);
$fileBase = 'resident_' . preg_replace('/[^A-Za-z0-9\-]/', '', $code) . '_' . date('Ymd');
report_toolbar($mode, $fileBase);
?>
<style>
    .brgy-addr{text-align:center;font-size:11px;color:#64748b;margin:-12px 0 14px}
    table.kv th{width:30%;background:transparent;font-size:10.5px;color:#64748b;vertical-align:top}
    table.kv td{font-weight:600}
    .sub-head{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#0d9488;margin:12px 0 2px}
    .section-wrap,tr,.sig,.certify-line{page-break-inside:avoid;break-inside:avoid}
</style>
<div class="report-page" id="reportRoot">
    <?php report_letterhead($brgy, 'Office of the Punong Barangay · Resident Records'); ?>
    <?php if ($bAddr): ?><p class="brgy-addr"><?php echo rh($bAddr); ?></p><?php endif; ?>
    <?php report_title_block($title, 'Resident ID ' . $code . ' · ' . $fullName, 'Resident Profiling'); ?>

    <?php report_section_open('Personal Information');
    rr_kv([
        ['Resident ID', $code],
        ['Full name', $dash($fullName)],
        ['Sex', $dash($r['Sex'] ?? '')],
        ['Date of birth', $d($r['BirthDate'] ?? null) . ($age !== '' ? " ($age yrs)" : '')],
        ['Place of birth', $dash($r['BirthPlace'] ?? '')],
        ['Civil status', $dash($r['CivilStatus'] ?? '')],
        ['Religion', $dash($r['Religion'] ?? '')],
        ['Nationality', $dash($r['Nationality'] ?? '')],
        ['Contact number', $dash($r['ContactNumber'] ?? '')],
        ['Email', $dash($r['Email'] ?? '')],
        ['Classification', $dash($classes)],
        ['Registered', $d($r['CreatedAt'] ?? null)],
    ]);
    report_section_close(); ?>

    <?php report_section_open('Address');
    rr_kv([
        ['Complete address', $dash($address)],
        ['Subdivision / Village / Sitio / Purok', $dash(($r['AreaName'] ?? '') ?: ($r['Purok'] ?? ''))],
        ['Map coordinates', (!empty($r['Latitude']) && !empty($r['Longitude'])) ? $r['Latitude'] . ', ' . $r['Longitude'] : '—'],
    ]);
    report_section_close(); ?>

    <?php report_section_open('Household');
    if ($hh) {
        rr_kv([
            ['Household ID', $dash($hh['household_id'])],
            ['Household Head', $hh['head_name'] . ($hh['head_code'] ? ' (' . $hh['head_code'] . ')' : '')],
            ['Role in household', $hh['is_head'] ? 'Household Head' : $dash($r['RelationshipToHead'] ?? 'Member')],
            ['Household address', $dash($hh['address'])],
        ]);
        echo '<p class="sub-head">Household Members (' . count($hh['members']) . ')</p>';
        rr_table(['#', 'Name', 'Relationship to Head'],
            array_map(fn($m, $i) => [(string)($i + 1), $m['name'], $m['relationship']], $hh['members'], array_keys($hh['members'])),
            'No household members linked.');
    } else {
        echo '<p class="empty-note">This resident is not linked to any household.</p>';
    }
    report_section_close(); ?>

    <?php report_section_open('Socio-Economic Profile');
    rr_kv([
        ['Education', $dash($r['EducationLevel'] ?? '')],
        ['Employment status', $dash($other($r['EmploymentStatus'] ?? '', $r['EmploymentStatusOther'] ?? ''))],
        ['Occupation', $dash($r['Occupation'] ?? '')],
        ['Source of income', $dash($other($r['SourceOfIncome'] ?? '', $r['SourceOfIncomeOther'] ?? ''))],
        ['Monthly income', ($r['TotalHouseholdIncome'] ?? '') !== '' && $r['TotalHouseholdIncome'] !== null ? 'PHP ' . number_format((float)$r['TotalHouseholdIncome'], 2) : '—'],
    ]);
    report_section_close(); ?>

    <?php report_section_open('Government & Social Programs');
    $welfare = [
        ['Registered voter', $yes($r['IsVoter'] ?? 0) ? 'Yes' . (!empty($r['VoterNumber']) ? ' · ' . $r['VoterNumber'] : '') : 'No'],
        ['Memberships / programs', $dash($programs)],
        ['Senior citizen', $yes($r['IsSenior'] ?? 0) ? 'Yes' : 'No'],
        ['Solo parent', $yes($r['IsSoloParent'] ?? 0) ? 'Yes' : 'No'],
        ['PWD', $yes($r['IsPWD'] ?? 0) ? 'Yes' : 'No'],
    ];
    if ($yes($r['IsPWD'] ?? 0)) {
        $welfare[] = ['PWD classification', $dash($r['PWDClassification'] ?? '')];
        $welfare[] = ['PWD ID', $dash($r['PWDID'] ?? '')];
    }
    rr_kv($welfare);
    report_section_close(); ?>

    <?php if ($yes($r['IsDeceased'] ?? 0)): report_section_open('Deceased Record');
    rr_kv([
        ['Date of death', $d($r['DateOfDeath'] ?? null)],
        ['Place of death', $dash($r['PlaceOfDeath'] ?? '')],
        ['Cause of death', $dash($r['CauseOfDeath'] ?? '')],
        ['Remarks', $dash($r['DeceasedRemarks'] ?? '')],
        ['Date reported', $d($r['DeathDateReported'] ?? null, 'F j, Y g:i A')],
        ['Reported by', $dash($r['DeathReportedBy'] ?? '')],
    ]);
    report_section_close(); endif; ?>

    <?php report_section_open('Blotter Cases (' . count($D['blotter']) . ')');
    rr_table(['Blotter ID', 'Role', 'Case Type', 'Other Party', 'Incident', 'Filed', 'Status'],
        array_map(fn($x) => [$x['case_number'], $x['role'], $x['type'], $x['other_party'], $x['incident'] ?: '—', $x['filed'] ?: '—', $x['status']], $D['blotter']),
        'No blotter case on record.');
    report_section_close(); ?>

    <?php report_section_open('Complaints (' . count($D['complaints']) . ')');
    rr_table(['Complaint ID', 'Title', 'Category', 'Priority', 'Filed', 'Status'],
        array_map(fn($x) => [$x['id'], $x['title'] . ($x['anonymous'] ? ' (anonymous)' : ''), $x['category'], $x['priority'], $x['filed'] ?: '—', $x['status']], $D['complaints']),
        'No complaint on record.');
    report_section_close(); ?>

    <?php report_section_open('Legal Document Requests (' . count($D['documents']) . ')');
    rr_table(['Reference', 'Document', 'Purpose', 'Type', 'Requested', 'Released', 'Status'],
        array_map(fn($x) => [$x['reference'] . ($x['doc_number'] ? ' / ' . $x['doc_number'] : ''), $x['type'], $x['purpose'] ?: '—', $x['request_type'] ?: '—', $x['requested'] ?: '—', $x['released'] ?: '—', $x['status']], $D['documents']),
        'No document request on record.');
    report_section_close(); ?>

    <?php report_section_open('Official / Staff History (' . count($D['service']) . ')');
    rr_table(['Role', 'Position', 'Start', 'End', 'Status', 'Remarks'],
        array_map(fn($x) => [$x['kind'], $x['position'], $x['start'] ?: '—', $x['end'] ?: ($x['status'] === 'Current' ? 'Present' : '—'), $x['status'], $x['reason'] ?: ''], $D['service']),
        'Never served as an official or staff.');
    report_section_close(); ?>

    <?php report_certify_and_signatures($brgy['brgy_name'] ?? 'Barangay', $generatedBy, $generatedRole, $captain); ?>
    <?php report_footer_strip($brgy['brgy_name'] ?? 'Barangay'); ?>
</div>
<?php report_foot(); ?>

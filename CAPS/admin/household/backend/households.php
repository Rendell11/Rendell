<?php
declare(strict_types=1);

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../permission_helper.php';
require_once __DIR__ . '/../../theme_loader.php';
require_once __DIR__ . '/household_common.php';

require_permission($pdo, 'households', 'read');
hh_ensure_schema($pdo);
hh_migrate_legacy_ids($pdo);

function hh_name(array $r): string {
    return trim(implode(' ', array_filter([
        $r['FirstName'] ?? '',
        $r['MiddleName'] ?? '',
        $r['LastName'] ?? '',
        $r['Suffix'] ?? ''
    ], static fn($v) => trim((string)$v) !== '')));
}

function hh_address(array $r): string {
    $parts = [
        $r['HouseNumber'] ?? '',
        $r['BuildingName'] ?? '',
        $r['StreetName'] ?? '',
        $r['AreaName'] ?? '',
        $r['Purok'] ?? '',
        $r['BarangayName'] ?? '',
        $r['CityMunicipalityName'] ?? '',
        $r['ProvinceName'] ?? '',
        $r['RegionName'] ?? '',
        $r['ZipCode'] ?? ''
    ];
    // Purok is often the same value as AreaName; show it once.
    if (trim((string)($r['Purok'] ?? '')) !== '' && strcasecmp(trim((string)$r['Purok']), trim((string)($r['AreaName'] ?? ''))) === 0) {
        $parts[4] = '';
    }
    $joined = implode(', ', array_values(array_filter(array_map('trim', $parts), static fn($v) => $v !== '')));
    return $joined !== '' ? $joined : trim((string)($r['address'] ?? ''));
}

function ensure_household_tables(PDO $pdo): void {
    // Non-destructive compatibility check. The actual migration is supplied
    // separately in CAPS/database/household_migration.sql.
    $pdo->query("SELECT 1 FROM household_survey LIMIT 1");
    // Heads created in the Resident module get a real HH-YYYY-NNNN record.
    hh_ensure_missing_household_records($pdo);
}

try {
    ensure_household_tables($pdo);
} catch (Throwable $e) {
    // Keep the module usable for resident-derived households even before the
    // optional survey tables are migrated.
}

$action = $_GET['action'] ?? '';

if ($action === 'locations') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $q = $pdo->query("
            SELECT
                r.ResidentID, r.FirstName, r.LastName, r.HouseNumber,
                r.StreetName, r.Purok, r.AreaName, r.BarangayName,
                r.TotalHouseholdIncome, r.Latitude, r.Longitude,
                COALESCE((
                    SELECT COUNT(*)
                    FROM residents m
                    WHERE m.FamilyHeadID = r.ResidentID
                      AND (m.IsDeceased = 0 OR m.IsDeceased IS NULL)
                ), 0) AS MemberCount
            FROM residents r
            WHERE r.IsHead = 1
              AND (r.IsDeceased = 0 OR r.IsDeceased IS NULL)
              AND r.Latitude IS NOT NULL AND r.Longitude IS NOT NULL
              AND r.Latitude <> 0 AND r.Longitude <> 0
            ORDER BY r.ResidentID
        ");
        echo json_encode($q->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Unable to load household locations.']);
        error_log('[CAPS-HOUSEHOLD] locations: ' . $e->getMessage());
    }
    exit;
}

// HH_LIST_ALL: the Master List report reuses these exact filters without paging.
$perPage = defined('HH_LIST_ALL') ? 1000000 : 10;
$page = defined('HH_LIST_ALL') ? 1 : max(1, (int)($_GET['page'] ?? 1));
$search = trim((string)($_GET['search'] ?? ''));
$incomeClass = trim((string)($_GET['income_class'] ?? ''));
$statusFilter = ($_GET['status'] ?? '') === 'inactive' ? 'inactive' : 'active';

if ($statusFilter === 'inactive') {
    // Deactivated households stay in household_survey for history.
    $where = [
        "(COALESCE(hs.status, 'active') = 'inactive' OR COALESCE(hs.is_removed, 0) = 1)"
    ];
} else {
    $where = [
        "r.IsHead = 1",
        "(r.IsDeceased = 0 OR r.IsDeceased IS NULL)"
    ];
}
$params = [];

$combinedSql = hh_combined_income_sql('r');

if ($search !== '') {
    // Matches the Household Head, any living Household Member, contact, address
    // fields and the Household ID. Rows are still one per household (the Head).
    $memberMatch = "EXISTS (
            SELECT 1 FROM residents sm
            WHERE sm.FamilyHeadID = r.ResidentID
              AND (sm.IsDeceased = 0 OR sm.IsDeceased IS NULL)
              AND (CONCAT_WS(' ', sm.FirstName, sm.MiddleName, sm.LastName, sm.Suffix) LIKE :search_member
                   OR CONCAT_WS(' ', sm.FirstName, sm.LastName) LIKE :search_member2
                   OR sm.ContactNumber LIKE :search_member_contact
                   OR sm.ResidentCode LIKE :search_member_code)
        )";
    $where[] = "(
        CONCAT_WS(' ', r.FirstName, r.MiddleName, r.LastName, r.Suffix) LIKE :search_name OR
        CONCAT_WS(' ', r.FirstName, r.LastName) LIKE :search_name2 OR
        r.ResidentCode LIKE :search_code OR
        r.ContactNumber LIKE :search_contact OR
        r.Email LIKE :search_email OR
        r.HouseNumber LIKE :search_house OR
        r.BuildingName LIKE :search_building OR
        r.StreetName LIKE :search_street OR
        r.Purok LIKE :search_purok OR
        r.AreaName LIKE :search_area OR
        CONCAT_WS(' ', r.HouseNumber, r.StreetName) LIKE :search_addr OR
        EXISTS (SELECT 1 FROM household_survey hsx WHERE hsx.ResidentID = r.ResidentID AND (hsx.HouseholdID LIKE :search_hhid OR hsx.head_name LIKE :search_hhname OR hsx.address LIKE :search_hhaddr)) OR
        EXISTS (SELECT 1 FROM household_survey hsm JOIN household_survey_members hm ON hm.SurveyID = hsm.SurveyID
                WHERE hsm.ResidentID = r.ResidentID AND hm.full_name LIKE :search_hhmember) OR
        $memberMatch
    )";

    $searchValue = '%' . $search . '%';
    foreach (['name', 'name2', 'code', 'contact', 'email', 'house', 'building', 'street', 'purok', 'area', 'addr',
              'hhid', 'hhname', 'hhaddr', 'hhmember', 'member', 'member2', 'member_contact', 'member_code'] as $k) {
        $params[':search_' . $k] = $searchValue;
    }
}
// Income filters use the household's Combined Income (Head + members).
if ($incomeClass === 'low') {
    $where[] = "$combinedSql < 20000";
} elseif ($incomeClass === 'mid') {
    $where[] = "$combinedSql >= 20000 AND $combinedSql < 50000";
} elseif ($incomeClass === 'high') {
    $where[] = "$combinedSql >= 50000";
}
$whereSql = implode(' AND ', $where);

$countSql = $statusFilter === 'inactive'
    ? "SELECT COUNT(*) FROM household_survey hs LEFT JOIN residents r ON r.ResidentID = hs.ResidentID WHERE $whereSql"
    : "SELECT COUNT(*) FROM residents r WHERE $whereSql";
$countStmt = $pdo->prepare($countSql);
foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
$countStmt->execute();
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

$listSql = "
    SELECT
        r.ResidentID, r.FirstName, r.MiddleName, r.LastName, r.Suffix,
        r.Sex, r.BirthDate, r.CivilStatus, r.ContactNumber, r.Email,
        r.HouseNumber, r.BuildingName, r.StreetName, r.Purok, r.AreaName,
        r.AreaType, r.BarangayName, r.CityMunicipalityName, r.ProvinceName,
        r.RegionName, r.ZipCode, r.Latitude, r.Longitude,
        r.TotalHouseholdIncome, r.EmploymentStatus, r.EducationLevel,
        r.IsPWD, r.IsSenior, r.CreatedAt AS DateCreated,
        COALESCE((
            SELECT COUNT(*) FROM residents m
            WHERE m.FamilyHeadID = r.ResidentID
              AND (m.IsDeceased = 0 OR m.IsDeceased IS NULL)
        ),0) AS MemberCount,
        hs.HouseholdID AS HouseholdID,
        COALESCE(hs.is_removed,0) AS is_removed,
        hs.SurveyID,
        COALESCE(hs.status, 'active') AS status,
        $combinedSql AS CombinedIncome" . ($search !== '' ? ",
        (SELECT GROUP_CONCAT(CONCAT_WS(' ', mm.FirstName, mm.LastName) ORDER BY mm.LastName SEPARATOR ', ')
           FROM residents mm
          WHERE mm.FamilyHeadID = r.ResidentID AND (mm.IsDeceased = 0 OR mm.IsDeceased IS NULL)
            AND (CONCAT_WS(' ', mm.FirstName, mm.MiddleName, mm.LastName, mm.Suffix) LIKE :search_mlist
                 OR CONCAT_WS(' ', mm.FirstName, mm.LastName) LIKE :search_mlist2
                 OR mm.ContactNumber LIKE :search_mlist_contact
                 OR mm.ResidentCode LIKE :search_mlist_code)) AS MatchedMembers" : "") . "
    FROM residents r
    LEFT JOIN household_survey hs
        ON hs.ResidentID = r.ResidentID
       AND COALESCE(hs.status, 'active') = 'active'
       AND COALESCE(hs.is_removed, 0) = 0
    WHERE $whereSql
    ORDER BY r.CreatedAt DESC, r.ResidentID DESC
    LIMIT :limit OFFSET :offset
";
if ($statusFilter === 'inactive') {
    $listSql = "
        SELECT
            r.ResidentID, r.FirstName, r.MiddleName, r.LastName, r.Suffix,
            r.Sex, r.ContactNumber, r.TotalHouseholdIncome,
            (COALESCE(r.TotalHouseholdIncome, 0) + COALESCE((SELECT SUM(COALESCE(m2.monthly_income, 0)) FROM household_survey_members m2 WHERE m2.SurveyID = hs.SurveyID AND (m2.ResidentID IS NULL OR m2.ResidentID <> hs.ResidentID)), 0)) AS CombinedIncome,
            hs.head_name, hs.address AS HouseholdAddress,
            hs.DateCreated,
            (SELECT COUNT(*) FROM household_survey_members m WHERE m.SurveyID = hs.SurveyID) AS MemberCount,
            hs.HouseholdID AS HouseholdID,
            COALESCE(hs.is_removed,0) AS is_removed,
            hs.SurveyID,
            'inactive' AS status,
            COALESCE(hs.inactive_since, hs.removed_at) AS inactive_since
        FROM household_survey hs
        LEFT JOIN residents r ON r.ResidentID = hs.ResidentID
        WHERE $whereSql
        ORDER BY COALESCE(hs.inactive_since, hs.removed_at) DESC, hs.SurveyID DESC
        LIMIT :limit OFFSET :offset
    ";
}
try {
    $stmt = $pdo->prepare($listSql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    if ($search !== '' && $statusFilter !== 'inactive') {
        // Members that matched the search (shown under the Head's name).
        foreach (['mlist', 'mlist2', 'mlist_contact', 'mlist_code'] as $k) $stmt->bindValue(':search_' . $k, '%' . $search . '%');
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $households = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($households as &$__hhRow) {
        $__createdYear = !empty($__hhRow['DateCreated']) ? (int)date('Y', strtotime((string)$__hhRow['DateCreated'])) : (int)date('Y');
        $__hhRow['HouseholdID'] = hh_normalize_household_id($__hhRow['HouseholdID'] ?? null, $__createdYear, isset($__hhRow['SurveyID']) ? (int)$__hhRow['SurveyID'] : (int)($__hhRow['ResidentID'] ?? 0));
    }
    unset($__hhRow);
} catch (Throwable $e) {
  if ($statusFilter === 'inactive') {
    // Inactive households only exist in household_survey.
    $households = [];
  } else {
    // If optional survey tables are not migrated yet, fall back to resident-only data.
    $fallback = "
        SELECT r.*,
            NULL AS HouseholdID,
            0 AS is_removed, NULL AS SurveyID,
            COALESCE((SELECT COUNT(*) FROM residents m
                      WHERE m.FamilyHeadID=r.ResidentID
                      AND (m.IsDeceased=0 OR m.IsDeceased IS NULL)),0) AS MemberCount
        FROM residents r
        WHERE $whereSql
        ORDER BY r.CreatedAt DESC, r.ResidentID DESC
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $pdo->prepare($fallback);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $households = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($households as &$__hhRow) {
        $__createdYear = !empty($__hhRow['DateCreated']) ? (int)date('Y', strtotime((string)$__hhRow['DateCreated'])) : (int)date('Y');
        $__hhRow['HouseholdID'] = hh_normalize_household_id($__hhRow['HouseholdID'] ?? null, $__createdYear, isset($__hhRow['SurveyID']) ? (int)$__hhRow['SurveyID'] : (int)($__hhRow['ResidentID'] ?? 0));
    }
    unset($__hhRow);
  }
    error_log('[CAPS-HOUSEHOLD] optional household_survey unavailable: ' . $e->getMessage());
}

$totalHH = (int)$pdo->query("SELECT COUNT(*) FROM residents WHERE IsHead=1 AND (IsDeceased=0 OR IsDeceased IS NULL)")->fetchColumn();
$mapped = (int)$pdo->query("SELECT COUNT(*) FROM residents WHERE IsHead=1 AND (IsDeceased=0 OR IsDeceased IS NULL) AND Latitude IS NOT NULL AND Longitude IS NOT NULL AND Latitude<>0 AND Longitude<>0")->fetchColumn();
// Low Income card: households whose Socioeconomic Status is in the Low group
// (Poor / Low Income (Not Poor)) — same classification as View Household.
$low = 0;
$lowRows = $pdo->query("SELECT " . hh_combined_income_sql('r') . " AS inc,
        1 + (SELECT COUNT(*) FROM residents m WHERE m.FamilyHeadID = r.ResidentID AND (m.IsDeceased = 0 OR m.IsDeceased IS NULL)) AS size
    FROM residents r WHERE r.IsHead = 1 AND (r.IsDeceased = 0 OR r.IsDeceased IS NULL)")->fetchAll(PDO::FETCH_ASSOC);
foreach ($lowRows as $x) {
    if ((hh_socioeconomic_status((float) $x['inc'], (int) $x['size'])['tier'] ?? '') === 'Low') $low++;
}

$avg = $pdo->query("
    SELECT ROUND(AVG(cnt),1) FROM (
        SELECT CASE WHEN IsHead=1 THEN ResidentID ELSE FamilyHeadID END AS HeadID,
               COUNT(*) + SUM(CASE WHEN IsHead=1 THEN 1 ELSE 0 END) AS cnt
        FROM residents
        WHERE IsDeceased=0 OR IsDeceased IS NULL
        GROUP BY CASE WHEN IsHead=1 THEN ResidentID ELSE FamilyHeadID END
    ) x WHERE HeadID IS NOT NULL
")->fetchColumn();

// Active households whose Head moved to another household: they need a new Head.
$headlessHouseholds = hh_headless_households($pdo);

$googleKey = '';
require_once __DIR__ . '/../../config.php';
$googleKey = getenv('GOOGLE_MAPS_BROWSER_KEY') ?: '';
$current_page = 'Households';

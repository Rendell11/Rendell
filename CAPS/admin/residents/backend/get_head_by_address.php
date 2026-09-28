<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../permission_helper.php';
require_permission($pdo, 'residents', 'read');

$house_no      = trim((string)($_GET['house_no'] ?? ''));
$building      = trim((string)($_GET['building'] ?? ''));
$street        = trim((string)($_GET['street'] ?? ''));
$area          = trim((string)($_GET['area'] ?? ''));
$area_type     = trim((string)($_GET['area_type'] ?? ''));
$purok         = trim((string)($_GET['purok'] ?? ''));
$current_id    = (int)($_GET['exclude_id'] ?? 0);
$barangay_code = preg_replace('/\D/', '', (string)($_GET['barangay_code'] ?? ''));
$barangay      = trim((string)($_GET['barangay'] ?? ''));

if ($house_no === '' || $street === '') {
    echo json_encode([
        'success' => true,
        'status' => 'incomplete',
        'heads' => [],
        'residents' => []
    ]);
    exit;
}

try {
    $norm = static function($value): string {
        $value = mb_strtolower(trim((string)$value), 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    };

    $targetHouse    = $norm($house_no);
    $targetStreet   = $norm($street);
    $targetArea     = $norm($area);
    $targetPurok    = $norm($purok);
    $targetBuilding = $norm($building);
    $targetLocal    = $targetPurok !== '' ? $targetPurok : $targetArea;

    /*
     * Do not rely on SQL exact-string matching for the household identity.
     * CAPS and older SOE records may contain harmless differences in spaces,
     * punctuation, capitalization, or whether a local area is stored in
     * AreaName versus Purok. Fetch active residents in the configured
     * barangay, then compare the canonicalized address parts in PHP.
     */
    $sql = "SELECT
                r.ResidentID,
                CONCAT_WS(' ', NULLIF(r.FirstName,''), NULLIF(r.MiddleName,''), NULLIF(r.LastName,''), NULLIF(r.Suffix,'')) AS FullName,
                r.Latitude,
                r.Longitude,
                r.HouseNumber,
                r.BuildingName,
                r.StreetName,
                r.AreaName,
                r.AreaType,
                r.Purok,
                r.BarangayName,
                r.CityMunicipalityName,
                r.ProvinceName,
                r.RegionName,
                r.PSGCRegionCode,
                r.PSGCProvinceCode,
                r.PSGCMunicipalityCode,
                r.PSGCBarangayCode,
                r.IsHead,
                r.IsDeceased,
                r.RelationshipToHead,
                (SELECT COUNT(*) FROM residents m
                   WHERE m.FamilyHeadID = r.ResidentID
                     AND (m.IsDeceased IS NULL OR m.IsDeceased = 0)) AS MemberCount
            FROM residents r
            WHERE r.ResidentID <> :exclude_id
              AND (r.IsDeceased IS NULL OR r.IsDeceased = 0)";

    // Deliberately do NOT filter by barangay/area/building here.
    // The household identity requested by the user is ONLY:
    //   House/Lot/Unit Number + Street.
    // Optional address metadata must never block the same-address prompt.
    $sql .= " ORDER BY r.IsHead DESC, r.LastName, r.FirstName, r.ResidentID LIMIT 5000";

    $params = [':exclude_id' => $current_id];

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Household detection is intentionally based ONLY on the two address
    // fields requested by the Resident form:
    //   1) House/Lot/Unit Number
    //   2) Street
    // Area/Purok, Building, and other optional address fields must NOT prevent
    // an existing Head of Family from being detected. The confirmation dialog
    // is the safeguard before any FamilyHeadID is actually assigned.
    $matched = [];

    foreach ($rows as $row) {
        if ($norm($row['HouseNumber'] ?? '') !== $targetHouse) continue;
        if ($norm($row['StreetName'] ?? '') !== $targetStreet) continue;

        $matched[] = $row;
    }

    $heads = array_values(array_filter($matched, static fn($r) => (int)$r['IsHead'] === 1));

    $residents = array_map(static function(array $r): array {
        return [
            'id' => (int)$r['ResidentID'],
            'name' => trim((string)$r['FullName']),
            'is_head' => (int)$r['IsHead'] === 1,
            'relationship' => (string)($r['RelationshipToHead'] ?? ''),
            'house_number' => $r['HouseNumber'] ?? '',
            'street' => $r['StreetName'] ?? '',
            'area' => $r['AreaName'] ?? '',
            'purok' => $r['Purok'] ?? ''
        ];
    }, $matched);

    // Household code (HH-YYYY-####) of each detected head, used by the
    // "There is an existing household at this address." notice.
    $householdCodes = [];
    $headIds = array_map(static fn($r) => (int)$r['ResidentID'], $heads);
    if ($headIds) {
        try {
            $in = implode(',', array_fill(0, count($headIds), '?'));
            $hs = $pdo->prepare("SELECT ResidentID, HouseholdID FROM household_survey WHERE ResidentID IN ($in) ORDER BY SurveyID DESC");
            $hs->execute($headIds);
            foreach ($hs->fetchAll(PDO::FETCH_ASSOC) as $h) {
                $householdCodes[(int)$h['ResidentID']] ??= (string)$h['HouseholdID'];
            }
        } catch (Throwable $e) {
            error_log('[CAPS] get_head_by_address household code: ' . $e->getMessage());
        }
    }

    $headPayload = array_map(static function(array $r) use ($householdCodes): array {
        return [
            'id' => (int)$r['ResidentID'],
            'name' => trim((string)$r['FullName']),
            'household_code' => $householdCodes[(int)$r['ResidentID']] ?? '',
            'latitude' => $r['Latitude'] ?? null,
            'longitude' => $r['Longitude'] ?? null,
            'member_count' => (int)$r['MemberCount'],
            'house_number' => $r['HouseNumber'] ?? '',
            'building_name' => $r['BuildingName'] ?? '',
            'street' => $r['StreetName'] ?? '',
            'area' => $r['AreaName'] ?? '',
            'area_type' => $r['AreaType'] ?? '',
            'purok' => $r['Purok'] ?? ''
        ];
    }, $heads);

    if (count($headPayload) > 1) {
        echo json_encode([
            'success' => true,
            'status' => 'multiple_heads',
            'heads' => $headPayload,
            'residents' => $residents
        ]);
        exit;
    }

    if (count($headPayload) === 1) {
        $head = $headPayload[0];
        echo json_encode([
            'success' => true,
            'status' => 'head_found',
            'heads' => $headPayload,
            'residents' => $residents,
            'id' => $head['id'],
            'name' => $head['name'],
            'household_code' => $head['household_code'],
            'latitude' => $head['latitude'],
            'longitude' => $head['longitude'],
            'member_count' => $head['member_count'],
            'house_number' => $head['house_number'],
            'building_name' => $head['building_name'],
            'street' => $head['street'],
            'area' => $head['area'],
            'area_type' => $head['area_type'],
            'purok' => $head['purok']
        ]);
        exit;
    }

    if (count($matched) > 0) {
        echo json_encode([
            'success' => true,
            'status' => 'residents_no_head',
            'heads' => [],
            'residents' => $residents,
            'resident_count' => count($matched)
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'status' => 'none',
        'heads' => [],
        'residents' => []
    ]);
} catch (Throwable $e) {
    error_log('[CAPS] get_head_by_address: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}

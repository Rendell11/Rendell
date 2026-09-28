<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../permission_helper.php';
require_once __DIR__ . '/household_common.php';
require_permission($pdo, 'households', 'read');

$headId = (int)($_GET['head_id'] ?? 0);
if ($headId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing head_id']);
    exit;
}

try {
    $headStmt = $pdo->prepare("
        SELECT ResidentID, FirstName, MiddleName, LastName, Suffix,
               Sex, BirthDate, CivilStatus, ContactNumber, Email,
               HouseNumber, BuildingName, StreetName, Purok, AreaName,
               AreaType, BarangayName, CityMunicipalityName, ProvinceName,
               RegionName, ZipCode, Latitude, Longitude,
               TotalHouseholdIncome, EmploymentStatus, EducationLevel,
               IsHead, IsPWD, IsSenior, IsDeceased, CreatedAt AS DateCreated
        FROM residents
        WHERE ResidentID = ? AND IsHead = 1
        LIMIT 1
    ");
    $headStmt->execute([$headId]);
    $head = $headStmt->fetch(PDO::FETCH_ASSOC);
    if (!$head) {
        http_response_code(404);
        echo json_encode(['error' => 'Household head not found']);
        exit;
    }

    $memStmt = $pdo->prepare("
        SELECT ResidentID, FirstName, MiddleName, LastName, Suffix,
               Sex, BirthDate, CivilStatus, ContactNumber,
               RelationshipToHead, IsHead, IsPWD, IsSenior, IsDeceased,
               HouseNumber, BuildingName, StreetName, Purok, AreaName,
               BarangayName, CityMunicipalityName, ProvinceName, RegionName,
               ZipCode, Latitude, Longitude, EmploymentStatus,
               EducationLevel, TotalHouseholdIncome, CreatedAt AS DateCreated
        FROM residents
        WHERE FamilyHeadID = ?
          AND (IsDeceased = 0 OR IsDeceased IS NULL)
        ORDER BY LastName, FirstName, ResidentID
    ");
    $memStmt->execute([$headId]);
    $members = $memStmt->fetchAll(PDO::FETCH_ASSOC);

    $survey = null;
    try {
        $s = $pdo->prepare("SELECT * FROM household_survey WHERE ResidentID=? ORDER BY (COALESCE(status,'active')='active' AND COALESCE(is_removed,0)=0) DESC, SurveyID DESC LIMIT 1");
        $s->execute([$headId]);
        $survey = $s->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $ignored) {}

    $householdId = hh_normalize_household_id($survey['HouseholdID'] ?? null, !empty($head['DateCreated']) ? (int)date('Y', strtotime((string)$head['DateCreated'])) : (int)date('Y'), !empty($survey['SurveyID']) ? (int)$survey['SurveyID'] : $headId);
    echo json_encode([
        'success' => true,
        'household_id' => $householdId,
        'head' => $head,
        'members' => $members,
        'survey' => $survey
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[CAPS-HOUSEHOLD] fetch_family: ' . $e->getMessage());
    echo json_encode(['error' => 'Unable to load household.']);
}

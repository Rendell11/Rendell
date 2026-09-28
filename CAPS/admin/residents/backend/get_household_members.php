<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../permission_helper.php';
require_permission($pdo, 'residents', 'read');

// Household members linked (FamilyHeadID) to a Head of Household.
// Used by the Resident Profiling form: "There are X household member(s) under your household."
$head_id = (int)($_GET['head_id'] ?? 0);

if ($head_id <= 0) {
    echo json_encode(['success' => true, 'count' => 0, 'members' => [], 'household_code' => '']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT ResidentID,
               CONCAT_WS(' ', NULLIF(FirstName,''), NULLIF(MiddleName,''), NULLIF(LastName,''), NULLIF(Suffix,'')) AS FullName,
               RelationshipToHead
        FROM residents
        WHERE FamilyHeadID = ?
          AND ResidentID <> ?
          AND (IsDeceased IS NULL OR IsDeceased = 0)
        ORDER BY LastName, FirstName, ResidentID
    ");
    $stmt->execute([$head_id, $head_id]);

    $members = array_map(static function (array $r): array {
        return [
            'id' => (int)$r['ResidentID'],
            'name' => trim((string)$r['FullName']),
            'relationship' => (string)($r['RelationshipToHead'] ?? '')
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    $household_code = '';
    try {
        $hs = $pdo->prepare("SELECT HouseholdID FROM household_survey WHERE ResidentID = ? ORDER BY SurveyID DESC LIMIT 1");
        $hs->execute([$head_id]);
        $household_code = (string)($hs->fetchColumn() ?: '');
    } catch (Throwable $e) {
        error_log('[CAPS] get_household_members household code: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'count' => count($members),
        'members' => $members,
        'household_code' => $household_code
    ]);
} catch (Throwable $e) {
    error_log('[CAPS] get_household_members: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}

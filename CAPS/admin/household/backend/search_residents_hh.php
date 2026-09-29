<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../permission_helper.php';
require_permission($pdo, 'households', 'read');

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') { echo json_encode([]); exit; }

$like = '%' . $q . '%';
try {
    $stmt = $pdo->prepare("
        SELECT ResidentID, FirstName, MiddleName, LastName, Suffix, Sex,
               CivilStatus, ContactNumber, BirthDate, IsHead, FamilyHeadID, RelationshipToHead,
               HouseNumber, BuildingName, StreetName, Purok, AreaName, BarangayName
        FROM residents
        WHERE (IsDeceased=0 OR IsDeceased IS NULL)
          AND (
            FirstName LIKE :q1 OR MiddleName LIKE :q2 OR LastName LIKE :q3
            OR ContactNumber LIKE :q4
            OR CONCAT_WS(' ',FirstName,MiddleName,LastName) LIKE :q5
          )
        ORDER BY LastName, FirstName
        LIMIT 20
    ");
    // Native prepares (EMULATE_PREPARES=false) cannot reuse one named placeholder.
    $stmt->execute([':q1'=>$like,':q2'=>$like,':q3'=>$like,':q4'=>$like,':q5'=>$like]);
    // Household the resident currently belongs to (for the "from another household" notice).
    $hhOf = $pdo->prepare("SELECT hs.HouseholdID, CONCAT_WS(' ', h.FirstName, h.LastName) AS HeadName
                           FROM household_survey hs JOIN residents h ON h.ResidentID = hs.ResidentID
                           WHERE hs.ResidentID = ? AND COALESCE(hs.status,'active') = 'active' AND COALESCE(hs.is_removed,0) = 0
                           ORDER BY hs.SurveyID DESC LIMIT 1");
    $memberCount = $pdo->prepare("SELECT COUNT(*) FROM residents WHERE FamilyHeadID = ? AND (IsDeceased=0 OR IsDeceased IS NULL)");
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $anchor = (int)$r['IsHead'] === 1 ? (int)$r['ResidentID'] : (int)($r['FamilyHeadID'] ?? 0);
        $hhInfo = null;
        if ($anchor > 0) {
            $hhOf->execute([$anchor]);
            $hhInfo = $hhOf->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $ownMembers = 0;
        if ((int)$r['IsHead'] === 1) {
            $memberCount->execute([(int)$r['ResidentID']]);
            $ownMembers = (int)$memberCount->fetchColumn();
        }
        $rows[] = [
            'id'=>(int)$r['ResidentID'],
            'fullName'=>trim(implode(' ',array_filter([$r['FirstName'],$r['MiddleName'],$r['LastName'],$r['Suffix']]))),
            'sex'=>$r['Sex'] ?? '',
            'civilStatus'=>$r['CivilStatus'] ?? '',
            'contact'=>$r['ContactNumber'] ?? '',
            'age'=>$r['BirthDate'] ? (new DateTime($r['BirthDate']))->diff(new DateTime())->y : null,
            'isHead'=>(int)$r['IsHead'],
            'familyHeadId'=>$r['FamilyHeadID'] ? (int)$r['FamilyHeadID'] : null,
            'relationship'=>$r['RelationshipToHead'] ?? '',
            'householdId'=>$hhInfo['HouseholdID'] ?? '',
            'householdHead'=>trim((string)($hhInfo['HeadName'] ?? '')),
            'ownMemberCount'=>$ownMembers,
            'address'=>implode(', ',array_filter([$r['HouseNumber'],$r['BuildingName'],$r['StreetName'],$r['AreaName'],$r['Purok'],$r['BarangayName']]))
        ];
    }
    echo json_encode($rows);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[CAPS-HOUSEHOLD] search_residents_hh: '.$e->getMessage());
    echo json_encode(['error'=>'Database error']);
}

<?php
/**
 * user/backend/household.php
 * ─────────────────────────────────────────────────────────────────────────────
 * My Household — read-only JSON API for the Flutter app (lib/household/).
 * Ported from the SOE resident page user/household.php and aligned with the
 * admin Household module (admin/household/, same tables):
 *   • role of the resident: head (IsHead = 1), member (FamilyHeadID set) or none
 *   • household record from household_survey (Household ID, survey on file)
 *   • head + members (residents.FamilyHeadID = head), not deceased
 *   • summary: total, male, female, seniors, minors, PWD
 *   • income = head's TotalHouseholdIncome, same brackets as the admin page
 *
 *   GET ?resident_id=  → { role, relationship, household, head_id, members, stats }
 *
 * Nothing is created or changed here; the barangay manages household records.
 * Add &debug=1 to see the exact database error when loading fails.
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php'; // respond(), handle_preflight(), db(), L()
handle_preflight();

$rid = require_resident(); // verified login token (auth.php), not the resident_id sent by the app
if ($rid <= 0) {
    respond(false, L('Kailangan ang resident_id.', 'resident_id is required.'), null, 400);
}

function hh_name(array $r): string
{
    return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter([
        $r['FirstName'] ?? '', $r['MiddleName'] ?? '', $r['LastName'] ?? '', $r['Suffix'] ?? '',
    ]))));
}

function hh_age(?string $birth): ?int
{
    if (!$birth || !strtotime($birth)) return null;
    try {
        return (int) (new DateTime($birth))->diff(new DateTime('today'))->y;
    } catch (Throwable $e) {
        return null;
    }
}

/** Same brackets as admin/household/backend/household_common.php. */
function hh_income_class(float $income): string
{
    if ($income < 20000) return 'Low Income';
    if ($income < 40000) return 'Lower Middle Income';
    if ($income < 70000) return 'Middle Income';
    if ($income < 120000) return 'Upper Middle Income';
    return 'High Income';
}

/** HH-0004 → HH-YYYY-0004, like the admin module shows it. */
function hh_display_id(?string $id, ?string $created): ?string
{
    $id = trim((string) $id);
    if ($id === '' || strpos($id, 'PENDING-') === 0) return null;
    if (preg_match('/^HH-(\d+)$/', $id, $m)) {
        $year = $created && strtotime($created) ? date('Y', strtotime($created)) : date('Y');
        return 'HH-' . $year . '-' . str_pad((string) (int) $m[1], 4, '0', STR_PAD_LEFT);
    }
    return $id;
}

/** Profile photo uploaded from the app (see profile.php), relative to this folder. */
function hh_photo(?string $path): ?string
{
    $prefix = UPLOAD_URL . '/';
    if (!$path || strpos($path, $prefix) !== 0) return null;
    $rel = 'uploads/' . substr($path, strlen($prefix));
    return is_file(__DIR__ . '/' . $rel) ? $rel : null;
}

function hh_person(array $r, int $me, bool $isHead): array
{
    $age = hh_age($r['BirthDate'] ?? null);
    return [
        'resident_id'    => (int) $r['ResidentID'],
        'name'           => hh_name($r),
        'first_name'     => (string) ($r['FirstName'] ?? ''),
        'last_name'      => (string) ($r['LastName'] ?? ''),
        'photo_url'      => hh_photo($r['ProfilePhoto'] ?? null),
        'is_head'        => $isHead,
        'is_me'          => (int) $r['ResidentID'] === $me,
        'relationship'   => $isHead ? 'Head of Family'
                              : (trim((string) ($r['RelationshipToHead'] ?? '')) ?: 'Member'),
        'sex'            => $r['Sex'] ?? null,
        'age'            => $age,
        'birth_date'     => $r['BirthDate'] ?? null,
        'civil_status'   => $r['CivilStatus'] ?? null,
        'contact_number' => $r['ContactNumber'] ?? null,
        'employment'     => $r['EmploymentStatus'] ?? null,
        'education'      => $r['EducationLevel'] ?? null,
        'is_senior'      => (int) ($r['IsSenior'] ?? 0) === 1 || ($age !== null && $age >= 60),
        'is_pwd'         => (int) ($r['IsPWD'] ?? 0) === 1,
        'is_voter'       => (int) ($r['IsVoter'] ?? 0) === 1,
        'is_solo_parent' => (int) ($r['IsSoloParent'] ?? 0) === 1,
        'is_minor'       => $age !== null && $age < 18,
    ];
}

try {
    $pdo = db();

    $s = $pdo->prepare("SELECT * FROM residents WHERE ResidentID = ? LIMIT 1");
    $s->execute([$rid]);
    $me = $s->fetch(PDO::FETCH_ASSOC);
    if (!$me) {
        respond(false, L('Hindi nahanap ang iyong record.', 'Your record was not found.'), null, 404);
    }

    $isHead = (int) ($me['IsHead'] ?? 0) === 1;
    $headId = $isHead ? $rid : (int) ($me['FamilyHeadID'] ?? 0);
    $head   = $isHead ? $me : null;
    if (!$isHead && $headId > 0) {
        $s->execute([$headId]);
        $head = $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$head) {
        respond(true, '', [
            'role'         => 'none',
            'relationship' => $me['RelationshipToHead'] ?? null,
            'household'    => null,
            'head_id'      => null,
            'members'      => [],
            'stats'        => null,
        ]);
    }
    $headId = (int) $head['ResidentID'];

    // Household record kept by the admin module (may not exist yet).
    $survey = null;
    try {
        $cols = $pdo->query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'household_survey'"
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($cols) {
            $where = in_array('is_removed', $cols, true) ? ' AND COALESCE(is_removed, 0) = 0' : '';
            if (in_array('status', $cols, true)) $where .= " AND COALESCE(status, 'active') = 'active'";
            $q = $pdo->prepare("SELECT * FROM household_survey WHERE ResidentID = ?$where
                                ORDER BY SurveyID DESC LIMIT 1");
            $q->execute([$headId]);
            $survey = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } catch (Throwable $e) {
        error_log('[household.php survey] ' . $e->getMessage());
    }

    // Members, like admin view_household.php.
    $m = $pdo->prepare(
        "SELECT * FROM residents
         WHERE FamilyHeadID = ? AND ResidentID <> ?
           AND (IsDeceased = 0 OR IsDeceased IS NULL)
         ORDER BY LastName, FirstName, ResidentID"
    );
    $m->execute([$headId, $headId]);

    $people = [hh_person($head, $rid, true)];
    foreach ($m->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $people[] = hh_person($r, $rid, false);
    }

    $stats = ['total' => count($people), 'male' => 0, 'female' => 0,
              'seniors' => 0, 'minors' => 0, 'pwd' => 0];
    foreach ($people as $p) {
        $sex = strtolower((string) $p['sex']);
        if ($sex === 'male') $stats['male']++;
        if ($sex === 'female') $stats['female']++;
        if ($p['is_senior']) $stats['seniors']++;
        if ($p['is_minor']) $stats['minors']++;
        if ($p['is_pwd']) $stats['pwd']++;
    }

    // Older records store puroks.id instead of the name.
    $purok = trim((string) ($head['Purok'] ?? ''));
    if ($purok !== '' && ctype_digit($purok)) {
        try {
            $pq = $pdo->prepare("SELECT purok_name FROM puroks WHERE id = ? LIMIT 1");
            $pq->execute([(int) $purok]);
            $purok = (string) ($pq->fetchColumn() ?: $purok);
        } catch (Throwable $e) { /* keep the raw value */ }
    }

    $income  = $head['TotalHouseholdIncome'] !== null ? (float) $head['TotalHouseholdIncome'] : null;
    $address = implode(', ', array_filter([
        trim((string) ($head['HouseNumber'] ?? '')),
        trim((string) ($head['StreetName'] ?? '')),
    ]));

    respond(true, '', [
        'role'         => $isHead ? 'head' : 'member',
        'relationship' => $isHead ? 'Head of Family'
                            : (trim((string) ($me['RelationshipToHead'] ?? '')) ?: 'Member'),
        'household'    => [
            'household_id'   => hh_display_id($survey['HouseholdID'] ?? ($head['HouseholdID'] ?? null),
                                    $survey['DateCreated'] ?? ($head['DateCreated'] ?? $head['CreatedAt'] ?? null)),
            'head_name'      => hh_name($head),
            'address'        => $address,
            'purok'          => $purok !== '' ? $purok : null,
            'house_type'     => ($survey['house_type'] ?? null) ?: ($head['HouseType'] ?? null),
            'tenure'         => ($survey['tenure_status'] ?? null) ?: ($survey['housing_tenure'] ?? null),
            'monthly_income' => $income,
            'income_class'   => $income !== null ? hh_income_class($income) : null,
            'registered'     => $survey['DateCreated'] ?? ($head['DateCreated'] ?? $head['CreatedAt'] ?? null),
            'survey_on_file' => $survey !== null,
        ],
        'head_id'      => $headId,
        'members'      => $people,
        'stats'        => $stats,
    ]);
} catch (Throwable $e) {
    error_log('[household.php] ' . $e->getMessage());
    respond(false, L('Hindi ma-load ang sambahayan.', 'Could not load the household.'),
        isset($_GET['debug']) ? ['error' => $e->getMessage()] : null, 500);
}

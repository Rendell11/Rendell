<?php
/**
 * user/backend/profile.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Resident profile — JSON API for the Flutter app (lib/profile/).
 * Ported from the SOE resident page user/profile.php.
 *
 * Actions (GET ?action= or POST action=):
 *   • get           (resident_id)          → profile details (read-only) + photo
 *   • upload_photo  (resident_id, photo)   → new profile picture (multipart)
 *   • remove_photo  (resident_id)          → back to initials
 *
 * The details are VIEW-ONLY for now: the resident can only change the photo.
 * Photos are saved in user/backend/uploads/profile_photos/ and the path is
 * stored in residents.ProfilePhoto relative to the CAPS root
 * (e.g. "user/backend/uploads/profile_photos/resident_14_ab12cd34.jpg").
 *
 * Envelope: { success, message, data } via respond() in config.php.
 * Same trust model as chat.php / complaint.php (resident_id from the app).
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php'; // respond(), handle_preflight(), db(), L()
handle_preflight();

const PROFILE_PHOTO_SUB = 'profile_photos';
const PROFILE_PHOTO_MAX = 5 * 1024 * 1024; // 5 MB

$pdo    = db();
$action = $_GET['action'] ?? $_POST['action'] ?? 'get';
$rid    = (int) ($_GET['resident_id'] ?? $_POST['resident_id'] ?? 0);

if ($rid <= 0) {
    respond(false, L('Kailangan ang resident_id.', 'resident_id is required.'), null, 400);
}

// ── Self-heal: some CAPS databases don't have residents.ProfilePhoto yet.
//    Add it once so uploads can be saved (no manual SQL needed).
try {
    $has = $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'residents'
           AND COLUMN_NAME = 'ProfilePhoto'"
    )->fetchColumn();
    if ((int) $has === 0) {
        $pdo->exec("ALTER TABLE residents ADD COLUMN ProfilePhoto VARCHAR(512) NULL
                    COMMENT 'Relative path to uploaded profile picture' AFTER Email");
    }
} catch (Throwable $e) {
    error_log('[profile.php migrate] ' . $e->getMessage());
}

/** The resident row, or null when missing / not an Active portal account. */
function profile_row(PDO $pdo, int $rid): ?array
{
    $s = $pdo->prepare("SELECT * FROM residents WHERE ResidentID = ? LIMIT 1");
    $s->execute([$rid]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return ($r && ($r['access_status'] ?? '') === 'Active') ? $r : null;
}

/** Stored path → URL relative to this backend folder (null if not ours / missing). */
function profile_photo_url(?string $path): ?string
{
    if (!$path) return null;
    $prefix = UPLOAD_URL . '/';
    if (strpos($path, $prefix) === 0) {
        $rel = 'uploads/' . substr($path, strlen($prefix));
        return is_file(__DIR__ . '/' . $rel) ? $rel : null;
    }
    return null; // photos saved by other modules aren't served from here
}

/** Delete a previous photo only if it lives in our own profile_photos folder. */
function profile_delete_photo(?string $path): void
{
    $prefix = UPLOAD_URL . '/' . PROFILE_PHOTO_SUB . '/';
    if ($path && strpos($path, $prefix) === 0) {
        $file = UPLOAD_DIR . '/' . PROFILE_PHOTO_SUB . '/' . basename($path);
        if (is_file($file)) @unlink($file);
    }
}

function yn($v): ?bool
{
    return $v === null ? null : ((int) $v === 1);
}

$r = profile_row($pdo, $rid);
if ($r === null) {
    respond(false, L('Hindi aktibo o hindi nahanap ang resident account.',
        'The resident account is not active or was not found.'), null, 403);
}

// ── GET ──────────────────────────────────────────────────────────────────────
if ($action === 'get') {
    $head = null;
    if (!empty($r['FamilyHeadID'])) {
        try {
            $h = $pdo->prepare("SELECT FirstName, LastName FROM residents WHERE ResidentID = ? LIMIT 1");
            $h->execute([(int) $r['FamilyHeadID']]);
            $hr = $h->fetch(PDO::FETCH_ASSOC);
            if ($hr) $head = trim($hr['FirstName'] . ' ' . $hr['LastName']);
        } catch (Throwable $e) { /* ignore */ }
    }
    $age = null;
    if (!empty($r['BirthDate']) && strtotime($r['BirthDate'])) {
        $age = (int) (new DateTime($r['BirthDate']))->diff(new DateTime('today'))->y;
    }
    respond(true, '', [
        'resident_id'      => (int) $r['ResidentID'],
        'resident_code'    => $r['ResidentCode'] ?? null,
        'photo_url'        => profile_photo_url($r['ProfilePhoto'] ?? null),
        'first_name'       => $r['FirstName'],
        'middle_name'      => $r['MiddleName'],
        'last_name'        => $r['LastName'],
        'suffix'           => $r['Suffix'],
        'sex'              => $r['Sex'],
        'civil_status'     => $r['CivilStatus'],
        'birth_date'       => $r['BirthDate'],
        'age'              => $age,
        'birth_place'      => $r['BirthPlace'],
        'religion'         => $r['Religion'] ?? null,
        'nationality'      => $r['Nationality'] ?? null,
        'email'            => $r['Email'],
        'contact_number'   => $r['ContactNumber'],
        'house_number'     => $r['HouseNumber'],
        'street'           => $r['StreetName'],
        'purok'            => $r['Purok'],
        'is_head'          => yn($r['IsHead']),
        'relationship'     => $r['RelationshipToHead'],
        'household_head'   => $head,
        'employment'       => $r['EmploymentStatus'],
        'education'        => $r['EducationLevel'],
        'household_income' => $r['TotalHouseholdIncome'] !== null ? (float) $r['TotalHouseholdIncome'] : null,
        'is_voter'         => yn($r['IsVoter']),
        'is_pwd'           => yn($r['IsPWD']),
        'pwd_class'        => $r['PWDClassification'],
        'is_senior'        => yn($r['IsSenior']),
        'is_solo_parent'   => yn($r['IsSoloParent']),
        'has_philhealth'   => yn($r['HasPhilhealth'] ?? null),
        'has_sss_gsis'     => yn($r['HasSSSGSIS'] ?? null),
        'has_4ps'          => yn($r['Has4Ps'] ?? null),
        'member_since'     => $r['DateCreated'],
    ]);
}

// ── UPLOAD PHOTO ─────────────────────────────────────────────────────────────
if ($action === 'upload_photo') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond(false, L('POST lang ang tinatanggap.', 'Only POST is accepted.'), null, 405);
    }
    $f = $_FILES['photo'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        respond(false, L('Hindi na-upload ang larawan. Subukan muli.', 'The photo was not uploaded. Please try again.'), null, 422);
    }
    if ($f['size'] > PROFILE_PHOTO_MAX) {
        respond(false, L('Masyadong malaki ang larawan. Hanggang 5 MB lang.', 'The photo is too large. 5 MB max.'), null, 422);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext  = ['image/jpeg' => 'jpg', 'image/pjpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if ($ext === null) {
        respond(false, L('JPG, PNG o WEBP lang ang puwede.', 'Only JPG, PNG or WEBP images are allowed.'), null, 422);
    }
    $dir = UPLOAD_DIR . '/' . PROFILE_PHOTO_SUB;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $name = 'resident_' . $rid . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!is_dir($dir) || !is_writable($dir) || !move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        error_log('[profile.php upload] cannot write to ' . $dir);
        respond(false, L('Hindi ma-save ang larawan sa server (uploads folder). Ipaalam sa admin.',
            'The server could not store the photo (uploads folder). Please tell the admin.'), null, 500);
    }
    $path = UPLOAD_URL . '/' . PROFILE_PHOTO_SUB . '/' . $name;
    try {
        $pdo->prepare("UPDATE residents SET ProfilePhoto = ? WHERE ResidentID = ?")->execute([$path, $rid]);
    } catch (Throwable $e) {
        @unlink($dir . '/' . $name);
        error_log('[profile.php upload] ' . $e->getMessage());
        respond(false, L('Hindi ma-save ang larawan sa database. Ipaalam sa admin.',
            'The photo could not be saved in the database. Please tell the admin.'), null, 500);
    }
    profile_delete_photo($r['ProfilePhoto'] ?? null);
    respond(true, L('Na-update ang profile picture.', 'Profile picture updated.'), ['photo_url' => profile_photo_url($path)]);
}

// ── REMOVE PHOTO ─────────────────────────────────────────────────────────────
if ($action === 'remove_photo') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond(false, L('POST lang ang tinatanggap.', 'Only POST is accepted.'), null, 405);
    }
    $pdo->prepare("UPDATE residents SET ProfilePhoto = NULL WHERE ResidentID = ?")->execute([$rid]);
    profile_delete_photo($r['ProfilePhoto'] ?? null);
    respond(true, L('Tinanggal ang profile picture.', 'Profile picture removed.'), ['photo_url' => null]);
}

respond(false, L('Hindi wastong action.', 'Invalid action.'), null, 400);

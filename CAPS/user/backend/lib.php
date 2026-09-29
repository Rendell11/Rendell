<?php
/**
 * Resident access-request logic — the single source of truth.
 *
 * Every function returns an array: ['ok'=>bool, 'message'=>string, 'data'=>mixed].
 * The JSON API endpoints and the browser frontend pages both call these, so the
 * behaviour is identical no matter how the resident reaches it.
 *
 * Touches only two tables: `access_requests` and `residents`.
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';

/**
 * Password rules (set password, change password, forgot password):
 * 8–72 characters, with an uppercase letter, a lowercase letter, a number and
 * a special character, no spaces. Returns the error message or null.
 * The app shows the same rules as a live checklist (widgets/password_rules.dart).
 */
function password_policy_error(string $pw): ?string
{
    $missing = [];
    if (strlen($pw) < 8)                  $missing[] = L('8 karakter pataas', 'at least 8 characters');
    if (!preg_match('/[A-Z]/', $pw))      $missing[] = L('malaking titik (A-Z)', 'an uppercase letter (A-Z)');
    if (!preg_match('/[a-z]/', $pw))      $missing[] = L('maliit na titik (a-z)', 'a lowercase letter (a-z)');
    if (!preg_match('/[0-9]/', $pw))      $missing[] = L('numero (0-9)', 'a number (0-9)');
    if (!preg_match('/[^A-Za-z0-9\s]/', $pw)) $missing[] = L('special character (hal. ! @ # ?)', 'a special character (e.g. ! @ # ?)');
    if ($missing) {
        return L('Kulang sa password: ', 'The password needs ') . implode(', ', $missing) . '.';
    }
    if (preg_match('/\s/', $pw)) {
        return L('Bawal ang space sa password.', 'The password cannot contain spaces.');
    }
    if (strlen($pw) > 72) {
        return L('Hanggang 72 karakter lang ang password.', 'The password can have at most 72 characters.');
    }
    return null;
}

/** Save an uploaded image from $_FILES[$field]; return stored relative path or null. */
function save_upload(string $field, string $prefix): ?string
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0775, true);
    }
    $tmp  = $_FILES[$field]['tmp_name'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $ext  = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/bmp' => 'bmp',
        'image/x-ms-bmp' => 'bmp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'image/tiff' => 'tiff',
    ][$mime] ?? null;
    if ($ext === null) {
        return null; // silently skip a non-image; caller decides if it was required
    }
    $name = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($tmp, UPLOAD_DIR . '/' . $name)) {
        return null;
    }
    return UPLOAD_URL . '/' . $name;
}

/**
 * STEP 1 — create a new access request from a set of fields + optional uploads.
 * $fields keys: first_name, middle_name, last_name, email, contact_number,
 * birthdate, house_no, street, purok, request_message.
 */
function create_access_request(array $fields): array
{
    $firstName = trim((string)($fields['first_name'] ?? ''));
    $lastName  = trim((string)($fields['last_name'] ?? ''));
    $email     = strtolower(trim((string)($fields['email'] ?? '')));
    $contact   = trim((string)($fields['contact_number'] ?? ''));
    $birthdate = trim((string)($fields['birthdate'] ?? ''));
    $house     = trim((string)($fields['house_no'] ?? ''));
    $street    = trim((string)($fields['street'] ?? ''));
    $purok     = trim((string)($fields['purok'] ?? ''));

    // Same required set + formats as SOE request_access.php
    if ($firstName === '' || $lastName === '' || $email === '' || $contact === ''
        || $house === '' || $street === '' || $purok === '' || $birthdate === '') {
        return ['ok' => false, 'message' => L('Punan ang lahat ng kailangang field.', 'Please fill in all required fields.'), 'data' => null];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => L('Hindi wastong email.', 'Invalid email address.'), 'data' => null];
    }
    if (!preg_match('/^09\d{9}$/', $contact)) {
        return ['ok' => false, 'message' => L('Contact number dapat 09XXXXXXXXX.', 'Contact number must be 09XXXXXXXXX.'), 'data' => null];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate)
        || $birthdate > date('Y-m-d') || substr($birthdate, 0, 4) < 1900) {
        return ['ok' => false, 'message' => L('Hindi wastong petsa ng kapanganakan.', 'Invalid date of birth.'), 'data' => null];
    }

    $pdo = db();
    // Duplicate access request (Pending/Approved) by email
    $dup = $pdo->prepare(
        "SELECT id FROM access_requests WHERE email = ? AND status IN ('Pending','Approved') LIMIT 1"
    );
    $dup->execute([$email]);
    if ($dup->fetch()) {
        return ['ok' => false, 'message' => L('May naka-request na para sa email na ito (pending o approved).', 'There is already a request for this email (pending or approved).'), 'data' => null];
    }
    // Duplicate contact
    $dupC = $pdo->prepare(
        "SELECT COUNT(*) FROM access_requests WHERE contact_number = ? AND status IN ('Pending','Approved')"
    );
    $dupC->execute([$contact]);
    if ((int)$dupC->fetchColumn() > 0) {
        return ['ok' => false, 'message' => L('Ginamit na ang contact number na ito sa ibang request.', 'This contact number is already used in another request.'), 'data' => null];
    }
    // Already a resident with this email?
    $dupR = $pdo->prepare(
        "SELECT COUNT(*) FROM residents WHERE Email = ? AND (IsDeceased = 0 OR IsDeceased IS NULL)"
    );
    $dupR->execute([$email]);
    if ((int)$dupR->fetchColumn() > 0) {
        return ['ok' => false, 'message' => L('Nakarehistro na ang email na ito bilang resident.', 'This email is already registered to a resident.'), 'data' => null];
    }

    $validId = save_upload('valid_id', 'id');
    $selfie  = save_upload('selfie', 'selfie');
    if ($validId === null) {
        return ['ok' => false, 'message' => L('Mag-upload ng valid ID (JPG/PNG).', 'Please upload a valid ID (JPG/PNG).'), 'data' => null];
    }
    if ($selfie === null) {
        return ['ok' => false, 'message' => L('Mag-upload ng selfie hawak ang ID (JPG/PNG).', 'Please upload a selfie holding your ID (JPG/PNG).'), 'data' => null];
    }

    $middle   = trim((string)($fields['middle_name'] ?? ''));
    $fullname = trim($firstName . ' ' . $middle . ' ' . $lastName);

    $stmt = $pdo->prepare(
        "INSERT INTO access_requests
            (fullname, first_name, middle_name, last_name, email, contact_number,
             birthdate, house_no, street, purok, valid_id_path, selfie_image,
             request_message, status)
         VALUES (:fullname,:first_name,:middle_name,:last_name,:email,:contact_number,
             :birthdate,:house_no,:street,:purok,:valid_id_path,:selfie_image,
             :request_message,'Pending')"
    );
    $stmt->execute([
        ':fullname'        => $fullname,
        ':first_name'      => $firstName,
        ':middle_name'     => $middle !== '' ? $middle : null,
        ':last_name'       => $lastName,
        ':email'           => $email,
        ':contact_number'  => trim((string)($fields['contact_number'] ?? '')) ?: null,
        ':birthdate'       => trim((string)($fields['birthdate'] ?? '')) ?: null,
        ':house_no'        => trim((string)($fields['house_no'] ?? '')) ?: null,
        ':street'          => trim((string)($fields['street'] ?? '')) ?: null,
        ':purok'           => trim((string)($fields['purok'] ?? '')) ?: null,
        ':valid_id_path'   => $validId,
        ':selfie_image'    => $selfie,
        ':request_message' => trim((string)($fields['request_message'] ?? '')) ?: null,
    ]);

    return [
        'ok'      => true,
        'message' => L('Naisumite ang request. Maghintay ng approval ng admin.', 'Request submitted. Please wait for the admin to approve it.'),
        'data'    => ['request_id' => (int)$pdo->lastInsertId()],
    ];
}

/** STEP 2 — latest request for an email or contact number. */
function find_request_status(?string $email, ?string $contact): array
{
    $email   = $email !== null ? trim($email) : '';
    $contact = $contact !== null ? trim($contact) : '';
    if ($email === '' && $contact === '') {
        return ['ok' => false, 'message' => L('Ilagay ang email o contact number.', 'Enter your email or contact number.'), 'data' => null];
    }

    $pdo = db();
    if ($email !== '') {
        $stmt = $pdo->prepare('SELECT * FROM access_requests WHERE email = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$email]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM access_requests WHERE contact_number = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$contact]);
    }
    $row = $stmt->fetch();
    if (!$row) {
        return ['ok' => false, 'message' => L('Walang nahanap na request.', 'No request found.'), 'data' => null];
    }
    unset($row['token'], $row['token_expiry']); // never leak the token
    return ['ok' => true, 'message' => L('Nahanap ang request.', 'Request found.'), 'data' => $row];
}

/** STEP 3 — set the resident's password using the admin-issued token. */
function set_resident_password(string $token, string $password): array
{
    $token = trim($token);
    if ($token === '') {
        return ['ok' => false, 'message' => L('Kailangan ang access token.', 'The access token is required.'), 'data' => null];
    }
    if (($err = password_policy_error($password)) !== null) {
        return ['ok' => false, 'message' => $err, 'data' => null];
    }

    $pdo  = db();
    // Read the full request so we can create a resident if none exists yet
    // (matches the SOE admin approve → set_password flow: approve only stores a
    // token; the residents row may not exist until the password is set).
    $stmt = $pdo->prepare(
        'SELECT id, request_id, resident_id, matched_resident_id, status, token_expiry,
                first_name, middle_name, last_name, email, contact_number,
                birthdate, house_no, street, purok
         FROM access_requests WHERE token = ? LIMIT 1'
    );
    $stmt->execute([$token]);
    $req = $stmt->fetch();

    if (!$req) {
        return ['ok' => false, 'message' => L('Hindi wasto ang token.', 'Invalid token.'), 'data' => null];
    }
    if (!in_array($req['status'], ['Approved', 'Matched'], true)) {
        return ['ok' => false, 'message' => L('Hindi pa aprubado ang request na ito.', 'This request is not approved yet.'), 'data' => null];
    }
    if (!empty($req['token_expiry']) && strtotime($req['token_expiry']) < time()) {
        return ['ok' => false, 'message' => L('Nag-expire na ang token. Humiling ng bago sa admin.', 'The token has expired. Ask the admin for a new one.'), 'data' => null];
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $pdo->beginTransaction();
    try {
        // 1) Find the resident, in this priority:
        //    (a) explicitly linked resident_id,
        //    (b) EXACT email match (the resident's own identity for login),
        //    (c) matched_resident_id (auto-guess) — last, so a wrong/low match
        //        never hijacks the password of another person.
        $residentId = $req['resident_id'] ?: null;
        if (!$residentId && !empty($req['email'])) {
            $r = $pdo->prepare(
                "SELECT ResidentID FROM residents
                 WHERE Email = ? AND (IsDeceased = 0 OR IsDeceased IS NULL) LIMIT 1"
            );
            $r->execute([$req['email']]);
            $row = $r->fetch();
            if ($row) $residentId = (int) $row['ResidentID'];
        }
        // (c) matched_resident_id — only if that resident's email matches the
        //     request email (or is blank). Guards against a stale/low auto-match
        //     pointing at a different person.
        if (!$residentId && !empty($req['matched_resident_id'])) {
            $m = $pdo->prepare("SELECT ResidentID, Email FROM residents WHERE ResidentID = ? LIMIT 1");
            $m->execute([$req['matched_resident_id']]);
            $mrow = $m->fetch();
            if ($mrow) {
                $memail = strtolower(trim((string)($mrow['Email'] ?? '')));
                $remail = strtolower(trim((string)($req['email'] ?? '')));
                if ($memail === '' || $remail === '' || $memail === $remail) {
                    $residentId = (int) $mrow['ResidentID'];
                }
            }
        }

        if ($residentId) {
            // 2a) Existing resident → set password + activate.
            $pdo->prepare(
                "UPDATE residents SET Password = ?, access_status = 'Active',
                 ResetToken = NULL, TokenExpiry = NULL WHERE ResidentID = ?"
            )->execute([$hash, $residentId]);
        } else {
            // 2b) No resident yet → create one from the request data (like SOE).
            $ins = $pdo->prepare(
                "INSERT INTO residents
                    (FirstName, MiddleName, LastName, Email, ContactNumber,
                     BirthDate, HouseNumber, StreetName, Purok,
                     Password, access_status, IsDeceased)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', 0)"
            );
            $ins->execute([
                $req['first_name'],
                $req['middle_name'] ?: null,
                $req['last_name'],
                $req['email'] ?: null,
                $req['contact_number'] ?: null,
                $req['birthdate'] ?: null,
                $req['house_no'] ?: null,
                $req['street'] ?: null,
                $req['purok'] ?: null,
                $hash,
            ]);
            $residentId = (int) $pdo->lastInsertId();
            // Link the new resident back to the request.
            $pdo->prepare('UPDATE access_requests SET resident_id = ? WHERE id = ?')
                ->execute([$residentId, $req['id']]);
        }

        // 3) Burn the one-time token.
        $pdo->prepare('UPDATE access_requests SET token = NULL, token_expiry = NULL WHERE id = ?')
            ->execute([$req['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[set_resident_password] ' . $e->getMessage());
        return ['ok' => false, 'message' => L('Hindi naitakda ang password.', 'The password was not set.'), 'data' => null];
    }
    // 4) Make sure the resident can log in with what they requested with.
    //    A profile the admin encoded often has NO email (or contact), so the
    //    password landed on a record the login could never find.
    resident_link_request($pdo, (int) $residentId, (int) $req['id'], $req['email'] ?? null, $req['contact_number'] ?? null);
    return ['ok' => true, 'message' => L('Naitakda ang password. Maaari ka nang mag-login.', 'Password set. You can now log in.'), 'data' => null];
}

/**
 * The admin-managed default barangay address (Manage Area → barangay_profile).
 * Same shape as admin/backend/address_api.php?action=profile so the app shows
 * exactly what the admin configured (region/province/city/barangay come from
 * the PSGC picker, never hard-coded).
 */
function get_barangay_profile(): ?array
{
    $pdo = db();
    try {
        $row = $pdo->query(
            "SELECT region_name, province_name, municipality_name, barangay_name,
                    psgc_region_code, psgc_province_code, psgc_municipality_code,
                    psgc_barangay_code, zip_code
             FROM barangay_profile ORDER BY id DESC LIMIT 1"
        )->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Public appearance for the pre-login screens (login / request access /
 * forgot password), configured by the admin in settings.php → admin_preferences
 * (keys `accent_color`, `color_mode`). Residents personalise inside the app
 * separately; this is only the admin-set look for the entry screens.
 *
 * Reads the primary admin (admin_id = 1 by default); if that admin hasn't set
 * an accent, it falls back to the most recently set accent across admins, then
 * to the barangay brand blue.
 */
function get_public_theme(int $adminId = 0): array
{
    $accent = '#1D63DA';
    $mode   = 'light';
    $pdo    = db();
    try {
        // Dedicated portal appearance the admin sets in settings.php, stored in
        // its own `portal_preferences` table (separate from admin_preferences,
        // which is per-admin panel personalisation).
        $stmt = $pdo->prepare(
            "SELECT preference_key, preference_value FROM portal_preferences
             WHERE preference_key IN ('portal_accent_color','portal_color_mode')"
        );
        $stmt->execute();
        $rows = [];
        while ($r = $stmt->fetch()) {
            $rows[$r['preference_key']] = $r['preference_value'];
        }
        if (!empty($rows['portal_accent_color'])) {
            $accent = $rows['portal_accent_color'];
        }
        if (!empty($rows['portal_color_mode'])) {
            $mode = $rows['portal_color_mode'];
        }
    } catch (Throwable $e) {
        // keep defaults
    }
    // Sanitise the hex so the app always gets a valid value.
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
        // allow 3-digit hex too
        if (preg_match('/^#[0-9a-fA-F]{3}$/', $accent)) {
            $accent = '#' . $accent[1] . $accent[1] . $accent[2] . $accent[2] . $accent[3] . $accent[3];
        } else {
            $accent = '#1D63DA';
        }
    }
    return ['accent_color' => strtoupper($accent), 'color_mode' => $mode === 'dark' ? 'dark' : 'light'];
}

/** Active streets for a barangay (resident_streets), admin-managed. */
function list_streets(string $barangayCode): array
{
    if ($barangayCode === '') return [];
    $pdo  = db();
    try {
        $stmt = $pdo->prepare(
            "SELECT street_name FROM resident_streets
             WHERE psgc_barangay_code = ? AND status = 'Active'
             ORDER BY street_name ASC"
        );
        $stmt->execute([$barangayCode]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return [];
    }
}

/** Active areas (Purok/Subdivision/Village/Sitio) for a barangay. */
function list_areas(string $barangayCode): array
{
    if ($barangayCode === '') return [];
    $pdo  = db();
    try {
        $stmt = $pdo->prepare(
            "SELECT area_name, area_type FROM resident_areas
             WHERE psgc_barangay_code = ? AND status = 'Active'
             ORDER BY area_type ASC, area_name ASC"
        );
        $stmt->execute([$barangayCode]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/** Purok options for the request form (legacy `puroks` table fallback). */
function list_puroks(): array
{
    $pdo = db();
    try {
        $rows = $pdo->query(
            "SELECT purok_name FROM puroks
             WHERE purok_name IS NOT NULL AND purok_name <> '' AND status = 'Active'
             ORDER BY purok_name ASC"
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($rows) return $rows;
    } catch (Throwable $e) {
        // fall through
    }
    try {
        $rows = $pdo->query(
            "SELECT DISTINCT Purok FROM residents
             WHERE Purok IS NOT NULL AND Purok <> '' ORDER BY Purok ASC"
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($rows) return $rows;
    } catch (Throwable $e) {
        // fall through
    }
    return ['Purok 1', 'Purok 2', 'Purok 3', 'Purok 4', 'Purok 5'];
}

/**
 * Link an access request to its resident and copy the request's email /
 * contact number onto the resident when the resident has none, so the
 * resident can log in (and reset the password) with them. Best effort: the
 * residents table has UNIQUE Email / ContactNumber, so a value already used by
 * another record is left alone.
 */
function resident_link_request(PDO $pdo, int $residentId, int $requestId, ?string $email, ?string $contact): void
{
    if ($residentId <= 0) return;
    try {
        $pdo->prepare('UPDATE access_requests SET resident_id = ? WHERE id = ? AND (resident_id IS NULL OR resident_id = 0)')
            ->execute([$residentId, $requestId]);
    } catch (Throwable $e) { /* ignore */ }
    $email = strtolower(trim((string) $email));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            $pdo->prepare("UPDATE residents SET Email = ? WHERE ResidentID = ? AND (Email IS NULL OR TRIM(Email) = '')")
                ->execute([$email, $residentId]);
        } catch (Throwable $e) { error_log('[resident_link_request] email: ' . $e->getMessage()); }
    }
    $contact = trim((string) $contact);
    if (preg_match('/^09\d{9}$/', $contact)) {
        try {
            $pdo->prepare("UPDATE residents SET ContactNumber = ? WHERE ResidentID = ? AND (ContactNumber IS NULL OR TRIM(ContactNumber) = '')")
                ->execute([$contact, $residentId]);
        } catch (Throwable $e) { error_log('[resident_link_request] contact: ' . $e->getMessage()); }
    }
}

/**
 * Every resident record an email or contact number can log in to:
 *   1. residents whose Email / ContactNumber matches, and
 *   2. residents linked to an APPROVED access request with that email /
 *      contact (resident_id or matched_resident_id) — covers accounts whose
 *      profile has a different or empty email.
 * Best candidates first (Active with a password).
 */
function resident_accounts(PDO $pdo, string $identifier): array
{
    $identifier = trim($identifier);
    if ($identifier === '') return [];
    $isEmail = (bool) filter_var($identifier, FILTER_VALIDATE_EMAIL);
    $key = $isEmail ? strtolower($identifier) : preg_replace('/[^0-9]/', '', $identifier);
    if ($key === '') return [];
    $cols = 'r.ResidentID, r.ResidentCode, r.FirstName, r.MiddleName, r.LastName,
             r.Email, r.ContactNumber, r.Purok, r.Password, r.access_status';
    $alive = '(r.IsDeceased = 0 OR r.IsDeceased IS NULL)';
    $rows = [];
    if ($isEmail) {
        $q = $pdo->prepare("SELECT $cols FROM residents r WHERE $alive AND LOWER(TRIM(r.Email)) = ?");
    } else {
        $q = $pdo->prepare("SELECT $cols FROM residents r
                            WHERE $alive AND REPLACE(REPLACE(REPLACE(r.ContactNumber, ' ', ''), '-', ''), '+63', '0') = ?");
    }
    $q->execute([$key]);
    foreach ($q->fetchAll() as $r) $rows[(int) $r['ResidentID']] = $r;
    try {
        $q = $pdo->prepare("SELECT $cols FROM access_requests a
                            JOIN residents r ON r.ResidentID = COALESCE(NULLIF(a.resident_id, 0), a.matched_resident_id)
                            WHERE $alive AND a.status IN ('Approved','Matched') AND "
                            . ($isEmail ? 'LOWER(TRIM(a.email)) = ?' : "REPLACE(REPLACE(a.contact_number, ' ', ''), '-', '') = ?")
                            . ' ORDER BY a.id DESC');
        $q->execute([$key]);
        foreach ($q->fetchAll() as $r) $rows[(int) $r['ResidentID']] ??= $r;
    } catch (Throwable $e) { /* older schema */ }
    $rows = array_values($rows);
    usort($rows, fn($a, $b) =>
        [($b['access_status'] ?? '') === 'Active', !empty($b['Password'])]
        <=> [($a['access_status'] ?? '') === 'Active', !empty($a['Password'])]);
    return $rows;
}

/* ── Forgot password: a 6-digit code by email ─────────────────────────────────
 * The app cannot open a web reset link on the phone (the backend is only
 * reachable on the barangay Wi-Fi), so the resident gets a CODE by email and
 * types it in the app together with the new password.
 *   request_password_reset(email)            → emails the code (15 minutes)
 *   reset_password_with_code(email, code, pw) → sets the new password
 * Codes are stored hashed; 5 wrong tries end a code; one code per minute.
 */
const RESET_CODE_MINUTES = 15;
const RESET_CODE_TRIES   = 5;

function reset_migrate(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS resident_password_resets (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            resident_id INT UNSIGNED NOT NULL,
            code_hash   CHAR(64) NOT NULL,
            expires_at  DATETIME NOT NULL,
            attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
            used_at     DATETIME NULL,
            ip          VARCHAR(45) NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_rpr_resident (resident_id, used_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/** The Active account an email may reset (see resident_accounts()). */
function reset_account(PDO $pdo, string $email): ?array
{
    foreach (resident_accounts($pdo, $email) as $r) {
        if (($r['access_status'] ?? '') === 'Active') return $r;
    }
    return null;
}

function reset_code_hash(int $residentId, string $code): string
{
    return hash('sha256', $residentId . ':' . $code);
}

function reset_email_html(string $name, string $code): string
{
    $name = htmlspecialchars($name !== '' ? $name : 'Resident', ENT_QUOTES, 'UTF-8');
    $digits = implode('&nbsp;', str_split($code));
    return "<!DOCTYPE html><html><body style='margin:0;padding:0;background:#eef2fb;font-family:Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#eef2fb;padding:28px 12px'><tr><td align='center'>
<table width='460' cellpadding='0' cellspacing='0' style='max-width:460px;width:100%;background:#fff;border-radius:20px;overflow:hidden'>
<tr><td style='background:#1a3570;padding:28px;text-align:center'>
<p style='margin:0 0 4px;font-size:11px;letter-spacing:3px;color:#b8c4e0'>REPUBLIC OF THE PHILIPPINES</p>
<h1 style='margin:0;font-size:20px;color:#fff'>BARANGAY BIÑANG 2ND</h1>
<p style='margin:6px 0 0;font-size:11px;color:#b8c4e0'>Resident App</p></td></tr>
<tr><td style='padding:28px 30px 8px'>
<p style='margin:0 0 6px;font-size:17px;font-weight:bold;color:#0f172a'>Password reset code</p>
<p style='margin:0 0 18px;font-size:13px;color:#475569;line-height:1.6'>Hi {$name}, use this code in the app to set a new password.<br>
<i>Ilagay ang code na ito sa app para makagawa ng bagong password.</i></p>
<p style='margin:0 0 18px;text-align:center'><span style='display:inline-block;background:#eef2fb;border:1px solid #c7d4f0;border-radius:14px;padding:14px 22px;font-size:30px;font-weight:bold;letter-spacing:4px;color:#1a3570'>{$digits}</span></p>
<p style='margin:0 0 18px;font-size:13px;color:#334155;line-height:1.6'>The code expires in <b style='color:#f05a00'>" . RESET_CODE_MINUTES . " minutes</b>. / Mag-e-expire ito sa loob ng " . RESET_CODE_MINUTES . " minuto.</p>
<p style='margin:0 0 22px;font-size:12px;color:#64748b;line-height:1.6'>If you did not ask for this, ignore this email — your password stays the same. Never share this code with anyone, even barangay staff.<br>
<i>Kung hindi ikaw ang humiling nito, balewalain lang ang email. Huwag ibigay ang code kahit kanino.</i></p>
</td></tr></table></td></tr></table></body></html>";
}

function request_password_reset(string $email): array
{
    require_once __DIR__ . '/mailer.php';
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => L('Hindi wastong email.', 'Invalid email address.'), 'data' => null];
    }
    if (!mailer_ready()) {
        error_log('[forgot_password] email is not set up (smtp_config.php / CAPS/vendor)');
        return ['ok' => false, 'message' => L(
            'Hindi pa naka-set up ang pagpapadala ng email. Pumunta o tumawag muna sa barangay hall.',
            'Sending email is not set up yet. Please visit or call the barangay hall.'), 'data' => null];
    }
    $generic = ['ok' => true,
        'message' => L('Kung nakarehistro ang email, may 6-digit code na ipinadala. Tingnan ang inbox/spam.',
                       'If the email is registered, a 6-digit code was sent. Check your inbox/spam.'),
        'data' => ['expires_minutes' => RESET_CODE_MINUTES]];

    $pdo = db();
    reset_migrate($pdo);
    $row = reset_account($pdo, $email);
    if (!$row) return $generic; // don't reveal which emails are registered
    $rid = (int) $row['ResidentID'];

    // One code per minute, 5 per hour.
    $recent = $pdo->prepare("SELECT
            SUM(created_at > NOW() - INTERVAL 1 MINUTE) AS last_min,
            COUNT(*) AS last_hour
        FROM resident_password_resets WHERE resident_id = ? AND created_at > NOW() - INTERVAL 1 HOUR");
    $recent->execute([$rid]);
    $rc = $recent->fetch();
    if ((int) ($rc['last_min'] ?? 0) > 0 || (int) ($rc['last_hour'] ?? 0) >= 5) {
        return $generic;
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE resident_password_resets SET used_at = NOW() WHERE resident_id = ? AND used_at IS NULL')
        ->execute([$rid]);
    $pdo->prepare('INSERT INTO resident_password_resets (resident_id, code_hash, expires_at, ip)
                   VALUES (?, ?, NOW() + INTERVAL ' . RESET_CODE_MINUTES . ' MINUTE, ?)')
        ->execute([$rid, reset_code_hash($rid, $code), substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);

    $err = null;
    if (!send_mail($email, (string) $row['FirstName'], 'Password reset code — Barangay Biñang 2nd',
                   reset_email_html((string) $row['FirstName'], $code), $err)) {
        $pdo->prepare('UPDATE resident_password_resets SET used_at = NOW() WHERE resident_id = ? AND used_at IS NULL')
            ->execute([$rid]);
        return ['ok' => false, 'message' => L('Hindi naipadala ang email. Subukan ulit mamaya.',
                                               'The email could not be sent. Please try again later.'), 'data' => null];
    }
    return $generic;
}

function reset_password_with_code(string $email, string $code, string $password): array
{
    $email = strtolower(trim($email));
    $code  = preg_replace('/\D/', '', $code);
    $bad = ['ok' => false, 'message' => L('Mali o expired na ang code. Humingi ng bagong code.',
                                          'The code is wrong or expired. Ask for a new code.'), 'data' => null];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($code) !== 6) return $bad;
    if (($perr = password_policy_error($password)) !== null) {
        return ['ok' => false, 'message' => $perr, 'data' => null];
    }

    $pdo = db();
    reset_migrate($pdo);
    $res = reset_account($pdo, $email);
    if (!$res) return $bad;
    $rid = (int) $res['ResidentID'];

    $q = $pdo->prepare('SELECT id, code_hash, attempts, expires_at < NOW() AS expired
                        FROM resident_password_resets
                        WHERE resident_id = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1');
    $q->execute([$rid]);
    $row = $q->fetch();
    if (!$row || (int) $row['expired'] === 1) return $bad;

    if (!hash_equals($row['code_hash'], reset_code_hash($rid, $code))) {
        $tries = (int) $row['attempts'] + 1;
        $left  = RESET_CODE_TRIES - $tries;
        $pdo->prepare('UPDATE resident_password_resets SET attempts = ?' . ($left <= 0 ? ', used_at = NOW()' : '') . ' WHERE id = ?')
            ->execute([$tries, $row['id']]);
        if ($left <= 0) {
            return ['ok' => false, 'message' => L('Sobra na ang maling subok. Humingi ng bagong code.',
                                                  'Too many wrong tries. Ask for a new code.'), 'data' => null];
        }
        return ['ok' => false, 'message' => L("Mali ang code. May $left subok pa.",
                                              "Wrong code. $left tries left."), 'data' => ['tries_left' => $left]];
    }
    if (!empty($res['Password']) && password_verify($password, (string) $res['Password'])) {
        return ['ok' => false, 'message' => L('Dapat iba sa dating password.', 'Use a password different from your old one.'), 'data' => null];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE residents SET Password = ?, ResetToken = NULL, TokenExpiry = NULL WHERE ResidentID = ?')
            ->execute([password_hash($password, PASSWORD_BCRYPT), $rid]);
        $pdo->prepare('UPDATE resident_password_resets SET used_at = NOW() WHERE resident_id = ? AND used_at IS NULL')
            ->execute([$rid]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[reset_password_with_code] ' . $e->getMessage());
        return ['ok' => false, 'message' => L('Hindi naitakda ang password.', 'The password was not set.'), 'data' => null];
    }
    // Log out every device that used the old password.
    try {
        auth_migrate($pdo);
        $pdo->prepare('UPDATE resident_sessions SET revoked_at = NOW() WHERE resident_id = ? AND revoked_at IS NULL')
            ->execute([$rid]);
    } catch (Throwable $e) { /* ignore */ }
    return ['ok' => true, 'message' => L('Napalitan ang password. Mag-login gamit ang bago.',
                                         'Password changed. Log in with your new password.'), 'data' => null];
}

/** STEP 4 — verify login against residents.Password; require Active account. */
function resident_login(string $identifier, string $password): array
{
    $identifier = trim($identifier);
    if ($identifier === '' || $password === '') {
        return ['ok' => false, 'message' => L('Kailangan ang email/contact at password.', 'Email/contact and password are required.'), 'data' => null];
    }
    $pdo = db();
    $wrong = ['ok' => false, 'message' => L('Maling email/contact o password.', 'Wrong email/contact or password.'), 'data' => null];
    $inactive = null;
    foreach (resident_accounts($pdo, $identifier) as $resident) {
        if (empty($resident['Password']) || !password_verify($password, (string) $resident['Password'])) continue;
        if ($resident['access_status'] !== 'Active') {
            $inactive = ['ok' => false, 'message' => L('Hindi pa aktibo ang account mo.', 'Your account is not active yet.'), 'data' => null];
            continue;
        }
        // Logged in through an approved request: save the email/contact on the
        // profile so the next login and "forgot password" find it directly.
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            resident_link_request($pdo, (int) $resident['ResidentID'], 0, $identifier, null);
        } else {
            resident_link_request($pdo, (int) $resident['ResidentID'], 0, null, $identifier);
        }
        unset($resident['Password']);
        return ['ok' => true, 'message' => L('Matagumpay ang pag-login.', 'Logged in successfully.'), 'data' => $resident];
    }
    return $inactive ?? $wrong;
}

/** Settings → change password: verify the current one, then save the new one. */
function change_resident_password(int $residentId, string $current, string $new): array
{
    if ($residentId <= 0 || $current === '' || $new === '') {
        return ['ok' => false, 'message' => L('Punan ang lahat ng kailangang field.', 'Please fill in all required fields.'), 'data' => null];
    }
    if (($err = password_policy_error($new)) !== null) {
        return ['ok' => false, 'message' => $err, 'data' => null];
    }
    if ($new === $current) {
        return ['ok' => false, 'message' => L('Dapat iba ang bagong password.', 'The new password must be different.'), 'data' => null];
    }
    $pdo  = db();
    $stmt = $pdo->prepare('SELECT Password, access_status FROM residents WHERE ResidentID = ? LIMIT 1');
    $stmt->execute([$residentId]);
    $row = $stmt->fetch();
    if (!$row || $row['access_status'] !== 'Active') {
        return ['ok' => false, 'message' => L('Hindi aktibo ang account mo.', 'Your account is not active.'), 'data' => null];
    }
    if (!$row['Password'] || !password_verify($current, $row['Password'])) {
        return ['ok' => false, 'message' => L('Mali ang kasalukuyang password.', 'The current password is wrong.'), 'data' => null];
    }
    $pdo->prepare('UPDATE residents SET Password = ?, ResetToken = NULL, TokenExpiry = NULL WHERE ResidentID = ?')
        ->execute([password_hash($new, PASSWORD_BCRYPT), $residentId]);
    return ['ok' => true, 'message' => L('Napalitan ang password.', 'Password changed.'), 'data' => null];
}

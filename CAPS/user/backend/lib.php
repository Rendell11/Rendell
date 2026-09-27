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
    if (strlen($password) < 8) {
        return ['ok' => false, 'message' => L('Dapat 8 karakter pataas ang password.', 'The password must be at least 8 characters.'), 'data' => null];
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
 * Forgot password — write a reset token + 1-hour expiry to the resident row.
 * Emailing the link is left to your existing SMTP setup (see note in
 * forgot_password.php); this always returns success to avoid user enumeration.
 */
function request_password_reset(string $email): array
{
    $email = strtolower(trim($email));
    $generic = ['ok' => true,
        'message' => L('Kung nakarehistro ang email, may reset link na ipapadala. Tingnan ang inbox/spam.', 'If the email is registered, a reset link will be sent. Check your inbox/spam.'),
        'data' => null];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => L('Hindi wastong email.', 'Invalid email address.'), 'data' => null];
    }
    $pdo  = db();
    $stmt = $pdo->prepare('SELECT ResidentID FROM residents WHERE Email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if ($row) {
        $token  = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $pdo->prepare('UPDATE residents SET ResetToken = ?, TokenExpiry = ? WHERE ResidentID = ?')
            ->execute([$token, $expiry, $row['ResidentID']]);
        // TODO: email the link with your SMTP (as in resident_forgot_password.php).
        // e.g. resident_reset_password.php?token=$token
    }
    return $generic;
}

/** STEP 4 — verify login against residents.Password; require Active account. */
function resident_login(string $identifier, string $password): array
{
    $identifier = trim($identifier);
    if ($identifier === '' || $password === '') {
        return ['ok' => false, 'message' => L('Kailangan ang email/contact at password.', 'Email/contact and password are required.'), 'data' => null];
    }
    $column = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'Email' : 'ContactNumber';

    $pdo  = db();
    $stmt = $pdo->prepare(
        "SELECT ResidentID, ResidentCode, FirstName, MiddleName, LastName,
                Email, ContactNumber, Purok, Password, access_status
         FROM residents WHERE $column = ? LIMIT 1"
    );
    $stmt->execute([$identifier]);
    $resident = $stmt->fetch();

    if (!$resident || !$resident['Password'] || !password_verify($password, $resident['Password'])) {
        return ['ok' => false, 'message' => L('Maling email/contact o password.', 'Wrong email/contact or password.'), 'data' => null];
    }
    if ($resident['access_status'] !== 'Active') {
        return ['ok' => false, 'message' => L('Hindi pa aktibo ang account mo.', 'Your account is not active yet.'), 'data' => null];
    }
    unset($resident['Password']);
    return ['ok' => true, 'message' => L('Matagumpay ang pag-login.', 'Logged in successfully.'), 'data' => $resident];
}

/** Settings → change password: verify the current one, then save the new one. */
function change_resident_password(int $residentId, string $current, string $new): array
{
    if ($residentId <= 0 || $current === '' || $new === '') {
        return ['ok' => false, 'message' => L('Punan ang lahat ng kailangang field.', 'Please fill in all required fields.'), 'data' => null];
    }
    if (strlen($new) < 8) {
        return ['ok' => false, 'message' => L('Dapat 8 karakter pataas ang password.', 'The password must be at least 8 characters.'), 'data' => null];
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

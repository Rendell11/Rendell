<?php
// 1. USE statements dapat nasa pinakataas
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../../../vendor/autoload.php'; 
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/address_validation.php';
require_once __DIR__ . '/../../auth_check.php';
if (file_exists(__DIR__ . '/csrf_helper.php')) {
    require_once __DIR__ . '/csrf_helper.php';
} else {
    if (!function_exists('csrf_verify')) { function csrf_verify(): void {} }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_verify();

date_default_timezone_set('Asia/Manila'); 

// ─────────────────────────────────────────────────────────────────────────────
// HELPER: Redirect back with a user-friendly error (no more raw die() blobs)
// ─────────────────────────────────────────────────────────────────────────────
function redirectError(string $msg, string $action = 'add', ?string $residentId = null): void {
    $params = http_build_query([
        'status'  => 'error',
        'message' => $msg,
        'action'  => $action,
    ]);
    if ($residentId) $params .= '&edit_id=' . urlencode($residentId);
    header("Location: ../frontend/residents.php?{$params}");
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPER: Generate next ResidentCode (RES-YYYY-NNNN)
// ─────────────────────────────────────────────────────────────────────────────
function generateResidentCode(PDO $pdo): string {
    $year = date('Y');
    // Count all residents to get the next sequence number
    $count = (int)$pdo->query("SELECT COUNT(*) FROM residents")->fetchColumn();
    $seq   = str_pad($count + 1, 4, '0', STR_PAD_LEFT);
    $code  = "RES-{$year}-{$seq}";
    // Ensure uniqueness — increment if taken
    $check = $pdo->prepare("SELECT COUNT(*) FROM residents WHERE ResidentCode = ?");
    $check->execute([$code]);
    while ($check->fetchColumn() > 0) {
        $seq  = str_pad((int)$seq + 1, 4, '0', STR_PAD_LEFT);
        $code = "RES-{$year}-{$seq}";
        $check->execute([$code]);
    }
    return $code;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action         = $_POST['action'] ?? 'add';

    // RBAC: backend gate — 'edit' requires update permission, anything
    // else (default 'add') requires create permission on Residents.
    require_once __DIR__ . '/../../permission_helper.php';
    require_permission($pdo, 'residents', $action === 'edit' ? 'update' : 'create');

    $resident_id    = $_POST['ResidentID'] ?? null; 
    $first_name     = trim($_POST['FirstName']);
    $middle_name    = trim($_POST['MiddleName']);
    $last_name      = trim($_POST['LastName']);
    $suffix         = trim($_POST['Suffix']);
    $sex            = $_POST['Sex']; 
    $birthdate      = $_POST['BirthDate'];
    $birth_place    = trim($_POST['BirthPlace'] ?? ''); 

    $civil_status   = $_POST['CivilStatus'] ?? 'Single';
    // Email is editable from Resident Management. Keep it optional, normalize it,
    // and validate uniqueness below for both add and edit operations.
    $email = strtolower(trim((string)($_POST['Email'] ?? '')));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        redirectError('Please enter a valid email address.', $action, $resident_id);
    }
    // Optional email: store an empty field as SQL NULL, not an empty string.
    // This prevents a UNIQUE email index from treating multiple blank emails
    // as duplicates while still enforcing uniqueness when an email is supplied.
    $email = $email !== '' ? $email : null;
    
    // Contact number is optional on the Staff/Admin side.
    // If supplied, it must still be a valid PH mobile number.
    $contact_no = preg_replace('/[^0-9]/', '', (string)($_POST['ContactNumber'] ?? ''));
    if ($contact_no !== '' && !preg_match('/^09\d{9}$/', $contact_no)) {
        redirectError('Contact number must be 11 digits and start with 09.', $action, $resident_id);
    }
    $contact_no = $contact_no !== '' ? $contact_no : null;

    $house_no       = trim((string)($_POST['HouseNumber'] ?? ''));
    $building_name  = cleanAddressValue($_POST['BuildingName'] ?? '');
    $street         = trim((string)($_POST['StreetName'] ?? ''));
    $area_name      = cleanAddressValue($_POST['AreaName'] ?? '');
    $area_type      = cleanAddressValue($_POST['AreaType'] ?? '', 30);
    $purok          = trim((string)($_POST['Purok'] ?? ''));

    // Region/Province/Municipality/Barangay are now controlled centrally by Manage Area.
    // Never trust a per-resident manual hierarchy.
    try {
        $profileQ = $pdo->query("SELECT region_name, province_name, municipality_name, barangay_name,
            psgc_region_code, psgc_province_code, psgc_municipality_code, psgc_barangay_code, zip_code
            FROM barangay_profile WHERE id=1 LIMIT 1");
        $profile = $profileQ->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $profile = []; }
    $region_name   = cleanAddressValue($profile['region_name'] ?? '');
    $province_name = cleanAddressValue($profile['province_name'] ?? '');
    $city_name    = cleanAddressValue($profile['municipality_name'] ?? '');
    $barangay_name= cleanAddressValue($profile['barangay_name'] ?? '');
    $psgc_region   = preg_replace('/\D/','',(string)($profile['psgc_region_code'] ?? ''));
    $psgc_province = preg_replace('/\D/','',(string)($profile['psgc_province_code'] ?? ''));
    $psgc_municipal= preg_replace('/\D/','',(string)($profile['psgc_municipality_code'] ?? ''));
    $psgc_barangay = preg_replace('/\D/','',(string)($profile['psgc_barangay_code'] ?? ''));
    $zip_code      = cleanAddressValue($profile['zip_code'] ?? '', 10);
    if (!$psgc_region || !$psgc_province || !$psgc_municipal || !$psgc_barangay || !$barangay_name) {
        redirectError('Please configure the default Region, Province, City/Municipality and Barangay in Manage Area first.', $action, $resident_id);
    }
    if ($zip_code === '') {
        try {
            $zq=$pdo->prepare("SELECT zip_code FROM psgc_zip_codes WHERE municipality_code=? AND status='Active' LIMIT 1");
            $zq->execute([$psgc_municipal]);
            $zip_code=cleanAddressValue($zq->fetchColumn() ?: '',10);
        } catch (Throwable $e) {}
    }

    $latitude       = !empty($_POST['Latitude']) ? $_POST['Latitude'] : null;
    $longitude      = !empty($_POST['Longitude']) ? $_POST['Longitude'] : null;
    
    // BACKEND ROLE LOGIC ENFORCEMENT
    $is_head        = (int)($_POST['IsHead'] ?? 0); 
    if ($is_head === 1) {
        $rel_to_head = "Head of Family";
    } else {
        $rel_to_head = trim($_POST['RelationshipToHead'] ?? '');
        if (empty($rel_to_head)) {
            redirectError('Relationship to Head is required for family members.', $action, $resident_id);
        }
    }

    $family_head_id = ($is_head === 1 || empty($_POST['FamilyHeadID']))
        ? null
        : (int)$_POST['FamilyHeadID'];

    // Preserve an existing household link during an ordinary edit when the
    // form did not send a replacement head. A deliberate change to Head of
    // Family still clears the link because $is_head === 1 above.
    if ($action === 'edit' && $is_head !== 1 && !$family_head_id && $resident_id) {
        $existingLink = $pdo->prepare("
            SELECT FamilyHeadID
            FROM residents
            WHERE ResidentID=? AND IsHead=0
            LIMIT 1
        ");
        $existingLink->execute([(int)$resident_id]);
        $family_head_id = (int)($existingLink->fetchColumn() ?: 0) ?: null;
    }

    /*
     * SERVER-SIDE HOUSEHOLD LINK VALIDATION
     *
     * JavaScript confirmation is only a UI step. The database write is allowed
     * only when the selected FamilyHeadID is an active Head of Family and the
     * selected head has the same normalized HouseNumber + StreetName.
     *
     * Never auto-select a head here. If the browser sends IsHead=0 without a
     * FamilyHeadID, the request is rejected instead of silently creating a link.
     */
    if ($action === 'edit' && $is_head !== 1 && $resident_id) {
        $currentHeadCheck = $pdo->prepare("
            SELECT IsHead,
                   (SELECT COUNT(*) FROM residents m WHERE m.FamilyHeadID = residents.ResidentID
                      AND (m.IsDeceased IS NULL OR m.IsDeceased=0)) AS ActiveMemberCount
            FROM residents
            WHERE ResidentID=?
            LIMIT 1
        ");
        $currentHeadCheck->execute([(int)$resident_id]);
        $currentHeadState = $currentHeadCheck->fetch(PDO::FETCH_ASSOC);

        if ($currentHeadState
            && (int)$currentHeadState['IsHead'] === 1
            && (int)$currentHeadState['ActiveMemberCount'] > 0) {
            redirectError(
                'This resident is currently a Head of Family with '
                . (int)$currentHeadState['ActiveMemberCount']
                . ' linked household member(s). Change the household head through Household Management first.',
                $action,
                $resident_id
            );
        }
    }

    // A Member may be saved without a household only after the Admin answered
    // "Yes, Continue" to the "No household has been selected" confirmation.
    $allow_no_household = ($_POST['AllowNoHousehold'] ?? '') === '1';

    if ($is_head !== 1 && !$family_head_id && !$allow_no_household) {
        redirectError(
            'No household has been selected for this resident.',
            $action,
            $resident_id
        );
    }

    if ($is_head !== 1 && $family_head_id) {
        $normalizeHouseholdAddress = static function ($value): string {
            $value = mb_strtolower(trim((string)$value), 'UTF-8');
            $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
            return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        };

        $hq = $pdo->prepare("
            SELECT ResidentID, IsHead, IsDeceased,
                   HouseNumber, BuildingName, StreetName, Purok, AreaName,
                   Latitude, Longitude
            FROM residents
            WHERE ResidentID=?
              AND IsHead=1
              AND (IsDeceased IS NULL OR IsDeceased=0)
            LIMIT 1
        ");
        $hq->execute([$family_head_id]);
        $headRow = $hq->fetch(PDO::FETCH_ASSOC);

        if (!$headRow) {
            redirectError(
                'The selected Head of Family is no longer available. Please choose a valid household head.',
                $action,
                $resident_id
            );
        }

        $sameHouse = $normalizeHouseholdAddress($headRow['HouseNumber'] ?? '')
            === $normalizeHouseholdAddress($house_no);
        $sameStreet = $normalizeHouseholdAddress($headRow['StreetName'] ?? '')
            === $normalizeHouseholdAddress($street);
        // Household matching is intentionally limited to the two required
        // identity fields: House/Lot/Unit Number + Street.
        if (!$sameHouse || !$sameStreet) {
            redirectError(
                'The selected Head of Family does not match this resident\'s address. House/Lot/Unit Number and Street must match.',
                $action,
                $resident_id
            );
        }

        // A confirmed household member uses the household's existing map pin.
        // Only replace the member coordinates when the head has a real pin.
        if ($headRow['Latitude'] !== null && $headRow['Latitude'] !== ''
            && $headRow['Longitude'] !== null && $headRow['Longitude'] !== '') {
            $latitude = $headRow['Latitude'];
            $longitude = $headRow['Longitude'];
        }
    }


    $emp_status     = $_POST['EmploymentStatus'] ?? 'Unemployed';
    $emp_status_other = ($emp_status === 'Other') ? (trim($_POST['EmploymentStatusOther'] ?? '') ?: null) : null;
    $occupation     = trim($_POST['Occupation'] ?? '') ?: null;
    $edu_level      = $_POST['EducationLevel'] ?? null; 
    $income         = !empty($_POST['TotalHouseholdIncome']) ? (float)$_POST['TotalHouseholdIncome'] : 0.00;

    // Source of Income — multiple checkboxes, stored as a comma-separated list
    $source_income_arr = isset($_POST['SourceOfIncome']) && is_array($_POST['SourceOfIncome'])
        ? array_map('trim', $_POST['SourceOfIncome'])
        : [];
    $source_income_arr   = array_values(array_filter($source_income_arr, fn($v) => $v !== ''));
    $source_of_income    = !empty($source_income_arr) ? implode(',', $source_income_arr) : null;
    $source_income_other = in_array('Other', $source_income_arr, true)
        ? (trim($_POST['SourceOfIncomeOther'] ?? '') ?: null)
        : null;

    $is_pwd         = (int)($_POST['IsPWD'] ?? 0);
    $pwd_class      = ($is_pwd === 1) ? ($_POST['PWDClassification'] ?? null) : null;
    $pwd_id_val     = ($is_pwd === 1) ? ($_POST['PWDID'] ?? null) : null;

    $is_senior      = (int)($_POST['IsSenior'] ?? 0);
    $is_solo        = (int)($_POST['IsSoloParent'] ?? 0);
    $is_deceased    = (int)($_POST['IsDeceased'] ?? 0);
    $is_voter       = (int)($_POST['IsVoter'] ?? 0); // fixed: matches form field name
    $voter_number   = ($is_voter === 1) ? trim($_POST['VoterNumber'] ?? '') : null;
    $voter_number   = ($voter_number === '') ? null : $voter_number;

    $religion       = trim($_POST['Religion'] ?? '') ?: null;
    $nationality    = trim($_POST['Nationality'] ?? '') ?: null;
    $has_philhealth = (int)($_POST['HasPhilhealth'] ?? 0);
    $has_sss_gsis   = (int)($_POST['HasSSSGSIS'] ?? 0);
    $has_4ps        = (int)($_POST['Has4Ps'] ?? 0);
    $is_sss         = (int)($_POST['IsSSSMember'] ?? 0);
    $is_gsis        = (int)($_POST['IsGSISMember'] ?? 0);
    $is_pagibig     = (int)($_POST['IsPagibigMember'] ?? 0);

    // ── Deceased details (only meaningful while IsDeceased = 1) ───
    $date_of_death    = ($is_deceased === 1) ? (trim($_POST['DateOfDeath'] ?? '') ?: null) : null;
    $place_of_death   = ($is_deceased === 1) ? (trim($_POST['PlaceOfDeath'] ?? '') ?: null) : null;
    $cause_of_death   = ($is_deceased === 1) ? (trim($_POST['CauseOfDeath'] ?? '') ?: null) : null;
    $deceased_remarks = ($is_deceased === 1) ? (trim($_POST['DeceasedRemarks'] ?? '') ?: null) : null;

    // Who / when the death was reported — captured automatically, never from user input
    $reporter_name = $_SESSION['admin_name'] ?? $_SESSION['staff_name'] ?? $_SESSION['user_name'] ?? 'System';

    // ── ResidentCode: only relevant for new records ───────────────
    $resident_code_input = trim($_POST['ResidentCode'] ?? '');

    // FILE UPLOAD FOR DEATH CERTIFICATE
    $death_cert_filename = null;
    if ($is_deceased === 1 && isset($_FILES['DeathCertificate']) && $_FILES['DeathCertificate']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/death_certificates/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $tmp  = $_FILES['DeathCertificate']['tmp_name'];
        $size = $_FILES['DeathCertificate']['size'];

        if ($size > 10 * 1024 * 1024) {
            redirectError('Death certificate file exceeds 10 MB limit.', $action, $resident_id);
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($tmp);
        $allowed_mimes = [
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/webp'      => 'webp',
            'application/pdf' => 'pdf',
        ];
        if (!array_key_exists($mime, $allowed_mimes)) {
            redirectError('Invalid file type for death certificate. Allowed: JPG, PNG, WebP, PDF.', $action, $resident_id);
        }

        $ext = $allowed_mimes[$mime];
        $death_cert_filename = 'DEATH_' . bin2hex(random_bytes(8)) . '.' . $ext;
        move_uploaded_file($tmp, $upload_dir . $death_cert_filename);
    }

    try {
        if ($action == 'edit') {
            // ── DECEASED LOCK: fetch current status before update ─────────
            $currentRow = $pdo->prepare("SELECT IsDeceased, ResidentCode FROM residents WHERE ResidentID = ?");
            $currentRow->execute([$resident_id]);
            $current = $currentRow->fetch(PDO::FETCH_ASSOC);

            $was_deceased = $current && (int)$current['IsDeceased'] === 1;
            if ($was_deceased) {
                // Once marked as deceased, status cannot be reversed
                $is_deceased = 1;
            }

            // Re-evaluate deceased detail fields now that the lock has been applied
            $date_of_death    = ($is_deceased === 1) ? (trim($_POST['DateOfDeath'] ?? '') ?: null) : null;
            $place_of_death   = ($is_deceased === 1) ? (trim($_POST['PlaceOfDeath'] ?? '') ?: null) : null;
            $cause_of_death   = ($is_deceased === 1) ? (trim($_POST['CauseOfDeath'] ?? '') ?: null) : null;
            $deceased_remarks = ($is_deceased === 1) ? (trim($_POST['DeceasedRemarks'] ?? '') ?: null) : null;

            // "Date Reported" / "Reported By" are set once, the first moment a resident
            // is marked deceased, and never overwritten afterwards.
            $newly_deceased = ($is_deceased === 1 && !$was_deceased);

            // ── EDIT MODE: check uniqueness excluding this resident ──────────

            // Contact uniqueness (exclude self)
            if (!empty($contact_no)) {
                $chk = $pdo->prepare("
                    SELECT COUNT(*) FROM residents
                    WHERE ContactNumber = ?
                      AND ResidentID != ?
                      AND (IsDeceased = 0 OR IsDeceased IS NULL)
                ");
                $chk->execute([$contact_no, $resident_id]);
                if ($chk->fetchColumn() > 0) {
                    redirectError('This mobile number is already registered to another resident.', $action, $resident_id);
                }
            }

            // Email uniqueness (exclude self)
            if (!empty($email)) {
                $chk = $pdo->prepare("
                    SELECT COUNT(*) FROM residents
                    WHERE Email = ?
                      AND ResidentID != ?
                      AND (IsDeceased = 0 OR IsDeceased IS NULL)
                ");
                $chk->execute([$email, $resident_id]);
                if ($chk->fetchColumn() > 0) {
                    redirectError('This email address is already registered to another resident.', $action, $resident_id);
                }
            }

            $sql = "UPDATE residents SET 
                        FirstName=?, MiddleName=?, LastName=?, Suffix=?, Sex=?, 
                        BirthDate=?, BirthPlace=?, CivilStatus=?, ContactNumber=?, Email=?, HouseNumber=?, BuildingName=?, StreetName=?, Purok=?, AreaName=?, AreaType=?, BarangayName=?, CityMunicipalityName=?, ProvinceName=?, RegionName=?, ZipCode=?, PSGCRegionCode=?, PSGCProvinceCode=?, PSGCMunicipalityCode=?, PSGCBarangayCode=?, 
                        IsHead=?, RelationshipToHead=?, FamilyHeadID=?, IsPWD=?, 
                        PWDClassification=?, PWDID=?, IsSenior=?, 
                        IsSoloParent=?, TotalHouseholdIncome=?, 
                        EmploymentStatus=?, EmploymentStatusOther=?, Occupation=?,
                        SourceOfIncome=?, SourceOfIncomeOther=?,
                        EducationLevel=?, IsDeceased=?, IsVoter=?,
                        VoterNumber=?,  Religion=?, Nationality=?,
                        HasPhilhealth=?, HasSSSGSIS=?, Has4Ps=?,
                        IsSSSMember=?, IsGSISMember=?, IsPagibigMember=?,
                        DateOfDeath=?, PlaceOfDeath=?, CauseOfDeath=?, DeceasedRemarks=?,
                        Latitude=?, Longitude=?" .
                        ($death_cert_filename ? ", DeathCertificate=?" : "") .
                        ($newly_deceased ? ", DeathDateReported=?, DeathReportedBy=?" : "") . "
                    WHERE ResidentID=?";
            
            $params = [
                $first_name, $middle_name, $last_name, $suffix, $sex, 
                $birthdate, $birth_place, $civil_status, $contact_no, $email, $house_no, $building_name, $street, $purok ?: null, $area_name, $area_type, $barangay_name, $city_name, $province_name, $region_name, $zip_code, $psgc_region ?: null, $psgc_province ?: null, $psgc_municipal ?: null, $psgc_barangay ?: null, 
                $is_head, $rel_to_head, $family_head_id, $is_pwd, 
                $pwd_class, $pwd_id_val, $is_senior, 
                $is_solo, $income, 
                $emp_status, $emp_status_other, $occupation,
                $source_of_income, $source_income_other,
                $edu_level, $is_deceased, $is_voter,
                $voter_number, $religion, $nationality,
                $has_philhealth, $has_sss_gsis, $has_4ps,
                $is_sss, $is_gsis, $is_pagibig,
                $date_of_death, $place_of_death, $cause_of_death, $deceased_remarks,
                $latitude, $longitude
            ];

            if ($death_cert_filename) $params[] = $death_cert_filename;
            if ($newly_deceased) {
                $params[] = date('Y-m-d H:i:s');
                $params[] = $reporter_name;
            }
            $params[] = $resident_id;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

        } else {
            // ── ADD MODE: full duplicate prevention ─────────────────────────

            // 1. SMART DUPLICATE: all 5 fields must match
            $dup = $pdo->prepare("
                SELECT COUNT(*) FROM residents
                WHERE  FirstName               = ?
                  AND  COALESCE(MiddleName,'') = COALESCE(?,\"\")
                  AND  LastName                = ?
                  AND  COALESCE(Suffix,'')     = COALESCE(?,\"\")
                  AND  BirthDate               = ?
                  AND  (IsDeceased = 0 OR IsDeceased IS NULL)
            ");
            $dup->execute([$first_name, $middle_name ?: null, $last_name, $suffix ?: null, $birthdate]);
            if ($dup->fetchColumn() > 0) {
                redirectError(
                    'This resident already exists in the system. (Same name, suffix, and birthdate.) '
                    . 'If this is a different person, make sure the suffix (Jr./Sr./II/III) or birthdate is different.',
                    'add'
                );
            }

            // 2. CONTACT NUMBER UNIQUENESS
            if (!empty($contact_no)) {
                $chkPhone = $pdo->prepare("
                    SELECT COUNT(*) FROM residents
                    WHERE ContactNumber = ?
                      AND (IsDeceased = 0 OR IsDeceased IS NULL)
                ");
                $chkPhone->execute([$contact_no]);
                if ($chkPhone->fetchColumn() > 0) {
                    redirectError('This mobile number is already registered to another resident.', 'add');
                }
            }

            // 3. EMAIL UNIQUENESS
            if (!empty($email)) {
                $chkEmail = $pdo->prepare("
                    SELECT COUNT(*) FROM residents
                    WHERE Email = ?
                      AND (IsDeceased = 0 OR IsDeceased IS NULL)
                ");
                $chkEmail->execute([$email]);
                if ($chkEmail->fetchColumn() > 0) {
                    redirectError('This email address is already registered to another resident.', 'add');
                }
            }

            // 4. Generate ResidentCode
            $resident_code = generateResidentCode($pdo);

            $temp_password = bin2hex(random_bytes(4)); 
            $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);

            // A resident can (rarely) be registered while already deceased
            // (historical / backlog entries) — capture the report info too.
            $death_date_reported = ($is_deceased === 1) ? date('Y-m-d H:i:s') : null;
            $death_reported_by   = ($is_deceased === 1) ? $reporter_name : null;

            $sql = "INSERT INTO residents (
                        FirstName, MiddleName, LastName, Suffix, Sex, 
                        BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, BuildingName, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, 
                        IsHead, RelationshipToHead, FamilyHeadID, IsPWD, 
                        PWDClassification, PWDID, IsSenior, 
                        IsSoloParent, TotalHouseholdIncome, 
                        EmploymentStatus, EmploymentStatusOther, Occupation,
                        SourceOfIncome, SourceOfIncomeOther,
                        EducationLevel, IsDeceased, IsVoter,
                        VoterNumber, Religion, Nationality, Password,   
                        HasPhilhealth, HasSSSGSIS, Has4Ps,
                        IsSSSMember, IsGSISMember, IsPagibigMember,
                        DateOfDeath, PlaceOfDeath, CauseOfDeath, DeceasedRemarks,
                        DeathCertificate, DeathDateReported, DeathReportedBy,
                        Latitude, Longitude, ResidentCode
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            if ($stmt === false) {
                throw new RuntimeException('Failed to prepare resident insert statement.');
            }
            $stmt->execute([
                $first_name, $middle_name, $last_name, $suffix, $sex, 
                $birthdate, $birth_place, $civil_status, $contact_no, $email, $house_no, $building_name, $street, $purok ?: null, $area_name, $area_type, $barangay_name, $city_name, $province_name, $region_name, $zip_code, $psgc_region ?: null, $psgc_province ?: null, $psgc_municipal ?: null, $psgc_barangay ?: null, 
                $is_head, $rel_to_head, $family_head_id, $is_pwd, 
                $pwd_class, $pwd_id_val, $is_senior, 
                $is_solo, $income, 
                $emp_status, $emp_status_other, $occupation,
                $source_of_income, $source_income_other,
                $edu_level, $is_deceased, $is_voter,
                $voter_number, $religion, $nationality, $hashed_password,
                $has_philhealth, $has_sss_gsis, $has_4ps,
                $is_sss, $is_gsis, $is_pagibig,
                $date_of_death, $place_of_death, $cause_of_death, $deceased_remarks,
                $death_cert_filename, $death_date_reported, $death_reported_by,
                $latitude, $longitude, $resident_code
            ]);
            $new_resident_id = (int)$pdo->lastInsertId();

            if (!empty($email)) {
                sendInvitationEmail($email, $first_name, $temp_password, $pdo);
            }
        }

        // Late Household Head linking: Members profiled before their Head are
        // linked to this Head now (same House/Lot/Unit Number + Street, no Head yet).
        $linked_members = 0;
        $head_resident_id = ($action === 'edit') ? (int)$resident_id : (int)($new_resident_id ?? 0);
        if ($is_head === 1 && $is_deceased !== 1 && $head_resident_id > 0) {
            $linked_members = linkUnassignedHouseholdMembers($pdo, $head_resident_id, (string)$house_no, (string)$street);
        }

        // Activity log
        require_once __DIR__ . '/../../activity_log_helper.php';
        $action_type = ($action === 'edit') ? 'Edit Resident' : 'Create Resident';
        if ($action === 'edit') {
            $action_desc = "Updated resident: $first_name $last_name (ResidentID: $resident_id)";
        } else {
            $new_id = (int)($new_resident_id ?? 0);
            $action_desc = "Created new resident: $first_name $last_name"
                . ($resident_code ? " [Code: $resident_code]" : '')
                . ($new_id ? " (ResidentID: $new_id)" : '');
        }
        if ($linked_members > 0) {
            $action_desc .= " — linked {$linked_members} existing household member(s) to this Head";
        }
        log_activity('Residents', $action_type, $action_desc);

        $success_text = ($action === 'edit')
            ? "Resident profile updated successfully."
            : "New resident registered successfully.";
        if ($linked_members > 0) {
            $success_text .= " {$linked_members} existing household member(s) were linked to this Household Head.";
        }
        $success_msg = urlencode($success_text);
        header("Location: ../frontend/residents.php?status=success&message={$success_msg}");
        exit();

    } catch (PDOException $e) {
        // Catch DB-level UNIQUE constraint violations as a safety net
        if ($e->getCode() === '23000' || strpos($e->getMessage(), 'Duplicate entry') !== false) {
            $msg = 'A duplicate entry was detected.';
            if (strpos($e->getMessage(), 'uq_residents_contact') !== false) {
                $msg = 'This mobile number is already registered to another resident.';
            } elseif (strpos($e->getMessage(), 'uq_residents_email') !== false) {
                $msg = 'This email address is already registered to another resident.';
            }
            redirectError($msg, $action, $resident_id);
        }
        error_log("DB error in " . basename(__FILE__) . ": " . $e->getMessage());
        redirectError('A server error occurred. Please try again.', $action, $resident_id);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Late Household Head linking.
// Residents saved as Household Member without a Head (FamilyHeadID empty, or
// pointing to a resident that is no longer an active Head) at the same
// House/Lot/Unit Number + Street are linked to $headId. The household record
// uses the Household module helpers, so the HH-YYYY-#### rules apply and an
// existing household_survey row is reused (no duplicate household).
// Returns the number of members linked. Never blocks the resident save.
// ─────────────────────────────────────────────────────────────────────────────
function linkUnassignedHouseholdMembers(PDO $pdo, int $headId, string $houseNo, string $street): int {
    $norm = static function ($value): string {
        $value = mb_strtolower(trim((string)$value), 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    };
    $targetHouse  = $norm($houseNo);
    $targetStreet = $norm($street);
    if ($targetHouse === '' || $targetStreet === '') return 0;

    try {
        require_once __DIR__ . '/../../household/backend/household_common.php';
        hh_ensure_schema($pdo);

        $head = $pdo->prepare("SELECT Latitude, Longitude FROM residents WHERE ResidentID = ? AND IsHead = 1 LIMIT 1");
        $head->execute([$headId]);
        $headRow = $head->fetch(PDO::FETCH_ASSOC);
        if (!$headRow) return 0;

        $candidates = $pdo->prepare("
            SELECT m.ResidentID, m.HouseNumber, m.StreetName
            FROM residents m
            LEFT JOIN residents h ON h.ResidentID = m.FamilyHeadID
            WHERE m.ResidentID <> ?
              AND m.IsHead = 0
              AND (m.IsDeceased IS NULL OR m.IsDeceased = 0)
              AND (
                    m.FamilyHeadID IS NULL OR m.FamilyHeadID = 0
                 OR h.ResidentID IS NULL
                 OR h.IsHead <> 1
                 OR h.IsDeceased = 1
              )
        ");
        $candidates->execute([$headId]);

        $memberIds = [];
        foreach ($candidates->fetchAll(PDO::FETCH_ASSOC) as $m) {
            if ($norm($m['HouseNumber'] ?? '') === $targetHouse && $norm($m['StreetName'] ?? '') === $targetStreet) {
                $memberIds[] = (int)$m['ResidentID'];
            }
        }
        if (!$memberIds) {
            hh_ensure_household_record($pdo, $headId);
            return 0;
        }

        $hasPin = $headRow['Latitude'] !== null && $headRow['Latitude'] !== ''
            && $headRow['Longitude'] !== null && $headRow['Longitude'] !== '';

        $pdo->beginTransaction();
        $in = implode(',', array_fill(0, count($memberIds), '?'));
        if ($hasPin) {
            // Same rule as a confirmed member: the member uses the household's map pin.
            $pdo->prepare("UPDATE residents SET FamilyHeadID = ?, Latitude = ?, Longitude = ? WHERE ResidentID IN ($in)")
                ->execute(array_merge([$headId, $headRow['Latitude'], $headRow['Longitude']], $memberIds));
        } else {
            $pdo->prepare("UPDATE residents SET FamilyHeadID = ? WHERE ResidentID IN ($in)")
                ->execute(array_merge([$headId], $memberIds));
        }

        $surveyId = hh_ensure_household_record($pdo, $headId);
        if ($surveyId > 0) {
            sync_hh_members($pdo, $surveyId, $headId);
            log_hh_history(
                $pdo,
                $surveyId,
                'MEMBER_LINKED',
                count($memberIds) . ' previously profiled household member(s) linked to the new Household Head.',
                null,
                implode(',', $memberIds)
            );
        }
        $pdo->commit();

        return count($memberIds);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[CAPS] late household head linking: ' . $e->getMessage());
        return 0;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
function sendInvitationEmail($recipientEmail, $name, $tempPass, $pdo) {
    if (!$pdo) return;
    $mail = new PHPMailer(true);
    try {
        $token = bin2hex(random_bytes(32));
        $expiry = date("Y-m-d H:i:s", strtotime('+1 hour'));

        $stmt = $pdo->prepare("UPDATE residents SET ResetToken = ?, TokenExpiry = ? WHERE Email = ?");
        $stmt->execute([$token, $expiry, $recipientEmail]);

        $proto     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
        // Derive base path up to the project root (e.g. /SOE)
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        // admin_int is one level deep inside the project root
        $basePath  = dirname($scriptDir); // goes up from /SOE/admin_int → /SOE
        $resetLink = $proto . '://' . $host . $basePath . '/set_password.php?token=' . $token;

        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'rendellsubu65@gmail.com';
        $mail->Password   = 'prnm nbty ckda jwbt'; // synced with residents.php app password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('rendellsubu65@gmail.com', 'Brgy. Biñang 2nd — Resident Portal');
        $mail->addAddress($recipientEmail);

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = 'Account Registration — Brgy. Biñang 2nd Resident Portal';

        $mail->Body = "
<!DOCTYPE html>
<html lang='en'>
<head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body style='margin:0;padding:0;background:#eef2fb;font-family:Arial,sans-serif;'>

  <table width='100%' cellpadding='0' cellspacing='0' style='background:#eef2fb;padding:32px 16px;'>
    <tr><td align='center'>

      <table width='480' cellpadding='0' cellspacing='0' style='max-width:480px;width:100%;background:#ffffff;border-radius:24px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);'>

        <!-- Header -->
        <tr>
          <td style='background:linear-gradient(135deg,#1a3570 0%,#2a4fa0 100%);padding:36px 32px;text-align:center;'>
            <p style='margin:0 0 6px 0;font-size:11px;font-weight:800;letter-spacing:3px;color:rgba(255,255,255,0.5);text-transform:uppercase;'>Republic of the Philippines</p>
            <h1 style='margin:0;font-size:22px;font-weight:900;color:#ffffff;letter-spacing:1px;'>BARANGAY BIÑANG 2ND</h1>
            <p style='margin:8px 0 0 0;font-size:11px;color:rgba(255,255,255,0.55);letter-spacing:0.5px;'>Bocaue, Bulacan</p>
            <div style='width:40px;height:3px;background:#f05a00;border-radius:2px;margin:16px auto 0;'></div>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style='padding:36px 36px 12px;'>
            <p style='margin:0 0 8px;font-size:19px;font-weight:700;color:#0f172a;'>Welcome, {$name}!</p>
            <p style='margin:0 0 24px;font-size:13px;color:#64748b;line-height:1.7;'>
              Your account has been successfully registered in the
              <strong style='color:#1a3570;'>Barangay Biñang 2nd Resident Portal</strong>.
              Click the button below to set your password and activate your account.
            </p>

            <table width='100%' cellpadding='0' cellspacing='0' style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;margin-bottom:24px;'>
              <tr>
                <td style='padding:18px 20px;'>
                  <p style='margin:0 0 3px;font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#94a3b8;'>Registered Email</p>
                  <p style='margin:0;font-size:13px;font-weight:600;color:#334155;'>{$recipientEmail}</p>
                </td>
              </tr>
            </table>

            <p style='margin:0 0 20px;font-size:12px;color:#64748b;text-align:center;line-height:1.6;'>
              This link will expire in <strong style='color:#f05a00;'>1 hour</strong>.<br>
              If you did not expect this email, please disregard it.
            </p>

            <table width='100%' cellpadding='0' cellspacing='0'>
              <tr>
                <td align='center' style='padding-bottom:8px;'>
                  <a href='{$resetLink}'
                     style='display:inline-block;background:#f05a00;color:#ffffff;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:1.5px;text-decoration:none;padding:14px 36px;border-radius:12px;'>
                    Set Your Password
                  </a>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style='padding:20px 36px 32px;border-top:1px solid #f1f5f9;text-align:center;'>
            <p style='margin:0 0 4px;font-size:11px;font-weight:700;color:#94a3b8;letter-spacing:0.5px;'>Barangay Biñang 2nd &bull; Bocaue, Bulacan</p>
            <p style='margin:0;font-size:10px;color:#cbd5e1;'>Automated message — please do not reply to this email.</p>
          </td>
        </tr>

      </table>

    </td></tr>
  </table>

</body> 
</html>";
    
        $mail->send();
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
    }
}
?>
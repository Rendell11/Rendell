<?php

declare(strict_types=1);

/*
 * Shared helpers for the CAPS Household Management module.
 *
 * Keep this file inside:
 * admin/household/backend/household_common.php
 */

if (!function_exists('hh_csrf_token')) {
    function hh_csrf_token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        if (empty($_SESSION['hh_csrf_token'])) {
            $_SESSION['hh_csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['hh_csrf_token'];
    }
}

if (!function_exists('hh_csrf_verify')) {
    function hh_csrf_verify(?string $token = null): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        // Household pages post the shared CAPS token as csrf_token.
        if ($token === null) {
            $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        }

        if (!is_string($token) || $token === '') {
            return false;
        }

        foreach (['csrf_token', 'hh_csrf_token'] as $key) {
            $stored = (string) ($_SESSION[$key] ?? '');

            if ($stored !== '' && hash_equals($stored, $token)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('hh_full_name')) {
    function hh_full_name(array $row): string
    {
        $parts = [];

        foreach (['FirstName', 'MiddleName', 'LastName', 'Suffix'] as $field) {
            $value = trim((string) ($row[$field] ?? ''));

            if ($value !== '') {
                $parts[] = $value;
            }
        }

        if ($parts) {
            return implode(' ', $parts);
        }

        return trim((string) ($row['head_name'] ?? ''));
    }
}

if (!function_exists('hh_address_parts')) {
    function hh_address_parts(array $row): array
    {
        $parts = [];

        $houseNumber = trim((string) ($row['HouseNumber'] ?? ''));
        $street = trim((string) ($row['StreetName'] ?? ''));
        $area = trim((string) ($row['AreaName'] ?? ''));
        $purok = trim((string) ($row['Purok'] ?? ''));
        $barangay = trim((string) ($row['BarangayName'] ?? ''));
        $city = trim((string) ($row['CityMunicipalityName'] ?? ''));
        $province = trim((string) ($row['ProvinceName'] ?? ''));
        $region = trim((string) ($row['RegionName'] ?? ''));
        $zip = trim((string) ($row['ZipCode'] ?? ''));

        if ($houseNumber !== '') {
            $parts[] = $houseNumber;
        }

        if ($street !== '') {
            $parts[] = $street;
        }

        if ($area !== '') {
            $parts[] = $area;
        } elseif ($purok !== '') {
            $parts[] = $purok;
        }

        if ($barangay !== '') {
            $parts[] = 'Brgy. ' . $barangay;
        }

        if ($city !== '') {
            $parts[] = $city;
        }

        if ($province !== '') {
            $parts[] = $province;
        }

        if ($region !== '') {
            $parts[] = $region;
        }

        if ($zip !== '') {
            $parts[] = $zip;
        }

        return $parts;
    }
}

if (!function_exists('hh_address')) {
    function hh_address(array $row): string
    {
        $parts = hh_address_parts($row);

        if ($parts) {
            return implode(', ', $parts);
        }

        return trim((string) ($row['address'] ?? ''));
    }
}

if (!function_exists('hh_income_class')) {
    function hh_income_class(float $income): string
    {
        if ($income < 20000) {
            return 'Low Income';
        }

        if ($income < 40000) {
            return 'Lower Middle Income';
        }

        if ($income < 70000) {
            return 'Middle Income';
        }

        if ($income < 120000) {
            return 'Upper Middle Income';
        }

        return 'High Income';
    }
}

if (!function_exists('hh_actor_name')) {
    function hh_actor_name(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $possible = [
            $_SESSION['admin_name'] ?? null,
            $_SESSION['staff_name'] ?? null,
            $_SESSION['FullName'] ?? null,
            $_SESSION['full_name'] ?? null,
            $_SESSION['name'] ?? null,
            $_SESSION['username'] ?? null,
            $_SESSION['Username'] ?? null,
        ];

        foreach ($possible as $value) {
            $value = trim((string) $value);

            if ($value !== '') {
                return $value;
            }
        }

        return 'System';
    }
}

if (!function_exists('hh_json_ok')) {
    function hh_json_ok(array $data = []): never
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            array_merge(['success' => true], $data),
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }
}

if (!function_exists('hh_json_error')) {
    function hh_json_error(string $message, int $status = 400): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            [
                'success' => false,
                'error' => $message,
            ],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }
}

if (!function_exists('hh_normalize_household_id')) {
    /** Convert legacy HH-0004 values to the required HH-YYYY-0004 format. */
    function hh_normalize_household_id(?string $value, ?int $year = null, ?int $fallbackSequence = null): string
    {
        $year = $year ?: (int)date('Y');
        $value = trim((string)$value);

        if (preg_match('/^HH-(\d{4})-(\d+)$/', $value, $m)) {
            return 'HH-' . $m[1] . '-' . str_pad((string)((int)$m[2]), 4, '0', STR_PAD_LEFT);
        }

        if (preg_match('/^HH-(\d+)$/', $value, $m)) {
            return 'HH-' . $year . '-' . str_pad((string)((int)$m[1]), 4, '0', STR_PAD_LEFT);
        }

        if ($fallbackSequence !== null && $fallbackSequence > 0) {
            return 'HH-' . $year . '-' . str_pad((string)$fallbackSequence, 4, '0', STR_PAD_LEFT);
        }

        return 'HH-' . $year . '-0001';
    }
}

if (!function_exists('hh_next_household_id')) {
    function hh_next_household_id(PDO $pdo, ?int $year = null): string
    {
        $year = $year ?: (int)date('Y');
        $max = 0;

        // New-format IDs.
        try {
            $stmt = $pdo->prepare("SELECT HouseholdID FROM household_survey WHERE HouseholdID LIKE ?");
            $stmt->execute(['HH-' . $year . '-%']);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                if (preg_match('/^HH-\d{4}-(\d+)$/', (string)$id, $m)) {
                    $max = max($max, (int)$m[1]);
                }
            }
        } catch (Throwable $e) {
            // Optional household table may not exist in older installations.
        }

        // Legacy HH-0001 values created in the current year must still count
        // toward the sequence so the new numbering never collides logically.
        try {
            $stmt = $pdo->prepare("SELECT HouseholdID, SurveyID, DateCreated FROM household_survey WHERE HouseholdID LIKE 'HH-%' AND HouseholdID NOT LIKE 'HH-____-%'");
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $createdYear = !empty($row['DateCreated']) ? (int)date('Y', strtotime((string)$row['DateCreated'])) : $year;
                if ($createdYear !== $year) continue;
                if (preg_match('/^HH-(\d+)$/', (string)$row['HouseholdID'], $m)) {
                    $max = max($max, (int)$m[1]);
                } elseif (!empty($row['SurveyID'])) {
                    $max = max($max, (int)$row['SurveyID']);
                }
            }
        } catch (Throwable $e) {
            // Keep sequence generation available even on partially migrated DBs.
        }

        return 'HH-' . $year . '-' . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
if (!function_exists('hh_migrate_legacy_ids')) {
    /** Convert old HH-0001 style IDs to HH-YYYY-0001 once, preserving sequence/history. */
    function hh_migrate_legacy_ids(PDO $pdo): void
    {
        try {
            $pdo->beginTransaction();
            $rows = $pdo->query("SELECT SurveyID, HouseholdID, DateCreated FROM household_survey WHERE HouseholdID REGEXP '^HH-[0-9]+$' ORDER BY SurveyID ASC FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $legacy = (string)($row['HouseholdID'] ?? '');
                if (!preg_match('/^HH-(\d+)$/', $legacy, $m)) continue;
                $year = !empty($row['DateCreated']) ? (int)date('Y', strtotime((string)$row['DateCreated'])) : (int)date('Y');
                $seq = max(1, (int)$m[1]);
                $newId = 'HH-' . $year . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

                $check = $pdo->prepare("SELECT SurveyID FROM household_survey WHERE HouseholdID=? AND SurveyID<>? LIMIT 1");
                $check->execute([$newId, (int)$row['SurveyID']]);
                if ($check->fetchColumn()) {
                    $newId = hh_next_household_id($pdo, $year);
                }

                $pdo->prepare("UPDATE household_survey SET HouseholdID=? WHERE SurveyID=?")->execute([$newId, (int)$row['SurveyID']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[CAPS-HOUSEHOLD] legacy ID migration: ' . $e->getMessage());
        }
    }
}

if (!function_exists('sync_hh_members')) {
    function sync_hh_members(PDO $pdo, int $surveyId, int $headId): void
    {
        $rows = $pdo->prepare(
            "SELECT *
             FROM residents
             WHERE FamilyHeadID = ?
               AND (IsDeceased = 0 OR IsDeceased IS NULL)
             ORDER BY LastName, FirstName, ResidentID"
        );

        $rows->execute([$headId]);
        $members = $rows->fetchAll(PDO::FETCH_ASSOC);

        $pdo->prepare(
            "DELETE FROM household_survey_members WHERE SurveyID = ?"
        )->execute([$surveyId]);

        $insert = $pdo->prepare(
            "INSERT INTO household_survey_members
                (SurveyID, ResidentID, MemberNumber, full_name, sex, age,
                 relationship, civil_status, education, monthly_income)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $number = 1;

        foreach ($members as $member) {
            $age = null;

            if (!empty($member['BirthDate'])) {
                try {
                    $age = (new DateTime($member['BirthDate']))
                        ->diff(new DateTime())
                        ->y;
                } catch (Throwable $e) {
                    $age = null;
                }
            }

            $insert->execute([
                $surveyId,
                (int) $member['ResidentID'],
                $number++,
                hh_full_name($member),
                $member['Sex'] ?? null,
                $age,
                $member['RelationshipToHead'] ?? 'Member',
                $member['CivilStatus'] ?? null,
                $member['EducationLevel'] ?? null,
                $member['TotalHouseholdIncome'] ?? 0,
            ]);
        }
    }
}

if (!function_exists('log_hh_history')) {
    function log_hh_history(
        PDO $pdo,
        int $surveyId,
        string $actionType,
        string $description,
        ?string $oldValue = null,
        ?string $newValue = null
    ): void {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO household_history
                    (SurveyID, ActionType, Description, OldValue, NewValue, ActorName, CreatedAt)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())"
            );

            $stmt->execute([
                $surveyId,
                $actionType,
                $description,
                $oldValue,
                $newValue,
                hh_actor_name(),
            ]);
        } catch (Throwable $e) {
            error_log('[CAPS-HOUSEHOLD] history: ' . $e->getMessage());
        }
    }
}

if (!function_exists('hh_ensure_schema')) {
    /**
     * Additive, idempotent schema guard for the Household module.
     * Only ADDs missing columns/tables and relaxes NOT NULL on optional head
     * profile fields; never drops or rewrites existing data.
     * Run it outside a transaction (DDL commits implicitly in MySQL).
     */
    function hh_ensure_schema(PDO $pdo): void
    {
        static $done = false;

        if ($done || $pdo->inTransaction()) {
            return;
        }

        $done = true;

        try {
            $cols = $pdo->query(
                "SELECT COLUMN_NAME, IS_NULLABLE
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'household_survey'"
            )->fetchAll(PDO::FETCH_KEY_PAIR);

            if ($cols) {
                $add = [
                    'status' => "VARCHAR(20) NOT NULL DEFAULT 'active'",
                    'income_classification' => 'VARCHAR(50) NULL',
                    'house_type' => 'VARCHAR(80) NULL',
                    'tenure_status' => 'VARCHAR(80) NULL',
                    'removal_reason_other' => 'VARCHAR(255) NULL',
                    'inactive_since' => 'DATETIME NULL',
                    'inactive_by' => 'VARCHAR(255) NULL',
                ];

                foreach ($add as $column => $definition) {
                    if (!array_key_exists($column, $cols)) {
                        $pdo->exec("ALTER TABLE household_survey ADD COLUMN `{$column}` {$definition}");

                        if ($column === 'status') {
                            $pdo->exec("UPDATE household_survey SET status = 'inactive' WHERE COALESCE(is_removed, 0) = 1");
                        }
                    }
                }

                // Contact number / sex are optional on the Resident record.
                $relax = [
                    'contact_number' => 'VARCHAR(30) NULL',
                    'civil_status' => 'VARCHAR(30) NULL',
                    'sex' => "ENUM('Male','Female') NULL",
                    'income_bracket' => 'VARCHAR(50) NULL',
                ];

                foreach ($relax as $column => $definition) {
                    if (($cols[$column] ?? 'YES') === 'NO') {
                        $pdo->exec("ALTER TABLE household_survey MODIFY COLUMN `{$column}` {$definition}");
                    }
                }
            }

            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS household_history (
                    HistoryID int unsigned NOT NULL AUTO_INCREMENT,
                    SurveyID int unsigned NOT NULL,
                    ActionType varchar(50) NOT NULL,
                    Description text,
                    OldValue text NULL,
                    NewValue text NULL,
                    OldAddress text NULL,
                    NewAddress text NULL,
                    ActorName varchar(255) NULL,
                    CreatedAt timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (HistoryID),
                    KEY idx_household_history_survey (SurveyID)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            $historyCols = $pdo->query(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'household_history'"
            )->fetchAll(PDO::FETCH_COLUMN);

            foreach (['OldValue', 'NewValue', 'OldAddress', 'NewAddress'] as $column) {
                if (!in_array($column, $historyCols, true)) {
                    $pdo->exec("ALTER TABLE household_history ADD COLUMN `{$column}` text NULL");
                }
            }

            // Resident module's address_api.php (profile) selects barangay_profile.address,
            // which the rebuild script does not create. The Household transfer modal reuses
            // that endpoint, so add the column (additive only) when it is missing.
            $profileCols = $pdo->query(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barangay_profile'"
            )->fetchAll(PDO::FETCH_COLUMN);

            if ($profileCols && !in_array('address', $profileCols, true)) {
                $pdo->exec("ALTER TABLE barangay_profile ADD COLUMN address VARCHAR(512) NULL");
            }
        } catch (Throwable $e) {
            error_log('[CAPS-HOUSEHOLD] schema check: ' . $e->getMessage());
        }
    }
}

if (!function_exists('hh_active_survey_for_head')) {
    /** Active household_survey row for a head resident, or null. */
    function hh_active_survey_for_head(PDO $pdo, int $headId): ?array
    {
        if ($headId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT * FROM household_survey
             WHERE ResidentID = ?
               AND COALESCE(status, 'active') = 'active'
               AND COALESCE(is_removed, 0) = 0
             ORDER BY SurveyID DESC
             LIMIT 1"
        );
        $stmt->execute([$headId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('hh_ensure_household_record')) {
    /**
     * Heads created from the Resident module may not have a household_survey
     * row yet. Give them one with a real HH-YYYY-NNNN ID (instead of an ID
     * derived from ResidentID) so every Household action has a SurveyID.
     */
    function hh_ensure_household_record(PDO $pdo, int $headId): int
    {
        $existing = hh_active_survey_for_head($pdo, $headId);

        if ($existing) {
            return (int) $existing['SurveyID'];
        }

        $ownTransaction = !$pdo->inTransaction();

        try {
            if ($ownTransaction) {
                $pdo->beginTransaction();
            }

            $stmt = $pdo->prepare(
                "SELECT * FROM residents
                 WHERE ResidentID = ? AND IsHead = 1
                   AND (IsDeceased = 0 OR IsDeceased IS NULL)
                 LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$headId]);
            $head = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$head) {
                if ($ownTransaction) {
                    $pdo->rollBack();
                }

                return 0;
            }

            $existing = hh_active_survey_for_head($pdo, $headId);

            if ($existing) {
                if ($ownTransaction) {
                    $pdo->commit();
                }

                return (int) $existing['SurveyID'];
            }

            $classification = hh_income_class((float) ($head['TotalHouseholdIncome'] ?? 0));
            $address = hh_address($head);

            $pdo->prepare(
                "INSERT INTO household_survey
                    (HouseholdID, ResidentID, head_name, address, civil_status, sex,
                     contact_number, income_bracket, income_classification, status, is_removed)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 0)"
            )->execute([
                'PENDING-' . bin2hex(random_bytes(8)),
                $headId,
                hh_full_name($head),
                $address,
                $head['CivilStatus'] ?? null,
                $head['Sex'] ?? null,
                $head['ContactNumber'] ?? null,
                $classification,
                $classification,
            ]);

            $surveyId = (int) $pdo->lastInsertId();
            $householdId = hh_next_household_id($pdo, (int) date('Y'));

            $pdo->prepare("UPDATE household_survey SET HouseholdID = ? WHERE SurveyID = ?")
                ->execute([$householdId, $surveyId]);

            sync_hh_members($pdo, $surveyId, $headId);
            log_hh_history(
                $pdo,
                $surveyId,
                'CREATED',
                "Household record {$householdId} registered for existing head " . hh_full_name($head) . '.',
                null,
                $address
            );

            if ($ownTransaction) {
                $pdo->commit();
            }

            return $surveyId;
        } catch (Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('[CAPS-HOUSEHOLD] ensure household record: ' . $e->getMessage());

            if (!$ownTransaction) {
                throw $e;
            }

            return 0;
        }
    }
}

if (!function_exists('hh_ensure_missing_household_records')) {
    /** Register household rows for active heads that still have none. */
    function hh_ensure_missing_household_records(PDO $pdo): void
    {
        try {
            $ids = $pdo->query(
                "SELECT r.ResidentID
                 FROM residents r
                 WHERE r.IsHead = 1
                   AND (r.IsDeceased = 0 OR r.IsDeceased IS NULL)
                   AND NOT EXISTS (
                       SELECT 1 FROM household_survey hs
                       WHERE hs.ResidentID = r.ResidentID
                         AND COALESCE(hs.status, 'active') = 'active'
                         AND COALESCE(hs.is_removed, 0) = 0
                   )
                 ORDER BY r.CreatedAt ASC, r.ResidentID ASC"
            )->fetchAll(PDO::FETCH_COLUMN);

            foreach ($ids as $headId) {
                hh_ensure_household_record($pdo, (int) $headId);
            }
        } catch (Throwable $e) {
            error_log('[CAPS-HOUSEHOLD] ensure missing household records: ' . $e->getMessage());
        }
    }
}

if (!function_exists('hh_norm_address_part')) {
    /** Same canonicalization the Resident module uses for household matching. */
    function hh_norm_address_part($value): string
    {
        $value = mb_strtolower(trim((string) $value), 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}

if (!function_exists('hh_find_household_at_address')) {
    /**
     * Active household whose head lives at the same House Number + Street
     * (the two fields the Resident module matches on). Returns null if none.
     */
    function hh_find_household_at_address(PDO $pdo, array $address, int $excludeResidentId = 0): ?array
    {
        $house = hh_norm_address_part($address['HouseNumber'] ?? '');
        $street = hh_norm_address_part($address['StreetName'] ?? '');

        if ($house === '' || $street === '') {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT r.*
             FROM residents r
             WHERE r.IsHead = 1
               AND r.ResidentID <> ?
               AND (r.IsDeceased = 0 OR r.IsDeceased IS NULL)
             ORDER BY r.ResidentID ASC"
        );
        $stmt->execute([$excludeResidentId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $head) {
            if (hh_norm_address_part($head['HouseNumber'] ?? '') !== $house
                || hh_norm_address_part($head['StreetName'] ?? '') !== $street) {
                continue;
            }

            $surveyId = hh_ensure_household_record($pdo, (int) $head['ResidentID']);

            if ($surveyId <= 0) {
                continue;
            }

            $survey = $pdo->prepare("SELECT HouseholdID FROM household_survey WHERE SurveyID = ?");
            $survey->execute([$surveyId]);

            return [
                'survey_id' => $surveyId,
                'household_id' => (string) $survey->fetchColumn(),
                'head_id' => (int) $head['ResidentID'],
                'head_name' => hh_full_name($head),
                'address' => hh_address($head),
            ];
        }

        return null;
    }
}

if (!function_exists('hh_report_branding')) {
    /**
     * Barangay name/logo/address/captain for reports, read from the database
     * the same way the Resident report does. Paths assume a file in
     * admin/household/backend/.
     */
    function hh_report_branding(PDO $pdo): array
    {
        $brand = [
            'name' => 'Barangay',
            'address' => '',
            'captain' => 'Barangay Captain',
            'logo_src' => '',
            'has_logo' => false,
            'printed_by' => hh_actor_name(),
        ];

        $profile = null;

        try {
            $profile = $pdo->query(
                "SELECT logo_path, brgy_name, address, region_name, province_name,
                        municipality_name, barangay_name, zip_code
                 FROM barangay_profile WHERE id = 1 LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            // Older barangay_profile tables only have name + logo.
            try {
                $profile = $pdo->query("SELECT logo_path, brgy_name FROM barangay_profile WHERE id = 1 LIMIT 1")
                    ->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $ignored) {
                $profile = null;
            }
        }

        if ($profile) {
            $brand['name'] = trim((string) ($profile['brgy_name'] ?? '')) ?: (trim((string) ($profile['barangay_name'] ?? '')) ?: 'Barangay');
            $brand['address'] = trim((string) ($profile['address'] ?? ''));

            if ($brand['address'] === '') {
                $brand['address'] = implode(', ', array_filter([
                    $profile['barangay_name'] ?? '',
                    $profile['municipality_name'] ?? '',
                    $profile['province_name'] ?? '',
                    $profile['region_name'] ?? '',
                    $profile['zip_code'] ?? '',
                ], static fn($v) => trim((string) $v) !== ''));
            }

            $logo = trim((string) ($profile['logo_path'] ?? ''));

            if ($logo !== '') {
                $rootDir = dirname(__DIR__, 3);
                $brand['has_logo'] = file_exists($rootDir . '/' . ltrim($logo, '/\\'));
                $brand['logo_src'] = '../../../' . ltrim($logo, '/\\');
            }
        }

        try {
            $captain = $pdo->query(
                "SELECT NULLIF(TRIM(CONCAT_WS(' ', r.FirstName, r.MiddleName, r.LastName, r.Suffix)), '') AS CaptainName
                 FROM officials o
                 LEFT JOIN residents r ON r.ResidentID = o.ResidentID
                 WHERE o.Position IN ('Barangay Captain', 'Punong Barangay')
                   AND (o.TermEnd IS NULL OR o.TermEnd >= CURDATE())
                 ORDER BY o.TermStart DESC, o.OfficialID DESC
                 LIMIT 1"
            )->fetchColumn();

            if (!empty($captain)) {
                $brand['captain'] = (string) $captain;
            }
        } catch (Throwable $e) {
            // Keep the generic label when officials are not configured.
        }

        return $brand;
    }
}

if (!function_exists('hh_combined_income_sql')) {
    /**
     * SQL expression: combined monthly income of a household = the Head's recorded
     * income + every living member's recorded income (residents.TotalHouseholdIncome
     * holds each resident's own monthly income). $alias is the Head's residents alias.
     */
    function hh_combined_income_sql(string $alias = 'r'): string
    {
        $a = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 'r';

        return "(COALESCE({$a}.TotalHouseholdIncome, 0) + COALESCE((
                    SELECT SUM(COALESCE(ci.TotalHouseholdIncome, 0))
                    FROM residents ci
                    WHERE ci.FamilyHeadID = {$a}.ResidentID
                      AND ci.ResidentID <> {$a}.ResidentID
                      AND (ci.IsDeceased = 0 OR ci.IsDeceased IS NULL)
                ), 0))";
    }
}

if (!function_exists('hh_combined_income')) {
    /** Combined monthly income from the Head row + member rows (each with TotalHouseholdIncome). */
    function hh_combined_income(array $head, array $members): float
    {
        $sum = (float) ($head['TotalHouseholdIncome'] ?? 0);

        foreach ($members as $m) {
            $sum += (float) ($m['TotalHouseholdIncome'] ?? $m['monthly_income'] ?? 0);
        }

        return round($sum, 2);
    }
}

if (!function_exists('hh_socioeconomic_status')) {
    /**
     * Socioeconomic Status from the household's combined monthly income and size,
     * following the PIDS income-class method: monthly income per person compared
     * with the monthly per-capita poverty threshold.
     *   ratio = (combined monthly income ÷ household members) ÷ poverty threshold per person
     *   < 1 Poor · 1–<2 Low Income (not poor) · 2–<4 Lower Middle Income · 4–<7 Middle Income
     *   7–<12 Upper Middle Income · 12–<20 Upper Income (not rich) · ≥ 20 Rich
     * Threshold: HH_POVERTY_LINE_PER_CAPITA in .env, else ₱2,774.33/person/month
     * (PSA 2023 annual per-capita poverty threshold ₱33,292 ÷ 12; ₱13,873 for a family of five).
     */
    function hh_socioeconomic_status(float $combinedIncome, int $members): array
    {
        $members = max(1, $members);
        $line = (float) (getenv('HH_POVERTY_LINE_PER_CAPITA') ?: 0);
        if ($line <= 0) {
            $line = 2774.33;
        }

        $perCapita = $combinedIncome / $members;
        $ratio = $perCapita / $line;

        $classes = [
            [1, 'Poor', 'Below the poverty threshold'],
            [2, 'Low Income (Not Poor)', 'Between 1× and 2× the poverty threshold'],
            [4, 'Lower Middle Income', 'Between 2× and 4× the poverty threshold'],
            [7, 'Middle Income', 'Between 4× and 7× the poverty threshold'],
            [12, 'Upper Middle Income', 'Between 7× and 12× the poverty threshold'],
            [20, 'Upper Income (Not Rich)', 'Between 12× and 20× the poverty threshold'],
            [INF, 'Rich', 'At least 20× the poverty threshold'],
        ];

        foreach ($classes as [$max, $label, $range]) {
            if ($ratio < $max) {
                return [
                    'label' => $label,
                    'range' => $range,
                    'per_capita' => round($perCapita, 2),
                    'ratio' => round($ratio, 2),
                    'poverty_line' => $line,
                    'members' => $members,
                    'combined' => round($combinedIncome, 2),
                ];
            }
        }

        return [];
    }
}

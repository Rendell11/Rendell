<?php
/**
 * id_helper.php — shared record ID numbering for every module.
 *
 * Format: [PREFIX]-[YEAR]-[####]   e.g. RES-2026-0001, HH-2026-0001, DOC-2026-0001
 *   - prefix = first 3 letters of the module (Household = HH)
 *   - year   = creation year; a new year restarts at 0001
 *   - numbers are never reused (deleted / archived records keep theirs, gaps are OK)
 *   - an ID never changes when the record's status changes
 *
 * Usage:
 *   require_once __DIR__.'/id_helper.php';
 *   $docNo = next_record_id($pdo, 'DOC', ['table' => 'document_requests', 'column' => 'doc_number']);
 *
 * The optional $seed tells the helper where existing IDs already live, so the
 * very first call for a prefix/year starts after the highest number in use
 * and never produces a duplicate of an older record.
 *
 * Other modules still use their own generators and can be switched later
 * (they are NOT changed now so nothing breaks):
 *   - residents/backend/residents.php            RES-YYYY-#### (COUNT-based)
 *   - household/backend/household_common.php     HH-YYYY-####  (MAX-based)
 *   - staffbpso/backend/process_staff.php        STF-YYYY-#### (COUNT-based)
 *   - announcement/backend/process_disaster.php  DIS-YYYY-#### (row id based)
 *   - announcement/backend/announcement_analytics_data.php / get_trash.php  ANN-YYYY-#### (row id based, display only)
 */

if (!function_exists('id_helper_migrate')) {
    function id_helper_migrate(PDO $pdo): void {
        static $done = false;
        if ($done) return;
        // Check first: CREATE TABLE is DDL, and DDL silently commits any open transaction.
        $exists = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'id_sequences'")->fetchColumn();
        if ((int)$exists) { $done = true; return; }
        if ($pdo->inTransaction()) throw new RuntimeException('id_sequences table is missing; call id_helper_migrate() before starting a transaction.');
        $pdo->exec("CREATE TABLE IF NOT EXISTS `id_sequences` (
            `prefix`     VARCHAR(10) NOT NULL,
            `year`       SMALLINT UNSIGNED NOT NULL,
            `last_value` INT UNSIGNED NOT NULL DEFAULT 0, -- backticks needed: LAST_VALUE is reserved in MySQL 8
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`prefix`, `year`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done = true;
    }
}

if (!function_exists('format_record_id')) {
    function format_record_id(string $prefix, int $year, int $n): string {
        return strtoupper($prefix) . '-' . $year . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('next_record_id')) {
    /**
     * Reserve and return the next ID for $prefix in $year (default: this year).
     * @param array|null $seed ['table' => ..., 'column' => ...] where existing IDs are stored.
     */
    function next_record_id(PDO $pdo, string $prefix, ?array $seed = null, ?int $year = null): string {
        id_helper_migrate($pdo);
        $prefix = strtoupper(preg_replace('/[^A-Za-z]/', '', $prefix));
        $year = $year ?: (int)date('Y');

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            // First use of this prefix/year: start after the highest number already in use.
            $exists = $pdo->prepare("SELECT `last_value` FROM `id_sequences` WHERE `prefix` = ? AND `year` = ? FOR UPDATE");
            $exists->execute([$prefix, $year]);
            if ($exists->fetchColumn() === false) {
                $start = 0;
                if ($seed && preg_match('/^\w+$/', $seed['table'] ?? '') && preg_match('/^\w+$/', $seed['column'] ?? '')) {
                    $like = $prefix . '-' . $year . '-%';
                    $q = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(`{$seed['column']}`, '-', -1) AS UNSIGNED))
                                        FROM `{$seed['table']}` WHERE `{$seed['column']}` LIKE ?");
                    $q->execute([$like]);
                    $start = (int)$q->fetchColumn();
                }
                $pdo->prepare("INSERT IGNORE INTO `id_sequences` (`prefix`, `year`, `last_value`) VALUES (?, ?, ?)")
                    ->execute([$prefix, $year, $start]);
            }
            $pdo->prepare("UPDATE `id_sequences` SET `last_value` = LAST_INSERT_ID(`last_value` + 1) WHERE `prefix` = ? AND `year` = ?")
                ->execute([$prefix, $year]);
            $n = (int)$pdo->query("SELECT LAST_INSERT_ID()")->fetchColumn();
            if ($ownTx) $pdo->commit();
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return format_record_id($prefix, $year, $n);
    }
}

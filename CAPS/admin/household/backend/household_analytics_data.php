<?php
/**
 * household_analytics_data.php — Household Analytics data (actual household_survey + residents rows).
 * Shared by the Household Analytics page, the AI analytics endpoint and the Save PDF / Print report.
 *   GET ?start=YYYY-MM-DD&end=YYYY-MM-DD   (both optional)
 *   - both empty → every active household
 *   - a range    → households registered (household_survey.DateCreated) within the range
 * Income = combined monthly income of the Head + all living members; Income Status and
 * Socioeconomic Status use the same helpers as View Household (household_common.php).
 * Direct request → JSON. Required by another file → functions only.
 */
declare(strict_types=1);

require_once __DIR__ . '/household_common.php';
require_once __DIR__ . '/../../residents/backend/resident_analytics_data.php'; // ra_params, ra_sorted, ra_top, ra_pct

if (!function_exists('hha_compute')) {
    function hha_income_bracket(float $v): string
    {
        if ($v <= 0) return 'No recorded income';
        if ($v < 10000) return 'Below ₱10,000';
        if ($v < 20000) return '₱10,000–19,999';
        if ($v < 40000) return '₱20,000–39,999';
        if ($v < 70000) return '₱40,000–69,999';
        if ($v < 120000) return '₱70,000–119,999';
        return '₱120,000 and above';
    }

    function hha_median(array $vals): ?float
    {
        if (!$vals) return null;
        sort($vals);
        $n = count($vals);
        return $n % 2 ? (float) $vals[intdiv($n, 2)] : round(($vals[$n / 2 - 1] + $vals[$n / 2]) / 2, 2);
    }

    function hha_age(?string $bd, DateTimeImmutable $today): ?int
    {
        $bd = (string) $bd;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bd) || $bd === '0000-00-00') return null;
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $bd);
        if (!$d || $d > $today) return null;
        $a = $today->diff($d)->y;
        return $a <= 130 ? $a : null;
    }

    function hha_compute(PDO $pdo, array $p): array
    {
        hh_ensure_schema($pdo);
        $where = ["COALESCE(hs.status,'active') = 'active'", 'COALESCE(hs.is_removed,0) = 0', 'r.IsHead = 1',
                  '(r.IsDeceased = 0 OR r.IsDeceased IS NULL)'];
        $args = [];
        if ($p['start'] !== '') { $where[] = 'hs.DateCreated >= ?'; $args[] = $p['start'] . ' 00:00:00'; }
        if ($p['end'] !== '') { $where[] = 'hs.DateCreated <= ?'; $args[] = $p['end'] . ' 23:59:59'; }
        $st = $pdo->prepare("SELECT hs.SurveyID, hs.HouseholdID, hs.DateCreated, hs.house_type, hs.tenure_status, hs.housing_tenure, r.*
                             FROM household_survey hs JOIN residents r ON r.ResidentID = hs.ResidentID
                             WHERE " . implode(' AND ', $where) . " ORDER BY hs.HouseholdID");
        $st->execute($args);
        $heads = $st->fetchAll(PDO::FETCH_ASSOC);

        $memStmt = $pdo->prepare("SELECT * FROM residents WHERE FamilyHeadID = ? AND ResidentID <> ? AND (IsDeceased = 0 OR IsDeceased IS NULL)");
        $today = new DateTimeImmutable('today');
        $ageBands = ['0–4' => [0, 4], '5–12' => [5, 12], '13–17' => [13, 17], '18–29' => [18, 29],
                     '30–44' => [30, 44], '45–59' => [45, 59], '60–69' => [60, 69], '70+' => [70, 200]];
        $classOrder = ['Low Income', 'Lower Middle Income', 'Middle Income', 'Upper Middle Income', 'High Income'];
        $sesOrder = ['Poor', 'Low Income (Not Poor)', 'Lower Middle Income', 'Middle Income', 'Upper Middle Income', 'Upper Income (Not Rich)', 'Rich'];
        $bracketOrder = ['No recorded income', 'Below ₱10,000', '₱10,000–19,999', '₱20,000–39,999', '₱40,000–69,999', '₱70,000–119,999', '₱120,000 and above'];

        $t = ['households' => 0, 'persons' => 0, 'members' => 0, 'single_person' => 0, 'with_senior' => 0, 'with_pwd' => 0,
              'with_minor' => 0, 'female_headed' => 0, 'male_headed' => 0, 'with_4ps' => 0, 'no_income' => 0,
              'low_income' => 0, 'poor' => 0, 'total_income' => 0.0, 'avg_income' => null, 'median_income' => null,
              'avg_per_capita' => null, 'avg_size' => null, 'median_size' => null, 'earners' => 0, 'pinned' => 0];
        $size = ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0, '6' => 0, '7+' => 0];
        $incomeClass = array_fill_keys($classOrder, 0);
        $ses = array_fill_keys($sesOrder, 0);
        $brackets = array_fill_keys($bracketOrder, 0);
        $headSex = ['Male' => 0, 'Female' => 0, 'Not specified' => 0];
        $headAge = array_fill_keys(array_keys($ageBands), 0);
        $memberAge = array_fill_keys(array_keys($ageBands), ['male' => 0, 'female' => 0, 'other' => 0, 'total' => 0]);
        $relationship = []; $houseType = []; $tenure = []; $areas = []; $streets = []; $monthly = []; $rows = [];
        $incomes = []; $sizes = []; $perCapita = [];

        foreach ($heads as $h) {
            $memStmt->execute([(int) $h['ResidentID'], (int) $h['ResidentID']]);
            $members = $memStmt->fetchAll(PDO::FETCH_ASSOC);
            $people = array_merge([$h], $members);
            $n = count($people);
            $income = hh_combined_income($h, $members);
            $class = hh_income_class($income);
            $sesRow = hh_socioeconomic_status($income, $n);

            $t['households']++;
            $t['persons'] += $n;
            $t['members'] += count($members);
            $t['total_income'] += $income;
            $incomes[] = $income; $sizes[] = $n; $perCapita[] = $sesRow['per_capita'];
            $size[$n >= 7 ? '7+' : (string) $n]++;
            $incomeClass[$class] = ($incomeClass[$class] ?? 0) + 1;
            $ses[$sesRow['label']] = ($ses[$sesRow['label']] ?? 0) + 1;
            $brackets[hha_income_bracket($income)]++;
            if ($n === 1) $t['single_person']++;
            if ($class === 'Low Income') $t['low_income']++;
            if ($sesRow['label'] === 'Poor') $t['poor']++;
            if ($income <= 0) $t['no_income']++;
            if (hh_valid_gps($h['Latitude'] ?? null, $h['Longitude'] ?? null)) $t['pinned']++;

            $s = strtolower(trim((string) $h['Sex']));
            $hs = $s === 'male' ? 'Male' : ($s === 'female' ? 'Female' : 'Not specified');
            $headSex[$hs]++;
            if ($hs === 'Female') $t['female_headed']++;
            if ($hs === 'Male') $t['male_headed']++;
            $ha = hha_age($h['BirthDate'] ?? null, $today);
            if ($ha !== null) foreach ($ageBands as $band => [$lo, $hi]) { if ($ha >= $lo && $ha <= $hi) { $headAge[$band]++; break; } }

            $hasSenior = $hasPwd = $hasMinor = $has4ps = false;
            $earners = 0;
            foreach ($people as $pp) {
                $a = hha_age($pp['BirthDate'] ?? null, $today);
                $ps = strtolower(trim((string) $pp['Sex']));
                $sk = $ps === 'male' ? 'male' : ($ps === 'female' ? 'female' : 'other');
                if ($a !== null) {
                    foreach ($ageBands as $band => [$lo, $hi]) {
                        if ($a >= $lo && $a <= $hi) { $memberAge[$band][$sk]++; $memberAge[$band]['total']++; break; }
                    }
                    if ($a < 18) $hasMinor = true;
                    if ($a >= 60) $hasSenior = true;
                }
                if ((int) ($pp['IsSenior'] ?? 0) === 1) $hasSenior = true;
                if ((int) ($pp['IsPWD'] ?? 0) === 1) $hasPwd = true;
                if ((int) ($pp['Has4Ps'] ?? 0) === 1) $has4ps = true;
                if ((float) ($pp['TotalHouseholdIncome'] ?? 0) > 0) $earners++;
            }
            foreach ($members as $m) {
                $rel = trim((string) ($m['RelationshipToHead'] ?? '')) ?: 'Member (not specified)';
                $relationship[$rel] = ($relationship[$rel] ?? 0) + 1;
            }
            $t['earners'] += $earners;
            if ($hasSenior) $t['with_senior']++;
            if ($hasPwd) $t['with_pwd']++;
            if ($hasMinor) $t['with_minor']++;
            if ($has4ps) $t['with_4ps']++;

            $ht = trim((string) ($h['house_type'] ?? '')) ?: 'Not recorded';
            $houseType[$ht] = ($houseType[$ht] ?? 0) + 1;
            $tn = trim((string) ($h['tenure_status'] ?? $h['housing_tenure'] ?? '')) ?: 'Not recorded';
            $tenure[$tn] = ($tenure[$tn] ?? 0) + 1;

            $area = trim((string) ($h['AreaName'] ?: $h['Purok'])) ?: 'Not specified';
            if (!isset($areas[$area])) $areas[$area] = ['area' => $area, 'households' => 0, 'persons' => 0, 'income' => 0.0, 'low_income' => 0, 'poor' => 0];
            $areas[$area]['households']++;
            $areas[$area]['persons'] += $n;
            $areas[$area]['income'] += $income;
            if ($class === 'Low Income') $areas[$area]['low_income']++;
            if ($sesRow['label'] === 'Poor') $areas[$area]['poor']++;
            $street = trim((string) $h['StreetName']) ?: 'Not specified';
            $streets[$street] = ($streets[$street] ?? 0) + 1;

            $month = substr((string) $h['DateCreated'], 0, 7);
            if (preg_match('/^\d{4}-\d{2}$/', $month)) $monthly[$month] = ($monthly[$month] ?? 0) + 1;

            $rows[] = [
                'household_id' => (string) $h['HouseholdID'],
                'head' => hh_full_name($h),
                'address' => implode(', ', array_filter([trim((string) $h['HouseNumber']), trim((string) $h['StreetName']), trim((string) ($h['AreaName'] ?: $h['Purok']))])) ?: '—',
                'size' => $n,
                'earners' => $earners,
                'income' => round($income, 2),
                'per_capita' => $sesRow['per_capita'],
                'income_class' => $class,
                'ses' => $sesRow['label'],
            ];
        }

        if ($t['households']) {
            $t['avg_size'] = round($t['persons'] / $t['households'], 2);
            $t['median_size'] = hha_median($sizes);
            $t['avg_income'] = round($t['total_income'] / $t['households'], 2);
            $t['median_income'] = hha_median($incomes);
            $t['avg_per_capita'] = round(array_sum($perCapita) / count($perCapita), 2);
        }
        $t['total_income'] = round($t['total_income'], 2);
        $t['poverty_line'] = hh_socioeconomic_status(0, 1)['poverty_line'];
        $t['headless'] = count(hh_headless_households($pdo));

        // Households set inactive (deactivated) within the range.
        $iw = ["(COALESCE(status,'active') = 'inactive' OR COALESCE(is_removed,0) = 1)"];
        $ia = [];
        if ($p['start'] !== '') { $iw[] = 'COALESCE(inactive_since, removed_at) >= ?'; $ia[] = $p['start'] . ' 00:00:00'; }
        if ($p['end'] !== '') { $iw[] = 'COALESCE(inactive_since, removed_at) <= ?'; $ia[] = $p['end'] . ' 23:59:59'; }
        $iq = $pdo->prepare("SELECT COUNT(*) FROM household_survey WHERE " . implode(' AND ', $iw));
        $iq->execute($ia);
        $t['inactive'] = (int) $iq->fetchColumn();

        foreach ($areas as &$a) { $a['avg_income'] = $a['households'] ? round($a['income'] / $a['households'], 2) : 0; }
        unset($a);
        $areasList = array_values($areas);
        usort($areasList, static fn($x, $y) => ($y['households'] <=> $x['households']) ?: strcasecmp($x['area'], $y['area']));
        ksort($monthly);

        $largest = $rows;
        usort($largest, static fn($x, $y) => ($y['size'] <=> $x['size']) ?: strcmp($x['household_id'], $y['household_id']));
        $lowest = array_values(array_filter($rows, static fn($r) => $r['size'] > 0));
        usort($lowest, static fn($x, $y) => ($x['per_capita'] <=> $y['per_capita']) ?: strcmp($x['household_id'], $y['household_id']));

        $comparison = null;
        if (empty($p['no_compare']) && $p['start'] !== '' && $p['end'] !== '') {
            $days = (int) (new DateTime($p['start']))->diff(new DateTime($p['end']))->days + 1;
            $prevEnd = (new DateTime($p['start']))->modify('-1 day');
            $prevStart = (clone $prevEnd)->modify('-' . ($days - 1) . ' days');
            $prev = hha_compute($pdo, ['start' => $prevStart->format('Y-m-d'), 'end' => $prevEnd->format('Y-m-d'), 'label' => '', 'no_compare' => true] + ['_nested' => true]);
            $pt = $prev['totals'];
            $comparison = [
                'previous_label' => ra_range_label($prevStart->format('Y-m-d'), $prevEnd->format('Y-m-d')),
                'rows' => [
                    ['metric' => 'Households registered', 'current' => $t['households'], 'previous' => $pt['households']],
                    ['metric' => 'Persons in those households', 'current' => $t['persons'], 'previous' => $pt['persons']],
                    ['metric' => 'Low Income households', 'current' => $t['low_income'], 'previous' => $pt['low_income']],
                    ['metric' => 'Poor households (below poverty threshold)', 'current' => $t['poor'], 'previous' => $pt['poor']],
                    ['metric' => 'Average combined income (₱)', 'current' => $t['avg_income'] ?? 0, 'previous' => $pt['avg_income'] ?? 0],
                ],
            ];
        }

        return [
            'period' => $p,
            'totals' => $t,
            'size' => $size,
            'income_class' => $incomeClass,
            'ses' => $ses,
            'brackets' => $brackets,
            'head_sex' => $headSex,
            'head_age' => $headAge,
            'member_age' => $memberAge,
            'relationship' => ra_sorted($relationship),
            'house_type' => ra_sorted($houseType),
            'tenure' => ra_sorted($tenure),
            'areas' => $areasList,
            'streets' => ra_sorted($streets),
            'monthly' => $monthly,
            'largest' => array_slice($largest, 0, 15),
            'lowest_per_capita' => array_slice($lowest, 0, 15),
            'comparison' => $comparison,
            'generated_at' => date('F j, Y g:i A'),
        ];
    }

    /** Aggregated figures only (no names) — sent to Gemini and used as the AI cache key. */
    function hha_snapshot(array $a): array
    {
        return [
            'date_range' => $a['period']['label'],
            'totals' => $a['totals'],
            'household_size' => $a['size'],
            'income_status' => $a['income_class'],
            'socioeconomic_status' => $a['ses'],
            'combined_income_brackets' => $a['brackets'],
            'head_sex' => $a['head_sex'],
            'head_age_bands' => $a['head_age'],
            'member_age_bands' => array_map(static fn($b) => $b['total'], $a['member_age']),
            'relationship_to_head' => ra_top($a['relationship'], 10),
            'house_type' => $a['house_type'],
            'tenure' => $a['tenure'],
            'areas' => array_map(static fn($x) => array_intersect_key($x, array_flip(['area', 'households', 'persons', 'avg_income', 'low_income', 'poor'])), array_slice($a['areas'], 0, 15)),
            'registrations_per_month' => $a['monthly'],
            'comparison_with_previous_period' => $a['comparison'],
        ];
    }

    /** Short automatic interpretation (always available, no AI). */
    function hha_interpretation(array $a): array
    {
        $t = $a['totals'];
        $peso = static fn($v) => '₱' . number_format((float) $v, 2);
        if (!$t['households']) {
            return ['No active households match the selected date range.'
                . ($t['headless'] ? ' ' . $t['headless'] . ' household(s) currently have no Head.' : '')];
        }
        $out = [];
        $out[] = number_format($t['households']) . ' active household(s) with ' . number_format($t['persons']) . ' person(s) are covered; the average household has '
            . $t['avg_size'] . ' member(s) (median ' . $t['median_size'] . '), and ' . number_format($t['single_person']) . ' are one-person households.';
        $out[] = 'Average combined monthly income is ' . $peso($t['avg_income']) . ' (median ' . $peso($t['median_income']) . '); average income per member is '
            . $peso($t['avg_per_capita']) . ' against a poverty threshold of ' . $peso($t['poverty_line']) . ' per person.';
        $out[] = number_format($t['low_income']) . ' household(s) (' . ra_pct($t['low_income'], $t['households']) . '%) are Low Income by combined income, and '
            . number_format($t['poor']) . ' (' . ra_pct($t['poor'], $t['households']) . '%) are below the poverty threshold per member.';
        if ($t['no_income']) $out[] = number_format($t['no_income']) . ' household(s) have no recorded income from any member — verify whether income is missing or truly zero.';
        $out[] = number_format($t['female_headed']) . ' household(s) (' . ra_pct($t['female_headed'], $t['households']) . '%) are headed by women; '
            . number_format($t['with_senior']) . ' include a senior citizen, ' . number_format($t['with_minor']) . ' include a minor, and ' . number_format($t['with_pwd']) . ' include a PWD.';
        $areas = array_values(array_filter($a['areas'], static fn($x) => $x['area'] !== 'Not specified'));
        if ($areas) $out[] = 'The area with the most households is ' . $areas[0]['area'] . ' (' . number_format($areas[0]['households']) . ' households, ' . number_format($areas[0]['poor']) . ' below the poverty threshold).';
        if ($t['headless']) $out[] = number_format($t['headless']) . ' household(s) currently have no Head and need a new Head.';
        if ($a['comparison']) {
            $c = $a['comparison']['rows'][0];
            $out[] = 'Households registered: ' . number_format($c['current']) . ' in this period vs ' . number_format($c['previous']) . ' in the previous period of the same length.';
        }
        return $out;
    }
}

// Direct request → JSON for the analytics page.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    require_once __DIR__ . '/../../db.php';
    require_once __DIR__ . '/../../auth_check.php';
    require_once __DIR__ . '/../../permission_helper.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!staff_can($pdo, 'households', 'read')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied.']);
        exit;
    }
    try {
        $a = hha_compute($pdo, ra_params($_GET));
        $a['interpretation'] = hha_interpretation($a);
        echo json_encode(['success' => true, 'data' => $a], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[Household analytics] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Unable to load household analytics.']);
    }
}

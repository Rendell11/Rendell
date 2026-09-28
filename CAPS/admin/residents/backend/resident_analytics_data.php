<?php
/**
 * resident_analytics_data.php — Resident Analytics data (actual `residents` / `household_survey` rows).
 * Shared by the Resident Analytics page, the AI analytics endpoint and the Save PDF / Print report.
 *   GET ?start=YYYY-MM-DD&end=YYYY-MM-DD   (both optional)
 *   - both empty  → every resident record
 *   - a range     → only residents registered (CreatedAt) within the range
 * Direct request → JSON. Required by another file → functions only.
 */
declare(strict_types=1);

if (!function_exists('ra_params')) {
    /** Validated date range. Empty values mean "no limit". */
    function ra_params(array $in): array
    {
        $valid = static function ($v): string {
            $v = trim((string)$v);
            if ($v === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return '';
            $d = DateTime::createFromFormat('!Y-m-d', $v);
            return ($d && $d->format('Y-m-d') === $v) ? $v : '';
        };
        $start = $valid($in['start'] ?? '');
        $end = $valid($in['end'] ?? '');
        if ($start !== '' && $end !== '' && $start > $end) [$start, $end] = [$end, $start];

        return ['start' => $start, 'end' => $end, 'label' => ra_range_label($start, $end)];
    }

    function ra_range_label(string $start, string $end): string
    {
        $f = static fn($d) => date('F j, Y', strtotime($d));
        if ($start === '' && $end === '') return 'All records (no date range)';
        if ($start !== '' && $end !== '') return $f($start) . ' – ' . $f($end);
        return $start !== '' ? 'From ' . $f($start) : 'Up to ' . $f($end);
    }

    /** Count helper: [label => count] sorted by count desc, label asc. */
    function ra_sorted(array $counts): array
    {
        uksort($counts, static function ($a, $b) use ($counts) {
            return ($counts[$b] <=> $counts[$a]) ?: strcasecmp((string)$a, (string)$b);
        });
        return $counts;
    }

    /** Fold everything after $keep items into "Other" so charts stay readable. */
    function ra_top(array $counts, int $keep, string $otherLabel = 'Others'): array
    {
        $counts = ra_sorted($counts);
        if (count($counts) <= $keep) return $counts;
        $head = array_slice($counts, 0, $keep, true);
        $head[$otherLabel] = ($head[$otherLabel] ?? 0) + array_sum(array_slice($counts, $keep, null, true));
        return $head;
    }

    function ra_pct(int|float $n, int|float $d): float
    {
        return $d > 0 ? round($n / $d * 100, 1) : 0.0;
    }

    function ra_clean(?string $v, string $empty = 'Not specified'): string
    {
        $v = trim((string)$v);
        return $v === '' ? $empty : $v;
    }

    /**
     * Every figure on the analytics page / report, computed from the actual rows.
     */
    function ra_compute(PDO $pdo, array $p): array
    {
        $where = [];
        $args = [];
        if ($p['start'] !== '') { $where[] = 'r.CreatedAt >= ?'; $args[] = $p['start'] . ' 00:00:00'; }
        if ($p['end'] !== '') { $where[] = 'r.CreatedAt <= ?'; $args[] = $p['end'] . ' 23:59:59'; }
        $sql = "SELECT r.ResidentID, r.ResidentCode, r.FirstName, r.MiddleName, r.LastName, r.Suffix,
                       r.Sex, r.BirthDate, r.CivilStatus, r.Religion, r.HouseNumber, r.StreetName,
                       r.Purok, r.AreaName, r.AreaType, r.IsHead, r.RelationshipToHead, r.FamilyHeadID,
                       r.EmploymentStatus, r.EducationLevel, r.TotalHouseholdIncome,
                       r.IsPWD, r.IsSenior, r.IsSoloParent, r.IsVoter, r.HasPhilhealth, r.Has4Ps,
                       r.IsSSSMember, r.IsGSISMember, r.IsPagibigMember, r.HasSSSGSIS,
                       r.IsDeceased, r.CreatedAt
                FROM residents r" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . "
                ORDER BY r.ResidentID";
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $today = new DateTimeImmutable('today');
        $ageBands = ['0–4' => [0, 4], '5–12' => [5, 12], '13–17' => [13, 17], '18–29' => [18, 29],
                     '30–44' => [30, 44], '45–59' => [45, 59], '60–69' => [60, 69], '70+' => [70, 200]];

        $t = ['total' => 0, 'male' => 0, 'female' => 0, 'other_sex' => 0, 'active' => 0, 'deceased' => 0,
              'heads' => 0, 'members' => 0, 'unlinked' => 0, 'households' => 0,
              'seniors' => 0, 'pwd' => 0, 'solo_parent' => 0, 'voters' => 0, 'philhealth' => 0, '4ps' => 0,
              'sss' => 0, 'gsis' => 0, 'pagibig' => 0, 'with_income' => 0, 'minors' => 0, 'working_age' => 0,
              'elderly' => 0, 'unknown_age' => 0, 'avg_age' => null, 'median_age' => null];
        $age = array_fill_keys(array_keys($ageBands), ['male' => 0, 'female' => 0, 'other' => 0, 'total' => 0]);
        $sex = ['Male' => 0, 'Female' => 0, 'Not specified' => 0];
        $civil = []; $religion = []; $relationship = []; $employment = []; $education = [];
        $areas = []; $streets = []; $monthly = [];
        $headsById = []; $membersOf = [];
        $ages = [];

        foreach ($rows as $r) {
            $t['total']++;
            $s = strtolower(trim((string)$r['Sex']));
            $sexKey = $s === 'male' ? 'Male' : ($s === 'female' ? 'Female' : 'Not specified');
            $sex[$sexKey]++;
            if ($sexKey === 'Male') $t['male']++; elseif ($sexKey === 'Female') $t['female']++; else $t['other_sex']++;

            $dead = (int)$r['IsDeceased'] === 1;
            if ($dead) { $t['deceased']++; } else { $t['active']++; }

            $month = substr((string)$r['CreatedAt'], 0, 7);
            if (preg_match('/^\d{4}-\d{2}$/', $month)) $monthly[$month] = ($monthly[$month] ?? 0) + 1;

            // Everything below describes the living population.
            if ($dead) continue;

            $a = null;
            $bd = (string)$r['BirthDate'];
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $bd) && $bd !== '0000-00-00') {
                $dob = DateTimeImmutable::createFromFormat('!Y-m-d', $bd);
                if ($dob && $dob <= $today) $a = $today->diff($dob)->y;
            }
            if ($a === null || $a > 130) {
                $t['unknown_age']++;
            } else {
                $ages[] = $a;
                foreach ($ageBands as $label => [$lo, $hi]) {
                    if ($a >= $lo && $a <= $hi) {
                        $age[$label]['total']++;
                        $age[$label][$sexKey === 'Male' ? 'male' : ($sexKey === 'Female' ? 'female' : 'other')]++;
                        break;
                    }
                }
                if ($a < 18) $t['minors']++; elseif ($a < 60) $t['working_age']++; else $t['elderly']++;
            }

            $civil[ra_clean($r['CivilStatus'])] = ($civil[ra_clean($r['CivilStatus'])] ?? 0) + 1;
            $rel = trim((string)$r['Religion']);
            if ($rel !== '') $religion[$rel] = ($religion[$rel] ?? 0) + 1;
            $emp = ra_clean($r['EmploymentStatus']);
            $employment[$emp] = ($employment[$emp] ?? 0) + 1;
            $edu = trim((string)$r['EducationLevel']);
            if ($edu !== '') $education[$edu] = ($education[$edu] ?? 0) + 1;

            if ((int)$r['IsHead'] === 1) {
                $t['heads']++;
                $relKey = 'Household Head';
                $headsById[(int)$r['ResidentID']] = $r;
            } elseif (!empty($r['FamilyHeadID'])) {
                $t['members']++;
                $relKey = ra_clean($r['RelationshipToHead'], 'Member (not specified)');
                $membersOf[(int)$r['FamilyHeadID']][] = (int)$r['ResidentID'];
            } else {
                $t['unlinked']++;
                $relKey = 'No household linked';
            }
            $relationship[$relKey] = ($relationship[$relKey] ?? 0) + 1;

            $area = trim((string)($r['AreaName'] ?: $r['Purok']));
            $areaKey = $area === '' ? 'Not specified' : $area;
            if (!isset($areas[$areaKey])) {
                $areas[$areaKey] = ['area' => $areaKey, 'type' => trim((string)$r['AreaType']) ?: ($area === '' ? '—' : 'Area'),
                    'total' => 0, 'male' => 0, 'female' => 0, 'households' => 0, 'seniors' => 0, 'pwd' => 0, 'minors' => 0];
            }
            $areas[$areaKey]['total']++;
            if ($sexKey === 'Male') $areas[$areaKey]['male']++;
            if ($sexKey === 'Female') $areas[$areaKey]['female']++;
            if ((int)$r['IsHead'] === 1) $areas[$areaKey]['households']++;
            if ((int)$r['IsSenior'] === 1) $areas[$areaKey]['seniors']++;
            if ((int)$r['IsPWD'] === 1) $areas[$areaKey]['pwd']++;
            if ($a !== null && $a < 18) $areas[$areaKey]['minors']++;

            $street = ra_clean($r['StreetName']);
            $streets[$street] = ($streets[$street] ?? 0) + 1;

            foreach ([['seniors', 'IsSenior'], ['pwd', 'IsPWD'], ['solo_parent', 'IsSoloParent'], ['voters', 'IsVoter'],
                      ['philhealth', 'HasPhilhealth'], ['4ps', 'Has4Ps'], ['sss', 'IsSSSMember'],
                      ['gsis', 'IsGSISMember'], ['pagibig', 'IsPagibigMember']] as [$k, $col]) {
                if ((int)$r[$col] === 1) $t[$k]++;
            }
            if ((float)($r['TotalHouseholdIncome'] ?? 0) > 0) $t['with_income']++;
        }

        if ($ages) {
            sort($ages);
            $n = count($ages);
            $t['avg_age'] = round(array_sum($ages) / $n, 1);
            $t['median_age'] = $n % 2 ? $ages[intdiv($n, 2)] : round(($ages[$n / 2 - 1] + $ages[$n / 2]) / 2, 1);
        }

        // Households = active Heads in the selected records; Household ID from the Household module.
        $codes = [];
        if ($headsById) {
            try {
                $ids = array_keys($headsById);
                $q = $pdo->prepare("SELECT ResidentID, HouseholdID FROM household_survey
                                    WHERE ResidentID IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
                                    ORDER BY SurveyID DESC");
                $q->execute($ids);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $h) $codes[(int)$h['ResidentID']] ??= (string)$h['HouseholdID'];
            } catch (Throwable $e) {
                error_log('[Resident analytics] household codes: ' . $e->getMessage());
            }
        }
        $households = [];
        $sizeDist = ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0, '6' => 0, '7+' => 0];
        foreach ($headsById as $hid => $h) {
            $size = 1 + count($membersOf[$hid] ?? []);
            $sizeDist[$size >= 7 ? '7+' : (string)$size]++;
            $households[] = [
                'household_id' => $codes[$hid] ?? '—',
                'head' => trim(implode(' ', array_filter([$h['FirstName'], $h['MiddleName'], $h['LastName'], $h['Suffix']]))),
                'address' => implode(', ', array_filter([trim((string)$h['HouseNumber']), trim((string)$h['StreetName']),
                                  trim((string)($h['AreaName'] ?: $h['Purok']))])) ?: '—',
                'size' => $size,
            ];
        }
        usort($households, static fn($a, $b) => ($b['size'] <=> $a['size']) ?: strcmp($a['household_id'], $b['household_id']));
        $t['households'] = count($headsById);
        $t['avg_household_size'] = $households ? round(array_sum(array_column($households, 'size')) / count($households), 2) : null;

        $areasList = array_values($areas);
        usort($areasList, static fn($a, $b) => ($b['total'] <=> $a['total']) ?: strcasecmp($a['area'], $b['area']));
        ksort($monthly);

        // Previous period of equal length (only when both dates are set).
        $comparison = null;
        if ($p['start'] !== '' && $p['end'] !== '') {
            $days = (int)(new DateTime($p['start']))->diff(new DateTime($p['end']))->days + 1;
            $prevEnd = (new DateTime($p['start']))->modify('-1 day');
            $prevStart = (clone $prevEnd)->modify('-' . ($days - 1) . ' days');
            $cq = $pdo->prepare("SELECT COUNT(*) AS total,
                        SUM(CASE WHEN Sex='Male' THEN 1 ELSE 0 END) AS male,
                        SUM(CASE WHEN Sex='Female' THEN 1 ELSE 0 END) AS female,
                        SUM(CASE WHEN IsHead=1 AND COALESCE(IsDeceased,0)=0 THEN 1 ELSE 0 END) AS heads,
                        SUM(CASE WHEN COALESCE(IsDeceased,0)=1 THEN 1 ELSE 0 END) AS deceased
                    FROM residents WHERE CreatedAt BETWEEN ? AND ?");
            $cq->execute([$prevStart->format('Y-m-d') . ' 00:00:00', $prevEnd->format('Y-m-d') . ' 23:59:59']);
            $prev = array_map('intval', $cq->fetch(PDO::FETCH_ASSOC) ?: []);
            $comparison = [
                'previous_label' => ra_range_label($prevStart->format('Y-m-d'), $prevEnd->format('Y-m-d')),
                'rows' => [
                    ['metric' => 'Residents registered', 'current' => $t['total'], 'previous' => $prev['total'] ?? 0],
                    ['metric' => 'Male', 'current' => $t['male'], 'previous' => $prev['male'] ?? 0],
                    ['metric' => 'Female', 'current' => $t['female'], 'previous' => $prev['female'] ?? 0],
                    ['metric' => 'New Household Heads', 'current' => $t['heads'], 'previous' => $prev['heads'] ?? 0],
                    ['metric' => 'Deceased records', 'current' => $t['deceased'], 'previous' => $prev['deceased'] ?? 0],
                ],
            ];
        }

        return [
            'period' => $p,
            'totals' => $t,
            'sex' => $sex,
            'age' => $age,
            'civil_status' => ra_sorted($civil),
            'religion' => ra_sorted($religion),
            'relationship' => ra_sorted($relationship),
            'household_size' => $sizeDist,
            'households' => $households,
            'areas' => $areasList,
            'streets' => ra_sorted($streets),
            'employment' => ra_sorted($employment),
            'education' => ra_sorted($education),
            'programs' => [
                'Registered Voter' => $t['voters'], 'PhilHealth' => $t['philhealth'], '4Ps' => $t['4ps'],
                'SSS' => $t['sss'], 'GSIS' => $t['gsis'], 'Pag-IBIG' => $t['pagibig'],
                'Senior Citizen' => $t['seniors'], 'PWD' => $t['pwd'], 'Solo Parent' => $t['solo_parent'],
            ],
            'monthly' => $monthly,
            'comparison' => $comparison,
            'generated_at' => date('F j, Y g:i A'),
        ];
    }

    /** Aggregated figures only (no names) — sent to Gemini and used as the AI cache key. */
    function ra_snapshot(array $a): array
    {
        $t = $a['totals'];
        return [
            'date_range' => $a['period']['label'],
            'totals' => array_intersect_key($t, array_flip(['total', 'active', 'deceased', 'male', 'female', 'other_sex',
                'households', 'heads', 'members', 'unlinked', 'avg_household_size', 'minors', 'working_age', 'elderly',
                'unknown_age', 'avg_age', 'median_age', 'seniors', 'pwd', 'solo_parent', 'voters', 'philhealth', '4ps',
                'sss', 'gsis', 'pagibig', 'with_income'])),
            'age_bands' => array_map(static fn($b) => $b['total'], $a['age']),
            'civil_status' => $a['civil_status'],
            'religion' => ra_top($a['religion'], 8),
            'relationship_to_head' => ra_top($a['relationship'], 10),
            'household_size' => $a['household_size'],
            'areas' => array_map(static fn($x) => array_intersect_key($x, array_flip(['area', 'total', 'households', 'seniors', 'pwd', 'minors'])),
                array_slice($a['areas'], 0, 15)),
            'streets' => ra_top($a['streets'], 12),
            'employment' => $a['employment'],
            'education' => $a['education'],
            'registrations_per_month' => $a['monthly'],
            'comparison_with_previous_period' => $a['comparison'],
        ];
    }

    /** Short automatic interpretation (always available, no AI). */
    function ra_interpretation(array $a): array
    {
        $t = $a['totals'];
        if (!$t['total']) return ['No resident records match the selected date range.'];
        $out = [];
        $out[] = number_format($t['total']) . ' resident record(s) are covered: ' . number_format($t['active']) . ' active and '
            . number_format($t['deceased']) . ' deceased (' . ra_pct($t['deceased'], $t['total']) . '%).';
        $out[] = 'By sex: ' . ra_pct($t['male'], $t['total']) . '% male, ' . ra_pct($t['female'], $t['total']) . '% female'
            . ($t['other_sex'] ? ', ' . ra_pct($t['other_sex'], $t['total']) . '% not specified' : '') . ' of all covered records.';
        $bands = array_map(static fn($b) => $b['total'], $a['age']);
        if (array_sum($bands) > 0) {
            arsort($bands);
            $top = array_key_first($bands);
            $out[] = "The largest age group is {$top} years (" . number_format($bands[$top]) . ' residents); '
                . number_format($t['minors']) . ' are under 18 and ' . number_format($t['elderly']) . ' are 60 or older'
                . ($t['median_age'] !== null ? '; the median age is ' . $t['median_age'] . '.' : '.');
        }
        if ($t['households'] > 0) {
            $out[] = number_format($t['households']) . ' household(s) are headed by residents in these records, with an average of '
                . $t['avg_household_size'] . ' member(s) each (Head included).';
        }
        if ($t['unlinked'] > 0) {
            $out[] = number_format($t['unlinked']) . ' living resident(s) are not linked to any household; linking them keeps household figures complete.';
        }
        $areas = array_values(array_filter($a['areas'], static fn($x) => $x['area'] !== 'Not specified'));
        if ($areas) {
            $out[] = 'The most populated area is ' . $areas[0]['area'] . ' with ' . number_format($areas[0]['total']) . ' living resident(s) ('
                . ra_pct($areas[0]['total'], max(1, $t['active'])) . '% of the living population).';
        }
        if ($t['unknown_age'] > 0) {
            $out[] = number_format($t['unknown_age']) . ' living resident(s) have no valid birth date, so they are not counted in the age statistics.';
        }
        if ($a['comparison']) {
            $cur = $a['comparison']['rows'][0]['current'];
            $prev = $a['comparison']['rows'][0]['previous'];
            $out[] = 'Registrations: ' . number_format($cur) . ' in this period vs ' . number_format($prev) . ' in the previous period of the same length ('
                . ($prev > 0 ? (($cur >= $prev ? '+' : '') . ra_pct($cur - $prev, $prev) . '%') : 'no records before') . ').';
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
    if (!staff_can($pdo, 'residents', 'read')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied.']);
        exit;
    }
    try {
        $a = ra_compute($pdo, ra_params($_GET));
        $a['interpretation'] = ra_interpretation($a);
        echo json_encode(['success' => true, 'data' => $a], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[Resident analytics] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Unable to load resident analytics.']);
    }
}

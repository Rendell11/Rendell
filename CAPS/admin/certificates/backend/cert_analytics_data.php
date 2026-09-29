<?php
/**
 * cert_analytics_data.php — Legal Document (certificate) Analytics data from the actual
 * document_requests + residents rows. Shared by the analytics page, the AI analytics endpoint
 * and the Save PDF / Print report (same structure as Resident / Household Analytics).
 *   GET ?start=YYYY-MM-DD&end=YYYY-MM-DD   (both optional)
 *   - both empty → every request on record
 *   - a range    → requests made (DateRequested) within the range
 * Direct request → JSON {success, data}. Required by another file → functions only.
 */
require_once __DIR__ . '/cert_common.php';
require_once __DIR__ . '/../../residents/backend/resident_analytics_data.php'; // ra_params, ra_range_label, ra_pct

if (!function_exists('cert_fmt_duration')) {
    function cert_fmt_duration(?float $minutes): string {
        if ($minutes === null) return '—';
        if ($minutes < 60) return round($minutes) . ' min';
        if ($minutes < 60 * 24) return round($minutes / 60, 1) . ' h';
        return round($minutes / 1440, 1) . ' days';
    }
}

/** Validated date range: empty = no limit (same rules as Resident / Household Analytics). */
function cert_analytics_params(array $in): array {
    return ra_params($in);
}

function cert_analytics_compute(PDO $pdo, array $p): array {
    $where = []; $args = [];
    if ($p['start'] !== '') { $where[] = 'dr.DateRequested >= ?'; $args[] = $p['start'] . ' 00:00:00'; }
    if ($p['end'] !== '') { $where[] = 'dr.DateRequested <= ?'; $args[] = $p['end'] . ' 23:59:59'; }
    $W = $where ? implode(' AND ', $where) : '1 = 1';
    $rq = function (string $sql, array $extra = []) use ($pdo, $args) { $s = $pdo->prepare($sql); $s->execute(array_merge($args, $extra)); return $s->fetchAll(PDO::FETCH_ASSOC); };

    $t = $rq("SELECT COUNT(*) total,
            SUM(dr.request_type = 'walk-in') walkin, SUM(dr.request_type = 'online') online,
            SUM(dr.Status = 'Released') released, SUM(dr.Status IN ('Pending','Review')) pending,
            SUM(dr.Status = 'Ready to Pick Up') ready, SUM(dr.Status = 'Rejected') rejected, SUM(dr.Status = 'Expired') expired,
            SUM(dr.Status = 'Preview') preview,
            SUM(dr.request_type = 'online' AND dr.Status IN ('Ready to Pick Up','Released','Expired')) accepted_online,
            COUNT(DISTINCT dr.ResidentID) residents, COUNT(DISTINCT dr.DocType) doc_types,
            AVG(CASE WHEN dr.Status = 'Released' AND dr.release_date IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, dr.DateRequested, dr.release_date) END) avg_min,
            AVG(CASE WHEN dr.Status = 'Released' AND dr.request_type = 'walk-in' THEN TIMESTAMPDIFF(MINUTE, dr.DateRequested, dr.release_date) END) walkin_min,
            AVG(CASE WHEN dr.Status = 'Released' AND dr.request_type = 'online' THEN TIMESTAMPDIFF(MINUTE, dr.DateRequested, dr.release_date) END) online_min
        FROM document_requests dr WHERE $W")[0];
    $mins = ['avg_min' => $t['avg_min'], 'walkin_min' => $t['walkin_min'], 'online_min' => $t['online_min']];
    unset($t['avg_min'], $t['walkin_min'], $t['online_min']);
    $t = array_map(fn($v) => (int)$v, $t);
    foreach ($mins as $k => $v) $t[$k] = $v !== null ? round((float)$v, 1) : null;
    $t['release_rate'] = $t['total'] ? ra_pct($t['released'], $t['total']) : null;
    $t['rejection_rate'] = $t['online'] ? ra_pct($t['rejected'], $t['online']) : null;
    $t['unclaimed_rate'] = $t['accepted_online'] ? ra_pct($t['expired'], $t['accepted_online']) : null;

    $byDoc = array_map(fn($r) => [
        'label' => (string)$r['label'], 'total' => (int)$r['n'], 'walkin' => (int)$r['w'], 'online' => (int)$r['o'],
        'released' => (int)$r['rel'], 'rejected' => (int)$r['rej'], 'pending' => (int)$r['pen'],
        'avg' => cert_fmt_duration($r['m'] !== null ? (float)$r['m'] : null),
    ], $rq("SELECT COALESCE(NULLIF(dr.DocType,''),'Not specified') label, COUNT(*) n, SUM(dr.request_type='walk-in') w, SUM(dr.request_type='online') o,
                   SUM(dr.Status='Released') rel, SUM(dr.Status='Rejected') rej, SUM(dr.Status IN ('Pending','Review')) pen,
                   AVG(CASE WHEN dr.Status='Released' THEN TIMESTAMPDIFF(MINUTE, dr.DateRequested, dr.release_date) END) m
            FROM document_requests dr WHERE $W GROUP BY label ORDER BY n DESC, label"));
    $t['top_document'] = $byDoc[0]['label'] ?? null;
    $t['top_document_n'] = $byDoc[0]['total'] ?? 0;

    $status = array_fill_keys(cert_statuses(), 0);
    foreach ($rq("SELECT dr.Status s, COUNT(*) n FROM document_requests dr WHERE $W GROUP BY dr.Status") as $r) $status[$r['s'] ?: 'Not specified'] = (int)$r['n'];
    $status = array_filter($status);

    $purpose = [];
    foreach ($rq("SELECT COALESCE(NULLIF(TRIM(dr.Purpose),''),'Not specified') l, COUNT(*) n FROM document_requests dr WHERE $W GROUP BY l ORDER BY n DESC, l LIMIT 12") as $r) $purpose[$r['l']] = (int)$r['n'];

    // Rejection reasons ("Others: …" typed reasons grouped under Others).
    $reasons = [];
    foreach ($rq("SELECT dr.rejection_reason r, COUNT(*) n FROM document_requests dr WHERE $W AND dr.Status = 'Rejected' GROUP BY r") as $r) {
        $reason = trim((string)$r['r']) ?: 'Not specified';
        if (stripos($reason, 'Others:') === 0) $reason = 'Others';
        $reasons[$reason] = ($reasons[$reason] ?? 0) + (int)$r['n'];
    }
    arsort($reasons);

    // Over time: per day when the data spans up to 62 days, otherwise per month.
    $span = $rq("SELECT MIN(dr.DateRequested) a, MAX(dr.DateRequested) b FROM document_requests dr WHERE $W")[0];
    $from = $p['start'] !== '' ? $p['start'] : ($span['a'] ? substr($span['a'], 0, 10) : null);
    $to = $p['end'] !== '' ? $p['end'] : ($span['b'] ? substr($span['b'], 0, 10) : null);
    $series = [];
    $daily = false;
    if ($from && $to) {
        $daily = (strtotime($to) - strtotime($from)) / 86400 <= 62;
        $fmt = $daily ? '%Y-%m-%d' : '%Y-%m';
        $map = [];
        foreach ($rq("SELECT DATE_FORMAT(dr.DateRequested, '$fmt') k, SUM(dr.request_type='walk-in') w, SUM(dr.request_type='online') o, SUM(dr.Status='Released') r
                      FROM document_requests dr WHERE $W GROUP BY k") as $r) $map[$r['k']] = $r;
        $cur = strtotime($daily ? $from : date('Y-m-01', strtotime($from)));
        $end = strtotime($to);
        for ($i = 0; $cur <= $end && $i < 400; $i++) {
            $k = date($daily ? 'Y-m-d' : 'Y-m', $cur);
            $series[$k] = ['walkin' => (int)($map[$k]['w'] ?? 0), 'online' => (int)($map[$k]['o'] ?? 0), 'released' => (int)($map[$k]['r'] ?? 0)];
            $cur = strtotime($daily ? '+1 day' : '+1 month', $cur);
        }
    }

    $weekday = array_fill_keys(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], 0);
    $wk = array_keys($weekday);
    foreach ($rq("SELECT WEEKDAY(dr.DateRequested) d, COUNT(*) n FROM document_requests dr WHERE $W GROUP BY d") as $r) $weekday[$wk[(int)$r['d']]] = (int)$r['n'];
    $hours = [];
    foreach ($rq("SELECT HOUR(dr.DateRequested) h, COUNT(*) n FROM document_requests dr WHERE $W GROUP BY h ORDER BY h") as $r) $hours[date('g A', mktime((int)$r['h'], 0))] = (int)$r['n'];
    $dayNames = ['Mon' => 'Monday', 'Tue' => 'Tuesday', 'Wed' => 'Wednesday', 'Thu' => 'Thursday', 'Fri' => 'Friday', 'Sat' => 'Saturday', 'Sun' => 'Sunday'];
    $t['busiest_day'] = max($weekday) ? $dayNames[array_search(max($weekday), $weekday, true)] : null;
    $t['busiest_hour'] = $hours ? array_search(max($hours), $hours, true) : null;

    // Requesters (resident profile of each request).
    $sex = ['Male' => 0, 'Female' => 0, 'Not specified' => 0];
    $age = ['Below 18' => 0, '18–29' => 0, '30–44' => 0, '45–59' => 0, '60 and above' => 0, 'Not specified' => 0];
    $areas = [];
    foreach ($rq("SELECT r.Sex, r.BirthDate, COALESCE(NULLIF(TRIM(r.AreaName),''), NULLIF(TRIM(r.Purok),''), 'Not specified') area,
                         COUNT(*) n, SUM(dr.Status='Released') rel
                  FROM document_requests dr LEFT JOIN residents r ON r.ResidentID = dr.ResidentID
                  WHERE $W GROUP BY dr.ResidentID, r.Sex, r.BirthDate, area") as $r) {
        $n = (int)$r['n'];
        $s = strtolower(trim((string)$r['Sex']));
        $sex[$s === 'male' ? 'Male' : ($s === 'female' ? 'Female' : 'Not specified')] += $n;
        $years = null;
        if (!empty($r['BirthDate']) && !str_starts_with((string)$r['BirthDate'], '0000')) {
            try { $years = (new DateTime((string)$r['BirthDate']))->diff(new DateTime('today'))->y; } catch (Throwable $e) { $years = null; }
        }
        $band = $years === null ? 'Not specified' : ($years < 18 ? 'Below 18' : ($years < 30 ? '18–29' : ($years < 45 ? '30–44' : ($years < 60 ? '45–59' : '60 and above'))));
        $age[$band] += $n;
        $a = $r['area'];
        if (!isset($areas[$a])) $areas[$a] = ['area' => $a, 'total' => 0, 'released' => 0, 'residents' => 0];
        $areas[$a]['total'] += $n; $areas[$a]['released'] += (int)$r['rel']; $areas[$a]['residents']++;
    }
    $areas = array_values($areas);
    usort($areas, fn($x, $y) => ($y['total'] <=> $x['total']) ?: strcasecmp($x['area'], $y['area']));

    $recent = array_map(fn($r) => [
        'doc_number' => $r['doc_number'] ?: '—', 'doc_type' => $r['DocType'], 'type' => $r['request_type'] === 'online' ? 'Online' : 'Walk-in',
        'resident' => cert_person_name($r) ?: '—', 'released' => date('M j, Y g:i A', strtotime($r['release_date'])),
        'took' => cert_fmt_duration((strtotime($r['release_date']) - strtotime($r['DateRequested'])) / 60),
    ], $rq("SELECT dr.doc_number, dr.DocType, dr.request_type, dr.release_date, dr.DateRequested, r.FirstName, r.MiddleName, r.LastName, r.Suffix
            FROM document_requests dr LEFT JOIN residents r ON r.ResidentID = dr.ResidentID
            WHERE $W AND dr.Status = 'Released' AND dr.release_date IS NOT NULL ORDER BY dr.release_date DESC LIMIT 10"));
    $unclaimed = array_map(fn($r) => [
        'doc_number' => $r['doc_number'] ?: ($r['ReferenceNo'] ?: '—'), 'doc_type' => $r['DocType'], 'resident' => cert_person_name($r) ?: '—',
        'approved' => $r['approved_at'] ? date('M j, Y', strtotime($r['approved_at'])) : '—', 'status' => $r['Status'],
        'deadline' => $r['approved_at'] ? date('M j, Y', strtotime($r['approved_at'] . ' +' . CERT_PICKUP_DAYS . ' days')) : '—',
    ], $rq("SELECT dr.doc_number, dr.ReferenceNo, dr.DocType, dr.Status, dr.approved_at, r.FirstName, r.MiddleName, r.LastName, r.Suffix
            FROM document_requests dr LEFT JOIN residents r ON r.ResidentID = dr.ResidentID
            WHERE $W AND dr.Status IN ('Ready to Pick Up','Expired') ORDER BY dr.Status = 'Expired' DESC, dr.approved_at LIMIT 15"));

    // Selected period vs the previous period of the same length (both dates set).
    $comparison = null;
    if (empty($p['no_compare']) && $p['start'] !== '' && $p['end'] !== '') {
        $days = (int)(new DateTime($p['start']))->diff(new DateTime($p['end']))->days + 1;
        $prevEnd = (new DateTime($p['start']))->modify('-1 day');
        $prevStart = (clone $prevEnd)->modify('-' . ($days - 1) . ' days');
        $pt = cert_analytics_compute($pdo, ['start' => $prevStart->format('Y-m-d'), 'end' => $prevEnd->format('Y-m-d'), 'label' => '', 'no_compare' => true])['totals'];
        $comparison = [
            'previous_label' => ra_range_label($prevStart->format('Y-m-d'), $prevEnd->format('Y-m-d')),
            'rows' => [
                ['metric' => 'Total requests', 'current' => $t['total'], 'previous' => $pt['total']],
                ['metric' => 'Walk-in requests', 'current' => $t['walkin'], 'previous' => $pt['walkin']],
                ['metric' => 'Online requests', 'current' => $t['online'], 'previous' => $pt['online']],
                ['metric' => 'Released', 'current' => $t['released'], 'previous' => $pt['released']],
                ['metric' => 'Rejected', 'current' => $t['rejected'], 'previous' => $pt['rejected']],
            ],
        ];
    }

    return [
        'period' => $p, 'totals' => $t, 'status' => $status, 'type' => ['Walk-in' => $t['walkin'], 'Online' => $t['online']],
        'documents' => $byDoc, 'purpose' => $purpose, 'rejections' => $reasons,
        'series' => $series, 'series_daily' => $daily, 'weekday' => $weekday, 'hours' => $hours,
        'sex' => $sex, 'age' => $age, 'areas' => array_slice($areas, 0, 15),
        'recent' => $recent, 'unclaimed' => $unclaimed, 'comparison' => $comparison,
        'generated_at' => date('F j, Y g:i A'),
    ];
}

/** Aggregated figures only — all the AI ever sees (no names or contact details). */
function cert_analytics_snapshot(array $a): array {
    $t = $a['totals'];
    return [
        'date_range' => $a['period']['label'],
        'totals' => array_intersect_key($t, array_flip(['total', 'walkin', 'online', 'released', 'pending', 'ready', 'rejected', 'expired', 'preview',
            'accepted_online', 'residents', 'doc_types', 'release_rate', 'rejection_rate', 'unclaimed_rate', 'busiest_day', 'busiest_hour'])),
        'avg_processing' => ['all' => cert_fmt_duration($t['avg_min']), 'walk_in' => cert_fmt_duration($t['walkin_min']), 'online' => cert_fmt_duration($t['online_min'])],
        'documents' => array_map(fn($d) => array_intersect_key($d, array_flip(['label', 'total', 'walkin', 'online', 'released', 'rejected', 'avg'])), $a['documents']),
        'status' => $a['status'], 'purpose' => $a['purpose'], 'rejection_reasons' => $a['rejections'],
        'over_time' => $a['series'], 'weekday' => $a['weekday'], 'requester_sex' => $a['sex'], 'requester_age' => $a['age'],
        'areas' => array_map(fn($x) => [$x['area'], $x['total'], $x['released']], $a['areas']),
        'comparison_with_previous_period' => $a['comparison'],
    ];
}

/** Automatic, rule-based reading of the figures (no AI) — shown under AI Analytics and in the report. */
function cert_analytics_interpretation(array $a): array {
    $t = $a['totals'];
    if (!$t['total']) return ['No document requests were recorded for ' . $a['period']['label'] . '.'];
    $out = [];
    $out[] = $t['total'] . ' document request(s) were recorded for ' . $a['period']['label'] . ' from ' . $t['residents'] . ' resident(s): '
        . $t['walkin'] . ' walk-in (' . ra_pct($t['walkin'], $t['total']) . '%) and ' . $t['online'] . ' online (' . ra_pct($t['online'], $t['total']) . '%).';
    if ($t['top_document']) $out[] = 'The most requested document is ' . $t['top_document'] . ' with ' . $t['top_document_n'] . ' request(s) ('
        . ra_pct($t['top_document_n'], $t['total']) . '% of all requests), out of ' . $t['doc_types'] . ' document type(s) requested.';
    $out[] = $t['released'] . ' request(s) were released (' . $t['release_rate'] . '%); ' . $t['pending'] . ' are waiting for review, '
        . $t['ready'] . ' are ready to pick up and ' . $t['preview'] . ' walk-in document(s) are generated but not yet released.';
    if ($t['avg_min'] !== null) $out[] = 'Average processing time from request to release is ' . cert_fmt_duration($t['avg_min'])
        . ' (walk-in ' . cert_fmt_duration($t['walkin_min']) . ', online ' . cert_fmt_duration($t['online_min']) . ').';
    if ($t['online']) $out[] = $t['rejected'] . ' online request(s) were rejected (' . $t['rejection_rate'] . '% of online requests)'
        . ($a['rejections'] ? '; the most common reason is "' . array_key_first($a['rejections']) . '".' : '.');
    if ($t['accepted_online']) $out[] = $t['expired'] . ' accepted online document(s) expired unclaimed after ' . CERT_PICKUP_DAYS . ' days ('
        . $t['unclaimed_rate'] . '% of accepted online requests).';
    if ($t['busiest_day']) $out[] = 'Requests are highest on ' . $t['busiest_day'] . ($t['busiest_hour'] ? ', around ' . $t['busiest_hour'] : '') . '.';
    $area = array_values(array_filter($a['areas'], fn($x) => $x['area'] !== 'Not specified'))[0] ?? null;
    if ($area) $out[] = 'Most requests come from ' . $area['area'] . ' (' . $area['total'] . ' request(s), ' . ra_pct($area['total'], $t['total']) . '%).';
    if ($a['comparison']) {
        $c = $a['comparison']['rows'][0];
        $d = $c['current'] - $c['previous'];
        $out[] = 'Compared with the previous period (' . $a['comparison']['previous_label'] . '), total requests '
            . ($d > 0 ? 'increased by ' . $d : ($d < 0 ? 'decreased by ' . abs($d) : 'stayed the same')) . ' (' . $c['previous'] . ' → ' . $c['current'] . ').';
    }
    return $out;
}

// Direct request → JSON for the analytics page (Apply without reloading).
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    require_once __DIR__ . '/../../db.php';
    $required_module = 'certificates';
    require_once __DIR__ . '/../../auth_check.php';
    if (!cert_can($pdo, 'read')) cert_json(['success' => false, 'error' => 'Access denied.'], 403);
    try {
        cert_migrate($pdo);
        $a = cert_analytics_compute($pdo, cert_analytics_params($_GET));
        $a['interpretation'] = cert_analytics_interpretation($a);
        cert_json(['success' => true, 'data' => $a]);
    } catch (Throwable $e) {
        error_log('[Certificates] analytics: ' . $e->getMessage());
        cert_json(['success' => false, 'error' => 'Unable to load legal document analytics.'], 500);
    }
}

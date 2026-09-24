<?php
/**
 * cert_analytics_data.php — numbers for the Certificate Analytics page, its AI overview
 * and its printable report. Real data only (document_requests + residents).
 *
 *   $p = cert_analytics_params($_GET);          // from / to (default last 30 days)
 *   $a = cert_analytics_compute($pdo, $p);
 */
require_once __DIR__ . '/cert_common.php';

function cert_analytics_params(array $in): array {
    $valid = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d);
    $to = $valid($in['to'] ?? null) ? $in['to'] : date('Y-m-d');
    $from = $valid($in['from'] ?? null) ? $in['from'] : date('Y-m-d', strtotime($to . ' -29 days'));
    if ($from > $to) [$from, $to] = [$to, $from];
    return ['from' => $from, 'to' => $to];
}

function cert_fmt_duration(?float $minutes): string {
    if ($minutes === null) return '—';
    if ($minutes < 60) return round($minutes) . ' min';
    if ($minutes < 60 * 24) return round($minutes / 60, 1) . ' h';
    return round($minutes / 1440, 1) . ' days';
}

function cert_analytics_compute(PDO $pdo, array $p): array {
    $from = $p['from'] . ' 00:00:00';
    $to = $p['to'] . ' 23:59:59';
    $rq = function (string $sql, array $args = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC); };
    $in = "DateRequested BETWEEN ? AND ?";

    $t = $rq("SELECT COUNT(*) total,
            SUM(request_type='walk-in') walkin, SUM(request_type='online') online,
            SUM(Status='Released') released, SUM(Status IN ('Pending','Review')) pending,
            SUM(Status='Ready to Pick Up') ready, SUM(Status='Rejected') rejected, SUM(Status='Expired') expired,
            SUM(Status='Preview') preview,
            SUM(request_type='online' AND Status IN ('Ready to Pick Up','Released','Expired')) accepted_online,
            SUM(request_type='online' AND Status='Released') released_online
        FROM document_requests WHERE $in", [$from, $to])[0];
    $t = array_map(fn($v) => (int)$v, $t);

    // Processing time: request → release, for documents released inside the period.
    $proc = $rq("SELECT AVG(TIMESTAMPDIFF(MINUTE, DateRequested, release_date)) avg_min,
                        AVG(CASE WHEN request_type='walk-in' THEN TIMESTAMPDIFF(MINUTE, DateRequested, release_date) END) walkin_min,
                        AVG(CASE WHEN request_type='online' THEN TIMESTAMPDIFF(MINUTE, DateRequested, release_date) END) online_min,
                        COUNT(*) n
                 FROM document_requests WHERE Status='Released' AND release_date BETWEEN ? AND ?", [$from, $to])[0];
    $t['avg_processing_min'] = $proc['avg_min'] !== null ? (float)$proc['avg_min'] : null;
    $t['avg_walkin_min'] = $proc['walkin_min'] !== null ? (float)$proc['walkin_min'] : null;
    $t['avg_online_min'] = $proc['online_min'] !== null ? (float)$proc['online_min'] : null;
    $t['released_in_period'] = (int)$proc['n'];
    $t['unclaimed_rate'] = $t['accepted_online'] ? round($t['expired'] / $t['accepted_online'] * 100, 1) : null;
    $t['rejection_rate'] = $t['online'] ? round($t['rejected'] / $t['online'] * 100, 1) : null;

    $byDoc = $rq("SELECT DocType label, COUNT(*) n, SUM(Status='Released') released, SUM(request_type='online') online,
                         AVG(CASE WHEN Status='Released' THEN TIMESTAMPDIFF(MINUTE, DateRequested, release_date) END) avg_min
                  FROM document_requests WHERE $in GROUP BY DocType ORDER BY n DESC", [$from, $to]);
    $t['top_document'] = $byDoc[0]['label'] ?? null;
    $t['top_document_n'] = (int)($byDoc[0]['n'] ?? 0);

    // Requests over time (daily up to 92 days, else monthly).
    $days = (int)((strtotime($p['to']) - strtotime($p['from'])) / 86400) + 1;
    $monthly = $days > 92;
    $fmt = $monthly ? '%Y-%m' : '%Y-%m-%d';
    $series = $rq("SELECT DATE_FORMAT(DateRequested, '$fmt') k, SUM(request_type='walk-in') w, SUM(request_type='online') o
                   FROM document_requests WHERE $in GROUP BY k", [$from, $to]);
    $sMap = []; foreach ($series as $s) $sMap[$s['k']] = $s;
    $labels = []; $walk = []; $onl = []; $keys = [];
    $cur = strtotime($monthly ? date('Y-m-01', strtotime($p['from'])) : $p['from']);
    $end = strtotime($p['to']);
    while ($cur <= $end) {
        $k = date($monthly ? 'Y-m' : 'Y-m-d', $cur);
        $keys[] = $k;
        $labels[] = date($monthly ? 'M Y' : 'M j', $cur);
        $walk[] = (int)($sMap[$k]['w'] ?? 0); $onl[] = (int)($sMap[$k]['o'] ?? 0);
        $cur = strtotime($monthly ? '+1 month' : '+1 day', $cur);
    }
    // Processing time trend (avg hours per release day / month).
    $pt = $rq("SELECT DATE_FORMAT(release_date, '$fmt') k, AVG(TIMESTAMPDIFF(MINUTE, DateRequested, release_date)) m
               FROM document_requests WHERE Status='Released' AND release_date BETWEEN ? AND ? GROUP BY k", [$from, $to]);
    $ptMap = []; foreach ($pt as $r) $ptMap[$r['k']] = round((float)$r['m'] / 60, 2);
    $procTrend = array_map(fn($k) => $ptMap[$k] ?? null, $keys);

    $byPurpose = $rq("SELECT COALESCE(NULLIF(TRIM(Purpose),''),'(none)') label, COUNT(*) n FROM document_requests WHERE $in GROUP BY label ORDER BY n DESC LIMIT 8", [$from, $to]);
    $byStatus = $rq("SELECT Status label, COUNT(*) n FROM document_requests WHERE $in GROUP BY Status ORDER BY n DESC", [$from, $to]);

    // Rejection reasons: typed "Others: …" grouped under Others (hover lists them).
    $rej = $rq("SELECT rejection_reason r, COUNT(*) n FROM document_requests WHERE $in AND Status='Rejected' GROUP BY r", [$from, $to]);
    $reasons = []; $others = [];
    foreach ($rej as $r) {
        $reason = trim((string)$r['r']) ?: 'Not specified';
        if (stripos($reason, 'Others:') === 0 || stripos($reason, 'Cancelled') === 0) {
            $txt = trim(substr($reason, stripos($reason, 'Others:') === 0 ? 7 : 0));
            $others[$txt] = ($others[$txt] ?? 0) + (int)$r['n'];
            $reasons['Others'] = ($reasons['Others'] ?? 0) + (int)$r['n'];
        } else {
            $reasons[$reason] = ($reasons[$reason] ?? 0) + (int)$r['n'];
        }
    }
    arsort($reasons); arsort($others);

    $dow = array_fill(0, 7, 0); // Mon..Sun
    foreach ($rq("SELECT WEEKDAY(DateRequested) d, COUNT(*) n FROM document_requests WHERE $in GROUP BY d", [$from, $to]) as $r) $dow[(int)$r['d']] = (int)$r['n'];
    $hours = array_fill(0, 24, 0);
    foreach ($rq("SELECT HOUR(DateRequested) h, COUNT(*) n FROM document_requests WHERE $in GROUP BY h", [$from, $to]) as $r) $hours[(int)$r['h']] = (int)$r['n'];
    $dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $busiestDay = max($dow) ? $dayNames[array_search(max($dow), $dow, true)] : null;
    $busiestHour = max($hours) ? array_search(max($hours), $hours, true) : null;

    $puroks = $rq("SELECT COALESCE(NULLIF(TRIM(r.Purok),''),'Not set') label, COUNT(*) n
                   FROM document_requests dr LEFT JOIN residents r ON r.ResidentID = dr.ResidentID
                   WHERE dr.DateRequested BETWEEN ? AND ? GROUP BY label ORDER BY n DESC LIMIT 8", [$from, $to]);
    foreach ($puroks as &$pk) if ($pk['label'] !== 'Not set' && stripos($pk['label'], 'purok') !== 0) $pk['label'] = 'Purok ' . $pk['label'];
    unset($pk);

    $recent = $rq("SELECT dr.doc_number, dr.DocType, dr.request_type, dr.release_date, dr.released_by, dr.DateRequested,
                          r.FirstName, r.MiddleName, r.LastName, r.Suffix, r.ResidentCode
                   FROM document_requests dr LEFT JOIN residents r ON r.ResidentID = dr.ResidentID
                   WHERE dr.Status='Released' AND dr.release_date BETWEEN ? AND ? ORDER BY dr.release_date DESC LIMIT 10", [$from, $to]);
    $expiredRows = $rq("SELECT dr.doc_number, dr.ReferenceNo, dr.DocType, dr.approved_at, dr.expired_at, r.FirstName, r.MiddleName, r.LastName, r.Suffix, r.ResidentCode, r.ContactNumber
                        FROM document_requests dr LEFT JOIN residents r ON r.ResidentID = dr.ResidentID
                        WHERE dr.Status IN ('Expired','Ready to Pick Up') AND dr.DateRequested BETWEEN ? AND ?
                        ORDER BY dr.Status = 'Expired' DESC, dr.approved_at LIMIT 20", [$from, $to]);

    return [
        'params' => $p, 'monthly' => $monthly, 'days' => $days,
        'totals' => $t,
        'series' => ['labels' => $labels, 'walkin' => $walk, 'online' => $onl, 'processing_hours' => $procTrend],
        'by_document' => array_map(fn($r) => ['label' => $r['label'], 'n' => (int)$r['n'], 'released' => (int)$r['released'], 'online' => (int)$r['online'],
                                              'avg' => cert_fmt_duration($r['avg_min'] !== null ? (float)$r['avg_min'] : null)], $byDoc),
        'by_purpose' => array_map(fn($r) => ['label' => $r['label'], 'n' => (int)$r['n']], $byPurpose),
        'by_status' => array_map(fn($r) => ['label' => $r['label'], 'n' => (int)$r['n']], $byStatus),
        'rejections' => ['labels' => array_keys($reasons), 'data' => array_values($reasons), 'others' => $others],
        'weekday' => ['labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], 'data' => $dow, 'busiest' => $busiestDay],
        'hours' => ['labels' => array_map(fn($h) => date('g A', mktime($h, 0)), range(0, 23)), 'data' => $hours,
                    'busiest' => $busiestHour !== null ? date('g A', mktime($busiestHour, 0)) : null],
        'puroks' => array_map(fn($r) => ['label' => $r['label'], 'n' => (int)$r['n']], $puroks),
        'recent_releases' => array_map(fn($r) => ['doc_number' => $r['doc_number'], 'doc_type' => $r['DocType'], 'type' => $r['request_type'],
            'resident' => cert_person_name($r), 'code' => $r['ResidentCode'], 'released' => date('M j, Y g:i A', strtotime($r['release_date'])),
            'by' => $r['released_by'], 'took' => cert_fmt_duration((strtotime($r['release_date']) - strtotime($r['DateRequested'])) / 60)], $recent),
        'unclaimed' => array_map(fn($r) => ['doc_number' => $r['doc_number'] ?: $r['ReferenceNo'], 'doc_type' => $r['DocType'],
            'resident' => cert_person_name($r), 'code' => $r['ResidentCode'], 'contact' => $r['ContactNumber'],
            'approved' => $r['approved_at'] ? date('M j, Y', strtotime($r['approved_at'])) : '—',
            'status' => $r['expired_at'] ? 'Expired' : 'Ready to Pick Up',
            'deadline' => $r['approved_at'] ? date('M j, Y', strtotime($r['approved_at'] . ' +' . CERT_PICKUP_DAYS . ' days')) : '—'], $expiredRows),
    ];
}

/** Chart.js-ready data. */
function cert_analytics_chart_data(array $a): array {
    return [
        'series' => $a['series'], 'monthly' => $a['monthly'],
        'byDocument' => ['labels' => array_column($a['by_document'], 'label'), 'data' => array_column($a['by_document'], 'n')],
        'byPurpose' => ['labels' => array_column($a['by_purpose'], 'label'), 'data' => array_column($a['by_purpose'], 'n')],
        'byStatus' => ['labels' => array_column($a['by_status'], 'label'), 'data' => array_column($a['by_status'], 'n')],
        'rejections' => $a['rejections'], 'weekday' => $a['weekday'], 'hours' => $a['hours'],
        'puroks' => ['labels' => array_column($a['puroks'], 'label'), 'data' => array_column($a['puroks'], 'n')],
    ];
}

/** Rule-based insights (used when AI is unavailable, and in the report explanations). */
function cert_analytics_rules(array $a): array {
    $t = $a['totals'];
    $f = []; $tr = []; $ac = [];
    if (!$t['total']) return ['key_findings' => [['title' => 'No requests', 'detail' => 'No certificate requests were recorded in this period.', 'severity' => 'info']], 'trends' => [], 'actions' => []];
    $f[] = ['title' => $t['total'] . ' requests', 'detail' => $t['walkin'] . ' walk-in and ' . $t['online'] . ' online; ' . $t['released'] . ' released so far.', 'severity' => 'info'];
    if ($t['top_document']) $f[] = ['title' => 'Most requested: ' . $t['top_document'], 'detail' => $t['top_document_n'] . ' of ' . $t['total'] . ' requests (' . round($t['top_document_n'] / $t['total'] * 100) . '%).', 'severity' => 'info'];
    if ($t['avg_processing_min'] !== null) $f[] = ['title' => 'Average processing ' . cert_fmt_duration($t['avg_processing_min']), 'detail' => 'From request to release for ' . $t['released_in_period'] . ' released document(s).', 'severity' => $t['avg_processing_min'] > 1440 * 3 ? 'warning' : 'good'];
    if ($t['expired']) $f[] = ['title' => $t['expired'] . ' unclaimed (expired)', 'detail' => 'Unclaimed rate ' . $t['unclaimed_rate'] . '% of accepted online requests.', 'severity' => 'warning'];
    elseif ($t['rejected']) $f[] = ['title' => $t['rejected'] . ' rejected', 'detail' => 'Rejection rate ' . $t['rejection_rate'] . '% of online requests.', 'severity' => 'warning'];

    $s = $a['series']; $n = count($s['labels']);
    if ($n >= 4) {
        $half = intdiv($n, 2);
        $sum = fn($arr, $o, $l) => array_sum(array_slice($arr, $o, $l));
        $first = $sum($s['walkin'], 0, $half) + $sum($s['online'], 0, $half);
        $second = $sum($s['walkin'], $half, $n) + $sum($s['online'], $half, $n);
        $dir = $second > $first ? 'up' : ($second < $first ? 'down' : 'stable');
        $tr[] = ['title' => 'Requests ' . ($dir === 'up' ? 'increasing' : ($dir === 'down' ? 'decreasing' : 'steady')), 'detail' => 'First half: ' . $first . ', second half: ' . $second . '.', 'direction' => $dir];
        $ow1 = $sum($s['online'], 0, $half); $ow2 = $sum($s['online'], $half, $n);
        if ($ow1 + $ow2) $tr[] = ['title' => 'Online requests ' . ($ow2 > $ow1 ? 'rising' : ($ow2 < $ow1 ? 'falling' : 'steady')), 'detail' => $ow1 . ' → ' . $ow2 . ' between the two halves of the period.', 'direction' => $ow2 > $ow1 ? 'up' : ($ow2 < $ow1 ? 'down' : 'stable')];
    }
    if ($a['weekday']['busiest']) $tr[] = ['title' => 'Busiest on ' . $a['weekday']['busiest'], 'detail' => 'Peak hour ' . ($a['hours']['busiest'] ?? '—') . '.', 'direction' => 'stable'];

    if ($t['pending']) $ac[] = ['action' => 'Review the ' . $t['pending'] . ' pending online request(s)', 'reason' => 'Residents are waiting for Accept / Reject.', 'priority' => 'high'];
    if ($t['ready']) $ac[] = ['action' => 'Remind residents with ' . $t['ready'] . ' document(s) ready to pick up', 'reason' => 'They expire ' . CERT_PICKUP_DAYS . ' days after approval.', 'priority' => $t['expired'] ? 'high' : 'medium'];
    if ($a['weekday']['busiest']) $ac[] = ['action' => 'Assign extra staff on ' . $a['weekday']['busiest'] . 's', 'reason' => 'That is the busiest day for requests.', 'priority' => 'low'];
    if ($t['rejected'] && !empty($a['rejections']['labels'])) $ac[] = ['action' => 'Post the requirements for "' . $a['rejections']['labels'][0] . '" clearly', 'reason' => 'It is the most common rejection reason.', 'priority' => 'medium'];
    return ['key_findings' => array_slice($f, 0, 4), 'trends' => array_slice($tr, 0, 3), 'actions' => array_slice($ac, 0, 4)];
}

/** Aggregated numbers only — this is all the AI ever sees (no names or contact details). */
function cert_analytics_snapshot(array $a): array {
    $t = $a['totals'];
    return [
        'period' => $a['params'], 'days' => $a['days'],
        'totals' => array_intersect_key($t, array_flip(['total', 'walkin', 'online', 'released', 'pending', 'ready', 'rejected', 'expired', 'preview', 'accepted_online', 'unclaimed_rate', 'rejection_rate', 'released_in_period'])),
        'avg_processing' => ['all' => cert_fmt_duration($t['avg_processing_min']), 'walk_in' => cert_fmt_duration($t['avg_walkin_min']), 'online' => cert_fmt_duration($t['avg_online_min'])],
        'by_document' => array_map(fn($d) => [$d['label'], $d['n'], $d['released'], $d['avg']], $a['by_document']),
        'by_purpose' => array_map(fn($d) => [$d['label'], $d['n']], $a['by_purpose']),
        'status' => array_map(fn($d) => [$d['label'], $d['n']], $a['by_status']),
        'rejection_reasons' => array_combine($a['rejections']['labels'], $a['rejections']['data']) ?: [],
        'over_time' => ['labels' => $a['series']['labels'], 'walk_in' => $a['series']['walkin'], 'online' => $a['series']['online']],
        'weekday' => array_combine($a['weekday']['labels'], $a['weekday']['data']),
        'busiest_hour' => $a['hours']['busiest'],
        'puroks' => array_map(fn($d) => [$d['label'], $d['n']], $a['puroks']),
    ];
}

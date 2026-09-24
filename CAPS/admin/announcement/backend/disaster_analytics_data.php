<?php
/**
 * disaster_analytics_data.php
 * Shared data layer for the Disaster Analytics page (frontend/disaster_analytics.php)
 * and its AI endpoint (backend/disaster_ai_summary.php).
 *
 * Every figure is computed for ONE date range (Start Date → End Date) so the cards,
 * charts, records table, AI analytics and the printed/PDF report always agree.
 *
 * Date rule: a disaster belongs to the range when its alert was issued inside it
 * (disaster_alerts.CreatedAt). A report without a linked alert falls back to the
 * date the report itself was filed.
 */

// Uniform disaster reference number: DIS-YYYY-0001 (same rule as ann.php)
if (!function_exists('disaster_report_no')) {
    function disaster_report_no(array $row): string
    {
        if (!empty($row['ReportNo']))
            return $row['ReportNo'];
        $year = !empty($row['CreatedAt']) ? date('Y', strtotime($row['CreatedAt'])) : date('Y');
        return sprintf('DIS-%s-%04d', $year, (int) ($row['ReportID'] ?? 0));
    }
}

if (!function_exists('disaster_analytics_parse_range')) {
    /**
     * Normalises the Start/End dates coming from the request.
     * Defaults to January 1 of the current year → today. Swaps them when reversed.
     *
     * @return array{0:string,1:string} [start Y-m-d, end Y-m-d]
     */
    function disaster_analytics_parse_range(?string $start, ?string $end): array
    {
        $valid = static function (?string $d): ?string {
            $d = trim((string) $d);
            $dt = DateTime::createFromFormat('!Y-m-d', $d);
            return ($dt && $dt->format('Y-m-d') === $d) ? $d : null;
        };

        $s = $valid($start) ?? date('Y-01-01');
        $e = $valid($end) ?? date('Y-m-d');
        if ($s > $e) {
            [$s, $e] = [$e, $s];
        }
        return [$s, $e];
    }
}

if (!function_exists('disaster_analytics_categories')) {
    /** Disaster categories the module issues alerts for. */
    function disaster_analytics_categories(): array
    {
        return ['Flood', 'Fire', 'Earthquake', 'Typhoon'];
    }
}

if (!function_exists('disaster_analytics_buckets')) {
    /**
     * Builds the time buckets used by the trend chart.
     * ≤ 62 days → one bucket per day, otherwise one bucket per month.
     *
     * @return array{granularity:string, keys:string[], labels:string[]}
     */
    function disaster_analytics_buckets(string $start, string $end): array
    {
        $s = new DateTime($start);
        $e = new DateTime($end);
        $days = (int) $s->diff($e)->days + 1;

        $keys = [];
        $labels = [];
        if ($days <= 62) {
            $granularity = 'day';
            for ($d = clone $s; $d <= $e; $d->modify('+1 day')) {
                $keys[] = $d->format('Y-m-d');
                $labels[] = $d->format('M j');
            }
        } else {
            $granularity = 'month';
            $multiYear = $s->format('Y') !== $e->format('Y');
            for ($d = new DateTime($s->format('Y-m-01')); $d <= $e; $d->modify('+1 month')) {
                $keys[] = $d->format('Y-m');
                $labels[] = $multiYear ? $d->format("M 'y") : $d->format('M');
            }
        }
        return ['granularity' => $granularity, 'keys' => $keys, 'labels' => $labels];
    }
}

if (!function_exists('disaster_analytics_collect')) {
    /**
     * Collects every figure the Disaster Analytics page needs for a date range.
     *
     * @param  bool $withRecords  include the full disaster report rows (page + print),
     *                            false for the AI brief which only needs aggregates.
     */
    function disaster_analytics_collect(PDO $pdo, string $start, string $end, string $category = 'All', bool $withRecords = true): array
    {
        $categories = disaster_analytics_categories();
        if ($category !== 'All' && !in_array($category, $categories, true)) {
            $category = 'All';
        }

        $startDt = $start . ' 00:00:00';
        $endDt = $end . ' 23:59:59';

        // Same-length window immediately before the selected range (for comparison)
        $days = (int) (new DateTime($start))->diff(new DateTime($end))->days + 1;
        $prevEnd = (new DateTime($start))->modify('-1 day')->format('Y-m-d');
        $prevStart = (new DateTime($start))->modify('-' . $days . ' days')->format('Y-m-d');

        $catSql = $category === 'All' ? '' : ' AND Type = :cat';
        $catBind = $category === 'All' ? [] : [':cat' => $category];

        $buckets = disaster_analytics_buckets($start, $end);
        $bucketFmt = $buckets['granularity'] === 'day' ? '%Y-%m-%d' : '%Y-%m';

        // ── 1. Alerts (disaster_alerts) ─────────────────────────────────────
        $stmt = $pdo->prepare(
            "SELECT AlertID, Type, Severity, Status, notify_app, notify_sms, CreatedAt,
                    DATE_FORMAT(CreatedAt, '{$bucketFmt}') AS bucket
               FROM disaster_alerts
              WHERE CreatedAt BETWEEN :s AND :e {$catSql}
              ORDER BY CreatedAt ASC"
        );
        $stmt->execute(array_merge([':s' => $startDt, ':e' => $endDt], $catBind));
        $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byType = [];
        $bySeverity = [];
        $byAlertStatus = ['Active' => 0, 'Deactivated' => 0];
        $channels = ['App Notification' => 0, 'SMS Broadcast' => 0, 'Both' => 0, 'None' => 0];
        $weekday = array_fill_keys(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], 0);
        $timeline = [];
        $alertIds = [];

        foreach ($alerts as $a) {
            $type = trim((string) ($a['Type'] ?? '')) ?: 'Unspecified';
            $sev = trim((string) ($a['Severity'] ?? '')) ?: 'Unspecified';
            $alertIds[] = (int) $a['AlertID'];

            $byType[$type] = $byType[$type] ?? ['alerts' => 0, 'reports' => 0, 'affected' => 0, 'evacuees' => 0, 'injuries' => 0, 'casualties' => 0];
            $byType[$type]['alerts']++;
            $bySeverity[$sev] = ($bySeverity[$sev] ?? 0) + 1;

            $isActive = strtolower((string) ($a['Status'] ?? '')) === 'active';
            $byAlertStatus[$isActive ? 'Active' : 'Deactivated']++;

            $app = !empty($a['notify_app']);
            $sms = !empty($a['notify_sms']);
            if ($app && $sms) {
                $channels['Both']++;
            } elseif ($app) {
                $channels['App Notification']++;
            } elseif ($sms) {
                $channels['SMS Broadcast']++;
            } else {
                $channels['None']++;
            }

            $weekday[date('D', strtotime($a['CreatedAt']))]++;
            $timeline[$type][$a['bucket']] = ($timeline[$type][$a['bucket']] ?? 0) + 1;
        }

        // Always show the four standard categories on the trend chart
        $trendTypes = $category === 'All' ? array_values(array_unique(array_merge($categories, array_keys($timeline)))) : [$category];
        $datasets = [];
        foreach ($trendTypes as $t) {
            $row = [];
            foreach ($buckets['keys'] as $k) {
                $row[] = (int) ($timeline[$t][$k] ?? 0);
            }
            $datasets[] = ['label' => $t, 'data' => $row];
        }

        // Previous period total (comparison)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM disaster_alerts WHERE CreatedAt BETWEEN :s AND :e {$catSql}");
        $stmt->execute(array_merge([':s' => $prevStart . ' 00:00:00', ':e' => $prevEnd . ' 23:59:59'], $catBind));
        $prevTotal = (int) $stmt->fetchColumn();

        // ── 2. Disaster reports (final report filed on deactivation) ────────
        $repCatSql = $category === 'All' ? '' : ' AND dr.Type = :cat';
        $stmt = $pdo->prepare(
            "SELECT dr.*,
                    da.Severity   AS AlertSeverity,
                    da.Message    AS AlertMessage,
                    da.notify_app AS AlertNotifyApp,
                    da.notify_sms AS AlertNotifySms,
                    da.Status     AS AlertStatus,
                    da.CreatedAt  AS AlertCreatedAt
               FROM disaster_reports dr
               LEFT JOIN disaster_alerts da ON da.AlertID = dr.AlertID
              WHERE COALESCE(da.CreatedAt, dr.CreatedAt) BETWEEN :s AND :e {$repCatSql}
              ORDER BY COALESCE(da.CreatedAt, dr.CreatedAt) DESC"
        );
        $stmt->execute(array_merge([':s' => $startDt, ':e' => $endDt], $catBind));
        $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $impact = ['reports' => 0, 'affected' => 0, 'evacuees' => 0, 'injuries' => 0, 'casualties' => 0];
        $byReportStatus = [];
        $resolveHours = [];
        foreach ($reports as $r) {
            $type = trim((string) ($r['Type'] ?? '')) ?: 'Unspecified';
            $byType[$type] = $byType[$type] ?? ['alerts' => 0, 'reports' => 0, 'affected' => 0, 'evacuees' => 0, 'injuries' => 0, 'casualties' => 0];
            $byType[$type]['reports']++;
            $impact['reports']++;
            foreach (['affected' => 'AffectedResidents', 'evacuees' => 'Evacuees', 'injuries' => 'Injuries', 'casualties' => 'Casualties'] as $k => $col) {
                $v = (int) ($r[$col] ?? 0);
                $impact[$k] += $v;
                $byType[$type][$k] += $v;
            }
            $st = trim((string) ($r['Status'] ?? '')) ?: 'Unspecified';
            $byReportStatus[$st] = ($byReportStatus[$st] ?? 0) + 1;

            if (!empty($r['AlertCreatedAt']) && !empty($r['CreatedAt'])) {
                $h = (strtotime($r['CreatedAt']) - strtotime($r['AlertCreatedAt'])) / 3600;
                if ($h >= 0) {
                    $resolveHours[] = $h;
                }
            }
        }

        // ── 3. SMS broadcasts for the alerts in range ───────────────────────
        $sms = ['broadcasts' => 0, 'recipients' => 0, 'sent' => 0];
        $smsByAlert = [];
        if ($alertIds || $reports) {
            $ids = $alertIds;
            foreach ($reports as $r) {
                if (!empty($r['AlertID'])) {
                    $ids[] = (int) $r['AlertID'];
                }
            }
            $ids = array_values(array_unique($ids));
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                try {
                    $stmt = $pdo->prepare(
                        "SELECT LogID, AlertID, total_recipients, sent_count, status, created_at
                           FROM sms_logs WHERE AlertID IN ($ph) ORDER BY created_at DESC"
                    );
                    $stmt->execute($ids);
                    $inRange = array_flip($alertIds);
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
                        $smsByAlert[(int) $l['AlertID']][] = $l;
                        if (isset($inRange[(int) $l['AlertID']])) {
                            $sms['broadcasts']++;
                            $sms['recipients'] += (int) $l['total_recipients'];
                            $sms['sent'] += (int) $l['sent_count'];
                        }
                    }
                } catch (PDOException $e) {
                    // sms_logs is optional — analytics still work without it
                }
            }
        }

        // ── 4. Order + tidy the breakdowns ─────────────────────────────────
        uasort($byType, static fn($a, $b) => ($b['alerts'] + $b['reports']) <=> ($a['alerts'] + $a['reports']));
        $sevOrder = ['Low' => 1, 'Medium' => 2, 'High' => 3, 'Critical' => 4, 'Extreme' => 5];
        uksort($bySeverity, static fn($a, $b) => ($sevOrder[$a] ?? 9) <=> ($sevOrder[$b] ?? 9));
        arsort($byReportStatus);

        $typeRows = [];
        foreach ($byType as $t => $v) {
            $typeRows[] = ['type' => $t] + $v;
        }

        // Peak period
        $peak = ['label' => null, 'count' => 0];
        foreach ($buckets['keys'] as $i => $k) {
            $sum = 0;
            foreach ($timeline as $series) {
                $sum += (int) ($series[$k] ?? 0);
            }
            if ($sum > $peak['count']) {
                $peak = ['label' => $buckets['labels'][$i], 'count' => $sum];
            }
        }

        $total = count($alerts);
        $out = [
            'range' => [
                'start' => $start,
                'end' => $end,
                'days' => $days,
                'label' => date('M j, Y', strtotime($start)) . ' – ' . date('M j, Y', strtotime($end)),
                'prev_start' => $prevStart,
                'prev_end' => $prevEnd,
            ],
            'category' => $category,
            'summary' => [
                'total_alerts' => $total,
                'prev_total' => $prevTotal,
                'active_alerts' => $byAlertStatus['Active'],
                'deactivated_alerts' => $byAlertStatus['Deactivated'],
                'reports' => $impact['reports'],
                'affected' => $impact['affected'],
                'evacuees' => $impact['evacuees'],
                'injuries' => $impact['injuries'],
                'casualties' => $impact['casualties'],
                'sms_broadcasts' => $sms['broadcasts'],
                'sms_recipients' => $sms['recipients'],
                'sms_sent' => $sms['sent'],
                'avg_resolve_hours' => $resolveHours ? round(array_sum($resolveHours) / count($resolveHours), 1) : null,
                'most_frequent' => $typeRows ? $typeRows[0]['type'] : null,
                'peak_label' => $peak['label'],
                'peak_count' => $peak['count'],
                'high_severity' => ($bySeverity['High'] ?? 0) + ($bySeverity['Critical'] ?? 0) + ($bySeverity['Extreme'] ?? 0),
            ],
            'trend' => [
                'granularity' => $buckets['granularity'],
                'labels' => $buckets['labels'],
                'datasets' => $datasets,
            ],
            'by_type' => $typeRows,
            'by_severity' => $bySeverity,
            'by_alert_status' => $byAlertStatus,
            'by_report_status' => $byReportStatus,
            'channels' => $channels,
            'weekday' => $weekday,
        ];

        if ($withRecords) {
            foreach ($reports as &$r) {
                $r['ReportNo'] = disaster_report_no($r);
                $r['SmsLogs'] = $smsByAlert[(int) ($r['AlertID'] ?? 0)] ?? [];
            }
            unset($r);
            $out['reports'] = $reports;
        }

        return $out;
    }
}

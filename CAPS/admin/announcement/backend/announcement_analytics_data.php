<?php
/**
 * announcement_analytics_data.php
 * Shared data layer for the Announcement (Meta) Analytics page
 * (frontend/announcement_analytics.php) and its AI endpoint
 * (backend/announcement_ai_summary.php).
 *
 *  - announcement_analytics_collect()  → posting statistics from the database
 *  - announcement_fb_metrics()         → Facebook views / reach / engagement for
 *                                         announcements that were posted to the Page
 *
 * An announcement belongs to the range when its date_posted falls inside it.
 */

require_once __DIR__ . '/disaster_analytics_data.php'; // date-range + bucket helpers

if (!function_exists('announcement_status_label')) {
    /**
     * Same status rules as the Announcement Archive table in ann.php.
     * @return string ACTIVE | SCHEDULED | EXPIRED | ENDED | DRAFT
     */
    function announcement_status_label(array $a, string $today): string
    {
        $status = $a['status'] ?? '';
        if ($status === 'Ended') {
            return 'ENDED';
        }
        if ($status === 'Draft') {
            return (!empty($a['date_end']) && $a['date_end'] <= $today) ? 'ENDED' : 'DRAFT';
        }
        if ($status === 'Scheduled') {
            return 'SCHEDULED';
        }
        if (!empty($a['date_end']) && $a['date_end'] < $today) {
            return 'EXPIRED';
        }
        return 'ACTIVE';
    }
}

if (!function_exists('announcement_ref')) {
    function announcement_ref(array $a): string
    {
        $year = !empty($a['created_at']) ? date('Y', strtotime($a['created_at'])) : date('Y', strtotime($a['date_posted'] ?? 'now'));
        return 'ANN-' . $year . '-' . str_pad((string) (int) ($a['ann_id'] ?? 0), 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('announcement_analytics_collect')) {
    function announcement_analytics_collect(PDO $pdo, string $start, string $end): array
    {
        $today = date('Y-m-d');
        $buckets = disaster_analytics_buckets($start, $end);
        $bucketFmt = $buckets['granularity'] === 'day' ? 'Y-m-d' : 'Y-m';
        $days = (int) (new DateTime($start))->diff(new DateTime($end))->days + 1;
        $prevEnd = (new DateTime($start))->modify('-1 day')->format('Y-m-d');
        $prevStart = (new DateTime($start))->modify('-' . $days . ' days')->format('Y-m-d');

        $stmt = $pdo->prepare(
            "SELECT a.id, a.ann_id, a.title, a.category, a.category_other, a.status, a.date_posted,
                    a.date_start, a.date_end, a.sms_sent, a.fb_post_id, a.fb_pending, a.created_at,
                    COUNT(at.id) AS attachment_count, COALESCE(SUM(at.is_image), 0) AS image_count
               FROM announcements a
               LEFT JOIN announcement_attachments at ON at.announcement_id = a.id
              WHERE a.deleted_at IS NULL AND a.date_posted BETWEEN :s AND :e
              GROUP BY a.id
              ORDER BY a.date_posted DESC, a.created_at DESC"
        );
        $stmt->execute([':s' => $start, ':e' => $end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM announcements WHERE deleted_at IS NULL AND date_posted BETWEEN :s AND :e");
        $stmt->execute([':s' => $prevStart, ':e' => $prevEnd]);
        $prevTotal = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM announcements WHERE deleted_at IS NOT NULL AND date_posted BETWEEN :s AND :e");
        $stmt->execute([':s' => $start, ':e' => $end]);
        $trashed = (int) $stmt->fetchColumn();

        $byStatus = ['ACTIVE' => 0, 'SCHEDULED' => 0, 'EXPIRED' => 0, 'ENDED' => 0, 'DRAFT' => 0];
        $byCategory = [];
        $weekday = array_fill_keys(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], 0);
        $trendAll = array_fill_keys($buckets['keys'], 0);
        $trendFb = array_fill_keys($buckets['keys'], 0);
        $fb = ['posted' => 0, 'queued' => 0, 'not_posted' => 0];
        $withAttach = 0;
        $withImages = 0;
        $smsSent = 0;
        $durations = [];
        $list = [];

        foreach ($rows as $a) {
            $label = announcement_status_label($a, $today);
            $byStatus[$label]++;

            $cat = $a['category'] ?: 'General';
            if ($cat === 'Health') {
                $cat = 'Health Advisory';
            }
            $byCategory[$cat] = ($byCategory[$cat] ?? 0) + 1;

            $weekday[date('D', strtotime($a['date_posted']))]++;
            $key = date($bucketFmt, strtotime($a['date_posted']));
            if (isset($trendAll[$key])) {
                $trendAll[$key]++;
            }

            if (!empty($a['fb_post_id'])) {
                $fb['posted']++;
                if (isset($trendFb[$key])) {
                    $trendFb[$key]++;
                }
            } elseif (!empty($a['fb_pending']) && $a['status'] === 'Scheduled') {
                $fb['queued']++;
            } else {
                $fb['not_posted']++;
            }

            if ((int) $a['attachment_count'] > 0) {
                $withAttach++;
            }
            if ((int) $a['image_count'] > 0) {
                $withImages++;
            }
            if (!empty($a['sms_sent'])) {
                $smsSent++;
            }
            if (!empty($a['date_start']) && !empty($a['date_end']) && $a['date_end'] >= $a['date_start']) {
                $durations[] = (strtotime($a['date_end']) - strtotime($a['date_start'])) / 86400 + 1;
            }

            $list[] = [
                'id' => (int) $a['id'],
                'ref' => announcement_ref($a),
                'title' => $a['title'],
                'category' => ($a['category'] === 'Others' && !empty($a['category_other'])) ? $a['category_other'] : $cat,
                'date_posted' => $a['date_posted'],
                'status' => $label,
                'fb_post_id' => $a['fb_post_id'] ?: null,
                'attachments' => (int) $a['attachment_count'],
            ];
        }
        arsort($byCategory);

        $total = count($rows);
        $weeks = max(1, $days / 7);

        return [
            'range' => [
                'start' => $start,
                'end' => $end,
                'days' => $days,
                'label' => date('M j, Y', strtotime($start)) . ' – ' . date('M j, Y', strtotime($end)),
                'prev_start' => $prevStart,
                'prev_end' => $prevEnd,
            ],
            'summary' => [
                'total' => $total,
                'prev_total' => $prevTotal,
                'active' => $byStatus['ACTIVE'],
                'scheduled' => $byStatus['SCHEDULED'],
                'ended' => $byStatus['ENDED'],
                'expired' => $byStatus['EXPIRED'],
                'draft' => $byStatus['DRAFT'],
                'fb_posted' => $fb['posted'],
                'fb_queued' => $fb['queued'],
                'with_attachments' => $withAttach,
                'with_images' => $withImages,
                'sms_sent' => $smsSent,
                'trashed' => $trashed,
                'per_week' => round($total / $weeks, 1),
                'avg_duration_days' => $durations ? round(array_sum($durations) / count($durations), 1) : null,
                'top_category' => $byCategory ? array_key_first($byCategory) : null,
            ],
            'trend' => [
                'granularity' => $buckets['granularity'],
                'labels' => $buckets['labels'],
                'datasets' => [
                    ['label' => 'Announcements posted', 'data' => array_values($trendAll)],
                    ['label' => 'Posted to Facebook', 'data' => array_values($trendFb)],
                ],
            ],
            'by_status' => $byStatus,
            'by_category' => $byCategory,
            'weekday' => $weekday,
            'facebook' => $fb,
            'announcements' => $list,
        ];
    }
}

if (!function_exists('announcement_fb_metrics')) {
    /**
     * Pulls views (impressions), reach (unique impressions), reactions, comments,
     * shares and clicks for the given announcements from the Facebook Graph API.
     *
     * Results are cached per post for 30 minutes (backend/cache/fb_metrics_cache.json)
     * so opening the page repeatedly does not hammer the Graph API. At most $limit
     * of the most recent posts are queried per request.
     *
     * @param  array $posts list of ['id','ref','title','fb_post_id','date_posted']
     * @return array ['available'=>bool,'error'=>?string,'totals'=>array,'posts'=>array,'fetched_at'=>string]
     */
    function announcement_fb_metrics(array $posts, bool $forceRefresh = false, int $limit = 40): array
    {
        $empty = ['impressions' => 0, 'reach' => 0, 'reactions' => 0, 'comments' => 0, 'shares' => 0, 'clicks' => 0];
        $posts = array_values(array_filter($posts, static fn($p) => !empty($p['fb_post_id'])));
        $out = ['available' => false, 'error' => null, 'insights_available' => false, 'totals' => $empty, 'posts' => [], 'fetched_at' => date('M j, Y g:i A'), 'limited' => count($posts) > $limit];

        if (!$posts) {
            $out['error'] = 'None of the announcements in this date range were posted to the Facebook Page.';
            return $out;
        }
        $posts = array_slice($posts, 0, $limit);

        if (!defined('SOE_LIB_INCLUDE')) {
            define('SOE_LIB_INCLUDE', true);
        }
        try {
            require_once __DIR__ . '/fb_helper.php';
            $token = fb_get_page_token();
        } catch (Throwable $e) {
            $out['error'] = 'Facebook is not connected, so views/reach cannot be read. An admin can reconnect it in Settings → Facebook.';
            return $out;
        }

        $cacheDir = __DIR__ . '/cache';
        $cacheFile = $cacheDir . '/fb_metrics_cache.json';
        $cache = [];
        if (is_file($cacheFile)) {
            $cache = json_decode((string) @file_get_contents($cacheFile), true) ?: [];
        }
        $ttl = 1800;
        $lastError = null;
        $dirty = false;

        foreach ($posts as $p) {
            $pid = (string) $p['fb_post_id'];
            $m = null;

            if (!$forceRefresh && isset($cache[$pid]) && (time() - (int) ($cache[$pid]['t'] ?? 0)) < $ttl) {
                $m = $cache[$pid]['m'];
            } else {
                $res = fb_graph_request('GET', '/' . rawurlencode($pid), [
                    'fields' => 'shares,reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0),permalink_url',
                    'access_token' => $token,
                ]);
                if (!$res['success']) {
                    $lastError = $res['data']['error']['message'] ?? 'Facebook request failed.';
                    continue;
                }
                $d = $res['data'];
                $m = [
                    'reactions' => (int) ($d['reactions']['summary']['total_count'] ?? 0),
                    'comments' => (int) ($d['comments']['summary']['total_count'] ?? 0),
                    'shares' => (int) ($d['shares']['count'] ?? 0),
                    'impressions' => null,
                    'reach' => null,
                    'clicks' => null,
                    'permalink' => $d['permalink_url'] ?? null,
                ];

                // Insights need the read_insights permission — optional.
                $ins = fb_graph_request('GET', '/' . rawurlencode($pid) . '/insights', [
                    'metric' => 'post_impressions,post_impressions_unique,post_clicks',
                    'access_token' => $token,
                ]);
                if ($ins['success']) {
                    foreach ($ins['data']['data'] ?? [] as $row) {
                        $val = (int) ($row['values'][0]['value'] ?? 0);
                        if ($row['name'] === 'post_impressions') {
                            $m['impressions'] = $val;
                        } elseif ($row['name'] === 'post_impressions_unique') {
                            $m['reach'] = $val;
                        } elseif ($row['name'] === 'post_clicks') {
                            $m['clicks'] = $val;
                        }
                    }
                }
                $cache[$pid] = ['t' => time(), 'm' => $m];
                $dirty = true;
            }

            $engagement = $m['reactions'] + $m['comments'] + $m['shares'] + (int) ($m['clicks'] ?? 0);
            $out['posts'][] = [
                'id' => $p['id'],
                'ref' => $p['ref'] ?? '',
                'title' => $p['title'] ?? '',
                'date_posted' => $p['date_posted'] ?? null,
                'engagement' => $engagement,
                'rate' => !empty($m['reach']) ? round($engagement / $m['reach'] * 100, 1) : null,
            ] + $m;

            foreach (['reactions', 'comments', 'shares'] as $k) {
                $out['totals'][$k] += (int) $m[$k];
            }
            foreach (['impressions', 'reach', 'clicks'] as $k) {
                if ($m[$k] !== null) {
                    $out['totals'][$k] += (int) $m[$k];
                    $out['insights_available'] = true;
                }
            }
        }

        if ($dirty) {
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0775, true);
                @file_put_contents($cacheDir . '/.htaccess', "Require all denied\nDeny from all\n");
            }
            @file_put_contents($cacheFile, json_encode($cache));
        }

        usort($out['posts'], static fn($a, $b) => $b['engagement'] <=> $a['engagement']);
        $out['available'] = (bool) $out['posts'];
        if (!$out['available']) {
            $out['error'] = 'Could not read the Facebook metrics: ' . ($lastError ?? 'unknown error');
        } elseif ($lastError) {
            $out['error'] = 'Some posts could not be read from Facebook: ' . $lastError;
        }

        $t = $out['totals'];
        $out['totals']['engagement'] = $t['reactions'] + $t['comments'] + $t['shares'] + $t['clicks'];
        $out['totals']['rate'] = $t['reach'] > 0 ? round($out['totals']['engagement'] / $t['reach'] * 100, 1) : null;
        $out['totals']['posts_read'] = count($out['posts']);

        return $out;
    }
}

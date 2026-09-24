<?php
/**
 * announcement_ai_summary.php
 * AJAX endpoint used by the "Generate Analytics" button on
 * frontend/announcement_analytics.php. Only runs when the admin presses the button.
 *
 * Uses the SAME date range as the page. Only aggregated counts (and post titles for
 * the top Facebook posts) are sent to the AI.
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../permission_helper.php';
require_permission($pdo, 'announcements', 'read');
require_once __DIR__ . '/csrf_helper.php';
require_once __DIR__ . '/ai_config.php';
require_once __DIR__ . '/announcement_analytics_data.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Your session has expired. Please refresh the page and try again.']);
    exit;
}

[$start, $end] = disaster_analytics_parse_range($_POST['start_date'] ?? null, $_POST['end_date'] ?? null);

try {
    $d = announcement_analytics_collect($pdo, $start, $end);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Could not read the announcement records.']);
    exit;
}

$s = $d['summary'];
if ($s['total'] === 0) {
    echo json_encode(['success' => false, 'error' => 'There are no announcements posted from ' . $d['range']['label'] . ', so there is nothing to analyse.']);
    exit;
}

// Cached Facebook figures only (no forced refresh) — keeps this call fast.
$fb = announcement_fb_metrics($d['announcements']);

$kv = static function (array $arr): string {
    $parts = [];
    foreach ($arr as $k => $v) {
        $parts[] = "{$k}: {$v}";
    }
    return $parts ? implode(', ', $parts) : 'none';
};

$lines = [];
$lines[] = 'Barangay: Biñang 2nd';
$lines[] = "Date range analysed: {$d['range']['label']} ({$d['range']['days']} days)";
$lines[] = "Announcements posted: {$s['total']} (previous period of the same length: {$s['prev_total']}); average {$s['per_week']} per week";
$lines[] = 'By status: ' . $kv($d['by_status']);
$lines[] = 'By category: ' . $kv($d['by_category']);
$lines[] = 'By day of week posted: ' . $kv($d['weekday']);

$trendParts = [];
foreach ($d['trend']['labels'] as $i => $label) {
    $n = (int) $d['trend']['datasets'][0]['data'][$i];
    if ($n > 0) {
        $trendParts[] = "{$label} {$n}";
    }
}
$lines[] = 'Posts per ' . $d['trend']['granularity'] . ' (non-zero only): ' . ($trendParts ? implode(', ', $trendParts) : 'none');
$lines[] = "Posted to Facebook: {$s['fb_posted']}; queued for Facebook: {$s['fb_queued']}";
$lines[] = "With attachments: {$s['with_attachments']} (with images: {$s['with_images']}); SMS sent: {$s['sms_sent']}; moved to trash: {$s['trashed']}";
$lines[] = 'Average active duration (days): ' . ($s['avg_duration_days'] ?? 'not available');

if ($fb['available']) {
    $t = $fb['totals'];
    $lines[] = "Facebook totals for {$t['posts_read']} posts — reactions {$t['reactions']}, comments {$t['comments']}, shares {$t['shares']}"
        . ($fb['insights_available'] ? ", views (impressions) {$t['impressions']}, reach {$t['reach']}, clicks {$t['clicks']}, engagement rate {$t['rate']}%" : ', views/reach not available (no insights permission)');
    $top = array_slice($fb['posts'], 0, 5);
    $tp = [];
    foreach ($top as $p) {
        $tp[] = '"' . mb_substr($p['title'], 0, 80) . "\" — engagement {$p['engagement']}"
            . ($p['reach'] !== null ? ", reach {$p['reach']}" : '');
    }
    $lines[] = 'Top Facebook posts: ' . implode(' | ', $tp);
} else {
    $lines[] = 'Facebook engagement data: not available';
}

$systemPrompt =
    "You are a communications analyst reviewing a Barangay (village) announcement board and its Facebook Page "
    . "in the Philippines.\n"
    . "Rules:\n"
    . "- Write in clear, professional English only.\n"
    . "- Use ONLY the figures provided. Never invent numbers, dates or names.\n"
    . "- If a figure is zero or not available, say so plainly instead of guessing.\n"
    . "- Be concrete: cite the actual counts, categories and periods.\n"
    . "- Keep every item to one or two short sentences.\n"
    . "- Recommended actions must be practical for barangay staff (posting schedule, use of images, "
    . "Facebook cross-posting, categories that need more coverage, clean-up of expired posts).\n"
    . "Give 3 to 5 items in each array.";

$responseSchema = [
    'type' => 'OBJECT',
    'properties' => [
        'headline' => ['type' => 'STRING'],
        'key_findings' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
        'trends' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
        'recommended_actions' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
        'other_observations' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
    ],
    'required' => ['headline', 'key_findings', 'trends', 'recommended_actions', 'other_observations'],
];

$result = gemini_generate(
    [['text' => "Here are the aggregated announcement figures:\n\n" . implode("\n", $lines)]],
    $systemPrompt,
    [
        'maxOutputTokens' => 2500,
        'temperature' => 0.5,
        'responseMimeType' => 'application/json',
        'responseSchema' => $responseSchema,
    ]
);

if (!$result['success']) {
    echo json_encode(['success' => false, 'error' => $result['error']]);
    exit;
}

$meta = ['success' => true, 'generated' => date('M j, Y g:i A'), 'range' => $d['range']];

$clean = trim(preg_replace('/^```(?:json)?|```$/mi', '', $result['text']));
$summary = json_decode($clean, true);
if (!is_array($summary) || empty($summary['headline'])) {
    echo json_encode($meta + ['raw' => $result['text']]);
    exit;
}

$list = static fn($v) => array_values(array_slice(array_map('strval', (array) ($v ?? [])), 0, 6));
echo json_encode($meta + [
    'summary' => [
        'headline' => (string) $summary['headline'],
        'key_findings' => $list($summary['key_findings'] ?? []),
        'trends' => $list($summary['trends'] ?? []),
        'recommended_actions' => $list($summary['recommended_actions'] ?? []),
        'other_observations' => $list($summary['other_observations'] ?? []),
    ],
]);

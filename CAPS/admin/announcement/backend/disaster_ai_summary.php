<?php
/**
 * disaster_ai_summary.php
 * AJAX endpoint used by the "Generate Analytics" button on
 * frontend/disaster_analytics.php.
 *
 * The AI is NEVER called automatically — only when the admin presses the button —
 * so Gemini API tokens are not wasted on every page load / filter change.
 *
 * Uses the SAME date range (Start Date → End Date) and category as the page, via
 * the shared disaster_analytics_collect(). Only aggregated counts are sent to the
 * AI — never raw resident data.
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../permission_helper.php';
require_permission($pdo, 'announcements', 'read');
require_once __DIR__ . '/csrf_helper.php';
require_once __DIR__ . '/ai_config.php';
require_once __DIR__ . '/disaster_analytics_data.php';

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
$category = trim($_POST['category'] ?? 'All');

try {
    $d = disaster_analytics_collect($pdo, $start, $end, $category, false);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Could not read the disaster records.']);
    exit;
}

$s = $d['summary'];
$category = $d['category'];
$catLabel = $category === 'All' ? 'All categories' : $category;

if ($s['total_alerts'] === 0 && $s['reports'] === 0) {
    echo json_encode([
        'success' => false,
        'error' => 'There are no disaster records from ' . $d['range']['label']
            . ($category === 'All' ? '' : ' under ' . $category) . ', so there is nothing to analyse.',
    ]);
    exit;
}

// ── Flatten the figures into a compact brief for the model ────────────────
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
$lines[] = "Category filter: {$catLabel}";
$lines[] = "Total disaster alerts issued: {$s['total_alerts']} (previous period of the same length "
    . date('M j, Y', strtotime($d['range']['prev_start'])) . ' – ' . date('M j, Y', strtotime($d['range']['prev_end']))
    . ": {$s['prev_total']})";
$lines[] = "Alerts still active: {$s['active_alerts']}; deactivated: {$s['deactivated_alerts']}";

$typeParts = [];
foreach ($d['by_type'] as $t) {
    $typeParts[] = "{$t['type']} — alerts {$t['alerts']}, reports {$t['reports']}, affected {$t['affected']}, "
        . "evacuees {$t['evacuees']}, injuries {$t['injuries']}, casualties {$t['casualties']}";
}
$lines[] = 'Per category: ' . ($typeParts ? implode(' | ', $typeParts) : 'none');

$trendParts = [];
foreach ($d['trend']['labels'] as $i => $label) {
    $sum = 0;
    foreach ($d['trend']['datasets'] as $ds) {
        $sum += (int) $ds['data'][$i];
    }
    if ($sum > 0) {
        $trendParts[] = "{$label} {$sum}";
    }
}
$lines[] = 'Alerts per ' . $d['trend']['granularity'] . ' (non-zero only): ' . ($trendParts ? implode(', ', $trendParts) : 'none');
$lines[] = 'Alerts by severity: ' . $kv($d['by_severity']);
$lines[] = 'Alerts by day of week: ' . $kv($d['weekday']);
$lines[] = 'Notification channels used: ' . $kv($d['channels']);
$lines[] = 'Final report status: ' . $kv($d['by_report_status']);
$lines[] = "Incident reports filed: {$s['reports']}; affected residents {$s['affected']}; evacuees {$s['evacuees']}; "
    . "injuries {$s['injuries']}; casualties {$s['casualties']}";
$lines[] = 'Average hours from alert to final report: ' . ($s['avg_resolve_hours'] ?? 'not available');
$lines[] = "SMS broadcasts: {$s['sms_broadcasts']}; recipients {$s['sms_recipients']}; delivered {$s['sms_sent']}";

$brief = implode("\n", $lines);

$systemPrompt =
    "You are a data analyst writing a disaster analytics report for a Barangay (village) disaster risk "
    . "reduction and management officer in the Philippines.\n"
    . "Rules:\n"
    . "- Write in clear, professional English only.\n"
    . "- Use ONLY the figures provided. Never invent numbers, dates, locations or names.\n"
    . "- If a figure is zero or missing, say so plainly instead of guessing.\n"
    . "- Be concrete: cite the actual counts, categories and periods when describing a finding or trend.\n"
    . "- Compare against the previous period when it is useful.\n"
    . "- Keep every item to one or two short sentences.\n"
    . "- Recommended actions must be practical things a barangay can do (pre-positioning of relief goods, "
    . "drills, clearing of waterways, advisories, evacuation-centre readiness, equipment checks, SMS list upkeep).\n"
    . "- Other observations are useful notes that do not fit the other sections (data gaps, channel usage, "
    . "response time, reporting completeness).\n"
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
    [['text' => "Here are the aggregated disaster figures:\n\n" . $brief]],
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

$meta = [
    'success' => true,
    'generated' => date('M j, Y g:i A'),
    'range' => $d['range'],
    'category' => $category,
    'stats' => [
        'total' => $s['total_alerts'],
        'prev_total' => $s['prev_total'],
        'reports' => $s['reports'],
        'evacuees' => $s['evacuees'],
    ],
];

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
        'recommended_actions' => $list($summary['recommended_actions'] ?? ($summary['recommendations'] ?? [])),
        'other_observations' => $list($summary['other_observations'] ?? []),
    ],
]);

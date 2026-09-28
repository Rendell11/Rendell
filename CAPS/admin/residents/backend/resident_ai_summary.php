<?php
/**
 * resident_ai_summary.php — AI Analytics for Resident Analytics (JSON, POST).
 * Called ONLY when the user clicks "Generate AI Analytics" (never on page load).
 *   start, end          date range (same rules as resident_analytics_data.php)
 *   cached_only=1       return an analysis generated before for the same data (no Gemini call)
 * Uses the existing CAPS Gemini client (admin/ai_helper.php, GEMINI_API_KEY from .env — server side only)
 * and sends aggregated figures only (no names or personal data).
 * The result is cached by data snapshot, so the report can include it without calling Gemini again.
 */
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../permission_helper.php';
require_once __DIR__ . '/../../ai_helper.php';
require_once __DIR__ . '/resident_analytics_data.php';

header('Content-Type: application/json; charset=utf-8');
$out = static function (array $d, int $code = 200): never {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
};

if (!staff_can($pdo, 'residents', 'read')) $out(['success' => false, 'error' => 'Access denied.'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(['success' => false, 'error' => 'Method not allowed.'], 405);
$csrf = (string)($_POST['csrf_token'] ?? '');
if ($csrf === '' || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
    $out(['success' => false, 'error' => 'Session expired. Refresh the page.'], 403);
}

try {
    $p = ra_params($_POST);
    $a = ra_compute($pdo, $p);
} catch (Throwable $e) {
    error_log('[Resident AI] data: ' . $e->getMessage());
    $out(['success' => false, 'error' => 'Could not read resident statistics.'], 500);
}

$snapshot = ra_snapshot($a);
if (!empty($_POST['cached_only'])) {
    $c = ai_cache_get('residents', $snapshot);
    $out($c ? $c + ['cached' => true] : ['success' => true, 'none' => true]);
}
if (!$a['totals']['total']) $out(['success' => false, 'error' => 'No resident records match the selected date range.']);
if (ai_api_key() === '') $out(['success' => false, 'error' => 'AI is not configured. Set GEMINI_API_KEY in the CAPS .env file.']);

$prompt = "You are a data analyst for a Philippine barangay (Resident Records / Community Profiling). Analyze ONLY the aggregated resident data below "
    . "for the date range: " . $p['label'] . " (residents registered within the range; age, household and area figures describe the living residents). "
    . "Do not invent numbers or personal facts; cite the actual figures and percentages. Keep each detail to one or two short sentences. "
    . "If the data is thin, say so briefly.\n"
    . "- key_findings: the most important facts (population size, sex ratio, age structure, households, deceased records).\n"
    . "- demographic_trends: age / sex / civil status / relationship patterns and what they imply.\n"
    . "- patterns: significant changes or patterns (registrations per month, comparison with the previous period, household sizes, unlinked residents).\n"
    . "- population_observations: where people live (areas / streets), vulnerable groups (seniors, PWD, minors, solo parents), program coverage.\n"
    . "- recommendations: concrete actions for the barangay, each tied to a finding.\n\n"
    . "Return JSON: {\"summary\": string (1-2 sentences), "
    . "\"key_findings\": [{\"title\": string, \"detail\": string}] (3-4 items), "
    . "\"demographic_trends\": [{\"title\": string, \"detail\": string}] (2-3 items), "
    . "\"patterns\": [{\"title\": string, \"detail\": string}] (2-3 items), "
    . "\"population_observations\": [{\"title\": string, \"detail\": string}] (2-3 items), "
    . "\"recommendations\": [{\"action\": string, \"reason\": string, \"priority\": \"high\"|\"medium\"|\"low\"}] (3-4 items)}.\n\n"
    . "DATA:\n" . json_encode($snapshot, JSON_UNESCAPED_UNICODE);

$r = ai_call([['text' => $prompt]], '', ['temperature' => 0.3]);
$parsed = $r['ok'] ? ai_parse_json($r['text']) : null;
if (!is_array($parsed) || empty($parsed['key_findings'])) {
    $out(['success' => false, 'error' => $r['error'] ?: 'The AI service returned an unreadable answer. Please try again.']);
}

$clean = static fn($v) => mb_substr(trim((string)$v), 0, 600);
$items = static function ($list) use ($clean): array {
    $res = [];
    foreach (is_array($list) ? $list : [] as $x) {
        if (!is_array($x) || trim((string)($x['title'] ?? '')) === '') continue;
        $res[] = ['title' => $clean($x['title']), 'detail' => $clean($x['detail'] ?? '')];
    }
    return array_slice($res, 0, 5);
};
$recs = [];
foreach (is_array($parsed['recommendations'] ?? null) ? $parsed['recommendations'] : [] as $x) {
    if (!is_array($x) || trim((string)($x['action'] ?? '')) === '') continue;
    $recs[] = [
        'action' => $clean($x['action']),
        'reason' => $clean($x['reason'] ?? ''),
        'priority' => in_array($x['priority'] ?? '', ['high', 'medium', 'low'], true) ? $x['priority'] : 'medium',
    ];
}

$result = [
    'success' => true,
    'ai' => true,
    'model' => $r['model'],
    'generated_at' => date('F j, Y g:i A'),
    'period' => $p,
    'summary' => $clean($parsed['summary'] ?? ''),
    'key_findings' => $items($parsed['key_findings']),
    'demographic_trends' => $items($parsed['demographic_trends'] ?? []),
    'patterns' => $items($parsed['patterns'] ?? []),
    'population_observations' => $items($parsed['population_observations'] ?? []),
    'recommendations' => array_slice($recs, 0, 5),
];
ai_cache_put('residents', $snapshot, $result);

try {
    require_once __DIR__ . '/../../activity_log_helper.php';
    log_activity('Residents', 'Generate AI Analytics', 'Generated resident AI analytics for ' . $p['label']);
} catch (Throwable $e) {
    error_log('[Resident AI] activity log: ' . $e->getMessage());
}
$out($result);

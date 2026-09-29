<?php
/**
 * cert_ai_analytics.php — AI Analytics for Legal Document Analytics (JSON, POST).
 * Called ONLY when the user clicks "Generate AI Analytics" (never generated on page load).
 *   start, end          date range (same rules as cert_analytics_data.php; empty = all records)
 *   cached_only=1       return an analysis generated before for the same data (no Gemini call)
 *   csrf_token          Certificates CSRF token (CERT.post adds it)
 * Uses the existing CAPS Gemini client (admin/ai_helper.php) and sends aggregated figures only.
 * The result is cached by data snapshot, so the report can include it without calling Gemini again.
 */
require_once __DIR__ . '/../../db.php';
$required_module = 'certificates';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/cert_analytics_data.php';
require_once __DIR__ . '/../../ai_helper.php';

$out = function (array $d, int $code = 200) { cert_json($d, $code); };
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(['success' => false, 'error' => 'Method not allowed.'], 405);
cert_csrf_verify();
if (!cert_can($pdo, 'read')) $out(['success' => false, 'error' => 'Access denied.'], 403);

try {
    cert_migrate($pdo);
    $p = cert_analytics_params($_POST);
    $a = cert_analytics_compute($pdo, $p);
} catch (Throwable $e) {
    error_log('[Certificates AI] data: ' . $e->getMessage());
    $out(['success' => false, 'error' => 'Could not read the legal document statistics.'], 500);
}

$snapshot = cert_analytics_snapshot($a);
$ns = 'certificates_analytics';
if (!empty($_POST['cached_only'])) {
    $c = ai_cache_get($ns, $snapshot);
    $out($c ? $c + ['cached' => true] : ['success' => true, 'none' => true]);
}
if (!$a['totals']['total']) $out(['success' => false, 'error' => 'No document requests match the selected date range.']);
if (ai_api_key() === '') $out(['success' => false, 'error' => 'AI is not configured. Set GEMINI_API_KEY in the CAPS .env file.']);

$prompt = "You are a data analyst for a Philippine barangay office (Legal Documents / certificates and clearances). Analyze ONLY the aggregated request data below "
    . "for the date range: " . $p['label'] . " (walk-in and online requests, releases, rejections, documents that expired unclaimed after " . CERT_PICKUP_DAYS . " days). "
    . "Do not invent numbers or personal facts; cite the actual figures and percentages. Keep each detail to one or two short sentences. If the data is thin, say so briefly.\n"
    . "- key_findings: the most important facts (volume, most requested documents, release rate, processing time, rejections, unclaimed).\n"
    . "- demographic_trends: request trends (walk-in vs online, over time, busiest day / hour, requester sex and age).\n"
    . "- patterns: significant changes or patterns (comparison with the previous period, purposes, rejection reasons, document-specific turnaround).\n"
    . "- population_observations: service observations (areas where requests come from, pending / ready / expired documents needing attention).\n"
    . "- recommendations: concrete actions for the barangay office, each tied to a finding.\n\n"
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

$clean = fn($v) => mb_substr(trim((string)$v), 0, 600);
$items = function ($list) use ($clean): array {
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
    $recs[] = ['action' => $clean($x['action']), 'reason' => $clean($x['reason'] ?? ''),
               'priority' => in_array($x['priority'] ?? '', ['high', 'medium', 'low'], true) ? $x['priority'] : 'medium'];
}
$result = [
    'success' => true, 'ai' => true, 'model' => $r['model'], 'generated_at' => date('F j, Y g:i A'), 'period' => $p,
    'summary' => $clean($parsed['summary'] ?? ''),
    'key_findings' => $items($parsed['key_findings']),
    'demographic_trends' => $items($parsed['demographic_trends'] ?? []),
    'patterns' => $items($parsed['patterns'] ?? []),
    'population_observations' => $items($parsed['population_observations'] ?? []),
    'recommendations' => array_slice($recs, 0, 5),
];
ai_cache_put($ns, $snapshot, $result);
cert_log_activity('Generate AI Analytics', 'Generated legal document AI analytics for ' . $p['label']);
$out($result);

<?php
/**
 * AI Analytics Overview for Certificate Analytics (JSON).
 * Only generated on request (button). Sends aggregated numbers only.
 *   ?cached_only=1  page load: show an analysis generated before for the same data (no API call)
 *   ?refresh=1      regenerate
 * Results are cached by data snapshot (md5) in admin/cache/ai/.
 */
require_once __DIR__ . '/../../db.php';
$required_module = 'certificates';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/cert_analytics_data.php';
require_once __DIR__ . '/../../ai_helper.php';
cert_migrate($pdo);

$out = function (array $d) { cert_json($d); };
try {
    $p = cert_analytics_params($_GET);
    $a = cert_analytics_compute($pdo, $p);
} catch (Throwable $e) {
    error_log('[Certificates] analytics: ' . $e->getMessage());
    $out(['ok' => false, 'error' => 'Unable to load analytics data.']);
}

$rules = cert_analytics_rules($a);
$fallback = ['ok' => true, 'ai' => false, 'summary' => ''] + $rules;
if (!$a['totals']['total']) $out($fallback + ['note' => 'No certificate requests in this period.']);

$snapshot = cert_analytics_snapshot($a);
if (!empty($_GET['cached_only'])) {
    $c = ai_cache_get('certificates', $snapshot);
    $out($c ? $c + ['cached' => true] : ['ok' => true, 'none' => true]);
}
if (empty($_GET['refresh']) && ($c = ai_cache_get('certificates', $snapshot))) $out($c + ['cached' => true]);
if (ai_api_key() === '') $out($fallback + ['note' => 'GEMINI_API_KEY is not set in the CAPS .env. Showing automatic insights.']);

$prompt = "You are an operations analyst for a Philippine barangay office. Analyze ONLY the certificate / clearance request data below "
    . "(walk-in and online requests, releases, rejections, unclaimed documents that expired after " . CERT_PICKUP_DAYS . " days). "
    . "Do not invent numbers; cite the actual figures. Keep each detail to one short sentence. If data is thin, say so briefly.\n"
    . "- key_findings: the most important facts (volume, most requested documents, processing time, rejections, unclaimed).\n"
    . "- trends: how things changed over the period (use over_time, weekday, busiest hour).\n"
    . "- actions: concrete steps the barangay can take, each tied to a finding.\n\n"
    . "Return JSON: {\"summary\": string (1-2 sentences), "
    . "\"key_findings\": [{\"title\": string, \"detail\": string, \"severity\": \"good\"|\"info\"|\"warning\"}] (3-4 items), "
    . "\"trends\": [{\"title\": string, \"detail\": string, \"direction\": \"up\"|\"down\"|\"stable\"}] (2-3 items), "
    . "\"actions\": [{\"action\": string, \"reason\": string, \"priority\": \"high\"|\"medium\"|\"low\"}] (2-4 items)}.\n\n"
    . "DATA:\n" . json_encode($snapshot, JSON_UNESCAPED_UNICODE);

$r = ai_call([['text' => $prompt]]);
$parsed = $r['ok'] ? ai_parse_json($r['text']) : null;
if (!is_array($parsed) || empty($parsed['key_findings'])) {
    $out($fallback + ['note' => 'AI service is unavailable right now. Showing automatic insights.']);
}
$clean = fn($v) => mb_substr(trim((string)$v), 0, 600);
$pick = fn($v, $ok, $def) => in_array($v, $ok, true) ? $v : $def;
$result = [
    'ok' => true, 'ai' => true, 'model' => $r['model'], 'generated_at' => date('M j, Y g:i A'),
    'summary' => $clean($parsed['summary'] ?? ''),
    'key_findings' => array_map(fn($i) => ['title' => $clean($i['title'] ?? ''), 'detail' => $clean($i['detail'] ?? ''), 'severity' => $pick($i['severity'] ?? '', ['good', 'info', 'warning'], 'info')], array_slice((array)$parsed['key_findings'], 0, 4)),
    'trends' => array_map(fn($i) => ['title' => $clean($i['title'] ?? ''), 'detail' => $clean($i['detail'] ?? ''), 'direction' => $pick($i['direction'] ?? '', ['up', 'down', 'stable'], 'stable')], array_slice((array)($parsed['trends'] ?? []), 0, 3)),
    'actions' => array_map(fn($i) => ['action' => $clean($i['action'] ?? ''), 'reason' => $clean($i['reason'] ?? ''), 'priority' => $pick($i['priority'] ?? '', ['high', 'medium', 'low'], 'medium')], array_slice((array)($parsed['actions'] ?? []), 0, 4)),
];
if (!$result['trends']) $result['trends'] = $rules['trends'];
if (!$result['actions']) $result['actions'] = $rules['actions'];
ai_cache_put('certificates', $snapshot, $result);
cert_log_activity('Generate AI Analytics', 'Certificate analytics ' . $p['from'] . ' to ' . $p['to']);
$out($result);

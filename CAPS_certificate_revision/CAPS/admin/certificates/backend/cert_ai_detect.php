<?php
/**
 * cert_ai_detect.php — "AI Auto Detect" in the layout editor.
 * POST id (document type id), keys (JSON list of field keys to place), csrf_token.
 * Sends the template image + field list to Gemini and returns suggested positions
 * (% of the page). Nothing is saved: the editor applies them and the admin reviews + saves.
 */
ob_start();
require_once __DIR__ . '/../../db.php';
$required_module = 'certificates';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/cert_common.php';
require_once __DIR__ . '/../../ai_helper.php';
cert_migrate($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') cert_json(['success' => false, 'message' => 'POST only.'], 405);
cert_csrf_verify();
cert_require($pdo, 'update');

$id = (int)($_POST['id'] ?? 0);
$s = $pdo->prepare("SELECT doc_type FROM custom_document_types WHERE id = ?");
$s->execute([$id]);
$docType = $s->fetchColumn();
if ($docType === false) cert_json(['success' => false, 'message' => 'Document type not found.'], 404);
$tpl = cert_template_for($pdo, (string)$docType);
$path = $tpl['background_image_path'] ?? '';
$abs = $path ? realpath(__DIR__ . '/../../../' . preg_replace('#^(\.\./)+#', '', $path)) : false;
$root = realpath(__DIR__ . '/../../..');
if (!$abs || strpos($abs, $root) !== 0 || !is_file($abs)) cert_json(['success' => false, 'message' => 'Upload the template image first.'], 422);

$labels = cert_field_labels($pdo, (string)$docType);
$keys = json_decode((string)($_POST['keys'] ?? '[]'), true);
$keys = array_values(array_filter(is_array($keys) ? $keys : [], fn($k) => isset($labels[$k])));
if (!$keys) $keys = json_decode((string)($tpl['layout_json'] ?? ''), true)['selected'] ?? [];
$keys = array_values(array_filter($keys, fn($k) => isset($labels[$k])));
if (!$keys) cert_json(['success' => false, 'message' => 'Select the fields for this document first (step 2).'], 422);

// Downscale big images to keep the request small (the AI only needs to read the lines).
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($abs);
$data = (string)file_get_contents($abs);
if (function_exists('imagecreatefromstring') && ($img = @imagecreatefromstring($data))) {
    $w = imagesx($img); $hgt = imagesy($img); $max = 1600;
    if (max($w, $hgt) > $max) {
        $r = $max / max($w, $hgt);
        $dst = imagecreatetruecolor((int)($w * $r), (int)($hgt * $r));
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $img, 0, 0, 0, 0, (int)($w * $r), (int)($hgt * $r), $w, $hgt);
        ob_start(); imagejpeg($dst, null, 85); $data = (string)ob_get_clean();
        $mime = 'image/jpeg';
    }
}

$fieldList = implode("\n", array_map(fn($k) => '- ' . $k . ': ' . $labels[$k], $keys));
$prompt = "This image is a Philippine barangay certificate template (a printed form with blank lines). "
    . "Find where each field below should be written, e.g. \"Name: ______\" gets the Full Name on that blank line, "
    . "\"this ___ day of ___\" gets the day / month issued, a signature line above \"Punong Barangay\" gets the captain's name.\n"
    . "Fields (key: label):\n" . $fieldList . "\n\n"
    . "Return ONLY a JSON array: [{\"field_key\": string, \"pos_x\": number, \"pos_y\": number, \"width\": number|null, \"text_align\": \"left\"|\"center\"|\"right\"}].\n"
    . "Coordinates are percentages of the whole image (0-100). pos_y = vertical middle of the text line (just above the underline). "
    . "For text_align \"left\" pos_x is where the text starts; for \"center\" it is the middle of the blank; for \"right\" it is where the text ends. "
    . "width = length of the blank line in % of the image width, or null. Skip a field if there is no place for it. Use only the keys listed.";

$r = ai_call([
    ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($data)]],
    ['text' => $prompt],
], '', ['temperature' => 0.1, 'maxOutputTokens' => 4096]);

if (!$r['ok']) cert_json(['success' => false, 'message' => $r['error'] . ' You can still place the fields manually.']);
$parsed = ai_parse_json($r['text']);
if (isset($parsed['fields']) && is_array($parsed['fields'])) $parsed = $parsed['fields'];
if (!is_array($parsed)) cert_json(['success' => false, 'message' => 'The AI answer could not be read. You can still place the fields manually.']);

$out = []; $seen = [];
foreach ($parsed as $p) {
    if (!is_array($p) || !in_array($p['field_key'] ?? '', $keys, true) || isset($seen[$p['field_key']])) continue;
    if (!is_numeric($p['pos_x'] ?? null) || !is_numeric($p['pos_y'] ?? null)) continue;
    $seen[$p['field_key']] = true;
    $c = cert_clean_position($p + ['font_size' => 16]);
    $out[] = ['field_key' => $c['field_key'], 'pos_x' => $c['pos_x'], 'pos_y' => $c['pos_y'], 'width' => $c['width'], 'text_align' => $c['text_align']];
}
cert_log_activity('AI Auto Detect', 'AI suggested positions for ' . count($out) . ' field(s) on ' . $docType . ' (not saved)');
cert_json(['success' => true, 'positions' => $out, 'model' => $r['model']]);

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
    . "For each field, find the BLANK area (the underline / empty space) where its value must be written — not the printed label.\n"
    . "Return ONLY a JSON array: [{\"field_key\": string, \"box_2d\": [ymin, xmin, ymax, xmax], \"text_align\": \"left\"|\"center\"|\"right\"}].\n"
    . "box_2d is the box of that blank area, normalized to 0-1000 over the whole image (0,0 = top-left, 1000,1000 = bottom-right). "
    . "Use \"left\" for blanks inside a sentence, \"center\" for blanks centered on the page or above a signature title. "
    . "Skip a field if there is no place for it. Use only the keys listed. Each key at most once.";

$r = ai_call([
    ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($data)]],
    ['text' => $prompt],
], '', ['temperature' => 0.1, 'maxOutputTokens' => 4096]);

if (!$r['ok']) cert_json(['success' => false, 'message' => $r['error'] . ' You can still place the fields manually.']);
$parsed = ai_parse_json($r['text']);
if (isset($parsed['fields']) && is_array($parsed['fields'])) $parsed = $parsed['fields'];
if (!is_array($parsed)) cert_json(['success' => false, 'message' => 'The AI answer could not be read. You can still place the fields manually.']);

/**
 * Gemini vision answers in 0-1000 normalized boxes: box_2d = [ymin, xmin, ymax, xmax].
 * Converted here to the editor's % of the page: x per text_align, y = middle of the blank, width = blank length.
 * (Older-style answers with pos_x/pos_y are accepted too; values above 100 are treated as 0-1000.)
 */
function ai_detect_to_position(array $p): ?array {
    $align = in_array($p['text_align'] ?? '', ['left', 'center', 'right'], true) ? $p['text_align'] : 'left';
    $box = $p['box_2d'] ?? $p['box'] ?? null;
    if (is_array($box) && count($box) === 4 && count(array_filter($box, 'is_numeric')) === 4) {
        [$y1, $x1, $y2, $x2] = array_map('floatval', array_values($box));
        $scale = max($y1, $x1, $y2, $x2) <= 1.0 ? 100 : 0.1; // Gemini's box_2d is 0-1000 (0-1 accepted too)
        [$y1, $x1, $y2, $x2] = [$y1 * $scale, $x1 * $scale, $y2 * $scale, $x2 * $scale];
        if ($x2 < $x1) [$x1, $x2] = [$x2, $x1];
        if ($y2 < $y1) [$y1, $y2] = [$y2, $y1];
        $x = $align === 'left' ? $x1 + 0.5 : ($align === 'right' ? $x2 - 0.5 : ($x1 + $x2) / 2);
        $y = ($y1 + $y2) / 2;
        $w = ($x2 - $x1) >= 2 ? $x2 - $x1 : null;
    } elseif (is_numeric($p['pos_x'] ?? null) && is_numeric($p['pos_y'] ?? null)) {
        $x = (float)$p['pos_x']; $y = (float)$p['pos_y'];
        $w = is_numeric($p['width'] ?? null) ? (float)$p['width'] : null;
        if ($x > 100 || $y > 100) { $x /= 10; $y /= 10; $w = $w !== null ? $w / 10 : null; }
    } else {
        return null;
    }
    if ($x < 0 || $x > 100 || $y < 0 || $y > 100) return null; // out of the page: skip instead of piling up in a corner
    return ['pos_x' => round($x, 2), 'pos_y' => round($y, 2), 'width' => $w !== null ? round(min(100, $w), 2) : null, 'text_align' => $align];
}

$out = []; $seen = [];
foreach ($parsed as $p) {
    if (!is_array($p) || !in_array($p['field_key'] ?? '', $keys, true) || isset($seen[$p['field_key']])) continue;
    $pos = ai_detect_to_position($p);
    if (!$pos) continue;
    $seen[$p['field_key']] = true;
    $c = cert_clean_position(['field_key' => $p['field_key'], 'font_size' => 16] + $pos);
    $out[] = ['field_key' => $c['field_key'], 'pos_x' => $c['pos_x'], 'pos_y' => $c['pos_y'], 'width' => $c['width'], 'text_align' => $c['text_align']];
}
cert_log_activity('AI Auto Detect', 'AI suggested positions for ' . count($out) . ' field(s) on ' . $docType . ' (not saved)');
cert_json(['success' => true, 'positions' => $out, 'model' => $r['model']]);

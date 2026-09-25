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
// The checked fields are only a hint: the AI may use ANY available field that fits a blank
// (e.g. "born on ____" → Birth Date even if only Birth Place was checked), and must skip
// checked fields that have no matching blank instead of forcing them somewhere.
$preferred = json_decode((string)($_POST['keys'] ?? '[]'), true);
$preferred = array_values(array_filter(is_array($preferred) ? $preferred : [], fn($k) => isset($labels[$k])));
if (!$preferred) $preferred = array_values(array_filter(json_decode((string)($tpl['layout_json'] ?? ''), true)['selected'] ?? [], fn($k) => isset($labels[$k])));
$keys = array_keys($labels);
$samples = [];
foreach (cert_field_catalog($pdo) as $k => $f) $samples[$k] = $f['sample'];
$paperH = cert_paper($tpl['paper_size'] ?? 'a4')['h'];

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

$fieldList = implode("\n", array_map(fn($k) => '- ' . $k . ': ' . $labels[$k] . ' (example: "' . ($samples[$k] ?? $labels[$k]) . '")'
    . (in_array($k, $preferred, true) ? ' [selected by admin]' : ''), $keys));
$prompt = "This image is a blank Philippine barangay certificate template: printed text with blank underlines to be filled in.\n"
    . "Task: for EVERY blank that should be filled, choose the ONE field below whose value belongs there, based on the words printed right before/after the blank.\n"
    . "Available fields (key: label, example value):\n" . $fieldList . "\n\n"
    . "Rules:\n"
    . "- Match the MEANING of the surrounding words, e.g. \"whose name ____\" = full_name, \"born on ____\" = birth_date, \"born in / place of birth ____\" = birth_place, "
    . "\"a resident of ____\" = purok or complete_address, \"House No. ____\" = house_number, \"Street ____\" = street, \"No. ____\" near the title = document_number, "
    . "\"Issued this ____ day\" = day_issued, \"day of ____\" = month_issued (or month_year_issued if no year blank follows), \"20__\" = year_issued_short, "
    . "\"purpose of ____\" = purpose, the line above \"Punong Barangay\" = captain_name — but if \"HON.\" is already printed before that line use captain_name_only, "
    . "the line above \"Barangay Secretary\" or the issuing officer title = issuing_officer.\n"
    . "- \"born on ____\" is ALWAYS birth_date (a date), never birth_place.\n"
    . "- Only place a field ON an existing underline or clearly empty blank. Never place a field in open space between paragraphs, over printed words, or where no underline exists — leave it out instead.\n"
    . "- Prefer fields marked [selected by admin] when they fit, but NEVER put a field where its meaning does not match. Leave a field out if no blank matches it.\n"
    . "- Do not place anything on printed text that is already complete, on the letterhead, or on seals/signature-free areas.\n"
    . "- box_2d = the box of the BLANK (the underline or empty space), not the printed label, normalized 0-1000 over the whole image (0,0 top-left, 1000,1000 bottom-right). "
    . "The box height should be the height of one text line sitting on the underline.\n"
    . "- text_align: \"left\" for blanks inside a sentence, \"center\" for blanks centered above a title/signature or centered on the page.\n"
    . "- Each field at most once; each blank at most one field.\n"
    . "Return ONLY a JSON array: [{\"field_key\": string, \"box_2d\": [ymin, xmin, ymax, xmax], \"text_align\": \"left\"|\"center\"|\"right\"}].";

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
    return ['pos_x' => round($x, 2), 'pos_y' => round($y, 2), 'width' => $w !== null ? round(min(100, $w), 2) : null, 'text_align' => $align,
            'height' => isset($y1, $y2) && $y2 > $y1 ? $y2 - $y1 : null,
            'bottom' => isset($y2) ? $y2 : null]; // the underline
}

$out = []; $seen = [];
foreach ($parsed as $p) {
    if (!is_array($p) || !in_array($p['field_key'] ?? '', $keys, true) || isset($seen[$p['field_key']])) continue;
    $pos = ai_detect_to_position($p);
    if (!$pos) continue;
    $seen[$p['field_key']] = true;
    $c = cert_clean_position(['field_key' => $p['field_key'], 'font_size' => 14] + $pos);
    $out[] = ['field_key' => $c['field_key'], 'pos_x' => $c['pos_x'], 'pos_y' => $c['pos_y'], 'width' => $c['width'], 'text_align' => $c['text_align'],
              'font_size' => 14, '_h' => $pos['height'] ?? null, '_b' => $pos['bottom'] ?? null];
}
// ONE font size for the whole document, from the typical (median) line height, so fields look
// like the printed body text instead of a mix of sizes. Long values shrink to fit on screen/print.
$hs = array_values(array_filter(array_column($out, '_h')));
sort($hs);
$font = $hs ? (int)max(14, min(18, round($hs[intdiv(count($hs), 2)] / 100 * $paperH * 0.8))) : 15;
foreach ($out as &$o) {
    $o['font_size'] = $font;
    // pos_y is the middle of the text. Put the text ON the underline (its bottom just above the
    // line) instead of centering it on the line, which made the underline strike through the text.
    if ($o['_b'] !== null) $o['pos_y'] = round(max(0, $o['_b'] - ($font * 0.62 + 1) / $paperH * 100), 2);
    unset($o['_h'], $o['_b']);
}
unset($o);
cert_log_activity('AI Auto Detect', 'AI suggested positions for ' . count($out) . ' field(s) on ' . $docType . ' (not saved)');
$dropped = array_values(array_diff($preferred, array_column($out, 'field_key')));
cert_json(['success' => true, 'positions' => $out, 'unplaced' => $dropped, 'model' => $r['model']]);

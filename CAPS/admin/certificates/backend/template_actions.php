<?php
/**
 * template_actions.php — JSON endpoint for the Template Builder (document_templates.php).
 *
 * GET  ?action=list                 all document types (drafts included) for the builder list
 * GET  ?action=get&id=N             one document type with template, requirements, extra fields, layout
 * GET  ?action=catalog              dynamic field list (from the residents table + system values)
 * POST action=save_type             wizard steps 1–4 (Save Draft / Next) — multipart, optional `template_image` (page 1) +
 *                                   `template_pages[]` (pages 2… of a multi-page PDF / Word template) + page_w / page_h
 * POST action=save_layout           step 5 layout editor; finish=1 marks the document finished (not a draft)
 * POST action=archive               step 5 Archive: saves the current progress (layout) as "Not finished" — not issuable,
 *                                   continued later from the Not finished list
 * POST action=convert_doc           old Word (.doc) → PDF on the server (only when LibreOffice is installed)
 * POST action=toggle_active         enable / disable a finished document type
 * POST action=delete_type           delete a document type that was never used (otherwise disable it)
 * Every POST needs the CSRF token (csrf_token field or X-CSRF-Token header).
 */
ob_start();
require_once __DIR__ . '/../../db.php';
$required_module = 'certificates';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/cert_common.php';

try { cert_migrate($pdo); }
catch (Throwable $e) { error_log('[Certificates] migrate: ' . $e->getMessage()); cert_json(['success' => false, 'message' => 'Database setup failed.'], 500); }

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function tpl_catalog_groups(PDO $pdo, ?string $docType = null): array {
    $groups = ['resident' => ['title' => 'Resident data', 'items' => []], 'system' => ['title' => 'System', 'items' => []]];
    foreach (cert_field_catalog($pdo) as $k => $f) $groups[$f['group']]['items'][] = ['key' => $k, 'label' => $f['label'], 'sample' => $f['sample']];
    if ($docType !== null) {
        $extra = ['title' => 'Extra information fields', 'items' => []];
        foreach (cert_extra_fields($pdo, $docType) as $ef) $extra['items'][] = ['key' => 'extra.' . $ef['field_key'], 'label' => $ef['label'], 'sample' => $ef['label']];
        if ($extra['items']) $groups['extra'] = $extra;
    }
    return array_values($groups);
}

function tpl_load(PDO $pdo, int $id): ?array {
    $s = $pdo->prepare("SELECT * FROM custom_document_types WHERE id = ?");
    $s->execute([$id]);
    $t = $s->fetch(PDO::FETCH_ASSOC);
    if (!$t) return null;
    $tpl = cert_template_for($pdo, $t['doc_type']);
    $positions = $tpl ? cert_positions($pdo, (int)$tpl['id']) : [];
    $paper = cert_paper($tpl['paper_size'] ?? 'a4', $tpl);
    $pages = cert_template_pages($tpl);
    return [
        'id' => (int)$t['id'], 'doc_type' => $t['doc_type'], 'doc_code' => $t['doc_code'], 'description' => $t['description'],
        'is_active' => (int)$t['is_active'], 'is_draft' => (int)$t['is_draft'], 'draft_step' => (int)$t['draft_step'],
        'template_id' => $tpl['id'] ?? null,
        'paper_size' => $paper['key'], 'paper' => $paper,
        'page_w' => isset($tpl['page_w']) ? (int)$tpl['page_w'] : null, 'page_h' => isset($tpl['page_h']) ? (int)$tpl['page_h'] : null,
        'bg_image' => cert_bg_url($tpl['background_image_path'] ?? null),
        'pages' => array_map(fn($p) => ['bg_image' => cert_bg_url($p)], $pages),
        'page_count' => max(1, count($pages)),
        'bg_opacity' => isset($tpl['bg_opacity']) ? (float)$tpl['bg_opacity'] : 1.0,
        'selected_fields' => json_decode((string)($tpl['layout_json'] ?? ''), true)['selected'] ?? array_column($positions, 'field_key'),
        'positions' => $positions,
        'legacy_custom_layout' => $tpl && !$positions && !empty($tpl['custom_layout_elements']),
        'requirements' => cert_requirements($pdo, $t['doc_type']),
        'extra_fields' => cert_extra_fields($pdo, $t['doc_type']),
        'groups' => tpl_catalog_groups($pdo, $t['doc_type']),
    ];
}

/** Validate + store an uploaded template image. Returns the path relative to the CAPS root. */
function tpl_store_image(array $file, int $typeId): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Template image upload failed.');
    if ($file['size'] > 5 * 1024 * 1024) throw new RuntimeException('Each template page must be 5 MB or smaller.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext || !@getimagesize($file['tmp_name'])) throw new RuntimeException('Template image must be a PNG, JPG or WebP picture.');
    $dir = realpath(__DIR__ . '/../../..') . '/upload/certificates/templates';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('Cannot create the upload folder.');
    $name = 'tpl_' . $typeId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('Could not save the template image.');
    return 'upload/certificates/templates/' . $name;
}

function tpl_slug(string $label): string {
    $s = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $label), '_'));
    return substr($s !== '' ? $s : 'field', 0, 50);
}

/* ───────── GET ───────── */

if ($action === 'list') {
    $rows = $pdo->query("SELECT t.*, ct.paper_size, ct.background_image_path, ct.page_images, ct.page_w, ct.page_h,
            (SELECT COUNT(*) FROM document_requirements r WHERE r.doc_type = t.doc_type) AS req_count,
            (SELECT COUNT(*) FROM document_extra_fields e WHERE e.doc_type = t.doc_type) AS extra_count,
            (SELECT COUNT(*) FROM document_requests d WHERE d.DocType = t.doc_type) AS request_count,
            (SELECT COUNT(*) FROM certificate_field_positions p WHERE p.template_id = ct.id) AS field_count
        FROM custom_document_types t
        LEFT JOIN certificate_templates ct ON ct.id = (SELECT MAX(id) FROM certificate_templates c2 WHERE c2.doc_type = t.doc_type AND c2.is_active = 1)
        ORDER BY t.is_draft DESC, t.sort_order, t.doc_type")->fetchAll(PDO::FETCH_ASSOC);
    cert_json(['success' => true, 'types' => array_map(fn($r) => [
        'id' => (int)$r['id'], 'doc_type' => $r['doc_type'], 'doc_code' => $r['doc_code'], 'description' => $r['description'],
        'is_active' => (int)$r['is_active'], 'is_draft' => (int)$r['is_draft'], 'draft_step' => (int)$r['draft_step'],
        'paper_label' => cert_paper((string)($r['paper_size'] ?? 'a4'), $r)['label'],
        'page_count' => max(1, count(cert_template_pages($r))),
        'bg_image' => cert_bg_url($r['background_image_path']),
        'req_count' => (int)$r['req_count'], 'extra_count' => (int)$r['extra_count'],
        'field_count' => (int)$r['field_count'], 'request_count' => (int)$r['request_count'],
        'updated_at' => $r['updated_at'] ? date('M j, Y', strtotime($r['updated_at'])) : '',
    ], $rows)]);
}

if ($action === 'get') {
    $t = tpl_load($pdo, (int)($_GET['id'] ?? 0));
    $t ? cert_json(['success' => true, 'type' => $t]) : cert_json(['success' => false, 'message' => 'Document type not found.'], 404);
}

if ($action === 'catalog') {
    cert_json(['success' => true, 'groups' => tpl_catalog_groups($pdo), 'paper_sizes' => cert_paper_sizes()]);
}

/* ───────── POST ───────── */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') cert_json(['success' => false, 'message' => 'Unknown action.'], 400);
cert_csrf_verify();
$actor = cert_actor_name($pdo);

if ($action === 'save_type') {
    $id = (int)($_POST['id'] ?? 0);
    cert_require($pdo, $id ? 'update' : 'create');
    $isDraft = ($_POST['draft'] ?? '1') === '1';
    $step = max(1, min(5, (int)($_POST['step'] ?? 1)));
    $name = trim(preg_replace('/\s+/', ' ', (string)($_POST['doc_type'] ?? '')));
    $code = strtoupper(trim(preg_replace('/[^A-Za-z0-9-]/', '', (string)($_POST['doc_code'] ?? ''))));
    $desc = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 500);
    $paperKey = (string)($_POST['paper_size'] ?? 'a4');
    // Page size of the uploaded template (CSS px at 96 dpi), sent with a new upload.
    $pageW = (int)($_POST['page_w'] ?? 0); $pageH = (int)($_POST['page_h'] ?? 0);
    $pageDims = ($pageW >= 200 && $pageW <= 6000 && $pageH >= 200 && $pageH <= 6000) ? [$pageW, $pageH] : null;
    // Fields are chosen in the layout editor (step 5) or by AI Auto Detect — not in the wizard.
    $requirements = json_decode((string)($_POST['requirements'] ?? '[]'), true);
    $extras = json_decode((string)($_POST['extra_fields'] ?? '[]'), true);
    if (!is_array($requirements) || !is_array($extras)) cert_json(['success' => false, 'message' => 'Invalid form data.'], 422);

    if ($name === '' || mb_strlen($name) > 100) cert_json(['success' => false, 'message' => 'Document name is required (max 100 characters).'], 422);
    $dup = $pdo->prepare("SELECT id FROM custom_document_types WHERE doc_type = ? AND id <> ?");
    $dup->execute([$name, $id]);
    if ($dup->fetchColumn()) cert_json(['success' => false, 'message' => 'A document with this name already exists.'], 422);
    if ($code !== '') {
        if (strlen($code) > 20) cert_json(['success' => false, 'message' => 'Document code is too long (max 20).'], 422);
        $dup = $pdo->prepare("SELECT id FROM custom_document_types WHERE doc_code = ? AND id <> ?");
        $dup->execute([$code, $id]);
        if ($dup->fetchColumn()) cert_json(['success' => false, 'message' => 'Document code "' . $code . '" is already used.'], 422);
    }

    // Allowed field keys: current catalog + this form's extra fields.
    $catalog = cert_field_catalog($pdo);
    $cleanExtras = []; $seen = [];
    foreach ($extras as $i => $e) {
        $label = mb_substr(trim((string)($e['label'] ?? '')), 0, 150);
        if ($label === '') continue;
        $key = tpl_slug((string)($e['field_key'] ?? '') ?: $label);
        $base = $key; $n = 2;
        while (isset($seen[$key]) || isset($catalog[$key])) $key = $base . '_' . $n++;
        $seen[$key] = true;
        $type = in_array($e['input_type'] ?? '', ['text', 'number', 'date', 'textarea', 'select'], true) ? $e['input_type'] : 'text';
        $opts = $type === 'select' ? array_values(array_filter(array_map(fn($o) => mb_substr(trim((string)$o), 0, 100), (array)($e['options'] ?? [])), 'strlen')) : [];
        if ($type === 'select' && !$opts) cert_json(['success' => false, 'message' => '"' . $label . '" is a dropdown — add at least one choice.'], 422);
        $cleanExtras[] = ['field_key' => $key, 'label' => $label, 'input_type' => $type, 'options' => $opts, 'is_required' => !empty($e['is_required']) ? 1 : 0, 'sort_order' => $i];
    }
    $allowed = array_merge(array_keys($catalog), array_map(fn($e) => 'extra.' . $e['field_key'], $cleanExtras));
    $requirements = array_values(array_unique(array_filter(array_map(fn($r) => mb_substr(trim((string)$r), 0, 255), $requirements), 'strlen')));

    $pdo->beginTransaction();
    try {
        $oldName = null;
        if ($id) {
            $s = $pdo->prepare("SELECT doc_type FROM custom_document_types WHERE id = ? FOR UPDATE");
            $s->execute([$id]);
            $oldName = $s->fetchColumn();
            if ($oldName === false) throw new RuntimeException('Document type not found.');
        }
        if ($code === '') $code = cert_auto_doc_code($pdo, $name, $id ?: null);

        if ($id) {
            // is_draft only goes back to 1 if it was never finished (a finished document stays issuable).
            $pdo->prepare("UPDATE custom_document_types SET doc_type = ?, doc_code = ?, description = ?,
                           draft_step = IF(is_draft = 1, ?, draft_step) WHERE id = ?")
                ->execute([$name, $code, $desc, $step, $id]);
            if ($oldName !== $name) {
                foreach (['certificate_templates', 'document_requirements', 'document_extra_fields'] as $tbl) {
                    $pdo->prepare("UPDATE `$tbl` SET doc_type = ? WHERE doc_type = ?")->execute([$name, $oldName]);
                }
                $pdo->prepare("UPDATE certificate_templates SET template_name = ? WHERE doc_type = ?")->execute([$name, $name]);
                // Requests not generated yet follow the new name; issued ones keep their snapshot.
                $pdo->prepare("UPDATE document_requests SET DocType = ? WHERE DocType = ? AND Status IN ('Pending','Review')")->execute([$name, $oldName]);
            }
        } else {
            $pdo->prepare("INSERT INTO custom_document_types (doc_type, doc_code, description, is_active, is_draft, draft_step, sort_order)
                           VALUES (?,?,?,1,1,?, (SELECT COALESCE(MAX(sort_order),0)+1 FROM custom_document_types t2))")
                ->execute([$name, $code, $desc, $step]);
            $id = (int)$pdo->lastInsertId();
        }

        // Template row (Prefilled template only).
        $tpl = cert_template_for($pdo, $name);
        if (!$tpl) {
            $pdo->prepare("INSERT INTO certificate_templates (doc_type, template_name, paper_size, is_active) VALUES (?,?,'a4',1)
                           ON DUPLICATE KEY UPDATE doc_type = VALUES(doc_type), is_active = 1")
                ->execute([$name, $name]);
            $tpl = cert_template_for($pdo, $name);
        }
        $tplId = (int)$tpl['id'];
        $imgPath = null; $pages = null;
        if (!empty($_FILES['template_image']) && ($_FILES['template_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            // Page 1, then every other page of a multi-page PDF / Word template (same number of pages, max CERT_MAX_PAGES).
            $imgPath = tpl_store_image($_FILES['template_image'], $id);
            $pages = [$imgPath];
            $more = $_FILES['template_pages'] ?? null;
            if ($more && is_array($more['name'] ?? null)) {
                foreach (array_keys($more['name']) as $i) {
                    if (count($pages) >= CERT_MAX_PAGES) break;
                    if (($more['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                    $pages[] = tpl_store_image(['name' => $more['name'][$i], 'type' => $more['type'][$i], 'tmp_name' => $more['tmp_name'][$i],
                                                'error' => $more['error'][$i], 'size' => $more['size'][$i]], $id);
                }
            }
            if (!$pageDims) {   // image upload without a size: keep its proportion at letter width
                $sz = @getimagesize(realpath(__DIR__ . '/../../..') . '/' . $imgPath);
                if ($sz && $sz[0] > 0) $pageDims = [816, (int)round(816 * $sz[1] / $sz[0])];
            }
        }
        $dims = $pageDims ?: (($tpl['page_w'] ?? null) ? [(int)$tpl['page_w'], (int)$tpl['page_h']] : null);
        $paper = ($paperKey === 'original' && $dims) ? 'original' : cert_paper($paperKey)['key'];
        $sets = ['paper_size = ?']; $args = [$paper];
        if ($imgPath) { $sets[] = 'background_image_path = ?'; $args[] = $imgPath; $sets[] = 'page_images = ?'; $args[] = json_encode($pages); }
        if ($pageDims) { $sets[] = 'page_w = ?'; $args[] = $pageDims[0]; $sets[] = 'page_h = ?'; $args[] = $pageDims[1]; }
        $args[] = $tplId;
        $pdo->prepare("UPDATE certificate_templates SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);

        // Requirements (replace).
        $pdo->prepare("DELETE FROM document_requirements WHERE doc_type = ?")->execute([$name]);
        $ins = $pdo->prepare("INSERT INTO document_requirements (doc_type, requirement, sort_order) VALUES (?,?,?)");
        foreach ($requirements as $i => $r) $ins->execute([$name, $r, $i]);

        // Extra information fields (replace; keys stay stable so saved values still match).
        $pdo->prepare("DELETE FROM document_extra_fields WHERE doc_type = ?")->execute([$name]);
        $ins = $pdo->prepare("INSERT INTO document_extra_fields (doc_type, field_key, label, input_type, options, is_required, sort_order) VALUES (?,?,?,?,?,?,?)");
        foreach ($cleanExtras as $e) {
            $ins->execute([$name, $e['field_key'], $e['label'], $e['input_type'], $e['options'] ? json_encode($e['options'], JSON_UNESCAPED_UNICODE) : null, $e['is_required'], $e['sort_order']]);
        }

        // The layout is kept as placed in step 5; only fields that no longer exist (removed extra fields) leave it.
        // A replacement template with fewer pages: fields on pages that no longer exist go to its last page.
        $labels = cert_field_labels($pdo, $name);
        $pageCount = max(1, count($pages ?? cert_template_pages(cert_template_for($pdo, $name))));
        $keep = [];
        foreach (cert_positions($pdo, $tplId) as $p) {
            if (!in_array($p['field_key'], $allowed, true)) continue;
            if ($p['page_no'] > $pageCount) $p['page_no'] = $pageCount;
            $keep[] = $p;
        }
        tpl_write_positions($pdo, $tplId, $keep, $labels);
        $pdo->prepare("UPDATE certificate_templates SET layout_json = ? WHERE id = ?")
            ->execute([json_encode(['selected' => array_values(array_unique(array_column($keep, 'field_key')))]), $tplId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = $e instanceof RuntimeException ? $e->getMessage() : 'Could not save the document type.';
        error_log('[Certificates] save_type: ' . $e->getMessage());
        cert_json(['success' => false, 'message' => $msg], 422);
    }
    cert_log_activity($isDraft ? 'Save Draft Document Type' : 'Save Document Type', ($isDraft ? 'Saved draft of ' : 'Saved ') . $name . ' (step ' . $step . ')');
    cert_json(['success' => true, 'message' => $isDraft ? 'Draft saved. It stays "Not finished" until the layout is saved.' : 'Saved.', 'type' => tpl_load($pdo, $id)]);
}

function tpl_write_positions(PDO $pdo, int $tplId, array $positions, array $labels): void {
    $pdo->prepare("DELETE FROM certificate_field_positions WHERE template_id = ?")->execute([$tplId]);
    $ins = $pdo->prepare("INSERT INTO certificate_field_positions (template_id, field_key, field_label, pos_x, pos_y, width, font_size, font_weight, text_align, text_color, uppercase, page_no, is_visible)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)");
    $seen = [];
    foreach ($positions as $p) {
        $p = cert_clean_position($p);
        if ($p['field_key'] === '' || isset($seen[$p['field_key']])) continue;
        $seen[$p['field_key']] = true;
        $ins->execute([$tplId, $p['field_key'], $labels[$p['field_key']] ?? ($p['field_label'] ?: $p['field_key']), $p['pos_x'], $p['pos_y'], $p['width'],
                       $p['font_size'], $p['font_weight'], $p['text_align'], $p['text_color'], $p['uppercase'], $p['page_no']]);
    }
}

if ($action === 'save_layout') {
    cert_require($pdo, 'update');
    $id = (int)($_POST['id'] ?? 0);
    $t = tpl_load($pdo, $id);
    if (!$t || !$t['template_id']) cert_json(['success' => false, 'message' => 'Save the document details first.'], 404);
    $positions = json_decode((string)($_POST['positions'] ?? ''), true);
    if (!is_array($positions)) cert_json(['success' => false, 'message' => 'Invalid layout.'], 422);
    $labels = cert_field_labels($pdo, $t['doc_type']);
    $positions = array_values(array_filter($positions, fn($p) => is_array($p) && isset($labels[$p['field_key'] ?? ''])));
    foreach ($positions as &$pp) $pp['page_no'] = min(max(1, (int)($pp['page_no'] ?? 1)), $t['page_count']);
    unset($pp);
    $finish = ($_POST['finish'] ?? '0') === '1';
    if ($finish && !$t['bg_image']) cert_json(['success' => false, 'message' => 'Upload the template image (step 2) before finishing.'], 422);
    if ($finish && !$positions) cert_json(['success' => false, 'message' => 'Place at least one field on the template before finishing.'], 422);

    $pdo->beginTransaction();
    try {
        tpl_write_positions($pdo, (int)$t['template_id'], $positions, $labels);
        $pdo->prepare("UPDATE certificate_templates SET layout_json = ? WHERE id = ?")
            ->execute([json_encode(['selected' => array_values(array_unique(array_column($positions, 'field_key')))]), $t['template_id']]);
        if ($finish) $pdo->prepare("UPDATE custom_document_types SET is_draft = 0, draft_step = 5 WHERE id = ?")->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[Certificates] save_layout: ' . $e->getMessage());
        cert_json(['success' => false, 'message' => 'Could not save the layout.'], 500);
    }
    cert_log_activity('Save Template Layout', 'Saved layout of ' . $t['doc_type'] . ' (' . count($positions) . ' fields)' . ($finish ? ' — finished' : ''));
    cert_json(['success' => true, 'message' => $finish && $t['is_draft'] ? 'Layout saved. "' . $t['doc_type'] . '" is finished and can now be issued.' : 'Layout saved.', 'type' => tpl_load($pdo, $id)]);
}

if ($action === 'archive') {
    // Save the current progress and set the document type to "Not finished" (hidden from the document choices).
    cert_require($pdo, 'update');
    $id = (int)($_POST['id'] ?? 0);
    $t = tpl_load($pdo, $id);
    if (!$t) cert_json(['success' => false, 'message' => 'Document type not found.'], 404);
    $pdo->beginTransaction();
    try {
        $positions = json_decode((string)($_POST['positions'] ?? ''), true);
        if (is_array($positions) && $t['template_id']) {
            $labels = cert_field_labels($pdo, $t['doc_type']);
            $positions = array_values(array_filter($positions, fn($p) => is_array($p) && isset($labels[$p['field_key'] ?? ''])));
            foreach ($positions as &$pp) $pp['page_no'] = min(max(1, (int)($pp['page_no'] ?? 1)), $t['page_count']);
            unset($pp);
            tpl_write_positions($pdo, (int)$t['template_id'], $positions, $labels);
            $pdo->prepare("UPDATE certificate_templates SET layout_json = ? WHERE id = ?")
                ->execute([json_encode(['selected' => array_values(array_unique(array_column($positions, 'field_key')))]), $t['template_id']]);
        }
        $pdo->prepare("UPDATE custom_document_types SET is_draft = 1, draft_step = 5 WHERE id = ?")->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[Certificates] archive: ' . $e->getMessage());
        cert_json(['success' => false, 'message' => 'Could not save the progress.'], 500);
    }
    cert_log_activity('Save Progress (Not Finished)', 'Saved progress of ' . $t['doc_type'] . ' as Not finished');
    cert_json(['success' => true, 'message' => 'Progress saved. "' . $t['doc_type'] . '" is Not finished — continue it later from the Not finished list.', 'type' => tpl_load($pdo, $id)]);
}

if ($action === 'convert_doc') {
    // Browsers cannot read old Word (.doc) files: convert on the server when LibreOffice is available.
    cert_require($pdo, 'create');
    $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) cert_json(['success' => false, 'message' => 'Upload failed.'], 422);
    if ($f['size'] > 10 * 1024 * 1024) cert_json(['success' => false, 'message' => 'The file must be 10 MB or smaller.'], 422);
    if (!preg_match('/\.doc$/i', (string)$f['name'])) cert_json(['success' => false, 'message' => 'Only .doc files are converted here.'], 422);
    $bins = array_filter(['soffice', 'libreoffice', 'C:\\Program Files\\LibreOffice\\program\\soffice.exe', 'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
                          '/usr/bin/soffice', '/usr/bin/libreoffice', '/Applications/LibreOffice.app/Contents/MacOS/soffice']);
    $bin = null;
    foreach ($bins as $b) {
        if (strpbrk($b, '/\\') !== false) { if (is_file($b)) { $bin = $b; break; } continue; }
        if (!function_exists('shell_exec')) continue;
        $which = @shell_exec((stripos(PHP_OS, 'WIN') === 0 ? 'where ' : 'command -v ') . $b . ' 2>' . (stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null'));
        if ($which && trim($which) !== '') { $bin = $b; break; }
    }
    if (!$bin || !function_exists('shell_exec')) cert_json(['success' => false, 'message' => 'Old Word (.doc) files cannot be converted on this server. Open the file in Word and save it as DOCX or PDF, then upload it again.'], 422);
    $tmp = sys_get_temp_dir() . '/certdoc_' . bin2hex(random_bytes(6));
    @mkdir($tmp, 0700, true);
    $src = $tmp . '/template.doc';
    move_uploaded_file($f['tmp_name'], $src);
    @shell_exec(escapeshellarg($bin) . ' --headless --convert-to pdf --outdir ' . escapeshellarg($tmp) . ' ' . escapeshellarg($src) . ' 2>&1');
    $pdf = $tmp . '/template.pdf';
    if (!is_file($pdf)) { @unlink($src); @rmdir($tmp); cert_json(['success' => false, 'message' => 'The .doc file could not be converted. Save it as DOCX or PDF and upload it again.'], 422); }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($pdf));
    readfile($pdf);
    @unlink($pdf); @unlink($src); @rmdir($tmp);
    exit;
}

if ($action === 'toggle_active') {
    cert_require($pdo, 'update');
    $id = (int)($_POST['id'] ?? 0);
    $on = ($_POST['active'] ?? '0') === '1' ? 1 : 0;
    $pdo->prepare("UPDATE custom_document_types SET is_active = ? WHERE id = ?")->execute([$on, $id]);
    cert_log_activity($on ? 'Enable Document Type' : 'Disable Document Type', 'Document type #' . $id);
    cert_json(['success' => true, 'message' => $on ? 'Document type enabled.' : 'Document type disabled — it will not appear in Issue Walk-In.']);
}

if ($action === 'delete_type') {
    cert_require($pdo, 'delete');
    $id = (int)($_POST['id'] ?? 0);
    $t = tpl_load($pdo, $id);
    if (!$t) cert_json(['success' => false, 'message' => 'Document type not found.'], 404);
    $used = $pdo->prepare("SELECT COUNT(*) FROM document_requests WHERE DocType = ?");
    $used->execute([$t['doc_type']]);
    if ((int)$used->fetchColumn() > 0) {
        $pdo->prepare("UPDATE custom_document_types SET is_active = 0 WHERE id = ?")->execute([$id]);
        cert_json(['success' => true, 'message' => 'This document was already issued, so it was disabled instead of deleted (records are kept).']);
    }
    $pdo->beginTransaction();
    foreach (['document_requirements', 'document_extra_fields'] as $tbl) $pdo->prepare("DELETE FROM `$tbl` WHERE doc_type = ?")->execute([$t['doc_type']]);
    if ($t['template_id']) {
        $pdo->prepare("DELETE FROM certificate_field_positions WHERE template_id = ?")->execute([$t['template_id']]);
        $pdo->prepare("DELETE FROM certificate_templates WHERE doc_type = ?")->execute([$t['doc_type']]);
    }
    $pdo->prepare("DELETE FROM custom_document_types WHERE id = ?")->execute([$id]);
    $pdo->commit();
    cert_log_activity('Delete Document Type', 'Deleted ' . $t['doc_type']);
    cert_json(['success' => true, 'message' => 'Document type deleted.']);
}

cert_json(['success' => false, 'message' => 'Unknown action.'], 400);

<?php
/**
 * template_actions.php — JSON endpoint for the Template Builder (document_templates.php).
 *
 * GET  ?action=list                 all document types (drafts included) for the builder list
 * GET  ?action=get&id=N             one document type with template, requirements, extra fields, layout
 * GET  ?action=catalog              dynamic field list (from the residents table + system values)
 * POST action=save_type             wizard steps 1–4 (Save Draft / Next) — multipart, optional `template_image`
 * POST action=save_layout           step 5 layout editor; finish=1 marks the document finished (not a draft)
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
    $paper = cert_paper($tpl['paper_size'] ?? 'a4');
    return [
        'id' => (int)$t['id'], 'doc_type' => $t['doc_type'], 'doc_code' => $t['doc_code'], 'description' => $t['description'],
        'is_active' => (int)$t['is_active'], 'is_draft' => (int)$t['is_draft'], 'draft_step' => (int)$t['draft_step'],
        'template_id' => $tpl['id'] ?? null,
        'paper_size' => $paper['key'], 'paper' => $paper,
        'bg_image' => cert_bg_url($tpl['background_image_path'] ?? null),
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
    if ($file['size'] > 5 * 1024 * 1024) throw new RuntimeException('Template image must be 5 MB or smaller.');
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
    $rows = $pdo->query("SELECT t.*, ct.paper_size, ct.background_image_path,
            (SELECT COUNT(*) FROM document_requirements r WHERE r.doc_type = t.doc_type) AS req_count,
            (SELECT COUNT(*) FROM document_extra_fields e WHERE e.doc_type = t.doc_type) AS extra_count,
            (SELECT COUNT(*) FROM document_requests d WHERE d.DocType = t.doc_type) AS request_count,
            (SELECT COUNT(*) FROM certificate_field_positions p WHERE p.template_id = ct.id) AS field_count
        FROM custom_document_types t
        LEFT JOIN certificate_templates ct ON ct.id = (SELECT MAX(id) FROM certificate_templates c2 WHERE c2.doc_type = t.doc_type AND c2.is_active = 1)
        ORDER BY t.is_draft DESC, t.sort_order, t.doc_type")->fetchAll(PDO::FETCH_ASSOC);
    $sizes = cert_paper_sizes();
    cert_json(['success' => true, 'types' => array_map(fn($r) => [
        'id' => (int)$r['id'], 'doc_type' => $r['doc_type'], 'doc_code' => $r['doc_code'], 'description' => $r['description'],
        'is_active' => (int)$r['is_active'], 'is_draft' => (int)$r['is_draft'], 'draft_step' => (int)$r['draft_step'],
        'paper_label' => $sizes[$r['paper_size'] ?? 'a4']['label'] ?? 'A4',
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
    $paper = cert_paper((string)($_POST['paper_size'] ?? 'a4'))['key'];
    $selected = json_decode((string)($_POST['fields'] ?? '[]'), true);
    $requirements = json_decode((string)($_POST['requirements'] ?? '[]'), true);
    $extras = json_decode((string)($_POST['extra_fields'] ?? '[]'), true);
    if (!is_array($selected) || !is_array($requirements) || !is_array($extras)) cert_json(['success' => false, 'message' => 'Invalid form data.'], 422);

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
    $selected = array_values(array_unique(array_filter(array_map('strval', $selected), fn($k) => in_array($k, $allowed, true))));
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
            $pdo->prepare("INSERT INTO certificate_templates (doc_type, template_name, paper_size, is_active) VALUES (?,?,?,1)
                           ON DUPLICATE KEY UPDATE doc_type = VALUES(doc_type), paper_size = VALUES(paper_size), is_active = 1")
                ->execute([$name, $name, $paper]);
            $tpl = cert_template_for($pdo, $name);
        }
        $tplId = (int)$tpl['id'];
        $imgPath = null;
        if (!empty($_FILES['template_image']) && ($_FILES['template_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $imgPath = tpl_store_image($_FILES['template_image'], $id);
        }
        $pdo->prepare("UPDATE certificate_templates SET paper_size = ?, layout_json = ?" . ($imgPath ? ", background_image_path = ?" : "") . " WHERE id = ?")
            ->execute(array_merge([$paper, json_encode(['selected' => $selected])], $imgPath ? [$imgPath] : [], [$tplId]));

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

        // Layout follows the checkboxes: unchecked fields leave the page, newly checked ones get a starting spot.
        $existing = cert_positions($pdo, $tplId);
        $keep = array_filter($existing, fn($p) => in_array($p['field_key'], $selected, true));
        $have = array_column($keep, 'field_key');
        $labels = cert_field_labels($pdo, $name);
        $n = count($keep);
        foreach ($selected as $k) {
            if (in_array($k, $have, true)) continue;
            $keep[] = cert_clean_position(['field_key' => $k, 'field_label' => $labels[$k] ?? $k, 'pos_x' => 50, 'pos_y' => min(92, 25 + ($n++ % 14) * 5), 'font_size' => 16]);
        }
        tpl_write_positions($pdo, $tplId, array_values($keep), $labels);

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
    $ins = $pdo->prepare("INSERT INTO certificate_field_positions (template_id, field_key, field_label, pos_x, pos_y, width, font_size, font_weight, text_align, text_color, uppercase, is_visible)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,1)");
    $seen = [];
    foreach ($positions as $p) {
        $p = cert_clean_position($p);
        if ($p['field_key'] === '' || isset($seen[$p['field_key']])) continue;
        $seen[$p['field_key']] = true;
        $ins->execute([$tplId, $p['field_key'], $labels[$p['field_key']] ?? ($p['field_label'] ?: $p['field_key']), $p['pos_x'], $p['pos_y'], $p['width'],
                       $p['font_size'], $p['font_weight'], $p['text_align'], $p['text_color'], $p['uppercase']]);
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

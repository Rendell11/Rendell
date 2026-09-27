<?php
/**
 * user/backend/complaint.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Resident Complaints — JSON API for the Flutter app (lib/complaint/).
 * Ported from the SOE resident page user/incidents.php (Complaints tab) and
 * aligned with the CAPS admin module admin/complaint/ so both sides read and
 * write the SAME `complaints` rows:
 *   • complaint_id format  CMP-YYYYMMDD-####   (same as admin complaint_rep.php)
 *   • categories           same list as the admin "Add New Complaint" form
 *   • priority_level       Low | Medium | High (Urgent)
 *   • status               Pending | Ongoing | Resolved (set by admin)
 *   • admin_reply          admin's response, shown read-only to the resident
 *   • notif_read           0 = resident has an unseen update (SOE semantics)
 *
 * Actions (GET ?action= or POST action=):
 *   • categories                                   → category list
 *   • list      (resident_id, status?, q?)         → complaints + stats + defaults
 *   • detail    (resident_id, id)                  → one complaint (marks it read)
 *   • submit    (resident_id, category, title, description, address_location,
 *                priority_level, is_anonymous?, other_category_specify?,
 *                attachment? [multipart])           → files a new complaint
 *
 * Envelope: { success, message, data } via respond() in config.php.
 * Scoped to the resident_id supplied by the app (same trust model as chat.php),
 * and every read/write is limited to that resident's own complaints.
 * ─────────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/config.php'; // respond(), handle_preflight(), db(), post()
handle_preflight();

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

const COMPLAINT_CATEGORIES = [
    'Noise Complaint',
    'Garbage/Sanitation',
    'Property Dispute',
    'Harassment',
    'Domestic Issue',
    'Road/Infrastructure',
    'Public Safety',
    'Other',
];
const COMPLAINT_PRIORITIES  = ['Low', 'Medium', 'High (Urgent)'];
const COMPLAINT_STATUSES    = ['Pending', 'Ongoing', 'Resolved'];
const COMPLAINT_MAX_BYTES   = 5 * 1024 * 1024; // 5 MB, same as admin
const COMPLAINT_UPLOAD_SUB  = 'complaints';

// ── Self-heal: make sure the columns this API uses exist (older DBs may lack
//    them; SOE notif_api.php added notif_read the same way). No-op once present.
try {
    $have = $pdo->query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints'"
    )->fetchAll(PDO::FETCH_COLUMN);
    $needed = [
        'notif_read'             => "TINYINT(1) NOT NULL DEFAULT 0",
        'other_category_specify' => "VARCHAR(255) NULL AFTER category",
        'is_anonymous'           => "TINYINT(1) NULL",
        'purok'                  => "VARCHAR(50) NULL",
    ];
    foreach ($needed as $col => $def) {
        if (!in_array($col, $have, true)) {
            $pdo->exec("ALTER TABLE complaints ADD COLUMN `$col` $def");
        }
    }
} catch (Throwable $e) {
    error_log('[complaint.php migrate] ' . $e->getMessage());
}

// ── Read action + resident ───────────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$rid    = (int) ($_GET['resident_id'] ?? $_POST['resident_id'] ?? 0);

if ($action === 'categories') {
    respond(true, '', [
        'categories' => COMPLAINT_CATEGORIES,
        'priorities' => COMPLAINT_PRIORITIES,
    ]);
}

if ($rid <= 0) {
    respond(false, 'Kailangan ang resident_id.', null, 400);
}

/** The resident row (must be an Active portal account). */
function complaint_resident(PDO $pdo, int $rid): ?array
{
    $s = $pdo->prepare(
        "SELECT ResidentID, FirstName, MiddleName, LastName,
                HouseNumber, StreetName, Purok, access_status
         FROM residents WHERE ResidentID = ? LIMIT 1"
    );
    $s->execute([$rid]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r || $r['access_status'] !== 'Active') {
        return null;
    }
    return $r;
}

/**
 * Attachment path → URL relative to this backend folder, so the app can build
 * "$baseUrl/<attachment_url>". Resident uploads live in user/backend/uploads/;
 * admin uploads are stored relative to admin/complaint/backend/.
 */
function complaint_attachment_url(?string $path): ?string
{
    if ($path === null || $path === '') return null;
    $prefix = UPLOAD_URL . '/';
    if (strpos($path, $prefix) === 0) {
        return 'uploads/' . substr($path, strlen($prefix));
    }
    if (strpos($path, 'uploads/complaints/') === 0) {
        return '../../admin/complaint/backend/' . $path;
    }
    return null;
}

/** Shape a DB row for the app (hide internals, add derived fields). */
function complaint_row(array $c): array
{
    $status = in_array($c['status'] ?? '', COMPLAINT_STATUSES, true) ? $c['status'] : 'Pending';
    return [
        'id'                     => (int) $c['id'],
        'complaint_id'           => $c['complaint_id'] ?: ('#' . $c['id']),
        'title'                  => (string) ($c['title'] ?? ''),
        'category'               => (string) ($c['category'] ?? ''),
        'other_category_specify' => $c['other_category_specify'] ?? null,
        'description'            => (string) ($c['description'] ?? ''),
        'address_location'       => (string) ($c['address_location'] ?? ''),
        'purok'                  => $c['purok'] ?? null,
        'priority_level'         => $c['priority_level'] ?: 'Medium',
        'is_anonymous'           => (int) ($c['is_anonymous'] ?? 0) === 1,
        'status'                 => $status,
        'admin_reply'            => trim((string) ($c['admin_reply'] ?? '')),
        'attachment_url'         => complaint_attachment_url($c['attachment_path'] ?? null),
        'is_unread'              => (int) ($c['notif_read'] ?? 1) === 0,
        'created_at'             => $c['created_at'] ?? null,
    ];
}

/** Save the optional attachment (image or PDF). Returns [path|null, error|null]. */
function complaint_save_attachment(): array
{
    if (empty($_FILES['attachment']) || ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    $f = $_FILES['attachment'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return [null, 'Hindi na-upload ang file. Subukan muli.'];
    }
    if ($f['size'] > COMPLAINT_MAX_BYTES) {
        return [null, 'Masyadong malaki ang file. Hanggang 5 MB lang.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext  = [
        'image/jpeg'      => 'jpg',
        'image/pjpeg'     => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'image/gif'       => 'gif',
        'image/heic'      => 'heic',
        'image/heif'      => 'heif',
        'application/pdf' => 'pdf',
    ][$mime] ?? null;
    if ($ext === null) {
        return [null, 'Larawan (JPG/PNG/WEBP) o PDF lang ang puwedeng i-attach.'];
    }
    $dir = UPLOAD_DIR . '/' . COMPLAINT_UPLOAD_SUB;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $name = 'cmp_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        return [null, 'Hindi na-save ang file. Subukan muli.'];
    }
    return [UPLOAD_URL . '/' . COMPLAINT_UPLOAD_SUB . '/' . $name, null];
}

/** New unique CMP-YYYYMMDD-#### (same format as the admin form). */
function complaint_new_code(PDO $pdo): string
{
    $check = $pdo->prepare('SELECT COUNT(*) FROM complaints WHERE complaint_id = ?');
    for ($i = 0; $i < 10; $i++) {
        $code = 'CMP-' . date('Ymd') . '-' . random_int(1000, 9999);
        $check->execute([$code]);
        if ((int) $check->fetchColumn() === 0) {
            return $code;
        }
    }
    return 'CMP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

$resident = complaint_resident($pdo, $rid);
if ($resident === null) {
    respond(false, 'Hindi aktibo o hindi nahanap ang resident account.', null, 403);
}

// ── LIST ─────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    try {
        $stmt = $pdo->prepare(
            "SELECT id, complaint_id, title, category, other_category_specify, description,
                    address_location, purok, priority_level, is_anonymous, status,
                    admin_reply, attachment_path, notif_read, created_at
             FROM complaints WHERE resident_id = ?
             ORDER BY created_at DESC, id DESC"
        );
        $stmt->execute([$rid]);
        $rows = array_map('complaint_row', $stmt->fetchAll(PDO::FETCH_ASSOC));

        $stats = ['total' => count($rows), 'pending' => 0, 'ongoing' => 0, 'resolved' => 0, 'unread' => 0];
        foreach ($rows as $r) {
            $stats[strtolower($r['status'])]++;
            if ($r['is_unread']) $stats['unread']++;
        }

        $address = trim(implode(' ', array_filter([
            $resident['HouseNumber'], $resident['StreetName'],
        ])) . ($resident['Purok'] ? ', ' . $resident['Purok'] : ''), ' ,');

        respond(true, '', [
            'complaints' => $rows,
            'stats'      => $stats,
            'defaults'   => [
                'address_location' => $address,
                'purok'            => $resident['Purok'],
            ],
        ]);
    } catch (Throwable $e) {
        error_log('[complaint.php list] ' . $e->getMessage());
        respond(false, 'Hindi ma-load ang mga reklamo.', null, 500);
    }
}

// ── DETAIL (also marks the update as seen) ───────────────────────────────────
if ($action === 'detail') {
    $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
    try {
        $stmt = $pdo->prepare(
            "SELECT id, complaint_id, title, category, other_category_specify, description,
                    address_location, purok, priority_level, is_anonymous, status,
                    admin_reply, attachment_path, notif_read, created_at
             FROM complaints WHERE id = ? AND resident_id = ? LIMIT 1"
        );
        $stmt->execute([$id, $rid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            respond(false, 'Hindi nahanap ang reklamo.', null, 404);
        }
        if ((int) ($row['notif_read'] ?? 1) === 0) {
            $pdo->prepare('UPDATE complaints SET notif_read = 1 WHERE id = ? AND resident_id = ?')
                ->execute([$id, $rid]);
            $row['notif_read'] = 1;
        }
        respond(true, '', complaint_row($row));
    } catch (Throwable $e) {
        error_log('[complaint.php detail] ' . $e->getMessage());
        respond(false, 'Hindi ma-load ang reklamo.', null, 500);
    }
}

// ── SUBMIT ───────────────────────────────────────────────────────────────────
if ($action === 'submit') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond(false, 'POST lang ang tinatanggap.', null, 405);
    }

    $category    = post('category', '');
    $other       = post('other_category_specify', '');
    $title       = post('title', '');
    $description = post('description', '');
    $location    = post('address_location', '');
    $priority    = post('priority_level', 'Medium');
    $anonymous   = post('is_anonymous', '0') === '1' ? 1 : 0;

    $errors = [];
    if (!in_array($category, COMPLAINT_CATEGORIES, true)) $errors[] = 'Pumili ng kategorya.';
    if ($category === 'Other' && $other === '')          $errors[] = 'Ilagay kung anong uri ng reklamo.';
    if ($title === '')                                    $errors[] = 'Ilagay ang pamagat.';
    if ($description === '')                              $errors[] = 'Ilarawan ang reklamo.';
    if ($location === '')                                 $errors[] = 'Ilagay ang lugar ng insidente.';
    if (!in_array($priority, COMPLAINT_PRIORITIES, true)) $errors[] = 'Pumili ng priority.';
    if (mb_strlen($title) > 255 || mb_strlen($other) > 255) $errors[] = 'Masyadong mahaba ang pamagat.';
    if (mb_strlen($description) > 2000)                   $errors[] = 'Hanggang 2000 karakter lang ang paglalarawan.';
    if ($errors) {
        respond(false, implode(' ', $errors), null, 422);
    }

    [$attachment, $uploadError] = complaint_save_attachment();
    if ($uploadError !== null) {
        respond(false, $uploadError, null, 422);
    }

    // Same convention as admin process_complaint.php: an "Other" complaint is
    // stored under the resident's own label so it shows up in the admin chart.
    $storedCategory = $category === 'Other' ? $other : $category;
    $fullName = trim(preg_replace('/\s+/', ' ',
        $resident['FirstName'] . ' ' . ($resident['MiddleName'] ?? '') . ' ' . $resident['LastName']));

    try {
        $code = complaint_new_code($pdo);
        $pdo->prepare(
            "INSERT INTO complaints
                (complaint_id, resident_id, purok, is_anonymous, complainant_name,
                 category, other_category_specify, address_location, priority_level,
                 title, description, attachment_path, status, admin_reply, notif_read, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', '', 1, NOW())"
        )->execute([
            $code,
            $rid,
            $resident['Purok'] ?: null,
            $anonymous,
            $anonymous ? null : $fullName,
            $storedCategory,
            $category === 'Other' ? $other : null,
            $location,
            $priority,
            $title,
            $description,
            $attachment,
        ]);
        $id = (int) $pdo->lastInsertId();

        // Audit trail in the admin Activity Logs (best-effort; table may differ).
        try {
            $pdo->prepare(
                "INSERT INTO activity_logs
                    (user_id, full_name, role, module, action, description, ip_address, device_info)
                 VALUES (?, ?, 'Resident', 'Complaints', 'File Complaint', ?, ?, ?)"
            )->execute([
                $anonymous ? null : (string) $rid,
                $anonymous ? 'Anonymous Resident' : $fullName,
                "Resident filed complaint {$code} via the app. Category: {$storedCategory}. Priority: {$priority}."
                    . ($anonymous ? ' (Anonymous)' : ''),
                substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Resident App'), 0, 255),
            ]);
        } catch (Throwable $e) { /* activity_logs schema differs — ignore */ }

        respond(true, 'Naisumite ang reklamo. Susuriin ito ng barangay.', [
            'id'           => $id,
            'complaint_id' => $code,
        ]);
    } catch (Throwable $e) {
        error_log('[complaint.php submit] ' . $e->getMessage());
        respond(false, 'Hindi naisumite ang reklamo.', null, 500);
    }
}

respond(false, 'Hindi wastong action.', null, 400);

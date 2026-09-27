<?php
// ─── process_complaint.php ────────────────────────────────────────────────────
// Handles POST from the Add New Complaint form in complaint_rep.php
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../permission_helper.php';
require_once __DIR__ . '/../../activity_log_helper.php';
require_permission($pdo, 'complaints', 'create');
if (session_status() === PHP_SESSION_NONE) { @session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

// ── Only accept POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method Not Allowed');
}

// ── CSRF check ────────────────────────────────────────────────────────────────
if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
    $_SESSION['toast_msg']   = 'Invalid request. Please try again.';
    $_SESSION['toast_color'] = 'error';
    header('Location: ../frontend/complaint_rep.php');
    exit;
}

// ── Sanitize & collect inputs ─────────────────────────────────────────────────
$complaint_id       = trim($_POST['complaint_id']         ?? '');
$resident_id        = !empty($_POST['resident_id'])        ? (int)$_POST['resident_id'] : null;
$purok              = trim($_POST['purok']                 ?? '');
$is_anonymous       = isset($_POST['is_anonymous']) && $_POST['is_anonymous'] === '1' ? 1 : 0;
$complainant_name   = trim($_POST['complainant_name']      ?? '');
$category           = trim($_POST['category']              ?? '');
$other_category     = trim($_POST['other_category_specify'] ?? '');
$address_location   = trim($_POST['address_location']      ?? '');
$priority_level     = trim($_POST['priority_level']        ?? '');
$title              = trim($_POST['title']                 ?? '');
$description        = trim($_POST['description']           ?? '');
$created_at         = trim($_POST['created_at']            ?? '');

// ── If category is "Other", use the specified value ───────────────────────────
if ($category === 'Other' && $other_category !== '') {
    $category = $other_category;
}

// ── Server-side validation ────────────────────────────────────────────────────
$errors = [];

if (empty($complaint_id)) {
    $errors[] = 'Complaint ID is missing.';
}
if (!$is_anonymous && empty($complainant_name)) {
    $errors[] = 'Complainant Name is required.';
}
if (empty($category)) {
    $errors[] = 'Category is required.';
}
if (empty($address_location)) {
    $errors[] = 'Address / Location is required.';
}
if (empty($priority_level) || !in_array($priority_level, ['Low', 'Medium', 'High (Urgent)'])) {
    $errors[] = 'Priority Level is required.';
}
if (empty($title)) {
    $errors[] = 'Complaint Title is required.';
}
if (empty($description)) {
    $errors[] = 'Description is required.';
}

// Validate created_at — fallback to now if malformed
if (empty($created_at) || strtotime($created_at) === false) {
    $created_at = date('Y-m-d H:i:s');
} else {
    $created_at = date('Y-m-d H:i:s', strtotime($created_at));
}

if (!empty($errors)) {
    $_SESSION['toast_msg']   = implode(' ', $errors);
    $_SESSION['toast_color'] = 'error';
    header('Location: ../frontend/complaint_rep.php');
    exit;
}

// ── Handle file attachment ────────────────────────────────────────────────────
$attachment_path = null;

if (!empty($_FILES['attachment']['name'])) {
    $file      = $_FILES['attachment'];
    $allowed   = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf',
                  'application/msword',
                  'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    $max_size  = 5 * 1024 * 1024; // 5 MB

    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mime     = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['toast_msg']   = 'File upload error. Please try again.';
        $_SESSION['toast_color'] = 'error';
        header('Location: ../frontend/complaint_rep.php');
        exit;
    }
    if (!in_array($mime, $allowed)) {
        $_SESSION['toast_msg']   = 'Invalid file type. Allowed: images, PDF, DOC, DOCX.';
        $_SESSION['toast_color'] = 'error';
        header('Location: ../frontend/complaint_rep.php');
        exit;
    }
    if ($file['size'] > $max_size) {
        $_SESSION['toast_msg']   = 'File is too large. Maximum size is 5 MB.';
        $_SESSION['toast_color'] = 'error';
        header('Location: ../frontend/complaint_rep.php');
        exit;
    }

    $upload_dir = __DIR__ . '/uploads/complaints/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $ext             = pathinfo($file['name'], PATHINFO_EXTENSION);
    $safe_filename   = $complaint_id . '_' . time() . '.' . strtolower($ext);
    $destination     = $upload_dir . $safe_filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        $_SESSION['toast_msg']   = 'Failed to save the uploaded file. Please try again.';
        $_SESSION['toast_color'] = 'error';
        header('Location: ../frontend/complaint_rep.php');
        exit;
    }

    $attachment_path = 'uploads/complaints/' . $safe_filename;
}

// ── Insert into DB ────────────────────────────────────────────────────────────
try {
    $sql = "INSERT INTO complaints
            (complaint_id, resident_id, purok, is_anonymous, complainant_name,
             category, address_location, priority_level, title, description,
             attachment_path, status, created_at)
            VALUES
                (:complaint_id, :resident_id, :purok, :is_anonymous, :complainant_name,
                 :category, :address_location, :priority_level, :title, :description,
                 :attachment_path, 'Pending', :created_at)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':complaint_id'     => $complaint_id,
        ':resident_id'      => $resident_id,
        ':purok'            => $purok ?: null,
        ':is_anonymous'     => $is_anonymous,
        ':complainant_name' => $is_anonymous ? null : $complainant_name,
        ':category'         => $category,
        ':address_location' => $address_location,
        ':priority_level'   => $priority_level,
        ':title'            => $title,
        ':description'      => $description,
        ':attachment_path'  => $attachment_path,
        ':created_at'       => $created_at,
    ]);

    $_SESSION['toast_msg']   = 'Complaint "' . $title . '" filed successfully.';
    $_SESSION['toast_color'] = 'success';
    log_activity('Complaints', 'File Complaint', "Filed complaint ID: {$complaint_id}. Category: {$category}, Priority: {$priority_level}, Title: " . htmlspecialchars($title) . "." . ($is_anonymous ? " (Anonymous)" : " Complainant: {$complainant_name}."));

} catch (PDOException $e) {
    error_log('process_complaint.php DB error: ' . $e->getMessage());

    // Duplicate complaint_id — extremely rare but handle gracefully
    if ($e->getCode() === '23000') {
        $_SESSION['toast_msg']   = 'A complaint with that ID already exists. Please try again.';
    } else {
        $_SESSION['toast_msg']   = 'A server error occurred. Please try again.';
    }
    $_SESSION['toast_color'] = 'error';
}

header('Location: ../frontend/complaint_rep.php');
exit;
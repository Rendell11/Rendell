<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db.php';

$isStaff = isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin','staff'], true);
if (!$isStaff) {
    require_once __DIR__ . '/../../auth_check.php';
    $isStaff = isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin','staff'], true);
}
$profileToken = trim($_GET['profile_token'] ?? '');
$isProfile = false;
if (!$isStaff && $profileToken !== '' && preg_match('/^[a-f0-9]{32,128}$/i', $profileToken)) {
    $q = $pdo->prepare("SELECT 1 FROM access_requests WHERE token=? AND status IN ('Approved','For Profiling') AND (token_expiry IS NULL OR token_expiry >= NOW()) LIMIT 1");
    $q->execute([$profileToken]); $isProfile=(bool)$q->fetchColumn();
}
if (!$isStaff && !$isProfile) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}
$action=$_GET['action']??'';
try {
 // Manage Area extends the existing single-row barangay_profile table.
 // Keep this migration here as well as in residents.php because this API can be
 // called directly before the Residents page has had a chance to bootstrap it.
 $pdo->exec("CREATE TABLE IF NOT EXISTS barangay_profile (
   id TINYINT UNSIGNED NOT NULL DEFAULT 1,
   brgy_name VARCHAR(255) NOT NULL DEFAULT 'Barangay Biñang 2nd',
   logo_path VARCHAR(512) NULL, about TEXT NULL, vision TEXT NULL, mission TEXT NULL,
   office_hours VARCHAR(512) NOT NULL DEFAULT 'Monday – Friday, 8:00 AM – 5:00 PM',
   address VARCHAR(512) NULL, email VARCHAR(255) NULL, facebook_url VARCHAR(512) NULL,
   updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
   PRIMARY KEY (id)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 $pdo->exec("INSERT IGNORE INTO barangay_profile (id) VALUES (1)");
 foreach ([
   'region_name VARCHAR(150) NULL', 'province_name VARCHAR(150) NULL',
   'municipality_name VARCHAR(150) NULL', 'barangay_name VARCHAR(150) NULL',
   'psgc_region_code VARCHAR(20) NULL', 'psgc_province_code VARCHAR(20) NULL',
   'psgc_municipality_code VARCHAR(20) NULL', 'psgc_barangay_code VARCHAR(20) NULL',
   'zip_code VARCHAR(10) NULL',
        // older databases (e.g. an earlier Rebuild_Database script) lack these too
        'address VARCHAR(512) NULL', 'about TEXT NULL', 'vision TEXT NULL', 'mission TEXT NULL',
        'email VARCHAR(255) NULL', 'facebook_url VARCHAR(512) NULL',
        "office_hours VARCHAR(512) NOT NULL DEFAULT 'Monday – Friday, 8:00 AM – 5:00 PM'"
 ] as $definition) {
   try { $pdo->exec("ALTER TABLE barangay_profile ADD COLUMN {$definition}"); } catch (Throwable $ignore) {}
 }
 if ($action==='profile') {
   $s=$pdo->query("SELECT region_name,province_name,municipality_name,barangay_name,
     psgc_region_code,psgc_province_code,psgc_municipality_code,psgc_barangay_code,zip_code,address
     FROM barangay_profile WHERE id=1 LIMIT 1");
   echo json_encode(['success'=>true,'data'=>$s->fetch(PDO::FETCH_ASSOC) ?: []], JSON_UNESCAPED_UNICODE); exit;
 }
 if ($action==='streets') {
   $b=preg_replace('/\D/','',$_GET['barangay']??'');
   $s=$pdo->prepare("SELECT id, street_name, status FROM resident_streets WHERE psgc_barangay_code=? AND status='Active' ORDER BY street_name");
   $s->execute([$b]); echo json_encode(['success'=>true,'data'=>$s->fetchAll()]); exit;
 }
 if ($action==='areas') {
   $b=preg_replace('/\D/','',$_GET['barangay']??'');
   $s=$pdo->prepare("SELECT id, area_type, area_name, status FROM resident_areas WHERE psgc_barangay_code=? AND status='Active' ORDER BY area_type, area_name");
   $s->execute([$b]); echo json_encode(['success'=>true,'data'=>$s->fetchAll()]); exit;
 }
 if ($action==='zip') {
   $m=preg_replace('/\D/','',$_GET['municipality']??'');
   $s=$pdo->prepare("SELECT zip_code FROM psgc_zip_codes WHERE municipality_code=? AND status='Active' LIMIT 1");
   $s->execute([$m]); echo json_encode(['success'=>true,'zip_code'=>$s->fetchColumn() ?: null]); exit;
 }
 echo json_encode(['success'=>false,'message'=>'Invalid action']);
} catch(Throwable $e) {
 error_log('address_api: '.$e->getMessage());
 http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error']);
}

<?php
/**
 * user/backend/chat.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Resident ⇄ Barangay Staff chat — JSON API for the Flutter app.
 * Ported from the SOE emergency_chat.php flow, simplified to a single thread
 * per resident (resident ↔ staff). Text messages (image optional later).
 *
 * Actions (GET ?action= or POST action=):
 *   • list      (resident_id)                 → messages + status + hotlines
 *   • send      (resident_id, message, category?) → append a Resident message
 *   • end       (resident_id)                 → mark the thread Resolved
 *   • hotlines                                 → emergency hotlines only
 *
 * Envelope: { success, message, data } via respond() in config.php.
 * Scoped to the resident_id supplied by the app (same trust model as the
 * other app endpoints).
 * ─────────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/config.php'; // respond(), handle_preflight(), db()
handle_preflight();

/*
 * IMPORTANT: talk to the SAME database as the ADMIN chat so messages are
 * shared both ways. The admin chat uses CAPS/admin/db.php. From this file
 * (CAPS/user/backend/) that is ../../admin/db.php. We include it directly so
 * we never diverge onto a different DB via config's fallback constants.
 */
$pdo = null;
foreach ([
    __DIR__ . '/../../admin/db.php',   // CAPS/admin/db.php  ← admin chat's DB
    __DIR__ . '/../../db.php',          // CAPS/db.php
    __DIR__ . '/../../staff/db.php',
] as $__p) {
    if (is_file($__p)) {
        try { ob_start(); require $__p; ob_end_clean(); } catch (Throwable $e) { if (ob_get_level() > 0) ob_end_clean(); }
        if (isset($pdo) && $pdo instanceof PDO) break;
    }
}
if (!($pdo instanceof PDO)) {
    $pdo = db(); // fall back to config's resolver
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ── Ensure tables exist (no manual SQL needed) ───────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS emergency_chats (
        ChatID      INT AUTO_INCREMENT PRIMARY KEY,
        ResidentID  INT NOT NULL,
        SenderRole  ENUM('Resident','Staff','System') NOT NULL DEFAULT 'Resident',
        Message     TEXT NOT NULL,
        Category    VARCHAR(50) NULL DEFAULT 'General',
        Image       VARCHAR(500) NULL,
        Status      ENUM('Pending','Ongoing','Resolved','Closed') NOT NULL DEFAULT 'Pending',
        IsRead      TINYINT(1) NOT NULL DEFAULT 0,
        Timestamp   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_res (ResidentID),
        INDEX idx_time (Timestamp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
// Self-heal: make sure the columns the ADMIN chat expects exist. An older
// build of this file created the table with `ImagePath` instead of `Image`,
// which broke the admin's INSERT (Unknown column 'Image'). These ALTERs are
// idempotent — they no-op once the column is present.
foreach ([
    "ALTER TABLE emergency_chats ADD COLUMN Image VARCHAR(500) NULL AFTER Message",
    "ALTER TABLE emergency_chats ADD COLUMN Category VARCHAR(50) NULL DEFAULT 'General' AFTER Message",
    "ALTER TABLE emergency_chats ADD COLUMN IsRead TINYINT(1) NOT NULL DEFAULT 0",
] as $__alter) {
    try { $pdo->exec($__alter); } catch (Throwable $e) { /* column already exists */ }
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS emergency_hotlines (
        id     INT AUTO_INCREMENT PRIMARY KEY,
        name   VARCHAR(100) NOT NULL,
        number VARCHAR(50)  NOT NULL,
        color  VARCHAR(20)  NULL DEFAULT 'blue'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
// Seed a couple of default hotlines the first time.
try {
    $c = (int) $pdo->query("SELECT COUNT(*) FROM emergency_hotlines")->fetchColumn();
    if ($c === 0) {
        $pdo->exec("INSERT INTO emergency_hotlines (name, number, color) VALUES
            ('Barangay Hall', '(044) 123-4567', 'blue'),
            ('Police / PNP',  '117',            'indigo'),
            ('Fire / BFP',    '(044) 111-2222', 'red'),
            ('Medical / MDRRMO', '(044) 333-4444', 'emerald')");
    }
} catch (Throwable $e) { /* ignore */ }

// ── Read action + resident ───────────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$rid    = require_resident(); // verified login token (auth.php), not the resident_id sent by the app

function chat_hotlines(PDO $pdo): array {
    try {
        return $pdo->query("SELECT id, name, number, color FROM emergency_hotlines ORDER BY id ASC")
                   ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

function chat_thread_status(PDO $pdo, int $rid): string {
    try {
        $s = $pdo->prepare("SELECT Status FROM emergency_chats WHERE ResidentID = ? ORDER BY Timestamp DESC, ChatID DESC LIMIT 1");
        $s->execute([$rid]);
        $v = $s->fetchColumn();
        return $v ?: 'None';
    } catch (Throwable $e) { return 'None'; }
}

// ── HOTLINES ─────────────────────────────────────────────────────────────────
if ($action === 'hotlines') {
    respond(true, '', ['hotlines' => chat_hotlines($pdo)]);
}

if ($rid <= 0) {
    respond(false, L('Kailangan ang resident_id.', 'resident_id is required.'), null, 400);
}

// ── LIST messages ────────────────────────────────────────────────────────────
if ($action === 'list') {
    try {
        // Mark staff/system messages as read.
        $pdo->prepare("UPDATE emergency_chats SET IsRead = 1
                       WHERE ResidentID = ? AND SenderRole IN ('Staff','System') AND IsRead = 0")
            ->execute([$rid]);

        $stmt = $pdo->prepare("SELECT * FROM emergency_chats
                               WHERE ResidentID = ? ORDER BY Timestamp ASC, ChatID ASC");
        $stmt->execute([$rid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Normalise the image column name (admin uses `Image`, some schemas `ImagePath`).
        foreach ($rows as &$r) {
            $r['ImagePath'] = $r['ImagePath'] ?? $r['Image'] ?? null;
        }
        unset($r);

        $status = chat_thread_status($pdo, $rid);
        respond(true, '', [
            'messages' => $rows,
            'status'   => $status,
            'can_start_new' => in_array($status, ['None', 'Resolved', 'Closed'], true),
            'hotlines' => chat_hotlines($pdo),
        ]);
    } catch (Throwable $e) {
        error_log('[chat.php list] ' . $e->getMessage());
        respond(false, L('Hindi ma-load ang mensahe.', 'Could not load the messages.'), null, 500);
    }
}

// ── SEND a resident message ──────────────────────────────────────────────────
if ($action === 'send') {
    $message  = trim($_POST['message'] ?? '');
    $category = trim($_POST['category'] ?? 'General');
    if ($message === '') {
        respond(false, L('Walang laman ang mensahe.', 'The message is empty.'), null, 400);
    }
    try {
        // If the last thread is Resolved/Closed (or none), this starts a fresh
        // one as Pending; otherwise it continues the ongoing thread.
        $status = chat_thread_status($pdo, $rid);
        $newStatus = in_array($status, ['None', 'Resolved', 'Closed'], true) ? 'Pending' : $status;

        $pdo->prepare("INSERT INTO emergency_chats (ResidentID, SenderRole, Message, Category, Status, IsRead, Timestamp)
                       VALUES (?, 'Resident', ?, ?, ?, 1, NOW())")
            ->execute([$rid, $message, $category ?: 'General', $newStatus]);

        respond(true, L('Naipadala ang mensahe.', 'Message sent.'), ['chat_id' => (int) $pdo->lastInsertId()]);
    } catch (Throwable $e) {
        error_log('[chat.php send] ' . $e->getMessage());
        respond(false, L('Hindi naipadala ang mensahe.', 'Message not sent.'), null, 500);
    }
}

// ── END conversation ─────────────────────────────────────────────────────────
if ($action === 'end') {
    try {
        $pdo->prepare("UPDATE emergency_chats SET Status = 'Resolved' WHERE ResidentID = ?")
            ->execute([$rid]);
        respond(true, L('Naisara ang usapan.', 'Conversation closed.'));
    } catch (Throwable $e) {
        error_log('[chat.php end] ' . $e->getMessage());
        respond(false, L('Hindi naisara ang usapan.', 'Could not close the conversation.'), null, 500);
    }
}

respond(false, L('Hindi wastong action.', 'Invalid action.'), null, 400);

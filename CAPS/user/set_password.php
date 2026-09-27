<?php
/**
 * set_password.php  — PLACE IN THE USER FOLDER:  C:\xampp\htdocs\CAPS\user\set_password.php
 * ─────────────────────────────────────────────────────────────────────
 * Resident Portal — Set Password via Secure Token (ported from SOE).
 * The admin "Approve" flow stores a one-time token on access_requests; the
 * emailed link opens this page. On success the matched resident is created/
 * activated. Matches the CAPS access_requests + residents schema and reuses
 * the same logic as the Flutter app's set-password endpoint.
 * PUBLIC page (opened by the resident from their email) — it is intentionally
 * OUTSIDE admin/ so it is not behind the admin login guard.
 * ─────────────────────────────────────────────────────────────────────
 */

session_start();

/*
 * CAPS DB bootstrap (provides $pdo).
 * Preferred: reuse the resident module's own connector — CAPS/user/backend/config.php
 * defines db() which returns a PDO to barangay_db (same DB as the app + admin).
 * Fallbacks: the admin/shared db.php, then direct constants, so this page still
 * connects even if config.php is missing.
 */
$pdo = null;

// 1) Preferred — the resident backend's db() (this file is in CAPS/user/).
$__cfg = __DIR__ . '/backend/config.php';
if (is_file($__cfg)) {
    require_once $__cfg;
    if (function_exists('db')) {
        try { $pdo = db(); } catch (Throwable $e) { $pdo = null; }
    }
}

// 2) Fallback — a project db.php that defines $pdo.
if (!($pdo instanceof PDO)) {
    foreach ([
        __DIR__ . '/../admin/db.php',   // CAPS/user/ → CAPS/admin/db.php
        __DIR__ . '/../db.php',         // CAPS/db.php
        __DIR__ . '/admin/db.php',      // if placed at CAPS root instead
        __DIR__ . '/db.php',
    ] as $__p) {
        if (is_file($__p)) {
            try { ob_start(); require $__p; ob_end_clean(); } catch (Throwable $e) { if (ob_get_level()>0) ob_end_clean(); }
            if (isset($pdo) && $pdo instanceof PDO) break;
        }
    }
}

// 3) Last resort — connect with XAMPP defaults to barangay_db.
if (!($pdo instanceof PDO)) {
    try {
        $pdo = new PDO('mysql:host=127.0.0.1;dbname=barangay_db;charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) { $pdo = null; }
}

if (!($pdo instanceof PDO)) {
    die('Database connection is not available. Check set_password.php DB path.');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ── Dynamic accent (matches the resident portal appearance set in settings.php) ─
$ACCENT      = '#1d63da';   // default portal blue
$ACCENT_DARK = '#104db8';
try {
    $t = $pdo->query("SELECT portal_accent_color FROM portal_preferences ORDER BY id DESC LIMIT 1");
    $row = $t ? $t->fetch(PDO::FETCH_ASSOC) : null;
    if (!empty($row['portal_accent_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $row['portal_accent_color'])) {
        $ACCENT = $row['portal_accent_color'];
        // Simple darken for hover
        $r = max(0, hexdec(substr($ACCENT,1,2)) - 40);
        $g = max(0, hexdec(substr($ACCENT,3,2)) - 40);
        $b = max(0, hexdec(substr($ACCENT,5,2)) - 40);
        $ACCENT_DARK = sprintf('#%02x%02x%02x', $r, $g, $b);
    }
} catch (Throwable $e) { /* keep defaults */ }

// ── Sanitise & validate token from URL ───────────────────────────────
$token = trim($_GET['token'] ?? '');
if ($token === '' || strlen($token) > 128) {
    $pageError = 'This link is invalid or missing.';
}

// ── Look up token from access_requests (set by the admin approve flow) ─
$accessReq = null;
if (!isset($pageError)) {
    $stmt = $pdo->prepare("
        SELECT id, request_id, resident_id, matched_resident_id, status, token_expiry,
               first_name, middle_name, last_name, email, contact_number,
               birthdate, house_no, street, purok
        FROM   access_requests
        WHERE  token = ? AND status IN ('Approved','Matched')
        LIMIT  1
    ");
    $stmt->execute([$token]);
    $accessReq = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$accessReq) {
        $pageError = 'This link is invalid or has already been used.';
    } elseif (!empty($accessReq['token_expiry']) && strtotime($accessReq['token_expiry']) < time()) {
        $pageError = 'This link has expired. Please contact the barangay office to request a new one.';
    } else {
        $resident = [
            'FirstName' => $accessReq['first_name'],
            'LastName'  => $accessReq['last_name'],
            'Email'     => $accessReq['email'],
        ];
    }
}

// ── Handle form submission ────────────────────────────────────────────
$formError = '';
$success   = false;

if (!isset($pageError) && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $newPassword = $_POST['new_password']     ?? '';
    $confirmPw   = $_POST['confirm_password'] ?? '';

    if (strlen($newPassword) < 8) {
        $formError = 'Password must be at least 8 characters long.';
    } elseif (!preg_match('/[A-Z]/', $newPassword)) {
        $formError = 'Password must contain at least one uppercase letter.';
    } elseif (!preg_match('/[0-9]/', $newPassword)) {
        $formError = 'Password must contain at least one number.';
    } elseif ($newPassword !== $confirmPw) {
        $formError = 'Passwords do not match. Please re-enter.';
    } else {
        $hashed = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

        try {
            $pdo->beginTransaction();

            // 1) Find the resident: by linked id, else by email.
            $residentId = $accessReq['resident_id'] ?: $accessReq['matched_resident_id'];
            if (!$residentId && !empty($accessReq['email'])) {
                $rs = $pdo->prepare("SELECT ResidentID FROM residents
                                     WHERE Email = ? AND (IsDeceased = 0 OR IsDeceased IS NULL) LIMIT 1");
                $rs->execute([$accessReq['email']]);
                $row = $rs->fetch(PDO::FETCH_ASSOC);
                if ($row) $residentId = (int) $row['ResidentID'];
            }

            if ($residentId) {
                // 2a) Existing resident → set password + activate.
                $pdo->prepare("
                    UPDATE residents
                    SET    Password = ?, ResetToken = NULL, TokenExpiry = NULL, access_status = 'Active'
                    WHERE  ResidentID = ?
                ")->execute([$hashed, $residentId]);
            } else {
                // 2b) No resident yet → create one from the request data.
                $ins = $pdo->prepare("
                    INSERT INTO residents
                        (FirstName, MiddleName, LastName, Email, ContactNumber,
                         BirthDate, HouseNumber, StreetName, Purok,
                         Password, access_status, IsDeceased)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', 0)
                ");
                $ins->execute([
                    $accessReq['first_name'],
                    $accessReq['middle_name'] ?: null,
                    $accessReq['last_name'],
                    $accessReq['email'] ?: null,
                    $accessReq['contact_number'] ?: null,
                    $accessReq['birthdate'] ?: null,
                    $accessReq['house_no'] ?: null,
                    $accessReq['street'] ?: null,
                    $accessReq['purok'] ?: null,
                    $hashed,
                ]);
                $residentId = (int) $pdo->lastInsertId();
                $pdo->prepare("UPDATE access_requests SET resident_id = ? WHERE id = ?")
                    ->execute([$residentId, $accessReq['id']]);
            }

            // 3) Burn the one-time token so the link can't be reused.
            $pdo->prepare("UPDATE access_requests SET token = NULL, token_expiry = NULL WHERE id = ?")
                ->execute([$accessReq['id']]);

            $pdo->commit();
            $success = true;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("[SetPassword] " . $e->getMessage());
            $formError = 'Something went wrong while activating your account. Please try again.';
        }
    }
}

function sp_h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Your Password — Barangay Biñang 2nd</title>

    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">

    <script>
        tailwind.config = { theme: { extend: { fontFamily: { sans: ['Poppins','sans-serif'] } } } }
    </script>

    <style>
        :root {
            --accent: <?= sp_h($ACCENT) ?>;
            --accent-dark: <?= sp_h($ACCENT_DARK) ?>;
        }
        body {
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
            overflow-x: hidden; overflow-y: auto;
            background: linear-gradient(rgba(239,244,250,0.92), rgba(239,244,250,0.92));
            background-size: cover; background-position: center; background-attachment: fixed;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            padding: 1.5rem 1rem;
        }
        @media (max-height: 700px) { body { justify-content: flex-start; } }

        /* Waves */
        .wave-wrapper { position:fixed; bottom:0; left:0; width:100%; height:230px; overflow:hidden; z-index:1; }
        .wave1,.wave2,.wave3 { position:absolute; width:120%; left:-10%; border-radius:50%; transform-origin:center; }
        @keyframes waveMove1 { 0%,100%{transform:translateX(0) translateY(0)} 50%{transform:translateX(-35px) translateY(10px)} }
        @keyframes waveMove2 { 0%,100%{transform:translateX(0) translateY(0)} 50%{transform:translateX(40px) translateY(-8px)} }
        @keyframes waveMove3 { 0%,100%{transform:translateX(0) translateY(0)} 50%{transform:translateX(-25px) translateY(6px)} }
        .wave1 { height:220px; background:var(--accent); bottom:-55px; opacity:.8; animation:waveMove1 7s ease-in-out infinite; }
        .wave2 { height:190px; background:var(--accent-dark); bottom:-80px; opacity:.9; animation:waveMove2 9s ease-in-out infinite; }
        .wave3 { height:140px; background:var(--accent-dark); bottom:-95px; filter:brightness(.85); animation:waveMove3 11s ease-in-out infinite; }

        .glass-card { background:rgba(255,255,255,0.98); border-radius:28px!important; box-shadow:0 20px 50px rgba(0,0,0,.10); }
        .divider-accent { width:85px; height:4px; background:var(--accent); margin:10px auto; border-radius:50px; }
        .resident-badge {
            display:inline-flex; align-items:center; gap:6px;
            background:color-mix(in srgb, var(--accent) 12%, #fff);
            border:1px solid color-mix(in srgb, var(--accent) 35%, #fff); color:var(--accent);
            font-size:.65rem; font-weight:700; letter-spacing:.12em;
            text-transform:uppercase; padding:5px 14px; border-radius:50px;
        }
        .input-field { transition:all .2s; border-radius:14px!important; }
        .input-field:focus { outline:none; box-shadow:0 0 0 4px color-mix(in srgb, var(--accent) 15%, transparent); border-color:var(--accent)!important; }
        .btn-primary { background:var(--accent); transition:.3s; border-radius:14px!important; }
        .btn-primary:hover:not(:disabled) { background:var(--accent-dark); transform:translateY(-2px); box-shadow:0 10px 20px color-mix(in srgb, var(--accent) 30%, transparent); }
        .btn-primary:disabled { opacity:.75; cursor:not-allowed; }
        .pw-strength-bar { height:4px; border-radius:2px; transition:width .3s,background .3s; }
        @keyframes fadeInUp { from{opacity:0;transform:translateY(24px)} to{opacity:1;transform:translateY(0)} }
        .animate-fade-in-up { animation:fadeInUp .5s ease both; }
        @keyframes pulse-once { 0%{transform:scale(.8);opacity:0} 60%{transform:scale(1.1)} 100%{transform:scale(1);opacity:1} }
        .pop-icon { animation:pulse-once .5s ease both; }
        @media (max-width:480px) { .glass-card{border-radius:20px!important} .px-8{padding-left:1.25rem!important;padding-right:1.25rem!important} }
        @media (max-width:360px) { .wave-wrapper{height:160px} }
        .accent-text { color: var(--accent); }
    </style>
</head>

<body class="antialiased relative">

    <div class="wave-wrapper">
        <div class="wave1"></div><div class="wave2"></div><div class="wave3"></div>
    </div>

    <div class="w-full max-w-lg relative z-10 animate-fade-in-up">
        <div class="glass-card overflow-hidden">

            <!-- Header -->
            <div class="px-8 pt-5 text-center">
                <div class="inline-block mb-2">
                    <img src="barangaylogo.png" alt="Barangay Logo" class="w-16 h-auto mx-auto"
                         onerror="this.style.display='none'">
                </div>
                <h1 class="text-xl font-bold text-[#102d76] tracking-tight uppercase">Barangay Biñang 2nd</h1>
                <p class="text-xs font-bold text-slate-500 uppercase tracking-widest mt-0.5">Bocaue, Bulacan</p>
                <div class="divider-accent"></div>
                <div class="flex justify-center mb-2">
                    <span class="resident-badge">
                        <span class="material-symbols-outlined text-[14px]">lock</span>
                        Account Setup
                    </span>
                </div>

                <?php if (isset($pageError)): ?>
                    <h2 class="text-[#16408d] font-bold text-xl tracking-tight uppercase">Link Unavailable</h2>
                <?php elseif ($success): ?>
                    <h2 class="text-[#16408d] font-bold text-xl tracking-tight uppercase">Account Activated</h2>
                <?php else: ?>
                    <h2 class="text-[#16408d] font-bold text-xl tracking-tight uppercase">Set Your Password</h2>
                    <p class="text-slate-400 text-sm mt-1 mb-1">Create a secure password to activate your portal account.</p>
                <?php endif; ?>
            </div>

            <?php if (isset($pageError)): ?>
            <!-- ── ERROR STATE ─────────────────────────────────────── -->
            <div class="px-8 py-8 text-center">
                <div class="pop-icon flex items-center justify-center w-20 h-20 rounded-full bg-rose-50 border-2 border-rose-200 mx-auto mb-5">
                    <span class="material-symbols-outlined text-rose-500 text-[44px]">link_off</span>
                </div>
                <p class="text-slate-600 text-sm leading-relaxed mb-2"><?= sp_h($pageError) ?></p>
                <p class="text-slate-400 text-xs">Please contact the barangay office or request a new link.</p>
            </div>

            <?php elseif ($success): ?>
            <!-- ── SUCCESS STATE ───────────────────────────────────── -->
            <div class="px-8 py-8 text-center">
                <div class="pop-icon flex items-center justify-center w-20 h-20 rounded-full bg-emerald-50 border-2 border-emerald-200 mx-auto mb-5">
                    <span class="material-symbols-outlined text-emerald-500 text-[44px]">task_alt</span>
                </div>
                <p class="text-slate-700 text-sm leading-relaxed mb-2 font-semibold">
                    Your password has been set and your account is now active.
                </p>
                <p class="text-slate-500 text-sm leading-relaxed mb-6">
                    You can now open the <strong>Barangay Biñang 2nd Resident app</strong> and log in with your
                    email and new password.
                </p>
                <div class="flex items-center justify-center gap-2 text-sm text-slate-400">
                    <span class="material-symbols-outlined text-[22px] accent-text">shield_with_heart</span>
                    <span class="font-medium">Secure Resident Portal</span>
                </div>
            </div>

            <?php else: ?>
            <!-- ── FORM STATE ──────────────────────────────────────── -->
            <div class="mx-8 mt-3 flex items-center gap-3 bg-slate-50 border border-slate-200 rounded-xl px-4 py-3">
                <span class="material-symbols-outlined accent-text text-[20px] shrink-0">person</span>
                <p class="text-sm text-slate-700 font-medium">
                    Welcome, <strong><?= sp_h($resident['FirstName'] . ' ' . $resident['LastName']) ?></strong>!
                </p>
            </div>

            <?php if ($formError): ?>
            <div class="mx-8 mt-4 flex items-start gap-3 bg-rose-50 border border-rose-200 text-rose-700 rounded-xl px-4 py-3 text-sm font-medium">
                <span class="material-symbols-outlined text-rose-500 text-xl mt-0.5">error</span>
                <span><?= sp_h($formError) ?></span>
            </div>
            <?php endif; ?>

            <div class="px-8 py-4">
                <form method="POST" action="" id="setPasswordForm" autocomplete="off" novalidate class="space-y-4">

                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-2 px-1">Registered Email</label>
                        <div class="flex items-center gap-2 bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm text-slate-500 font-medium">
                            <span class="material-symbols-outlined text-slate-400 text-[18px]">mail</span>
                            <?= sp_h($resident['Email']) ?>
                        </div>
                    </div>

                    <div>
                        <label for="new_password" class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-2 px-1">
                            New Password <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative">
                            <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-[20px] pointer-events-none">lock</span>
                            <input type="password" name="new_password" id="new_password"
                                   placeholder="Min. 8 chars, 1 uppercase, 1 number" required minlength="8"
                                   class="input-field w-full pl-12 pr-12 py-3 bg-white border border-slate-200 text-slate-800 text-sm placeholder-slate-400">
                            <button type="button" onclick="togglePw('new_password','eyeNew')"
                                    class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 focus:outline-none">
                                <span class="material-symbols-outlined text-[20px]" id="eyeNew">visibility</span>
                            </button>
                        </div>
                        <div class="mt-2 bg-slate-100 rounded-full overflow-hidden">
                            <div id="strengthBar" class="pw-strength-bar w-0 bg-slate-300"></div>
                        </div>
                        <p id="strengthLabel" class="text-xs text-slate-400 mt-1"></p>
                        <ul class="mt-2 space-y-0.5 text-xs text-slate-400" id="pwChecks">
                            <li id="chkLen"   class="flex items-center gap-1.5"><span class="material-symbols-outlined text-[11px]">circle</span> At least 8 characters</li>
                            <li id="chkUpper" class="flex items-center gap-1.5"><span class="material-symbols-outlined text-[11px]">circle</span> One uppercase letter</li>
                            <li id="chkNum"   class="flex items-center gap-1.5"><span class="material-symbols-outlined text-[11px]">circle</span> One number</li>
                        </ul>
                    </div>

                    <div>
                        <label for="confirm_password" class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-2 px-1">
                            Confirm Password <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative">
                            <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-[20px] pointer-events-none">lock_reset</span>
                            <input type="password" name="confirm_password" id="confirm_password"
                                   placeholder="Re-enter your password" required
                                   class="input-field w-full pl-12 pr-12 py-3 bg-white border border-slate-200 text-slate-800 text-sm placeholder-slate-400">
                            <button type="button" onclick="togglePw('confirm_password','eyeConfirm')"
                                    class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 focus:outline-none">
                                <span class="material-symbols-outlined text-[20px]" id="eyeConfirm">visibility</span>
                            </button>
                        </div>
                        <p id="matchMsg" class="text-xs mt-1.5 hidden px-1"></p>
                    </div>

                    <div class="flex items-start gap-2 bg-slate-50 border border-slate-100 rounded-xl px-4 py-3 text-xs text-slate-600">
                        <span class="material-symbols-outlined accent-text text-[18px] mt-0.5 shrink-0">info</span>
                        <span>Your password must be at least <strong>8 characters</strong> with one <strong>uppercase letter</strong> and one <strong>number</strong>.</span>
                    </div>

                    <button type="submit" id="submitBtn"
                            class="btn-primary w-full text-white font-bold py-3 text-sm tracking-wide flex items-center justify-center gap-2 uppercase">
                        <span id="btnText">Activate My Account</span>
                        <span id="btnIcon" class="material-symbols-outlined text-[18px]">lock</span>
                        <svg id="btnSpinner" class="hidden w-5 h-5 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                    </button>
                </form>
            </div>

            <div class="px-8 pb-5">
                <div class="border-t border-slate-100 pt-5 flex flex-col items-center gap-3">
                    <div class="flex items-center justify-center gap-2 text-sm text-slate-400">
                        <span class="material-symbols-outlined text-[22px] accent-text">shield_with_heart</span>
                        <span class="font-medium">Secure Resident Portal</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /.glass-card -->

        <p class="text-center text-xs text-white mt-8 font-medium">
            &copy;<?= date('Y') ?> Barangay Biñang 2nd &middot; Bocaue, Bulacan
        </p>
    </div>

    <?php if (!isset($pageError) && !$success): ?>
    <script>
    function togglePw(inputId, iconId) {
        const inp = document.getElementById(inputId), icon = document.getElementById(iconId);
        if (!inp || !icon) return;
        inp.type = inp.type === 'password' ? 'text' : 'password';
        icon.textContent = inp.type === 'password' ? 'visibility' : 'visibility_off';
    }
    const newPwEl=document.getElementById('new_password'), confirmPwEl=document.getElementById('confirm_password'),
          bar=document.getElementById('strengthBar'), label=document.getElementById('strengthLabel'),
          chkLen=document.getElementById('chkLen'), chkUpper=document.getElementById('chkUpper'),
          chkNum=document.getElementById('chkNum'), matchMsg=document.getElementById('matchMsg'),
          submitBtn=document.getElementById('submitBtn'), btnText=document.getElementById('btnText'),
          btnIcon=document.getElementById('btnIcon'), btnSpinner=document.getElementById('btnSpinner');

    function setCheck(el, ok){ if(!el) return; const i=el.querySelector('span');
        if(ok){ el.classList.replace('text-slate-400','text-emerald-600'); if(i) i.textContent='check_circle'; }
        else  { el.classList.replace('text-emerald-600','text-slate-400'); if(i) i.textContent='circle'; } }

    function updateChecks(){
        const v=newPwEl?.value ?? '';
        const hasLen=v.length>=8, hasUpper=/[A-Z]/.test(v), hasNum=/[0-9]/.test(v);
        const score=[hasLen,hasUpper,hasNum].filter(Boolean).length;
        setCheck(chkLen,hasLen); setCheck(chkUpper,hasUpper); setCheck(chkNum,hasNum);
        const levels=[
            {w:'0%',cls:'bg-slate-300',text:'',lbl:''},
            {w:'33%',cls:'bg-rose-400',text:'Weak',lbl:'text-rose-500'},
            {w:'66%',cls:'bg-amber-400',text:'Fair',lbl:'text-amber-600'},
            {w:'100%',cls:'bg-emerald-500',text:'Strong',lbl:'text-emerald-600'}];
        const lvl=v.length===0?levels[0]:levels[score];
        if(bar){ bar.style.width=lvl.w; bar.className=`pw-strength-bar ${lvl.cls}`; }
        if(label){ label.textContent=lvl.text; label.className=`text-xs mt-1 ${lvl.lbl}`; }
        checkMatch();
    }
    function checkMatch(){
        if(!confirmPwEl||!matchMsg) return;
        const cv=confirmPwEl.value;
        if(cv===''){ matchMsg.classList.add('hidden'); return; }
        matchMsg.classList.remove('hidden');
        if((newPwEl?.value ?? '')===cv){ matchMsg.textContent='✓ Passwords match'; matchMsg.className='text-xs mt-1.5 px-1 text-emerald-600'; }
        else { matchMsg.textContent='✗ Passwords do not match'; matchMsg.className='text-xs mt-1.5 px-1 text-rose-500'; }
    }
    newPwEl?.addEventListener('input', updateChecks);
    confirmPwEl?.addEventListener('input', checkMatch);
    document.getElementById('setPasswordForm')?.addEventListener('submit', function(){
        if(submitBtn){ submitBtn.disabled=true; btnText.textContent='Activating...'; btnIcon.classList.add('hidden'); btnSpinner.classList.remove('hidden'); }
    });
    </script>
    <?php endif; ?>
</body>
</html>

<?php
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../permission_helper.php';
require_permission($pdo, 'announcements', 'read');
require_once __DIR__ . '/../../theme_loader.php';
$current_page = "Announcements";
$_theme_head_loaded = true; // We include theme_head.php ourselves inside <head>

// CSRF helper — load from file if available, otherwise define inline
$csrf_helper_path = __DIR__ . '/csrf_helper.php';
if (file_exists($csrf_helper_path)) {
    require_once $csrf_helper_path;
}
if (session_status() === PHP_SESSION_NONE) session_start();
if (!function_exists('csrf_token_field')) {
    function csrf_token_field() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token']) . '">';
    }
}
if (!function_exists('csrf_verify')) {
    function csrf_verify() {
        if (
            empty($_SESSION['csrf_token']) ||
            empty($_POST['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
        ) {
            http_response_code(403);
            die('Invalid request. Please go back and try again.');
        }
        unset($_SESSION['csrf_token']);
    }
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ann.php'); exit; }

try {
    $stmt = $pdo->prepare("SELECT * FROM announcements WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$id]);
    $ann = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ann) { header('Location: ann.php'); exit; }

    $stmt2 = $pdo->prepare("SELECT * FROM announcement_attachments WHERE announcement_id = ? ORDER BY sort_order, id");
    $stmt2->execute([$id]);
    $attachments = $stmt2->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    header('Location: ann.php'); exit;
}

// Use old input if validation failed
$d = $_SESSION['ann_old'] ?? $ann;
unset($_SESSION['ann_old']);

$cats = ['General','Health Advisory','Community Event','Emergency Notice'];
// Keep the announcement's current category selectable even if it isn't in the list above
// (e.g. 'Health' or 'Others'), so saving the edit doesn't silently change or reject it.
if (!empty($d['category']) && !in_array($d['category'], $cats, true)) { $cats[] = $d['category']; }

// "Today" in Philippine time. Used as the Post Date when the announcement is
// switched to Publish Now. Explicit timezone so it can't drift if PHP's default
// timezone isn't Asia/Manila (update_ann.php stamps the same value on save).
$server_today = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');

// What is actually saved in the DB right now. If the admin flips to Publish Now
// and back to Scheduled, the form falls back to these instead of leaving blanks.
$orig_sched = [
    'datePostedInput' => $ann['date_posted'] ?? '',
    'dateStartInput'  => $ann['date_start']  ?? '',
    'timeStartInput'  => !empty($ann['time_start']) ? substr($ann['time_start'], 0, 5) : '',
    'dateEndInput'    => $ann['date_end']    ?? '',
    'timeEndInput'    => (!empty($ann['time_end']) && $ann['time_end'] !== '00:00:00') ? substr($ann['time_end'], 0, 5) : '',
];

// Once an announcement is actually live (Published), only content fields stay
// editable — category, status, and every scheduling date/time are locked,
// since there's nothing left to schedule for a post that's already out.
$cur_status_top   = $d['status'] ?? 'Published';
$isAlreadyPosted  = ($cur_status_top === 'Published');
?>
<!DOCTYPE html>
<html <?php echo $theme_attrs['html']; ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Announcement — Barangay Biñang 2nd</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <?php include __DIR__ . '/../../theme_head.php'; ?>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: { DEFAULT: 'var(--accent-600)', light: 'var(--accent-500)', dark: 'var(--accent-700)' },
                        accent:  { DEFAULT: 'var(--accent-500)', light: 'var(--accent-400)' },
                    },
                    fontFamily: { sans: ['"Plus Jakarta Sans"', 'sans-serif'], mono: ['"DM Mono"', 'monospace'] }
                }
            }
        }
    </script>
    <style>
        :root { --sidebar-w: 288px; --nav-h: 64px; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #eef2fb; }

        .main-wrapper { margin-left: var(--sidebar-w); width: calc(100% - var(--sidebar-w)); }
        @media (max-width: 1024px) { .main-wrapper { margin-left: 0; width: 100%; } }

        .section-title { font-size: .65rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; color: #94a3b8; }

        /* ── Dark mode ───────────────────────────────────────── */
        html.dark body { background: #0f172a; }
        html.dark .bg-white { background: #1e293b !important; }
        html.dark .bg-slate-50 { background: #0f172a !important; }
        html.dark .border-slate-200\/60, html.dark .border-slate-200 { border-color: #334155 !important; }
        html.dark .border-slate-100 { border-color: #1e293b !important; }
        html.dark .text-slate-800 { color: #f1f5f9 !important; }
        html.dark .text-slate-700 { color: #e2e8f0 !important; }
        html.dark .text-slate-600 { color: #94a3b8 !important; }
        html.dark .text-slate-400 { color: #475569 !important; }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }

        .file-preview-item { animation: fadeIn .2s ease; }
        @keyframes fadeIn { from { opacity:0; transform: translateY(4px); } to { opacity:1; transform: translateY(0); } }
        .drop-active { border-color: var(--accent-600, #1a3570) !important; background-color: color-mix(in srgb, var(--accent-100, #eff6ff) 80%, transparent) !important; }

        /* Facebook toggle */
        .fb-toggle input[type="checkbox"] { display: none; }
        .fb-toggle .track {
            width: 44px; height: 24px; border-radius: 12px;
            background: #e2e8f0; transition: background .25s; cursor: pointer;
            display: inline-flex; align-items: center; padding: 2px; flex-shrink: 0;
        }
        .fb-toggle input:checked + .track { background: #1877f2; }
        .fb-toggle .thumb {
            width: 20px; height: 20px; border-radius: 50%;
            background: white; box-shadow: 0 1px 3px rgba(0,0,0,.25);
            transition: transform .25s;
        }
        .fb-toggle input:checked + .track .thumb { transform: translateX(20px); }
    </style>
</head>
<body <?php echo $theme_attrs['body']; ?> class="text-slate-900 antialiased">

<div class="flex min-h-screen">
    <?php include __DIR__ . '/../../sidebar.php'; ?>

    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php include __DIR__ . '/../../header.php'; ?>

        <main class="p-4 md:p-6 lg:p-8 space-y-6">

            <!-- Validation errors -->
            <?php if (!empty($_SESSION['ann_errors'])): ?>
            <div class="px-5 py-4 bg-rose-50 border border-rose-100 rounded-2xl text-rose-700 text-sm font-semibold space-y-1">
                <?php foreach($_SESSION['ann_errors'] as $err): ?>
                <p class="flex items-center gap-2"><span class="material-symbols-outlined" style="font-size:15px">error</span><?php echo htmlspecialchars($err); ?></p>
                <?php endforeach; unset($_SESSION['ann_errors']); ?>
            </div>
            <?php endif; ?>

            <!-- ── Hero Band ───────────────────────────────────────────────────── -->
            <div class="rounded-2xl p-6 md:p-8 text-white relative overflow-hidden" style="background: linear-gradient(135deg, var(--accent-800, #1a3570) 0%, var(--accent-600, #2a4fa0) 50%, var(--accent-800, #1a3570) 100%);">
                <div class="absolute -right-12 -top-12 w-64 h-64 opacity-10 rounded-full blur-3xl pointer-events-none" style="background: var(--accent-400, #f05a00);"></div>
                <div class="absolute left-1/3 bottom-0 w-48 h-48 opacity-10 rounded-full blur-2xl pointer-events-none" style="background: var(--accent-300, #6366f1);"></div>
                <div class="relative z-10">
                    <h1 class="text-2xl md:text-3xl font-black tracking-tight leading-none">Edit Announcement</h1>
                    <p class="text-white/60 text-sm mt-2 font-medium">Updating: <strong class="text-white/80"><?php echo htmlspecialchars($ann['title']); ?></strong></p>
                    <br>
                    <nav class="flex gap-2 text-[11px] font-bold uppercase tracking-widest text-white/40 mb-3">
                        <a href="ann.php" class="hover:text-white/80 transition-colors">Announcements</a>
                        <span>›</span>
                        <span class="text-white/70">Edit #ANN-<?php
    $ann_year = !empty($ann['created_at']) ? date('Y', strtotime($ann['created_at'])) : date('Y', strtotime($ann['date_posted']));
    echo $ann_year . '-' . str_pad($ann['ann_id'], 3, '0', STR_PAD_LEFT);
?></span>
                    </nav>
                </div>
            </div>

            <form action="../backend/update_ann.php" method="POST" enctype="multipart/form-data" id="editForm">
                <?php csrf_token_field(); ?>
                <input type="hidden" name="ann_db_id" value="<?php echo $ann['id']; ?>">

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                    <!-- ── Left Column ─────────────────────────────────────────── -->
                    <div class="lg:col-span-2 space-y-5">

                        <div class="bg-white p-6 rounded-2xl border border-slate-200/60 shadow-sm">
                            <label class="section-title mb-3 block">
                                Announcement Title <span class="text-rose-400">*</span>
                            </label>
                            <input type="text" name="title" required maxlength="255"
                                value="<?php echo htmlspecialchars($d['title']); ?>"
                                class="w-full px-5 py-4 bg-slate-50 border-none rounded-2xl text-slate-700 font-medium focus:ring-2 focus:ring-blue-100 placeholder:text-slate-300">
                        </div>

                        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                            <div class="p-4 border-b border-slate-50 bg-slate-50/30 flex items-center gap-2">
                                <button type="button" onclick="wrapText('**','**')" class="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-50 rounded-lg transition-colors"><i data-lucide="bold" class="w-4 h-4"></i></button>
                                <button type="button" onclick="wrapText('_','_')" class="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-50 rounded-lg transition-colors"><i data-lucide="italic" class="w-4 h-4"></i></button>
                                <button type="button" onclick="insertBullet()" class="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-50 rounded-lg transition-colors"><i data-lucide="list" class="w-4 h-4"></i></button>
                            </div>
                            <div class="p-6">
                                <label class="section-title mb-3 block">Content Body <span class="text-rose-400">*</span></label>
                                <textarea name="details" id="contentBody" rows="12" required
                                    class="w-full border-none focus:ring-0 text-slate-600 font-medium placeholder:text-slate-300 p-0 resize-none"><?php echo htmlspecialchars($d['details']); ?></textarea>
                            </div>
                        </div>

                        <?php if (!empty($attachments)): ?>
                        <div class="bg-white p-6 rounded-2xl border border-slate-200/60 shadow-sm">
                            <label class="section-title mb-4 block">Existing Attachments</label>
                            <div class="space-y-3" id="existingAttachments">
                                <?php foreach ($attachments as $att):
                                    $extIcons = ['pdf'=>'picture_as_pdf','doc'=>'description','docx'=>'description','xls'=>'table_chart','xlsx'=>'table_chart','txt'=>'text_snippet','zip'=>'folder_zip','rar'=>'folder_zip'];
                                    $icon = $att['is_image'] ? null : ($extIcons[$att['file_ext']] ?? 'attach_file');
                                    $size = $att['file_size'] < 1024 ? $att['file_size'].'B' : ($att['file_size'] < 1048576 ? round($att['file_size']/1024,1).'KB' : round($att['file_size']/1048576,1).'MB');
                                ?>
                                <div class="flex items-center gap-4 p-4 bg-slate-50 rounded-xl" id="att_row_<?php echo $att['id']; ?>">
                                    <div class="w-12 h-12 rounded-xl overflow-hidden shrink-0 <?php echo $att['is_image'] ? '' : 'bg-primary/10 flex items-center justify-center'; ?>">
                                        <?php if ($att['is_image']): ?>
                                        <img src="<?php echo htmlspecialchars(preg_match('#^(https?:)?//|^/|^\.\./#', $att['file_path']) ? $att['file_path'] : '../backend/' . ltrim($att['file_path'], './')); ?>" class="w-full h-full object-cover" alt="">
                                        <?php else: ?>
                                        <span class="material-symbols-outlined text-primary" style="font-size:20px"><?php echo $icon; ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-bold text-slate-700 truncate"><?php echo htmlspecialchars($att['original_name']); ?></p>
                                        <p class="text-[10px] text-slate-400 font-medium"><?php echo strtoupper($att['file_ext']); ?> · <?php echo $size; ?></p>
                                    </div>
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" name="delete_attachments[]" value="<?php echo $att['id']; ?>"
                                            class="rounded text-rose-500 focus:ring-rose-400"
                                            onchange="toggleDeleteMark(this, <?php echo $att['id']; ?>)">
                                        <span class="text-xs font-bold text-rose-500">Remove</span>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- New Attachments -->
                        <div class="bg-white p-6 rounded-2xl border border-slate-200/60 shadow-sm">
                            <label class="section-title mb-4 block">Add New Attachments</label>
                            <div id="dropZone">
                                <input type="file" name="attachments[]" id="fileInput" class="hidden" multiple
                                    accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar,.ppt,.pptx,.csv"
                                    onchange="handleFiles(this.files)">
                                <label for="fileInput" id="dropLabel"
                                    class="flex flex-col items-center justify-center border-2 border-dashed border-slate-200 rounded-2xl p-10 cursor-pointer hover:border-slate-300 hover:bg-slate-50/50 transition-all">
                                    <div class="w-12 h-12 bg-primary/10 rounded-2xl flex items-center justify-center mb-4">
                                        <i data-lucide="upload-cloud" class="w-6 h-6 text-primary"></i>
                                    </div>
                                    <p class="text-sm font-bold text-slate-700">Click to upload or drag and drop</p>
                                    <p class="text-[10px] font-medium text-slate-400 mt-1 uppercase">Images, PDF, DOC, XLS, ZIP (Max 10MB each)</p>
                                </label>
                            </div>
                            <div id="filePreviewList" class="mt-4 space-y-2"></div>
                        </div>
                    </div>

                    <!-- ── Right Column ─────────────────────────────────────── -->
                    <div class="space-y-5">

                        <div class="bg-white p-6 rounded-2xl border border-slate-200/60 shadow-sm">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-8 h-8 bg-primary/10 rounded-lg flex items-center justify-center">
                                    <i data-lucide="layers" class="w-4 h-4 text-primary"></i>
                                </div>
                                <h3 class="font-bold text-slate-800 text-sm">Classification</h3>
                            </div>
                            <label class="section-title mb-2 block">Category</label>
                            <?php if ($isAlreadyPosted): ?>
                            <select disabled class="w-full bg-slate-100 border-none rounded-xl text-sm font-bold text-slate-400 cursor-not-allowed">
                                <option selected><?php echo htmlspecialchars($d['category']); ?></option>
                            </select>
                            <input type="hidden" name="category" value="<?php echo htmlspecialchars($d['category']); ?>">
                            <?php else: ?>
                            <select name="category" class="w-full bg-slate-50 border-none rounded-xl text-sm font-bold text-slate-700 focus:ring-2 focus:ring-blue-100 cursor-pointer">
                                <?php foreach($cats as $c): ?>
                                <option value="<?php echo $c; ?>" <?php echo $d['category']===$c ? 'selected' : ''; ?>><?php echo $c; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>

                            <label class="section-title mb-2 mt-4 block">Status</label>
                            <?php if ($isAlreadyPosted): ?>
                            <select id="statusSelect" disabled class="w-full bg-slate-100 border-none rounded-xl text-sm font-bold text-slate-400 cursor-not-allowed">
                                <option selected>Published</option>
                            </select>
                            <input type="hidden" name="status" value="Published">
                            <div class="mt-3 p-3 bg-slate-50 border border-slate-100 rounded-xl">
                                <p class="text-[11px] text-slate-500 font-semibold flex items-center gap-2">
                                    <span class="material-symbols-outlined" style="font-size:14px">lock</span>
                                    Your announcement is already posted, so scheduling isn't needed anymore.
                                </p>
                            </div>
                            <?php else: ?>
                            <?php
                                $cur_status = $d['status'] ?? 'Published';
                                // Status is now just Scheduled (auto-publish later) or Published
                                // (publish now) — Draft has been retired as a selectable state.
                                if ($cur_status === 'Scheduled') {
                                    $status_options = ['Scheduled' => 'Scheduled (Auto-publish)', 'Published' => 'Published (Publish Now)'];
                                } else {
                                    // Ended / any legacy state — just the two live choices
                                    $status_options = ['Published' => 'Published (Publish Now)', 'Scheduled' => 'Scheduled (re-schedule)'];
                                }
                            ?>
                            <select name="status" id="statusSelect" onchange="handleStatusChange(this.value)"
                                class="w-full bg-slate-50 border-none rounded-xl text-sm font-bold text-slate-700 focus:ring-2 focus:ring-blue-100 cursor-pointer">
                                <?php foreach ($status_options as $val => $label): ?>
                                <option value="<?php echo $val; ?>" <?php echo $cur_status === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <!-- Scheduling notice shown when Scheduled is selected -->
                            <div id="scheduledNotice" class="<?php echo $cur_status === 'Scheduled' ? '' : 'hidden'; ?> mt-3 p-3 bg-indigo-50 border border-indigo-100 rounded-xl">
                                <p class="text-[11px] text-indigo-600 font-semibold flex items-center gap-2">
                                    <span class="material-symbols-outlined" style="font-size:14px">schedule_send</span>
                                    Announcement will auto-publish on the Start Date &amp; Time below.
                                </p>
                            </div>
                            <!-- Live warning when the Start/End Date & Time is invalid -->
                            <p id="schedWarning" class="hidden mt-3 text-[11px] font-semibold leading-relaxed bg-rose-50 border border-rose-100 text-rose-600 rounded-xl px-3 py-2.5"></p>
                            <?php endif; ?>
                        </div>

                        <div class="bg-white p-6 rounded-2xl border border-slate-200/60 shadow-sm">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-8 h-8 bg-primary/10 rounded-lg flex items-center justify-center">
                                    <i data-lucide="clock" class="w-4 h-4 text-primary"></i>
                                </div>
                                <h3 class="font-bold text-slate-800 text-sm">Scheduling</h3>
                            </div>
                            <?php if ($isAlreadyPosted): ?>
                            <div class="mb-4 p-3 bg-slate-50 border border-slate-100 rounded-xl">
                                <p class="text-[11px] text-slate-500 font-semibold flex items-center gap-2">
                                    <span class="material-symbols-outlined" style="font-size:14px">lock</span>
                                    Your announcement is already posted — the schedule date isn't needed anymore.
                                </p>
                            </div>
                            <?php endif; ?>
                            <?php
                                // Once already live, these fields are historical record only —
                                // readonly (not disabled) so their values still submit unchanged.
                                $lockAttr  = $isAlreadyPosted ? 'readonly tabindex="-1" onclick="return false;"' : '';
                                $lockClass = $isAlreadyPosted ? ' opacity-50 cursor-not-allowed pointer-events-none' : '';
                            ?>
                            <div class="space-y-4">
                                <div>
                                    <label class="section-title mb-2 block">Post Date</label>
                                    <?php /* Post Date is never editable here: it's the date the announcement was
                                             created (or today, when publishing now). readonly, not disabled, so it still submits.
                                             The live schedule is the "Publish On" date below. */ ?>
                                    <input type="date" name="date_posted" id="datePostedInput" readonly tabindex="-1" onclick="return false;"
                                        value="<?php echo htmlspecialchars($ann['date_posted'] ?? $server_today); ?>"
                                        class="w-full bg-slate-50 border-none rounded-xl text-xs font-bold text-slate-700 focus:ring-2 focus:ring-blue-100 px-4 py-3 opacity-50 cursor-not-allowed pointer-events-none">
                                    <p id="postDateHint" class="<?php echo $isAlreadyPosted ? 'hidden ' : ''; ?>mt-1.5 text-[10px] text-slate-400 font-medium flex items-center gap-1">
                                        <span class="material-symbols-outlined" style="font-size:12px">lock</span>
                                        <span id="postDateHintText">The date this announcement was created. The "Publish On" date below sets when it goes live.</span>
                                    </p>
                                </div>
                                <div>
                                    <label class="section-title mb-2 block" id="startDateLabel">
                                        <?php echo ($d['status'] ?? '') === 'Scheduled' ? 'Publish On (Date &amp; Time)' : 'Start Date &amp; Time'; ?>
                                    </label>
                                    <div class="grid grid-cols-2 gap-2">
                                        <input type="date" name="date_start" id="dateStartInput" <?php echo $lockAttr; ?>
                                            value="<?php echo htmlspecialchars($d['date_start'] ?? ''); ?>"
                                            class="bg-slate-50 border-none rounded-xl text-xs font-bold text-slate-700 focus:ring-2 focus:ring-blue-100 px-3 py-3<?php echo $lockClass; ?>">
                                        <input type="time" name="time_start" id="timeStartInput" <?php echo $lockAttr; ?>
                                            value="<?php echo !empty($d['time_start']) ? substr($d['time_start'],0,5) : ''; ?>"
                                            class="bg-slate-50 border-none rounded-xl text-xs font-bold text-slate-700 focus:ring-2 focus:ring-blue-100 px-3 py-3<?php echo $lockClass; ?>">
                                    </div>
                                </div>
                                <div>
                                    <label class="section-title mb-2 block">End Date &amp; Time <span class="text-slate-300 font-normal normal-case">(optional)</span></label>
                                    <div class="grid grid-cols-2 gap-2">
                                        <input type="date" name="date_end" id="dateEndInput" <?php echo $lockAttr; ?>
                                            value="<?php echo htmlspecialchars($d['date_end'] ?? ''); ?>"
                                            class="bg-slate-50 border-none rounded-xl text-xs font-bold text-slate-700 focus:ring-2 focus:ring-blue-100 px-3 py-3<?php echo $lockClass; ?>">
                                        <input type="time" name="time_end" id="timeEndInput" <?php echo $lockAttr; ?>
                                            value="<?php echo !empty($d['time_end']) && $d['time_end'] !== '00:00:00' ? substr($d['time_end'],0,5) : ''; ?>"
                                            class="bg-slate-50 border-none rounded-xl text-xs font-bold text-slate-700 focus:ring-2 focus:ring-blue-100 px-3 py-3<?php echo $lockClass; ?>">
                                    </div>
                                </div>
                            </div>
                        </div>


                        <!-- ── Facebook Auto-Edit ─────────────────────────── -->
                        <div class="bg-white p-6 rounded-2xl border border-slate-200/60 shadow-sm" id="fbCard">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-8 h-8 bg-blue-50 rounded-lg flex items-center justify-center">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="#1877f2">
                                        <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.312 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>
                                    </svg>
                                </div>
                                <h3 class="font-bold text-slate-800 text-sm">Facebook Sync</h3>
                            </div>

                            <?php if (!empty($ann['fb_post_id'])): ?>
                            <!-- Already posted — show update toggle -->
                            <div class="flex items-center justify-between p-4 bg-slate-50 rounded-xl mb-3">
                                <div>
                                    <p class="text-xs font-bold text-slate-700">Update Facebook Post</p>
                                    <p class="text-[10px] text-slate-400 font-medium">Edit the linked post when saving</p>
                                </div>
                                <label class="fb-toggle">
                                    <input type="checkbox" name="update_facebook" id="fbCheckbox" value="1" checked>
                                    <span class="track"><span class="thumb"></span></span>
                                </label>
                            </div>
                            <p class="text-[10px] text-slate-400 flex items-center gap-1 mb-3">
                                <span class="material-symbols-outlined" style="font-size:12px">link</span>
                                Linked post ID: <code class="font-mono text-slate-500"><?php echo htmlspecialchars($ann['fb_post_id']); ?></code>
                            </p>
                            <?php else: ?>
                            <!-- Not yet posted — offer to post now -->
                            <div class="flex items-center justify-between p-4 bg-slate-50 rounded-xl mb-3">
                                <div>
                                    <p class="text-xs font-bold text-slate-700">Post to Facebook Page</p>
                                    <p class="text-[10px] text-slate-400 font-medium">Publish to Facebook when saving</p>
                                </div>
                                <label class="fb-toggle">
                                    <input type="checkbox" name="update_facebook" id="fbCheckbox" value="1">
                                    <span class="track"><span class="thumb"></span></span>
                                </label>
                            </div>
                            <?php endif; ?>

                            <!-- Live preview (shown when toggle is on) -->
                            <div id="fbPreviewBox" class="hidden mt-3 bg-slate-50 border border-slate-100 rounded-xl p-4">
                                <p class="section-title mb-3">Post Preview</p>
                                <div class="flex items-center gap-3 mb-3">
                                    <div class="w-9 h-9 bg-primary rounded-full flex items-center justify-center text-white font-bold text-xs shrink-0">B2</div>
                                    <div>
                                        <p class="text-xs font-bold text-slate-700">Barangay Biñang 2nd</p>
                                        <p class="text-[10px] text-slate-400">Just now · 🌐</p>
                                    </div>
                                </div>
                                <p class="text-xs text-slate-600 leading-relaxed whitespace-pre-line" id="fbPreviewText">—</p>
                                <p class="text-[10px] text-slate-400 mt-3">
                                    <span class="material-symbols-outlined align-middle" style="font-size:12px">info</span>
                                    Preview updates as you type.
                                </p>
                            </div>
                        </div>
                        <!-- ── /Facebook Auto-Edit ─────────────────────────── -->

                        <div class="flex gap-3">
                            <a href="ann.php" class="flex-1 px-4 py-3 rounded-xl bg-slate-100 text-slate-700 font-bold text-sm hover:bg-slate-200 transition-all text-center">Cancel</a>
                            <button type="submit" class="flex-1 px-4 py-3 rounded-xl text-white font-bold text-sm transition-all shadow-lg" style="background: var(--accent-600, #1a3570);">
                                Save Changes
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </main>
    </div>
</div>

<script>
    lucide.createIcons();

    // ─── Publish Now ↔ Scheduled ──────────────────────────────────────────────
    // Publish Now  → Post Date becomes today's date (locked) and the schedule
    //                fields are cleared + locked.
    // Back to Scheduled → the Post Date and schedule the admin had come back, so
    //                clicking Publish Now by mistake doesn't lose anything.
    const SERVER_TODAY  = <?php echo json_encode($server_today); ?>;
    const remembered    = <?php echo json_encode($orig_sched, JSON_HEX_TAG | JSON_HEX_AMP); ?>; // id → last value before Publish Now
    const postDateInput = document.getElementById('datePostedInput');
    const postDateHint  = document.getElementById('postDateHint');
    const postDateHintText = document.getElementById('postDateHintText');
    const HINT_SCHEDULED   = 'The date this announcement was created. The "Publish On" date below sets when it goes live.';
    const HINT_PUBLISH_NOW = "Set to today's date because this will be published now.";
    const LOCK_CLASSES  = ['opacity-50', 'cursor-not-allowed', 'pointer-events-none'];

    function schedFieldEls() {
        return ['dateStartInput', 'timeStartInput', 'dateEndInput', 'timeEndInput']
            .map(id => document.getElementById(id));
    }
    function lockEl(el) {
        el.readOnly = true;           // readonly (not disabled) so the value still submits
        el.tabIndex = -1;
        el.classList.add(...LOCK_CLASSES);
    }
    function unlockEl(el) {
        el.readOnly = false;
        el.removeAttribute('tabindex');
        el.classList.remove(...LOCK_CLASSES);
    }
    // Snapshot whatever is in the schedule fields right now. (Post Date isn't
    // snapshotted — it's never edited, so its original value is always the saved one.)
    function rememberValues() {
        ['dateStartInput', 'timeStartInput', 'dateEndInput', 'timeEndInput'].forEach(id => {
            const el = document.getElementById(id);
            if (el) remembered[id] = el.value;
        });
    }
    // Post Date = today (locked)
    function applyPublishNowPostDate() {
        if (!postDateInput) return;
        postDateInput.value = SERVER_TODAY;
        if (postDateHintText) postDateHintText.textContent = HINT_PUBLISH_NOW;
    }
    // Post Date = the date the announcement was created (locked)
    function applyScheduledPostDate() {
        if (!postDateInput) return;
        if (remembered.datePostedInput) postDateInput.value = remembered.datePostedInput;
        if (postDateHintText) postDateHintText.textContent = HINT_SCHEDULED;
    }

    function handleStatusChange(val) {
        const notice = document.getElementById('scheduledNotice');
        const startLabel = document.getElementById('startDateLabel');
        const schedInputs = schedFieldEls();

        if (val === 'Scheduled') {
            notice.classList.remove('hidden');
            if (startLabel) startLabel.innerHTML = 'Publish On (Date &amp; Time) <span class="text-rose-400">*</span>';
            // Unlock — admin is (re-)scheduling this post — and put back what they had
            schedInputs.forEach(el => {
                if (!el) return;
                unlockEl(el);
                if (el.value === '' && remembered[el.id] !== undefined) el.value = remembered[el.id];
            });
            applyScheduledPostDate();
        } else {
            notice.classList.add('hidden');
            if (startLabel) startLabel.textContent = 'Start Date & Time';
            rememberValues(); // keep the admin's input in case they switch back
            // Publishing now — scheduling isn't used, so lock and clear these
            // fields so nothing stale gets saved and confuses the system later.
            schedInputs.forEach(el => {
                if (!el) return;
                el.value = '';
                lockEl(el);
            });
            applyPublishNowPostDate();
        }
        validateSchedule();
        if (typeof updateFbPreview === 'function') updateFbPreview();
    }

    // Page opened already on "Publish Now" (e.g. re-publishing an ended
    // announcement, or the form re-shown after a validation error): the Post
    // Date must show today's date here too, not a stale one.
    (function () {
        const sel = document.getElementById('statusSelect');
        if (!postDateInput || !sel || sel.disabled) return; // already-posted view is locked server-side
        if (sel.value === 'Published') applyPublishNowPostDate();
    })();

    // ─── Scheduling Date & Time Validation (only while Status = Scheduled) ─────
    // Only relevant for announcements not yet live — an already-posted
    // announcement's fields are locked (readonly) and this simply no-ops for it,
    // and a historical Ended announcement's past start date is expected, so we
    // only enforce "must be in the future" while actively scheduling.
    (function () {
        const statusSelectEl = document.getElementById('statusSelect');
        const dateStartInput = document.getElementById('dateStartInput');
        const timeStartInput = document.getElementById('timeStartInput');
        const dateEndInput   = document.getElementById('dateEndInput');
        const timeEndInput   = document.getElementById('timeEndInput');
        const warnBox        = document.getElementById('schedWarning');
        if (!statusSelectEl || !dateStartInput) return; // locked (already-posted) view — nothing to wire up

        function localDateStr(d) {
            const p = n => String(n).padStart(2, '0');
            return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
        }
        const todayStr = localDateStr(new Date());
        dateStartInput.min = todayStr;

        function toDateTime(dateVal, timeVal, fallbackTime) {
            if (!dateVal) return null;
            const t = timeVal || fallbackTime || '00:00';
            const dt = new Date(`${dateVal}T${t}`);
            return isNaN(dt.getTime()) ? null : dt;
        }

        window.validateSchedule = function () {
            if (statusSelectEl.value !== 'Scheduled') {
                if (warnBox) { warnBox.textContent = ''; warnBox.classList.add('hidden'); }
                return '';
            }
            const startDT = toDateTime(dateStartInput.value, timeStartInput.value, '00:00');
            let msg = '';
            if (!startDT) {
                msg = 'Please set the Start Date & Time before saving.';
            } else if (startDT.getTime() < Date.now() - 60000) {
                msg = 'The Start Date & Time has already passed. Please choose a future date and time.';
            } else {
                const endDT = toDateTime(dateEndInput.value, timeEndInput.value, '23:59');
                if (endDT && endDT <= startDT) {
                    msg = 'The End Date & Time must be later than the Start Date & Time.';
                }
            }
            if (warnBox) {
                warnBox.textContent = msg;
                warnBox.classList.toggle('hidden', msg === '');
            }
            return msg;
        };

        [dateStartInput, timeStartInput, dateEndInput, timeEndInput].forEach(el => {
            if (el) el.addEventListener('change', validateSchedule);
        });

        document.getElementById('editForm').addEventListener('submit', function (e) {
            const msg = validateSchedule();
            if (msg) {
                e.preventDefault();
                if (typeof showToast === 'function') showToast('error', msg);
                else alert(msg);
            }
        });
    })();

    let allFiles = new DataTransfer();

    // HTML-escape helper used by the file preview list (was missing, which made the
    // preview crash with "esc is not defined" so nothing showed after picking a file).
    function esc(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    function handleFiles(newFiles) {
        const MAX = 10*1024*1024;
        Array.from(newFiles).forEach(f => { if(f.size<=MAX) allFiles.items.add(f); else alert(f.name+' exceeds 10MB'); });
        document.getElementById('fileInput').files = allFiles.files;
        renderPreviews();
    }

    function renderPreviews() {
        const list = document.getElementById('filePreviewList');
        list.innerHTML = '';
        if (allFiles.files.length) {
            const head = document.createElement('p');
            head.className = 'text-[11px] font-bold text-emerald-600 flex items-center gap-1.5';
            head.innerHTML = '<span class="material-symbols-outlined" style="font-size:15px">check_circle</span>'
                + allFiles.files.length + ' file' + (allFiles.files.length > 1 ? 's' : '') + ' selected \u2014 will be uploaded when you click Save';
            list.appendChild(head);
        }
        Array.from(allFiles.files).forEach((file,i) => {
            const isImg = file.type.startsWith('image/') || /\.(jpe?g|png|gif|webp)$/i.test(file.name);
            const ext = file.name.split('.').pop().toUpperCase();
            const size = file.size<1024?file.size+'B':file.size<1048576?(file.size/1024).toFixed(1)+'KB':(file.size/1048576).toFixed(1)+'MB';
            const icons = {PDF:'picture_as_pdf',DOC:'description',DOCX:'description',XLS:'table_chart',XLSX:'table_chart',TXT:'text_snippet',ZIP:'folder_zip',RAR:'folder_zip'};
            const icon = icons[ext]||'attach_file';
            const div = document.createElement('div');
            div.className = 'file-preview-item flex items-center gap-4 p-4 bg-slate-50 rounded-2xl';
            div.innerHTML = `
                <div class="w-12 h-12 rounded-xl overflow-hidden shrink-0 ${isImg?'':'bg-blue-50 flex items-center justify-center'}">
                    ${isImg?`<img src="${URL.createObjectURL(file)}" class="w-full h-full object-cover">`:`<span class="material-symbols-outlined text-[#1e3a8a] !text-xl">${icon}</span>`}
                </div>
                <div class="min-w-0 flex-1"><p class="text-sm font-bold text-slate-700 truncate">${esc(file.name)}</p><p class="text-[10px] text-slate-400">${ext} · ${size}</p></div>
                <button type="button" onclick="removeFile(${i})" class="p-1.5 hover:bg-rose-50 rounded-lg text-slate-300 hover:text-rose-500 transition-colors">
                    <span class="material-symbols-outlined !text-lg">close</span>
                </button>`;
            list.appendChild(div);
        });
    }

    function removeFile(i) {
        const dt = new DataTransfer();
        Array.from(allFiles.files).forEach((f,idx)=>{ if(idx!==i) dt.items.add(f); });
        allFiles = dt;
        document.getElementById('fileInput').files = allFiles.files;
        renderPreviews();
    }

    function toggleDeleteMark(cb, id) {
        const row = document.getElementById('att_row_'+id);
        if (cb.checked) row.classList.add('opacity-40','line-through');
        else row.classList.remove('opacity-40','line-through');
    }

    const dropZone = document.getElementById('dropZone');
    ['dragenter','dragover'].forEach(ev=>dropZone.addEventListener(ev,e=>{e.preventDefault();dropZone.querySelector('label').classList.add('drop-active');}));
    ['dragleave','drop'].forEach(ev=>dropZone.addEventListener(ev,e=>{e.preventDefault();dropZone.querySelector('label').classList.remove('drop-active');}));
    dropZone.addEventListener('drop',e=>handleFiles(e.dataTransfer.files));

    function wrapText(b,a){const ta=document.getElementById('contentBody');const s=ta.selectionStart,e=ta.selectionEnd;ta.value=ta.value.substring(0,s)+b+ta.value.substring(s,e)+a+ta.value.substring(e);ta.focus();}
    function insertBullet(){const ta=document.getElementById('contentBody');const p=ta.selectionStart;ta.value=ta.value.substring(0,p)+'\n• '+ta.value.substring(p);ta.focus();}
    // ─── Facebook Preview ────────────────────────────────────────
    const fbCheckbox = document.getElementById('fbCheckbox');
    const fbPreviewBox = document.getElementById('fbPreviewBox');

    if (fbCheckbox) {
        fbCheckbox.addEventListener('change', function () {
            fbPreviewBox.classList.toggle('hidden', !this.checked);
            if (this.checked) updateFbPreview();
        });
        // Show preview on load if toggle is checked
        if (fbCheckbox.checked) {
            fbPreviewBox.classList.remove('hidden');
            updateFbPreview();
        }
    }

    function updateFbPreview() {
        if (!fbCheckbox || !fbCheckbox.checked) return;
        const title    = (document.querySelector('[name="title"]').value || '').trim();
        const details  = (document.getElementById('contentBody').value || '').trim();
        const category = (document.querySelector('[name="category"]').value || 'GENERAL').toUpperCase();
        const startRaw = document.querySelector('[name="date_start"]').value;
        const endRaw   = document.querySelector('[name="date_end"]').value;

        let dateLine = '';
        if (startRaw) {
            const fmt = s => { const [y,m,d]=s.split('-'); const mo=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']; return `${mo[+m-1]} ${+d}, ${y}`; };
            const start = fmt(startRaw);
            const end   = endRaw ? fmt(endRaw) : null;
            dateLine = (end && end !== start) ? `📅 Valid: ${start} – ${end}` : `📅 Date: ${start}`;
        }

        const parts = [`📣 [${category}] ${title || '(untitled)'}`, '', details || '(no content)'];
        if (dateLine) parts.push('', dateLine);
        parts.push('', '—', 'Barangay Biñang 2nd Official Announcement', '#BarangayBinang2nd #OfficialAnnouncement');
        document.getElementById('fbPreviewText').textContent = parts.join('\n');
    }

    // Live-update preview on typing
    ['title','category','date_start','date_end'].forEach(n => {
        const el = document.querySelector(`[name="${n}"]`);
        if (el) { el.addEventListener('input', updateFbPreview); el.addEventListener('change', updateFbPreview); }
    });
    document.getElementById('contentBody').addEventListener('input', updateFbPreview);

</script>
</body>
</html>
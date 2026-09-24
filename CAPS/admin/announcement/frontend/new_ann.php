<?php
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../permission_helper.php';
require_permission($pdo, 'announcements', 'create');
require_once __DIR__ . '/../../theme_loader.php';
$current_page = "Announcements";
$_theme_head_loaded = true; // We include theme_head.php ourselves inside <head>

// CSRF helper — load from file if available, otherwise define inline
$csrf_helper_path = __DIR__ . '/csrf_helper.php';
if (file_exists($csrf_helper_path)) {
    require_once $csrf_helper_path;
}
// Inline fallback: define functions if not already defined by the include
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
?>
<!DOCTYPE html>
<html <?php echo $theme_attrs['html']; ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Announcement — Barangay Biñang 2nd</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
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
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--page-bg, #eef2fb); -webkit-font-smoothing: antialiased; }
        .btn-accent { background: var(--accent-600); color: #fff; }
        .btn-accent:hover { background: var(--accent-700); }

        .main-wrapper { margin-left: var(--sidebar-w); width: calc(100% - var(--sidebar-w)); }
        @media (max-width: 1024px) { .main-wrapper { margin-left: 0; width: 100%; } }

        .section-title { font-size: .65rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; color: #94a3b8; }

        .hero-gradient {
            background: linear-gradient(135deg, var(--accent-800, #1a3570) 0%, var(--accent-600, #2a4fa0) 50%, var(--accent-800, #1a3570) 100%);
            position: relative; overflow: hidden;
        }
        .hero-gradient::after {
            content: ''; position: absolute; inset: 0;
            background: radial-gradient(ellipse at 80% 50%, rgba(var(--accent-500-rgb, 240,90,0),.25) 0%, transparent 65%),
                        radial-gradient(ellipse at 10% 80%, rgba(99,102,241,.2) 0%, transparent 60%);
            pointer-events: none;
        }

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

            <!-- Validation errors as toast (fired on load) -->
            <?php if (!empty($_SESSION['ann_errors'])): ?>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                <?php foreach($_SESSION['ann_errors'] as $err): ?>
                showToast('error', <?php echo json_encode($err); ?>);
                <?php endforeach; ?>
            });
            </script>
            <?php unset($_SESSION['ann_errors']); endif; ?>

            <!-- ── Hero Band ───────────────────────────────────────────────────── -->
            <div class="rounded-2xl p-6 md:p-8 text-white relative overflow-hidden"
                style="background: linear-gradient(135deg, var(--accent-700) 0%, var(--accent-600) 50%, var(--accent-700) 100%);">
                <div class="absolute -right-12 -top-12 w-64 h-64 opacity-10 rounded-full blur-3xl pointer-events-none"
                    style="background: var(--accent-400);"></div>
                <div class="absolute left-1/3 bottom-0 w-48 h-48 opacity-10 rounded-full blur-2xl pointer-events-none"
                    style="background: var(--accent-300);"></div>
                <div class="relative z-10">
                    <h1 class="text-2xl md:text-3xl font-black tracking-tight leading-none">Create New Announcement</h1>
                    <p class="text-white/60 text-sm mt-2 font-medium">Communicate vital information to the residents of Barangay Biñang 2nd.</p>
                    <br>
                    <nav class="flex gap-2 text-[11px] font-bold uppercase tracking-widest text-white/40 mb-3">
                        <a href="ann.php" class="hover:text-white/80 transition-colors">Announcements</a>
                        <span>›</span>
                        <span class="text-white/70">Create New</span>
                    </nav>
                </div>
            </div>

<form action="../backend/save_ann.php" method="POST" enctype="multipart/form-data" id="annForm">
    <?php csrf_token_field(); ?>

    <!-- Restored-draft banner — shown only when a locally-saved draft was found and applied -->
    <div id="draftRestoreBanner" class="hidden mb-5 px-5 py-4 bg-amber-50 border border-amber-100 rounded-2xl flex items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <span class="material-symbols-outlined text-amber-500">history</span>
            <div>
                <p class="text-xs font-bold text-slate-700">Restored your unsaved draft</p>
                <p class="text-[11px] text-slate-500 font-medium" id="draftRestoreMeta">Picked up where you left off.</p>
            </div>
        </div>
        <button type="button" onclick="discardDraft()"
            class="px-3.5 py-2 rounded-xl text-[11px] font-bold text-rose-500 hover:bg-rose-50 transition-colors whitespace-nowrap">
            Discard draft
        </button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                    <!-- ── Left Column ─────────────────────────────────────────── -->
                    <div class="lg:col-span-2 space-y-5">

                        <!-- Title -->
                        <div class="bg-white p-6 rounded-[32px] border border-slate-100 shadow-sm">
                            <label class="block text-[10px] font-bold text-slate-400 uppercase ml-1 mb-1.5">
                                Announcement Title <span class="text-rose-400">*</span>
                            </label>
                            <input type="text" name="title" required maxlength="255"
                                placeholder="Enter a descriptive headline..."
                                class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20 placeholder:text-slate-300"
                                value="<?php echo htmlspecialchars($_SESSION['ann_old']['title'] ?? ''); ?>">
                        </div>

                        <!-- Content Body -->
                        <div class="bg-white rounded-[32px] border border-slate-100 shadow-sm overflow-hidden">
                            <div class="px-4 py-3 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between gap-2">
                                <div class="flex items-center gap-1">
                                    <button type="button" onclick="wrapText('**','**')"
                                        class="p-2 text-slate-400 hover:text-slate-600 hover:bg-white rounded-lg transition-colors" title="Bold">
                                        <span class="material-symbols-outlined" style="font-size:18px">format_bold</span>
                                    </button>
                                    <button type="button" onclick="wrapText('_','_')"
                                        class="p-2 text-slate-400 hover:text-slate-600 hover:bg-white rounded-lg transition-colors" title="Italic">
                                        <span class="material-symbols-outlined" style="font-size:18px">format_italic</span>
                                    </button>
                                    <button type="button" onclick="insertBullet()"
                                        class="p-2 text-slate-400 hover:text-slate-600 hover:bg-white rounded-lg transition-colors" title="List">
                                        <span class="material-symbols-outlined" style="font-size:18px">format_list_bulleted</span>
                                    </button>
                                    <span id="draftStatus" class="ml-2 text-[10px] font-bold text-slate-300 flex items-center gap-1"></span>
                                </div>
                                <button type="button" id="aiToggleBtn" onclick="toggleAiPanel()"
                                    class="flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-[11px] font-bold text-white transition-all shadow-sm hover:-translate-y-0.5"
                                    style="background: linear-gradient(90deg, var(--accent-500, #f05a00), var(--accent-400, #ff7a20));"
                                    aria-expanded="false" aria-controls="aiPanel"
                                    title="Generate content using AI">
                                    <span class="material-symbols-outlined" style="font-size:15px">auto_awesome</span>
                                    <span>Generate with AI</span>
                                    <span class="material-symbols-outlined transition-transform" style="font-size:15px" id="aiToggleChevron">expand_more</span>
                                </button>
                            </div>
                            <div class="p-6">
                                <!-- AI panel — hidden until "Generate with AI" is clicked -->
                                <div id="aiPanel" class="hidden mb-5 rounded-2xl border border-indigo-100 bg-indigo-50/40 overflow-hidden">
                                    <div class="flex items-center justify-between px-5 py-3 border-b border-indigo-100/70">
                                        <div class="flex items-center gap-2">
                                            <span class="material-symbols-outlined text-indigo-500" style="font-size:17px">auto_awesome</span>
                                            <p class="text-xs font-bold text-slate-700">AI Content Assistant</p>
                                        </div>
                                        <button type="button" onclick="toggleAiPanel(false)"
                                            class="p-1 rounded-lg text-slate-300 hover:text-slate-500 hover:bg-white transition-colors" title="Close">
                                            <span class="material-symbols-outlined" style="font-size:17px">close</span>
                                        </button>
                                    </div>

                                    <div class="p-5 space-y-4">
                                        <div>
                                            <label for="aiPromptInput" class="block text-[10px] font-bold text-slate-400 uppercase ml-1 mb-1.5">Prompt</label>
                                            <textarea id="aiPromptInput" rows="3"
                                                placeholder="e.g. Free anti-rabies vaccination at the Barangay Hall this Saturday, 8AM–12NN, for pet dogs and cats..."
                                                class="w-full px-4 py-3 bg-white border border-indigo-100 rounded-xl text-sm text-slate-700 font-medium focus:ring-2 focus:ring-primary/20 placeholder:text-slate-300 resize-none"></textarea>
                                        </div>

                                        <div>
                                            <span class="block text-[10px] font-bold text-slate-400 uppercase ml-1 mb-1.5">AI Source</span>
                                            <div class="grid sm:grid-cols-2 gap-2">
                                                <label class="relative block cursor-pointer">
                                                    <input type="radio" name="ai_source" value="prompt" class="peer sr-only" checked onchange="updateAiSourceUI()">
                                                    <span class="flex items-start gap-3 p-3.5 bg-white border border-indigo-100 rounded-xl transition-all peer-checked:border-primary peer-checked:ring-2 peer-checked:ring-primary/15 peer-focus-visible:ring-2 peer-focus-visible:ring-primary/30">
                                                        <span class="material-symbols-outlined text-slate-400" style="font-size:18px">edit_note</span>
                                                        <span class="min-w-0">
                                                            <span class="block text-xs font-bold text-slate-700">Prompt only</span>
                                                            <span class="block text-[10px] text-slate-400 font-medium leading-relaxed mt-0.5">The AI will use your text only.</span>
                                                        </span>
                                                    </span>
                                                </label>

                                                <label class="relative block cursor-pointer" id="aiMediaOptionWrap">
                                                    <input type="radio" name="ai_source" value="prompt_media" id="aiSourceMedia" class="peer sr-only" onchange="updateAiSourceUI()">
                                                    <span class="flex items-start gap-3 p-3.5 bg-white border border-indigo-100 rounded-xl transition-all peer-checked:border-primary peer-checked:ring-2 peer-checked:ring-primary/15 peer-focus-visible:ring-2 peer-focus-visible:ring-primary/30 peer-disabled:opacity-50">
                                                        <span class="material-symbols-outlined text-slate-400" style="font-size:18px">imagesmode</span>
                                                        <span class="min-w-0">
                                                            <span class="block text-xs font-bold text-slate-700">Prompt + attachment</span>
                                                            <span class="block text-[10px] text-slate-400 font-medium leading-relaxed mt-0.5" id="aiMediaHint">The AI will also read the attached media.</span>
                                                        </span>
                                                    </span>
                                                </label>
                                            </div>
                                        </div>

                                        <div class="flex items-center justify-end gap-2 pt-1">
                                            <button type="button" onclick="toggleAiPanel(false)"
                                                class="px-4 py-2.5 rounded-xl text-[11px] font-bold text-slate-500 hover:bg-white transition-colors">Cancel</button>
                                            <button type="button" id="aiGenerateBtn" onclick="generateAiCaption()"
                                                class="flex items-center gap-1.5 px-4 py-2.5 rounded-xl text-[11px] font-bold text-white transition-all shadow-sm hover:-translate-y-0.5"
                                                style="background: linear-gradient(90deg, var(--accent-500, #f05a00), var(--accent-400, #ff7a20));">
                                                <span class="material-symbols-outlined" style="font-size:15px" id="aiGenerateIcon">auto_awesome</span>
                                                <span id="aiGenerateLabel">Generate</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <label class="block text-[10px] font-bold text-slate-400 uppercase ml-1 mb-1.5">
                                    Content Body <span class="text-rose-400">*</span>
                                </label>
                                <textarea name="details" id="contentBody" rows="12" required
                                    placeholder="Write your announcement details here..."
                                    class="w-full border-none focus:ring-0 text-slate-600 font-medium placeholder:text-slate-300 p-0 resize-none"><?php echo htmlspecialchars($_SESSION['ann_old']['details'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <!-- Attachments -->
                        <div class="bg-white p-6 rounded-[32px] border border-slate-100 shadow-sm">
                            <label class="block text-[10px] font-bold text-slate-400 uppercase ml-1 mb-1.5">Attachments & Media</label>

                            <div id="dropZone" class="relative group">
                                <input type="file" name="attachments[]" id="fileInput" class="hidden" multiple
                                    accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar,.ppt,.pptx,.csv"
                                    onchange="handleFiles(this.files)">
                                <label for="fileInput" id="dropLabel"
                                    class="flex flex-col items-center justify-center border-2 border-dashed border-slate-200 rounded-[24px] p-10 bg-slate-50/50 cursor-pointer hover:border-primary/40 hover:bg-primary/5 transition-all">
                                    <div class="w-12 h-12 bg-primary/10 rounded-2xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
                                        <span class="material-symbols-outlined text-primary" style="font-size:24px">cloud_upload</span>
                                    </div>
                                    <p class="text-sm font-bold text-slate-700">Click to upload or drag and drop</p>
                                    <p class="section-title mt-1">Images, PDF, DOC, XLS, ZIP and more (Max 10MB each)</p>
                                </label>
                            </div>

                            <div id="filePreviewList" class="mt-4 space-y-2"></div>
                        </div>

                    </div>

                    <!-- ── Right Column ────────────────────────────────────────── -->
                    <div class="space-y-5">


                        <!-- Classification -->
                        <div class="bg-white p-6 rounded-[32px] border border-slate-100 shadow-sm">
                            <div class="flex items-center gap-3 mb-5">
                                <div class="w-10 h-10 bg-indigo-50 rounded-xl flex items-center justify-center">
                                    <span class="material-symbols-outlined text-indigo-500" style="font-size:18px">layers</span>
                                </div>
                                <h3 class="text-sm font-black text-slate-800 uppercase tracking-tight">Classification</h3>
                            </div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase ml-1 mb-1.5">Category</label>
                            <select name="category" id="categorySelect" onchange="toggleCategoryOther()"
                                class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20 cursor-pointer">
                                <?php
                                $cats = ['General','Health Advisory','Community Event','Emergency Notice','Others'];
                                $oldCat = $_SESSION['ann_old']['category'] ?? 'General';
                                foreach($cats as $c): ?>
                                <option value="<?php echo $c; ?>" <?php echo $oldCat === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
                                <?php endforeach; ?>
                            </select>

                            <!-- Shown only when "Others" is selected — still saved/classified as "Others" -->
                            <div id="categoryOtherWrap" class="hidden mt-3">
                                <label class="block text-[10px] font-bold text-slate-400 uppercase ml-1 mb-1.5">Specify Category</label>
                                <input type="text" name="category_other" id="categoryOtherInput" maxlength="100"
                                    placeholder="e.g. Infrastructure, Livelihood, Sports..."
                                    value="<?php echo htmlspecialchars($_SESSION['ann_old']['category_other'] ?? ''); ?>"
                                    class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20 placeholder:text-slate-300">
                                <p class="text-[10px] text-slate-400 mt-1.5 font-medium leading-relaxed">
                                    For reference only — the announcement stays classified as <strong>"Others"</strong> in the system.
                                </p>
                            </div>
                        </div>

                        <!-- Scheduling -->
                        <?php $schedOn = !empty($_SESSION['ann_old']['scheduling_enabled']); ?>
                        <div class="bg-white p-6 rounded-[32px] border border-slate-100 shadow-sm">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-10 h-10 bg-amber-50 rounded-xl flex items-center justify-center">
                                    <span class="material-symbols-outlined text-amber-500" style="font-size:18px">schedule</span>
                                </div>
                                <h3 class="text-sm font-black text-slate-800 uppercase tracking-tight">Scheduling</h3>
                            </div>

                            <!-- Toggle row -->
                            <div class="flex items-center justify-between p-4 bg-slate-50 rounded-xl mb-3">
                                <div>
                                    <p class="text-xs font-bold text-slate-700" id="schedToggleLabel"><?php echo $schedOn ? 'Scheduling is ON' : 'Scheduling is OFF'; ?></p>
                                    <p class="text-[10px] text-slate-400 font-medium" id="schedToggleSub"><?php echo $schedOn ? 'Publishes automatically on the date and time you set' : 'Posted automatically upon upload'; ?></p>
                                </div>
                                <label class="fb-toggle">
                                    <input type="checkbox" name="scheduling_enabled" id="schedCheckbox" value="1" <?php echo $schedOn ? 'checked' : ''; ?>>
                                    <span class="track"><span class="thumb"></span></span>
                                </label>
                            </div>

                            <!-- Scheduling fields — shown only when toggle is ON -->
                            <div id="schedFields" class="<?php echo $schedOn ? '' : 'hidden'; ?> space-y-4">
                                <p class="text-[11px] text-slate-500 font-medium leading-relaxed bg-amber-50 border border-amber-100 rounded-xl px-4 py-3">
                                    <span class="material-symbols-outlined align-middle text-amber-400" style="font-size:13px">info</span>
                                    Choose a future date and time for this announcement to go live. Leave scheduling off to post it immediately.
                                </p>
                                <p id="schedWarning" class="hidden text-[11px] font-semibold leading-relaxed bg-rose-50 border border-rose-100 text-rose-600 rounded-xl px-4 py-3"></p>
                                <div>
                                    <label class="block text-[10px] font-bold text-slate-400 uppercase ml-1 mb-1.5">
                                        Start Date & Time <span class="text-rose-400">*</span>
                                    </label>
                                    <div class="grid grid-cols-2 gap-2">
                                        <input type="date" name="date_start" id="schedDateStart"
                                            value="<?php echo htmlspecialchars($_SESSION['ann_old']['date_start'] ?? ''); ?>"
                                            class="bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20">
                                        <input type="time" name="time_start" id="schedTimeStart"
                                            value="<?php echo htmlspecialchars($_SESSION['ann_old']['time_start'] ?? '08:00'); ?>"
                                            class="bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-slate-400 uppercase ml-1 mb-1.5">
                                        End Date & Time <span class="text-slate-400 font-medium normal-case" style="font-size:0.6rem;letter-spacing:0">(optional)</span>
                                    </label>
                                    <div class="grid grid-cols-2 gap-2">
                                        <input type="date" name="date_end" id="schedDateEnd"
                                            value="<?php echo htmlspecialchars($_SESSION['ann_old']['date_end'] ?? ''); ?>"
                                            class="bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20">
                                        <input type="time" name="time_end" id="schedTimeEnd"
                                            value="<?php echo htmlspecialchars($_SESSION['ann_old']['time_end'] ?? '23:59'); ?>"
                                            class="bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-slate-700 focus:ring-2 focus:ring-primary/20">
                                    </div>
                                    <p class="text-[10px] text-slate-400 mt-1.5 font-medium">Leave blank to keep active until manually ended.</p>
                                </div>
                            </div>
                        </div>

                        <!-- ── Facebook Auto-Post ───────────────────────────────── -->
                        <div class="bg-white p-6 rounded-[32px] border border-slate-100 shadow-sm" id="fbCard">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-10 h-10 bg-blue-50 rounded-xl flex items-center justify-center">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="#1877f2">
                                        <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.312 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>
                                    </svg>
                                </div>
                                <h3 class="text-sm font-black text-slate-800 uppercase tracking-tight">Facebook Auto-Post</h3>
                            </div>

                            <!-- Toggle row -->
                            <div class="flex items-center justify-between p-4 bg-slate-50 rounded-xl mb-3" id="fbToggleRow">
                                <div>
                                    <p class="text-xs font-bold text-slate-700" id="fbToggleLabel">Post to Facebook Page</p>
                                    <p class="text-[10px] text-slate-400 font-medium" id="fbToggleSub">Auto-post immediately when announcement is saved</p>
                                </div>
                                <label class="fb-toggle">
                                    <input type="checkbox" name="post_to_facebook" id="fbCheckbox" value="1"
                                        <?php echo (($_SESSION['ann_old']['post_to_facebook'] ?? '') ? 'checked' : ''); ?>>
                                    <span class="track"><span class="thumb"></span></span>
                                </label>
                            </div>

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
                                <div id="fbPreviewImgWrap" class="hidden mt-3 rounded-lg overflow-hidden max-h-40 bg-slate-200">
                                    <img id="fbPreviewImg" src="" alt="" class="w-full object-cover max-h-40">
                                </div>
                                <p class="text-[10px] text-slate-400 mt-3">
                                    <span class="material-symbols-outlined align-middle" style="font-size:12px">info</span>
                                    Preview updates as you type. Actual post is created after saving.
                                </p>
                            </div>
                        </div>
                        <!-- ── /Facebook Auto-Post ─────────────────────────────── -->


                        <!-- Submit -->
                        <button type="submit" name="submit"
                            class="w-full flex items-center justify-center gap-2 btn-accent py-3.5 rounded-2xl text-xs font-black uppercase shadow-lg active:scale-95 transition-all">
                            <span class="material-symbols-outlined" style="font-size:18px">send</span>
                            Publish Announcement
                        </button>
                    </div>
                </div>
            </form>

        </main>
    </div>
</div>

<?php unset($_SESSION['ann_old']); ?>

<script>

    // ─── Toast Notifications ────────────────────────────────────
    // Toast — same look as Resident Management (top-right, colored, progress bar)
    function showToast(type, msg, duration = 5000) {
        if (!document.getElementById('ann-toast-style')) {
            const st = document.createElement('style');
            st.id = 'ann-toast-style';
            st.textContent = `
    #toast-container { position: fixed; top: 1.25rem; right: 1.25rem; z-index: 99999; display: flex; flex-direction: column; gap: .6rem; pointer-events: none; }
    .toast { display: flex; align-items: center; gap: .75rem; padding: .85rem 1.1rem; border-radius: 1rem; box-shadow: 0 8px 28px rgba(0,0,0,.14); font-family: 'Plus Jakarta Sans', sans-serif; font-size: .75rem; font-weight: 700; min-width: 280px; max-width: 380px; pointer-events: all; transform: translateX(110%); opacity: 0; transition: transform .3s cubic-bezier(.34,1.56,.64,1), opacity .3s ease; position: relative; overflow: hidden; }
    .toast.show { transform: translateX(0); opacity: 1; }
    .toast.hide { transform: translateX(110%); opacity: 0; }
    .toast-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
    .toast-error   { background: #fff1f2; border: 1px solid #fecaca; color: #991b1b; }
    .toast-warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
    .toast-info    { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
    .toast-icon    { font-size: 1.1rem; flex-shrink: 0; }
    .toast-msg     { flex: 1; line-height: 1.4; }
    .toast-close   { background: none; border: none; cursor: pointer; opacity: .5; padding: 0; font-size: 1rem; line-height: 1; flex-shrink: 0; color: inherit; }
    .toast-bar     { position: absolute; bottom: 0; left: 0; height: 3px; border-radius: 0 0 1rem 1rem; animation: toastProgress linear forwards; }
    .toast-success .toast-bar { background: #10b981; }
    .toast-error   .toast-bar { background: #ef4444; }
    .toast-warning .toast-bar { background: #f59e0b; }
    .toast-info    .toast-bar { background: #3b82f6; }
    @keyframes toastProgress { from { width: 100%; } to { width: 0%; } }`;
            document.head.appendChild(st);
        }
        let container = document.getElementById('toast-container');
        if (!container) { container = document.createElement('div'); container.id = 'toast-container'; document.body.appendChild(container); }
        const kind = ['success', 'error', 'warning', 'info'].includes(type) ? type : 'info';
        const icons = { success: 'check_circle', error: 'error', warning: 'warning', info: 'info' };
        const toast = document.createElement('div');
        toast.id = 'ann_toast';
        toast.className = `toast toast-${kind}`;
        toast.innerHTML = `<span class="material-symbols-outlined toast-icon">${icons[kind]}</span>
            <span class="toast-msg">${msg}</span>
            <button class="toast-close" onclick="this.parentElement.remove()">&times;</button>
            <div class="toast-bar" style="animation-duration:${duration}ms"></div>`;
        container.appendChild(toast);
        requestAnimationFrame(() => requestAnimationFrame(() => toast.classList.add('show')));
        setTimeout(() => { toast.classList.add('hide'); setTimeout(() => toast.remove(), 350); }, duration);
    }

    // ─── File handling ──────────────────────────────────────────
    let allFiles = new DataTransfer();

    function handleFiles(newFiles) {
        const MAX_SIZE = 10 * 1024 * 1024;
        Array.from(newFiles).forEach(file => {
            if (file.size > MAX_SIZE) { alert(file.name + ' exceeds 10MB limit.'); return; }
            allFiles.items.add(file);
        });
        document.getElementById('fileInput').files = allFiles.files;
        renderPreviews();
        updateFbPreview(); // refresh preview when images change
        updateAiSourceUI();
    }

    function renderPreviews() {
        const list = document.getElementById('filePreviewList');
        list.innerHTML = '';
        Array.from(allFiles.files).forEach((file, i) => {
            const isImg = file.type.startsWith('image/');
            const ext   = file.name.split('.').pop().toUpperCase();
            const size  = formatSize(file.size);
            const extIcons = { PDF:'picture_as_pdf', DOC:'description', DOCX:'description', XLS:'table_chart', XLSX:'table_chart', TXT:'text_snippet', ZIP:'folder_zip', RAR:'folder_zip' };
            const icon = isImg ? null : (extIcons[ext] || 'attach_file');

            const div = document.createElement('div');
            div.className = 'file-preview-item flex items-center gap-4 p-4 bg-slate-50 rounded-xl';
            div.innerHTML = `
                <div class="w-12 h-12 rounded-xl overflow-hidden shrink-0 ${isImg ? '' : 'bg-primary/10 flex items-center justify-center'}">
                    ${isImg
                        ? `<img src="${URL.createObjectURL(file)}" class="w-full h-full object-cover" alt="" id="thumb_${i}">`
                        : `<span class="material-symbols-outlined text-primary" style="font-size:20px">${icon}</span>`
                    }
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-slate-700 truncate">${escHtml(file.name)}</p>
                    <p class="text-[10px] text-slate-400 font-medium">${ext} · ${size}</p>
                </div>
                <button type="button" onclick="removeFile(${i})" class="p-1.5 hover:bg-rose-50 rounded-lg text-slate-300 hover:text-rose-500 transition-colors">
                    <span class="material-symbols-outlined" style="font-size:18px">close</span>
                </button>`;
            list.appendChild(div);
        });
    }

    function removeFile(index) {
        const dt = new DataTransfer();
        Array.from(allFiles.files).forEach((f, i) => { if (i !== index) dt.items.add(f); });
        allFiles = dt;
        document.getElementById('fileInput').files = allFiles.files;
        renderPreviews();
        updateFbPreview();
        updateAiSourceUI();
    }

    // ─── Drag & drop ────────────────────────────────────────────
    const dropZone = document.getElementById('dropZone');
    ['dragenter','dragover'].forEach(ev => dropZone.addEventListener(ev, e => { e.preventDefault(); dropZone.querySelector('label').classList.add('drop-active'); }));
    ['dragleave','drop'].forEach(ev => dropZone.addEventListener(ev, e => { e.preventDefault(); dropZone.querySelector('label').classList.remove('drop-active'); }));
    dropZone.addEventListener('drop', e => { handleFiles(e.dataTransfer.files); });

    // ─── Classification: "Others" category custom label ─────────
    function toggleCategoryOther() {
        const sel   = document.getElementById('categorySelect');
        const wrap  = document.getElementById('categoryOtherWrap');
        const input = document.getElementById('categoryOtherInput');
        const isOthers = sel.value === 'Others';
        wrap.classList.toggle('hidden', !isOthers);
        input.required = isOthers;
    }
    toggleCategoryOther(); // init (handles validation-error round-trips)

    // ─── Toolbar helpers ────────────────────────────────────────
    function wrapText(before, after) {
        const ta = document.getElementById('contentBody');
        const start = ta.selectionStart, end = ta.selectionEnd;
        const sel = ta.value.substring(start, end);
        ta.value = ta.value.substring(0, start) + before + sel + after + ta.value.substring(end);
        ta.focus();
        updateFbPreview();
    }
    function insertBullet() {
        const ta = document.getElementById('contentBody');
        const pos = ta.selectionStart;
        ta.value = ta.value.substring(0,pos) + '\n• ' + ta.value.substring(pos);
        ta.focus();
        updateFbPreview();
    }

    // ─── Facebook Preview ────────────────────────────────────────

    const fbCheckbox = document.getElementById('fbCheckbox');
    const fbPreviewBox = document.getElementById('fbPreviewBox');

    fbCheckbox.addEventListener('change', function () {
        fbPreviewBox.classList.toggle('hidden', !this.checked);
        if (this.checked) updateFbPreview();
    });

    // ─── Scheduling Toggle ──────────────────────────────────────
    const schedCheckbox = document.getElementById('schedCheckbox');
    const schedFields   = document.getElementById('schedFields');
    const schedLabel    = document.getElementById('schedToggleLabel');
    const schedSub      = document.getElementById('schedToggleSub');
    const schedDateStart = document.getElementById('schedDateStart');
    const schedTimeStart = document.getElementById('schedTimeStart');

    function applySchedToggle(on) {
        schedFields.classList.toggle('hidden', !on);
        const warnBox = document.getElementById('schedWarning');
        if (warnBox && !on) warnBox.classList.add('hidden'); // clear warning when scheduling is off
        schedLabel.textContent = on ? 'Scheduling is ON' : 'Scheduling is OFF';
        schedSub.textContent   = on ? 'Publishes automatically on the date and time you set'
                                    : 'Posted automatically upon upload';
        // required attribute only active when fields are visible
        schedDateStart.required = on;
        schedTimeStart.required = on;
        // Keep Facebook toggle subtitle in sync with scheduling state
        const fbSub = document.getElementById('fbToggleSub');
        if (fbSub) {
            fbSub.textContent = on
                ? 'Will post to Facebook automatically at the scheduled time'
                : 'Auto-post immediately when announcement is saved';
        }
        updateFbPreview();
    }

    schedCheckbox.addEventListener('change', function() { applySchedToggle(this.checked); });
    // Init on load
    applySchedToggle(schedCheckbox.checked);

    // ─── Date & Time Validation (only when scheduling is ON) ────
    // Local date string (do NOT use toISOString — that is UTC and is a day
    // behind for PH time in the evening).
    function localDateStr(d) {
        const p = n => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
    }
    const todayStr = localDateStr(new Date());

    const dateStartInput = document.getElementById('schedDateStart');
    const timeStartInput = document.getElementById('schedTimeStart');
    const dateEndInput   = document.getElementById('schedDateEnd');
    const timeEndInput   = document.getElementById('schedTimeEnd');

    // Can't even pick a past day in the date picker
    if (dateStartInput) dateStartInput.min = todayStr;

    function toDateTime(dateVal, timeVal, fallbackTime) {
        if (!dateVal) return null;
        const t = timeVal || fallbackTime || '00:00';
        const dt = new Date(`${dateVal}T${t}`);
        return isNaN(dt.getTime()) ? null : dt;
    }

    // Returns an error message, or '' when the schedule is valid
    function validateSchedule() {
        if (!schedCheckbox.checked) return '';

        const startDT = toDateTime(dateStartInput.value, timeStartInput.value, '00:00');
        if (!startDT) return 'Please set the Start Date & Time before publishing.';

        // 1-minute grace so a click at the exact minute isn't rejected
        if (startDT.getTime() < Date.now() - 60000) {
            return 'The Start Date & Time has already passed. Please choose a future date and time.';
        }

        const endDT = toDateTime(dateEndInput.value, timeEndInput.value, '23:59');
        if (endDT && endDT <= startDT) {
            return 'The End Date & Time must be later than the Start Date & Time.';
        }
        return '';
    }

    // Live feedback — warn as soon as a past date/time is picked
    [dateStartInput, timeStartInput, dateEndInput, timeEndInput].forEach(el => {
        if (!el) return;
        el.addEventListener('change', function () {
            const msg = validateSchedule();
            const box = document.getElementById('schedWarning');
            if (box) {
                box.textContent = msg;
                box.classList.toggle('hidden', msg === '');
            }
        });
    });

    // Block submit on an invalid schedule
    document.getElementById('annForm').addEventListener('submit', function(e) {
        const msg = validateSchedule();
        if (msg) {
            e.preventDefault();
            showFormError(msg);
            if (typeof showToast === 'function') showToast('error', msg);
        }
    });

    function showFormError(msg) {
        let el = document.getElementById('form_error_banner');
        if (!el) {
            el = document.createElement('div');
            el.id = 'form_error_banner';
            el.className = 'px-5 py-4 bg-rose-50 border border-rose-100 rounded-2xl text-rose-700 text-sm font-semibold flex items-center gap-3 mb-4';
            el.innerHTML = '<span class="material-symbols-outlined text-rose-500">error</span><span id="form_error_text"></span>';
            document.querySelector('main').insertBefore(el, document.querySelector('main').firstChild);
        }
        document.getElementById('form_error_text').textContent = msg;
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(() => el.remove(), 6000);
    }

    function updateFbPreview() {
        if (!fbCheckbox.checked) return;

        const title    = (document.querySelector('[name="title"]').value || '').trim();
        const details  = (document.getElementById('contentBody').value || '').trim();
        const category = (document.querySelector('[name="category"]').value || 'GENERAL').toUpperCase();
        const startRaw = schedCheckbox && schedCheckbox.checked && document.getElementById('schedDateStart')
            ? document.getElementById('schedDateStart').value : '';
        const endRaw   = schedCheckbox && schedCheckbox.checked && document.getElementById('schedDateEnd')
            ? document.getElementById('schedDateEnd').value : '';

        let dateLine = '';
        if (startRaw) {
            const start = formatDatePreview(startRaw);
            const end   = endRaw ? formatDatePreview(endRaw) : null;
            dateLine = (end && end !== start)
                ? `📅 Valid: ${start} – ${end}`
                : `📅 From: ${start}`;
        }

        const parts = [`📣 [${category}] ${title || '(untitled)'}`, '', details || '(no content)'];
        if (dateLine) { parts.push('', dateLine); }
        parts.push('', '—', 'Barangay Biñang 2nd Official Announcement', '#BarangayBinang2nd #OfficialAnnouncement');

        document.getElementById('fbPreviewText').textContent = parts.join('\n');

        // Image preview — pick first image file if any
        const imgFile = Array.from(allFiles.files).find(f => f.type.startsWith('image/'));
        const imgWrap = document.getElementById('fbPreviewImgWrap');
        const imgEl   = document.getElementById('fbPreviewImg');
        if (imgFile) {
            imgEl.src = URL.createObjectURL(imgFile);
            imgWrap.classList.remove('hidden');
        } else {
            imgWrap.classList.add('hidden');
        }
    }

    function formatDatePreview(str) {
        if (!str) return '';
        const [y, m, d] = str.split('-');
        const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return `${months[+m-1]} ${+d}, ${y}`;
    }

    // Live-update preview on typing
    ['title', 'category'].forEach(name => {
        const el = document.querySelector(`[name="${name}"]`);
        if (el) el.addEventListener('input', updateFbPreview);
        if (el) el.addEventListener('change', updateFbPreview);
    });
    ['schedDateStart', 'schedDateEnd'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', updateFbPreview);
        if (el) el.addEventListener('change', updateFbPreview);
    });
    document.getElementById('contentBody').addEventListener('input', updateFbPreview);

    // Init
    if (fbCheckbox.checked) { fbPreviewBox.classList.remove('hidden'); updateFbPreview(); }

    // ─── AI Content Generation (Google Gemini, free tier) ────────

    // Files the AI can actually read (images + PDF)
    function aiReadableFiles() {
        return Array.from(allFiles.files)
            .filter(f => f.type.startsWith('image/') || f.type === 'application/pdf')
            .slice(0, 3);
    }

    // Show/hide the AI panel. Pass true/false to force a state.
    function toggleAiPanel(force) {
        const panel   = document.getElementById('aiPanel');
        const btn     = document.getElementById('aiToggleBtn');
        const chevron = document.getElementById('aiToggleChevron');
        const open    = (typeof force === 'boolean') ? force : panel.classList.contains('hidden');

        panel.classList.toggle('hidden', !open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (chevron) chevron.classList.toggle('rotate-180', open);

        if (open) {
            updateAiSourceUI();
            document.getElementById('aiPromptInput').focus();
        }
    }

    // Enable/disable the "Prompt + attachment" choice based on what is attached
    function updateAiSourceUI() {
        const mediaRadio = document.getElementById('aiSourceMedia');
        const hint       = document.getElementById('aiMediaHint');
        const wrap       = document.getElementById('aiMediaOptionWrap');
        if (!mediaRadio) return;

        const files   = aiReadableFiles();
        const enabled = files.length > 0;

        mediaRadio.disabled = !enabled;
        wrap.classList.toggle('cursor-not-allowed', !enabled);
        wrap.classList.toggle('cursor-pointer', enabled);

        if (!enabled) {
            // Fall back to prompt-only if the attachment was removed
            if (mediaRadio.checked) {
                document.querySelector('[name="ai_source"][value="prompt"]').checked = true;
            }
            hint.textContent = 'No image or PDF attached yet.';
        } else {
            hint.textContent = files.length === 1
                ? 'The AI will also read 1 attachment.'
                : `The AI will also read ${files.length} attachments.`;
        }
    }
    updateAiSourceUI(); // init — no attachments yet on a fresh form

    async function generateAiCaption() {
        const btn   = document.getElementById('aiGenerateBtn');
        const icon  = document.getElementById('aiGenerateIcon');
        const label = document.getElementById('aiGenerateLabel');
        const ta    = document.getElementById('contentBody');

        const useMedia    = document.querySelector('[name="ai_source"]:checked')?.value === 'prompt_media';
        const imageFiles  = useMedia ? aiReadableFiles() : [];
        const promptInput = document.getElementById('aiPromptInput');
        const promptVal   = (promptInput?.value || '').trim();

        if (promptVal === '' && imageFiles.length === 0) {
            showToast('warning', 'Please describe what the announcement is about in the Prompt field first.');
            promptInput?.focus();
            return;
        }

        if (ta.value.trim() !== '' && !confirm('This will replace the current Content Body with AI-generated content. Continue?')) {
            return;
        }

        btn.disabled = true;
        btn.classList.add('opacity-70', 'cursor-not-allowed');
        icon.textContent = 'progress_activity';
        icon.classList.add('animate-spin');
        label.textContent = 'Generating…';

        try {
            const fd = new FormData();
            fd.append('csrf_token', document.querySelector('[name="csrf_token"]').value);
            fd.append('title', document.querySelector('[name="title"]').value || '');
            fd.append('category', document.querySelector('[name="category"]').value || '');
            fd.append('prompt', promptVal);
            imageFiles.forEach(f => fd.append('images[]', f));

            const res  = await fetch('../backend/generate_caption.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.success && data.caption) {
                ta.value = data.caption;
                updateFbPreview();
                toggleAiPanel(false);
                showToast('success', 'Content generated. You can still edit it before publishing.');
            } else {
                showToast('error', data.error || 'Could not generate the content. Please try again.');
            }
        } catch (err) {
            showToast('error', 'Something went wrong while connecting to the AI service.');
        } finally {
            btn.disabled = false;
            btn.classList.remove('opacity-70', 'cursor-not-allowed');
            icon.textContent = 'auto_awesome';
            icon.classList.remove('animate-spin');
            label.textContent = 'Generate';
        }
    }

    // ─── Utilities ──────────────────────────────────────────────
    function formatSize(b) {
        if (b < 1024) return b+'B';
        if (b < 1024*1024) return (b/1024).toFixed(1)+'KB';
        return (b/1024/1024).toFixed(1)+'MB';
    }
    function escHtml(s) {
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    // ─── Draft Autosave (localStorage) ───────────────────────────
    // Keeps whatever the admin has typed even through an accidental
    // refresh, browser crash, or a dropped connection — everything here
    // stays on this device/browser only, no internet required.
    const DRAFT_KEY = 'caps_new_announcement_draft_v1';

    function draftFields() {
        return {
            title:              document.querySelector('[name="title"]'),
            details:            document.getElementById('contentBody'),
            category:           document.getElementById('categorySelect'),
            category_other:     document.getElementById('categoryOtherInput'),
            scheduling_enabled: document.getElementById('schedCheckbox'),
            date_start:         document.getElementById('schedDateStart'),
            time_start:         document.getElementById('schedTimeStart'),
            date_end:           document.getElementById('schedDateEnd'),
            time_end:           document.getElementById('schedTimeEnd'),
            post_to_facebook:   document.getElementById('fbCheckbox'),
        };
    }

    function debounce(fn, wait) {
        let t;
        return function (...args) {
            clearTimeout(t);
            t = setTimeout(() => fn.apply(this, args), wait);
        };
    }

    function isDraftBlank(d) {
        return !d.title && !d.details && !d.category_other
            && !d.date_start && !d.date_end;
    }

    function saveDraft() {
        const f = draftFields();
        const data = {
            title:              f.title ? f.title.value : '',
            details:            f.details ? f.details.value : '',
            category:           f.category ? f.category.value : '',
            category_other:     f.category_other ? f.category_other.value : '',
            scheduling_enabled: f.scheduling_enabled ? f.scheduling_enabled.checked : false,
            date_start:         f.date_start ? f.date_start.value : '',
            time_start:         f.time_start ? f.time_start.value : '',
            date_end:           f.date_end ? f.date_end.value : '',
            time_end:           f.time_end ? f.time_end.value : '',
            post_to_facebook:   f.post_to_facebook ? f.post_to_facebook.checked : false,
            savedAt:            Date.now(),
        };
        if (isDraftBlank(data)) {
            localStorage.removeItem(DRAFT_KEY); // nothing worth keeping
            setDraftStatus('');
            return;
        }
        try {
            localStorage.setItem(DRAFT_KEY, JSON.stringify(data));
            setDraftStatus('Draft saved on this device · ' + new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' }));
        } catch (e) {
            // localStorage full/unavailable — fail silently, not worth alarming the admin
        }
    }

    function setDraftStatus(text) {
        const el = document.getElementById('draftStatus');
        if (!el) return;
        el.innerHTML = text
            ? '<span class="material-symbols-outlined" style="font-size:12px">cloud_done</span>' + escHtml(text)
            : '';
    }

    function applyDraft(data) {
        const f = draftFields();
        if (f.title && data.title) f.title.value = data.title;
        if (f.details && data.details) f.details.value = data.details;
        if (f.category && data.category) f.category.value = data.category;
        if (f.category_other && data.category_other) f.category_other.value = data.category_other;
        if (f.scheduling_enabled) f.scheduling_enabled.checked = !!data.scheduling_enabled;
        if (f.date_start && data.date_start) f.date_start.value = data.date_start;
        if (f.time_start && data.time_start) f.time_start.value = data.time_start;
        if (f.date_end && data.date_end) f.date_end.value = data.date_end;
        if (f.time_end && data.time_end) f.time_end.value = data.time_end;
        if (f.post_to_facebook) f.post_to_facebook.checked = !!data.post_to_facebook;

        // Re-run the UI logic that depends on these fields so everything
        // (category-other box, schedule fields, FB preview, AI panel) matches.
        if (typeof toggleCategoryOther === 'function') toggleCategoryOther();
        if (typeof applySchedToggle === 'function') applySchedToggle(f.scheduling_enabled ? f.scheduling_enabled.checked : false);
        if (f.post_to_facebook && f.post_to_facebook.checked && typeof updateFbPreview === 'function') {
            document.getElementById('fbPreviewBox')?.classList.remove('hidden');
            updateFbPreview();
        }
    }

    function discardDraft() {
        localStorage.removeItem(DRAFT_KEY);
        document.getElementById('draftRestoreBanner')?.classList.add('hidden');
        setDraftStatus('');
        // Clear the fields the draft would have filled — attachments were
        // never part of the draft, so nothing to touch there.
        const f = draftFields();
        if (f.title) f.title.value = '';
        if (f.details) f.details.value = '';
        if (f.category_other) f.category_other.value = '';
        if (f.date_start) f.date_start.value = '';
        if (f.time_start) f.time_start.value = '08:00';
        if (f.date_end) f.date_end.value = '';
        if (f.time_end) f.time_end.value = '23:59';
        if (f.scheduling_enabled) { f.scheduling_enabled.checked = false; applySchedToggle(false); }
        toggleCategoryOther();
    }

    (function initDraft() {
        const f = draftFields();
        // If the server already re-populated the form (a validation-error
        // round trip via $_SESSION['ann_old']), that data is more current
        // than anything sitting in localStorage — don't clobber it.
        const serverHasData = (f.title && f.title.value.trim() !== '')
            || (f.details && f.details.value.trim() !== '');

        if (!serverHasData) {
            let stored = null;
            try { stored = JSON.parse(localStorage.getItem(DRAFT_KEY) || 'null'); } catch (e) { stored = null; }

            if (stored && !isDraftBlank(stored)) {
                applyDraft(stored);
                const banner = document.getElementById('draftRestoreBanner');
                const meta   = document.getElementById('draftRestoreMeta');
                if (banner && meta) {
                    const when = new Date(stored.savedAt || Date.now());
                    meta.textContent = 'Last edited ' + when.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' }) + '.';
                    banner.classList.remove('hidden');
                }
                setDraftStatus('Draft saved on this device · ' + new Date(stored.savedAt || Date.now()).toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' }));
            }
        } else {
            // Server-rendered values are the source of truth right now —
            // sync localStorage to match so a future refresh stays consistent.
            saveDraft();
        }

        // Wire up autosave on every field that matters. Text inputs debounce
        // so we're not hammering localStorage on every keystroke; everything
        // else saves immediately on change.
        const debouncedSave = debounce(saveDraft, 600);
        [f.title, f.details, f.category_other].forEach(el => {
            if (el) el.addEventListener('input', debouncedSave);
        });
        [f.category, f.scheduling_enabled, f.date_start, f.time_start, f.date_end, f.time_end, f.post_to_facebook].forEach(el => {
            if (el) el.addEventListener('change', saveDraft);
        });
    })();

    // Draft has served its purpose once the form is actually being submitted —
    // clear it so the admin doesn't see a stale "restored" banner on a fresh
    // Create New Announcement visit after this one goes through. Runs after
    // the schedule-validation listener above, so e.defaultPrevented is
    // already set if that check blocked the submit — don't wipe the draft
    // in that case, the admin still needs it.
    document.getElementById('annForm').addEventListener('submit', function (e) {
        if (!e.defaultPrevented) {
            localStorage.removeItem(DRAFT_KEY);
        }
    });
</script>

</body>
</html>
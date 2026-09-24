<?php
require_once __DIR__ . '/../backend/settings.php';
// CSRF token for the Facebook connection form (Settings → Facebook)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// Facebook App ID shown in the setup steps (public value — the secret is never shown)
$fb_app_id = '—';
$fb_config_file = __DIR__ . '/../../announcement/backend/fb_config.php';
if (is_file($fb_config_file)) {
    if (!defined('SOE_LIB_INCLUDE')) define('SOE_LIB_INCLUDE', true);
    require_once $fb_config_file;
    if (defined('FB_APP_ID')) $fb_app_id = FB_APP_ID;
}
?>
<!DOCTYPE html>
<html <?php echo $theme_attrs['html']; ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — Admin Portal</title>

    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] } } }
        }
    </script>

    <style>
        /* ── Layout ──────────────────────────────────────────────────────────── */
        body { font-family: 'Inter', sans-serif; }
        .main-wrapper { margin-left: 288px; width: calc(100% - 288px); }
        @media (max-width: 1023px) { .main-wrapper { margin-left: 0; width: 100%; } }

        /* ── Entrance animations ─────────────────────────────────────────────── */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .fade-up { animation: fadeInUp 0.4s ease both; }
        .d1 { animation-delay: 0.05s; }
        .d2 { animation-delay: 0.10s; }
        .d3 { animation-delay: 0.15s; }
        .d4 { animation-delay: 0.20s; }
        .d5 { animation-delay: 0.25s; }
        .no-anim * { animation: none !important; transition: none !important; }

        /* ── Settings card ───────────────────────────────────────────────────── */
        .settings-card {
            background: white;
            border: 1px solid #f1f5f9;
            border-radius: 1rem;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
            transition: box-shadow 0.2s ease;
        }
        .settings-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.07); }

        /* ── Toggle switch ───────────────────────────────────────────────────── */
        .toggle-track {
            width: 44px; height: 24px;
            border-radius: 50px;
            background: #e2e8f0;
            position: relative;
            cursor: pointer;
            transition: background 0.25s ease;
            flex-shrink: 0;
        }
        .toggle-track.on { background: var(--accent-500, #6366f1); }
        .toggle-knob {
            width: 18px; height: 18px;
            border-radius: 50%;
            background: white;
            position: absolute;
            top: 3px; left: 3px;
            transition: transform 0.25s ease;
            box-shadow: 0 1px 4px rgba(0,0,0,0.18);
        }
        .toggle-track.on .toggle-knob { transform: translateX(20px); }

        /* ── Color picker ────────────────────────────────────────────────────── */
        .color-picker-wrapper {
            display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
        }
        .color-picker-swatch {
            width: 56px; height: 56px; border-radius: 14px; border: none;
            padding: 4px; cursor: pointer; background: transparent;
            outline: 3px solid transparent; outline-offset: 3px;
            transition: outline-color 0.2s ease, transform 0.15s ease, box-shadow 0.2s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.14);
        }
        .color-picker-swatch:hover { transform: scale(1.06); box-shadow: 0 6px 20px rgba(0,0,0,0.18); }
        .color-picker-swatch:focus { outline-color: var(--accent-400); }
        .color-picker-swatch::-webkit-color-swatch-wrapper { padding: 0; border-radius: 10px; }
        .color-picker-swatch::-webkit-color-swatch { border: none; border-radius: 10px; }
        .color-picker-swatch::-moz-color-swatch { border: none; border-radius: 10px; }
        .color-picker-meta { display: flex; flex-direction: column; gap: 6px; }
        .color-picker-hex-input {
            font-family: 'Courier New', monospace;
            font-size: 12px; font-weight: 700; letter-spacing: 0.08em;
            width: 108px; padding: 6px 10px;
            border: 1.5px solid #e2e8f0; border-radius: 8px;
            color: #334155; background: #f8fafc; outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            text-transform: uppercase;
        }
        .color-picker-hex-input:focus {
            border-color: var(--accent-400); box-shadow: 0 0 0 3px var(--accent-50);
        }
        html.dark .color-picker-hex-input {
            background: #1e293b; color: #e2e8f0; border-color: #334155;
        }
        .quick-color-btn {
            width: 24px; height: 24px; border-radius: 50%;
            border: 2.5px solid transparent; cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            box-shadow: 0 1px 4px rgba(0,0,0,0.14); flex-shrink: 0;
        }
        .quick-color-btn:hover { transform: scale(1.22); box-shadow: 0 3px 10px rgba(0,0,0,0.22); }
        .quick-color-btn.active { box-shadow: 0 0 0 2px white, 0 0 0 4px currentColor; }
        html.dark .quick-color-btn.active { box-shadow: 0 0 0 2px #1e293b, 0 0 0 4px currentColor; }

        /* ── Section tab nav ─────────────────────────────────────────────────── */
        .tab-btn {
            display: flex; align-items: center; gap: 8px;
            padding: 10px 16px; border-radius: 10px;
            font-size: 13px; font-weight: 500;
            cursor: pointer; width: 100%; text-align: left;
            color: #64748b; transition: background 0.15s ease, color 0.15s ease;
            border: none; background: none;
        }
        .tab-btn:hover { background: #f8fafc; color: #1e293b; }
        .tab-btn.active { background: #eef2ff; color: #4f46e5; font-weight: 600; }
        .tab-btn .material-symbols-outlined { font-size: 18px; }
        .tab-btn-link {
            display: flex; align-items: center; gap: 8px;
            padding: 10px 16px; border-radius: 10px;
            font-size: 13px; font-weight: 500;
            color: #64748b; transition: background 0.15s ease, color 0.15s ease;
            text-decoration: none;
        }
        .tab-btn-link:hover { background: #f8fafc; color: #1e293b; }
        .tab-btn-link.active { background: #eef2ff; color: #4f46e5; font-weight: 600; }
        .tab-btn-link .material-symbols-outlined { font-size: 18px; }
        html.dark .tab-btn-link:hover { background: #1e293b; color: #e2e8f0; }
        html.dark .tab-btn-link.active { background: #1e1b4b; color: #818cf8; }

        /* ── Density options ─────────────────────────────────────────────────── */
        .density-option {
            border: 2px solid #e2e8f0; border-radius: 12px;
            padding: 14px 12px; cursor: pointer; text-align: center;
            transition: all 0.2s ease;
        }
        .density-option:hover { border-color: #c7d2fe; background: #eef2ff; }
        .density-option.active { border-color: #6366f1; background: #eef2ff; }
        html.dark .density-option { border-color: #334155; }
        html.dark .density-option.active { border-color: #818cf8; background: #1e1b4b; }

        /* ── Font size cards ─────────────────────────────────────────────────── */
        .font-size-option {
            flex: 1; border: 2px solid #e2e8f0; border-radius: 12px;
            padding: 12px 8px; cursor: pointer; text-align: center;
            transition: all 0.2s ease; color: #64748b;
        }
        .font-size-option:hover { border-color: #c7d2fe; }

        /* ── Dark mode ───────────────────────────────────────────────────────── */
        html.dark .settings-card { background: #1e293b; border-color: #334155; }
        html.dark body { background: #0f172a; color: #e2e8f0; }
        html.dark .text-slate-800 { color: #f1f5f9; }
        html.dark .text-slate-700 { color: #e2e8f0; }
        html.dark .text-slate-600 { color: #cbd5e1; }
        html.dark .text-slate-500 { color: #94a3b8; }
        html.dark .text-slate-400 { color: #64748b; }
        html.dark .bg-slate-50    { background: #1e293b; }
        html.dark .bg-white       { background: #1e293b; }
        html.dark .border-slate-100 { border-color: #334155; }
        html.dark .border-slate-200 { border-color: #334155; }
        html.dark input, html.dark select, html.dark textarea {
            background: #0f172a !important;
            color: #e2e8f0 !important;
            border-color: #334155 !important;
        }
        html.dark .tab-btn:hover { background: #1e293b; color: #e2e8f0; }
        html.dark .tab-btn.active { background: #1e1b4b; color: #818cf8; }
        html.dark .density-option { color: #94a3b8; }
        html.dark .font-size-option { border-color: #334155; color: #94a3b8; }

        /* ── Transitions ─────────────────────────────────────────────────────── */
        body, .settings-card, aside, header, input, select {
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
        }

        /* ── Toast (residents.php style) ────────────────────────────────────── */
        #toast-container { position: fixed; top: 1.25rem; right: 1.25rem; z-index: 99999; display: flex; flex-direction: column; gap: .6rem; pointer-events: none; }
        .toast { display: flex; align-items: center; gap: .75rem; padding: .85rem 1.1rem; border-radius: 1rem; box-shadow: 0 8px 28px rgba(0,0,0,.14); font-family: 'Inter', sans-serif; font-size: .75rem; font-weight: 700; min-width: 280px; max-width: 380px; pointer-events: all; transform: translateX(110%); opacity: 0; transition: transform .3s cubic-bezier(.34,1.56,.64,1), opacity .3s ease; }
        .toast.show { transform: translateX(0); opacity: 1; }
        .toast.hide { transform: translateX(110%); opacity: 0; }
        .toast-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .toast-error   { background: #fff1f2; border: 1px solid #fecaca; color: #991b1b; }
        .toast-warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .toast-info    { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
        .toast-icon    { font-size: 1.1rem; flex-shrink: 0; }
        .toast-msg     { flex: 1; line-height: 1.4; }
        .toast-close   { background: none; border: none; cursor: pointer; opacity: .5; padding: 0; font-size: 1rem; line-height: 1; flex-shrink: 0; color: inherit; }
        .toast-close:hover { opacity: 1; }
        .toast-bar     { position: absolute; bottom: 0; left: 0; height: 3px; border-radius: 0 0 1rem 1rem; animation: toastProgress linear forwards; }
        .toast-success .toast-bar { background: #10b981; }
        .toast-error   .toast-bar { background: #ef4444; }
        .toast-warning .toast-bar { background: #f59e0b; }
        .toast-info    .toast-bar { background: #3b82f6; }
        @keyframes toastProgress { from { width: 100%; } to { width: 0%; } }

        /* ── Password strength bar ───────────────────────────────────────────── */
        .strength-bar { height: 4px; border-radius: 4px; transition: width 0.3s ease, background 0.3s ease; }

        /* ── Custom scrollbar ────────────────────────────────────────────────── */
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #cbd5e1; }

        /* ── Role badge ──────────────────────────────────────────────────────── */
        .role-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.06em;
        }
        .role-badge.admin { background: #fef3c7; color: #92400e; }
        .role-badge.staff { background: #e0f2fe; color: #0c4a6e; }
    </style>
    <?php include __DIR__ . '/../../theme_head.php'; ?>
</head>

<body <?php echo $theme_attrs['body']; ?> style="background-color:#f8fafc;">
<div class="flex min-h-screen">

    <?php include __DIR__ . '/../../sidebar.php'; ?>

    <div class="flex-1 flex flex-col main-wrapper min-h-screen">

        <?php include __DIR__ . '/../../header.php'; ?>

        <main class="flex-1 p-4 sm:p-6 lg:p-8">

            <!-- ── Page heading ────────────────────────────────────────────── -->
            <div class="mb-6 fade-up">
                <h1 class="text-2xl font-bold text-slate-800 tracking-tight">Settings</h1>
                <p class="text-slate-500 text-sm mt-1 font-medium">
                    Manage your account preferences and personalize your admin experience.
                </p>
            </div>

            <!-- ── Main layout ─────────────────────────────────────────────── -->
            <div class="flex flex-col lg:flex-row gap-6">

                <!-- ═══ Left: Tab navigation ══════════════════════════════════ -->
                <div class="w-full lg:w-56 shrink-0 fade-up d1">
                    <div class="settings-card p-3 space-y-0.5">
                        <button class="tab-btn active" data-tab="appearance" onclick="switchTab('appearance', this)">
                            <span class="material-symbols-outlined">palette</span> Appearance
                        </button>
                        <button class="tab-btn" data-tab="preferences" onclick="switchTab('preferences', this)">
                            <span class="material-symbols-outlined">tune</span> Preferences
                        </button>
                        <button class="tab-btn" data-tab="security" onclick="switchTab('security', this)">
                            <span class="material-symbols-outlined">shield</span> Security
                        </button>
                        <?php if ($admin_role === 'admin'): ?>
                        <button class="tab-btn" data-tab="sms" onclick="switchTab('sms', this); loadSmsConfigs();">
                            <span class="material-symbols-outlined">sms</span> SMS Configuration
                        </button>
                        <?php endif; ?>
                        <?php if ($admin_role === 'admin'): ?>
                        <button class="tab-btn" data-tab="facebook" onclick="switchTab('facebook', this); loadFbStatus();">
                            <span class="material-symbols-outlined">share</span> Facebook
                        </button>
                        <?php endif; ?>
                        <button class="tab-btn" data-tab="system" onclick="switchTab('system', this)">
                            <span class="material-symbols-outlined">settings_applications</span> System
                        </button>
                        
                    </div>
                </div>

                <!-- ═══ Right: Tab panels ══════════════════════════════════════ -->
                <div class="flex-1 min-w-0 space-y-5">

                    <!-- ════════════════════════════════════════════════════════
                         TAB: APPEARANCE
                    ════════════════════════════════════════════════════════ -->
                    <div id="tab-appearance" class="tab-panel fade-up d2">
                    <form method="POST" action="" id="form-appearance" onsubmit="submitPrefsAjax(this, this.querySelector('[type=submit]')); return false;">
                    <input type="hidden" name="action" value="save_prefs">

                        <!-- Color Mode -->
                        <div class="settings-card p-6 mb-5">
                            <div class="flex items-center gap-2 mb-5">
                                <span class="material-symbols-outlined text-indigo-500 text-[20px]">contrast</span>
                                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider">Color Mode</h2>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <!-- Light -->
                                <label class="relative cursor-pointer rounded-xl border-2 p-4 transition-all duration-200
                                    <?php echo $prefs['theme_mode'] === 'light'
                                        ? 'border-indigo-500 bg-indigo-50'
                                        : 'border-slate-200 hover:border-slate-300 bg-white'; ?>">
                                    <input type="radio" name="theme_mode" value="light" class="sr-only"
                                           <?php echo $prefs['theme_mode'] === 'light' ? 'checked' : ''; ?>
                                           onchange="setThemeMode('light')">
                                    <div class="flex items-center gap-3 mb-3">
                                        <div class="w-9 h-9 rounded-xl bg-white border border-slate-200
                                                    flex items-center justify-center shadow-sm">
                                            <span class="material-symbols-outlined text-amber-400 text-[20px]">light_mode</span>
                                        </div>
                                        <div>
                                            <p class="text-sm font-bold text-slate-800">Light</p>
                                            <p class="text-[11px] text-slate-400">Classic bright mode</p>
                                        </div>
                                        <?php if ($prefs['theme_mode'] === 'light'): ?>
                                        <span class="ml-auto material-symbols-outlined text-indigo-600 text-[18px]">check_circle</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="rounded-lg bg-slate-100 p-2 space-y-1.5">
                                        <div class="h-2 bg-white rounded w-3/4"></div>
                                        <div class="h-2 bg-white rounded w-1/2"></div>
                                        <div class="h-2 bg-indigo-200 rounded w-2/3"></div>
                                    </div>
                                </label>
                                <!-- Dark -->
                                <label class="relative cursor-pointer rounded-xl border-2 p-4 transition-all duration-200
                                    <?php echo $prefs['theme_mode'] === 'dark'
                                        ? 'border-indigo-500 bg-indigo-50'
                                        : 'border-slate-200 hover:border-slate-300 bg-white'; ?>">
                                    <input type="radio" name="theme_mode" value="dark" class="sr-only"
                                           <?php echo $prefs['theme_mode'] === 'dark' ? 'checked' : ''; ?>
                                           onchange="setThemeMode('dark')">
                                    <div class="flex items-center gap-3 mb-3">
                                        <div class="w-9 h-9 rounded-xl bg-slate-800 border border-slate-700
                                                    flex items-center justify-center shadow-sm">
                                            <span class="material-symbols-outlined text-indigo-400 text-[20px]">dark_mode</span>
                                        </div>
                                        <div>
                                            <p class="text-sm font-bold text-slate-800">Dark</p>
                                            <p class="text-[11px] text-slate-400">Easy on the eyes</p>
                                        </div>
                                        <?php if ($prefs['theme_mode'] === 'dark'): ?>
                                        <span class="ml-auto material-symbols-outlined text-indigo-600 text-[18px]">check_circle</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="rounded-lg bg-slate-800 p-2 space-y-1.5">
                                        <div class="h-2 bg-slate-700 rounded w-3/4"></div>
                                        <div class="h-2 bg-slate-700 rounded w-1/2"></div>
                                        <div class="h-2 bg-indigo-700 rounded w-2/3"></div>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Accent Color -->
                        <div class="settings-card p-6 mb-5">
                            <div class="flex items-center gap-2 mb-5">
                                <span class="material-symbols-outlined text-indigo-500 text-[20px]">colorize</span>
                                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider">Accent Color</h2>
                            </div>
                            <div class="color-picker-wrapper mb-4">
                                <input type="color" id="accentColorPicker" name="accent_color"
                                       value="<?php echo htmlspecialchars($prefs['accent_color']); ?>"
                                       class="color-picker-swatch"
                                       oninput="onColorPickerChange(this.value)"
                                       onchange="onColorPickerCommit(this.value)">
                                <div class="color-picker-meta">
                                    <span class="text-xs font-semibold text-slate-500">Hex code</span>
                                    <input type="text" id="accentHexInput"
                                           value="<?php echo strtoupper(htmlspecialchars($prefs['accent_color'])); ?>"
                                           class="color-picker-hex-input"
                                           maxlength="7"
                                           oninput="onHexInputChange(this.value)">
                                    <span id="accentLabel" class="text-xs font-bold"
                                          style="color:<?php echo htmlspecialchars($prefs['accent_color']); ?>">
                                        <?php echo strtoupper(htmlspecialchars($prefs['accent_color'])); ?>
                                    </span>
                                </div>
                            </div>
                            <!-- Quick presets -->
                            <p class="text-xs text-slate-400 font-medium mb-2">Quick presets</p>
                            <div class="flex flex-wrap gap-3">
                                <?php
                                $swatches = [
                                    '#6366f1' => 'Indigo',   '#8b5cf6' => 'Violet',
                                    '#ec4899' => 'Pink',     '#ef4444' => 'Red',
                                    '#f97316' => 'Orange',   '#eab308' => 'Yellow',
                                    '#10b981' => 'Emerald',  '#14b8a6' => 'Teal',
                                    '#3b82f6' => 'Blue',     '#06b6d4' => 'Cyan',
                                    '#64748b' => 'Slate',    '#0f172a' => 'Dark',
                                ];
                                foreach ($swatches as $hex => $label):
                                    $isActive = strtolower($prefs['accent_color']) === strtolower($hex);
                                ?>
                                <button type="button" class="quick-color-btn <?php echo $isActive ? 'active' : ''; ?>"
                                        title="<?php echo $label; ?>"
                                        data-hex="<?php echo $hex; ?>"
                                        style="background:<?php echo $hex; ?>; color:<?php echo $hex; ?>"
                                        onclick="applyQuickPreset('<?php echo $hex; ?>', this)">
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Font Size -->
                        <div class="settings-card p-6 mb-5">
                            <div class="flex items-center gap-2 mb-5">
                                <span class="material-symbols-outlined text-indigo-500 text-[20px]">format_size</span>
                                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider">Font Size</h2>
                            </div>
                            <div class="flex gap-3">
                                <?php foreach (['sm' => ['Small','text-xs'], 'base' => ['Medium','text-sm'], 'lg' => ['Large','text-base']] as $sz => [$label, $cls]): ?>
                                <label class="font-size-option <?php echo $prefs['font_size'] === $sz ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : ''; ?>">
                                    <input type="radio" name="font_size" value="<?php echo $sz; ?>" class="sr-only"
                                           <?php echo $prefs['font_size'] === $sz ? 'checked' : ''; ?>
                                           onchange="setFontSize('<?php echo $sz; ?>')">
                                    <p class="<?php echo $cls; ?> font-bold text-current">Aa</p>
                                    <p class="text-[11px] mt-1"><?php echo $label; ?></p>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- UI Density (admin extra) -->
                        <div class="settings-card p-6 mb-5">
                            <div class="flex items-center gap-2 mb-5">
                                <span class="material-symbols-outlined text-indigo-500 text-[20px]">density_medium</span>
                                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider">UI Density</h2>
                            </div>
                            <div class="grid grid-cols-3 gap-3">
                                <?php foreach (['compact' => ['Compact','view_comfy_alt'], 'normal' => ['Normal','view_agenda'], 'comfortable' => ['Comfortable','view_stream']] as $d => [$dlabel, $dicon]): ?>
                                <label class="density-option <?php echo $prefs['ui_density'] === $d ? 'active' : ''; ?> cursor-pointer">
                                    <input type="radio" name="ui_density" value="<?php echo $d; ?>" class="sr-only"
                                           <?php echo $prefs['ui_density'] === $d ? 'checked' : ''; ?>
                                           onchange="setDensity('<?php echo $d; ?>')">
                                    <span class="material-symbols-outlined text-[20px] text-indigo-500 block mb-1"><?php echo $dicon; ?></span>
                                    <p class="text-xs font-semibold text-slate-700"><?php echo $dlabel; ?></p>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Animations -->
                        <div class="settings-card p-6 mb-5">
                            <div class="flex items-center gap-2 mb-4">
                                <span class="material-symbols-outlined text-indigo-500 text-[20px]">animation</span>
                                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider">Animations</h2>
                            </div>
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-semibold text-slate-700">Enable animations</p>
                                    <p class="text-xs text-slate-400 mt-0.5">Smooth transitions and entrance effects</p>
                                </div>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" name="animations" class="sr-only"
                                           id="animationsCheck"
                                           <?php echo $prefs['animations'] ? 'checked' : ''; ?>
                                           onchange="syncToggle(this); toggleAnimations(this.checked)">
                                    <div id="track_animations" class="toggle-track <?php echo $prefs['animations'] ? 'on' : ''; ?>"
                                         onclick="document.getElementById('animationsCheck').click()">
                                        <div class="toggle-knob"></div>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Save -->
                        <div class="flex justify-end">
                            <button type="submit"
                                    class="flex items-center gap-2 text-white px-5 py-2.5 rounded-xl font-bold text-[11px] uppercase tracking-widest transition-all shadow-lg hover:shadow-[var(--accent-600)]/40 hover:-translate-y-0.5" style="background:var(--accent-600);" onmouseover="this.style.background='var(--accent-700)'" onmouseout="this.style.background='var(--accent-600)'">
                                <span class="material-symbols-outlined text-[18px]">save</span>
                                Save Appearance
                            </button>
                        </div>
                    </form>
                    </div><!-- /tab-appearance -->

                    <!-- ════════════════════════════════════════════════════════
                         TAB: PREFERENCES
                    ════════════════════════════════════════════════════════ -->
                    <div id="tab-preferences" class="tab-panel hidden fade-up d2">
                    <form method="POST" action="" id="form-preferences" onsubmit="submitPrefsAjax(this, this.querySelector('[type=submit]')); return false;">
                    <input type="hidden" name="action" value="save_prefs">

                        <!-- Notifications -->
                        <div class="settings-card p-6 mb-5">
                            <div class="flex items-center gap-2 mb-4">
                                <span class="material-symbols-outlined text-indigo-500 text-[20px]">notifications</span>
                                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider">Notifications</h2>
                            </div>
                            <div class="space-y-4">
                                <!-- Email notif -->
                                <div class="flex items-center justify-between py-1">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-700">Email notifications</p>
                                        <p class="text-xs text-slate-400 mt-0.5">Receive alerts and updates via email</p>
                                    </div>
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" name="notif_email" class="sr-only"
                                               id="notifEmailCheck"
                                               <?php echo $prefs['notif_email'] ? 'checked' : ''; ?>
                                               onchange="syncToggle(this)">
                                        <div id="track_notif_email" class="toggle-track <?php echo $prefs['notif_email'] ? 'on' : ''; ?>"
                                             onclick="document.getElementById('notifEmailCheck').click()">
                                            <div class="toggle-knob"></div>
                                        </div>
                                    </label>
                                </div>
                                <hr class="border-slate-100">
                                <!-- Browser notif -->
                                <div class="flex items-center justify-between py-1">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-700">Browser notifications</p>
                                        <p class="text-xs text-slate-400 mt-0.5">Show desktop push notifications</p>
                                    </div>
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" name="notif_browser" class="sr-only"
                                               id="notifBrowserCheck"
                                               <?php echo $prefs['notif_browser'] ? 'checked' : ''; ?>
                                               onchange="syncToggle(this)">
                                        <div id="track_notif_browser" class="toggle-track <?php echo $prefs['notif_browser'] ? 'on' : ''; ?>"
                                             onclick="document.getElementById('notifBrowserCheck').click()">
                                            <div class="toggle-knob"></div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Default page -->
                        <div class="settings-card p-6 mb-5">
                            <div class="flex items-center gap-2 mb-4">
                                <span class="material-symbols-outlined text-indigo-500 text-[20px]">home</span>
                                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider">Default Landing Page</h2>
                            </div>
                            <p class="text-xs text-slate-400 mb-3">Choose which page loads after you log in.</p>
                            <select name="default_page"
                                    class="w-full sm:w-64 border border-slate-200 rounded-xl px-4 py-2.5
                                           text-sm text-slate-700 bg-white focus:outline-none focus:ring-2
                                           focus:ring-indigo-300 transition-all">
                                <?php
                                $pages = [
                                    'dashboard.php'          => 'Dashboard',
                                    'residents.php'          => 'Residents',
                                    'document_requests.php'  => 'Document Requests',
                                    'activity_logs.php'      => 'Activity Logs',
                                ];
                                foreach ($pages as $val => $label): ?>
                                <option value="<?php echo $val; ?>"
                                        <?php echo $prefs['default_page'] === $val ? 'selected' : ''; ?>>
                                    <?php echo $label; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Sidebar preference -->
                        <div class="settings-card p-6 mb-5">
                            <div class="flex items-center gap-2 mb-4">
                                <span class="material-symbols-outlined text-indigo-500 text-[20px]">left_panel_close</span>
                                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider">Sidebar</h2>
                            </div>
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-semibold text-slate-700">Start collapsed</p>
                                    <p class="text-xs text-slate-400 mt-0.5">Collapse the sidebar by default on login</p>
                                </div>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" name="sidebar_collapsed" class="sr-only"
                                           id="sidebarCheck"
                                           <?php echo $prefs['sidebar_collapsed'] ? 'checked' : ''; ?>
                                           onchange="syncToggle(this)">
                                    <div id="track_sidebar_collapsed" class="toggle-track <?php echo $prefs['sidebar_collapsed'] ? 'on' : ''; ?>"
                                         onclick="document.getElementById('sidebarCheck').click()">
                                        <div class="toggle-knob"></div>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Save -->
                        <div class="flex justify-end">
                            <button type="submit"
                                    class="flex items-center gap-2 text-white px-5 py-2.5 rounded-xl font-bold text-[11px] uppercase tracking-widest transition-all shadow-lg hover:shadow-[var(--accent-600)]/40 hover:-translate-y-0.5" style="background:var(--accent-600);" onmouseover="this.style.background='var(--accent-700)'" onmouseout="this.style.background='var(--accent-600)'">
                                <span class="material-symbols-outlined text-[18px]">save</span>
                                Save Preferences
                            </button>
                        </div>
                    </form>
                    </div><!-- /tab-preferences -->

                    <!-- ════════════════════════════════════════════════════════
                         TAB: SECURITY
                    ════════════════════════════════════════════════════════ -->
                    <div id="tab-security" class="tab-panel hidden fade-up d2">

                        <!-- Change password -->
                        <div class="settings-card p-6 mb-5">
                            <div class="flex items-center gap-2 mb-5">
                                <span class="material-symbols-outlined text-indigo-500 text-[20px]">lock</span>
                                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider">Change Password</h2>
                            </div>
                            <form method="POST" action="" id="form-password" class="space-y-4 max-w-sm" onsubmit="submitPasswordAjax(this, this.querySelector('[type=submit]')); return false;">
                                <input type="hidden" name="action" value="change_password">

                                <!-- Current -->
                                <div>
                                    <label class="block text-xs font-semibold text-slate-500 mb-1.5">Current Password</label>
                                    <div class="relative">
                                        <input type="password" name="current_password" id="curPw" required
                                               class="w-full border border-slate-200 rounded-xl px-4 py-2.5 pr-10
                                                      text-sm text-slate-700 bg-white focus:outline-none
                                                      focus:ring-2 focus:ring-indigo-300 transition-all">
                                        <button type="button" onclick="togglePw('curPw', this)"
                                                class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600">
                                            <span class="material-symbols-outlined text-[18px]">visibility</span>
                                        </button>
                                    </div>
                                </div>

                                <!-- New -->
                                <div>
                                    <label class="block text-xs font-semibold text-slate-500 mb-1.5">New Password</label>
                                    <div class="relative">
                                        <input type="password" name="new_password" id="newPw" required
                                               oninput="checkStrength(this.value); checkMatch()"
                                               class="w-full border border-slate-200 rounded-xl px-4 py-2.5 pr-10
                                                      text-sm text-slate-700 bg-white focus:outline-none
                                                      focus:ring-2 focus:ring-indigo-300 transition-all">
                                        <button type="button" onclick="togglePw('newPw', this)"
                                                class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600">
                                            <span class="material-symbols-outlined text-[18px]">visibility</span>
                                        </button>
                                    </div>
                                    <!-- Strength bar -->
                                    <div class="mt-2 bg-slate-100 rounded-full overflow-hidden h-1">
                                        <div id="strengthBar" class="strength-bar" style="width:0%;background:#94a3b8"></div>
                                    </div>
                                    <p id="strengthLabel" class="text-[11px] font-semibold mt-1" style="color:#94a3b8"></p>
                                </div>

                                <!-- Confirm -->
                                <div>
                                    <label class="block text-xs font-semibold text-slate-500 mb-1.5">Confirm New Password</label>
                                    <div class="relative">
                                        <input type="password" name="confirm_password" id="confPw" required
                                               oninput="checkMatch()"
                                               class="w-full border border-slate-200 rounded-xl px-4 py-2.5 pr-10
                                                      text-sm text-slate-700 bg-white focus:outline-none
                                                      focus:ring-2 focus:ring-indigo-300 transition-all">
                                        <button type="button" onclick="togglePw('confPw', this)"
                                                class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600">
                                            <span class="material-symbols-outlined text-[18px]">visibility</span>
                                        </button>
                                    </div>
                                    <p id="matchMsg" class="text-[11px] font-semibold mt-1"></p>
                                </div>

                                <button type="submit"
                                        class="flex items-center gap-2 text-white px-5 py-2.5 rounded-xl font-bold text-[11px] uppercase tracking-widest transition-all shadow-lg hover:shadow-[var(--accent-600)]/40 hover:-translate-y-0.5" style="background:var(--accent-600);" onmouseover="this.style.background='var(--accent-700)'" onmouseout="this.style.background='var(--accent-600)'">
                                    <span class="material-symbols-outlined text-[18px]">lock_reset</span>
                                    Update Password
                                </button>
                            </form>
                        </div>

                        <!-- Session info -->
                        <div class="settings-card p-6">
                            <div class="flex items-center gap-2 mb-4">
                                <span class="material-symbols-outlined text-indigo-500 text-[20px]">verified_user</span>
                                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider">Session Info</h2>
                            </div>
                            <div class="space-y-3 text-sm">
                                <div class="flex items-center justify-between py-2 border-b border-slate-100">
                                    <span class="text-slate-500 font-medium">Logged in as</span>
                                    <span class="font-semibold text-slate-700"><?php echo htmlspecialchars($admin_name); ?></span>
                                </div>
                                <div class="flex items-center justify-between py-2 border-b border-slate-100">
                                    <span class="text-slate-500 font-medium">Role</span>
                                    <span class="role-badge <?php echo $admin_role; ?>">
                                        <span class="material-symbols-outlined text-[12px]"><?php echo $admin_role === 'admin' ? 'admin_panel_settings' : 'badge'; ?></span>
                                        <?php echo ucfirst($admin_role); ?>
                                    </span>
                                </div>
                                <div class="flex items-center justify-between py-2 border-b border-slate-100">
                                    <span class="text-slate-500 font-medium">Session started</span>
                                    <span class="font-semibold text-slate-700"><?php echo htmlspecialchars($session_started); ?></span>
                                </div>
                                <div class="flex items-center justify-between py-2">
                                    <span class="text-slate-500 font-medium">IP Address</span>
                                    <span class="font-mono text-xs font-semibold text-slate-700">
                                        <?php echo htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '—'); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="mt-5">
                                <a href="../../logout.php"
                                   class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl font-bold text-[11px] uppercase tracking-widest transition-all text-rose-600 border border-rose-200 bg-rose-50 hover:bg-rose-100 hover:-translate-y-0.5">
                                    <span class="material-symbols-outlined text-[16px]">logout</span>
                                    Sign out
                                </a>
                            </div>
                        </div>

                    </div><!-- /tab-security -->

                    <?php if ($admin_role === 'admin'): ?>
                    <!-- ════════════════════════════════════════════════════════
                         TAB: SMS CONFIGURATION (gateway used by Disaster Alert SMS)
                    ════════════════════════════════════════════════════════ -->
                    <div id="tab-sms" class="tab-panel hidden fade-up d2">

                        <!-- Gateway status -->
                        <div class="settings-card p-6 mb-5">
                            <div class="flex items-center justify-between gap-3 mb-5">
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-indigo-500 text-[20px]">sms</span>
                                    <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider">SMS Gateway Status</h2>
                                </div>
                                <button type="button" onclick="loadSmsConfigs()" class="text-xs font-semibold text-slate-500 hover:text-slate-800 flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[16px]">refresh</span> Check again
                                </button>
                            </div>
                            <div id="smsStatusBox" class="rounded-xl border border-slate-100 bg-slate-50 p-4 text-sm text-slate-500">Loading the SMS configuration…</div>
                            <p class="text-xs text-slate-400 mt-3 leading-relaxed">
                                Used by Announcements → Issue Alert to text residents during disasters. Only one configuration is active at a time.
                            </p>
                        </div>

                        <!-- Send test SMS -->
                        <div class="settings-card p-6 mb-5" id="smsTestCard">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="material-symbols-outlined text-indigo-500 text-[20px]">send_to_mobile</span>
                                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider">Send Test SMS</h2>
                            </div>
                            <p class="text-sm text-slate-500 mb-4 leading-relaxed">
                                Sends one message through the gateway — the <strong>same way disaster alerts are sent</strong> — so you can confirm the setup works before an emergency.
                            </p>
                            <form id="smsTestForm" class="flex flex-wrap items-end gap-3" onsubmit="sendSmsTest(event)">
                                <div class="min-w-[220px]">
                                    <label class="block text-xs font-semibold text-slate-500 mb-1.5">Configuration</label>
                                    <select name="config_id" id="smsTestConfig" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-700 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-300 transition-all"></select>
                                </div>
                                <div class="min-w-[200px]">
                                    <label class="block text-xs font-semibold text-slate-500 mb-1.5">Send to</label>
                                    <input type="tel" name="test_number" required placeholder="09XXXXXXXXX" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-700 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-300 transition-all font-mono">
                                </div>
                                <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 transition">
                                    <span class="material-symbols-outlined text-[18px]">send</span> Send Test
                                </button>
                            </form>
                        </div>

                        <!-- Configurations -->
                        <div class="settings-card p-6 mb-5">
                            <div class="flex items-center justify-between gap-3 mb-4">
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-indigo-500 text-[20px]">sim_card</span>
                                    <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider">SMS Configurations</h2>
                                    <span id="smsCount" class="text-[10px] font-bold bg-slate-100 text-slate-500 px-2 py-0.5 rounded-full hidden"></span>
                                </div>
                                <button type="button" onclick="openSmsForm()" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 transition">
                                    <span class="material-symbols-outlined text-[18px]">add</span> Add Configuration
                                </button>
                            </div>
                            <div id="smsConfigList" class="space-y-3"></div>
                        </div>

                        <!-- Add / Edit configuration -->
                        <div class="settings-card p-6 hidden" id="smsFormCard">
                            <div class="flex items-center justify-between gap-2 mb-2">
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-emerald-500 text-[20px]" id="smsFormIcon">add_circle</span>
                                    <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider" id="smsFormTitle">Add Configuration</h2>
                                </div>
                                <button type="button" onclick="closeSmsForm()" class="text-slate-400 hover:text-slate-700"><span class="material-symbols-outlined text-[20px]">close</span></button>
                            </div>
                            <p class="text-sm text-slate-500 mb-3 leading-relaxed">Credentials from your <strong>InfiniReach</strong> account and the Android phone running the SMS Gateway app.</p>
                            <details class="mb-4 text-xs text-slate-500">
                                <summary class="cursor-pointer font-semibold text-slate-600">How to get these values (one time only)</summary>
                                <ol class="list-decimal ml-5 mt-2 space-y-1.5 leading-relaxed">
                                    <li>Install the <strong>SMS Gateway</strong> app on the Android phone that will send the messages. Insert a SIM with load, keep it online, and allow SMS, Phone, Notification and Background permissions (turn off battery optimization for the app).</li>
                                    <li>Create or log in to your account at <a href="https://app.infinireach.io" target="_blank" rel="noopener" class="text-indigo-600 underline">app.infinireach.io</a>.</li>
                                    <li><strong>API Key</strong>: <em>Automation → API Keys</em> → <strong>Generate API Key</strong>, then copy it.</li>
                                    <li><strong>Device ID</strong>: in the SMS Gateway app, link the phone to your InfiniReach account, then copy the ID from <em>Device Information</em>.</li>
                                    <li><strong>Sender Number</strong>: the number of the SIM in that phone, with country code (e.g. <code>+639171234567</code>).</li>
                                    <li><strong>API URL</strong>: keep the default <code>https://api.infinireach.io/api/v1/messages</code> unless your provider says otherwise.</li>
                                    <li>Save with <strong>Set as active</strong> ticked, then use <strong>Send Test SMS</strong> above.</li>
                                </ol>
                            </details>
                            <form id="smsConfigForm" class="grid sm:grid-cols-2 gap-4" onsubmit="saveSmsConfig(event)">
                                <input type="hidden" name="config_id" value="">
                                <div class="sm:col-span-2">
                                    <label class="block text-xs font-semibold text-slate-500 mb-1.5">Configuration Name</label>
                                    <input type="text" name="configuration_name" required placeholder="e.g. Main SIM – Globe" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-700 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-300 transition-all">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-xs font-semibold text-slate-500 mb-1.5">API Key <span id="smsKeyHint" class="font-normal text-slate-400 hidden">— leave blank to keep the saved key</span></label>
                                    <input type="text" name="api_key" autocomplete="off" spellcheck="false" placeholder="smsrelay_…" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-700 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-300 transition-all font-mono">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-500 mb-1.5">Sender Number</label>
                                    <input type="text" name="from_number" required placeholder="+639XXXXXXXXX" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-700 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-300 transition-all font-mono">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-500 mb-1.5">Device ID</label>
                                    <input type="text" name="device_id" required placeholder="xxxxxxxx-xxxx-…" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-700 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-300 transition-all font-mono">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-xs font-semibold text-slate-500 mb-1.5">API URL</label>
                                    <input type="url" name="api_url" required value="https://api.infinireach.io/api/v1/messages" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-700 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-300 transition-all">
                                </div>
                                <label class="sm:col-span-2 flex items-center gap-2 text-sm text-slate-600 font-medium">
                                    <input type="checkbox" name="status" value="Active" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-300">
                                    Set as active (disaster alerts will use this configuration)
                                </label>
                                <div class="sm:col-span-2 flex gap-2">
                                    <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold text-white bg-emerald-600 hover:bg-emerald-700 transition">
                                        <span class="material-symbols-outlined text-[18px]">save</span> Save Configuration
                                    </button>
                                    <button type="button" onclick="closeSmsForm()" class="px-5 py-2.5 rounded-xl text-sm font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 transition">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div><!-- /tab-sms -->

                    <!-- ════════════════════════════════════════════════════════
                         TAB: FACEBOOK (Page connection for announcements)
                    ════════════════════════════════════════════════════════ -->
                    <div id="tab-facebook" class="tab-panel hidden fade-up d2">

                        <!-- Connection status -->
                        <div class="settings-card p-6 mb-5">
                            <div class="flex items-center justify-between gap-3 mb-5">
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-[20px]" style="color:#1877f2">share</span>
                                    <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider">Facebook Page Connection</h2>
                                </div>
                                <button type="button" onclick="loadFbStatus()" class="text-xs font-semibold text-slate-500 hover:text-slate-800 flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[16px]">refresh</span> Check again
                                </button>
                            </div>
                            <div id="fbStatusBox" class="rounded-xl border border-slate-100 bg-slate-50 p-4 text-sm text-slate-500">Checking the connection…</div>
                            <p class="text-xs text-slate-400 mt-3 leading-relaxed">
                                Used by Announcements to post to the barangay Facebook Page and by Announcement Analytics to read views, reach and engagement.
                            </p>
                        </div>

                        <!-- Reconnect with Facebook Login -->
                        <div class="settings-card p-6 mb-5">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="material-symbols-outlined text-indigo-500 text-[20px]">login</span>
                                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider">Reconnect Facebook</h2>
                            </div>
                            <p class="text-sm text-slate-500 mb-4 leading-relaxed">
                                Log in with a Facebook account that is an <strong>admin of the barangay Page</strong> and allow all the permissions asked.
                                The new token is saved automatically — nothing to copy or paste.
                            </p>
                            <a href="../backend/fb_connection.php?action=connect"
                               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold text-white shadow-sm hover:opacity-90 transition"
                               style="background:#1877f2">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="#fff"><path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.312 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>
                                Reconnect Facebook
                            </a>
                            <details class="mt-4 text-xs text-slate-500">
                                <summary class="cursor-pointer font-semibold text-slate-600">First-time setup of the Facebook app (one time only)</summary>
                                <ol class="list-decimal ml-5 mt-2 space-y-1.5 leading-relaxed">
                                    <li>Open <a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener" class="text-indigo-600 underline">developers.facebook.com/apps</a> → your app (ID <span class="font-mono"><?php echo htmlspecialchars($fb_app_id); ?></span>).</li>
                                    <li>Add the <strong>Facebook Login</strong> product if it is not there yet.</li>
                                    <li>Facebook Login → Settings → <strong>Valid OAuth Redirect URIs</strong>: add<br>
                                        <code id="fbRedirectUri" class="inline-block mt-1 px-2 py-1 bg-slate-100 rounded font-mono text-[11px] break-all">—</code></li>
                                    <li>Save. After that, anyone with the Admin role can use <strong>Reconnect Facebook</strong>.</li>
                                </ol>
                            </details>
                        </div>

                        <!-- Option 1: System User token -->
                        <div class="settings-card p-6">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="material-symbols-outlined text-emerald-500 text-[20px]">verified</span>
                                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider">Never-Expiring Token (System User)</h2>
                                <span class="text-[10px] font-bold uppercase tracking-wider bg-emerald-50 text-emerald-600 px-2 py-0.5 rounded-full">Recommended</span>
                            </div>
                            <p class="text-sm text-slate-500 mb-3 leading-relaxed">
                                A System User token from Meta Business Settings <strong>does not expire</strong> and is not tied to anyone's personal
                                Facebook account, so it keeps working even when a staff member changes their password or leaves.
                            </p>
                            <details class="mb-4 text-xs text-slate-500">
                                <summary class="cursor-pointer font-semibold text-slate-600">How to get the System User token</summary>
                                <ol class="list-decimal ml-5 mt-2 space-y-1.5 leading-relaxed">
                                    <li>Go to <a href="https://business.facebook.com/settings" target="_blank" rel="noopener" class="text-indigo-600 underline">business.facebook.com/settings</a> (the Business portfolio that owns the barangay Page).</li>
                                    <li><strong>Accounts → Pages</strong>: make sure the barangay Page is in the portfolio. <strong>Accounts → Apps</strong>: add the app (ID <span class="font-mono"><?php echo htmlspecialchars($fb_app_id); ?></span>).</li>
                                    <li><strong>Users → System users → Add</strong>. Name it (e.g. <em>CAPS Announcements</em>), role <strong>Admin</strong>.</li>
                                    <li>Select the system user → <strong>Assign assets</strong>: the barangay <strong>Page</strong> (Full control / Manage Page) and the <strong>App</strong>.</li>
                                    <li>Click <strong>Generate token</strong> → choose the app → Token expiration: <strong>Never</strong> → tick
                                        <code>pages_show_list</code>, <code>pages_read_engagement</code>, <code>pages_manage_posts</code> and <code>read_insights</code>.</li>
                                    <li>Copy the token and paste it below, then click <strong>Save Token</strong>. It is checked with Facebook before it is saved.</li>
                                </ol>
                            </details>
                            <form id="fbSystemTokenForm" class="space-y-3" onsubmit="saveFbSystemToken(event)">
                                <input type="hidden" name="action" value="save_system_token">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                <textarea name="token" rows="3" required autocomplete="off" spellcheck="false"
                                    placeholder="Paste the System User access token here"
                                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-xs font-mono text-slate-700 focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400"></textarea>
                                <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold text-white bg-emerald-600 hover:bg-emerald-700 transition">
                                    <span class="material-symbols-outlined text-[18px]">save</span> Save Token
                                </button>
                            </form>
                        </div>
                    </div><!-- /tab-facebook -->
                    <?php endif; ?>

                    <!-- ════════════════════════════════════════════════════════
                         TAB: SYSTEM
                    ════════════════════════════════════════════════════════ -->
                    <div id="tab-system" class="tab-panel hidden fade-up d2">

                        <!-- Account info -->
                        <div class="settings-card p-6 mb-5">
                            <div class="flex items-center gap-2 mb-5">
                                <span class="material-symbols-outlined text-indigo-500 text-[20px]">account_circle</span>
                                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider">Account Info</h2>
                            </div>
                            <div class="flex items-center gap-4 mb-5">
                                <!-- Avatar -->
                                <div class="w-16 h-16 rounded-2xl flex items-center justify-center text-xl font-extrabold text-white shrink-0"
                                     style="background: linear-gradient(135deg, var(--accent-500,#6366f1), var(--accent-700,#4f46e5))">
                                    <?php echo htmlspecialchars($initials); ?>
                                </div>
                                <div>
                                    <p class="text-base font-bold text-slate-800"><?php echo htmlspecialchars($admin_name); ?></p>
                                    <p class="text-sm text-slate-500"><?php echo htmlspecialchars($admin_email); ?></p>
                                    <span class="role-badge <?php echo $admin_role; ?> mt-1 inline-flex">
                                        <?php echo ucfirst($admin_role); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-4 space-y-2 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-slate-500">Admin ID</span>
                                    <span class="font-mono font-semibold text-slate-700">#<?php echo $admin_id; ?></span>
                                </div>
                                <?php if ($profile && !empty($profile['Phone'])): ?>
                                <div class="flex justify-between">
                                    <span class="text-slate-500">Phone</span>
                                    <span class="font-semibold text-slate-700"><?php echo htmlspecialchars($profile['Phone']); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- App info -->
                        <div class="settings-card p-6">
                            <div class="flex items-center gap-2 mb-4">
                                <span class="material-symbols-outlined text-indigo-500 text-[20px]">info</span>
                                <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider">Application</h2>
                            </div>
                            <div class="space-y-3 text-sm">
                                <div class="flex justify-between py-2 border-b border-slate-100">
                                    <span class="text-slate-500">System</span>
                                    <span class="font-semibold text-slate-700">Barangay Management System</span>
                                </div>
                                <div class="flex justify-between py-2 border-b border-slate-100">
                                    <span class="text-slate-500">Portal</span>
                                    <span class="font-semibold text-slate-700">Admin / Staff</span>
                                </div>
                                <div class="flex justify-between py-2">
                                    <span class="text-slate-500">Server time</span>
                                    <span class="font-mono font-semibold text-slate-700">
                                        <?php echo date('M d, Y h:i:s A'); ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                    </div><!-- /tab-system -->

                </div><!-- /tab panels -->
            </div><!-- /flex -->
        </main>
    </div><!-- /main-wrapper -->
</div><!-- /flex min-h-screen -->

<!-- Toast Container (residents.php style) -->
<div id="toast-container"></div>

<script>
// ── Tab switching ─────────────────────────────────────────────────────────────
function switchTab(name, btn) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.add('hidden'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    const panel = document.getElementById('tab-' + name);
    if (panel) { panel.classList.remove('hidden'); panel.classList.add('fade-up'); }
    if (btn) btn.classList.add('active');
}

// ── localStorage helpers ──────────────────────────────────────────────────────
function tryGetLS() {
    try { return JSON.parse(localStorage.getItem('adminThemePrefs') || '{}'); } catch(e) { return {}; }
}
function saveLS(key, val) {
    try { const d = tryGetLS(); d[key] = val; localStorage.setItem('adminThemePrefs', JSON.stringify(d)); } catch(e) {}
}

// ── Theme mode ────────────────────────────────────────────────────────────────
function setThemeMode(mode) {
    if (mode === 'dark') {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }
    document.documentElement.setAttribute('data-theme', mode);
    saveLS('colorMode', mode);
    showToast('Theme changed — save to keep it', 'success');
}

// ── Accent color ──────────────────────────────────────────────────────────────
function applyAccentColor(hex) {
    if (!hex || !/^#[0-9a-fA-F]{6}$/.test(hex)) return;
    const r = parseInt(hex.slice(1,3),16),
          g = parseInt(hex.slice(3,5),16),
          b = parseInt(hex.slice(5,7),16);
    const lighten = (r2,g2,b2,a) =>
        `rgba(${Math.round(r2+(255-r2)*a)},${Math.round(g2+(255-g2)*a)},${Math.round(b2+(255-b2)*a)},1)`;
    const darken = (r2,g2,b2,f) =>
        `rgb(${Math.round(r2*f)},${Math.round(g2*f)},${Math.round(b2*f)})`;
    const root = document.documentElement;
    root.style.setProperty('--accent-50',  lighten(r,g,b,0.92));
    root.style.setProperty('--accent-100', lighten(r,g,b,0.85));
    root.style.setProperty('--accent-200', lighten(r,g,b,0.70));
    root.style.setProperty('--accent-300', lighten(r,g,b,0.55));
    root.style.setProperty('--accent-400', lighten(r,g,b,0.30));
    root.style.setProperty('--accent-500', `rgb(${r},${g},${b})`);
    root.style.setProperty('--accent-600', darken(r,g,b,0.88));
    root.style.setProperty('--accent-700', darken(r,g,b,0.76));
    root.style.setProperty('--accent-800', darken(r,g,b,0.64));
    root.style.setProperty('--accent-900', darken(r,g,b,0.52));

    // Sync accent color hidden input
    const picker = document.getElementById('accentColorPicker');
    const hexInp = document.getElementById('accentHexInput');
    const label  = document.getElementById('accentLabel');
    if (picker) picker.value = hex;
    if (hexInp) hexInp.value = hex.toUpperCase();
    if (label)  { label.textContent = hex.toUpperCase(); label.style.color = hex; }
}

// Called on every oninput (drag) — updates visuals only, no toast
function onColorPickerChange(hex) {
    applyAccentColor(hex);
    document.querySelectorAll('.quick-color-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.hex === hex.toLowerCase());
    });
    saveLS('accentHex', hex);
}

// Called on onchange (mouse release / final commit) — shows toast once
function onColorPickerCommit(hex) {
    onColorPickerChange(hex);
    showToast('Accent color updated — save to keep it', 'info');
}

function onHexInputChange(val) {
    val = val.trim();
    if (!val.startsWith('#')) val = '#' + val;
    if (/^#[0-9a-fA-F]{6}$/.test(val)) {
        const picker = document.getElementById('accentColorPicker');
        if (picker) picker.value = val;
        onColorPickerCommit(val);
    }
}

function applyQuickPreset(hex, btn) {
    const picker = document.getElementById('accentColorPicker');
    if (picker) picker.value = hex;
    onColorPickerCommit(hex);
    document.querySelectorAll('.quick-color-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}

// ── Font size ─────────────────────────────────────────────────────────────────
function setFontSize(size) {
    const px = { sm: '13px', base: '15px', lg: '17px' }[size] || '15px';
    document.documentElement.style.fontSize = px;
    document.querySelectorAll('.font-size-option').forEach(el => {
        const is = el.querySelector('input').value === size;
        el.classList.toggle('border-indigo-500', is);
        el.classList.toggle('bg-indigo-50', is);
        el.classList.toggle('text-indigo-700', is);
    });
    saveLS('fontSize', size);
}

// ── UI Density ────────────────────────────────────────────────────────────────
function setDensity(d) {
    document.documentElement.setAttribute('data-density', d);
    document.querySelectorAll('.density-option').forEach(el => {
        el.classList.toggle('active', el.querySelector('input').value === d);
    });
    saveLS('uiDensity', d);
}

// ── Animations ────────────────────────────────────────────────────────────────
function toggleAnimations(on) {
    document.documentElement.classList.toggle('no-anim', !on);
    saveLS('animations', on);
}

// ── Toggle switch sync ────────────────────────────────────────────────────────
function syncToggle(checkbox) {
    const track = document.getElementById('track_' + checkbox.name);
    if (track) track.classList.toggle('on', checkbox.checked);
}

// ── Password visibility ───────────────────────────────────────────────────────
function togglePw(id, btn) {
    const inp  = document.getElementById(id);
    const icon = btn.querySelector('.material-symbols-outlined');
    const show = inp.type === 'password';
    inp.type         = show ? 'text' : 'password';
    icon.textContent = show ? 'visibility_off' : 'visibility';
}

// ── Password strength ─────────────────────────────────────────────────────────
function checkStrength(val) {
    const bar   = document.getElementById('strengthBar');
    const label = document.getElementById('strengthLabel');
    if (!bar) return;
    let score = 0;
    if (val.length >= 8)          score++;
    if (/[A-Z]/.test(val))        score++;
    if (/[0-9]/.test(val))        score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const map = [
        { w: '0%',   color: '#94a3b8', txt: '' },
        { w: '30%',  color: '#f87171', txt: 'Weak' },
        { w: '55%',  color: '#fbbf24', txt: 'Fair' },
        { w: '80%',  color: '#60a5fa', txt: 'Good' },
        { w: '100%', color: '#10b981', txt: 'Strong' },
    ];
    const s = map[score] || map[0];
    bar.style.width      = s.w;
    bar.style.background = s.color;
    label.textContent    = s.txt;
    label.style.color    = s.color;
}

function checkMatch() {
    const np  = document.getElementById('newPw').value;
    const cp  = document.getElementById('confPw').value;
    const msg = document.getElementById('matchMsg');
    if (!msg || !cp) return;
    if (np === cp) {
        msg.textContent = '✓ Passwords match'; msg.style.color = '#10b981';
    } else {
        msg.textContent = '✗ Passwords do not match'; msg.style.color = '#f43f5e';
    }
}

// ── Toast System (residents.php style) ───────────────────────────────────────
(function() {
    const style = document.createElement('style');
    style.textContent = '';  // CSS already in <style> block above
    // Ensure container exists
    if (!document.getElementById('toast-container')) {
        const c = document.createElement('div');
        c.id = 'toast-container';
        document.body.appendChild(c);
    }
})();

function showToast(message, type = 'success', duration = 4500) {
    const icons = { success: 'check_circle', error: 'error', warning: 'warning', info: 'info' };
    const container = document.getElementById('toast-container');
    if (!container) return;
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.style.position = 'relative';
    toast.style.overflow = 'hidden';
    toast.innerHTML = `
        <span class="material-symbols-outlined toast-icon">${icons[type] || 'info'}</span>
        <span class="toast-msg">${message}</span>
        <button class="toast-close" onclick="dismissToast(this.parentElement)">&times;</button>
        <div class="toast-bar" style="animation-duration: ${duration}ms;"></div>`;
    container.appendChild(toast);
    requestAnimationFrame(() => { requestAnimationFrame(() => toast.classList.add('show')); });
    setTimeout(() => dismissToast(toast), duration);
    return toast;
}

function dismissToast(toast) {
    if (!toast || toast._dismissed) return;
    toast._dismissed = true;
    toast.classList.add('hide');
    setTimeout(() => toast.remove(), 350);
}

// ── AJAX form submission for save_prefs ───────────────────────────────────────
function submitPrefsAjax(form, btnEl) {
    const origText = btnEl ? btnEl.innerHTML : '';
    if (btnEl) {
        btnEl.disabled = true;
        btnEl.innerHTML = '<span class="material-symbols-outlined text-[18px] animate-spin">sync</span> Saving…';
    }
    const fd = new FormData(form);
    // Ensure accent_color hidden input is synced before submit
    const picker = document.getElementById('accentColorPicker');
    if (picker) fd.set('accent_color', picker.value);

    fetch(location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    })
    .then(data => {
        showToast(data.message, data.success ? 'success' : 'error');
    })
    .catch(() => {
        showToast('Network error. Please try again.', 'error');
    })
    .finally(() => {
        if (btnEl) {
            btnEl.disabled = false;
            btnEl.innerHTML = origText;
        }
    });
}

// ── AJAX form submission for change_password ──────────────────────────────────
function submitPasswordAjax(form, btnEl) {
    const origText = btnEl ? btnEl.innerHTML : '';
    if (btnEl) {
        btnEl.disabled = true;
        btnEl.innerHTML = '<span class="material-symbols-outlined text-[18px] animate-spin">sync</span> Updating…';
    }
    const fd = new FormData(form);
    fetch(location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    })
    .then(data => {
        showToast(data.message, data.success ? 'success' : 'error');
        if (data.success) form.reset();
    })
    .catch(() => {
        showToast('Network error. Please try again.', 'error');
    })
    .finally(() => {
        if (btnEl) {
            btnEl.disabled = false;
            btnEl.innerHTML = origText;
        }
    });
}

// ── SMS Configuration (Settings → SMS Configuration) ─────────────────────────
const SMS_API = '../backend/sms_config.php';
const SETTINGS_CSRF = <?php echo json_encode($_SESSION['csrf_token']); ?>;
let smsConfigs = [];

async function smsPost(data) {
    const fd = data instanceof FormData ? data : new FormData();
    if (!(data instanceof FormData)) Object.entries(data).forEach(([k, v]) => fd.append(k, v));
    fd.append('csrf_token', SETTINGS_CSRF);
    const res = await fetch(SMS_API, { method: 'POST', body: fd });
    return res.json();
}

async function loadSmsConfigs() {
    const box = document.getElementById('smsStatusBox');
    if (!box) return;
    try {
        const res = await fetch(SMS_API + '?action=list', { headers: { 'Accept': 'application/json' } });
        const d = await res.json();
        if (!d.success) throw new Error(d.message || 'Could not load the SMS configuration.');
        smsConfigs = d.configs;
        renderSmsStatus(d.active);
        renderSmsList();
    } catch (e) {
        box.className = 'rounded-xl border border-rose-100 bg-rose-50/60 p-4 text-sm text-rose-600';
        box.textContent = e.message;
    }
}

function renderSmsStatus(a) {
    const box = document.getElementById('smsStatusBox');
    if (a) {
        box.className = 'rounded-xl border border-emerald-100 bg-emerald-50/60 p-4 text-sm';
        box.innerHTML = `
            <p class="flex items-center gap-2 font-bold text-emerald-700 mb-3"><span class="material-symbols-outlined text-[20px]">check_circle</span> Active: ${fbEsc(a.configuration_name)} — Disaster SMS ready</p>
            <div class="grid sm:grid-cols-2 gap-x-6 gap-y-1.5 text-slate-600">
                <div class="flex justify-between"><span class="text-slate-400">Sender number</span><span class="font-mono">${fbEsc(a.from_number)}</span></div>
                <div class="flex justify-between"><span class="text-slate-400">Gateway</span><span>${fbEsc(a.api_host)}</span></div>
                <div class="flex justify-between"><span class="text-slate-400">Device ID</span><span class="font-mono">${fbEsc(a.device_id)}</span></div>
                <div class="flex justify-between"><span class="text-slate-400">API key</span><span class="font-mono">${fbEsc(a.api_key_masked)}</span></div>
            </div>`;
    } else {
        box.className = 'rounded-xl border border-rose-100 bg-rose-50/60 p-4 text-sm';
        box.innerHTML = `
            <p class="flex items-center gap-2 font-bold text-rose-700 mb-1"><span class="material-symbols-outlined text-[20px]">sms_failed</span> No active SMS configuration</p>
            <p class="text-rose-600 text-xs">Disaster alerts are saved, but no SMS is sent to residents.</p>
            <p class="text-slate-500 text-xs mt-2">${smsConfigs.length ? 'Click <strong>Activate</strong> on a configuration below.' : 'Click <strong>Add Configuration</strong> below.'}</p>`;
    }
}

function renderSmsList() {
    const list = document.getElementById('smsConfigList');
    const count = document.getElementById('smsCount');
    count.textContent = smsConfigs.length;
    count.classList.toggle('hidden', !smsConfigs.length);
    document.getElementById('smsTestCard').classList.toggle('hidden', !smsConfigs.length);
    document.getElementById('smsTestConfig').innerHTML = smsConfigs.map(c =>
        `<option value="${c.id}" ${c.status === 'Active' ? 'selected' : ''}>${fbEsc(c.configuration_name)}${c.status === 'Active' ? ' (Active)' : ''}</option>`).join('');

    if (!smsConfigs.length) {
        list.innerHTML = `<div class="rounded-xl border border-dashed border-slate-200 p-6 text-center text-sm text-slate-400">No SMS configurations yet.</div>`;
        return;
    }
    list.innerHTML = smsConfigs.map(c => `
        <div class="rounded-xl border ${c.status === 'Active' ? 'border-emerald-100 bg-emerald-50/40' : 'border-slate-100 bg-slate-50'} p-4 flex flex-wrap items-center gap-4">
            <div class="flex-1 min-w-[220px]">
                <p class="font-bold text-slate-700 text-sm flex items-center gap-2">${fbEsc(c.configuration_name)}
                    ${c.status === 'Active' ? '<span class="text-[10px] font-bold uppercase tracking-wider bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full">Active</span>' : '<span class="text-[10px] font-bold uppercase tracking-wider bg-slate-200 text-slate-500 px-2 py-0.5 rounded-full">Inactive</span>'}</p>
                <p class="text-xs text-slate-500 mt-1">
                    <span class="font-mono">${fbEsc(c.from_number)}</span> · Device <span class="font-mono">${fbEsc(String(c.device_id).slice(0, 12))}${String(c.device_id).length > 12 ? '…' : ''}</span>
                    · Key <span class="font-mono">${fbEsc(c.api_key_masked)}</span> · ${fbEsc(c.api_host)}</p>
            </div>
            <div class="flex items-center gap-1.5">
                ${c.status === 'Active' ? '' : `<button type="button" onclick="activateSms(${c.id})" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-bold text-emerald-700 bg-emerald-50 hover:bg-emerald-100"><span class="material-symbols-outlined text-[16px]">bolt</span> Activate</button>`}
                <button type="button" onclick="openSmsForm(${c.id})" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-bold text-slate-600 bg-white border border-slate-200 hover:bg-slate-100"><span class="material-symbols-outlined text-[16px]">edit</span> Edit</button>
                <button type="button" onclick="deleteSms(${c.id})" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-bold text-rose-600 bg-rose-50 hover:bg-rose-100"><span class="material-symbols-outlined text-[16px]">delete</span> Delete</button>
            </div>
        </div>`).join('');
}

function openSmsForm(id) {
    const card = document.getElementById('smsFormCard');
    const f = document.getElementById('smsConfigForm');
    const c = id ? smsConfigs.find(x => x.id === id) : null;
    f.reset();
    f.config_id.value = c ? c.id : '';
    f.api_key.required = !c;
    document.getElementById('smsKeyHint').classList.toggle('hidden', !c);
    document.getElementById('smsFormTitle').textContent = c ? 'Edit Configuration' : 'Add Configuration';
    document.getElementById('smsFormIcon').textContent = c ? 'edit' : 'add_circle';
    if (c) {
        f.configuration_name.value = c.configuration_name;
        f.from_number.value = c.from_number;
        f.device_id.value = c.device_id;
        f.api_url.value = c.api_url;
        f.status.checked = c.status === 'Active';
    } else {
        f.status.checked = !smsConfigs.some(x => x.status === 'Active');
    }
    card.classList.remove('hidden');
    card.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function closeSmsForm() { document.getElementById('smsFormCard').classList.add('hidden'); }

async function withBtn(btn, fn) {
    btn.disabled = true; btn.classList.add('opacity-60');
    try { await fn(); } catch (e) { showToast('Could not reach the server.', 'error'); }
    finally { btn.disabled = false; btn.classList.remove('opacity-60'); }
}

function saveSmsConfig(e) {
    e.preventDefault();
    const f = e.target;
    withBtn(f.querySelector('[type=submit]'), async () => {
        const fd = new FormData(f);
        fd.append('action', 'save');
        const d = await smsPost(fd);
        showToast(fbEsc(d.message), d.success ? 'success' : 'error', d.success ? 4500 : 7000);
        if (d.success) { closeSmsForm(); loadSmsConfigs(); }
    });
}

async function activateSms(id) {
    const d = await smsPost({ action: 'activate', config_id: id });
    showToast(fbEsc(d.message), d.success ? 'success' : 'error');
    loadSmsConfigs();
}

async function deleteSms(id) {
    const c = smsConfigs.find(x => x.id === id);
    if (!confirm(`Delete the SMS configuration "${c ? c.configuration_name : ''}"?` + (c && c.status === 'Active' ? '\n\nIt is the ACTIVE one — disaster alerts will stop sending SMS until another is activated.' : ''))) return;
    const d = await smsPost({ action: 'delete', config_id: id });
    showToast(fbEsc(d.message), d.success ? 'success' : 'error');
    loadSmsConfigs();
}

function sendSmsTest(e) {
    e.preventDefault();
    const f = e.target;
    const btn = f.querySelector('[type=submit]');
    const html = btn.innerHTML;
    btn.innerHTML = '<span class="material-symbols-outlined text-[18px] animate-spin">sync</span> Sending…';
    withBtn(btn, async () => {
        const fd = new FormData(f);
        fd.append('action', 'test');
        const d = await smsPost(fd);
        showToast(fbEsc(d.message), d.success ? 'success' : 'error', d.success ? 6000 : 9000);
    }).finally(() => { btn.innerHTML = html; });
}

window.addEventListener('DOMContentLoaded', function () {
    if (new URLSearchParams(window.location.search).get('tab') === 'sms') loadSmsConfigs();
});

// ── Facebook Page connection (Settings → Facebook) ───────────────────────────
function fbEsc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

async function loadFbStatus() {
    const box = document.getElementById('fbStatusBox');
    if (!box) return;
    box.innerHTML = 'Checking the connection with Facebook…';
    try {
        const res = await fetch('../backend/fb_connection.php?action=status', { headers: { 'Accept': 'application/json' } });
        const d = await res.json();
        const uri = document.getElementById('fbRedirectUri');
        if (uri && d.redirect_uri) uri.textContent = d.redirect_uri;
        if (!d.success) throw new Error(d.error || 'Could not check the connection.');

        const typeLabel = d.token_type === 'system' ? 'System User token' : (d.token_type === 'login' ? 'Facebook Login' : '—');
        let expiry = '—';
        if (d.expires_at === 0) expiry = '<span class="text-emerald-600 font-bold">Never expires</span>';
        else if (d.expires_at) {
            const days = Math.round((d.expires_at * 1000 - Date.now()) / 86400000);
            expiry = new Date(d.expires_at * 1000).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })
                + ` <span class="${days <= 10 ? 'text-rose-600' : 'text-slate-400'}">(${days} day(s) left)</span>`;
        }

        if (d.connected) {
            box.className = 'rounded-xl border border-emerald-100 bg-emerald-50/60 p-4 text-sm';
            box.innerHTML = `
                <p class="flex items-center gap-2 font-bold text-emerald-700 mb-3"><span class="material-symbols-outlined text-[20px]">check_circle</span> Connected to ${fbEsc(d.page_name || 'the Page')}</p>
                <div class="grid sm:grid-cols-2 gap-x-6 gap-y-1.5 text-slate-600">
                    <div class="flex justify-between"><span class="text-slate-400">Page ID</span><span class="font-mono">${fbEsc(d.page_id)}</span></div>
                    <div class="flex justify-between"><span class="text-slate-400">Connected via</span><span>${typeLabel}</span></div>
                    <div class="flex justify-between"><span class="text-slate-400">Token expiry</span><span>${expiry}</span></div>
                    <div class="flex justify-between"><span class="text-slate-400">Connected</span><span>${fbEsc(d.obtained_at || '—')}${d.connected_by ? ' · ' + fbEsc(d.connected_by) : ''}</span></div>
                </div>
                ${d.missing_scopes.length ? `<p class="mt-3 text-rose-600 font-semibold text-xs">Missing permissions: ${fbEsc(d.missing_scopes.join(', '))} — reconnect and allow them.</p>` : ''}
                ${d.missing_optional.length ? `<p class="mt-2 text-amber-600 font-semibold text-xs">Views/reach on Announcement Analytics need the <code>read_insights</code> permission.</p>` : ''}`;
        } else {
            box.className = 'rounded-xl border border-rose-100 bg-rose-50/60 p-4 text-sm';
            box.innerHTML = `
                <p class="flex items-center gap-2 font-bold text-rose-700 mb-1"><span class="material-symbols-outlined text-[20px]">link_off</span> Not connected</p>
                <p class="text-rose-600 text-xs">${fbEsc(d.error || 'The Facebook Page is not connected.')}</p>
                <p class="text-slate-500 text-xs mt-2">Use <strong>Reconnect Facebook</strong> or save a <strong>System User token</strong> below.</p>`;
        }
    } catch (e) {
        box.className = 'rounded-xl border border-rose-100 bg-rose-50/60 p-4 text-sm text-rose-600';
        box.textContent = e.message || 'Could not check the connection.';
    }
}

async function saveFbSystemToken(e) {
    e.preventDefault();
    const form = e.target;
    const btn = form.querySelector('[type=submit]');
    btn.disabled = true;
    btn.classList.add('opacity-60');
    try {
        const res = await fetch('../backend/fb_connection.php', { method: 'POST', body: new FormData(form) });
        const d = await res.json();
        if (d.success) {
            form.reset();
            showToast(d.message, 'success');
            loadFbStatus();
        } else {
            showToast(d.error || 'Could not save the token.', 'error', 7000);
        }
    } catch (err) {
        showToast('Could not reach the server.', 'error');
    } finally {
        btn.disabled = false;
        btn.classList.remove('opacity-60');
    }
}

// Result of the Facebook Login redirect (?tab=facebook&fb=connected / &fb_error=…)
window.addEventListener('DOMContentLoaded', function () {
    const q = new URLSearchParams(window.location.search);
    if (q.get('tab') === 'facebook') loadFbStatus();
    if (q.get('fb') === 'connected') showToast('Facebook reconnected successfully.', 'success');
    if (q.get('fb_error')) showToast(fbEsc(q.get('fb_error')), 'error', 8000);
    if (q.has('fb') || q.has('fb_error')) {
        q.delete('fb'); q.delete('fb_error');
        history.replaceState(null, '', window.location.pathname + '?' + q.toString());
    }
});

// ── Auto-open security tab on password errors (non-AJAX fallback) ─────────────
<?php if (!empty($error) && isset($_POST['action']) && $_POST['action'] === 'change_password'): ?>
window.addEventListener('DOMContentLoaded', () =>
    switchTab('security', document.querySelector('[data-tab="security"]'))
);
<?php endif; ?>

// ── Auto flash PHP message as toast (non-AJAX fallback) ───────────────────────
<?php if (!empty($success)): ?>
window.addEventListener('DOMContentLoaded', () =>
    showToast(<?php echo json_encode($success); ?>, 'success')
);
<?php elseif (!empty($error)): ?>
window.addEventListener('DOMContentLoaded', () =>
    showToast(<?php echo json_encode($error); ?>, 'error')
);
<?php endif; ?>

// ── Init on DOMContentLoaded ──────────────────────────────────────────────────
window.addEventListener('DOMContentLoaded', function () {
    const phpAccent = <?php echo json_encode($prefs['accent_color']); ?>;
    const ls = tryGetLS();
    const currentHex = (ls.accentHex && /^#[0-9a-fA-F]{6}$/.test(ls.accentHex))
        ? ls.accentHex
        : (/^#[0-9a-fA-F]{6}$/.test(phpAccent) ? phpAccent : '#6366f1');

    applyAccentColor(currentHex);

    document.querySelectorAll('.quick-color-btn').forEach(function (btn) {
        btn.classList.toggle('active', btn.dataset.hex === currentHex.toLowerCase());
    });

    var label = document.getElementById('accentLabel');
    if (label) { label.textContent = currentHex.toUpperCase(); label.style.color = currentHex; }

    // ── Activate tab from URL ?tab= parameter ─────────────────────────────────
    var urlTab = new URLSearchParams(window.location.search).get('tab');
    if (urlTab) {
        var tabBtn = document.querySelector('[data-tab="' + urlTab + '"]');
        if (tabBtn) switchTab(urlTab, tabBtn);
    }
});
</script>

</body>
</html>
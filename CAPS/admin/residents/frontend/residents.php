<?php
/**
 * ADMIN/residents/frontend/residents.php
 * Resident Management — UI rendering only.
 * All queries, POST handling (approve/disapprove access requests, mail),
 * and stats are computed in ADMIN/residents/backend/residents.php (required below).
 */
require_once __DIR__ . '/../backend/residents.php';
require_once __DIR__ . '/../../permission_helper.php';
require_once __DIR__ . '/../../theme_loader.php';
?>
<!DOCTYPE html>
<html <?php echo $theme_attrs['html']; ?>>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resident Management — Barangay Biñang 2nd</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=DM+Mono:wght@400;500&display=swap"
        rel="stylesheet">
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
        rel="stylesheet">
    <?php
    // Residents module uses Google Maps only. Keep the browser key separate
    // from the server-side geocoding key.
    require_once __DIR__ . '/../../config.php';
    $googleMapsBrowserKey = getenv('GOOGLE_MAPS_BROWSER_KEY') ?: '';
    ?>
    <?php if ($googleMapsBrowserKey !== ''): ?>
        <script>
            // Google may execute the async callback before the Residents JS block
            // below has been parsed. Keep a safe bridge so initGoogleMap() never
            // becomes an "is not a function" race-condition error.
            window.__capsGoogleMapsLoaded = false;
            window.initGoogleMap = function () {
                window.__capsGoogleMapsLoaded = true;
                if (typeof window.initMap === 'function') window.initMap();
            };
        </script>
        <script async defer
            src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($googleMapsBrowserKey, ENT_QUOTES, 'UTF-8'); ?>&callback=initGoogleMap"></script>
    <?php endif; ?>
    <?php include __DIR__ . '/../../theme_head.php'; ?>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: { DEFAULT: 'var(--accent-600)', light: 'var(--accent-500)', dark: 'var(--accent-700)' },
                        accent: { DEFAULT: 'var(--accent-500)', light: 'var(--accent-400)' },
                    },
                    fontFamily: { sans: ['"Plus Jakarta Sans"', 'sans-serif'], mono: ['"DM Mono"', 'monospace'] }
                }
            }
        }
    </script>
    <style>
        :root {
            --sidebar-w: 288px;
            --nav-h: 64px;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--page-bg, #eef2fb);
            -webkit-font-smoothing: antialiased;
        }

        #location_pin_section.map-picker-open {
            position: fixed;
            inset: 0;
            z-index: 80;
            background: #fff;
            padding: 18px;
            overflow-y: auto;
        }

        #location_pin_section.map-picker-open #map {
            height: calc(100vh - 180px) !important;
            min-height: 420px;
        }

        #location_pin_section.map-picker-open #map_picker_header {
            display: flex !important;
        }

        #location_pin_section.map-picker-open .grid.grid-cols-2 {
            max-width: 720px;
            margin-left: auto;
            margin-right: auto;
        }

        #location_pin_section.map-picker-open>.col-span-12,
        #location_pin_section.map-picker-open>div.relative {
            max-width: 1100px;
            margin-left: auto;
            margin-right: auto;
        }


        .main-wrapper {
            margin-left: var(--sidebar-w);
            width: calc(100% - var(--sidebar-w));
        }

        @media (max-width: 1024px) {
            .main-wrapper {
                margin-left: 0;
                width: 100%;
            }
        }

        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 99px;
        }

        .section-title {
            font-size: .65rem;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #94a3b8;
        }

        input[type="text"]:not(#f_email) {
            text-transform: capitalize;
        }

        /* ── Accent-colored interactive elements ──────────────── */
        .btn-accent {
            background: var(--accent-600);
            color: #fff;
        }

        .btn-accent:hover {
            background: var(--accent-700);
        }

        .text-accent {
            color: var(--accent-600);
        }

        .border-accent {
            border-color: var(--accent-500);
        }

        .bg-accent-50 {
            background-color: var(--accent-50);
        }

        /* ── Dark mode overrides ──────────────────────────────── */
        html.dark body {
            background: #0f172a;
            color: #e2e8f0;
        }

        html.dark .bg-white {
            background-color: #1e293b !important;
        }

        html.dark .bg-slate-50 {
            background-color: #0f172a !important;
        }

        html.dark .bg-slate-100 {
            background-color: #1e293b !important;
        }

        html.dark .bg-\[\#eef2fb\] {
            background-color: #0f172a !important;
        }

        html.dark .text-slate-900 {
            color: #f1f5f9 !important;
        }

        html.dark .text-slate-800 {
            color: #e2e8f0 !important;
        }

        html.dark .text-slate-700 {
            color: #cbd5e1 !important;
        }

        html.dark .text-slate-600 {
            color: #94a3b8 !important;
        }

        html.dark .text-slate-500 {
            color: #64748b !important;
        }

        html.dark .border-slate-100 {
            border-color: #334155 !important;
        }

        html.dark .border-slate-200 {
            border-color: #334155 !important;
        }

        html.dark .divide-y>*+* {
            border-color: #334155 !important;
        }

        html.dark table thead {
            background-color: #0f172a !important;
        }

        html.dark table thead th {
            color: #94a3b8 !important;
            border-color: #334155 !important;
        }

        html.dark .shadow-sm,
        html.dark .shadow {
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.4) !important;
        }

        html.dark .rounded-2xl.bg-white,
        html.dark .rounded-xl.bg-white {
            background-color: #1e293b !important;
        }

        html.dark input,
        html.dark select,
        html.dark textarea {
            background-color: #0f172a !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        html.dark .bg-slate-100.rounded-xl {
            background-color: #0f172a !important;
        }

        /* Email input icon: keep the mail icon precisely centered in the 48px field. */
        .email-icon {
            position: absolute;
            left: 16px;
            top: 11px;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            line-height: 20px;
            pointer-events: none;
        }
    </style>
</head>

<body <?php echo $theme_attrs['body']; ?>>

    <div class="flex min-h-screen">

        <?php include __DIR__ . '/../../sidebar.php'; ?>

        <div class="flex-1 flex flex-col min-w-0 main-wrapper">

            <?php include __DIR__ . '/../../header.php'; ?>

            <main class="p-4 md:p-6 lg:p-8 space-y-8">

                <!-- ── Hero Band (unchanged) ───────────────────────────────── -->
                <div class="rounded-2xl p-6 md:p-8 text-white relative overflow-hidden"
                    style="background: linear-gradient(135deg, var(--accent-700) 0%, var(--accent-600) 50%, var(--accent-700) 100%);">
                    <div class="absolute -right-12 -top-12 w-64 h-64 opacity-10 rounded-full blur-3xl pointer-events-none"
                        style="background: var(--accent-400);"></div>
                    <div class="absolute left-1/3 bottom-0 w-48 h-48 opacity-10 rounded-full blur-2xl pointer-events-none"
                        style="background: var(--accent-300);"></div>
                    <div class="relative z-10 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div>
                            <h1 class="text-2xl md:text-3xl font-black tracking-tight leading-none">Resident Management
                            </h1>
                            <p class="text-white/60 text-sm mt-2 font-medium">Manage and monitor residency data for
                                Barangay Biñang 2nd.</p>
                        </div>
                        <div class="flex gap-3 flex-shrink-0">
                            <a href="analytics_resident.php"
                                class="flex items-center gap-2 bg-white/10 hover:bg-white/20 border border-white/20 text-white px-5 py-2.5 rounded-xl font-bold text-xs uppercase tracking-wider transition-all">
                                <span class="material-symbols-outlined text-lg">analytics</span>
                                Analytics
                            </a>
                            <?php if (staff_can($pdo, 'residents', 'update')): ?>
                                <button onclick="openManageAreaModal()"
                                    class="flex items-center gap-2 bg-white/10 hover:bg-white/20 border border-white/20 text-white px-5 py-2.5 rounded-xl font-bold text-xs uppercase tracking-wider transition-all">
                                    <span class="material-symbols-outlined text-lg">location_city</span>
                                    Manage Area
                                </button>
                            <?php endif; ?>
                            <?php if (staff_can($pdo, 'residents', 'create')): ?>
                                <button onclick="openAddModal()"
                                    class="flex items-center gap-2 bg-accent hover:bg-accent-light text-white px-5 py-2.5 rounded-xl font-bold text-xs uppercase tracking-wider transition-all shadow-lg">
                                    <span class="material-symbols-outlined text-lg">person_add</span>
                                    Register Resident
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- ── Search & Filter Row — extended with res_search ─────── -->
                <div class="grid grid-cols-12 gap-4 mb-8">
                    <!-- Search now submits as GET so pagination works with search -->
                    <form method="GET" action="" class="col-span-8 relative" id="residentSearchForm">
                        <?php foreach ($_GET as $k => $v):
                            if ($k === 'res_search' || $k === 'res_page')
                                continue; ?>
                            <input type="hidden" name="<?= htmlspecialchars($k) ?>"
                                value="<?= htmlspecialchars((string) $v) ?>">
                        <?php endforeach; ?>
                        <span
                            class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-xl">search</span>
                        <input type="text" name="res_search" id="residentSearch"
                            value="<?= htmlspecialchars($resSearch) ?>" onkeyup="filterResidents()"
                            placeholder="Search residents by name, email, contact…"
                            class="w-full pl-11 pr-4 py-2.5 bg-white border border-slate-200 rounded-2xl text-sm focus:outline-none focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all shadow-sm">
                    </form>
                    <div class="col-span-2">
                        <select id="rep_sex" onchange="filterResidents()"
                            class="w-full border-slate-200 rounded-2xl py-2.5 text-sm font-bold text-slate-600 focus:ring-indigo-500 transition-all shadow-sm">
                            <option value="">All Genders</option>
                            <option value="MALE">Male</option>
                            <option value="FEMALE">Female</option>
                        </select>
                    </div>
                    <div class="col-span-2">
                        <select id="rep_class" onchange="filterResidents()"
                            class="w-full border-slate-200 rounded-2xl py-2.5 text-sm font-bold text-slate-600 focus:ring-indigo-500 transition-all shadow-sm">
                            <option value="">All Types</option>
                            <option value="HEAD">Family Head</option>
                            <option value="SENIOR">Senior</option>
                            <option value="PWD">PWD</option>
                        </select>
                    </div>
                </div>

                <!-- ── Stats Cards (unchanged) ───────────────────────────── -->
                <div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-8">
                    <?php
                    $stats = [
                        ['Total Population', $total_pop, 'groups', 'bg-indigo-50', 'text-indigo-600'],
                        ['Seniors', $seniors, 'military_tech', 'bg-amber-50', 'text-amber-600'],
                        ['PWDs', $pwds, 'accessibility_new', 'bg-rose-50', 'text-rose-600'],
                        ['Heads', $heads, 'home', 'bg-emerald-50', 'text-emerald-600'],
                        ['Deceased', $deceased, 'person_off', 'bg-slate-100', 'text-slate-500']
                    ];
                    foreach ($stats as $stat):
                        ?>
                        <div class="bg-white p-6 rounded-[32px] border border-slate-100 shadow-sm">
                            <div
                                class="w-10 h-10 <?= $stat[3] ?> <?= $stat[4] ?> rounded-xl flex items-center justify-center mb-4">
                                <span class="material-symbols-outlined"><?= $stat[2] ?></span>
                            </div>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest"><?= $stat[0] ?></p>
                            <h3 class="text-2xl font-bold text-slate-800 mt-1"><?= number_format($stat[1]) ?></h3>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- ══════════════════════════════════════════════════════════
                 RESIDENT TABLE — pagination-aware
                 Table structure / thead / styling: 100% original
                 tbody now renders $pagedResidents (5 per page)
            ═══════════════════════════════════════════════════════════ -->
                <div class="bg-white rounded-[32px] shadow-sm border border-slate-100 overflow-hidden">
                    <table class="w-full text-left border-collapse" id="residentTable">
                        <thead>
                            <tr
                                class="bg-slate-50/50 text-[10px] font-bold text-slate-400 uppercase tracking-widest border-b border-slate-50">
                                <th class="px-8 py-5">Resident Info</th>
                                <th class="px-6 py-5">Location & Contact</th>
                                <th class="px-6 py-5">Classifications</th>
                                <th class="px-8 py-5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50" id="residentTableBody">
                            <?php if (empty($pagedResidents)): ?>
                                <tr>
                                    <td colspan="4" class="text-center py-16 text-slate-400">
                                        <span
                                            class="material-symbols-outlined text-4xl block mb-2 text-slate-200">manage_search</span>
                                        <?= $resSearch !== '' ? 'No residents match your search.' : 'No residents found.' ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($pagedResidents as $res): ?>
                                    <tr class="hover:bg-slate-50/50 transition-colors group"
                                        data-fname="<?= htmlspecialchars($res['FirstName']) ?>"
                                        data-mname="<?= htmlspecialchars($res['MiddleName'] ?? '') ?>"
                                        data-lname="<?= htmlspecialchars($res['LastName']) ?>"
                                        data-bdate="<?= $res['BirthDate'] ?>">
                                        <td class="px-8 py-5">
                                            <div class="flex items-center gap-3">
                                                <div
                                                    class="w-9 h-9 bg-slate-100 text-slate-500 rounded-xl flex items-center justify-center font-bold text-[10px]">
                                                    <?= substr($res['FirstName'], 0, 1) . substr($res['LastName'], 0, 1) ?>
                                                </div>
                                                <div>
                                                    <p class="text-sm font-bold text-slate-700 leading-tight name-cell">
                                                        <?= htmlspecialchars($res['FullName']) ?></p>
                                                    <p
                                                        class="text-[10px] text-slate-400 font-bold uppercase tracking-tighter mt-0.5 gender-cell">
                                                        <?= !empty($res['ResidentCode']) ? htmlspecialchars($res['ResidentCode']) : 'ID: #' . $res['ResidentID'] ?>
                                                        | <span class="gender-val"><?= strtoupper($res['Sex']) ?></span>
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-5">
                                            <div class="text-xs font-semibold text-slate-700 location-cell">
                                                #<?= htmlspecialchars($res['HouseNumber'] ?? '') ?>,
                                                <?= htmlspecialchars($res['StreetName'] ?? '') ?>        <?= !empty($res['Purok']) ? ', ' . htmlspecialchars($res['Purok']) : '' ?>
                                            </div>
                                            <div class="text-[10px] text-slate-400 font-bold flex items-center gap-1 mt-1">
                                                <span class="material-symbols-outlined text-[12px]">call</span>
                                                <?= htmlspecialchars($res['ContactNumber'] ?? '') ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-5">
                                            <div class="flex flex-wrap gap-1 classification-cell">
                                                <?php if ($res['IsHead']): ?>
                                                    <span
                                                        class="px-2 py-0.5 bg-indigo-50 text-indigo-600 text-[9px] font-bold rounded-md uppercase border border-indigo-100">Head</span>
                                                <?php elseif (!empty($res['FamilyHeadID'])): ?>
                                                    <span
                                                        class="px-2 py-0.5 bg-emerald-50 text-emerald-600 text-[9px] font-bold rounded-md uppercase border border-emerald-100">Member</span>
                                                <?php endif; ?>
                                                <?php if ($res['IsPWD']): ?><span
                                                        class="px-2 py-0.5 bg-blue-50 text-blue-600 text-[9px] font-bold rounded-md uppercase border border-blue-100">PWD</span><?php endif; ?>
                                                <?php if ($res['IsSenior']): ?><span
                                                        class="px-2 py-0.5 bg-amber-50 text-amber-600 text-[9px] font-bold rounded-md uppercase border border-amber-100">Senior</span><?php endif; ?>
                                                <?php if ($res['IsDeceased']): ?><span
                                                        class="px-2 py-0.5 bg-rose-50 text-rose-600 text-[9px] font-bold rounded-md uppercase border border-rose-100">Dead</span><?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-8 py-5 text-right">
                                            <div
                                                class="flex justify-end gap-2 opacity-0 group-hover:opacity-100 transition-all">
                                                <?php if (staff_can($pdo, 'residents', 'update')): ?>
                                                    <button type="button" onclick="editResidentFromButton(this)"
                                                        data-resident="<?= htmlspecialchars(base64_encode(json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), ENT_QUOTES, 'UTF-8') ?>"
                                                        class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all">
                                                        <span class="material-symbols-outlined text-xl">edit_square</span>
                                                    </button>
                                                <?php endif; ?>
                                                <?php if (staff_can($pdo, 'residents', 'delete') && !$res['IsDeceased']): ?>
                                                    <button
                                                        onclick="confirmDelete('<?= $res['ResidentID'] ?>', '<?= addslashes($res['FullName']) ?>')"
                                                        class="p-2 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-all">
                                                        <span class="material-symbols-outlined text-xl">delete</span>
                                                    </button>
                                                <?php elseif (staff_can($pdo, 'residents', 'delete') && $res['IsDeceased']): ?>
                                                    <span class="p-2 text-slate-200 cursor-not-allowed"
                                                        title="Deceased residents are kept as a historical record and cannot be deleted.">
                                                        <span class="material-symbols-outlined text-xl">delete</span>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <!-- ── Pagination Controls ──────────────────────────────── -->
                    <?php if ($totalResPages > 1): ?>
                        <div
                            class="flex flex-col sm:flex-row items-center justify-between gap-3 px-8 py-4 border-t border-slate-50">
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                                Showing <strong class="text-slate-600"><?= $resOffset + 1 ?></strong>–<strong
                                    class="text-slate-600"><?= min($resOffset + RESIDENTS_PER_PAGE, $totalResidents) ?></strong>
                                of <strong class="text-slate-600"><?= $totalResidents ?></strong> residents
                            </p>
                            <nav class="flex items-center gap-1">
                                <!-- Previous -->
                                <?php $prevParams = array_merge($_GET, ['res_page' => $resPage - 1]); ?>
                                <a href="?<?= http_build_query($prevParams) ?>"
                                    class="flex items-center gap-1 px-3 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all
                                  <?= $resPage <= 1 ? 'pointer-events-none text-slate-300 bg-slate-50' : 'text-slate-500 bg-white border border-slate-200 hover:border-indigo-300 hover:text-indigo-600' ?>">
                                    <span class="material-symbols-outlined text-sm">chevron_left</span> Prev
                                </a>

                                <?php
                                $window = 2;
                                for ($p = 1; $p <= $totalResPages; $p++):
                                    $showPage = ($p === 1 || $p === $totalResPages || abs($p - $resPage) <= $window);
                                    $isEllipsis = (!$showPage && abs($p - $resPage) === $window + 1);
                                    ?>
                                    <?php if ($showPage): ?>
                                        <?php $pageParams = array_merge($_GET, ['res_page' => $p]); ?>
                                        <a href="?<?= http_build_query($pageParams) ?>"
                                            class="px-3 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all
                                          <?= $p === $resPage
                                              ? 'bg-primary text-white shadow-md'
                                              : 'text-slate-500 bg-white border border-slate-200 hover:border-indigo-300 hover:text-indigo-600' ?>">
                                            <?= $p ?>
                                        </a>
                                    <?php elseif ($isEllipsis): ?>
                                        <span class="px-2 py-1.5 text-[10px] text-slate-300 font-bold">…</span>
                                    <?php endif; ?>
                                <?php endfor; ?>

                                <!-- Next -->
                                <?php $nextParams = array_merge($_GET, ['res_page' => $resPage + 1]); ?>
                                <a href="?<?= http_build_query($nextParams) ?>"
                                    class="flex items-center gap-1 px-3 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all
                                  <?= $resPage >= $totalResPages ? 'pointer-events-none text-slate-300 bg-slate-50' : 'text-slate-500 bg-white border border-slate-200 hover:border-indigo-300 hover:text-indigo-600' ?>">
                                    Next <span class="material-symbols-outlined text-sm">chevron_right</span>
                                </a>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>
                <!-- END RESIDENT TABLE -->

                <!-- ══════════════════════════════════════════════════════════
                 ACCESS REQUESTS SECTION
                 Placed directly after the resident table card.
                 Does NOT affect the resident table above in any way.
            ═══════════════════════════════════════════════════════════ -->
                <div class="bg-white rounded-[32px] shadow-sm border border-slate-100 overflow-hidden"
                    id="accessRequestsSection">

                    <!-- Card Header -->
                    <div
                        class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 px-8 py-5 border-b border-slate-50">
                        <div class="flex items-center gap-3">
                            <div
                                class="w-10 h-10 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center">
                                <span class="material-symbols-outlined text-xl">shield_person</span>
                            </div>
                            <div>
                                <h2 class="text-sm font-black text-slate-800 uppercase tracking-tight">Portal Access
                                    Requests</h2>
                                <p class="text-[10px] text-slate-400 font-bold mt-0.5">Review and manage resident portal
                                    access applications</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <?php if ($pendingCount > 0): ?>
                                <span
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-amber-50 text-amber-600 text-[10px] font-black uppercase tracking-widest border border-amber-100">
                                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                                    <?= $pendingCount ?> Pending
                                </span>
                            <?php endif; ?>
                            <button onclick="refreshAccessRequests()"
                                class="flex items-center gap-2 px-3 py-1.5 rounded-xl border border-slate-200 text-[10px] font-black uppercase text-slate-500 hover:border-indigo-300 hover:text-indigo-600 transition-all">
                                <span class="material-symbols-outlined text-sm" id="arRefreshIcon">sync</span> Refresh
                            </button>
                        </div>
                    </div>

                    <!-- Alert area -->
                    <div id="arAlert" class="hidden px-8 pt-4"></div>

                    <!-- Table -->
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr
                                    class="bg-slate-50/50 text-[10px] font-bold text-slate-400 uppercase tracking-widest border-b border-slate-50">
                                    <th class="px-8 py-4">#</th>
                                    <th class="px-6 py-4">Full Name</th>
                                    <th class="px-6 py-4">Email</th>
                                    <th class="px-6 py-4">Request Date</th>
                                    <th class="px-6 py-4">Status</th>
                                    <th class="px-8 py-4 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50" id="accessRequestTableBody">
                                <?php if (empty($accessRequests)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-16 text-slate-400">
                                            <span
                                                class="material-symbols-outlined text-4xl block mb-2 text-slate-200">inbox</span>
                                            No access requests found.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($accessRequests as $i => $ar): ?>
                                        <tr class="hover:bg-slate-50/50 transition-colors group" data-req-id="<?= $ar['id'] ?>">
                                            <td class="px-8 py-4 text-[11px] font-bold text-slate-400"><?= $i + 1 ?></td>
                                            <td class="px-6 py-4">
                                                <p class="text-sm font-bold text-slate-700">
                                                    <?= htmlspecialchars($ar['fullname']) ?></p>
                                            </td>
                                            <td class="px-6 py-4 text-xs text-slate-500 font-medium">
                                                <?= htmlspecialchars($ar['email']) ?></td>
                                            <td class="px-6 py-4 text-[11px] text-slate-400 font-bold whitespace-nowrap">
                                                <?= date('M d, Y g:i A', strtotime($ar['created_at'])) ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <?php
                                                $badgeCls = match ($ar['status']) {
                                                    'Pending' => 'bg-amber-50 text-amber-600 border-amber-100',
                                                    'Approved' => 'bg-emerald-50 text-emerald-600 border-emerald-100',
                                                    'Disapproved' => 'bg-rose-50 text-rose-600 border-rose-100',
                                                    default => 'bg-slate-50 text-slate-500 border-slate-100',
                                                };
                                                ?>
                                                <span
                                                    class="px-2.5 py-1 text-[9px] font-black uppercase rounded-lg border <?= $badgeCls ?> tracking-wider">
                                                    <?= htmlspecialchars($ar['status']) ?>
                                                </span>
                                            </td>
                                            <td class="px-8 py-4 text-right">
                                                <div class="flex justify-end gap-2 flex-wrap">
                                                    <a href="access_requests.php?id=<?= $ar['id'] ?>"
                                                        class="flex items-center gap-1 px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-600 text-[9px] font-black uppercase rounded-xl transition-all border border-indigo-100">
                                                        <span class="material-symbols-outlined text-sm">visibility</span> View
                                                        Details
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="px-8 py-3 border-t border-slate-50 flex items-center justify-between">
                        <p class="text-[9px] font-bold text-slate-300 uppercase tracking-widest">
                            Auto-refreshes every 60 s &bull; Last: <span id="arLastRefresh">just now</span>
                        </p>
                    </div>
                </div>
                <!-- END ACCESS REQUESTS SECTION -->

            </main>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════
     EXISTING MODALS — 100% unchanged below this line
════════════════════════════════════════════════════════════════════ -->

    <!-- Resident Add/Edit Modal -->
    <div id="resModal"
        class="fixed inset-0 z-50 hidden bg-slate-900/80 modal-blur flex items-center justify-center p-4">
        <div class="bg-white rounded-[2.5rem] shadow-2xl w-full max-w-4xl p-10 space-y-6 overflow-y-auto max-h-[95vh]">
            <div class="flex justify-between items-start border-b border-slate-100 pb-6">
                <div>
                    <h3 id="modalTitle" class="text-2xl font-black text-slate-900 tracking-tight">Community Profiling
                    </h3>
                    <p class="text-xs text-primary font-bold uppercase tracking-widest mt-1">Resident Data Entry &
                        Management</p>
                </div>
                <button onclick="closeModal('resModal')"
                    class="p-2 hover:bg-orange-50 rounded-full text-slate-400 hover:text-primary transition-colors">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>

            <form id="residentForm" action="../backend/process_resident.php" method="POST" enctype="multipart/form-data"
                class="space-y-10">
                <?php echo csrf_token(); ?>
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="ResidentID" id="f_res_id">
                <input type="hidden" name="ResidentCode" id="f_resident_code">

                <div class="space-y-5">
                    <div class="flex items-center gap-3">
                        <span
                            class="material-symbols-outlined text-white bg-primary p-1.5 rounded-lg text-sm shadow-md shadow-orange-100">person</span>
                        <h4 class="text-sm font-black text-primary uppercase tracking-tight">Personal Profile</h4>
                    </div>
                    <div class="grid grid-cols-12 gap-4">
                        <div class="col-span-12 md:col-span-4 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Last Name *</label>
                            <input type="text" name="LastName" id="f_last" required oninput="capitalizeFirst(this)"
                                class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold focus:ring-2 focus:ring-primary/20">
                        </div>
                        <div class="col-span-12 md:col-span-4 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">First Name *</label>
                            <input type="text" name="FirstName" id="f_first" required oninput="capitalizeFirst(this)"
                                class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold focus:ring-2 focus:ring-primary/20">
                        </div>
                        <div class="col-span-12 md:col-span-3 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Middle Name</label>
                            <input type="text" name="MiddleName" id="f_middle" oninput="capitalizeFirst(this)"
                                class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold focus:ring-2 focus:ring-primary/20">
                        </div>
                        <div class="col-span-12 md:col-span-1 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Suffix</label>
                            <input type="text" name="Suffix" id="f_suffix" oninput="capitalizeFirst(this)"
                                class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold text-center focus:ring-2 focus:ring-primary/20">
                        </div>
                    </div>
                    <div class="grid grid-cols-12 gap-4">
                        <div class="col-span-6 md:col-span-3 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Gender *</label>
                            <select name="Sex" id="f_sex" required
                                class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold focus:ring-2 focus:ring-primary/20">
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>

                        <div class="col-span-6 md:col-span-3 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase">Date of Birth *</label>
                            <input type="date" name="BirthDate" id="f_dob" onchange="calculateAge()" required
                                max="9999-12-31"
                                class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold">
                        </div>
                        <div class="col-span-4 md:col-span-2 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase">Age</label>
                            <input type="text" id="f_age" readonly
                                class="w-full bg-slate-50 border-none rounded-xl py-3 px-4 text-sm font-black text-center text-slate-500">
                        </div>

                        <div class="col-span-8 md:col-span-4 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Civil Status *</label>
                            <select name="CivilStatus" id="f_civil_status"
                                class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold focus:ring-2 focus:ring-primary/20">
                                <option value="Single">Single</option>
                                <option value="Married">Married</option>
                                <option value="Widowed">Widowed</option>
                                <option value="Separated">Separated</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-4">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Place of Birth *</label>
                            <input type="text" name="BirthPlace" id="f_pob" required oninput="capitalizeFirst(this)"
                                class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold focus:ring-2 focus:ring-primary/20">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Nationality</label>
                            <input type="text" name="Nationality" id="f_nationality" oninput="capitalizeFirst(this)"
                                placeholder=""
                                class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold focus:ring-2 focus:ring-primary/20">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Religion</label>
                            <input type="text" name="Religion" id="f_religion" oninput="capitalizeFirst(this)"
                                placeholder=""
                                class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold focus:ring-2 focus:ring-primary/20">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Email Address</label>
                            <div class="relative">
                                <span class="material-symbols-outlined email-icon" aria-hidden="true">mail</span>
                                <input type="email" name="Email" id="f_email"
                                    class="w-full bg-slate-100 border-none rounded-xl py-3 pl-12 pr-4 text-sm font-bold focus:ring-2 focus:ring-primary/20"
                                    placeholder="resident@example.com" autocomplete="email">
                                <p class="text-[9px] text-slate-400 ml-1 mt-1">Staff/Admin can enter or update the
                                    resident's email. Duplicate email addresses are not allowed.</p>
                            </div>
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Contact Number
                                (Optional)</label>
                            <div class="relative">
                                <span
                                    class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-sm">call</span>
                                <input type="text" name="ContactNumber" id="f_contact" pattern="\d{11}" maxlength="11"
                                    oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                                    class="w-full bg-slate-100 border-none rounded-xl py-3 pl-11 pr-4 text-sm font-bold focus:ring-2 focus:ring-primary/20"
                                    placeholder="09XXXXXXXXX">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-5">
                    <div class="flex items-center gap-3">
                        <span
                            class="material-symbols-outlined text-white bg-primary p-1.5 rounded-lg text-sm shadow-md shadow-orange-100">location_on</span>
                        <h4 class="text-sm font-black text-primary uppercase tracking-tight">Address Information</h4>
                    </div>
                    <p id="resident_default_address_note"
                        class="text-[10px] font-bold text-slate-500 bg-indigo-50 border border-indigo-100 rounded-2xl px-4 py-3">
                        Default barangay address is managed centrally. Configure it in <strong>Manage Area</strong>; it
                        will be applied automatically to every resident.
                    </p>

                    <!-- Centralized address hierarchy: visible for confirmation, but not editable here. -->
                    <div class="grid grid-cols-12 gap-4 rounded-2xl border border-indigo-100 bg-indigo-50/40 p-4">
                        <div class="col-span-12 md:col-span-6 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Region</label>
                            <input type="text" id="f_region_display" readonly placeholder="Auto-filled from Manage Area"
                                class="w-full bg-white border border-slate-200 rounded-xl py-3 px-4 text-sm font-bold text-slate-700 cursor-not-allowed">
                        </div>
                        <div class="col-span-12 md:col-span-6 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Province</label>
                            <input type="text" id="f_province_display" readonly
                                placeholder="Auto-filled from Manage Area"
                                class="w-full bg-white border border-slate-200 rounded-xl py-3 px-4 text-sm font-bold text-slate-700 cursor-not-allowed">
                        </div>
                        <div class="col-span-12 md:col-span-6 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">City/Municipality</label>
                            <input type="text" id="f_municipality_display" readonly
                                placeholder="Auto-filled from Manage Area"
                                class="w-full bg-white border border-slate-200 rounded-xl py-3 px-4 text-sm font-bold text-slate-700 cursor-not-allowed">
                        </div>
                        <div class="col-span-12 md:col-span-6 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Barangay</label>
                            <input type="text" id="f_barangay_display" readonly
                                placeholder="Auto-filled from Manage Area"
                                class="w-full bg-white border border-slate-200 rounded-xl py-3 px-4 text-sm font-bold text-slate-700 cursor-not-allowed">
                        </div>
                    </div>

                    <div class="grid grid-cols-12 gap-4">
                        <div class="col-span-12 md:col-span-6 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">House/Lot/Unit
                                Number</label>
                            <input type="text" name="HouseNumber" id="f_house_no"
                                placeholder="e.g. 123, Block 5 Lot 2, Unit 4A"
                                oninput="checkHeadAvailability(); capitalizeFirst(this);" required
                                class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold focus:ring-2 focus:ring-primary/20">
                        </div>
                        <div class="col-span-12 md:col-span-6 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Building Name</label>
                            <input type="text" name="BuildingName" id="f_building_name" placeholder="Optional"
                                oninput="capitalizeFirst(this);"
                                class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold focus:ring-2 focus:ring-primary/20">
                        </div>
                        <div class="hidden">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Region</label>
                            <select name="RegionName" id="f_region" class="addr-select" disabled>
                                <option value="">Loading regions...</option>
                            </select>
                            <input type="hidden" name="PSGCRegionCode" id="f_region_code">
                        </div>
                        <div class="hidden">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Province</label>
                            <select name="ProvinceName" id="f_province" class="addr-select" disabled>
                                <option value="">Select a region first</option>
                            </select>
                            <input type="hidden" name="PSGCProvinceCode" id="f_province_code">
                        </div>
                        <div class="hidden">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">City/Municipality</label>
                            <select name="CityMunicipalityName" id="f_municipality" class="addr-select" disabled>
                                <option value="">Select a province first</option>
                            </select>
                            <input type="hidden" name="PSGCMunicipalityCode" id="f_municipality_code">
                        </div>
                        <div class="hidden">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Barangay</label>
                            <select name="BarangayName" id="f_barangay" class="addr-select" disabled>
                                <option value="">Select a city/municipality first</option>
                            </select>
                            <input type="hidden" name="PSGCBarangayCode" id="f_barangay_code">
                        </div>
                        <div class="col-span-12 md:col-span-6 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Street</label>
                            <select name="StreetName" id="f_street" class="addr-select" disabled
                                onchange="checkHeadAvailability()">
                                <option value="">Select a barangay first</option>
                            </select>
                        </div>
                        <div class="col-span-12 md:col-span-6 space-y-1.5">
                            <label
                                class="text-[10px] font-bold text-slate-400 uppercase ml-1">Subdivision/Village/Sitio/Purok</label>
                            <select name="AreaName" id="f_area" class="addr-select" disabled>
                                <option value="">Select a barangay first</option>
                            </select>
                            <input type="hidden" name="AreaType" id="f_area_type">
                            <input type="hidden" name="Purok" id="f_purok">
                        </div>
                        <div class="col-span-12 md:col-span-6 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">ZIP Code</label>
                            <input type="text" name="ZipCode" id="f_zip" maxlength="10" readonly
                                placeholder="Auto-filled from Manage Area"
                                class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold focus:ring-2 focus:ring-primary/20">
                        </div>
                    </div>

                    <div id="hd_checking_bar" class="hidden flex items-center gap-2 px-4 py-2 bg-slate-100 rounded-xl">
                        <svg class="animate-spin h-3.5 w-3.5 text-indigo-500 shrink-0"
                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                            </circle>
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 22 6.477 22 12h-4z"></path>
                        </svg>
                        <p class="text-[9px] font-black text-slate-500 uppercase tracking-widest">Checking address for
                            existing household…</p>
                    </div>

                    <div id="household_detection_panel"
                        class="hidden overflow-hidden rounded-2xl border-2 border-indigo-200 shadow-md">
                        <div class="flex items-center gap-3 px-5 py-3.5 bg-indigo-600 text-white">
                            <span class="material-symbols-outlined text-base">home_pin</span>
                            <div>
                                <p class="text-[10px] font-black uppercase tracking-widest leading-none">Existing
                                    Household Detected</p>
                                <p class="text-[8px] text-indigo-200 font-bold mt-0.5">This address is already
                                    registered to an existing household.</p>
                            </div>
                            <span id="hd_status_badge"
                                class="ml-auto px-2.5 py-1 bg-emerald-400 text-emerald-900 text-[8px] font-black uppercase rounded-full tracking-wider">Active</span>
                        </div>
                        <div class="bg-indigo-50/40 p-5 space-y-4">
                            <div class="grid grid-cols-3 gap-3">
                                <div
                                    class="col-span-3 md:col-span-1 bg-white rounded-2xl p-4 border border-indigo-100 flex items-center gap-3 shadow-sm">
                                    <div
                                        class="w-9 h-9 bg-indigo-100 rounded-xl flex items-center justify-center shrink-0">
                                        <span class="material-symbols-outlined text-indigo-600 text-base">person</span>
                                    </div>
                                    <div class="min-w-0">
                                        <p
                                            class="text-[8px] font-black text-indigo-400 uppercase tracking-widest mb-0.5">
                                            Household Head</p>
                                        <p id="hd_head_name"
                                            class="text-xs font-black text-slate-800 leading-tight truncate">—</p>
                                    </div>
                                </div>
                                <div
                                    class="col-span-3 md:col-span-1 bg-white rounded-2xl p-4 border border-indigo-100 flex items-center gap-3 shadow-sm">
                                    <div
                                        class="w-9 h-9 bg-indigo-100 rounded-xl flex items-center justify-center shrink-0">
                                        <span class="material-symbols-outlined text-indigo-600 text-base">home</span>
                                    </div>
                                    <div class="min-w-0">
                                        <p
                                            class="text-[8px] font-black text-indigo-400 uppercase tracking-widest mb-0.5">
                                            Address</p>
                                        <p id="hd_address"
                                            class="text-xs font-bold text-slate-700 leading-tight truncate">—</p>
                                    </div>
                                </div>
                                <div
                                    class="col-span-3 md:col-span-1 bg-white rounded-2xl p-4 border border-indigo-100 flex items-center gap-3 shadow-sm">
                                    <div
                                        class="w-9 h-9 bg-indigo-100 rounded-xl flex items-center justify-center shrink-0">
                                        <span
                                            class="material-symbols-outlined text-indigo-600 text-base">location_on</span>
                                    </div>
                                    <div class="min-w-0">
                                        <p
                                            class="text-[8px] font-black text-indigo-400 uppercase tracking-widest mb-0.5">
                                            Purok</p>
                                        <p id="hd_purok" class="text-xs font-bold text-slate-700 leading-tight">—</p>
                                    </div>
                                </div>
                            </div>
                            <div
                                class="flex items-center gap-3 bg-white rounded-2xl px-4 py-3 border border-indigo-100 shadow-sm">
                                <span class="material-symbols-outlined text-indigo-400 text-base">group</span>
                                <p class="text-[9px] font-black text-slate-500 uppercase tracking-widest">Current
                                    Household Members:</p>
                                <span id="hd_member_count"
                                    class="ml-auto px-2.5 py-0.5 bg-indigo-100 text-indigo-700 text-[10px] font-black rounded-full">—</span>
                            </div>
                            <div id="hd_multiple_heads_state"
                                class="hidden rounded-2xl border-2 border-amber-200 bg-amber-50 p-4 space-y-3">
                                <div class="flex items-start gap-3">
                                    <span
                                        class="material-symbols-outlined text-amber-600 text-base mt-0.5">warning</span>
                                    <div>
                                        <p class="text-[10px] font-black text-amber-800 uppercase tracking-widest">
                                            Multiple Heads of Family were found at this address.</p>
                                        <p class="text-[9px] font-semibold text-amber-700 mt-1">Do not automatically
                                            choose one. Select the correct Head below, then confirm.</p>
                                    </div>
                                </div>
                                <div id="hd_multiple_heads_list" class="space-y-2"></div>
                            </div>

                            <div id="hd_no_head_state"
                                class="hidden rounded-2xl border-2 border-slate-200 bg-white p-4 space-y-2">
                                <div class="flex items-start gap-3">
                                    <span class="material-symbols-outlined text-slate-500 text-base mt-0.5">group</span>
                                    <div>
                                        <p class="text-[10px] font-black text-slate-700 uppercase tracking-widest">
                                            Residents Found at This Address</p>
                                        <p id="hd_no_head_residents"
                                            class="text-[9px] font-semibold text-slate-500 mt-1">Residents were found,
                                            but no Head of Family has been assigned to this address.</p>
                                        <p class="text-[9px] font-bold text-slate-600 mt-1">No household link was
                                            applied. Staff/Admin may register this resident as Head of Family or resolve
                                            the existing household manually.</p>
                                    </div>
                                </div>
                            </div>

                            <div id="hd_cancelled_state"
                                class="hidden rounded-2xl border border-slate-200 bg-slate-50 p-3">
                                <p class="text-[9px] font-black text-slate-600 uppercase tracking-widest">No household
                                    link applied.</p>
                            </div>
                            <div id="hd_single_head_state">
                                <div id="hd_member_pending"
                                    class="rounded-2xl border-2 border-dashed border-indigo-300 bg-white p-4 space-y-3">
                                    <div class="flex items-start gap-3">
                                        <span
                                            class="material-symbols-outlined text-amber-500 text-base mt-0.5 shrink-0">info</span>
                                        <p class="text-[9px] font-bold text-slate-600 leading-relaxed">
                                            An existing Household Head was found at this address. Click the button below
                                            to register this resident as a
                                            <strong class="text-indigo-700">Household Member</strong> and inherit the
                                            household's GPS coordinates automatically.
                                        </p>
                                    </div>
                                    <button type="button" id="hd_become_member_btn" onclick="confirmBecomeMember()"
                                        class="w-full flex items-center justify-center gap-2 py-3 px-4 bg-indigo-600 hover:bg-indigo-700 active:scale-[0.98] text-white text-[10px] font-black uppercase tracking-widest rounded-xl transition-all shadow-md shadow-indigo-200">
                                        <span class="material-symbols-outlined text-base">group_add</span>
                                        Confirm Household Member Link
                                    </button>
                                </div>
                                <div id="hd_member_confirmed"
                                    class="hidden rounded-2xl bg-emerald-50 border-2 border-emerald-200 p-4">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="w-9 h-9 bg-emerald-100 rounded-xl flex items-center justify-center shrink-0">
                                            <span
                                                class="material-symbols-outlined text-emerald-600 text-base">check_circle</span>
                                        </div>
                                        <div>
                                            <p
                                                class="text-[10px] font-black text-emerald-700 uppercase tracking-widest">
                                                Household Membership Confirmed</p>
                                            <p class="text-[9px] font-bold text-emerald-600 mt-0.5">
                                                This resident will be linked to <span id="hd_confirmed_head_name"
                                                    class="font-black">—</span>'s household. GPS coordinates inherited.
                                            </p>
                                        </div>
                                        <button type="button" onclick="cancelBecomeMember()"
                                            class="ml-auto text-emerald-400 hover:text-red-500 transition-colors"
                                            title="Cancel membership">
                                            <span class="material-symbols-outlined text-base">close</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="location_pin_section">
                        <div id="map_picker_header" class="hidden items-center justify-between gap-3 mb-3">
                            <div>
                                <p class="text-xs font-black text-slate-800">Pick resident location</p>
                                <p class="text-[9px] text-slate-500">Tap anywhere on the map or drag the pin, then
                                    confirm.</p>
                            </div>
                            <button type="button" id="close_map_picker" onclick="closeMapPicker()"
                                class="px-3 py-2 rounded-xl bg-slate-100 text-[9px] font-black uppercase text-slate-600">Done</button>
                        </div>
                        <div class="col-span-12 space-y-2">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Map Location</label>
                            <div class="relative">
                                <div id="map" class="w-full h-72 rounded-2xl shadow-inner"></div>
                                <div class="absolute right-3 bottom-3 flex flex-col gap-2">
                                    <button type="button" onclick="useMyCurrentLocation()"
                                        class="flex items-center gap-2 rounded-xl bg-white px-3 py-2 text-[9px] font-black uppercase tracking-wider text-slate-700 shadow-lg border border-slate-200 hover:bg-slate-50">
                                        <span
                                            class="material-symbols-outlined text-base text-primary">my_location</span>
                                        Use my location
                                    </button>
                                    <button type="button" onclick="openMapPicker()"
                                        class="flex items-center gap-2 rounded-xl bg-primary px-3 py-2 text-[9px] font-black uppercase tracking-wider text-white shadow-lg hover:opacity-90">
                                        <span class="material-symbols-outlined text-base">open_in_full</span> Pick on
                                        map
                                    </button>
                                </div>
                            </div>
                            <p class="text-[8px] text-slate-400 font-medium italic">Tap the map, use your current
                                location, or drag the pin to set the exact resident location.</p>
                            <div id="location_detected_address"
                                class="hidden mt-2 rounded-xl bg-slate-50 border border-slate-200 px-3 py-2 text-[9px] font-bold text-slate-600">
                            </div>
                        </div>
                        <div class="flex items-center justify-between gap-3 mt-3">
                            <span id="location_adjusted_badge"
                                class="hidden text-[9px] font-black text-amber-700 bg-amber-50 border border-amber-200 rounded-full px-3 py-1">Location
                                manually adjusted</span>
                            <div class="flex items-center gap-3 ml-auto">
                                <button type="button" onclick="useMyCurrentLocation()"
                                    class="text-[9px] font-black uppercase tracking-wider text-primary hover:text-accent">Use
                                    Current Location</button>
                                <button type="button" onclick="recalculateResidentLocation()"
                                    class="text-[9px] font-black uppercase tracking-wider text-primary hover:text-accent">Recalculate
                                    Location</button>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4 mt-4">
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-bold text-slate-400 uppercase">Latitude</label>
                                <input type="text" name="Latitude" id="f_lat" readonly
                                    class="w-full bg-slate-50 border-none rounded-xl py-3 px-4 text-xs font-mono font-bold text-slate-500"
                                    placeholder="Auto-filled via Map">
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-bold text-slate-400 uppercase">Longitude</label>
                                <input type="text" name="Longitude" id="f_long" readonly
                                    class="w-full bg-slate-50 border-none rounded-xl py-3 px-4 text-xs font-mono font-bold text-slate-500"
                                    placeholder="Auto-filled via Map">
                            </div>
                        </div>
                    </div>

                    <input type="hidden" id="f_inherited_lat" name="InheritedLatitude" value="">
                    <input type="hidden" id="f_inherited_long" name="InheritedLongitude" value="">

                    <div id="head_exists_warning"
                        class="hidden p-3 bg-red-50 border border-red-100 rounded-xl flex items-center gap-3">
                        <span class="material-symbols-outlined text-red-500 text-sm">warning</span>
                        <p class="text-[9px] font-black text-red-600 uppercase">Address has existing Family Head (<span
                                id="existing_head_name"></span>).</p>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Family Role *</label>
                            <select name="IsHead" id="f_is_head" onchange="handleFamilyRoleChange();"
                                class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold focus:ring-2 focus:ring-primary/20">
                                <option value="1">Head of Family</option>
                                <option value="0">Member of Household</option>
                            </select>
                        </div>
                        <div class="space-y-1.5" id="relationship_group">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Relationship to Head
                                *</label>
                            <input type="text" name="RelationshipToHead" id="f_rel" oninput="capitalizeFirst(this)"
                                class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold focus:ring-2 focus:ring-primary/20">
                        </div>
                    </div>
                    <input type="hidden" name="FamilyHeadID" id="f_family_head_id" value="">
                </div>

                <div class="space-y-5">
                    <div class="flex items-center gap-3">
                        <span
                            class="material-symbols-outlined text-white bg-primary p-1.5 rounded-lg text-sm shadow-md shadow-orange-100">analytics</span>
                        <h4 class="text-sm font-black text-primary uppercase tracking-tight">Socio-Economic Profile</h4>
                    </div>
                    <div class="grid grid-cols-12 gap-4">
                        <div class="col-span-12 md:col-span-6 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Employment Status</label>
                            <select name="EmploymentStatus" id="f_emp_status" onchange="toggleEmploymentFields()"
                                class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold focus:ring-2 focus:ring-primary/20">
                                <option value="Unemployed">Unemployed</option>
                                <option value="Employed">Employed</option>
                                <option value="Self-Employed">Self-Employed</option>
                                <option value="Student">Student</option>
                                <option value="Retired">Retired</option>
                                <option value="Homemaker">Homemaker</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-span-12 md:col-span-6 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Individual Monthly Income
                                (Optional)</label>
                            <input type="number" name="TotalHouseholdIncome" id="f_total_house_income"
                                class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold focus:ring-2 focus:ring-primary/20">
                        </div>
                        <div class="col-span-12 md:col-span-6 space-y-1.5 hidden" id="emp_other_group">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Please Specify Employment
                                Status</label>
                            <input type="text" name="EmploymentStatusOther" id="f_emp_status_other"
                                placeholder="e.g. OFW, Freelancer"
                                class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold focus:ring-2 focus:ring-primary/20">
                        </div>
                        <div class="col-span-12 md:col-span-6 space-y-1.5 hidden" id="occupation_group">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Occupation</label>
                            <input type="text" name="Occupation" id="f_occupation"
                                placeholder="e.g. Tricycle Driver, Teacher, Vendor"
                                class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold focus:ring-2 focus:ring-primary/20">
                        </div>
                    </div>

                    <!-- Source of Income — multiple selection -->
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Source of Income</label>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                            <label
                                class="flex items-center gap-2 bg-slate-50 p-3 rounded-xl border border-slate-100 cursor-pointer hover:bg-slate-100 transition-all">
                                <input type="checkbox" name="SourceOfIncome[]" value="Employment"
                                    class="rounded text-primary focus:ring-primary source-income-cb">
                                <span class="text-[10px] font-black text-slate-600 uppercase">Employment</span>
                            </label>
                            <label
                                class="flex items-center gap-2 bg-slate-50 p-3 rounded-xl border border-slate-100 cursor-pointer hover:bg-slate-100 transition-all">
                                <input type="checkbox" name="SourceOfIncome[]" value="Business"
                                    class="rounded text-primary focus:ring-primary source-income-cb">
                                <span class="text-[10px] font-black text-slate-600 uppercase">Business</span>
                            </label>
                            <label
                                class="flex items-center gap-2 bg-slate-50 p-3 rounded-xl border border-slate-100 cursor-pointer hover:bg-slate-100 transition-all">
                                <input type="checkbox" name="SourceOfIncome[]" value="Agriculture/Farming"
                                    class="rounded text-primary focus:ring-primary source-income-cb">
                                <span class="text-[10px] font-black text-slate-600 uppercase">Agriculture /
                                    Farming</span>
                            </label>
                            <label
                                class="flex items-center gap-2 bg-slate-50 p-3 rounded-xl border border-slate-100 cursor-pointer hover:bg-slate-100 transition-all">
                                <input type="checkbox" name="SourceOfIncome[]" value="Remittance"
                                    class="rounded text-primary focus:ring-primary source-income-cb">
                                <span class="text-[10px] font-black text-slate-600 uppercase">Remittance</span>
                            </label>
                            <label
                                class="flex items-center gap-2 bg-slate-50 p-3 rounded-xl border border-slate-100 cursor-pointer hover:bg-slate-100 transition-all">
                                <input type="checkbox" name="SourceOfIncome[]" value="Pension"
                                    class="rounded text-primary focus:ring-primary source-income-cb">
                                <span class="text-[10px] font-black text-slate-600 uppercase">Pension</span>
                            </label>
                            <label
                                class="flex items-center gap-2 bg-slate-50 p-3 rounded-xl border border-slate-100 cursor-pointer hover:bg-slate-100 transition-all">
                                <input type="checkbox" name="SourceOfIncome[]" value="Government Assistance"
                                    class="rounded text-primary focus:ring-primary source-income-cb">
                                <span class="text-[10px] font-black text-slate-600 uppercase">Gov't Assistance</span>
                            </label>
                            <label
                                class="flex items-center gap-2 bg-slate-50 p-3 rounded-xl border border-slate-100 cursor-pointer hover:bg-slate-100 transition-all">
                                <input type="checkbox" name="SourceOfIncome[]" value="Other"
                                    id="f_source_income_other_cb" onchange="toggleSourceIncomeOther()"
                                    class="rounded text-primary focus:ring-primary source-income-cb">
                                <span class="text-[10px] font-black text-slate-600 uppercase">Other</span>
                            </label>
                        </div>
                        <div id="source_income_other_group" class="hidden pt-1">
                            <input type="text" name="SourceOfIncomeOther" id="f_source_income_other"
                                placeholder="Please specify other source of income"
                                class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold focus:ring-2 focus:ring-primary/20">
                        </div>
                    </div>
                </div>

                <div class="space-y-5">
                    <div class="flex items-center gap-3">
                        <span
                            class="material-symbols-outlined text-white bg-primary p-1.5 rounded-lg text-sm shadow-md shadow-orange-100">verified_user</span>
                        <h4 class="text-sm font-black text-primary uppercase tracking-tight">Government &amp; Social
                            Program Membership</h4>
                    </div>

                    <!-- Registered Voter + PhilHealth + SSS + GSIS + 4Ps + Pag-IBIG -->
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                        <!-- Registered Voter -->
                        <label
                            class="flex items-center gap-3 bg-slate-50 p-4 rounded-xl border border-slate-100 cursor-pointer hover:bg-slate-100 transition-all">
                            <input type="checkbox" name="IsVoter" value="1" id="f_is_voter"
                                class="rounded text-primary focus:ring-primary">
                            <span class="text-[10px] font-black text-slate-600 uppercase">Registered Voter</span>
                        </label>
                        <!-- Hidden VoterNumber preserved for DB compatibility -->
                        <input type="hidden" name="VoterNumber" id="f_voter_number" value="">
                        <!-- PhilHealth -->
                        <label
                            class="flex items-center gap-3 bg-slate-50 p-4 rounded-xl border border-slate-100 cursor-pointer hover:bg-slate-100 transition-all">
                            <input type="checkbox" name="HasPhilhealth" value="1" id="f_philhealth"
                                class="rounded text-primary focus:ring-primary">
                            <span class="text-[10px] font-black text-slate-600 uppercase">PhilHealth Member</span>
                        </label>
                        <!-- SSS -->
                        <label
                            class="flex items-center gap-3 bg-slate-50 p-4 rounded-xl border border-slate-100 cursor-pointer hover:bg-slate-100 transition-all">
                            <input type="checkbox" name="IsSSSMember" value="1" id="f_sss"
                                class="rounded text-primary focus:ring-primary">
                            <span class="text-[10px] font-black text-slate-600 uppercase">SSS Member</span>
                        </label>
                        <!-- GSIS -->
                        <label
                            class="flex items-center gap-3 bg-slate-50 p-4 rounded-xl border border-slate-100 cursor-pointer hover:bg-slate-100 transition-all">
                            <input type="checkbox" name="IsGSISMember" value="1" id="f_gsis"
                                class="rounded text-primary focus:ring-primary">
                            <span class="text-[10px] font-black text-slate-600 uppercase">GSIS Member</span>
                        </label>
                        <!-- 4P's Member -->
                        <label
                            class="flex items-center gap-3 bg-slate-50 p-4 rounded-xl border border-slate-100 cursor-pointer hover:bg-slate-100 transition-all">
                            <input type="checkbox" name="Has4Ps" value="1" id="f_4ps"
                                class="rounded text-primary focus:ring-primary">
                            <span class="text-[10px] font-black text-slate-600 uppercase">4P's Member</span>
                        </label>
                        <!-- Pag-IBIG -->
                        <label
                            class="flex items-center gap-3 bg-slate-50 p-4 rounded-xl border border-slate-100 cursor-pointer hover:bg-slate-100 transition-all">
                            <input type="checkbox" name="IsPagibigMember" value="1" id="f_pagibig"
                                class="rounded text-primary focus:ring-primary">
                            <span class="text-[10px] font-black text-slate-600 uppercase">Pag-IBIG Member</span>
                        </label>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-4">
                        <div class="bg-slate-50 p-5 rounded-2xl border border-slate-100 space-y-3">
                            <div class="flex items-center justify-between">
                                <p class="text-[10px] font-black text-slate-400 uppercase">PWD Status</p>
                                <input type="checkbox" name="IsPWD" value="1" id="f_is_pwd"
                                    onchange="toggleWelfareFields()" class="rounded text-primary focus:ring-primary">
                            </div>
                            <div id="pwd_details" class="hidden space-y-2 animate-fade-in">
                                <select name="PWDClassification" id="f_pwd_class"
                                    class="w-full bg-white border border-slate-200 rounded-lg py-2 px-3 text-[10px] font-bold">
                                    <option value="">-- Select Disability Type --</option>
                                    <option value="Visual Impairment">Visual Impairment</option>
                                    <option value="Hearing Impairment">Hearing Impairment</option>
                                    <option value="Mobility Impairment">Mobility Impairment</option>
                                    <option value="Intellectual Disability">Intellectual Disability</option>
                                    <option value="Psychosocial Disability">Psychosocial Disability</option>
                                    <option value="Speech and Language Impairment">Speech and Language Impairment
                                    </option>
                                    <option value="Multiple Disabilities">Multiple Disabilities</option>
                                </select>
                                <input type="text" name="PWDID" id="f_pwd_id" placeholder="PWD ID Number"
                                    class="w-full bg-white border border-slate-200 rounded-lg py-2 px-3 text-[10px] font-bold">
                            </div>
                        </div>
                        <div class="bg-slate-50 p-5 rounded-2xl border border-slate-100 space-y-3">
                            <div class="flex items-center justify-between">
                                <p class="text-[10px] font-black text-slate-400 uppercase">Senior Citizen</p>
                                <input type="checkbox" name="IsSenior" value="1" id="f_is_senior"
                                    onchange="toggleWelfareFields()" class="rounded text-primary focus:ring-primary">
                            </div>
                            <p id="senior_msg" class="text-[8px] font-bold text-slate-400 italic hidden">Automatic
                                Senior status for 60+ years old.</p>
                        </div>
                    </div>
                </div>

                <!-- ══ Deceased Record — intentionally placed at the very end of the form ══ -->
                <div class="space-y-4 pt-6 border-t-2 border-dashed border-red-100">
                    <div class="flex items-center gap-3">
                        <span
                            class="material-symbols-outlined text-white bg-red-600 p-1.5 rounded-lg text-sm shadow-md shadow-red-100">person_off</span>
                        <h4 class="text-sm font-black text-red-600 uppercase tracking-tight">Deceased Record</h4>
                    </div>

                    <label
                        class="flex items-center gap-3 bg-slate-50 p-4 rounded-xl border border-slate-100 cursor-pointer hover:bg-red-50 transition-all border-l-4 border-l-red-500">
                        <input type="checkbox" name="IsDeceased" value="1" id="f_is_deceased"
                            onchange="toggleDeathDoc()" class="rounded text-red-600 focus:ring-red-500">
                        <span class="text-[10px] font-black text-red-600 uppercase">Mark as Deceased</span>
                    </label>

                    <div id="death_doc_container"
                        class="hidden p-5 bg-red-50 rounded-2xl border-2 border-dashed border-red-100 animate-fade-in space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-black text-red-600 uppercase block">Date of Death</label>
                                <input type="date" name="DateOfDeath" id="f_date_of_death"
                                    class="w-full bg-white border border-red-100 rounded-xl py-3 px-4 text-sm font-bold">
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-black text-red-600 uppercase block">Place of
                                    Death</label>
                                <input type="text" name="PlaceOfDeath" id="f_place_of_death"
                                    class="w-full bg-white border border-red-100 rounded-xl py-3 px-4 text-sm font-bold">
                            </div>
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-red-600 uppercase block">Cause of Death
                                (Optional)</label>
                            <input type="text" name="CauseOfDeath" id="f_cause_of_death"
                                class="w-full bg-white border border-red-100 rounded-xl py-3 px-4 text-sm font-bold">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-red-600 uppercase block">Death Certificate
                                (PDF/Image)</label>
                            <input type="file" name="DeathCertificate" id="f_death_cert"
                                class="text-xs font-bold text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-[10px] file:font-black file:bg-red-100 file:text-red-700 hover:file:bg-red-200">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-red-600 uppercase block">Remarks
                                (Optional)</label>
                            <textarea name="DeceasedRemarks" id="f_deceased_remarks" rows="2"
                                class="w-full bg-white border border-red-100 rounded-xl py-3 px-4 text-sm font-bold"></textarea>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-3 border-t border-red-100">
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-black text-slate-400 uppercase block">Date
                                    Reported</label>
                                <input type="text" id="f_death_date_reported" disabled
                                    placeholder="Recorded automatically on save"
                                    class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm font-bold text-slate-500">
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-black text-slate-400 uppercase block">Issued By</label>
                                <input type="text" id="f_death_reported_by" disabled
                                    placeholder="Recorded automatically on save"
                                    class="w-full bg-slate-100 border border-slate-200 rounded-xl py-3 px-4 text-sm font-bold text-slate-500">
                            </div>
                        </div>
                        <p class="text-[10px] font-bold text-red-500 italic">⚠ Once saved, this resident's deceased
                            status cannot be reversed and the record cannot be deleted. It will remain in the system
                            permanently as a historical record.</p>
                    </div>
                </div>

                <div class="flex gap-4 pt-8 border-t border-slate-100">
                    <button type="button" onclick="closeModal('resModal')"
                        class="flex-1 py-4 text-xs font-black uppercase text-slate-400 hover:text-slate-600 transition-all">Cancel</button>
                    <button type="submit" id="saveBtn"
                        class="flex-[2] py-4 bg-primary text-white text-xs font-black uppercase rounded-2xl shadow-xl hover:bg-orange-600 transition-all shadow-orange-100 active:scale-95">
                        Save Resident Profile
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Custom Confirm Dialog (unchanged) -->
    <div id="confirmDialog" class="fixed inset-0 z-[999] hidden bg-slate-900/80 flex items-center justify-center p-4">
        <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-md p-8 space-y-6 animate-fade-in">
            <div class="flex items-start gap-4">
                <div id="confirmDialogIcon"
                    class="w-12 h-12 rounded-2xl flex items-center justify-center shrink-0 bg-indigo-50">
                    <span class="material-symbols-outlined text-indigo-500 text-2xl">help</span>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 id="confirmDialogTitle"
                        class="text-base font-black text-slate-800 leading-tight tracking-tight">Confirm Action</h3>
                    <p id="confirmDialogMessage" class="text-xs text-slate-500 font-medium mt-1.5 leading-relaxed"></p>
                </div>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="button" id="confirmDialogCancelBtn"
                    class="flex-1 py-3.5 text-xs font-black uppercase text-slate-400 hover:text-slate-700 border border-slate-200 hover:border-slate-300 rounded-2xl transition-all">
                    Cancel
                </button>
                <button type="button" id="confirmDialogOkBtn"
                    class="flex-[2] py-3.5 bg-primary text-white text-xs font-black uppercase rounded-2xl shadow-lg hover:bg-indigo-700 active:scale-95 transition-all">
                    Confirm
                </button>
            </div>
        </div>
    </div>

    <!-- ══ NEW: View Request Details Modal ═══════════════════════════ -->
    <!-- ══ MANAGE AREA MODAL ═════════════════════════════════════════════ -->
    <div id="manageAreaModal" class="fixed inset-0 z-[70] hidden bg-slate-900/80 items-center justify-center p-4">
        <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-5xl overflow-hidden max-h-[92vh] flex flex-col">
            <div class="flex items-center gap-3 px-8 py-5 border-b border-slate-100"
                style="background:linear-gradient(135deg,var(--accent-700) 0%,var(--accent-600) 100%);">
                <div class="w-9 h-9 bg-white/20 rounded-xl flex items-center justify-center"><span
                        class="material-symbols-outlined text-white text-lg">map</span></div>
                <div>
                    <h3 class="font-black text-white text-sm uppercase tracking-tight">Manage Area</h3>
                    <p class="text-[10px] text-white/60 font-bold mt-0.5">Manage streets and subdivision / village /
                        sitio / purok for a specific barangay.</p>
                </div>
                <button type="button" onclick="closeManageAreaModal()"
                    class="ml-auto w-8 h-8 flex items-center justify-center bg-white/10 hover:bg-white/20 rounded-full text-white"><span
                        class="material-symbols-outlined text-base">close</span></button>
            </div>
            <div class="p-6 space-y-4 overflow-y-auto">
                <div class="rounded-2xl bg-slate-50 border border-slate-200 p-4 space-y-3">
                    <div>
                        <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Default Barangay
                            Address</p>
                        <p class="text-[9px] text-slate-400">Set this once. New residents automatically inherit the
                            Region, Province, City/Municipality, Barangay and ZIP.</p>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <select id="mgr_region" class="addr-select">
                            <option value="">Select region</option>
                        </select>
                        <select id="mgr_province" class="addr-select" disabled>
                            <option value="">Select province</option>
                        </select>
                        <select id="mgr_municipality" class="addr-select" disabled>
                            <option value="">Select city/municipality</option>
                        </select>
                        <select id="mgr_barangay" class="addr-select" disabled>
                            <option value="">Select barangay</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <input id="mgr_zip" maxlength="10"
                            class="bg-white border border-slate-200 rounded-xl py-2.5 px-4 text-sm font-bold"
                            placeholder="ZIP Code (auto)">
                        <input id="mgr_address" maxlength="255"
                            class="md:col-span-2 bg-white border border-slate-200 rounded-xl py-2.5 px-4 text-sm font-bold"
                            placeholder="Barangay address / street address for reports (optional)">
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <p id="mgr_selected_label" class="text-[9px] font-bold text-slate-400">Choose a barangay to
                            configure the default address.</p>
                        <button type="button" onclick="saveBarangayProfile()"
                            class="px-5 py-2.5 bg-primary text-white text-[9px] font-black uppercase rounded-xl shadow-sm">Save
                            Default Address</button>
                    </div>
                </div>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                    <section class="border border-slate-200 rounded-2xl overflow-hidden">
                        <div class="px-5 py-4 bg-slate-50 border-b border-slate-200">
                            <h4 class="text-xs font-black text-slate-700 uppercase">Street</h4>
                            <p class="text-[9px] text-slate-400 mt-1">Official street names used by residents.</p>
                        </div>
                        <div class="max-h-72 overflow-y-auto">
                            <table class="w-full text-left">
                                <tbody id="streetManagerBody"></tbody>
                            </table>
                        </div>
                        <div class="p-4 border-t border-slate-200 bg-slate-50">
                            <div class="flex gap-2"><input id="newStreetInput" maxlength="100"
                                    placeholder="e.g. Mabini Street"
                                    class="flex-1 bg-white border border-slate-200 rounded-xl py-2.5 px-4 text-sm font-bold"><button
                                    id="addStreetBtn" onclick="addManagedAddress('street')"
                                    class="px-4 py-2.5 bg-primary text-white text-[9px] font-black uppercase rounded-xl">Add</button>
                            </div>
                        </div>
                    </section>
                    <section class="border border-slate-200 rounded-2xl overflow-hidden">
                        <div class="px-5 py-4 bg-slate-50 border-b border-slate-200">
                            <h4 class="text-xs font-black text-slate-700 uppercase">Subdivision / Village / Sitio /
                                Purok</h4>
                            <p class="text-[9px] text-slate-400 mt-1">All local areas are managed here; choose the type
                                when adding.</p>
                        </div>
                        <div class="max-h-72 overflow-y-auto">
                            <table class="w-full text-left">
                                <tbody id="areaManagerBody"></tbody>
                            </table>
                        </div>
                        <div class="p-4 border-t border-slate-200 bg-slate-50 space-y-2">
                            <div class="flex gap-2"><select id="newAreaType"
                                    class="w-36 bg-white border border-slate-200 rounded-xl py-2.5 px-3 text-xs font-bold">
                                    <option>Subdivision</option>
                                    <option>Village</option>
                                    <option>Sitio</option>
                                    <option>Purok</option>
                                </select><input id="newAreaInput" maxlength="100" placeholder="Area name"
                                    class="flex-1 bg-white border border-slate-200 rounded-xl py-2.5 px-4 text-sm font-bold"><button
                                    id="addAreaBtn" onclick="addManagedAddress('area')"
                                    class="px-4 py-2.5 bg-primary text-white text-[9px] font-black uppercase rounded-xl">Add</button>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
            <div class="flex justify-end px-8 py-4 border-t border-slate-100"><button type="button"
                    onclick="closeManageAreaModal()"
                    class="px-6 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-black uppercase rounded-xl">Done</button>
            </div>
        </div>
    </div>
    <!-- END MANAGE AREA MODAL -->

    <script>
        // App base URL for resolving uploaded file paths (e.g. "/SOE")
        window._APP_BASE = '<?= rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin_int/residents.php')), '/') ?>';
    </script>

    <script>
        // ═══════════════════════════════════════════════════════════════════
        //  TOAST NOTIFICATION SYSTEM
        // ═══════════════════════════════════════════════════════════════════
        (function () {
            const style = document.createElement('style');
            style.textContent = `
    #toast-container { position: fixed; top: 1.25rem; right: 1.25rem; z-index: 99999; display: flex; flex-direction: column; gap: .6rem; pointer-events: none; }
    .toast { display: flex; align-items: center; gap: .75rem; padding: .85rem 1.1rem; border-radius: 1rem; box-shadow: 0 8px 28px rgba(0,0,0,.14); font-family: 'Plus Jakarta Sans', sans-serif; font-size: .75rem; font-weight: 700; min-width: 280px; max-width: 380px; pointer-events: all; transform: translateX(110%); opacity: 0; transition: transform .3s cubic-bezier(.34,1.56,.64,1), opacity .3s ease; }
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
    `;
            document.head.appendChild(style);
            const container = document.createElement('div');
            container.id = 'toast-container';
            document.body.appendChild(container);
        })();

        function showToast(message, type = 'success', duration = 4500) {
            const icons = { success: 'check_circle', error: 'error', warning: 'warning', info: 'info' };
            const container = document.getElementById('toast-container');
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

        // ═══════════════════════════════════════════════════════════════════
        //  ORIGINAL JAVASCRIPT — PRESERVED + ENHANCED
        // ═══════════════════════════════════════════════════════════════════
        var map = null;
        var marker = null;
        var googleMapsReady = false;
        var residentGeocoder = null;

        function initGoogleMap() {
            window.__capsGoogleMapsLoaded = true;
            googleMapsReady = true;
            initMap();
        }

        function initMap() {
            googleMapsReady = googleMapsReady || !!window.__capsGoogleMapsLoaded;
            const defaultLat = 14.8000;
            const defaultLng = 120.9333;
            const mapEl = document.getElementById('map');
            if (!mapEl) return;

            if (!googleMapsReady || typeof google === 'undefined' || !google.maps) {
                mapEl.innerHTML = '<div class="h-full flex items-center justify-center text-center px-6 text-xs font-semibold text-slate-500 bg-slate-50 rounded-2xl">Google Maps is not configured yet. Add GOOGLE_MAPS_BROWSER_KEY to .env.</div>';
                return;
            }

            if (map === null) {
                map = new google.maps.Map(mapEl, {
                    center: { lat: defaultLat, lng: defaultLng },
                    zoom: 15,
                    mapTypeControl: true,
                    streetViewControl: true,
                    fullscreenControl: false,
                    gestureHandling: 'greedy'
                });

                residentGeocoder = new google.maps.Geocoder();
                map.addListener('click', function (e) {
                    if (e.latLng) {
                        setMarker(e.latLng.lat(), e.latLng.lng(), true, true);
                    }
                });
            }
        }

        function setMarker(lat, lng, reverseGeocode = false, skipScopeCheck = true) {
            if (!map || typeof google === 'undefined' || !google.maps) return;
            lat = parseFloat(lat);
            lng = parseFloat(lng);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;

            const latFixed = lat.toFixed(8);
            const lngFixed = lng.toFixed(8);
            const position = { lat: parseFloat(latFixed), lng: parseFloat(lngFixed) };

            if (marker) {
                marker.setPosition(position);
            } else {
                marker = new google.maps.Marker({
                    position,
                    map,
                    draggable: true,
                    title: 'Resident location'
                });
                marker.addListener('dragend', function (e) {
                    if (e.latLng) {
                        setMarker(e.latLng.lat(), e.latLng.lng(), true, true);
                    }
                });
            }
            updateCoords(latFixed, lngFixed);
            if (reverseGeocode) reverseGeocodeLocation(latFixed, lngFixed);
        }

        function clearMapMarker() {
            if (marker) {
                marker.setMap(null);
                marker = null;
            }
        }

        async function reverseGeocodeLocation(lat, lng) {
            const thisRun = ++addressAutofillRun;
            if (!residentGeocoder && window.google && google.maps) residentGeocoder = new google.maps.Geocoder();
            if (!residentGeocoder) return;

            residentGeocoder.geocode({ location: { lat: parseFloat(lat), lng: parseFloat(lng) }, region: 'PH' }, async function (results, status) {
                if (status !== 'OK' || !results || !results.length) {
                    showToast('Google found the location, but could not read the address details.', 'warning', 5000);
                    return;
                }

                // Google can return several results for one GPS point. Build one combined
                // component set from all results so a route/postal/barangay that is missing
                // from result[0] can still be picked up from another result.
                if (thisRun !== addressAutofillRun) return;
                const values = {};
                const allComponents = [];
                results.forEach(result => (result.address_components || []).forEach(c => allComponents.push(c)));
                allComponents.forEach(c => (c.types || []).forEach(t => { if (!values[t]) values[t] = c.long_name; }));

                const normalize = (value) => String(value || '')
                    .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                    .toLowerCase().replace(/barangay\s+/g, '').replace(/province\s+/g, '')
                    .replace(/city\s+of\s+/g, '').replace(/municipality\s+of\s+/g, '')
                    .replace(/[^a-z0-9]+/g, ' ').trim();

                const findOption = (sel, name) => {
                    if (!sel || !name) return null;
                    const target = normalize(name);
                    if (!target) return null;
                    const opts = Array.from(sel.options);
                    return opts.find(o => normalize(o.textContent) === target || normalize(o.dataset.name) === target)
                        || opts.find(o => {
                            const a = normalize(o.textContent), b = normalize(o.dataset.name);
                            return a && (a.includes(target) || target.includes(a) || b.includes(target) || target.includes(b));
                        }) || null;
                };

                // Philippine Google address components vary by place. These fallbacks make
                // the hierarchy work for municipalities/barangays where Google uses a
                // different administrative level.
                const regionName = values.administrative_area_level_1 || '';
                const provinceName = values.administrative_area_level_2 || values.administrative_area_level_3 || '';
                const municipalityName = values.locality || values.postal_town || '';
                const barangayCandidates = [
                    values.sublocality_level_1,
                    values.sublocality,
                    values.neighborhood,
                    values.administrative_area_level_3
                ].filter(Boolean);
                const route = values.route || '';
                const postal = values.postal_code || '';
                const areaCandidates = [values.sublocality_level_2, values.neighborhood].filter(Boolean);

                const regionSel = document.getElementById('f_region');
                const provinceSel = document.getElementById('f_province');
                const munSel = document.getElementById('f_municipality');
                const bSel = document.getElementById('f_barangay');
                const street = document.getElementById('f_street');
                const area = document.getElementById('f_area');

                try {
                    // Make sure PSGC region options are loaded before matching Google's
                    // administrative_area_level_1. The location button can be clicked
                    // before the asynchronous PSGC initialization has finished.
                    if (regionSel && regionSel.options.length <= 1 && window.loadAddressRegions) {
                        await window.loadAddressRegions();
                    }

                    // 1) Region: Google says "Central Luzon", while PSGC usually says
                    // "Region III (Central Luzon)". findOption() intentionally handles both.
                    let regionOpt = findOption(regionSel, regionName);
                    if (!regionOpt && regionSel && regionName) {
                        const rn = normalize(regionName);
                        regionOpt = Array.from(regionSel.options).find(o => {
                            const t = normalize(o.textContent);
                            return rn && t && (t.includes(rn) || rn.includes(t));
                        }) || null;
                    }
                    if (thisRun !== addressAutofillRun) return;
                    if (regionOpt) {
                        regionSel.value = regionOpt.value;
                        document.getElementById('f_region_code').value = regionOpt.value;
                        window.clearAddressAfter?.('p');
                        await window.loadAddressChildren?.('provinces', { reg: regionOpt.value }, window.residentAddressIds.p, 'Select province');
                    }

                    // 2) Province. If the region wording did not match, search the currently
                    // loaded list first. This prevents the Google result from being discarded.
                    let provinceOpt = findOption(provinceSel, provinceName);
                    if (!provinceOpt && provinceSel && provinceName) {
                        const pn = normalize(provinceName);
                        provinceOpt = Array.from(provinceSel.options).find(o => {
                            const t = normalize(o.textContent);
                            return pn && t && (t.includes(pn) || pn.includes(t));
                        }) || null;
                    }
                    if (thisRun !== addressAutofillRun) return;
                    if (provinceOpt) {
                        provinceSel.value = provinceOpt.value;
                        document.getElementById('f_province_code').value = provinceOpt.value;
                        window.clearAddressAfter?.('m');
                        await window.loadAddressChildren?.('municipalities', { prv: provinceOpt.value }, window.residentAddressIds.m, 'Select city/municipality');
                    }

                    // 3) Municipality/City.
                    let municipalityOpt = findOption(munSel, municipalityName);
                    if (!municipalityOpt && munSel && municipalityName) {
                        const mn = normalize(municipalityName);
                        municipalityOpt = Array.from(munSel.options).find(o => {
                            const t = normalize(o.textContent);
                            return mn && t && (t.includes(mn) || mn.includes(t));
                        }) || null;
                    }
                    if (thisRun !== addressAutofillRun) return;
                    if (municipalityOpt) {
                        munSel.value = municipalityOpt.value;
                        document.getElementById('f_municipality_code').value = municipalityOpt.value;
                        window.clearAddressAfter?.('b');
                        await window.loadAddressChildren?.('barangays', {
                            prv: document.getElementById('f_province_code').value,
                            mun: municipalityOpt.value
                        }, window.residentAddressIds.b, 'Select barangay');

                        // ZIP is stored locally in CAPS. Google frequently omits PH postal
                        // codes, so use Google's value first and CAPS as a fallback.
                        if (!postal) {
                            try {
                                const zr = await fetch('../backend/address_api.php?action=zip&municipality=' + encodeURIComponent(municipalityOpt.value), { credentials: 'same-origin' });
                                const zj = await zr.json();
                                if (zj.zip_code) document.getElementById('f_zip').value = zj.zip_code;
                            } catch (e) { console.warn('ZIP lookup failed:', e); }
                        }
                    }

                    // 4) Barangay. Try all candidate administrative components because
                    // Google does not use the same component type everywhere in the PH.
                    let barangayOpt = null;
                    for (const candidate of barangayCandidates) {
                        barangayOpt = findOption(bSel, candidate);
                        if (barangayOpt) break;
                    }
                    if (!barangayOpt && bSel) {
                        const candidates = barangayCandidates.map(normalize).filter(Boolean);
                        barangayOpt = Array.from(bSel.options).find(o => {
                            const t = normalize(o.textContent);
                            return candidates.some(n => t === n || t.includes(n) || n.includes(t));
                        }) || null;
                    }
                    if (thisRun !== addressAutofillRun) return;
                    if (barangayOpt) {
                        bSel.value = barangayOpt.value;
                        document.getElementById('f_barangay_code').value = barangayOpt.value;
                        await window.loadAddressLocal?.('streets', { barangay: barangayOpt.value }, window.residentAddressIds.s, 'Select street');
                        await window.loadAddressLocal?.('areas', { barangay: barangayOpt.value }, window.residentAddressIds.a, 'Select area');
                    }

                    // 5) Street: only use a street that already exists in CAPS.
                    // Google can return a route name that is not part of the barangay's
                    // official street list, so NEVER inject an arbitrary Google street
                    // into the dropdown. This keeps the saved address consistent with CAPS.
                    if (street && route) {
                        const option = findOption(street, route);
                        if (option) {
                            street.disabled = false;
                            street.value = option.value;
                        } else {
                            street.value = '';
                            street.disabled = false;
                        }
                    }

                    // 6) Subdivision/Village/Sitio/Purok: only use an area that already
                    // exists in CAPS. Google data is advisory; it must not create a new
                    // local-area option that could be saved without PSGC/local validation.
                    if (area) {
                        const areaName = areaCandidates.find(Boolean) || '';
                        if (areaName) {
                            const aopt = findOption(area, areaName);
                            if (aopt) {
                                area.disabled = false;
                                area.value = aopt.value;
                                document.getElementById('f_area_type').value = aopt.dataset.type || 'Subdivision';
                                if ((aopt.dataset.type || '').toLowerCase() === 'purok') document.getElementById('f_purok').value = aopt.value;
                            }
                        }
                    }

                    if (postal) document.getElementById('f_zip').value = postal;

                    const firstResult = results[0];
                    const detectedParts = [route, ...areaCandidates, ...barangayCandidates, municipalityName, provinceName, regionName, postal].filter(Boolean);
                    const box = document.getElementById('location_detected_address');
                    if (box) {
                        box.textContent = 'Google detected: ' + (detectedParts.length ? detectedParts.join(', ') : firstResult.formatted_address) + ' • CAPS fields use only matched records.';
                        box.classList.remove('hidden');
                    }

                    document.getElementById('location_adjusted_badge')?.classList.remove('hidden');

                    const missing = [];
                    if (!regionSel?.value) missing.push('region');
                    if (!provinceSel?.value) missing.push('province');
                    if (!munSel?.value) missing.push('city/municipality');
                    if (!bSel?.value) missing.push('barangay');
                    if (!street?.value) missing.push('street');
                    if (!document.getElementById('f_zip')?.value) missing.push('ZIP code');

                    // The dropdown hierarchy is the source of truth. Google is only used
                    // to identify which existing CAPS records correspond to the pin.
                    // This prevents a pin in another municipality/barangay from leaving
                    // a mismatched manually-selected address.
                    const selectedHierarchy = [
                        regionSel?.selectedOptions?.[0]?.textContent || '',
                        provinceSel?.selectedOptions?.[0]?.textContent || '',
                        munSel?.selectedOptions?.[0]?.textContent || '',
                        bSel?.selectedOptions?.[0]?.textContent || ''
                    ].filter(Boolean).join(' → ');
                    const detectedHierarchy = [regionName, provinceName, municipalityName, barangayCandidates[0] || ''].filter(Boolean).join(' → ');

                    if (missing.length) {
                        showToast('Location found. Some fields are not available in CAPS/Google: ' + missing.join(', ') + '.', 'warning', 7000);
                    } else {
                        showToast('Location found. Address fields were matched to existing CAPS records.', 'success', 4000);
                    }
                } catch (e) {
                    console.error('Google address auto-fill failed:', e);
                    showToast('Location was found, but some address fields could not be auto-filled. You can adjust them manually.', 'warning', 6000);
                }
            });
        }

        function useMyCurrentLocation() {
            if (!navigator.geolocation) {
                showToast('Your browser does not support location detection.', 'warning');
                return;
            }
            if (!googleMapsReady || !window.google || !google.maps) {
                showToast('Google Maps is not ready yet.', 'warning');
                return;
            }
            initMap();
            navigator.geolocation.getCurrentPosition(function (pos) {
                const lat = pos.coords.latitude;
                const lng = pos.coords.longitude;
                if (!map) return;
                map.setCenter({ lat, lng });
                map.setZoom(18);
                setMarker(lat, lng, true);
                document.getElementById('location_adjusted_badge')?.classList.remove('hidden');
            }, function (err) {
                const msg = err.code === 1 ? 'Location permission was denied. Allow location access in your browser.' : 'Unable to get your current location.';
                showToast(msg, 'warning', 6000);
            }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 });
        }

        function openMapPicker() {
            const section = document.getElementById('location_pin_section');
            if (!section) return;
            section.classList.add('map-picker-open');
            const closeBtn = document.getElementById('close_map_picker');
            if (closeBtn) closeBtn.focus();
            setTimeout(function () {
                initMap();
                if (map && window.google && google.maps) {
                    google.maps.event.trigger(map, 'resize');
                    if (marker) map.setCenter(marker.getPosition());
                }
            }, 100);
        }

        function closeMapPicker() {
            const section = document.getElementById('location_pin_section');
            if (section) section.classList.remove('map-picker-open');
            setTimeout(function () {
                if (map && window.google && google.maps) google.maps.event.trigger(map, 'resize');
            }, 100);
        }

        function updateCoords(lat, lng) {
            const latInput = document.getElementById("f_lat");
            const longInput = document.getElementById("f_long");
            if (latInput && longInput) {
                latInput.value = lat;
                longInput.value = lng;
            }
        }

        function capitalizeFirst(input) {
            let value = input.value;
            if (value.length > 0) {
                input.value = value.charAt(0).toUpperCase() + value.slice(1);
            }
        }

        // ── Voter Number toggle removed — voter number is managed by resident portal ──

        async function loadDefaultResidentAddress() {
            try {
                const resp = await fetch('../backend/address_api.php?action=profile', { credentials: 'same-origin' });
                const json = await resp.json();
                const p = json.data || {};
                const setDisplay = (id, value) => { const el = document.getElementById(id); if (el) el.value = value || ''; };
                setDisplay('f_region_display', p.region_name);
                setDisplay('f_province_display', p.province_name);
                setDisplay('f_municipality_display', p.municipality_name);
                setDisplay('f_barangay_display', p.barangay_name);
                if (!json.success || !p.psgc_barangay_code) {
                    const note = document.getElementById('resident_default_address_note');
                    ['f_region_display', 'f_province_display', 'f_municipality_display', 'f_barangay_display'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
                    if (note) {
                        note.className = 'text-[10px] font-bold text-amber-700 bg-amber-50 border border-amber-200 rounded-2xl px-4 py-3';
                        note.innerHTML = 'Default barangay address is not configured yet. Open <strong>Manage Area</strong> and save the barangay profile before registering residents.';
                    }
                    return false;
                }

                const setHidden = (id, value) => { const el = document.getElementById(id); if (el) el.value = value || ''; };
                setHidden('f_region_code', p.psgc_region_code);
                setHidden('f_province_code', p.psgc_province_code);
                setHidden('f_municipality_code', p.psgc_municipality_code);
                setHidden('f_barangay_code', p.psgc_barangay_code);
                setHidden('f_area_type', '');
                setHidden('f_purok', '');

                // Keep the existing hierarchy selects populated for submission and edit compatibility,
                // but they remain hidden from Staff/Admin during resident registration.
                if (window.loadAddressRegions) await window.loadAddressRegions();
                const r = document.getElementById('f_region'), pv = document.getElementById('f_province'),
                    m = document.getElementById('f_municipality'), b = document.getElementById('f_barangay');
                if (r) r.value = p.psgc_region_code || '';
                if (window.loadAddressChildren && p.psgc_region_code)
                    await window.loadAddressChildren('provinces', { reg: p.psgc_region_code }, 'f_province', 'Select province');
                if (pv) pv.value = p.psgc_province_code || '';
                if (window.loadAddressChildren && p.psgc_province_code)
                    await window.loadAddressChildren('municipalities', { prv: p.psgc_province_code }, 'f_municipality', 'Select city/municipality');
                if (m) m.value = p.psgc_municipality_code || '';
                if (p.zip_code) setHidden('f_zip', p.zip_code);
                if (window.loadAddressChildren && p.psgc_municipality_code)
                    await window.loadAddressChildren('barangays', { prv: p.psgc_province_code, mun: p.psgc_municipality_code }, 'f_barangay', 'Select barangay');
                if (b) b.value = p.psgc_barangay_code || '';

                if (window.loadAddressLocal && p.psgc_barangay_code) {
                    await window.loadAddressLocal('streets', { barangay: p.psgc_barangay_code }, 'f_street', 'Select street');
                    await window.loadAddressLocal('areas', { barangay: p.psgc_barangay_code }, 'f_area', 'Select area');
                }
                const note = document.getElementById('resident_default_address_note');
                if (note) {
                    note.className = 'text-[10px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-2xl px-4 py-3';
                    note.innerHTML = 'Default address: <strong>' + escHtml([p.region_name, p.province_name, p.municipality_name, p.barangay_name].filter(Boolean).join(' · ')) + '</strong>. Staff/Admin only needs to enter the house number, building, street and subdivision/sitio/purok.';
                }
                return true;
            } catch (e) {
                console.error('Default resident address load failed:', e);
                return false;
            }
        }

        function openAddModal() {
            hd_originalEditAddressKey = null;
            document.getElementById('residentForm').reset();
            document.getElementById('formAction').value = 'add';
            document.getElementById('modalTitle').innerText = 'Community Profiling';
            document.getElementById('f_res_id').value = '';
            document.getElementById('f_resident_code').value = '';
            document.getElementById('saveBtn').innerText = "Save Resident Profile";
            document.getElementById('f_is_senior').checked = false;
            document.getElementById('f_is_senior').disabled = true;
            document.getElementById('senior_msg').textContent = 'Senior Citizen status requires age 60 or above.';
            document.getElementById('senior_msg').classList.remove('hidden');
            document.getElementById('death_doc_container').classList.add('hidden');
            // Reset socio-economic conditional fields
            document.getElementById('emp_other_group').classList.add('hidden');
            document.getElementById('occupation_group').classList.add('hidden');
            document.getElementById('source_income_other_group').classList.add('hidden');
            document.getElementById('f_death_date_reported').value = '';
            document.getElementById('f_death_reported_by').value = '';
            // Clear nationality
            if (document.getElementById('f_nationality')) document.getElementById('f_nationality').value = '';
            // Ensure family role select is enabled
            document.getElementById('f_is_head').disabled = false;
            document.getElementById('f_is_head').dataset.originalRole = '1';
            document.getElementById('f_is_head').dataset.originalMemberCount = '0';
            // Re-enable deceased checkbox for new residents
            document.getElementById('f_is_deceased').disabled = false;
            document.getElementById('f_is_deceased').parentElement.classList.remove('opacity-50', 'cursor-not-allowed');
            toggleRelationshipField();
            resetHouseholdDetection();
            loadDefaultResidentAddress();
            const modal = document.getElementById('resModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => {
                try {
                    initMap();
                    clearMapMarker();
                    document.getElementById('f_lat').value = '';
                    document.getElementById('f_long').value = '';
                    document.getElementById('location_detected_address')?.classList.add('hidden');
                    if (map && window.google && google.maps) {
                        google.maps.event.trigger(map, 'resize');
                        map.setCenter({ lat: 14.8000, lng: 120.9333 });
                        map.setZoom(15);
                    }
                } catch (e) { console.error('Residents map init error:', e); }
            }, 400);
        }

        function closeModal(id) {
            document.getElementById(id).classList.add('hidden');
            document.getElementById(id).classList.remove('flex');
        }

        function toggleWelfareFields() {
            document.getElementById('pwd_details').classList.toggle('hidden', !document.getElementById('f_is_pwd').checked);
        }

        function toggleDeathDoc() {
            document.getElementById('death_doc_container').classList.toggle('hidden', !document.getElementById('f_is_deceased').checked);
        }

        function toggleEmploymentFields() {
            const status = document.getElementById('f_emp_status').value;
            const occGroup = document.getElementById('occupation_group');
            const otherGroup = document.getElementById('emp_other_group');

            // Occupation only makes sense for people who actually work
            const needsOccupation = ['Employed', 'Self-Employed', 'Other'].includes(status);
            occGroup.classList.toggle('hidden', !needsOccupation);
            if (!needsOccupation) document.getElementById('f_occupation').value = '';

            const isOther = status === 'Other';
            otherGroup.classList.toggle('hidden', !isOther);
            if (!isOther) document.getElementById('f_emp_status_other').value = '';
        }

        function toggleSourceIncomeOther() {
            const checked = document.getElementById('f_source_income_other_cb').checked;
            document.getElementById('source_income_other_group').classList.toggle('hidden', !checked);
            if (!checked) document.getElementById('f_source_income_other').value = '';
        }

        function toggleHeadSelector() {
            // head_link_container removed; FamilyHeadID is now a hidden input — no UI toggle needed
        }

        function toggleRelationshipField() {
            const isHead = document.getElementById('f_is_head').value;
            const relGroup = document.getElementById('relationship_group');
            const relInput = document.getElementById('f_rel');
            if (isHead === "1") {
                relGroup.classList.add('hidden');
                relInput.required = false;
                relInput.value = "";
            } else {
                relGroup.classList.remove('hidden');
                relInput.required = true;
            }
        }

        function calculateAge() {
            const dobInput = document.getElementById('f_dob').value;
            if (!dobInput) return;
            const dob = new Date(dobInput + 'T00:00:00');
            const today = new Date();
            if (dob.getFullYear() > today.getFullYear() || dob.getFullYear().toString().length > 4) {
                showToast("Invalid Year. Please enter a valid Date of Birth.", 'warning');
                document.getElementById('f_dob').value = "";
                document.getElementById('f_age').value = "";
                return;
            }
            if (dob > today) {
                showToast("Date of Birth cannot be in the future.", 'warning');
                document.getElementById('f_dob').value = "";
                document.getElementById('f_age').value = "";
                return;
            }
            let age = today.getFullYear() - dob.getFullYear();
            const monthDiff = today.getMonth() - dob.getMonth();
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) age--;
            const computedAge = age >= 0 ? age : 0;
            document.getElementById('f_age').value = computedAge;
            const seniorCheckbox = document.getElementById('f_is_senior');
            const seniorMsg = document.getElementById('senior_msg');
            if (computedAge >= 60) {
                seniorCheckbox.checked = true;
                seniorCheckbox.disabled = true;
                seniorMsg.textContent = 'Automatically tagged as Senior Citizen (60+ years old).';
                seniorMsg.classList.remove('hidden');
            } else {
                seniorCheckbox.checked = false;
                seniorCheckbox.disabled = true;
                seniorMsg.textContent = 'Senior Citizen status requires age 60 or above.';
                seniorMsg.classList.remove('hidden');
            }
        }

        var hd_detectedHeadId = null;
        var hd_detectedHeadName = null;
        var hd_detectedLat = null;
        var hd_detectedLng = null;
        var hd_memberConfirmed = false;
        var hd_cancelledAddress = false;
        var hd_promptedAddressKey = null;
        var hd_debounceTimer = null;
        // Address snapshot loaded when editing an existing resident.
        // Household-head confirmation should only run on Edit when the user actually
        // changes House/Lot/Unit Number or Street.
        var hd_originalEditAddressKey = null;

        function resetHouseholdDetection(clearCoordinates = false) {
            hd_detectedHeadId = null;
            hd_detectedHeadName = null;
            hd_detectedLat = null;
            hd_detectedLng = null;
            hd_memberConfirmed = false;
            hd_cancelledAddress = false;
            hd_promptedAddressKey = null;

            const isHeadSel = document.getElementById('f_is_head');
            const currentRole = isHeadSel?.value || '1';
            const existingLink = document.getElementById('f_family_head_id')?.value || '';
            const isEdit = document.getElementById('formAction')?.value === 'edit';

            document.getElementById('f_inherited_lat').value = '';
            document.getElementById('f_inherited_long').value = '';
            // Preserve an existing edit-time household relationship until the user
            // deliberately confirms a replacement or deliberately changes the role.
            document.getElementById('f_family_head_id').value =
                (isEdit && currentRole === '0') ? existingLink : '';

            if (clearCoordinates) {
                document.getElementById('f_lat').value = '';
                document.getElementById('f_long').value = '';
            }

            isHeadSel.disabled = false;
            isHeadSel.value = currentRole;
            toggleHeadSelector();
            toggleRelationshipField();

            document.getElementById('household_detection_panel').classList.add('hidden');
            document.getElementById('hd_checking_bar').classList.add('hidden');
            document.getElementById('head_exists_warning').classList.add('hidden');
            document.getElementById('hd_member_pending').classList.remove('hidden');
            document.getElementById('hd_member_confirmed').classList.add('hidden');
            document.getElementById('hd_no_head_state')?.classList.add('hidden');
            document.getElementById('hd_cancelled_state')?.classList.add('hidden');
            document.getElementById('hd_multiple_heads_state')?.classList.add('hidden');
            document.getElementById('hd_single_head_state')?.classList.remove('hidden');
            document.getElementById('location_pin_section').style.display = '';

            // Do not remove the resident's existing pin just because address detection
            // is re-running. The pin must remain visible while staff edits the address
            // and must remain visible after a household member link is confirmed.
            if (map && window.google && google.maps) {
                google.maps.event.trigger(map, 'resize');
            }
        }

        function householdAddressKey() {
            // Same-address household detection is triggered by these two fields only:
            // House/Lot/Unit Number + Street.
            return [
                document.getElementById('f_house_no')?.value.trim() || '',
                document.getElementById('f_street')?.value.trim() || ''
            ].map(v => v.toLowerCase().replace(/\s+/g, ' ').trim()).join('|');
        }

        function queueHouseholdConfirmation(addressKey) {
            if (!hd_detectedHeadId || hd_memberConfirmed) return;
            if (hd_cancelledAddress || hd_promptedAddressKey === addressKey) return;

            hd_promptedAddressKey = addressKey;
            setTimeout(() => {
                if (householdAddressKey() !== addressKey) return;
                if (hd_memberConfirmed || !hd_detectedHeadId || hd_cancelledAddress) return;
                confirmBecomeMember();
            }, 180);
        }

        function handleFamilyRoleChange() {
            const sel = document.getElementById('f_is_head');
            if (!sel) return;

            const originalRole = sel.dataset.originalRole || '1';
            const memberCount = Number(sel.dataset.originalMemberCount || 0);

            if (sel.value === '0' && originalRole === '1' && memberCount > 0) {
                showConfirmDialog({
                    title: 'Head of Family Has Household Members',
                    message:
                        `This resident is currently a Head of Family with ${memberCount} linked household member(s).\n\n` +
                        `Do not silently break the existing FamilyHeadID relationships. Change the household head first, then make this resident a member.`,
                    iconClass: 'bg-amber-50',
                    iconName: 'warning',
                    okLabel: 'Keep Head of Family',
                    okClass: 'bg-amber-600 text-white hover:bg-amber-700',
                    onConfirm: () => {
                        sel.value = '1';
                        sel.disabled = false;
                        toggleHeadSelector();
                        toggleRelationshipField();
                        showToast('Household members were preserved. Change the household head through Household Management before demoting this resident.', 'warning', 6000);
                    }
                });
                sel.value = '1';
                toggleHeadSelector();
                toggleRelationshipField();
                return;
            }

            toggleHeadSelector();
            if (sel.value === '0') {
                hd_promptedAddressKey = null;
                hd_cancelledAddress = false;
            }
            if (sel.value === '1') {
                document.getElementById('f_family_head_id').value = '';
                hd_memberConfirmed = false;
                document.getElementById('f_inherited_lat').value = '';
                document.getElementById('f_inherited_long').value = '';
                document.getElementById('location_pin_section').style.display = '';
            }
            checkHeadAvailability();
            toggleRelationshipField();
        }

        async function checkHeadAvailability() {
            const houseNo = document.getElementById('f_house_no').value.trim();
            const building = document.getElementById('f_building_name')?.value.trim() || '';
            const street = document.getElementById('f_street').value.trim();

            // When editing, do not rediscover/confirm a household merely because the
            // address fields were restored or a check was triggered. Only perform the
            // same-address Head lookup after House/Lot/Unit or Street actually differs
            // from the address that was loaded with the resident.
            const currentEditAddressKey = householdAddressKey();
            if (document.getElementById('formAction')?.value === 'edit' &&
                hd_originalEditAddressKey !== null &&
                currentEditAddressKey === hd_originalEditAddressKey) {
                return;
            }
            const area = document.getElementById('f_area')?.value.trim() || '';
            const areaType = document.getElementById('f_area_type')?.value.trim() || '';
            const purok = document.getElementById('f_purok').value.trim();

            clearTimeout(hd_debounceTimer);
            resetHouseholdDetection(false);

            if (!houseNo || !street) return;

            const addressKey = householdAddressKey();

            // A new address should be eligible for a fresh Head-of-Family confirmation.
            if (hd_promptedAddressKey !== addressKey) {
                hd_promptedAddressKey = null;
            }

            hd_debounceTimer = setTimeout(async () => {
                if (addressKey !== householdAddressKey()) return;

                document.getElementById('hd_checking_bar').classList.remove('hidden');

                try {
                    const qs = new URLSearchParams({
                        house_no: houseNo,
                        building: building,
                        street: street,
                        area: area,
                        area_type: areaType,
                        purok: purok,
                        region: document.getElementById('f_region')?.value || '',
                        province: document.getElementById('f_province')?.value || '',
                        municipality: document.getElementById('f_municipality')?.value || '',
                        barangay: document.getElementById('f_barangay')?.value || '',
                        region_code: document.getElementById('f_region_code')?.value || '',
                        province_code: document.getElementById('f_province_code')?.value || '',
                        municipality_code: document.getElementById('f_municipality_code')?.value || '',
                        barangay_code: document.getElementById('f_barangay_code')?.value || '',
                        exclude_id: document.getElementById('f_res_id').value || '0'
                    });

                    const resp = await fetch(`../backend/get_head_by_address.php?${qs.toString()}`, {
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json' }
                    });
                    if (!resp.ok) {
                        throw new Error(`Household detection endpoint returned HTTP ${resp.status}`);
                    }
                    const data = await resp.json();

                    document.getElementById('hd_checking_bar').classList.add('hidden');

                    if (addressKey !== householdAddressKey()) return;

                    const currentId = document.getElementById('f_res_id').value;

                    if (data.status === 'head_found' && data.heads?.length) {
                        const head = data.heads[0];

                        hd_detectedHeadId = head.id;
                        hd_detectedHeadName = head.name;
                        hd_detectedLat = (head.latitude !== null && head.latitude !== '' && parseFloat(head.latitude) !== 0)
                            ? head.latitude : null;
                        hd_detectedLng = (head.longitude !== null && head.longitude !== '' && parseFloat(head.longitude) !== 0)
                            ? head.longitude : null;

                        document.getElementById('hd_head_name').textContent = head.name || '—';
                        document.getElementById('hd_address').textContent =
                            [houseNo, street].filter(Boolean).join(', ');
                        document.getElementById('hd_purok').textContent = purok || head.purok || '—';
                        document.getElementById('hd_member_count').textContent =
                            `${Number(head.member_count || 0)} member(s)`;

                        document.getElementById('hd_single_head_state')?.classList.remove('hidden');
                        document.getElementById('hd_no_head_state')?.classList.add('hidden');
                        document.getElementById('hd_multiple_heads_state')?.classList.add('hidden');
                        document.getElementById('household_detection_panel').classList.remove('hidden');

                        // Same-address detection follows the SOE workflow: once a
                        // valid Head is found, switch the Family Role to Member immediately
                        // but keep it editable until the confirmation is accepted. The
                        // actual FamilyHeadID is only considered final after Confirm.
                        const isHeadSel = document.getElementById('f_is_head');
                        isHeadSel.value = '0';
                        isHeadSel.disabled = false;
                        document.getElementById('f_family_head_id').value = head.id;
                        toggleHeadSelector();
                        toggleRelationshipField();

                        document.getElementById('location_pin_section').style.display = '';
                        document.getElementById('existing_head_name').textContent = head.name;
                        document.getElementById('head_exists_warning').classList.remove('hidden');
                        applyDetectedHeadLocationToMap();

                        // Same as the SOE workflow: whenever the address resolves to
                        // an existing Head of Family, immediately ask for confirmation.
                        // Nothing is permanently linked until the user presses Confirm.
                        queueHouseholdConfirmation(addressKey);

                    } else if (data.status === 'multiple_heads' && data.heads?.length) {
                        hd_detectedHeadId = null;
                        hd_detectedHeadName = null;

                        const list = document.getElementById('hd_multiple_heads_list');
                        if (list) {
                            list.innerHTML = data.heads.map((h, idx) => `
                        <button type="button"
                                class="hd-head-option w-full text-left rounded-xl border border-amber-200 bg-white hover:bg-amber-50 px-4 py-3 transition-all"
                                data-head-id="${escHtml(h.id)}">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-lg bg-amber-100 text-amber-700 flex items-center justify-center font-black text-xs">${idx + 1}</div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-black text-slate-800 truncate">${escHtml(h.name)}</p>
                                    <p class="text-[9px] text-slate-500">${Number(h.member_count || 0)} household member(s)</p>
                                </div>
                            </div>
                        </button>
                    `).join('');

                            list.querySelectorAll('.hd-head-option').forEach(btn => {
                                btn.addEventListener('click', () => {
                                    const selected = data.heads.find(h => String(h.id) === String(btn.dataset.headId));
                                    if (!selected) return;
                                    selectDetectedHeadForConfirmation(selected, houseNo, building, street, area, purok);
                                });
                            });
                        }

                        document.getElementById('hd_single_head_state')?.classList.add('hidden');
                        document.getElementById('hd_no_head_state')?.classList.add('hidden');
                        document.getElementById('hd_multiple_heads_state')?.classList.remove('hidden');
                        document.getElementById('household_detection_panel').classList.remove('hidden');
                        document.getElementById('location_pin_section').style.display = '';
                        document.getElementById('f_family_head_id').value = '';
                        document.getElementById('f_is_head').disabled = false;

                    } else if (data.status === 'residents_no_head') {
                        document.getElementById('hd_no_head_residents').textContent =
                            `${Number(data.resident_count || data.residents?.length || 0)} resident(s) found at this address.`;

                        document.getElementById('hd_single_head_state')?.classList.add('hidden');
                        document.getElementById('hd_multiple_heads_state')?.classList.add('hidden');
                        document.getElementById('hd_no_head_state')?.classList.remove('hidden');
                        document.getElementById('household_detection_panel').classList.remove('hidden');
                        document.getElementById('location_pin_section').style.display = '';
                        document.getElementById('f_family_head_id').value = '';
                        document.getElementById('f_is_head').disabled = false;

                    } else {
                        document.getElementById('household_detection_panel').classList.add('hidden');
                    }

                    if (map && window.google && google.maps) {
                        google.maps.event.trigger(map, 'resize');
                    }
                } catch (err) {
                    console.error('Household detection error:', err);
                    document.getElementById('hd_checking_bar').classList.add('hidden');
                }
            }, 500);
        }

        function applyDetectedHeadLocationToMap() {
            if (hd_detectedLat === null || hd_detectedLng === null) return;

            const lat = parseFloat(hd_detectedLat);
            const lng = parseFloat(hd_detectedLng);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;

            const apply = () => {
                if (!map || !window.google || !google.maps) return;
                setMarker(lat, lng, false, true);
                map.setCenter({ lat, lng });
                map.setZoom(18);
            };

            if (map) apply();
            else setTimeout(apply, 150);
        }

        function selectDetectedHeadForConfirmation(head, houseNo, building, street, area, purok) {
            hd_detectedHeadId = head.id;
            hd_detectedHeadName = head.name;
            hd_detectedLat = (head.latitude !== null && head.latitude !== '' && parseFloat(head.latitude) !== 0)
                ? head.latitude : null;
            hd_detectedLng = (head.longitude !== null && head.longitude !== '' && parseFloat(head.longitude) !== 0)
                ? head.longitude : null;

            document.getElementById('hd_head_name').textContent = head.name || '—';
            document.getElementById('hd_address').textContent =
                [houseNo, street].filter(Boolean).join(', ');
            document.getElementById('hd_purok').textContent = purok || head.purok || '—';
            document.getElementById('hd_member_count').textContent =
                `${Number(head.member_count || 0)} member(s)`;

            document.getElementById('hd_single_head_state')?.classList.remove('hidden');
            document.getElementById('hd_multiple_heads_state')?.classList.add('hidden');
            document.getElementById('hd_no_head_state')?.classList.add('hidden');
            document.getElementById('location_pin_section').style.display = '';
            applyDetectedHeadLocationToMap();

            // For a manually selected Head (when multiple Heads exist), open the same
            // confirmation dialog used by the single-head detection flow.
            queueHouseholdConfirmation(householdAddressKey());
        }

        function confirmBecomeMember() {
            if (!hd_detectedHeadId) return;

            showConfirmDialog({
                title: 'Confirm Head of Family',
                message:
                    `Is this the correct Head of Family for this resident?\n\n` +
                    `Head of Family: ${hd_detectedHeadName || '—'}\n` +
                    `Address: ${document.getElementById('hd_address')?.textContent || '—'}\n` +
                    `Current Household Members: ${document.getElementById('hd_member_count')?.textContent || '0 member(s)'}`,
                iconClass: 'bg-indigo-50',
                iconName: 'family_restroom',
                okLabel: 'Confirm',
                okClass: 'bg-indigo-600 text-white hover:bg-indigo-700',
                onCancel: () => { cancelBecomeMember(); },
                onConfirm: () => {
                    hd_memberConfirmed = true;

                    const inheritedLat = hd_detectedLat || '';
                    const inheritedLng = hd_detectedLng || '';

                    document.getElementById('f_inherited_lat').value = inheritedLat;
                    document.getElementById('f_inherited_long').value = inheritedLng;
                    if (inheritedLat !== '' && inheritedLng !== '') {
                        document.getElementById('f_lat').value = inheritedLat;
                        document.getElementById('f_long').value = inheritedLng;
                        applyDetectedHeadLocationToMap();
                    }

                    const isHeadSel = document.getElementById('f_is_head');
                    isHeadSel.value = '0';
                    isHeadSel.disabled = true;

                    document.getElementById('f_family_head_id').value = hd_detectedHeadId;
                    toggleRelationshipField();

                    document.getElementById('hd_member_pending').classList.add('hidden');
                    document.getElementById('hd_member_confirmed').classList.remove('hidden');
                    document.getElementById('hd_confirmed_head_name').textContent = hd_detectedHeadName || '—';
                    // Keep the map and inherited household pin visible after linking.
                    document.getElementById('location_pin_section').style.display = '';
                    applyDetectedHeadLocationToMap();
                    document.getElementById('hd_status_badge').textContent = 'Confirmed';
                }
            });
        }

        function cancelBecomeMember() {
            hd_memberConfirmed = false;
            hd_cancelledAddress = true;
            hd_promptedAddressKey = householdAddressKey();
            hd_detectedHeadId = null;
            hd_detectedHeadName = null;
            hd_detectedLat = null;
            hd_detectedLng = null;

            // Cancellation must remove the proposed link. Do not copy the head's GPS.
            document.getElementById('f_inherited_lat').value = '';
            document.getElementById('f_inherited_long').value = '';
            // For a new resident this stays empty. During edit, keep the existing link
            // already present in the form so an address change cannot silently break it.
            if (document.getElementById('formAction')?.value !== 'edit') {
                document.getElementById('f_family_head_id').value = '';
            }

            const isHeadSel = document.getElementById('f_is_head');
            isHeadSel.disabled = false;
            // Leave the currently selected Family Role unchanged so staff can continue.
            toggleHeadSelector();
            toggleRelationshipField();

            document.getElementById('hd_member_confirmed').classList.add('hidden');
            document.getElementById('hd_member_pending').classList.remove('hidden');
            document.getElementById('hd_status_badge').textContent = 'Not Linked';
            document.getElementById('location_pin_section').style.display = '';

            const state = document.getElementById('hd_cancelled_state');
            if (state) state.classList.remove('hidden');
        }

        function hideHouseholdDetectionPanel() {
            resetHouseholdDetection(false);
        }

        function prepareResidentForCreate() {
            hd_originalEditAddressKey = null;
        }

        function editResidentFromButton(button) {
            try {
                const encoded = button?.dataset?.resident || '';
                if (!encoded) throw new Error('Resident data is missing from the Edit button.');
                const binary = atob(encoded);
                const bytes = Uint8Array.from(binary, ch => ch.charCodeAt(0));
                const json = new TextDecoder('utf-8').decode(bytes);
                const data = JSON.parse(json);
                return editResident(data);
            } catch (error) {
                console.error('[Residents] Edit button data error:', error);
                showToast('Hindi mabuksan ang resident profile. Check the browser Console for the exact error.', 'error', 7000);
            }
        }

        async function editResident(data) {
            document.getElementById('modalTitle').innerText = "Edit Resident Profile";
            document.getElementById('formAction').value = 'edit';
            document.getElementById('f_res_id').value = data.ResidentID;
            document.getElementById('f_resident_code').value = data.ResidentCode || '';
            document.getElementById('saveBtn').innerText = "Update Resident Profile";
            document.getElementById('f_email').value = data.Email;
            document.getElementById('f_contact').value = data.ContactNumber;
            document.getElementById('f_first').value = data.FirstName;
            document.getElementById('f_last').value = data.LastName;
            document.getElementById('f_middle').value = data.MiddleName || '';
            document.getElementById('f_suffix').value = data.Suffix || '';
            document.getElementById('f_dob').value = data.BirthDate;
            document.getElementById('f_pob').value = data.BirthPlace || '';
            document.getElementById('f_sex').value = data.Sex;
            document.getElementById('f_house_no').value = data.HouseNumber || '';
            if (document.getElementById('f_building_name')) document.getElementById('f_building_name').value = data.BuildingName || '';
            document.getElementById('f_street').value = data.StreetName || '';
            document.getElementById('f_religion').value = data.Religion || '';
            if (document.getElementById('f_nationality')) {
                document.getElementById('f_nationality').value = data.Nationality || '';
            }
            // The current address UI uses the managed Area dropdown (Subdivision/Village/Sitio/Purok).
            // The old setPurokDropdownValue() helper belonged to the previous Purok-only UI and
            // was removed, so calling it here caused the Edit button to stop immediately.
            if (typeof window.populatePSGCAddress === 'function') {
                await window.populatePSGCAddress(data);
            }
            document.getElementById('f_is_pwd').checked = data.IsPWD == 1;
            if (data.IsPWD == 1) {
                document.getElementById('f_pwd_class').value = data.PWDClassification || '';
                document.getElementById('f_pwd_id').value = data.PWDID || '';
                document.getElementById('pwd_details').classList.remove('hidden');
            } else {
                document.getElementById('f_pwd_class').value = '';
                document.getElementById('f_pwd_id').value = '';
                document.getElementById('pwd_details').classList.add('hidden');
            }
            document.getElementById('f_is_senior').checked = data.IsSenior == 1;
            document.getElementById('f_is_senior').disabled = false;

            // ── Deceased status lock ─────────────────────────────────────
            const isDeceasedEl = document.getElementById('f_is_deceased');
            const deceasedLabel = isDeceasedEl.closest('label');
            if (data.IsDeceased == 1) {
                isDeceasedEl.checked = true;
                isDeceasedEl.disabled = true;
                deceasedLabel.classList.add('opacity-60', 'cursor-not-allowed');
                deceasedLabel.title = 'Deceased status cannot be reversed.';
            } else {
                isDeceasedEl.checked = false;
                isDeceasedEl.disabled = false;
                deceasedLabel.classList.remove('opacity-60', 'cursor-not-allowed');
                deceasedLabel.title = '';
            }

            // ── Voter ────────────────────────────────────────────────────
            document.getElementById('f_is_voter').checked = data.IsVoter == 1;
            // VoterNumber is a hidden field — preserve saved value
            if (document.getElementById('f_voter_number')) {
                document.getElementById('f_voter_number').value = data.VoterNumber || '';
            }

            document.getElementById('f_total_house_income').value = data.TotalHouseholdIncome || 0;
            // ── Socio-Economic Profile ─────────────────────────────────────
            // Restore Employment Status and Source of Income when opening Edit.
            // These fields are submitted normally by the form; this block prevents
            // the edit modal from resetting them to the default/unchecked state.
            const empStatusEl = document.getElementById('f_emp_status');
            if (empStatusEl) {
                empStatusEl.value = data.EmploymentStatus || 'Unemployed';
                const empOtherEl = document.getElementById('f_emp_status_other');
                if (empOtherEl) empOtherEl.value = data.EmploymentStatusOther || '';
                const occupationEl = document.getElementById('f_occupation');
                if (occupationEl) occupationEl.value = data.Occupation || '';
                toggleEmploymentFields();
            }

            const savedSources = String(data.SourceOfIncome || '')
                .split(',')
                .map(v => v.trim())
                .filter(Boolean);
            document.querySelectorAll('.source-income-cb').forEach(cb => {
                cb.checked = savedSources.includes(cb.value);
            });
            const sourceOtherEl = document.getElementById('f_source_income_other');
            if (sourceOtherEl) sourceOtherEl.value = data.SourceOfIncomeOther || '';
            if (typeof toggleSourceIncomeOther === 'function') toggleSourceIncomeOther();

            document.getElementById('f_philhealth').checked = data.HasPhilhealth == 1;
            if (document.getElementById('f_sss')) {
                document.getElementById('f_sss').checked = data.IsSSSMember == 1;
            }
            if (document.getElementById('f_gsis')) {
                document.getElementById('f_gsis').checked = data.IsGSISMember == 1;
            }
            document.getElementById('f_4ps').checked = data.Has4Ps == 1;
            if (document.getElementById('f_pagibig')) {
                document.getElementById('f_pagibig').checked = data.IsPagibigMember == 1;
            }
            calculateAge();
            toggleWelfareFields();
            toggleDeathDoc();

            // resetHouseholdDetection sets f_is_head='1' and clears f_rel — so we
            // restore IsHead, FamilyHeadID, and RelationshipToHead AFTER calling it.
            resetHouseholdDetection();
            document.getElementById('f_is_head').value = data.IsHead;
            document.getElementById('f_is_head').disabled = false;
            document.getElementById('f_is_head').dataset.originalRole = String(data.IsHead ?? '1');
            document.getElementById('f_is_head').dataset.originalMemberCount = String(data.MemberCount ?? 0);
            document.getElementById('f_family_head_id').value = data.FamilyHeadID || '';
            toggleHeadSelector();
            toggleRelationshipField();
            // Restore rel value AFTER toggleRelationshipField (which would wipe it if IsHead='1')
            document.getElementById('f_rel').value = data.RelationshipToHead || '';

            document.getElementById('location_pin_section').style.display = '';
            const modal = document.getElementById('resModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => {
                try {
                    initMap();
                    clearMapMarker();
                    if (map && window.google && google.maps) {
                        google.maps.event.trigger(map, 'resize');
                        if (data.Latitude && data.Longitude && data.Latitude != 0) {
                            setMarker(data.Latitude, data.Longitude, true);
                            map.setCenter({ lat: parseFloat(data.Latitude), lng: parseFloat(data.Longitude) });
                            map.setZoom(18);
                        } else {
                            map.setCenter({ lat: 14.8000, lng: 120.9333 });
                            map.setZoom(15);
                        }
                    }
                } catch (e) { console.error('Residents map edit error:', e); }
            }, 400);

            // Snapshot the address exactly as it existed when Edit was opened.
            // Do NOT run household detection here: an unchanged existing address must
            // not trigger the Confirm Head of Family dialog. If staff later changes
            // House/Lot/Unit Number or Street, the normal input/change handler will
            // detect the new address and run the confirmation flow.
            hd_originalEditAddressKey = householdAddressKey();
        }

        function filterResidents() {
            const searchText = document.getElementById("residentSearch").value.toUpperCase();
            const genderFilter = document.getElementById("rep_sex").value.toUpperCase();
            const classFilter = document.getElementById("rep_class").value.toUpperCase();
            document.querySelectorAll("#residentTableBody tr").forEach(row => {
                const matchesSearch = row.textContent.toUpperCase().includes(searchText);
                const genderEl = row.querySelector(".gender-val");
                const classEl = row.querySelector(".classification-cell");
                const matchesGender = !genderFilter || (genderEl && genderEl.textContent.trim().toUpperCase() === genderFilter);
                const matchesClass = !classFilter || (classEl && classEl.textContent.toUpperCase().includes(classFilter));
                row.style.display = (matchesSearch && matchesGender && matchesClass) ? "" : "none";
            });
        }

        function confirmDelete(id, name) {
            showConfirmDialog({
                title: 'Delete Resident?',
                message: `Are you sure you want to delete ${name}? This action cannot be undone.`,
                iconClass: 'bg-rose-50',
                iconName: 'delete',
                okLabel: 'Yes, Delete',
                okClass: 'bg-rose-600 text-white hover:bg-rose-700',
                onConfirm: () => { window.location.href = `../backend/delete_resident.php?id=${id}`; }
            });
        }

        var _confirmCallback = null;
        var _confirmCancelCallback = null;

        function showConfirmDialog({ title, message, iconClass, iconName, okLabel, okClass, onConfirm, onCancel }) {
            document.getElementById('confirmDialogTitle').textContent = title;
            document.getElementById('confirmDialogMessage').textContent = message;
            const iconWrap = document.getElementById('confirmDialogIcon');
            iconWrap.className = 'w-12 h-12 rounded-2xl flex items-center justify-center shrink-0 ' + (iconClass || 'bg-indigo-50');
            iconWrap.querySelector('span').textContent = iconName || 'help';
            iconWrap.querySelector('span').className = 'material-symbols-outlined text-2xl ' + (iconClass ? iconClass.replace('bg-', 'text-').replace('-50', '-600') : 'text-indigo-600');
            const okBtn = document.getElementById('confirmDialogOkBtn');
            okBtn.textContent = okLabel || 'Confirm';
            okBtn.className = 'flex-[2] py-3.5 text-xs font-black uppercase rounded-2xl shadow-lg active:scale-95 transition-all ' + (okClass || 'bg-primary text-white hover:bg-indigo-700');
            _confirmCallback = onConfirm;
            _confirmCancelCallback = onCancel || null;
            const dlg = document.getElementById('confirmDialog');
            dlg.classList.remove('hidden');
            dlg.classList.add('flex');
        }

        function dismissConfirmDialog(invokeCancel = true) {
            const dlg = document.getElementById('confirmDialog');
            const cancelCallback = _confirmCancelCallback;
            dlg.classList.add('hidden');
            dlg.classList.remove('flex');
            _confirmCallback = null;
            _confirmCancelCallback = null;
            if (invokeCancel && typeof cancelCallback === 'function') cancelCallback();
        }

        document.getElementById('confirmDialogOkBtn').addEventListener('click', function () {
            const callback = _confirmCallback;
            dismissConfirmDialog(false);
            if (typeof callback === 'function') callback();
        });

        document.getElementById('confirmDialogCancelBtn').addEventListener('click', function () {
            dismissConfirmDialog(true);
        });

        document.getElementById('confirmDialog').addEventListener('click', function (e) {
            if (e.target === this) dismissConfirmDialog(true);
        });

        // ── Field-level error helpers ──────────────────────────────────────────
        function setFieldError(inputId, msg) {
            const el = document.getElementById(inputId);
            if (!el) return;
            el.classList.add('ring-2', 'ring-rose-400');
            let errSpan = el.parentElement.querySelector('.form-field-err');
            if (!errSpan) {
                errSpan = document.createElement('p');
                errSpan.className = 'form-field-err text-[10px] text-rose-500 font-bold mt-1 ml-1';
                el.parentElement.appendChild(errSpan);
            }
            errSpan.textContent = msg;
        }

        function clearFieldError(inputId) {
            const el = document.getElementById(inputId);
            if (!el) return;
            el.classList.remove('ring-2', 'ring-rose-400');
            const errSpan = el.parentElement.querySelector('.form-field-err');
            if (errSpan) errSpan.remove();
        }

        ['f_contact', 'f_email', 'f_first', 'f_last', 'f_middle', 'f_dob', 'f_suffix'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('input', () => clearFieldError(id));
        });

        // ── AJAX duplicate checker ──────────────────────────────────────────
        async function checkDuplicate(type, params) {
            try {
                const fd = new FormData();
                fd.append('type', type);
                for (const [k, v] of Object.entries(params)) fd.append(k, v);
                const res = await fetch('../backend/check_duplicate.php', { method: 'POST', body: fd });
                const data = await res.json();
                return data;
            } catch (_) {
                return { duplicate: false, message: '' };
            }
        }

        // ── Double-submit guard ────────────────────────────────────────────
        let _residentSubmitting = false;

        document.getElementById('residentForm').onsubmit = async function (e) {
            e.preventDefault();
            if (_residentSubmitting) return;

            const action = document.getElementById('formAction').value;
            const resId = document.getElementById('f_res_id')?.value || '';
            const contact = document.getElementById('f_contact').value.trim();
            const email = (document.getElementById('f_email')?.value || '').trim();
            const fname = document.getElementById('f_first').value.trim();
            const lname = document.getElementById('f_last').value.trim();
            const mname = (document.getElementById('f_middle')?.value || '').trim();
            const suffix = (document.getElementById('f_suffix')?.value || '').trim();
            const bdate = document.getElementById('f_dob').value;
            const familyRole = document.getElementById('f_is_head')?.value || '1';
            const familyHeadId = document.getElementById('f_family_head_id')?.value || '';

            // A detected household is only a proposal. The staff member must explicitly
            // confirm the detected Head before a new member link can be submitted.
            if (familyRole === '0' && hd_detectedHeadId && !hd_memberConfirmed) {
                showToast('Please confirm the detected Head of Family before saving this resident as a household member.', 'warning');
                return;
            }

            if (familyRole === '0' && !familyHeadId) {
                showToast('No household link is applied. Select Head of Family or confirm a valid household Head first.', 'warning');
                return;
            }

            if (contact && !/^09\d{9}$/.test(contact)) {
                setFieldError('f_contact', 'Kung maglalagay ng mobile number, dapat 11 digits at nagsisimula sa 09.');
                showToast('Optional ang contact number. Kung ilalagay, gamitin ang 09XXXXXXXXX.', 'warning');
                return;
            }

            const saveBtn = document.getElementById('saveBtn');
            const origLabel = saveBtn.textContent;
            saveBtn.disabled = true;
            saveBtn.textContent = 'Checking...';

            let hasError = false;

            if (fname && lname && bdate) {
                const iRes = await checkDuplicate('identity', {
                    first_name: fname, middle_name: mname, last_name: lname,
                    suffix: suffix, birthdate: bdate, exclude_id: resId
                });
                if (iRes.duplicate) {
                    setFieldError('f_dob', iRes.message);
                    showToast(iRes.message, 'error');
                    hasError = true;
                }
            }

            if (!hasError && contact.length === 11) {
                const cRes = await checkDuplicate('contact', { contact, exclude_id: resId });
                if (cRes.duplicate) {
                    setFieldError('f_contact', cRes.message);
                    showToast(cRes.message, 'error');
                    hasError = true;
                }
            }

            if (!hasError && email && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                const eRes = await checkDuplicate('email', { email, exclude_id: resId });
                if (eRes.duplicate) {
                    setFieldError('f_email', eRes.message);
                    showToast(eRes.message, 'error');
                    hasError = true;
                }
            }

            if (hasError) {
                saveBtn.disabled = false;
                saveBtn.textContent = origLabel;
                return;
            }

            saveBtn.textContent = origLabel;
            saveBtn.disabled = false;

            const isEdit = action === 'edit';
            const fullName = (fname + ' ' + lname).trim() || 'this resident';
            showConfirmDialog({
                title: isEdit ? 'Update Resident Profile?' : 'Save New Resident?',
                message: isEdit
                    ? `You are about to update the profile of ${fullName}. Please review all entered information before confirming.`
                    : `You are about to register ${fullName} as a new resident. Please review all entered information before confirming.`,
                iconClass: 'bg-indigo-50',
                iconName: isEdit ? 'edit_square' : 'person_add',
                okLabel: isEdit ? 'Yes, Update Profile' : 'Yes, Save Resident',
                okClass: 'bg-primary text-white hover:bg-indigo-700',
                onConfirm: () => {
                    _residentSubmitting = true;
                    saveBtn.disabled = true;
                    saveBtn.textContent = 'Submitting...';
                    document.getElementById('f_is_senior').disabled = false;
                    document.getElementById('f_is_deceased').disabled = false;
                    document.getElementById('residentForm').submit();
                }
            });
        };

        // ── Show PHP backend errors/success on page load (toast-based) ─────
        (function showPageLoadAlerts() {
            const params = new URLSearchParams(window.location.search);
            const status = params.get('status');
            const message = params.get('message');
            if (status === 'success') {
                showToast(message ? decodeURIComponent(message) : 'Resident saved successfully.', 'success');
                history.replaceState({}, '', window.location.pathname);
            } else if (status === 'error' && message) {
                showToast(decodeURIComponent(message), 'error');
                history.replaceState({}, '', window.location.pathname);
            }
        })();

        // ═══════════════════════════════════════════════════════════════════
        //  ACCESS REQUEST SYSTEM — JavaScript
        // ═══════════════════════════════════════════════════════════════════

        let _disapproveReqId = null;
        let _disapproveReqName = null;
        let _approveReqId = null;
        let _approveReqName = null;
        let _approveReqEmail = null;

        /* ── AJAX table refresh ── */
        function refreshAccessRequests() {
            const icon = document.getElementById('arRefreshIcon');
            if (icon) icon.classList.add('animate-spin');

            const fd = new FormData();
            fd.append('action', 'refresh_requests');

            fetch(location.href, { method: 'POST', body: fd })
                .then(r => {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(res => {
                    if (!res.success) return;
                    renderAccessRequestRows(res.data);
                    const ts = document.getElementById('arLastRefresh');
                    if (ts) ts.textContent = new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                })
                .catch(err => console.warn('Access request refresh error:', err))
                .finally(() => { if (icon) icon.classList.remove('animate-spin'); });
        }

        /* ── Re-render tbody from AJAX data ── */
        function renderAccessRequestRows(rows) {
            const tbody = document.getElementById('accessRequestTableBody');
            if (!tbody) return;

            if (!rows || rows.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" class="text-center py-16 text-slate-400">
            <span class="material-symbols-outlined text-4xl block mb-2 text-slate-200">inbox</span>
            No access requests found.</td></tr>`;
                return;
            }

            const esc = s => String(s ?? '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));

            const badgeCls = s => ({
                Pending: 'bg-amber-50 text-amber-600 border-amber-100',
                Approved: 'bg-emerald-50 text-emerald-600 border-emerald-100',
                Disapproved: 'bg-rose-50 text-rose-600 border-rose-100',
            }[s] || 'bg-slate-50 text-slate-500 border-slate-100');

            const fmtDate = d => {
                try { return new Date(d).toLocaleDateString('en-PH', { month: 'short', day: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }); }
                catch { return d; }
            };

            tbody.innerHTML = rows.map((ar, i) => `
        <tr class="hover:bg-slate-50/50 transition-colors group" data-req-id="${ar.id}">
            <td class="px-8 py-4 text-[11px] font-bold text-slate-400">${i + 1}</td>
            <td class="px-6 py-4"><p class="text-sm font-bold text-slate-700">${esc(ar.fullname)}</p></td>
            <td class="px-6 py-4 text-xs text-slate-500 font-medium">${esc(ar.email)}</td>
            <td class="px-6 py-4 text-[11px] text-slate-400 font-bold whitespace-nowrap">${fmtDate(ar.created_at)}</td>
            <td class="px-6 py-4">
                <span class="px-2.5 py-1 text-[9px] font-black uppercase rounded-lg border ${badgeCls(ar.status)} tracking-wider">
                    ${esc(ar.status)}
                </span>
            </td>
            <td class="px-8 py-4 text-right">
                <div class="flex justify-end gap-2 flex-wrap">
                    <a href="access_requests.php?id=${ar.id}"
                       class="flex items-center gap-1 px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-600 text-[9px] font-black uppercase rounded-xl transition-all border border-indigo-100">
                        <span class="material-symbols-outlined text-sm">visibility</span> View Details
                    </a>
                </div>
            </td>
        </tr>`).join('');
        }

        /* ── Auto-refresh every 60 s ── */
        setInterval(refreshAccessRequests, 60000);

        /* ── Manage Area ─────────────────────────────────────────────── */
        let managedBarangayCode = '';
        let managedAreaRun = 0;
        function managerGet(url) { return fetch(url, { credentials: 'same-origin' }).then(r => r.json()).then(j => { if (!j.success) throw new Error(j.message || 'Request failed'); return j.data || []; }); }
        function managerFill(id, rows, placeholder) { const el = document.getElementById(id); if (!el) return; el.innerHTML = '<option value="">' + placeholder + '</option>'; rows.forEach(x => { const o = document.createElement('option'); o.value = x.code; o.textContent = fixMojibake(x.name || ''); el.appendChild(o) }); el.disabled = rows.length === 0; }
        async function managerLoadRegions() { try { managerFill('mgr_region', await managerGet('../backend/psgc_proxy.php?action=regions'), 'Select region') } catch (e) { console.error(e) } }
        async function managerLoadChildren(action, params, id, placeholder) { const rows = await managerGet('../backend/psgc_proxy.php?action=' + action + '&' + new URLSearchParams(params)); managerFill(id, rows, placeholder); return rows }
        function fixMojibake(v) { let s = String(v ?? ''); if (/[ÃÂâ]/.test(s)) { try { const repaired = decodeURIComponent(escape(s)); if (repaired && !/[ÃÂâ]/.test(repaired)) return repaired } catch (e) { } } return s }
        async function openManageAreaModal() {
            const m = document.getElementById('manageAreaModal');
            m.classList.remove('hidden'); m.classList.add('flex');
            await managerLoadRegions();
            await loadBarangayProfile();
        }
        async function loadBarangayProfile() {
            try {
                const r = await fetch('../backend/address_api.php?action=profile', { credentials: 'same-origin' });
                const j = await r.json(); const p = j.data || {};
                if (!j.success) throw new Error(j.message || 'Unable to load profile');
                const set = (id, v) => { const e = document.getElementById(id); if (e) e.value = v || ''; };
                if (p.psgc_region_code) {
                    set('mgr_region', p.psgc_region_code);
                    await managerLoadChildren('provinces', { reg: p.psgc_region_code }, 'mgr_province', 'Select province');
                    set('mgr_province', p.psgc_province_code);
                    await managerLoadChildren('municipalities', { prv: p.psgc_province_code }, 'mgr_municipality', 'Select city/municipality');
                    set('mgr_municipality', p.psgc_municipality_code);
                    await managerLoadChildren('barangays', { prv: p.psgc_province_code, mun: p.psgc_municipality_code }, 'mgr_barangay', 'Select barangay');
                    set('mgr_barangay', p.psgc_barangay_code);
                }
                set('mgr_zip', p.zip_code);
                set('mgr_address', p.address);
                const o = document.getElementById('mgr_barangay')?.selectedOptions[0];
                document.getElementById('mgr_selected_label').textContent = o ? ('Managing: ' + o.textContent) : 'Choose a barangay to configure the default address.';
                managedBarangayCode = p.psgc_barangay_code || '';
                await loadManagedLists();
            } catch (e) { console.error(e); }
        }
        async function saveBarangayProfile() {
            const ids = ['mgr_region', 'mgr_province', 'mgr_municipality', 'mgr_barangay'];
            const els = ids.map(id => document.getElementById(id));
            if (els.some(e => !e || !e.value)) { showToast('Select Region, Province, City/Municipality and Barangay first.', 'warning'); return; }
            const fd = new FormData();
            fd.append('address_action', 'profile_save');
            fd.append('region_code', els[0].value);
            fd.append('province_code', els[1].value);
            fd.append('municipality_code', els[2].value);
            fd.append('barangay_code', els[3].value);
            fd.append('region_name', els[0].selectedOptions[0]?.textContent || '');
            fd.append('province_name', els[1].selectedOptions[0]?.textContent || '');
            fd.append('municipality_name', els[2].selectedOptions[0]?.textContent || '');
            fd.append('barangay_name', els[3].selectedOptions[0]?.textContent || '');
            fd.append('zip_code', document.getElementById('mgr_zip')?.value.trim() || '');
            fd.append('address', document.getElementById('mgr_address')?.value.trim() || '');
            try {
                const r = await fetch(location.href, { method: 'POST', body: fd, credentials: 'same-origin' });
                const j = await r.json();
                showToast(j.message || 'Saved', j.success ? 'success' : 'error');
                if (j.success) { managedBarangayCode = els[3].value; await loadManagedLists(); }
            } catch (e) { showToast('Unable to save the default address.', 'error'); }
        }

        function closeManageAreaModal() { const m = document.getElementById('manageAreaModal'); m.classList.add('hidden'); m.classList.remove('flex'); managedBarangayCode = ''; }
        async function loadManagedLists() { const b = document.getElementById('mgr_barangay')?.value || ''; managedBarangayCode = b; const sb = document.getElementById('streetManagerBody'), ab = document.getElementById('areaManagerBody'); if (!b) { sb.innerHTML = '<tr><td class="p-6 text-center text-slate-400 text-xs">Select a barangay first.</td></tr>'; ab.innerHTML = '<tr><td class="p-6 text-center text-slate-400 text-xs">Select a barangay first.</td></tr>'; return; } try { const [streets, areas] = await Promise.all([managerGet('../backend/address_api.php?action=streets&barangay=' + encodeURIComponent(b)), managerGet('../backend/address_api.php?action=areas&barangay=' + encodeURIComponent(b))]); sb.innerHTML = streets.length ? streets.map(x => managerRow('street', x)).join('') : '<tr><td class="p-6 text-center text-slate-400 text-xs italic">No streets yet.</td></tr>'; ab.innerHTML = areas.length ? areas.map(x => managerRow('area', x)).join('') : '<tr><td class="p-6 text-center text-slate-400 text-xs italic">No areas yet.</td></tr>'; } catch (e) { console.error(e); sb.innerHTML = ab.innerHTML = '<tr><td class="p-6 text-center text-rose-400 text-xs">Unable to load records.</td></tr>'; } }
        function managerRow(type, x) {
            const name = fixMojibake(type === 'street' ? x.street_name : x.area_name);
            const label = type === 'area' ? name + ' <span class="text-[8px] text-slate-400">(' + escHtml(x.area_type) + ')</span>' : name;
            const safeName = escHtml(JSON.stringify(name));
            return '<tr class="border-b border-slate-100"><td class="px-4 py-3 text-sm font-bold text-slate-700">' + label + '</td><td class="px-4 py-3 text-right">'
                + ' <button type="button" onclick="editManagedAddress(\'' + type + '\',' + x.id + ',' + safeName + ')" class="text-[9px] font-black uppercase text-indigo-600 mr-2">Edit</button>'
                + ' <button type="button" onclick="deleteManagedAddress(\'' + type + '\',' + x.id + ',' + safeName + ')" class="text-[9px] font-black uppercase text-rose-600">Delete</button>'
                + '</td></tr>';
        }

        async function addManagedAddress(type) { if (!managedBarangayCode) { showToast('Select a barangay first.', 'warning'); return; } const name = (type === 'street' ? document.getElementById('newStreetInput').value : document.getElementById('newAreaInput').value).trim(); if (!name) { showToast('Enter a name first.', 'warning'); return; } const fd = new FormData(); fd.append('address_action', 'add'); fd.append('address_type', type); fd.append('barangay', managedBarangayCode); fd.append('name', name); if (type === 'area') fd.append('area_type', document.getElementById('newAreaType').value); const r = await fetch(location.href, { method: 'POST', body: fd }); const j = await r.json(); showToast(j.message || 'Done', j.success ? 'success' : 'error'); if (j.success) { if (type === 'street') document.getElementById('newStreetInput').value = ''; else document.getElementById('newAreaInput').value = ''; loadManagedLists(); } }
        async function editManagedAddress(type, id, current) { const name = prompt('Edit ' + (type === 'street' ? 'street' : 'area') + ' name:', current); if (name === null) return; const fd = new FormData(); fd.append('address_action', 'edit'); fd.append('address_type', type); fd.append('id', id); fd.append('name', name.trim()); if (type === 'area') fd.append('area_type', document.getElementById('newAreaType').value); const r = await fetch(location.href, { method: 'POST', body: fd }); const j = await r.json(); showToast(j.message || 'Done', j.success ? 'success' : 'error'); if (j.success) loadManagedLists(); }
        async function deleteManagedAddress(type, id, name) { if (!confirm('Delete ' + name + '?')) return; const fd = new FormData(); fd.append('address_action', 'delete'); fd.append('address_type', type); fd.append('id', id); const r = await fetch(location.href, { method: 'POST', body: fd }); const j = await r.json(); showToast(j.message || 'Done', j.success ? 'success' : 'error'); if (j.success) loadManagedLists(); }
        ['mgr_region', 'mgr_province', 'mgr_municipality', 'mgr_barangay'].forEach(id => document.getElementById(id)?.addEventListener('change', async function () { if (id === 'mgr_region') { managerFill('mgr_province', [], 'Select province'); managerFill('mgr_municipality', [], 'Select city/municipality'); managerFill('mgr_barangay', [], 'Select barangay'); if (this.value) await managerLoadChildren('provinces', { reg: this.value }, 'mgr_province', 'Select province'); } else if (id === 'mgr_province') { managerFill('mgr_municipality', [], 'Select city/municipality'); managerFill('mgr_barangay', [], 'Select barangay'); if (this.value) await managerLoadChildren('municipalities', { prv: this.value }, 'mgr_municipality', 'Select city/municipality'); } else if (id === 'mgr_municipality') { managerFill('mgr_barangay', [], 'Select barangay'); if (this.value) { await managerLoadChildren('barangays', { prv: document.getElementById('mgr_province').value, mun: this.value }, 'mgr_barangay', 'Select barangay'); try { const z = await fetch('../backend/address_api.php?action=zip&municipality=' + encodeURIComponent(this.value)); const zj = await z.json(); if (zj.zip_code) document.getElementById('mgr_zip').value = zj.zip_code; } catch (e) { } } } else { const o = this.selectedOptions[0]; managedBarangayCode = this.value; document.getElementById('mgr_selected_label').textContent = o ? ('Managing: ' + o.textContent) : 'Choose a barangay to manage its address lists.'; await loadManagedLists(); } }));

        function escHtml(str) {
            return String(str ?? '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
        }

    </script>

    <style>
        .addr-select {
            width: 100%;
            background: #f1f5f9;
            border: 0;
            border-radius: .75rem;
            padding: .75rem 1rem;
            font-size: .875rem;
            font-weight: 700
        }

        .addr-select:disabled {
            opacity: .65;
            cursor: not-allowed
        }
    </style>
    <script>
        (function () {
            const ids = { r: 'f_region', p: 'f_province', m: 'f_municipality', b: 'f_barangay', s: 'f_street', a: 'f_area' };
            const codes = { r: 'f_region_code', p: 'f_province_code', m: 'f_municipality_code', b: 'f_barangay_code' };
            const $ = id => document.getElementById(id);
            function fill(sel, rows, placeholder) {
                const el = $(sel); el.innerHTML = '<option value="">' + placeholder + '</option>';
                rows.forEach(x => { const o = document.createElement('option'); o.value = x.code; o.textContent = fixMojibake(x.name); o.dataset.name = fixMojibake(x.name); el.appendChild(o); });
                el.disabled = rows.length === 0;
            }
            async function get(url) { const r = await fetch(url, { credentials: 'same-origin' }); const j = await r.json(); if (!j.success) throw new Error(j.message || 'Request failed'); return j.data || []; }
            function clearAfter(level) {
                const order = ['p', 'm', 'b', 's', 'a'], i = order.indexOf(level);
                order.slice(i).forEach(k => { if ($(ids[k])) { $(ids[k]).innerHTML = '<option value="">Select a parent first</option>'; $(ids[k]).disabled = true; } });
            }
            async function loadRegions() {
                try { const d = await get('../backend/psgc_proxy.php?action=regions'); fill(ids.r, d, 'Select region'); }
                catch (e) { $(ids.r).innerHTML = '<option value="">Unable to load regions</option>'; console.error(e); }
            }
            async function loadChildren(type, params, sel, placeholder) {
                const el = $(sel); if (!el) return [];
                el.disabled = true; el.innerHTML = '<option value="">Loading...</option>';
                const url = '../backend/psgc_proxy.php?action=' + encodeURIComponent(type) + '&' + new URLSearchParams(params).toString();
                let lastError = null;
                for (let attempt = 1; attempt <= 3; attempt++) {
                    try {
                        const d = await get(url);
                        if (Array.isArray(d) && d.length) { fill(sel, d, placeholder); return d; }
                        lastError = new Error('PSGC returned no records for ' + type + ' (' + url + ')');
                    } catch (e) { lastError = e; }
                    if (attempt < 3) await new Promise(r => setTimeout(r, 350 * attempt));
                }
                el.innerHTML = '<option value="">Unable to load records</option>';
                el.disabled = true;
                console.error('PSGC child lookup failed:', type, params, lastError);
                return [];
            }
            async function loadLocal(action, param, sel, placeholder) {
                $(sel).disabled = true; $(sel).innerHTML = '<option value="">Loading...</option>';
                try {
                    const d = await get('../backend/address_api.php?action=' + action + '&' + new URLSearchParams(param));
                    const el = $(sel); el.innerHTML = '<option value="">' + placeholder + '</option>';
                    d.forEach(x => { const o = document.createElement('option'); o.value = action === 'areas' ? x.area_name : x.street_name; o.textContent = action === 'areas' ? x.area_name : x.street_name; o.dataset.type = x.area_type || ''; el.appendChild(o); });
                    el.disabled = d.length === 0;
                } catch (e) { $(sel).innerHTML = '<option value="">No records available</option>'; console.error(e); }
            }
            window.residentAddressIds = ids;
            window.loadAddressRegions = loadRegions;
            window.loadAddressChildren = loadChildren;
            window.loadAddressLocal = loadLocal;
            window.clearAddressAfter = clearAfter;
            $('f_region')?.addEventListener('change', async function () {
                $('f_region_code').value = this.value; $('f_province_code').value = ''; $('f_municipality_code').value = ''; $('f_barangay_code').value = '';
                clearAfter('p'); if (this.value) await loadChildren('provinces', { reg: this.value }, ids.p, 'Select province');
            });
            $('f_province')?.addEventListener('change', async function () {
                $('f_province_code').value = this.value; $('f_municipality_code').value = ''; $('f_barangay_code').value = ''; clearAfter('m');
                if (this.value) await loadChildren('municipalities', { prv: this.value }, ids.m, 'Select city/municipality');
            });
            $('f_municipality')?.addEventListener('change', async function () {
                $('f_municipality_code').value = this.value; $('f_barangay_code').value = ''; clearAfter('b');
                if (this.value) {
                    await loadChildren('barangays', { prv: $('f_province_code').value, mun: this.value }, ids.b, 'Select barangay');
                    try { const r = await fetch('../backend/address_api.php?action=zip&municipality=' + encodeURIComponent(this.value)); const j = await r.json(); if (j.zip_code) $('f_zip').value = j.zip_code; } catch (e) { }
                }
            });
            $('f_barangay')?.addEventListener('change', async function () {
                $('f_barangay_code').value = this.value;
                const o = this.selectedOptions?.[0]; const d = $('f_barangay_display'); if (d) d.value = o?.textContent || '';
                await loadLocal('streets', { barangay: this.value }, ids.s, 'Select street');
                await loadLocal('areas', { barangay: this.value }, ids.a, 'Select area');
            });
            $('f_region')?.addEventListener('change', function () { const d = $('f_region_display'); if (d) d.value = this.selectedOptions?.[0]?.textContent || ''; });
            $('f_province')?.addEventListener('change', function () { const d = $('f_province_display'); if (d) d.value = this.selectedOptions?.[0]?.textContent || ''; });
            $('f_municipality')?.addEventListener('change', function () { const d = $('f_municipality_display'); if (d) d.value = this.selectedOptions?.[0]?.textContent || ''; });
            $('f_area')?.addEventListener('change', function () {
                const o = this.options[this.selectedIndex]; $('f_area_type').value = o?.dataset.type || '';
                $('f_purok').value = ($('f_area_type').value === 'Purok') ? this.value : '';
            });
            window.initPSGCAddress = async function () { await loadRegions(); };
            window.populatePSGCAddress = async function (data) {
                await loadRegions();
                async function choose(sel, code, nextType, nextParams) {
                    if (!code) return;
                    $(sel).value = code; const codeId = codes[Object.keys(ids).find(k => ids[k] === sel)] || ''; if (codeId && $(codeId)) $(codeId).value = code;
                    $(sel).dispatchEvent(new Event('change')); await new Promise(r => setTimeout(r, 250));
                }
                const r = data.PSGCRegionCode || '', p = data.PSGCProvinceCode || '', m = data.PSGCMunicipalityCode || '', b = data.PSGCBarangayCode || '';
                const selectedText = (id) => $(id)?.selectedOptions?.[0]?.textContent?.trim() || '';
                const setDisplay = (id, value) => { const el = $(id); if (el) el.value = value || ''; };
                if (r) { $('f_region').value = r; $('f_region_code').value = r; await loadChildren('provinces', { reg: r }, ids.p, 'Select province'); }
                if (p) { $('f_province').value = p; $('f_province_code').value = p; await loadChildren('municipalities', { prv: p }, ids.m, 'Select city/municipality'); }
                if (m) { $('f_municipality').value = m; $('f_municipality_code').value = m; await loadChildren('barangays', { prv: p, mun: m }, ids.b, 'Select barangay'); }
                if (b) { $('f_barangay').value = b; $('f_barangay_code').value = b; await loadLocal('streets', { barangay: b }, ids.s, 'Select street'); await loadLocal('areas', { barangay: b }, ids.a, 'Select area'); }
                setDisplay('f_region_display', data.RegionName || selectedText('f_region'));
                setDisplay('f_province_display', data.ProvinceName || selectedText('f_province'));
                setDisplay('f_municipality_display', data.CityMunicipalityName || selectedText('f_municipality'));
                setDisplay('f_barangay_display', data.BarangayName || selectedText('f_barangay'));
                if (data.StreetName && !b) { $('f_street').innerHTML = '<option value="">Legacy street</option>'; let so = document.createElement('option'); so.value = data.StreetName; so.textContent = data.StreetName; so.selected = true; $('f_street').appendChild(so); $('f_street').disabled = false; } else { $('f_street').value = data.StreetName || ''; }
                if ((data.AreaName || data.Purok) && !b) { $('f_area').innerHTML = '<option value="">Legacy area</option>'; let ao = document.createElement('option'); ao.value = data.AreaName || data.Purok; ao.textContent = data.AreaName || data.Purok; ao.dataset.type = data.AreaType || 'Purok'; ao.selected = true; $('f_area').appendChild(ao); $('f_area').disabled = false; } else { $('f_area').value = data.AreaName || data.Purok || ''; }
                $('f_area_type').value = data.AreaType || '';
                $('f_purok').value = data.Purok || ((data.AreaType === 'Purok') ? data.AreaName || '' : '');
                $('f_zip').value = data.ZipCode || '';
            };
            window.buildStructuredAddress = function () {
                return [$('f_house_no')?.value, $('f_building_name')?.value, $('f_street')?.value, $('f_area')?.value,
                $('f_barangay')?.selectedOptions[0]?.textContent, $('f_municipality')?.selectedOptions[0]?.textContent,
                $('f_province')?.selectedOptions[0]?.textContent, $('f_region')?.selectedOptions[0]?.textContent, 'Philippines'].filter(Boolean).join(', ');
            };
            window.recalculateResidentLocation = function () {
                const q = buildStructuredAddress();
                if (!q) return;
                if (!googleMapsReady || !window.google || !google.maps) {
                    showToast('Google Maps is not ready yet.', 'warning');
                    return;
                }
                initMap();
                if (!map) return;
                if (!residentGeocoder) residentGeocoder = new google.maps.Geocoder();
                residentGeocoder.geocode({ address: q, region: 'PH' }, function (results, status) {
                    if (status === 'OK' && results && results[0]) {
                        const loc = results[0].geometry.location;
                        map.setCenter(loc);
                        map.setZoom(18);
                        setMarker(loc.lat(), loc.lng(), true, true);
                        $('location_adjusted_badge')?.classList.add('hidden');
                    } else {
                        showToast('Google could not locate this address. Try selecting a street or picking the exact spot on the map.', 'warning', 6000);
                    }
                });
            };
            let _geoTimer;
            let addressAutofillRun = 0;
            ['f_region', 'f_province', 'f_municipality', 'f_barangay'].forEach(id => $(id)?.addEventListener('change', () => { addressAutofillRun++; clearMapMarker(); $('f_lat').value = ''; $('f_long').value = ''; }));
            ['f_street', 'f_area', 'f_barangay', 'f_municipality'].forEach(id => $(id)?.addEventListener('change', () => { clearTimeout(_geoTimer); _geoTimer = setTimeout(() => window.recalculateResidentLocation(), 700); }));
            document.addEventListener('DOMContentLoaded', () => window.initPSGCAddress());
        })();
    </script>



</body>

</html>
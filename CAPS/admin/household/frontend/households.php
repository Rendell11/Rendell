<?php
declare(strict_types=1);
require_once __DIR__ . '/../backend/households.php';
require_once __DIR__ . '/../../../login/csrf_helper.php';
$csrf = function_exists('csrf_token') ? (string) csrf_token() : (string) ($_SESSION['csrf_token'] ?? '');

$success = isset($_GET['success']);
$error = trim((string) ($_GET['error'] ?? ''));
?>
<!doctype html>
<html <?php echo $theme_attrs['html']; ?>>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Household Management — Barangay Biñang 2nd</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=DM+Mono:wght@400;500&display=swap"
        rel="stylesheet">
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700;1,0,-50..200"
        rel="stylesheet">

    <?php if ($googleKey): ?>
        <script>
            window.initCapsHouseholdMap = function () {
                window.__capsHouseholdMapsReady = true;
                if (typeof window.renderHouseholdMap === 'function') window.renderHouseholdMap();
            };
        </script>
        <script async defer
            src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($googleKey, ENT_QUOTES, 'UTF-8') ?>&callback=initCapsHouseholdMap"></script>
    <?php else: ?>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="" />
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
    <?php endif; ?>

    <?php include __DIR__ . '/../../theme_head.php'; ?>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: { DEFAULT: 'var(--accent-600)', light: 'var(--accent-500)', dark: 'var(--accent-700)' },
                        accent: { DEFAULT: 'var(--accent-500)', light: 'var(--accent-400)' }
                    },
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                        mono: ['"DM Mono"', 'monospace']
                    }
                }
            }
        };
    </script>

    <style>
        :root {
            --sidebar-w: 288px;
            --nav-h: 64px
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--page-bg, #eef2fb);
            -webkit-font-smoothing: antialiased
        }

        .main-wrapper {
            margin-left: var(--sidebar-w);
            width: calc(100% - var(--sidebar-w))
        }

        @media(max-width:1024px) {
            .main-wrapper {
                margin-left: 0;
                width: 100%
            }
        }

        ::-webkit-scrollbar {
            width: 6px;
            height: 6px
        }

        ::-webkit-scrollbar-track {
            background: transparent
        }

        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 99px
        }

        #householdMap {
            height: 430px;
            border-radius: 1.25rem;
            overflow: hidden
        }

        .income-high {
            background: #ecfdf5;
            color: #059669;
            border-color: #a7f3d0
        }

        .income-mid {
            background: #fffbeb;
            color: #d97706;
            border-color: #fde68a
        }

        .income-low {
            background: #fff1f2;
            color: #e11d48;
            border-color: #fecdd3
        }

        html.dark body {
            background: #0f172a;
            color: #e2e8f0
        }

        html.dark .bg-white {
            background: #1e293b !important
        }

        html.dark .bg-slate-50,
        html.dark .bg-slate-50\/50 {
            background: #0f172a !important
        }

        html.dark .bg-slate-100 {
            background: #1e293b !important
        }

        html.dark .text-slate-900 {
            color: #f1f5f9 !important
        }

        html.dark .text-slate-800 {
            color: #e2e8f0 !important
        }

        html.dark .text-slate-700 {
            color: #cbd5e1 !important
        }

        html.dark .text-slate-600 {
            color: #94a3b8 !important
        }

        html.dark .text-slate-500 {
            color: #64748b !important
        }

        html.dark .border-slate-100,
        html.dark .border-slate-200 {
            border-color: #334155 !important
        }

        html.dark .divide-y>*+* {
            border-color: #334155 !important
        }

        html.dark input,
        html.dark select {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important
        }
    </style>
</head>

<body <?php echo $theme_attrs['body']; ?>>
    <div class="flex min-h-screen">
        <?php include __DIR__ . '/../../sidebar.php'; ?>

        <div class="flex-1 flex flex-col min-w-0 main-wrapper">
            <?php include __DIR__ . '/../../header.php'; ?>

            <main class="p-4 md:p-6 lg:p-8 space-y-8">

                <!-- Same Hero Band as Resident Management -->
                <div class="rounded-2xl p-6 md:p-8 text-white relative overflow-hidden"
                    style="background:linear-gradient(135deg,var(--accent-700) 0%,var(--accent-600) 50%,var(--accent-700) 100%);">
                    <div class="absolute -right-12 -top-12 w-64 h-64 opacity-10 rounded-full blur-3xl pointer-events-none"
                        style="background:var(--accent-400);"></div>
                    <div class="absolute left-1/3 bottom-0 w-48 h-48 opacity-10 rounded-full blur-2xl pointer-events-none"
                        style="background:var(--accent-300);"></div>

                    <div class="relative z-10 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div>
                            <h1 class="text-2xl md:text-3xl font-black tracking-tight leading-none">Household Management
                            </h1>
                            <p class="text-white/60 text-sm mt-2 font-medium">
                                Manage and monitor household data for Barangay Biñang 2nd.
                            </p>
                        </div>

                        <div class="flex gap-3 flex-shrink-0">
                            <a href="new_household.php"
                                class="flex items-center gap-2 bg-white/10 hover:bg-white/20 border border-white/20 text-white px-5 py-2.5 rounded-xl font-bold text-xs uppercase tracking-wider transition-all">
                                <span class="material-symbols-outlined text-lg">add_home</span>
                                New Household
                            </a>
                        </div>
                    </div>
                </div>

                <?php if ($success): ?>
                    <div
                        class="rounded-2xl border border-emerald-100 bg-emerald-50 px-5 py-4 text-sm font-bold text-emerald-700">
                        Household saved successfully.
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="rounded-2xl border border-rose-100 bg-rose-50 px-5 py-4 text-sm font-bold text-rose-700">
                        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($headlessHouseholds)): ?>
                    <!-- Households without a Head (their Head became the Head of another household) -->
                    <div class="rounded-2xl border-2 border-amber-200 bg-amber-50 px-6 py-4">
                        <div class="flex items-start gap-3">
                            <span class="material-symbols-outlined text-amber-600">warning</span>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-black text-amber-800"><?= count($headlessHouseholds) ?> household<?= count($headlessHouseholds) === 1 ? ' has' : 's have' ?> no Head and need<?= count($headlessHouseholds) === 1 ? 's' : '' ?> a new Head.</p>
                                <p class="text-xs font-semibold text-amber-700 mt-0.5">Open Edit Household → Change Household Head to assign one. These households were not deleted.</p>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <?php foreach ($headlessHouseholds as $hl): ?>
                                        <a href="edit_household.php?sid=<?= (int) $hl['SurveyID'] ?>"
                                            class="inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-white border border-amber-200 text-[11px] font-black text-amber-800 hover:bg-amber-100">
                                            <span class="font-mono"><?= htmlspecialchars((string) $hl['HouseholdID'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="font-semibold text-amber-700"><?= (int) $hl['MemberCount'] ?> member<?= (int) $hl['MemberCount'] === 1 ? '' : 's' ?></span>
                                            <span class="material-symbols-outlined text-sm">edit</span> Assign Head
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Search / Filter — same visual proportions as Resident Management -->
                <form method="GET" action="" id="filterForm" class="grid grid-cols-12 gap-4">
                    <div class="col-span-12 md:col-span-6 relative">
                        <span
                            class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-xl">search</span>
                        <input type="text" name="search" id="searchInput"
                            value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="Search by head or member name, contact, street, area, household ID..."
                            class="w-full pl-11 pr-4 py-2.5 bg-white border border-slate-200 rounded-2xl text-sm focus:outline-none focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all shadow-sm">
                    </div>
                    <div class="col-span-12 md:col-span-2">
                        <select name="income_class" id="incomeFilter"
                            class="w-full border-slate-200 rounded-2xl py-2.5 text-sm font-bold text-slate-600 focus:ring-indigo-500 transition-all shadow-sm">
                            <option value="">All Income</option>
                            <option value="low" <?= $incomeClass === 'low' ? 'selected' : '' ?>>Low Income</option>
                            <option value="mid" <?= $incomeClass === 'mid' ? 'selected' : '' ?>>Mid Income</option>
                            <option value="high" <?= $incomeClass === 'high' ? 'selected' : '' ?>>High Income</option>
                        </select>
                    </div>
                    <div class="col-span-12 md:col-span-2">
                        <select name="status" id="statusFilter"
                            class="w-full border-slate-200 rounded-2xl py-2.5 text-sm font-bold text-slate-600 focus:ring-indigo-500 transition-all shadow-sm">
                            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-span-12 md:col-span-2">
                        <button type="submit"
                            class="w-full px-5 py-2.5 bg-primary text-white rounded-2xl font-bold text-xs uppercase tracking-wider hover:opacity-90 transition-all shadow-md">
                            Search
                        </button>
                    </div>
                </form>

                <!-- Stats Cards — same Resident Management card styling -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                    <div class="bg-white p-6 rounded-[32px] border border-slate-100 shadow-sm">
                        <div
                            class="w-10 h-10 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center mb-4">
                            <span class="material-symbols-outlined">home</span>
                        </div>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Households</p>
                        <h3 class="text-2xl font-bold text-slate-800 mt-1"><?= number_format((int) $totalHH) ?></h3>
                    </div>

                    <div class="bg-white p-6 rounded-[32px] border border-slate-100 shadow-sm">
                        <div
                            class="w-10 h-10 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center mb-4">
                            <span class="material-symbols-outlined">location_on</span>
                        </div>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Mapped</p>
                        <h3 class="text-2xl font-bold text-slate-800 mt-1"><?= number_format((int) $mapped) ?></h3>
                    </div>

                    <div class="bg-white p-6 rounded-[32px] border border-slate-100 shadow-sm">
                        <div
                            class="w-10 h-10 bg-rose-50 text-rose-600 rounded-xl flex items-center justify-center mb-4">
                            <span class="material-symbols-outlined">payments</span>
                        </div>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Low Income</p>
                        <h3 class="text-2xl font-bold text-slate-800 mt-1"><?= number_format((int) $low) ?></h3>
                    </div>

                    <div class="bg-white p-6 rounded-[32px] border border-slate-100 shadow-sm">
                        <div class="w-10 h-10 bg-sky-50 text-sky-600 rounded-xl flex items-center justify-center mb-4">
                            <span class="material-symbols-outlined">group</span>
                        </div>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Avg. Members</p>
                        <h3 class="text-2xl font-bold text-slate-800 mt-1">
                            <?= htmlspecialchars((string) ($avg ?? '0'), ENT_QUOTES, 'UTF-8') ?></h3>
                    </div>
                </div>

                <!-- Map -->
                <div class="bg-white rounded-[32px] shadow-sm border border-slate-100 overflow-hidden">
                    <div
                        class="px-8 py-5 border-b border-slate-50 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <h3 class="font-bold text-slate-700">Household Locations</h3>
                            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-0.5">
                                Uses the latitude/longitude already stored by the Resident Module.
                            </p>
                        </div>
                        <span
                            class="px-3 py-1 bg-indigo-50 text-indigo-700 text-[10px] font-black rounded-full uppercase border border-indigo-100">
                            <?= number_format((int) $mapped) ?> Mapped
                        </span>
                    </div>
                    <div class="p-4">
                        <div id="householdMap">
                            <div class="h-full flex items-center justify-center text-sm font-semibold text-slate-400">
                                Loading Google Maps...
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Household Table -->
                <div class="bg-white rounded-[32px] shadow-sm border border-slate-100 overflow-hidden">
                    <div
                        class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 px-8 py-5 border-b border-slate-50">
                        <div>
                            <h2 class="text-sm font-black text-slate-800 uppercase tracking-tight">Household Master List
                            </h2>
                            <p class="text-[10px] text-slate-400 font-bold mt-0.5">
                                Household records derived from CAPS Resident relationships.
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <a href="../backend/household_generate_report.php?print=1&status=<?= urlencode($statusFilter) ?>&search=<?= urlencode($search) ?>&income_class=<?= urlencode($incomeClass) ?>"
                                target="_blank"
                                class="flex items-center gap-2 px-4 py-2 rounded-xl border border-slate-200 text-[10px] font-black uppercase text-slate-500 hover:border-indigo-300 hover:text-indigo-600 transition-all">
                                <span class="material-symbols-outlined text-sm">print</span> Print
                            </a>
                            <a href="../backend/household_generate_report.php?print=1&status=<?= urlencode($statusFilter) ?>&search=<?= urlencode($search) ?>&income_class=<?= urlencode($incomeClass) ?>"
                                target="_blank"
                                class="flex items-center gap-2 px-4 py-2 rounded-xl border border-slate-200 text-[10px] font-black uppercase text-slate-500 hover:border-indigo-300 hover:text-indigo-600 transition-all">
                                <span class="material-symbols-outlined text-sm">picture_as_pdf</span> Save as PDF
                            </a>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse" id="householdTable">
                            <thead>
                                <tr
                                    class="bg-slate-50/50 text-[10px] font-bold text-slate-400 uppercase tracking-widest border-b border-slate-50">
                                    <th class="px-8 py-5">Household ID</th>
                                    <th class="px-6 py-5">Household Head</th>
                                    <th class="px-6 py-5">Address</th>
                                    <th class="px-6 py-5">Members</th>
                                    <th class="px-6 py-5" title="Sum of the recorded monthly income of the Head and all household members">Combined Income</th>
                                    <th class="px-8 py-5 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50 text-sm">
                                <?php if (!$households): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-16 text-slate-400">
                                            <span
                                                class="material-symbols-outlined text-4xl block mb-2 text-slate-200">manage_search</span>
                                            No household records found.
                                        </td>
                                    </tr>
                                <?php else:
                                    foreach ($households as $h):
                                        $name = trim(implode(' ', array_filter([
                                            $h['FirstName'] ?? '',
                                            $h['MiddleName'] ?? '',
                                            $h['LastName'] ?? '',
                                            $h['Suffix'] ?? ''
                                        ])));
                                        $addr = implode(', ', array_filter([
                                            $h['HouseNumber'] ?? '',
                                            $h['BuildingName'] ?? '',
                                            $h['StreetName'] ?? '',
                                            $h['AreaName'] ?? '',
                                            $h['Purok'] ?? '',
                                            $h['BarangayName'] ?? ''
                                        ]));
                                        $isInactiveRow = strtolower((string) ($h['status'] ?? 'active')) === 'inactive';
                                        if ($isInactiveRow) {
                                            // Address/head as recorded on the inactive household.
                                            $addr = (string) ($h['HouseholdAddress'] ?? $addr);
                                            if ($name === '') $name = (string) ($h['head_name'] ?? '');
                                        }
                                        $viewUrl = !empty($h['SurveyID'])
                                            ? 'view_household.php?sid=' . (int) $h['SurveyID']
                                            : 'view_household.php?id=' . (int) $h['ResidentID'];
                                        // Combined Income = Head + all living members' recorded income.
                                        $inc = (float) ($h['CombinedIncome'] ?? $h['TotalHouseholdIncome'] ?? 0);
                                        if ($inc >= 50000) {
                                            $badge = 'income-high';
                                        } elseif ($inc >= 20000) {
                                            $badge = 'income-mid';
                                        } else {
                                            $badge = 'income-low';
                                        }
                                        // Same Income Status as View Household (hh_income_class on the combined income).
                                        $incLabel = hh_income_class($inc);
                                        $initials = strtoupper(
                                            substr((string) ($h['FirstName'] ?? ''), 0, 1) .
                                            substr((string) ($h['LastName'] ?? ''), 0, 1)
                                        );
                                        ?>
                                        <tr class="hover:bg-slate-50/50 transition-colors group">
                                            <td class="px-8 py-5">
                                                <p class="text-[11px] font-black text-indigo-600 uppercase tracking-tight">
                                                    <?= htmlspecialchars((string) $h['HouseholdID'], ENT_QUOTES, 'UTF-8') ?>
                                                </p>
                                                <p class="text-[9px] text-slate-400 font-bold mt-0.5">
                                                    Resident #<?= (int) $h['ResidentID'] ?>
                                                </p>
                                                <?php if ($isInactiveRow): ?>
                                                    <p class="text-[9px] text-rose-500 font-black uppercase mt-0.5">
                                                        Inactive<?= !empty($h['inactive_since']) ? ' since ' . htmlspecialchars(date('M j, Y', strtotime((string) $h['inactive_since'])), ENT_QUOTES, 'UTF-8') : '' ?>
                                                    </p>
                                                <?php endif; ?>
                                            </td>

                                            <td class="px-6 py-5">
                                                <div class="flex items-center gap-3">
                                                    <div
                                                        class="w-9 h-9 bg-slate-100 text-slate-500 rounded-xl flex items-center justify-center font-bold text-[10px] shrink-0">
                                                        <?= htmlspecialchars($initials ?: 'HH', ENT_QUOTES, 'UTF-8') ?>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm font-bold text-slate-700 leading-tight">
                                                            <?= htmlspecialchars($name ?: 'Unknown Head', ENT_QUOTES, 'UTF-8') ?>
                                                        </p>
                                                        <p
                                                            class="text-[10px] text-slate-400 font-bold uppercase tracking-tighter mt-0.5">
                                                            <?= htmlspecialchars((string) ($h['Sex'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                                                            ·
                                                            <?= !empty($h['ContactNumber']) ? htmlspecialchars((string) $h['ContactNumber'], ENT_QUOTES, 'UTF-8') : 'No contact' ?>
                                                        </p>
                                                        <?php if (!empty($h['MatchedMembers'])): ?>
                                                            <p class="text-[10px] text-indigo-500 font-bold mt-1">
                                                                Matched member: <?= htmlspecialchars((string) $h['MatchedMembers'], ENT_QUOTES, 'UTF-8') ?>
                                                            </p>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>

                                            <td class="px-6 py-5">
                                                <div class="text-xs font-semibold text-slate-700 max-w-[360px]">
                                                    <?= htmlspecialchars($addr ?: 'No address', ENT_QUOTES, 'UTF-8') ?>
                                                </div>
                                            </td>

                                            <td class="px-6 py-5">
                                                <span
                                                    class="px-2.5 py-0.5 bg-indigo-50 text-indigo-700 text-[10px] font-black rounded-full">
                                                    <?= ((int) ($h['MemberCount'] ?? 0)) + 1 ?>
                                                </span>
                                            </td>

                                            <td class="px-6 py-5">
                                                <span
                                                    class="income-badge px-3 py-1 rounded-lg text-[10px] font-bold uppercase border <?= $badge ?>">
                                                    ₱<?= number_format($inc, 2) ?>
                                                </span>
                                                <p class="text-[9px] font-black uppercase tracking-wider text-slate-400 mt-1.5">
                                                    <?= htmlspecialchars($incLabel, ENT_QUOTES, 'UTF-8') ?></p>
                                            </td>

                                            <td class="px-8 py-5 text-right">
                                                <div class="flex justify-end">
                                                    <a href="<?= htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') ?>"
                                                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider text-indigo-600 bg-indigo-50 hover:bg-indigo-100 transition-all"
                                                        title="View Household">
                                                        <span class="material-symbols-outlined text-base">visibility</span>
                                                        View
                                                    </a>
                                                    <?php if (!$isInactiveRow && empty($h['is_removed']) && !empty($h['SurveyID'])): ?>
                                                        <button type="button"
                                                            onclick="openRemoveHousehold(<?= (int) $h['SurveyID'] ?>, <?= (int) $h['ResidentID'] ?>, <?= htmlspecialchars(json_encode($name), ENT_QUOTES, 'UTF-8') ?>)"
                                                            class="inline-flex items-center justify-center w-9 h-9 rounded-xl text-rose-600 bg-rose-50 hover:bg-rose-100 transition-all ml-1"
                                                            title="Remove / Deactivate Household">
                                                            <span class="material-symbols-outlined text-base">delete</span>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <div
                            class="flex flex-col sm:flex-row items-center justify-between gap-3 px-8 py-4 border-t border-slate-50">
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                                Page <strong class="text-slate-600"><?= (int) $page ?></strong>
                                of <strong class="text-slate-600"><?= (int) $totalPages ?></strong>
                            </p>
                            <nav class="flex items-center gap-1">
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&income_class=<?= urlencode($incomeClass) ?>&status=<?= urlencode($statusFilter) ?>"
                                        class="flex items-center gap-1 px-3 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-wider text-slate-500 bg-white border border-slate-200 hover:border-indigo-300 hover:text-indigo-600">
                                        <span class="material-symbols-outlined text-sm">chevron_left</span> Prev
                                    </a>
                                <?php endif; ?>

                                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                    <a href="?page=<?= $p ?>&search=<?= urlencode($search) ?>&income_class=<?= urlencode($incomeClass) ?>&status=<?= urlencode($statusFilter) ?>"
                                        class="px-3 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all <?= $p === $page ? 'bg-primary text-white shadow-md' : 'text-slate-500 bg-white border border-slate-200 hover:border-indigo-300 hover:text-indigo-600' ?>">
                                        <?= $p ?>
                                    </a>
                                <?php endfor; ?>

                                <?php if ($page < $totalPages): ?>
                                    <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&income_class=<?= urlencode($incomeClass) ?>&status=<?= urlencode($statusFilter) ?>"
                                        class="flex items-center gap-1 px-3 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-wider text-slate-500 bg-white border border-slate-200 hover:border-indigo-300 hover:text-indigo-600">
                                        Next <span class="material-symbols-outlined text-sm">chevron_right</span>
                                    </a>
                                <?php endif; ?>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>

            </main>
        </div>
    </div>

    <!-- Household Details Modal -->
    <div id="modal" class="fixed inset-0 hidden items-center justify-center p-4 bg-slate-900/80 z-[9999]">
        <div class="bg-white rounded-[2.5rem] shadow-2xl w-full max-w-5xl max-h-[90vh] overflow-auto p-8 md:p-10">
            <div class="flex justify-between gap-4 border-b border-slate-100 pb-5">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Household Details</p>
                    <h2 id="mTitle" class="text-2xl font-black text-slate-900 tracking-tight mt-1">Loading...</h2>
                </div>
                <button type="button" onclick="closeModal()"
                    class="p-2 hover:bg-slate-100 rounded-full text-slate-400 transition-colors">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div id="mBody" class="mt-6"></div>
        </div>
    </div>

    <script>
        window.__capsHouseholdMapsReady = false;
        let hmap = null, bounds = null;
        const markerRefs = [];

        async function loadLocations() {
            const r = await fetch('../backend/households.php?action=locations', { headers: { 'Accept': 'application/json' } });
            if (!r.ok) throw new Error('Unable to load household locations.');
            return await r.json();
        }

        window.renderHouseholdMap = async function () {
            const el = document.getElementById('householdMap');
            if (!el) return;
            try {
                const data = await loadLocations();
                const points = (data || []).map(x => ({ x, lat: Number(x.Latitude), lng: Number(x.Longitude) }))
                    .filter(p => Number.isFinite(p.lat) && Number.isFinite(p.lng) && p.lat !== 0 && p.lng !== 0);

                <?php if ($googleKey): ?>
                    if (!window.google || !google.maps) {
                        el.innerHTML = '<div class="h-full flex items-center justify-center text-sm font-semibold text-rose-400">Google Maps failed to load. Check the Google Maps API key.</div>';
                        return;
                    }
                    hmap = new google.maps.Map(el, { center: { lat: 14.7935, lng: 120.9247 }, zoom: 13, mapTypeControl: true, mapTypeControlOptions: { mapTypeIds: ['roadmap', 'satellite'] }, streetViewControl: false, fullscreenControl: true });
                    bounds = new google.maps.LatLngBounds();
                    points.forEach(function (p) {
                        const marker = new google.maps.Marker({ map: hmap, position: { lat: p.lat, lng: p.lng }, title: (p.x.FirstName || '') + ' ' + (p.x.LastName || '') });
                        marker.addListener('click', function () { viewHousehold(Number(p.x.ResidentID)); });
                        markerRefs.push(marker); bounds.extend({ lat: p.lat, lng: p.lng });
                    });
                    if (points.length) hmap.fitBounds(bounds);
                    else hmap.setZoom(13);
                <?php else: ?>
                    if (!window.L) {
                        el.innerHTML = '<div class="h-full flex items-center justify-center text-sm font-semibold text-rose-400">Map library failed to load.</div>';
                        return;
                    }
                    if (hmap) hmap.remove();
                    hmap = L.map(el, { zoomControl: true });
                    const street = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(hmap);
                    const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, attribution: 'Tiles © Esri' });
                    L.control.layers({ 'Map View': street, 'Satellite View': satellite }, null, { position: 'topright' }).addTo(hmap);
                    if (points.length) {
                        const group = L.featureGroup();
                        points.forEach(function (p) { L.marker([p.lat, p.lng]).bindTooltip((p.x.FirstName || '') + ' ' + (p.x.LastName || '')).on('click', function () { viewHousehold(Number(p.x.ResidentID)); }).addTo(group); });
                        group.addTo(hmap); hmap.fitBounds(group.getBounds().pad(0.15));
                    } else { hmap.setView([14.7935, 120.9247], 13); }
                <?php endif; ?>
            } catch (e) {
                console.error(e);
                el.innerHTML = '<div class="h-full flex flex-col items-center justify-center text-sm font-semibold text-rose-400 gap-1"><span>Unable to load household map.</span><small>' + String(e.message || e) + '</small></div>';
            }
        };

        async function viewHousehold(id) {
            const modal = document.getElementById('modal');
            const title = document.getElementById('mTitle');
            const body = document.getElementById('mBody');

            modal.classList.remove('hidden');
            modal.classList.add('flex');
            body.innerHTML = '<div class="py-12 text-center text-slate-400">Loading...</div>';

            try {
                const r = await fetch('../backend/fetch_family.php?head_id=' + encodeURIComponent(id));
                const d = await r.json();

                if (!d.success) {
                    body.innerHTML = '<div class="text-rose-600 font-bold">Unable to load household.</div>';
                    return;
                }

                title.textContent = (d.head.FirstName || '') + ' ' + (d.head.LastName || '');
                const members = [d.head].concat(d.members || []);

                body.innerHTML = `
            <div class="grid md:grid-cols-4 gap-3">
                ${stat('Household ID', d.household_id)}
                ${stat('Members', members.length)}
                ${stat('Combined Income', '₱' + members.reduce((s, m) => s + Number(m.TotalHouseholdIncome || 0), 0).toLocaleString('en-PH', { minimumFractionDigits: 2 }))}
                ${stat('GPS', (d.head.Latitude && d.head.Longitude) ? Number(d.head.Latitude).toFixed(6) + ', ' + Number(d.head.Longitude).toFixed(6) : 'Not set')}
            </div>

            <div class="mt-6 grid md:grid-cols-2 gap-6">
                <div>
                    <h3 class="font-black text-slate-800 mb-2">Address</h3>
                    <p class="text-sm text-slate-500">${esc([
                    d.head.HouseNumber, d.head.BuildingName, d.head.StreetName, d.head.AreaName,
                    d.head.Purok, d.head.BarangayName, d.head.CityMunicipalityName,
                    d.head.ProvinceName, d.head.RegionName, d.head.ZipCode
                ].filter(Boolean).join(', ') || 'No address')}</p>
                </div>
                <div>
                    <h3 class="font-black text-slate-800 mb-2">Household Head</h3>
                    <p class="text-sm text-slate-500">${esc(d.head.Sex || '—')} · ${esc(d.head.CivilStatus || '—')} · ${esc(d.head.ContactNumber || '—')}</p>
                </div>
            </div>

            <div class="mt-7 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[10px] uppercase tracking-widest text-slate-400 border-b border-slate-100">
                            <th class="p-3">Resident</th>
                            <th class="p-3">Relationship</th>
                            <th class="p-3">Sex</th>
                            <th class="p-3">Age</th>
                            <th class="p-3">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${members.map(function (x, i) {
                    return `
                            <tr class="border-t border-slate-100">
                                <td class="p-3 font-bold text-slate-700">${esc([x.FirstName, x.MiddleName, x.LastName, x.Suffix].filter(Boolean).join(' '))}</td>
                                <td class="p-3 text-slate-600">${i === 0 ? 'Head' : esc(x.RelationshipToHead || 'Member')}</td>
                                <td class="p-3 text-slate-600">${esc(x.Sex || '—')}</td>
                                <td class="p-3 text-slate-600">${x.BirthDate ? age(x.BirthDate) : '—'}</td>
                                <td class="p-3 text-slate-600">${x.IsPWD ? 'PWD ' : ''}${x.IsSenior ? 'Senior' : ''}</td>
                            </tr>
                        `}).join('')}
                    </tbody>
                </table>
            </div>

            <div class="mt-6 flex justify-end">
                <a class="px-5 py-3 rounded-xl bg-primary text-white text-xs font-black uppercase tracking-wider shadow-md hover:opacity-90"
                   href="edit_household.php?id=${encodeURIComponent(id)}">Manage Members</a>
            </div>`;
            } catch (e) {
                console.error(e);
                body.innerHTML = '<div class="text-rose-600 font-bold">Unable to load household.</div>';
            }
        }

        function stat(label, value) {
            return `<div class="p-4 rounded-2xl bg-slate-50 border border-slate-100">
        <div class="text-[10px] font-black uppercase tracking-widest text-slate-400">${esc(label)}</div>
        <div class="font-black text-slate-800 mt-1">${esc(String(value ?? '—'))}</div>
    </div>`;
        }
        function age(value) {
            const birth = new Date(value);
            if (Number.isNaN(birth.getTime())) return '—';
            return Math.floor((Date.now() - birth.getTime()) / 31557600000);
        }
        function esc(value) {
            const d = document.createElement('div');
            d.textContent = value ?? '';
            return d.innerHTML;
        }
        function closeModal() {
            const modal = document.getElementById('modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
        document.addEventListener('DOMContentLoaded', function () {
            if (window.__capsHouseholdMapsReady) window.renderHouseholdMap();
        });
    </script>

    <div id="removeHouseholdModal"
        class="hidden fixed inset-0 z-[100] bg-slate-950/50 backdrop-blur-sm items-center justify-center p-4">
        <div class="w-full max-w-lg rounded-3xl bg-white shadow-2xl overflow-hidden">
            <div class="p-6 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <h3 class="font-black text-slate-800">Remove Household</h3>
                    <p id="removeHouseholdName" class="text-xs text-slate-400 mt-1"></p>
                </div><button onclick="closeRemoveHousehold()"
                    class="w-9 h-9 rounded-xl bg-slate-100 text-slate-500">×</button>
            </div>
            <div class="p-6 space-y-4">
                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400">Reason</label>
                <select id="removeReason" class="w-full rounded-xl border-slate-200 text-sm font-semibold">
                    <option value="">Select Reason</option>
                    <option>Household relocated (within barangay)</option>
                    <option>Household relocated (other barangay)</option>
                    <option>Household dissolved</option>
                    <option>Duplicate household</option>
                    <option>Other</option>
                </select>
                <input id="removeReasonOther" class="hidden w-full rounded-xl border-slate-200 text-sm"
                    placeholder="Specify reason">
                <div id="removeConfirmStep"
                    class="hidden rounded-2xl bg-rose-50 border border-rose-100 p-4 text-sm text-rose-700 font-semibold">
                    Are you sure you want to remove this household? Resident profiles will NOT be deleted.</div>
                <div id="relocateStep" class="hidden space-y-3">
                    <div class="rounded-2xl bg-indigo-50 border border-indigo-100 p-4 text-xs text-indigo-700 font-semibold">
                        Set New Household Address. The head and all members move to this address under a new Household ID. The current household becomes Inactive and stays in the records.</div>
                    <div class="grid grid-cols-2 gap-3 text-xs">
                        <input id="relHouseNumber" class="rounded-xl border-slate-200" placeholder="House Number">
                        <input id="relBuildingName" class="rounded-xl border-slate-200" placeholder="Building Name">
                        <input id="relStreetName" class="col-span-2 rounded-xl border-slate-200" placeholder="Street">
                        <input id="relAreaName" class="col-span-2 rounded-xl border-slate-200" placeholder="Subdivision / Sitio / Purok">
                        <input id="relBarangayName" class="rounded-xl border-slate-200" placeholder="Barangay">
                        <input id="relCityMunicipalityName" class="rounded-xl border-slate-200" placeholder="City / Municipality">
                        <input id="relProvinceName" class="rounded-xl border-slate-200" placeholder="Province">
                        <input id="relRegionName" class="rounded-xl border-slate-200" placeholder="Region">
                        <input id="relZipCode" class="rounded-xl border-slate-200" placeholder="ZIP Code">
                        <div></div>
                        <input id="relLatitude" class="rounded-xl border-slate-200" placeholder="Latitude (optional)">
                        <input id="relLongitude" class="rounded-xl border-slate-200" placeholder="Longitude (optional)">
                    </div>
                </div>
                <div class="flex justify-end gap-2"><button onclick="closeRemoveHousehold()"
                        class="px-4 py-3 rounded-xl bg-slate-100 text-slate-600 text-xs font-black">Cancel</button><button
                        onclick="continueRemoveHousehold()"
                        class="px-5 py-3 rounded-xl bg-rose-600 text-white text-xs font-black">Continue</button></div>
            </div>
        </div>
    </div>
    <script>
        let removeSurveyId = 0, removeResidentId = 0;
        function openRemoveHousehold(sid, rid, name) { removeSurveyId = sid; removeResidentId = rid; document.getElementById('removeHouseholdName').textContent = name; document.getElementById('removeHouseholdModal').classList.remove('hidden'); document.getElementById('removeHouseholdModal').classList.add('flex'); }
        function closeRemoveHousehold() { document.getElementById('removeHouseholdModal').classList.add('hidden'); document.getElementById('removeHouseholdModal').classList.remove('flex'); }
        document.getElementById('removeReason')?.addEventListener('change', e => document.getElementById('removeReasonOther').classList.toggle('hidden', e.target.value !== 'Other'));
        const REL_FIELDS = ['HouseNumber', 'BuildingName', 'StreetName', 'AreaName', 'BarangayName', 'CityMunicipalityName', 'ProvinceName', 'RegionName', 'ZipCode', 'Latitude', 'Longitude'];
        let removeStep = 1;
        function resetRemoveSteps() { removeStep = 1; document.getElementById('removeConfirmStep').classList.add('hidden'); document.getElementById('relocateStep').classList.add('hidden'); document.getElementById('removeReason').disabled = false; }
        const _openRemoveHousehold = openRemoveHousehold;
        openRemoveHousehold = function (sid, rid, name) { resetRemoveSteps(); document.getElementById('removeReason').value = ''; document.getElementById('removeReasonOther').value = ''; document.getElementById('removeReasonOther').classList.add('hidden'); _openRemoveHousehold(sid, rid, name); };
        async function prefillRelocation() {
            REL_FIELDS.forEach(f => { document.getElementById('rel' + f).value = ''; });
            try {
                const r = await fetch('../backend/fetch_family.php?head_id=' + encodeURIComponent(removeResidentId), { headers: { 'Accept': 'application/json' } });
                const j = await r.json();
                const h = (j && j.head) || {};
                // Relocation within the barangay keeps barangay/city/province/region/ZIP.
                ['BarangayName', 'CityMunicipalityName', 'ProvinceName', 'RegionName', 'ZipCode'].forEach(f => { document.getElementById('rel' + f).value = h[f] || ''; });
                if (document.getElementById('removeReason').value === 'Household relocated (other barangay)') ['BarangayName', 'ZipCode'].forEach(f => { document.getElementById('rel' + f).value = ''; });
            } catch (e) { console.error(e); }
        }
        async function continueRemoveHousehold() {
            const reason = document.getElementById('removeReason').value; const other = document.getElementById('removeReasonOther').value.trim();
            if (!reason) return alert('Select a reason first.'); if (reason === 'Other' && !other) return alert('Specify the reason.');
            const relocate = reason.startsWith('Household relocated');
            if (removeStep === 1) {
                // Step 2: confirmation
                removeStep = 2; document.getElementById('removeReason').disabled = true;
                document.getElementById('removeConfirmStep').classList.remove('hidden');
                return;
            }
            if (removeStep === 2 && relocate) {
                // Step 3: new household address
                removeStep = 3; document.getElementById('removeConfirmStep').classList.add('hidden');
                document.getElementById('relocateStep').classList.remove('hidden');
                await prefillRelocation();
                return;
            }
            const fd = new FormData(); fd.append('csrf_token', <?= json_encode($csrf) ?>); fd.append('action', 'remove_household'); fd.append('survey_id', removeSurveyId); fd.append('reason', reason); fd.append('reason_other', other);
            if (relocate) {
                if (!document.getElementById('relHouseNumber').value.trim() && !document.getElementById('relStreetName').value.trim()) return alert('Enter the new household address.');
                fd.append('relocate_now', '1');
                REL_FIELDS.forEach(f => fd.append(f, document.getElementById('rel' + f).value.trim()));
            }
            try {
                const r = await fetch('../backend/household_actions.php', { method: 'POST', body: fd }); const j = await r.json();
                if (!j.success) throw new Error(j.error || 'Unable to remove household.');
                if (j.relocated && j.relocated.household_id) { alert('Household relocated. New Household ID: ' + j.relocated.household_id + '. The previous household is now Inactive.'); location.href = 'view_household.php?sid=' + encodeURIComponent(j.relocated.survey_id); return; }
                location.reload();
            } catch (e) { alert(e.message); }
        }
    </script>
</body>

</html>
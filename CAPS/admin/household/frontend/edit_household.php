<?php
declare(strict_types=1);

/* Household edit diagnostics: never leave a blank HTTP 500 on this page. */
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><html><head><meta charset=\"utf-8\"><title>CAPS Household Error</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#f8fafc;padding:32px;color:#0f172a}.box{max-width:1100px;margin:auto;background:#fff;border:1px solid #fecaca;border-radius:16px;padding:24px;box-shadow:0 8px 30px rgba(15,23,42,.08)}h1{font-size:22px;margin:0 0 18px;color:#b91c1c}pre{white-space:pre-wrap;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px;overflow:auto;font-size:12px}.label{font-weight:700;margin-top:14px}</style></head><body><div class=\"box\"><h1>Household Edit Error (HTTP 500)</h1>';
    echo '<div class=\"label\">Message</div><pre>' . htmlspecialchars((string) $e['message'], ENT_QUOTES, 'UTF-8') . '</pre>';
    echo '<div class=\"label\">File</div><pre>' . htmlspecialchars((string) $e['file'], ENT_QUOTES, 'UTF-8') . '</pre>';
    echo '<div class=\"label\">Line</div><pre>' . (int) $e['line'] . '</pre>';
    echo '<div class=\"label\">Trace</div><pre>Fatal error occurred before PHP could render the normal page. Check the file and line above.</pre>';
    echo '</div></body></html>';
});

require_once __DIR__ . '/../backend/households.php';
require_once __DIR__ . '/../backend/household_common.php';
require_permission($pdo, 'households', 'update');

/*
 * ?id=  Head ResidentID (master list, view page).  ?phid= legacy link.
 * ?sid= household SurveyID — also opens a household that has no Head
 *       (its Head moved to another household), so a new Head can be assigned.
 */
$id = (int) ($_GET['id'] ?? 0);
$phid = (int) ($_GET['phid'] ?? 0);
$sidParam = (int) ($_GET['sid'] ?? 0);

if ($id <= 0 && $phid > 0) {
    $resolve = $pdo->prepare("SELECT ResidentID FROM household_survey WHERE SurveyID=? LIMIT 1");
    $resolve->execute([$phid]);
    $resolved = $resolve->fetchColumn();
    if (!$resolved) {
        $resolve = $pdo->prepare("SELECT ResidentID FROM residents WHERE ResidentID=? AND IsHead=1 LIMIT 1");
        $resolve->execute([$phid]);
        $resolved = $resolve->fetchColumn();
    }
    $id = (int) ($resolved ?: 0);
}

$head = null;
$survey = null;
if ($sidParam > 0) {
    $st = $pdo->prepare("SELECT * FROM household_survey WHERE SurveyID=? LIMIT 1");
    $st->execute([$sidParam]);
    $survey = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($survey && !empty($survey['ResidentID'])) {
        $st = $pdo->prepare("SELECT * FROM residents WHERE ResidentID=? AND IsHead=1 AND (IsDeceased=0 OR IsDeceased IS NULL) LIMIT 1");
        $st->execute([(int) $survey['ResidentID']]);
        $head = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} elseif ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM residents WHERE ResidentID=? AND IsHead=1 LIMIT 1");
    $stmt->execute([$id]);
    $head = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($head) {
        $sidFound = hh_ensure_household_record($pdo, $id);
        $st = $pdo->prepare("SELECT * FROM household_survey WHERE SurveyID=? LIMIT 1");
        $st->execute([$sidFound]);
        $survey = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!$survey || (!$head && strtolower((string) ($survey['status'] ?? 'active')) !== 'active')) {
    header('Location: households.php?error=Household+not+found');
    exit;
}
if (strtolower((string) ($survey['status'] ?? 'active')) !== 'active' || (int) ($survey['is_removed'] ?? 0) === 1) {
    header('Location: view_household.php?sid=' . (int) $survey['SurveyID']);
    exit;
}

$isHeadless = $head === null;
$id = $head ? (int) $head['ResidentID'] : 0;
$surveyId = (int) $survey['SurveyID'];

/* Current household members (headless: the members still listed on this household). */
if ($head) {
    $memberStmt = $pdo->prepare("
        SELECT *
        FROM residents
        WHERE FamilyHeadID = ?
          AND ResidentID <> ?
          AND COALESCE(IsHead,0) = 0
          AND (IsDeceased = 0 OR IsDeceased IS NULL)
        ORDER BY LastName, FirstName, ResidentID
    ");
    $memberStmt->execute([$id, $id]);
    $currentMembers = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $currentMembers = hh_headless_members($pdo, $surveyId, !empty($survey['ResidentID']) ? (int) $survey['ResidentID'] : null);
}

/* CSRF: use CAPS helper when available, otherwise provide a safe fallback. */
if (file_exists(__DIR__ . '/../../../login/csrf_helper.php')) {
    require_once __DIR__ . '/../../../login/csrf_helper.php';
}
if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf_token'];
    }
}
$csrf = csrf_token();

/* Same read-only details as View Household (shared partial). */
$hh = array_merge($survey, $head ?: []);
$hh['SurveyID'] = $surveyId;
$headId = $id;
$name = $head ? hh_full_name($head) : '';
$householdId = hh_normalize_household_id((string) ($survey['HouseholdID'] ?? ''), (int) date('Y'), $surveyId);
$hh['HouseholdID'] = $householdId;
$addressSource = $head ?: ($currentMembers[0] ?? []);
$address = hh_address($addressSource) ?: (string) ($survey['address'] ?? '');
$age = '—';
if ($head && !empty($head['BirthDate'])) {
    try { $age = (new DateTime($head['BirthDate']))->diff(new DateTime())->y; } catch (Throwable $e) { $age = '—'; }
}
$members = $currentMembers;
$status = 'active';
$headIncome = (float) ($head['TotalHouseholdIncome'] ?? 0);
$memberCount = count($members) + ($head ? 1 : 0);
$income = hh_combined_income($head ?: [], $members);
$classification = hh_income_class($income);
$ses = hh_socioeconomic_status($income, max(1, $memberCount));
$withIncome = ($headIncome > 0 ? 1 : 0) + count(array_filter($members, static fn($m) => (float) ($m['TotalHouseholdIncome'] ?? 0) > 0));
$hhLocation = hh_household_location($head ?: [], $members);
$readOnlyNote = 'Head details come from Resident Profiling and cannot be edited here.';

if (!function_exists('hh_view_escape')) {
    function hh_view_escape($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function member_name(array $row): string
{
    return trim(implode(' ', array_filter([
        $row['FirstName'] ?? '',
        $row['MiddleName'] ?? '',
        $row['LastName'] ?? '',
        $row['Suffix'] ?? ''
    ])));
}

function member_address(array $row): string
{
    return implode(', ', array_filter([
        $row['HouseNumber'] ?? '',
        $row['BuildingName'] ?? '',
        $row['StreetName'] ?? '',
        $row['AreaName'] ?? '',
        $row['Purok'] ?? '',
        $row['BarangayName'] ?? '',
        $row['CityMunicipalityName'] ?? ''
    ]));
}

/* Current household address, pre-filled in the Change Household Address form. */
$addrCurrent = [];
foreach (['HouseNumber', 'BuildingName', 'StreetName', 'AreaName', 'AreaType', 'Purok', 'ZipCode', 'RegionName', 'ProvinceName',
    'CityMunicipalityName', 'BarangayName', 'PSGCRegionCode', 'PSGCProvinceCode', 'PSGCMunicipalityCode', 'PSGCBarangayCode'] as $k) {
    $addrCurrent[$k] = (string) ($addressSource[$k] ?? '');
}
$addrCurrent['Latitude'] = $hhLocation ? (string) $hhLocation['lat'] : '';
$addrCurrent['Longitude'] = $hhLocation ? (string) $hhLocation['lng'] : '';
$addrCurrent['label'] = $address;
$relationshipOptions = ['Spouse', 'Son', 'Daughter', 'Stepson', 'Stepdaughter', 'Son-in-law', 'Daughter-in-law',
    'Grandson', 'Granddaughter', 'Father', 'Mother', 'Father-in-law', 'Mother-in-law',
    'Brother', 'Sister', 'Brother-in-law', 'Sister-in-law', 'Grandfather', 'Grandmother',
    'Uncle', 'Aunt', 'Nephew', 'Niece', 'Cousin', 'Other Relative', 'Boarder',
    'Domestic Helper', 'Non-relative', 'Other'];
?>
<!doctype html>
<html <?= $theme_attrs['html']; ?>>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Edit Household - Barangay Biñang 2nd</title>

    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=DM+Mono:wght@400;500&display=swap"
        rel="stylesheet">
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700;1,0,-50..200"
        rel="stylesheet">

    <?php include __DIR__ . '/../../theme_head.php'; ?>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            DEFAULT: 'var(--accent-600)',
                            light: 'var(--accent-500)',
                            dark: 'var(--accent-700)'
                        },
                        accent: {
                            DEFAULT: 'var(--accent-500)',
                            light: 'var(--accent-400)'
                        }
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
            --sidebar-w: 288px
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--page-bg, #eef2fb);
            -webkit-font-smoothing: antialiased;
        }

        .main-wrapper {
            margin-left: var(--sidebar-w);
            width: calc(100% - var(--sidebar-w));
        }

        @media(max-width:1024px) {
            .main-wrapper {
                margin-left: 0;
                width: 100%
            }
        }

        .member-table {
            border: 1px solid #e7edf5;
            border-radius: 18px;
            overflow: hidden;
            background: #fff
        }

        .member-head,
        .member-row {
            display: grid;
            grid-template-columns: 52px minmax(220px, 1.25fr) minmax(260px, 1.7fr) minmax(130px, .8fr) 70px;
            align-items: center;
            column-gap: 18px
        }

        .member-head {
            padding: 12px 18px;
            background: #f8fafc;
            border-bottom: 1px solid #e7edf5
        }

        .member-row {
            min-height: 76px;
            padding: 13px 18px;
            border-bottom: 1px solid #edf1f6;
            transition: background .15s ease
        }

        .member-row:last-child {
            border-bottom: 0
        }

        .member-row:hover {
            background: #f8fbff
        }

        .member-index {
            width: 30px;
            height: 30px;
            border-radius: 10px;
            background: #f1f5f9;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 900
        }

        .member-person {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0
        }

        .member-avatar {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: #eef4ff;
            color: #4f46e5;
            display: flex;
            align-items: center;
            justify-content: center;
            flex: none
        }

        .member-primary {
            font-size: 12px;
            font-weight: 900;
            color: #334155;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis
        }

        .member-secondary {
            font-size: 9px;
            color: #94a3b8;
            font-weight: 700;
            margin-top: 3px
        }

        .member-value {
            font-size: 10px;
            font-weight: 700;
            color: #475569;
            line-height: 1.45;
            word-break: break-word
        }

        .member-action {
            display: flex;
            justify-content: flex-end
        }

        .member-remove {
            width: 36px;
            height: 36px;
            border: 0;
            border-radius: 11px;
            background: #fff;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: .15s
        }

        .member-remove:hover {
            background: #fff1f2;
            color: #e11d48
        }

        .member-search-result {
            display: flex;
            align-items: center;
            gap: 12px
        }

        .member-search-result .member-avatar {
            background: #eef4ff
        }

        .member-search-meta {
            min-width: 0;
            flex: 1
        }

        @media(max-width:900px) {
            .member-head {
                display: none
            }

            .member-row {
                grid-template-columns: 36px minmax(0, 1fr) 44px;
                row-gap: 8px;
                padding: 14px
            }

            .member-row .member-index {
                grid-row: 1 / span 2
            }

            .member-row .member-person {
                grid-column: 2
            }

            .member-row .member-address {
                grid-column: 2
            }

            .member-row .member-contact {
                grid-column: 2
            }

            .member-row .member-action {
                grid-column: 3;
                grid-row: 1 / span 3
            }

            .member-value {
                white-space: normal
            }

            .member-person {
                gap: 9px
            }
        }

        .search-result {
            animation: fadeIn .14s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-3px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
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

        @keyframes hhFadeIn {
            from { opacity: 0; transform: translateY(6px) scale(.98); }
            to { opacity: 1; transform: none; }
        }

        .animate-fade-in {
            animation: hhFadeIn .18s ease-out;
        }

        /* Same select style as the Resident Add/Edit modal (residents.php). */
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
    <?php if (!empty($googleKey)): ?>
        <script async defer
            src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($googleKey, ENT_QUOTES, 'UTF-8') ?>"></script>
    <?php else: ?>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="" />
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
    <?php endif; ?>
</head>

<body <?php echo $theme_attrs['body']; ?>>
    <div class="flex min-h-screen">
        <?php include __DIR__ . '/../../sidebar.php'; ?>

        <div class="flex-1 flex flex-col min-w-0 main-wrapper">
            <?php include __DIR__ . '/../../header.php'; ?>

            <main class="p-4 md:p-6 lg:p-8 space-y-6">

                <!-- CAPS Hero Band -->
                <section class="rounded-2xl p-6 md:p-8 text-white relative overflow-hidden"
                    style="background:linear-gradient(135deg,var(--accent-700) 0%,var(--accent-600) 50%,var(--accent-700) 100%);">
                    <div class="absolute -right-12 -top-12 w-64 h-64 opacity-10 rounded-full blur-3xl pointer-events-none"
                        style="background:var(--accent-400);"></div>
                    <div class="absolute left-1/3 bottom-0 w-48 h-48 opacity-10 rounded-full blur-2xl pointer-events-none"
                        style="background:var(--accent-300);"></div>

                    <div class="relative z-10 flex flex-col md:flex-row md:items-center md:justify-between gap-5">
                        <div>
                            <div
                                class="flex items-center gap-2 text-white/60 text-[10px] font-black uppercase tracking-[0.18em] mb-2">
                                <span class="material-symbols-outlined text-base">home_work</span>
                                Household Management
                            </div>
                            <h1 class="text-2xl md:text-3xl font-black tracking-tight leading-none">
                                Edit Household
                            </h1>
                            <p class="text-white/65 text-sm mt-2 font-medium">
                                Update household membership while keeping the Resident record as the source of truth.
                            </p>
                        </div>

                        <div class="flex items-center gap-2 shrink-0">
                            <span
                                class="px-4 py-3 rounded-xl bg-white/10 border border-white/20 text-white text-[10px] font-black uppercase tracking-wider font-mono">
                                <?= htmlspecialchars($householdId, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <a href="households.php"
                                class="inline-flex items-center justify-center gap-2 bg-white/10 hover:bg-white/20 border border-white/20 text-white px-5 py-3 rounded-xl font-black text-xs uppercase tracking-wider transition-all">
                                <span class="material-symbols-outlined text-lg">arrow_back</span>
                                Back to Households
                            </a>
                        </div>
                    </div>
                </section>

<?php if ($isHeadless): ?>
                    <div class="rounded-2xl border-2 border-amber-200 bg-amber-50 px-6 py-4 flex items-start gap-3">
                        <span class="material-symbols-outlined text-amber-600">warning</span>
                        <div>
                            <p class="text-sm font-black text-amber-800">This household has no Head and needs a new Head.</p>
                            <p class="text-xs font-semibold text-amber-700 mt-0.5">Choose an existing household member or another resident in <strong>Change Household Head</strong>, then Save Changes.</p>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="post" action="#" id="householdForm" class="space-y-6" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="head_resident_id" value="<?= $id ?>">

                    <?php include __DIR__ . '/partials/household_info_sections.php'; ?>

                    <!-- Change Household Address — same address form as Resident Profiling -->
                    <section class="bg-white rounded-[28px] border border-slate-100 shadow-sm overflow-hidden" id="addressSection">
                        <div class="px-6 md:px-8 py-5 border-b border-slate-50 flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                                    <span class="material-symbols-outlined">edit_location_alt</span>
                                </div>
                                <div>
                                    <h2 class="text-sm font-black text-slate-800">Change Household Address</h2>
                                    <p class="text-[10px] text-slate-400 font-semibold mt-0.5">Same address fields, map and validation as Resident Profiling. The Head and every member move to this address.</p>
                                </div>
                            </div>
                            <span id="adPendingBadge" class="hidden px-3 py-1 rounded-full bg-amber-100 text-amber-700 text-[9px] font-black uppercase tracking-wider">Pending · Save Changes to apply</span>
                        </div>
                        <div class="p-6 md:p-8 space-y-5">
                            <p id="ad_default_address_note"
                                class="text-[10px] font-bold text-slate-500 bg-indigo-50 border border-indigo-100 rounded-2xl px-4 py-3">
                                Loading the default barangay address from Manage Area…
                            </p>
                            <div class="grid grid-cols-12 gap-4 rounded-2xl border border-indigo-100 bg-indigo-50/40 p-4">
                                <?php foreach ([['adRegionName', 'Region'], ['adProvinceName', 'Province'], ['adCityMunicipalityName', 'City/Municipality'], ['adBarangayName', 'Barangay']] as [$fid, $flabel]): ?>
                                    <div class="col-span-12 md:col-span-6 space-y-1.5">
                                        <label for="<?= $fid ?>" class="text-[10px] font-bold text-slate-400 uppercase ml-1"><?= $flabel ?></label>
                                        <input type="text" id="<?= $fid ?>" readonly placeholder="Auto-filled from Manage Area"
                                            class="w-full bg-white border border-slate-200 rounded-xl py-3 px-4 text-sm font-bold text-slate-700 cursor-not-allowed">
                                    </div>
                                <?php endforeach; ?>
                                <input type="hidden" id="adPSGCRegionCode"><input type="hidden" id="adPSGCProvinceCode">
                                <input type="hidden" id="adPSGCMunicipalityCode"><input type="hidden" id="adPSGCBarangayCode">
                            </div>
                            <div class="grid grid-cols-12 gap-4">
                                <div class="col-span-12 md:col-span-6 space-y-1.5">
                                    <label for="adHouseNumber" class="text-[10px] font-bold text-slate-400 uppercase ml-1">House/Lot/Unit Number *</label>
                                    <input type="text" id="adHouseNumber" placeholder="e.g. 123, Block 5 Lot 2, Unit 4A"
                                        value="<?= hh_view_escape($addrCurrent['HouseNumber']) ?>" oninput="adOnEdit(); adScheduleAddressCheck(); adScheduleRecalc();"
                                        class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold focus:ring-2 focus:ring-primary/20">
                                </div>
                                <div class="col-span-12 md:col-span-6 space-y-1.5">
                                    <label for="adBuildingName" class="text-[10px] font-bold text-slate-400 uppercase ml-1">Building Name</label>
                                    <input type="text" id="adBuildingName" placeholder="Optional" value="<?= hh_view_escape($addrCurrent['BuildingName']) ?>" oninput="adOnEdit(); adScheduleRecalc();"
                                        class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold focus:ring-2 focus:ring-primary/20">
                                </div>
                                <div class="col-span-12 md:col-span-6 space-y-1.5">
                                    <label for="adStreetName" class="text-[10px] font-bold text-slate-400 uppercase ml-1">Street *</label>
                                    <select id="adStreetName" class="addr-select" disabled onchange="adOnEdit(); adScheduleAddressCheck(); adScheduleRecalc();">
                                        <option value="">Select a barangay first</option>
                                    </select>
                                </div>
                                <div class="col-span-12 md:col-span-6 space-y-1.5">
                                    <label for="adAreaName" class="text-[10px] font-bold text-slate-400 uppercase ml-1">Subdivision/Village/Sitio/Purok</label>
                                    <select id="adAreaName" class="addr-select" disabled onchange="adOnEdit(); adScheduleRecalc();">
                                        <option value="">Select a barangay first</option>
                                    </select>
                                </div>
                                <div class="col-span-12 md:col-span-6 space-y-1.5">
                                    <label for="adZipCode" class="text-[10px] font-bold text-slate-400 uppercase ml-1">ZIP Code</label>
                                    <input type="text" id="adZipCode" maxlength="10" readonly placeholder="Auto-filled from Manage Area" value="<?= hh_view_escape($addrCurrent['ZipCode']) ?>"
                                        class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold">
                                </div>
                            </div>

                            <div id="adChecking" class="hidden flex items-center gap-2 px-4 py-2 bg-slate-100 rounded-xl">
                                <p class="text-[9px] font-black text-slate-500 uppercase tracking-widest">Checking address for existing household…</p>
                            </div>
                            <div id="adMatchNotice" class="hidden rounded-2xl border border-rose-200 bg-rose-50 p-4">
                                <p class="text-xs font-black text-rose-700">There is an existing household at this address.</p>
                                <p id="adMatchText" class="text-[11px] font-semibold text-rose-600 mt-1"></p>
                            </div>

                            <div class="space-y-2">
                                <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Map Location</label>
                                <div class="relative">
                                    <div id="adMap" class="w-full h-72 rounded-2xl shadow-inner bg-slate-100"></div>
                                    <div class="absolute right-3 bottom-3 flex flex-col gap-2" style="z-index:500">
                                        <button type="button" onclick="adUseMyLocation()"
                                            class="flex items-center gap-2 rounded-xl bg-white px-3 py-2 text-[9px] font-black uppercase tracking-wider text-slate-700 shadow-lg border border-slate-200 hover:bg-slate-50">
                                            <span class="material-symbols-outlined text-base text-primary">my_location</span> Use my location
                                        </button>
                                        <button type="button" onclick="adPickOnMap()"
                                            class="flex items-center gap-2 rounded-xl bg-primary px-3 py-2 text-[9px] font-black uppercase tracking-wider text-white shadow-lg hover:opacity-90">
                                            <span class="material-symbols-outlined text-base">open_in_full</span> Pick on map
                                        </button>
                                    </div>
                                </div>
                                <p class="text-[8px] text-slate-400 font-medium italic">The pin follows the address as you type. Tap the map, use your current location, or drag the pin to set the exact household location — the address fields update from the pin.</p>
                                <div id="adDetected" class="hidden mt-2 rounded-xl bg-slate-50 border border-slate-200 px-3 py-2 text-[9px] font-bold text-slate-600"></div>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <span id="adAdjustedBadge" class="hidden text-[9px] font-black text-amber-700 bg-amber-50 border border-amber-200 rounded-full px-3 py-1">Location manually adjusted</span>
                                <span></span>
                                <div class="flex items-center gap-3">
                                <button type="button" onclick="adUseMyLocation()" class="text-[9px] font-black uppercase tracking-wider text-primary hover:text-accent">Use Current Location</button>
                                <button type="button" onclick="adRecalculateLocation()" class="text-[9px] font-black uppercase tracking-wider text-primary hover:text-accent">Recalculate Location</button>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div class="space-y-1.5">
                                    <label for="adLatitude" class="text-[10px] font-bold text-slate-400 uppercase">Latitude</label>
                                    <input type="text" id="adLatitude" readonly value="<?= hh_view_escape($addrCurrent['Latitude']) ?>"
                                        class="w-full bg-slate-50 border-none rounded-xl py-3 px-4 text-xs font-mono font-bold text-slate-500" placeholder="Auto-filled via Map">
                                </div>
                                <div class="space-y-1.5">
                                    <label for="adLongitude" class="text-[10px] font-bold text-slate-400 uppercase">Longitude</label>
                                    <input type="text" id="adLongitude" readonly value="<?= hh_view_escape($addrCurrent['Longitude']) ?>"
                                        class="w-full bg-slate-50 border-none rounded-xl py-3 px-4 text-xs font-mono font-bold text-slate-500" placeholder="Auto-filled via Map">
                                </div>
                            </div>
                            <div id="adPendingBox" class="hidden rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs font-semibold text-amber-800"></div>
                            <div class="flex justify-end gap-2">
                                <button type="button" id="adUndoBtn" onclick="adUndo()" class="hidden px-5 py-3 rounded-xl bg-slate-100 text-slate-600 font-black text-xs uppercase">Undo Address Change</button>
                                <button type="button" onclick="adSaveAddress()" class="px-5 py-3 rounded-xl bg-emerald-50 text-emerald-700 font-black text-xs uppercase">Save Address</button>
                            </div>
                        </div>
                    </section>

                    <!-- Change Household Head -->
                    <section class="bg-white rounded-[28px] border border-slate-100 shadow-sm overflow-hidden" id="headSection">
                        <div class="px-6 md:px-8 py-5 border-b border-slate-50 flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                                    <span class="material-symbols-outlined">swap_horiz</span>
                                </div>
                                <div>
                                    <h2 class="text-sm font-black text-slate-800">Change Household Head</h2>
                                    <p class="text-[10px] text-slate-400 font-semibold mt-0.5">Select New Household Head</p>
                                </div>
                            </div>
                            <span id="hdPendingBadge" class="hidden px-3 py-1 rounded-full bg-amber-100 text-amber-700 text-[9px] font-black uppercase tracking-wider">Pending · Save Changes to apply</span>
                        </div>
                        <div class="p-6 md:p-8 space-y-4">
                            <div class="flex flex-wrap gap-3">
                                <label class="flex items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 cursor-pointer text-sm font-bold text-slate-700">
                                    <input type="radio" name="hdMode" value="member" onchange="hdModeChanged()"> Existing Household Member
                                </label>
                                <label class="flex items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 cursor-pointer text-sm font-bold text-slate-700">
                                    <input type="radio" name="hdMode" value="resident" onchange="hdModeChanged()"> New Resident
                                </label>
                            </div>

                            <div id="hdMemberWrap" class="hidden space-y-1.5">
                                <label for="hdMemberSelect" class="text-[10px] font-bold text-slate-400 uppercase ml-1">Household member</label>
                                <select id="hdMemberSelect" class="addr-select" style="background:#f1f5f9">
                                    <option value="">Select a member of this household</option>
                                    <?php foreach ($currentMembers as $m): ?>
                                        <option value="<?= (int) $m['ResidentID'] ?>"><?= hh_view_escape(member_name($m) . ' — ' . ($m['RelationshipToHead'] ?: 'Member')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!$currentMembers): ?><p class="text-[10px] font-semibold text-slate-400 ml-1">This household has no other members. Use New Resident.</p><?php endif; ?>
                            </div>

                            <div id="hdResidentWrap" class="hidden space-y-2">
                                <div class="relative">
                                    <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">person_search</span>
                                    <input id="hdResidentSearch" type="text" autocomplete="off" placeholder="Search resident by name or contact..."
                                        class="w-full pl-11 pr-4 py-3 rounded-2xl border border-slate-200 bg-white text-sm font-semibold text-slate-700 focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/10 outline-none">
                                </div>
                                <div id="hdResidentResults" class="space-y-2"></div>
                                <div id="hdResidentPicked"></div>
                                <div id="hdResidentNotice" class="hidden rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3">
                                    <p class="text-xs font-black text-amber-800">This resident is currently from another household. If you continue, their household/address assignment will be changed.</p>
                                    <p id="hdResidentNoticeDetail" class="text-[11px] font-semibold text-amber-700 mt-1"></p>
                                </div>
                            </div>

                            <div id="hdPendingBox" class="hidden rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs font-semibold text-amber-800"></div>

                            <!-- Relationship of EVERY member to the new Head (same dropdown as Resident Profiling) -->
                            <div id="hdRelEditor" class="hidden rounded-2xl border border-indigo-100 overflow-hidden">
                                <div class="px-4 py-3 bg-indigo-50/70">
                                    <p class="text-xs font-black text-indigo-800">Relationship to the new Household Head</p>
                                    <p class="text-[10px] font-semibold text-indigo-600 mt-0.5">New Head → <strong>Head</strong>. Previous Head and all members → <strong>Member</strong>. Update each member's relationship to the new Head.</p>
                                </div>
                                <div id="hdRelRows" class="divide-y divide-slate-100"></div>
                            </div>
                            <div class="flex justify-end gap-2">
                                <button type="button" id="hdUndoBtn" onclick="hdUndo()" class="hidden px-5 py-3 rounded-xl bg-slate-100 text-slate-600 font-black text-xs uppercase">Undo Head Change</button>
                                <button type="button" onclick="hdApply()" class="px-5 py-3 rounded-xl bg-amber-50 text-amber-700 font-black text-xs uppercase">Change Head</button>
                            </div>
                        </div>
                    </section>

                    <!-- Members -->
                    <section class="bg-white rounded-[28px] border border-slate-100 shadow-sm overflow-hidden">
                        <div class="px-6 md:px-8 py-5 border-b border-slate-50 flex items-center justify-between gap-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center shrink-0">
                                    <span class="material-symbols-outlined">groups</span>
                                </div>
                                <div>
                                    <h2 class="text-sm font-black text-slate-800">Household Members</h2>
                                    <p class="text-[10px] text-slate-400 font-semibold mt-0.5">Add or reassign existing residents to this household.</p>
                                </div>
                            </div>
                            <span id="memberCount" class="px-3 py-1 bg-slate-100 text-slate-500 text-[9px] font-black uppercase tracking-wider rounded-full">
                                <?= count($currentMembers) ?> <?= count($currentMembers) === 1 ? 'Member' : 'Members' ?>
                            </span>
                        </div>
                        <div class="p-6 md:p-8">
                            <div class="relative">
                                <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">person_search</span>
                                <input id="memberSearch" type="text" autocomplete="off" placeholder="Search resident by name or contact..."
                                    class="w-full pl-11 pr-4 py-3 rounded-2xl border border-slate-200 bg-white text-sm font-semibold text-slate-700 focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/10 outline-none transition-all">
                            </div>
                            <div id="memberResults" class="mt-2 space-y-2"></div>
                            <div id="membersEmpty" class="<?= count($currentMembers) ? 'hidden ' : '' ?>mt-5 rounded-2xl border border-dashed border-slate-200 bg-slate-50/50 px-6 py-10 text-center">
                                <span class="material-symbols-outlined text-3xl text-slate-300">person_add</span>
                                <p class="text-xs font-bold text-slate-400 mt-2">No household members selected</p>
                                <p class="text-[10px] text-slate-400 mt-1">Search above to add existing residents.</p>
                            </div>
                            <div id="memberListWrap" class="<?= count($currentMembers) ? '' : 'hidden ' ?>mt-5 rounded-2xl border border-slate-100 overflow-hidden">
                                <div class="member-head text-[9px] font-black uppercase tracking-widest text-slate-400">
                                    <div>#</div><div>Resident</div><div>Address</div><div>Contact</div><div class="text-right">Action</div>
                                </div>
                                <div id="members" class="divide-y divide-slate-100"></div>
                            </div>
                        </div>
                    </section>

                    <!-- Bottom action bar -->
                    <section class="bg-white rounded-[28px] border border-slate-100 shadow-sm px-6 md:px-8 py-5">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <div class="flex items-center gap-2 text-xs font-semibold text-slate-400">
                                <span class="material-symbols-outlined text-lg text-emerald-500">verified_user</span>
                                <span id="dirtyLabel">No unsaved changes.</span>
                            </div>
                            <div class="flex items-center justify-end gap-3">
                                <a href="households.php" class="px-5 py-3 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 font-black text-xs uppercase tracking-wider transition-all">Cancel</a>
                                <button type="submit" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-primary text-white font-black text-xs uppercase tracking-wider shadow-md hover:opacity-90 transition-all">
                                    <span class="material-symbols-outlined text-lg">save</span> Save Changes
                                </button>
                            </div>
                        </div>
                    </section>
                </form>
            </main>
        </div>
    </div>

    <!-- Remove Member: confirmation, then household destination (no resident profiling here) -->
    <div id="transferModal"
        class="hidden fixed inset-0 z-[100] bg-slate-950/50 backdrop-blur-sm items-center justify-center p-4">
        <div class="w-full max-w-3xl max-h-[92vh] overflow-y-auto rounded-3xl bg-white shadow-2xl">
            <div class="p-6 border-b border-slate-100 flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-sm font-black text-primary uppercase tracking-tight">Household Member Transfer</h3>
                    <p class="text-[10px] font-bold text-slate-400 uppercase mt-2">Resident</p>
                    <p id="trWho" class="text-sm font-black text-slate-800"></p>
                </div>
                <button type="button" onclick="closeTransfer()"
                    class="w-9 h-9 rounded-xl bg-slate-100 text-slate-500 flex items-center justify-center shrink-0"
                    title="Close"><span class="material-symbols-outlined text-lg">close</span></button>
            </div>

            <!-- Step 2: where will this resident go? -->
            <div id="trStep2" class="p-6 space-y-4">
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Where will this resident belong?</p>

                <div id="trReplaceWrap" class="hidden rounded-2xl bg-amber-50 border border-amber-100 p-4 space-y-2">
                    <p class="text-xs font-bold text-amber-700">This resident is the current Head. Choose the member who will become the new Head of this household.</p>
                    <select id="trReplacement" class="w-full rounded-xl border-slate-200 text-sm font-semibold">
                        <option value="">Select new head</option>
                    </select>
                </div>

                <label class="flex items-center gap-2 text-sm font-bold text-slate-700">
                    <input type="radio" name="trDest" value="existing" onchange="transferMode()"> Join Existing Household
                </label>
                <div id="trExistingWrap" class="hidden pl-6 space-y-2">
                    <input id="trHhSearch" type="text" autocomplete="off"
                        placeholder="Search Household ID, head name or address..."
                        class="w-full rounded-xl border-slate-200 text-sm font-semibold">
                    <div id="trHhResults" class="space-y-2"></div>
                    <div id="trHhSelected"></div>
                    <label class="block">
                        <span class="text-[9px] font-black uppercase tracking-widest text-slate-400">Relationship to the new Head</span>
                        <input id="trRelationship" value="Member" class="mt-1 w-full rounded-xl border-slate-200 text-sm font-semibold">
                    </label>
                    <p class="text-[10px] text-slate-400 font-semibold">Address and map location are taken from the selected Household.</p>
                </div>

                <label class="flex items-center gap-2 text-sm font-bold text-slate-700">
                    <input type="radio" name="trDest" value="new" onchange="transferMode()"> Create New Household
                </label>
                <!-- Create New Household: same Address Information layout as the Resident Add/Edit modal -->
                <div id="trNewWrap" class="hidden space-y-5 pt-2">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-white bg-primary p-1.5 rounded-lg text-sm shadow-md">location_on</span>
                        <h4 class="text-sm font-black text-primary uppercase tracking-tight">Address Information</h4>
                    </div>
                    <p id="tr_default_address_note"
                        class="text-[10px] font-bold text-slate-500 bg-indigo-50 border border-indigo-100 rounded-2xl px-4 py-3">
                        Loading the default barangay address from Manage Area…
                    </p>

                    <!-- Region/Province/City/Barangay come from Manage Area (read-only, like Residents). -->
                    <div class="grid grid-cols-12 gap-4 rounded-2xl border border-indigo-100 bg-indigo-50/40 p-4">
                        <div class="col-span-12 md:col-span-6 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Region</label>
                            <input type="text" id="trRegionName" readonly placeholder="Auto-filled from Manage Area"
                                class="w-full bg-white border border-slate-200 rounded-xl py-3 px-4 text-sm font-bold text-slate-700 cursor-not-allowed">
                        </div>
                        <div class="col-span-12 md:col-span-6 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Province</label>
                            <input type="text" id="trProvinceName" readonly placeholder="Auto-filled from Manage Area"
                                class="w-full bg-white border border-slate-200 rounded-xl py-3 px-4 text-sm font-bold text-slate-700 cursor-not-allowed">
                        </div>
                        <div class="col-span-12 md:col-span-6 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">City/Municipality</label>
                            <input type="text" id="trCityMunicipalityName" readonly placeholder="Auto-filled from Manage Area"
                                class="w-full bg-white border border-slate-200 rounded-xl py-3 px-4 text-sm font-bold text-slate-700 cursor-not-allowed">
                        </div>
                        <div class="col-span-12 md:col-span-6 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Barangay</label>
                            <input type="text" id="trBarangayName" readonly placeholder="Auto-filled from Manage Area"
                                class="w-full bg-white border border-slate-200 rounded-xl py-3 px-4 text-sm font-bold text-slate-700 cursor-not-allowed">
                        </div>
                        <input type="hidden" id="trPSGCRegionCode"><input type="hidden" id="trPSGCProvinceCode">
                        <input type="hidden" id="trPSGCMunicipalityCode"><input type="hidden" id="trPSGCBarangayCode">
                    </div>

                    <div class="grid grid-cols-12 gap-4">
                        <div class="col-span-12 md:col-span-6 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">House/Lot/Unit Number</label>
                            <input type="text" id="trHouseNumber" placeholder="e.g. 123, Block 5 Lot 2, Unit 4A"
                                oninput="trScheduleAddressCheck()"
                                class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold focus:ring-2 focus:ring-primary/20">
                        </div>
                        <div class="col-span-12 md:col-span-6 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Building Name</label>
                            <input type="text" id="trBuildingName" placeholder="Optional"
                                class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold focus:ring-2 focus:ring-primary/20">
                        </div>
                        <div class="col-span-12 md:col-span-6 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Street</label>
                            <select id="trStreetName" class="addr-select" disabled onchange="trScheduleAddressCheck(); trScheduleRecalc();">
                                <option value="">Select a barangay first</option>
                            </select>
                        </div>
                        <div class="col-span-12 md:col-span-6 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Subdivision/Village/Sitio/Purok</label>
                            <select id="trAreaName" class="addr-select" disabled onchange="trScheduleRecalc();">
                                <option value="">Select a barangay first</option>
                            </select>
                        </div>
                        <div class="col-span-12 md:col-span-6 space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">ZIP Code</label>
                            <input type="text" id="trZipCode" maxlength="10" readonly placeholder="Auto-filled from Manage Area"
                                class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold focus:ring-2 focus:ring-primary/20">
                        </div>
                    </div>

                    <div id="trChecking" class="hidden flex items-center gap-2 px-4 py-2 bg-slate-100 rounded-xl">
                        <p class="text-[9px] font-black text-slate-500 uppercase tracking-widest">Checking address for existing household…</p>
                    </div>

                    <!-- Same-address household found (same House No. + Street rule as the Resident module) -->
                    <div id="trMatchPanel" class="hidden overflow-hidden rounded-2xl border-2 border-indigo-200 shadow-md">
                        <div class="flex items-center gap-3 px-5 py-3.5 bg-indigo-600 text-white">
                            <span class="material-symbols-outlined text-base">home_pin</span>
                            <div>
                                <p class="text-[10px] font-black uppercase tracking-widest leading-none">Existing Household Found</p>
                                <p class="text-[8px] text-indigo-200 font-bold mt-0.5">A Household already exists at this address.</p>
                            </div>
                        </div>
                        <div class="bg-indigo-50/40 p-5 space-y-3">
                            <div class="grid grid-cols-3 gap-3 text-xs">
                                <div class="col-span-3 md:col-span-1 bg-white rounded-2xl p-4 border border-indigo-100">
                                    <p class="text-[8px] font-black text-indigo-400 uppercase tracking-widest mb-0.5">Household ID</p>
                                    <p id="trMatchId" class="font-black text-slate-800">—</p>
                                </div>
                                <div class="col-span-3 md:col-span-1 bg-white rounded-2xl p-4 border border-indigo-100">
                                    <p class="text-[8px] font-black text-indigo-400 uppercase tracking-widest mb-0.5">Household Head</p>
                                    <p id="trMatchHead" class="font-black text-slate-800">—</p>
                                </div>
                                <div class="col-span-3 md:col-span-1 bg-white rounded-2xl p-4 border border-indigo-100">
                                    <p class="text-[8px] font-black text-indigo-400 uppercase tracking-widest mb-0.5">Address</p>
                                    <p id="trMatchAddress" class="font-bold text-slate-700">—</p>
                                </div>
                            </div>
                            <p id="trMatchNote" class="text-[9px] font-bold text-slate-600">This resident can be added as a member of the existing Household.</p>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" id="trMatchJoinBtn" onclick="trJoinMatchedHousehold()"
                                    class="flex items-center gap-2 py-3 px-4 bg-indigo-600 hover:bg-indigo-700 text-white text-[10px] font-black uppercase tracking-widest rounded-xl shadow-md">
                                    <span class="material-symbols-outlined text-base">group_add</span> Join Existing Household
                                </button>
                                <button type="button" onclick="trChangeAddress()"
                                    class="flex items-center gap-2 py-3 px-4 bg-white border border-slate-200 text-slate-600 text-[10px] font-black uppercase tracking-widest rounded-xl">
                                    <span class="material-symbols-outlined text-base">edit_location_alt</span> Change Address
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Map Location</label>
                        <div class="relative">
                            <div id="trMap" class="w-full h-72 rounded-2xl shadow-inner bg-slate-100"></div>
                            <div class="absolute right-3 bottom-3 flex flex-col gap-2" style="z-index:500">
                                <button type="button" onclick="trUseMyLocation()"
                                    class="flex items-center gap-2 rounded-xl bg-white px-3 py-2 text-[9px] font-black uppercase tracking-wider text-slate-700 shadow-lg border border-slate-200 hover:bg-slate-50">
                                    <span class="material-symbols-outlined text-base text-primary">my_location</span> Use my location
                                </button>
                                <button type="button" onclick="trPickOnMap()"
                                    class="flex items-center gap-2 rounded-xl bg-primary px-3 py-2 text-[9px] font-black uppercase tracking-wider text-white shadow-lg hover:opacity-90">
                                    <span class="material-symbols-outlined text-base">open_in_full</span> Pick on map
                                </button>
                            </div>
                        </div>
                        <p class="text-[8px] text-slate-400 font-medium italic">Tap the map, use your current location, or drag the pin to set the exact household location.</p>
                    </div>
                    <div class="flex items-center justify-end gap-3">
                        <button type="button" onclick="trUseMyLocation()"
                            class="text-[9px] font-black uppercase tracking-wider text-primary hover:text-accent">Use Current Location</button>
                        <button type="button" onclick="trRecalculateLocation()"
                            class="text-[9px] font-black uppercase tracking-wider text-primary hover:text-accent">Recalculate Location</button>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase">Latitude</label>
                            <input type="text" id="trLatitude" readonly
                                class="w-full bg-slate-50 border-none rounded-xl py-3 px-4 text-xs font-mono font-bold text-slate-500"
                                placeholder="Auto-filled via Map">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold text-slate-400 uppercase">Longitude</label>
                            <input type="text" id="trLongitude" readonly
                                class="w-full bg-slate-50 border-none rounded-xl py-3 px-4 text-xs font-mono font-bold text-slate-500"
                                placeholder="Auto-filled via Map">
                        </div>
                    </div>

                    <!-- Family Role: same choices as Resident Profiling -->
                    <div class="rounded-2xl border border-slate-200 p-4 space-y-3">
                        <label class="text-[10px] font-bold text-slate-400 uppercase ml-1">Family Role *</label>
                        <div class="flex flex-wrap gap-3">
                            <label class="flex items-center gap-2 text-sm font-bold text-slate-700"><input type="radio" name="trRole" value="head" checked onchange="trRoleChanged()"> Head</label>
                            <label class="flex items-center gap-2 text-sm font-bold text-slate-700"><input type="radio" name="trRole" value="member" onchange="trRoleChanged()"> Member</label>
                        </div>
                        <p id="trRoleHeadNote" class="text-[10px] font-semibold text-slate-500">This resident becomes the Head of a new household at this address.</p>
                        <div id="trRoleMemberWrap" class="hidden space-y-3">
                            <div>
                                <p class="text-[10px] font-bold text-slate-400 uppercase ml-1 mb-1">Household Head</p>
                                <div id="trRoleHeadPicked" class="rounded-xl bg-slate-50 border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-500">No household selected. Enter an address with an existing household or search below.</div>
                            </div>
                            <input id="trRoleSearch" type="text" autocomplete="off" placeholder="Search Household ID, head name or address..."
                                class="w-full rounded-xl border-slate-200 text-sm font-semibold">
                            <div id="trRoleResults" class="space-y-2"></div>
                            <div class="grid md:grid-cols-2 gap-3">
                                <div class="space-y-1.5">
                                    <label for="trRoleRel" class="text-[10px] font-bold text-slate-400 uppercase ml-1">Relationship to Head *</label>
                                    <select id="trRoleRel" class="addr-select" onchange="relOtherToggle('trRoleRel')">
                                        <option value="">-- Select Relationship --</option>
                                        <?php foreach ($relationshipOptions as $opt): ?><option value="<?= hh_view_escape($opt) ?>"><?= hh_view_escape($opt) ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="space-y-1.5 hidden" id="trRoleRelOtherWrap">
                                    <label for="trRoleRelOther" class="text-[10px] font-bold text-slate-400 uppercase ml-1">Specify Relationship *</label>
                                    <input type="text" id="trRoleRelOther" maxlength="100" class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold">
                                </div>
                            </div>
                            <p class="text-[10px] font-semibold text-slate-400">The member uses the selected household's address and map pin.</p>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="closeTransfer()"
                        class="px-4 py-3 rounded-xl bg-slate-100 text-slate-600 text-xs font-black">Cancel</button>
                    <button type="button" id="trConfirmBtn" onclick="confirmTransfer()"
                        class="px-5 py-3 rounded-xl bg-primary text-white text-xs font-black">Confirm</button>
                </div>
            </div>
        </div>
    </div>
    <!-- Add Member: relationship (same dropdown as Resident Profiling) -->
    <div id="addMemberModal" class="hidden fixed inset-0 z-[120] bg-slate-900/70 items-center justify-center p-4">
        <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-md p-8 space-y-5">
            <div>
                <h3 class="text-base font-black text-slate-800">Add Household Member</h3>
                <p id="amWho" class="text-xs font-semibold text-slate-500 mt-1"></p>
            </div>
            <div id="amNotice" class="hidden rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-[11px] font-semibold text-amber-800"></div>
            <p class="text-[11px] font-semibold text-slate-500">This person's address and map pin will change to this household's address.</p>
            <div class="space-y-1.5">
                <label for="amRel" class="text-[10px] font-bold text-slate-400 uppercase ml-1">Relationship to Head *</label>
                <select id="amRel" class="addr-select" style="background:#f1f5f9" onchange="relOtherToggle('amRel')">
                    <option value="">-- Select Relationship --</option>
                    <?php foreach ($relationshipOptions as $opt): ?><option value="<?= hh_view_escape($opt) ?>"><?= hh_view_escape($opt) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="space-y-1.5 hidden" id="amRelOtherWrap">
                <label for="amRelOther" class="text-[10px] font-bold text-slate-400 uppercase ml-1">Specify Relationship *</label>
                <input type="text" id="amRelOther" maxlength="100" class="w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold">
            </div>
            <p id="amError" class="hidden text-xs font-bold text-rose-600"></p>
            <div class="flex gap-3 pt-2">
                <button type="button" onclick="amClose()" class="flex-1 py-3.5 text-xs font-black uppercase text-slate-400 border border-slate-200 rounded-2xl">Cancel</button>
                <button type="button" onclick="amConfirm()" class="flex-[2] py-3.5 bg-primary text-white text-xs font-black uppercase rounded-2xl shadow-lg">Add Member</button>
            </div>
        </div>
    </div>

    <!-- Save Changes: confirmation summary -->
    <div id="summaryModal" class="hidden fixed inset-0 z-[130] bg-slate-900/70 items-center justify-center p-4">
        <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-lg p-8 space-y-5 max-h-[90vh] overflow-y-auto">
            <div class="flex items-start gap-4">
                <div class="w-12 h-12 rounded-2xl bg-indigo-50 flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-indigo-600 text-2xl">fact_check</span></div>
                <div>
                    <h3 class="text-base font-black text-slate-800">Confirm Changes</h3>
                    <p class="text-xs text-slate-500 font-medium mt-1">Review the changes below. Nothing is saved until you confirm.</p>
                </div>
            </div>
            <ul id="summaryList" class="space-y-2 text-xs text-slate-700"></ul>
            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeSummary()" class="flex-1 py-3.5 text-xs font-black uppercase text-slate-400 border border-slate-200 rounded-2xl">Cancel</button>
                <button type="button" id="summaryConfirmBtn" onclick="saveAll()" class="flex-[2] py-3.5 bg-primary text-white text-xs font-black uppercase rounded-2xl shadow-lg">Confirm &amp; Save</button>
            </div>
        </div>
    </div>

    <!-- Unsaved changes -->
    <div id="leaveModal" class="hidden fixed inset-0 z-[140] bg-slate-900/70 items-center justify-center p-4">
        <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-md p-8 space-y-6">
            <div class="flex items-start gap-4">
                <div class="w-12 h-12 rounded-2xl bg-amber-50 flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-amber-600 text-2xl">warning</span></div>
                <div>
                    <h3 class="text-base font-black text-slate-800">Unsaved Changes</h3>
                    <p class="text-xs text-slate-500 font-medium mt-1.5 leading-relaxed">You still have unsaved changes. If you leave this page, your changes will not be saved.</p>
                </div>
            </div>
            <div class="flex gap-3">
                <button type="button" onclick="stayOnPage()" class="flex-1 py-3.5 text-xs font-black uppercase text-slate-600 border border-slate-200 rounded-2xl">Stay</button>
                <button type="button" onclick="leaveWithoutSaving()" class="flex-[2] py-3.5 bg-rose-600 text-white text-xs font-black uppercase rounded-2xl shadow-lg">Leave Without Saving</button>
            </div>
        </div>
    </div>

    <!-- Confirm / notice dialog: same markup and classes as the #confirmDialog in residents.php -->
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
                    <p id="confirmDialogMessage" class="text-xs text-slate-500 font-medium mt-1.5 leading-relaxed" style="white-space:pre-line"></p>
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
    <script>
        function esc(value) {
            const d = document.createElement('div');
            d.textContent = value ?? '';
            return d.innerHTML;
        }

        /* ---------- Page state (nothing is written until Save Changes → Confirm & Save) ---------- */
        const CSRF = <?= json_encode($csrf) ?>;
        const SURVEY_ID = <?= (int) $surveyId ?>;
        const currentHeadId = <?= json_encode((string) $id) ?>;
        const CURRENT_HEAD_NAME = <?= json_encode($name ?: '(No Head)') ?>;
        const IS_HEADLESS = <?= $isHeadless ? 'true' : 'false' ?>;
        const HOUSEHOLD_ID = <?= json_encode($householdId) ?>;
        const ADDR_ORIG = <?= json_encode($addrCurrent, JSON_UNESCAPED_UNICODE) ?>;
        const REL_OPTIONS = <?= json_encode($relationshipOptions) ?>;
        const selected = new Map();
        <?php foreach ($currentMembers as $member): ?>
            selected.set('<?= (int) $member['ResidentID'] ?>', {
                id: '<?= (int) $member['ResidentID'] ?>',
                fullName: <?= json_encode(member_name($member)) ?>,
                address: <?= json_encode(member_address($member)) ?>,
                contact: <?= json_encode((string) ($member['ContactNumber'] ?? '')) ?>,
                relationship: <?= json_encode((string) ($member['RelationshipToHead'] ?? '') ?: 'Member') ?>,
                saved: true
            });
        <?php endforeach; ?>
        let pendingAddress = null;   // staged by Save Address
        let pendingHead = null;      // staged by Change Head
        let allowLeave = false, pendingNav = null;

        function relOtherToggle(id) {
            const wrap = document.getElementById(id + 'OtherWrap');
            if (wrap) wrap.classList.toggle('hidden', document.getElementById(id).value !== 'Other');
        }
        function relValue(id) {
            const v = document.getElementById(id).value;
            return v === 'Other' ? (document.getElementById(id + 'Other').value || '').trim() : v;
        }

        function membersChanged() {
            const saved = [...selected.values()].filter(x => x.saved).map(x => String(x.id));
            return saved.length !== savedMemberIds.size || [...selected.values()].some(x => !x.saved);
        }
        function isDirty() { return !!(pendingAddress || pendingHead || membersChanged() || adEdited()); }
        function updateDirtyUi() {
            const lbl = document.getElementById('dirtyLabel');
            if (lbl) lbl.textContent = isDirty() ? 'You have unsaved changes. Click Save Changes to review and save.' : 'No unsaved changes.';
        }

        function renderMembers() {
            const values = [...selected.values()];
            document.getElementById('memberCount').textContent = values.length + ' ' + (values.length === 1 ? 'Member' : 'Members');
            const empty = document.getElementById('membersEmpty'), wrap = document.getElementById('memberListWrap'), list = document.getElementById('members');
            empty.classList.toggle('hidden', values.length > 0);
            wrap.classList.toggle('hidden', !values.length);
            list.innerHTML = values.map((x, index) => `
                <div class="member-row">
                    <div class="member-number"><span class="member-index">${index + 1}</span></div>
                    <div class="member-person">
                        <div class="member-avatar"><span class="material-symbols-outlined text-lg">person</span></div>
                        <div class="min-w-0">
                            <p class="member-primary">${esc(x.fullName)}</p>
                            <p class="member-secondary">Resident #${esc(x.id)} · ${esc(x.relationship || 'Member')}${x.saved ? '' : ' · <span style="color:#d97706">New — not saved yet</span>'}${pendingHead && String(pendingHead.id) === String(x.id) ? ' · <span style="color:#d97706">New Head (pending)</span>' : ''}</p>
                        </div>
                    </div>
                    <div class="member-address"><p class="member-value">${esc(x.address || 'No address')}</p></div>
                    <div class="member-contact"><p class="member-value">${esc(x.contact || 'No contact')}</p></div>
                    <div class="member-action">
                        <button type="button" onclick="removeMember('${esc(x.id)}')" class="member-remove" title="Remove member">
                            <span class="material-symbols-outlined text-lg">delete</span>
                        </button>
                    </div>
                </div>`).join('');
            if (typeof hdRenderRelEditor === 'function' && document.getElementById('hdRelRows')) hdRenderRelEditor();
            updateDirtyUi();
        }

        /* ---------- Residents-style confirm / notice dialog (see residents.php showConfirmDialog) ---------- */
        let _confirmCallback = null, _confirmCancelCallback = null;
        function showConfirmDialog({ title, message, iconClass, iconName, okLabel, okClass, onConfirm, onCancel, cancelLabel, hideCancel }) {
            document.getElementById('confirmDialogTitle').textContent = title;
            document.getElementById('confirmDialogMessage').textContent = message;
            const iconWrap = document.getElementById('confirmDialogIcon');
            iconWrap.className = 'w-12 h-12 rounded-2xl flex items-center justify-center shrink-0 ' + (iconClass || 'bg-indigo-50');
            iconWrap.querySelector('span').textContent = iconName || 'help';
            iconWrap.querySelector('span').className = 'material-symbols-outlined text-2xl ' + (iconClass ? iconClass.replace('bg-', 'text-').replace('-50', '-600') : 'text-indigo-600');
            const okBtn = document.getElementById('confirmDialogOkBtn');
            okBtn.textContent = okLabel || 'Confirm';
            okBtn.className = 'flex-[2] py-3.5 text-xs font-black uppercase rounded-2xl shadow-lg active:scale-95 transition-all ' + (okClass || 'bg-primary text-white hover:bg-indigo-700');
            const cancelBtn = document.getElementById('confirmDialogCancelBtn');
            cancelBtn.textContent = cancelLabel || 'Cancel';
            cancelBtn.classList.toggle('hidden', !!hideCancel);
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
        document.getElementById('confirmDialogCancelBtn').addEventListener('click', function () { dismissConfirmDialog(true); });
        document.getElementById('confirmDialog').addEventListener('click', function (e) { if (e.target === this) dismissConfirmDialog(true); });
        // Single-button notice in the same dialog (replaces alert() in the transfer workflow).
        const HH_NOTICE = {
            success: { iconClass: 'bg-emerald-50', iconName: 'check_circle', okClass: 'bg-emerald-600 text-white hover:bg-emerald-700' },
            warning: { iconClass: 'bg-amber-50', iconName: 'warning', okClass: 'bg-primary text-white hover:bg-indigo-700' },
            error: { iconClass: 'bg-rose-50', iconName: 'error', okClass: 'bg-rose-600 text-white hover:bg-rose-700' },
        };
        function hhNotice(type, title, message, onOk) {
            const t = HH_NOTICE[type] || HH_NOTICE.warning;
            showConfirmDialog({ title, message, iconClass: t.iconClass, iconName: t.iconName, okLabel: 'OK', okClass: t.okClass, hideCancel: true, onConfirm: onOk, onCancel: onOk });
        }

        /* ---------- Remove Member = transfer to a household destination ---------- */
        const trSurveyId = <?= (int) $surveyId ?>;
        const trDefaultCenter = <?= json_encode([
            'lat' => is_numeric($head['Latitude'] ?? null) && (float) $head['Latitude'] != 0.0 ? (float) $head['Latitude'] : 14.7935,
            'lng' => is_numeric($head['Longitude'] ?? null) && (float) $head['Longitude'] != 0.0 ? (float) $head['Longitude'] : 120.9247,
        ]) ?>;
        const savedMemberIds = new Set(<?= json_encode(array_map(static fn($m) => (string) (int) $m['ResidentID'], $currentMembers)) ?>);
        let trResidentId = null, trIsHead = false, trTarget = null, trMap = null, trMarker = null, trTimer = null;
        let trResidentName = '', trLastMatchShown = '';
        let trMatch = null, trCheckTimer = null, trRecalcTimer = null, trProfileLoaded = false, trGeocoder = null;
        // Resident module endpoint for Manage Area profile, streets and areas (reused, not duplicated).
        const TR_ADDRESS_API = '../../residents/backend/address_api.php';

        function removeMember(id, isHead = false) {
            const name = isHead ? <?= json_encode($name) ?> : (selected.get(String(id)) || {}).fullName;
            if (!name) return;
            if (!isHead && !savedMemberIds.has(String(id))) {
                // Picked in this session but not saved yet: just drop it from the list.
                selected.delete(String(id)); renderMembers(); return;
            }
            if (pendingHead && String(pendingHead.id) === String(id)) {
                return hhNotice('warning', 'Pending Household Head', `${name} is the pending new Household Head. Undo the Head change first.`);
            }
            if (isDirty()) {
                // Removing a saved member is applied right away (transfer), so other edits must be saved or undone first.
                return hhNotice('warning', 'Unsaved Changes', 'Save or undo your other changes first. Removing a saved member moves the resident to another household immediately.');
            }
            showConfirmDialog({
                title: isHead ? 'Remove Household Head?' : 'Remove Household Member?',
                message: `You are about to remove ${name} from this Household. Please review the new Household assignment before confirming.`,
                iconClass: 'bg-rose-50',
                iconName: 'person_remove',
                okLabel: 'Continue',
                okClass: 'bg-rose-600 text-white hover:bg-rose-700',
                onConfirm: () => trOpenTransferModal(id, isHead, name)
            });
        }
        function trOpenTransferModal(id, isHead, name) {
            trResidentId = String(id); trIsHead = !!isHead; trTarget = null; trResidentName = name; trLastMatchShown = '';
            document.getElementById('trWho').textContent = (isHead ? 'Household Head: ' : 'Member: ') + name;
            document.querySelectorAll('input[name="trDest"]').forEach(r => { r.checked = false; });
            ['trExistingWrap', 'trNewWrap'].forEach(w => document.getElementById(w).classList.add('hidden'));
            ['trHhSearch', 'trHouseNumber', 'trBuildingName', 'trStreetName', 'trAreaName', 'trLatitude', 'trLongitude', 'trRoleSearch', 'trRoleRel', 'trRoleRelOther'].forEach(f => { document.getElementById(f).value = ''; });
            document.querySelector('input[name="trRole"][value="head"]').checked = true; trRoleTarget = null; trRoleChanged();
            trMatch = null; document.getElementById('trMatchPanel').classList.add('hidden'); trClearPin();
            document.getElementById('trRelationship').value = 'Member';
            document.getElementById('trHhResults').innerHTML = ''; document.getElementById('trHhSelected').innerHTML = '';
            const rep = document.getElementById('trReplacement');
            rep.innerHTML = '<option value="">Select new head</option>' + [...selected.values()].filter(x => savedMemberIds.has(String(x.id))).map(x => `<option value="${esc(x.id)}">${esc(x.fullName)}</option>`).join('');
            document.getElementById('trReplaceWrap').classList.toggle('hidden', !(isHead && savedMemberIds.size));
            const m = document.getElementById('transferModal'); m.classList.remove('hidden'); m.classList.add('flex');
        }
        function closeTransfer() { const m = document.getElementById('transferModal'); m.classList.add('hidden'); m.classList.remove('flex'); }
        function transferDest() { const r = document.querySelector('input[name="trDest"]:checked'); return r ? r.value : ''; }
        function transferMode() {
            const d = transferDest();
            document.getElementById('trExistingWrap').classList.toggle('hidden', d !== 'existing');
            document.getElementById('trNewWrap').classList.toggle('hidden', d !== 'new');
            if (d === 'new') { initTransferMap(); trLoadAddressProfile(); }
        }

        /* Family Role in Create New Household (Head / Member), same choices as Resident Profiling. */
        let trRoleTarget = null, trRoleTimer = null;
        function trRole() { return document.querySelector('input[name="trRole"]:checked')?.value || 'head'; }
        function trRoleChanged() {
            const member = trRole() === 'member';
            document.getElementById('trRoleMemberWrap').classList.toggle('hidden', !member);
            document.getElementById('trRoleHeadNote').classList.toggle('hidden', member);
            if (member && trMatch && Number(trMatch.survey_id) !== Number(trSurveyId)) trSetRoleTarget({ survey_id: trMatch.survey_id, household_id: trMatch.household_id, head_name: trMatch.head_name, address: trMatch.address });
        }
        function trSetRoleTarget(t) {
            trRoleTarget = t;
            document.getElementById('trRoleHeadPicked').innerHTML = t
                ? `<div class="font-black text-indigo-700">${esc(t.household_id)}</div><div class="font-bold text-slate-700">Head: ${esc(t.head_name)}</div><div class="text-slate-500">Address: ${esc(t.address || 'No address')}</div>`
                : 'No household selected. Enter an address with an existing household or search below.';
        }
        document.getElementById('trRoleSearch').addEventListener('input', e => {
            clearTimeout(trRoleTimer);
            const q = e.target.value.trim(), box = document.getElementById('trRoleResults');
            if (!q) { box.innerHTML = ''; return; }
            trRoleTimer = setTimeout(async () => {
                try {
                    const r = await fetch('../backend/search_households.php?exclude=' + trSurveyId + '&q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } });
                    const data = await r.json();
                    if (!Array.isArray(data) || !data.length) { box.innerHTML = '<div class="rounded-xl bg-slate-50 border border-slate-200 px-3 py-3 text-xs font-semibold text-slate-400">No active household found.</div>'; return; }
                    box.innerHTML = data.map(h => `<button type="button" data-sid="${esc(h.survey_id)}" class="w-full text-left px-3 py-2 rounded-xl border border-slate-200 hover:border-indigo-300 hover:bg-indigo-50/40">
                        <div class="text-xs font-black text-indigo-600">${esc(h.household_id)}</div>
                        <div class="text-xs font-bold text-slate-700">Head: ${esc(h.head_name)}</div>
                        <div class="text-[10px] text-slate-400 font-semibold">Address: ${esc(h.address || 'No address')}</div></button>`).join('');
                    box.querySelectorAll('button').forEach(btn => btn.addEventListener('click', () => {
                        const t = data.find(h => String(h.survey_id) === btn.dataset.sid); if (!t) return;
                        box.innerHTML = ''; e.target.value = ''; trSetRoleTarget(t);
                    }));
                } catch (err) { console.error(err); box.innerHTML = '<div class="text-xs text-rose-600 font-semibold">Unable to search households right now.</div>'; }
            }, 200);
        });

        function trIsGoogleMap() { return !!(window.google && google.maps && trMap instanceof google.maps.Map); }
        function trClearPin() {
            if (trMarker) { if (trIsGoogleMap()) trMarker.setMap(null); else if (trMap && trMap.removeLayer) trMap.removeLayer(trMarker); }
            trMarker = null;
        }
        function setTransferPin(lat, lng, pan) {
            document.getElementById('trLatitude').value = Number(lat).toFixed(7);
            document.getElementById('trLongitude').value = Number(lng).toFixed(7);
            if (!trMap) return;
            if (trIsGoogleMap()) {
                if (!trMarker) {
                    trMarker = new google.maps.Marker({ map: trMap, draggable: true });
                    trMarker.addListener('dragend', e => setTransferPin(e.latLng.lat(), e.latLng.lng(), false));
                }
                trMarker.setPosition({ lat: Number(lat), lng: Number(lng) });
                if (pan) { trMap.panTo({ lat: Number(lat), lng: Number(lng) }); trMap.setZoom(18); }
            } else if (window.L) {
                if (!trMarker) {
                    trMarker = L.marker([lat, lng], { draggable: true }).addTo(trMap);
                    trMarker.on('dragend', e => { const p = e.target.getLatLng(); setTransferPin(p.lat, p.lng, false); });
                } else trMarker.setLatLng([lat, lng]);
                if (pan) trMap.setView([lat, lng], 18);
            }
        }
        function initTransferMap() {
            const el = document.getElementById('trMap');
            if (trMap) { if (window.L && trMap.invalidateSize) setTimeout(() => trMap.invalidateSize(), 50); return; }
            if (window.google && google.maps) {
                // Start from the barangay area, not the old household pin: the new household needs its own location.
                trMap = new google.maps.Map(el, { center: trDefaultCenter, zoom: 15, mapTypeControl: true, mapTypeControlOptions: { mapTypeIds: ['roadmap', 'satellite'] }, streetViewControl: false, fullscreenControl: true });
                trMap.addListener('click', e => setTransferPin(e.latLng.lat(), e.latLng.lng(), false));
            } else if (window.L) {
                trMap = L.map(el).setView([trDefaultCenter.lat, trDefaultCenter.lng], 15);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(trMap);
                trMap.on('click', e => setTransferPin(e.latlng.lat, e.latlng.lng, false));
                setTimeout(() => trMap.invalidateSize(), 50);
            } else {
                el.innerHTML = '<div style="height:100%;display:flex;align-items:center;justify-content:center;padding:16px;text-align:center;color:#64748b;font:600 12px Arial">Map unavailable.</div>';
            }
        }
        function trPickOnMap() {
            // Bigger map for precise picking; press again to return to the normal size.
            const el = document.getElementById('trMap');
            el.classList.toggle('h-72'); el.style.height = el.classList.contains('h-72') ? '' : '60vh';
            if (trIsGoogleMap()) google.maps.event.trigger(trMap, 'resize'); else if (trMap && trMap.invalidateSize) setTimeout(() => trMap.invalidateSize(), 50);
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        function trUseMyLocation() {
            if (!navigator.geolocation) return hhNotice('warning', 'Location Unavailable', 'Location is not supported by this browser. Tap the map to pin the location instead.');
            navigator.geolocation.getCurrentPosition(
                pos => { initTransferMap(); setTransferPin(pos.coords.latitude, pos.coords.longitude, true); },
                () => hhNotice('warning', 'Location Unavailable', 'Unable to get your current location. Tap the map to pin the location instead.'),
                { enableHighAccuracy: true, timeout: 10000 }
            );
        }
        function trStructuredAddress() {
            const v = id => (document.getElementById(id)?.value || '').trim();
            return [v('trHouseNumber'), v('trBuildingName'), v('trStreetName'), v('trAreaName'), v('trBarangayName'),
                v('trCityMunicipalityName'), v('trProvinceName'), v('trRegionName'), 'Philippines'].filter(Boolean).join(', ');
        }
        function trRecalculateLocation() {
            // Same approach as the Resident modal: geocode the structured address with Google.
            if (!trIsGoogleMap()) return hhNotice('warning', 'Map Not Ready', 'Google Maps is not ready. Tap the map to pin the location.');
            if (!trGeocoder) trGeocoder = new google.maps.Geocoder();
            trGeocoder.geocode({ address: trStructuredAddress(), region: 'PH' }, (results, status) => {
                if (status === 'OK' && results && results[0]) { const loc = results[0].geometry.location; setTransferPin(loc.lat(), loc.lng(), true); }
                else hhNotice('warning', 'Location Not Found', 'Google could not locate this address. Try selecting a street or picking the exact spot on the map.');
            });
        }
        function trScheduleRecalc() {
            clearTimeout(trRecalcTimer);
            if (trIsGoogleMap()) trRecalcTimer = setTimeout(trRecalculateLocation, 700);
        }

        async function trLoadLocal(action, barangayCode, selId, placeholder) {
            const el = document.getElementById(selId);
            el.disabled = true; el.innerHTML = '<option value="">Loading...</option>';
            try {
                const r = await fetch(TR_ADDRESS_API + '?action=' + action + '&' + new URLSearchParams({ barangay: barangayCode }), { credentials: 'same-origin' });
                const j = await r.json(); if (!j.success) throw new Error(j.message || 'Request failed');
                const rows = j.data || [];
                el.innerHTML = '<option value="">' + placeholder + '</option>';
                rows.forEach(x => { const o = document.createElement('option'); o.value = action === 'areas' ? x.area_name : x.street_name; o.textContent = o.value; o.dataset.type = x.area_type || ''; el.appendChild(o); });
                el.disabled = rows.length === 0;
            } catch (e) { el.innerHTML = '<option value="">No records available</option>'; console.error(e); }
        }
        async function trLoadAddressProfile() {
            if (trProfileLoaded) return;
            const note = document.getElementById('tr_default_address_note');
            try {
                const r = await fetch(TR_ADDRESS_API + '?action=profile', { credentials: 'same-origin' });
                const j = await r.json(); const p = j.data || {};
                const set = (id, v) => { document.getElementById(id).value = v || ''; };
                set('trRegionName', p.region_name); set('trProvinceName', p.province_name);
                set('trCityMunicipalityName', p.municipality_name); set('trBarangayName', p.barangay_name);
                set('trPSGCRegionCode', p.psgc_region_code); set('trPSGCProvinceCode', p.psgc_province_code);
                set('trPSGCMunicipalityCode', p.psgc_municipality_code); set('trPSGCBarangayCode', p.psgc_barangay_code);
                set('trZipCode', p.zip_code);
                if (!j.success || !p.psgc_barangay_code) {
                    note.className = 'text-[10px] font-bold text-amber-700 bg-amber-50 border border-amber-200 rounded-2xl px-4 py-3';
                    note.innerHTML = 'Default barangay address is not configured yet. Open <strong>Manage Area</strong> in Residents and save the barangay profile first.';
                    return;
                }
                await trLoadLocal('streets', p.psgc_barangay_code, 'trStreetName', 'Select street');
                await trLoadLocal('areas', p.psgc_barangay_code, 'trAreaName', 'Select area');
                note.className = 'text-[10px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-2xl px-4 py-3';
                note.innerHTML = 'Default address: <strong>' + esc([p.region_name, p.province_name, p.municipality_name, p.barangay_name].filter(Boolean).join(' · ')) + '</strong>. Staff/Admin only needs to enter the house number, building, street and subdivision/sitio/purok.';
                trProfileLoaded = true;
            } catch (e) {
                console.error(e);
                note.className = 'text-[10px] font-bold text-rose-700 bg-rose-50 border border-rose-100 rounded-2xl px-4 py-3';
                note.textContent = 'Unable to load the default barangay address.';
            }
        }

        function trScheduleAddressCheck() { clearTimeout(trCheckTimer); trCheckTimer = setTimeout(trCheckAddress, 400); }
        async function trCheckAddress() {
            const house = document.getElementById('trHouseNumber').value.trim();
            const street = document.getElementById('trStreetName').value.trim();
            const panel = document.getElementById('trMatchPanel');
            trMatch = null; panel.classList.add('hidden');
            if (!house || !street) return;
            const chk = document.getElementById('trChecking'); chk.classList.remove('hidden');
            try {
                const r = await fetch('../backend/household_address_match.php?' + new URLSearchParams({ house_no: house, street: street, exclude_id: trResidentId }), { headers: { 'Accept': 'application/json' } });
                const j = await r.json();
                if (j.success && j.match) {
                    trMatch = j.match;
                    document.getElementById('trMatchId').textContent = trMatch.household_id;
                    document.getElementById('trMatchHead').textContent = trMatch.head_name;
                    document.getElementById('trMatchAddress').textContent = trMatch.address || '—';
                    const same = Number(trMatch.survey_id) === Number(trSurveyId);
                    document.getElementById('trMatchNote').textContent = same
                        ? 'This is the address of the current household. Enter a different address.'
                        : 'This resident can be added as a member of the existing Household.';
                    document.getElementById('trMatchJoinBtn').classList.toggle('hidden', same);
                    panel.classList.remove('hidden');
                    const key = trMatch.household_id + '|' + house + '|' + street;
                    if (!same && trRole() === 'member') trSetRoleTarget({ survey_id: trMatch.survey_id, household_id: trMatch.household_id, head_name: trMatch.head_name, address: trMatch.address });
                    else if (!same && key !== trLastMatchShown) trShowMatchDialog();
                }
            } catch (e) { console.error(e); } finally { chk.classList.add('hidden'); }
        }
        function trShowMatchDialog() {
            if (!trMatch) return;
            if (Number(trMatch.survey_id) === Number(trSurveyId)) {
                return hhNotice('warning', 'Same Household Address', 'This is the address of the current Household. Enter a different address.');
            }
            trLastMatchShown = trMatch.household_id + '|' + document.getElementById('trHouseNumber').value.trim() + '|' + document.getElementById('trStreetName').value.trim();
            showConfirmDialog({
                title: 'Existing Household Found',
                message: `A Household already exists at this address.\n\nHousehold ID: ${trMatch.household_id}\nHousehold Head: ${trMatch.head_name}\nAddress: ${trMatch.address || '—'}`,
                iconClass: 'bg-indigo-50',
                iconName: 'home_pin',
                okLabel: 'Join Household',
                cancelLabel: 'Change Address',
                onConfirm: trJoinMatchedHousehold,
                onCancel: trChangeAddress
            });
        }
        function trJoinMatchedHousehold() {
            if (!trMatch) return;
            // Switch the operation to "Join Existing Household" with the matched household.
            trTarget = { survey_id: trMatch.survey_id, household_id: trMatch.household_id, head_name: trMatch.head_name, address: trMatch.address };
            document.querySelector('input[name="trDest"][value="existing"]').checked = true;
            transferMode();
            document.getElementById('trHhSelected').innerHTML = `<div class="rounded-xl bg-indigo-50 border border-indigo-100 px-3 py-2 text-xs">
                <div class="font-black text-indigo-700">${esc(trTarget.household_id)}</div>
                <div class="font-bold text-slate-700">Head: ${esc(trTarget.head_name)}</div>
                <div class="text-slate-500">Address: ${esc(trTarget.address || 'No address')}</div></div>`;
            document.getElementById('trRelationship').focus();
        }
        function trChangeAddress() {
            trMatch = null; document.getElementById('trMatchPanel').classList.add('hidden');
            document.getElementById('trHouseNumber').value = ''; document.getElementById('trStreetName').value = '';
            document.getElementById('trHouseNumber').focus();
        }

        document.getElementById('trHhSearch').addEventListener('input', e => {
            clearTimeout(trTimer);
            const q = e.target.value.trim(); const box = document.getElementById('trHhResults');
            if (!q) { box.innerHTML = ''; return; }
            trTimer = setTimeout(async () => {
                try {
                    const r = await fetch('../backend/search_households.php?exclude=' + trSurveyId + '&q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } });
                    const data = await r.json();
                    if (!Array.isArray(data) || !data.length) { box.innerHTML = '<div class="rounded-xl bg-slate-50 border border-slate-200 px-3 py-3 text-xs font-semibold text-slate-400">No active household found.</div>'; return; }
                    box.innerHTML = data.map(h => `<button type="button" data-sid="${esc(h.survey_id)}" class="w-full text-left px-3 py-2 rounded-xl border border-slate-200 hover:border-indigo-300 hover:bg-indigo-50/40">
                        <div class="text-xs font-black text-indigo-600">${esc(h.household_id)}</div>
                        <div class="text-xs font-bold text-slate-700">Head: ${esc(h.head_name)}</div>
                        <div class="text-[10px] text-slate-400 font-semibold">Address: ${esc(h.address || 'No address')}</div></button>`).join('');
                    box.querySelectorAll('button').forEach(btn => btn.addEventListener('click', () => {
                        trTarget = data.find(h => String(h.survey_id) === btn.dataset.sid) || null;
                        if (!trTarget) return;
                        box.innerHTML = ''; document.getElementById('trHhSearch').value = '';
                        document.getElementById('trHhSelected').innerHTML = `<div class="rounded-xl bg-indigo-50 border border-indigo-100 px-3 py-2 text-xs">
                            <div class="font-black text-indigo-700">${esc(trTarget.household_id)}</div>
                            <div class="font-bold text-slate-700">Head: ${esc(trTarget.head_name)}</div>
                            <div class="text-slate-500">Address: ${esc(trTarget.address || 'No address')}</div></div>`;
                    }));
                } catch (err) { console.error(err); box.innerHTML = '<div class="text-xs text-rose-600 font-semibold">Unable to search households right now.</div>'; }
            }, 200);
        });

        async function confirmTransfer() {
            const dest = transferDest();
            if (!dest) return hhNotice('warning', 'Household Assignment Required', 'Choose Join Existing Household or Create New Household before continuing.');
            const fd = new FormData();
            fd.append('csrf_token', <?= json_encode($csrf) ?>); fd.append('action', 'transfer_member');
            fd.append('survey_id', trSurveyId); fd.append('resident_id', trResidentId); fd.append('destination', dest);
            if (trIsHead && savedMemberIds.size) {
                const rep = document.getElementById('trReplacement').value;
                if (!rep) return hhNotice('warning', 'New Head Required', 'Select the member who will become the new Head of this Household.');
                fd.append('replacement_head_id', rep);
            }
            if (dest === 'existing') {
                if (!trTarget) return hhNotice('warning', 'Household Required', 'Please select an existing Household before continuing.');
                fd.append('target_survey_id', trTarget.survey_id);
                fd.append('relationship', document.getElementById('trRelationship').value.trim() || 'Member');
            } else if (trRole() === 'member') {
                // Family Role = Member: link to the selected Household Head (same as Resident Profiling).
                if (!trRoleTarget) return hhNotice('warning', 'Household Head Required', 'Select the Household Head for this member: enter an address with an existing household or search for the household.');
                const rel = relValue('trRoleRel');
                if (!rel) return hhNotice('warning', 'Relationship Required', 'Select the relationship to the Household Head' + (document.getElementById('trRoleRel').value === 'Other' ? ' and specify it.' : '.'));
                if (Number(trRoleTarget.survey_id) === Number(trSurveyId)) return hhNotice('warning', 'Same Household', 'The resident already belongs to this household. Select a different household.');
                fd.set('destination', 'existing');
                fd.append('target_survey_id', trRoleTarget.survey_id);
                fd.append('relationship', rel);
            } else {
                const v = f => document.getElementById('tr' + f).value.trim();
                const lat = parseFloat(v('Latitude')), lng = parseFloat(v('Longitude'));
                if (!v('HouseNumber') || !v('StreetName')) return hhNotice('warning', 'Address Required', 'Please complete the new Household address: House/Lot/Unit Number and Street are required.');
                if (!isFinite(lat) || !isFinite(lng) || (lat === 0 && lng === 0)) return hhNotice('warning', 'Map Location Required', 'Please pin the new Household location on the map.');
                await trCheckAddress();
                if (trMatch) { trShowMatchDialog(); return; }
                const areaOpt = document.getElementById('trAreaName').selectedOptions[0];
                const areaType = areaOpt ? (areaOpt.dataset.type || '') : '';
                ['HouseNumber', 'BuildingName', 'StreetName', 'AreaName', 'ZipCode', 'RegionName', 'ProvinceName', 'CityMunicipalityName', 'BarangayName',
                    'PSGCRegionCode', 'PSGCProvinceCode', 'PSGCMunicipalityCode', 'PSGCBarangayCode', 'Latitude', 'Longitude'].forEach(f => fd.append(f, v(f)));
                fd.append('AreaType', areaType);
                fd.append('Purok', areaType === 'Purok' ? v('AreaName') : '');
            }
            const btn = document.getElementById('trConfirmBtn'); btn.disabled = true;
            try {
                const r = await fetch('../backend/household_actions.php', { method: 'POST', body: fd }); const j = await r.json();
                if (!j.success) throw new Error(j.error || 'Unable to remove member.');
                closeTransfer();
                allowLeave = true;
                const who = j.resident_name || trResidentName;
                const next = () => {
                    if (!trIsHead) location.reload();
                    else if (j.old_household_active && j.old_head_id) location.href = 'edit_household.php?id=' + encodeURIComponent(j.old_head_id);
                    else location.href = 'households.php';
                };
                if (j.destination === 'existing' || fd.get('destination') === 'existing') hhNotice('success', 'Household Updated', `${who} has been successfully moved to ${j.household_id}.`, next);
                else hhNotice('success', 'Household Created', `New Household ${j.household_id} has been created successfully.\n${who} is now the Household Head.`, next);
            } catch (e) {
                hhNotice('error', dest === 'existing' ? 'Transfer Failed' : 'Household Creation Failed', e.message);
            } finally { btn.disabled = false; }
        }

        /* ================= Change Household Address (same form/map as Resident Profiling) ================= */
        let adMap = null, adMarker = null, adGeocoder = null, adRecalcTimer = null, adCheckTimer = null, adMatch = null, adProfileLoaded = false;
        const AD_FIELDS = ['HouseNumber', 'BuildingName', 'StreetName', 'AreaName', 'ZipCode', 'RegionName', 'ProvinceName', 'CityMunicipalityName', 'BarangayName',
            'PSGCRegionCode', 'PSGCProvinceCode', 'PSGCMunicipalityCode', 'PSGCBarangayCode', 'Latitude', 'Longitude'];
        const adEl = f => document.getElementById('ad' + f);
        function adFields() {
            const o = {};
            AD_FIELDS.forEach(f => { o[f] = (adEl(f)?.value || '').trim(); });
            const opt = adEl('AreaName').selectedOptions[0];
            o.AreaType = opt ? (opt.dataset.type || '') : '';
            o.Purok = o.AreaType === 'Purok' ? o.AreaName : '';
            o.label = [o.HouseNumber, o.BuildingName, o.StreetName, o.AreaName, o.BarangayName, o.CityMunicipalityName, o.ProvinceName].filter(Boolean).join(', ');
            return o;
        }
        const AD_KEY_FIELDS = ['HouseNumber', 'BuildingName', 'StreetName', 'AreaName', 'Latitude', 'Longitude'];
        function adSame(a, b) { return AD_KEY_FIELDS.every(k => String(a[k] || '').trim() === String(b[k] || '').trim()); }
        function adEdited() {
            if (!adProfileLoaded) return false;
            return !adSame(adFields(), pendingAddress || ADDR_ORIG);
        }
        function adOnEdit() { updateDirtyUi(); }
        function adIsGoogle() { return !!(window.google && google.maps && adMap instanceof google.maps.Map); }
        // fromUser: map click / drag / current location → address fields follow the pin (reverse geocode).
        function adSetPin(lat, lng, pan, fromUser) {
            adEl('Latitude').value = Number(lat).toFixed(7);
            adEl('Longitude').value = Number(lng).toFixed(7);
            if (adMap) {
                if (adIsGoogle()) {
                    if (!adMarker) {
                        adMarker = new google.maps.Marker({ map: adMap, draggable: true, title: 'Household location' });
                        adMarker.addListener('dragend', e => adSetPin(e.latLng.lat(), e.latLng.lng(), false, true));
                    }
                    adMarker.setPosition({ lat: Number(lat), lng: Number(lng) });
                    if (pan) { adMap.panTo({ lat: Number(lat), lng: Number(lng) }); adMap.setZoom(18); }
                } else if (window.L) {
                    if (!adMarker) {
                        adMarker = L.marker([lat, lng], { draggable: true }).addTo(adMap);
                        adMarker.on('dragend', e => { const pt = e.target.getLatLng(); adSetPin(pt.lat, pt.lng, false, true); });
                    } else adMarker.setLatLng([lat, lng]);
                    if (pan) adMap.setView([lat, lng], 18);
                }
            }
            document.getElementById('adAdjustedBadge').classList.toggle('hidden', !fromUser);
            if (fromUser) adReverseGeocode(lat, lng);
            adOnEdit();
        }
        /* Pin → address (same idea as Resident Profiling reverseGeocodeLocation): fill Street / Area / House No. when Google knows them. */
        let adReverseRun = 0, adSuppressRecalc = false;
        function adNorm(v) {
            return String(v || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
                .replace(/\b(street|st|road|rd|avenue|ave|barangay|brgy|purok|subdivision|subd|village)\b\.?/g, ' ')
                .replace(/[^a-z0-9]+/g, ' ').trim();
        }
        function adMatchOption(sel, name) {
            const t = adNorm(name); if (!t) return null;
            const opts = [...sel.options].filter(o => o.value);
            return opts.find(o => adNorm(o.value) === t) || opts.find(o => { const a = adNorm(o.value); return a && (a.includes(t) || t.includes(a)); }) || null;
        }
        function adReverseGeocode(lat, lng) {
            if (!(window.google && google.maps && google.maps.Geocoder)) return;
            if (!adGeocoder) adGeocoder = new google.maps.Geocoder();
            const run = ++adReverseRun;
            adGeocoder.geocode({ location: { lat: parseFloat(lat), lng: parseFloat(lng) }, region: 'PH' }, (results, status) => {
                if (run !== adReverseRun) return;
                const box = document.getElementById('adDetected');
                if (status !== 'OK' || !results || !results.length) { box.textContent = 'Pin location saved. Google could not read the address details for this spot.'; box.classList.remove('hidden'); return; }
                const values = {};
                results.forEach(r => (r.address_components || []).forEach(c => (c.types || []).forEach(t => { if (!values[t]) values[t] = c.long_name; })));
                adSuppressRecalc = true;
                const changed = [];
                const st = adMatchOption(adEl('StreetName'), values.route);
                if (st && adEl('StreetName').value !== st.value) { adEl('StreetName').value = st.value; changed.push('Street'); }
                const areaName = [values.sublocality_level_2, values.neighborhood, values.sublocality_level_1, values.sublocality].find(v => adMatchOption(adEl('AreaName'), v));
                const ar = areaName ? adMatchOption(adEl('AreaName'), areaName) : null;
                if (ar && adEl('AreaName').value !== ar.value) { adEl('AreaName').value = ar.value; changed.push('Subdivision/Purok'); }
                if (values.street_number && !adEl('HouseNumber').value.trim()) { adEl('HouseNumber').value = values.street_number; changed.push('House No.'); }
                adSuppressRecalc = false;
                box.innerHTML = '<span class="text-slate-400 uppercase tracking-wider">Detected at pin:</span> ' + esc(results[0].formatted_address || '') +
                    (changed.length ? ' <span class="text-emerald-600">· Updated ' + esc(changed.join(', ')) + '</span>' : (values.route && !st ? ' <span class="text-amber-600">· Street "' + esc(values.route) + '" is not in Manage Area</span>' : ''));
                box.classList.remove('hidden');
                if (changed.length) { adScheduleAddressCheck(); adOnEdit(); }
            });
        }
        function adInitMap(tries = 0) {
            const el = document.getElementById('adMap');
            if (adMap) return;
            const lat = parseFloat(adEl('Latitude').value), lng = parseFloat(adEl('Longitude').value);
            const hasPin = isFinite(lat) && isFinite(lng) && !(lat === 0 && lng === 0);
            const center = hasPin ? { lat, lng } : trDefaultCenter;
            if (window.google && google.maps) {
                adMap = new google.maps.Map(el, { center, zoom: hasPin ? 18 : 15, mapTypeControl: true, mapTypeControlOptions: { mapTypeIds: ['roadmap', 'satellite'] }, streetViewControl: false, fullscreenControl: true });
                adMap.addListener('click', e => adSetPin(e.latLng.lat(), e.latLng.lng(), false, true));
            } else if (window.L) {
                adMap = L.map(el).setView([center.lat, center.lng], hasPin ? 18 : 15);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(adMap);
                adMap.on('click', e => adSetPin(e.latlng.lat, e.latlng.lng, false, true));
                setTimeout(() => adMap.invalidateSize(), 50);
            } else if (tries < 40) {
                return setTimeout(() => adInitMap(tries + 1), 250);   // Google Maps script still loading
            } else {
                el.innerHTML = '<div style="height:100%;display:flex;align-items:center;justify-content:center;padding:16px;text-align:center;color:#64748b;font:600 12px Arial">Map unavailable.</div>';
                return;
            }
            if (hasPin) { const keep = [adEl('Latitude').value, adEl('Longitude').value]; adSetPin(lat, lng, false); adEl('Latitude').value = keep[0]; adEl('Longitude').value = keep[1]; }
        }
        function adPickOnMap() {
            const el = document.getElementById('adMap');
            el.classList.toggle('h-72'); el.style.height = el.classList.contains('h-72') ? '' : '60vh';
            if (adIsGoogle()) google.maps.event.trigger(adMap, 'resize'); else if (adMap && adMap.invalidateSize) setTimeout(() => adMap.invalidateSize(), 50);
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        function adUseMyLocation() {
            if (!navigator.geolocation) return hhNotice('warning', 'Location Unavailable', 'Location is not supported by this browser. Tap the map to pin the location instead.');
            navigator.geolocation.getCurrentPosition(
                pos => { adInitMap(); adSetPin(pos.coords.latitude, pos.coords.longitude, true, true); },
                () => hhNotice('warning', 'Location Unavailable', 'Unable to get your current location. Tap the map to pin the location instead.'),
                { enableHighAccuracy: true, timeout: 10000 }
            );
        }
        function adRecalculateLocation(silent) {
            if (!adIsGoogle()) return silent ? null : hhNotice('warning', 'Map Not Ready', 'Google Maps is not ready. Tap the map to pin the location.');
            if (!adGeocoder) adGeocoder = new google.maps.Geocoder();
            const f = adFields();
            const q = [f.HouseNumber, f.BuildingName, f.StreetName, f.AreaName, f.BarangayName, f.CityMunicipalityName, f.ProvinceName, f.RegionName, 'Philippines'].filter(Boolean).join(', ');
            adGeocoder.geocode({ address: q, region: 'PH' }, (results, status) => {
                if (status === 'OK' && results && results[0]) { const loc = results[0].geometry.location; adSetPin(loc.lat(), loc.lng(), true, false); document.getElementById('adDetected').classList.add('hidden'); }
                else if (!silent) hhNotice('warning', 'Location Not Found', 'Google could not locate this address. Try selecting a street or picking the exact spot on the map.');
            });
        }
        // Address → pin: re-locate the pin whenever the address changes (not while the pin is filling the address).
        function adScheduleRecalc() {
            clearTimeout(adRecalcTimer);
            if (adSuppressRecalc || !adIsGoogle() || !adEl('StreetName').value) return;
            adRecalcTimer = setTimeout(() => adRecalculateLocation(true), 800);
        }
        async function adLoadLocal(action, barangayCode, selId, placeholder, current) {
            const el = document.getElementById(selId);
            el.disabled = true; el.innerHTML = '<option value="">Loading...</option>';
            try {
                const r = await fetch(TR_ADDRESS_API + '?action=' + action + '&' + new URLSearchParams({ barangay: barangayCode }), { credentials: 'same-origin' });
                const j = await r.json(); if (!j.success) throw new Error(j.message || 'Request failed');
                const rows = j.data || [];
                el.innerHTML = '<option value="">' + placeholder + '</option>';
                rows.forEach(x => { const o = document.createElement('option'); o.value = action === 'areas' ? x.area_name : x.street_name; o.textContent = o.value; o.dataset.type = x.area_type || ''; el.appendChild(o); });
                el.disabled = rows.length === 0;
            } catch (e) { el.innerHTML = '<option value="">No records available</option>'; console.error(e); }
            // Keep the saved value even when it is no longer in Manage Area (legacy address).
            if (current) {
                if (![...el.options].some(o => o.value === current)) { const o = document.createElement('option'); o.value = current; o.textContent = current; o.dataset.type = ADDR_ORIG.AreaType || ''; el.appendChild(o); }
                el.value = current; el.disabled = false;
            }
        }
        async function adLoadProfile() {
            const note = document.getElementById('ad_default_address_note');
            try {
                const r = await fetch(TR_ADDRESS_API + '?action=profile', { credentials: 'same-origin' });
                const j = await r.json(); const p = j.data || {};
                const set = (id, v) => { adEl(id).value = v || ''; };
                set('RegionName', p.region_name || ADDR_ORIG.RegionName); set('ProvinceName', p.province_name || ADDR_ORIG.ProvinceName);
                set('CityMunicipalityName', p.municipality_name || ADDR_ORIG.CityMunicipalityName); set('BarangayName', p.barangay_name || ADDR_ORIG.BarangayName);
                set('PSGCRegionCode', p.psgc_region_code || ADDR_ORIG.PSGCRegionCode); set('PSGCProvinceCode', p.psgc_province_code || ADDR_ORIG.PSGCProvinceCode);
                set('PSGCMunicipalityCode', p.psgc_municipality_code || ADDR_ORIG.PSGCMunicipalityCode); set('PSGCBarangayCode', p.psgc_barangay_code || ADDR_ORIG.PSGCBarangayCode);
                set('ZipCode', p.zip_code || ADDR_ORIG.ZipCode);
                const code = p.psgc_barangay_code || ADDR_ORIG.PSGCBarangayCode;
                if (!code) {
                    note.className = 'text-[10px] font-bold text-amber-700 bg-amber-50 border border-amber-200 rounded-2xl px-4 py-3';
                    note.innerHTML = 'Default barangay address is not configured yet. Open <strong>Manage Area</strong> in Residents and save the barangay profile first.';
                } else {
                    await adLoadLocal('streets', code, 'adStreetName', 'Select street', ADDR_ORIG.StreetName);
                    await adLoadLocal('areas', code, 'adAreaName', 'Select area', ADDR_ORIG.AreaName);
                    note.className = 'text-[10px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-2xl px-4 py-3';
                    note.innerHTML = 'Default address: <strong>' + esc([p.region_name, p.province_name, p.municipality_name, p.barangay_name].filter(Boolean).join(' · ')) + '</strong>. Staff/Admin only needs to enter the house number, building, street and subdivision/sitio/purok.';
                }
            } catch (e) {
                console.error(e);
                note.className = 'text-[10px] font-bold text-rose-700 bg-rose-50 border border-rose-100 rounded-2xl px-4 py-3';
                note.textContent = 'Unable to load the default barangay address.';
            }
            adProfileLoaded = true;
            updateDirtyUi();
        }
        function adScheduleAddressCheck() { clearTimeout(adCheckTimer); adCheckTimer = setTimeout(adCheckAddress, 400); }
        async function adCheckAddress() {
            const f = adFields(), box = document.getElementById('adMatchNotice');
            adMatch = null; box.classList.add('hidden');
            if (!f.HouseNumber || !f.StreetName) return null;
            const chk = document.getElementById('adChecking'); chk.classList.remove('hidden');
            try {
                const r = await fetch('../backend/household_address_match.php?' + new URLSearchParams({ house_no: f.HouseNumber, street: f.StreetName, exclude_id: currentHeadId || 0 }), { headers: { 'Accept': 'application/json' } });
                const j = await r.json();
                if (j.success && j.match && Number(j.match.survey_id) !== Number(SURVEY_ID)) {
                    adMatch = j.match;
                    document.getElementById('adMatchText').textContent = `Household ${adMatch.household_id} · Head: ${adMatch.head_name} · ${adMatch.address || ''}. Moving this household here would create a duplicate household.`;
                    box.classList.remove('hidden');
                }
            } catch (e) { console.error(e); } finally { chk.classList.add('hidden'); }
            return adMatch;
        }
        function adRenderPending() {
            const box = document.getElementById('adPendingBox');
            box.classList.toggle('hidden', !pendingAddress);
            document.getElementById('adPendingBadge').classList.toggle('hidden', !pendingAddress);
            document.getElementById('adUndoBtn').classList.toggle('hidden', !pendingAddress);
            if (pendingAddress) box.innerHTML = '<strong>New address:</strong> ' + esc(pendingAddress.label) + (pendingAddress.Latitude ? ' · pin ' + esc(pendingAddress.Latitude) + ', ' + esc(pendingAddress.Longitude) : '') + '<br>It will be applied to the Head and every member when you click <strong>Save Changes</strong>.';
            updateDirtyUi();
        }
        async function adSaveAddress() {
            const f = adFields();
            if (!f.HouseNumber) return hhNotice('warning', 'Address Required', 'House/Lot/Unit Number is required.');
            if (!f.StreetName) return hhNotice('warning', 'Address Required', 'Please select the Street.');
            if (adSame(f, ADDR_ORIG)) { pendingAddress = null; adRenderPending(); return hhNotice('warning', 'No Address Change', 'This is already the household address.'); }
            await adCheckAddress();
            if (adMatch) return hhNotice('error', 'Existing Household at This Address', `There is an existing household at this address (${adMatch.household_id}, Head: ${adMatch.head_name}). Enter a different address to avoid a duplicate household.`);
            showConfirmDialog({
                title: 'Change Household Address?',
                message: 'Changing this household address will also update the address of all members in this household. Continue?',
                iconClass: 'bg-amber-50', iconName: 'edit_location_alt', okLabel: 'Continue',
                onConfirm: () => { pendingAddress = f; adRenderPending(); }
            });
        }
        function adUndo() {
            pendingAddress = null;
            ['HouseNumber', 'BuildingName', 'Latitude', 'Longitude'].forEach(k => { adEl(k).value = ADDR_ORIG[k] || ''; });
            adEl('StreetName').value = ADDR_ORIG.StreetName || ''; adEl('AreaName').value = ADDR_ORIG.AreaName || '';
            const lat = parseFloat(ADDR_ORIG.Latitude), lng = parseFloat(ADDR_ORIG.Longitude);
            if (isFinite(lat) && isFinite(lng)) adSetPin(lat, lng, true);
            document.getElementById('adMatchNotice').classList.add('hidden');
            adRenderPending();
        }

        /* ================= Change Household Head ================= */
        let hdPicked = null, hdTimer = null;
        function hdMode() { return document.querySelector('input[name="hdMode"]:checked')?.value || ''; }
        function hdModeChanged() {
            const m = hdMode();
            document.getElementById('hdMemberWrap').classList.toggle('hidden', m !== 'member');
            document.getElementById('hdResidentWrap').classList.toggle('hidden', m !== 'resident');
        }
        function hdFromAnotherHousehold(x) { return !!(Number(x.isHead) === 1 || x.familyHeadId || x.householdId); }
        function hdRenderPicked() {
            const box = document.getElementById('hdResidentPicked'), notice = document.getElementById('hdResidentNotice');
            if (!hdPicked) { box.innerHTML = ''; notice.classList.add('hidden'); return; }
            box.innerHTML = `<div class="rounded-2xl border border-indigo-100 bg-indigo-50 px-4 py-3 text-xs">
                <div class="font-black text-indigo-700">${esc(hdPicked.fullName)}</div>
                <div class="font-semibold text-slate-600">${esc(hdPicked.address || 'No address')} · ${esc(hdPicked.contact || 'No contact')}</div></div>`;
            const other = hdFromAnotherHousehold(hdPicked);
            notice.classList.toggle('hidden', !other);
            if (other) {
                let d = hdPicked.householdId ? `Current household: ${hdPicked.householdId}` + (hdPicked.householdHead ? ` (Head: ${hdPicked.householdHead})` : '') + '.' : 'Currently linked to another Household Head.';
                if (Number(hdPicked.isHead) === 1) d += ` ${hdPicked.fullName} is the Head of that household. It will have no Head and will need a new Head (it is not deleted).`;
                else d += ' They will be transferred to this household.';
                document.getElementById('hdResidentNoticeDetail').textContent = d;
            }
        }
        document.getElementById('hdResidentSearch').addEventListener('input', e => {
            clearTimeout(hdTimer);
            const q = e.target.value.trim(), box = document.getElementById('hdResidentResults');
            if (!q) { box.innerHTML = ''; return; }
            hdTimer = setTimeout(async () => {
                try {
                    const r = await fetch('../backend/search_residents_hh.php?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } });
                    let data = await r.json();
                    data = Array.isArray(data) ? data.filter(x => String(x.id) !== String(currentHeadId)) : [];
                    if (!data.length) { box.innerHTML = '<div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 text-xs font-semibold text-slate-400">No matching resident found.</div>'; return; }
                    box.innerHTML = data.map(x => `<button type="button" data-id="${esc(x.id)}" class="w-full text-left px-4 py-3 rounded-2xl border border-slate-200 bg-white hover:border-indigo-200 hover:bg-indigo-50/40">
                        <p class="text-sm font-black text-slate-700">${esc(x.fullName)}</p>
                        <p class="text-[10px] text-slate-400 font-semibold mt-0.5">${esc(x.address || 'No address')} · ${esc(x.householdId ? (Number(x.isHead) === 1 ? 'Head of ' : 'Member of ') + x.householdId : 'No household')}</p></button>`).join('');
                    box.querySelectorAll('button').forEach(btn => btn.addEventListener('click', () => {
                        const item = data.find(v => String(v.id) === btn.dataset.id); if (!item) return;
                        if (selected.has(String(item.id)) && selected.get(String(item.id)).saved) {
                            box.innerHTML = '<div class="rounded-2xl border border-amber-100 bg-amber-50 px-4 py-3 text-xs font-semibold text-amber-700">This resident is already a member of this household. Choose "Existing Household Member" instead.</div>';
                            return;
                        }
                        hdPicked = item; box.innerHTML = ''; e.target.value = ''; hdRenderPicked();
                    }));
                } catch (err) { console.error(err); box.innerHTML = '<div class="text-xs text-rose-600 font-semibold">Unable to search residents right now.</div>'; }
            }, 200);
        });
        function hdRenderPending() {
            const box = document.getElementById('hdPendingBox');
            box.classList.toggle('hidden', !pendingHead);
            document.getElementById('hdPendingBadge').classList.toggle('hidden', !pendingHead);
            document.getElementById('hdUndoBtn').classList.toggle('hidden', !pendingHead);
            if (pendingHead) {
                box.innerHTML = `<strong>New Household Head:</strong> ${esc(pendingHead.name)}` + (pendingHead.detail ? ` <span class="text-amber-700">(${esc(pendingHead.detail)})</span>` : '') +
                    (IS_HEADLESS ? '' : `<br>${esc(CURRENT_HEAD_NAME)} becomes a Member.`) + '<br>Set each member\'s relationship below. Applied when you click <strong>Save Changes</strong>.';
            }
            renderMembers();
        }
        function relSelectHtml(id, value) {
            const known = value && REL_OPTIONS.includes(value) && value !== 'Other';
            const sel = known ? value : (value ? 'Other' : '');
            return `<select class="addr-select" style="background:#f1f5f9" data-rel-id="${esc(id)}" onchange="hdRelChanged(this)">
                    <option value="">-- Select Relationship --</option>${REL_OPTIONS.map(o => `<option value="${esc(o)}"${o === sel ? ' selected' : ''}>${esc(o)}</option>`).join('')}</select>
                <input type="text" maxlength="100" data-rel-other="${esc(id)}" placeholder="Specify Relationship" value="${sel === 'Other' ? esc(value) : ''}"
                    oninput="hdRelChanged(this)" class="${sel === 'Other' ? '' : 'hidden '}mt-2 w-full bg-slate-100 border-none rounded-xl py-3 px-4 text-sm font-bold">`;
        }
        function hdRelChanged(el) {
            const id = el.dataset.relId || el.dataset.relOther;
            const sel = document.querySelector(`select[data-rel-id="${CSS.escape(id)}"]`);
            const other = document.querySelector(`input[data-rel-other="${CSS.escape(id)}"]`);
            other.classList.toggle('hidden', sel.value !== 'Other');
            pendingHead.rels[id] = sel.value === 'Other' ? other.value.trim() : sel.value;
            if (sel.value === 'Other' && el === sel) other.focus();
            updateDirtyUi();
        }
        function hdRenderRelEditor() {
            const box = document.getElementById('hdRelEditor');
            box.classList.toggle('hidden', !pendingHead);
            if (!pendingHead) return;
            const rows = finalMembers();
            rows.forEach(x => { if (!(String(x.id) in pendingHead.rels)) pendingHead.rels[String(x.id)] = x.relationship || ''; });
            document.getElementById('hdRelRows').innerHTML = rows.length ? rows.map(x => `
                <div class="grid md:grid-cols-[1fr_280px] gap-3 items-start px-4 py-3">
                    <div>
                        <p class="text-sm font-black text-slate-700">${esc(x.fullName)}</p>
                        <p class="text-[10px] font-bold text-slate-400">${x.formerHead ? 'Previous Head → Member' : 'Member'}${x.saved ? '' : ' · new'}</p>
                    </div>
                    <div>${relSelectHtml(x.id, pendingHead.rels[String(x.id)])}</div>
                </div>`).join('') : '<p class="px-4 py-4 text-xs font-semibold text-slate-400">No other members.</p>';
        }
        function hdApply() {
            const mode = hdMode();
            if (!mode) return hhNotice('warning', 'Select New Household Head', 'Choose Existing Household Member or New Resident.');
            let target = null;
            if (mode === 'member') {
                const sel = document.getElementById('hdMemberSelect');
                if (!sel.value) return hhNotice('warning', 'Member Required', 'Select the household member who will become the new Head.');
                const m = selected.get(String(sel.value));
                target = { id: sel.value, name: m ? m.fullName : sel.selectedOptions[0].textContent.split(' — ')[0], other: false, detail: 'current member' };
            } else {
                if (!hdPicked) return hhNotice('warning', 'Resident Required', 'Search and select the resident who will become the new Head.');
                target = { id: String(hdPicked.id), name: hdPicked.fullName, other: hdFromAnotherHousehold(hdPicked),
                    detail: hdPicked.householdId ? (Number(hdPicked.isHead) === 1 ? `Head of ${hdPicked.householdId}; that household will have no Head` : `transferred from ${hdPicked.householdId}`) : 'resident not in a household',
                    resident: hdPicked };
            }
            const stage = () => {
                // Relationships start from the current values; the previous Head must be chosen.
                const rels = {};
                [...selected.values()].forEach(x => { if (String(x.id) !== String(target.id)) rels[String(x.id)] = x.relationship || ''; });
                if (!IS_HEADLESS) rels[String(currentHeadId)] = '';
                pendingHead = Object.assign(target, { mode, rels });
                hdRenderPending();
            };
            if (mode === 'resident' && target.other) {
                showConfirmDialog({
                    title: 'Resident From Another Household',
                    message: 'This resident is currently from another household. If you continue, their household/address assignment will be changed.\n\n' + document.getElementById('hdResidentNoticeDetail').textContent,
                    iconClass: 'bg-amber-50', iconName: 'swap_horiz', okLabel: 'Continue', onConfirm: stage
                });
            } else stage();
        }
        function hdUndo() { pendingHead = null; hdPicked = null; hdRenderPicked(); document.querySelectorAll('input[name="hdMode"]').forEach(r => { r.checked = false; }); hdModeChanged(); hdRenderPending(); }

        /* ================= Add member (relationship dropdown) ================= */
        let amItem = null;
        function amOpen(item, notice) {
            amItem = item;
            document.getElementById('amWho').textContent = `${item.fullName} → Household ${HOUSEHOLD_ID} (Head: ${CURRENT_HEAD_NAME})`;
            const n = document.getElementById('amNotice'); n.classList.toggle('hidden', !notice); n.textContent = notice || '';
            // Relationship is to THIS household's Head, so it is always chosen here.
            document.getElementById('amRel').value = '';
            document.getElementById('amRelOther').value = ''; relOtherToggle('amRel');
            document.getElementById('amError').classList.add('hidden');
            const m = document.getElementById('addMemberModal'); m.classList.remove('hidden'); m.classList.add('flex');
        }
        function amClose() { const m = document.getElementById('addMemberModal'); m.classList.add('hidden'); m.classList.remove('flex'); amItem = null; }
        function amConfirm() {
            if (!amItem) return;
            const rel = relValue('amRel'), err = document.getElementById('amError');
            if (!rel) { err.textContent = 'Select the relationship to the Head' + (document.getElementById('amRel').value === 'Other' ? ' and specify it.' : '.'); err.classList.remove('hidden'); return; }
            selected.set(String(amItem.id), { id: String(amItem.id), fullName: amItem.fullName, address: amItem.address || '', contact: amItem.contact || '', relationship: rel, saved: false });
            amClose(); renderMembers();
        }
        async function searchResidents(q) {
            const box = document.getElementById('memberResults');
            if (!q) { box.innerHTML = ''; return; }
            try {
                const r = await fetch('../backend/search_residents_hh.php?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } });
                if (!r.ok) throw new Error('Search failed');
                const data = await r.json();
                if (!Array.isArray(data) || !data.length) { box.innerHTML = '<div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 text-xs font-semibold text-slate-400">No matching resident found.</div>'; return; }
                box.innerHTML = data.map(x => `
                    <button type="button" data-id="${esc(x.id)}" class="w-full text-left px-4 py-3 rounded-2xl border border-slate-200 bg-white hover:border-indigo-200 hover:bg-indigo-50/40 transition-all shadow-sm">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-lg">person</span></div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-black text-slate-700 truncate">${esc(x.fullName)}</p>
                                <p class="text-[10px] text-slate-400 font-semibold truncate mt-0.5">${esc(x.address || 'No address')} · ${esc(x.contact || 'No contact')}${x.householdId ? ' · ' + esc((Number(x.isHead) === 1 ? 'Head of ' : 'Member of ') + x.householdId) : ''}</p>
                            </div>
                            <span class="material-symbols-outlined text-slate-300">add_circle</span>
                        </div>
                    </button>`).join('');
                box.querySelectorAll('button').forEach(btn => btn.addEventListener('click', () => {
                    const item = data.find(v => String(v.id) === String(btn.dataset.id)); if (!item) return;
                    box.innerHTML = ''; document.getElementById('memberSearch').value = '';
                    if (IS_HEADLESS && !pendingHead) return hhNotice('warning', 'No Household Head', 'Assign a new Household Head first, then add members.');
                    if (String(item.id) === String(currentHeadId) || (pendingHead && String(pendingHead.id) === String(item.id))) return hhNotice('warning', 'Household Head', 'The Household Head cannot also be added as a member.');
                    if (selected.has(String(item.id))) return hhNotice('warning', 'Already a Member', `${item.fullName} is already in this household.`);
                    if (Number(item.isHead) === 1 && Number(item.ownMemberCount) > 0) return hhNotice('error', 'Head of Another Household', `${item.fullName} is the Head of ${item.householdId || 'another household'} that still has members. Change that household's Head first, or use Change Household Head → New Resident.`);
                    let notice = '';
                    if (Number(item.isHead) === 1) notice = `${item.fullName} is the Head of ${item.householdId || 'a one-person household'}. That one-person household will be set inactive.`;
                    else if (item.familyHeadId && String(item.familyHeadId) !== String(currentHeadId)) notice = `This resident is currently from another household${item.householdId ? ' (' + item.householdId + ')' : ''}. If you continue, their household/address assignment will be changed.`;
                    amOpen(item, notice);
                }));
            } catch (e) {
                console.error(e);
                box.innerHTML = '<div class="rounded-2xl border border-rose-100 bg-rose-50 px-4 py-4 text-xs font-semibold text-rose-600">Unable to search residents right now.</div>';
            }
        }
        let timer = null;
        document.getElementById('memberSearch').addEventListener('input', e => {
            clearTimeout(timer);
            const q = e.target.value.trim();
            if (!q) { document.getElementById('memberResults').innerHTML = ''; return; }
            timer = setTimeout(() => searchResidents(q), 180);
        });

        /* ================= Save Changes → Confirm Changes summary → save ================= */
        function finalMembers() {
            // Members after the pending Head change: new Head leaves the list, previous Head joins it.
            const list = [...selected.values()].filter(x => !(pendingHead && String(pendingHead.id) === String(x.id)))
                .map(x => Object.assign({}, x, pendingHead && (String(x.id) in pendingHead.rels) ? { relationship: pendingHead.rels[String(x.id)] } : {}));
            if (pendingHead && !IS_HEADLESS) list.unshift({ id: currentHeadId, fullName: CURRENT_HEAD_NAME, relationship: pendingHead.rels[String(currentHeadId)] || '', saved: true, formerHead: true });
            return list;
        }
        function openSummary() {
            if (adEdited()) return hhNotice('warning', 'Address Not Confirmed', 'You edited the household address. Click Save Address to confirm it, or Undo Address Change.');
            if (IS_HEADLESS && !pendingHead) return hhNotice('warning', 'Household Head Required', 'This household has no Head. Select the new Household Head in Change Household Head first.');
            if (!isDirty()) return hhNotice('warning', 'No Changes', 'There are no changes to save.');
            if (pendingHead) {
                const missing = finalMembers().filter(x => !String(x.relationship || '').trim());
                if (missing.length) {
                    document.getElementById('hdRelEditor').scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return hhNotice('warning', 'Relationship Required', 'Set the relationship to the new Head for: ' + missing.map(x => x.fullName).join(', ') + '.');
                }
            }
            const items = [];
            const li = (label, text) => items.push(`<li class="rounded-xl bg-slate-50 border border-slate-100 px-4 py-3"><span class="block text-[9px] font-black uppercase tracking-widest text-slate-400">${esc(label)}</span><span class="font-bold">${text}</span></li>`);
            if (pendingAddress) li('Household Address', `${esc(ADDR_ORIG.label || '—')} <span class="text-indigo-500">→</span> ${esc(pendingAddress.label)}`);
            if (pendingHead) {
                li('Household Head', `${esc(CURRENT_HEAD_NAME)} <span class="text-indigo-500">→</span> ${esc(pendingHead.name)}` + (pendingHead.detail ? ` <span class="text-slate-400">(${esc(pendingHead.detail)})</span>` : ''));
                if (!IS_HEADLESS) li('Previous Head', `${esc(CURRENT_HEAD_NAME)} → Member (${esc(pendingHead.rels[String(currentHeadId)])})`);
                const relLines = finalMembers().filter(x => !x.formerHead).map(x => `${esc(x.fullName)}: ${esc(x.relationship)}`);
                if (relLines.length) li('Relationship to New Head', relLines.join('<br>'));
            }
            [...selected.values()].filter(x => !x.saved).forEach(x => li('Member Added', `${esc(x.fullName)} (${esc(x.relationship)})`));
            [...savedMemberIds].filter(id => !selected.has(id)).forEach(id => li('Member Removed', 'Resident #' + esc(id)));
            if (pendingAddress || pendingHead) li('Addresses', 'The Head and all members will use the household address' + (pendingAddress ? ' and map pin.' : '.'));
            document.getElementById('summaryList').innerHTML = items.join('');
            const m = document.getElementById('summaryModal'); m.classList.remove('hidden'); m.classList.add('flex');
        }
        function closeSummary() { const m = document.getElementById('summaryModal'); m.classList.add('hidden'); m.classList.remove('flex'); }
        async function saveAll() {
            const btn = document.getElementById('summaryConfirmBtn'); btn.disabled = true;
            const fd = new FormData();
            fd.append('csrf_token', CSRF); fd.append('action', 'save_all'); fd.append('survey_id', SURVEY_ID);
            fd.append('expected_head_id', currentHeadId || 0);
            if (pendingHead) { fd.append('head_mode', pendingHead.mode); fd.append('new_head_id', pendingHead.id); fd.append('old_head_relationship', pendingHead.rels[String(currentHeadId)] || ''); }
            if (pendingAddress) {
                fd.append('address_changed', '1');
                [...AD_FIELDS, 'AreaType', 'Purok'].forEach(f => fd.append(f, pendingAddress[f] || ''));
            }
            finalMembers().forEach(x => { fd.append('member_resident_id[]', x.id); fd.append('member_relationship[' + x.id + ']', x.relationship || 'Member'); });
            try {
                const r = await fetch('../backend/household_actions.php', { method: 'POST', body: fd });
                const j = await r.json();
                if (!j.success) throw new Error(j.error || 'Unable to save the household changes.');
                allowLeave = true;
                location.href = 'edit_household.php?id=' + encodeURIComponent(j.head_id) + '&saved=1';
            } catch (e) {
                closeSummary();
                hhNotice('error', 'Save Failed', e.message + '\nNo changes were saved.');
            } finally { btn.disabled = false; }
        }
        document.getElementById('householdForm').addEventListener('submit', e => { e.preventDefault(); openSummary(); });

        /* ================= Unsaved changes guard ================= */
        window.addEventListener('beforeunload', e => { if (!allowLeave && isDirty()) { e.preventDefault(); e.returnValue = ''; } });
        document.addEventListener('click', e => {
            const a = e.target.closest('a[href]');
            if (!a || allowLeave || !isDirty()) return;
            const href = a.getAttribute('href') || '';
            if (a.target === '_blank' || href.startsWith('#') || href.startsWith('javascript:')) return;
            e.preventDefault(); e.stopPropagation();
            pendingNav = a.href;
            const m = document.getElementById('leaveModal'); m.classList.remove('hidden'); m.classList.add('flex');
        }, true);
        function stayOnPage() { pendingNav = null; const m = document.getElementById('leaveModal'); m.classList.add('hidden'); m.classList.remove('flex'); }
        function leaveWithoutSaving() { allowLeave = true; location.href = pendingNav || 'households.php'; }

        /* Initial render */
        renderMembers();
        adLoadProfile();
        adInitMap();
        if (new URLSearchParams(location.search).get('saved') === '1') {
            history.replaceState({}, '', location.pathname + '?id=' + encodeURIComponent(currentHeadId));
            hhNotice('success', 'Changes Saved', 'The household changes were saved.');
        }
    </script>
</body>

</html>
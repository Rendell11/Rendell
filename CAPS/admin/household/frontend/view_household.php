<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/households.php';
require_permission($pdo, 'households', 'read');
require_once __DIR__ . '/../backend/household_common.php';

$id = (int) ($_GET['id'] ?? 0);
$phid = (int) ($_GET['phid'] ?? 0);

// Accept both current ?id= and legacy ?phid= links. A phid may be a
// household SurveyID or a resident/head ID depending on the older page.
if ($id <= 0 && $phid > 0) {
    $resolve = $pdo->prepare('SELECT ResidentID FROM household_survey WHERE SurveyID = ? LIMIT 1');
    $resolve->execute([$phid]);
    $resolved = $resolve->fetchColumn();

    if (!$resolved) {
        $resolve = $pdo->prepare('SELECT ResidentID FROM residents WHERE ResidentID = ? AND IsHead = 1 LIMIT 1');
        $resolve->execute([$phid]);
        $resolved = $resolve->fetchColumn();
    }
    $id = (int) ($resolved ?: 0);
}

if ($id <= 0 && (int) ($_GET['sid'] ?? 0) <= 0) {
    header('Location: households.php?error=Household+not+found');
    exit;
}

// ?sid= is the household (SurveyID). ?id= is the head ResidentID used by the
// master list, edit page and resident links. Resolve ?id= as a head first so
// a ResidentID that happens to equal another SurveyID never opens the wrong
// household; only then fall back to treating it as a legacy SurveyID.
$sid = (int) ($_GET['sid'] ?? 0);
$hh = false;
$whoRow = null;
$loadHousehold = static function (PDO $pdo, int $surveyId) {
    $stmt = $pdo->prepare(
        'SELECT r.*, hs.*
         FROM household_survey hs
         LEFT JOIN residents r ON r.ResidentID = hs.ResidentID
         WHERE hs.SurveyID = ?
         LIMIT 1'
    );
    $stmt->execute([$surveyId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
};

if ($sid > 0) {
    $hh = $loadHousehold($pdo, $sid);
} elseif ($id > 0) {
    $headSurveyId = hh_ensure_household_record($pdo, $id);
    if ($headSurveyId <= 0) {
        $who = $pdo->prepare('SELECT FamilyHeadID FROM residents WHERE ResidentID = ? LIMIT 1');
        $who->execute([$id]);
        $whoRow = $who->fetch(PDO::FETCH_ASSOC);
        if ($whoRow && !empty($whoRow['FamilyHeadID'])) {
            // A member's ResidentID opens the household they belong to.
            $headSurveyId = hh_ensure_household_record($pdo, (int) $whoRow['FamilyHeadID']);
        }
    }
    if ($headSurveyId > 0) {
        $hh = $loadHousehold($pdo, $headSurveyId);
    } elseif (!$whoRow) {
        // Legacy links that passed a SurveyID as ?id=.
        $hh = $loadHousehold($pdo, $id);
    }
}

if (!$hh) {
    header('Location: households.php?error=Household+not+found');
    exit;
}

// Same data as the Household Record report (household_record_report.php).
extract(hh_household_view_data($pdo, $hh));

if (!function_exists('hh_view_escape')) {
    function hh_view_escape($value): string
    {
        return htmlspecialchars(
            (string) ($value ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}


?>
<!doctype html>
<html <?= $theme_attrs['html']; ?>>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Household - Barangay Biñang 2nd </title>

    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>

    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=DM+Mono:wght@400;500&display=swap"
        rel="stylesheet">

    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
        rel="stylesheet">

    <?php if (!empty($googleKey)): ?>
        <script>
            window.initCapsViewHouseholdMap = function () {
                window.__capsViewHouseholdMapsReady = true;
                if (typeof window.renderViewHouseholdMap === 'function') {
                    window.renderViewHouseholdMap();
                }
            };
        </script>
        <script async defer
            src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($googleKey, ENT_QUOTES, 'UTF-8') ?>&callback=initCapsViewHouseholdMap"></script>
    <?php endif; ?>

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
            --sidebar-w: 288px;
            --nav-h: 64px;
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

        .member-head,
        .member-row {
            display: grid;
            grid-template-columns:
                52px minmax(220px, 1.25fr) minmax(260px, 1.7fr) minmax(130px, .8fr) 150px;
            align-items: center;
            column-gap: 18px;
        }

        .member-head {
            padding: 12px 18px;
            background: #f8fafc;
            border-bottom: 1px solid #e7edf5;
        }

        .member-row {
            min-height: 76px;
            padding: 13px 18px;
            border-bottom: 1px solid #edf1f6;
            transition: background .15s ease;
        }

        .member-row:last-child {
            border-bottom: 0;
        }

        .member-table {
            border: 1px solid #e7edf5;
            border-radius: 18px;
            overflow: hidden;
            background: #fff;
        }

        @media (max-width: 1024px) {
            .main-wrapper {
                margin-left: 0;
                width: 100%;
            }
        }

        @media (max-width: 767px) {
            .member-head {
                display: none;
            }

            .member-row {
                grid-template-columns: 42px 1fr;
                column-gap: 12px;
                row-gap: 7px;
                padding: 16px;
            }

            .member-row .member-number {
                grid-row: 1 / span 3;
            }

            .member-row .member-person,
            .member-row .member-address,
            .member-row .member-contact,
            .member-row .member-relation {
                grid-column: 2;
            }
        }

        html.dark body {
            background: #0f172a;
            color: #e2e8f0;
        }

        html.dark .bg-white {
            background: #1e293b !important;
        }

        html.dark .bg-slate-50,
        html.dark .bg-slate-50\/50 {
            background: #0f172a !important;
        }

        html.dark .bg-slate-100 {
            background: #1e293b !important;
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

        html.dark .border-slate-100,
        html.dark .border-slate-200 {
            border-color: #334155 !important;
        }
    </style>
</head>

<body <?= $theme_attrs['body']; ?>>

    <div class="flex min-h-screen">

        <?php include __DIR__ . '/../../sidebar.php'; ?>

        <div class="flex-1 flex flex-col min-w-0 main-wrapper">

            <?php include __DIR__ . '/../../header.php'; ?>

            <main class="p-4 md:p-6 lg:p-8 space-y-6">

                <!-- CAPS Hero Band — same structure as New Household -->
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
                                View Household
                            </h1>

                            <p class="text-white/65 text-sm mt-2 font-medium max-w-2xl">
                                View household information and residents linked to this household.
                            </p>

                        </div>

                        <div class="flex flex-wrap items-center gap-3">

                            <span
                                class="font-mono text-xs font-black px-4 py-3 rounded-xl bg-white/10 border border-white/20">
                                <?= hh_view_escape($hh['HouseholdID'] ?? '—'); ?>
                            </span>

                            <a href="households.php"
                                class="inline-flex items-center justify-center gap-2 shrink-0 bg-white/10 hover:bg-white/20 border border-white/20 text-white px-5 py-3 rounded-xl font-black text-xs uppercase tracking-wider transition-all">
                                <span class="material-symbols-outlined text-lg">arrow_back</span>
                                Back to Households
                            </a>

                        </div>

                    </div>
                </section>


                <!-- Bottom Actions -->
                <section class="bg-white rounded-[28px] border border-slate-100 shadow-sm px-6 md:px-8 py-5">

                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">

                        <div class="flex items-center gap-2 text-xs font-semibold text-slate-400">
                            <span class="material-symbols-outlined text-lg text-emerald-500">
                                verified_user
                            </span>
                            Resident profiles are preserved by Household Management.
                        </div>

                        <div class="flex items-center justify-end gap-3">

                            <a href="#survey"
                                onclick="document.getElementById('survey').scrollIntoView({behavior:'smooth'});return false;"
                                class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-indigo-50 text-indigo-700 font-black text-xs uppercase tracking-wider"><span
                                    class="material-symbols-outlined text-lg">assignment</span>View Survey</a>

                            <button type="button" onclick="openRecordReport('pdf')"
                                class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 font-black text-xs uppercase tracking-wider transition-all">
                                <span class="material-symbols-outlined text-lg">picture_as_pdf</span>
                                Save PDF
                            </button>

                            <button type="button" onclick="openRecordReport('print')"
                                class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 font-black text-xs uppercase tracking-wider transition-all">
                                <span class="material-symbols-outlined text-lg">print</span>
                                Print
                            </button>

                            <?php if ($status === 'active'): ?>
                                <a href="edit_household.php?id=<?= $headId; ?>"
                                    class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-primary text-white font-black text-xs uppercase tracking-wider shadow-md hover:opacity-90 transition-all">
                                    <span class="material-symbols-outlined text-lg">edit</span>
                                    Edit
                                </a>
                            <?php endif; ?>

                        </div>
                    </div>
                </section>



                <?php include __DIR__ . '/partials/household_info_sections.php'; ?>

                <!-- Household Members -->
                <section class="bg-white rounded-[28px] border border-slate-100 shadow-sm overflow-hidden">

                    <div class="px-6 md:px-8 py-5 border-b border-slate-50 flex items-center justify-between gap-4">

                        <div class="flex items-center gap-3">
                            <div
                                class="w-10 h-10 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined">groups</span>
                            </div>
                            <div>
                                <h2 class="text-sm font-black text-slate-800">
                                    Household Members
                                </h2>
                                <p class="text-[10px] text-slate-400 font-semibold mt-0.5">
                                    Current residents linked to this household.
                                </p>
                            </div>
                        </div>

                        <span
                            class="px-3 py-1 bg-sky-50 text-sky-600 text-[9px] font-black uppercase tracking-wider rounded-full">
                            <?= count($members) + 1; ?> <?= count($members) + 1 === 1 ? 'Member' : 'Members'; ?>
                        </span>

                    </div>

                    <div class="p-6 md:p-8">

                        <div class="member-table">

                            <div class="member-head text-[10px] font-black uppercase tracking-widest text-slate-400">
                                <div>#</div>
                                <div>Resident</div>
                                <div>Address</div>
                                <div>Contact</div>
                                <div>Relationship</div>
                            </div>

                            <!-- Head -->
                            <div class="member-row bg-indigo-50/40">

                                <div class="member-number">
                                    <span class="text-[10px] font-black uppercase text-indigo-500">
                                        Head
                                    </span>
                                </div>

                                <div class="member-person min-w-0">
                                    <div class="font-black text-sm text-slate-800">
                                        <?= hh_view_escape($name); ?>
                                    </div>
                                    <div class="text-[10px] text-slate-400 font-semibold mt-0.5">
                                        Resident #<?= (int) $headId; ?>
                                    </div>
                                </div>

                                <div class="member-address text-xs text-slate-500">
                                    <?= hh_view_escape($address ?: 'No address recorded'); ?>
                                </div>

                                <div class="member-contact text-xs font-bold text-slate-700">
                                    <?= hh_view_escape($hh['ContactNumber'] ?? '—'); ?>
                                </div>

                                <div class="member-relation">
                                    <span
                                        class="px-2.5 py-1 rounded-full bg-indigo-50 text-indigo-600 text-[9px] font-black">
                                        Household Head
                                    </span>
                                </div>

                            </div>

                            <?php foreach ($members as $index => $member): ?>
                                <div class="member-row">

                                    <div class="member-number">
                                        <span
                                            class="w-7 h-7 rounded-lg bg-slate-100 text-slate-500 inline-flex items-center justify-center text-[10px] font-black">
                                            <?= $index + 1; ?>
                                        </span>
                                    </div>

                                    <div class="member-person min-w-0">
                                        <div class="font-black text-sm text-slate-800">
                                            <?= hh_view_escape(hh_full_name($member)); ?>
                                        </div>
                                        <div class="text-[10px] text-slate-400 font-semibold mt-0.5">
                                            Resident #<?= (int) ($member['ResidentID'] ?? 0); ?>
                                        </div>
                                    </div>

                                    <div class="member-address text-xs text-slate-500">
                                        <?= hh_view_escape(hh_address($member) ?: 'No address recorded'); ?>
                                    </div>

                                    <div class="member-contact text-xs font-bold text-slate-700">
                                        <?= hh_view_escape($member['ContactNumber'] ?? '—'); ?>
                                    </div>

                                    <div class="member-relation">
                                        <span class="text-xs font-bold text-slate-600">
                                            <?= hh_view_escape($member['RelationshipToHead'] ?? 'Member'); ?>
                                        </span>
                                    </div>

                                </div>
                            <?php endforeach; ?>

                        </div>

                        <?php if (!$members): ?>
                            <div
                                class="mt-4 rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-5 py-8 text-center">
                                <span class="material-symbols-outlined text-3xl text-slate-300">
                                    person_off
                                </span>
                                <p class="text-xs font-bold text-slate-400 mt-2">
                                    No other household members are currently linked.
                                </p>
                            </div>
                        <?php endif; ?>

                    </div>
                </section>


                <!-- Timeline -->
                <?php if ($history): ?>
                    <section class="bg-white rounded-[28px] border border-slate-100 shadow-sm overflow-hidden">

                        <div class="px-6 md:px-8 py-5 border-b border-slate-50">
                            <div class="flex items-center gap-3">
                                <div
                                    class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                                    <span class="material-symbols-outlined">history</span>
                                </div>
                                <div>
                                    <h2 class="text-sm font-black text-slate-800">
                                        Household Timeline
                                    </h2>
                                    <p class="text-[10px] text-slate-400 font-semibold mt-0.5">
                                        Recorded household changes and activities.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="p-6 md:p-8 space-y-4">

                            <?php foreach ($history as $item): ?>
                                <div class="flex gap-4">
                                    <div
                                        class="w-9 h-9 rounded-full bg-sky-50 text-sky-600 flex items-center justify-center shrink-0">
                                        <span class="material-symbols-outlined text-lg">history</span>
                                    </div>
                                    <div>
                                        <div class="font-black text-sm">
                                            <?= hh_view_escape($item['ActionType'] ?? 'Household Update'); ?>
                                        </div>
                                        <div class="text-sm text-slate-500 mt-1">
                                            <?= hh_view_escape($item['Description'] ?? ''); ?>
                                        </div>
                                        <div class="text-[11px] text-slate-400 mt-1">
                                            <?= hh_view_escape($item['CreatedAt'] ?? ''); ?>
                                            • By:
                                            <?= hh_view_escape($item['ActorName'] ?? 'System'); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                        </div>
                    </section>
                <?php endif; ?>


                <section class="bg-white rounded-[28px] border border-slate-100 shadow-sm overflow-hidden">
                    <div class="px-6 md:px-8 py-5 border-b border-slate-50 flex items-center justify-between gap-4">
                        <div>
                            <h2 class="text-sm font-black text-slate-800">Household Location</h2>
                            <p class="text-[10px] text-slate-400 font-semibold mt-0.5">
                                <?php if ($hhLocation): ?>
                                    Saved map pin from Resident Profiling (<?= hh_view_escape($hhLocation['source']); ?>) ·
                                    <span class="font-mono"><?= hh_view_escape(number_format($hhLocation['lat'], 7) . ', ' . number_format($hhLocation['lng'], 7)); ?></span>
                                <?php else: ?>
                                    Pinned household location.
                                <?php endif; ?>
                            </p>
                        </div>
                        <?php if ($hhLocation): ?>
                            <div class="flex items-center gap-2">
                                <a target="_blank" rel="noopener"
                                    href="https://www.google.com/maps?q=<?= rawurlencode($hhLocation['lat'] . ',' . $hhLocation['lng']); ?>"
                                    class="px-4 py-2 rounded-xl bg-slate-100 text-slate-600 text-[10px] font-black uppercase">Open in Google Maps</a>
                                <button id="satToggle" type="button"
                                    class="px-4 py-2 rounded-xl bg-slate-100 text-slate-600 text-[10px] font-black uppercase">Satellite View</button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($hhLocation): ?>
                        <div id="householdMap" style="height:320px; width:100%;"></div>
                        <script>
                            // Defined before Google Maps can call back, so the saved pin always renders.
                            window.renderViewHouseholdMap = function () {
                                const el = document.getElementById('householdMap');
                                if (!el || el.dataset.rendered === '1') return;
                                if (!window.google || !google.maps) {
                                    el.innerHTML = '<div style="height:100%;display:flex;align-items:center;justify-content:center;padding:24px;text-align:center;color:#64748b;font:600 13px Arial">Google Maps is not available. Use “Open in Google Maps” to see the pinned location.</div>';
                                    return;
                                }
                                el.dataset.rendered = '1';
                                const pos = { lat: <?= json_encode($hhLocation['lat']); ?>, lng: <?= json_encode($hhLocation['lng']); ?> };
                                const map = new google.maps.Map(el, {
                                    center: pos, zoom: 18, mapTypeId: 'roadmap', mapTypeControl: true,
                                    mapTypeControlOptions: { style: google.maps.MapTypeControlStyle.DEFAULT, mapTypeIds: ['roadmap', 'satellite'] },
                                    streetViewControl: true, fullscreenControl: true, zoomControl: true
                                });
                                new google.maps.Marker({ map: map, position: pos, title: 'Household Location' });
                                const toggle = document.getElementById('satToggle');
                                if (toggle) toggle.onclick = function () {
                                    const satellite = map.getMapTypeId() === 'satellite';
                                    map.setMapTypeId(satellite ? 'roadmap' : 'satellite');
                                    this.textContent = satellite ? 'Satellite View' : 'Map View';
                                };
                            };
                            if (window.__capsViewHouseholdMapsReady || (window.google && window.google.maps)) {
                                window.renderViewHouseholdMap();
                            }
                            <?php if (empty($googleKey)): ?>window.renderViewHouseholdMap();<?php endif; ?>
                            // Maps script failed to load (network / key): show the fallback message.
                            setTimeout(function () { if (!(window.google && window.google.maps)) window.renderViewHouseholdMap(); }, 8000);
                        </script>
                    <?php else: ?>
                        <div class="h-48 flex flex-col items-center justify-center gap-1 text-sm font-semibold text-slate-400">
                            No household GPS location recorded.
                            <span class="text-[11px]">Pin the location in Resident Profiling or Edit Household → Change Household Address.</span>
                        </div>
                    <?php endif; ?>
                </section>
                <section id="survey" class="bg-white rounded-[28px] border border-slate-100 shadow-sm overflow-hidden">
                    <div class="px-6 md:px-8 py-5 border-b border-slate-50">
                        <h2 class="text-sm font-black text-slate-800">Household Survey</h2>
                        <p class="text-[10px] text-slate-400 font-semibold mt-0.5">View-only household survey record
                            currently stored for this household.</p>
                    </div>
                    <?php if (!$surveyAnswers): ?>
                        <div class="p-6 md:p-8">
                            <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-5 py-8 text-center text-xs font-bold text-slate-400">
                                No survey response available yet.
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="p-6 md:p-8 grid md:grid-cols-2 gap-4 text-xs">
                            <?php foreach ($surveyAnswers as $label => $value): ?>
                                <div class="rounded-2xl bg-slate-50 border border-slate-100 p-4">
                                    <div class="text-[9px] font-black uppercase tracking-widest text-slate-400">
                                        <?= hh_view_escape($label) ?>
                                    </div>
                                    <div class="mt-1 font-bold text-slate-700 whitespace-pre-line">
                                        <?= hh_view_escape($value) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </main>
        </div>
    </div>

    <!-- Save PDF / Print: include the household survey? -->
    <div id="reportModal" class="fixed inset-0 z-[999] hidden bg-slate-900/70 items-center justify-center p-4" onclick="if (event.target === this) closeRecordReport()">
        <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-md p-8" role="dialog" aria-modal="true" aria-labelledby="rmTitle">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 id="rmTitle" class="text-2xl font-black text-slate-900 tracking-tight">Save as PDF</h3>
                    <p class="text-[11px] font-black text-indigo-600 uppercase tracking-widest mt-1">Household Record</p>
                    <p class="text-xs font-bold text-slate-400 mt-1"><?= hh_view_escape(($hh['HouseholdID'] ?? '') . ' · ' . $name); ?></p>
                </div>
                <button type="button" onclick="closeRecordReport()" class="p-1 text-slate-400 hover:text-slate-700" aria-label="Close">
                    <span class="material-symbols-outlined">close</span></button>
            </div>
            <p class="text-sm font-bold text-slate-700 mt-6 mb-3">Include the household survey in the report?</p>
            <div class="space-y-3">
                <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 cursor-pointer hover:border-indigo-300">
                    <input type="radio" name="rmSurvey" value="0" class="mt-1 text-indigo-600 focus:ring-indigo-500" checked>
                    <span><span class="block text-sm font-black text-slate-800">No Survey – Household record only</span>
                        <span class="block text-[11px] font-semibold text-slate-400">Head, household information, socio-economic profile, members, location and timeline.</span></span>
                </label>
                <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 cursor-pointer hover:border-indigo-300">
                    <input type="radio" name="rmSurvey" value="1" class="mt-1 text-indigo-600 focus:ring-indigo-500">
                    <span><span class="block text-sm font-black text-slate-800">Include Survey – Household record and survey answers</span>
                        <span class="block text-[11px] font-semibold text-slate-400"><?= $surveyAnswers ? 'Adds the household survey answers.' : 'No survey response recorded yet — the report will say so.'; ?></span></span>
                </label>
            </div>
            <div class="flex justify-end gap-3 mt-8">
                <button type="button" onclick="closeRecordReport()" class="px-6 py-3 rounded-2xl border border-slate-200 text-xs font-black uppercase tracking-wider text-slate-500 hover:text-slate-700">Cancel</button>
                <button type="button" onclick="continueRecordReport()" class="px-6 py-3 rounded-2xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-black uppercase tracking-wider shadow-lg">Continue</button>
            </div>
        </div>
    </div>
    <script>
        // Save PDF / Print — shared CAPS report layout (household_record_report.php).
        let _recordMode = 'pdf';
        function openRecordReport(mode) {
            _recordMode = mode;
            document.getElementById('rmTitle').textContent = mode === 'pdf' ? 'Save as PDF' : 'Print';
            document.querySelector('input[name="rmSurvey"][value="0"]').checked = true;
            const m = document.getElementById('reportModal');
            m.classList.remove('hidden'); m.classList.add('flex');
        }
        function closeRecordReport() {
            const m = document.getElementById('reportModal');
            m.classList.add('hidden'); m.classList.remove('flex');
        }
        function continueRecordReport() {
            const survey = document.querySelector('input[name="rmSurvey"]:checked')?.value === '1' ? '1' : '0';
            const q = new URLSearchParams({ mode: _recordMode, sid: <?= json_encode((string) $surveyId); ?>, survey });
            closeRecordReport();
            window.open('../backend/household_record_report.php?' + q.toString(), '_blank');
        }
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeRecordReport(); });
    </script>

</body>

</html>
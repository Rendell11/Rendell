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

$surveyId = (int) ($hh['SurveyID'] ?? 0);
$headId = (int) ($hh['ResidentID'] ?? 0);

$hh['HouseholdID'] = hh_normalize_household_id(
    $hh['HouseholdID'] ?? null,
    !empty($hh['CreatedAt']) ? (int) date('Y', strtotime((string) $hh['CreatedAt'])) : (int) date('Y'),
    $surveyId > 0 ? $surveyId : $headId
);

$memberStmt = $pdo->prepare(
    'SELECT *
     FROM residents
     WHERE FamilyHeadID = ?
       AND (IsDeceased = 0 OR IsDeceased IS NULL)
     ORDER BY LastName, FirstName, ResidentID'
);
$memberStmt->execute([$headId]);
$members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);

$isInactive = strtolower((string) ($hh['status'] ?? 'active')) === 'inactive' || (int) ($hh['is_removed'] ?? 0) === 1;
if ($isInactive && $surveyId > 0) {
    // Residents were unlinked on deactivation; show members as recorded.
    $snap = $pdo->prepare('SELECT * FROM household_survey_members WHERE SurveyID = ? ORDER BY MemberNumber');
    $snap->execute([$surveyId]);
    $members = [];
    foreach ($snap->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $members[] = [
            'ResidentID' => $row['ResidentID'],
            'head_name' => $row['full_name'],
            'address' => (string) ($hh['address'] ?? ''),
            'ContactNumber' => null,
            'RelationshipToHead' => $row['relationship'] ?: 'Member',
            'TotalHouseholdIncome' => $row['monthly_income'] ?? 0,
        ];
    }
}

$history = [];

if ($surveyId > 0) {
    try {
        $historyStmt = $pdo->prepare(
            'SELECT *
             FROM household_history
             WHERE SurveyID = ?
             ORDER BY CreatedAt DESC, HistoryID DESC'
        );
        $historyStmt->execute([$surveyId]);
        $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $history = [];
    }
}

$name = hh_full_name($hh);
$address = hh_address($hh);
if ($isInactive && trim((string) ($hh['address'] ?? '')) !== '') {
    $address = (string) $hh['address'];
}
// Household-level figures use the COMBINED monthly income of the Head and every member.
$headIncome = (float) ($hh['TotalHouseholdIncome'] ?? 0);
$memberCount = count($members) + 1; // members + Head
$income = hh_combined_income($hh, $members);
$classification = hh_income_class($income);
$ses = hh_socioeconomic_status($income, $memberCount);
$withIncome = ($headIncome > 0 ? 1 : 0) + count(array_filter($members, static fn($m) => (float) ($m['TotalHouseholdIncome'] ?? 0) > 0));

$age = '—';

if (!empty($hh['BirthDate'])) {
    try {
        $age = (new DateTime($hh['BirthDate']))->diff(new DateTime())->y;
    } catch (Throwable $e) {
        $age = '—';
    }
}

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

$status = $isInactive ? 'inactive' : strtolower((string) ($hh['status'] ?? 'active'));

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

                            <a href="../backend/print_household.php?<?= $surveyId > 0 ? 'sid=' . $surveyId : 'head_id=' . $headId; ?>" target="_blank"
                                class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 font-black text-xs uppercase tracking-wider transition-all">
                                <span class="material-symbols-outlined text-lg">print</span>
                                Print
                            </a>

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



                <!-- Household Head — the Head's own personal/basic information -->
                <section class="bg-white rounded-[28px] border border-slate-100 shadow-sm overflow-hidden">
                    <div class="px-6 md:px-8 py-5 border-b border-slate-50 flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined">account_box</span>
                            </div>
                            <div>
                                <h2 class="text-sm font-black text-slate-800">Household Head</h2>
                                <p class="text-[10px] text-slate-400 font-semibold mt-0.5">Personal information from the CAPS Resident profile.</p>
                            </div>
                        </div>
                    </div>
                    <?php
                    $headFields = [
                        ['Name', $name ?: '—', 'xl:col-span-2'],
                        ['Resident ID', !empty($hh['ResidentCode']) ? $hh['ResidentCode'] : ('#' . $headId), ''],
                        ['Age', $age === '—' ? '—' : $age . ' years old', ''],
                        ['Sex', $hh['Sex'] ?? '—', ''],
                        ['Civil Status', $hh['CivilStatus'] ?? '—', ''],
                        ['Date of Birth', !empty($hh['BirthDate']) ? date('F j, Y', strtotime((string) $hh['BirthDate'])) : '—', ''],
                        ['Place of Birth', $hh['BirthPlace'] ?? '—', ''],
                        ['Contact Number', $hh['ContactNumber'] ?? '—', ''],
                        ['Email', $hh['Email'] ?? '—', 'break-all'],
                        ['Religion', $hh['Religion'] ?? '—', ''],
                        ['Nationality', $hh['Nationality'] ?? '—', ''],
                        ['Educational Attainment', $hh['EducationLevel'] ?? '—', ''],
                        ['Employment Status', (($hh['EmploymentStatus'] ?? '') === 'Other' && !empty($hh['EmploymentStatusOther'])) ? $hh['EmploymentStatusOther'] : ($hh['EmploymentStatus'] ?? '—'), ''],
                        ['Occupation', $hh['Occupation'] ?? '—', ''],
                        ["Head's Own Monthly Income", '₱' . number_format($headIncome, 2), ''],
                    ];
                    $headTags = array_filter([
                        !empty($hh['IsSenior']) ? 'Senior Citizen' : '',
                        !empty($hh['IsPWD']) ? 'PWD' : '',
                        !empty($hh['IsSoloParent']) ? 'Solo Parent' : '',
                        !empty($hh['IsVoter']) ? 'Registered Voter' : '',
                    ]);
                    ?>
                    <div class="p-6 md:p-8">
                        <div class="grid md:grid-cols-2 xl:grid-cols-4 gap-4">
                            <?php foreach ($headFields as [$label, $value, $cls]): ?>
                                <div class="p-4 rounded-2xl bg-slate-50 <?= $cls; ?>">
                                    <div class="text-[10px] font-black uppercase tracking-widest text-slate-400"><?= hh_view_escape($label); ?></div>
                                    <div class="font-bold text-sm mt-1 <?= strpos($cls, 'break-all') !== false ? 'break-all' : ''; ?>">
                                        <?= hh_view_escape(trim((string) $value) !== '' ? $value : '—'); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($headTags): ?>
                            <div class="flex flex-wrap gap-2 mt-4">
                                <?php foreach ($headTags as $tag): ?>
                                    <span class="px-3 py-1 rounded-full bg-indigo-50 text-indigo-600 text-[10px] font-black uppercase tracking-wider"><?= hh_view_escape($tag); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- Household Information — the household as a whole -->
                <section class="bg-white rounded-[28px] border border-slate-100 shadow-sm overflow-hidden">
                    <div class="px-6 md:px-8 py-5 border-b border-slate-50 flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined">home</span>
                            </div>
                            <div>
                                <h2 class="text-sm font-black text-slate-800">Household Information</h2>
                                <p class="text-[10px] text-slate-400 font-semibold mt-0.5">Household-level details based on all household members.</p>
                            </div>
                        </div>
                        <span class="px-3 py-1 <?= $status === 'inactive' ? 'bg-rose-50 text-rose-600' : 'bg-emerald-50 text-emerald-600'; ?> text-[9px] font-black uppercase tracking-wider rounded-full">
                            <?= hh_view_escape(strtoupper($status)); ?>
                        </span>
                    </div>

                    <div class="p-6 md:p-8">
                        <div class="grid md:grid-cols-2 xl:grid-cols-4 gap-4">
                            <div class="p-4 rounded-2xl bg-slate-50">
                                <div class="text-[10px] font-black uppercase tracking-widest text-slate-400">Household ID</div>
                                <div class="font-mono font-black text-sm mt-1"><?= hh_view_escape($hh['HouseholdID'] ?? '—'); ?></div>
                            </div>
                            <div class="p-4 rounded-2xl bg-slate-50">
                                <div class="text-[10px] font-black uppercase tracking-widest text-slate-400">Number of Household Members</div>
                                <div class="font-black text-sm mt-1"><?= (int) $memberCount; ?> <span class="text-[11px] font-semibold text-slate-400">(Head + <?= count($members); ?> member<?= count($members) === 1 ? '' : 's'; ?>)</span></div>
                            </div>
                            <div class="p-4 rounded-2xl bg-slate-50">
                                <div class="text-[10px] font-black uppercase tracking-widest text-slate-400">Registered</div>
                                <div class="font-bold text-sm mt-1"><?php $regDate = $hh['DateCreated'] ?? $hh['CreatedAt'] ?? null; ?><?= hh_view_escape($regDate ? date('F j, Y', strtotime((string) $regDate)) : '—'); ?></div>
                            </div>
                            <div class="p-4 rounded-2xl bg-slate-50">
                                <div class="text-[10px] font-black uppercase tracking-widest text-slate-400">Location</div>
                                <div class="font-mono text-[11px] font-bold mt-1"><?= hh_view_escape(($hh['Latitude'] ?? '—') . ', ' . ($hh['Longitude'] ?? '—')); ?></div>
                            </div>
                            <div class="xl:col-span-4 p-4 rounded-2xl bg-slate-50">
                                <div class="text-[10px] font-black uppercase tracking-widest text-slate-400">Complete Address</div>
                                <div class="font-semibold text-sm mt-1"><?= hh_view_escape($address ?: 'No address recorded'); ?></div>
                            </div>
                        </div>

                        <?php if ($status === 'inactive'): ?>
                            <div class="mt-4 rounded-2xl bg-rose-50 border border-rose-100 p-4">
                                <div class="text-[10px] font-black uppercase tracking-widest text-rose-400">Inactive Household</div>
                                <div class="text-sm font-bold text-rose-700 mt-1">
                                    Inactive since
                                    <?php $inactiveSince = $hh['inactive_since'] ?? $hh['removed_at'] ?? null; ?>
                                    <?= hh_view_escape($inactiveSince ? date('F j, Y', strtotime((string) $inactiveSince)) : '—'); ?>
                                    • By: <?= hh_view_escape($hh['inactive_by'] ?? 'System'); ?>
                                </div>
                                <div class="text-xs text-rose-500 mt-1">
                                    Reason:
                                    <?= hh_view_escape((($hh['removal_reason'] ?? '') === 'Other') ? ($hh['removal_reason_other'] ?? 'Other') : ($hh['removal_reason'] ?? '—')); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Household Socio-Economic Profile (combined income) -->
                        <div class="mt-6">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="material-symbols-outlined text-indigo-500">analytics</span>
                                <h3 class="text-sm font-black text-slate-800">Household Socio-Economic Profile</h3>
                            </div>
                            <div class="grid md:grid-cols-2 xl:grid-cols-4 gap-4">
                                <div class="p-4 rounded-2xl bg-indigo-50/60">
                                    <div class="text-[10px] font-black uppercase tracking-widest text-indigo-400">Monthly Household Income</div>
                                    <div class="font-black text-lg mt-1 text-indigo-700">₱<?= number_format($income, 2); ?></div>
                                    <div class="text-[10px] font-semibold text-slate-500 mt-1">Combined income of <?= (int) $withIncome; ?> of <?= (int) $memberCount; ?> member(s) with recorded income</div>
                                </div>
                                <div class="p-4 rounded-2xl bg-indigo-50/60">
                                    <div class="text-[10px] font-black uppercase tracking-widest text-indigo-400">Income Status</div>
                                    <div class="font-black text-sm mt-1 text-indigo-700"><?= hh_view_escape($classification); ?></div>
                                    <div class="text-[10px] font-semibold text-slate-500 mt-1">Based on the combined monthly income</div>
                                </div>
                                <div class="p-4 rounded-2xl bg-indigo-600 text-white">
                                    <div class="text-[10px] font-black uppercase tracking-widest text-white/70">Socioeconomic Status</div>
                                    <div class="font-black text-lg mt-1"><?= hh_view_escape($ses['label'] ?? '—'); ?></div>
                                    <div class="text-[10px] font-semibold text-white/80 mt-1"><?= hh_view_escape($ses['range'] ?? ''); ?></div>
                                </div>
                                <div class="p-4 rounded-2xl bg-slate-50">
                                    <div class="text-[10px] font-black uppercase tracking-widest text-slate-400">Income per Member</div>
                                    <div class="font-black text-sm mt-1">₱<?= number_format((float) ($ses['per_capita'] ?? 0), 2); ?> / month</div>
                                    <div class="text-[10px] font-semibold text-slate-500 mt-1"><?= hh_view_escape(number_format((float) ($ses['ratio'] ?? 0), 2)); ?>× the poverty threshold</div>
                                </div>
                                <div class="p-4 rounded-2xl bg-slate-50">
                                    <div class="text-[10px] font-black uppercase tracking-widest text-slate-400">House Type</div>
                                    <div class="font-black text-sm mt-1"><?= hh_view_escape($hh['house_type'] ?? 'Not recorded in survey'); ?></div>
                                </div>
                                <div class="p-4 rounded-2xl bg-slate-50">
                                    <div class="text-[10px] font-black uppercase tracking-widest text-slate-400">Tenure Status</div>
                                    <div class="font-black text-sm mt-1"><?= hh_view_escape($hh['tenure_status'] ?? $hh['housing_tenure'] ?? 'Not recorded in survey'); ?></div>
                                </div>
                            </div>
                            <p class="text-[10px] text-slate-400 font-semibold mt-3 leading-relaxed">
                                Socioeconomic Status formula: (combined monthly income ₱<?= number_format($income, 2); ?> ÷ <?= (int) $memberCount; ?> member(s))
                                ÷ poverty threshold ₱<?= number_format((float) ($ses['poverty_line'] ?? 0), 2); ?> per person per month.
                                Below 1× Poor · 1–2× Low Income · 2–4× Lower Middle · 4–7× Middle · 7–12× Upper Middle · 12–20× Upper Income · 20× and above Rich (PIDS income classes).
                            </p>
                        </div>
                    </div>
                </section>

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


                <?php if (!empty($hh['Latitude']) && !empty($hh['Longitude'])): ?>
                    <section class="bg-white rounded-[28px] border border-slate-100 shadow-sm overflow-hidden">
                        <div class="px-6 md:px-8 py-5 border-b border-slate-50 flex items-center justify-between">
                            <div>
                                <h2 class="text-sm font-black text-slate-800">Household Location</h2>
                                <p class="text-[10px] text-slate-400 font-semibold mt-0.5">Pinned household location.</p>
                            </div>
                            <button id="satToggle" type="button"
                                class="px-4 py-2 rounded-xl bg-slate-100 text-slate-600 text-[10px] font-black uppercase">Satellite
                                View</button>
                        </div>
                        <div id="householdMap" style="height:320px; width:100%;"></div>
                    </section>
                    <script>
                        window.__capsViewHouseholdMapsReady = false;
                        window.renderViewHouseholdMap = function () {
                            const el = document.getElementById('householdMap');
                            if (!el) return;

                            if (!window.google || !google.maps) {
                                el.innerHTML = '<div style="height:100%;display:flex;align-items:center;justify-content:center;padding:24px;text-align:center;color:#64748b;font:600 13px Arial">Google Maps is not configured.</div>';
                                return;
                            }

                            const lat = <?= json_encode((float) $hh['Latitude']); ?>;
                            const lng = <?= json_encode((float) $hh['Longitude']); ?>;

                            const map = new google.maps.Map(el, {
                                center: { lat: lat, lng: lng },
                                zoom: 17,
                                mapTypeId: 'roadmap',
                                mapTypeControl: true,
                                mapTypeControlOptions: {
                                    style: google.maps.MapTypeControlStyle.DEFAULT,
                                    mapTypeIds: ['roadmap', 'satellite']
                                },
                                streetViewControl: true,
                                fullscreenControl: true,
                                zoomControl: true
                            });

                            new google.maps.Marker({
                                map: map,
                                position: { lat: lat, lng: lng },
                                title: 'Household Location'
                            });

                            const toggle = document.getElementById('satToggle');
                            if (toggle) {
                                toggle.onclick = function () {
                                    const satellite = map.getMapTypeId() === 'satellite';
                                    map.setMapTypeId(satellite ? 'roadmap' : 'satellite');
                                    this.textContent = satellite ? 'Satellite View' : 'Map View';
                                };
                            }
                        };

                        document.addEventListener('DOMContentLoaded', function () {
                            if (window.__capsViewHouseholdMapsReady) {
                                window.renderViewHouseholdMap();
                            }
                        });
                    </script>
                <?php else: ?>
                    <section class="bg-white rounded-[28px] border border-slate-100 shadow-sm overflow-hidden">
                        <div class="px-6 md:px-8 py-5 border-b border-slate-50">
                            <h2 class="text-sm font-black text-slate-800">Household Location</h2>
                            <p class="text-[10px] text-slate-400 font-semibold mt-0.5">Pinned household location.</p>
                        </div>
                        <div class="h-48 flex items-center justify-center text-sm font-semibold text-slate-400">No household
                            GPS location recorded.</div>
                    </section>
                <?php endif; ?>
                <section id="survey" class="bg-white rounded-[28px] border border-slate-100 shadow-sm overflow-hidden">
                    <div class="px-6 md:px-8 py-5 border-b border-slate-50">
                        <h2 class="text-sm font-black text-slate-800">Household Survey</h2>
                        <p class="text-[10px] text-slate-400 font-semibold mt-0.5">View-only household survey record
                            currently stored for this household.</p>
                    </div>
                    <?php
                    // Resident-app survey answers stored on this household_survey row.
                    $surveyKeys = [
                        'educational_background', 'is_indigenous_people', 'is_migrant_family',
                        'housing_tenure', 'housing_tenure_other', 'has_electricity', 'has_internet',
                        'waste_disposal', 'waste_disposal_other', 'water_source', 'water_source_other',
                        'toilet_facilities', 'toilet_other', 'household_gardening', 'gardening_other',
                        'pets', 'pets_other', 'prone_to_flooding', 'monitors_flood_updates', 'has_cctv',
                        'avg_sleep_hours', 'sleep_hours_other', 'poor_sleep_reasons', 'poor_sleep_reasons_other',
                        'avg_meals_per_day', 'nutrition_concerns', 'weekly_food_budget', 'food_types_purchased',
                        'children_snacks', 'food_storage', 'food_storage_other', 'health_center_visit_reason',
                        'health_center_visit_other', 'first_consulted', 'first_consulted_other',
                        'family_skipped_consult', 'skip_consult_reasons', 'skip_consult_reasons_other',
                        'has_healthcare_access', 'has_health_insurance', 'health_insurance_detail', 'has_illness',
                        'illness_detail', 'has_pregnant_member', 'pregnancy_age', 'had_prenatal', 'prenatal_provider',
                        'had_recent_birth', 'delivery_attendant', 'delivery_attendant_other', 'place_of_delivery',
                        'place_of_delivery_other', 'baby_vaccinated', 'vaccinations_received', 'all_children_enrolled',
                        'no_enrollment_reasons', 'no_enrollment_other', 'school_aged_children', 'children_enrolled',
                        'income_sources', 'income_sources_other', 'income_sufficient', 'govt_assistance',
                        'govt_assistance_other', 'in_community_org', 'community_org_detail', 'has_senior_citizen',
                        'has_pwd', 'pwd_detail', 'transport_mode', 'transport_mode_other', 'devices_at_home',
                        'devices_other', 'final_observation', 'name_of_interviewee', 'name_of_interviewer',
                        'interviewer_position', 'date_of_interview',
                    ];
                    $yesNoKeys = [
                        'is_indigenous_people', 'is_migrant_family', 'has_electricity', 'has_internet',
                        'prone_to_flooding', 'has_cctv', 'nutrition_concerns', 'family_skipped_consult',
                        'has_healthcare_access', 'has_health_insurance', 'has_illness', 'has_pregnant_member',
                        'had_prenatal', 'had_recent_birth', 'baby_vaccinated', 'all_children_enrolled',
                        'income_sufficient', 'in_community_org', 'has_senior_citizen', 'has_pwd',
                    ];
                    $surveyAnswers = [];
                    foreach ($surveyKeys as $key) {
                        if (!array_key_exists($key, $hh) || $hh[$key] === null || trim((string) $hh[$key]) === '') {
                            continue;
                        }
                        $value = (string) $hh[$key];
                        if (in_array($key, $yesNoKeys, true)) {
                            $value = ((int) $value === 1) ? 'Yes' : 'No';
                        } elseif ($key === 'weekly_food_budget') {
                            $value = '₱' . number_format((float) $value, 2);
                        }
                        $label = ucwords(str_replace('_', ' ', preg_replace('/_other$/', ' (other)', $key)));
                        $surveyAnswers[$label] = $value;
                    }
                    // These two flags default to 0 on every row, so they alone do not mean a survey was answered.
                    if (count(array_diff(array_keys($surveyAnswers), ['Is Indigenous People', 'Is Migrant Family'])) === 0) {
                        $surveyAnswers = [];
                    }
                    ?>
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

</body>

</html>
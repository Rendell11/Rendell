<?php
require_once __DIR__.'/../../db.php';
require_once __DIR__.'/../../auth_check.php';
require_once __DIR__.'/../backend/staffbpso_common.php';
staffbpso_migrate_schema($pdo);

$current_page = 'Staff and BPSO';

// Weekly view: Monday -> Sunday. ?week=YYYY-MM-DD may point to any date in the week.
$weekAnchor = $_GET['week'] ?? date('Y-m-d');
try {
    $anchor = new DateTime($weekAnchor);
} catch (Throwable $e) {
    $anchor = new DateTime();
}
$dayOfWeek = (int)$anchor->format('N'); // 1 = Monday
$anchor->modify('-'.($dayOfWeek - 1).' days');
$weekStart = $anchor->format('Y-m-d');
$weekEndDate = (clone $anchor)->modify('+6 days');
$weekEnd = $weekEndDate->format('Y-m-d');
$prevWeek = (clone $anchor)->modify('-7 days')->format('Y-m-d');
$nextWeek = (clone $anchor)->modify('+7 days')->format('Y-m-d');
$today = date('Y-m-d');

$days = [];
for ($i = 0; $i < 7; $i++) {
    $d = (clone $anchor)->modify("+$i days");
    $days[$d->format('Y-m-d')] = $d;
}

try {
    $tanods = $pdo->query("SELECT s.ResidentID, s.Name, r.ContactNumber
        FROM staff s
        LEFT JOIN residents r ON r.ResidentID = s.ResidentID
        WHERE LOWER(COALESCE(s.Position,'')) IN ('tanod','barangay tanod','barangay tanod chief','bpso')
          AND (s.EffectiveEnd IS NULL OR s.EffectiveEnd > CURDATE())
          AND COALESCE(s.IsBlocked, 0) = 0
        ORDER BY s.Name")->fetchAll();

    // Include one day before the visible week so overnight duty can be detected on Monday.
    $scheduleQueryStart = (clone $anchor)->modify('-1 day')->format('Y-m-d');
    // NOTE: ResidentID is not unique in `staff` (a resident can have more than one
    // staff/employee record). Joining directly on ResidentID would match a single
    // schedule row against every matching staff row and render it once per match,
    // which is what caused schedules to appear doubled/tripled on a single day even
    // when the shift wasn't overnight. GROUP BY ts.id collapses back to one row per
    // schedule while still letting us pull a display name.
    $st = $pdo->prepare('SELECT ts.*, MIN(s.Name) AS Name
        FROM tanod_schedules ts
        JOIN staff s ON s.ResidentID = ts.ResidentID
        WHERE ts.schedule_date BETWEEN ? AND ?
        GROUP BY ts.id
        ORDER BY ts.schedule_date, ts.shift_start, Name');
    $st->execute([$scheduleQueryStart, $weekEnd]);
    $schedules = $st->fetchAll();
} catch (Throwable $e) {
    $tanods = [];
    $schedules = [];
    $db_error = $e->getMessage();
}

$schedulesByDate = [];
$occupiedDatesByResident = [];
$carryOverByDate = [];
foreach ($schedules as $schedule) {
    $startDate = new DateTime($schedule['schedule_date']);
    $startTime = (string)$schedule['shift_start'];
    $endTime = (string)$schedule['shift_end'];
    $isOvernight = $endTime <= $startTime;
    $endDate = clone $startDate;
    if ($isOvernight) $endDate->modify('+1 day');

    $startKey = $startDate->format('Y-m-d');
    $endKey = $endDate->format('Y-m-d');
    $schedule['_is_overnight'] = $isOvernight;
    $schedule['_end_date'] = $endKey;

    if ($startDate >= $anchor && $startDate <= $weekEndDate) {
        $schedulesByDate[$startKey][] = $schedule;
    }
    if ($isOvernight && $endDate >= $anchor && $endDate <= $weekEndDate) {
        $carryOverByDate[$endKey][] = $schedule;
    }

    // Only the schedule's own start date blocks that personnel from being picked again
    // in the "Add Duty" dropdown. An overnight/graveyard shift no longer marks the
    // following date as occupied here — the backend (tanod_action.php) still catches
    // a real time conflict (a new shift starting before the graveyard shift ends) when
    // the form is submitted; this list only pre-filters the obvious same-day duplicate.
    $occupiedDatesByResident[$startKey][(int)$schedule['ResidentID']] = true;
}

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function timeLabel($time): string {
    if (!$time) return '—';
    return date('g:i A', strtotime($time));
}

function dayLabel(DateTime $date): string {
    return $date->format('D');
}

function fullDateLabel(DateTime $date): string {
    return $date->format('M j');
}

$weekLabel = $anchor->format('M j').'–'.$weekEndDate->format('M j, Y');
if ($anchor->format('Y') !== $weekEndDate->format('Y')) {
    $weekLabel = $anchor->format('M j, Y').'–'.$weekEndDate->format('M j, Y');
}
$msg = $_GET['msg'] ?? '';

// ---- Day timeline (0:00–24:00) so overlapping duties are visible side by side ----
// Each day gets its own shifts plus the after-midnight part of last night's
// overnight shifts. Overlapping blocks are placed in separate lanes.
const TL_HOUR_PX = 26;               // 1 hour = 26px (24h = 624px timeline)
require_once __DIR__.'/../backend/staffbpso_common.php'; // duty_status_meta()
function tl_minutes(string $t): int { return (int)substr($t, 0, 2) * 60 + (int)substr($t, 3, 2); }
function tl_color(int $residentId): array {
    // Soft, distinct colours per personnel: [background, border, text]
    $palette = [['#e0f2fe','#7dd3fc','#075985'], ['#fef3c7','#fcd34d','#92400e'], ['#ede9fe','#c4b5fd','#5b21b6'],
                ['#dcfce7','#86efac','#166534'], ['#ffe4e6','#fda4af','#9f1239'], ['#e0e7ff','#a5b4fc','#3730a3'],
                ['#fae8ff','#f0abfc','#86198f'], ['#ccfbf1','#5eead4','#115e59']];
    return $palette[$residentId % count($palette)];
}
function tl_layout(array $own, array $carry): array {
    $segs = [];
    foreach ($carry as $c) $segs[] = ['s' => $c, 'from' => 0, 'to' => max(1, tl_minutes($c['shift_end'])), 'carry' => true];
    foreach ($own as $s) {
        $from = tl_minutes($s['shift_start']);
        $to = !empty($s['_is_overnight']) ? 1440 : tl_minutes($s['shift_end']);
        $segs[] = ['s' => $s, 'from' => $from, 'to' => max($from + 15, $to), 'carry' => false];
    }
    usort($segs, function($a, $b) { return [$a['from'], $b['to']] <=> [$b['from'], $a['to']]; });
    // Greedy lanes inside clusters of overlapping segments.
    $clusters = []; $cur = []; $curEnd = -1;
    foreach ($segs as $seg) {
        if ($cur && $seg['from'] >= $curEnd) { $clusters[] = $cur; $cur = []; $curEnd = -1; }
        $cur[] = $seg; $curEnd = max($curEnd, $seg['to']);
    }
    if ($cur) $clusters[] = $cur;
    $out = [];
    foreach ($clusters as $cl) {
        $laneEnds = [];
        foreach ($cl as &$seg) {
            $lane = null;
            foreach ($laneEnds as $i => $end) if ($end <= $seg['from']) { $lane = $i; break; }
            if ($lane === null) $lane = count($laneEnds);
            $laneEnds[$lane] = $seg['to']; $seg['lane'] = $lane;
        }
        unset($seg);
        foreach ($cl as $i => $seg) {
            $seg['lanes'] = count($laneEnds);
            // Names of the other personnel this block overlaps with.
            $seg['with'] = [];
            foreach ($cl as $j => $o) if ($j !== $i && $o['from'] < $seg['to'] && $o['to'] > $seg['from']) $seg['with'][] = $o['s']['Name'];
            $out[] = $seg;
        }
    }
    return $out;
}
?>

<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BPSO Duty &amp; Schedule — CAPS</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0,-50..200" rel="stylesheet">
    <?php
    require_once __DIR__.'/../../theme_loader.php';
    $_theme_head_loaded = true;
    require_once __DIR__.'/../../theme_head.php';
    ?>
    <style>
        :root { --sidebar-w: 288px; }
        body { font-family:'Plus Jakarta Sans',sans-serif; background:var(--page-bg,#eef2fb); -webkit-font-smoothing:antialiased; }
        .main-wrapper { margin-left:var(--sidebar-w); width:calc(100% - var(--sidebar-w)); }
        @media (max-width:1024px) { .main-wrapper { margin-left:0; width:100%; } }
        .section-title { font-size:.65rem; font-weight:800; letter-spacing:.12em; text-transform:uppercase; color:#94a3b8; }
        .hero-band { background:linear-gradient(135deg,var(--accent-700) 0%,var(--accent-600) 50%,var(--accent-700) 100%); }
        .day-column { min-height:290px; } .duty-block { transition:box-shadow .15s ease; } .duty-block:hover { z-index:30; box-shadow:0 6px 16px -6px rgba(15,23,42,.35) !important; }
        .schedule-card { transition:transform .15s ease,box-shadow .15s ease; }
        .schedule-card:hover { transform:translateY(-1px); box-shadow:0 6px 18px rgba(15,23,42,.08); }
        .modal-backdrop { background:rgba(15,23,42,.52); backdrop-filter:blur(3px); }
        html.dark body { background:#0f172a; color:#e2e8f0; }
        html.dark .bg-white { background:#1e293b !important; }
        html.dark .bg-slate-50, html.dark .bg-slate-50\/50 { background:#0f172a !important; }
        html.dark .bg-slate-100 { background:#1e293b !important; }
        html.dark .text-slate-900 { color:#f1f5f9 !important; }
        html.dark .text-slate-800 { color:#e2e8f0 !important; }
        html.dark .text-slate-700 { color:#cbd5e1 !important; }
        html.dark .text-slate-600 { color:#94a3b8 !important; }
        html.dark .text-slate-500 { color:#64748b !important; }
        html.dark .border-slate-100, html.dark .border-slate-200 { border-color:#334155 !important; }
    </style>
</head>
<body <?php echo $theme_attrs['body']; ?>>
<div class="flex min-h-screen">
    <?php require __DIR__.'/../../sidebar.php'; ?>

    <div class="flex-1 min-w-0 main-wrapper">
        <?php require __DIR__.'/../../header.php'; ?>

        <main class="p-4 md:p-6 lg:p-8 space-y-6">
            <section class="hero-band rounded-2xl p-6 md:p-8 text-white relative overflow-hidden">
                <div class="absolute -right-12 -top-12 w-64 h-64 opacity-10 rounded-full blur-3xl pointer-events-none" style="background:var(--accent-400);"></div>
                <div class="absolute left-1/3 bottom-0 w-48 h-48 opacity-10 rounded-full blur-2xl pointer-events-none" style="background:var(--accent-300);"></div>
                <div class="relative z-10 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5">
                    <div>
                        <div class="flex items-center gap-2 text-white/60 text-[10px] font-black uppercase tracking-[0.18em] mb-2">
                            <span class="material-symbols-outlined text-base">shield_person</span>
                            BPSO Management
                        </div>
                        <h1 class="text-2xl md:text-3xl font-black tracking-tight leading-none">Duty &amp; Schedule</h1>
                        <p class="text-white/65 text-sm mt-2 font-medium max-w-2xl">Plan BPSO / Tanod duty by week. Select an available date to add a schedule.</p>
                    </div>
                    <a href="/CAPS/admin/staffbpso/frontend/staff.php" class="inline-flex items-center justify-center gap-2 shrink-0 bg-white/10 hover:bg-white/20 border border-white/20 text-white px-5 py-3 rounded-xl font-black text-xs uppercase tracking-wider transition-all">
                        <span class="material-symbols-outlined text-lg">arrow_back</span> Back to Staff and BPSO
                    </a>
                </div>
            </section>

            <?php if ($msg): ?>
                <?php $isErr = ($_GET['status'] ?? '') === 'error'; ?>
                <div class="rounded-xl border <?php echo $isErr ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700'; ?> px-4 py-3 text-sm font-semibold flex items-center gap-2">
                    <span class="material-symbols-outlined text-base"><?php echo $isErr ? 'error' : 'check_circle'; ?></span>
                    <?php echo h($msg); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($db_error)): ?>
                <div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-700 px-4 py-3 text-sm font-semibold">Unable to load the schedule: <?php echo h($db_error); ?></div>
            <?php endif; ?>

            <!-- Week controls -->
            <section class="bg-white rounded-2xl p-4 md:p-5 shadow-sm border border-slate-200/60">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div>
                        <p class="section-title">Weekly Duty Planner</p>
                        <h2 class="text-xl font-black mt-1 text-slate-900"><?php echo h($weekLabel); ?></h2>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="?week=<?php echo h($today); ?>" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 text-[10px] font-black uppercase tracking-wider">Today</a>
                        <a href="?week=<?php echo h($prevWeek); ?>" class="inline-flex items-center justify-center gap-1 px-3 py-2.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 transition" aria-label="Previous week">
                            <span class="material-symbols-outlined text-base">chevron_left</span>
                        </a>
                        <a href="?week=<?php echo h($nextWeek); ?>" class="inline-flex items-center justify-center gap-1 px-3 py-2.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 transition" aria-label="Next week">
                            <span class="material-symbols-outlined text-base">chevron_right</span>
                        </a>
                    </div>
                </div>
            </section>

            <!-- Weekly calendar -->
            <section class="bg-white rounded-2xl overflow-hidden shadow-sm border border-slate-200/60">
                <div class="p-4 md:p-5 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <p class="section-title">Schedule Overview</p>
                        <p class="text-xs text-slate-400 mt-1">Each column is one day. Add multiple BPSO/Tanod shifts to the same day.</p>
                    </div>
                    <button type="button" id="openAddFromTop" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-[10px] font-black uppercase tracking-wider">
                        <span class="material-symbols-outlined text-base">add</span> Add Duty Schedule
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <div class="min-w-[1260px] grid grid-cols-7 divide-x divide-slate-100">
                        <?php foreach ($days as $dateKey => $day): ?>
                            <?php $isToday = $dateKey === $today; $daySchedules = $schedulesByDate[$dateKey] ?? []; ?>
                            <div class="day-column bg-white <?php echo $isToday ? 'bg-blue-50/30' : ''; ?>">
                                <div class="px-4 py-4 border-b border-slate-100 <?php echo $isToday ? 'bg-blue-50/60' : 'bg-slate-50/70'; ?>">
                                    <div class="flex items-center justify-between gap-2">
                                        <div>
                                            <div class="text-[10px] font-black uppercase tracking-widest <?php echo $isToday ? 'text-blue-600' : 'text-slate-400'; ?>"><?php echo h(dayLabel($day)); ?></div>
                                            <div class="text-base font-black text-slate-900 mt-0.5"><?php echo h(fullDateLabel($day)); ?></div>
                                        </div>
                                        <?php if ($isToday): ?><span class="text-[8px] font-black uppercase tracking-wider px-2 py-1 rounded-full bg-blue-100 text-blue-700">Today</span><?php endif; ?>
                                    </div>
                                </div>

                                <div class="p-2.5 space-y-2">
                                    <?php $segs = tl_layout($daySchedules, $carryOverByDate[$dateKey] ?? []);
                                          $dayLocked = $dateKey < $today; ?>
                                    <div class="flex items-center justify-between h-4 px-0.5">
                                        <span class="text-[9px] font-bold text-slate-400"><?php echo count($daySchedules) ? count($daySchedules).' dut'.(count($daySchedules) > 1 ? 'ies' : 'y') : ''; ?></span>
                                        <?php if ($dayLocked): ?>
                                            <span class="inline-flex items-center gap-0.5 text-[8px] font-black uppercase tracking-wider text-slate-300"><span class="material-symbols-outlined text-[11px]">lock</span>Locked</span>
                                        <?php endif; ?>
                                    </div>
                                    <!-- 24-hour timeline: position = time of day, side-by-side = overlapping duty -->
                                    <div class="timeline relative rounded-xl border border-slate-100 <?php echo $dayLocked ? 'bg-slate-100/60' : 'bg-slate-50/60'; ?>" style="height:<?php echo 24 * TL_HOUR_PX; ?>px">
                                        <?php for ($hr = 0; $hr < 24; $hr++): // one row per hour ?>
                                            <div class="absolute right-0 border-t <?php echo $hr % 6 === 0 ? 'border-slate-300/70' : 'border-slate-200/60'; ?>" style="left:26px;top:<?php echo $hr * TL_HOUR_PX; ?>px"></div>
                                            <span class="absolute left-1 text-[8px] font-semibold leading-none <?php echo $hr % 6 === 0 ? 'text-slate-500' : 'text-slate-300'; ?>" style="top:<?php echo $hr * TL_HOUR_PX + 2; ?>px"><?php echo $hr === 0 ? '12a' : ($hr === 12 ? '12p' : ($hr % 12).($hr < 12 ? 'a' : 'p')); ?></span>
                                        <?php endfor; ?>
                                        <?php if ($isToday): $nowMin = (int)date('G') * 60 + (int)date('i'); ?>
                                            <div class="absolute right-0 border-t-2 border-rose-400 z-20 pointer-events-none" style="left:22px;top:<?php echo round($nowMin / 60 * TL_HOUR_PX); ?>px"><span class="absolute -left-1 -top-[5px] w-2 h-2 rounded-full bg-rose-400"></span></div>
                                        <?php endif; ?>
                                        <?php foreach ($segs as $g): $s = $g['s']; [$bg, $bd, $tx] = tl_color((int)$s['ResidentID']);
                                            $top = round($g['from'] / 60 * TL_HOUR_PX) + 1; $height = max(18, round(($g['to'] - $g['from']) / 60 * TL_HOUR_PX) - 2);
                                            $width = 1 / $g['lanes']; $left = $g['lane'] * $width;
                                            $timeText = timeLabel($s['shift_start']).' – '.timeLabel($s['shift_end']);
                                            [$stLabel] = duty_status_meta((string)$s['status']);
                                            // Past days, carried-over parts and duties already started can't be deleted.
                                            $canDelete = !$g['carry'] && !$dayLocked && ($s['status'] ?? 'Scheduled') === 'Scheduled';
                                            $short = $g['lanes'] > 2 ? implode('', array_map(function($w) { return $w[0] ?? ''; }, preg_split('/\s+/', trim($s['Name'])))) : $s['Name'];
                                        ?>
                                            <div class="duty-block group absolute rounded-md pl-2 pr-1 py-0.5 overflow-hidden cursor-default <?php echo $dayLocked ? 'opacity-70' : ''; ?>"
                                                 style="top:<?php echo $top; ?>px;height:<?php echo $height; ?>px;left:calc(28px + (100% - 30px) * <?php echo $left; ?>);width:calc((100% - 30px) * <?php echo $width; ?> - 2px);background:<?php echo $bg; ?>;color:<?php echo $tx; ?>;border:1px <?php echo $g['carry'] ? 'dashed' : 'solid'; ?> <?php echo $bd; ?>;"
                                                 data-name="<?php echo h($s['Name']); ?>" data-time="<?php echo h($timeText); ?>" data-status="<?php echo h($stLabel); ?>"
                                                 data-note="<?php echo h($g['carry'] ? 'Continuing from '.(new DateTime($s['schedule_date']))->format('M j') : (!empty($s['_is_overnight']) ? 'Ends '.(new DateTime($s['_end_date']))->format('M j') : '')); ?>">
                                                <span class="absolute left-0 top-0 bottom-0 w-1" style="background:<?php echo $bd; ?>"></span>
                                                <p class="text-[10px] font-extrabold leading-tight <?php echo $height >= 34 && $g['lanes'] <= 2 ? 'line-clamp-2' : 'truncate'; ?>"><?php echo h($short); ?></p>
                                                <?php if ($height >= 30 && $g['lanes'] <= 2): ?><p class="text-[9px] font-semibold opacity-75 leading-tight truncate"><?php echo h($timeText); ?></p><?php endif; ?>
                                                <?php if ($canDelete): ?>
                                                <a onclick="return confirm('Delete this duty schedule?')" href="../backend/tanod_action.php?delete_id=<?php echo (int)$s['id']; ?>&week=<?php echo h($weekStart); ?>&csrf_token=<?php echo h(staffbpso_csrf_token()); ?>" class="absolute top-0.5 right-0.5 hidden group-hover:flex w-4 h-4 rounded bg-white/90 text-rose-600 items-center justify-center shadow-sm" title="Delete"><span class="material-symbols-outlined text-[12px]">close</span></a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if (!$segs): ?><p class="absolute inset-x-0 top-1/2 -translate-y-1/2 text-center text-[10px] text-slate-300">No duty</p><?php endif; ?>
                                    </div>

                                    <?php $isPast = $dateKey < $today; ?>
                                    <button type="button" class="add-day-btn w-full min-h-12 rounded-xl border <?php echo $isPast ? 'border-slate-200 bg-slate-100 text-slate-300 cursor-not-allowed' : 'border-dashed border-slate-300 hover:border-slate-400 hover:bg-slate-50 text-slate-400 hover:text-slate-600'; ?> flex items-center justify-center gap-2 text-[9px] font-black uppercase tracking-wider transition" data-date="<?php echo h($dateKey); ?>" data-label="<?php echo h($day->format('l, F j, Y')); ?>" <?php echo $isPast ? 'disabled title="Past dates cannot be scheduled."' : ''; ?>>
                                        <span class="material-symbols-outlined text-base"><?php echo $isPast ? 'lock' : 'add_circle'; ?></span> <?php echo $isPast ? 'Past Date' : 'Add Duty'; ?>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        </main>
    </div>
</div>

<!-- Add schedule modal -->
<div id="scheduleModal" class="hidden fixed inset-0 z-[100] modal-backdrop items-center justify-center p-4">
    <div class="w-full max-w-lg bg-white rounded-2xl shadow-2xl overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between">
            <div>
                <p class="section-title">Duty Assignment</p>
                <h2 class="text-lg font-black text-slate-900 mt-1">Add BPSO Duty</h2>
                <p id="modalDateLabel" class="text-xs text-slate-400 mt-1">Choose a date and shift.</p>
            </div>
            <button type="button" id="closeScheduleModal" class="w-10 h-10 rounded-xl hover:bg-slate-100 flex items-center justify-center text-slate-500">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <form method="post" action="../backend/tanod_action.php" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo h(staffbpso_csrf_token()); ?>">
            <input type="hidden" name="week" value="<?php echo h($weekStart); ?>">
            <div>
                <label class="section-title">Selected Date</label>
                <input id="scheduleDateDisplay" type="text" readonly class="mt-2 w-full rounded-xl border-slate-200 bg-slate-100 text-sm font-semibold text-slate-600 cursor-not-allowed">
                <input id="scheduleDate" type="hidden" name="schedule_date" value="">
                <p class="text-[10px] text-slate-400 mt-1">The date is locked to the day you selected.</p>
            </div>
            <div>
                <label class="section-title">BPSO / Tanod</label>
                <select id="personnelSelect" name="ResidentID" required class="mt-2 w-full rounded-xl border-slate-200 bg-white text-sm">
                    <option value="">Select personnel</option>
                    <?php foreach ($tanods as $t): ?>
                        <option value="<?php echo h($t['ResidentID']); ?>"><?php echo h($t['Name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <p id="personnelHelp" class="text-[10px] text-slate-400 mt-1">A personnel can have more than one duty a day as long as the times do not overlap.</p>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="section-title">Duty Start</label>
                    <input type="time" name="shift_start" required class="mt-2 w-full rounded-xl border-slate-200 bg-white text-sm">
                </div>
                <div>
                    <label class="section-title">Duty End</label>
                    <input type="time" name="shift_end" required class="mt-2 w-full rounded-xl border-slate-200 bg-white text-sm">
                </div>
            </div>
            <p class="text-[10px] text-slate-400 -mt-2">For a graveyard/overnight duty, choose an end time earlier than the start time. Example: <strong>3:00 PM → 1:00 AM</strong> automatically means the shift ends the next day. The same personnel can still be scheduled again on that next date, as long as the new duty starts after the graveyard shift ends.</p>
            <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 pt-2">
                <button type="button" id="cancelScheduleModal" class="px-5 py-3 rounded-xl border border-slate-200 hover:bg-slate-50 text-slate-700 text-[10px] font-black uppercase tracking-wider">Cancel</button>
                <button type="submit" name="create_schedule" class="px-5 py-3 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-[10px] font-black uppercase tracking-wider inline-flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-base">event_available</span> Assign Duty
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function(){
    const modal = document.getElementById('scheduleModal');
    const dateInput = document.getElementById('scheduleDate');
    const dateDisplay = document.getElementById('scheduleDateDisplay');
    const dateLabel = document.getElementById('modalDateLabel');
    const personnelSelect = document.getElementById('personnelSelect');
    const personnelHelp = document.getElementById('personnelHelp');
    const openTop = document.getElementById('openAddFromTop');
    const closeBtn = document.getElementById('closeScheduleModal');
    const cancelBtn = document.getElementById('cancelScheduleModal');
    const minDate = <?php echo json_encode($today); ?>;
    const occupied = {}; // overlapping times are checked on save (tanod_action.php)
    const personnel = <?php echo json_encode(array_map(function($t){ return ['ResidentID'=>(int)$t['ResidentID'], 'Name'=>$t['Name']]; }, $tanods), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;

    function formatDateLabel(dateStr){
        if(!dateStr) return 'Choose an available date.';
        const d = new Date(dateStr + 'T00:00:00');
        return new Intl.DateTimeFormat('en-US', {weekday:'long', month:'long', day:'numeric', year:'numeric'}).format(d);
    }

    function refreshPersonnelOptions(){
        const current = personnelSelect.value;
        const blocked = occupied[dateInput.value] || {};
        personnelSelect.innerHTML = '<option value="">Select personnel</option>';
        let available = 0;
        personnel.forEach(function(person){
            const isBlocked = blocked[String(person.ResidentID)] || blocked[person.ResidentID];
            if(isBlocked) return;
            const option = document.createElement('option');
            option.value = String(person.ResidentID);
            option.textContent = person.Name;
            available++;
            personnelSelect.appendChild(option);
        });
        if(current && !blocked[current] && !blocked[Number(current)]) personnelSelect.value = current;
        else personnelSelect.value = '';
        if(available === 0){
            personnelHelp.textContent = 'No BPSO/Tanod is available for this date. Choose another date.';
            personnelHelp.className = 'text-[10px] text-rose-600 mt-1 font-semibold';
        } else {
            personnelHelp.textContent = available + ' personnel available. Overlapping duty times are checked when you save.';
            personnelHelp.className = 'text-[10px] text-slate-400 mt-1';
        }
    }

    function openModal(date, label){
        if(!date || date < minDate) return;
        dateInput.value = date;
        dateDisplay.value = formatDateLabel(date);
        dateLabel.textContent = label || formatDateLabel(date);
        refreshPersonnelOptions();
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(function(){ personnelSelect.focus(); }, 50);
    }

    function closeModal(){
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        personnelSelect.value = '';
    }

    openTop.addEventListener('click', function(){
        const preferred = <?php echo json_encode(($today >= $weekStart && $today <= $weekEnd) ? $today : (($weekStart >= $today) ? $weekStart : '')); ?>;
        if(preferred) openModal(preferred, formatDateLabel(preferred));
        else alert('This week has no schedulable date. Please open the current or a future week.');
    });

    document.querySelectorAll('.add-day-btn').forEach(function(btn){
        if(btn.disabled) return;
        btn.addEventListener('click', function(){
            openModal(btn.dataset.date, btn.dataset.label);
        });
    });
    closeBtn.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function(e){ if(e.target === modal) closeModal(); });
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape') closeModal(); });
})();
</script>
<!-- Hover card for duty blocks -->
<div id="dutyTip" class="hidden fixed z-[90] w-60 rounded-xl bg-white shadow-xl border border-slate-200 p-3 pointer-events-none text-slate-700">
    <p id="dtName" class="text-sm font-black text-slate-900"></p>
    <p id="dtTime" class="text-xs font-semibold text-slate-500 mt-0.5"></p>
    <div class="flex flex-wrap gap-1 mt-2"><span id="dtStatus" class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 text-[9px] font-black uppercase tracking-wider"></span><span id="dtNote" class="px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-600 text-[9px] font-black uppercase tracking-wider"></span></div>
    <p id="dtWith" class="mt-2 text-[11px] font-semibold text-rose-600"></p>
</div>
<script>
(function(){
    const tip = document.getElementById('dutyTip');
    const set = function(id, v){ const e = document.getElementById(id); e.textContent = v; e.classList.toggle('hidden', !v); };
    document.querySelectorAll('.duty-block').forEach(function(b){
        b.addEventListener('mouseenter', function(){
            set('dtName', b.dataset.name); set('dtTime', b.dataset.time); set('dtStatus', b.dataset.status); set('dtNote', b.dataset.note);
            set('dtWith', b.dataset.with ? 'Overlaps with: ' + b.dataset.with : '');
            tip.classList.remove('hidden');
        });
        b.addEventListener('mousemove', function(e){
            const x = Math.min(e.clientX + 14, window.innerWidth - tip.offsetWidth - 8);
            const y = Math.min(e.clientY + 14, window.innerHeight - tip.offsetHeight - 8);
            tip.style.left = x + 'px'; tip.style.top = y + 'px';
        });
        b.addEventListener('mouseleave', function(){ tip.classList.add('hidden'); });
    });
})();
</script>
</body>

</html>

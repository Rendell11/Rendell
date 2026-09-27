<?php
// ─── AJAX: Complaint chart data endpoint ─────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'chart_data') {
    header('Content-Type: application/json');
    require_once __DIR__ . '/../../db.php';
    require_once __DIR__ . '/../../auth_check.php';
    require_once __DIR__ . '/../../permission_helper.php';
    require_permission($pdo, 'complaints', 'read');

    $category = $_GET['category'] ?? 'All';
    $year     = intval($_GET['year'] ?? date('Y'));

    $labels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    try {
        // Get all distinct categories — used for color assignment & dropdown
        $allCats = $pdo->query(
            "SELECT DISTINCT category FROM complaints WHERE category IS NOT NULL AND category != '' ORDER BY category"
        )->fetchAll(PDO::FETCH_COLUMN);

        $datasets = [];
        $categoriesToQuery = ($category === 'All' || $category === '') ? $allCats : [$category];

        foreach ($categoriesToQuery as $cat) {
            $months = array_fill(1, 12, 0);
            $stmt = $pdo->prepare(
                "SELECT MONTH(created_at) as m, COUNT(*) as cnt FROM complaints
                 WHERE YEAR(created_at) = :yr AND category = :cat
                 GROUP BY MONTH(created_at)"
            );
            $stmt->execute([':yr' => $year, ':cat' => $cat]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $months[(int)$row['m']] = (int)$row['cnt'];
            }

            $datasets[] = [
                'label' => $cat,
                'data'  => array_values($months),
            ];
        }

        echo json_encode([
            'success'    => true,
            'labels'     => $labels,
            'datasets'   => $datasets,
            'categories' => $allCats,
            'year'       => $year,
            'category'   => $category,
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
// ─────────────────────────────────────────────────────────────────────────────

header("Content-Security-Policy: default-src 'self'; " .
    "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://fonts.googleapis.com; " .
    "connect-src 'self' https://cdn.jsdelivr.net; " .
    "font-src 'self' https://fonts.googleapis.com https://fonts.gstatic.com; " .
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
    "img-src 'self' data: blob:; " .
    "media-src 'self' blob:;");
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../permission_helper.php';
require_permission($pdo, 'complaints', 'read');
require_once __DIR__ . '/../../theme_loader.php';

if (session_status() === PHP_SESSION_NONE) { @session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
if (!function_exists('complaint_csrf_token')) {
    function complaint_csrf_token(): string { return htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); }
}

$current_page = 'Complaint';
$_theme_head_loaded = true;

try {
    // All complaints with complainant name
    $complaints_sql = "SELECT c.*, CONCAT(r.FirstName, ' ', r.LastName) as ComplainantName
                       FROM complaints c
                       LEFT JOIN residents r ON c.resident_id = r.ResidentID
                       ORDER BY c.created_at DESC";
    $complaints_stmt = $pdo->query($complaints_sql);
    $complaints_data = $complaints_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Residents for dropdown
    $res_stmt = $pdo->query("SELECT ResidentID, FirstName, LastName FROM residents ORDER BY LastName ASC");
    $residents = $res_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Puroks from DB
    $puroks_stmt = $pdo->query("SELECT purok_name FROM puroks WHERE status = 'Active' ORDER BY purok_name ASC");
    $puroks = $puroks_stmt->fetchAll(PDO::FETCH_COLUMN);

    // Statistics
    $total_complaints   = $pdo->query("SELECT COUNT(*) FROM complaints")->fetchColumn();
    $active_complaints  = $pdo->query("SELECT COUNT(*) FROM complaints WHERE status != 'Resolved'")->fetchColumn();
    $resolved_complaints = $pdo->query("SELECT COUNT(*) FROM complaints WHERE status = 'Resolved'")->fetchColumn();
    $anonymous_complaints = $pdo->query("SELECT COUNT(*) FROM complaints WHERE is_anonymous = 1")->fetchColumn();

} catch (PDOException $e) {
    error_log("DB error in " . basename(__FILE__) . ": " . $e->getMessage());
    http_response_code(500);
    die("A server error occurred.");
}

// Toast from redirect
$toast_msg   = $_SESSION['toast_msg']   ?? '';
$toast_color = $_SESSION['toast_color'] ?? 'indigo';
unset($_SESSION['toast_msg'], $_SESSION['toast_color']);
?>
<!DOCTYPE html>
<html <?php echo $theme_attrs['html']; ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complaint Reports — Barangay Biñang 2nd</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <?php include __DIR__ . '/../../theme_head.php'; ?>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: { DEFAULT: 'var(--accent-600)', light: 'var(--accent-500)', dark: 'var(--accent-700)' },
                        accent:  { DEFAULT: 'var(--accent-500)', light: 'var(--accent-400)' },
                        surface: 'var(--accent-50, #f0f4ff)',
                        'primary-orange': 'var(--accent-500)',
                    },
                    fontFamily: { sans: ['"Plus Jakarta Sans"', 'sans-serif'], mono: ['"DM Mono"', 'monospace'] }
                }
            }
        }
    </script>
    <style>
        :root { --sidebar-w: 288px; --nav-h: 64px; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--page-bg, #eef2fb); -webkit-font-smoothing: antialiased; }

        .main-wrapper { margin-left: var(--sidebar-w); width: calc(100% - var(--sidebar-w)); }
        @media (max-width: 1024px) { .main-wrapper { margin-left: 0; width: 100%; } }

        .stat-card { transition: transform .25s ease, box-shadow .25s ease; }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 20px 40px -12px rgba(26,53,112,.18); }

        .card-accent-bar::before {
            content: ''; position: absolute; inset: 0 0 auto 0;
            height: 3px; border-radius: 16px 16px 0 0;
        }
        .card-blue::before   { background: linear-gradient(90deg, #3b82f6, #6366f1); }
        .card-green::before  { background: linear-gradient(90deg, #10b981, #06b6d4); }
        .card-rose::before   { background: linear-gradient(90deg, #f43f5e, #f97316); }
        .card-amber::before  { background: linear-gradient(90deg, #f59e0b, var(--accent-500, #f05a00)); }

        @keyframes countUp { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        .count-anim { animation: countUp .5s ease both; }

        .section-title { font-size: .65rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; color: #94a3b8; }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }


        .modal-blur { backdrop-filter: blur(4px); }
        [x-cloak] { display: none !important; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }

        .filter-btn { transition: all .2s ease; }
        .filter-btn.active { background: var(--accent-700, #1a3570) !important; color: white !important; }

        /* Priority badges */
        .badge-low    { background:#dcfce7; color:#166534; }
        .badge-medium { background:#fef9c3; color:#854d0e; }
        .badge-high   { background:#fee2e2; color:#991b1b; }
    </style>
</head>
<body <?php echo $theme_attrs['body']; ?> class="bg-[#eef2fb] text-slate-900 antialiased"
      x-data="{
        openComplaintModal: false,
        adminReply: '',
        formData: {},
        openComplaintView(row) {
            this.formData = {
                id:               row.complaint_id,
                complainant_name: row.ComplainantName || 'Anonymous',
                category:         row.category         || 'N/A',
                address:          row.address_location || 'N/A',
                priority:         row.priority_level   || 'Normal',
                title:            row.title,
                description:      row.description,
                status:           row.status
            };
            this.adminReply = row.admin_reply || '';
            this.openComplaintModal = true;
        }
      }">

<div class="flex min-h-screen">
    <?php include __DIR__ . '/../../sidebar.php'; ?>

    <div class="flex-1 flex flex-col min-w-0 main-wrapper">
        <?php include __DIR__ . '/../../header.php'; ?>

        <main class="p-4 md:p-6 lg:p-8 space-y-8">

            <!-- ── Hero Band (same design as Resident Management) ───────────── -->
            <div class="rounded-2xl p-6 md:p-8 text-white relative overflow-hidden" style="background: linear-gradient(135deg, var(--accent-700) 0%, var(--accent-600) 50%, var(--accent-700) 100%);">
                <div class="absolute -right-12 -top-12 w-64 h-64 opacity-10 rounded-full blur-3xl pointer-events-none" style="background: var(--accent-400);"></div>
                <div class="absolute left-1/3 bottom-0 w-48 h-48 opacity-10 rounded-full blur-2xl pointer-events-none" style="background: var(--accent-300);"></div>
                <div class="relative z-10 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div>
                        <h1 class="text-2xl md:text-3xl font-black tracking-tight leading-none">Complaint Reports</h1>
                        <p class="text-white/60 text-sm mt-2 font-medium">Manage and track all resident complaint submissions.</p>
                    </div>
                    <?php if (staff_can($pdo, 'complaints', 'create')): ?>
                    <button onclick="openNewComplaintModal()"
                            class="flex items-center gap-2 bg-white/10 hover:bg-white/20 border border-white/20 text-white px-5 py-2.5 rounded-xl font-bold text-xs uppercase tracking-wider transition-all">
                        <span class="material-symbols-outlined text-lg">add_circle</span>
                        Add Complaint
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── KPI Cards — same design as Resident Management ───────────── -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <?php
                $stats = [
                    ['Total Complaints', $total_complaints, 'folder', 'bg-indigo-50', 'text-indigo-600'],
                    ['Active Complaints', $active_complaints, 'pending', 'bg-rose-50', 'text-rose-600'],
                    ['Resolved', $resolved_complaints, 'check_circle', 'bg-emerald-50', 'text-emerald-600'],
                    ['Anonymous', $anonymous_complaints, 'person_off', 'bg-amber-50', 'text-amber-600']
                ];
                foreach ($stats as $stat):
                ?>
                <div class="bg-white p-6 rounded-[32px] border border-slate-100 shadow-sm">
                    <div class="w-10 h-10 <?= $stat[3] ?> <?= $stat[4] ?> rounded-xl flex items-center justify-center mb-4">
                        <span class="material-symbols-outlined"><?= $stat[2] ?></span>
                    </div>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest"><?= $stat[0] ?></p>
                    <h3 class="text-2xl font-bold text-slate-800 mt-1"><?= number_format($stat[1]) ?></h3>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- ── Analytics Chart ────────────────────────────────────────── -->
            <div class="bg-white rounded-2xl p-6 shadow-sm border border-slate-200/60">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                    <div>
                        <p class="section-title mb-0.5">Incident Analytics</p>
                        <h3 class="text-sm font-bold text-slate-700">Monthly Complaints by Category</h3>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <select id="chartYear" onchange="loadChartData()"
                                class="bg-slate-50 border border-slate-200/60 rounded-xl py-2 px-6 text-xs font-bold text-slate-700 focus:ring-2 cursor-pointer">
                            <?php
                            $currentYear = (int)date('Y');
                            for ($y = $currentYear; $y >= $currentYear - 4; $y--) {
                                $sel = ($y === $currentYear) ? 'selected' : '';
                                echo "<option value=\"$y\" $sel>$y</option>";
                            }
                            ?>
                        </select>
                        <select id="chartCategory" onchange="loadChartData()"
                                class="bg-slate-50 border border-slate-200/60 rounded-xl py-2 px-6 text-xs font-bold text-slate-700 focus:ring-2 cursor-pointer">
                            <option value="All">All Categories</option>
                        </select>
                    </div>
                </div>

                <!-- Chart canvas -->
                <div class="relative" style="height:300px;">
                    <div id="chartLoader" class="absolute inset-0 flex items-center justify-center bg-white/80 rounded-xl z-10 hidden">
                        <div class="flex flex-col items-center gap-3">
                            <div class="w-8 h-8 border-4 border-primary/20 border-t-primary rounded-full animate-spin"></div>
                            <span class="text-xs font-bold text-slate-400">Loading data…</span>
                        </div>
                    </div>
                    <canvas id="complaintsChart"></canvas>
                </div>

                <!-- Legend row — dynamically built, same style as disaster -->
                <div class="flex flex-wrap gap-5 mt-5 pt-5 border-t border-slate-100" id="chartLegend"></div>
            </div>

            <!-- ── Complaints Table ───────────────────────────────────────── -->
            <div class="bg-white rounded-2xl p-6 shadow-sm border border-slate-200/60">
                <div class="flex items-center justify-between mb-5">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-rose-50 flex items-center justify-center">
                            <span class="material-symbols-outlined text-rose-500" style="font-size:18px">chat_bubble</span>
                        </div>
                        <div>
                            <p class="section-title mb-0">Complaints</p>
                            <h3 class="text-sm font-bold text-slate-700">Resident Complaint Records</h3>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <!-- Search -->
                        <div class="relative">
                            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" style="font-size:16px">search</span>
                            <input type="text" id="complaintSearch" oninput="filterTable()" placeholder="Search…"
                                   class="pl-9 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold focus:ring-2 focus:ring-primary/20 w-48">
                        </div>
                        <?php if (staff_can($pdo, 'complaints', 'create')): ?>
                        <button onclick="openNewComplaintModal()"
                                class="flex items-center gap-2 bg-primary hover:bg-primary-light text-white px-4 py-2 rounded-xl font-bold text-[11px] uppercase tracking-widest transition-all shadow-sm">
                            <span class="material-symbols-outlined" style="font-size:16px">add_circle</span> Add Complaint
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Status Filter Tabs -->
                <div class="flex gap-2 mb-5 flex-wrap">
                    <button onclick="filterByStatus('all')"       id="filter-all"      class="filter-btn active px-4 py-1.5 rounded-xl text-[10px] font-black uppercase bg-primary text-white">All</button>
                    <button onclick="filterByStatus('Pending')"   id="filter-pending"  class="filter-btn px-4 py-1.5 rounded-xl text-[10px] font-black uppercase bg-orange-100 text-orange-700 hover:bg-orange-200">Pending</button>
                    <button onclick="filterByStatus('Ongoing')"   id="filter-ongoing"  class="filter-btn px-4 py-1.5 rounded-xl text-[10px] font-black uppercase bg-blue-100 text-blue-700 hover:bg-blue-200">Ongoing</button>
                    <button onclick="filterByStatus('Resolved')"  id="filter-resolved" class="filter-btn px-4 py-1.5 rounded-xl text-[10px] font-black uppercase bg-emerald-100 text-emerald-700 hover:bg-emerald-200">Resolved</button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full" id="complaintsTable">
                        <thead>
                            <tr class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                                <th class="text-left pb-4 font-extrabold">ID</th>
                                <th class="text-left pb-4 font-extrabold">Complainant</th>
                                <th class="text-left pb-4 font-extrabold">Category</th>
                                <th class="text-left pb-4 font-extrabold">Subject</th>
                                <th class="text-left pb-4 font-extrabold">Priority</th>
                                <th class="text-left pb-4 font-extrabold">Date Filed</th>
                                <th class="text-left pb-4 font-extrabold">Status</th>
                                <th class="text-right pb-4 font-extrabold">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50" id="complaintsTableBody">
                            <?php if (empty($complaints_data)): ?>
                                <tr><td colspan="8" class="py-10 text-center text-slate-400 text-xs italic">No resident complaints found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($complaints_data as $comp):
                                    $statusClass = match($comp['status'] ?? 'Pending') {
                                        'Resolved' => 'bg-green-100 text-green-600',
                                        'Ongoing'  => 'bg-blue-100 text-blue-600',
                                        default    => 'bg-orange-100 text-orange-600',
                                    };
                                    $priorityClass = match($comp['priority_level'] ?? 'Medium') {
                                        'Low'           => 'badge-low',
                                        'High (Urgent)' => 'badge-high',
                                        default         => 'badge-medium',
                                    };
                                ?>
                                <tr class="text-xs complaint-row" data-status="<?php echo htmlspecialchars($comp['status'] ?? ''); ?>">
                                    <td class="py-4 text-slate-400 font-mono">#<?php echo htmlspecialchars($comp['complaint_id']); ?></td>
                                    <td class="py-4 font-bold text-slate-800"><?php echo htmlspecialchars($comp['ComplainantName'] ?: 'Anonymous'); ?></td>
                                    <td class="py-4 text-slate-500"><?php echo htmlspecialchars($comp['category'] ?? '—'); ?></td>
                                    <td class="py-4 text-slate-600 max-w-[200px] truncate"><?php echo htmlspecialchars($comp['title']); ?></td>
                                    <td class="py-4">
                                        <span class="px-2 py-0.5 rounded text-[9px] font-black uppercase <?php echo $priorityClass; ?>">
                                            <?php echo htmlspecialchars($comp['priority_level'] ?? 'Medium'); ?>
                                        </span>
                                    </td>
                                    <td class="py-4 text-slate-500"><?php echo date('M d, Y', strtotime($comp['created_at'])); ?></td>
                                    <td class="py-4">
                                        <span class="px-3 py-1 rounded-full text-[9px] font-black uppercase <?php echo $statusClass; ?>">
                                            <?php echo htmlspecialchars($comp['status'] ?? 'Pending'); ?>
                                        </span>
                                    </td>
                                    <td class="py-4 text-right">
                                        <button @click="openComplaintView(<?php echo htmlspecialchars(json_encode($comp)); ?>)"
                                                class="bg-primary hover:bg-accent text-white px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all">
                                            <?php echo staff_can($pdo, 'complaints', 'update') ? 'View & Respond' : 'View Details'; ?>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     MODAL: COMPLAINT VIEW & RESPOND
═══════════════════════════════════════════════════════ -->
<div x-show="openComplaintModal" x-cloak
     class="fixed inset-0 z-50 flex items-center justify-center p-6 bg-black/50 modal-blur" x-transition>
    <div class="bg-white w-full max-w-2xl rounded-[2.5rem] p-10 shadow-2xl overflow-y-auto max-h-[90vh]"
         @click.away="openComplaintModal = false">
        <div class="flex justify-between items-start mb-8">
            <div>
                <h2 class="text-2xl font-black text-slate-900 tracking-tight">Complaint Details</h2>
                <p class="text-xs font-bold text-primary-orange uppercase tracking-widest mt-1" x-text="'ID: #' + formData.id"></p>
            </div>
            <button @click="openComplaintModal = false" class="text-slate-300 hover:text-slate-900 transition-all">
                <span class="material-symbols-outlined !text-3xl">close</span>
            </button>
        </div>
        <div class="grid grid-cols-2 gap-6 mb-8">
            <div class="space-y-4">
                <div>
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">Complainant</label>
                    <p class="text-sm font-bold text-slate-700" x-text="formData.complainant_name"></p>
                </div>
                <div>
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">Location</label>
                    <p class="text-sm font-bold italic text-primary-orange" x-text="formData.address"></p>
                </div>
            </div>
            <div class="space-y-4">
                <div>
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">Category</label>
                    <p class="text-sm font-bold text-slate-700" x-text="formData.category"></p>
                </div>
                <div>
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">Priority</label>
                    <span class="px-2 py-1 rounded text-[10px] font-black uppercase bg-red-50 text-red-500" x-text="formData.priority"></span>
                </div>
            </div>
        </div>
        <div class="mb-6">
            <label class="text-[10px] font-black text-slate-400 uppercase tracking-wider block mb-2">Subject</label>
            <p class="text-sm font-bold text-slate-800" x-text="formData.title"></p>
        </div>
        <div class="mb-8 p-6 bg-slate-50 rounded-2xl border-l-4 border-primary-orange">
            <p class="text-sm text-slate-600 leading-relaxed" x-text="formData.description"></p>
        </div>
        <?php if (staff_can($pdo, 'complaints', 'update')): ?>
        <form action="../backend/update_status.php" method="POST" class="border-t border-slate-100 pt-8">
            <input type="hidden" name="csrf_token" value="<?php echo complaint_csrf_token(); ?>">
            <input type="hidden" name="complaint_id" :value="formData.id">
            <div class="mb-6">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-wider block mb-3">Admin Response</label>
                <textarea name="admin_message" x-model="adminReply" rows="4"
                          class="w-full bg-slate-50 border-none rounded-2xl text-sm p-4 focus:ring-2 focus:ring-primary-orange/20"
                          placeholder="Write your response..."></textarea>
            </div>
            <div class="flex gap-3">
                <div class="flex-1">
                    <select name="status" class="w-full bg-slate-100 border-none rounded-xl text-xs font-bold py-3 px-4">
                        <option value="Pending"  :selected="formData.status == 'Pending'">Pending</option>
                        <option value="Ongoing"  :selected="formData.status == 'Ongoing'">Ongoing</option>
                        <option value="Resolved" :selected="formData.status == 'Resolved'">Resolved</option>
                    </select>
                </div>
                <button type="submit" class="bg-black text-white px-8 py-3 rounded-xl font-black text-[11px] uppercase tracking-widest hover:bg-primary-orange">
                    Send Response
                </button>
            </div>
        </form>
        <?php else: ?>
        <div class="border-t border-slate-100 pt-8">
            <div class="rounded-2xl bg-slate-50 border border-slate-100 p-4 text-xs text-slate-500">You have read-only access to complaint records.</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     MODAL: ADD NEW COMPLAINT
═══════════════════════════════════════════════════════ -->
<div id="addComplaintModal" class="fixed inset-0 z-50 hidden bg-slate-900/80 modal-blur flex items-center justify-center p-6">
    <div class="bg-white rounded-[3rem] shadow-2xl w-full max-w-4xl p-12 space-y-8 overflow-y-auto max-h-[95vh] custom-scrollbar">
        <div class="flex justify-between items-center">
            <div>
                <h3 class="text-3xl font-black text-slate-900 tracking-tighter">Add New Complaint</h3>
                <p id="newComplaintIdDisplay" class="text-xs text-indigo-400 font-mono font-bold mt-1"></p>
            </div>
            <button onclick="document.getElementById('addComplaintModal').classList.add('hidden')"
                    class="h-12 w-12 flex items-center justify-center bg-slate-50 hover:bg-slate-100 rounded-full transition-colors">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <form id="addComplaintForm" action="../backend/process_complaint.php" method="POST" enctype="multipart/form-data" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?php echo complaint_csrf_token(); ?>">
            <input type="hidden" name="complaint_id" id="newComplaintId">
            <input type="hidden" name="created_at"   id="newComplaintCreatedAt">

            <div class="grid grid-cols-2 gap-6">
                <!-- Resident Dropdown -->
                <div class="space-y-1">
                    <label class="text-[10px] font-black uppercase text-slate-400 ml-2">Resident (Optional)</label>
                    <select name="resident_id" id="newResidentId" onchange="onNewResidentChange()"
                            class="w-full bg-slate-50 border-none rounded-2xl py-4 px-6 font-bold text-sm">
                        <option value="">-- Select Resident --</option>
                        <?php foreach ($residents as $r): ?>
                            <option value="<?php echo $r['ResidentID']; ?>"><?php echo $r['LastName'] . ', ' . $r['FirstName']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Purok -->
                <div class="space-y-1">
                    <label class="text-[10px] font-black uppercase text-slate-400 ml-2">Purok</label>
                    <select name="purok" id="newPurok" class="w-full bg-slate-50 border-none rounded-2xl py-4 px-6 font-bold text-sm">
                        <option value="">-- Select Purok --</option>
                        <?php foreach ($puroks as $p): ?>
                            <option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Anonymous Toggle -->
                <div class="space-y-1 col-span-2">
                    <label class="text-[10px] font-black uppercase text-slate-400 ml-2">Anonymous Complaint?</label>
                    <div class="flex items-center gap-4">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="is_anonymous" value="0" id="notAnon" checked onchange="toggleComplainantName(false)" class="w-4 h-4 accent-indigo-600">
                            <span class="text-sm font-bold text-slate-700">No — show name</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="is_anonymous" value="1" id="isAnon" onchange="toggleComplainantName(true)" class="w-4 h-4 accent-indigo-600">
                            <span class="text-sm font-bold text-slate-700">Yes — anonymous</span>
                        </label>
                    </div>
                </div>

                <!-- Complainant Name -->
                <div class="space-y-1 col-span-2" id="complainantNameField">
                    <label class="text-[10px] font-black uppercase text-slate-400 ml-2">Complainant Name <span class="text-rose-500">*</span></label>
                    <input type="text" name="complainant_name" id="newComplainantName" required
                           class="w-full bg-slate-50 border-none rounded-2xl py-4 px-6 font-bold text-sm"
                           placeholder="Full name of complainant">
                </div>

                <!-- Category -->
                <div class="space-y-1">
                    <label class="text-[10px] font-black uppercase text-slate-400 ml-2">Category <span class="text-rose-500">*</span></label>
                    <select name="category" id="newCategory" required onchange="toggleOtherCategory()"
                            class="w-full bg-slate-50 border-none rounded-2xl py-4 px-6 font-bold text-sm">
                        <option value="">-- Select Category --</option>
                        <option value="Noise Complaint">Noise Complaint</option>
                        <option value="Garbage/Sanitation">Garbage / Sanitation</option>
                        <option value="Property Dispute">Property Dispute</option>
                        <option value="Harassment">Harassment</option>
                        <option value="Domestic Issue">Domestic Issue</option>
                        <option value="Road/Infrastructure">Road / Infrastructure</option>
                        <option value="Public Safety">Public Safety</option>
                        <option value="Other">Other (specify below)</option>
                    </select>
                </div>

                <!-- Other Category -->
                <div class="space-y-1 hidden" id="otherCategoryField">
                    <label class="text-[10px] font-black uppercase text-slate-400 ml-2">Specify Other Category</label>
                    <input type="text" name="other_category_specify" id="newOtherCategory"
                           class="w-full bg-slate-50 border-none rounded-2xl py-4 px-6 font-bold text-sm"
                           placeholder="Describe the category">
                </div>

                <!-- Address / Location -->
                <div class="space-y-1 col-span-2">
                    <label class="text-[10px] font-black uppercase text-slate-400 ml-2">Address / Location <span class="text-rose-500">*</span></label>
                    <input type="text" name="address_location" id="newAddressLocation" required
                           class="w-full bg-slate-50 border-none rounded-2xl py-4 px-6 font-bold text-sm"
                           placeholder="Where did the incident occur?">
                </div>

                <!-- Priority Level -->
                <div class="space-y-1">
                    <label class="text-[10px] font-black uppercase text-slate-400 ml-2">Priority Level <span class="text-rose-500">*</span></label>
                    <select name="priority_level" id="newPriority" required
                            class="w-full bg-slate-50 border-none rounded-2xl py-4 px-6 font-bold text-sm">
                        <option value="">-- Select Priority --</option>
                        <option value="Low">Low</option>
                        <option value="Medium">Medium</option>
                        <option value="High (Urgent)">High (Urgent)</option>
                    </select>
                </div>

                <!-- Title -->
                <div class="space-y-1">
                    <label class="text-[10px] font-black uppercase text-slate-400 ml-2">Complaint Title <span class="text-rose-500">*</span></label>
                    <input type="text" name="title" id="newTitle" required
                           class="w-full bg-slate-50 border-none rounded-2xl py-4 px-6 font-bold text-sm"
                           placeholder="Brief subject of complaint">
                </div>

                <!-- Description -->
                <div class="space-y-1 col-span-2">
                    <label class="text-[10px] font-black uppercase text-slate-400 ml-2">Description <span class="text-rose-500">*</span></label>
                    <textarea name="description" id="newDescription" rows="5" required
                              class="w-full bg-slate-50 border-none rounded-2xl py-4 px-6 font-medium text-sm resize-none focus:ring-2 focus:ring-indigo-200"
                              placeholder="Detailed description of the complaint..."></textarea>
                </div>

                <!-- Attachment -->
                <div class="space-y-1 col-span-2">
                    <label class="text-[10px] font-black uppercase text-slate-400 ml-2">Attachment (Optional)</label>
                    <div class="border-2 border-dashed border-slate-200 rounded-2xl p-5 text-center">
                        <span class="material-symbols-outlined text-slate-300 text-3xl mb-1 block">attach_file</span>
                        <p class="text-xs text-slate-400 mb-2">Upload photos, PDFs, or documents</p>
                        <input type="file" name="attachment" id="newAttachment" accept="image/*,.pdf,.doc,.docx"
                               class="hidden" onchange="showComplaintFileName(this)">
                        <button type="button" onclick="document.getElementById('newAttachment').click()"
                                class="bg-slate-100 text-slate-600 px-5 py-2 rounded-xl text-xs font-bold hover:bg-slate-200">
                            Browse File
                        </button>
                        <p id="newAttachmentName" class="mt-2 text-xs text-indigo-500 font-bold hidden"></p>
                    </div>
                </div>
            </div>

            <div id="complaintFormError" class="hidden bg-rose-50 border border-rose-200 rounded-2xl px-5 py-3 text-xs font-bold text-rose-600"></div>

            <div class="flex gap-4 pt-2">
                <button type="button" onclick="document.getElementById('addComplaintModal').classList.add('hidden')"
                        class="flex-1 py-5 font-black uppercase text-slate-400 hover:text-slate-600">Cancel</button>
                <button type="button" onclick="submitNewComplaint()"
                        class="flex-1 py-5 bg-slate-900 text-white font-black uppercase rounded-2xl shadow-lg hover:bg-indigo-900 transition-all text-sm">
                    <span class="material-symbols-outlined !text-sm align-middle">save</span> Save Complaint
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($toast_msg): ?>
<script>document.addEventListener('DOMContentLoaded', () => showToast(<?php echo json_encode($toast_msg); ?>, '<?php echo $toast_color; ?>'));</script>
<?php endif; ?>

<script>
/* ─── COMPLAINT ANALYTICS CHART ─── */
let complaintsChart = null;

// Color palette — cycles per category index, same approach as disasterColorMap
const complaintColorMap_palette = [
    { bgLine: 'rgba(99,102,241,0.12)',  border: 'rgba(99,102,241,1)'  },
    { bgLine: 'rgba(239,68,68,0.10)',   border: 'rgba(239,68,68,1)'   },
    { bgLine: 'rgba(34,197,94,0.10)',   border: 'rgba(34,197,94,1)'   },
    { bgLine: 'rgba(249,115,22,0.10)',  border: 'rgba(249,115,22,1)'  },
    { bgLine: 'rgba(59,130,246,0.12)',  border: 'rgba(59,130,246,1)'  },
    { bgLine: 'rgba(168,85,247,0.10)',  border: 'rgba(168,85,247,1)'  },
    { bgLine: 'rgba(20,184,166,0.10)',  border: 'rgba(20,184,166,1)'  },
    { bgLine: 'rgba(245,158,11,0.10)',  border: 'rgba(245,158,11,1)'  },
];

// Built once on first load — maps category name → color
let complaintColorMap = {};

async function loadChartData() {
    const year     = document.getElementById('chartYear').value;
    const category = document.getElementById('chartCategory').value;
    const loader   = document.getElementById('chartLoader');
    loader.classList.remove('hidden');

    try {
        const url = `complaint_rep.php?action=chart_data&year=${year}&category=${encodeURIComponent(category)}`;
        const res  = await fetch(url);
        const data = await res.json();

        if (!data.success) throw new Error(data.error || 'Failed to load chart data');

        // Populate category dropdown once
        const sel = document.getElementById('chartCategory');
        if (sel.options.length <= 1 && data.categories?.length) {
            data.categories.forEach(cat => {
                const opt = document.createElement('option');
                opt.value = cat; opt.textContent = cat;
                sel.appendChild(opt);
            });
        }

        // Build stable color map from full category list
        if (data.categories?.length) {
            data.categories.forEach((cat, i) => {
                if (!complaintColorMap[cat]) {
                    complaintColorMap[cat] = complaintColorMap_palette[i % complaintColorMap_palette.length];
                }
            });
        }

        renderComplaintChart(data);
        updateComplaintLegend(data);
    } catch (err) {
        console.error('Complaint chart error:', err);
    } finally {
        loader.classList.add('hidden');
    }
}

function renderComplaintChart(data) {
    const ctx = document.getElementById('complaintsChart').getContext('2d');
    if (complaintsChart) complaintsChart.destroy();

    const datasets = data.datasets.map(ds => {
        const c = complaintColorMap[ds.label] || { bgLine: 'rgba(26,53,112,0.12)', border: ds.borderColor || 'rgba(99,102,241,1)' };
        return {
            label:                ds.label,
            data:                 ds.data,
            backgroundColor:      c.bgLine,
            borderColor:          c.border,
            borderWidth:          2.5,
            tension:              0.4,
            fill:                 true,
            pointBackgroundColor: c.border,
            pointRadius:          4,
            pointHoverRadius:     6,
        };
    });

    complaintsChart = new Chart(ctx, {
        type: 'line',
        data: { labels: data.labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#0f172a',
                    titleFont: { family: "'Plus Jakarta Sans'", weight: '700', size: 11 },
                    bodyFont:  { family: "'Plus Jakarta Sans'", size: 12 },
                    padding: 12,
                    cornerRadius: 12,
                    callbacks: {
                        title: (items) => items[0].label + ' ' + data.year,
                        label: (item)  => ` ${item.dataset.label}: ${item.raw} record${item.raw !== 1 ? 's' : ''}`,
                    }
                }
            },
            scales: {
                x: {
                    grid:   { display: false },
                    ticks:  { font: { family: "'Plus Jakarta Sans'", size: 10, weight: '700' }, color: '#94a3b8' },
                    border: { display: false },
                },
                y: {
                    beginAtZero: true,
                    grid:   { color: '#f1f5f9' },
                    ticks:  { font: { family: "'Plus Jakarta Sans'", size: 10 }, color: '#94a3b8', stepSize: 1, precision: 0 },
                    border: { display: false },
                }
            },
            animation: { duration: 500, easing: 'easeInOutQuart' },
        }
    });
}

function updateComplaintLegend(data) {
    const legendEl = document.getElementById('chartLegend');
    legendEl.innerHTML = '';

    let grand = 0;
    data.datasets.forEach(ds => {
        const total = ds.data.reduce((a, b) => a + b, 0);
        grand += total;
        const c = complaintColorMap[ds.label] || { border: 'rgba(99,102,241,1)' };
        const item = document.createElement('div');
        item.className = 'flex items-center gap-2';
        item.innerHTML = `
            <span class="w-2.5 h-2.5 rounded-full inline-block" style="background:${c.border}"></span>
            <span class="text-[10px] font-bold uppercase text-slate-400">${ds.label}</span>
            <span class="text-xs font-black text-slate-700">${total}</span>`;
        legendEl.appendChild(item);
    });

    const totalItem = document.createElement('div');
    totalItem.className = 'ml-auto flex items-center gap-2';
    totalItem.innerHTML = `
        <span class="text-[10px] font-bold uppercase text-slate-400">Total Records</span>
        <span class="text-xs font-black" style="color: var(--accent-600);">${grand} total records</span>`;
    legendEl.appendChild(totalItem);
}

document.addEventListener('DOMContentLoaded', () => loadChartData());
</script>

<script>
/* ─── Status filter tabs ─── */
function filterByStatus(status) {
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('filter-' + (status === 'all' ? 'all' : status.toLowerCase())).classList.add('active');
    filterTable(status);
}

let currentStatus = 'all';
function filterTable(status) {
    if (status !== undefined && typeof status === 'string' && ['all','Pending','Ongoing','Resolved'].includes(status)) {
        currentStatus = status;
    }
    const search = (document.getElementById('complaintSearch').value || '').toLowerCase();
    document.querySelectorAll('#complaintsTableBody .complaint-row').forEach(row => {
        const rowStatus = row.dataset.status || '';
        const text      = row.textContent.toLowerCase();
        const matchStatus = currentStatus === 'all' || rowStatus === currentStatus;
        const matchSearch = !search || text.includes(search);
        row.style.display = (matchStatus && matchSearch) ? '' : 'none';
    });
}

/* ─── Add Complaint Modal ─── */
function openNewComplaintModal() {
    const now      = new Date();
    const datePart = now.getFullYear().toString() +
        String(now.getMonth() + 1).padStart(2, '0') +
        String(now.getDate()).padStart(2, '0');
    const cmpId = 'CMP-' + datePart + '-' + Math.floor(1000 + Math.random() * 9000);
    document.getElementById('newComplaintId').value          = cmpId;
    document.getElementById('newComplaintIdDisplay').textContent = 'ID: ' + cmpId;
    document.getElementById('newComplaintCreatedAt').value   = now.toISOString().slice(0, 19).replace('T', ' ');
    document.getElementById('addComplaintForm').reset();
    document.getElementById('newComplaintId').value          = cmpId;
    document.getElementById('newComplaintIdDisplay').textContent = 'ID: ' + cmpId;
    document.getElementById('complainantNameField').classList.remove('hidden');
    document.getElementById('otherCategoryField').classList.add('hidden');
    document.getElementById('newAttachmentName').classList.add('hidden');
    document.getElementById('complaintFormError').classList.add('hidden');
    document.getElementById('addComplaintModal').classList.remove('hidden');
}

function onNewResidentChange() {
    const sel = document.getElementById('newResidentId');
    const opt = sel.options[sel.selectedIndex];
    if (opt.value) {
        const parts = opt.text.split(', ');
        document.getElementById('newComplainantName').value =
            parts.length >= 2 ? parts[1] + ' ' + parts[0] : opt.text;
    }
}

function toggleComplainantName(isAnon) {
    const field = document.getElementById('complainantNameField');
    const input = document.getElementById('newComplainantName');
    if (isAnon) { field.classList.add('hidden'); input.removeAttribute('required'); input.value = ''; }
    else        { field.classList.remove('hidden'); input.setAttribute('required', 'required'); }
}

function toggleOtherCategory() {
    const cat = document.getElementById('newCategory').value;
    document.getElementById('otherCategoryField').classList.toggle('hidden', cat !== 'Other');
}

function showComplaintFileName(input) {
    const el = document.getElementById('newAttachmentName');
    if (input.files && input.files[0]) {
        el.textContent = '📎 ' + input.files[0].name;
        el.classList.remove('hidden');
    }
}

function submitNewComplaint() {
    const errEl    = document.getElementById('complaintFormError');
    const isAnon   = document.querySelector('input[name="is_anonymous"]:checked')?.value === '1';
    const name     = document.getElementById('newComplainantName').value.trim();
    const category = document.getElementById('newCategory').value;
    const address  = document.getElementById('newAddressLocation').value.trim();
    const priority = document.getElementById('newPriority').value;
    const title    = document.getElementById('newTitle').value.trim();
    const desc     = document.getElementById('newDescription').value.trim();
    const errors   = [];

    errEl.classList.add('hidden');
    if (!isAnon && !name)  errors.push('Complainant Name is required.');
    if (!category)         errors.push('Category is required.');
    if (!address)          errors.push('Address/Location is required.');
    if (!priority)         errors.push('Priority Level is required.');
    if (!title)            errors.push('Complaint Title is required.');
    if (!desc)             errors.push('Description is required.');

    if (errors.length) {
        errEl.textContent = errors.join(' ');
        errEl.classList.remove('hidden');
        return;
    }
    document.getElementById('addComplaintForm').submit();
}

/* ─── Toast ─── */
/* ── Toast System (matches residents.php) ── */
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
    const icons = { success: 'check_circle', error: 'cancel', warning: 'warning', info: 'info' };
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
</script>

</body>
</html>
<?php
/**
 * partials/analytics_head.php — shared <head> assets + styles for the analytics pages.
 * Expects $page_title and the variables from analytics_bootstrap.php.
 */
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($page_title ?? 'Analytics'); ?> — Barangay Biñang 2nd</title>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
<?php include __DIR__ . '/../../../theme_head.php'; ?>
<script>
    const CSRF_TOKEN = <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>;
    // Barangay identity used on the printed / PDF analytics report letterhead
    const BRGY = <?php echo json_encode([
        'name' => $brgy_name,
        'address' => $brgy_address,
        'logo' => $brgy_logo_url,
        'captain' => $brgy_captain,
        'preparedBy' => $prepared_by,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

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
    body {
        font-family: 'Plus Jakarta Sans', sans-serif;
        background: var(--page-bg, #eef2fb);
        -webkit-font-smoothing: antialiased;
    }

    .main-wrapper { margin-left: 272px; width: calc(100% - 272px); transition: margin-left .3s ease, width .3s ease; }
    body.sidebar-collapsed .main-wrapper { margin-left: 68px; width: calc(100% - 68px); }
    @media (max-width: 1024px) { .main-wrapper { margin-left: 0 !important; width: 100% !important; } }

    html.dark body { background: #0f172a; color: #e2e8f0; }
    html.dark .bg-white { background: #1e293b !important; }
    html.dark .border-slate-100, html.dark .border-slate-200, html.dark .border-slate-200\/60 { border-color: #334155 !important; }
    html.dark .text-slate-800, html.dark .text-slate-900 { color: #f1f5f9 !important; }
    html.dark .text-slate-700 { color: #e2e8f0 !important; }
    html.dark .text-slate-600 { color: #94a3b8 !important; }
    html.dark .bg-slate-50 { background: #0f172a !important; }
    html.dark .bg-slate-100 { background: #1e293b !important; }

    .section-title { font-size: .65rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; color: #94a3b8; }
    .panel { background: #fff; border: 1px solid #f1f5f9; border-radius: 32px; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, .05); }
    .panel-desc { font-size: 12px; color: #64748b; line-height: 1.6; font-weight: 500; }
    .kpi { transition: transform .25s ease, box-shadow .25s ease; }
    .kpi:hover { transform: translateY(-3px); box-shadow: 0 16px 32px -12px rgba(26, 53, 112, .18); }
    .chart-box { position: relative; width: 100%; }
    .field { background: #f1f5f9; border: 0; border-radius: .75rem; padding: .75rem 1rem; font-size: .875rem; font-weight: 700; color: #334155; }
    .field:focus { outline: none; box-shadow: 0 0 0 2px color-mix(in srgb, var(--accent-600, #4f46e5) 20%, transparent); }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: .4rem; border-radius: 1rem; padding: .8rem 1.25rem; font-size: .75rem; font-weight: 900; text-transform: uppercase; letter-spacing: .02em; transition: all .15s ease; white-space: nowrap; }
    .btn:active { transform: scale(.97); }
    .btn:disabled { opacity: .6; cursor: not-allowed; }
    .btn-dark { background: var(--accent-600, #4f46e5); color: #fff; box-shadow: 0 10px 15px -3px rgba(0,0,0,.1); }
    .btn-dark:hover { background: var(--accent-700, #4338ca); }
    .btn-soft { background: #eef2ff; color: #4f46e5; }
    .btn-soft:hover { background: #e0e7ff; }
    .btn-light { background: #fff; color: #94a3b8; border: 1px solid #e2e8f0; }
    .btn-light:hover { color: #334155; border-color: #cbd5e1; }
    .btn-ai { color: #fff; background: linear-gradient(90deg, var(--accent-500, #f05a00), var(--accent-400, #ff7a20)); box-shadow: 0 6px 16px -6px rgba(240, 90, 0, .45); }
    .btn-ai:hover { transform: translateY(-1px); }
    .data-table th { font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .1em; padding: 1rem 1.5rem; text-align: left; background: rgba(248, 250, 252, .5); border-bottom: 1px solid #f8fafc; white-space: nowrap; }
    .data-table td { padding: 1rem 1.5rem; font-size: 12px; color: #475569; font-weight: 600; border-top: 1px solid #f8fafc; }
    .data-table tbody tr:hover { background: rgba(248, 250, 252, .7); }
    .pill { display: inline-block; padding: 2px 8px; border-radius: .375rem; font-size: 9px; font-weight: 700; text-transform: uppercase; border: 1px solid transparent; }
    .modal-backdrop { background: rgba(15, 23, 42, .8); }
    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }
</style>

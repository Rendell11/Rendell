<?php
/**
 * Shared <head> content for the Certificates pages — same design system as the
 * Resident module (admin/residents). Set $pageTitle before including.
 */
require_once __DIR__ . '/../../../theme_loader.php';
$_theme_head_loaded = true;
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h($pageTitle ?? 'Certificates'); ?> — CAPS</title>
<meta name="csrf-token" content="<?php echo h(cert_csrf_token()); ?>">
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
<?php require __DIR__ . '/../../../theme_head.php'; ?>
<script>
    // Same Tailwind theme as the Resident module (primary = the barangay accent color).
    tailwind.config = {
        theme: { extend: {
            colors: {
                primary: { DEFAULT: 'var(--accent-600)', light: 'var(--accent-500)', dark: 'var(--accent-700)' },
                accent: { DEFAULT: 'var(--accent-500)', light: 'var(--accent-400)' },
            },
            fontFamily: { sans: ['"Plus Jakarta Sans"', 'sans-serif'], mono: ['"DM Mono"', 'monospace'] }
        } }
    };
</script>
<style>
    /* ── Layout + design system shared with admin/residents (Resident Management) ── */
    :root { --sidebar-w: 288px; --nav-h: 64px; }
    body { font-family:'Plus Jakarta Sans',sans-serif; background:var(--page-bg,#eef2fb); -webkit-font-smoothing:antialiased; }
    .main-wrapper { margin-left:var(--sidebar-w); width:calc(100% - var(--sidebar-w)); }
    @media (max-width:1024px) { .main-wrapper { margin-left:0; width:100%; } }
    ::-webkit-scrollbar { width:6px; height:6px; } ::-webkit-scrollbar-track { background:transparent; } ::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:99px; }
    .section-title { font-size:.65rem; font-weight:800; letter-spacing:.12em; text-transform:uppercase; color:#94a3b8; }

    /* Hero band (Resident Management) */
    .hero-band { background:linear-gradient(135deg,var(--accent-700) 0%,var(--accent-600) 50%,var(--accent-700) 100%); }
    .hero-btn { display:inline-flex; align-items:center; justify-content:center; gap:.5rem; flex-shrink:0; padding:.625rem 1.25rem; border-radius:.75rem; font-weight:700; font-size:.75rem; text-transform:uppercase; letter-spacing:.05em; transition:all .15s; color:#fff; background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.2); }
    .hero-btn:hover { background:rgba(255,255,255,.2); }
    .hero-btn .material-symbols-outlined { font-size:1.125rem; }
    .hero-btn-primary { background:var(--accent-500); border-color:transparent; box-shadow:0 10px 15px -3px rgba(0,0,0,.15); }
    .hero-btn-primary:hover { background:var(--accent-400); }
    .hero-btn-white { background:#fff; color:var(--accent-600); border-color:#fff; font-weight:900; }
    .hero-btn-white:hover { background:rgba(255,255,255,.9); }

    /* Cards (Resident stat cards + analytics cards) */
    .card { background:#fff; border:1px solid #e8edf5; border-radius:28px; box-shadow:0 4px 18px rgba(15,23,42,.04); }
    .stat-card { background:#fff; padding:1.5rem; border-radius:32px; border:1px solid #f1f5f9; box-shadow:0 1px 2px rgba(15,23,42,.05); }
    .stat-icon { width:2.5rem; height:2.5rem; border-radius:.75rem; display:flex; align-items:center; justify-content:center; margin-bottom:1rem; }
    .stat-label { font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:.1em; }
    .stat-value { font-size:1.5rem; font-weight:700; color:#1e293b; margin-top:.25rem; line-height:1.2; }
    .table-card { background:#fff; border-radius:32px; border:1px solid #f1f5f9; box-shadow:0 1px 2px rgba(15,23,42,.05); overflow:hidden; }
    .table-card thead tr { background:rgba(248,250,252,.5); font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:.1em; border-bottom:1px solid #f8fafc; }
    .table-card th { padding:1.25rem 1.5rem; text-align:left; }
    .table-card th:first-child, .table-card td:first-child { padding-left:2rem; }
    .table-card th:last-child, .table-card td:last-child { padding-right:2rem; }
    .table-card td { padding:1.25rem 1.5rem; }
    .table-card tbody tr { transition:background .15s; }
    .table-card tbody tr:hover { background:rgba(248,250,252,.6); }
    .avatar { width:2.25rem; height:2.25rem; border-radius:.75rem; background:#f1f5f9; color:#64748b; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:10px; flex-shrink:0; }
    .search-pill, input.search-pill { width:100%; padding:.625rem 1rem .625rem 2.75rem; background:#fff; border:1px solid #e2e8f0; border-radius:1rem; font-size:.875rem; box-shadow:0 1px 2px rgba(15,23,42,.05); transition:all .15s; }
    .search-pill:focus, input.search-pill:focus { outline:none; border-color:var(--accent-500,#6366f1); box-shadow:0 0 0 4px rgba(99,102,241,.06); }
    .select-pill { width:100%; border:1px solid #e2e8f0; border-radius:1rem; padding-top:.625rem; padding-bottom:.625rem; font-size:.875rem; font-weight:700; color:#475569; background-color:#fff; box-shadow:0 1px 2px rgba(15,23,42,.05); }

    /* Pills (Resident classification tags) */
    .pill { display:inline-flex; align-items:center; gap:.25rem; padding:.125rem .5rem; border-radius:.375rem; border:1px solid; font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.02em; white-space:nowrap; }
    .pill .material-symbols-outlined { font-size:12px; }

    /* Modals (Resident Add/Edit modal) */
    .modal-back { position:fixed; inset:0; z-index:60; background:rgba(15,23,42,.8); backdrop-filter:blur(4px); display:none; align-items:center; justify-content:center; padding:1rem; }
    .modal-back.open { display:flex; }
    .modal-box { background:#fff; border-radius:2.5rem; width:100%; max-height:95vh; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 25px 50px -12px rgba(0,0,0,.25); }
    .modal-head { padding:2rem 2.5rem 1.25rem; margin:0; border-bottom:1px solid #f1f5f9; display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; }
    .modal-title { font-size:1.5rem; font-weight:900; color:#0f172a; letter-spacing:-.025em; line-height:1.2; }
    .modal-sub { font-size:.75rem; color:var(--accent-600); font-weight:700; text-transform:uppercase; letter-spacing:.1em; margin-top:.25rem; }
    .modal-close { padding:.5rem; border-radius:999px; color:#94a3b8; transition:all .15s; flex-shrink:0; }
    .modal-close:hover { background:#eef2ff; color:var(--accent-600); }
    .modal-body { padding:1.5rem 2.5rem; overflow:auto; flex:1; min-height:0; }
    .modal-foot { padding:1.25rem 2.5rem 2rem; display:flex; justify-content:flex-end; align-items:center; gap:.75rem; flex-wrap:wrap; }
    @media (max-width:640px) { .modal-head, .modal-body, .modal-foot { padding-left:1.25rem; padding-right:1.25rem; } .modal-box { border-radius:1.75rem; } }
    /* Section header inside modals: white icon on the accent tile + accent title */
    .sec-head { display:flex; align-items:center; gap:.75rem; margin-bottom:1rem; }
    .sec-head .material-symbols-outlined { color:#fff; background:var(--accent-600); padding:.375rem; border-radius:.5rem; font-size:.875rem; box-shadow:0 4px 6px -1px rgba(99,102,241,.2); }
    .sec-head h4 { font-size:.875rem; font-weight:900; color:var(--accent-600); text-transform:uppercase; letter-spacing:-.01em; }
    .sec-box { border:1px solid #f1f5f9; border-radius:1.5rem; padding:1.25rem; }

    /* Buttons (Resident buttons: rounded-xl / rounded-2xl, bold uppercase) */
    .btn { display:inline-flex; align-items:center; justify-content:center; gap:.4rem; padding:.7rem 1.25rem; border-radius:1rem; font-size:.7rem; font-weight:900; text-transform:uppercase; letter-spacing:.06em; transition:all .15s; white-space:nowrap; }
    .btn .material-symbols-outlined { font-size:1.05rem; }
    .btn:disabled { opacity:.45; cursor:not-allowed; }
    .btn:active:not(:disabled) { transform:scale(.97); }
    .btn-dark, .btn-accent { background:var(--accent-600); color:#fff; box-shadow:0 10px 15px -3px rgba(99,102,241,.15); }
    .btn-dark:hover:not(:disabled), .btn-accent:hover:not(:disabled) { background:var(--accent-700); }
    .btn-ghost { background:#fff; color:#64748b; border:1px solid #e2e8f0; }
    .btn-ghost:hover:not(:disabled) { color:#334155; border-color:#cbd5e1; }
    .btn-green { background:#10b981; color:#fff; box-shadow:0 10px 15px -3px rgba(16,185,129,.2); } .btn-green:hover:not(:disabled) { background:#059669; }
    .btn-red { background:#ef4444; color:#fff; box-shadow:0 10px 15px -3px rgba(239,68,68,.2); } .btn-red:hover:not(:disabled) { background:#dc2626; }
    .btn-sm { padding:.4rem .8rem; border-radius:.75rem; font-size:.62rem; }
    .icon-btn { padding:.5rem; color:#94a3b8; border-radius:.5rem; transition:all .15s; display:inline-flex; }
    .icon-btn:hover { color:var(--accent-600); background:#eef2ff; }
    .icon-btn.danger:hover { color:#e11d48; background:#fff1f2; }

    /* Form fields (Resident inputs: slate-100, no border) */
    .field-label { display:block; font-size:10px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:#94a3b8; margin:0 0 .375rem .25rem; }
    /* :is(input,select,textarea).input outranks Tailwind forms' [type='text'] / [type='date'] rules
       (loaded after this sheet by the CDN), so every field type gets the same look. */
    .input, :is(input,select,textarea).input { width:100%; border-radius:.75rem; border:0; background-color:#f1f5f9; font-size:.875rem; font-weight:700; padding:.75rem 1rem; color:#1e293b; box-shadow:none; }
    .input:focus, :is(input,select,textarea).input:focus { outline:none; box-shadow:0 0 0 2px rgba(99,102,241,.2); background-color:#f1f5f9; border-color:transparent; }
    select.input { padding-right:2.5rem; }
    .input::placeholder { color:#94a3b8; font-weight:600; }

    /* Wizard steps */
    .steps { display:flex; align-items:center; gap:.35rem; flex-wrap:wrap; }
    .step-dot { display:flex; align-items:center; gap:.4rem; font-size:.62rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; }
    .step-dot .n { width:24px; height:24px; border-radius:.5rem; background:#f1f5f9; color:#64748b; display:flex; align-items:center; justify-content:center; font-size:.68rem; }
    .step-dot.active { color:var(--accent-600); } .step-dot.active .n { background:var(--accent-600); color:#fff; box-shadow:0 4px 6px -1px rgba(99,102,241,.25); }
    .step-dot.done .n { background:#10b981; color:#fff; }
    .step-line { width:18px; height:2px; background:#e2e8f0; border-radius:2px; }

    /* Toasts (Resident toast) */
    #toast-wrap { position:fixed; top:1.25rem; right:1.25rem; z-index:100000; display:flex; flex-direction:column; gap:.6rem; pointer-events:none; }
    .toast { pointer-events:auto; min-width:280px; max-width:380px; padding:.85rem 1.1rem; border-radius:1rem; font-size:.75rem; font-weight:700; box-shadow:0 8px 28px rgba(0,0,0,.14); border:1px solid; display:flex; gap:.75rem; align-items:center; }
    .toast-success { background:#ecfdf5; border-color:#a7f3d0; color:#065f46; }
    .toast-error { background:#fff1f2; border-color:#fecaca; color:#991b1b; }
    .toast-info { background:#eff6ff; border-color:#bfdbfe; color:#1e40af; }
    .toast-warning { background:#fffbeb; border-color:#fde68a; color:#92400e; }

    /* Dark mode (same overrides as the Resident module) */
    html.dark body { background:#0f172a; color:#e2e8f0; }
    html.dark .bg-white, html.dark .modal-box, html.dark .card, html.dark .stat-card, html.dark .table-card, html.dark .search-pill, html.dark .select-pill, html.dark .btn-ghost { background:#1e293b !important; }
    html.dark .bg-slate-50 { background:#0f172a !important; }
    html.dark .text-slate-900, html.dark .text-slate-800, html.dark .modal-title, html.dark .stat-value { color:#f1f5f9 !important; }
    html.dark .text-slate-700 { color:#cbd5e1 !important; }
    html.dark .text-slate-600 { color:#94a3b8 !important; }
    html.dark .border-slate-100, html.dark .border-slate-200, html.dark .modal-head, html.dark .card, html.dark .stat-card, html.dark .table-card, html.dark .sec-box { border-color:#334155 !important; }
    html.dark .input, html.dark :is(input,select,textarea).input { background-color:#0f172a; color:#e2e8f0; }
    html.dark .table-card thead tr { background:#0f172a; }
</style>
<script>
// Small shared helpers for the Certificates pages.
window.CERT = (function(){
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    function esc(v){ const x = document.createElement('div'); x.textContent = v == null ? '' : String(v); return x.innerHTML; }
    function toast(msg, type){
        let wrap = document.getElementById('toast-wrap');
        if (!wrap) { wrap = document.createElement('div'); wrap.id = 'toast-wrap'; document.body.appendChild(wrap); }
        const icons = { success:'check_circle', error:'error', info:'info', warning:'warning' };
        const t = document.createElement('div');
        t.className = 'toast toast-' + (type || 'success');
        t.innerHTML = '<span class="material-symbols-outlined text-lg">' + (icons[type || 'success']) + '</span><span class="flex-1">' + esc(msg) + '</span>';
        wrap.appendChild(t);
        setTimeout(function(){ t.style.opacity = '0'; t.style.transition = 'opacity .3s'; setTimeout(function(){ t.remove(); }, 300); }, 4500);
    }
    async function getJSON(url){
        const r = await fetch(url, { headers:{ 'Accept':'application/json' }, credentials:'same-origin' });
        let d = null; try { d = await r.json(); } catch (e) {}
        if (!d) throw new Error('Server error (' + r.status + '). Please refresh and try again.');
        return d;
    }
    async function post(url, data){
        const fd = data instanceof FormData ? data : new FormData();
        if (!(data instanceof FormData)) Object.keys(data || {}).forEach(function(k){ fd.append(k, typeof data[k] === 'object' && data[k] !== null ? JSON.stringify(data[k]) : data[k]); });
        fd.append('csrf_token', csrf);
        const r = await fetch(url, { method:'POST', body:fd, headers:{ 'Accept':'application/json', 'X-CSRF-Token':csrf }, credentials:'same-origin' });
        let d = null; try { d = await r.json(); } catch (e) {}
        if (!d) throw new Error('Server error (' + r.status + '). Please refresh and try again.');
        return d;
    }
    function open(id){ document.getElementById(id).classList.add('open'); }
    function close(id){ document.getElementById(id).classList.remove('open'); }
    /** Confirmation dialog → Promise<boolean>. */
    function confirmBox(opts){
        return new Promise(function(resolve){
            const back = document.createElement('div');
            back.className = 'modal-back open'; back.style.zIndex = 90;
            back.innerHTML = '<div class="bg-white rounded-[2rem] shadow-2xl w-full p-8 space-y-6" style="max-width:28rem">' +
                '<div class="flex items-start gap-4"><div class="w-12 h-12 rounded-2xl flex items-center justify-center shrink-0 ' + (opts.danger ? 'bg-rose-50 text-rose-500' : 'bg-indigo-50 text-indigo-500') + '"><span class="material-symbols-outlined text-2xl">' + (opts.icon || 'help') + '</span></div>' +
                '<div class="flex-1 min-w-0"><h3 class="text-base font-black text-slate-800 leading-tight tracking-tight">' + esc(opts.title) + '</h3><p class="text-xs text-slate-500 font-medium mt-1.5 leading-relaxed">' + esc(opts.message) + '</p></div></div>' +
                '<div class="flex gap-3 pt-2"><button type="button" class="flex-1 py-3.5 text-xs font-black uppercase text-slate-400 hover:text-slate-700 border border-slate-200 hover:border-slate-300 rounded-2xl transition-all" data-r="0">' + esc(opts.cancel || 'Cancel') + '</button>' +
                '<button type="button" class="flex-[2] py-3.5 text-white text-xs font-black uppercase rounded-2xl shadow-lg active:scale-95 transition-all ' + (opts.danger ? 'bg-rose-500 hover:bg-rose-600' : 'bg-primary hover:opacity-90') + '" data-r="1">' + esc(opts.ok || 'Confirm') + '</button></div></div>';
            document.body.appendChild(back);
            back.addEventListener('click', function(e){
                const b = e.target.closest('[data-r]');
                if (!b && e.target !== back) return;
                back.remove(); resolve(!!(b && b.dataset.r === '1'));
            });
        });
    }
    return { csrf:csrf, esc:esc, toast:toast, getJSON:getJSON, post:post, open:open, close:close, confirm:confirmBox };
})();
</script>

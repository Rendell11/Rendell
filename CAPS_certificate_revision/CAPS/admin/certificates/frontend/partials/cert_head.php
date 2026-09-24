<?php
/**
 * Shared <head> content for the Certificates pages (same look as the Officials
 * and Staff/BPSO modules). Set $pageTitle before including.
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
<style>
    :root { --sidebar-w: 288px; }
    body { font-family:'Plus Jakarta Sans',sans-serif; background:var(--page-bg,#eef2fb); -webkit-font-smoothing:antialiased; }
    .main-wrapper { margin-left:var(--sidebar-w); width:calc(100% - var(--sidebar-w)); }
    @media (max-width:1024px) { .main-wrapper { margin-left:0; width:100%; } }
    .section-title { font-size:.65rem; font-weight:800; letter-spacing:.12em; text-transform:uppercase; color:#94a3b8; }
    .hero-band { background:linear-gradient(135deg,var(--accent-700) 0%,var(--accent-600) 50%,var(--accent-700) 100%); }
    .card { border:1px solid rgba(148,163,184,.22); box-shadow:0 8px 30px rgba(15,23,42,.04); }
    .pill { display:inline-flex; align-items:center; gap:.25rem; padding:.2rem .6rem; border-radius:999px; border:1px solid; font-size:.62rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; white-space:nowrap; }
    .pill .material-symbols-outlined { font-size:13px; }
    .modal-back { position:fixed; inset:0; z-index:60; background:rgba(2,6,23,.6); backdrop-filter:blur(4px); display:none; align-items:center; justify-content:center; padding:1rem; }
    .modal-back.open { display:flex; }
    .modal-box { background:#fff; border-radius:1.5rem; width:100%; max-height:calc(100vh - 2rem); display:flex; flex-direction:column; overflow:hidden; box-shadow:0 25px 60px rgba(0,0,0,.3); }
    .modal-head { padding:1rem 1.25rem; border-bottom:1px solid #f1f5f9; display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; }
    .modal-body { padding:1.25rem; overflow:auto; flex:1; min-height:0; }
    .modal-foot { padding:.85rem 1.25rem; border-top:1px solid #f1f5f9; display:flex; justify-content:flex-end; align-items:center; gap:.5rem; flex-wrap:wrap; }
    .btn { display:inline-flex; align-items:center; justify-content:center; gap:.35rem; padding:.6rem 1rem; border-radius:.75rem; font-size:.65rem; font-weight:900; text-transform:uppercase; letter-spacing:.08em; transition:.15s; }
    .btn .material-symbols-outlined { font-size:17px; }
    .btn:disabled { opacity:.45; cursor:not-allowed; }
    .btn-dark { background:#0f172a; color:#fff; } .btn-dark:hover:not(:disabled) { background:#1e293b; }
    .btn-accent { background:var(--accent-600); color:#fff; } .btn-accent:hover:not(:disabled) { background:var(--accent-700); }
    .btn-ghost { background:#fff; color:#334155; border:1px solid #e2e8f0; } .btn-ghost:hover:not(:disabled) { background:#f8fafc; }
    .btn-green { background:#10b981; color:#fff; } .btn-green:hover:not(:disabled) { background:#059669; }
    .btn-red { background:#ef4444; color:#fff; } .btn-red:hover:not(:disabled) { background:#dc2626; }
    .field-label { display:block; font-size:.62rem; font-weight:800; letter-spacing:.1em; text-transform:uppercase; color:#94a3b8; margin-bottom:.3rem; }
    .input { width:100%; border-radius:.75rem; border:1px solid #e2e8f0; background:#f8fafc; font-size:.85rem; font-weight:600; padding:.55rem .8rem; }
    .input:focus { border-color:var(--accent-400,#60a5fa); box-shadow:0 0 0 4px rgba(59,130,246,.1); background:#fff; }
    .steps { display:flex; align-items:center; gap:.35rem; flex-wrap:wrap; }
    .step-dot { display:flex; align-items:center; gap:.4rem; font-size:.62rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; }
    .step-dot .n { width:22px; height:22px; border-radius:999px; background:#f1f5f9; color:#64748b; display:flex; align-items:center; justify-content:center; font-size:.68rem; }
    .step-dot.active { color:#0f172a; } .step-dot.active .n { background:var(--accent-600); color:#fff; }
    .step-dot.done .n { background:#10b981; color:#fff; }
    .step-line { width:18px; height:2px; background:#e2e8f0; border-radius:2px; }
    #toast-wrap { position:fixed; top:1rem; right:1rem; z-index:100; display:flex; flex-direction:column; gap:.5rem; pointer-events:none; }
    .toast { pointer-events:auto; min-width:260px; max-width:380px; padding:.75rem 1rem; border-radius:1rem; font-size:.78rem; font-weight:700; box-shadow:0 8px 28px rgba(0,0,0,.14); border:1px solid; display:flex; gap:.5rem; align-items:flex-start; }
    .toast-success { background:#ecfdf5; border-color:#a7f3d0; color:#065f46; }
    .toast-error { background:#fff1f2; border-color:#fecaca; color:#991b1b; }
    .toast-info { background:#eff6ff; border-color:#bfdbfe; color:#1e40af; }
    .toast-warning { background:#fffbeb; border-color:#fde68a; color:#92400e; }
    html.dark body { background:#0f172a; color:#e2e8f0; }
    html.dark .bg-white, html.dark .modal-box { background:#1e293b !important; }
    html.dark .bg-slate-50 { background:#0f172a !important; }
    html.dark .text-slate-900, html.dark .text-slate-800 { color:#f1f5f9 !important; }
    html.dark .text-slate-700 { color:#cbd5e1 !important; }
    html.dark .text-slate-600 { color:#94a3b8 !important; }
    html.dark .border-slate-100, html.dark .border-slate-200, html.dark .modal-head, html.dark .modal-foot { border-color:#334155 !important; }
    html.dark .input { background:#0f172a; border-color:#334155; color:#e2e8f0; }
    html.dark .btn-ghost { background:#1e293b; color:#cbd5e1; border-color:#334155; }
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
            back.innerHTML = '<div class="modal-box" style="max-width:420px"><div class="modal-body">' +
                '<div class="flex gap-3"><span class="w-10 h-10 shrink-0 rounded-xl flex items-center justify-center ' + (opts.danger ? 'bg-red-50 text-red-600' : 'bg-slate-100 text-slate-700') + '"><span class="material-symbols-outlined">' + (opts.icon || 'help') + '</span></span>' +
                '<div><p class="text-base font-black text-slate-800">' + esc(opts.title) + '</p><p class="text-sm text-slate-500 mt-1 leading-relaxed">' + esc(opts.message) + '</p></div></div></div>' +
                '<div class="modal-foot"><button type="button" class="btn btn-ghost" data-r="0">' + esc(opts.cancel || 'Cancel') + '</button>' +
                '<button type="button" class="btn ' + (opts.danger ? 'btn-red' : 'btn-dark') + '" data-r="1">' + esc(opts.ok || 'Confirm') + '</button></div></div>';
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

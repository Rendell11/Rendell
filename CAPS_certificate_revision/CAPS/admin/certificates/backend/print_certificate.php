<?php
/**
 * print_certificate.php — the issued certificate at its real paper size.
 *   ?id=N&token=T  opened by "Print & Release": prints once (one-time token from the release action)
 *   ?id=N          view only — printing is blocked (released documents cannot be reprinted)
 * Uses the same renderer (cert_render.js) as the preview modal, so preview = print.
 */
require_once __DIR__ . '/../../db.php';
$required_module = 'certificates';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/cert_common.php';
cert_migrate($pdo);

$id = (int)($_GET['id'] ?? 0);
$req = cert_request($pdo, $id);
if (!$req) { http_response_code(404); exit('Document not found.'); }

$canPrint = false;
$tok = $_SESSION['cert_print_tokens'][$id] ?? null;
if ($tok && hash_equals($tok['t'], (string)($_GET['token'] ?? '')) && $tok['exp'] >= time() && $req['Status'] === CERT_ST_RELEASED) {
    $canPrint = true;
    unset($_SESSION['cert_print_tokens'][$id]); // one print only
}
$model = cert_render_model($pdo, $req);
$paper = $model['paper'];
if (!$canPrint) cert_log_activity('View Document', ($req['doc_number'] ?: '#' . $id) . ' viewed (view only)');
?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h(($req['doc_number'] ?: 'Document') . ' — ' . $req['DocType']); ?></title>
<style>
    @page { size: <?php echo h($paper['css']); ?>; margin: 0; }
    html, body { margin: 0; padding: 0; }
    body { background: #e2e8f0; font-family: Arial, sans-serif; }
    #host { padding: 24px 12px; }
    .cert-page { margin: 0 auto; box-shadow: 0 10px 30px rgba(15,23,42,.2); }
    .banner { max-width: <?php echo (int)$paper['w']; ?>px; margin: 16px auto 0; padding: 10px 14px; border-radius: 12px; font-size: 13px; font-weight: 700; }
    .banner.view { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
    @media print {
        body { background: #fff; }
        #host { padding: 0; }
        .banner { display: none; }
        .cert-page { box-shadow: none; transform: none !important; }
        #host > div { width: auto !important; height: auto !important; }
        <?php if (!$canPrint): ?>
        #host { display: none; }
        body::before { content: "Printing is disabled for this document (view only)."; display: block; padding: 40px; font: 16px Arial; }
        <?php endif; ?>
    }
</style>
</head>
<body>
<?php if (!$canPrint): ?>
<div class="banner view"><?php echo h($req['DocType'] . ' · ' . ($req['doc_number'] ?: '—') . ' · ' . $req['Status']); ?> — view only. <?php echo $req['Status'] === CERT_ST_RELEASED ? 'Released documents cannot be edited or reprinted.' : 'Use Print &amp; Release on the Certificates page to print.'; ?></div>
<?php endif; ?>
<div id="host"></div>
<script src="../frontend/assets/cert_render.js"></script>
<script>
(function(){
    const model = <?php echo json_encode($model, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const host = document.getElementById('host');
    <?php if ($canPrint): ?>
    // Real size for printing (no scaling).
    const pg = CertRender.page(model); host.appendChild(pg);
    function go(){ requestAnimationFrame(function(){ requestAnimationFrame(function(){ setTimeout(function(){ window.print(); }, 150); }); }); }
    const img = pg.querySelector('img');
    if (img && !img.complete) { img.addEventListener('load', go); img.addEventListener('error', go); } else { window.addEventListener('load', go); }
    window.onafterprint = function(){ try { window.close(); } catch (e) {} };
    <?php else: ?>
    CertRender.into(host, model, { width: Math.min(model.paper.w, window.innerWidth - 24) });
    window.addEventListener('beforeprint', function(){ alert('This document is view only. Printing is disabled.'); });
    <?php endif; ?>
})();
</script>
</body>
</html>

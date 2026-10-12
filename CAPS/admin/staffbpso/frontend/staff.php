<?php
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../permission_helper.php';
require_once __DIR__ . '/../backend/staffbpso_common.php';
staffbpso_migrate_schema($pdo);
staffbpso_duty_migrate_schema($pdo);
staffbpso_end_deceased($pdo); // auto End Term (reason: Deceased) for any resident marked deceased
$current_page = 'Staff and BPSO';

$staff_members=[]; $tanods=[]; $msg=$_GET['msg']??''; $status=$_GET['status']??'';
try {
  // EffectiveEnd is only ever set (to CURDATE()) the moment End Term is
  // used — staff/BPSO assignments have no separate "planned future end"
  // like Officials' TermEnd, so any row with EffectiveEnd set at all is
  // already ended and must drop out of the current listing immediately.
  $staff_members=$pdo->query("SELECT s.*, r.ContactNumber, r.Email AS ResidentEmail FROM staff s LEFT JOIN residents r ON r.ResidentID=s.ResidentID WHERE LOWER(COALESCE(s.Position,'')) NOT IN ('tanod','barangay tanod','barangay tanod chief','bpso') AND s.EffectiveEnd IS NULL ORDER BY s.Name")->fetchAll();
  $tanods=$pdo->query("SELECT s.*, r.ContactNumber, r.Email AS ResidentEmail FROM staff s LEFT JOIN residents r ON r.ResidentID=s.ResidentID WHERE LOWER(COALESCE(s.Position,'')) IN ('tanod','barangay tanod','barangay tanod chief','bpso') AND s.EffectiveEnd IS NULL ORDER BY s.Name")->fetchAll();
} catch(Throwable $e){ $staff_members=[]; $tanods=[]; }
$total_staff=count($staff_members); $total_tanod=count($tanods);



function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
foreach([&$staff_members,&$tanods] as &$list){ foreach($list as &$row){ $row['_rel']=staffbpso_relocation($pdo,$row['ResidentID']??null,$row['EffectiveStart']?:null); } unset($row); } unset($list);
$blocked_count=count(array_filter(array_merge($staff_members,$tanods),fn($x)=>!empty($x['IsBlocked'])));
$relocated_count=count(array_filter(array_merge($staff_members,$tanods),fn($x)=>$x['_rel']&&empty($x['IsBlocked'])));
/* Relocation note + blocked note under a personnel's name. */
function staff_notes(array $s): string {
  $out='';
  if(!empty($s['IsBlocked'])) $out.='<div class="mt-2 rounded-lg bg-rose-50 border border-rose-100 px-2.5 py-1.5 text-[11px] text-rose-700"><b>Blocked</b>'.($s['BlockedAt']?' since '.h(date('M j, Y',strtotime($s['BlockedAt']))):'').($s['BlockReason']?' — '.h($s['BlockReason']):'').'. Account cannot be used.</div>';
  if($s['_rel']){ $r=$s['_rel'];
    $out.='<div class="mt-2 rounded-lg bg-amber-50 border border-amber-100 px-2.5 py-1.5 text-[11px] text-amber-800"><b>'.($r['moved_out']?'Relocated out of the barangay':'Relocated').'</b> on '.h(date('M j, Y',strtotime($r['date']))).'<br>Old: '.h($r['old_address']?:'—').'<br>New: '.h($r['new_address']?:'—').(empty($s['IsBlocked'])?'<br><span class="font-semibold">You may block this personnel until they move back.</span>':'').'</div>'; }
  return $out;
}
function staff_block_btn(array $s, string $cls=''): string {
  $data=json_encode(['id'=>$s['EmployeeID'],'name'=>$s['Name'],'position'=>$s['Position']?:'Staff','moved_out'=>!empty($s['_rel']['moved_out']),'relocated'=>!empty($s['_rel'])],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
  return !empty($s['IsBlocked'])
    ? "<button type=\"button\" onclick='unblockStaff($data)' class=\"px-3 py-2 rounded-lg bg-emerald-50 text-emerald-700 text-[10px] font-black uppercase $cls\">Unblock</button>"
    : "<button type=\"button\" onclick='blockStaff($data)' class=\"px-3 py-2 rounded-lg bg-amber-50 text-amber-700 text-[10px] font-black uppercase $cls\">Block</button>";
}
function tl($t){ return $t ? date('g:i A', strtotime($t)) : '—'; }
function dtl($t){ return $t ? date('g:i A', strtotime($t)) : '—'; }
?>
<!doctype html><html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Staff and BPSO — Barangay Biñang 2nd</title>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
<?php require_once __DIR__.'/../../theme_loader.php'; $_theme_head_loaded=true; require_once __DIR__.'/../../theme_head.php'; ?>

<script>tailwind.config={theme:{extend:{fontFamily:{sans:['"Plus Jakarta Sans"','sans-serif'],mono:['"DM Mono"','monospace']},colors:{primary:'var(--accent-600)',accent:'var(--accent-500)'}}}};</script>
<style>
:root{--sidebar-w:288px}
html,body{font-family:'Plus Jakarta Sans',sans-serif!important;background:var(--page-bg,#eef2fb);-webkit-font-smoothing:antialiased}
button,input,select,textarea,table{font-family:'Plus Jakarta Sans',sans-serif!important}
.main-wrapper{margin-left:var(--sidebar-w);width:calc(100% - var(--sidebar-w))}
@media(max-width:1024px){.main-wrapper{margin-left:0;width:100%}}
.section-title{font-size:.65rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#94a3b8}
.hero-band{background:linear-gradient(135deg,var(--accent-700) 0%,var(--accent-600) 50%,var(--accent-700) 100%);position:relative;overflow:hidden}
.card{border:1px solid rgba(148,163,184,.22);box-shadow:0 8px 30px rgba(15,23,42,.04)}
html.dark body{background:#0f172a!important;color:#e2e8f0}
html.dark .bg-white{background:#1e293b!important}
html.dark .bg-slate-50,html.dark .bg-slate-50\/50{background:#0f172a!important}
html.dark .bg-slate-100{background:#1e293b!important}
html.dark .text-slate-900{color:#f1f5f9!important}
html.dark .text-slate-800{color:#e2e8f0!important}
html.dark .text-slate-700{color:#cbd5e1!important}
html.dark .text-slate-600{color:#94a3b8!important}
html.dark .text-slate-500{color:#64748b!important}
html.dark .border-slate-100,html.dark .border-slate-200{border-color:#334155!important}
</style></head><body <?php echo $theme_attrs['body']; ?> class="text-slate-900 antialiased">
<div class="flex min-h-screen"><?php require __DIR__.'/../../sidebar.php'; ?><div class="flex-1 min-w-0 main-wrapper"><?php require __DIR__.'/../../header.php'; ?>
<main class="p-4 md:p-6 lg:p-8 space-y-6">
<section class="hero-band rounded-2xl p-6 md:p-8 text-white relative overflow-hidden">
    <div class="absolute -right-12 -top-12 w-64 h-64 opacity-10 rounded-full blur-3xl pointer-events-none" style="background:var(--accent-400);"></div>
    <div class="absolute left-1/3 bottom-0 w-48 h-48 opacity-10 rounded-full blur-2xl pointer-events-none" style="background:var(--accent-300);"></div>
    <div class="relative z-10 flex flex-col md:flex-row md:items-center md:justify-between gap-5">
        <div>
            <div class="flex items-center gap-2 text-white/60 text-[10px] font-black uppercase tracking-[0.18em] mb-2">
                <span class="material-symbols-outlined text-base">badge</span>Personnel Management
            </div>
            <h1 class="text-2xl md:text-3xl font-black tracking-tight leading-none">Staff and BPSO</h1>
            <p class="text-white/65 text-sm mt-2 font-medium max-w-2xl">Manage barangay staff, system access, and Barangay Public Safety Officers.</p>
        </div>
        <div class="flex flex-wrap gap-3 shrink-0">
        <a href="/CAPS/admin/staffbpso/frontend/create_staff.php" class="inline-flex items-center justify-center gap-2 bg-white/10 hover:bg-white/20 border border-white/20 text-white px-5 py-3 rounded-xl font-black text-xs uppercase tracking-wider transition-all">
            <span class="material-symbols-outlined text-lg">person_add</span>Add Personnel
        </a>
        <a href="/CAPS/admin/staffbpso/frontend/today_attendance.php" class="inline-flex items-center justify-center gap-2 bg-white/10 hover:bg-white/20 border border-white/20 text-white px-5 py-3 rounded-xl font-black text-xs uppercase tracking-wider transition-all">
            <span class="material-symbols-outlined text-lg">how_to_reg</span>Today's Attendance
        </a>
        <a href="/CAPS/admin/staffbpso/frontend/staff_analytics.php" class="inline-flex items-center justify-center gap-2 bg-white text-slate-900 hover:bg-white/90 px-5 py-3 rounded-xl font-black text-xs uppercase tracking-wider transition-all">
            <span class="material-symbols-outlined text-lg">monitoring</span>Staff Analytics
        </a>
        </div>
    </div>
</section>
<?php if($msg): ?><div class="rounded-xl px-4 py-3 text-sm font-semibold <?php echo $status==='error'?'bg-rose-50 text-rose-700':'bg-emerald-50 text-emerald-700'; ?>"><?php echo h($msg); ?></div><?php endif; ?>
<div class="grid grid-cols-1 md:grid-cols-3 gap-4"><div class="card bg-white rounded-2xl p-5"><p class="section-title">Total Personnel</p><p class="text-3xl font-black mt-2"><?php echo $total_staff+$total_tanod; ?></p></div><div class="card bg-white rounded-2xl p-5"><p class="section-title">Staff</p><p class="text-3xl font-black mt-2"><?php echo $total_staff; ?></p></div><div class="card bg-white rounded-2xl p-5"><p class="section-title">BPSO / Tanod</p><p class="text-3xl font-black mt-2"><?php echo $total_tanod; ?></p></div></div>
<?php if($relocated_count||$blocked_count): ?><div class="rounded-xl px-4 py-3 text-sm font-semibold bg-amber-50 text-amber-800 flex items-start gap-2"><span class="material-symbols-outlined text-lg">move_location</span><span><?php if($relocated_count) echo h($relocated_count).' personnel relocated (see the notes below). Block them to stop their account and duty until they move back, or use End Term to remove them. '; if($blocked_count) echo h($blocked_count).' blocked.'; ?></span></div><?php endif; ?>
<div class="bg-white rounded-2xl card overflow-hidden"><div class="p-5 md:p-6 border-b border-slate-100"><div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3"><div><p class="section-title">System Personnel</p><h2 class="text-lg font-black">Barangay Staff</h2></div></div></div>
<div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-slate-50 text-[10px] uppercase tracking-widest text-slate-400"><tr><th class="text-left px-5 py-4">Employee ID</th><th class="text-left px-5 py-4">Name</th><th class="text-left px-5 py-4">Position</th><th class="text-left px-5 py-4">Workload</th><th class="text-right px-5 py-4">Action</th></tr></thead><tbody class="divide-y divide-slate-100"><?php foreach($staff_members as $s): ?><tr><td class="px-5 py-4 font-mono text-xs font-bold"><?php echo h($s['EmployeeID']); ?></td><td class="px-5 py-4"><div class="font-bold"><?php echo h($s['Name']); ?></div><div class="text-xs text-slate-400"><?php echo h($s['WorkEmail']); ?></div><?php echo staff_notes($s); ?></td><td class="px-5 py-4 font-semibold"><?php echo h($s['Position']?:'Staff'); ?><?php if(!empty($s['IsBlocked'])): ?><span class="ml-1 px-2 py-0.5 rounded-full bg-rose-50 text-rose-700 text-[9px] font-black uppercase">Blocked</span><?php endif; ?></td><td class="px-5 py-4 text-xs text-slate-500 max-w-xs"><?php echo h($s['WorkLoad']?:'General'); ?></td><td class="px-5 py-4"><div class="flex justify-end gap-2"><a href="/CAPS/admin/staffbpso/frontend/create_staff.php?id=<?php echo urlencode($s['EmployeeID']); ?>" class="px-3 py-2 rounded-lg bg-slate-100 text-slate-700 text-[10px] font-black uppercase">Edit</a><a href="/CAPS/admin/officials/frontend/resident_profile.php?from=staff&resident_id=<?php echo urlencode($s['ResidentID']); ?>" class="px-3 py-2 rounded-lg bg-indigo-50 text-indigo-700 text-[10px] font-black uppercase">History</a><?php echo staff_block_btn($s); ?><button type="button" onclick='startEndTerm(<?php echo json_encode(['id'=>$s['EmployeeID'],'name'=>$s['Name'],'position'=>$s['Position']?:'Staff'],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>)' class="px-3 py-2 rounded-lg bg-rose-50 text-rose-600 text-[10px] font-black uppercase">End Term</button></div></td></tr><?php endforeach; if(!$staff_members): ?><tr><td colspan="5" class="px-5 py-12 text-center text-slate-400">No staff personnel found.</td></tr><?php endif; ?></tbody></table></div></div>

<div class="bg-white rounded-2xl card overflow-hidden"><div class="p-5 md:p-6 border-b border-slate-100 flex flex-col md:flex-row md:items-center md:justify-between gap-3"><div><p class="section-title">Public Safety</p><h2 class="text-lg font-black">Barangay Tanod / BPSO</h2><p class="text-xs text-slate-400 mt-1">BPSO personnel are linked to existing Resident profiles.</p></div><div class="flex gap-2"><a href="/CAPS/admin/staffbpso/frontend/tanod_management.php" class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-700 text-[10px] font-black uppercase tracking-widest">BPSO Duty & Schedule</a></div></div>
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 p-5 md:p-6"><?php foreach($tanods as $s): ?><div class="rounded-2xl border border-slate-200/70 p-5"><div class="flex items-start justify-between gap-3"><div><p class="section-title">BPSO / Tanod</p><h3 class="font-black mt-1"><?php echo h($s['Name']); ?></h3><p class="font-mono text-[10px] text-slate-400 mt-1"><?php echo h($s['EmployeeID']); ?></p></div><?php if(!empty($s['IsBlocked'])): ?><span class="px-2 py-1 rounded-full bg-rose-50 text-rose-700 text-[9px] font-black uppercase">Blocked</span><?php else: ?><span class="px-2 py-1 rounded-full bg-emerald-50 text-emerald-700 text-[9px] font-black uppercase">Active</span><?php endif; ?></div><?php echo staff_notes($s); ?><div class="mt-4 text-xs text-slate-500 space-y-1"><div>Contact: <?php echo h($s['ContactNumber']?:'—'); ?></div><div>Resident ID: <?php echo h($s['ResidentID']?:'—'); ?></div></div><div class="mt-4 flex gap-2"><a href="/CAPS/admin/staffbpso/frontend/create_staff.php?id=<?php echo urlencode($s['EmployeeID']); ?>" class="flex-1 text-center px-3 py-2 rounded-lg bg-slate-100 text-[10px] font-black uppercase">Edit</a><a href="/CAPS/admin/officials/frontend/resident_profile.php?from=staff&resident_id=<?php echo urlencode($s['ResidentID']); ?>" class="px-3 py-2 rounded-lg bg-indigo-50 text-indigo-700 text-[10px] font-black uppercase">History</a><?php echo staff_block_btn($s); ?><button type="button" onclick='startEndTerm(<?php echo json_encode(['id'=>$s['EmployeeID'],'name'=>$s['Name'],'position'=>$s['Position']?:'BPSO'],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>)' class="px-3 py-2 rounded-lg bg-rose-50 text-rose-600 text-[10px] font-black uppercase">End Term</button></div></div><?php endforeach; if(!$tanods): ?><div class="md:col-span-2 xl:col-span-3 rounded-2xl border border-dashed border-slate-200 p-10 text-center text-slate-400">No BPSO / Tanod personnel found.</div><?php endif; ?></div></div>

<!-- Block / Unblock -->
<div id="blockModal" class="fixed inset-0 hidden z-[60] bg-slate-950/60 backdrop-blur-sm p-4 items-center justify-center">
  <div class="bg-white rounded-[28px] w-full max-w-md p-6 md:p-8">
    <div class="flex items-start gap-3"><span class="material-symbols-outlined text-3xl text-amber-500">block</span><div><h2 class="text-xl font-black text-slate-800">Block Personnel</h2><p id="blockWho" class="text-sm text-slate-500 mt-1"></p></div></div>
    <p class="text-xs text-slate-500 mt-3">The record is kept. While blocked, their work email cannot log in and they are left out of duty schedules and attendance. Unblock them when they move back. To remove them for good, use End Term.</p>
    <form action="/CAPS/admin/staffbpso/backend/process_staff.php" method="post" onsubmit="return submitBlock()" class="mt-5 space-y-4">
      <input type="hidden" name="csrf_token" value="<?php echo h(staffbpso_csrf_token()); ?>">
      <input type="hidden" name="block_staff" value="1">
      <input type="hidden" name="EmployeeID" id="blockEmployeeId">
      <input type="hidden" name="reason" id="blockReasonFinal">
      <label class="block"><span class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Reason</span>
        <select id="blockReasonSelect" onchange="document.getElementById('blockReasonOther').classList.toggle('hidden', this.value!=='Other')" class="w-full mt-1.5 rounded-2xl border-slate-200 py-3 text-sm font-semibold">
          <option value="Relocated (moved out of the barangay)">Relocated (moved out of the barangay)</option>
          <option value="Relocated (within the barangay)">Relocated (within the barangay)</option>
          <option value="Other">Other (please specify)</option>
        </select>
      </label>
      <label id="blockReasonOther" class="block hidden"><span class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Please specify</span><input type="text" id="blockReasonText" maxlength="255" class="w-full mt-1.5 rounded-2xl border-slate-200 py-3 text-sm"></label>
      <div class="flex justify-end gap-3 pt-2"><button type="button" onclick="closeModal('blockModal')" class="px-4 py-3 text-xs font-bold text-slate-500">Cancel</button><button type="submit" class="px-5 py-3 rounded-xl bg-amber-500 text-white text-[10px] font-bold uppercase tracking-wider">Block</button></div>
    </form>
  </div>
</div>
<form id="unblockForm" action="/CAPS/admin/staffbpso/backend/process_staff.php" method="post" class="hidden">
  <input type="hidden" name="csrf_token" value="<?php echo h(staffbpso_csrf_token()); ?>">
  <input type="hidden" name="unblock_staff" value="1">
  <input type="hidden" name="EmployeeID" id="unblockEmployeeId">
</form>

<!-- End Term: step 1 (confirm) -->
<div id="endTermConfirmModal" class="fixed inset-0 hidden z-[60] bg-slate-950/60 backdrop-blur-sm p-4 items-center justify-center">
  <div class="bg-white rounded-[28px] w-full max-w-md p-6 md:p-8">
    <div class="flex items-start gap-3"><span class="material-symbols-outlined text-3xl text-amber-500">warning</span><div><h2 class="text-xl font-black text-slate-800">End This Assignment?</h2><p id="endTermConfirmWho" class="text-sm text-slate-500 mt-2"></p></div></div>
    <div class="flex justify-end gap-3 mt-6"><button type="button" onclick="closeModal('endTermConfirmModal')" class="px-4 py-3 text-xs font-bold text-slate-500">Cancel</button><button type="button" onclick="closeModal('endTermConfirmModal');openModal('endTermReasonModal')" class="px-5 py-3 rounded-xl bg-red-600 text-white text-[10px] font-bold uppercase tracking-wider">Yes, End Term</button></div>
  </div>
</div>

<!-- End Term: step 2 (reason) -->
<div id="endTermReasonModal" class="fixed inset-0 hidden z-[60] bg-slate-950/60 backdrop-blur-sm p-4 items-center justify-center">
  <div class="bg-white rounded-[28px] w-full max-w-md p-6 md:p-8">
    <h2 class="text-xl font-black text-slate-800">End Term</h2>
    <p id="endTermWho" class="text-sm text-slate-500 mt-1"></p>
    <form id="endTermReasonForm" onsubmit="return proceedToFinalConfirm(event)" class="mt-5 space-y-4">
      <label class="block"><span class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Reason for Ending Term</span>
        <select id="endTermReasonSelect" onchange="document.getElementById('endTermReasonOther').classList.toggle('hidden', this.value!=='Other')" class="w-full mt-1.5 rounded-2xl border-slate-200 py-3 text-sm font-semibold">
          <option value="">Select reason...</option>
          <option value="Resigned">Resigned</option>
          <option value="Removed / Relieved">Removed / Relieved</option>
          <option value="Suspended">Suspended</option>
          <option value="Deceased">Deceased</option>
          <option value="Transferred / Relocated">Transferred / Relocated</option>
          <option value="Health Reasons">Health Reasons</option>
          <option value="End of Contract">End of Contract</option>
          <option value="Other">Other (please specify)</option>
        </select>
      </label>
      <label id="endTermReasonOther" class="block hidden"><span class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Please specify</span><input type="text" id="endTermReasonText" maxlength="255" class="w-full mt-1.5 rounded-2xl border-slate-200 py-3 text-sm"></label>
      <div class="flex justify-end gap-3 pt-2"><button type="button" onclick="closeModal('endTermReasonModal')" class="px-4 py-3 text-xs font-bold text-slate-500">Cancel</button><button type="submit" class="px-5 py-3 rounded-xl bg-red-600 text-white text-[10px] font-bold uppercase tracking-wider">Continue</button></div>
    </form>
  </div>
</div>

<!-- End Term: step 3 (final confirmation before the assignment is actually ended) -->
<div id="endTermFinalModal" class="fixed inset-0 hidden z-[60] bg-slate-950/60 backdrop-blur-sm p-4 items-center justify-center">
  <div class="bg-white rounded-[28px] w-full max-w-md p-6 md:p-8">
    <div class="flex items-start gap-3"><span class="material-symbols-outlined text-3xl text-red-600">event_busy</span><div><h2 class="text-xl font-black text-slate-800">Confirm: End Term</h2><p class="text-sm text-slate-500 mt-2">This will end the assignment for:</p></div></div>
    <div class="mt-4 rounded-2xl bg-slate-50 p-4 space-y-1.5">
      <p id="endTermFinalWho" class="font-bold text-sm text-slate-800"></p>
      <p class="text-xs text-slate-500">Reason: <span id="endTermFinalReason" class="font-semibold text-slate-700"></span></p>
    </div>
    <p class="text-xs text-slate-400 mt-3">This action cannot be undone. The end date, reason, and who ended the assignment will all be recorded automatically. This resident will become available again in Add Staff and Add Official, and this record stays visible in Yearly History. Their resident profile and previous history are not deleted.</p>
    <form id="endTermForm" action="/CAPS/admin/staffbpso/backend/process_staff.php" method="post" class="mt-5">
      <input type="hidden" name="csrf_token" value="<?php echo h(staffbpso_csrf_token()); ?>">
      <input type="hidden" name="end_term" value="1">
      <input type="hidden" name="EmployeeID" id="endTermEmployeeId">
      <input type="hidden" name="reason" id="endTermReasonFinal">
      <div class="flex justify-end gap-3"><button type="button" onclick="closeModal('endTermFinalModal');openModal('endTermReasonModal')" class="px-4 py-3 text-xs font-bold text-slate-500">Cancel</button><button type="submit" class="px-5 py-3 rounded-xl bg-red-600 text-white text-[10px] font-bold uppercase tracking-wider">Yes, End Term</button></div>
    </form>
  </div>
</div>

<script>
function openModal(id){const e=document.getElementById(id);e.classList.remove('hidden');e.classList.add('flex')}
function closeModal(id){const e=document.getElementById(id);e.classList.add('hidden');e.classList.remove('flex')}
function blockStaff(p){
  document.getElementById('blockWho').textContent = p.name+' — '+p.position;
  document.getElementById('blockEmployeeId').value = p.id;
  document.getElementById('blockReasonSelect').value = p.relocated && !p.moved_out ? 'Relocated (within the barangay)' : 'Relocated (moved out of the barangay)';
  document.getElementById('blockReasonText').value = '';
  document.getElementById('blockReasonOther').classList.add('hidden');
  openModal('blockModal');
}
function submitBlock(){
  const sel = document.getElementById('blockReasonSelect').value;
  const reason = sel==='Other' ? document.getElementById('blockReasonText').value.trim() : sel;
  if (!reason){ alert('Please enter a reason.'); return false; }
  document.getElementById('blockReasonFinal').value = reason;
  return true;
}
function unblockStaff(p){
  if (!confirm('Unblock '+p.name+' ('+p.position+')? Their account and duty can be used again.')) return;
  document.getElementById('unblockEmployeeId').value = p.id;
  document.getElementById('unblockForm').submit();
}
var _endTermTarget = null;
function startEndTerm(person){
  _endTermTarget = person;
  document.getElementById('endTermConfirmWho').textContent = 'End the assignment for '+person.name+' ('+person.position+')? They will stop appearing as current personnel, but stay on record in Yearly History.';
  document.getElementById('endTermWho').textContent = person.name+' — '+person.position;
  document.getElementById('endTermReasonSelect').value = '';
  document.getElementById('endTermReasonText').value = '';
  document.getElementById('endTermReasonOther').classList.add('hidden');
  openModal('endTermConfirmModal');
}
function proceedToFinalConfirm(e){
  e.preventDefault();
  const sel = document.getElementById('endTermReasonSelect').value;
  const other = document.getElementById('endTermReasonText').value.trim();
  const reason = sel==='Other' ? other : sel;
  if (!reason){ alert('Please select or enter a reason for ending this assignment.'); return false; }
  document.getElementById('endTermEmployeeId').value = _endTermTarget.id;
  document.getElementById('endTermReasonFinal').value = reason;
  document.getElementById('endTermFinalWho').textContent = _endTermTarget.name+' — '+_endTermTarget.position;
  document.getElementById('endTermFinalReason').textContent = reason;
  closeModal('endTermReasonModal');
  openModal('endTermFinalModal');
  return false;
}
</script>
</main></div></div></body></html>

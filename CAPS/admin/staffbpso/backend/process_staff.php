<?php
require_once __DIR__.'/../../db.php'; require_once __DIR__.'/../../auth_check.php'; require_once __DIR__.'/../../permission_helper.php'; require_once __DIR__.'/staffbpso_common.php'; staffbpso_migrate_schema($pdo);
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location:/CAPS/admin/staffbpso/frontend/staff.php');exit;}
function go($status,$msg){header('Location:/CAPS/admin/staffbpso/frontend/staff.php?status='.urlencode($status).'&msg='.urlencode($msg));exit;}
staffbpso_csrf_verify();
if(isset($_POST['end_term'])){
 // Soft-end instead of hard delete: keeps this personnel's row so Yearly
 // History (Year+Month) can still show them for past periods, even after
 // they've left. They're excluded from "current" listings via the
 // EffectiveEnd filter applied elsewhere, and immediately become available
 // again in Add Staff / Add Official. Mirrors Officials' End Term: a reason
 // is required and who ended it (and when) is recorded automatically.
 $id=(string)($_POST['EmployeeID']??''); $reason=trim($_POST['reason']??'');
 if(!$id) go('error','Personnel record not found.');
 if($reason==='') go('error','Please provide a reason for ending this assignment.');
 try{
  $cur=$pdo->prepare('SELECT * FROM staff WHERE EmployeeID=?'); $cur->execute([$id]); $row=$cur->fetch();
  if(!$row) go('error','Personnel record not found.');
  if(!empty($row['EffectiveEnd']) && $row['EffectiveEnd']<=date('Y-m-d')) go('error','This assignment has already been ended.');
  $actorName=staffbpso_actor_name(); $actorId=staffbpso_actor_id();
  $pdo->beginTransaction();
  $pdo->prepare('UPDATE staff SET EffectiveEnd=CURDATE(), EndReason=?, EndedBy=?, EndedByID=?, EndedAt=NOW() WHERE EmployeeID=? AND EffectiveEnd IS NULL')->execute([$reason,$actorName,$actorId,$id]);
  $pdo->commit();
  require_once __DIR__.'/../../activity_log_helper.php';log_activity('Staff','End Term','Ended personnel assignment EmployeeID: '.$id.' ('.$row['Position'].'). Reason: '.$reason.'.');
  go('success','Personnel assignment ended.');
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();go('error',$e->getMessage());}
}
// Block / Unblock (e.g. the resident relocated). The record stays; while blocked the
// work email cannot log in and the person is left out of duty schedules / attendance.
if(isset($_POST['block_staff'])||isset($_POST['unblock_staff'])){
 $block=isset($_POST['block_staff']); $id=(string)($_POST['EmployeeID']??''); $reason=trim($_POST['reason']??'');
 if(!$id) go('error','Personnel record not found.');
 if($block&&$reason==='') go('error','Please provide a reason for blocking.');
 try{
  $cur=$pdo->prepare('SELECT * FROM staff WHERE EmployeeID=?'); $cur->execute([$id]); $row=$cur->fetch();
  if(!$row) go('error','Personnel record not found.');
  if(!empty($row['EffectiveEnd'])) go('error','This assignment has already been ended.');
  if($block===!empty($row['IsBlocked'])) go('error',$block?'This personnel is already blocked.':'This personnel is not blocked.');
  $actorName=staffbpso_actor_name(); $actorId=staffbpso_actor_id();
  if($block) $pdo->prepare('UPDATE staff SET IsBlocked=1, BlockReason=?, BlockedBy=?, BlockedByID=?, BlockedAt=NOW(), UnblockedBy=NULL, UnblockedAt=NULL WHERE EmployeeID=?')->execute([mb_substr($reason,0,255),$actorName,$actorId,$id]);
  else $pdo->prepare('UPDATE staff SET IsBlocked=0, UnblockedBy=?, UnblockedAt=NOW() WHERE EmployeeID=?')->execute([$actorName,$id]);
  require_once __DIR__.'/../../activity_log_helper.php';
  log_activity('Staff',$block?'Block Staff':'Unblock Staff',($block?'Blocked ':'Unblocked ').$row['Name'].' ('.$id.', '.$row['Position'].')'.($block?'. Reason: '.$reason:'').'.');
  go('success',$block?'Personnel blocked. Their account cannot be used until unblocked.':'Personnel unblocked. Their account can be used again.');
 }catch(Throwable $e){go('error',$e->getMessage());}
}
$editing=isset($_POST['update_staff']); $resident_id=(string)($_POST['ResidentID']??''); $position=trim($_POST['Position']??'Staff'); $email=trim($_POST['WorkEmail']??''); $perms=$_POST['Permissions']??[];
if(!$resident_id) go('error','Please select an existing resident.');
// Tanod-category (BPSO/Barangay Tanod: no system login, no module
// permissions) is now derived straight from the chosen Position — there's
// no separate role toggle on the form anymore, Position is the only source
// of truth. This also makes the check work even if JS didn't run.
$tanod_positions=['tanod','barangay tanod','barangay tanod chief'];
$is_tanod=in_array(strtolower($position),$tanod_positions,true);
if($is_tanod){$perms=[];}
// Regular staff work schedule (Staff Attendance). Tanod duty comes from the weekly planner instead.
$work_start=preg_match('/^\d{2}:\d{2}(:\d{2})?$/',$_POST['WorkStart']??'')?$_POST['WorkStart']:'08:00';
$work_end=preg_match('/^\d{2}:\d{2}(:\d{2})?$/',$_POST['WorkEnd']??'')?$_POST['WorkEnd']:'17:00';
$work_days=array_values(array_intersect(array_map('intval',(array)($_POST['WorkDays']??[])),[1,2,3,4,5,6,7]));
$work_days=$work_days?implode(',',$work_days):'1,2,3,4,5';
if(!$is_tanod && $work_start===$work_end) go('error','Work start and end time cannot be the same.');

try{
 $r=$pdo->prepare('SELECT FirstName,LastName,Email FROM residents WHERE ResidentID=? AND IsDeceased=0');$r->execute([$resident_id]);$resident=$r->fetch();if(!$resident)go('error','Resident not found or deceased.');
 $name=trim($resident['FirstName'].' '.$resident['LastName']);
 if($editing){$id=$_POST['EmployeeID'];$chk=$pdo->prepare('SELECT EmployeeID FROM staff WHERE ResidentID=? AND EmployeeID<>? AND EffectiveEnd IS NULL');$chk->execute([$resident_id,$id]);if($chk->fetch())go('error','This resident already has another personnel role.');
   $cur=$pdo->prepare('SELECT * FROM staff WHERE EmployeeID=?');$cur->execute([$id]);$old=$cur->fetch();if(!$old)go('error','Personnel record not found.');
   $workload=array_keys($perms);$pdo->beginTransaction();
   // Work email is auto-generated on registration and never edited afterwards.
   $pdo->prepare('UPDATE staff SET Name=?,WorkLoad=?,WorkStart=?,WorkEnd=?,WorkDays=? WHERE EmployeeID=?')->execute([$name,$workload?implode(', ',$workload):null,$work_start,$work_end,$work_days,$id]);
   // Position changed: close the old position in the audit trail (ended today, reason, who)
   // and continue this same record (same EmployeeID / login) as the new position from today.
   if(strcasecmp(trim((string)$old['Position']),$position)!==0){
     $actorName=staffbpso_actor_name();$actorId=staffbpso_actor_id();
     $pdo->prepare('INSERT INTO staff_position_history(EmployeeID,ResidentID,Name,Position,StartDate,EndDate,EndReason,RecordedBy,RecordedAt,EndedBy,EndedByID,EndedAt) VALUES(?,?,?,?,?,CURDATE(),?,?,?,?,?,NOW())')
       ->execute([$id,$old['ResidentID'],$old['Name'],$old['Position'],$old['EffectiveStart']?:substr((string)$old['CreatedAt'],0,10),'Transferred to '.$position,$old['RecordedBy'],$old['CreatedAt'],$actorName,$actorId]);
     $pdo->prepare('UPDATE staff SET Position=?,EffectiveStart=CURDATE(),RecordedBy=?,RecordedByID=? WHERE EmployeeID=?')->execute([$position,$actorName,$actorId,$id]);
     require_once __DIR__.'/../../activity_log_helper.php';log_activity('Staff','Change Position',$name.' ('.$id.') reassigned from '.$old['Position'].' to '.$position.'.');
   }$pdo->prepare('DELETE FROM staff_permissions WHERE EmployeeID=?')->execute([$id]);$ins=$pdo->prepare('INSERT INTO staff_permissions(EmployeeID,ModuleKey,CanCreate,CanRead,CanUpdate,CanDelete) VALUES(?,?,?,?,?,?)');foreach($perms as $k=>$a)$ins->execute([$id,$k,isset($a['create'])?1:0,isset($a['read'])?1:0,isset($a['update'])?1:0,isset($a['delete'])?1:0]);$pdo->commit();require_once __DIR__.'/../../activity_log_helper.php';log_activity('Staff','Edit Staff','Updated personnel '.$id);go('success','Personnel record updated.');
 } else {$chk=$pdo->prepare('SELECT EmployeeID FROM staff WHERE ResidentID=? AND EffectiveEnd IS NULL');$chk->execute([$resident_id]);if($chk->fetch())go('error','This resident already has an assigned personnel role.');
  // A resident currently a sitting Official can't also become Staff/BPSO
  // until their official term is ended first (mirrors the exclusion
  // already applied to the Add Staff resident picker).
  $ochk=$pdo->prepare("SELECT OfficialID FROM officials WHERE ResidentID=? AND ActualEndDate IS NULL AND (TermEnd IS NULL OR TermEnd>=CURDATE())");$ochk->execute([$resident_id]);if($ochk->fetch())go('error','This resident is currently a sitting Official. End their official term first before assigning a Staff/BPSO role.');
  $year=date('Y');$c=$pdo->prepare("SELECT COUNT(*) FROM staff WHERE EmployeeID LIKE ?");$c->execute(['STF-'.$year.'-%']);$id='STF-'.$year.'-'.str_pad((int)$c->fetchColumn()+1,4,'0',STR_PAD_LEFT);$password=$is_tanod?null:password_hash(bin2hex(random_bytes(8)),PASSWORD_DEFAULT);$workload=$perms?implode(', ',array_keys($perms)):null;$email=staffbpso_work_email($pdo,$resident['FirstName'],$resident['LastName']);$recordedByName=staffbpso_actor_name();$recordedById=staffbpso_actor_id();$pdo->beginTransaction();$pdo->prepare('INSERT INTO staff(EmployeeID,Name,ResidentID,Position,WorkEmail,Password,WorkLoad,EffectiveStart,RecordedBy,RecordedByID,WorkStart,WorkEnd,WorkDays) VALUES(?,?,?,?,?,?,?,CURDATE(),?,?,?,?,?)')->execute([$id,$name,$resident_id,$position,$email,$password,$workload,$recordedByName,$recordedById,$work_start,$work_end,$work_days]);$ins=$pdo->prepare('INSERT INTO staff_permissions(EmployeeID,ModuleKey,CanCreate,CanRead,CanUpdate,CanDelete) VALUES(?,?,?,?,?,?)');foreach($perms as $k=>$a)$ins->execute([$id,$k,isset($a['create'])?1:0,isset($a['read'])?1:0,isset($a['update'])?1:0,isset($a['delete'])?1:0]);$pdo->commit();require_once __DIR__.'/../../activity_log_helper.php';log_activity('Staff','Create Staff','Registered '.$name.' ('.$id.'), Position: '.$position);go('success','Personnel registered successfully.');}
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();go('error',$e->getMessage());}

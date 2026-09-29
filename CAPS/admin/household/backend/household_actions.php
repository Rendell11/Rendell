<?php
declare(strict_types=1);
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../permission_helper.php';
require_once __DIR__ . '/../../activity_log_helper.php';
require_once __DIR__ . '/../../../login/csrf_helper.php';
require_once __DIR__ . '/household_common.php';
header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'error'=>'POST required.']);
    exit;
}
if(!hh_csrf_verify()) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Invalid security token.']);
    exit;
}
require_permission($pdo,'households','update');
hh_ensure_schema($pdo);
$action=(string)($_POST['action']??'');
$surveyId=(int)($_POST['survey_id']??0);
function hh_json_ok(array $data=[]): never {
    echo json_encode(['success'=>true]+$data);
    exit;
}
function hh_json_fail(string $message,int $code=400): never {
    http_response_code($code);
    echo json_encode(['success'=>false,'error'=>$message]);
    exit;
}
function get_hh(PDO $pdo,int $sid,bool $lock=false): array {
    $sql="SELECT hs.*, r.* FROM household_survey hs JOIN residents r ON r.ResidentID=hs.ResidentID WHERE hs.SurveyID=? LIMIT 1".($lock?' FOR UPDATE':'');
    $s=$pdo->prepare($sql);
    $s->execute([$sid]);
    $r=$s->fetch(PDO::FETCH_ASSOC);
    if(!$r)throw new RuntimeException('Household not found.');
    return $r;
}
function log_hh_history(PDO $pdo,int $sid,string $type,string $desc,?string $old=null,?string $new=null):void {
    $s=$pdo->prepare("INSERT INTO household_history (SurveyID,ActionType,Description,OldAddress,NewAddress,ActorName) VALUES (?,?,?,?,?,?)");
    $s->execute([$sid,$type,$desc,$old,$new,hh_actor_name()]);
}
function sync_hh_members(PDO $pdo,int $sid,int $headId):void {
    $pdo->prepare("DELETE FROM household_survey_members WHERE SurveyID=?")->execute([$sid]);
    $s=$pdo->prepare("SELECT * FROM residents WHERE FamilyHeadID=? AND (IsDeceased=0 OR IsDeceased IS NULL) ORDER BY LastName,FirstName,ResidentID");
    $s->execute([$headId]);
    $rows=$s->fetchAll(PDO::FETCH_ASSOC);
    $ins=$pdo->prepare("INSERT INTO household_survey_members (SurveyID,ResidentID,MemberNumber,full_name,sex,age,relationship,civil_status,education,monthly_income) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $n=1;
    foreach($rows as $r) {
        $age=$r['BirthDate']?(new DateTime($r['BirthDate']))->diff(new DateTime())->y:null;
        $ins->execute([$sid,$r['ResidentID'],$n++,hh_full_name($r),$r['Sex']??null,$age,$r['RelationshipToHead']??'Member',$r['CivilStatus']??null,$r['EducationLevel']??null,$r['TotalHouseholdIncome']??0]);
    }
}
function address_update_sql(): string {
    return "HouseNumber=?,BuildingName=?,StreetName=?,Purok=?,AreaName=?,AreaType=?,BarangayName=?,CityMunicipalityName=?,ProvinceName=?,RegionName=?,ZipCode=?,PSGCRegionCode=?,PSGCProvinceCode=?,PSGCMunicipalityCode=?,PSGCBarangayCode=?";
}
function address_values(array $p): array {
    return [
    trim((string)($p['HouseNumber']??''))?:null,trim((string)($p['BuildingName']??''))?:null,trim((string)($p['StreetName']??''))?:null,
    trim((string)($p['Purok']??''))?:null,trim((string)($p['AreaName']??''))?:null,trim((string)($p['AreaType']??''))?:null,
    trim((string)($p['BarangayName']??''))?:null,trim((string)($p['CityMunicipalityName']??''))?:null,trim((string)($p['ProvinceName']??''))?:null,
    trim((string)($p['RegionName']??''))?:null,trim((string)($p['ZipCode']??''))?:null,trim((string)($p['PSGCRegionCode']??''))?:null,
    trim((string)($p['PSGCProvinceCode']??''))?:null,trim((string)($p['PSGCMunicipalityCode']??''))?:null,trim((string)($p['PSGCBarangayCode']??''))?:null
    ];
}
/* Keep current values for address parts the form did not send. */
function address_values_keep(array $p,array $current): array {
    $keys=['HouseNumber','BuildingName','StreetName','Purok','AreaName','AreaType','BarangayName','CityMunicipalityName','ProvinceName','RegionName','ZipCode','PSGCRegionCode','PSGCProvinceCode','PSGCMunicipalityCode','PSGCBarangayCode'];
    $merged=[];
    foreach($keys as $k)$merged[$k]=array_key_exists($k,$p)?$p[$k]:($current[$k]??null);
    return address_values($merged);
}
/* Optional GPS pair from the form; null when not supplied or invalid. */
function posted_gps(array $p): ?array {
    $lat=trim((string)($p['Latitude']??''));$lng=trim((string)($p['Longitude']??''));
    if($lat===''||$lng===''||!is_numeric($lat)||!is_numeric($lng))return null;
    $lat=(float)$lat;$lng=(float)$lng;
    if($lat<-90||$lat>90||$lng<-180||$lng>180||($lat==0.0&&$lng==0.0))return null;
    return [$lat,$lng];
}
/*
 * Before a resident becomes a member of $targetHeadId:
 * - a head of another household that still has members is rejected;
 * - a head of a one-person household has that household set inactive
 *   (history kept, ID never reused) so no duplicate household remains;
 * Returns the previous head ResidentID (to refresh its member snapshot) or 0.
 */
function prepare_member_link(PDO $pdo,array $r,int $targetHeadId,string $targetHouseholdId): int {
    $rid=(int)$r['ResidentID'];
    if((int)($r['IsHead']??0)===1) {
        $c=$pdo->prepare("SELECT COUNT(*) FROM residents WHERE FamilyHeadID=? AND (IsDeceased=0 OR IsDeceased IS NULL)");
        $c->execute([$rid]);
        if((int)$c->fetchColumn()>0)throw new RuntimeException(hh_full_name($r).' is the head of another household that still has members. Change that household\'s head or remove it first.');
        $own=hh_active_survey_for_head($pdo,$rid);
        if($own) {
            $pdo->prepare("UPDATE household_survey SET status='inactive',is_removed=1,removal_reason='Other',removal_reason_other=?,removed_at=NOW(),inactive_since=NOW(),inactive_by=? WHERE SurveyID=?")
                ->execute(["Merged into {$targetHouseholdId}",hh_actor_name(),(int)$own['SurveyID']]);
            log_hh_history($pdo,(int)$own['SurveyID'],'DEACTIVATED',"Household {$own['HouseholdID']} was set inactive because its head joined {$targetHouseholdId} as a member.",hh_address($r),null);
        }
        return 0;
    }
    $prev=(int)($r['FamilyHeadID']??0);
    return ($prev>0&&$prev!==$targetHeadId)?$prev:0;
}
function resync_previous_household(PDO $pdo,int $prevHeadId,string $memberName,string $targetHouseholdId): void {
    if($prevHeadId<=0)return;
    $prev=hh_active_survey_for_head($pdo,$prevHeadId);
    if(!$prev)return;
    sync_hh_members($pdo,(int)$prev['SurveyID'],$prevHeadId);
    log_hh_history($pdo,(int)$prev['SurveyID'],'MEMBER_REMOVED',"{$memberName} moved to household {$targetHouseholdId}.");
}
/* Relocation: new household (new ID) for the head + listed members at a new address. */
function create_relocated_household(PDO $pdo,array $old,int $headId,array $ids,array $post): array {
    $hs=$pdo->prepare("SELECT * FROM residents WHERE ResidentID=? AND (IsDeceased=0 OR IsDeceased IS NULL) FOR UPDATE");
    $hs->execute([$headId]);
    $head=$hs->fetch(PDO::FETCH_ASSOC);
    if(!$head)throw new RuntimeException('Household head resident is unavailable.');
    $vals=address_values($post);
    $newAddr=hh_address($post);
    if(trim((string)($post['HouseNumber']??''))===''&&trim((string)($post['StreetName']??''))==='')throw new RuntimeException('Enter the new household address.');
    $ids=array_values(array_unique(array_merge([$headId],$ids)));
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $sel=$pdo->prepare("SELECT ResidentID FROM residents WHERE ResidentID IN ($ph) AND (IsDeceased=0 OR IsDeceased IS NULL) FOR UPDATE");
    $sel->execute($ids);
    $ids=array_map('intval',$sel->fetchAll(PDO::FETCH_COLUMN));
    if(!$ids)throw new RuntimeException('No residents available for the new household.');
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $pdo->prepare("UPDATE residents SET ".address_update_sql().",IsHead=0,FamilyHeadID=NULL,RelationshipToHead=NULL WHERE ResidentID IN ($ph)")->execute(array_merge($vals,$ids));
    $gps=posted_gps($post);
    if($gps)$pdo->prepare("UPDATE residents SET Latitude=?,Longitude=? WHERE ResidentID IN ($ph)")->execute(array_merge($gps,$ids));
    $pdo->prepare("UPDATE residents SET IsHead=1,FamilyHeadID=NULL,RelationshipToHead=NULL WHERE ResidentID=?")->execute([$headId]);
    $s=$pdo->prepare("SELECT * FROM residents WHERE ResidentID=?");
    $s->execute([$headId]);
    $headNow=$s->fetch(PDO::FETCH_ASSOC);
    $income=hh_income_class((float)($headNow['TotalHouseholdIncome']??0));
    $ins=$pdo->prepare("INSERT INTO household_survey (HouseholdID,ResidentID,head_name,address,civil_status,sex,contact_number,income_bracket,income_classification,status,is_removed) VALUES (?,?,?,?,?,?,?, ?,?, 'active',0)");
    $ins->execute(['PENDING-'.bin2hex(random_bytes(8)),$headId,hh_full_name($headNow),$newAddr,$headNow['CivilStatus']??null,$headNow['Sex']??null,$headNow['ContactNumber']??null,$income,$income]);
    $sid=(int)$pdo->lastInsertId();
    $hhid=hh_next_household_id($pdo,(int)date('Y'));
    $pdo->prepare("UPDATE household_survey SET HouseholdID=? WHERE SurveyID=?")->execute([$hhid,$sid]);
    $others=array_values(array_diff($ids,[$headId]));
    if($others) {
        // Keep each member's relationship from the previous household.
        $relStmt=$pdo->prepare("SELECT relationship FROM household_survey_members WHERE SurveyID=? AND ResidentID=? LIMIT 1");
        $upd=$pdo->prepare("UPDATE residents SET FamilyHeadID=?,IsHead=0,RelationshipToHead=? WHERE ResidentID=?");
        foreach($others as $oid) {
            $relStmt->execute([(int)$old['SurveyID'],$oid]);
            $rel=trim((string)($relStmt->fetchColumn()?:''));
            $upd->execute([$headId,$rel!==''?$rel:'Member',$oid]);
        }
    }
    sync_hh_members($pdo,$sid,$headId);
    log_hh_history($pdo,$sid,'RELOCATED',"Created {$hhid} after relocation of {$old['HouseholdID']}.",hh_address($old),$newAddr);
    log_hh_history($pdo,(int)$old['SurveyID'],'RELOCATED',"Household relocated. New household: {$hhid}.",hh_address($old),$newAddr);
    return ['survey_id'=>$sid,'household_id'=>$hhid,'head_id'=>$headId];
}
/*
 * Make $newHead the head of household $hh (caller holds the transaction and
 * the household row lock). Used by Change Head and by Transfer Member when
 * the current head leaves. Returns the new head's name.
 */
function do_change_head(PDO $pdo,array $hh,int $newHead,string $oldHeadRel): string {
    $surveyId=(int)$hh['SurveyID'];
    $oldHead=(int)$hh['ResidentID'];
    $s=$pdo->prepare("SELECT * FROM residents WHERE ResidentID=? AND (IsDeceased=0 OR IsDeceased IS NULL) FOR UPDATE");
    $s->execute([$newHead]);
    $nh=$s->fetch(PDO::FETCH_ASSOC);
    if(!$nh)throw new RuntimeException('Selected resident is unavailable.');
    if($newHead===$oldHead)throw new RuntimeException('The selected resident is already the household head.');
    if((int)($nh['IsHead']??0)===1)throw new RuntimeException('The selected resident is the head of another household.');
    $sameHouseStmt=$pdo->prepare("SELECT ResidentID FROM residents WHERE ResidentID=? AND (FamilyHeadID=? OR (LOWER(TRIM(COALESCE(HouseNumber,'')))=LOWER(TRIM(COALESCE(?,''))) AND LOWER(TRIM(COALESCE(StreetName,'')))=LOWER(TRIM(COALESCE(?,''))) AND LOWER(TRIM(COALESCE(Purok,'')))=LOWER(TRIM(COALESCE(?,''))))) LIMIT 1");
    $sameHouseStmt->execute([$newHead,$oldHead,$hh['HouseNumber']??'', $hh['StreetName']??'', $hh['Purok']??'']);
    if (!$sameHouseStmt->fetchColumn()) throw new RuntimeException('The selected new Head must be an existing household member or belong to the same household address.');

    $oldGpsStmt=$pdo->prepare("SELECT Latitude,Longitude FROM residents WHERE ResidentID=? LIMIT 1");
    $oldGpsStmt->execute([$oldHead]);
    $oldGps=$oldGpsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $pdo->prepare("UPDATE residents SET IsHead=0,FamilyHeadID=?,RelationshipToHead='Member' WHERE FamilyHeadID=? OR ResidentID=?")->execute([$newHead,$oldHead,$oldHead]);
    if($oldHeadRel!=='')$pdo->prepare("UPDATE residents SET RelationshipToHead=? WHERE ResidentID=?")->execute([$oldHeadRel,$oldHead]);
    $pdo->prepare("UPDATE residents SET IsHead=1,FamilyHeadID=NULL,RelationshipToHead=NULL,Latitude=COALESCE(Latitude,?),Longitude=COALESCE(Longitude,?) WHERE ResidentID=?")->execute([$oldGps['Latitude']??null,$oldGps['Longitude']??null,$newHead]);
    $newGpsStmt=$pdo->prepare("SELECT Latitude,Longitude FROM residents WHERE ResidentID=? LIMIT 1");
    $newGpsStmt->execute([$newHead]);
    $newGps=$newGpsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $pdo->prepare("UPDATE residents SET Latitude=COALESCE(?,Latitude),Longitude=COALESCE(?,Longitude) WHERE FamilyHeadID=?")->execute([$newGps['Latitude']??null,$newGps['Longitude']??null,$newHead]);
    $oldName=hh_full_name($hh);
    $newName=hh_full_name($nh);
    $newAddr=hh_address($nh);
    $pdo->prepare("UPDATE household_survey SET ResidentID=?,head_name=?,address=?,civil_status=?,sex=?,contact_number=?,income_bracket=?,income_classification=? WHERE SurveyID=?")
    ->execute([$newHead,$newName,$newAddr,$nh['CivilStatus']??null,$nh['Sex']??null,$nh['ContactNumber']??null,hh_income_class((float)($nh['TotalHouseholdIncome']??0)),hh_income_class((float)($nh['TotalHouseholdIncome']??0)),$surveyId]);
    sync_hh_members($pdo,$surveyId,$newHead);
    log_hh_history($pdo,$surveyId,'HEAD_CHANGED',"Changed household head from {$oldName} to {$newName}.");
    return $newName;
}
/* Household row that may have no valid Head (headless household). */
function get_hh_any(PDO $pdo,int $sid,bool $lock=false): array {
    $s=$pdo->prepare("SELECT * FROM household_survey WHERE SurveyID=? LIMIT 1".($lock?' FOR UPDATE':''));
    $s->execute([$sid]);
    $hs=$s->fetch(PDO::FETCH_ASSOC);
    if(!$hs)throw new RuntimeException('Household not found.');
    $head=null;
    if(!empty($hs['ResidentID'])) {
        $r=$pdo->prepare("SELECT * FROM residents WHERE ResidentID=? AND IsHead=1 AND (IsDeceased=0 OR IsDeceased IS NULL) LIMIT 1".($lock?' FOR UPDATE':''));
        $r->execute([(int)$hs['ResidentID']]);
        $head=$r->fetch(PDO::FETCH_ASSOC)?:null;
    }
    return ['survey'=>$hs,'head'=>$head];
}
/* Relationship value from the Resident Profiling dropdown ("Other" → the specified text). */
function clean_relationship(string $rel,string $other=''): string {
    $rel=trim($rel);
    if(strcasecmp($rel,'Other')===0)$rel=trim($other);
    $rel=trim(preg_replace('/\s+/',' ',$rel)??$rel);
    return $rel!==''?mb_substr($rel,0,100):'Member';
}
/*
 * Head of another household moves in as the new Head of this household:
 * their old household stays (never deleted) but becomes headless; its members
 * are unlinked from them and kept in that household's member list until a new
 * Head is assigned (Edit Household → Change Household Head).
 */
function make_previous_household_headless(PDO $pdo,array $resident,string $targetHouseholdId): ?array {
    $rid=(int)$resident['ResidentID'];
    $own=hh_active_survey_for_head($pdo,$rid);
    if(!$own)return null;
    $sid=(int)$own['SurveyID'];
    sync_hh_members($pdo,$sid,$rid); // snapshot the members before they are unlinked
    $pdo->prepare("UPDATE residents SET FamilyHeadID=NULL WHERE FamilyHeadID=?")->execute([$rid]);
    $pdo->prepare("UPDATE household_survey SET ResidentID=NULL,head_name=? WHERE SurveyID=?")->execute(['(No Head)',$sid]);
    log_hh_history($pdo,$sid,'HEAD_TRANSFERRED',hh_full_name($resident)." became the Head of {$targetHouseholdId}. This household has no Head and needs a new Head.",hh_address($resident),null);
    return ['survey_id'=>$sid,'household_id'=>(string)$own['HouseholdID']];
}
try {
    if($action==='save_all') {
        /*
         * Edit Household → Save Changes (after the Confirm Changes summary).
         * One transaction, in this order:
         *   1. Household Head  (existing member / resident from another household)
         *   2. Household members (final list for the Head)
         *   3. Household address + map pin → Head and EVERY member individually
         * Nothing is written when any step fails.
         */
        if($surveyId<=0)hh_json_fail('Missing household.');
        $expectedHead=(int)($_POST['expected_head_id']??0);
        $headMode=(string)($_POST['head_mode']??'');
        $newHeadId=(int)($_POST['new_head_id']??0);
        $oldHeadRel=clean_relationship((string)($_POST['old_head_relationship']??''),(string)($_POST['old_head_relationship_other']??''));
        $addressChanged=($_POST['address_changed']??'')==='1';
        $ids=$_POST['member_resident_id']??[];
        $ids=is_array($ids)?array_values(array_unique(array_filter(array_map('intval',$ids)))):[];
        $relMap=$_POST['member_relationship']??[];
        if(!is_array($relMap))$relMap=[];
        if(!in_array($headMode,['','member','resident'],true))hh_json_fail('Invalid Household Head option.');
        if($headMode!==''&&$newHeadId<=0)hh_json_fail('Select the new Household Head.');

        $pdo->beginTransaction();
        $cur=get_hh_any($pdo,$surveyId,true);
        $hs=$cur['survey'];
        if(strtolower((string)($hs['status']??'active'))!=='active'||(int)($hs['is_removed']??0)===1)throw new RuntimeException('Inactive households cannot be changed.');
        $currentHead=$cur['head']?(int)$cur['head']['ResidentID']:0;
        if($currentHead!==$expectedHead)throw new RuntimeException('This household was changed by someone else. Reload the page and try again.');
        $hhId=(string)$hs['HouseholdID'];
        if($currentHead===0&&$headMode==='')throw new RuntimeException('This household has no Head. Select a new Household Head first.');

        // Current household address (before any change): the Head, else a recorded member.
        $formerHeadId=!empty($hs['ResidentID'])?(int)$hs['ResidentID']:null;
        $currentMembers=$currentHead>0?(function() use($pdo,$currentHead){ $s=$pdo->prepare("SELECT * FROM residents WHERE FamilyHeadID=? AND ResidentID<>? AND (IsDeceased=0 OR IsDeceased IS NULL)"); $s->execute([$currentHead,$currentHead]); return $s->fetchAll(PDO::FETCH_ASSOC); })():hh_headless_members($pdo,$surveyId,$formerHeadId);
        $base=$cur['head']?:($currentMembers[0]??null);
        if(!$base)throw new RuntimeException('This household has no residents.');
        $oldAddr=hh_address($base);
        $history=[];
        $prevHeads=[];

        // 1. Household Head
        $headId=$currentHead;
        if($headMode!=='') {
            $s=$pdo->prepare("SELECT * FROM residents WHERE ResidentID=? AND (IsDeceased=0 OR IsDeceased IS NULL) FOR UPDATE");
            $s->execute([$newHeadId]);
            $nh=$s->fetch(PDO::FETCH_ASSOC);
            if(!$nh)throw new RuntimeException('The selected resident is unavailable.');
            if($newHeadId===$currentHead)throw new RuntimeException('The selected resident is already the Household Head.');
            $memberIds=array_map(static fn($m)=>(int)$m['ResidentID'],$currentMembers);
            $oldHeadName=$cur['head']?hh_full_name($cur['head']):'(No Head)';
            if($headMode==='member') {
                if(!in_array($newHeadId,$memberIds,true))throw new RuntimeException('The selected new Head is not a member of this household.');
                if($currentHead>0) {
                    do_change_head($pdo,array_merge($hs,$cur['head']),$newHeadId,$oldHeadRel);
                } else {
                    hh_assign_headless_head($pdo,$surveyId,$nh);
                }
            } else {
                // Resident from another household (or not in any household).
                if(in_array($newHeadId,$memberIds,true))throw new RuntimeException('This resident is already a member of this household. Choose "Existing Household Member" instead.');
                if((int)($nh['IsHead']??0)===1) {
                    $orphan=make_previous_household_headless($pdo,$nh,$hhId);
                    if($orphan)$history[]="Previous household {$orphan['household_id']} of ".hh_full_name($nh)." now has no Head.";
                } elseif(!empty($nh['FamilyHeadID'])&&(int)$nh['FamilyHeadID']!==$currentHead) {
                    $prevHeads[(int)$nh['FamilyHeadID']][]=hh_full_name($nh);
                }
                if($currentHead>0) {
                    // Previous Head and all members now belong to the new Head.
                    $pdo->prepare("UPDATE residents SET FamilyHeadID=?,IsHead=0 WHERE FamilyHeadID=? AND ResidentID<>?")->execute([$newHeadId,$currentHead,$newHeadId]);
                    $pdo->prepare("UPDATE residents SET IsHead=0,FamilyHeadID=?,RelationshipToHead=? WHERE ResidentID=?")->execute([$newHeadId,$oldHeadRel,$currentHead]);
                    $pdo->prepare("UPDATE residents SET IsHead=1,FamilyHeadID=NULL,RelationshipToHead='Head of Family' WHERE ResidentID=?")->execute([$newHeadId]);
                    $pdo->prepare("UPDATE household_survey SET ResidentID=?,head_name=?,civil_status=?,sex=?,contact_number=? WHERE SurveyID=?")
                        ->execute([$newHeadId,hh_full_name($nh),$nh['CivilStatus']??null,$nh['Sex']??null,$nh['ContactNumber']??null,$surveyId]);
                    sync_hh_members($pdo,$surveyId,$newHeadId);
                    log_hh_history($pdo,$surveyId,'HEAD_CHANGED',"Changed household head from {$oldHeadName} to ".hh_full_name($nh)." (resident from another household).");
                } else {
                    hh_assign_headless_head($pdo,$surveyId,$nh);
                }
            }
            $headId=$newHeadId;
            $history[]="Household Head: {$oldHeadName} → ".hh_full_name($nh).'.';
        }

        // 2. Household members (final list for the Head; the Head is never a member)
        $old=$pdo->prepare("SELECT ResidentID FROM residents WHERE FamilyHeadID=?");
        $old->execute([$headId]);
        $oldIds=array_map('intval',$old->fetchAll(PDO::FETCH_COLUMN));
        $all=array_values(array_diff($ids,[$headId]));
        if($all) {
            $ph=implode(',',array_fill(0,count($all),'?'));
            $chk=$pdo->prepare("SELECT * FROM residents WHERE ResidentID IN ($ph) AND (IsDeceased=0 OR IsDeceased IS NULL) FOR UPDATE");
            $chk->execute($all);
            foreach($chk->fetchAll(PDO::FETCH_ASSOC) as $cand) {
                if(in_array((int)$cand['ResidentID'],$oldIds,true))continue;
                $prev=prepare_member_link($pdo,$cand,$headId,$hhId);
                if($prev>0)$prevHeads[$prev][]=hh_full_name($cand);
            }
        }
        $pdo->prepare("UPDATE residents SET FamilyHeadID=NULL,RelationshipToHead=NULL,IsHead=0 WHERE FamilyHeadID=?")->execute([$headId]);
        $relStmt=$pdo->prepare("UPDATE residents SET FamilyHeadID=?,IsHead=0,RelationshipToHead=? WHERE ResidentID=? AND (IsDeceased=0 OR IsDeceased IS NULL)");
        foreach($all as $mid) {
            $rel=clean_relationship((string)($relMap[$mid]??'Member'),(string)(($_POST['member_relationship_other']??[])[$mid]??''));
            $relStmt->execute([$headId,$rel,$mid]);
        }
        $added=array_values(array_diff($all,$oldIds));
        $removed=array_values(array_diff($oldIds,$all));
        if($added||$removed)$history[]='Members: '.count($added).' added, '.count($removed).' removed.';

        // 3. Address + map pin: same values for the Head and each member.
        $vals=address_values($base);
        $gps=hh_valid_gps($base['Latitude']??null,$base['Longitude']??null);
        if($addressChanged) {
            $post=$_POST;
            foreach(['RegionName','ProvinceName','CityMunicipalityName','BarangayName','ZipCode','PSGCRegionCode','PSGCProvinceCode','PSGCMunicipalityCode','PSGCBarangayCode'] as $k) {
                if(array_key_exists($k,$post)&&trim((string)$post[$k])==='')unset($post[$k]);
            }
            $vals=address_values_keep($post,$base);
            if(trim((string)($vals[0]??''))===''||trim((string)($vals[2]??''))==='')throw new RuntimeException('House/Lot/Unit Number and Street are required.');
            $match=hh_find_household_at_address($pdo,['HouseNumber'=>$vals[0],'StreetName'=>$vals[2]],$headId);
            if($match&&(int)$match['survey_id']!==$surveyId)throw new RuntimeException("A household already exists at this address ({$match['household_id']}, Head: {$match['head_name']}). Moving here would create a duplicate household.");
            $newGps=posted_gps($_POST);
            if($newGps)$gps=$newGps;
        }
        $idsStmt=$pdo->prepare("SELECT ResidentID FROM residents WHERE ResidentID=? OR (FamilyHeadID=? AND (IsDeceased=0 OR IsDeceased IS NULL))");
        $idsStmt->execute([$headId,$headId]);
        $every=array_map('intval',$idsStmt->fetchAll(PDO::FETCH_COLUMN));
        $updAddr=$pdo->prepare("UPDATE residents SET ".address_update_sql()." WHERE ResidentID=?");
        $updGps=$pdo->prepare("UPDATE residents SET Latitude=?,Longitude=? WHERE ResidentID=?");
        foreach($every as $rid) {
            $updAddr->execute(array_merge($vals,[$rid]));
            if($gps)$updGps->execute([$gps[0],$gps[1],$rid]); // one household pin for everyone
        }

        // Household record = the (new) Head + combined income of everyone.
        $hn=$pdo->prepare("SELECT * FROM residents WHERE ResidentID=?");
        $hn->execute([$headId]);
        $headNow=$hn->fetch(PDO::FETCH_ASSOC);
        $mem=$pdo->prepare("SELECT * FROM residents WHERE FamilyHeadID=? AND ResidentID<>? AND (IsDeceased=0 OR IsDeceased IS NULL)");
        $mem->execute([$headId,$headId]);
        $class=hh_income_class(hh_combined_income($headNow,$mem->fetchAll(PDO::FETCH_ASSOC)));
        $newAddr=hh_address($headNow);
        $pdo->prepare("UPDATE household_survey SET ResidentID=?,head_name=?,address=?,civil_status=?,sex=?,contact_number=?,income_bracket=?,income_classification=? WHERE SurveyID=?")
            ->execute([$headId,hh_full_name($headNow),$newAddr,$headNow['CivilStatus']??null,$headNow['Sex']??null,$headNow['ContactNumber']??null,$class,$class,$surveyId]);
        sync_hh_members($pdo,$surveyId,$headId);
        foreach($prevHeads as $prevHead=>$names)resync_previous_household($pdo,(int)$prevHead,implode(', ',$names),$hhId);
        if($addressChanged)log_hh_history($pdo,$surveyId,'ADDRESS_CHANGED','Updated the household address for the Head and all members.',$oldAddr,$newAddr);
        if($added||$removed)log_hh_history($pdo,$surveyId,'MEMBERS_UPDATED','Updated household membership ('.count($added).' added, '.count($removed).' removed).');
        $pdo->commit();
        if(function_exists('log_activity'))log_activity('Households','Save Household Changes',"Saved changes to {$hhId}. ".implode(' ',$history));
        hh_json_ok(['head_id'=>$headId,'survey_id'=>$surveyId,'household_id'=>$hhId]);
    }
    if($action==='save_members') {
        $headId=(int)($_POST['head_resident_id']??0);
        if($surveyId<=0||$headId<=0)hh_json_fail('Missing household information.');
        $hh=get_hh($pdo,$surveyId,true);
        if((int)$hh['ResidentID']!==$headId)hh_json_fail('Household head mismatch.',409);
        if(strtolower((string)($hh['status']??'active'))!=='active')hh_json_fail('Inactive households cannot be changed.');
        $ids=$_POST['member_resident_id']??[];
        $ids=is_array($ids)?array_values(array_unique(array_filter(array_map('intval',$ids)))):[];
        $pdo->beginTransaction();
        $old=$pdo->prepare("SELECT ResidentID FROM residents WHERE FamilyHeadID=?");
        $old->execute([$headId]);
        $oldIds=array_map('intval',$old->fetchAll(PDO::FETCH_COLUMN));
        $all=array_values(array_diff($ids,[$headId]));
        $prevHeads=[];
        if($all) {
            $ph=implode(',',array_fill(0,count($all),'?'));
            $chk=$pdo->prepare("SELECT * FROM residents WHERE ResidentID IN ($ph) AND (IsDeceased=0 OR IsDeceased IS NULL) FOR UPDATE");
            $chk->execute($all);
            foreach($chk->fetchAll(PDO::FETCH_ASSOC) as $cand) {
                if(in_array((int)$cand['ResidentID'],$oldIds,true))continue;
                $prev=prepare_member_link($pdo,$cand,$headId,(string)$hh['HouseholdID']);
                if($prev>0)$prevHeads[$prev][]=hh_full_name($cand);
            }
        }
        $pdo->prepare("UPDATE residents SET FamilyHeadID=NULL,RelationshipToHead=NULL,IsHead=0 WHERE FamilyHeadID=?")->execute([$headId]);
        if($all) {
            $ph=implode(',',array_fill(0,count($all),'?'));
            $s=$pdo->prepare("SELECT ResidentID FROM residents WHERE ResidentID IN ($ph) AND (IsDeceased=0 OR IsDeceased IS NULL)");
            $s->execute($all);
            $valid=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
            if($valid) {
                $ph=implode(',',array_fill(0,count($valid),'?'));
                $headLoc=$pdo->prepare("SELECT * FROM residents WHERE ResidentID=? LIMIT 1");
                $headLoc->execute([$headId]);
                $headGps=$headLoc->fetch(PDO::FETCH_ASSOC) ?: [];
                $relMap=$_POST['member_relationship']??[];
                if(!is_array($relMap)) $relMap=[];
                $baseVals=address_values($headGps);
                foreach($valid as $memberId){
                    $rel=trim((string)($relMap[$memberId]??'Member'));
                    if($rel==='') $rel='Member';
                    $sql="UPDATE residents SET ".address_update_sql().",FamilyHeadID=?,IsHead=0,RelationshipToHead=?,Latitude=COALESCE(?,Latitude),Longitude=COALESCE(?,Longitude) WHERE ResidentID=?";
                    $pdo->prepare($sql)->execute(array_merge($baseVals,[$headId,$rel,$headGps['Latitude']??null,$headGps['Longitude']??null,$memberId]));
                }
            }
        }
        sync_hh_members($pdo,$surveyId,$headId);
        foreach($prevHeads as $prevHead=>$names)resync_previous_household($pdo,(int)$prevHead,implode(', ',$names),(string)$hh['HouseholdID']);
        $added=array_values(array_diff($all,$oldIds));
        $removed=array_values(array_diff($oldIds,$all));
        log_hh_history($pdo,$surveyId,'MEMBERS_UPDATED','Updated household membership ('.count($added).' added, '.count($removed).' removed).');
        $pdo->commit();
        if(function_exists('log_activity'))log_activity('Households','Update Members',"Updated members of {$hh['HouseholdID']}.");
        hh_json_ok();
    }
    if($action==='change_head') {
        $newHead=(int)($_POST['new_head_id']??0);
        if($surveyId<=0||$newHead<=0)hh_json_fail('Select a new household head.');
        $pdo->beginTransaction();
        $hh=get_hh($pdo,$surveyId,true);
        if(strtolower((string)($hh['status']??'active'))!=='active')throw new RuntimeException('Inactive households cannot be changed.');
        $newName=do_change_head($pdo,$hh,$newHead,trim((string)($_POST['old_head_relationship']??'')));
        $pdo->commit();
        if(function_exists('log_activity'))log_activity('Households','Change Head',"Changed {$hh['HouseholdID']} head to {$newName}.");
        hh_json_ok();
    }
    if($action==='transfer_member') {
        /*
         * Remove a resident from this household only after a valid destination:
         *   destination=existing -> join target_survey_id as a member
         *                            (address + map pin come from that household)
         *   destination=new      -> become head of a new household at the posted
         *                            address + map location (new Household ID)
         * One transaction: on any failure the resident stays where they were.
         * Only the resident's own household fields change; personal profile
         * fields are never touched here (Resident module owns those).
         */
        $rid=(int)($_POST['resident_id']??0);
        $dest=(string)($_POST['destination']??'');
        if($surveyId<=0||$rid<=0)hh_json_fail('Missing member.');
        if($dest!=='existing'&&$dest!=='new')hh_json_fail('Choose where this resident will go: Join Existing Household or Create New Household.');
        $targetSid=(int)($_POST['target_survey_id']??0);
        $rel=trim((string)($_POST['relationship']??''));
        if($rel==='')$rel='Member';
        if($dest==='existing'&&$targetSid<=0)hh_json_fail('Please select an existing Household.');
        if($dest==='new') {
            if(trim((string)($_POST['HouseNumber']??''))===''||trim((string)($_POST['StreetName']??''))===''||!posted_gps($_POST))
                hh_json_fail('Please complete the new Household address and location.');
        }
        if($dest==='existing'&&$targetSid===$surveyId)hh_json_fail('The resident already belongs to this Household. Select a different Household.');

        $pdo->beginTransaction();
        $hh=get_hh($pdo,$surveyId,true);
        if(strtolower((string)($hh['status']??'active'))!=='active')throw new RuntimeException('Inactive households cannot be changed.');
        $oldHeadId=(int)$hh['ResidentID'];
        $s=$pdo->prepare("SELECT * FROM residents WHERE ResidentID=? AND (IsDeceased=0 OR IsDeceased IS NULL) FOR UPDATE");
        $s->execute([$rid]);
        $r=$s->fetch(PDO::FETCH_ASSOC);
        if(!$r)throw new RuntimeException('Resident not found.');
        $isHead=$rid===$oldHeadId;
        if(!$isHead&&(int)($r['FamilyHeadID']??0)!==$oldHeadId)throw new RuntimeException('Member is not part of this household.');
        $name=hh_full_name($r);

        // Destination checks before anything changes.
        $target=null;
        if($dest==='existing') {
            $target=get_hh($pdo,$targetSid,true);
            if(strtolower((string)($target['status']??'active'))!=='active'||(int)($target['is_removed']??0)===1)throw new RuntimeException('The selected Household is inactive. Please select an active Household.');
            if((int)$target['ResidentID']<=0||(int)($target['IsHead']??0)!==1)throw new RuntimeException('The selected Household has no valid head.');
        } else {
            $match=hh_find_household_at_address($pdo,$_POST,$rid);
            if($match&&(int)$match['survey_id']!==$surveyId)throw new RuntimeException("A household already exists at this address ({$match['household_id']}, Head: {$match['head_name']}). Choose Join Existing Household instead.");
            if($match&&(int)$match['survey_id']===$surveyId)throw new RuntimeException('The new address is the same as the current household address.');
        }

        // Leaving head: keep the old household valid.
        $oldNote='';
        $oldActiveHead=$oldHeadId; // head of the old household after this resident leaves (0 = none)
        if($isHead) {
            $cnt=$pdo->prepare("SELECT COUNT(*) FROM residents WHERE FamilyHeadID=? AND (IsDeceased=0 OR IsDeceased IS NULL)");
            $cnt->execute([$oldHeadId]);
            if((int)$cnt->fetchColumn()>0) {
                $replacement=(int)($_POST['replacement_head_id']??0);
                if($replacement<=0)throw new RuntimeException('Select the member who will become the new head of this household.');
                $newHeadName=do_change_head($pdo,$hh,$replacement,'');
                $oldActiveHead=$replacement;
                $oldNote=" {$newHeadName} is now the head.";
            } else {
                // Nobody left: household becomes inactive (kept for history, ID not reused).
                $pdo->prepare("UPDATE household_survey SET status='inactive',is_removed=1,removal_reason='Other',removal_reason_other=?,removed_at=NOW(),inactive_since=NOW(),inactive_by=? WHERE SurveyID=?")
                    ->execute(["Head {$name} transferred out; no remaining members",hh_actor_name(),$surveyId]);
                log_hh_history($pdo,$surveyId,'DEACTIVATED',"Household {$hh['HouseholdID']} was set inactive because its head {$name} transferred out and no members remained.",hh_address($hh),null);
                $oldActiveHead=0;
                $oldNote=' The household had no other members and is now inactive.';
            }
        }

        $oldAddr=hh_address($r);
        if($dest==='existing') {
            $tHead=(int)$target['ResidentID'];
            $pdo->prepare("UPDATE residents SET ".address_update_sql().",FamilyHeadID=?,IsHead=0,RelationshipToHead=?,Latitude=?,Longitude=? WHERE ResidentID=?")
                ->execute(array_merge(address_values($target),[$tHead,$rel,$target['Latitude']??null,$target['Longitude']??null,$rid]));
            sync_hh_members($pdo,$targetSid,$tHead);
            if($oldActiveHead>0)sync_hh_members($pdo,$surveyId,$oldActiveHead);
            log_hh_history($pdo,$targetSid,'MEMBER_ADDED',"{$name} transferred from {$hh['HouseholdID']} as {$rel}.",$oldAddr,hh_address($target));
            log_hh_history($pdo,$surveyId,'MEMBER_TRANSFERRED',"{$name} left the household and joined {$target['HouseholdID']}.".$oldNote,$oldAddr,hh_address($target));
            $result=['destination'=>'existing','survey_id'=>$targetSid,'household_id'=>$target['HouseholdID'],'head_id'=>$tHead];
        } else {
            $gps=posted_gps($_POST);
            // Barangay-level parts come from Manage Area; if not configured (blank), keep the resident's current values.
            $newAddrPost=$_POST;
            foreach(['RegionName','ProvinceName','CityMunicipalityName','BarangayName','ZipCode','PSGCRegionCode','PSGCProvinceCode','PSGCMunicipalityCode','PSGCBarangayCode'] as $k) {
                if(array_key_exists($k,$newAddrPost)&&trim((string)$newAddrPost[$k])==='')unset($newAddrPost[$k]);
            }
            $pdo->prepare("UPDATE residents SET ".address_update_sql().",FamilyHeadID=NULL,IsHead=1,RelationshipToHead=NULL,Latitude=?,Longitude=? WHERE ResidentID=?")
                ->execute(array_merge(address_values_keep($newAddrPost,$r),[$gps[0],$gps[1],$rid]));
            $newSid=hh_ensure_household_record($pdo,$rid);
            if($newSid<=0)throw new RuntimeException('Unable to create the new household.');
            $ns=$pdo->prepare("SELECT HouseholdID FROM household_survey WHERE SurveyID=?");
            $ns->execute([$newSid]);
            $newHhId=(string)$ns->fetchColumn();
            if($oldActiveHead>0)sync_hh_members($pdo,$surveyId,$oldActiveHead);
            $newAddrNow=$pdo->prepare("SELECT * FROM residents WHERE ResidentID=?");
            $newAddrNow->execute([$rid]);
            $newAddr=hh_address($newAddrNow->fetch(PDO::FETCH_ASSOC)?:[]);
            log_hh_history($pdo,$surveyId,'MEMBER_TRANSFERRED',"{$name} left the household and became head of new household {$newHhId}.".$oldNote,$oldAddr,$newAddr);
            $result=['destination'=>'new','survey_id'=>$newSid,'household_id'=>$newHhId,'head_id'=>$rid];
        }
        $pdo->commit();
        if(function_exists('log_activity'))log_activity('Households','Transfer Member',"Moved {$name} from {$hh['HouseholdID']} to {$result['household_id']}.");
        hh_json_ok($result+['old_household_active'=>$oldActiveHead>0,'old_head_id'=>$oldActiveHead,'resident_name'=>$name]);
    }
    if($action==='remove_member') {
        $rid=(int)($_POST['resident_id']??0);
        if($surveyId<=0||$rid<=0)hh_json_fail('Missing member.');
        $pdo->beginTransaction();
        $hh=get_hh($pdo,$surveyId,true);
        if($rid===(int)$hh['ResidentID'])throw new RuntimeException('The household head cannot be removed as a member. Use Change Household Head first.');
        $s=$pdo->prepare("SELECT * FROM residents WHERE ResidentID=? AND FamilyHeadID=? FOR UPDATE");
        $s->execute([$rid,$hh['ResidentID']]);
        $member=$s->fetch(PDO::FETCH_ASSOC);
        if(!$member)throw new RuntimeException('Member is not part of this household.');
        $vals=address_values_keep($_POST,$member);
        $pdo->prepare("UPDATE residents SET ".address_update_sql().",FamilyHeadID=NULL,RelationshipToHead=NULL,IsHead=0 WHERE ResidentID=?")->execute(array_merge($vals,[$rid]));
        $gps=posted_gps($_POST);
        if($gps)$pdo->prepare("UPDATE residents SET Latitude=?,Longitude=? WHERE ResidentID=?")->execute(array_merge($gps,[$rid]));
        $s=$pdo->prepare("SELECT * FROM residents WHERE ResidentID=?");
        $s->execute([$rid]);
        $memberNow=$s->fetch(PDO::FETCH_ASSOC)?:$member;
        sync_hh_members($pdo,$surveyId,(int)$hh['ResidentID']);
        log_hh_history($pdo,$surveyId,'MEMBER_REMOVED',hh_full_name($member).' was removed from the household.',hh_address($hh),hh_address($memberNow));
        $pdo->commit();
        if(function_exists('log_activity'))log_activity('Households','Remove Member',"Removed ".hh_full_name($member)." from {$hh['HouseholdID']}.");
        hh_json_ok();
    }
    if($action==='save_socioeconomic') {
        if($surveyId<=0)hh_json_fail('Missing household.');
        $houseType=trim((string)($_POST['house_type']??''));
        $tenure=trim((string)($_POST['tenure_status']??''));
        $allowedHouse=['Concrete','Semi-Concrete','Light Materials','Mixed Materials','Makeshift / Salvaged','Other'];
        $allowedTenure=['Owned','Rented','Rent-free with consent','Rent-free without consent','Other'];
        if($houseType!==''&&!in_array($houseType,$allowedHouse,true))hh_json_fail('Invalid house type.');
        if($tenure!==''&&!in_array($tenure,$allowedTenure,true))hh_json_fail('Invalid tenure status.');
        $pdo->beginTransaction();
        $hh=get_hh($pdo,$surveyId,true);
        $pdo->prepare("UPDATE household_survey SET house_type=?,tenure_status=?,housing_tenure=? WHERE SurveyID=?")->execute([$houseType?:null,$tenure?:null,$tenure?:null,$surveyId]);
        log_hh_history($pdo,$surveyId,'SOCIOECONOMIC_UPDATED','Updated household house type and tenure status.');
        $pdo->commit();
        if(function_exists('log_activity'))log_activity('Households','Update Socio-Economic Details',"Updated {$hh['HouseholdID']} survey details.");
        hh_json_ok();
    }
    if($action==='change_address') {
        if($surveyId<=0)hh_json_fail('Missing household.');
        $pdo->beginTransaction();
        $hh=get_hh($pdo,$surveyId,true);
        if(strtolower((string)($hh['status']??'active'))!=='active')throw new RuntimeException('Inactive households cannot be changed.');
        $oldAddr=hh_address($hh);
        $vals=address_values_keep($_POST,$hh);
        if(trim((string)($vals[0]??''))===''&&trim((string)($vals[2]??''))==='')throw new RuntimeException('Enter the new household address.');
        $idsStmt=$pdo->prepare("SELECT ResidentID FROM residents WHERE ResidentID=? OR (FamilyHeadID=? AND (IsDeceased=0 OR IsDeceased IS NULL))");
        $idsStmt->execute([(int)$hh['ResidentID'],(int)$hh['ResidentID']]);
        $ids=array_map('intval',$idsStmt->fetchAll(PDO::FETCH_COLUMN));
        $gps=posted_gps($_POST);
        if($ids) {
            $sql="UPDATE residents SET ".address_update_sql()." WHERE ResidentID=?";
            foreach($ids as $rid)$pdo->prepare($sql)->execute(array_merge($vals,[$rid]));
            if($gps) {
                $ph=implode(',',array_fill(0,count($ids),'?'));
                $pdo->prepare("UPDATE residents SET Latitude=?,Longitude=? WHERE ResidentID IN ($ph)")->execute(array_merge($gps,$ids));
            }
        }
        $headNow=$pdo->prepare("SELECT * FROM residents WHERE ResidentID=?");
        $headNow->execute([(int)$hh['ResidentID']]);
        $hn=$headNow->fetch(PDO::FETCH_ASSOC);
        $newAddr=hh_address($hn);
        $pdo->prepare("UPDATE household_survey SET head_name=?,address=?,civil_status=?,sex=?,contact_number=?,income_bracket=?,income_classification=? WHERE SurveyID=?")->execute([hh_full_name($hn),$newAddr,$hn['CivilStatus']??null,$hn['Sex']??null,$hn['ContactNumber']??null,hh_income_class((float)($hn['TotalHouseholdIncome']??0)),hh_income_class((float)($hn['TotalHouseholdIncome']??0)),$surveyId]);
        log_hh_history($pdo,$surveyId,'ADDRESS_CHANGED','Updated the household address for the head and all associated members.',$oldAddr,$newAddr);
        $pdo->commit();
        if(function_exists('log_activity'))log_activity('Households','Change Address',"Changed address of {$hh['HouseholdID']}.");
        hh_json_ok(['address'=>$newAddr]);
    }
    if($action==='remove_household') {
        if($surveyId<=0)hh_json_fail('Missing household.');
        $reason=trim((string)($_POST['reason']??''));
        $other=trim((string)($_POST['reason_other']??''));
        $relocate=str_starts_with($reason,'Household relocated');
        $allowed=['Household relocated (within barangay)','Household relocated (other barangay)','Household dissolved','Duplicate household','Other'];
        if(!in_array($reason,$allowed,true))hh_json_fail('Select a valid removal reason.');
        if($reason==='Other'&&$other==='')hh_json_fail('Please specify the other reason.');
        $pdo->beginTransaction();
        $hh=get_hh($pdo,$surveyId,true);
        if(strtolower((string)($hh['status']??'active'))!=='active'||(int)($hh['is_removed']??0)===1)throw new RuntimeException('This household is already inactive.');
        $oldAddr=hh_address($hh);
        $idsStmt=$pdo->prepare("SELECT ResidentID FROM residents WHERE ResidentID=? OR (FamilyHeadID=? AND (IsDeceased=0 OR IsDeceased IS NULL)) ORDER BY ResidentID");
        $idsStmt->execute([(int)$hh['ResidentID'],(int)$hh['ResidentID']]);
        $residentIds=array_map('intval',$idsStmt->fetchAll(PDO::FETCH_COLUMN));
        $pdo->prepare("UPDATE household_survey SET status='inactive',is_removed=1,removal_reason=?,removal_reason_other=?,removed_at=NOW(),inactive_since=NOW(),inactive_by=? WHERE SurveyID=?")->execute([$reason,$reason==='Other'?$other:null,hh_actor_name(),$surveyId]);
        $pdo->prepare("UPDATE residents SET IsHead=0,FamilyHeadID=NULL,RelationshipToHead=NULL WHERE ResidentID=? OR FamilyHeadID=?")->execute([(int)$hh['ResidentID'],(int)$hh['ResidentID']]);
        log_hh_history($pdo,$surveyId,'DEACTIVATED',"Household {$hh['HouseholdID']} was set inactive. Reason: ".($reason==='Other'?$other:$reason),$oldAddr,null);
        $relocated=null;
        if($relocate&&!empty($_POST['relocate_now'])) {
            // Same transaction: old household inactive + new household created, or neither.
            $hh['status']='inactive';
            $relocated=create_relocated_household($pdo,$hh,(int)$hh['ResidentID'],$residentIds,$_POST);
        }
        $pdo->commit();
        if(function_exists('log_activity'))log_activity('Households','Deactivate Household',"Deactivated {$hh['HouseholdID']}.");
        if($relocated&&function_exists('log_activity'))log_activity('Households','Relocate Household',"Created {$relocated['household_id']} after relocating {$hh['HouseholdID']}.");
        hh_json_ok(['relocate'=>$relocate,'reason'=>$reason,'head_name'=>hh_full_name($hh),'resident_ids'=>$residentIds,'relocated'=>$relocated]);
    }
    if($action==='create_relocated_household') {
        $oldSurvey=(int)($_POST['old_survey_id']??0);
        $headId=(int)($_POST['head_resident_id']??0);
        $ids=$_POST['resident_ids']??[];
        $ids=is_array($ids)?array_values(array_unique(array_filter(array_map('intval',$ids)))):[];
        if($oldSurvey<=0||$headId<=0)hh_json_fail('Missing relocation information.');
        $pdo->beginTransaction();
        $old=get_hh($pdo,$oldSurvey,true);
        if($old['status']!=='inactive')throw new RuntimeException('The previous household must be inactive before relocation.');
        $res=create_relocated_household($pdo,$old,$headId,$ids,$_POST);
        $pdo->commit();
        if(function_exists('log_activity'))log_activity('Households','Relocate Household',"Created {$res['household_id']} after relocating {$old['HouseholdID']}.");
        hh_json_ok(['survey_id'=>$res['survey_id'],'household_id'=>$res['household_id']]);
    }
    if($action==='add_member') {
        $rid=(int)($_POST['resident_id']??0);
        if($surveyId<=0||$rid<=0)hh_json_fail('Missing member.');
        $pdo->beginTransaction();
        $hh=get_hh($pdo,$surveyId,true);
        $s=$pdo->prepare("SELECT * FROM residents WHERE ResidentID=? AND (IsDeceased=0 OR IsDeceased IS NULL) FOR UPDATE");
        $s->execute([$rid]);
        $r=$s->fetch(PDO::FETCH_ASSOC);
        if(!$r)throw new RuntimeException('Resident not found.');
        if($rid===(int)$hh['ResidentID'])throw new RuntimeException('The household head is already linked.');
        if(strtolower((string)($hh['status']??'active'))!=='active')throw new RuntimeException('Members cannot be added to an inactive household.');
        $rel=trim((string)($_POST['relationship']??''));
        if($rel==='')$rel='Member';
        $prevHead=prepare_member_link($pdo,$r,(int)$hh['ResidentID'],(string)$hh['HouseholdID']);
        $vals=address_values($hh);
        $headGps=$pdo->prepare("SELECT Latitude,Longitude FROM residents WHERE ResidentID=? LIMIT 1");
        $headGps->execute([(int)$hh['ResidentID']]);
        $gps=$headGps->fetch(PDO::FETCH_ASSOC) ?: [];
        $pdo->prepare("UPDATE residents SET ".address_update_sql().",FamilyHeadID=?,IsHead=0,RelationshipToHead=?,Latitude=COALESCE(?,Latitude),Longitude=COALESCE(?,Longitude) WHERE ResidentID=?")->execute(array_merge($vals,[(int)$hh['ResidentID'],$rel,$gps['Latitude']??null,$gps['Longitude']??null,$rid]));
        sync_hh_members($pdo,$surveyId,(int)$hh['ResidentID']);
        resync_previous_household($pdo,$prevHead,hh_full_name($r),(string)$hh['HouseholdID']);
        log_hh_history($pdo,$surveyId,'MEMBER_ADDED',hh_full_name($r).' was added to the household.',hh_address($r),hh_address($hh));
        $pdo->commit();
        if(function_exists('log_activity'))log_activity('Households','Add Member',"Added ".hh_full_name($r)." to {$hh['HouseholdID']}.");
        hh_json_ok(['head_id'=>(int)$hh['ResidentID'],'survey_id'=>$surveyId]);
    }
    hh_json_fail('Unknown household action.');
} catch(Throwable $e) {
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('[CAPS-HOUSEHOLD] action '.$action.': '.$e->getMessage());
    hh_json_fail($e->getMessage(),500);
}

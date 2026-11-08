<?php
declare(strict_types=1);
require __DIR__.'/inc/bootstrap.php';
session_open();
$action=(string)($_GET['action']??'');
$input=body();
function rows(string $sql,array $params=[]):array{$s=db()->prepare($sql);$s->execute($params);return $s->fetchAll();}
function single(string $sql,array $params=[]):?array{$x=rows($sql,$params);return $x[0]??null;}
function must_post():void{if($_SERVER['REQUEST_METHOD']!=='POST')json_out(['success'=>false,'message'=>'POST required'],405);csrf_check();}
function school_access(array $u,string $school):void {
 if(!is_admin($u)&&$school!==($u['schoolName']??''))json_out(['success'=>false,'message'=>'ไม่มีสิทธิ์จัดการโรงเรียนอื่น'],403);
}
function runner_map(array $r,bool $withProof=false):array {
 $birth=(int)($r['birth_year']??0);
 return ['RunnerID'=>$r['id'],'SchoolName'=>$r['school_name'],'Level'=>$r['level'],'FirstName'=>$r['first_name'],'LastName'=>$r['last_name'],
 'CitizenID'=>$withProof?$r['citizen_id']:null,'BirthYear'=>$birth,'Age'=>$birth?max(0,(int)date('Y')+543-$birth):null,
 'Photo'=>$r['photo_url'],'IDProofURL'=>$withProof?$r['proof_url']:null,'Gender'=>$r['gender'],'Status'=>$r['status'],'FailReason'=>$r['fail_reason']??''];
}
function reg_map(array $r):array{
 return ['RegID'=>$r['id'],'EventID'=>$r['event_id'],'EventName'=>$r['event_name'],'Category'=>$r['category'],
 'Level'=>$r['level'],'SchoolName'=>$r['school_name'],'RunnerID'=>$r['runner_ids'],'FirstName'=>$r['runner_names'],
 'Photo'=>$r['photos'],'Gender'=>$r['gender'],'Age'=>$r['age'],'Key'=>$r['registration_key'],
 'QRURL'=>$r['qr_url'],'Heat'=>$r['heat'],'Lane'=>$r['lane'],'CreatedBy'=>$r['created_by'],'CreatedAt'=>$r['created_at']];
}
function feature_allowed(string $name):bool {
 $c=single('SELECT * FROM feature_config WHERE name=?',[$name]);if(!$c)return true;
 if($c['status']==='เปิดระบบ')return true;
 if($c['status']==='ปิดระบบ')return false;
 $now=time();
 return (!$c['opens_at']||strtotime($c['opens_at'])<=$now)&&(!$c['closes_at']||strtotime($c['closes_at'])>=$now);
}
function require_feature(string $name):void{if(!feature_allowed($name))json_out(['success'=>false,'message'=>'ขณะนี้ปิดการทำรายการ'],403);}
if($action==='login'){
 if($_SERVER['REQUEST_METHOD']!=='POST')json_out(['success'=>false],405);
 $email=strtolower(trim((string)($input['email']??'')));
 $pass=(string)($input['password']??'');
 $u=single('SELECT * FROM coaches WHERE email=?',[$email]);
 if(!$u||!password_verify($pass,(string)$u['password_hash'])) {
  log_action('LOGIN_FAIL',['email'=>$email],'User',$email,'Invalid credentials');
  json_out(['success'=>false,'message'=>'อีเมลหรือรหัสผ่านไม่ถูกต้อง'],401);
 }
 if(!in_array($u['role'],['Teacher','Manager','Admin','TopAdmin'],true))json_out(['success'=>false,'message'=>'ไม่มีสิทธิ์ใช้งาน'],403);
 $school=$u['school_name'];
 $mapped=single('SELECT name FROM schools WHERE nickname=? AND (active IN ("Active","ใช้งาน","1") OR active IS NULL) LIMIT 1',[$school]);
 if($mapped)$school=$mapped['name'];
 session_regenerate_id(true);
 $_SESSION['user']=['coachId'=>$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role'],'schoolName'=>$school];
 $_SESSION['csrf']=bin2hex(random_bytes(24));
 log_action('LOGIN_SUCCESS',$_SESSION['user'],'User',$email);
 json_out(['success'=>true,'user'=>$_SESSION['user'],'csrf'=>$_SESSION['csrf']]);
}
if($action==='me'){
 $me=isset($_SESSION['user'])?require_user():null;
 json_out(['success'=>true,'user'=>$me,'csrf'=>$me?($_SESSION['csrf']??null):null]);
}
if($action==='logout'){must_post();session_destroy();json_out(['success'=>true]);}

if($action==='original_results'){
 $u=require_user();
 authorize_athletics_action($u,$action);
 // Use the active events table rather than source_rows so resetting results takes effect immediately.
 $out=[['EventName','รอบการแข่ง','ฮีท','อันดับ 1','อันดับ 2','อันดับ 3','อันดับ 4','อันดับ 5','อันดับ 6','อันดับ 7','อันดับ 8']];
 $evs=rows('SELECT name, stage, heat, team_count, ranking_json FROM events ORDER BY source_order,id');
 foreach($evs as $ev){
   if((int)($ev['team_count']??0)===0)continue;
   $rank=json_decode((string)($ev['ranking_json']??''),true);
   $row=[(string)$ev['name'],(string)($ev['stage']??''),(string)($ev['heat']??'')];
   for($i=1;$i<=8;$i++)$row[]=(string)($rank[$i]??'');
   $out[]=$row;
 }
 json_out(['success'=>true,'data'=>$out]);
}
$u=require_user();
authorize_athletics_action($u,$action);
require __DIR__.'/admin-config.php';
require __DIR__.'/event-management.php';
if($action==='schools'){json_out(['success'=>true,'data'=>rows('SELECT id, name, nickname, active FROM schools ORDER BY name')]);}
if($action==='runners'){
 $school=is_reviewer($u)?trim((string)($_GET['school']??'')):$u['schoolName'];
 $p=[];$sql='SELECT * FROM runners WHERE 1=1';
 if($school!==''){$sql.=' AND school_name=?';$p[]=$school;}
 $sql.=' ORDER BY id DESC';
 $data=array_map(fn($r)=>runner_map($r,is_reviewer($u)||$r['school_name']===$u['schoolName']),rows($sql,$p));
 json_out(['success'=>true,'data'=>$data]);
}
if($action==='save_runner'){
 must_post();require_editor($u);require_feature('รับลงทะเบียนนักวิ่ง');
 $id=(int)($input['RunnerID']??0);
 $school=$u['schoolName'];if(is_admin($u)&&!empty($input['SchoolName']))$school=trim((string)$input['SchoolName']);
 $old=$id?single('SELECT * FROM runners WHERE id=?',[$id]):null;
 if($id&&!$old)json_out(['success'=>false,'message'=>'ไม่พบนักวิ่ง'],404);
 if($old){school_access($u,$old['school_name']);if(in_array($old['status'],['ผ่านการตรวจสอบ','รอตรวจภาพอีกครั้ง'],true))json_out(['success'=>false,'message'=>'ไม่สามารถแก้ไขนักวิ่งที่อยู่ในสถานะผ่านการตรวจสอบหรือรอตรวจภาพอีกครั้งแล้ว'],403);}
 $first=trim((string)($input['FirstName']??''));$last=trim((string)($input['LastName']??''));
 $citizen=preg_replace('/\\D/','',(string)($input['CitizenID']??''));
 $year=(int)($input['BirthYear']??0);
 $level=trim((string)($input['Level']??''));$gender=trim((string)($input['Gender']??''));
 if(!$first||!$last||!in_array($level,['อนุบาล','ประถม','มัธยม'],true)||!in_array($gender,['ชาย','หญิง'],true)||!preg_match('/^\\d{13}$/',$citizen)||$year<2400||$year>(int)date('Y')+543)json_out(['success'=>false,'message'=>'กรอกข้อมูลนักวิ่งให้ถูกต้อง'],422);
 // Preserve Google Apps Script rule: a citizen ID belongs to only one runner.
 $duplicate=single('SELECT id FROM runners WHERE citizen_id=? AND id<>? LIMIT 1',[$citizen,$id]);
 if($duplicate)json_out(['success'=>false,'message'=>'ไม่สามารถบันทึกได้: เลขบัตรประชาชนนี้ถูกใช้ลงทะเบียนนักวิ่งแล้ว'],409);
 if($old) {
  db()->prepare('UPDATE runners SET level=?,first_name=?,last_name=?,citizen_id=?,birth_year=?,gender=? WHERE id=?')->execute([$level,$first,$last,$citizen,$year,$gender,$id]);
  $code='RUNNER_UPDATE';
 } else {
  db()->prepare('INSERT INTO runners(school_name,level,first_name,last_name,citizen_id,birth_year,gender,status,created_by,created_at) VALUES(?,?,?,?,?,?,?,"รอตรวจสอบ",?,NOW())')->execute([$school,$level,$first,$last,$citizen,$year,$gender,$u['name']]);$id=(int)db()->lastInsertId();$code='RUNNER_CREATE';
 }
 log_action($code,$u,'Runner',(string)$id);
 json_out(['success'=>true,'id'=>$id]);
}
if($action==='delete_runner'){
 must_post();require_editor($u);require_feature('รับลงทะเบียนนักวิ่ง');
 $id=(int)($input['id']??0);$r=single('SELECT * FROM runners WHERE id=?',[$id]);
 if(!$r)json_out(['success'=>false,'message'=>'ไม่พบข้อมูล'],404);
 school_access($u,$r['school_name']);
 if(in_array($r['status'],['ผ่านการตรวจสอบ','รอตรวจภาพอีกครั้ง'],true))json_out(['success'=>false,'message'=>'นักวิ่งอยู่ในสถานะที่ล็อกการแก้ไขแล้ว'],403);
 $check=single('SELECT COUNT(*) c FROM registrations WHERE FIND_IN_SET(?,REPLACE(runner_ids," ",""))>0',[$id]);
 if((int)$check['c'])json_out(['success'=>false,'message'=>'มีการสมัครแข่งขันอยู่ กรุณาลบรายการสมัครก่อน'],409);
 db()->prepare('DELETE FROM runners WHERE id=?')->execute([$id]);log_action('RUNNER_DELETE',$u,'Runner',(string)$id);json_out(['success'=>true]);
}
if($action==='update_status'){
 must_post();require_reviewer($u);
 $id=(int)($input['id']??0);$status=trim((string)($input['status']??''));
 if(!in_array($status,['รอตรวจสอบ','รอตรวจภาพอีกครั้ง','ผ่านการตรวจสอบ','ไม่ผ่านการตรวจสอบ'],true))json_out(['success'=>false,'message'=>'สถานะไม่ถูกต้อง'],422);
 $reason=trim((string)($input['reason']??''));
 if($status==='ไม่ผ่านการตรวจสอบ'&&!$reason)json_out(['success'=>false,'message'=>'กรุณาระบุเหตุผล'],422);
 $q=db()->prepare('UPDATE runners SET status=?,fail_reason=?,checked_by=? WHERE id=?');$q->execute([$status,$reason,$u['name'],$id]);
 if(!$q->rowCount())json_out(['success'=>false,'message'=>'ไม่พบข้อมูลหรือสถานะเดิม'],404);
 log_action('RUNNER_STATUS_UPDATE',$u,'Runner',(string)$id,$status);json_out(['success'=>true]);
}
if($action==='events'){
 $ev=rows('SELECT id EventID,name EventName,category Category,level Level,gender Gender,remark Remark,heat Heat,heat_plan HeatPlan,team_count TeamCount,total_teams TotalTeams,stage Stage,ranking_json Ranking FROM events ORDER BY source_order,id');
 json_out(['success'=>true,'data'=>$ev]);
}
if($action==='registrations'){
 $params=[];$where='';
 if(!is_admin($u)){$where=' WHERE school_name=?';$params[]=$u['schoolName'];}
 json_out(['success'=>true,'data'=>array_map('reg_map',rows('SELECT * FROM registrations'.$where.' ORDER BY event_name,id',$params))]);
}
if($action==='save_registration'){
 must_post();require_editor($u);require_feature('รับสมัครรายการวิ่ง');
 $id=(int)($input['regId']??0);
 $ev=single('SELECT * FROM events WHERE id=?',[(int)($input['eventId']??0)]);
 if(!$ev)json_out(['success'=>false,'message'=>'ไม่พบรายการแข่งขัน'],422);
 $ids=array_unique(array_values(array_filter(array_map('intval',(array)($input['runnerIds']??[])))));
 $max=$ev['category']==='วิ่งผลัด'?6:2;
 if(count($ids)<1||count($ids)>$max)json_out(['success'=>false,'message'=>'จำนวนผู้สมัครไม่ถูกต้อง'],422);
 $places=implode(',',array_fill(0,count($ids),'?'));
 $runners=rows('SELECT * FROM runners WHERE id IN ('.$places.')',$ids);
 if(count($runners)!==count($ids))json_out(['success'=>false,'message'=>'ไม่พบนักวิ่งบางราย'],422);
 foreach($runners as $r){
  school_access($u,$r['school_name']);
  if($r['status']!=='ผ่านการตรวจสอบ')json_out(['success'=>false,'message'=>'กรุณาตรวจสอบนักวิ่งให้ผ่านก่อนสมัคร'],422);
  if($ev['level']&&$r['level']!==$ev['level'])json_out(['success'=>false,'message'=>'ระดับชั้นนักวิ่งไม่ตรงรายการ'],422);
  if($ev['gender']&&$r['gender']!==$ev['gender'])json_out(['success'=>false,'message'=>'เพศนักวิ่งไม่ตรงรายการ'],422);
 }
 $school=$runners[0]['school_name'];foreach($runners as $r)if($r['school_name']!==$school)json_out(['success'=>false,'message'=>'นักวิ่งต้องมาจากโรงเรียนเดียวกัน'],422);
 $old=$id?single('SELECT * FROM registrations WHERE id=?',[$id]):null;
 if($id&&!$old)json_out(['success'=>false,'message'=>'ไม่พบใบสมัคร'],404);
 if($old)school_access($u,$old['school_name']);
 $dup=single('SELECT id FROM registrations WHERE event_name=? AND category <=> ? AND level <=> ? AND school_name=? AND id<>? LIMIT 1',[$ev['name'],$ev['category'],$ev['level'],$school,$id]);
 if($dup)json_out(['success'=>false,'message'=>'โรงเรียนสมัครรายการนี้แล้ว'],409);
 $byId=[];foreach($runners as $r)$byId[$r['id']]=$r;
 $ordered=array_map(fn($rid)=>$byId[$rid],$ids);
 $names=implode(',',array_map(fn($r)=>$r['first_name'].' '.$r['last_name'],$ordered));
 $photos=implode(',',array_map(fn($r)=>$r['photo_url']??'',$ordered));
 $ages=implode(',',array_map(fn($r)=>((int)date('Y')+543-(int)$r['birth_year']),$ordered));
 $genders=implode(',',array_map(fn($r)=>$r['gender'],$ordered));
 $key=$ev['id'].'|'.$ev['category'].'|'.$ev['level'].'|'.$school;
 if($old){
  db()->prepare('UPDATE registrations SET event_id=?,event_name=?,category=?,level=?,runner_ids=?,runner_names=?,photos=?,gender=?,age=?,registration_key=? WHERE id=?')
    ->execute([$ev['id'],$ev['name'],$ev['category'],$ev['level'],implode(',',$ids),$names,$photos,$genders,$ages,$key,$id]);
 } else {
  db()->prepare('INSERT INTO registrations(event_id,event_name,category,level,school_name,runner_ids,runner_names,photos,gender,age,registration_key,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,NOW())')
    ->execute([$ev['id'],$ev['name'],$ev['category'],$ev['level'],$school,implode(',',$ids),$names,$photos,$genders,$ages,$key,$u['name']]);$id=(int)db()->lastInsertId();
 }
 log_action($old?'REG_UPDATE':'REG_CREATE',$u,'Registration',(string)$id);
 json_out(['success'=>true,'id'=>$id]);
}
if($action==='delete_registration'){
 must_post();require_editor($u);require_feature('รับสมัครรายการวิ่ง');
 $id=(int)($input['id']??0);$old=single('SELECT * FROM registrations WHERE id=?',[$id]);
 if(!$old)json_out(['success'=>false,'message'=>'ไม่พบใบสมัคร'],404);
 school_access($u,$old['school_name']);db()->prepare('DELETE FROM registrations WHERE id=?')->execute([$id]);
 log_action('REG_DELETE',$u,'Registration',(string)$id);json_out(['success'=>true]);
}
if($action==='schedule'){
 $events=rows('SELECT id EventID,name EventName,category Category,level Level,gender Gender,remark Remark,heat Heat,heat_plan HeatPlan,team_count TeamCount,total_teams TotalTeams,stage Stage FROM events ORDER BY source_order,id');
 $res=[];
 foreach($events as $e) {
  if($e['Remark']==='ชิงชนะเลิศ'&&$e['Stage']==='ชิงชนะเลิศ'&&$e['HeatPlan']!=='ชิง'&&$e['TotalTeams']>0&&$e['TotalTeams']<9)continue;
  $e['entries']=rows('SELECT school_name SchoolName,runner_names RunnerName,heat Heat,lane Lane FROM registrations WHERE event_name=? ORDER BY heat,lane',[$e['EventName']]);
  $res[]=$e;
 }
 json_out(['success'=>true,'data'=>$res]);
}
if($action==='auto_heats'){
 must_post();require_admin($u);
 // Keep original Google Apps Script lane allocation (especially 9 and 10 teams).
 $events=rows('SELECT * FROM events ORDER BY source_order,id');
 db()->beginTransaction();
 try{
   db()->exec('DELETE FROM heats');
   $heatCount=0;
   foreach($events as $ev){
     $regs=rows('SELECT id FROM registrations WHERE event_name=? AND level=? AND category=? AND gender=? ORDER BY id',
       [$ev['name'],$ev['level'],$ev['category'],$ev['gender']]);
     $n=count($regs);if($n===0)continue;
     $ids=array_map(fn($x)=>(int)$x['id'],$regs);
     shuffle($ids);
     $assignments=[];
     if($n<=8){
       foreach($ids as $i=>$id)$assignments[]=[$id,1,$i+1];
     }elseif($n===9){
       foreach($ids as $i=>$id)$assignments[]=[$id,$i<5?1:2,$i<5?($i+2):($i-5+2)];
     }elseif($n===10){
       foreach($ids as $i=>$id)$assignments[]=[$id,$i<5?1:2,($i%5)+2];
     }else{
       foreach($ids as $i=>$id)$assignments[]=[$id,1,$i<8?($i+1):null];
     }
     foreach($assignments as $a){
       db()->prepare('UPDATE registrations SET heat=?,lane=? WHERE id=?')->execute([$a[1],$a[2],$a[0]]);
     }
     $heats=$n===9||$n===10?2:1;
     for($h=1;$h<=$heats;$h++){
       db()->prepare('INSERT INTO heats(event_id,event_name,category,level,gender,heat) VALUES(?,?,?,?,?,?)')
         ->execute([$ev['id'],$ev['name'],$ev['category'],$ev['level'],$ev['gender'],$h]);$heatCount++;
     }
   }
   db()->commit();
   log_action('AUTO_HEATS',$u,'System','',$heatCount.' heats');
   json_out(['success'=>true,'message'=>'จัดฮีทและลู่เรียบร้อย','heatsCount'=>$heatCount]);
 }catch(Throwable $e){
   if(db()->inTransaction())db()->rollBack();
   error_log('Bang1 heat assignment: '.$e->getMessage());
   json_out(['success'=>false,'message'=>'ไม่สามารถจัดฮีทได้'],500);
 }
}
if($action==='features'){
 $cfg=rows('SELECT * FROM feature_config ORDER BY id');foreach($cfg as &$f)$f['isOpen']=feature_allowed($f['name']);
 json_out(['success'=>true,'serverTimeEpochMs'=>time()*1000,'data'=>$cfg]);
}
if($action==='logs'){require_admin($u);json_out(['success'=>true,'data'=>rows('SELECT event_time,action_code,user_name,user_school,target_type,target_id,detail FROM audit_logs ORDER BY id DESC LIMIT 300')]);}
if($action==='certificates'){json_out(['success'=>true,'data'=>rows('SELECT event_name,medal,school_name,athlete_name,certificate_number FROM certificates ORDER BY id LIMIT 1000')]);}
if($action==='results'){json_out(['success'=>true,'data'=>rows('SELECT source_sheet,source_row,payload FROM race_results ORDER BY source_sheet,source_row LIMIT 1000')]);}
if($action==='upload'){
 must_post();require_editor($u);
 $id=(int)($_POST['runnerId']??0);$type=(string)($_POST['type']??'');
 if(!in_array($type,['photo','idproof'],true))json_out(['success'=>false,'message'=>'ชนิดไฟล์ไม่ถูกต้อง'],422);
 require_feature($type==='photo'?'แก้ไขรูปภาพ':'แก้ไขหลักฐาน');
 $r=single('SELECT * FROM runners WHERE id=?',[$id]);
 if(!$r)json_out(['success'=>false,'message'=>'ไม่พบนักวิ่ง'],404);
 school_access($u,$r['school_name']);
 if($type==='idproof'&&in_array($r['status'],['ผ่านการตรวจสอบ','รอตรวจภาพอีกครั้ง'],true))json_out(['success'=>false,'message'=>'ไม่สามารถแนบหลักฐานได้ เนื่องจากสถานะผ่านการตรวจสอบหรือรอตรวจภาพอีกครั้งแล้ว'],403);
 if(!isset($_FILES['file'])||!is_uploaded_file($_FILES['file']['tmp_name']))json_out(['success'=>false,'message'=>'ไม่พบไฟล์'],422);
 $file=$_FILES['file'];if($file['size']>6*1024*1024||$file['size']<1)json_out(['success'=>false,'message'=>'ไฟล์ต้องไม่เกิน 6 MB'],422);
 $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
 if(!in_array($mime,['image/jpeg','image/png','application/pdf'],true))json_out(['success'=>false,'message'=>'รองรับ JPEG PNG หรือ PDF'],422);
 if($type==='photo'&&$mime==='application/pdf')json_out(['success'=>false,'message'=>'รูปนักวิ่งต้องเป็นภาพ'],422);
 $bridge=config('drive_bridge_url');$token=config('drive_bridge_token');
 if(!$bridge||!$token||!preg_match('~^https://script\\.google\\.com/macros/s/[^/]+/exec$~',$bridge))json_out(['success'=>false,'message'=>'ยังไม่ตั้งค่า Google Drive Bridge'],503);
 $payload=json_encode(['token'=>$token,'runnerId'=>$id,'type'=>$type,'fileName'=>basename($file['name']),'mimeType'=>$mime,'base64Data'=>base64_encode(file_get_contents($file['tmp_name']))],JSON_UNESCAPED_UNICODE);
 $curl=curl_init($bridge);
 curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>['Content-Type: text/plain;charset=utf-8'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>60,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS]);
 $result=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
 $resp=json_decode((string)$result,true);
 if($status!==200||!is_array($resp)||empty($resp['success'])||empty($resp['url']))json_out(['success'=>false,'message'=>'อัปโหลดไป Google Drive ไม่สำเร็จ'],502);
 $url=safe_url($resp['url']);if(!$url||!str_contains(parse_url($url,PHP_URL_HOST)??'','google.com'))json_out(['success'=>false,'message'=>'Google Drive ส่ง URL ไม่ถูกต้อง'],502);
 $col=$type==='photo'?'photo_url':'proof_url';
 if($type==='photo'&&$r['status']==='ผ่านการตรวจสอบ'){
   db()->prepare('UPDATE runners SET '.$col.'=?,status=? WHERE id=?')->execute([$url,'รอตรวจภาพอีกครั้ง',$id]);
 }else{
   db()->prepare('UPDATE runners SET '.$col.'=? WHERE id=?')->execute([$url,$id]);
 }
 log_action($type==='photo'?'RUNNER_UPLOAD_PHOTO':'RUNNER_UPLOAD_IDPROOF',$u,'Runner',(string)$id);
 json_out(['success'=>true,'url'=>$url]);
}
json_out(['success'=>false,'message'=>'Unknown action'],404);

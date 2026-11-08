<?php
declare(strict_types=1);
require __DIR__.'/../inc/bootstrap.php';
if($_SERVER['REQUEST_METHOD']!=='POST'||!hash_equals(config('deploy_token'),(string)($_SERVER['HTTP_X_DEPLOY_TOKEN']??'')))json_out(['success'=>false],403);
if(empty($_SERVER['HTTPS'])||$_SERVER['HTTPS']==='off')json_out(['success'=>false,'message'=>'HTTPS required'],403);
if(!isset($_FILES['data'])||!is_uploaded_file($_FILES['data']['tmp_name']))json_out(['success'=>false,'message'=>'กรุณาแนบ JSONL gzip'],400);
if($_FILES['data']['size']>16*1024*1024)json_out(['success'=>false,'message'=>'ไฟล์ใหญ่เกินไป'],413);
$raw=file_get_contents($_FILES['data']['tmp_name']);
$decoded=gzdecode($raw);
if($decoded===false||strlen($decoded)>35*1024*1024)json_out(['success'=>false,'message'=>'gzip ไม่ถูกต้องหรือใหญ่เกินไป'],400);
function strv(mixed $v):string{return trim((string)($v??''));}
function datev(mixed $v):?string {
 if($v===null||$v==='')return null;
 if(is_numeric($v)&&$v>1000&&$v<100000) return gmdate('Y-m-d H:i:s',(int)round(((float)$v-25569)*86400));
 try{return (new DateTimeImmutable((string)$v))->format('Y-m-d H:i:s');}catch(Throwable){return null;}
}
function intv(mixed $v):?int{return is_numeric($v)?(int)$v:null;}
function upsert(string $table,array $data,array $keys):void {
 $columns=array_keys($data);
 $names=implode(',',array_map(fn($c)=>'`'.$c.'`',$columns));
 $places=implode(',',array_fill(0,count($columns),'?'));
 $updates=array_values(array_diff($columns,$keys));
 $sql='INSERT INTO `'.$table.'` ('.$names.') VALUES ('.$places.') ON DUPLICATE KEY UPDATE '.implode(',',array_map(fn($c)=>'`'.$c.'`=VALUES(`'.$c.'`)',$updates?:$keys));
 db()->prepare($sql)->execute(array_values($data));
}
$source=db()->prepare('INSERT INTO source_rows(sheet_name,source_row,payload) VALUES(?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload), imported_at=NOW()');
$counter=0;$warnings=0;
try {
 db()->beginTransaction();
 foreach(explode("\n",trim($decoded)) as $line) {
  if(!trim($line))continue;
  $obj=json_decode($line,true,512,JSON_THROW_ON_ERROR);
  $sheet=strv($obj['sheet']??'');$row=intv($obj['row']??null);
  $d=$obj['data']??null;
  if(!$sheet||!$row||!is_array($d))throw new RuntimeException('Invalid import row');
  // Store the complete workbook data only in MariaDB, never in the public repository.
  $source->execute([$sheet,$row,json_encode($d,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
  $counter++;
  if(in_array('#NAME?',array_values($d),true))$warnings++;
  if($sheet==='โรงเรียน'&&intv($d['SchoolID']??null)) {
   upsert('schools',['id'=>(int)$d['SchoolID'],'name'=>strv($d['SchoolName']??''),'nickname'=>strv($d['schoolnickname']??''),'active'=>strv($d['Active']??'')],['id']);
  } elseif($sheet==='ผู้ฝึกซ้อม' && intv($d['CoachID']??null)) {
   $hash=strv($d['PasswordHash']??'');
   if(!$hash||!password_get_info($hash)['algo'])throw new RuntimeException('Missing hashed password');
   upsert('coaches',['id'=>(int)$d['CoachID'],'name'=>strv($d['Name']??''),'email'=>strtolower(strv($d['Email']??'')),'school_name'=>strv($d['SchoolName']??''),'password_hash'=>$hash,'role'=>strv($d['Role']??'Teacher')],['id']);
  } elseif($sheet==='รายชื่อนักวิ่ง') {
   $id=intv($d['รหัสนักวิ่ง']??$d['RunnerID']??null);
   if(!$id)continue;
   upsert('runners',['id'=>$id,'school_name'=>strv($d['โรงเรียน']??$d['SchoolName']??''),'level'=>strv($d['ระดับ']??$d['Level']??''),'first_name'=>strv($d['ชื่อจริง']??$d['FirstName']??''),'last_name'=>strv($d['นามสกุล']??$d['LastName']??''),'citizen_id'=>strv($d['เลขประชาชน']??$d['CitizenID']??''),'birth_year'=>intv($d['ปีเกิด']??$d['BirthYear']??null),'photo_url'=>safe_url(strv($d['รูปภาพ']??$d['Photo']??'')),'proof_url'=>safe_url(strv($d['หลักฐานการสมัคร']??$d['IDProofURL']??'')),'gender'=>strv($d['เพศ']??$d['Gender']??''),'status'=>strv($d['สถานะ']??$d['Status']??'รอตรวจสอบ'),'created_by'=>strv($d['ลงทะเบียนโดย']??''),'created_at'=>datev($d['ลงทะเบียนเมื่อวันที่']??null),'checked_by'=>strv($d['ตรวจโดย']??''),'fail_reason'=>strv($d['เหตุผลไม่ผ่าน']??'')],['id']);
  } elseif($sheet==='รายการวิ่ง') {
   $id=intv($d['EventID']??null);
   if(!$id)continue;
   $rank=[];for($i=1;$i<=8;$i++)$rank[$i]=$d['อันดับ '.$i]??null;
   upsert('events',['id'=>$id,'name'=>strv($d['EventName']??''),'category'=>strv($d['Category']??''),'level'=>strv($d['Level']??''),'gender'=>strv($d['Gender']??''),'remark'=>strv($d['หมายเหตุ']??''),'heat'=>strv($d['ฮีท']??''),'heat_plan'=>strv($d['กำหนดฮีท']??''),'team_count'=>intv($d['จำนวนทีมที่ลงแข่งของฮีท']??null),'total_teams'=>intv($d['จำนวนที่ลงแข่งทั้งหมดของรายการ']??null),'stage'=>strv($d['รอบการแข่ง']??''),'ranking_json'=>json_encode($rank,JSON_UNESCAPED_UNICODE),'source_order'=>$row],['id']);
  } elseif($sheet==='สมัครวิ่ง') {
   $id=intv($d['RegID']??null);
   if(!$id)continue;
   upsert('registrations',['id'=>$id,'event_id'=>null,'event_name'=>strv($d['EventName']??''),'category'=>strv($d['Category']??''),'level'=>strv($d['Level']??''),'school_name'=>strv($d['SchoolName']??''),'runner_ids'=>strv($d['RunnerID']??''),'runner_names'=>strv($d['FirstName']??''),'photos'=>strv($d['Photo']??''),'gender'=>strv($d['Gender']??''),'age'=>strv($d['Age']??''),'registration_key'=>strv($d['Key']??''),'qr_url'=>safe_url(strv($d['QRURL']??'')),'heat'=>intv($d['Heat']??null),'lane'=>intv($d['Lane']??null),'created_by'=>strv($d['CreatedBy']??''),'created_at'=>datev($d['CreatedAt']??null)],['id']);
  } elseif($sheet==='จัดแข่งขัน') {
   $id=$row-1;
   upsert('heats',['id'=>$id,'event_id'=>intv($d['EventID']??null),'event_name'=>strv($d['EventName']??''),'category'=>strv($d['Category']??''),'level'=>strv($d['Level']??''),'gender'=>strv($d['Gender']??''),'heat'=>intv($d['Heat']??null)],['id']);
  } elseif($sheet==='admin') {
   $name=strv($d['ชื่อรายการ']??'');if(!$name)continue;
   upsert('feature_config',['name'=>$name,'opens_at'=>datev($d['เวลาเปิดลงทะเบียน']??null),'closes_at'=>datev($d['เวลาปิดลงทะเบียน']??null),'status'=>strv($d['สถานะ']??'')],['name']);
  } elseif($sheet==='เกียรติบัตร') {
   upsert('certificates',['id'=>$row-1,'event_name'=>strv($d['รายการ']??''),'medal'=>strv($d['อันดับ']??''),'school_name'=>strv($d['โรงเรียน']??''),'athlete_name'=>strv($d['ชื่อ']??''),'certificate_number'=>strv($d['เลขที่']??''),'sport'=>strv($d['ประเภทกีฬา']??'')],['id']);
  } elseif(in_array($sheet,['สรุปผล','บันทึกผล','ชีต9'],true)) {
   upsert('race_results',['source_sheet'=>$sheet,'source_row'=>$row,'payload'=>json_encode($d,JSON_UNESCAPED_UNICODE)],['source_sheet','source_row']);
  } elseif($sheet==='log') {
   $c=db()->prepare('INSERT INTO audit_logs(id,event_time,action_code,action_text,module,target_type,target_id,target_name,runner_id,reg_id,event_name,user_email,user_name,user_role,user_school,detail) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE action_code=VALUES(action_code)');
   $c->execute([$row-1,datev($d['Timestamp']??null)??date('Y-m-d H:i:s'),strv($d['ActionCode']??''),strv($d['ActionTextTH']??''),strv($d['Module']??''),strv($d['TargetType']??''),strv($d['TargetId']??''),strv($d['TargetName']??''),strv($d['RunnerID']??''),strv($d['RegID']??''),strv($d['EventName']??''),strv($d['UserEmail']??''),strv($d['UserName']??''),strv($d['UserRole']??''),strv($d['UserSchool']??''),strv($d['Detail']??'')]);
  }
 }
 db()->exec("UPDATE registrations r JOIN events e ON r.event_name=e.name SET r.event_id=e.id WHERE r.event_id IS NULL");
 db()->prepare('INSERT INTO import_batches(rows_imported,note) VALUES(?,?)')->execute([$counter,'initial workbook import']);
 db()->commit();
 json_out(['success'=>true,'imported_rows'=>$counter,'formula_warning_rows'=>$warnings]);
} catch(Throwable $e){
 if(db()->inTransaction())db()->rollBack();
 error_log('Bang1 private import: '.$e->getMessage());
 json_out(['success'=>false,'message'=>'นำเข้าไม่สำเร็จ กรุณาตรวจสอบ server log'],500);
}

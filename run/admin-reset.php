<?php
declare(strict_types=1);
// Called by admin-config.php after authentication and require_admin().
if (!in_array($action, ['admin_reset_preview','admin_reset_execute'],true)) return;
$validResetSections=['runners','coaches','registrations','photos','results','logs'];
$labels=[
  'runners'=>'ฐานข้อมูลนักวิ่ง',
  'coaches'=>'ฐานข้อมูลครูผู้ฝึกซ้อม',
  'registrations'=>'ฐานข้อมูลรายการสมัคร',
  'photos'=>'เคลียร์รูปภาพหลักฐานนักวิ่ง',
  'results'=>'รีเซ็ตผลการแข่งขัน',
  'logs'=>'log การทำรายการ'
];
function reset_selection(array $payload,array $valid): array {
    $items=$payload['sections']??[];
    if(!is_array($items)||count($items)===0||count($items)>count($valid)) {
        json_out(['success'=>false,'message'=>'กรุณาเลือกหมวดที่ต้องการรีเซ็ตอย่างน้อยหนึ่งรายการ'],422);
    }
    $selected=[];
    foreach($items as $item) {
        if(!is_string($item)||!in_array($item,$valid,true)) {
            json_out(['success'=>false,'message'=>'มีหมวดรีเซ็ตที่ไม่ถูกต้อง'],422);
        }
        $selected[$item]=true;
    }
    return array_keys($selected);
}
function reset_counts(int $selfId): array {
    $queries=[
        'runners'=>'SELECT COUNT(*) FROM runners',
        'coaches'=>'SELECT COUNT(*) FROM coaches WHERE id<>?',
        'registrations'=>'SELECT COUNT(*) FROM registrations',
        'photos'=>'SELECT COUNT(*) FROM runners WHERE (photo_url IS NOT NULL AND photo_url<>"") OR (proof_url IS NOT NULL AND proof_url<>"")',
        'results'=>'SELECT (SELECT COUNT(*) FROM race_results)+(SELECT COUNT(*) FROM certificates)+(SELECT COUNT(*) FROM events WHERE ranking_json IS NOT NULL AND ranking_json NOT IN ("","{}","null"))',
        'logs'=>'SELECT COUNT(*) FROM audit_logs',
    ];
    $out=[];
    foreach($queries as $name=>$sql) {
        $stmt=db()->prepare($sql);
        $stmt->execute($name==='coaches'?[$selfId]:[]);
        $out[$name]=(int)$stmt->fetchColumn();
    }
    return $out;
}
must_post();
$selected=reset_selection($input,$validResetSections);
$counts=reset_counts((int)$u['coachId']);
$hasOrphans=in_array('runners',$selected,true)
 && !in_array('registrations',$selected,true) && $counts['registrations']>0;
if ($hasOrphans) json_out(['success'=>false,
  'message'=>'ต้องเลือกฐานข้อมูลรายการสมัครร่วมด้วย เนื่องจากมีใบสมัครที่อ้างอิงข้อมูลนักวิ่งอยู่'],422);

if ($action==='admin_reset_preview') {
    // Preview only. Never mutate data here.
    $token=bin2hex(random_bytes(24));
    $_SESSION['admin_reset_confirmation']=[
      'token'=>$token,'sections'=>$selected,'expires'=>time()+300
    ];
    json_out(['success'=>true,'token'=>$token,'counts'=>$counts,'expiresInSeconds'=>300,
      'message'=>'โปรดตรวจสอบหมวดและจำนวนข้อมูลก่อนยืนยันการลบ',
      'notes'=>[
        'บัญชี Admin ที่กำลังใช้งานจะยังอยู่เพื่อป้องกันการล็อกระบบ',
        'เคลียร์รูปและหลักฐานจะล้างลิงก์ใน MariaDB เท่านั้น ไฟล์ Google Drive ไม่ถูกลบ',
        'ข้อมูลนำเข้าเดิมใน source_rows จะยังอยู่เพื่อเป็นสำเนาอ้างอิง',
        'หากล้าง Log จะคงรายการบันทึกการรีเซ็ตล่าสุดหนึ่งรายการ'
      ]]);
}
$confirmed=$_SESSION['admin_reset_confirmation']??null;
unset($_SESSION['admin_reset_confirmation']);
$token=(string)($input['token']??'');
if (!is_array($confirmed)
    || (int)($confirmed['expires']??0)<time()
    || !hash_equals((string)($confirmed['token']??''),$token)
    || $confirmed['sections']!==$selected
    || (string)($input['confirmation']??'')!=='ล้างข้อมูล') {
    json_out(['success'=>false,'message'=>'การยืนยันไม่ถูกต้องหรือหมดเวลา กรุณาตรวจสอบใหม่'],403);
}

try {
    db()->beginTransaction();
    // Check the orphan rule again at transaction time.
    if(in_array('runners',$selected,true) && !in_array('registrations',$selected,true)) {
      $number=(int)db()->query('SELECT COUNT(*) FROM registrations')->fetchColumn();
      if($number>0)throw new RuntimeException('ห้ามลบนักวิ่งโดยยังมีรายการสมัครค้างอยู่');
    }
    $affected=[];
    // Order intentionally avoids stale references between the registration, roster and heat tables.
    if(in_array('registrations',$selected,true)){
      $affected['registrations']=db()->exec('DELETE FROM registrations');
      db()->exec('DELETE FROM heats');
      db()->exec('UPDATE events SET heat=NULL, heat_plan=NULL, team_count=NULL, total_teams=NULL');
    }
    if(in_array('photos',$selected,true)){
      $stmt=db()->exec('UPDATE runners SET photo_url=NULL, proof_url=NULL WHERE photo_url IS NOT NULL OR proof_url IS NOT NULL');
      db()->exec('UPDATE registrations SET photos=NULL WHERE photos IS NOT NULL');
      $affected['photos']=$stmt;
    }
    if(in_array('runners',$selected,true)){
      $affected['runners']=db()->exec('DELETE FROM runners');
    }
    if(in_array('coaches',$selected,true)){
      // Never delete the current administrator's session-bearing account.
      $stmt=db()->prepare('DELETE FROM coaches WHERE id<>?');
      $stmt->execute([(int)$u['coachId']]);
      $affected['coaches']=$stmt->rowCount();
    }
    if(in_array('results',$selected,true)){
      $a=db()->exec('DELETE FROM race_results');
      $b=db()->exec('DELETE FROM certificates');
      $c=db()->exec('UPDATE events SET ranking_json=NULL WHERE ranking_json IS NOT NULL');
      $affected['results']=$a+$b+$c;
    }
    if(in_array('logs',$selected,true)){
      $affected['logs']=db()->exec('DELETE FROM audit_logs');
    }
    // Audit the destructive action even when logs are among the selected sections.
    log_action('ADMIN_DATA_RESET',$u,'System','',
      'รีเซ็ตข้อมูล: '.implode(', ',array_map(fn($key)=>$labels[$key],$selected)));
    db()->commit();
    json_out(['success'=>true,'message'=>'รีเซ็ตข้อมูลเฉพาะหมวดที่เลือกสำเร็จ',
      'affected'=>$affected,'sections'=>$selected]);
} catch(Throwable $e) {
    if(db()->inTransaction())db()->rollBack();
    error_log('Bang1 reset data failed: '.$e->getMessage());
    json_out(['success'=>false,'message'=>'รีเซ็ตข้อมูลไม่สำเร็จ: ระบบย้อนกลับการเปลี่ยนแปลงแล้ว'],500);
}

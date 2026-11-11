<?php
declare(strict_types=1);
/**
 * Admin-only views matching the four original Google Sheets tabs:
 * admin, โรงเรียน, ผู้ฝึกซ้อม, log.
 * Included from api.php after the session has been authenticated.
 */
if (!isset($u, $action) || !is_array($u) || !function_exists('require_admin')) {
    http_response_code(403);
    exit;
}
if (!str_starts_with((string)$action, 'admin_')) return;
require_admin($u);
require __DIR__.'/admin-school-logo-upload.php';
if(in_array($action,['admin_account_applications','admin_account_review','admin_account_resend_notice'],true)) require __DIR__.'/account-portal.php';
require __DIR__.'/admin-security.php';
require __DIR__.'/admin-reset.php';
require __DIR__.'/admin-backup.php';
require __DIR__.'/admin-champions.php';

function admin_date($value): ?string {
    $value = trim((string)($value ?? ''));
    if ($value === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)) {
        json_out(['success'=>false,'message'=>'รูปแบบวันที่และเวลาไม่ถูกต้อง'],422);
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('Asia/Bangkok'));
    if (!$dt || $dt->format('Y-m-d\TH:i') !== $value) {
        json_out(['success'=>false,'message'=>'วันที่หรือเวลาไม่ถูกต้อง'],422);
    }
    return $dt->format('Y-m-d H:i:s');
}

if ($action === 'admin_settings') {
    json_out(['success'=>true,'data'=>rows(
        'SELECT id, name, opens_at, closes_at, status FROM feature_config ORDER BY id'
    )]);
}
if ($action === 'admin_settings_save') {
    must_post();
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    $status = trim((string)($input['status'] ?? ''));
    if (!$id || !in_array($status, ['', 'เปิดระบบ', 'ปิดระบบ'], true)) {
        json_out(['success'=>false,'message'=>'ข้อมูลสถานะไม่ถูกต้อง'],422);
    }
    $openAt = admin_date($input['opensAt'] ?? '');
    $closeAt = admin_date($input['closesAt'] ?? '');
    if ($openAt && $closeAt && $openAt > $closeAt) {
        json_out(['success'=>false,'message'=>'เวลาปิดต้องไม่ก่อนเวลาเปิด'],422);
    }
    $before = single('SELECT name, opens_at, closes_at, status FROM feature_config WHERE id=?', [$id]);
    if (!$before) json_out(['success'=>false,'message'=>'ไม่พบรายการตั้งค่า'],404);
    db()->prepare('UPDATE feature_config SET opens_at=?, closes_at=?, status=? WHERE id=?')
        ->execute([$openAt,$closeAt,$status,$id]);
    log_action('ADMIN_SETTING_UPDATE',$u,'FeatureConfig',(string)$id,
        'รายการ: '.$before['name'].'; สถานะ: '.$before['status'].' => '.$status);
    json_out(['success'=>true,'message'=>'บันทึกการตั้งค่าเรียบร้อย']);
}

if ($action === 'admin_schools') {
    require_once __DIR__.'/school-branding.php';
    $items=rows('SELECT id,name,nickname,active,logo_file,logo_updated_at,live_position FROM schools ORDER BY id');
    $defaults=['วัดสุทธารีย์บุญญาราม','บ้านตะเคียนเตี้ย','บ้านสันติคาม',
       'บ้านบางละมุง','วัดหนองเกตุใหญ่','อนุบาลบางละมุง',
       'วัดเวฬุวนาราม','บ้านโรงหีบ','บ้านนาวัง','บ้านทุ่งกราด'];
    $compact=static fn($name)=>preg_replace('/[\\s\\p{P}\\p{Z}]+/u','',
        preg_replace('/^โรงเรียน/u','',trim((string)$name)))??trim((string)$name);
    $defaultIndex=[];
    foreach($defaults as $i=>$name)$defaultIndex[$compact($name)]=$i;
    usort($items,static function($a,$b)use($compact,$defaultIndex){
        $pa=(int)($a['live_position']??0);$pb=(int)($b['live_position']??0);
        if($pa>0||$pb>0)
            return (($pa>0?$pa:9999)<=>($pb>0?$pb:9999))
                ?:((int)$a['id']<=>(int)$b['id']);
        return (($defaultIndex[$compact($a['name'])]??9999)
            <=>($defaultIndex[$compact($b['name'])]??9999))
            ?:((int)$a['id']<=>(int)$b['id']);
    });
    foreach($items as &$item){
        $item['logoUrl']=school_logo_url((string)($item['logo_file']??''));
        unset($item['logo_file']); // filename is an internal implementation detail
    }
    unset($item);
    json_out(['success'=>true,'data'=>$items]);
}
if ($action === 'admin_school_order_save') {
    must_post();
    $submitted=$input['schoolIds']??null;
    if(!is_array($submitted)||count($submitted)<1||count($submitted)>200)
        json_out(['success'=>false,'message'=>'ลำดับโรงเรียนไม่ถูกต้อง'],422);
    $seen=[];$actual=[];
    foreach($submitted as $value){
        $id=filter_var($value,FILTER_VALIDATE_INT);
        if($id===false||$id<1||isset($seen[$id]))
            json_out(['success'=>false,'message'=>'รหัสโรงเรียนซ้ำหรือไม่ถูกต้อง'],422);
        $seen[$id]=true;$actual[]=$id;
    }
    try {
        db()->beginTransaction();
        $existing=rows('SELECT id FROM schools ORDER BY id FOR UPDATE');
        $expected=array_map('intval',array_column($existing,'id'));
        sort($expected,SORT_NUMERIC);$sorted=$actual;sort($sorted,SORT_NUMERIC);
        if($expected!==$sorted){
            db()->rollBack();
            json_out(['success'=>false,'message'=>'รายชื่อโรงเรียนมีการเปลี่ยนแปลง กรุณาโหลดหน้าใหม่'],409);
        }
        $statement=db()->prepare('UPDATE schools SET live_position=? WHERE id=?');
        foreach($actual as $position=>$id)$statement->execute([$position+1,$id]);
        db()->commit();
    } catch(Throwable $e) {
        if(db()->inTransaction())db()->rollBack();
        error_log('Bang1 LIVE school order: '.$e->getMessage());
        json_out(['success'=>false,'message'=>'บันทึกลำดับโรงเรียนไม่สำเร็จ'],500);
    }
    log_action('ADMIN_LIVE_SCHOOL_ORDER_UPDATE',$u,'School','all',
        'ลำดับช่อง LIVE: '.implode(',',$actual));
    json_out(['success'=>true,'message'=>'บันทึกลำดับช่องโรงเรียนบนจอ LIVE เรียบร้อย']);
}
if ($action === 'admin_school_save') {
    must_post();
    $id = (int)($input['id'] ?? 0);
    $isNewSchool = $id === 0;
    $name = trim((string)($input['name'] ?? ''));
    $nickname = trim((string)($input['nickname'] ?? ''));
    $active = trim((string)($input['active'] ?? 'Active'));
    if ($name === '' || $nickname === '' || strlen($name)>750 || strlen($nickname)>300
        || !in_array($active,['Active','Inactive'],true)) {
        json_out(['success'=>false,'message'=>'กรุณากรอกข้อมูลโรงเรียนให้ถูกต้อง'],422);
    }
    try {
        db()->beginTransaction();
        if ($id > 0) {
            $old = single('SELECT id, name, nickname FROM schools WHERE id=? FOR UPDATE',[$id]);
            if (!$old) { db()->rollBack(); json_out(['success'=>false,'message'=>'ไม่พบโรงเรียน'],404); }
            db()->prepare('UPDATE schools SET name=?,nickname=?,active=? WHERE id=?')
                ->execute([$name,$nickname,$active,$id]);
            if ($name !== $old['name']) {
                foreach (['runners','registrations','certificates'] as $table) {
                    db()->prepare('UPDATE '.$table.' SET school_name=? WHERE school_name=?')
                        ->execute([$name,$old['name']]);
                }
                db()->prepare('UPDATE coaches SET school_name=? WHERE school_name=?')
                    ->execute([$name,$old['name']]);
            }
            if ($nickname !== $old['nickname']) {
                db()->prepare('UPDATE coaches SET school_name=? WHERE school_name=?')
                    ->execute([$nickname,$old['nickname']]);
            }
        } else {
            $id = (int)db()->query('SELECT COALESCE(MAX(id),0)+1 FROM schools')->fetchColumn();
            db()->prepare('INSERT INTO schools (id,name,nickname,active) VALUES (?,?,?,?)')
                ->execute([$id,$name,$nickname,$active]);
        }
        db()->commit();
    } catch (Throwable $e) {
        if(db()->inTransaction()) db()->rollBack();
        if ($e instanceof PDOException && $e->getCode()==='23000')
            json_out(['success'=>false,'message'=>'มีชื่อโรงเรียนนี้อยู่แล้ว'],409);
        error_log('Bang1 admin school: '.$e->getMessage());
        json_out(['success'=>false,'message'=>'บันทึกข้อมูลโรงเรียนไม่สำเร็จ'],500);
    }
    log_action($isNewSchool?'ADMIN_SCHOOL_CREATE':'ADMIN_SCHOOL_SAVE',$u,'School',(string)$id,$name);
    json_out(['success'=>true,'id'=>$id,'message'=>'บันทึกโรงเรียนเรียบร้อย']);
}

if ($action === 'admin_coaches') {
    // Never return password hashes or the old IDPASS values to browsers.
    json_out(['success'=>true,'data'=>rows(
        'SELECT id, name, email, school_name, role, can_live_checkin FROM coaches ORDER BY id'
    )]);
}
// Admin alone decides which individual Manager may operate the LIVE check-in screen.
if ($action === 'admin_coach_live_checkin_access') {
    must_post();
    $id=filter_var($input['id']??null,FILTER_VALIDATE_INT);
    $allowed=$input['allowed']??null;
    if(!$id||$id<1||!is_bool($allowed))
        json_out(['success'=>false,'message'=>'ข้อมูลสิทธิ์ไม่ถูกต้อง'],422);
    try{
        db()->beginTransaction();
        $account=single('SELECT id,name,role,can_live_checkin FROM coaches WHERE id=? FOR UPDATE',[$id]);
        if(!$account||$account['role']!=='Manager'){
            db()->rollBack();
            json_out(['success'=>false,'message'=>'กำหนดสิทธิ์เช็กชื่อได้เฉพาะบัญชี Manager'],422);
        }
        db()->prepare('UPDATE coaches SET can_live_checkin=? WHERE id=?')
            ->execute([$allowed?1:0,$id]);
        db()->commit();
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        error_log('Bang1 Manager LIVE check-in permission: '.$e->getMessage());
        json_out(['success'=>false,'message'=>'บันทึกสิทธิ์เช็กชื่อรายงานตัวไม่สำเร็จ'],500);
    }
    log_action($allowed?'ADMIN_LIVE_CHECKIN_GRANT':'ADMIN_LIVE_CHECKIN_REVOKE',
        $u,'Coach',(string)$id,'Manager '.$account['name'].' LIVE check-in permission '.($allowed?'granted':'revoked'));
    json_out(['success'=>true,'allowed'=>$allowed,
        'message'=>$allowed?'อนุญาตให้ Manager เช็กชื่อรายงานตัวแล้ว':'ถอนสิทธิ์เช็กชื่อรายงานตัวเรียบร้อยแล้ว']);
}
if ($action === 'admin_coach_save') {
    must_post();
    $id = (int)($input['id'] ?? 0);
    $name = trim((string)($input['name'] ?? ''));
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $school = trim((string)($input['schoolName'] ?? ''));
    $role = trim((string)($input['role'] ?? 'Teacher'));
    $password = (string)($input['password'] ?? '');
    if ($name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL) || $school===''
        || strlen($name)>750 || strlen($email)>255 || strlen($school)>750
        || !in_array($role,['Admin','Manager','Teacher','Staff'],true)) {
        json_out(['success'=>false,'message'=>'ข้อมูลผู้ฝึกซ้อมไม่ถูกต้อง'],422);
    }
    $old=$id>0?single('SELECT id,name,role,email,school_name FROM coaches WHERE id=?',[$id]):null;
    if($id>0 && !$old) json_out(['success'=>false,'message'=>'ไม่พบบัญชีผู้ฝึกซ้อม'],404);
    // Admin can manage every role. This API is already restricted to Admin by require_admin().
    if (in_array($role,['Teacher','Manager','Staff'],true)) {
        $exists=single('SELECT id FROM schools WHERE name=? OR nickname=? LIMIT 1',[$school,$school]);
        if(!$exists)json_out(['success'=>false,'message'=>'กรุณาเลือกโรงเรียนจากรายการ'],422);
    }
    if(($id===0 && strlen($password)<8) || ($password!=='' && strlen($password)<8)){
        json_out(['success'=>false,'message'=>'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร'],422);
    }
    $duplicate=single('SELECT id FROM coaches WHERE email=? AND id<>? LIMIT 1',[$email,$id]);
    if($duplicate)json_out(['success'=>false,'message'=>'มีอีเมลนี้อยู่แล้ว'],409);
    try {
        if($old){
            if($password!==''){
                db()->prepare('UPDATE coaches SET name=?, email=?, school_name=?, role=?, password_hash=?,auth_version=auth_version+1 WHERE id=?')
                  ->execute([$name,$email,$school,$role,password_hash($password,PASSWORD_DEFAULT),$id]);
            }else{
                db()->prepare('UPDATE coaches SET name=?, email=?, school_name=?, role=? WHERE id=?')
                  ->execute([$name,$email,$school,$role,$id]);
            }
        }else{
            $id=(int)db()->query('SELECT COALESCE(MAX(id),0)+1 FROM coaches')->fetchColumn();
            db()->prepare('INSERT INTO coaches(id,name,email,school_name,password_hash,role) VALUES (?,?,?,?,?,?)')
              ->execute([$id,$name,$email,$school,password_hash($password,PASSWORD_DEFAULT),$role]);
        }
    }catch(Throwable $e){
        if ($e instanceof PDOException && $e->getCode()==='23000')
            json_out(['success'=>false,'message'=>'มีอีเมลนี้อยู่แล้ว'],409);
        error_log('Bang1 admin coach: '.$e->getMessage());
        json_out(['success'=>false,'message'=>'บันทึกบัญชีผู้ฝึกซ้อมไม่สำเร็จ'],500);
    }
    // Leaving the Manager role always clears the old LIVE permission.
    // Promotion back to Manager requires a fresh explicit Admin grant.
    if($old && $old['role']==='Manager' && $role!=='Manager')
        db()->prepare('UPDATE coaches SET can_live_checkin=0 WHERE id=?')->execute([$id]);
    log_action($old?'ADMIN_COACH_UPDATE':'ADMIN_COACH_CREATE',$u,'Coach',(string)$id,
        'ปรับปรุงบัญชี '.$email.' (ไม่บันทึกรหัสผ่าน)');
    json_out(['success'=>true,'id'=>$id,'message'=>'บันทึกผู้ฝึกซ้อมเรียบร้อย']);
}

if ($action === 'admin_coach_reset_password') {
    must_post();
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) json_out(['success'=>false,'message'=>'รหัสผู้ฝึกซ้อมไม่ถูกต้อง'],422);
    $coach = single('SELECT id,name,email FROM coaches WHERE id=?',[$id]);
    if (!$coach) json_out(['success'=>false,'message'=>'ไม่พบผู้ฝึกซ้อม'],404);

    // Existing password hashes cannot be reversed: generate a new secret and reveal it once.
    $temporaryPassword = strtoupper(bin2hex(random_bytes(10)));
    $hash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
    try {
        db()->prepare('UPDATE coaches SET password_hash=?,auth_version=auth_version+1 WHERE id=?')->execute([$hash,$id]);
        log_action('ADMIN_COACH_PASSWORD_RESET',$u,'Coach',(string)$id,
            'Admin reset login password for '.$coach['email'].' (password not logged)');
    } catch(Throwable $e) {
        error_log('Bang1 coach password reset failed: '.$e->getMessage());
        json_out(['success'=>false,'message'=>'ตั้งรหัสผ่านใหม่ไม่สำเร็จ'],500);
    }
    header('Cache-Control: no-store, private');
    json_out(['success'=>true,'message'=>'ออกรหัสผ่านใหม่แล้ว โปรดแจ้งผู้ฝึกซ้อมโดยตรง',
        'password'=>$temporaryPassword, 'email'=>$coach['email']]);
}

if ($action === 'admin_coach_delete') {
    must_post();
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) json_out(['success'=>false,'message'=>'รหัสผู้ฝึกซ้อมไม่ถูกต้อง'],422);
    if ((int)($u['coachId']??0) === $id) {
        json_out(['success'=>false,'message'=>'ไม่สามารถลบบัญชีของตนเองได้'],403);
    }
    try {
        db()->beginTransaction();
        $old = single('SELECT id,name,email,role FROM coaches WHERE id=? FOR UPDATE',[$id]);
        if (!$old) {
            db()->rollBack();
            json_out(['success'=>false,'message'=>'ไม่พบผู้ฝึกซ้อม'],404);
        }
        if (in_array($old['role'],['Admin','TopAdmin'],true)) {
            $count = (int)db()->query("SELECT COUNT(*) FROM coaches WHERE role IN ('Admin','TopAdmin')")->fetchColumn();
            if ($count <= 1) {
                db()->rollBack();
                json_out(['success'=>false,'message'=>'ไม่สามารถลบบัญชีผู้ดูแลระบบคนสุดท้ายได้'],409);
            }
        }
        db()->prepare('DELETE FROM coaches WHERE id=?')->execute([$id]);
        db()->commit();
        // Keep registrations, runners and audit history: only the login account is deleted.
        log_action('ADMIN_COACH_DELETE',$u,'Coach',(string)$id,
            'Deleted coach login '.$old['email'].' (existing athletes/registrations retained)');
    } catch(Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('Bang1 coach deletion failed: '.$e->getMessage());
        json_out(['success'=>false,'message'=>'ลบผู้ฝึกซ้อมไม่สำเร็จ'],500);
    }
    json_out(['success'=>true,'message'=>'ลบบัญชีผู้ฝึกซ้อมเรียบร้อยแล้ว']);
}

if ($action === 'admin_logs') {
    $page=max(1,min(100000,(int)($_GET['page']??1)));
    $q=trim((string)($_GET['q']??''));
    if(strlen($q)>180)json_out(['success'=>false,'message'=>'คำค้นหายาวเกินไป'],422);
    // Security audit is never cleared, even when ordinary logs are reset/restored.
    // Filter duplicates since reset events are also saved in audit_logs for compatibility.
    $source="(SELECT id,event_time,action_code,action_text,module,target_type,target_id,target_name,
       runner_id,reg_id,event_name,user_email,user_name,user_role,user_school,detail
       FROM audit_logs WHERE action_code NOT IN ('ADMIN_DATA_RESET','ADMIN_BACKUP_EXPORT','ADMIN_BACKUP_RESTORE')
       UNION ALL
       SELECT id,event_time,action_code,'' AS action_text,'SECURITY' AS module,
       'System' AS target_type,'' AS target_id,'' AS target_name,'' AS runner_id,'' AS reg_id,
       '' AS event_name,actor_email AS user_email,actor_email AS user_name,'Admin' AS user_role,
       '' AS user_school,detail FROM admin_security_audit) AS audit_history";
    $where='';$params=[];
    if($q!==''){
      $where=' WHERE (user_name LIKE ? OR user_school LIKE ? OR action_code LIKE ? OR target_name LIKE ?)';
      $wildcard='%'.$q.'%';$params=[$wildcard,$wildcard,$wildcard,$wildcard];
    }
    $stmt=db()->prepare('SELECT COUNT(*) FROM '.$source.$where);
    $stmt->execute($params);
    $total=(int)$stmt->fetchColumn();
    $page=min($page,max(1,(int)ceil($total/20)));
    $offset=($page-1)*20;
    $sql='SELECT id,event_time,action_code,action_text,module,target_type,target_id,target_name,
       runner_id,reg_id,event_name,user_email,user_name,user_role,user_school,detail FROM '
       .$source.$where.' ORDER BY event_time DESC,id DESC LIMIT 20 OFFSET '.$offset;
    json_out(['success'=>true,'page'=>$page,'perPage'=>20,'total'=>$total,
       'data'=>rows($sql,$params)]);
}

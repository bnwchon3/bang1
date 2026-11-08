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
    json_out(['success'=>true,'data'=>rows(
        'SELECT id, name, nickname, active FROM schools ORDER BY id'
    )]);
}
if ($action === 'admin_school_save') {
    must_post();
    $id = (int)($input['id'] ?? 0);
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
    log_action($id>0?'ADMIN_SCHOOL_SAVE':'ADMIN_SCHOOL_CREATE',$u,'School',(string)$id,$name);
    json_out(['success'=>true,'id'=>$id,'message'=>'บันทึกโรงเรียนเรียบร้อย']);
}

if ($action === 'admin_coaches') {
    // Never return password hashes or the old IDPASS values to browsers.
    json_out(['success'=>true,'data'=>rows(
        'SELECT id, name, email, school_name, role FROM coaches ORDER BY id'
    )]);
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
        || !in_array($role,['Teacher','Guest','Admin','TopAdmin'],true)) {
        json_out(['success'=>false,'message'=>'ข้อมูลผู้ฝึกซ้อมไม่ถูกต้อง'],422);
    }
    $old=$id>0?single('SELECT id,name,role,email,school_name FROM coaches WHERE id=?',[$id]):null;
    if($id>0 && !$old) json_out(['success'=>false,'message'=>'ไม่พบบัญชีผู้ฝึกซ้อม'],404);
    // An ordinary Admin can manage teacher/guest accounts but cannot create,
    // edit or promote administrator accounts.
    if ($u['role']!=='TopAdmin' && (
        in_array($role,['Admin','TopAdmin'],true) ||
        ($old && in_array($old['role'],['Admin','TopAdmin'],true))
    )) json_out(['success'=>false,'message'=>'ไม่อนุญาตให้แก้ไขสิทธิ์บัญชีผู้ดูแลระบบ'],403);
    if (in_array($role,['Teacher','Guest'],true)) {
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
                db()->prepare('UPDATE coaches SET name=?, email=?, school_name=?, role=?, password_hash=? WHERE id=?')
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
    log_action($old?'ADMIN_COACH_UPDATE':'ADMIN_COACH_CREATE',$u,'Coach',(string)$id,
        'ปรับปรุงบัญชี '.$email.' (ไม่บันทึกรหัสผ่าน)');
    json_out(['success'=>true,'id'=>$id,'message'=>'บันทึกผู้ฝึกซ้อมเรียบร้อย']);
}

if ($action === 'admin_logs') {
    $page = max(1,min(100000,(int)($_GET['page'] ?? 1)));
    $q = trim((string)($_GET['q'] ?? ''));
    if (strlen($q)>180)json_out(['success'=>false,'message'=>'คำค้นหายาวเกินไป'],422);
    $where = '';
    $params = [];
    if($q!==''){
       $where=' WHERE (user_name LIKE ? OR user_school LIKE ? OR action_code LIKE ? OR target_name LIKE ?)';
       $search='%'.$q.'%';
       $params=[$search,$search,$search,$search];
    }
    $stmt=db()->prepare('SELECT COUNT(*) FROM audit_logs'.$where);
    $stmt->execute($params);
    $total=(int)$stmt->fetchColumn();
    $page=min($page,max(1,(int)ceil($total/20)));
    $offset=($page-1)*20;
    $sql='SELECT id,event_time,action_code,action_text,module,target_type,target_id,target_name,runner_id,reg_id,event_name,user_email,user_name,user_role,user_school,detail FROM audit_logs'
         .$where.' ORDER BY id DESC LIMIT 20 OFFSET '.$offset;
    json_out(['success'=>true,'page'=>$page,'perPage'=>20,'total'=>$total,
        'data'=>rows($sql,$params)]);
}

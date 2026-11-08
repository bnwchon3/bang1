<?php
declare(strict_types=1);
// Account directory, mail-verified self-service resets and approval-only signups.
// api.php includes this file only for account_* and admin_account_* routes.

function account_public_post(): void {
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST') json_out(['success'=>false,'message'=>'POST required'],405);
    $token=(string)($_SESSION['account_csrf']??'');
    $provided=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'');
    if($token==='' || $provided==='' || !hash_equals($token,$provided))
        json_out(['success'=>false,'message'=>'เซสชันหมดอายุ กรุณาโหลดหน้าเว็บใหม่'],403);
}
function account_email(mixed $value): string {
    $email=strtolower(trim((string)$value));
    return strlen($email)<=255 && filter_var($email,FILTER_VALIDATE_EMAIL) && !preg_match('/[\r\n]/',$email) ? $email : '';
}
function account_mask(string $email): string {
    $parts=explode('@',$email,2);
    if(count($parts)!==2)return '***';
    $prefix=$parts[0];
    $visible=max(1,(int)floor(strlen($prefix)/2));
    return substr($prefix,0,$visible).str_repeat('x',max(0,strlen($prefix)-$visible)).'@'.$parts[1];
}
function account_ip_hash(): string {
    return hash_hmac('sha256',(string)($_SERVER['REMOTE_ADDR']??'unknown'),config('deploy_token'));
}
function account_limit(string $action,int $max):void {
    $ip=account_ip_hash();
    $count=db()->prepare("SELECT COUNT(*) FROM account_public_limits WHERE ip_hash=? AND action_name=? AND created_at>=NOW()-INTERVAL 1 HOUR");
    $count->execute([$ip,$action]);
    if((int)$count->fetchColumn()>=$max)
        json_out(['success'=>false,'message'=>'มีคำขอมากเกินไป กรุณาลองใหม่ภายหลัง'],429);
    db()->prepare('INSERT INTO account_public_limits(ip_hash,action_name) VALUES(?,?)')->execute([$ip,$action]);
    if(random_int(1,100)===1)db()->exec('DELETE FROM account_public_limits WHERE created_at<NOW()-INTERVAL 2 DAY');
}
function account_mail(string $email,string $subject,string $message):bool {
    $from=account_email(config('mail_from'));
    if($from==='')$from='no-reply@bannawang.ac.th';
    $headers=[
        'From: =?UTF-8?B?'.base64_encode('ระบบกรีฑากลุ่มโรงเรียนบางละมุง 1').'?= <'.$from.'>',
        'Reply-To: '.$from,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Auto-Response-Suppress: All'
    ];
    return function_exists('mail') && @mail($email,'=?UTF-8?B?'.base64_encode($subject).'?=',$message,implode("\r\n",$headers));
}
function account_reset_password_valid(string $password):bool {
    return strlen($password)>=8 && strlen($password)<=128;
}
function account_school(string $school):string {
    $st=db()->prepare("SELECT name FROM schools WHERE (name=? OR nickname=?) AND (active IS NULL OR active IN ('Active','ใช้งาน','1')) LIMIT 1");
    $st->execute([$school,$school]);
    return (string)($st->fetchColumn()?:'');
}

if($action==='account_portal'){
    if(($_SERVER['REQUEST_METHOD']??'')!=='GET')json_out(['success'=>false],405);
    if(!isset($_SESSION['account_csrf']))$_SESSION['account_csrf']=bin2hex(random_bytes(24));
    $data=[];
    $query=db()->query("SELECT c.id,c.name,c.email,COALESCE((SELECT s.name FROM schools s WHERE s.nickname=c.school_name LIMIT 1),c.school_name) AS school_name
        FROM coaches c WHERE c.role IN ('Admin','TopAdmin','Manager','Staff','Teacher') ORDER BY c.school_name,c.name LIMIT 1500");
    foreach($query as $coach){
        $data[]=['id'=>(int)$coach['id'],'name'=>(string)$coach['name'],'school'=>(string)$coach['school_name'],
            'emailMasked'=>account_mask((string)$coach['email'])];
    }
    $schools=db()->query("SELECT name FROM schools WHERE active IS NULL OR active IN ('Active','ใช้งาน','1') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    json_out(['success'=>true,'csrf'=>$_SESSION['account_csrf'],'data'=>$data,'schools'=>$schools]);
}
if($action==='account_signup'){
    account_public_post();
    account_limit('signup',8);
    $first=trim((string)($input['firstName']??''));
    $last=trim((string)($input['lastName']??''));
    $email=account_email($input['email']??'');
    $schoolInput=trim((string)($input['school']??''));
    $school=account_school($schoolInput);
    if(!$first||!$last||!$email||!$school||strlen($first)>360||strlen($last)>360||
       preg_match_all('/./u',$first)>120||preg_match_all('/./u',$last)>120||
       !preg_match("/^[\p{L}\p{M}\s.'\-]+$/u",$first)||
       !preg_match("/^[\p{L}\p{M}\s.'\-]+$/u",$last)){
        json_out(['success'=>false,'message'=>'กรุณาระบุชื่อ นามสกุล โรงเรียน และอีเมลให้ถูกต้อง'],422);
    }
    $q=db()->prepare('SELECT id FROM coaches WHERE email=? LIMIT 1');
    $q->execute([$email]);
    if($q->fetchColumn())json_out(['success'=>true,'message'=>'หากยังไม่มีบัญชี กรุณารอผู้ดูแลระบบพิจารณาคำขอ หรือใช้ปุ่มรีเซ็ตรหัสผ่านหากเคยสมัครแล้ว']);
    $q=db()->prepare('SELECT id,status FROM account_applications WHERE email=? LIMIT 1');
    $q->execute([$email]);$old=$q->fetch();
    if($old && $old['status']==='pending')
        json_out(['success'=>true,'message'=>'มีคำขอสมัครของอีเมลนี้รอผู้ดูแลระบบพิจารณาอยู่แล้ว']);
    if($old && $old['status']==='approved')
        json_out(['success'=>true,'message'=>'บัญชีนี้ได้รับการอนุมัติแล้ว กรุณาใช้ปุ่มรีเซ็ตรหัสผ่านเพื่อกำหนดรหัสผ่านของตนเอง']);
    if($old){
        db()->prepare("UPDATE account_applications SET first_name=?,last_name=?,school_name=?,status='pending',reviewed_by=NULL,reviewed_at=NULL,created_at=NOW() WHERE id=?")
            ->execute([$first,$last,$school,(int)$old['id']]);
    }else{
        db()->prepare("INSERT INTO account_applications(first_name,last_name,school_name,email,status) VALUES(?,?,?,?,'pending')")
            ->execute([$first,$last,$school,$email]);
    }
    log_action('ACCOUNT_SIGNUP_REQUEST',['email'=>$email,'name'=>$first.' '.$last,'schoolName'=>$school],'AccountApplication',hash('sha256',$email),'Pending Admin approval');
    json_out(['success'=>true,'message'=>'ส่งคำขอสมัครเรียบร้อยแล้ว กรุณารอ Admin อนุมัติ ก่อนตั้งรหัสผ่านเพื่อเข้าสู่ระบบ']);
}
if($action==='account_reset_request'){
    account_public_post();
    account_limit('reset_request',24);
    $email=account_email($input['email']??'');
    $id=(int)($input['id']??0);
    if($email===''||$id<=0)json_out(['success'=>false,'message'=>'กรุณาระบุอีเมลที่ลงทะเบียนให้ถูกต้อง'],422);
    unset($_SESSION['account_pending_reset'],$_SESSION['account_verified_reset']);
    $q=db()->prepare('SELECT id,name,email FROM coaches WHERE id=? AND email=? LIMIT 1');
    $q->execute([$id,$email]);$coach=$q->fetch();
    if(!$coach){
        // Email matching alone is never proof of ownership; do not disclose a valid address.
        json_out(['success'=>true,'message'=>'หากข้อมูลตรงกับบัญชี ระบบจะส่งรหัสยืนยัน 6 หลักไปยังอีเมลนั้น','requiresCode'=>true]);
    }
    $q=db()->prepare("SELECT COUNT(*) AS total,MAX(created_at) AS newest FROM account_reset_challenges WHERE coach_id=? AND created_at>=NOW()-INTERVAL 1 HOUR");
    $q->execute([$id]);$stats=$q->fetch();
    if((int)$stats['total']>=3 || ($stats['newest']&&strtotime((string)$stats['newest'])>time()-90))
        json_out(['success'=>false,'message'=>'ส่งรหัสยืนยันแล้ว กรุณาตรวจสอบอีเมลหรือลองอีกครั้งภายหลัง'],429);
    $otp=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
    $q=db()->prepare("INSERT INTO account_reset_challenges(coach_id,code_hash,ip_hash,expires_at) VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE))");
    $q->execute([$id,password_hash($otp,PASSWORD_DEFAULT),account_ip_hash()]);
    $challenge=(int)db()->lastInsertId();
    $mailMessage="เรียนผู้ใช้งานระบบกรีฑา\n\nรหัสยืนยันเพื่อรีเซ็ตรหัสผ่านของคุณคือ: ".$otp.
      "\nรหัสนี้ใช้ได้ภายใน 10 นาที และใช้ได้เพียงครั้งเดียว\n\nหากคุณไม่ได้ร้องขอ โปรดละเว้นข้อความนี้ อย่าแจ้งรหัสให้ผู้อื่นทราบ\n\nระบบบริหารจัดการกรีฑา กลุ่มโรงเรียนบางละมุง 1";
    if(!account_mail($email,'รหัสยืนยันรีเซ็ตรหัสผ่านระบบกรีฑา',$mailMessage)){
        db()->prepare('DELETE FROM account_reset_challenges WHERE id=?')->execute([$challenge]);
        error_log('Bang1 account reset: mail transport unavailable');
        json_out(['success'=>false,'message'=>'ระบบส่งอีเมลยืนยันยังไม่พร้อม กรุณาติดต่อผู้ดูแลระบบ'],503);
    }
    $_SESSION['account_pending_reset']=['challenge'=>$challenge,'coachId'=>$id,'expires'=>time()+600];
    log_action('ACCOUNT_RESET_CODE_SENT',['email'=>$email],'Coach',(string)$id,'OTP sent; code not logged');
    json_out(['success'=>true,'message'=>'หากข้อมูลตรงกับบัญชี ระบบจะส่งรหัสยืนยัน 6 หลักไปยังอีเมลนั้น','requiresCode'=>true]);
}
if($action==='account_reset_verify'){
    account_public_post();
    account_limit('reset_verify',60);
    $pending=$_SESSION['account_pending_reset']??null;
    $code=(string)($input['code']??'');
    if(!is_array($pending)||($pending['expires']??0)<time()||!preg_match('/^\d{6}$/',$code))
        json_out(['success'=>false,'message'=>'รหัสยืนยันไม่ถูกต้องหรือหมดอายุ กรุณาขอรหัสใหม่'],422);
    $q=db()->prepare('SELECT * FROM account_reset_challenges WHERE id=? AND coach_id=? LIMIT 1');
    $q->execute([(int)$pending['challenge'],(int)$pending['coachId']]);$challenge=$q->fetch();
    if(!$challenge||$challenge['used_at']||$challenge['verified_at']||strtotime((string)$challenge['expires_at'])<time()||(int)$challenge['attempts']>=5){
        unset($_SESSION['account_pending_reset']);
        json_out(['success'=>false,'message'=>'รหัสยืนยันหมดอายุหรือใช้ไม่ได้แล้ว กรุณาขอรหัสใหม่'],422);
    }
    $upd=db()->prepare('UPDATE account_reset_challenges SET attempts=attempts+1 WHERE id=? AND attempts<5 AND verified_at IS NULL AND used_at IS NULL');
    $upd->execute([(int)$pending['challenge']]);
    if(!$upd->rowCount())json_out(['success'=>false,'message'=>'ยืนยันรหัสไม่สำเร็จ กรุณาขอรหัสใหม่'],422);
    if(!password_verify($code,(string)$challenge['code_hash']))
        json_out(['success'=>false,'message'=>'รหัสยืนยันไม่ถูกต้อง กรุณาตรวจสอบอีกครั้ง (สูงสุด 5 ครั้ง)'],422);
    db()->prepare('UPDATE account_reset_challenges SET verified_at=NOW(),expires_at=DATE_ADD(NOW(),INTERVAL 10 MINUTE) WHERE id=?')->execute([(int)$pending['challenge']]);
    unset($_SESSION['account_pending_reset']);
    $_SESSION['account_verified_reset']=['challenge'=>(int)$challenge['id'],'coachId'=>(int)$challenge['coach_id'],'expires'=>time()+600];
    session_regenerate_id(true);
    json_out(['success'=>true,'verified'=>true,'message'=>'ยืนยันอีเมลสำเร็จ กรุณาตั้งรหัสผ่านใหม่']);
}
if($action==='account_reset_complete'){
    account_public_post();
    account_limit('reset_complete',30);
    $verified=$_SESSION['account_verified_reset']??null;
    $pass=(string)($input['password']??'');
    $confirm=(string)($input['confirmPassword']??'');
    if(!account_reset_password_valid($pass)||!hash_equals($pass,$confirm))
        json_out(['success'=>false,'message'=>'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร และต้องตรงกับช่องยืนยัน'],422);
    if(!is_array($verified)||($verified['expires']??0)<time())
        json_out(['success'=>false,'message'=>'การยืนยันหมดอายุ กรุณาเริ่มใหม่'],403);
    try{
        db()->beginTransaction();
        $q=db()->prepare('SELECT id,coach_id,verified_at,used_at,expires_at FROM account_reset_challenges WHERE id=? AND coach_id=? FOR UPDATE');
        $q->execute([(int)$verified['challenge'],(int)$verified['coachId']]);$challenge=$q->fetch();
        if(!$challenge||!$challenge['verified_at']||$challenge['used_at']||strtotime((string)$challenge['expires_at'])<time()){
            db()->rollBack();
            json_out(['success'=>false,'message'=>'รหัสยืนยันใช้แล้วหรือหมดอายุ'],403);
        }
        db()->prepare('UPDATE coaches SET password_hash=?,auth_version=auth_version+1 WHERE id=?')
            ->execute([password_hash($pass,PASSWORD_DEFAULT),(int)$verified['coachId']]);
        db()->prepare('UPDATE account_reset_challenges SET used_at=NOW() WHERE id=?')->execute([(int)$verified['challenge']]);
        db()->commit();
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        error_log('Bang1 reset finish: '.$e->getMessage());
        json_out(['success'=>false,'message'=>'ตั้งรหัสผ่านใหม่ไม่สำเร็จ กรุณาลองอีกครั้ง'],500);
    }
    unset($_SESSION['account_verified_reset']);
    log_action('ACCOUNT_PASSWORD_SELF_RESET',[],'Coach',(string)$verified['coachId'],'Verified email OTP; sessions invalidated');
    json_out(['success'=>true,'message'=>'ตั้งรหัสผ่านใหม่สำเร็จ กรุณาเข้าสู่ระบบด้วยรหัสผ่านใหม่']);
}
if($action==='admin_account_applications'){
    require_admin($u);
    $records=db()->query("SELECT id,first_name,last_name,school_name,email,status,created_at,reviewed_at FROM account_applications ORDER BY CASE WHEN status='pending' THEN 0 ELSE 1 END,created_at DESC LIMIT 300")->fetchAll();
    json_out(['success'=>true,'data'=>$records]);
}
if($action==='admin_account_review'){
    require_admin($u);
    must_post();
    $id=(int)($input['id']??0);
    $decision=(string)($input['decision']??'');
    if($id<=0||!in_array($decision,['approve','reject'],true))
        json_out(['success'=>false,'message'=>'รายการหรือคำสั่งไม่ถูกต้อง'],422);
    try{
        db()->beginTransaction();
        $q=db()->prepare('SELECT * FROM account_applications WHERE id=? FOR UPDATE');
        $q->execute([$id]);$application=$q->fetch();
        if(!$application||$application['status']!=='pending'){
            db()->rollBack();
            json_out(['success'=>false,'message'=>'คำขอนี้ได้รับการพิจารณาแล้ว'],409);
        }
        if($decision==='approve'){
            $exists=db()->prepare('SELECT id FROM coaches WHERE email=? LIMIT 1');
            $exists->execute([$application['email']]);
            if($exists->fetchColumn()){
                db()->rollBack();
                json_out(['success'=>false,'message'=>'มีบัญชีอีเมลนี้อยู่แล้ว'],409);
            }
            // New accounts have no usable password until the owner verifies email.
            db()->prepare("INSERT INTO coaches(name,email,school_name,password_hash,role,auth_version) VALUES(?,?,?,?,'Teacher',1)")
                ->execute([$application['first_name'].' '.$application['last_name'],$application['email'],$application['school_name'],
                    password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT)]);
        }
        db()->prepare('UPDATE account_applications SET status=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?')
            ->execute([$decision==='approve'?'approved':'rejected',(int)$u['coachId'],$id]);
        db()->commit();
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        error_log('Bang1 application review: '.$e->getMessage());
        json_out(['success'=>false,'message'=>'ไม่สามารถพิจารณาคำขอได้'],500);
    }
    log_action('ADMIN_ACCOUNT_'.strtoupper($decision),$u,'AccountApplication',(string)$id,'Email '.$application['email']);
    $message=$decision==='approve'?'อนุมัติแล้ว ผู้สมัครสามารถยืนยันอีเมลผ่านปุ่มรีเซ็ตรหัสผ่านเพื่อตั้งรหัสผ่านครั้งแรก':'ไม่อนุมัติคำขอเรียบร้อยแล้ว';
    if($decision==='approve'){
        $sent=account_mail((string)$application['email'],'อนุมัติบัญชีระบบกรีฑาแล้ว',
            "บัญชีของคุณได้รับอนุมัติแล้ว\n\nเปิด https://bannawang.ac.th/bang1/run/ เลือกชื่อบัญชี แล้วกดรีเซ็ตรหัสผ่าน เพื่อรับรหัสยืนยันและตั้งรหัสผ่านครั้งแรก\n\nระบบบริหารจัดการกรีฑา กลุ่มโรงเรียนบางละมุง 1");
        if(!$sent)$message.=' (ระบบส่งอีเมลแจ้งเตือนไม่สำเร็จ กรุณาตรวจสอบระบบอีเมลของโฮสต์)';
    }
    json_out(['success'=>true,'message'=>$message]);
}

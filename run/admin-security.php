<?php
declare(strict_types=1);
// Included only after require_user(), CSRF checking, and require_admin().
// Passwords are never persisted in sessions, logs, or backup metadata.
function admin_verify_password(array $actor, mixed $supplied): void {
    $failed=(int)($_SESSION['admin_reauth_failures']??0);
    $lockedUntil=(int)($_SESSION['admin_reauth_locked_until']??0);
    if ($failed>=5 && $lockedUntil>time()) {
        json_out(['success'=>false,'message'=>'กรอกรหัสผ่านผิดหลายครั้ง กรุณาลองใหม่ภายหลัง'],429);
    }
    if(!is_string($supplied)||$supplied===''||strlen($supplied)>4096) {
        json_out(['success'=>false,'message'=>'กรุณากรอกรหัสผ่านเข้าสู่ระบบของ Admin'],422);
    }
    $stmt=db()->prepare("SELECT password_hash, role FROM coaches WHERE id=? AND email=? LIMIT 1");
    $stmt->execute([(int)$actor['coachId'],(string)$actor['email']]);
    $row=$stmt->fetch();
    if(!$row || !in_array($row['role'],['Admin','TopAdmin'],true) ||
       !password_verify($supplied,(string)$row['password_hash'])) {
        $failed++;
        $_SESSION['admin_reauth_failures']=$failed;
        if($failed>=5)$_SESSION['admin_reauth_locked_until']=time()+600;
        json_out(['success'=>false,'message'=>'รหัสผ่าน Admin ไม่ถูกต้อง'],403);
    }
    unset($_SESSION['admin_reauth_failures'],$_SESSION['admin_reauth_locked_until']);
}
function admin_security_log(array $actor,string $action,string $detail): void {
    $stmt=db()->prepare('INSERT INTO admin_security_audit(action_code,actor_id,actor_email,detail) VALUES (?,?,?,?)');
    $stmt->execute([$action,(int)$actor['coachId'],(string)$actor['email'],$detail]);
}

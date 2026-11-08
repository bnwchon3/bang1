<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Bangkok');
ini_set('display_errors','0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store');
$configPath=dirname(__DIR__,2).'/.private/config.php';
if (!is_file($configPath)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>false,'message'=>'ยังไม่ได้ตั้งค่าเซิร์ฟเวอร์'],JSON_UNESCAPED_UNICODE);
    exit;
}
$cfg=require $configPath;
function config(string $key): string { global $cfg; return (string)($cfg[$key]??''); }
function db(): PDO {
    static $pdo=null;
    if (!$pdo) {
        $pdo=new PDO('mysql:host='.config('db_host').';dbname='.config('db_name').';charset=utf8mb4', config('db_user'), config('db_password'),[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
        $pdo->exec("SET time_zone = '+07:00'");
    }
    return $pdo;
}
function json_out(mixed $payload,int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function body(): array {
    $b=json_decode((string)file_get_contents('php://input'),true);
    return is_array($b)?$b:[];
}
function session_open(): void {
    if(session_status()!==PHP_SESSION_ACTIVE) {
        // Original application remembered a login for 10 hours; use a secure server session.
        ini_set('session.gc_maxlifetime', (string)(10*60*60));
        session_name('BANGLAMUNG1');
        session_set_cookie_params(['lifetime'=>0,'path'=>'/bang1/run','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
        session_start();
        if (isset($_SESSION['user'])) {
            $started=(int)($_SESSION['login_started_at']??time());
            if (time()-$started>10*60*60) {
                $_SESSION=[];
                session_regenerate_id(true);
            } else {
                $_SESSION['login_started_at']=$started;
            }
        }
    }
}
function require_user(): array {
    session_open();
    if (!isset($_SESSION['user'])) json_out(['success'=>false,'message'=>'กรุณาเข้าสู่ระบบ'],401);
    // Synchronize permissions with the account on every request so promotions/revocations take effect immediately.
    $id=(int)($_SESSION['user']['coachId']??0);
    $q=db()->prepare('SELECT id,name,email,school_name,role FROM coaches WHERE id=? LIMIT 1');
    $q->execute([$id]);
    $coach=$q->fetch();
    if(!$coach || !in_array($coach['role'],['Admin','TopAdmin','Manager','Teacher','Staff'],true)) {
        $_SESSION=[];
        json_out(['success'=>false,'message'=>'บัญชีนี้ไม่มีสิทธิ์ใช้งาน กรุณาเข้าสู่ระบบใหม่'],401);
    }
    $school=$coach['school_name'];
    $s=db()->prepare('SELECT name FROM schools WHERE nickname=? LIMIT 1');
    $s->execute([$school]);
    $mapped=$s->fetchColumn();
    if($mapped) $school=$mapped;
    $_SESSION['user']=['coachId'=>(int)$coach['id'],'name'=>$coach['name'],'email'=>$coach['email'],
      'role'=>$coach['role'],'schoolName'=>$school];
    return $_SESSION['user'];
}
function is_admin(array $u): bool { return in_array(($u['role']??''),['Admin','TopAdmin'],true); }
function is_reviewer(array $u): bool { return is_admin($u)||($u['role']??'')==='Manager'; }
function require_reviewer(array $u): void { if(!is_reviewer($u)) json_out(['success'=>false,'message'=>'เฉพาะผู้ดูแลระบบหรือผู้ตรวจสอบ'],403); }
function require_editor(array $u): void {
    if(!is_admin($u)&&($u['role']??'')!=='Teacher') json_out(['success'=>false,'message'=>'ไม่มีสิทธิ์ทำรายการนี้'],403);
}
function require_admin(array $u): void { if(!is_admin($u)) json_out(['success'=>false,'message'=>'เฉพาะ Admin'],403); }
function authorize_athletics_action(array $u,string $action): void {
    if(is_admin($u))return;
    $teacher=['schools','runners','save_runner','delete_runner','upload','events','registrations',
      'save_registration','delete_registration','features'];
    $manager=['runners','update_status','features'];
    $staff=['staff_results_list','staff_results_save','score_summary'];
    $allowed=($u['role']??'')==='Manager'?$manager:(($u['role']??'')==='Teacher'?$teacher:(($u['role']??'')==='Staff'?$staff:[]));
    if(!in_array($action,$allowed,true)) json_out(['success'=>false,'message'=>'ไม่มีสิทธิ์เข้าใช้งานส่วนนี้'],403);
}
function csrf_check(): void {
    session_open();
    if(!hash_equals((string)($_SESSION['csrf']??''),(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))) json_out(['success'=>false,'message'=>'CSRF token ไม่ถูกต้อง'],403);
}
function log_action(string $code,array $u=[],string $type='',string $target='',string $details=''): void {
    $q=db()->prepare('INSERT INTO audit_logs(action_code,user_email,user_name,user_role,user_school,target_type,target_id,detail) VALUES (?,?,?,?,?,?,?,?)');
    $q->execute([$code,$u['email']??'', $u['name']??'', $u['role']??'', $u['schoolName']??'', $type,$target,$details]);
}
function school_filter(array $u): array {
    if(is_admin($u)) return ['',[]];
    return [' AND school_name=? ',[$u['schoolName']??'']];
}
function safe_url(?string $url): ?string {
    $url=trim((string)$url);
    if(!$url) return null;
    if(!filter_var($url,FILTER_VALIDATE_URL))return null;
    $p=parse_url($url);
    if(($p['scheme']??'')!=='https')return null;
    return $url;
}

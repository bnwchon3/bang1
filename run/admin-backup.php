<?php
declare(strict_types=1);
// Encrypted, authenticated in-memory backup/restore. No exports saved to public directories.
if(!in_array($action,['admin_backup_export','admin_backup_preview','admin_backup_restore'],true))return;
must_post();
const BACKUP_TABLES=['source_rows','schools','coaches','runners','events','registrations','heats','feature_config','audit_logs','race_results','certificates','import_batches','event_results','race_history_batches','race_history_results'];
const BACKUP_MAX_BYTES=31457280;
const BACKUP_MAX_PLAINTEXT=83886080;
function backup_columns(string $table): array {
    if(!in_array($table,BACKUP_TABLES,true))throw new RuntimeException('Unexpected table');
    $fields=db()->query('SHOW COLUMNS FROM '.chr(96).$table.chr(96))->fetchAll(PDO::FETCH_ASSOC);
    return array_column($fields,'Field');
}
function backup_snapshot(): array {
    $tables=[];$counts=[];
    foreach(BACKUP_TABLES as $table){
        $columns=backup_columns($table);
        $records=db()->query('SELECT * FROM '.chr(96).$table.chr(96))->fetchAll(PDO::FETCH_ASSOC);
        $tables[$table]=['columns'=>$columns,'rows'=>$records];
        $counts[$table]=count($records);
    }
    return ['format'=>'bang1-snapshot','version'=>1,'created_at'=>date('c'),'counts'=>$counts,'tables'=>$tables,
      'note'=>'Google Drive photos are linked by URL and are not stored in this file.'];
}
function backup_encrypt(array $snapshot,string $passphrase): string {
    $json=json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
    if(strlen($json)>BACKUP_MAX_PLAINTEXT)throw new RuntimeException('Backup data too large');
    $compressed=gzencode($json,6);
    if($compressed===false)throw new RuntimeException('Compression failed');
    $salt=random_bytes(16);$iv=random_bytes(12);$tag='';
    $key=hash_pbkdf2('sha256',$passphrase,$salt,210000,32,true);
    $encrypted=openssl_encrypt($compressed,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'',16);
    if($encrypted===false)throw new RuntimeException('Encryption failed');
    return 'BNW1'.$salt.$iv.$tag.$encrypted;
}
function backup_decrypt(string $bytes,string $passphrase): array {
    if(strlen($bytes)<49||strlen($bytes)>BACKUP_MAX_BYTES||substr($bytes,0,4)!=='BNW1')
        json_out(['success'=>false,'message'=>'ไฟล์ Backup ไม่ถูกต้องหรือใหญ่เกินกำหนด'],422);
    $salt=substr($bytes,4,16);$iv=substr($bytes,20,12);$tag=substr($bytes,32,16);
    $key=hash_pbkdf2('sha256',$passphrase,$salt,210000,32,true);
    $compressed=openssl_decrypt(substr($bytes,48),'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'');
    if($compressed===false)json_out(['success'=>false,'message'=>'รหัสไฟล์ Backup ไม่ถูกต้องหรือไฟล์เสียหาย'],422);
    $json=@gzdecode($compressed,BACKUP_MAX_PLAINTEXT);
    if($json===false||strlen($json)>BACKUP_MAX_PLAINTEXT)json_out(['success'=>false,'message'=>'ไฟล์ Backup ไม่สมบูรณ์หรือมีขนาดใหญ่เกินไป'],422);
    try{$decoded=json_decode($json,true,512,JSON_THROW_ON_ERROR);}
    catch(Throwable $e){json_out(['success'=>false,'message'=>'รูปแบบข้อมูล Backup ไม่รองรับ'],422);}
    if(!is_array($decoded)||($decoded['format']??'')!=='bang1-snapshot'||($decoded['version']??null)!==1||
       !isset($decoded['tables'])||!is_array($decoded['tables']))
        json_out(['success'=>false,'message'=>'ไฟล์ Backup ไม่ตรงกับระบบบางละมุง 1'],422);
    // Previous encrypted snapshots may lack Staff results or immutable
    // championship archive tables. Allow strict ordered prefixes and preserve
    // existing on-host champions when the backup does not contain them.
    $present=array_keys($decoded['tables']);
    if($present!==array_slice(BACKUP_TABLES,0,count($present))||count($present)<12)
        json_out(['success'=>false,'message'=>'ตารางในไฟล์ Backup ไม่ตรงกับระบบ'],422);
    foreach(array_slice(BACKUP_TABLES,count($present)) as $missing){
        $decoded['tables'][$missing]=['columns'=>backup_columns($missing),'rows'=>[]];
    }
    if(array_keys($decoded['tables'])!==BACKUP_TABLES)
        json_out(['success'=>false,'message'=>'จำนวนตารางในไฟล์ Backup ไม่ตรงกับระบบ'],422);
    $counts=[];
    foreach(BACKUP_TABLES as $table){
        $entry=$decoded['tables'][$table];
        if(!is_array($entry)||!isset($entry['columns'],$entry['rows'])||!is_array($entry['columns'])||
           !is_array($entry['rows'])||$entry['columns']!==backup_columns($table))
            json_out(['success'=>false,'message'=>'โครงสร้างตาราง '.$table.' ไม่ตรงกับระบบปัจจุบัน'],422);
        $cols=$entry['columns'];
        foreach($entry['rows'] as $row){
            if(!is_array($row)||array_keys($row)!==$cols)
                json_out(['success'=>false,'message'=>'ข้อมูลคอลัมน์ไม่ถูกต้องใน '.$table],422);
        }
        $counts[$table]=count($entry['rows']);
    }
    $decoded['counts']=$counts;
    return $decoded;
}
function backup_uploaded_file(): string {
    $file=$_FILES['backup']??null;
    if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||
       !is_uploaded_file((string)($file['tmp_name']??'')))
        json_out(['success'=>false,'message'=>'เลือกไฟล์ Backup ที่ถูกต้อง (หากไฟล์ใหญ่โปรดตรวจขนาดอัปโหลดของ Host)'],422);
    $size=(int)$file['size'];
    if($size<49||$size>BACKUP_MAX_BYTES)json_out(['success'=>false,'message'=>'ไฟล์ Backup ต้องไม่เกิน 30 MB'],422);
    $data=file_get_contents($file['tmp_name']);
    if($data===false)json_out(['success'=>false,'message'=>'อ่านไฟล์ Backup ไม่สำเร็จ'],422);
    return $data;
}
function backup_passphrase(mixed $value): string {
    if(!is_string($value)||strlen($value)<12||strlen($value)>256)
        json_out(['success'=>false,'message'=>'รหัสป้องกันไฟล์ Backup ต้องมีอย่างน้อย 12 ตัวอักษร'],422);
    return $value;
}
if($action==='admin_backup_export'){
    admin_verify_password($u,$input['adminPassword']??null);
    $pass=backup_passphrase($input['backupPassphrase']??null);
    try{
        db()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        db()->beginTransaction();
        $snapshot=backup_snapshot();
        db()->commit();
        $bytes=backup_encrypt($snapshot,$pass);
        admin_security_log($u,'ADMIN_BACKUP_EXPORT',
          'Encrypted backup exported. Row counts: '.json_encode($snapshot['counts']));
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        error_log('Bang1 export failure: '.$e->getMessage());
        json_out(['success'=>false,'message'=>'สร้างไฟล์ Backup ไม่สำเร็จ'],500);
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="bang1-backup-'.date('Ymd-His').'.bnwbackup"');
    header('Cache-Control: no-store, private');
    header('Content-Length: '.strlen($bytes));
    echo $bytes;
    exit;
}
admin_verify_password($u,$_POST['adminPassword']??null);
$pass=backup_passphrase($_POST['backupPassphrase']??null);
$bytes=backup_uploaded_file();
$snapshot=backup_decrypt($bytes,$pass);
$counts=$snapshot['counts'];
if($action==='admin_backup_preview'){
    $token=bin2hex(random_bytes(24));
    $_SESSION['admin_backup_restore_confirmation']=['token'=>$token,'checksum'=>hash('sha256',$bytes),'expires'=>time()+300];
    json_out(['success'=>true,'token'=>$token,'createdAt'=>$snapshot['created_at']??'','counts'=>$counts,'expiresInSeconds'=>300,
      'note'=>'กู้คืนจะแทนที่ฐานข้อมูลจากไฟล์ (คงบัญชี Admin ที่กำลังใช้และประวัติความปลอดภัยถาวร)',
      'driveNote'=>'รูปภาพจริงใน Google Drive ไม่รวมอยู่ในไฟล์ Backup มีแต่ URL อ้างอิง']);
}
$preview=$_SESSION['admin_backup_restore_confirmation']??null;
unset($_SESSION['admin_backup_restore_confirmation']);
if(!is_array($preview)||(int)($preview['expires']??0)<time()||
   !hash_equals((string)($preview['token']??''),(string)($_POST['token']??''))||
   !hash_equals((string)($preview['checksum']??''),hash('sha256',$bytes))||
   ($_POST['confirmation']??'')!=='กู้คืนข้อมูล')
    json_out(['success'=>false,'message'=>'กรุณาตรวจสอบไฟล์ Backup แล้วพิมพ์ยืนยันใหม่'],403);
try{
    db()->beginTransaction();
    $current=db()->prepare('SELECT * FROM coaches WHERE id=? FOR UPDATE');
    $current->execute([(int)$u['coachId']]);
    $own=$current->fetch(PDO::FETCH_ASSOC);
    if(!$own||!in_array($own['role'],['Admin','TopAdmin'],true))
        throw new RuntimeException('Current admin account missing');
    $entries=$snapshot['tables']['coaches']['rows'];
    $snapshot['tables']['coaches']['rows']=array_values(array_filter($entries,
        static fn($row)=>(int)($row['id']??0)!==(int)$own['id'] &&
          strtolower((string)($row['email']??''))!==strtolower((string)$own['email'])));
    $snapshot['tables']['coaches']['rows'][]=$own;
    // InnoDB DELETE + INSERT (never TRUNCATE) allows a rollback if any step fails.
    // If this host already has archived champions, never replace them with
    // older snapshots. This is historical data, not a season reset.
    $hasHistory=(int)db()->query('SELECT COUNT(*) FROM race_history_batches')->fetchColumn()>0;
    $historyTables=['race_history_batches','race_history_results'];
    foreach(array_reverse(BACKUP_TABLES) as $table){
        if($hasHistory&&in_array($table,$historyTables,true))continue;
        db()->exec('DELETE FROM '.chr(96).$table.chr(96));
    }
    foreach(BACKUP_TABLES as $table){
        if($hasHistory&&in_array($table,$historyTables,true))continue;
        $cols=$snapshot['tables'][$table]['columns'];
        $columnsSql=implode(',',array_map(static fn($c)=>chr(96).$c.chr(96),$cols));
        $holders=implode(',',array_fill(0,count($cols),'?'));
        $insert=db()->prepare('INSERT INTO '.chr(96).$table.chr(96).' ('.$columnsSql.') VALUES ('.$holders.')');
        foreach($snapshot['tables'][$table]['rows'] as $row)$insert->execute(array_values($row));
    }
    admin_security_log($u,'ADMIN_BACKUP_RESTORE',
        'Restored snapshot '.(string)($snapshot['created_at']??'unknown').
        ', counts '.json_encode($counts).'; active Admin preserved');
    db()->commit();
    json_out(['success'=>true,'message'=>'กู้คืนข้อมูลสำเร็จ โดยคงบัญชี Admin ที่ใช้งานไว้','counts'=>$counts]);
}catch(Throwable $e){
    if(db()->inTransaction())db()->rollBack();
    error_log('Bang1 restore failure: '.$e->getMessage());
    json_out(['success'=>false,'message'=>'กู้คืนข้อมูลไม่สำเร็จ และย้อนกลับการเปลี่ยนแปลงแล้ว'],500);
}

<?php
declare(strict_types=1);
/**
 * Banglamung 1 athletics - live registration report/check-in.
 * Called by api.php after bootstrap and rows()/single()/must_post() are defined.
 *
 * Public endpoint deliberately exposes school / event / registration numbers,
 * never athlete names or account details. Manager/Admin endpoints are session
 * and CSRF protected; scans are idempotent and event-scoped.
 */
if (!isset($action)) { http_response_code(403); exit; }

function live_gender(string $gender): string {
    $first=trim(explode(',',str_replace('，',',',$gender))[0]);
    return $first;
}
function live_group_key(array $row): string {
    $values=[
        trim((string)($row['event_name']??'')),
        trim((string)($row['category']??'')),
        trim((string)($row['level']??'')),
        live_gender((string)($row['gender']??'')),
        (int)($row['heat']??0),
    ];
    return hash('sha256',json_encode($values,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
}
function live_event_order_key(array $row): string {
    return implode("\x1F",[
        trim((string)($row['event_name']??$row['name']??'')),
        trim((string)($row['category']??'')),
        trim((string)($row['level']??'')),
        live_gender((string)($row['gender']??'')),
    ]);
}
function live_dataset(bool $manager=false): array {
    $order=[];
    foreach(rows('SELECT id,name,category,level,gender,source_order,remark,stage FROM events ORDER BY COALESCE(source_order,999999),id') as $e) {
        $k=live_event_order_key($e);
        if(!isset($order[$k]) || (
            (string)($e['stage']??'')!=='ชิงชนะเลิศ' &&
            (string)($e['remark']??'')!=='ชิงชนะเลิศ' &&
            $order[$k]['isFinal']
        )) {
            $order[$k]=[
                'order'=>(int)($e['source_order']??999999),
                'eventId'=>(int)$e['id'],
                'isFinal'=>(string)($e['stage']??'')==='ชิงชนะเลิศ'||
                    (string)($e['remark']??'')==='ชิงชนะเลิศ',
            ];
        }
    }
    $checked=[];
    foreach(rows('SELECT registration_id,checked_at,checked_by FROM race_checkins') as $r)
        $checked[(int)$r['registration_id']]=$r;
    $fixtures=[];
    $teamsByKey=[];
    foreach(rows('SELECT id,event_id,event_name,category,level,school_name,runner_names,gender,heat,lane,registration_key FROM registrations ORDER BY id') as $r){
        $key=live_group_key($r);
        if(!isset($fixtures[$key])) {
            $ord=$order[live_event_order_key($r)]??null;
            $fixtures[$key]=[
                'key'=>$key,
                'eventId'=>(int)($ord['eventId']??$r['event_id']??0),
                'eventName'=>(string)$r['event_name'],
                'category'=>(string)$r['category'],
                'level'=>(string)$r['level'],
                'gender'=>live_gender((string)$r['gender']),
                'heat'=>(int)$r['heat'],
                'order'=>(int)($ord['order']??999999),
                'total'=>0,
                'checked'=>0
            ];
            $teamsByKey[$key]=[];
        }
        $id=(int)$r['id'];$check=$checked[$id]??null;
        $fixtures[$key]['total']++;
        if($check)$fixtures[$key]['checked']++;
        $team=[
            'regId'=>$id,
            'school'=>(string)$r['school_name'],
            'lane'=>$r['lane']===null?null:(int)$r['lane'],
            'heat'=>(int)$r['heat'],
            'checkedAt'=>$check?(string)$check['checked_at']:null,
        ];
        if($manager)$team['runners']=(string)$r['runner_names'];
        $teamsByKey[$key][]=$team;
    }
    $fixtures=array_values($fixtures);
    usort($fixtures,static function(array $a,array $b):int{
        return ($a['order']<=>$b['order'])
            ?:strcmp($a['eventName'],$b['eventName'])
            ?:strcmp($a['category'],$b['category'])
            ?:strcmp($a['level'],$b['level'])
            ?:strcmp($a['gender'],$b['gender'])
            ?:($a['heat']<=>$b['heat']);
    });
    $state=single('SELECT fixture_key,updated_at,updated_by FROM race_live_state WHERE id=1');
    $activeKey=(string)($state['fixture_key']??'');
    $active=null;
    $currentIndex=-1;
    foreach($fixtures as $index=>$f)if($f['key']===$activeKey){
        $active=$f;$currentIndex=$index;break;
    }
    $teams=$active?$teamsByKey[$activeKey]:[];
    usort($teams,static function(array $a,array $b):int {
        // A stable order preserves heat/lane while refreshes update statuses.
        return ($a['lane']??9999)<=>($b['lane']??9999)
            ?:strcmp($a['school'],$b['school'])
            ?:($a['regId']<=>$b['regId']);
    });
    $recent=array_values(array_filter($teams,static fn($t)=>$t['checkedAt']!==null));
    usort($recent,static fn($a,$b)=>strcmp($b['checkedAt'],$a['checkedAt'])?:($b['regId']<=>$a['regId']));
    return [
        'success'=>true,
        'serverTime'=>date('c'),
        'active'=>$active,
        'activeUpdatedAt'=>$state['updated_at']??null,
        'fixtures'=>$fixtures,
        'teams'=>$teams,
        'recent'=>array_slice($recent,0,12),
        'next'=>$currentIndex>=0?array_slice($fixtures,$currentIndex+1,5):array_slice($fixtures,0,5),
        'isManager'=>$manager
    ];
}

if($action==='live_state'){
    if(($_SERVER['REQUEST_METHOD']??'')!=='GET')json_out(['success'=>false,'message'=>'GET required'],405);
    json_out(live_dataset(false));
}
if(!in_array($action,['live_manager_data','live_select','live_scan','live_undo'],true))return;
if(!isset($u)||!is_array($u))json_out(['success'=>false,'message'=>'กรุณาเข้าสู่ระบบ'],401);
require_reviewer($u);

if($action==='live_manager_data'){
    if(($_SERVER['REQUEST_METHOD']??'')!=='GET')json_out(['success'=>false,'message'=>'GET required'],405);
    json_out(live_dataset(true));
}
must_post();

if($action==='live_select'){
    $key=trim((string)($input['fixtureKey']??''));
    if($key!==''&&!preg_match('/^[a-f0-9]{64}$/D',$key))
        json_out(['success'=>false,'message'=>'รูปแบบรายการไม่ถูกต้อง'],422);
    if($key!==''){
        $found=false;
        foreach(live_dataset(false)['fixtures'] as $fixture){
            if(hash_equals($fixture['key'],$key)){$found=true;break;}
        }
        if(!$found)json_out(['success'=>false,'message'=>'ไม่พบรายการที่เลือกในตารางแข่งขัน'],404);
    }
    db()->prepare('INSERT INTO race_live_state(id,fixture_key,updated_by,updated_at) VALUES(1,?,?,NOW()) ON DUPLICATE KEY UPDATE fixture_key=VALUES(fixture_key),updated_by=VALUES(updated_by),updated_at=NOW()')
       ->execute([$key===''?null:$key,(int)$u['coachId']]);
    log_action('LIVE_FIXTURE_SELECT',$u,'AthleticsFixture',$key===''?'none':substr($key,0,32),$key===''?'End active event':'Manager activated event');
    json_out(['success'=>true,'message'=>$key===''?'ปิดการแสดงรายการ Active แล้ว':'เปลี่ยนรายการ Active บนจอ Live สำเร็จ']);
}

if($action==='live_scan'){
    $raw=trim((string)($input['code']??''));
    if($raw===''||strlen($raw)>1000)json_out(['success'=>false,'message'=>'QR Code หรือรหัสใบสมัครไม่ถูกต้อง'],422);
    $registration=null;
    // Newly printed QR / hand-entered registration number.
    if(preg_match('/^(?:BNWREG:)?([0-9]{1,10})$/iD',$raw,$m)){
        $registration=single('SELECT * FROM registrations WHERE id=? LIMIT 1',[(int)$m[1]]);
    }else{
        // New-style URL QR, accepting only this school's actual host and route.
        if(preg_match('~^https?://~i',$raw)){
            $url=parse_url($raw);
            $host=strtolower((string)($url['host']??''));
            $path=rtrim((string)($url['path']??''),'/');
            if(!in_array($host,['bannawang.ac.th','www.bannawang.ac.th'],true) ||
                $path!=='/bang1/run/live')
                json_out(['success'=>false,'message'=>'QR Code ไม่ใช่ใบสมัครกรีฑาของระบบนี้'],422);
            parse_str((string)($url['query']??''),$params);
            $reg=(string)($params['reg']??'');
            if(!preg_match('/^[0-9]{1,10}$/D',$reg))
                json_out(['success'=>false,'message'=>'QR Code ไม่มีรหัสใบสมัครที่รองรับ'],422);
            $registration=single('SELECT * FROM registrations WHERE id=? LIMIT 1',[(int)$reg]);
        }else{
            // Existing physical application QR: event_id|category|level|school.
            $matches=rows('SELECT * FROM registrations WHERE registration_key=? LIMIT 2',[$raw]);
            if(count($matches)>1)json_out(['success'=>false,'message'=>'QR นี้ตรงกับมากกว่าหนึ่งใบสมัคร กรุณาระบุรหัสการลงทะเบียน'],409);
            $registration=$matches[0]??null;
        }
    }
    if(!$registration)json_out(['success'=>false,'message'=>'ไม่พบใบสมัครที่ตรงกับ QR Code กรุณาตรวจสอบรหัส'],404);
    $key=live_group_key($registration);
    $state=single('SELECT fixture_key FROM race_live_state WHERE id=1');
    $active=(string)($state['fixture_key']??'');
    if($active==='')
        json_out(['success'=>false,'message'=>'กรุณาเลือกรายการแข่งขัน Active ก่อนสแกนรายงานตัว'],409);
    if(!hash_equals($active,$key))
        json_out(['success'=>false,'message'=>'ใบสมัครนี้เป็นคนละรายการหรือฮีทกับรายการ Active กรุณาเลือกรายการแข่งขันให้ถูกต้อง'],409);

    $id=(int)$registration['id'];
    $insert=db()->prepare('INSERT IGNORE INTO race_checkins(registration_id,checked_by,checked_at) VALUES(?,?,NOW())');
    $insert->execute([$id,(int)$u['coachId']]);
    $wasNew=$insert->rowCount()>0;
    $check=single('SELECT checked_at FROM race_checkins WHERE registration_id=?',[$id]);
    if($wasNew)log_action('LIVE_CHECKIN',$u,'Registration',(string)$id,
       'Check-in for '.$registration['event_name'].'; school '.$registration['school_name']);
    $result=[
        'success'=>true,
        'regId'=>$id,
        'school'=>(string)$registration['school_name'],
        'eventName'=>(string)$registration['event_name'],
        'checkedAt'=>(string)($check['checked_at']??''),
        'isNew'=>$wasNew,
        'message'=>$wasNew?'เช็กอินรายงานตัวสำเร็จ':'รายการนี้รายงานตัวแล้วก่อนหน้านี้'
    ];
    // idempotent acknowledgement; actual new/duplicate detection is handled by the
    // registration uniqueness constraint, never by client-supplied flags.
    json_out($result);
}

if($action==='live_undo'){
    $id=(int)($input['regId']??0);
    if($id<1)json_out(['success'=>false,'message'=>'รหัสใบสมัครไม่ถูกต้อง'],422);
    $reg=single('SELECT * FROM registrations WHERE id=?',[$id]);
    if(!$reg)json_out(['success'=>false,'message'=>'ไม่พบใบสมัคร'],404);
    $active=(string)(single('SELECT fixture_key FROM race_live_state WHERE id=1')['fixture_key']??'');
    if($active===''||!hash_equals($active,live_group_key($reg)))
        json_out(['success'=>false,'message'=>'สามารถยกเลิกเช็กอินได้เฉพาะรายการ Active'],403);
    $delete=db()->prepare('DELETE FROM race_checkins WHERE registration_id=?');
    $delete->execute([$id]);
    if(!$delete->rowCount())json_out(['success'=>false,'message'=>'ยังไม่มีรายการรายงานตัวให้ยกเลิก'],409);
    log_action('LIVE_CHECKIN_UNDO',$u,'Registration',(string)$id,'Manager cancelled mistaken check-in');
    json_out(['success'=>true,'message'=>'ยกเลิกการรายงานตัวแล้ว']);
}

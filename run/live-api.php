<?php
declare(strict_types=1);
/**
 * Banglamung 1 athletics - live registration report/check-in.
 * Called by api.php after bootstrap and rows()/single()/must_post() are defined.
 *
 * Public dashboard presents checked-in athletes' names and registered photos.
 * Manager/Admin controls are session and CSRF protected; check-in is per athlete.
 * Private ID numbers and proof documents are never returned.
 */
if (!isset($action)) { http_response_code(403); exit; }

function live_gender(string $gender): string {
    $first=trim(explode(',',str_replace('，',',',$gender))[0]);
    return $first;
}
/**
 * Match the official "รายการจัดแข่งขัน" roster. event_matching_regs()
 * compares event name, category and level, never the raw gender values
 * in registrations: these can be blank, mixed, or comma-delimited for relays.
 * Heat distinguishes the reporting stations within that same competition.
 */
function live_group_key(array $row): string {
    $values=[
        trim((string)($row['event_name']??'')),
        trim((string)($row['category']??'')),
        trim((string)($row['level']??'')),
        (int)($row['heat']??0),
    ];
    return hash('sha256',json_encode($values,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
}
/** Previous deployed key: used only to keep an already-active event selected. */
function live_legacy_group_key(array $row): string {
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
    ]);
}

/** Ten fixed presentation columns, in the reference screen's order. */
function live_school_order():array {
    return [
        'วัดสุทธารีย์บุญญาราม',
        'บ้านตะเคียนเตี้ย',
        'บ้านสันติคาม',
        'บ้านบางละมุง',
        'วัดหนองเกตุใหญ่',
        'อนุบาลบางละมุง',
        'วัดเวฬุวนาราม',
        'บ้านโรงหีบ',
        'บ้านนาวัง',
        'บ้านทุ่งกราด',
    ];
}
function live_school_compact(string $value):string {
    return preg_replace('/[\s\p{P}\p{Z}]+/u','',
        preg_replace('/^โรงเรียน/u','',trim($value)))??trim($value);
}
function live_school_columns(array $schoolRows,array $registrations):array {
    $known=[];
    foreach($schoolRows as $school){
        $name=trim((string)($school['name']??''));
        if($name!=='')$known[live_school_compact($name)]=$name;
    }
    // The names from enrolled registrations are authoritative if a school
    // was renamed or its nickname differs from the schools table.
    foreach($registrations as $reg){
        $name=trim((string)($reg['school_name']??''));
        if($name!=='')$known[live_school_compact($name)]=$name;
    }
    $used=[];$columns=[];
    foreach(live_school_order() as $short){
        $needle=live_school_compact($short);
        $found=null;
        foreach($known as $normalized=>$full){
            if(isset($used[$normalized]))continue;
            if($needle===$normalized || str_contains($normalized,$needle) ||
               str_contains($needle,$normalized)){
                $found=['name'=>$full,'key'=>$normalized];break;
            }
        }
        if($found===null){$found=['name'=>'โรงเรียน'.$short,'key'=>$needle];}
        $used[$found['key']]=true;
        $columns[]=$found;
    }
    return $columns;
}
/** Safe, display-only public photo URLs. Never include ID-card/proof attachments. */
function live_photo_url(string $value):?string {
    $value=trim($value);
    $url=$value===''?null:safe_url($value);
    if(!$url)return null;
    $parts=parse_url($url);
    if(strtolower((string)($parts['host']??''))==='drive.google.com'){
        $query=[];parse_str((string)($parts['query']??''),$query);
        $id=(string)($query['id']??'');
        if($id==='' && preg_match('~/(?:file/d|d)/([A-Za-z0-9_-]{15,})(?:/|$)~',
                                  (string)($parts['path']??''),$match))$id=$match[1];
        if(preg_match('/^[A-Za-z0-9_-]{15,}$/D',$id))
            return 'https://drive.google.com/thumbnail?id='.rawurlencode($id).'&sz=w800';
    }
    return $url;
}
function live_athlete_rows(array $reg,array $runnerMap,array $checks):array {
    $ids=array_map('trim',explode(',',(string)($reg['runner_ids']??'')));
    $names=array_map('trim',explode(',',(string)($reg['runner_names']??'')));
    $photos=array_map('trim',explode(',',(string)($reg['photos']??'')));
    $length=max(count($ids),count($names));
    $athletes=[];$used=[];
    for($i=0;$i<$length;$i++){
        $rawId=$ids[$i]??'';
        $runnerId=ctype_digit($rawId)?(int)$rawId:0;
        $runner=($runnerId>0)?($runnerMap[$runnerId]??null):null;
        $fallbackName=$names[$i]??'';
        $name=$runner?trim((string)$runner['first_name'].' '.(string)$runner['last_name']):$fallbackName;
        if($name===''&&$runnerId<=0)continue;
        if($name==='')$name='นักกรีฑาลำดับที่ '.($i+1);
        $athleteKey=$runnerId>0?'runner:'.$runnerId:'slot:'.($i+1);
        if(isset($used[$athleteKey]))continue;
        $used[$athleteKey]=true;
        $photo=live_photo_url((string)($runner['photo_url']??''));
        if(!$photo)$photo=live_photo_url((string)($photos[$i]??''));
        $when=$checks[(int)$reg['id']][$athleteKey]??null;
        $athletes[]=[
            'athleteKey'=>$athleteKey,'name'=>$name,'photo'=>$photo,
            'checkedAt'=>$when,
            'runnerId'=>$runnerId>0?$runnerId:null
        ];
    }
    return $athletes;
}
function live_dataset(bool $manager=false):array {
    $order=[];
    foreach(rows('SELECT id,name,category,level,gender,source_order,remark,stage FROM events ORDER BY COALESCE(source_order,999999),id') as $e){
        $k=live_event_order_key($e);
        $final=(string)($e['stage']??'')==='ชิงชนะเลิศ' ||
            (string)($e['remark']??'')==='ชิงชนะเลิศ';
        if(!isset($order[$k])||(!$final&&$order[$k]['isFinal'])){
            $order[$k]=[
                'order'=>(int)($e['source_order']??999999),
                'eventId'=>(int)$e['id'],
                'gender'=>(string)($e['gender']??''),
                'isFinal'=>$final
            ];
        }
    }
    $registrations=rows('SELECT id,event_id,event_name,category,level,school_name,runner_ids,runner_names,photos,gender,heat,lane FROM registrations ORDER BY id');
    $runnerMap=[];
    foreach(rows('SELECT id,school_name,first_name,last_name,photo_url FROM runners') as $r)
        $runnerMap[(int)$r['id']]=$r;
    $checks=[];
    foreach(rows('SELECT registration_id,athlete_key,checked_at FROM race_athlete_checkins') as $c){
        $checks[(int)$c['registration_id']][(string)$c['athlete_key']]=(string)$c['checked_at'];
    }
    $schoolColumns=live_school_columns(rows('SELECT name FROM schools ORDER BY id'),$registrations);
    $fixtures=[];$teamsByKey=[];$legacyToCurrent=[];
    foreach($registrations as $r){
        $key=live_group_key($r);
        $legacyToCurrent[live_legacy_group_key($r)]=$key;
        if(!isset($fixtures[$key])){
            $ord=$order[live_event_order_key($r)]??null;
            $fixtures[$key]=[
                'key'=>$key,
                'eventId'=>(int)($ord['eventId']??$r['event_id']??0),
                'eventName'=>(string)$r['event_name'],
                'category'=>(string)$r['category'],
                'level'=>(string)$r['level'],
                'gender'=>(string)($ord['gender']??live_gender((string)$r['gender'])),
                'heat'=>(int)$r['heat'],
                'order'=>(int)($ord['order']??999999),
                'total'=>0,'checked'=>0,'teams'=>0,'teamsReady'=>0
            ];
            $teamsByKey[$key]=[];
        }
        $athletes=live_athlete_rows($r,$runnerMap,$checks);
        $present=array_values(array_filter($athletes,static fn($a)=>$a['checkedAt']!==null));
        $fixtures[$key]['total']+=count($athletes);
        $fixtures[$key]['checked']+=count($present);
        $fixtures[$key]['teams']++;
        if(count($athletes)>0&&count($present)===count($athletes))$fixtures[$key]['teamsReady']++;
        $team=[
            'regId'=>(int)$r['id'],
            'school'=>(string)$r['school_name'],
            'lane'=>$r['lane']===null?null:(int)$r['lane'],
            'heat'=>(int)$r['heat'],
            'totalAthletes'=>count($athletes),
            'checkedAthletes'=>count($present)
        ];
        if($manager)$team['athletes']=$athletes;
        else $team['athletes']=$present;
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
    $storedKey=(string)($state['fixture_key']??'');
    $activeKey=$legacyToCurrent[$storedKey]??$storedKey;
    $active=null;$currentIndex=-1;
    foreach($fixtures as $index=>$f)if($f['key']===$activeKey){
        $active=$f;$currentIndex=$index;break;
    }
    $teams=$active?$teamsByKey[$activeKey]:[];
    usort($teams,static fn($a,$b)=>
        ($a['lane']??9999)<=>($b['lane']??9999)
        ?:strcmp($a['school'],$b['school'])
        ?:($a['regId']<=>$b['regId']));
    $columns=[];
    foreach($schoolColumns as $school){
        $columns[]=['name'=>$school['name'],'key'=>$school['key'],'athletes'=>[]];
    }
    $recent=[];
    foreach($teams as $team){
        $normalized=live_school_compact($team['school']);
        $columnIndex=null;
        foreach($columns as $i=>$col){
            if($col['key']===$normalized||
               str_contains($col['key'],$normalized)||
               str_contains($normalized,$col['key'])){$columnIndex=$i;break;}
        }
        if($columnIndex===null)continue;
        foreach($team['athletes'] as $athlete){
            if($athlete['checkedAt']===null)continue;
            $publicAthlete=[
                'name'=>$athlete['name'],'photo'=>$athlete['photo'],
                'regId'=>$team['regId'],'school'=>$team['school'],
                'checkedAt'=>$athlete['checkedAt']
            ];
            $columns[$columnIndex]['athletes'][]=$publicAthlete;
            $recent[]=$publicAthlete;
        }
    }
    usort($recent,static fn($a,$b)=>strcmp($b['checkedAt'],$a['checkedAt']));
    return [
        'success'=>true,
        'serverTime'=>date('c'),
        'active'=>$active,
        'activeUpdatedAt'=>$state['updated_at']??null,
        'fixtures'=>$fixtures,
        'schools'=>$columns,
        'teams'=>$teams,
        'recent'=>array_slice($recent,0,10),
        'next'=>$currentIndex>=0?array_slice($fixtures,$currentIndex+1,3):array_slice($fixtures,0,3),
        'isManager'=>$manager
    ];
}

if($action==='live_state'){
    if(($_SERVER['REQUEST_METHOD']??'')!=='GET')json_out(['success'=>false,'message'=>'GET required'],405);
    json_out(live_dataset(false));
}
if(!in_array($action,['live_manager_data','live_select','live_runner_set'],true))return;
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

if($action==='live_runner_set'){
    $regId=(int)($input['regId']??0);
    $athleteKey=(string)($input['athleteKey']??'');
    $checked=$input['checked']??null;
    if($regId<=0||!preg_match('/^(runner:[1-9][0-9]{0,16}|slot:[1-9][0-9]{0,3})$/D',$athleteKey)
        ||!is_bool($checked)){
        json_out(['success'=>false,'message'=>'ข้อมูลนักกรีฑาหรือสถานะไม่ถูกต้อง'],422);
    }
    $reg=single('SELECT * FROM registrations WHERE id=? LIMIT 1',[$regId]);
    if(!$reg)json_out(['success'=>false,'message'=>'ไม่พบใบสมัคร'],404);
    $active=(string)(live_dataset(false)['active']['key']??'');
    if($active===''||!hash_equals($active,live_group_key($reg))){
        json_out(['success'=>false,'message'=>'ต้องเลือกรายการ Active ที่ตรงกับนักกรีฑาก่อน'],409);
    }
    // The submitted key is merely a selector; only athletes actually present
    // on this registered roster may be checked in. Never accept name/school
    // or photo information from a browser request.
    $runnerMap=[];
    $runnerIds=array_values(array_filter(array_map('intval',explode(',',(string)$reg['runner_ids']))));
    if($runnerIds){
        $marks=implode(',',array_fill(0,count($runnerIds),'?'));
        foreach(rows('SELECT id,first_name,last_name,photo_url FROM runners WHERE id IN ('.$marks.')',$runnerIds) as $runner)
            $runnerMap[(int)$runner['id']]=$runner;
    }
    $candidates=live_athlete_rows($reg,$runnerMap,[]);
    $valid=false;$athleteName='';
    foreach($candidates as $athlete){
        if(hash_equals($athlete['athleteKey'],$athleteKey)){
            $valid=true;$athleteName=$athlete['name'];break;
        }
    }
    if(!$valid)json_out(['success'=>false,'message'=>'นักกรีฑาคนนี้ไม่อยู่ในใบสมัครรายการ Active'],422);
    if($checked){
        $stmt=db()->prepare('INSERT IGNORE INTO race_athlete_checkins (registration_id,athlete_key,checked_by,checked_at) VALUES(?,?,?,NOW())');
        $stmt->execute([$regId,$athleteKey,(int)$u['coachId']]);
        $changed=$stmt->rowCount()>0;
    }else{
        $stmt=db()->prepare('DELETE FROM race_athlete_checkins WHERE registration_id=? AND athlete_key=?');
        $stmt->execute([$regId,$athleteKey]);
        $changed=$stmt->rowCount()>0;
    }
    if($changed){
        log_action($checked?'LIVE_ATHLETE_CHECKIN':'LIVE_ATHLETE_UNCHECK',$u,
            'Registration',(string)$regId,'Athlete '.$athleteKey.' '.($checked?'in':'out'));
    }
    json_out([
        'success'=>true,
        'changed'=>$changed,
        'checked'=>$checked,
        'message'=>$checked?($changed?'ยืนยันรายงานตัว '.$athleteName.' แล้ว':'นักกรีฑาคนนี้รายงานตัวแล้ว'):
            ($changed?'ยกเลิกการรายงานตัว '.$athleteName.' แล้ว':'นักกรีฑาคนนี้ยังไม่ได้รายงานตัว')
    ]);
}

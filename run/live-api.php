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
// The public and manager Live boards must use the same officially generated
// competition schedule as the event list and Staff result entry.
require_once __DIR__.'/event-management.php';
require_once __DIR__.'/champion-history.php';
require_once __DIR__.'/school-branding.php';

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

/**
 * Return the competition stage assigned by event_schedule_data() rather than
 * deducing the round from the number of schools in ONE heat. Event #11, for
 * example, can have five schools in heat 1 but ten across the full event.
 *
 * The schedule may reuse an EventID for generated heats: prefer an exact
 * EventID+heat match, then the competition identity+heat. In a fallback
 * collision the preliminary fixture takes precedence over a future final.
 */
function live_official_round_index(array $schedule):array {
    $byId=[];$byGroup=[];
    foreach($schedule as $event){
        $stage=trim((string)($event['Stage']??''));
        if(!in_array($stage,['คัดเลือก','ชิงชนะเลิศ'],true))continue;
        $round=$stage==='ชิงชนะเลิศ'?'รอบชิง':'รอบคัดเลือก';
        $heat=max(1,(int)($event['Heat']??1));
        $eventId=(int)($event['EventID']??0);
        if($eventId>0)$byId[$eventId.'|'.$heat]=$round;
        $identity=live_event_order_key([
            'event_name'=>(string)($event['EventName']??''),
            'category'=>(string)($event['Category']??''),
            'level'=>(string)($event['Level']??''),
        ]).'|'.$heat;
        if(!isset($byGroup[$identity])||$round==='รอบคัดเลือก')
            $byGroup[$identity]=$round;
    }
    return ['byId'=>$byId,'byGroup'=>$byGroup];
}
function live_fixture_round(int $eventId,array $registration,array $index):string {
    $heat=max(1,(int)($registration['heat']??1));
    $idKey=$eventId.'|'.$heat;
    if($eventId>0&&isset($index['byId'][$idKey]))
        return $index['byId'][$idKey];
    $groupKey=live_event_order_key($registration).'|'.$heat;
    return $index['byGroup'][$groupKey]??'รอยืนยันรอบ';
}

/**
 * The Live board selects a competition/round rather than an individual heat.
 * Heat membership stays on each registration and is still independently
 * audited in race_athlete_checkins by registration ID + athlete key.
 */
function live_competition_key(array $row,string $round):string {
    return hash('sha256',json_encode([
        live_event_order_key($row),$round
    ],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
}
/** Official event number/ordering for each heat from the published schedule. */
function live_schedule_heat_slots(array $schedule):array {
    $slots=[];
    foreach($schedule as $slot){
        $id=(int)($slot['EventID']??0);
        $heat=max(1,(int)($slot['Heat']??1));
        $stage=trim((string)($slot['Stage']??''));
        $round=match($stage){
            'คัดเลือก'=>'รอบคัดเลือก',
            'ชิงชนะเลิศ'=>'รอบชิง',
            default=>'รอยืนยันรอบ'
        };
        $identity=live_event_order_key([
            'event_name'=>(string)($slot['EventName']??''),
            'category'=>(string)($slot['Category']??''),
            'level'=>(string)($slot['Level']??'')
        ]);
        $key=$identity.'|'.$round.'|'.$heat;
        if(!isset($slots[$key])){
            $slots[$key]=[
                'eventId'=>$id,
                'order'=>(int)($slot['SourceOrder']??$id),
                'heat'=>$heat
            ];
        }
    }
    return $slots;
}
function live_event_number_label(array $ids):string {
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids),
        static fn($v)=>$v>0)));
    sort($ids,SORT_NUMERIC);
    if(!$ids)return '—';
    if(count($ids)===1)return (string)$ids[0];
    $sequence=range($ids[0],$ids[count($ids)-1]);
    if($sequence===$ids)return $ids[0].'–'.$ids[count($ids)-1];
    return implode(', ',$ids);
}
/** Add a unique school heat without changing registration order. */
function live_add_heat(array &$heats,int $heat):void {
    if($heat>0&&!in_array($heat,$heats,true)){
        $heats[]=$heat;
        sort($heats,SORT_NUMERIC);
    }
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
/** Admin-defined school order, retaining the original ten slots until saved. */
function live_configured_school_order(array $schoolRows):array {
    $defaults=live_school_order();$ranked=[];
    foreach($schoolRows as $school){
        $position=(int)($school['live_position']??0);
        $name=trim((string)($school['name']??''));
        if($position>0&&$name!=='')
            $ranked[]=['name'=>$name,'position'=>$position,'id'=>(int)($school['id']??0)];
    }
    if(!$ranked)return $defaults;
    usort($ranked,static fn($a,$b)=>
        ($a['position']<=>$b['position'])?:($a['id']<=>$b['id']));
    $names=array_column($ranked,'name');
    foreach($defaults as $short){
        $needle=live_school_compact($short);$exists=false;
        foreach($names as $name)if(live_school_compact($name)===$needle){$exists=true;break;}
        if(!$exists)$names[]=$short;
    }
    foreach($schoolRows as $school){
        $name=trim((string)($school['name']??''));if($name==='')continue;
        $needle=live_school_compact($name);$exists=false;
        foreach($names as $other)if(live_school_compact($other)===$needle){$exists=true;break;}
        if(!$exists)$names[]=$name;
    }
    return $names;
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
    // The current registration's school name wins over an obsolete nickname.
    foreach($registrations as $reg){
        $name=trim((string)($reg['school_name']??''));
        if($name!=='')$known[live_school_compact($name)]=$name;
    }
    $used=[];$columns=[];
    foreach(live_configured_school_order($schoolRows) as $short){
        $needle=live_school_compact($short);
        $found=null;
        foreach($known as $normalized=>$full){
            if($normalized===''||isset($used[$normalized]))continue;
            if($needle===$normalized||str_contains($normalized,$needle)||
               str_contains($needle,$normalized)){
                $found=['name'=>$full,'key'=>$normalized,'matched'=>true];break;
            }
        }
        if($found===null)$found=['name'=>'โรงเรียน'.$short,'key'=>$needle,'matched'=>false];
        if($found['matched'])$used[$found['key']]=true;
        $columns[]=$found;
    }
    // Guard against school spelling/name changes: if an actual enrolled
    // school is not one of the ten expected aliases, give it an unmatched
    // column rather than silently dropping its checked-in athletes.
    $unmatched=[];
    foreach($registrations as $reg){
        $name=trim((string)($reg['school_name']??''));
        $normalized=live_school_compact($name);
        if($normalized!==''&&!isset($used[$normalized]))$unmatched[$normalized]=$name;
    }
    foreach($known as $normalized=>$name){
        if($normalized!==''&&!isset($used[$normalized])&&!isset($unmatched[$normalized]))
            $unmatched[$normalized]=$name;
    }
    foreach($unmatched as $normalized=>$name){
        $index=null;
        foreach($columns as $i=>$col)if(!$col['matched']){$index=$i;break;}
        if($index===null)break; // Never expand beyond ten columns.
        $columns[$index]=['name'=>$name,'key'=>$normalized,'matched'=>true];
        $used[$normalized]=true;
    }
    $logoLookup=school_logo_lookup($schoolRows);
    return array_map(static fn($column)=>[
        'name'=>$column['name'],'key'=>$column['key'],
        'logo'=>school_logo_for($logoLookup,$column['name'])],$columns);
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
/**
 * Attendance capacity is per registered team, not every runner saved on its
 * candidate list. A school may register two alternatives in a sprint but
 * send only ONE athlete to the call point. Relays check in FOUR athletes.
 * Never delete the alternative registrations or any historical check-ins.
 */
function live_attendance_capacity(array $registration):int {
    $category=trim((string)($registration['category']??''));
    $event=trim((string)($registration['event_name']??$registration['name']??''));
    return str_contains($category,'ผลัด')||str_contains($event,'ผลัด')?4:1;
}
/**
 * For old rows with more check-ins than the new limit, choose the earliest
 * checked athletes for the public screen while retaining all audit records.
 * Other checked athletes stay visible to the manager so they can uncheck
 * them intentionally. This function never mutates the database.
 */
function live_counted_attendees(array $athletes,int $capacity):array {
    $checked=array_values(array_filter($athletes,
        static fn($athlete)=>($athlete['checkedAt']??null)!==null));
    usort($checked,static fn($a,$b)=>
        strcmp((string)$a['checkedAt'],(string)$b['checkedAt']));
    return array_slice($checked,0,max(0,$capacity));
}

/**
 * Sport showcase previews real registered athletes without asserting that
 * unconfirmed candidates have reported for their events. Prefer anyone
 * already checked in; fill vacant slots with registered candidates in their
 * original roster order. The school may only show 1 solo / 4 relay runners.
 */
function live_sport_candidates(array $athletes,int $capacity):array {
    $checked=live_counted_attendees($athletes,$capacity);
    $seen=[];
    foreach($checked as $athlete)$seen[$athlete['athleteKey']]=true;
    $preview=$checked;
    foreach($athletes as $athlete){
        if(count($preview)>=$capacity)break;
        if(isset($seen[$athlete['athleteKey']]))continue;
        $seen[$athlete['athleteKey']]=true;
        $preview[]=$athlete;
    }
    return array_slice($preview,0,$capacity);
}
/** Global public display mode, separate from the Manager's Active fixture. */
function live_display_state():array {
    $row=single('SELECT display_mode,updated_at FROM race_live_display WHERE id=1');
    $mode=($row['display_mode']??'checkin')==='sport'?'sport':'checkin';
    $started=$row&&$row['updated_at']?strtotime((string)$row['updated_at']):false;
    return [
        'mode'=>$mode,
        'startedAtMs'=>$started!==false?((int)$started*1000):0,
        'slideSeconds'=>15
    ];
}

/**
 * Prepare a public ticker item only from an actually saved event result.
 * The official Staff ranking is stored per event, round and heat in event_results.
 * Never infer winners from check-ins or announce unfinished registrations.
 */
function live_recent_result_item(array $row):?array {
    $eventName=trim((string)($row['event_name']??''));
    $ranking=json_decode((string)($row['ranking_json']??''),true);
    if($eventName===''||!is_array($ranking))return null;
    $places=[];
    for($rank=1;$rank<=3;$rank++){
        $school=trim((string)($ranking[(string)$rank]??$ranking[$rank]??''));
        if($school!==''){
            $places[]=['place'=>$rank,'school'=>$school];
        }
    }
    if(!$places)return null;
    $round=(string)($row['round_type']??'');
    if(!in_array($round,['คัดเลือก','ชิงชนะเลิศ'],true))$round='คัดเลือก';
    return [
        'resultId'=>(int)($row['id']??0),
        'eventId'=>(int)($row['event_id']??0),
        'eventName'=>$eventName,
        'round'=>$round,
        'heat'=>(int)($row['heat_no']??1),
        'places'=>$places,
        'updatedAt'=>(string)($row['updated_at']??'')
    ];
}
/**
 * Look up today's REAL registered athletes for one ranked school. A Staff
 * result ranks SCHOOLS, not individual runners: never claim the displayed
 * pupils personally won without that being recorded in the results.
 *
 * Preliminary results must match the exact heat; final results use the
 * competition's registrations across the preliminary heats. Otherwise the
 * final screen could show a student from a different race.
 */
function live_result_place_athletes(
    array $result,array $place,array $registrations,array $runnerMap,array $checks
):array {
    $eventKey=live_event_order_key([
        'event_name'=>(string)($result['eventName']??''),
        'category'=>(string)($result['category']??''),
        'level'=>(string)($result['level']??'')
    ]);
    $schoolKey=live_school_compact((string)($place['school']??''));
    if($schoolKey==='')return [];
    $final=($result['round']??'')==='ชิงชนะเลิศ';
    $heat=max(1,(int)($result['heat']??1));
    $capacity=live_attendance_capacity([
        'event_name'=>$result['eventName']??'',
        'category'=>$result['category']??''
    ]);
    $candidates=[];
    $seen=[];
    foreach($registrations as $registration){
        if(live_event_order_key($registration)!==$eventKey
            ||live_school_compact((string)($registration['school_name']??''))!==$schoolKey)
            continue;
        if(!$final&&max(1,(int)($registration['heat']??1))!==$heat)continue;
        foreach(live_athlete_rows($registration,$runnerMap,$checks) as $athlete){
            $key=(string)$athlete['athleteKey'];
            if(isset($seen[$key]))continue;
            $seen[$key]=true;
            $candidates[]=$athlete;
        }
    }
    // Prefer recorded check-ins, then fill from the real registered roster.
    // Return only public display fields, not runner IDs / check-in keys.
    return array_map(static fn($athlete)=>[
        'name'=>(string)$athlete['name'],
        'photo'=>$athlete['photo']
    ],live_sport_candidates($candidates,$capacity));
}
function live_latest_results():array {
    $results=[];
    // Latest first. Only official Staff-saved results, no unsaved rankings.
    $saved=rows(
        'SELECT r.id,r.event_id,r.round_type,r.heat_no,r.ranking_json,r.updated_at,
                e.name AS event_name,e.category AS event_category,e.level AS event_level
         FROM event_results r INNER JOIN events e ON e.id=r.event_id
         ORDER BY r.updated_at DESC,r.id DESC LIMIT 32'
    );
    foreach($saved as $record){
        $item=live_recent_result_item($record);
        if($item!==null){
            $item['category']=(string)($record['event_category']??'');
            $item['level']=(string)($record['event_level']??'');
            $results[]=$item;
        }
        if(count($results)>=8)break;
    }
    if(!$results)return [];
    // Fetch the candidate pool once, not one SQL query per winner/card.
    $names=array_values(array_unique(array_column($results,'eventName')));
    $placeholders=implode(',',array_fill(0,count($names),'?'));
    $registrations=rows(
        'SELECT id,event_id,event_name,category,level,school_name,runner_ids,
                runner_names,photos,heat
         FROM registrations WHERE event_name IN ('.$placeholders.') ORDER BY id',
        $names
    );
    $runnerIds=[];$regIds=[];
    foreach($registrations as $registration){
        $regIds[]=(int)$registration['id'];
        foreach(explode(',',(string)($registration['runner_ids']??'')) as $raw){
            $raw=trim($raw);
            if(ctype_digit($raw)&&(int)$raw>0)$runnerIds[(int)$raw]=true;
        }
    }
    $runnerMap=[];$checks=[];
    if($runnerIds){
        $ids=array_keys($runnerIds);
        $params=implode(',',array_fill(0,count($ids),'?'));
        foreach(rows('SELECT id,first_name,last_name,photo_url FROM runners
                      WHERE id IN ('.$params.')',$ids) as $runner)
            $runnerMap[(int)$runner['id']]=$runner;
    }
    if($regIds){
        $params=implode(',',array_fill(0,count($regIds),'?'));
        foreach(rows('SELECT registration_id,athlete_key,checked_at
                      FROM race_athlete_checkins WHERE registration_id IN ('.$params.')',$regIds) as $check)
            $checks[(int)$check['registration_id']][(string)$check['athlete_key']]=
                (string)$check['checked_at'];
    }
    $logoLookup=school_logo_lookup(
        rows('SELECT name,nickname,logo_file FROM schools'));
    foreach($results as &$result){
        foreach($result['places'] as &$place){
            $place['logo']=school_logo_for($logoLookup,(string)$place['school']);
            $place['athletes']=live_result_place_athletes(
                $result,$place,$registrations,$runnerMap,$checks);
        }
        unset($place);
    }
    unset($result);
    return $results;
}

function live_dataset(bool $manager=false,?string $sportFixtureKey=null):array {
    $schedule=event_schedule_data();
    $roundIndex=live_official_round_index($schedule);
    $heatSlots=live_schedule_heat_slots($schedule);
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
    $schoolColumns=live_school_columns(
        rows('SELECT id,name,nickname,logo_file,live_position FROM schools ORDER BY id'),$registrations);
    $fixtures=[];$teamsByKey=[];$legacyToCurrent=[];
    foreach($registrations as $r){
        $ord=$order[live_event_order_key($r)]??null;
        $oldEventId=(int)($ord['eventId']??$r['event_id']??0);
        $round=live_fixture_round($oldEventId,$r,$roundIndex);
        $key=live_competition_key($r,$round);
        // Upgrade stored Active selections from both earlier per-heat formats
        // without changing or clearing the persisted selection/check-ins.
        $legacyToCurrent[live_group_key($r)]=$key;
        $legacyToCurrent[live_legacy_group_key($r)]=$key;
        $heat=max(1,(int)($r['heat']??1));
        $slotKey=live_event_order_key($r).'|'.$round.'|'.$heat;
        $slot=$heatSlots[$slotKey]??null;
        $eventId=(int)($slot['eventId']??$r['event_id']??$oldEventId);
        $sortOrder=(int)($slot['order']??$ord['order']??999999);
        if(!isset($fixtures[$key])){
            $fixtures[$key]=[
                'key'=>$key,
                'eventId'=>$eventId,
                'eventIds'=>[],
                'eventNumberLabel'=>'',
                'eventName'=>(string)$r['event_name'],
                'category'=>(string)$r['category'],
                'level'=>(string)$r['level'],
                'gender'=>(string)($ord['gender']??live_gender((string)$r['gender'])),
                'heat'=>$heat,
                'heats'=>[],
                'round'=>$round,
                'order'=>$sortOrder,
                'total'=>0,'checked'=>0,'teams'=>0,'teamsReady'=>0
            ];
            $teamsByKey[$key]=[];
        }
        live_add_heat($fixtures[$key]['heats'],$heat);
        if($eventId>0&&!in_array($eventId,$fixtures[$key]['eventIds'],true))
            $fixtures[$key]['eventIds'][]=$eventId;
        $fixtures[$key]['order']=min($fixtures[$key]['order'],$sortOrder);
        $athletes=live_athlete_rows($r,$runnerMap,$checks);
        $capacity=live_attendance_capacity($r);
        $present=live_counted_attendees($athletes,$capacity);
        $allChecked=count(array_filter($athletes,static fn($a)=>$a['checkedAt']!==null));
        $fixtures[$key]['total']+=$capacity;
        $fixtures[$key]['checked']+=count($present);
        $fixtures[$key]['teams']++;
        if(count($present)===$capacity)$fixtures[$key]['teamsReady']++;
        $team=[
            'regId'=>(int)$r['id'],
            'school'=>(string)$r['school_name'],
            'lane'=>$r['lane']===null?null:(int)$r['lane'],
            'heat'=>$heat,
            'totalAthletes'=>$capacity,
            'checkedAthletes'=>count($present),
            'registeredAthletes'=>count($athletes),
            // Display any legacy over-limit check-ins to managers for correction.
            'excessCheckins'=>max(0,$allChecked-$capacity)
        ];
        $team['athletes']=$manager?$athletes:
            ($sportFixtureKey!==null&&hash_equals($key,$sportFixtureKey)
                ?live_sport_candidates($athletes,$capacity):$present);
        $teamsByKey[$key][]=$team;
    }
    foreach($fixtures as &$fixture){
        sort($fixture['eventIds'],SORT_NUMERIC);
        $fixture['eventNumberLabel']=live_event_number_label($fixture['eventIds']);
        $fixture['eventId']=$fixture['eventIds'][0]??$fixture['eventId'];
        // Heat number is shown only when there is exactly one heat.
        $fixture['heat']=count($fixture['heats'])===1?$fixture['heats'][0]:0;
    }
    unset($fixture);
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
    $sportFixture=null;
    if($sportFixtureKey!==null){
        foreach($fixtures as $fixture)if(hash_equals($fixture['key'],$sportFixtureKey)){
            $sportFixture=$fixture;break;
        }
    }
    $teams=$sportFixtureKey!==null?
        ($teamsByKey[$sportFixtureKey]??[]):($active?$teamsByKey[$activeKey]:[]);
    usort($teams,static fn($a,$b)=>
        ($a['heat']<=>$b['heat'])
        ?:(($a['lane']??9999)<=>($b['lane']??9999))
        ?:strcmp($a['school'],$b['school'])
        ?:($a['regId']<=>$b['regId']));
    $columns=[];
    foreach($schoolColumns as $school){
        $columns[]=[
            'name'=>$school['name'],'key'=>$school['key'],'logo'=>$school['logo']??null,
            'registered'=>false,'heats'=>[],'teamCount'=>0,'totalAthletes'=>0,'checkedAthletes'=>0,
            'athletes'=>[]
        ];
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
        // This is registration membership, not check-in state. A registered
        // school must remain green even if none of its athletes has arrived.
        $columns[$columnIndex]['registered']=true;
        live_add_heat($columns[$columnIndex]['heats'],(int)$team['heat']);
        $columns[$columnIndex]['teamCount']++;
        $columns[$columnIndex]['totalAthletes']+=(int)$team['totalAthletes'];
        $columns[$columnIndex]['checkedAthletes']+=(int)$team['checkedAthletes'];
        foreach($team['athletes'] as $athlete){
            if($sportFixtureKey===null&&$athlete['checkedAt']===null)continue;
            $publicAthlete=[
                'name'=>$athlete['name'],'photo'=>$athlete['photo'],
                'regId'=>$team['regId'],'school'=>$team['school'],'heat'=>$team['heat'],
                'checkedAt'=>$athlete['checkedAt']
            ];
            $columns[$columnIndex]['athletes'][]=$publicAthlete;
            if($athlete['checkedAt']!==null)$recent[]=$publicAthlete;
        }
    }
    usort($recent,static fn($a,$b)=>strcmp($b['checkedAt'],$a['checkedAt']));
    $nextFixtures=$currentIndex>=0
        ?array_slice($fixtures,$currentIndex+1,3)
        :array_slice($fixtures,0,3);
    $nextFixture=$currentIndex>=0?($nextFixtures[0]??null):null;
    // Next-event roster is available only to Manager/Admin. It supports
    // pre-reporting while the current event remains on the public screen.
    $nextTeams=$manager && $nextFixture
        ?($teamsByKey[$nextFixture['key']]??[])
        :[];
    return [
        'success'=>true,
        'serverTime'=>date('c'),
        'serverNowMs'=>(int)round(microtime(true)*1000),
        'display'=>live_display_state(),
        'sportFixture'=>$sportFixture,
        'active'=>$active,
        'activeUpdatedAt'=>$state['updated_at']??null,
        'fixtures'=>$fixtures,
        'schools'=>$columns,
        'teams'=>$teams,
        'recent'=>array_slice($recent,0,10),
        'next'=>$nextFixtures,
        'latestResults'=>live_latest_results(),
        'nextTeams'=>$nextTeams,
        'isManager'=>$manager
    ];
}

if($action==='live_state'){
    if(($_SERVER['REQUEST_METHOD']??'')!=='GET')json_out(['success'=>false,'message'=>'GET required'],405);
    json_out(live_dataset(false));
}
if($action==='live_sport_fixture'){
    if(($_SERVER['REQUEST_METHOD']??'')!=='GET')json_out(['success'=>false,'message'=>'GET required'],405);
    if(live_display_state()['mode']!=='sport')
        json_out(['success'=>false,'message'=>'โหมด Sport ยังไม่เปิดใช้งาน'],409);
    $key=(string)($_GET['fixtureKey']??'');
    if(!preg_match('/^[a-f0-9]{64}$/D',$key))
        json_out(['success'=>false,'message'=>'รหัสรายการแข่งขันไม่ถูกต้อง'],422);
    $state=live_dataset(false,$key);
    if(!$state['sportFixture'])
        json_out(['success'=>false,'message'=>'ไม่พบรายการแข่งขัน'],404);
    // Only harmless public display fields; no proof, birth dates, citizenship
    // numbers, private registration keys, or manager-only metadata.
    json_out([
        'success'=>true,
        'fixture'=>$state['sportFixture'],
        'schools'=>$state['schools'],
        'display'=>$state['display'],
        // Null until Admin confirms an archived official championship.
        // The former winner is school-level; the visible photos are current
        // registered athletes, never historical student identity claims.
        'formerChampion'=>champion_history_for_fixture($state['sportFixture'])
    ]);
}
if(!in_array($action,['live_manager_data','live_select','live_runner_set','live_display_set'],true))return;
if(!isset($u)||!is_array($u))json_out(['success'=>false,'message'=>'กรุณาเข้าสู่ระบบ'],401);
require_reviewer($u);

if($action==='live_manager_data'){
    if(($_SERVER['REQUEST_METHOD']??'')!=='GET')json_out(['success'=>false,'message'=>'GET required'],405);
    json_out(live_dataset(true));
}
must_post();

if($action==='live_display_set'){
    $mode=trim((string)($input['mode']??''));
    if(!in_array($mode,['checkin','sport'],true))
        json_out(['success'=>false,'message'=>'โหมดการแสดงผลไม่ถูกต้อง'],422);
    db()->prepare("INSERT INTO race_live_display(id,display_mode,updated_by,updated_at)
        VALUES(1,?,?,NOW()) ON DUPLICATE KEY UPDATE
        display_mode=VALUES(display_mode),updated_by=VALUES(updated_by),updated_at=NOW()")
        ->execute([$mode,(int)$u['coachId']]);
    log_action('LIVE_DISPLAY_MODE',$u,'SportShowcase',$mode,
        $mode==='sport'?'Show competition carousel':'Restore live check-in board');
    json_out(['success'=>true,'mode'=>$mode,
        'message'=>$mode==='sport'?'เปิด Sport แสดงรายการแข่งขันวนอัตโนมัติแล้ว':
            'กลับสู่หน้าจอ Check-in รายการ Active แล้ว']);
}

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
    $state=live_dataset(true);
    $active=(string)($state['active']['key']??'');
    $available=array_merge($state['teams']??[],$state['nextTeams']??[]);
    $allowed=false;
    foreach($available as $team)if((int)$team['regId']===$regId){$allowed=true;break;}
    if($active===''||!$allowed){
        json_out(['success'=>false,'message'=>'เช็กชื่อได้เฉพาะรายการ Active หรือรายการถัดไปเท่านั้น'],409);
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
        $capacity=live_attendance_capacity($reg);
        try{
            db()->beginTransaction();
            // Lock a consistent parent row so simultaneous Manager clicks
            // cannot both claim the final available attendance slot.
            db()->prepare('SELECT id FROM registrations WHERE id=? FOR UPDATE')->execute([$regId]);
            $existing=single('SELECT athlete_key FROM race_athlete_checkins WHERE registration_id=? AND athlete_key=?',[$regId,$athleteKey]);
            if($existing){
                $changed=false;
            }else{
                $used=(int)single('SELECT COUNT(*) AS n FROM race_athlete_checkins WHERE registration_id=?',[$regId])['n'];
                if($used >= $capacity){
                    db()->rollBack();
                    json_out(['success'=>false,'message'=> $capacity===1
                        ?'วิ่งเดี่ยวรายงานตัวได้เพียง 1 คนต่อทีม กรุณายกเลิกชื่อเดิมก่อนเลือกคนใหม่'
                        :'วิ่งผลัดรายงานตัวได้ไม่เกิน 4 คนต่อทีม กรุณายกเลิกชื่อเดิมก่อนเลือกคนใหม่'],409);
                }
                $stmt=db()->prepare('INSERT IGNORE INTO race_athlete_checkins (registration_id,athlete_key,checked_by,checked_at) VALUES(?,?,?,NOW())');
                $stmt->execute([$regId,$athleteKey,(int)$u['coachId']]);
                $changed=$stmt->rowCount()>0;
            }
            db()->commit();
        }catch(Throwable $e){
            if(db()->inTransaction())db()->rollBack();
            error_log('Bang1 runner check-in failed: '.$e->getMessage());
            json_out(['success'=>false,'message'=>'ไม่สามารถบันทึกรายงานตัวได้ กรุณาลองอีกครั้ง'],500);
        }
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

<?php
declare(strict_types=1);
/**
 * Persistent, school-level athletics history. No student names, IDs, photos,
 * ID documents, passwords, or personal information are ever archived here.
 * Stored independently of the reset/restore tables.
 */
function champion_history_key(string $event,string $category,string $level):string {
    $values=[$event,$category,$level];
    foreach($values as &$value){
        $value=trim((string)preg_replace('/\s+/u','',$value));
        $value=strtolower($value); // Fold Latin 4X100/4x100 without altering Thai.
    }
    unset($value);
    return hash('sha256',json_encode($values,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
}
function champion_history_school_key(string $name):string {
    $name=trim((string)preg_replace('/\s+/u','',$name));
    return preg_replace('/^โรงเรียน/u','',$name)??$name;
}
function champion_history_rankings(mixed $ranking):array {
    $decoded=is_array($ranking)?$ranking:json_decode((string)$ranking,true);
    if(!is_array($decoded))return [];
    $ranks=[];$seen=[];
    for($place=1;$place<=8;$place++){
        $school=trim((string)($decoded[(string)$place]??$decoded[$place]??''));
        if($school===''||strlen($school)>750)continue;
        $key=champion_history_school_key($school);
        if(isset($seen[$key]))continue;
        $seen[$key]=true;
        $ranks[(string)$place]=$school;
    }
    return $ranks;
}
function champion_history_source():array {
    $events=rows('SELECT id,name,category,level,gender,stage,heat,ranking_json FROM events');
    $byId=[];
    foreach($events as $event)$byId[(int)$event['id']]=$event;
    $records=[];
    $seen=[];
    foreach(rows('SELECT event_id,round_type,heat_no,ranking_json,updated_at FROM event_results') as $result){
        $id=(int)$result['event_id'];
        $event=$byId[$id]??null;
        if(!$event)continue;
        $round=trim((string)$result['round_type']);
        if(!in_array($round,['คัดเลือก','ชิงชนะเลิศ'],true))continue;
        $heat=max(1,(int)$result['heat_no']);
        $ranking=champion_history_rankings($result['ranking_json']);
        if(!$ranking)continue;
        $key=$id.'|'.$round.'|'.$heat;
        $seen[$key]=true;
        $records[]=[
            'eventId'=>$id,'eventName'=>(string)$event['name'],
            'category'=>(string)$event['category'],'level'=>(string)$event['level'],
            'eventKey'=>champion_history_key((string)$event['name'],
                (string)$event['category'],(string)$event['level']),
            'gender'=>(string)$event['gender'],'round'=>$round,'heat'=>$heat,
            'rankings'=>$ranking,'updatedAt'=>$result['updated_at']
        ];
    }
    // Preserve imported legacy final rankings not yet migrated to Staff's
    // event_results. Explicit final stage is required to call anyone a winner.
    foreach($events as $event){
        if(trim((string)$event['ranking_json'])==='')continue;
        $round=trim((string)$event['stage']);
        if(!in_array($round,['คัดเลือก','ชิงชนะเลิศ'],true))continue;
        $id=(int)$event['id'];
        $heat=max(1,(int)$event['heat']);
        if(isset($seen[$id.'|'.$round.'|'.$heat]))continue;
        $ranking=champion_history_rankings($event['ranking_json']);
        if(!$ranking)continue;
        $records[]=[
            'eventId'=>$id,'eventName'=>(string)$event['name'],
            'category'=>(string)$event['category'],'level'=>(string)$event['level'],
            'eventKey'=>champion_history_key((string)$event['name'],
                (string)$event['category'],(string)$event['level']),
            'gender'=>(string)$event['gender'],'round'=>$round,'heat'=>$heat,
            'rankings'=>$ranking,'updatedAt'=>null
        ];
    }
    usort($records,static fn($a,$b)=>$a['eventId']<=>$b['eventId']
        ?:strcmp($a['round'],$b['round'])?:$a['heat']<=>$b['heat']);
    return $records;
}
function champion_history_count_finals(array $records):int {
    $groups=[];
    foreach($records as $row){
        if($row['round']!=='ชิงชนะเลิศ'||empty($row['rankings']['1']))continue;
        $groups[$row['eventKey']][$row['rankings']['1']]=true;
    }
    $finals=0;
    foreach($groups as $winners)if(count($winners)===1)$finals++;
    return $finals;
}
/** Called inside the caller's transaction; never makes a nested commit. */
function champion_history_capture(array $actor,string $label,bool $confirmed,string $source):array {
    if(!db()->inTransaction())throw new LogicException('History capture must be in a transaction');
    $records=champion_history_source();
    $finals=champion_history_count_finals($records);
    if(!$records)return ['id'=>null,'rows'=>0,'finals'=>0];
    if($confirmed&&$finals===0)throw new DomainException('ยังไม่มีผลรอบชิงอันดับ 1 ที่บันทึกจริง ไม่สามารถยืนยันแชมป์เก่าได้');
    $stmt=db()->prepare('INSERT INTO race_history_batches
        (season_label,source_type,is_confirmed,result_count,final_count,captured_by)
        VALUES(?,?,?,?,?,?)');
    $stmt->execute([$label,$source,$confirmed?1:0,count($records),$finals,(int)$actor['coachId']]);
    $batchId=(int)db()->lastInsertId();
    $save=db()->prepare('INSERT INTO race_history_results
        (batch_id,source_event_id,event_name,event_key,category,level,gender,
         round_type,heat_no,ranking_json,source_updated_at)
         VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    foreach($records as $item){
        $save->execute([$batchId,$item['eventId'],$item['eventName'],$item['eventKey'],
            $item['category'],$item['level'],$item['gender'],$item['round'],$item['heat'],
            json_encode($item['rankings'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
            $item['updatedAt']]);
    }
    return ['id'=>$batchId,'rows'=>count($records),'finals'=>$finals];
}
/**
 * A prior confirmed batch may have multiple final heats with different
 * first-place schools. That is ambiguous: do not invent a single champion.
 */
function champion_history_pick(array $records):?array {
    if(!$records)return null;
    $byBatch=[];
    foreach($records as $record){
        $batchId=(int)($record['batch_id']??0);
        $school=trim((string)(champion_history_rankings($record['ranking_json'])['1']??''));
        if($batchId<=0||$school==='')continue;
        $byBatch[$batchId][]=['school'=>$school,
            'season'=>(string)$record['season_label'],
            'capturedAt'=>(string)($record['captured_at']??'')];
    }
    foreach($byBatch as $champions){
        $unique=[];
        foreach($champions as $c)$unique[champion_history_school_key($c['school'])]=$c;
        if(count($unique)!==1)continue; // Never infer a winner from conflicting finals.
        $winner=array_values($unique)[0];
        return ['school'=>$winner['school'],'season'=>$winner['season'],
            'capturedAt'=>$winner['capturedAt']];
    }
    return null;
}
function champion_history_for_fixture(array $fixture):?array {
    $key=champion_history_key((string)($fixture['eventName']??''),
        (string)($fixture['category']??''),(string)($fixture['level']??''));
    $records=rows("SELECT h.batch_id,h.ranking_json,b.season_label,b.captured_at
        FROM race_history_results h
        JOIN race_history_batches b ON b.id=h.batch_id
        WHERE h.event_key=? AND h.round_type='ชิงชนะเลิศ' AND b.is_confirmed=1
        ORDER BY b.captured_at DESC,b.id DESC,h.id ASC",[$key]);
    return champion_history_pick($records);
}
function champion_history_list():array {
    return rows('SELECT id,season_label,source_type,is_confirmed,result_count,
        final_count,captured_at FROM race_history_batches ORDER BY id DESC LIMIT 60');
}

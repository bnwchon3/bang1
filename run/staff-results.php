<?php
declare(strict_types=1);
/**
 * Staff scoring. Only schools registered for the specific competition/heat
 * can be ranked; never trust options or role flags from the client.
 */
function staff_result_fixture_rows(): array {
    $fixtures=event_schedule_data();
    $allRegs=event_registration_rows();
    $stored=rows('SELECT event_id, round_type, heat_no, ranking_json, staff_name, updated_at FROM event_results');
    $savedByKey=[];
    foreach($stored as $saved){
        $savedByKey[(int)$saved['event_id'].'|'.$saved['round_type'].'|'.(int)$saved['heat_no']]=$saved;
    }
    $events=rows('SELECT id,name,category,level,heat,stage,ranking_json FROM events');
    $byId=[];
    foreach($events as $event)$byId[(int)$event['id']]=$event;
    $output=[];
    foreach($fixtures as $fixture){
        $id=(int)($fixture['EventID']??0);
        $base=$byId[$id]??null;
        if(!$base)continue;
        $relevant=event_matching_regs($base,$allRegs);
        $allSchools=event_schools($relevant);
        if(!count($allSchools))continue;
        sort($allSchools,SORT_NATURAL);
        $round=(string)($fixture['Stage']??'');
        if($round!=='ชิงชนะเลิศ')$round='คัดเลือก';
        $heat=(int)($fixture['Heat']??1);
        if($heat<1)$heat=1;
        $key=$id.'|'.$round.'|'.$heat;
        // The draw for multi-heat events must be recorded before ranking a heat.
        $heatCount=count(event_heat_sizes(count($allSchools)));
        $requiresDraw=$heatCount>1 && ($round==='คัดเลือก'
            || ((int)($fixture['SchoolCount']??0)<=8));
        if($requiresDraw){
            $allowed=[];
            foreach($relevant as $reg){
                if((int)($reg['heat']??0)===$heat && trim((string)$reg['school_name'])!==''){
                    $allowed[trim((string)$reg['school_name'])]=true;
                }
            }
            $schools=array_keys($allowed);
            sort($schools,SORT_NATURAL);
        } else {
            // For a separate final fixture, enrolled schools are the safe upper
            // bound; selection of finalists is still decided by officials.
            $schools=$allSchools;
        }
        $saved=$savedByKey[$key]??null;
        $ranks=[];
        if($saved){
            $decoded=json_decode((string)$saved['ranking_json'],true);
            if(is_array($decoded))$ranks=$decoded;
        }elseif(trim((string)($base['ranking_json']??''))!=='' &&
            ($heat===(int)($base['heat']??$heat)) &&
            ($round===trim((string)($base['stage']??$round)))){
            // Imported legacy results: keep existing rankings visible only where
            // they match a registered school and the current fixture.
            $decoded=json_decode((string)$base['ranking_json'],true);
            if(is_array($decoded))$ranks=$decoded;
        }
        $cleanRanks=[];
        $used=[];
        for($rank=1;$rank<=10;$rank++){
            $school=trim((string)($ranks[$rank]??$ranks[(string)$rank]??''));
            if($school!=='' && in_array($school,$schools,true) && !isset($used[$school])){
                $cleanRanks[(string)$rank]=$school;
                $used[$school]=true;
            }
        }
        $isSaved=count($cleanRanks)>0;
        $output[]=[
            'eventId'=>$id,'eventName'=>(string)$fixture['EventName'],
            'category'=>(string)($fixture['Category']??''),
            'level'=>(string)($fixture['Level']??''),
            'gender'=>(string)($fixture['Gender']??''),
            'round'=>$round,'heat'=>$heat,
            'fixtureKey'=>$key,'schools'=>$schools,
            'registeredSchools'=>count($allSchools),
            'isSaved'=>$isSaved,'ranks'=>$cleanRanks,
            'pendingDraw'=>$requiresDraw && !$schools,
            'updatedAt'=>$saved['updated_at']??null,
            'updatedBy'=>$saved['staff_name']??null,
        ];
    }
    return $output;
}
if($action==='staff_results_list'){
    json_out(['success'=>true,'data'=>staff_result_fixture_rows()]);
}
if($action==='staff_results_save'){
    must_post();
    if(($u['role']??'')!=='Staff')
        json_out(['success'=>false,'message'=>'เฉพาะ Staff เท่านั้นที่บันทึกผลการแข่งขันได้'],403);
    $id=(int)($input['eventId']??0);
    $heat=(int)($input['heat']??0);
    $round=(string)($input['round']??'');
    $raw=$input['ranks']??null;
    if($id<=0||$heat<=0||$heat>99||!in_array($round,['คัดเลือก','ชิงชนะเลิศ'],true)
       ||!is_array($raw))
        json_out(['success'=>false,'message'=>'ข้อมูลรายการแข่งขันไม่ถูกต้อง'],422);
    // Only rank 1..10. No arbitrary keys, no duplicated schools.
    $ranking=[];$used=[];
    foreach($raw as $k=>$v){
        if(!ctype_digit((string)$k)||(int)$k<1||(int)$k>10||!is_string($v))
            json_out(['success'=>false,'message'=>'ลำดับผลการแข่งขันไม่ถูกต้อง'],422);
        $school=trim($v);
        if(strlen($school)>255)
            json_out(['success'=>false,'message'=>'ชื่อโรงเรียนยาวเกินไป'],422);
        if($school==='')continue;
        if(isset($used[$school]))json_out(['success'=>false,'message'=>'เลือกโรงเรียนซ้ำไม่ได้'],422);
        $used[$school]=true;
        $ranking[(string)(int)$k]=$school;
    }
    if(!$ranking)json_out(['success'=>false,'message'=>'กรุณาเลือกโรงเรียนอย่างน้อยหนึ่งอันดับ'],422);
    try{
        db()->beginTransaction();
        $event=single('SELECT id FROM events WHERE id=? FOR UPDATE',[$id]);
        if(!$event)throw new DomainException('ไม่พบรายการแข่งขันนี้');
        // Recompute eligibility on the server at save time.
        $fixture=null;
        foreach(staff_result_fixture_rows() as $one){
            if($one['eventId']===$id && $one['heat']===$heat && $one['round']===$round){
                $fixture=$one;break;
            }
        }
        if(!$fixture || $fixture['pendingDraw'] || !$fixture['schools'])
            throw new DomainException('ยังไม่มีโรงเรียนในฮีทนี้ กรุณาจัดฮีทและลู่ก่อน');
        foreach($ranking as $school){
            if(!in_array($school,$fixture['schools'],true))
                throw new DomainException('พบโรงเรียนที่ไม่ได้สมัครรายการแข่งขันหรือไม่ได้อยู่ในฮีทนี้');
        }
        $serialized=json_encode($ranking,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $stmt=db()->prepare("INSERT INTO event_results(event_id,round_type,heat_no,ranking_json,staff_id,staff_name)
           VALUES(?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE ranking_json=VALUES(ranking_json),
             staff_id=VALUES(staff_id),staff_name=VALUES(staff_name),updated_at=CURRENT_TIMESTAMP");
        $stmt->execute([$id,$round,$heat,$serialized,(int)$u['coachId'],(string)$u['name']]);
        log_action('STAFF_RESULT_SAVE',$u,'Event',(string)$id,
            'บันทึกผล '.$round.' ฮีท '.$heat.' จำนวน '.count($ranking).' อันดับ');
        db()->commit();
        json_out(['success'=>true,'message'=>'บันทึกผลการแข่งขันเรียบร้อย']);
    }catch(DomainException $e){
        if(db()->inTransaction())db()->rollBack();
        json_out(['success'=>false,'message'=>$e->getMessage()],422);
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        error_log('Bang1 Staff score entry failed: '.$e->getMessage());
        json_out(['success'=>false,'message'=>'ไม่สามารถบันทึกผลการแข่งขันได้'],500);
    }
}

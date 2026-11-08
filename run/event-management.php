<?php
declare(strict_types=1);
/**
 * Manage seeded athletics event rows and compute competition rounds from
 * distinct registered schools. All writes are Admin-only.
 */
function event_key(array $e): string {
    return implode("\x1f",array_map(static fn($x)=>trim((string)$x),[
       $e['name'],$e['category']??'',$e['level']??'',$e['gender']??''
    ]));
}
function event_is_final(array $e): bool {
    // Original spreadsheet uses remark=คัดเลือก + stage=ชิงชนะเลิศ
    // for the pre-set later final (e.g. EventID 40), so stage is authoritative.
    return trim((string)($e['remark']??''))==='ชิงชนะเลิศ'
      || trim((string)($e['heat_plan']??''))==='ชิง'
      || trim((string)($e['stage']??''))==='ชิงชนะเลิศ';
}
function event_registration_rows(): array {
    return rows('SELECT id,event_id,event_name,category,level,school_name,runner_names,heat,lane FROM registrations ORDER BY id');
}
function event_group_rows(array $e): array {
    return rows('SELECT * FROM events WHERE name=? AND category <=> ? AND level <=> ? AND gender <=> ? ORDER BY source_order,id',[
        $e['name'],$e['category'],$e['level'],$e['gender']
    ]);
}
function event_matching_regs(array $event,array $regs): array {
    return array_values(array_filter($regs, static function($r)use($event){
        return trim((string)$r['event_name'])===trim((string)$event['name']) &&
           trim((string)($r['category']??''))===trim((string)($event['category']??'')) &&
           trim((string)($r['level']??''))===trim((string)($event['level']??''));
    }));
}
function event_schools(array $regs): array {
    $schools=[];
    foreach($regs as $r){
        $name=trim((string)$r['school_name']);
        if($name!=='')$schools[$name]=true;
    }
    return array_keys($schools);
}
function event_heat_sizes(int $teams): array {
    if($teams<=0)return [];
    if($teams<=6)return [$teams];
    if($teams<=8)return [intdiv($teams+1,2),intdiv($teams,2)];
    // At least two heats for preliminaries (9=5+4, 10=5+5).
    $number=(int)ceil($teams/5);
    $base=intdiv($teams,$number);
    $extra=$teams%$number;
    $out=[];
    for($i=0;$i<$number;$i++)$out[]=$base+($i<$extra?1:0);
    return $out;
}
function event_lanes(int $count): array {
    return match($count){
        1=>[4],
        2=>[3,4],
        3=>[3,4,5],
        4=>[3,4,5,6],
        5=>[2,3,4,5,6],
        6=>[1,2,3,4,5,6],
        default=>throw new InvalidArgumentException('Invalid heat lane count')
    };
}
/** Schedule is based on imported setup until there are registrations. */
function event_schedule_data(): array {
    $events=rows('SELECT * FROM events ORDER BY source_order,id');
    $registrations=event_registration_rows();
    $groups=[];
    foreach($events as $ev)$groups[event_key($ev)][]=$ev;
    $result=[];
    foreach($groups as $evs){
        $first=$evs[0];
        $regs=event_matching_regs($first,$registrations);
        $teams=count(event_schools($regs));
        $prelims=array_values(array_filter($evs,static fn($ev)=>!event_is_final($ev)));
        $finals=array_values(array_filter($evs,static fn($ev)=>event_is_final($ev)));
        if(!$prelims)$prelims=[$evs[0]];
        if($teams===0){
            foreach($evs as $ev){
                $result[]=array_merge(event_map_schedule($ev),[
                    'entries'=>[], 'SchoolCount'=>0,'CompetitionRound'=>'ตั้งค่าไว้เดิม'
                ]);
            }
            continue;
        }
        $sizes=event_heat_sizes($teams);
        $directFinal=$teams<=8;
        $byHeat=[];
        foreach($regs as $reg){
            $h=(int)($reg['heat']??0);
            if($h>0)$byHeat[$h][]=$reg;
        }
        foreach($sizes as $index=>$size){
            $h=$index+1;
            $template=$prelims[min($index,count($prelims)-1)];
            $entry=[];
            foreach($byHeat[$h]??[] as $reg){
                $entry[]=['SchoolName'=>$reg['school_name'],'RunnerName'=>$reg['runner_names'],
                   'Heat'=>$h,'Lane'=>$reg['lane']];
            }
            usort($entry,static fn($a,$b)=>((int)$a['Lane'])<=>((int)$b['Lane']));
            $line=event_map_schedule($template);
            $line['Heat']=(string)$h;
            $line['HeatPlan']=(string)$h;
            $line['TeamCount']=$size;
            $line['TotalTeams']=$teams;
            $line['SchoolCount']=$teams;
            $line['Remark']=$directFinal?'ชิงชนะเลิศ':'คัดเลือก';
            $line['Stage']=$directFinal?'ชิงชนะเลิศ':'คัดเลือก';
            $line['CompetitionRound']=$directFinal?'ชิงชนะเลิศทันที':'คัดเลือก';
            $line['entries']=$entry;
            $result[]=$line;
        }
        // Hide preconfigured separate final fixtures when the event is already a direct final.
        if(!$directFinal){
            foreach($finals as $ev){
                $line=event_map_schedule($ev);
                // Original schedule UI interprets Remark=คัดเลือก + Stage=ชิงชนะเลิศ
                // as an upcoming final that must wait for qualifying results.
                $line['Remark']='คัดเลือก';
                $line['Stage']='ชิงชนะเลิศ';
                $line['TeamCount']=null;
                $line['TotalTeams']=$teams;
                $line['SchoolCount']=$teams;
                $line['CompetitionRound']='รอผลรอบคัดเลือก';
                $line['entries']=[];
                $result[]=$line;
            }
        }
    }
    return $result;
}
function event_map_schedule(array $e): array {
    return ['EventID'=>$e['id'],'EventName'=>$e['name'],'Category'=>$e['category'],
       'Level'=>$e['level'],'Gender'=>$e['gender'],'Remark'=>$e['remark'],
       'Heat'=>$e['heat'],'HeatPlan'=>$e['heat_plan'],'TeamCount'=>$e['team_count'],
       'TotalTeams'=>$e['total_teams'],'Stage'=>$e['stage']];
}

if($action==='schedule'){
    json_out(['success'=>true,'data'=>event_schedule_data()]);
}

if($action==='manage_events'){
    require_admin($u);
    $events=rows('SELECT * FROM events ORDER BY source_order,id');
    $regs=event_registration_rows();
    $out=[];
    foreach($events as $ev){
        $matched=event_matching_regs($ev,$regs);
        $out[]=[
            'id'=>(int)$ev['id'],'name'=>$ev['name'],'category'=>$ev['category'],
            'level'=>$ev['level'],'gender'=>$ev['gender'],'remark'=>$ev['remark'],
            'heat'=>$ev['heat'],'heat_plan'=>$ev['heat_plan'],'stage'=>$ev['stage'],
            'schoolCount'=>count(event_schools($matched)),
            'registrationCount'=>count($matched),
            'isFinalSlot'=>event_is_final($ev),
            'autoCancelled'=>event_is_final($ev) && count(event_schools($matched))>0 &&
                count(event_schools($matched))<=8 && count(event_group_rows($ev))>1
        ];
    }
    json_out(['success'=>true,'data'=>$out]);
}

if($action==='manage_event_save'){
    require_admin($u);
    must_post();
    $id=(int)($input['id']??0);
    $name=trim((string)($input['name']??''));
    $category=trim((string)($input['category']??''));
    $level=trim((string)($input['level']??''));
    $gender=trim((string)($input['gender']??''));
    $remark=trim((string)($input['remark']??''));
    $heat=trim((string)($input['heat']??''));
    $heatPlan=trim((string)($input['heatPlan']??''));
    $stage=trim((string)($input['stage']??''));
    if($id<0||$name===''||strlen($name)>255||strlen($category)>80||
        strlen($level)>60||strlen($gender)>30||strlen($remark)>255||
        strlen($heat)>50||strlen($heatPlan)>50||strlen($stage)>100) {
        json_out(['success'=>false,'message'=>'ข้อมูลรายการแข่งขันไม่ถูกต้อง'],422);
    }
    try{
        db()->beginTransaction();
        $previous=$id>0?single('SELECT * FROM events WHERE id=? FOR UPDATE',[$id]):null;
        if($id>0&&!$previous){
            db()->rollBack();
            json_out(['success'=>false,'message'=>'ไม่พบรายการแข่งขัน'],404);
        }
        if($previous){
            $group=event_group_rows($previous);
            $groupIds=array_map(static fn($e)=>(int)$e['id'],$group);
            $identityChanged=($name!==$previous['name'] || $category!==(string)$previous['category'] ||
                 $level!==(string)$previous['level'] || $gender!==(string)$previous['gender']);
            if($identityChanged){
                // Avoid merging registration pools with another existing competition.
                $duplicate=rows('SELECT id FROM events WHERE name=? AND category <=> ? AND level <=> ? AND gender <=> ?',[
                    $name,$category,$level,$gender]);
                foreach($duplicate as $d){
                    if(!in_array((int)$d['id'],$groupIds,true))
                        throw new DomainException('ข้อมูลใหม่ซ้ำกับรายการแข่งขันอื่น');
                }
                $sql='UPDATE events SET name=?,category=?,level=?,gender=? WHERE name=? AND category <=> ? AND level <=> ? AND gender <=> ?';
                db()->prepare($sql)->execute([$name,$category,$level,$gender,
                    $previous['name'],$previous['category'],$previous['level'],$previous['gender']]);
                // Imported registrations may have event_id=NULL: match by old name/category/level.
                db()->prepare('UPDATE registrations SET event_name=?,category=?,level=? WHERE event_name=? AND category <=> ? AND level <=> ?')
                    ->execute([$name,$category,$level,$previous['name'],$previous['category'],$previous['level']]);
                db()->prepare('UPDATE heats SET event_name=?,category=?,level=?,gender=? WHERE event_name=? AND category <=> ? AND level <=> ? AND gender <=> ?')
                    ->execute([$name,$category,$level,$gender,$previous['name'],$previous['category'],$previous['level'],$previous['gender']]);
                db()->prepare('UPDATE certificates SET event_name=? WHERE event_name=?')
                    ->execute([$name,$previous['name']]);
            }
            db()->prepare('UPDATE events SET remark=?,heat=?,heat_plan=?,stage=? WHERE id=?')
                ->execute([$remark,$heat,$heatPlan,$stage,$id]);
        } else {
            $id=(int)db()->query('SELECT COALESCE(MAX(id),0)+1 FROM events')->fetchColumn();
            $sort=(int)db()->query('SELECT COALESCE(MAX(source_order),0)+1 FROM events')->fetchColumn();
            db()->prepare('INSERT INTO events(id,name,category,level,gender,remark,heat,heat_plan,stage,source_order) VALUES(?,?,?,?,?,?,?,?,?,?)')
                ->execute([$id,$name,$category,$level,$gender,$remark,$heat,$heatPlan,$stage,$sort]);
        }
        log_action($previous?'EVENT_UPDATE':'EVENT_CREATE',$u,'Event',(string)$id,
            'รายการ: '.$name.($previous?'; ชื่อเดิม: '.$previous['name']:''));
        db()->commit();
        json_out(['success'=>true,'id'=>$id,'message'=>'บันทึกรายการแข่งขันเรียบร้อย']);
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        error_log('Bang1 event save: '.$e->getMessage());
        json_out(['success'=>false,'message'=>$e instanceof DomainException?$e->getMessage():'บันทึกรายการแข่งขันไม่สำเร็จ'],409);
    }
}
if($action==='manage_event_delete'){
    require_admin($u);
    must_post();
    $id=(int)($input['id']??0);
    $explicit=($input['confirmation']??'')==='ลบรายการและใบสมัคร';
    try{
        db()->beginTransaction();
        $ev=single('SELECT * FROM events WHERE id=? FOR UPDATE',[$id]);
        if(!$ev){db()->rollBack();json_out(['success'=>false,'message'=>'ไม่พบรายการแข่งขัน'],404);}
        $group=event_group_rows($ev);
        $other=array_values(array_filter($group,static fn($e)=>(int)$e['id']!==$id));
        $countStmt=db()->prepare('SELECT COUNT(*) FROM registrations WHERE event_name=? AND category <=> ? AND level <=> ?');
        $countStmt->execute([$ev['name'],$ev['category'],$ev['level']]);
        $registered=(int)$countStmt->fetchColumn();
        if($registered>0&&!$explicit){
           db()->rollBack();
           json_out(['success'=>false,'requiresConfirmation'=>true,'registrationCount'=>$registered,
             'message'=>'รายการนี้มีใบสมัครแล้ว '.$registered.' รายการ ต้องยืนยันการลบอีกครั้ง'],409);
        }
        if($other){
            // Another heat/final slot still represents this competition: keep registrations.
            $keep=(int)$other[0]['id'];
            db()->prepare('UPDATE registrations SET event_id=? WHERE event_id=?')->execute([$keep,$id]);
            db()->prepare('UPDATE heats SET event_id=? WHERE event_id=?')->execute([$keep,$id]);
        }else{
            db()->prepare('DELETE FROM registrations WHERE event_name=? AND category <=> ? AND level <=> ?')
                ->execute([$ev['name'],$ev['category'],$ev['level']]);
            db()->prepare('DELETE FROM heats WHERE event_name=? AND category <=> ? AND level <=> ?')
                ->execute([$ev['name'],$ev['category'],$ev['level']]);
            db()->prepare('DELETE FROM certificates WHERE event_name=?')->execute([$ev['name']]);
        }
        db()->prepare('DELETE FROM events WHERE id=?')->execute([$id]);
        log_action('EVENT_DELETE',$u,'Event',(string)$id,
            'ลบ '.$ev['name'].'; มีใบสมัครเดิม '.$registered.' รายการ; เหลือแถวแข่งขัน '.count($other));
        db()->commit();
        json_out(['success'=>true,'message'=>'ลบรายการแข่งขันเรียบร้อย',
            'registrationCount'=>$registered,'keptRegistrations'=>count($other)>0]);
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        error_log('Bang1 event delete: '.$e->getMessage());
        json_out(['success'=>false,'message'=>'ลบรายการแข่งขันไม่สำเร็จ'],500);
    }
}

if($action==='auto_heats'){
    require_admin($u);
    must_post();
    $events=rows('SELECT * FROM events ORDER BY source_order,id');
    $groups=[];
    foreach($events as $ev)$groups[event_key($ev)][]=$ev;
    try{
        db()->beginTransaction();
        db()->exec('DELETE FROM heats');
        $heatCount=0;
        foreach($groups as $evs){
            $ev=$evs[0];
            // Race entries belong to a school; assign one lane per school.
            $entries=rows('SELECT id,school_name FROM registrations WHERE event_name=? AND category <=> ? AND level <=> ? ORDER BY id FOR UPDATE',
                [$ev['name'],$ev['category'],$ev['level']]);
            if(!$entries)continue;
            $bySchool=[];
            foreach($entries as $entry)$bySchool[$entry['school_name']][]=(int)$entry['id'];
            $schools=array_keys($bySchool);
            shuffle($schools);
            $sizes=event_heat_sizes(count($schools));
            $prelims=array_values(array_filter($evs,static fn($e)=>!event_is_final($e)));
            if(!$prelims)$prelims=[$evs[0]];
            $index=0;
            foreach($sizes as $heatIndex=>$size){
                $h=$heatIndex+1;
                $lanes=event_lanes($size);
                $seed=$prelims[min($heatIndex,count($prelims)-1)];
                foreach($lanes as $lane){
                    $school=$schools[$index++];
                    foreach($bySchool[$school] as $registrationId){
                        db()->prepare('UPDATE registrations SET heat=?,lane=? WHERE id=?')->execute([$h,$lane,$registrationId]);
                    }
                }
                db()->prepare('INSERT INTO heats(event_id,event_name,category,level,gender,heat) VALUES (?,?,?,?,?,?)')
                    ->execute([$seed['id'],$ev['name'],$ev['category'],$ev['level'],$ev['gender'],$h]);
                $heatCount++;
            }
        }
        log_action('AUTO_HEATS',$u,'System','',(string)$heatCount.' heats, one lane per school');
        db()->commit();
        json_out(['success'=>true,'message'=>'สุ่มฮีทและลู่วิ่งเรียบร้อย','heatsCount'=>$heatCount]);
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        error_log('Bang1 auto heats: '.$e->getMessage());
        json_out(['success'=>false,'message'=>'สุ่มฮีทและลู่วิ่งไม่สำเร็จ'],500);
    }
}

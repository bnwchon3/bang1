<?php
declare(strict_types=1);

/**
 * Resolve registrations to ONE official program row. Imported event fixtures
 * may repeat an event name for heat 1/2 and retain historical team totals;
 * registrations are the source of truth for the current year's entrants.
 *
 * Unassigned (NULL/0) registration heats are linked to their original event
 * row. An explicitly assigned heat takes priority over the original event ID.
 * This prevents the same entrants appearing in every heat.
 */
function bang1_schedule_name(string $value): string {
    return trim((string)(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $value)) ?? $value));
}
function bang1_schedule_same_optional_field(mixed $a, mixed $b): bool {
    $left=bang1_schedule_name((string)($a??''));
    $right=bang1_schedule_name((string)($b??''));
    return $left==='' || $right==='' || $left===$right;
}
function bang1_schedule_is_final(array $event): bool {
    return trim((string)($event['Remark']??''))==='ชิงชนะเลิศ'
        || trim((string)($event['HeatPlan']??''))==='ชิง';
}
function bang1_schedule_resolve(array $events, array $registrations): array {
    $map=[];
    foreach($events as $event){
        $key=(string)($event['EventID']??'');
        if($key==='')continue;
        $map[$key]=['entries'=>[], 'RegisteredTeams'=>0];
    }
    foreach($registrations as $reg){
        $regId=(int)($reg['event_id']??0);
        $regName=bang1_schedule_name((string)($reg['event_name']??''));
        $assignedHeat=(int)($reg['heat']??0);
        $chosen=null;
        $best=-1000000;
        foreach($events as $event){
            $id=(int)($event['EventID']??0);
            if($id<=0)continue;
            $direct=$regId>0 && $regId===$id;
            $eventName=bang1_schedule_name((string)($event['EventName']??''));
            $sameName=$regName!=='' && $regName===$eventName;
            $compatible=bang1_schedule_same_optional_field($reg['category']??'', $event['Category']??'')
                && bang1_schedule_same_optional_field($reg['level']??'', $event['Level']??'');
            if(!$compatible || (!$direct && !$sameName))continue;

            $rowHeat=(int)($event['Heat']??0);
            // Assigned heat is strongest; exact source event is second.
            $score=$direct?200:0;
            $score+=bang1_schedule_is_final($event)?0:100;
            if($assignedHeat>0) {
                $score+=$rowHeat===$assignedHeat?2000:($rowHeat===0?0:-400);
            } else {
                $score+=$rowHeat===1?60:($rowHeat===0?30:0);
            }
            if($score>$best){
                $best=$score;
                $chosen=(string)$id;
            }
        }
        if($chosen===null)continue; // Never synthesize entrants for unrelated races.
        $map[$chosen]['entries'][]=[
            'SchoolName'=>(string)($reg['school_name']??''),
            'RunnerName'=>(string)($reg['runner_names']??''),
            'Heat'=>$assignedHeat>0?$assignedHeat:null,
            'Lane'=>$reg['lane']??null
        ];
    }
    foreach($map as &$item){
        $schools=[];
        foreach($item['entries'] as $entry){
            $name=trim($entry['SchoolName']);
            if($name!=='')$schools[$name]=true;
        }
        $item['RegisteredTeams']=count($schools);
        usort($item['entries'],static function(array $a,array $b):int {
            $la=(int)($a['Lane']??0);
            $lb=(int)($b['Lane']??0);
            return ($la>0?$la:9999)<=>($lb>0?$lb:9999);
        });
    }
    unset($item);
    return $map;
}

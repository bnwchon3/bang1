<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/run/schedule-registration.php';
function schedule_assert(bool $value,string $message):void {
    if(!$value)throw new RuntimeException($message);
}
$event=[
  'EventID'=>7,'EventName'=>'วิ่ง 80 เมตร (10 ปี ชาย)',
  'Category'=>'วิ่งเดี่ยว','Level'=>'ประถม','Heat'=>1,
  'Remark'=>'ชิงชนะเลิศ','HeatPlan'=>''
];
$second=$event;$second['EventID']=8;$second['Heat']=2;
$other=$event;$other['EventID']=9;$other['EventName']='วิ่ง 80 เมตร (10 ปี หญิง)';
$events=[$event,$second,$other];
$entrant=[
  'event_id'=>7,'event_name'=>$event['EventName'],
  'category'=>'วิ่งเดี่ยว','level'=>'ประถม',
  'school_name'=>'โรงเรียนบ้านนาวัง',
  'runner_names'=>'นักวิ่งหนึ่ง,นักวิ่งสำรอง','heat'=>null,'lane'=>null
];

$mapped=bang1_schedule_resolve($events,[$entrant]);
schedule_assert($mapped['7']['RegisteredTeams']===1,'Unassigned heat must still show enrolled school on its source event');
schedule_assert(count($mapped['7']['entries'])===1,'Unassigned runner names must appear on expanded row');
schedule_assert($mapped['8']['RegisteredTeams']===0,'Unassigned registrations must not be duplicated to heat 2');
schedule_assert($mapped['9']['RegisteredTeams']===0,'Other genders must not be matched');

$heatTwo=$entrant;$heatTwo['heat']=2;$heatTwo['lane']=6;
$mapped=bang1_schedule_resolve($events,[$heatTwo]);
schedule_assert($mapped['7']['RegisteredTeams']===0,'Assigned heat 2 must not appear in heat 1');
schedule_assert($mapped['8']['RegisteredTeams']===1,'Assigned heat 2 must take priority over original event id');
schedule_assert($mapped['8']['entries'][0]['Lane']===6,'Assigned lanes must persist');

$secondSchool=$entrant;$secondSchool['event_id']=7;$secondSchool['runner_names']='สำรองอีกคน';
$mapped=bang1_schedule_resolve($events,[$entrant,$secondSchool]);
schedule_assert($mapped['7']['RegisteredTeams']===1,'Count unique schools, not athlete or duplicate entry records');
schedule_assert(count($mapped['7']['entries'])===2,'Keep all valid registrations in the listing');

$unknown=$entrant;$unknown['event_id']=0;$unknown['event_name']='ไม่ใช่รายการนี้';
$mapped=bang1_schedule_resolve($events,[$unknown]);
schedule_assert($mapped['7']['RegisteredTeams']===0,'Old fixture counts must never manufacture registered schools');

$legacy=$entrant;$legacy['event_name']='ชื่อรายการเก่าที่ไม่ตรง';$legacy['event_id']=7;
$mapped=bang1_schedule_resolve($events,[$legacy]);
schedule_assert($mapped['7']['RegisteredTeams']===1,'Persisted event id can recover mismatched legacy name');

$prelim=$event;$prelim['EventID']=15;$prelim['Remark']='คัดเลือก';
$final=$event;$final['EventID']=16;$final['Remark']='ชิงชนะเลิศ';
$reg=$entrant;$reg['event_id']=15;
$mapped=bang1_schedule_resolve([$final,$prelim],[$reg]);
schedule_assert($mapped['15']['RegisteredTeams']===1&&$mapped['16']['RegisteredTeams']===0,
    'Qualifier entries must not be duplicated into separately scheduled final');
echo "PASS event ID matching, unassigned/assigned heats, distinct schools, stale counts, qualifier isolation\n";

<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/run/runner-rules.php';
function age_assert(bool $ok,string $msg):void {
    if(!$ok)throw new RuntimeException($msg);
}
foreach([6,8,10,12,14,16] as $age){
    age_assert(bang1_event_age_limit("วิ่ง 80 เมตร ({$age} ปี ชาย)") === $age,'Event age '.$age);
}
age_assert(bang1_event_age_limit('วิ่งผลัด 4X50 เมตร (6 ปี หญิง)')===6,'Relay event');
age_assert(bang1_event_age_limit('วิ่งรายการเปิด')===null,'Unclassified open event');
age_assert(bang1_runner_age_at_year(2563,2569)===6,'Birth 2563 = age 6');
age_assert(bang1_runner_age_at_year(2561,2569)===8,'Birth 2561 = age 8');
age_assert(bang1_runner_age_at_year(2557,2569)===12,'Birth 2557 = age 12');
age_assert(bang1_runner_age_at_year(2555,2569)===14,'Birth 2555 = age 14');
age_assert(bang1_runner_age_at_year(2570,2569)===null,'Future birth invalid');

function allowed(string $schoolLevel,int $age,int $eventAge):bool {
    return bang1_runner_eligibility_reason($schoolLevel,$age,$eventAge)===null;
}
foreach([
    ['อนุบาล',6,6,true], ['อนุบาล',5,6,true],
    ['อนุบาล',6,8,false], ['อนุบาล',6,14,false],
    ['ประถม',6,6,false], ['ประถม',8,6,false],
    ['ประถม',8,8,true], ['ประถม',8,10,true],
    ['ประถม',10,8,false], ['ประถม',10,10,true],
    ['ประถม',11,12,true], ['ประถม',12,12,true],
    ['ประถม',8,14,true], ['ประถม',10,16,true],
    ['ประถม',12,14,true], ['ประถม',12,16,true],
    ['ประถม',13,14,false],
    ['มัธยม',11,12,false], ['มัธยม',10,10,false],
    ['มัธยม',12,10,false], ['มัธยม',12,12,true],
    ['มัธยม',12,14,true], ['มัธยม',12,16,true],
    ['มัธยม',14,12,false], ['มัธยม',14,14,true],
    ['มัธยม',15,14,false], ['มัธยม',15,16,true],
    ['มัธยม',16,16,true], ['มัธยม',17,16,false],
    ['',12,12,false], ['ไม่ทราบ',12,14,false]
] as [$level,$runnerAge,$eventAge,$expected]) {
    age_assert(allowed($level,$runnerAge,$eventAge)===$expected,
      "Unexpected eligibility: school={$level}, age={$runnerAge}, event={$eventAge}");
}
age_assert(bang1_runner_eligibility_reason('มัธยม',12,12)===null,
    'EXCEPTION: secondary pupil aged 12 can enter primary age-12 event');
age_assert(bang1_runner_eligibility_reason('มัธยม',11,12)!==null,
    'Secondary pupil below age 12 cannot enter primary events');
age_assert(bang1_runner_eligibility_reason('ประถม',12,16)===null,
    'Primary pupil aged 12 can register upward to secondary age 16');
age_assert(bang1_runner_eligibility_reason('',12,null)!==null,
    'No event may be joined before actual education level is recorded');
echo "PASS school-level plus age rules, kindergarten only 6, upward primary, secondary age-12 exception\n";

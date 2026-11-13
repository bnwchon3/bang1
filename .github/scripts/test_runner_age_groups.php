<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/run/runner-rules.php';
function age_assert(bool $ok,string $msg):void {
    if(!$ok)throw new RuntimeException($msg);
}
age_assert(bang1_event_age_limit('วิ่ง 80 เมตร (6 ปี ชาย)')===6,'6-year event');
age_assert(bang1_event_age_limit('วิ่ง 80 เมตร (8 ปี หญิง)')===8,'8-year event');
age_assert(bang1_event_age_limit('วิ่ง 80 เมตร (10 ปี ชาย)')===10,'10-year event');
age_assert(bang1_event_age_limit('วิ่ง 400 เมตร (16 ปี หญิง)')===16,'16-year event');
age_assert(bang1_event_age_limit('วิ่งผลัด 4X50 เมตร (6 ปี หญิง)')===6,'relay age');
age_assert(bang1_event_age_limit('วิ่งรายการเปิด')===null,'unknown age should not be invented');
age_assert(bang1_runner_age_at_year(2563,2569)===6,'Birth 2563 must be age 6 in 2569');
age_assert(bang1_runner_age_at_year(2562,2569)===7,'Birth 2562 must be age 7 in 2569');
age_assert(bang1_runner_age_at_year(2564,2569)===5,'Birth 2564 must be age 5 in 2569');
age_assert(bang1_runner_age_at_year(2570,2569)===null,'Future birth year cannot compete');
age_assert(bang1_runner_age_at_year(0,2569)===null,'Invalid birth year');
$age=bang1_runner_age_at_year(2563,2569);
age_assert($age!==null&&$age<=bang1_event_age_limit('วิ่ง 80 เมตร (6 ปี ชาย)'),
    'Primary-school pupil aged 6 must be age-eligible for age 6 event');
age_assert(bang1_runner_age_at_year(2562,2569)>bang1_event_age_limit('วิ่ง 80 เมตร (6 ปี ชาย)'),
    '7-year-old cannot register for age 6 event');
echo "PASS athletics eligibility by birth year, event age, not educational level\n";

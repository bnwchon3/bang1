<?php
declare(strict_types=1);
// Isolated regression for the Live roster, kept outside the web-deployed run/ directory.
function safe_url(?string $url):?string {return filter_var($url,FILTER_VALIDATE_URL)?$url:null;}
$action='__live_group_regression_test__';
require dirname(__DIR__,2).'/run/live-api.php';

function check(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException($message);
}
$rows=[
 ['event_name'=>'วิ่ง 400 เมตร (16 ปี หญิง)','category'=>'วิ่งเดี่ยว','level'=>'มัธยม','gender'=>'หญิง','heat'=>1,'school_name'=>'โรงเรียนอนุบาลบางละมุง'],
 ['event_name'=>'วิ่ง 400 เมตร (16 ปี หญิง)','category'=>'วิ่งเดี่ยว','level'=>'มัธยม','gender'=>'หญิง','heat'=>1,'school_name'=>'โรงเรียนวัดสุทธาวาส'],
 ['event_name'=>'วิ่ง 400 เมตร (16 ปี หญิง)','category'=>'วิ่งเดี่ยว','level'=>'มัธยม','gender'=>'หญิง,หญิง','heat'=>1,'school_name'=>'โรงเรียนบ้านทุ่งกราด'],
 ['event_name'=>'วิ่ง 400 เมตร (16 ปี หญิง)','category'=>'วิ่งเดี่ยว','level'=>'มัธยม','gender'=>'หญิง','heat'=>1,'school_name'=>'โรงเรียนบ้านนาวัง'],
 ['event_name'=>'วิ่ง 400 เมตร (16 ปี หญิง)','category'=>'วิ่งเดี่ยว','level'=>'มัธยม','gender'=>'','heat'=>1,'school_name'=>'โรงเรียนวัดหนองเกตุใหญ่']
];
$keys=array_unique(array_map('live_group_key',$rows));
$oldKeys=array_unique(array_map('live_legacy_group_key',$rows));
check(count($keys)===1,'All five registered schools must belong to one event/heat');
check(count($oldKeys)>1,'Regression fixture must reproduce the old gender-split bug');
$event=['name'=>$rows[0]['event_name'],'category'=>'วิ่งเดี่ยว','level'=>'มัธยม','gender'=>'หญิง'];
foreach($rows as $entry)
    check(live_event_order_key($event)===live_event_order_key($entry),
        'The Live competition grouping must match the official schedule identity');
$secondHeat=$rows[0];$secondHeat['heat']=2;
check(live_group_key($secondHeat)!==live_group_key($rows[0]),'Distinct heats must remain separate');
$otherCategory=$rows[0];$otherCategory['category']='วิ่งผลัด';
check(live_group_key($otherCategory)!==live_group_key($rows[0]),'Distinct categories must remain separate');
echo "PASS live event grouping, gender variants, competition identity, and heat separation\n";

// Preserve the ten school columns in the order of the reference board.
$schoolRows=[['name'=>'โรงเรียนบ้านนาวัง'],['name'=>'โรงเรียนบ้านทุ่งกราด']];
$columns=live_school_columns($schoolRows,[['school_name'=>'โรงเรียนบ้านนาวัง']]);
check(count($columns)===10,'Live board must display exactly 10 school columns');
check($columns[8]['name']==='โรงเรียนบ้านนาวัง','Registered school must match its column');
check($columns[9]['name']==='โรงเรียนบ้านทุ่งกราด','School name must occupy the reference order');
$registration=[
    'id'=>303,'runner_ids'=>'101,102',
    'runner_names'=>'นักกรีฑา ก,นักกรีฑา ข','photos'=>'',
];
$runnerMap=[
    101=>['first_name'=>'นักกรีฑา','last_name'=>'ก','photo_url'=>'https://example.org/athlete101.jpg'],
    102=>['first_name'=>'นักกรีฑา','last_name'=>'ข','photo_url'=>'']
];
$checks=[303=>['runner:101'=>'2026-11-09 08:30:00']];
$athletes=live_athlete_rows($registration,$runnerMap,$checks);
check(count($athletes)===2,'Each registered athlete must get a distinct checkbox');
check($athletes[0]['athleteKey']==='runner:101','Existing runner ID must remain stable');
check($athletes[0]['checkedAt']!==null && $athletes[1]['checkedAt']===null,
    'Only the checked athlete can appear on the public live board');
check($athletes[0]['photo']==='https://example.org/athlete101.jpg',
    'The runner photo should accompany the checked athlete');
check(!isset($athletes[0]['citizen_id']),'Do not expose identification numbers');
echo "PASS per-athlete checkbox roster, photos, and ten-school grid\n";


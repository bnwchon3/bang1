<?php
declare(strict_types=1);
// Isolated regression for the Live roster, kept outside the web-deployed run/ directory.
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

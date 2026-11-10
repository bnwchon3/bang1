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
$customRows=[
    ['id'=>7,'name'=>'โรงเรียนบ้านนาวัง','live_position'=>2],
    ['id'=>9,'name'=>'โรงเรียนบ้านทุ่งกราด','live_position'=>1]
];
$customColumns=live_school_columns($customRows,[]);
check($customColumns[0]['name']==='โรงเรียนบ้านทุ่งกราด',
    'Configured school position one must be the leftmost LIVE column');
check($customColumns[1]['name']==='โรงเรียนบ้านนาวัง',
    'Saved projector school order must override legacy hardcoded order');
check(count($customColumns)===10,'Saved order retains the ten-school grid');
// Regression: many school rows plus configured order MUST NOT overflow the
// ten-column projector and trigger an all-"ไม่ได้ส่งแข่ง" browser fallback.
$overflowRows=[
    ['id'=>101,'name'=>'โรงเรียนบ้านนาวัง','live_position'=>2],
    ['id'=>102,'name'=>'โรงเรียนบ้านทุ่งกราด','live_position'=>1],
    ['id'=>103,'name'=>'โรงเรียนใหม่ทดสอบ','live_position'=>3]
];
foreach(range(4,14) as $n){
    $overflowRows[]=[
        'id'=>100+$n,
        'name'=>'โรงเรียนอื่น '.$n,
        'live_position'=>$n
    ];
}
$overflowColumns=live_school_columns($overflowRows,[
    ['school_name'=>'โรงเรียนบ้านนาวัง'],
    ['school_name'=>'โรงเรียนบ้านทุ่งกราด']
]);
check(count(live_configured_school_order($overflowRows))===10,
    'Configured order must be capped at precisely ten names');
check(count($overflowColumns)===10,
    'Extra saved schools must never make the LIVE board more than ten columns');
check($overflowColumns[0]['name']==='โรงเรียนบ้านทุ่งกราด' &&
      $overflowColumns[1]['name']==='โรงเรียนบ้านนาวัง',
    'Saved first and second positions must survive excess school rows');
check($overflowColumns[2]['name']==='โรงเรียนใหม่ทดสอบ',
    'Other configured schools must follow the selected display order');
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



// Ticker must use ONLY saved official rankings. Unranked results are not news.
$official=[
    'event_id'=>82,'event_name'=>'วิ่งผลัด 4x100 เมตร (16 ปี หญิง)',
    'round_type'=>'ชิงชนะเลิศ','heat_no'=>1,
    'ranking_json'=>json_encode(['1'=>'โรงเรียนบ้านนาวัง','2'=>'โรงเรียนอนุบาลบางละมุง','3'=>'โรงเรียนบ้านทุ่งกราด'],JSON_UNESCAPED_UNICODE),
    'updated_at'=>'2026-11-09 10:59:00'
];
$official['id']=987;
$recent=live_recent_result_item($official);
check(is_array($recent),'A Staff-saved ranking must become a public ticker result');
check($recent['eventId']===82 && $recent['heat']===1,'Result must preserve event/heat');
check($recent['resultId']===987,
  'Breaking news must identify the exact saved score row to prevent duplicate announcements');
check($recent['round']==='ชิงชนะเลิศ','Result must show the official round');
check($recent['updatedAt']==='2026-11-09 10:59:00',
  'New-result detection must be anchored to the official saved timestamp');
check(count($recent['places'])===3 && $recent['places'][0]['place']===1,
    'The latest result must display podium ranking in its saved order');
check($recent['places'][0]['school']==='โรงเรียนบ้านนาวัง',
    'The first-place school must come from the saved ranking');
$empty=$official;$empty['ranking_json']='{}';
check(live_recent_result_item($empty)===null,'Do not announce an unranked result');
$corrupt=$official;$corrupt['ranking_json']='this is not JSON';
check(live_recent_result_item($corrupt)===null,'Corrupt ranking must not appear publicly');
$qualifying=$official;$qualifying['round_type']='คัดเลือก';
$qualifying['id']=988;$qualifying['ranking_json']=json_encode([
  '1'=>'โรงเรียน ก','2'=>'โรงเรียน ข','3'=>'โรงเรียน ค',
  '4'=>'โรงเรียน ง'],JSON_UNESCAPED_UNICODE);
$breakingQualifying=live_recent_result_item($qualifying);
check($breakingQualifying['round']==='คัดเลือก' &&
 count($breakingQualifying['places'])===3,
 'Qualifying breaking news should announce only saved positions 1-3');
check($breakingQualifying['places'][2]['school']==='โรงเรียน ค',
 'The third qualifying school must match official Staff result');
$partial=$official;$partial['ranking_json']='{"1":"โรงเรียน ก"}';
check(count(live_recent_result_item($partial)['places'])===1,
 'Missing podium ranks must stay missing; do not invent winners');
echo "PASS latest official result ticker ranking, round, heat, and no fake results\n";


// The official schedule, not per-heat team count, determines the label.
// Event #11 is a qualifying competition split into heats; heat 1 may
// contain only five schools while the entire event has ten.
$roundIndex=live_official_round_index([
    ['EventID'=>11,'EventName'=>'วิ่ง 80 เมตร (8 ปี ชาย)','Category'=>'วิ่งเดี่ยว',
     'Level'=>'ประถม','Heat'=>1,'Stage'=>'คัดเลือก'],
    ['EventID'=>11,'EventName'=>'วิ่ง 80 เมตร (8 ปี ชาย)','Category'=>'วิ่งเดี่ยว',
     'Level'=>'ประถม','Heat'=>2,'Stage'=>'คัดเลือก'],
    ['EventID'=>40,'EventName'=>'วิ่ง 80 เมตร (8 ปี ชาย)','Category'=>'วิ่งเดี่ยว',
     'Level'=>'ประถม','Heat'=>1,'Stage'=>'ชิงชนะเลิศ'],
]);
$event11=['event_name'=>'วิ่ง 80 เมตร (8 ปี ชาย)',
    'category'=>'วิ่งเดี่ยว','level'=>'ประถม','heat'=>1];
check(live_fixture_round(11,$event11,$roundIndex)==='รอบคัดเลือก',
    'Event 11 heat 1 must show qualifying even when it has five entries');
$event11['heat']=2;
check(live_fixture_round(11,$event11,$roundIndex)==='รอบคัดเลือก',
    'Event 11 heat 2 must also show qualifying');
$event11['heat']=1;
check(live_fixture_round(40,$event11,$roundIndex)==='รอบชิง',
    'The separately scheduled championship must still show final');
check(live_fixture_round(999,$event11,$roundIndex)==='รอบคัดเลือก',
    'Generated heat with reused event ID must use its matching official preliminary stage');
$event11['event_name']='รายการที่ไม่อยู่ในตาราง';
check(live_fixture_round(999,$event11,$roundIndex)==='รอยืนยันรอบ',
    'Unknown competitions must not automatically be labelled finals');
echo "PASS official round from full competition schedule, Event 11 two heats, final and unknown fixtures\n";


// A competition is one Live selection across all its preliminary heats,
// even though every registration retains its own persisted heat number.
$h1=['event_name'=>'วิ่ง 80 เมตร (8 ปี ชาย)',
    'category'=>'วิ่งเดี่ยว','level'=>'ประถม','heat'=>1];
$h2=$h1;$h2['heat']=2;
check(live_competition_key($h1,'รอบคัดเลือก')===live_competition_key($h2,'รอบคัดเลือก'),
    'Event 11 and 12 must be merged into a single preliminary Live fixture');
check(live_competition_key($h1,'รอบคัดเลือก')!==live_competition_key($h2,'รอบชิง'),
    'A later final must never be merged with preliminary heats');
check(live_competition_key($h1,'รอบคัดเลือก')!==live_competition_key(
    array_merge($h1,['level'=>'อนุบาล']),'รอบคัดเลือก'),
    'Competitions with different age/level must remain separate');
$slots=live_schedule_heat_slots([
    ['EventID'=>11,'EventName'=>$h1['event_name'],'Category'=>'วิ่งเดี่ยว',
     'Level'=>'ประถม','Heat'=>1,'Stage'=>'คัดเลือก','SourceOrder'=>11],
    ['EventID'=>12,'EventName'=>$h1['event_name'],'Category'=>'วิ่งเดี่ยว',
     'Level'=>'ประถม','Heat'=>2,'Stage'=>'คัดเลือก','SourceOrder'=>12],
    ['EventID'=>40,'EventName'=>$h1['event_name'],'Category'=>'วิ่งเดี่ยว',
     'Level'=>'ประถม','Heat'=>1,'Stage'=>'ชิงชนะเลิศ','SourceOrder'=>40]
]);
$identity=live_event_order_key($h1);
check($slots[$identity.'|รอบคัดเลือก|1']['eventId']===11,
    'Heat 1 must retain the original scheduled EventID 11');
check($slots[$identity.'|รอบคัดเลือก|2']['eventId']===12,
    'Heat 2 must retain the original scheduled EventID 12');
check($slots[$identity.'|รอบชิง|1']['eventId']===40,
    'Finals must retain their own scheduled EventID');
check(live_event_number_label([12,11,12])==='11–12',
    'The combined live heading must say 11–12, not 11 or 12 alone');
check(live_event_number_label([13,11])==='11, 13',
    'Non-consecutive event numbers must not imply a nonexistent middle event');
check(live_event_number_label([11])==='11','Single heat must retain single event number');
$heats=[];
live_add_heat($heats,2);live_add_heat($heats,1);live_add_heat($heats,2);
check($heats===[1,2],'School heat badges must be unique and ordered');
echo "PASS combined event 11–12, separate final, correct IDs and school heat badges\n";


// Reporting counters are based on competition slots, NOT all candidate names.
// One school can list two potential sprinters but only one may report.
// Relay teams have four check-in slots, even if more alternatives are listed.
$soloRegistration=[
    'event_name'=>'วิ่ง 80 เมตร (8 ปี หญิง)','category'=>'วิ่งเดี่ยว',
    'runner_ids'=>'101,102','runner_names'=>'คนแรก,คนสำรอง'
];
$relayRegistration=[
    'event_name'=>'วิ่งผลัด 4x50 เมตร (6 ปี ชาย)','category'=>'วิ่งผลัด',
    'runner_ids'=>'101,102,103,104,105,106',
    'runner_names'=>'คนที่ 1,คนที่ 2,คนที่ 3,คนที่ 4,คนที่ 5,คนที่ 6'
];
check(live_attendance_capacity($soloRegistration)===1,
    'One team in a solo event counts as one attendee even with two candidate runners');
check(live_attendance_capacity($relayRegistration)===4,
    'One relay team can check in exactly four athletes');
check(live_attendance_capacity(['event_name'=>'วิ่งผลัด 4x100','category'=>''])===4,
    'Relay fallback handles an incomplete category');
$attendees=[
    ['name'=>'A','checkedAt'=>null],
    ['name'=>'B','checkedAt'=>'2026-11-09 09:04:00'],
    ['name'=>'C','checkedAt'=>'2026-11-09 09:03:00'],
    ['name'=>'D','checkedAt'=>'2026-11-09 09:05:00'],
    ['name'=>'E','checkedAt'=>'2026-11-09 09:06:00']
];
check(count(live_counted_attendees($attendees,1))===1,
    'A solo registration must count one confirmed person, not multiple alternatives');
check(live_counted_attendees($attendees,1)[0]['name']==='C',
    'Keep earliest check-in for legacy over-limit registrations');
check(count(live_counted_attendees($attendees,4))===4,
    'Relay check-in count cannot exceed four even with extra historic checks');
check(count(live_counted_attendees($attendees,0))===0,
    'Empty capacity must never contribute to public totals');
$tenSoloTeams=array_fill(0,10,$soloRegistration);
$totalSolo=array_sum(array_map('live_attendance_capacity',$tenSoloTeams));
check($totalSolo===10,'Ten school sprint entries should display 0/10, never 0/20');
$tenRelayTeams=array_fill(0,10,$relayRegistration);
check(array_sum(array_map('live_attendance_capacity',$tenRelayTeams))===40,
    'Ten relay entries require 40 check-ins');
echo "PASS capacity-based live totals for ten solo teams, four-person relays, and historic extras\n";


// Sport showcases photos of actual registered runners, without requiring
// reporting/check-in. Solo teams display one, relay teams display four.
$registeredSport=[
 ['athleteKey'=>'runner:11','name'=>'ตัวเลือก ก','photo'=>'https://example.org/a.jpg','checkedAt'=>null],
 ['athleteKey'=>'runner:12','name'=>'ตัวเลือก ข','photo'=>'https://example.org/b.jpg','checkedAt'=>'2026-11-09 13:00:00'],
 ['athleteKey'=>'runner:13','name'=>'ตัวเลือก ค','photo'=>'https://example.org/c.jpg','checkedAt'=>null],
 ['athleteKey'=>'runner:14','name'=>'ตัวเลือก ง','photo'=>'https://example.org/d.jpg','checkedAt'=>null],
 ['athleteKey'=>'runner:15','name'=>'ตัวเลือก จ','photo'=>'https://example.org/e.jpg','checkedAt'=>null]
];
$soloSport=live_sport_candidates($registeredSport,1);
check(count($soloSport)===1,'Sport solo must display exactly one real registered athlete');
check($soloSport[0]['athleteKey']==='runner:12',
    'Sport solo must prioritize the actual checked-in athlete over a reserve');
$relaySport=live_sport_candidates($registeredSport,4);
check(count($relaySport)===4,'Sport relay must fit four athlete portraits in one school');
check($relaySport[0]['athleteKey']==='runner:12',
    'Sport relay must put the confirmed participant first');
check($relaySport[0]['photo']==='https://example.org/b.jpg',
    'Sport must reuse an authentic stored athlete photo URL');
$unconfirmed=array_map(static fn($a)=>array_merge($a,['checkedAt'=>null]),$registeredSport);
check(live_sport_candidates($unconfirmed,1)[0]['athleteKey']==='runner:11',
    'Sport shows a registered candidate when none is checked in yet');
check(count(live_sport_candidates([] ,4))===0,
    'Empty team must not generate fictitious athlete portraits');
check(count($registeredSport)===5 && $registeredSport[0]['checkedAt']===null,
    'Sport display selection must not mutate registration or check-in data');
echo "PASS Sport showcase registered roster: 1 solo, 4 relay, authentic photos and no check-in mutation\n";


// --------------------------------------------------------------
// Official podium / qualifiers use ACTUAL current student portraits.
// A scored school is NOT evidence that one particular pupil won.
$rankedRace=[
    'eventName'=>'วิ่ง 80 เมตร (8 ปี ชาย)',
    'category'=>'วิ่งเดี่ยว','level'=>'ประถม',
    'round'=>'คัดเลือก','heat'=>1
];
$rankedSchool=['place'=>1,'school'=>'โรงเรียนบ้านนาวัง'];
$registrationsForCards=[
    ['id'=>501,'event_name'=>'วิ่ง 80 เมตร (8 ปี ชาย)',
     'category'=>'วิ่งเดี่ยว','level'=>'ประถม','school_name'=>'โรงเรียนบ้านนาวัง',
     'heat'=>1,'runner_ids'=>'101,102','runner_names'=>'ก,ข',
     'photos'=>'https://example.org/old101.jpg,https://example.org/old102.jpg'],
    ['id'=>502,'event_name'=>'วิ่ง 80 เมตร (8 ปี ชาย)',
     'category'=>'วิ่งเดี่ยว','level'=>'ประถม','school_name'=>'โรงเรียนบ้านนาวัง',
     'heat'=>2,'runner_ids'=>'103','runner_names'=>'ค',
     'photos'=>'https://example.org/old103.jpg'],
    ['id'=>503,'event_name'=>'วิ่ง 80 เมตร (8 ปี ชาย)',
     'category'=>'วิ่งเดี่ยว','level'=>'ประถม','school_name'=>'โรงเรียนบ้านทุ่งกราด',
     'heat'=>1,'runner_ids'=>'104','runner_names'=>'ง',
     'photos'=>'https://example.org/old104.jpg'],
    ['id'=>504,'event_name'=>'วิ่ง 80 เมตร (8 ปี หญิง)',
     'category'=>'วิ่งเดี่ยว','level'=>'ประถม','school_name'=>'โรงเรียนบ้านนาวัง',
     'heat'=>1,'runner_ids'=>'105','runner_names'=>'จ',
     'photos'=>'https://example.org/wrong-gender.jpg'],
];
$runnersForCards=[
  101=>['first_name'=>'เด็กชาย','last_name'=>'ตัวสำรอง','photo_url'=>'https://example.org/101.jpg'],
  102=>['first_name'=>'เด็กชาย','last_name'=>'ตัวจริง','photo_url'=>'https://example.org/102.jpg'],
  103=>['first_name'=>'เด็กชาย','last_name'=>'ฮีทสอง','photo_url'=>'https://example.org/103.jpg'],
  104=>['first_name'=>'เด็กชาย','last_name'=>'โรงเรียนอื่น','photo_url'=>'https://example.org/104.jpg'],
  105=>['first_name'=>'เด็กหญิง','last_name'=>'คนละรายการ','photo_url'=>'https://example.org/wrong.jpg']
];
$checksForCards=[501=>['runner:102'=>'2026-11-09 10:11:00']];
$photoCardAthletes=live_result_place_athletes(
    $rankedRace,$rankedSchool,$registrationsForCards,$runnersForCards,$checksForCards);
check(count($photoCardAthletes)===1,'Solo podium must feature only one current student');
check($photoCardAthletes[0]['photo']==='https://example.org/102.jpg',
    'Choose actual checked runner photo ahead of unused alternate/registration snapshot');
check($photoCardAthletes[0]['name']==='เด็กชาย ตัวจริง',
    'Display the exact student name from the registered runner');
check(!array_key_exists('runnerId',$photoCardAthletes[0]) &&
      !array_key_exists('athleteKey',$photoCardAthletes[0]) &&
      !array_key_exists('checkedAt',$photoCardAthletes[0]),
    'Do not expose runner identifiers or attendance history in podium API');
check(live_result_place_athletes(
    $rankedRace,['school'=>'โรงเรียนที่ไม่ได้ลงแข่ง'],
    $registrationsForCards,$runnersForCards,$checksForCards)===[],
    'Never borrow a photo from another ranked school');
$secondHeat=$rankedRace;$secondHeat['heat']=2;
$secondHeatAthletes=live_result_place_athletes(
    $secondHeat,$rankedSchool,$registrationsForCards,$runnersForCards,$checksForCards);
check($secondHeatAthletes[0]['photo']==='https://example.org/103.jpg',
    'A qualifying heat must use its OWN school entry, not another heat');
$finalSameRace=$rankedRace;$finalSameRace['round']='ชิงชนะเลิศ';$finalSameRace['heat']=9;
$finalAthletes=live_result_place_athletes(
    $finalSameRace,$rankedSchool,$registrationsForCards,$runnersForCards,$checksForCards);
check(count($finalAthletes)===1&&$finalAthletes[0]['photo']==='https://example.org/102.jpg',
    'A final may draw from qualifying registrations across heats');
$relayRace=['eventName'=>'วิ่งผลัด 4x50 เมตร (6 ปี หญิง)',
    'category'=>'วิ่งผลัด','level'=>'อนุบาล','round'=>'ชิงชนะเลิศ','heat'=>1];
$relayReg=[[
    'id'=>520,'event_name'=>$relayRace['eventName'],'category'=>'วิ่งผลัด',
    'level'=>'อนุบาล','school_name'=>'โรงเรียนบ้านนาวัง',
    'heat'=>1,'runner_ids'=>'201,202,203,204,205',
    'runner_names'=>'หนึ่ง,สอง,สาม,สี่,ห้า',
    'photos'=>'https://example.org/201.jpg,https://example.org/202.jpg,https://example.org/203.jpg,https://example.org/204.jpg,https://example.org/205.jpg'
]];
$relayPhotos=live_result_place_athletes($relayRace,$rankedSchool,$relayReg,[],[]);
check(count($relayPhotos)===4,'Relay FIFA player card must show exactly four real athletes');
check($relayPhotos[3]['photo']==='https://example.org/204.jpg',
    'Use individual registration images for the 2 by 2 relay card');
check(live_result_place_athletes($rankedRace,$rankedSchool,[],[],[])===[],
    'A score with no matching student registration must show a safe placeholder');
echo "PASS true athlete portraits: podium single, relay four, exact school/event/heat, no private IDs\n";

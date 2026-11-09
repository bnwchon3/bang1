<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/run/champion-history.php';
function assert_champion(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException($message);
}
assert_champion(
 champion_history_key('วิ่ง 80 เมตร (8 ปี ชาย)','วิ่งเดี่ยว','ประถม')===
 champion_history_key('วิ่ง80เมตร(8ปีชาย)','วิ่งเดี่ยว','ประถม'),
 'Only harmless whitespace variations in an event may match'
);
assert_champion(
 champion_history_key('วิ่ง 80 เมตร (8 ปี ชาย)','วิ่งเดี่ยว','ประถม')!==
 champion_history_key('วิ่ง 80 เมตร (8 ปี หญิง)','วิ่งเดี่ยว','ประถม'),
 'Never mix male/female event champion histories'
);
assert_champion(champion_history_school_key('โรงเรียนบ้านนาวัง')===
 champion_history_school_key('บ้านนาวัง'),'Normalize prefix for champion school');
$ranking=['1'=>'โรงเรียนบ้านนาวัง','2'=>'โรงเรียนบ้านทุ่งกราด'];
$valid=[
 ['batch_id'=>3,'ranking_json'=>json_encode($ranking,JSON_UNESCAPED_UNICODE),
  'season_label'=>'2568','captured_at'=>'2026-11-09 10:00:00']
];
$champion=champion_history_pick($valid);
assert_champion($champion!==null&&$champion['school']==='โรงเรียนบ้านนาวัง',
    'Confirmed former winner must come from archived rank 1, not arbitrary team order');
assert_champion($champion['season']==='2568','Season label must be retained');
$disagreement=$valid;
$disagreement[]=[
 'batch_id'=>3,'ranking_json'=>json_encode(['1'=>'โรงเรียนบ้านตะเคียนเตี้ย'],JSON_UNESCAPED_UNICODE),
 'season_label'=>'2568','captured_at'=>'2026-11-09 10:00:00'
];
assert_champion(champion_history_pick($disagreement)===null,
    'Conflicting first places in finals must not create an invented champion');
$older=$valid[0];$older['batch_id']=2;$older['season_label']='2567';
$newest=$valid[0];$newest['batch_id']=4;$newest['season_label']='2569';
$newest['ranking_json']=json_encode(['1'=>'โรงเรียนวัดสุทธารีย์บุญญาราม'],JSON_UNESCAPED_UNICODE);
assert_champion(champion_history_pick([$newest,$older])['season']==='2569',
    'Choose the most recent verified archive when multiple years exist');
assert_champion(champion_history_pick([])===null,
    'No verified historical final => no champion is claimed');
$source=[
 ['eventKey'=>'a','round'=>'คัดเลือก','rankings'=>['1'=>'ก']],
 ['eventKey'=>'a','round'=>'ชิงชนะเลิศ','rankings'=>['1'=>'ก','2'=>'ข']],
 ['eventKey'=>'b','round'=>'ชิงชนะเลิศ','rankings'=>['1'=>'ค']]
];
assert_champion(champion_history_count_finals($source)===2,
    'Only championship finals count; preliminary first place is not a champion');
assert_champion(champion_history_count_finals([$source[0]])===0,
    'No final must fail confirmation');
assert_champion(champion_history_rankings('{"1":"โรงเรียนบ้านนาวัง","2":"โรงเรียนบ้านทุ่งกราด"}')['1']==='โรงเรียนบ้านนาวัง',
    'Archived results must be parsed from saved ranking JSON');
assert_champion(champion_history_rankings('bad JSON')===[],
    'Never use invalid or unofficial rank data');
echo "PASS champion archive keys, stored winner, no fabricated champion, and final-only rules\n";

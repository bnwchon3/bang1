<?php
declare(strict_types=1);
/**
 * School score summary calculated from official FINAL results.
 *
 * Workbook reference "บันทึกผลวิ่งบาง1.xlsx", tabs "รายการชิง" and "รวมคะแนน":
 *  - individual race: 1st=5, 2nd=3, 3rd=2
 *  - relay race:      1st=7, 2nd=5, 3rd=3
 *  - group by age: 6, 8, 10, 12, 14, 16 years
 *  - totals = sum of age groups, competition rank = 1 + number of
 *    schools whose total is greater (Excel RANK descending).
 * Qualifying rounds and places 4..10 do not earn points.
 *
 * This endpoint is read-only and never alters stored results.
 */
function bang1_score_summary(): array {
    $ages=[6,8,10,12,14,16];
    $known=rows('SELECT id,name FROM schools ORDER BY id');
    $score=[];
    $schoolNames=[];
    foreach($known as $school) {
        $name=trim((string)($school['name']??''));
        if($name==='' || isset($score[$name]))continue;
        $schoolNames[]=$name;
        $score[$name]=array_fill_keys($ages,0);
    }

    $finalFixtures=0;
    $scoredFixtures=0;
    $unclassified=[];
    $unscored=[];
    $seen=[];

    foreach(staff_result_fixture_rows() as $fixture) {
        if(trim((string)($fixture['round']??''))!=='ชิงชนะเลิศ')continue;
        $finalFixtures++;
        $fixtureKey=(string)($fixture['fixtureKey']??'');
        if($fixtureKey!=='' && isset($seen[$fixtureKey]))continue;
        if($fixtureKey!=='')$seen[$fixtureKey]=true;

        $ranks=is_array($fixture['ranks']??null)?$fixture['ranks']:[];
        if(!$ranks)continue;
        $eventName=trim((string)($fixture['eventName']??''));
        if(!preg_match('/(\d{1,2})\s*ปี/u',$eventName,$match) ||
            !in_array((int)$match[1],$ages,true)) {
            $unclassified[]=$eventName;
            continue;
        }
        $age=(int)$match[1];
        $category=trim((string)($fixture['category']??''));
        if($category==='วิ่งผลัด'||str_contains($eventName,'ผลัด')) {
            $points=[1=>7,2=>5,3=>3];
        } elseif($category==='วิ่งเดี่ยว'||str_contains($eventName,'วิ่ง')) {
            $points=[1=>5,2=>3,3=>2];
        } else {
            $unclassified[]=$eventName;
            continue;
        }

        $scoredFixtures++;
        foreach($points as $place=>$value) {
            $school=trim((string)($ranks[(string)$place]??$ranks[$place]??''));
            if($school==='')continue;
            // A valid final result can refer to a school not in the master
            // registry (renamed or added after import). Keep its score visible.
            if(!isset($score[$school])) {
                $score[$school]=array_fill_keys($ages,0);
                $schoolNames[]=$school;
            }
            $score[$school][$age]+=$value;
        }
    }

    $totals=[];
    foreach($schoolNames as $name) {
        $totals[$name]=array_sum($score[$name]);
    }
    $hasScores=$scoredFixtures>0 && array_sum($totals)>0;
    $output=[];
    foreach($schoolNames as $name) {
        $total=$totals[$name];
        // Matches spreadsheet RANK: ties receive equal place.
        $rank=null;
        if($hasScores){
            $rank=1;
            foreach($totals as $other){
                if($other>$total)$rank++;
            }
        }
        $output[]=[
            'schoolName'=>$name,
            'byAge'=>$score[$name],
            'total'=>$total,
            'rank'=>$rank
        ];
    }
    return [
        'success'=>true,
        'ages'=>$ages,
        'schools'=>$output,
        'scoring'=>[
            'วิ่งเดี่ยว'=>[5,3,2],
            'วิ่งผลัด'=>[7,5,3]
        ],
        'finalFixtures'=>$finalFixtures,
        'scoredFixtures'=>$scoredFixtures,
        'excludedQualifiers'=>true,
        'unclassifiedEvents'=>array_values(array_unique($unclassified)),
        'generatedAt'=>date('c')
    ];
}
if($action==='score_summary'){
    json_out(bang1_score_summary());
}

<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/run/school-branding.php';
function test_flag(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException($message);
}
$logo='school-9-0123456789abcdef01234567.png';
test_flag(school_logo_url($logo)==='/bang1/run/uploads/school-logos/'.$logo,
    'A valid on-host logo must produce an absolute public path');
foreach(['../secrets.php','school-9-X.png','https://evil.com/x.png',
         "school-9-0123456789abcdef01234567.png?x=<script>",
         'school-9-0123456789abcdef01234567.svg'] as $bad){
    test_flag(school_logo_url($bad)===null,'Untrusted logo file or URL rejected: '.$bad);
}
$schools=[
    ['name'=>'โรงเรียนบ้านนาวัง','nickname'=>'บ้านนาวัง','logo_file'=>$logo],
    ['name'=>'โรงเรียนบ้านทุ่งกราด','nickname'=>'ทุ่งกราด','logo_file'=>null]
];
$map=school_logo_lookup($schools);
test_flag(school_logo_for($map,'โรงเรียนบ้านนาวัง')===school_logo_url($logo),
    'Full school name resolves to the correct emblem');
test_flag(school_logo_for($map,'บ้านนาวัง')===school_logo_url($logo),
    'School name without prefix should resolve the same emblem');
test_flag(school_logo_for($map,'ทุ่งกราด')===null,
    'Missing image must remain null for text fallback');
test_flag(school_logo_for($map,'โรงเรียนที่ไม่ได้อยู่ในฐาน')===null,
    'Never borrow another school crest when school is unknown');
test_flag(school_logo_for($map,'../secrets.php')===null,'Never map a file path to a school');
echo "PASS school emblems: canonical/alias names, safe URLs and missing-logo fallback\n";

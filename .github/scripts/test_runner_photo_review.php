<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/run/runner-photo-review.php';
function assert_review(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
}
$first=[
 'photo_url'=>'https://drive.google.com/file/d/first/view',
 'proof_url'=>'https://drive.google.com/file/d/idproof/view',
 'status'=>'รอตรวจสอบ','verified_photo_url'=>null
];
assert_review(runner_photo_review_mode($first)==='document','First photo must use ID proof');
assert_review(runner_photo_can_approve($first),'First verified photo plus ID proof can be approved');
$missing=$first;$missing['proof_url']='';
assert_review(!runner_photo_can_approve($missing),'No first approval without ID document');
$approved=$first;$approved['status']='ผ่านการตรวจสอบ';
$approved['verified_photo_url']=$approved['photo_url'];
assert_review(runner_photo_review_mode($approved)==='document','Existing approved photo is not a pending replacement');
$changed=$approved;$changed['status']='รอตรวจภาพอีกครั้ง';
$changed['photo_url']='https://drive.google.com/file/d/second/view';
$changed['proof_url']='';
assert_review(runner_photo_review_mode($changed)==='face','Subsequent photo must compare with approved reference');
assert_review(runner_photo_can_approve($changed),'Verified photo replacement may skip document re-review');
$unknown=$changed;$unknown['verified_photo_url']=null;
assert_review(runner_photo_review_mode($unknown)==='document','Legacy missing baseline cannot bypass ID proof');
assert_review(!runner_photo_can_approve($unknown),'No photo-only approval without trusted baseline');
$same=$changed;$same['photo_url']=$same['verified_photo_url'];
assert_review(runner_photo_review_mode($same)==='document','Same image is not a replacement');
$identityEdited=$changed;
$identityEdited['status']='รอตรวจสอบ';
assert_review(runner_photo_review_mode($identityEdited)==='document','Any identity correction must force document re-review even if photographs differ');
echo "PASS approved reference, identity re-review, face-only replacement, and legacy safety\n";

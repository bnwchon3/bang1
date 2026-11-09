<?php
declare(strict_types=1);
// Only called from admin-config.php after require_user() and require_admin().
if(!in_array((string)$action,[
 'admin_champions_list','admin_champions_preview',
 'admin_champions_archive','admin_champions_confirm'
],true))return;
require_once __DIR__.'/champion-history.php';
if($action==='admin_champions_list'){
    json_out(['success'=>true,'data'=>champion_history_list()]);
}
if($action==='admin_champions_preview'){
    $source=champion_history_source();
    $winners=[];
    foreach($source as $row){
        if($row['round']!=='ชิงชนะเลิศ'||empty($row['rankings']['1']))continue;
        $winners[]=[
            'eventName'=>$row['eventName'],
            'school'=>$row['rankings']['1'],
            'heat'=>$row['heat']
        ];
    }
    json_out(['success'=>true,'rows'=>count($source),
        'finalWinners'=>champion_history_count_finals($source),
        'samples'=>array_slice($winners,0,20),
        'archives'=>champion_history_list(),
        'warning'=>'อย่ายืนยันผลทดสอบว่าเป็นแชมป์เก่า ต้องตรวจสอบผลรอบชิงจริงก่อน']);
}
must_post();
admin_verify_password($u,$input['adminPassword']??null);
if($action==='admin_champions_archive'){
    $season=trim((string)($input['season']??''));
    if($season===''||mb_safe_length($season)>70||preg_match('/[<>\\x00-\\x1F]/u',$season)){
        json_out(['success'=>false,'message'=>'กรุณาระบุปีหรือชื่อการแข่งขัน (สูงสุด 70 ตัวอักษร)'],422);
    }
    if(trim((string)($input['confirmation']??''))!=='ยืนยันแชมป์เก่า'){
        json_out(['success'=>false,'message'=>'กรุณาพิมพ์คำยืนยันว่าเป็นผลการแข่งขันจริง'],422);
    }
    try{
        db()->beginTransaction();
        $result=champion_history_capture($u,$season,true,'admin');
        if(!$result['id'])throw new DomainException('ไม่มีผลการแข่งขันที่บันทึกไว้สำหรับจัดเก็บ');
        admin_security_log($u,'CHAMPIONS_ARCHIVED','ยืนยันประวัติผลการแข่งขัน '.$season.
            '; จำนวนผล '.$result['rows'].'; รายการแชมป์ '.$result['finals']);
        db()->commit();
        json_out(['success'=>true,'message'=>'เก็บประวัติแชมป์เก่าที่ Admin ยืนยันแล้ว',
            'archive'=>$result,'data'=>champion_history_list()]);
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        if($e instanceof DomainException)
            json_out(['success'=>false,'message'=>$e->getMessage()],422);
        error_log('Bang1 champion manual archive: '.$e->getMessage());
        json_out(['success'=>false,'message'=>'บันทึกประวัติแชมป์ไม่สำเร็จ กรุณาตรวจอีกครั้ง'],500);
    }
}
if($action==='admin_champions_confirm'){
    $id=(int)($input['archiveId']??0);
    if($id<=0||($input['confirmation']??'')!=='ยืนยันแชมป์เก่า')
        json_out(['success'=>false,'message'=>'กรุณายืนยันประวัติที่ต้องการรับรอง'],422);
    try{
        db()->beginTransaction();
        $batch=single('SELECT id,season_label,final_count,is_confirmed
            FROM race_history_batches WHERE id=? FOR UPDATE',[$id]);
        if(!$batch)throw new DomainException('ไม่พบประวัติที่เลือก');
        if((int)$batch['final_count']<1)
            throw new DomainException('ชุดประวัตินี้ไม่มีอันดับ 1 ในรอบชิง จึงรับรองเป็นแชมป์ไม่ได้');
        if(!(int)$batch['is_confirmed']){
            db()->prepare('UPDATE race_history_batches SET is_confirmed=1 WHERE id=?')->execute([$id]);
            admin_security_log($u,'CHAMPIONS_CONFIRMED',
                'รับรองประวัติที่บันทึกก่อนล้าง #'.$id.' '.$batch['season_label']);
        }
        db()->commit();
        json_out(['success'=>true,'message'=>'ยืนยันประวัติแชมป์เก่าแล้ว','data'=>champion_history_list()]);
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        if($e instanceof DomainException)
            json_out(['success'=>false,'message'=>$e->getMessage()],422);
        error_log('Bang1 champion confirmation: '.$e->getMessage());
        json_out(['success'=>false,'message'=>'ยืนยันประวัติไม่สำเร็จ'],500);
    }
}
function mb_safe_length(string $value):int {
    return function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):strlen($value);
}

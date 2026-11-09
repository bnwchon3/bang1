<?php
declare(strict_types=1);
/** Normalize bridge transport errors without leaking tokens, folder IDs or URLs. */
function bang1_drive_upload_failure(int $http,int $curlErrno,mixed $payload):array {
    $remote=is_array($payload)?(string)($payload['message']??''):'';
    [$code,$message]=match(true){
        $curlErrno===28=>['DRIVE_BRIDGE_TIMEOUT','การเชื่อมต่อ Google Drive ใช้เวลานานเกินไป กรุณาลองใหม่'],
        $curlErrno!==0=>['DRIVE_BRIDGE_CONNECTION','โฮสต์ไม่สามารถเชื่อมต่อ Google Drive Bridge ได้ กรุณาแจ้งผู้ดูแลระบบ'],
        $remote==='Unauthorized'=>['DRIVE_BRIDGE_TOKEN','การยืนยันตัวตนของ Google Drive Bridge ไม่ผ่าน กรุณาให้ผู้ดูแลตรวจรหัสเชื่อมต่อ'],
        $remote==='Folder not configured'=>['DRIVE_FOLDER_NOT_CONFIGURED','โฟลเดอร์รูปนักเรียนบน Google Drive ยังไม่ได้กำหนดค่า'],
        $remote==='Upload failed'=>['DRIVE_UPLOAD_REJECTED','Google Drive ไม่สามารถสร้างไฟล์ได้ กรุณาตรวจสอบสิทธิ์โฟลเดอร์และการทำงานของ Apps Script'],
        $http===401||$http===403=>['DRIVE_BRIDGE_ACCESS','Google Apps Script ปฏิเสธการเข้าถึงจากเว็บไซต์ กรุณาตรวจการเผยแพร่ Web App'],
        !is_array($payload)=>['DRIVE_BRIDGE_NON_JSON','Google Apps Script ตอบกลับเป็นหน้าเว็บแทนข้อมูลระบบ กรุณาตรวจสิทธิ์ Web App และ URL Deployment'],
        $http!==200=>['DRIVE_BRIDGE_HTTP','Google Drive Bridge ตอบกลับสถานะ HTTP '.$http.' กรุณาแจ้งผู้ดูแล'],
        is_array($payload)&&!empty($payload['success'])=>['DRIVE_BRIDGE_NO_URL','Google Drive ไม่ส่งลิงก์ของไฟล์ที่อัปโหลดกลับมา'],
        default=>['DRIVE_BRIDGE_FAILURE','Google Drive Bridge ไม่สามารถรับไฟล์ได้ กรุณาแจ้งผู้ดูแลระบบ'],
    };
    return ['code'=>$code,'message'=>$message];
}

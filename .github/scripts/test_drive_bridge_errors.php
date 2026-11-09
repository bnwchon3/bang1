<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/run/drive-bridge-error.php';
function check_err(bool $ok,string $desc):void {if(!$ok)throw new RuntimeException($desc);}
check_err(bang1_drive_upload_failure(0,28,null)['code']==='DRIVE_BRIDGE_TIMEOUT','Timeout');
check_err(bang1_drive_upload_failure(0,7,null)['code']==='DRIVE_BRIDGE_CONNECTION','Transport failure');
check_err(bang1_drive_upload_failure(200,0,['success'=>false,'message'=>'Unauthorized'])['code']==='DRIVE_BRIDGE_TOKEN','Token mismatch');
check_err(bang1_drive_upload_failure(200,0,['success'=>false,'message'=>'Upload failed'])['code']==='DRIVE_UPLOAD_REJECTED','Google Drive write error');
check_err(bang1_drive_upload_failure(200,0,null)['code']==='DRIVE_BRIDGE_NON_JSON','Google login HTML');
check_err(bang1_drive_upload_failure(200,0,['success'=>true])['code']==='DRIVE_BRIDGE_NO_URL','Missing Drive URL');
echo "PASS Drive upload transport and safe error classification\n";

<?php
declare(strict_types=1);
// Deploy-only, non-mutating health check for the actual PHP host -> Google Apps
// Script bridge. Never returns tokens, deployment URLs, IDs or response bodies.
require __DIR__.'/../inc/bootstrap.php';
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'
    || config('deploy_token')===''
    || !hash_equals(config('deploy_token'),(string)($_SERVER['HTTP_X_DEPLOY_TOKEN']??''))){
    json_out(['success'=>false,'message'=>'Forbidden'],403);
}
header('Cache-Control: no-store, private');
$bridge=config('drive_bridge_url');
$token=config('drive_bridge_token');
if(!$bridge||!$token){
    json_out(['success'=>false,'code'=>'BRIDGE_NOT_CONFIGURED'],200);
}
if(!preg_match('~^https://script\.google\.com/macros/s/[^/]+/exec$~D',$bridge)){
    json_out(['success'=>false,'code'=>'BRIDGE_URL_INVALID'],200);
}
if(!function_exists('curl_init')){
    json_out(['success'=>false,'code'=>'HOST_CURL_UNAVAILABLE'],200);
}
// An intentionally invalid runner ID makes it impossible to upload or
// overwrite an image, but still exercises Apps Script authentication.
$probe=json_encode(['token'=>$token,'runnerId'=>'0','type'=>'photo',
    'mimeType'=>'image/png','base64Data'=>''],JSON_UNESCAPED_UNICODE);
$ch=curl_init($bridge);
curl_setopt_array($ch,[
    CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$probe,
    CURLOPT_HTTPHEADER=>['Content-Type: text/plain;charset=utf-8'],
    CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,
    CURLOPT_CONNECTTIMEOUT=>12,CURLOPT_TIMEOUT=>35,CURLOPT_MAXREDIRS=>5,
    CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS
]);
$result=curl_exec($ch);
$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
$errno=curl_errno($ch);
$error=curl_error($ch);
curl_close($ch);
if($result===false){
    $code=$errno===28?'BRIDGE_TIMEOUT':'BRIDGE_NETWORK_ERROR';
    error_log('Bang1 Drive bridge probe: '.$code.' curl_errno='.$errno);
    json_out(['success'=>false,'code'=>$code,'httpStatus'=>$http],200);
}
$body=json_decode((string)$result,true);
if(!is_array($body)){
    $code=$http===401||$http===403?'BRIDGE_ACCESS_DENIED':'BRIDGE_NON_JSON_RESPONSE';
    error_log('Bang1 Drive bridge probe: '.$code.' http='.$http);
    json_out(['success'=>false,'code'=>$code,'httpStatus'=>$http],200);
}
$message=(string)($body['message']??'');
if($http===200&&$message==='Invalid runner ID'){
    json_out(['success'=>true,'code'=>'BRIDGE_READY','httpStatus'=>$http],200);
}
$code=match(true){
    $message==='Unauthorized'=>'BRIDGE_TOKEN_REJECTED',
    $message==='Folder not configured'=>'DRIVE_FOLDER_MISSING',
    $message==='Upload failed'=>'BRIDGE_EXECUTION_FAILED',
    $http===401||$http===403=>'BRIDGE_ACCESS_DENIED',
    $http!==200=>'BRIDGE_HTTP_ERROR',
    default=>'BRIDGE_UNEXPECTED_RESPONSE',
};
error_log('Bang1 Drive bridge probe: '.$code.' http='.$http);
json_out(['success'=>false,'code'=>$code,'httpStatus'=>$http],200);

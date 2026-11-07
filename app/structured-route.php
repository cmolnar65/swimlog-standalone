<?php
// Included only after the standard host, HTTPS and WordPress cookie checks.
use SwimlogStandalone\StructuredWorkouts as SW;
require_once __DIR__.'/StructuredWorkouts.php';
if(!$owner){http_response_code(401);exit('Sign in again to open structured workouts.');}
$ready=SW::ready($owner);if(is_wp_error($ready)){http_response_code((int)($ready->get_error_data()['status']??403));exit(esc_html($ready->get_error_message()));}
if($action==='structured_asset') {
    if(!in_array($method,array('GET','HEAD'),true)){http_response_code(405);exit('Read-only asset.');}
    $kind=$_GET['kind']??'';if(!is_string($kind)||!in_array($kind,array('js','css'),true)){http_response_code(404);exit('Unknown asset.');}
    $path=SWIMLOG_EVALUATION_DIR.'assets/'.$kind.'/structured-workout-editor.'.$kind;
    if(!is_readable($path)){http_response_code(503);exit('The plugin editor asset is unavailable.');}
    header('Content-Type: '.($kind==='js'?'application/javascript':'text/css').'; charset=UTF-8');if($method!=='HEAD')readfile($path);exit;
}
if($action==='structured_download') {
    if(!in_array($method,array('GET','HEAD'),true)){http_response_code(405);exit('Read-only download.');}
    $out=SW::download($owner,$_GET['id']??null,$_GET['format']??null,$_GET['nonce']??null);
    if(is_wp_error($out)){http_response_code((int)($out->get_error_data()['status']??422));exit(esc_html($out->get_error_message()));}
    header('Content-Type: '.$out['type']);header('Content-Disposition: attachment; filename="'.$out['filename'].'"');if($method!=='HEAD')echo $out['bytes'];exit;
}
if($action==='structured_write') {
    if($method!=='POST'){http_response_code(405);header('Allow: POST');exit('POST required.');}
    $out=SW::submit($owner,wp_unslash($_POST),$_SERVER['HTTP_ORIGIN']??'',$config['app_origin']);
    if(is_wp_error($out)){http_response_code((int)($out->get_error_data()['status']??422));$structureError=$out;}
    elseif(isset($out['url'])){header('Location: '.$config['app_origin'].$out['url'],true,303);exit;}
    else {$structureForm=$out['form'];http_response_code(422);}
} elseif($action!==''||!in_array($method,array('GET','HEAD'),true)){http_response_code(404);exit('Not found.');}
$screen='structures';$signedIn=true;$returnUrl=SW::url();
if(!isset($structureError)&&!isset($structureForm)) {
    $intents=0;foreach(array('edit_structure','structure','plan','workout','edit_plan','plan_action','match_plan','event','edit_event','event_result','day')as$key)if(isset($_GET[$key]))++$intents;
    if($intents>1||($intents&&!isset($_GET['edit_structure'])&&!isset($_GET['structure']))){http_response_code(400);exit('Choose one structured workout action.');}
    if(isset($_GET['edit_structure'])){$structureForm=SW::editor($owner,$_GET['edit_structure']);if(is_wp_error($structureForm)){$structureError=$structureForm;unset($structureForm);}}
    elseif(isset($_GET['structure'])){$structureDetail=SW::item($owner,$_GET['structure']);if(is_wp_error($structureDetail)){$structureError=$structureDetail;unset($structureDetail);}}
    else $structureLibrary=SW::library($owner,$_GET);
}
if(isset($structureError))http_response_code((int)($structureError->get_error_data()['status']??400));
require __DIR__.'/home.php';
exit;

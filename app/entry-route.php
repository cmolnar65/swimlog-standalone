<?php
use SwimlogStandalone\WorkoutEntry as WE;
require_once __DIR__.'/WorkoutEntry.php';
$screen=in_array($action,array('manual_save','manual_update'),true)?'manual':($action==='workout_upload'?'import':($_GET['screen']??''));
if(!in_array($screen,array('manual','import'),true)){http_response_code(400);exit('Unknown workout entry screen.');}
if(!$owner){http_response_code(401);exit('Sign in again to add or import a workout.');}
$ready=WE::ready($owner,$screen);if(is_wp_error($ready)){http_response_code((int)($ready->get_error_data()['status']??403));exit(esc_html($ready->get_error_message()));}
$signedIn=true;$returnUrl='/?screen='.$screen;$entryError=null;$entryOutcomes=array();
if(in_array($action,array('manual_save','manual_update','workout_upload'),true)) {
    if($method!=='POST'){http_response_code(405);header('Allow: POST');exit('POST required.');}
    if(!$_POST&&(int)($_SERVER['CONTENT_LENGTH']??0)>0)$out=WE::error('The request exceeded the server’s PHP upload limit. Select fewer or smaller files, or ask the administrator to review PHP upload limits.',413);
    elseif($action==='manual_update'){require_once __DIR__.'/ManualEditor.php';$out=\SwimlogStandalone\ManualEditor::submit($owner,wp_unslash($_POST),$_FILES,$_SERVER['HTTP_ORIGIN']??'',$config['app_origin']);}
    else $out=WE::submit($owner,$screen,wp_unslash($_POST),$_FILES,$_SERVER['HTTP_ORIGIN']??'',$config['app_origin']);
    if(is_wp_error($out)&&$action==='manual_update'){http_response_code((int)($out->get_error_data()['status']??422));exit(esc_html($out->get_error_message()).' Reload the manual workout before trying again.');}
    if(is_wp_error($out)){$entryError=$out;http_response_code((int)($out->get_error_data()['status']??422));}
    elseif(isset($out['url'])){header('Location: '.$config['app_origin'].$out['url'],true,303);exit;}
    else{$entryForm=$out['form'];http_response_code(422);}
} elseif($action!==''||!in_array($method,array('GET','HEAD'),true)){http_response_code(404);exit('Not found.');}
foreach(array('plan','workout','edit_plan','event','edit_event','structure','edit_structure','day')as$key)if(isset($_GET[$key])){http_response_code(400);exit('Choose one workout entry action.');}
$entryLocations=WE::locations($owner);
if($screen==='manual') {
    if(!isset($entryForm)){if(isset($_GET['edit_manual'])){require_once __DIR__.'/ManualEditor.php';$entryForm=\SwimlogStandalone\ManualEditor::open($owner,$_GET['edit_manual']);if(is_wp_error($entryForm)){http_response_code((int)($entryForm->get_error_data()['status']??422));exit(esc_html($entryForm->get_error_message()));}}else $entryForm=WE::form($owner);}
} else {
    $entryToken=WE::token($owner,'import');$receipt=WE::receipt($owner,$_GET['receipt']??null);
    if($receipt&&$receipt['mode']==='import'&&!empty($receipt['done']))$entryOutcomes=$receipt['outcomes']??array();
    $entryReviews=array();$allPending=\SwimLogEvaluation\Importer::pending_all_for_user($owner);
    $page=$_GET['review_page']??'1';if(!is_string($page)||!preg_match('/^[1-9][0-9]{0,5}$/D',$page)){http_response_code(400);exit('Choose a valid import review page.');}
    $entryPages=max(1,(int)ceil(count($allPending)/10));$entryPage=min((int)$page,$entryPages);
    foreach(array_slice($allPending,($entryPage-1)*10,10)as$row){$review=WE::review($owner,(int)$row->id);$entryReviews[]=array('id'=>(int)$row->id,'review'=>$review,'token'=>WE::token($owner,'import'));}
}
require __DIR__.'/home.php';exit;

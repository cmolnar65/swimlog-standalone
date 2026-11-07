<?php
use SwimlogStandalone\Settings as S;
use SwimlogStandalone\Garmin as G;
require_once __DIR__.'/Settings.php';require_once __DIR__.'/Garmin.php';
if(!$owner){http_response_code(401);exit('Sign in to open settings or Garmin tools.');}
$configurationError=null;$configurationResult=array();$screen=$_GET['screen']??'garmin_setup';
$settingsOps=array('swimmer_save'=>'swimmer','location_save'=>'locations','location_delete'=>'locations','key_save'=>'garmin_setup','key_test'=>'garmin_setup','key_remove'=>'garmin_setup','activity_sync'=>'garmin_setup','measurement_sync'=>'garmin_setup');
if($action==='settings_write'){$op=$_POST['operation']??'';if(!is_string($op)){http_response_code(400);exit('Invalid settings operation.');}$screen=$settingsOps[$op]??'garmin_setup';}
if($action==='garmin_write'){$op=$_POST['operation']??'';$screen=is_string($op)&&strpos($op,'activities_')===0?'garmin_activities':($op==='measurements_import'?'garmin_measurements':'garmin_history');}
if(!is_string($screen)||!in_array($screen,array('swimmer','locations','garmin_setup','garmin_activities','garmin_measurements','garmin_history'),true)){http_response_code(400);exit('Unknown configuration screen.');}
$garminScreen=strpos($screen,'garmin_')===0&&$screen!=='garmin_setup';$ok=$garminScreen?G::ready($owner,$screen):S::ready($owner);
if($screen==='locations'&&!current_user_can('swimlog_manage_own_locations'))$ok=S::error('Location access denied.',403);
if(is_wp_error($ok)){http_response_code((int)($ok->get_error_data()['status']??403));exit(esc_html($ok->get_error_message()));}
if(in_array($action,array('settings_write','garmin_write'),true)){
    if($method!=='POST'){http_response_code(405);header('Allow: POST');exit('POST required.');}
    $result=$action==='settings_write'?S::submit($owner,wp_unslash($_POST),$_SERVER['HTTP_ORIGIN']??'',$config['app_origin']):G::submit($owner,wp_unslash($_POST),$_SERVER['HTTP_ORIGIN']??'',$config['app_origin']);
    if(is_wp_error($result)){$configurationError=$result;http_response_code((int)($result->get_error_data()['status']??422));}
    elseif(isset($result['url'])){header('Location: '.$config['app_origin'].$result['url'],true,303);exit;}else $configurationResult=$result;
}elseif($action!==''||!in_array($method,array('GET','HEAD'),true)){http_response_code(404);exit('Not found.');}
foreach(array('plan','workout','event','edit_plan','edit_event','structure','edit_structure','day')as$key)if(isset($_GET[$key])){http_response_code(400);exit('Choose one screen.');}
$signedIn=true;$returnUrl='/?screen='.$screen;
$configurationLocation=null;$configurationLocationId='new';
if($screen==='locations'&&isset($_GET['edit_location'])){$configurationLocationId=$_GET['edit_location'];$configurationLocation=S::location($owner,$configurationLocationId);if(is_wp_error($configurationLocation)){$configurationError=$configurationLocation;$configurationLocation=null;$configurationLocationId='new';http_response_code(404);}}
require __DIR__.'/home.php';exit;

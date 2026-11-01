<?php
if($method!=='POST'){header('Allow: POST');http_response_code(405);exit('POST required.');}
if(!$owner){http_response_code(401);exit('Sign in again before changing an association.');}
if(($action==='plan_delete')!==(($_POST['operation']??null)==='delete')){http_response_code(400);exit('Choose a valid operation.');}
require_once __DIR__.'/WorkoutAssociations.php';
$out=\SwimlogStandalone\WorkoutAssociations::submit($owner,wp_unslash($_POST),$_SERVER['HTTP_ORIGIN']??'',$config['app_origin']);
if(is_wp_error($out)){http_response_code((int)($out->get_error_data()['status']??400));exit(esc_html($out->get_error_message()));}
header('Location: '.$config['app_origin'].$out['url'],true,303);exit;

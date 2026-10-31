<?php
use SwimlogStandalone\WorkoutEditor as Editor;
require_once __DIR__.'/WorkoutEditor.php';
if(!$owner){http_response_code(401);exit('Sign in to edit your workout.');}
$id=in_array($action,array('workout_write','workout_public_link'),true)?($_POST['workout_id']??$_GET['workout_id']??null):($_GET['edit_workout']??$_GET['workout_id']??null);
$editorItem=Editor::load($owner,$id);$editorError=null;
if(is_wp_error($editorItem)){http_response_code((int)($editorItem->get_error_data()['status']??404));exit(esc_html($editorItem->get_error_message()));}
if($action==='workout_public_link'){
    if($method!=='POST'){http_response_code(405);header('Allow: POST');exit('POST required.');}
    $input=wp_unslash($_POST);
    if(array_diff(array_keys($input),array('workout_id','nonce','revision'))||($_SERVER['HTTP_ORIGIN']??'')!==$config['app_origin']||!is_string($input['nonce']??null)||!wp_verify_nonce($input['nonce'],'standalone_workout_link_'.$id))wp_send_json(array('error'=>'Reload the workout before copying its public link.'),403);
    $state=$editorItem['visibility'];
    if(!is_string($input['revision']??null)||!hash_equals($editorItem['revision'],$input['revision']))wp_send_json(array('error'=>'Sharing changed. Reload and review the current settings.'),409);
    if(is_wp_error($state)||!$state['effective_public'])wp_send_json(array('error'=>'This workout is not currently public. Reload and review sharing.'),403);
    wp_send_json(array('url'=>\SwimlogStandalone\Sharing::url($config['app_origin'],$id)),200);
}
if($action==='workout_photo'){
    if(!in_array($method,array('GET','HEAD'),true)){http_response_code(405);exit('Read-only photo.');}
    $slot=$_GET['slot']??'';if(!is_string($slot)||!in_array($slot,array('0','1','2'),true)||!isset($editorItem['photos'][(int)$slot])){http_response_code(404);exit;}
    $photo=$editorItem['photos'][(int)$slot];$path=get_attached_file($photo);$root=wp_upload_dir()['basedir'];$real=$path?realpath($path):false;$base=realpath($root);
    if(!$real||!$base||strpos($real,$base.DIRECTORY_SEPARATOR)!==0||!is_file($real)||!in_array(get_post_mime_type($photo),array('image/jpeg','image/png','image/gif','image/webp'),true)||(int)get_post_field('post_author',$photo)!==$owner){http_response_code(404);exit;}
    header('Content-Type: '.get_post_mime_type($photo));header('Content-Length: '.filesize($real));if($method!=='HEAD')readfile($real);exit;
}
if($action==='workout_write'){
    if($method!=='POST'){http_response_code(405);header('Allow: POST');exit('POST required.');}
    if(!$_POST&&(int)($_SERVER['CONTENT_LENGTH']??0)>0)$out=Editor::error('This upload exceeded the PHP request limit. Select smaller photos.',413);
    else $out=Editor::submit($owner,wp_unslash($_POST),$_FILES,$_SERVER['HTTP_ORIGIN']??'',$config['app_origin']);
    if(is_wp_error($out)){$editorError=$out;http_response_code((int)($out->get_error_data()['status']??422));}
    else{header('Location: '.$config['app_origin'].$out['url'],true,303);exit;}
}elseif($action!==''||!in_array($method,array('GET','HEAD'),true)){http_response_code(404);exit;}
$editorItem=Editor::load($owner,$id);if(is_wp_error($editorItem)){echo '<p role="alert">'.esc_html($editorError?$editorError->get_error_message():'Workout no longer available.').'</p><a href="/?screen=log">Workout Log</a>';exit;}
$conversionPreview=isset($_GET['convert_workout'])?Editor::preview($id,$owner):null;
$screen='workout_editor';$signedIn=true;$returnUrl='/?screen=log';
require __DIR__.'/home.php';exit;

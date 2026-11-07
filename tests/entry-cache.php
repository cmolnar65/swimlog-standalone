<?php
// Separate process: exercise the form boundary with an authoritative external cache.
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable test flag required.');
require_once dirname(__DIR__).'/app/Auth.php';require_once dirname(__DIR__).'/app/WorkoutEntry.php';
use SwimlogStandalone\WorkoutEntry as WE;
$owner=(int)get_user_by('login','synthetic_entry')->ID;wp_set_current_user($owner);$_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie($owner,time()+3600,'logged_in');
if(WE::ready($owner,'import')!==true)throw new RuntimeException('Cache test owner unavailable.');
$original=wp_using_ext_object_cache();wp_using_ext_object_cache(true);
try{
    $token=WE::token($owner,'import');$nonce=wp_create_nonce('standalone_entry_import_'.$token);
    $input=array('token'=>$token,'nonce'=>$nonce,'location_id'=>'0');$origin='https://swimlog.example.test';
    // Empty uploads must reach upload validation without destroying the form receipt.
    $result=WE::submit($owner,'import',$input,array(),$origin,$origin);
    if(!is_wp_error($result)||$result->get_error_message()!=='Choose at least one workout file.'||!WE::receipt($owner,$token))throw new RuntimeException('External-cache form receipt was destroyed.');
    // Completed receipts retain replay protection under the owner lock.
    $done=array('mode'=>'import','done'=>true,'url'=>'/?screen=import&receipt='.$token);
    $key='standalone_entry_'.hash('sha256',$owner.'|'.wp_get_session_token().'|'.$token);
    set_transient($key,$done,7200);
    $result=WE::submit($owner,'import',$input,array(),$origin,$origin);
    if(!isset($result['url'])||$result['url']!==$done['url'])throw new RuntimeException('External-cache replay receipt lost.');
    if(WE::receipt((int)get_user_by('login','synthetic_swimmer')->ID,$token)!==false)throw new RuntimeException('Receipt owner isolation failed.');
    echo "External-cache entry receipt regression passed: validation, preservation, completed replay and owner isolation.\n";
}finally{wp_using_ext_object_cache($original);}

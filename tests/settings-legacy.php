<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable test flag required.');
require_once dirname(__DIR__).'/app/Auth.php';require_once dirname(__DIR__).'/app/Settings.php';require_once dirname(__DIR__).'/app/Garmin.php';require_once __DIR__.'/settings-http-mock.php';
use SwimlogStandalone\Settings as S;use SwimlogStandalone\Garmin as G;use SwimLogEvaluation\Garmin_Personal_API as API;
$owner=(int)get_user_by('login','synthetic_settings')->ID;wp_set_current_user($owner);$_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie($owner,time()+3600,'logged_in');$checks=0;
$check=static function($ok)use(&$checks){++$checks;if(!$ok)throw new RuntimeException('Legacy Garmin assertion '.$checks.' failed.');};
$check(S::ready($owner)===true);
$check(!is_callable(array(API::class,'connection_ready'))&&!defined(API::class.'::TESTED_KEY'));
API::remove_key($owner);delete_user_meta($owner,S::VERIFIED_KEY);$check(!S::verified($owner));
function legacy_submit($owner,$op,$extra=array()){return S::submit($owner,array('operation'=>$op,'nonce'=>wp_create_nonce('standalone_settings_'.$op.'_new'),'revision'=>S::revision($owner,'garmin_setup'))+$extra,'https://swimlog.example.test','https://swimlog.example.test');}
$check(isset(legacy_submit($owner,'key_save',array('api_key'=>'synthetic-settings-key'))['url'])&&!S::verified($owner));
$check(is_wp_error(G::ready($owner,'garmin_activities')));
$check(isset(legacy_submit($owner,'key_test')['url'])&&S::verified($owner));
$marker=get_user_meta($owner,S::VERIFIED_KEY,true);$check(is_string($marker)&&strlen($marker)===64&&strpos($marker,'synthetic')===false);
API::save_key($owner,'replacement-synthetic-key');$check(!S::verified($owner));
$check(isset(legacy_submit($owner,'key_test')['url'])&&S::verified($owner));
$check(isset(legacy_submit($owner,'key_save',array('api_key'=>'invalid-synthetic-key'))['url'])&&!S::verified($owner));
$check(is_wp_error(legacy_submit($owner,'key_test'))&&!S::verified($owner)&&get_user_meta($owner,S::VERIFIED_KEY,true)==='');
$check(isset(legacy_submit($owner,'key_remove',array('confirm_remove'=>'1'))['url'])&&!S::verified($owner));
echo 'Legacy Garmin verification compatibility passed: '.$checks." assertions.\n";

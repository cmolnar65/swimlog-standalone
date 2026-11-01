<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable test flag required.');
require_once dirname(__DIR__).'/app/Auth.php';require_once dirname(__DIR__).'/app/Settings.php';require_once dirname(__DIR__).'/app/Garmin.php';require_once dirname(__DIR__).'/tests/settings-http-mock.php';
use SwimlogStandalone\Settings as S;use SwimlogStandalone\Garmin as G;use SwimLogEvaluation\Garmin_Personal_API as API;
$user=get_user_by('login','synthetic_settings');$owner=(int)$user->ID;wp_set_current_user($owner);$_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie($owner,time()+3600,'logged_in');$checks=0;
$check=static function($v)use(&$checks){++$checks;if(!$v)throw new RuntimeException('Settings assertion '.$checks.' failed.');};
function settings_input($owner,$op,$screen,$id='new'){return array('operation'=>$op,'nonce'=>wp_create_nonce('standalone_settings_'.$op.'_'.$id),'revision'=>S::revision($owner,$screen,$id));}
function settings_submit($owner,$input){return S::submit($owner,$input,'https://swimlog.example.test','https://swimlog.example.test');}
$check(S::ready($owner)===true);API::remove_key($owner);$check(!S::verified($owner)&&is_wp_error(G::ready($owner,'garmin_activities')));
update_user_meta($owner,'swimlog_public_routes','1');
$unitInput=array('swim_course'=>'yd','running'=>'mi','walking'=>'km','cycling'=>'mi','weight'=>'lb','show_secondary'=>'1');
$input=settings_input($owner,'swimmer_save','swimmer')+array('birthdate'=>'1965-01-02','calendar_first_day'=>'monday','training_volume_months'=>'6')+$unitInput;
$post=$_POST;$request=$_REQUEST;$r=settings_submit($owner,$input);$check(isset($r['url'])&&$_POST===$post&&$_REQUEST===$request);$prefs=S::preferences($owner);$check($prefs['birthdate']==='1965-01-02'&&$prefs['calendar_first_day']==='monday'&&$prefs['public_results']==='0');
$check($prefs['swim_course']==='yd'&&$prefs['running']==='mi'&&$prefs['walking']==='km'&&$prefs['cycling']==='mi'&&$prefs['weight']==='lb'&&$prefs['show_secondary']===true);
$expectedUnits=$unitInput;$expectedUnits['show_secondary']=true;$otherUnits=\SwimLogEvaluation\Unit_Preferences::get((int)get_user_by('login','synthetic_swimmer')->ID);
$check(\SwimLogEvaluation\Unit_Preferences::get($owner)===$expectedUnits && $otherUnits!==$expectedUnits);
$check(get_user_meta($owner,'swimlog_public_routes',true)==='1');
$check(is_wp_error(settings_submit($owner,$input)));
$unchecked=settings_input($owner,'swimmer_save','swimmer')+array('birthdate'=>'1965-01-02','calendar_first_day'=>'monday','training_volume_months'=>'6')+array_diff_key($unitInput,array('show_secondary'=>true));
$r=settings_submit($owner,$unchecked);$check(isset($r['url'])&&S::preferences($owner)['show_secondary']===false);$prefs=S::preferences($owner);
$bad=settings_input($owner,'swimmer_save','swimmer')+array('birthdate'=>'2026-02-30','calendar_first_day'=>'saturday','training_volume_months'=>'4','public_results'=>'1')+array_replace($unitInput,array('swim_course'=>'feet'));
$check(is_wp_error(settings_submit($owner,$bad))&&S::preferences($owner)===$prefs);
$bad=settings_input($owner,'swimmer_save','swimmer')+array('birthdate'=>'2026-02-30','calendar_first_day'=>'saturday','training_volume_months'=>'4','public_results'=>'1')+$unitInput;
$check(is_wp_error(settings_submit($owner,$bad))&&S::preferences($owner)===$prefs);
$loc=settings_input($owner,'location_save','locations')+array('location_id'=>'new','name'=>'Synthetic settings pool','pool_length'=>'25','pool_unit'=>'yd','latitude'=>'40.0','longitude'=>'-105.0','notes'=>'PRIVATE_LOCATION_SETTING_CANARY');
$r=settings_submit($owner,$loc);$check(isset($r['url']));parse_str(parse_url($r['url'],PHP_URL_QUERY),$query);$id=$query['edit_location'];$row=\SwimLogEvaluation\Location::get_for_user($id,$owner);$check($row&&(float)$row->pool_length_m===22.86);
$other=(int)get_user_by('login','synthetic_swimmer')->ID;$check(is_wp_error(S::location($other,$id)));
$bad=settings_input($owner,'location_save','locations',$id)+array_replace(array_intersect_key($loc,array_flip(S::LOCATION)),array('latitude'=>'nan'))+array('location_id'=>$id);$check(is_wp_error(settings_submit($owner,$bad)));
$key=settings_input($owner,'key_save','garmin_setup')+array('api_key'=>'synthetic-settings-key');$check(is_wp_error(S::submit($owner,$key,'https://evil.example.test','https://swimlog.example.test')));
$check(is_wp_error(settings_submit($owner,$key+array('user_id'=>(string)$other))));
$check(isset(settings_submit($owner,$key)['url'])&&API::is_configured($owner)&&!S::verified($owner));
$check(get_user_meta($owner,API::META_KEY,true)!=='synthetic-settings-key');
$check(isset(settings_submit($owner,settings_input($owner,'key_test','garmin_setup'))['url'])&&S::verified($owner));
$sync=settings_input($owner,'activity_sync','garmin_setup')+array('enabled'=>'1','types'=>array('lap_swimming','open_water_swimming'));$check(isset(settings_submit($owner,$sync)['url'])&&wp_next_scheduled('swimlog_garmin_scheduled_sync',array($owner)));
$measurement=settings_input($owner,'measurement_sync','garmin_setup')+array('categories'=>array('weight'));$check(isset(settings_submit($owner,$measurement)['url'])&&\SwimLogEvaluation\Garmin_Measurement_Sync::categories($owner)===array('weight'));
$today=wp_date('Y-m-d');$browse=array('operation'=>'activities_browse','nonce'=>wp_create_nonce('standalone_activities_browse'),'start'=>$today,'end'=>$today,'types'=>array('lap_swimming'));$r=G::submit($owner,$browse,'https://swimlog.example.test','https://swimlog.example.test');$check(isset($r['activities'])&&count($r['activities'])===1&&strpos(wp_json_encode($r),'PRIVATE_GARMIN_ROUTE_CANARY')===false);
$forge=array('operation'=>'activities_import','nonce'=>wp_create_nonce('standalone_activities_import'),'selection_token'=>$r['selection_token'],'activity_ids'=>array('888'),'location_id'=>'0');$check(is_wp_error(G::submit($owner,$forge,'https://swimlog.example.test','https://swimlog.example.test')));
$history=array('operation'=>'history_start','nonce'=>wp_create_nonce('standalone_history_start'),'start'=>$today,'end'=>$today,'categories'=>array('weight'));$r=G::submit($owner,$history,'https://swimlog.example.test','https://swimlog.example.test');$check(isset($r['url']));parse_str(parse_url($r['url'],PHP_URL_QUERY),$q);$job=$q['job'];
foreach(array('pause'=>'paused','resume'=>'queued','cancel'=>'cancelled')as$action=>$state){$out=G::submit($owner,array('operation'=>'history_'.$action,'nonce'=>wp_create_nonce('standalone_history_'.$action),'job'=>$job),'https://swimlog.example.test','https://swimlog.example.test');$check(isset($out['url'])&&\SwimLogEvaluation\Garmin_History_Jobs::get($job)['status']===$state);}
$check(isset(settings_submit($owner,settings_input($owner,'key_save','garmin_setup')+array('api_key'=>'invalid-synthetic-key'))['url'])&&!S::verified($owner));
$check(is_wp_error(settings_submit($owner,settings_input($owner,'key_test','garmin_setup')))&&!S::verified($owner));
$check(is_wp_error(G::submit($owner,$history,'https://swimlog.example.test','https://swimlog.example.test'))&&\SwimLogEvaluation\Garmin_History_Jobs::get($job)['status']==='cancelled');
$check(is_wp_error(settings_submit($owner,settings_input($owner,'activity_sync','garmin_setup')+array('enabled'=>'1','types'=>array('lap_swimming')))));
$remove=settings_input($owner,'key_remove','garmin_setup')+array('confirm_remove'=>'1');$check(isset(settings_submit($owner,$remove)['url'])&&!API::is_configured($owner));
$delete=settings_input($owner,'location_delete','locations',$id)+array('location_id'=>$id,'confirm_delete'=>'1');$check(isset(settings_submit($owner,$delete)['url'])&&!\SwimLogEvaluation\Location::get_for_user($id,$owner));
\SwimLogEvaluation\Plugin::set_garmin_sync_schedule($owner,false);\SwimLogEvaluation\Garmin_Measurement_Sync::configure(array());\SwimLogEvaluation\Garmin_History_Jobs::delete_owner($owner);
echo 'Settings and Garmin integration passed: '.$checks." assertions.\n";

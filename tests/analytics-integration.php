<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable synthetic environment required.');
require_once dirname(__DIR__).'/app/Auth.php';require_once dirname(__DIR__).'/app/AnalyticsReports.php';
use SwimlogStandalone\AnalyticsReports as A;use SwimLogEvaluation\Training_Trends as T;use SwimLogEvaluation\Pool_Analytics as P;
$f=json_decode(file_get_contents('/tmp/swimlog-analytics.json'),true);$owner=(int)$f['owner'];wp_set_current_user($owner);$_COOKIE[LOGGED_IN_COOKIE]=$f['cookie'];$checks=0;
$check=static function($v)use(&$checks){++$checks;if(!$v)throw new RuntimeException('Analytics assertion '.$checks.' failed.');};
$r=A::load($owner,'trends',array());$check(!is_wp_error($r));$d=$r['data'];$check($d['current']['workout_count']===3&&$d['previous']['workout_count']===1);$check(abs($d['current']['distance_m']-1097.86)<0.001);$check($d['current']['active_time_ms']===660000);$check($d['previous']['distance_m']==500);$check(count($d['weekly'])===1&&count($d['monthly'])===1);$check($d['pool_course']['m']['length_count']===3&&$d['pool_course']['yd']['length_count']===1);
$pool=A::load($owner,'pool',array('course'=>'m'));$check(!is_wp_error($pool));$groups=array_column($pool['data']['groups'],null,'stroke');$fr=$groups['FR'];$check(count($groups)===2&&$fr['active_lengths']===2);$check($fr['avg_pace_100_ms']==120000&&$fr['avg_swolf']==40&&$fr['stroke_count_coverage_percent']==50);$check($fr['pace_consistency_cv']===null);$check($groups['BR']['avg_swolf']==80);
$check(count(A::load($owner,'pool',array('course'=>'yd'))['data']['groups'])===1);$check(A::load($owner,'pool',array('stroke'=>'FLY'))['data']['groups']===array());$check(A::load($owner,'pool',array('additional_metrics'=>'1'))['additional']);
foreach(array('0','25','1.5','01',array('6'))as$bad)foreach(array('trends','pool')as$screen)$check(is_wp_error(A::load($owner,$screen,array('months'=>$bad))));
foreach(array(array('course'=>'running'),array('course'=>array('m')),array('stroke'=>'RUN'),array('additional_metrics'=>'yes'),array('additional_metrics'=>array('1')))as$q)$check(is_wp_error(A::load($owner,'pool',$q)));
$check(is_wp_error(A::load(0,'trends',array()))&&is_wp_error(A::load($owner+1,'pool',array())));
$before=get_user_meta($owner);A::load($owner,'trends',array('months'=>'2'));A::load($owner,'pool',array('months'=>'2'));$check(get_user_meta($owner)===$before);
$user=get_user_by('id',$owner);$user->add_cap('swimlog_manage_own_workouts',false);wp_set_current_user(0);wp_set_current_user($owner);$check(is_wp_error(A::load($owner,'trends',array()))&&is_wp_error(A::load($owner,'pool',array())));$user->add_cap('swimlog_manage_own_workouts');wp_set_current_user(0);wp_set_current_user($owner);
$version=get_option('swimlog_db_version');update_option('swimlog_db_version','0');$check(is_wp_error(A::load($owner,'pool',array())));update_option('swimlog_db_version',$version);
wp_set_current_user((int)wp_validate_auth_cookie($f['manager'],'logged_in'));$_COOKIE[LOGGED_IN_COOKIE]=$f['manager'];$check(A::load(get_current_user_id(),'trends',array('user_id'=>(string)$owner))['data']['current']['workout_count']===0);
echo 'Swimming analytics integration: '.$checks." assertions passed.\n";

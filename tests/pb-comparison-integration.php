<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable synthetic environment required.');
require_once dirname(__DIR__).'/app/Auth.php';require_once dirname(__DIR__).'/app/PersonalBestComparison.php';
use SwimlogStandalone\PersonalBestComparison as C;
$f=json_decode(file_get_contents('/tmp/swimlog-pb-comparison.json'),true);$owner=(int)$f['owner'];wp_set_current_user($owner);$_COOKIE[LOGGED_IN_COOKIE]=$f['cookie'];$checks=0;$check=static function($v)use(&$checks){++$checks;if(!$v)throw new RuntimeException('PB comparison assertion '.$checks.' failed.');};
$q=array('a_mode'=>'year','a_year'=>'2019','b_mode'=>'year','b_year'=>'2020');$m=C::load($owner,$q);$check(!is_wp_error($m));$check(count($m['rows'])===2);$fr=$m['rows'][1];$check($fr['a']['ms']===75000&&$fr['b']['ms']===70000);$check($fr['a']['workout_id']===$f['ids'][1]);$check($m['rows'][0]['a']===null&&$m['rows'][0]['b']['stroke']==='BR');
$m=C::load($owner,array('a_mode'=>'age','a_min_age'=>'55','a_max_age'=>'59','b_mode'=>'age','b_min_age'=>'60','b_max_age'=>'64'));$check($m['rows'][1]['a']['ms']===72000&&$m['rows'][1]['b']['ms']===70000);$check(!$m['overlap']);
$m=C::load($owner,array('a_mode'=>'dates','a_from'=>'2020-06-14','a_to'=>'2020-06-14','b_mode'=>'dates','b_from'=>'2020-06-15','b_to'=>'2020-06-15'));$check(count($m['rows'])===1&&$m['rows'][0]['a']['ms']===72000&&$m['rows'][0]['b']['ms']===70000);
$m=C::load($owner,array('a_mode'=>'month','a_month'=>'2020-06','b_mode'=>'month','b_month'=>'2020-07'));$check($m['rows'][1]['a']['ms']===70000&&$m['rows'][1]['b']===null);
$m=C::load($owner,$q+array('course'=>'yd'));$check(count($m['rows'])===1&&$m['rows'][0]['a']===null&&$m['rows'][0]['b']['ms']===65000);
$check(C::load($owner,array('a_year'=>'1900','b_year'=>'1901'))['rows']===array());
foreach(array(array('course'=>'bad'),array('a_mode'=>array('age')),array('a_mode'=>'all'),array('a_year'=>'01'),array('a_mode'=>'month','a_month'=>'2020-13'),array('a_mode'=>'dates','a_from'=>'2020-02-30'),array('a_mode'=>'age','a_min_age'=>'-1'),array('a_mode'=>'age','a_min_age'=>'70','a_max_age'=>'60'))as$bad)$check(is_wp_error(C::load($owner,$bad)));
$before=get_user_meta($owner);C::load($owner,$q);$check(get_user_meta($owner)===$before);delete_user_meta($owner,'swimlog_birthdate');$check(C::load($owner,array('a_mode'=>'age'))->get_error_data()['status']===422);$check(!is_wp_error(C::load($owner,$q)));update_user_meta($owner,'swimlog_birthdate','1960-06-15');
$check(is_wp_error(C::load(0,$q))&&is_wp_error(C::load($owner+1,$q)));$version=get_option('swimlog_db_version');update_option('swimlog_db_version','0');$check(C::load($owner,$q)->get_error_data()['status']===503);update_option('swimlog_db_version',$version);
wp_set_current_user((int)wp_validate_auth_cookie($f['manager'],'logged_in'));$_COOKIE[LOGGED_IN_COOKIE]=$f['manager'];$check(C::load(get_current_user_id(),$q+array('user_id'=>(string)$owner))['rows']===array());
echo 'Personal Best comparison integration: '.$checks." assertions passed.\n";

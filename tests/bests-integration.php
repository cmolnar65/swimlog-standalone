<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable synthetic environment required.');
require_once dirname(__DIR__).'/app/Auth.php';require_once dirname(__DIR__).'/app/PersonalBests.php';
use SwimlogStandalone\PersonalBests as PB;use SwimLogEvaluation\Records;use SwimLogEvaluation\Evaluator;
$f=json_decode(file_get_contents('/tmp/swimlog-sharing.json'),true);$owner=(int)$f['owner'];wp_set_current_user($owner);$_COOKIE[LOGGED_IN_COOKIE]=$f['cookie'];$checks=0;
$check=static function($ok)use(&$checks){++$checks;if(!$ok)throw new RuntimeException('Personal Bests assertion '.$checks.' failed.');};
$b=PB::load($owner,array('period'=>'lifetime'));$check(!is_wp_error($b));$check($b['pbs']==Records::personal_bests($owner,'m'));
$check((int)$b['pbs'][100]['FR']->duration_ms===30000); // Private best remains visible to its owner.
$check(PB::load($owner,array('course'=>'yd'))['pbs']===array());
foreach(array('course','period','age_group','history_distance','history_stroke')as$key)$check(is_wp_error(PB::load($owner,array($key=>array('invalid')))));
$prior=get_user_meta($owner,'swimlog_birthdate',true);update_user_meta($owner,'swimlog_birthdate','1966-06-01');
$check(PB::load($owner,array('period'=>'current'))['current']==='60–64'||PB::load($owner,array('period'=>'current'))['current']==='60-64');
$b=PB::load($owner,array('period'=>'lifetime'));$check(count($b['historical'])===1);
$history=PB::load($owner,array('period'=>'historical','age_group'=>$b['historical'][0]));$check((int)$history['pbs'][100]['FR']->duration_ms===30000);
if($prior==='')delete_user_meta($owner,'swimlog_birthdate');else update_user_meta($owner,'swimlog_birthdate',$prior);
$progression=PB::load($owner,array('history_distance'=>'100','history_stroke'=>'FR'));$check(!is_wp_error($progression));
$check(!$progression['progressionUnavailable']&&count($progression['progression'])>0);
foreach(array('running','walking','open_water')as$type)$check(Evaluator::evaluate_workout($f['ids'][$type],$owner)===0);
$check(is_wp_error(PB::load(0,array())));$check(is_wp_error(PB::load($owner+1,array())));
unset($_COOKIE[LOGGED_IN_COOKIE]);$check(is_wp_error(PB::load($owner,array())));$_COOKIE[LOGGED_IN_COOKIE]=$f['cookie'];
$version=get_option('swimlog_db_version');update_option('swimlog_db_version','1.4');$check(is_wp_error(PB::load($owner,array())));update_option('swimlog_db_version',$version);
echo 'Personal Bests integration: '.$checks." assertions passed.\n";

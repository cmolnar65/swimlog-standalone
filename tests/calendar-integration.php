<?php
if (getenv('SWIMLOG_SYNTHETIC_TEST') !== '1') throw new RuntimeException('Disposable test flag required.');
require_once dirname(__DIR__).'/app/Auth.php';
require_once dirname(__DIR__).'/app/CalendarView.php';
use SwimlogStandalone\CalendarView as CV;
$fixture = json_decode(file_get_contents(getenv('SWIMLOG_WEB_FIXTURE')),true);
$_COOKIE[LOGGED_IN_COOKIE]=$fixture['cookie'];
$owner=(int)wp_validate_auth_cookie($fixture['cookie'],'logged_in');
wp_set_current_user($owner);
$other=(int)get_user_by('login','synthetic_other')->ID;
$GLOBALS['calendar_checks']=0;
function calendar_check($value) { ++$GLOBALS['calendar_checks']; if(!$value) throw new RuntimeException('Calendar assertion '.$GLOBALS['calendar_checks'].' failed.'); }
$calendar=CV::load($owner,array('user'=>$other));
calendar_check(!is_wp_error($calendar));
calendar_check(count($calendar['days'])%7===0 && count($calendar['days'])<=42);
$serialized=json_encode($calendar['days']);
calendar_check(strpos($serialized,'Synthetic meet')!==false);
calendar_check(strpos($serialized,'FOREIGN_PRIVATE_EVENT')===false);
calendar_check(strpos($serialized,'PRIVATE_NOTES_CANARY')===false);
calendar_check(strpos($serialized,'Synthetic planned swim')!==false);
calendar_check(strpos($serialized,'"label":"Run"')!==false);
calendar_check(strpos($serialized,'"label":"Walk"')!==false);
$today=$calendar['today']->format('Y-m-d');
$week=array_values(array_filter($calendar['weeks'],function($w)use($today){return $w['start']<=$today&&$w['end']>=$today;}))[0];
calendar_check((float)$week['distance_m']===2000.0);
calendar_check((float)$week['running_distance_m']===3000.0 && (int)$week['walking_distance_samples']===0);
calendar_check(is_wp_error(CV::load($other,array())));
calendar_check(is_wp_error(CV::load($owner,array('date'=>array('bad')))));
calendar_check(is_wp_error(CV::load($owner,array('date'=>'2026-02-30'))));
calendar_check(is_wp_error(CV::load($owner,array('view'=>'bad'))));
$old=get_user_meta($owner,'swimlog_calendar_first_day',true);
foreach(array('sunday'=>0,'monday'=>1,'saturday'=>6) as $first=>$weekday){
    update_user_meta($owner,'swimlog_calendar_first_day',$first);
    $month=CV::load($owner,array('date'=>'2028-02-15'));
    calendar_check((int)$month['start']->format('w')===$weekday && isset($month['days']['2028-02-29']));
    $weekView=CV::load($owner,array('date'=>'2028-02-15','view'=>'week'));
    calendar_check(count($weekView['days'])===7 && (int)$weekView['start']->format('w')===$weekday);
}
update_user_meta($owner,'swimlog_calendar_first_day',$old);
calendar_check(CV::distance(null)==='Distance unavailable' && CV::distance(0)==='0 m');
$installed=get_option('swimlog_db_version'); update_option('swimlog_db_version','0');
calendar_check(is_wp_error(CV::load($owner,array()))); update_option('swimlog_db_version',$installed);
echo 'Calendar integration passed: '.$GLOBALS['calendar_checks']." assertions.\n";

<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable test flag required.');
require_once dirname(__DIR__).'/app/Auth.php';require_once dirname(__DIR__).'/app/PlanMatch.php';require_once dirname(__DIR__).'/app/PlannedDetails.php';require_once dirname(__DIR__).'/app/CalendarView.php';
use SwimlogStandalone\PlanMatch as PM;use SwimlogStandalone\PlannedDetails as PD;use SwimlogStandalone\CalendarView as CV;
use SwimLogEvaluation\Planned_Workout as PW;use SwimLogEvaluation\Workout;use SwimLogEvaluation\Planned_Structure_Link as Link;use SwimLogEvaluation\Structured_Workout_Store as Store;
$f=json_decode(file_get_contents(getenv('SWIMLOG_WEB_FIXTURE')),true);$_COOKIE[LOGGED_IN_COOKIE]=$f['cookie'];
$owner=(int)wp_validate_auth_cookie($f['cookie'],'logged_in');wp_set_current_user($owner);$ids=$f['plans'];
$GLOBALS['match_checks']=0;$GLOBALS['match_owner']=$owner;
function match_check($v){++$GLOBALS['match_checks'];if(!$v)throw new RuntimeException('Plan match assertion '.$GLOBALS['match_checks'].' failed.');}
function match_input($form){return array('plan_id'=>(string)$form['id'],'nonce'=>wp_create_nonce('swimlog_plan_match_'.$form['id']),'revision'=>$form['revision'],'date'=>$form['context']['date'],'view'=>$form['context']['view'],'recorded_date'=>$form['recorded_date'],'match_page'=>(string)$form['page'],'recorded_choice'=>$form['candidates'][0]['choice']??'');}
function match_submit($input){return PM::submit($GLOBALS['match_owner'],$input,'https://swimlog.example.test','https://swimlog.example.test');}
$form=PM::open($owner,$ids['plan']);match_check(!is_wp_error($form)&&!$form['candidates']); // Today's only pool swim is already matched.
match_check(is_wp_error(PM::open($owner,$ids['foreign']))&&is_wp_error(PM::open($owner,$ids['matched'])));
match_check(is_wp_error(PM::open($owner,array('bad')))&&is_wp_error(PM::open($owner,$ids['plan'],array('recorded_date'=>'2026-02-30'))));
$day=get_user_meta($owner,'standalone_log_fixture',true);
$form=PM::open($owner,$ids['plan'],array('recorded_date'=>$day,'view'=>'week'));match_check(count($form['candidates'])===20&&$form['pages']===2);
$second=PM::open($owner,$ids['plan'],array('recorded_date'=>$day,'match_page'=>'2'));match_check(count($second['candidates'])===6);
foreach($form['candidates']as$c)match_check($c['id']!==$f['workouts']['foreign']&&$c['id']!==$f['workouts']['running']);
$input=match_input($form);
foreach(array(array('recorded_choice'=>''),array('recorded_choice'=>array('bad')),array('recorded_choice'=>$f['workouts']['foreign'].':'.str_repeat('a',64)),array('recorded_choice'=>$f['workouts']['open_water'].':'.str_repeat('a',64)),array('user_id'=>'999'),array('completed_workout_id'=>'999'))as$bad) { $r=match_submit(array_replace($input,$bad));match_check(isset($r['form'])&&is_wp_error($r['form']['error'])); }
match_check(is_wp_error(match_submit(array_replace($input,array('nonce'=>'bad')))));
match_check(is_wp_error(PM::submit($owner,$input,'https://evil.example.test','https://swimlog.example.test')));
$before=PW::get_for_user($ids['plan'],$owner);$changed=get_object_vars($before);$changed['title']='Synthetic concurrent change';PW::save($owner,$changed,$ids['plan']);
$r=match_submit($input);match_check($r['form']['error']->get_error_data()['status']===409&&!PW::get_for_user($ids['plan'],$owner)->completed_workout_id);PW::save($owner,get_object_vars($before),$ids['plan']);
$form=PM::open($owner,$ids['plan'],array('recorded_date'=>$day));$input=match_input($form);$record=(int)$form['candidates'][0]['id'];$prior=Workout::get_for_user($record,$owner);
\SwimLogEvaluation\Database::write('update',\SwimLogEvaluation\Database::table('workouts'),array('elapsed_time_ms'=>(int)$prior->elapsed_time_ms+1000),array('id'=>$record));
$r=match_submit($input);match_check(isset($r['form'])&&is_wp_error($r['form']['error']));
\SwimLogEvaluation\Database::write('update',\SwimLogEvaluation\Database::table('workouts'),array('elapsed_time_ms'=>$prior->elapsed_time_ms),array('id'=>$record));
// A competing connection must not enter this owner's standalone match critical section.
$lock='swimlog_match_'.substr(hash('sha256',DB_NAME.'|'.$GLOBALS['wpdb']->prefix.'|'.$owner),0,48);
$blocker=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);
$acquired=$blocker->get_var($blocker->prepare('SELECT GET_LOCK(%s, 0)',$lock));
match_check((string)$acquired==='1');
try { $busy=match_submit(match_input(PM::open($owner,$ids['plan'],array('recorded_date'=>$day))));match_check(is_wp_error($busy)&&$busy->get_error_data()['status']===409); }
finally { $blocker->get_var($blocker->prepare('SELECT RELEASE_LOCK(%s)',$lock));$blocker->close(); }
// Matching a linked prescription locks it without rewriting the document or notes.
$source=PW::save($owner,array('title'=>'Synthetic integration match','planned_date'=>wp_date('Y-m-d'),'workout_type'=>'pool','notes'=>'PRIVATE_MATCH_NOTES','workout_instructions'=>'Synthetic match instructions'));
$original=Link::for_plan($ids['plan'],$owner);$structure=Store::save($owner,'Synthetic match prescription',$original['document']);Link::attach($source,$structure,$owner);
$form=PM::open($owner,$source,array('recorded_date'=>$day,'view'=>'week'));$input=match_input($form);$selected=(int)$form['candidates'][0]['id'];$recordBefore=serialize(Workout::get_for_user($selected,$owner));$document=get_post($structure)->post_content;$metadata=get_post_meta($structure);
$competing=match_input(PM::open($owner,$ids['plan'],array('recorded_date'=>$day)));
$r=match_submit($input);match_check(isset($r['id'])&&$r['id']===$source&&strpos($r['url'],'plan_result=matched')!==false&&strpos($r['url'],'view=week')!==false);
$after=PW::get_for_user($source,$owner);match_check($after->status==='completed'&&(int)$after->completed_workout_id===$selected&&$after->notes==='PRIVATE_MATCH_NOTES'&&$after->workout_instructions==='Synthetic match instructions');
match_check(Store::is_locked($structure,$owner)===true&&get_post($structure)->post_content===$document&&get_post_meta($structure)===$metadata);
match_check(serialize(Workout::get_for_user($selected,$owner))===$recordBefore);
match_check(is_wp_error(match_submit($input))&&is_wp_error(PM::open($owner,$source)));
$deniedDuplicate=match_submit($competing);match_check(isset($deniedDuplicate['form'])&&is_wp_error($deniedDuplicate['form']['error'])&&!PW::get_for_user($ids['plan'],$owner)->completed_workout_id);
$available=PM::open($owner,$ids['plan'],array('recorded_date'=>$day));match_check(!in_array($selected,array_column($available['candidates'],'id'),true));
$details=PD::load($owner,$source);match_check(!$details['matchable']&&!$details['editable']&&!$details['movable']&&$details['match']['id']===$selected);
$calendar=CV::load($owner,array());$serialized=json_encode($calendar['days']);match_check(strpos($serialized,'Synthetic integration match')!==false&&strpos($serialized,'"status":"Completed"')!==false);
$recordedCalendar=CV::load($owner,array('date'=>$day));$recordedItems=$recordedCalendar['days'][$day]['items'];$matchedItems=array_filter($recordedItems,static function($item)use($selected){return !empty($item['recorded'])&&$item['id']===$selected&&!empty($item['matched']);});match_check(count($matchedItems)===1);
// Existing plugin clear logic preserves the recorded prescription lock/history.
PW::clear_completed_workout($source,$owner);match_check(Store::is_locked($structure,$owner)===true&&is_wp_error(PM::open($owner,$source)));PW::delete($source,$owner);wp_delete_post($structure,true);
$user=get_user_by('id',$owner);$user->add_cap('swimlog_manage_own_workouts',false);wp_set_current_user(0);wp_set_current_user($owner);match_check(is_wp_error(PM::open($owner,$ids['plan']))&&is_wp_error(match_submit($input)));
$user->add_cap('swimlog_manage_own_workouts');wp_set_current_user(0);wp_set_current_user($owner);
echo 'Plan matching integration passed: '.$GLOBALS['match_checks']." assertions.\n";

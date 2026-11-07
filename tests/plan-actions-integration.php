<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable test flag required.');
require_once dirname(__DIR__).'/app/Auth.php';require_once dirname(__DIR__).'/app/PlanActions.php';require_once dirname(__DIR__).'/app/PlannedDetails.php';
use SwimlogStandalone\PlanActions as PA;use SwimlogStandalone\PlannedDetails as PD;
use SwimLogEvaluation\Planned_Workout as PW;use SwimLogEvaluation\Planned_Structure_Link as Link;use SwimLogEvaluation\Structured_Workout_Store as Store;
$f=json_decode(file_get_contents(getenv('SWIMLOG_WEB_FIXTURE')),true);$_COOKIE[LOGGED_IN_COOKIE]=$f['cookie'];
$owner=(int)wp_validate_auth_cookie($f['cookie'],'logged_in');wp_set_current_user($owner);$ids=$f['plans'];
$GLOBALS['action_checks']=0;$GLOBALS['action_owner']=$owner;
function action_check($v){++$GLOBALS['action_checks'];if(!$v)throw new RuntimeException('Plan action assertion '.$GLOBALS['action_checks'].' failed.');}
function action_input($form){return array('plan_id'=>(string)$form['id'],'operation'=>$form['operation'],'nonce'=>wp_create_nonce('swimlog_plan_'.$form['operation'].'_'.$form['id']),'revision'=>$form['revision'],'target_date'=>$form['target_date'],'date'=>$form['context']['date'],'view'=>$form['context']['view']);}
function action_submit($input){return PA::submit($GLOBALS['action_owner'],$input,'https://swimlog.example.test','https://swimlog.example.test');}
$form=PA::open($owner,$ids['plan'],'move',array('view'=>'week'));action_check(!is_wp_error($form));
$source=PW::get_for_user($ids['plan'],$owner);$structure=get_post($ids['structure']);$meta=get_post_meta($ids['structure']);
$future=(new DateTimeImmutable('today',wp_timezone()))->modify('+7 days')->format('Y-m-d');
$input=action_input($form);$input['target_date']=$future;$r=action_submit($input);
action_check($r['id']===$ids['plan']&&strpos($r['url'],'date='.$future)!==false&&strpos($r['url'],'view=week')!==false);
$moved=PW::get_for_user($ids['plan'],$owner);$a=get_object_vars($source);$b=get_object_vars($moved);unset($a['planned_date'],$a['updated_at'],$b['planned_date'],$b['updated_at']);
action_check($a===$b&&$moved->planned_date===$future);
action_check(get_post($ids['structure'])->post_content===$structure->post_content&&get_post_meta($ids['structure'])===$meta);
$stale=action_submit($input);action_check($stale['form']['error']->get_error_data()['status']===409);
PW::move($ids['plan'],$owner,$source->planned_date);
$base=action_input(PA::open($owner,$ids['plan'],'move'));
foreach(array(array('target_date'=>'1900-01-01'),array('target_date'=>'2026-02-30'),array('target_date'=>''),array('target_date'=>array('bad')),array('status'=>'completed'),array('user_id'=>'99'))as$bad) {
    $r=action_submit(array_replace($base,$bad));action_check(isset($r['form'])&&is_wp_error($r['form']['error']));
}
action_check(is_wp_error(action_submit(array_replace($base,array('nonce'=>'bad')))));
action_check(is_wp_error(PA::submit($owner,$base,'https://evil.example.test','https://swimlog.example.test')));
action_check(is_wp_error(action_submit(array_replace($base,array('operation'=>'copy')))));
$forged=array_replace($base,array('plan_id'=>$ids['foreign'],'nonce'=>wp_create_nonce('swimlog_plan_move_'.$ids['foreign'])));action_check(is_wp_error(action_submit($forged)));
$foreign=PA::open($owner,$ids['foreign'],'copy');$missing=PA::open($owner,99999999,'copy');action_check(is_wp_error($foreign)&&$foreign->get_error_message()===$missing->get_error_message());
action_check(is_wp_error(PA::open($owner,$ids['plan'],'delete'))&&is_wp_error(PA::open($owner,array('bad'),'move')));
action_check(is_wp_error(PA::open($owner,$ids['matched'],'move'))&&is_wp_error(PA::open($owner,$ids['broken'],'move')));
// Copying a recorded prescription must produce a separate, unlocked structure.
update_post_meta($ids['structure'],Store::META_RECORDED,$f['workouts']['pool']);
action_check(is_wp_error(PA::open($owner,$ids['plan'],'move')));
$sourceBefore=serialize(PW::get_for_user($ids['plan'],$owner));$metaBefore=get_post_meta($ids['structure']);
$input=action_input(PA::open($owner,$ids['plan'],'copy'));$input['target_date']=$future;$r=action_submit($input);
action_check(isset($r['id'])&&$r['id']!==$ids['plan']);$copy=$r['id'];$row=PW::get_for_user($copy,$owner);
action_check($row->planned_date===$future&&$row->status==='planned'&&$row->completed_workout_id===null&&$row->notes===$source->notes&&$row->workout_instructions===$source->workout_instructions);
$linked=Link::for_plan($copy,$owner);$original=Link::for_plan($ids['plan'],$owner);
action_check($linked['id']!==$original['id']&&$linked['document']===$original['document']&&Store::is_locked($linked['id'],$owner)===false);
action_check(serialize(PW::get_for_user($ids['plan'],$owner))===$sourceBefore&&get_post_meta($ids['structure'])===$metaBefore);
$doc=$linked['document'];$doc['sections'][0]['label']='Synthetic independent copy';$saved=Store::save($owner,$linked['title'],$doc,$linked['id']);
action_check(!is_wp_error($saved)&&get_post($ids['structure'])->post_content===$structure->post_content);
PW::delete($copy,$owner);wp_delete_post($linked['id'],true);delete_post_meta($ids['structure'],Store::META_RECORDED);
$input=action_input(PA::open($owner,$ids['matched'],'copy'));$input['target_date']=$future;$r=action_submit($input);$row=PW::get_for_user($r['id'],$owner);
action_check($row->status==='planned'&&$row->completed_workout_id===null&&PW::get_for_user($ids['matched'],$owner)->completed_workout_id!==null);PW::delete($r['id'],$owner);
// Invalid structured documents must fail before producing an orphan plan.
$count=count(PW::all_for_user($owner));$document=get_post($ids['structure'])->post_content;
wp_update_post(wp_slash(array('ID'=>$ids['structure'],'post_content'=>wp_json_encode(array('unit'=>'yd','sections'=>array())))));
$r=action_submit(action_input(PA::open($owner,$ids['plan'],'copy')));
action_check(isset($r['form'])&&is_wp_error($r['form']['error'])&&count(PW::all_for_user($owner))===$count);
wp_update_post(wp_slash(array('ID'=>$ids['structure'],'post_content'=>$document)));
// Permission-filtered controls agree with server-side history restrictions.
$details=PD::load($owner,$ids['matched']);action_check(!$details['movable']&&$details['copyable']);
$user=get_user_by('id',$owner);$user->add_cap('swimlog_manage_own_workouts',false);wp_set_current_user(0);wp_set_current_user($owner);
action_check(is_wp_error(PA::open($owner,$ids['plan'],'copy'))&&is_wp_error(action_submit($base)));
$user->add_cap('swimlog_manage_own_workouts');wp_set_current_user(0);wp_set_current_user($owner);
echo 'Plan actions integration passed: '.$GLOBALS['action_checks']." assertions.\n";

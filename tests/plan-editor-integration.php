<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable test flag required.');
require_once dirname(__DIR__).'/app/Auth.php';require_once dirname(__DIR__).'/app/PlanEditor.php';
use SwimlogStandalone\PlanEditor as PE;use SwimLogEvaluation\Planned_Workout as PW;
$f=json_decode(file_get_contents(getenv('SWIMLOG_WEB_FIXTURE')),true);$_COOKIE[LOGGED_IN_COOKIE]=$f['cookie'];
$owner=(int)wp_validate_auth_cookie($f['cookie'],'logged_in');wp_set_current_user($owner);$ids=$f['plans'];
$GLOBALS['editor_checks']=0;$GLOBALS['editor_owner']=$owner;
function editor_check($v){++$GLOBALS['editor_checks'];if(!$v)throw new RuntimeException('Editor assertion '.$GLOBALS['editor_checks'].' failed.');}
function editor_input($form){return $form['values']+array('plan_id'=>(string)$form['id'],'nonce'=>wp_create_nonce('swimlog_plan_save_'.$form['id']),'revision'=>$form['revision'],'date'=>$form['context']['date'],'view'=>$form['context']['view']);}
function editor_submit($input){return PE::submit($GLOBALS['editor_owner'],$input,'https://swimlog.example.test','https://swimlog.example.test');}
$form=PE::open($owner,'new',array('view'=>'week'));editor_check(!is_wp_error($form));
$input=editor_input($form);$input['title']='Synthetic create/edit';$input['planned_distance']='500';$input['planned_distance_unit']='yd';$input['planned_duration']='1:02.345';$input['pool_length_native']='25';$input['pool_course']='yd';$input['workout_instructions']="Keep quotes \"here\" and backslash \\ here";
$result=editor_submit($input);editor_check(isset($result['id'])&&strpos($result['url'],'view=week')!==false);
$id=$result['id'];$created=PW::get_for_user($id,$owner);
editor_check(abs((float)$created->planned_distance_m-457.2)<0.00001&&abs((float)$created->pool_length_m-22.86)<0.00001);
editor_check((int)$created->planned_duration_ms===62345&&$created->status==='planned'&&$created->completed_workout_id===null);
editor_check($created->workout_instructions===$input['workout_instructions']);
$form=PE::open($owner,$id);$input=editor_input($form);$input['title']='Synthetic edited';$result=editor_submit($input);
editor_check(isset($result['id'])&&PW::get_for_user($id,$owner)->title==='Synthetic edited');
$form=PE::open($owner,$id);$base=editor_input($form);
foreach(array(array('planned_duration'=>'10:99'),array('planned_distance'=>'0'),array('planned_distance'=>'INF'),array('pool_length_native'=>'5','pool_course'=>''),array('title'=>''),array('title'=>str_repeat('x',192)),array('planned_date'=>'2026-02-30'),array('planned_date'=>'1900-01-01'),array('workout_type'=>'running'),array('workout_instructions'=>str_repeat('x',20001)),array('title'=>array('bad')),array('status'=>'completed'),array('notes'=>'forged'),array('completed_workout_id'=>$f['workouts']['pool']),array('user_id'=>123))as$bad) {
    $r=editor_submit(array_replace($base,$bad));editor_check(isset($r['form'])&&is_wp_error($r['form']['error']));
}
editor_check(PW::get_for_user($id,$owner)->title==='Synthetic edited');
$wrong=$base;$wrong['nonce']='bad';editor_check(is_wp_error(editor_submit($wrong)));
editor_check(is_wp_error(PE::submit($owner,$base,'https://evil.example.test','https://swimlog.example.test')));
$foreign=$base;$foreign['plan_id']=$ids['foreign'];$foreign['nonce']=wp_create_nonce('swimlog_plan_save_'.$ids['foreign']);editor_check(is_wp_error(editor_submit($foreign)));
editor_check(is_wp_error(PE::open($owner,$ids['matched']))&&is_wp_error(PE::open($owner,$ids['broken'])));
// Changing a record while a form is open requires reloading, never overwrites it.
$external=get_object_vars(PW::get_for_user($id,$owner));$external['title']='External edit';PW::save($owner,$external,$id);
$r=editor_submit($base);editor_check($r['form']['error']->get_error_data()['status']===409&&PW::get_for_user($id,$owner)->title==='External edit');
// A linked structure and private notes survive an instructions edit unchanged.
$prior=PW::get_for_user($ids['plan'],$owner);$post=get_post($ids['structure']);$metadata=get_post_meta($ids['structure']);
$form=PE::open($owner,$ids['plan']);$input=editor_input($form);$input['workout_instructions']='Synthetic revised instructions';$r=editor_submit($input);
$after=PW::get_for_user($ids['plan'],$owner);
editor_check(isset($r['id'])&&$after->notes===$prior->notes&&$after->status===$prior->status&&$after->completed_workout_id===$prior->completed_workout_id);
editor_check(get_post($ids['structure'])->post_content===$post->post_content&&get_post_meta($ids['structure'])===$metadata);
PW::save($owner,get_object_vars($prior),$ids['plan']);
update_post_meta($ids['structure'],\SwimLogEvaluation\Structured_Workout_Store::META_RECORDED,$f['workouts']['pool']);
editor_check(is_wp_error(PE::open($owner,$ids['plan'])));
delete_post_meta($ids['structure'],\SwimLogEvaluation\Structured_Workout_Store::META_RECORDED);
// Exact canonical values are retained through an unchanged native-unit form.
$before=PW::get_for_user($id,$owner);$noop=editor_submit(editor_input(PE::open($owner,$id)));$after=PW::get_for_user($id,$owner);
editor_check(isset($noop['id'])&&$before->planned_distance_m===$after->planned_distance_m&&$before->pool_length_m===$after->pool_length_m);
// Open water accepts optional fields but rejects pool settings through plugin validation.
$new=editor_input(PE::open($owner,'new'));$new['title']='Synthetic open water';$new['workout_type']='open_water';$r=editor_submit($new);editor_check(isset($r['id']));PW::delete($r['id'],$owner);
$user=get_user_by('id',$owner);$user->add_cap('swimlog_manage_own_workouts',false);wp_set_current_user(0);wp_set_current_user($owner);editor_check(is_wp_error(PE::open($owner,'new'))&&is_wp_error(editor_submit($base)));
$user->add_cap('swimlog_manage_own_workouts');wp_set_current_user(0);wp_set_current_user($owner);
$schema=get_option('swimlog_db_version');update_option('swimlog_db_version','0');editor_check(is_wp_error(PE::open($owner,'new')));update_option('swimlog_db_version',$schema);
PW::delete($id,$owner);
echo 'Plan editor integration passed: '.$GLOBALS['editor_checks']." assertions.\n";

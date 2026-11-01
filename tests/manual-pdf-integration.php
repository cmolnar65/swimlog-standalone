<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable test flag required.');
require_once dirname(__DIR__).'/app/Auth.php';require_once dirname(__DIR__).'/app/ManualEditor.php';require_once dirname(__DIR__).'/app/TrainingPlanImport.php';
use SwimlogStandalone\ManualEditor as M;use SwimlogStandalone\WorkoutEntry as W;use SwimlogStandalone\TrainingPlanImport as P;use SwimLogEvaluation\Manual_Workout;use SwimLogEvaluation\Workout;
$u=get_user_by('login','synthetic_entry');$owner=(int)$u->ID;wp_set_current_user($owner);$_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie($owner,time()+3600,'logged_in');$checks=0;$check=static function($v)use(&$checks){++$checks;if(!$v)throw new RuntimeException('Manual/PDF assertion '.$checks.' failed.');};
$check(P::ready($owner)===true);$check(W::ready($owner,'manual')===true);
$v=W::form($owner)['values'];$v['workout_date']='2003-01-02';$v['duration']='100.123';$v['total_distance']='100';$set=W::blankSet();$set['repetitions']='1';$set['distance']='100';$set['timing']='measured_rep';$set['rep_times']='100.123';$set['stroke']='FR';
$doc=W::document($owner,$v,array($set));$saved=Manual_Workout::save($owner,$doc);$check(!is_wp_error($saved));$id=(string)$saved['workout_id'];
$f=M::open($owner,$id);$check($f['values']['duration']==='100.123'&&$f['sets'][0]['rep_times']==='100.123');
$input=$f['values']+array('manual_id'=>$id,'revision'=>$f['revision'],'nonce'=>wp_create_nonce('standalone_manual_edit_'.$id),'payload_complete'=>'1','sets'=>$f['sets']);
$submit=static function($in,$origin='https://swimlog.example.test')use($owner){return M::submit($owner,$in,array(),$origin,'https://swimlog.example.test');};
$check(is_wp_error($submit($input,'https://evil.test')));$check(is_wp_error($submit(array_replace($input,array('nonce'=>'bad')))));$check(is_wp_error($submit(array_diff_key($input,array('nonce'=>1)))));
$input['total_distance']='200';$input['sets'][0]['repetitions']='2';$input['sets'][0]['rep_times']='100.123, 90.456';$r=$submit($input);$check(isset($r['url']));$w=Workout::get_for_user((int)$id,$owner);$check((int)$w->id===(int)$id&&(float)$w->total_distance_m===200.0&&$w->source_type==='manual');$check(count(Workout::imports((int)$id,$owner))===0);$check($submit($input)->get_error_data()['status']===409);
$f=M::open($owner,$id);$check($f['sets'][0]['rep_times']==='100.123, 90.456');$input['revision']=$f['revision'];$before=Manual_Workout::get_document((int)$id,$owner);$bad=$input;$bad['sets'][0]['rep_times']='invalid';$check(isset($submit($bad)['form']));$check(Manual_Workout::get_document((int)$id,$owner)===$before);
$check(is_wp_error(M::open($owner,array($id))));$check(is_wp_error(P::preview($owner,'bad')));$check(is_wp_error(P::submit($owner,'upload',array('nonce'=>'bad'),array(),'https://swimlog.example.test','https://swimlog.example.test')));
$other=get_user_by('login','synthetic_other');$other->add_cap('swimlog_manage_own_workouts');wp_set_current_user($other->ID);$_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie($other->ID,time()+3600,'logged_in');$check(M::open((int)$other->ID,$id)->get_error_data()['status']===404);$check(is_wp_error(M::submit($owner,$input,array(),'https://swimlog.example.test','https://swimlog.example.test')));
wp_set_current_user($owner);$_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie($owner,time()+3600,'logged_in');$check(!is_wp_error(Workout::delete((int)$id,$owner)));
echo 'Manual editing/PDF integration: '.$checks." assertions passed.\n";

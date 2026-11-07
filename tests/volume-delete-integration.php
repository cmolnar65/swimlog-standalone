<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable synthetic environment required.');
require_once dirname(__DIR__).'/app/Auth.php';require_once dirname(__DIR__).'/app/TrainingVolume.php';require_once dirname(__DIR__).'/app/WorkoutAssociations.php';
use SwimlogStandalone\TrainingVolume as V;use SwimlogStandalone\WorkoutAssociations as A;use SwimLogEvaluation\Workout as W;use SwimLogEvaluation\Planned_Workout as P;use SwimLogEvaluation\Planned_Structure_Link as L;use SwimLogEvaluation\Structured_Workout_Store as S;
$f=json_decode(file_get_contents('/tmp/swimlog-volume.json'),true);$owner=(int)$f['owner'];wp_set_current_user($owner);$_COOKIE[LOGGED_IN_COOKIE]=$f['cookie'];$checks=0;
$check=static function($v)use(&$checks){++$checks;if(!$v)throw new RuntimeException('Volume/deletion assertion '.$checks.' failed.');};
$v=V::load($owner,array());$check(!is_wp_error($v));$check(count($v['rows'])===3);$m=array_column($v['groups']['m'],null,'stroke');$yd=$v['groups']['yd'][0];
$check(abs((float)$m['FR']->distance_native-25)<0.001&&(int)$m['FR']->active_time_ms===30000&&(int)$m['FR']->length_count===1);
$check(abs((float)$m['BR']->distance_native-50)<0.001&&abs((float)$yd->distance_native-25)<0.001);
$check(count(V::load($owner,array('course'=>'m','stroke'=>'FR'))['rows'])===1);
foreach(array(array('months'=>'0'),array('months'=>'25'),array('months'=>array('3')),array('course'=>'run'),array('stroke'=>'RUN'))as$q)$check(is_wp_error(V::load($owner,$q)));
update_user_meta($owner,'swimlog_training_volume_months',6);$check(V::load($owner,array())['months']==='6');V::load($owner,array('months'=>'1'));$check((int)get_user_meta($owner,'swimlog_training_volume_months',true)===6);delete_user_meta($owner,'swimlog_training_volume_months');
$check(is_wp_error(V::load(0,array()))&&is_wp_error(V::load($owner+1,array())));
$af=json_decode(file_get_contents('/tmp/swimlog-associations.json'),true);$owner=(int)$af['owner'];wp_set_current_user($owner);$_COOKIE[LOGGED_IN_COOKIE]=$af['cookie'];$check(A::ready($owner)===true);
function deletion_input($owner,$plan){$s=A::forPlan($owner,$plan);return array('plan_id'=>(string)$plan,'workout_id'=>(string)$s['workout_id'],'operation'=>'delete','revision'=>$s['revision'],'nonce'=>wp_create_nonce('standalone_unlink_delete_'.$plan),'return_to'=>'plan','confirm'=>'1');}
function deletion_submit($owner,$in){return A::submit($owner,$in,'https://swimlog.example.test','https://swimlog.example.test');}
$a=$af['pairs']['pool'];$in=deletion_input($owner,$a['plan']);$check(is_wp_error(deletion_submit($owner,$in))&&P::get_for_user($a['plan'],$owner));
P::clear_completed_workout($a['plan'],$owner);$check(is_wp_error(deletion_submit($owner,$in)));$in=deletion_input($owner,$a['plan']);
foreach(array('nonce','confirm','revision')as$key){$bad=$in;unset($bad[$key]);$check(is_wp_error(deletion_submit($owner,$bad)));}
$check(is_wp_error(A::submit($owner,$in,'https://evil.example.test','https://swimlog.example.test')));
$before=serialize(W::get_for_user($a['workout'],$owner));$doc=get_post($a['structure'])->post_content;
$check(isset(deletion_submit($owner,$in)['url']));$check(!P::get_for_user($a['plan'],$owner)&&get_post($a['structure'])->post_content===$doc&&S::is_locked($a['structure'],$owner));
$check((int)get_post_meta($a['structure'],L::META_KEY,true)===0&&serialize(W::get_for_user($a['workout'],$owner))===$before);
$plan=P::save($owner,array('title'=>'Synthetic never linked delete','planned_date'=>wp_date('Y-m-d'),'workout_type'=>'pool'));$in=deletion_input($owner,$plan);$check(isset(deletion_submit($owner,$in)['url'])&&!P::get_for_user($plan,$owner));
$plan=P::save($owner,array('title'=>'Synthetic cross owner delete','planned_date'=>wp_date('Y-m-d'),'workout_type'=>'pool'));$in=deletion_input($owner,$plan);wp_set_current_user((int)wp_validate_auth_cookie($af['manager'],'logged_in'));$_COOKIE[LOGGED_IN_COOKIE]=$af['manager'];$in['nonce']=wp_create_nonce('standalone_unlink_delete_'.$plan);$check(is_wp_error(deletion_submit(get_current_user_id(),$in)));wp_set_current_user($owner);$_COOKIE[LOGGED_IN_COOKIE]=$af['cookie'];$check(P::get_for_user($plan,$owner)!==null);P::delete($plan,$owner);
echo 'Training Volume/plan deletion integration: '.$checks." assertions passed.\n";

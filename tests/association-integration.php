<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable synthetic environment required.');
require_once dirname(__DIR__).'/app/Auth.php';require_once dirname(__DIR__).'/app/WorkoutAssociations.php';require_once dirname(__DIR__).'/app/StructuredWorkouts.php';
use SwimlogStandalone\WorkoutAssociations as A;use SwimLogEvaluation\Planned_Workout as P;use SwimLogEvaluation\Planned_Structure_Link as L;use SwimLogEvaluation\Structured_Workout_Store as S;use SwimLogEvaluation\Workout as W;
$f=json_decode(file_get_contents('/tmp/swimlog-associations.json'),true);$owner=(int)$f['owner'];wp_set_current_user($owner);$_COOKIE[LOGGED_IN_COOKIE]=$f['cookie'];$checks=0;
$check=static function($v)use(&$checks){++$checks;if(!$v)throw new RuntimeException('Association assertion '.$checks.' failed.');};
function unlink_input($owner,$id,$op){$s=A::forPlan($owner,$id);return array('plan_id'=>(string)$id,'workout_id'=>(string)$s['workout_id'],'operation'=>$op,'nonce'=>wp_create_nonce('standalone_unlink_'.$op.'_'.$id),'revision'=>$s['revision'],'return_to'=>'plan','confirm'=>'1');}
function unlink_submit($owner,$in){return A::submit($owner,$in,'https://swimlog.example.test','https://swimlog.example.test');}
$check(A::ready($owner)===true);
$a=$f['pairs']['pool'];$b=$f['pairs']['open_water'];$before=serialize(W::get_for_user($a['workout'],$owner));$doc=get_post($a['structure'])->post_content;
$in=unlink_input($owner,$a['plan'],'structure');
foreach(array('nonce','revision','confirm')as$key){$bad=$in;unset($bad[$key]);$check(is_wp_error(unlink_submit($owner,$bad)));}
$check(is_wp_error(unlink_submit($owner,$in+array('user_id'=>'1'))));$check(is_wp_error(A::submit($owner,$in,'https://evil.example.test','https://swimlog.example.test')));
$check(is_wp_error(unlink_submit(0,$in)));$check(is_wp_error(A::forPlan($owner+1,$a['plan'])));
$db=$GLOBALS['wpdb'];$blocker=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);
foreach(array('swimlog_match_'.substr(hash('sha256',DB_NAME.'|'.$db->prefix.'|'.$owner),0,48),'standalone_structure_'.substr(hash('sha256',DB_NAME.'|'.$db->prefix.'|'.$owner),0,42))as$lock) {
 $check((string)$blocker->get_var($blocker->prepare('SELECT GET_LOCK(%s, 0)',$lock))==='1');
 try {$busy=unlink_submit($owner,$in);$check(is_wp_error($busy)&&$busy->get_error_data()['status']===409);}
 finally {$blocker->get_var($blocker->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
}
$blocker->close();
$denyHistory=static function($check,$postId,$key)use($a){return (int)$postId===$a['structure']&&$key===S::META_RECORDED?false:$check;};
add_filter('update_post_metadata',$denyHistory,10,3);$check(is_wp_error(unlink_submit($owner,$in)));remove_filter('update_post_metadata',$denyHistory,10);
$check(L::for_plan($a['plan'],$owner)!==null&&(int)P::get_for_user($a['plan'],$owner)->completed_workout_id===$a['workout']);
$check(isset(unlink_submit($owner,$in)['url']));$check(L::for_plan($a['plan'],$owner)===null);
$check((int)P::get_for_user($a['plan'],$owner)->completed_workout_id===$a['workout']);$check(S::is_locked($a['structure'],$owner));
$check((int)get_post_meta($a['structure'],S::META_RECORDED,true)===$a['workout']);$check(is_wp_error(\SwimlogStandalone\StructuredWorkouts::editor($owner,$a['structure'])));
$check(is_wp_error(unlink_submit($owner,$in)));$check(serialize(W::get_for_user($a['workout'],$owner))===$before&&get_post($a['structure'])->post_content===$doc);
$stale=unlink_input($owner,$a['plan'],'match');$changed=$stale;$changed['revision']=str_repeat('0',64);$check(is_wp_error(unlink_submit($owner,$changed)));$check(isset(unlink_submit($owner,$stale)['url']));
$p=P::get_for_user($a['plan'],$owner);$check($p->status==='planned'&&empty($p->completed_workout_id));
// Reverse order also preserves history before losing the recorded match.
$check(isset(unlink_submit($owner,unlink_input($owner,$b['plan'],'match'))['url']));$check(S::is_locked($b['structure'],$owner));
$check(isset(unlink_submit($owner,unlink_input($owner,$b['plan'],'structure'))['url']));$check(S::is_locked($b['structure'],$owner)&&get_post($b['structure'])&&P::get_for_user($b['plan'],$owner)&&W::get_for_user($b['workout'],$owner));
wp_set_current_user((int)wp_validate_auth_cookie($f['manager'],'logged_in'));$_COOKIE[LOGGED_IN_COOKIE]=$f['manager'];$check(is_wp_error(A::forPlan(get_current_user_id(),$a['plan'])));$check(is_wp_error(unlink_submit(get_current_user_id(),$stale)));$check(is_wp_error(unlink_submit($owner,$stale)));
echo 'Workout association integration: '.$checks." assertions passed.\n";

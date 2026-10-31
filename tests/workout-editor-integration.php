<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable test flag required.');
require_once dirname(__DIR__).'/app/Auth.php';require_once dirname(__DIR__).'/app/WorkoutEditor.php';
use SwimlogStandalone\WorkoutEditor as E;use SwimLogEvaluation\Workout;use SwimLogEvaluation\Manual_Workout as M;
$user=get_user_by('login','synthetic_entry');$owner=(int)$user->ID;wp_set_current_user($owner);$_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie($owner,time()+3600,'logged_in');$checks=0;
$check=static function($ok)use(&$checks){++$checks;if(!$ok)throw new RuntimeException('Workout editor assertion '.$checks.' failed.');};
\SwimlogStandalone\WorkoutEntry::ready($owner,'manual');
$values=array('workout_date'=>'2001-05-06','workout_time'=>'08:00','location_id'=>'0','pool_length'=>'25','course_unit'=>'m','total_distance'=>'100','duration'=>'2:00','notes'=>'Synthetic editor initial');
$set=\SwimlogStandalone\WorkoutEntry::blankSet();$set['repetitions']='1';$set['distance']='100';$set['stroke']='FR';$set['timing']='measured_rep';$set['rep_times']='2:00';
$doc=\SwimlogStandalone\WorkoutEntry::document($owner,$values,array($set));
$result=M::save($owner,$doc);if(is_wp_error($result))throw new RuntimeException($result->get_error_message());$id=(string)$result['workout_id'];
function edit_input($owner,$id,$op,$extra=array()){$item=E::load($owner,$id);return array('workout_id'=>$id,'operation'=>$op,'nonce'=>wp_create_nonce('standalone_workout_'.$op.'_'.$id),'revision'=>$item['revision'])+$extra;}
function edit_submit($owner,$input){return E::submit($owner,$input,array(),'https://swimlog.example.test','https://swimlog.example.test');}
$before=Workout::get_for_user((int)$id,$owner);$input=edit_input($owner,$id,'metadata',array('location_id'=>'0','notes'=>'Synthetic edited','make_public'=>'1'));
$check(is_wp_error(E::submit($owner,$input,array(),'https://evil.example.test','https://swimlog.example.test')));
$check(is_wp_error(edit_submit($owner,array_replace($input,array('nonce'=>'bad')))));
$check(is_wp_error(edit_submit($owner,$input+array('user_id'=>'1'))));
$check(isset(edit_submit($owner,$input)['url'])&&E::publicPreference($owner,$id));
$after=Workout::get_for_user((int)$id,$owner);$check($after->notes==='Synthetic edited'&&$after->pool_length_m===$before->pool_length_m&&$after->total_distance_m===$before->total_distance_m);
$check(is_wp_error(edit_submit($owner,$input)));
$check(isset(edit_submit($owner,edit_input($owner,$id,'metadata',array('location_id'=>'0','notes'=>'Synthetic edited')))['url'])&&!E::publicPreference($owner,$id));
$check(is_wp_error(edit_submit($owner,edit_input($owner,$id,'delete'))));
$check(is_wp_error(edit_submit($owner,edit_input($owner,$id,'post'))));$user->add_cap('edit_posts');
$postInput=edit_input($owner,$id,'post');$check(isset(edit_submit($owner,$postInput)['url']));$posts=E::posts((int)$id);$check(count($posts)===1&&$posts[0]->post_status==='draft'&&(int)$posts[0]->post_author===$owner);
$check(isset(edit_submit($owner,$postInput)['url'])&&count(E::posts((int)$id))===1);
$preview=E::preview($id,$owner);$check(!is_wp_error($preview));
$conversion=edit_input($owner,$id,'convert',array('source_hash'=>$preview['hash'],'title'=>'Synthetic converted workout','planned_date'=>'','confirm'=>'1'));
$r=edit_submit($owner,$conversion);$check(isset($r['url'])&&strpos($r['url'],'structure=')!==false);$check(edit_submit($owner,$conversion)===$r);
$check(is_wp_error(edit_submit($owner,edit_input($owner,$id,'rebuild',array('confirm'=>'1'))))&&Workout::get_for_user((int)$id,$owner));
$other=(int)get_user_by('login','synthetic_swimmer')->ID;$check(is_wp_error(E::load($other,$id)));
$check(isset(edit_submit($owner,edit_input($owner,$id,'delete',array('confirm'=>'1')))['url'])&&!Workout::get_for_user((int)$id,$owner)&&!E::publicPreference($owner,$id));
foreach($posts as$post)wp_delete_post($post->ID,true);$user->remove_cap('edit_posts');
echo 'Workout editor integration passed: '.$checks." assertions.\n";

<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable synthetic environment required.');
require_once dirname(__DIR__).'/app/Auth.php';require_once dirname(__DIR__).'/app/WorkoutAssociations.php';require_once dirname(__DIR__).'/app/StructuredWorkouts.php';
use SwimLogEvaluation\Planned_Workout as Plans;use SwimLogEvaluation\Planned_Structure_Link as Links;use SwimLogEvaluation\Structured_Workout_Store as Store;
$f=json_decode(file_get_contents('/tmp/swimlog-sharing.json'),true);$owner=(int)$f['owner'];wp_set_current_user($owner);$_COOKIE[LOGGED_IN_COOKIE]=$f['cookie'];
if(is_wp_error(\SwimlogStandalone\WorkoutAssociations::ready($owner)))throw new RuntimeException('Unlink plugin services unavailable.');
$old=get_user_meta($owner,'synthetic_association_plans',true);if(is_array($old))foreach($old as$row){Plans::clear_completed_workout($row['plan'],$owner);Links::detach($row['plan'],$owner);Plans::delete($row['plan'],$owner);wp_delete_post($row['structure'],true);}
$form=\SwimlogStandalone\StructuredWorkouts::editor($owner,'new');$pairs=array();
foreach(array('pool','open_water')as$type){
 $plan=Plans::save($owner,array('title'=>'Synthetic unlink '.$type,'planned_date'=>'2026-01-01','workout_type'=>$type,'workout_instructions'=>'Synthetic unchanged instructions'));
 if(is_wp_error($plan))throw new RuntimeException('Association plan seed: '.$plan->get_error_code());
 $structure=Store::save($owner,'Synthetic unlink prescription '.$type,$form['document']);
 if(is_wp_error($structure))throw new RuntimeException('Association structure seed: '.$structure->get_error_code());
 $attached=Links::attach($plan,$structure,$owner);if(is_wp_error($attached))throw new RuntimeException('Association attach seed: '.$attached->get_error_code());
 $matched=Plans::associate_completed_workout($plan,$owner,$f['ids'][$type]);if(is_wp_error($matched))throw new RuntimeException('Association match seed: '.$matched->get_error_code());
 $pairs[$type]=array('plan'=>$plan,'structure'=>$structure,'workout'=>$f['ids'][$type]);
}
update_user_meta($owner,'synthetic_association_plans',$pairs);$f['pairs']=$pairs;file_put_contents('/tmp/swimlog-associations.json',wp_json_encode($f));chmod('/tmp/swimlog-associations.json',0600);

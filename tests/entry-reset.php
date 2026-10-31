<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable test flag required.');
$user=get_user_by('login','synthetic_entry');if(!$user)throw new RuntimeException('Synthetic entry account required.');
require_once SWIMLOG_EVALUATION_DIR.'includes/class-workout.php';require_once SWIMLOG_EVALUATION_DIR.'includes/class-database.php';
wp_set_current_user($user->ID);
for($i=0;$i<20;$i++){$page=\SwimLogEvaluation\Workout::query_for_user($user->ID,array(),1,100);if(!$page['rows'])break;foreach($page['rows']as$row)if(is_wp_error(\SwimLogEvaluation\Workout::delete($row->id,$user->ID)))throw new RuntimeException('Synthetic entry reset failed.');}
// Manual source rows are intentionally test-owned; do not retain orphans after fixture cleanup.
\SwimLogEvaluation\Database::write('delete',\SwimLogEvaluation\Database::table('manual_workouts'),array('user_id'=>$user->ID));

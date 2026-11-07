<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1') throw new RuntimeException('Disposable test flag required.');
$owner=get_user_by('login','synthetic_swimmer');$other=get_user_by('login','synthetic_other');$other->add_cap('swimlog_manage_own_workouts');
wp_set_current_user($owner->ID);
if(!get_user_meta($owner->ID,'standalone_planned_fixture',true)) {
    require_once SWIMLOG_EVALUATION_DIR.'includes/class-planned-workout.php';
    $plans=\SwimLogEvaluation\Planned_Workout::all_for_user($owner->ID);
    $ids=array('plan'=>(int)$plans[0]->id);
    foreach(array('matched','empty','broken','foreign') as $key) {
        wp_set_current_user($key==='foreign'?$other->ID:$owner->ID);
        $id=\SwimLogEvaluation\Planned_Workout::save($key==='foreign'?$other->ID:$owner->ID,array('title'=>$key==='foreign'?'FOREIGN_PRIVATE_PLAN':'Synthetic '.$key.' plan','planned_date'=>wp_date('Y-m-d'),'workout_type'=>'pool'));
        if(is_wp_error($id))throw new RuntimeException('Synthetic planned setup failed.');$ids[$key]=$id;
    }
    wp_set_current_user($owner->ID);
    $workouts=get_user_meta($owner->ID,'standalone_detail_fixture',true);
    $ok=\SwimLogEvaluation\Planned_Workout::associate_completed_workout($ids['matched'],$owner->ID,$workouts['pool']);
    if(is_wp_error($ok))throw new RuntimeException('Synthetic match setup failed.');
    $ok=\SwimLogEvaluation\Planned_Workout::save($owner->ID,array('title'=>'Synthetic planned swim','planned_date'=>wp_date('Y-m-d'),'planned_start_time'=>'07:30','planned_distance_m'=>1000,'planned_duration_ms'=>1800000,'pool_course'=>'yd','pool_length_m'=>22.86,'primary_stroke'=>'BR','workout_instructions'=>"4 × 250 relaxed swimming\nRest between sets",'notes'=>'PRIVATE_PLAN_NOTES_CANARY'),$ids['plan']);
    if(is_wp_error($ok))throw new RuntimeException('Synthetic plan update failed.');
    require_once SWIMLOG_EVALUATION_DIR.'includes/class-structured-workout.php';
    require_once SWIMLOG_EVALUATION_DIR.'includes/class-structured-workout-parser.php';
    require_once SWIMLOG_EVALUATION_DIR.'includes/class-structured-workout-store.php';
    require_once SWIMLOG_EVALUATION_DIR.'includes/class-planned-structure-link.php';
    $parsed=\SwimLogEvaluation\Structured_Workout_Parser::parse('2 x (25 FR + 25 BR)','yd');
    if(is_wp_error($parsed))throw new RuntimeException('Synthetic structure parse failed.');
    $structure=\SwimLogEvaluation\Structured_Workout_Store::save($owner->ID,'Synthetic yard prescription',$parsed['document']);
    if(is_wp_error($structure)||is_wp_error(\SwimLogEvaluation\Planned_Structure_Link::attach($ids['plan'],$structure,$owner->ID)))throw new RuntimeException('Synthetic structure link failed.');
    $ids['structure']=$structure;
    wp_set_current_user($other->ID);
    $foreign=\SwimLogEvaluation\Structured_Workout_Store::save($other->ID,'FOREIGN_STRUCTURE_CANARY',$parsed['document']);
    if(is_wp_error($foreign))throw new RuntimeException('Synthetic foreign structure failed.');
    wp_set_current_user($owner->ID);
    // Deliberately corrupt legacy links must not expose another owner's records.
    update_post_meta($foreign,\SwimLogEvaluation\Planned_Structure_Link::META_KEY,$ids['broken']);
    \SwimLogEvaluation\Database::write('update',\SwimLogEvaluation\Database::table('planned_workouts'),array('completed_workout_id'=>$workouts['foreign']),array('id'=>$ids['broken']));
    update_user_meta($owner->ID,'standalone_planned_fixture',$ids);
}

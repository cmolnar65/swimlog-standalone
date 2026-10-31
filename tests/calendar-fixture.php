<?php
if (getenv('SWIMLOG_SYNTHETIC_TEST') !== '1') throw new RuntimeException('Disposable test flag required.');
$owner = get_user_by('login', 'synthetic_swimmer');
$other = get_user_by('login', 'synthetic_other');
$owner->add_cap('swimlog_manage_own_workouts');
$other->add_cap('swimlog_view_own_results', false);
if (!get_user_meta($owner->ID, 'standalone_calendar_fixture', true)) {
    global $wpdb;
    $now = current_time('mysql'); $day = wp_date('Y-m-d');
    foreach (array(array($owner->ID,'pool',2000),array($owner->ID,'running',3000),array($owner->ID,'walking',null),array($other->ID,'pool',7777)) as $record) {
        $ok = \SwimLogEvaluation\Database::write('insert', \SwimLogEvaluation\Database::table('workouts'), array('user_id'=>$record[0],'workout_type'=>$record[1],'workout_start'=>$day.' 08:00:00','total_distance_m'=>$record[2],'elapsed_time_ms'=>1800000,'notes'=>'PRIVATE_NOTES_CANARY','created_at'=>$now,'updated_at'=>$now));
        if (!$ok) throw new RuntimeException('Synthetic workout setup failed.');
    }
    require_once SWIMLOG_EVALUATION_DIR.'includes/class-event.php';
    foreach(array($owner->ID=>'Synthetic meet', $other->ID=>'FOREIGN_PRIVATE_EVENT') as $uid=>$name) {
        $event=\SwimLogEvaluation\Event::save($uid,array('event_name'=>$name,'event_date'=>$day,'distance_value'=>200,'course_unit'=>'m','stroke'=>'BR'));
        if (is_wp_error($event)) throw new RuntimeException('Synthetic event setup failed.');
    }
    $plan=\SwimLogEvaluation\Planned_Workout::save($owner->ID,array('title'=>'Synthetic planned swim','planned_date'=>$day,'planned_distance'=>1000,'planned_duration_minutes'=>30,'workout_type'=>'pool','pool_course'=>'m','workout_instructions'=>'4 × 250 relaxed swimming'));
    if (is_wp_error($plan)) throw new RuntimeException('Synthetic plan setup failed.');
    update_user_meta($owner->ID, 'standalone_calendar_fixture', true);
}

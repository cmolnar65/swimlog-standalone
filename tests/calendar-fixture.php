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
if (!get_user_meta($owner->ID,'standalone_detail_fixture',true)) {
    global $wpdb;
    $now=current_time('mysql'); $day=wp_date('Y-m-d');
    $workouts=\SwimLogEvaluation\Database::table('workouts');
    $ids=array();
    foreach(array('pool','running','walking') as $type) $ids[$type]=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $workouts WHERE user_id=%d AND workout_type=%s ORDER BY id LIMIT 1",$owner->ID,$type));
    $ids['foreign']=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $workouts WHERE user_id=%d ORDER BY id LIMIT 1",$other->ID));
    \SwimLogEvaluation\Database::write('insert',$workouts,array('user_id'=>$owner->ID,'workout_type'=>'open_water','workout_start'=>$day.' 09:00:00','total_distance_m'=>2500,'elapsed_time_ms'=>1800000,'notes'=>'PRIVATE_NOTES_CANARY','created_at'=>$now,'updated_at'=>$now));
    $ids['open_water']=(int)$wpdb->insert_id;
    \SwimLogEvaluation\Database::write('insert',\SwimLogEvaluation\Database::table('locations'),array('user_id'=>$owner->ID,'name'=>'Synthetic <img src=x onerror=alert(1)>','created_at'=>$now,'updated_at'=>$now));
    $location=(int)$wpdb->insert_id;
    \SwimLogEvaluation\Database::write('update',$workouts,array('location_id'=>$location,'pool_length_m'=>25,'original_pool_length'=>25,'pool_length_unit'=>'m','primary_stroke'=>'BR','avg_swolf'=>35),array('id'=>$ids['pool']));
    \SwimLogEvaluation\Database::write('update',$workouts,array('activity_environment'=>'indoor'),array('id'=>$ids['walking']));
    for($sequence=1;$sequence<=4;$sequence++) \SwimLogEvaluation\Database::write('insert',\SwimLogEvaluation\Database::table('laps'),array('workout_id'=>$ids['pool'],'sequence_no'=>$sequence,'distance_m'=>500,'elapsed_time_ms'=>450000,'stroke'=>'BR','raw_metadata'=>'PRIVATE_RAW_CANARY','created_at'=>$now));
    for($sequence=1;$sequence<=81;$sequence++) \SwimLogEvaluation\Database::write('insert',\SwimLogEvaluation\Database::table('lengths'),array('workout_id'=>$ids['pool'],'sequence_no'=>$sequence,'distance_m'=>$sequence===81?0:25,'elapsed_time_ms'=>$sequence===81?0:22500,'stroke'=>$sequence===81?null:'BR','stroke_count'=>$sequence===81?0:12,'swolf_score'=>$sequence===81?0:35,'length_type'=>$sequence===81?'rest':'active','raw_metadata'=>'PRIVATE_RAW_CANARY','created_at'=>$now));
    \SwimLogEvaluation\Database::write('insert',\SwimLogEvaluation\Database::table('performances'),array('user_id'=>$owner->ID,'workout_id'=>$ids['pool'],'distance_value'=>200,'course_unit'=>'m','stroke'=>'BR','duration_ms'=>180000,'is_personal_best'=>1,'evaluation_version'=>'synthetic-test','achieved_at'=>$day.' 08:00:00','created_at'=>$now));
    foreach(array('running','open_water') as $type) {
        foreach(array(1,2,3,4,5) as $sequence) \SwimLogEvaluation\Database::write('insert',\SwimLogEvaluation\Database::table('trackpoints'),array('workout_id'=>$ids[$type],'sequence_no'=>$sequence,'recorded_at'=>$day.' 09:0'.($sequence-1).':00','latitude'=>$sequence===3?null:10+$sequence/1000,'longitude'=>$sequence===3?null:20+$sequence/1000,'distance_m'=>($sequence-1)*625,'created_at'=>$now));
    }
    update_user_meta($owner->ID,'standalone_detail_fixture',$ids);
}

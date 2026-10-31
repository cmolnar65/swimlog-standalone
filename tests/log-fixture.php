<?php
if (getenv('SWIMLOG_SYNTHETIC_TEST') !== '1') throw new RuntimeException('Disposable test flag required.');
$owner=get_user_by('login','synthetic_swimmer');
if (!get_user_meta($owner->ID,'standalone_log_fixture',true)) {
    $now=current_time('mysql');
    $day=(new DateTimeImmutable('first day of previous month',wp_timezone()))->format('Y-m-15');
    for($i=0;$i<27;$i++) {
        $row=array('user_id'=>$owner->ID,'workout_type'=>$i===26?'running':'pool','workout_start'=>$day.sprintf(' 07:%02d:00',$i),'total_distance_m'=>$i===26?0:($i===25?91.44:250),'elapsed_time_ms'=>150000,'notes'=>'PRIVATE_LOG_CANARY','created_at'=>$now,'updated_at'=>$now);
        if($i===25) $row+=array('original_distance'=>100,'original_distance_unit'=>'yd','pool_length_unit'=>'yd');
        if(!\SwimLogEvaluation\Database::write('insert',\SwimLogEvaluation\Database::table('workouts'),$row)) throw new RuntimeException('Synthetic log setup failed.');
    }
    update_user_meta($owner->ID,'standalone_log_fixture',$day);
}

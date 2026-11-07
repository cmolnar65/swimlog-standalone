<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable test flag required.');
require_once SWIMLOG_EVALUATION_DIR.'includes/class-event.php';require_once SWIMLOG_EVALUATION_DIR.'includes/class-location.php';
$owner=get_user_by('login','synthetic_swimmer');$other=get_user_by('login','synthetic_other');$owner->add_cap('swimlog_manage_own_events');
if(!get_user_meta($owner->ID,'standalone_event_fixture',true)) {
    $own=\SwimLogEvaluation\Event::all_for_user($owner->ID,'all');$foreign=\SwimLogEvaluation\Event::all_for_user($other->ID,'all');
    $location=\SwimLogEvaluation\Location::save($owner->ID,array('name'=>'Synthetic event pool','pool_length'=>25,'pool_unit'=>'yd','notes'=>'PRIVATE_LOCATION_NOTES_CANARY'));
    $foreignLocation=\SwimLogEvaluation\Location::save($other->ID,array('name'=>'FOREIGN_EVENT_LOCATION','pool_length'=>25,'pool_unit'=>'m'));
    if(is_wp_error($location)||is_wp_error($foreignLocation))throw new RuntimeException('Synthetic event locations failed.');
    $data=get_object_vars($own[0]);$data['event_time']='07:30';$data['location_id']=$location;
    $saved=\SwimLogEvaluation\Event::save($owner->ID,$data,$own[0]->id);
    $result=\SwimLogEvaluation\Event::save_result($own[0]->id,$owner->ID,'1:23.456','2','PRIVATE_EVENT_NOTES_CANARY');
    if(is_wp_error($saved)||is_wp_error($result))throw new RuntimeException('Synthetic event result failed.');
    update_user_meta($owner->ID,'standalone_event_fixture',array('own'=>(int)$own[0]->id,'foreign'=>(int)$foreign[0]->id,'location'=>(int)$location,'foreign_location'=>(int)$foreignLocation));
}

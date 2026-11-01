<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable synthetic environment required.');
use SwimLogEvaluation\Database as D;
$u=get_user_by('login','synthetic_readiness');if(!$u){$id=wp_create_user('synthetic_readiness','synthetic-readiness-only','readiness@example.test');$u=get_user_by('id',$id);}
foreach(array('swimlog_view_own_results','swimlog_manage_own_workouts','swimlog_manage_own_events')as$cap)$u->add_cap($cap);
$owner=(int)$u->ID;$ids=get_user_meta($owner,'synthetic_readiness_ids',true);
if(!$ids){
 global $wpdb;$ids=array('events'=>array(),'workouts'=>array());$now=current_time('mysql');$today=new DateTimeImmutable('today',wp_timezone());
 foreach(array('first'=>array('pool','m','FR','-2 days',70000),'latest'=>array('pool','m','FR','-1 day',65000),'pb'=>array('pool','m','FR','-100 days',60000),'yard'=>array('pool','yd','FR','-1 day',80000),'breast'=>array('pool','m','BR','-1 day',90000),'open'=>array('open_water','m','FR','-1 day',null),'run'=>array('running','m','FR','-1 day',null),'walk'=>array('walking','m','FR','-1 day',null),'future'=>array('pool','m','FR','+1 day',null))as$key=>$r){
  $date=$today->modify($r[3])->format('Y-m-d 12:00:00');
  D::write('insert',D::table('workouts'),array('user_id'=>$owner,'workout_type'=>$r[0],'workout_start'=>$date,'pool_length_unit'=>$r[1],'original_pool_length'=>25,'pool_length_m'=>25,'total_distance_m'=>100,'elapsed_time_ms'=>70000,'notes'=>'PRIVATE_READINESS_NOTES','device_model'=>'PRIVATE_READINESS_DEVICE','created_at'=>$now,'updated_at'=>$now));$id=(int)$wpdb->insert_id;$ids['workouts'][$key]=$id;
  foreach(array(array('active',100,70000),array('idle',0,999999))as$i=>$length)D::write('insert',D::table('lengths'),array('workout_id'=>$id,'sequence_no'=>$i+1,'length_type'=>$length[0],'distance_m'=>$length[1],'elapsed_time_ms'=>$length[2],'stroke'=>$r[2],'created_at'=>$now));
  if($r[4]!==null)D::write('insert',D::table('performances'),array('user_id'=>$owner,'workout_id'=>$id,'distance_value'=>100,'course_unit'=>$r[1],'stroke'=>$r[2],'duration_ms'=>$r[4],'is_personal_best'=>$key==='pb'?1:0,'evaluation_version'=>'synthetic','achieved_at'=>$date,'created_at'=>$now));
 }
 for($i=0;$i<22;$i++){
  D::write('insert',D::table('events'),array('user_id'=>$owner,'event_name'=>'Synthetic readiness event '.$i,'event_date'=>$today->modify('+'.($i+1).' days')->format('Y-m-d'),'distance_value'=>100,'course_unit'=>'m','stroke'=>'FR','notes'=>'PRIVATE_EVENT_READINESS_NOTES','created_at'=>$now,'updated_at'=>$now));$ids['events'][]=(int)$wpdb->insert_id;
 }
 D::write('insert',D::table('events'),array('user_id'=>$owner,'event_name'=>'Synthetic past readiness event','event_date'=>$today->modify('-1 day')->format('Y-m-d'),'distance_value'=>100,'course_unit'=>'m','stroke'=>'FR','created_at'=>$now,'updated_at'=>$now));$ids['past']=(int)$wpdb->insert_id;
 update_user_meta($owner,'synthetic_readiness_ids',$ids);
}
$f=json_decode(file_get_contents('/tmp/swimlog-sharing.json'),true);file_put_contents('/tmp/swimlog-readiness.json',wp_json_encode(array('owner'=>$owner,'ids'=>$ids,'name'=>LOGGED_IN_COOKIE,'cookie'=>wp_generate_auth_cookie($owner,time()+3600,'logged_in'),'manager'=>$f['manager'])));chmod('/tmp/swimlog-readiness.json',0600);

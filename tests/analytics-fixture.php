<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable synthetic environment required.');
use SwimLogEvaluation\Database as D;
$u=get_user_by('login','synthetic_analytics');if(!$u){$id=wp_create_user('synthetic_analytics','synthetic-analytics-only','analytics@example.test');$u=get_user_by('id',$id);}
foreach(array('swimlog_view_own_results','swimlog_manage_own_workouts')as$cap)$u->add_cap($cap);$owner=(int)$u->ID;$ids=get_user_meta($owner,'synthetic_analytics_ids',true);
if(!$ids){
 global $wpdb;$ids=array();$now=current_time('mysql');$today=new DateTimeImmutable('today',wp_timezone());
 foreach(array('pool'=>array('pool','m',75,90000,80000,'-1 day'),'yard'=>array('pool','yd',22.86,30000,null,'-1 day'),'open_water'=>array('open_water','m',1000,600000,550000,'-1 day'),'previous'=>array('pool','m',500,300000,null,'-7 months'),'old'=>array('pool','m',999999,999999,null,'-49 months'),'future'=>array('pool','m',999999,999999,null,'+1 day'),'running'=>array('running','m',999999,999999,null,'-1 day'),'walking'=>array('walking','m',999999,999999,null,'-1 day'))as$key=>$r){
  D::write('insert',D::table('workouts'),array('user_id'=>$owner,'workout_type'=>$r[0],'workout_start'=>$today->modify($r[5])->format('Y-m-d 12:00:00'),'pool_length_unit'=>$r[1],'original_pool_length'=>25,'pool_length_m'=>$r[1]==='yd'?22.86:25,'total_distance_m'=>$r[2],'elapsed_time_ms'=>$r[3],'moving_time_ms'=>$r[4],'notes'=>'PRIVATE_ANALYTICS_NOTES','device_model'=>'PRIVATE_ANALYTICS_DEVICE','created_at'=>$now,'updated_at'=>$now));$id=(int)$wpdb->insert_id;$ids[$key]=$id;
  $rows=$key==='pool'?array(array(1,'active',25,30000,'FR',10),array(2,'active',25,null,'FR',null),array(3,'active',25,60000,'BR',20),array(4,'idle',0,999999,'FR',99),array(5,'active',0,999999,'FR',99)):array(array(1,'active',$key==='yard'?22.86:$r[2],30000,'FR',12));
  foreach($rows as$l)D::write('insert',D::table('lengths'),array('workout_id'=>$id,'sequence_no'=>$l[0],'length_type'=>$l[1],'distance_m'=>$l[2],'elapsed_time_ms'=>$l[3],'stroke'=>$l[4],'stroke_count'=>$l[5],'created_at'=>$now));
 }
 update_user_meta($owner,'synthetic_analytics_ids',$ids);
}
$f=json_decode(file_get_contents('/tmp/swimlog-sharing.json'),true);$data=array('owner'=>$owner,'ids'=>$ids,'name'=>LOGGED_IN_COOKIE,'cookie'=>wp_generate_auth_cookie($owner,time()+3600,'logged_in'),'manager'=>$f['manager']);file_put_contents('/tmp/swimlog-analytics.json',wp_json_encode($data));chmod('/tmp/swimlog-analytics.json',0600);

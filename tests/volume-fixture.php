<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable synthetic environment required.');
use SwimLogEvaluation\Database as D;
$u=get_user_by('login','synthetic_volume');if(!$u){$id=wp_create_user('synthetic_volume','synthetic-volume-only','volume@example.test');$u=get_user_by('id',$id);}
foreach(array('swimlog_view_own_results','swimlog_manage_own_workouts')as$cap)$u->add_cap($cap);
$owner=(int)$u->ID;$ids=get_user_meta($owner,'synthetic_volume_ids',true);
if(!$ids){
 global $wpdb;$ids=array();$today=wp_date('Y-m-d').' 12:00:00';$now=current_time('mysql');
 foreach(array('meter','yard','open_water','running','walking','old')as$key){$type=in_array($key,array('meter','yard','old'),true)?'pool':$key;$unit=$key==='yard'?'yd':'m';$start=$key==='old'?(new DateTimeImmutable('today',wp_timezone()))->modify('-25 months')->format('Y-m-d 12:00:00'):$today;
  D::write('insert',D::table('workouts'),array('user_id'=>$owner,'workout_type'=>$type,'workout_start'=>$start,'pool_length_unit'=>$unit,'created_at'=>$now,'updated_at'=>$now));$id=(int)$wpdb->insert_id;$ids[$key]=$id;
  $distance=$key==='yard'?22.86:($key==='meter'?25:999999);
  D::write('insert',D::table('lengths'),array('workout_id'=>$id,'sequence_no'=>1,'length_type'=>'active','distance_m'=>$distance,'elapsed_time_ms'=>30000,'stroke'=>'FR','created_at'=>$now));
  if($key==='meter')foreach(array(array(2,'active',50,60000,'BR'),array(3,'idle',0,999999,'FR'),array(4,'active',0,999999,'FR'))as$r)D::write('insert',D::table('lengths'),array('workout_id'=>$id,'sequence_no'=>$r[0],'length_type'=>$r[1],'distance_m'=>$r[2],'elapsed_time_ms'=>$r[3],'stroke'=>$r[4],'created_at'=>$now));
 }
 update_user_meta($owner,'synthetic_volume_ids',$ids);
}
$sharing=json_decode(file_get_contents('/tmp/swimlog-sharing.json'),true);
$f=array('owner'=>$owner,'ids'=>$ids,'name'=>LOGGED_IN_COOKIE,'cookie'=>wp_generate_auth_cookie($owner,time()+3600,'logged_in'),'other'=>$sharing['cookie'],'manager'=>$sharing['manager']);file_put_contents('/tmp/swimlog-volume.json',wp_json_encode($f));chmod('/tmp/swimlog-volume.json',0600);

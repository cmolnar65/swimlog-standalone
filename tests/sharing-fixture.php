<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable test flag required.');
use SwimLogEvaluation\Database as D;use SwimLogEvaluation\Workout_Visibility as V;
$u=get_user_by('login','synthetic_sharing');
if(!$u){$id=wp_create_user('synthetic_sharing','synthetic-sharing-login','sharing@example.test');$u=get_user_by('id',$id);}
foreach(array('swimlog_view_own_results','swimlog_manage_own_workouts','swimlog_upload_workouts','edit_posts')as$cap)$u->add_cap($cap);
$owner=(int)$u->ID;wp_set_current_user($owner);$ids=get_user_meta($owner,'standalone_sharing_fixture',true);
if(!$ids){
 global $wpdb;$ids=array();$now='2026-01-01 00:00:00';
 foreach(array('pool','open_water','running','walking','cycling','private','pending')as$i=>$key){
  $type=in_array($key,array('private','pending'),true)?'pool':$key;
  $ok=D::write('insert',D::table('workouts'),array('user_id'=>$owner,'workout_type'=>$type,'workout_start'=>sprintf('2026-01-%02d 08:12:34',$i+1),'total_distance_m'=>1000,'original_distance'=>1000,'original_distance_unit'=>'m','original_pool_length'=>$type==='pool'?25:null,'pool_length_unit'=>$type==='pool'?'m':null,'elapsed_time_ms'=>900000,'moving_time_ms'=>850000,'primary_stroke'=>$type==='pool'?'FR':null,'notes'=>'PRIVATE_SHARING_NOTES','device_model'=>'PRIVATE_SHARING_DEVICE','average_heart_rate'=>123,'average_cadence'=>88,'created_at'=>$now,'updated_at'=>$now));
  if(!$ok)throw new RuntimeException('Sharing seed failed.');$ids[$key]=(int)$wpdb->insert_id;
  foreach(array(array(1,40,-105),array(2,40.001,-105.001),array(4,40.002,-105.002),array(5,40.003,-105.003))as$p)D::write('insert',D::table('trackpoints'),array('workout_id'=>$ids[$key],'sequence_no'=>$p[0],'recorded_at'=>'2026-01-01 08:12:'.str_pad((string)$p[0],2,'0',STR_PAD_LEFT),'latitude'=>$p[1],'longitude'=>$p[2],'heart_rate'=>123,'created_at'=>$now));
 }
 D::write('insert',D::table('lengths'),array('workout_id'=>$ids['pool'],'sequence_no'=>1,'length_type'=>'active','distance_m'=>25,'elapsed_time_ms'=>30000,'stroke'=>'FR','created_at'=>$now));
 foreach(array(array('pool',60000,0),array('private',30000,1))as$p)D::write('insert',D::table('performances'),array('user_id'=>$owner,'workout_id'=>$ids[$p[0]],'distance_value'=>100,'course_unit'=>'m','stroke'=>'FR','duration_ms'=>$p[1],'achieved_at'=>$now,'is_personal_best'=>$p[2],'evaluation_version'=>'synthetic','created_at'=>$now));
 update_user_meta($owner,'standalone_sharing_fixture',$ids);
}
update_user_meta($owner,'swimlog_public_results','1');delete_user_meta($owner,'swimlog_public_routes');delete_user_meta($owner,'swimlog_public_ows_routes');
foreach($ids as$key=>$id){if($key==='cycling')continue;$s=V::get_for_owner($id);$r=V::set_for_owner($id,array('visibility'=>in_array($key,array('pool','open_water','running','walking'),true)?'public':'private','route_public'=>false),wp_create_nonce('swimlog_workout_visibility_'.$id),$s['revision']);if(is_wp_error($r))throw new RuntimeException('Sharing fixture visibility failed.');}
update_user_meta($owner,'_standalone_workout_public_'.$ids['pending'],'1');
$manager=get_user_by('login','synthetic_sharing_manager');if(!$manager){$managerId=wp_create_user('synthetic_sharing_manager','synthetic-manager-login','manager@example.test');$manager=get_user_by('id',$managerId);}
foreach(array('swimlog_view_own_results','swimlog_manage_own_workouts','swimlog_manage_all_users')as$cap)$manager->add_cap($cap);
file_put_contents('/tmp/swimlog-sharing.json',wp_json_encode(array('name'=>LOGGED_IN_COOKIE,'cookie'=>wp_generate_auth_cookie($owner,time()+3600,'logged_in'),'manager'=>wp_generate_auth_cookie($manager->ID,time()+3600,'logged_in'),'owner'=>$owner,'ids'=>$ids)));

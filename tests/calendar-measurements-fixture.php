<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable synthetic environment required.');
require_once SWIMLOG_EVALUATION_DIR.'includes/class-health-measurement-store.php';
use SwimLogEvaluation\Health_Measurement_Store as S;use SwimLogEvaluation\Database as D;
$u=get_user_by('login','synthetic_calendar_health');if(!$u){$id=wp_create_user('synthetic_calendar_health','synthetic-only','calendar-health@example.test');$u=get_user_by('id',$id);}foreach(array('swimlog_view_own_results','swimlog_manage_own_workouts')as$cap)$u->add_cap($cap);$owner=(int)$u->ID;wp_set_current_user($owner);
if(!get_user_meta($owner,'synthetic_calendar_health_seeded',true)){
 $r=array('provider'=>'garmin','category'=>'spo2','calendar_date'=>'2026-11-01','fetched_at_utc'=>'2026-11-02T12:00:00Z','summary'=>array('average'=>array('value'=>96.4,'unit'=>'%')),'context'=>array('sleep_start_utc'=>'2026-11-01T04:00:00Z','sleep_end_utc'=>'2026-11-01T14:00:00Z','sleep_start_local'=>'2026-10-31T22:00:00','sleep_end_local'=>'2026-11-01T07:00:00','device'=>'PRIVATE_HEALTH_SOURCE'));
 $reading=array('role'=>'sample','observed_at_utc'=>'2026-10-31T23:30:00Z','calendar_date'=>'2026-11-01','date_basis'=>'provider_calendar','values'=>array('spo2'=>array('value'=>97,'unit'=>'%')),'provenance'=>array('device'=>'PRIVATE_HEALTH_SOURCE'));
 $a=$reading;$a['role']='hourly_average';$a['observed_at_utc']='2026-11-01T01:00:00Z';$conflict=$reading;$conflict['values']['spo2']['value']=94;
 if(is_wp_error(S::save_report($owner,$r,array($reading,$a,$conflict))))throw new RuntimeException('Synthetic calendar summary failed.');
 $r['category']='weight';$r['summary']=array();$r['context']=array();$reading['values']=array('weight'=>array('value'=>82.5,'unit'=>'kg'));if(is_wp_error(S::save_report($owner,$r,array($reading))))throw new RuntimeException('Synthetic calendar weight failed.');
 $now=current_time('mysql');D::write('insert',D::table('workouts'),array('user_id'=>$owner,'workout_type'=>'pool','workout_start'=>'2026-11-01 12:00:00','pool_length_unit'=>'m','total_distance_m'=>100,'created_at'=>$now,'updated_at'=>$now));update_user_meta($owner,'synthetic_calendar_health_seeded',true);
}
$f=json_decode(file_get_contents('/tmp/swimlog-sharing.json'),true);file_put_contents('/tmp/swimlog-calendar-health.json',wp_json_encode(array('owner'=>$owner,'name'=>LOGGED_IN_COOKIE,'cookie'=>wp_generate_auth_cookie($owner,time()+3600,'logged_in'),'manager'=>$f['manager'])));chmod('/tmp/swimlog-calendar-health.json',0600);

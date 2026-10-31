<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable test flag required.');
require_once dirname(__DIR__).'/app/Auth.php';require_once dirname(__DIR__).'/app/WorkoutEditor.php';require_once dirname(__DIR__).'/app/PublicWorkout.php';
use SwimlogStandalone\WorkoutEditor as E;use SwimlogStandalone\Sharing as S;use SwimlogStandalone\PublicWorkout as P;use SwimLogEvaluation\Workout_Visibility as V;use SwimLogEvaluation\Database as D;
$f=json_decode(file_get_contents('/tmp/swimlog-sharing.json'),true);$owner=(int)$f['owner'];$ids=$f['ids'];$GLOBALS['sharing_owner']=$owner;wp_set_current_user($owner);$_COOKIE[LOGGED_IN_COOKIE]=$f['cookie'];$checks=0;
$check=static function($ok)use(&$checks){++$checks;if(!$ok)throw new RuntimeException('Sharing assertion '.$checks.' failed.');};
function sharing_input($id,$op='visibility',$extra=array()){
 $owner=$GLOBALS['sharing_owner'];$item=E::load($owner,(string)$id);$s=$item['visibility'];
 return array('workout_id'=>(string)$id,'operation'=>$op,'nonce'=>wp_create_nonce('standalone_workout_'.$op.'_'.$id),'revision'=>$item['revision'],'visibility_nonce'=>wp_create_nonce('swimlog_workout_visibility_'.$id),'visibility_revision'=>(string)$s['revision'])+$extra;
}
function sharing_submit($in,$files=array()){$owner=$GLOBALS['sharing_owner'];return E::submit($owner,$in,$files,'https://swimlog.example.test','https://swimlog.example.test');}
$check(S::ready());$id=$ids['pool'];
$in=sharing_input($id,'visibility',array('make_public'=>'1','share_route'=>'1'));
foreach(array('nonce','visibility_nonce')as$key){$bad=$in;unset($bad[$key]);$check(is_wp_error(sharing_submit($bad)));$bad[$key]='bad';$check(is_wp_error(sharing_submit($bad)));}
$check(is_wp_error(E::submit($owner,$in,array(),'https://evil.example.test','https://swimlog.example.test')));
foreach(array('-1','+1','1.0','1e2','999999999999999999999999999','')as$bad)$check(is_wp_error(sharing_submit(array_replace($in,array('visibility_revision'=>$bad)))));
$check(is_wp_error(sharing_submit($in+array('owner_id'=>'1'))));
$check(is_wp_error(sharing_submit(array_replace($in,array('make_public'=>'false')))));
$check(isset(sharing_submit($in)['url']));$check(is_wp_error(sharing_submit($in))&&sharing_submit($in)->get_error_data()['status']===409);
$in=sharing_input($id);$bad=$in;$bad['visibility_revision']=(string)((int)$in['visibility_revision']-1);$check(is_wp_error(sharing_submit($bad))&&V::is_public($id));
$check(null===V::get_public($id)['route']);
update_user_meta($owner,'swimlog_public_ows_routes','1');$check(is_array(V::get_public($id)['route']));
foreach(array('running','walking','open_water')as$key){
 $x=$ids[$key];$check(isset(sharing_submit(sharing_input($x,'visibility',array('make_public'=>'1','share_route'=>'1')))['url']));
 $check((V::get_public($x)['route']!==null)===($key==='open_water'));
}
update_user_meta($owner,'swimlog_public_routes','1');$run=V::get_public($ids['running']);$check(count($run['route']['segments'])===2);
$html=P::render($run,S::url('https://swimlog.example.test',$ids['running']));$check(substr_count($html,'<polyline')===2&&strpos($html,'PRIVATE_')===false&&strpos($html,'08:12:34')===false);
foreach(array('private','public')as$grant)foreach(array('0','1')as$global){
 update_user_meta($owner,'swimlog_public_results',$global);
 $extra=$grant==='public'?array('make_public'=>'1'):array();$check(isset(sharing_submit(sharing_input($id,'visibility',$extra))['url']));
 $check(!is_wp_error(S::publicWorkout((string)$id))===($global==='1'&&$grant==='public'));
}
update_user_meta($owner,'swimlog_public_results','1');
$check(isset(sharing_submit(sharing_input($ids['pending'],'adopt'))['url']));$s=V::get_for_owner($ids['pending']);$check($s['effective_public']&&!$s['route_public']&&!$s['pending_standalone_preference']);
$check(is_wp_error(sharing_submit(sharing_input($ids['pending'],'adopt'))));
$check(isset(sharing_submit(sharing_input($id,'visibility',array('make_public'=>'1')))['url']));
delete_user_meta($owner,'_standalone_workout_public_'.$id);$check(V::get_for_owner($id)['effective_public']);
$metadata=E::load($owner,(string)$id);$state=$metadata['visibility'];
$in=array('workout_id'=>(string)$id,'operation'=>'metadata','nonce'=>wp_create_nonce('standalone_workout_metadata_'.$id),'revision'=>$metadata['revision'],'location_id'=>'0','notes'=>'PRIVATE_SHARING_NOTES');
$check(is_wp_error(sharing_submit($in+array('make_public'=>'1'))));
$check(is_wp_error(sharing_submit($in,array('workout_photo_0'=>array('error'=>UPLOAD_ERR_PARTIAL))))&&V::get_for_owner($id)===$state);
$check(isset(sharing_submit($in)['url'])&&V::get_for_owner($id)===$state);
// A canonical grandfathered grant remains selected when provisional metadata is absent.
D::write('update',D::table('workout_visibility'),array('visibility'=>'legacy_public'),array('workout_id'=>$id));
delete_user_meta($owner,'_standalone_workout_public_'.$id);
$check(E::publicPreference($owner,$id)&&E::load($owner,(string)$id)['visibility']['visibility']==='legacy_public');

$check((int)V::query_public($owner,array(),1,1)['total']===5&&V::query_public($owner,array(),1,1)['pages']===5);
$check((int)V::personal_bests($owner,'m')[100]['FR']->duration_ms===60000&&count(V::progression($owner,100,'m','FR'))===1);
$check(is_wp_error(S::publicWorkout((string)$ids['private']))&&is_wp_error(S::publicWorkout((string)$ids['cycling'])));
$privateDto=V::get_public($ids['running']);foreach(array('notes','photos','location_name','device_model','average_heart_rate','average_cadence','measurements','raw_metadata','user_id','source_type')as$key)$check(!array_key_exists($key,$privateDto));
// Real foreign/admin identity, never an owner supplied in a browser field.
$oldCookie=$_COOKIE[LOGGED_IN_COOKIE];$oldIn=sharing_input($id);
foreach(array(0,(int)get_user_by('login','synthetic_sharing_manager')->ID)as$actor){
 wp_set_current_user($actor);$_COOKIE[LOGGED_IN_COOKIE]=$actor?wp_generate_auth_cookie($actor,time()+3600,'logged_in'):'';
 $check(!is_wp_error(S::publicWorkout((string)$id)));
 foreach(array('metadata','post','rebuild','delete','convert','visibility','adopt')as$op)$check(is_wp_error(E::submit($actor,array_replace($oldIn,array('operation'=>$op)),array(),'https://swimlog.example.test','https://swimlog.example.test')));
}
wp_set_current_user($owner);$_COOKIE[LOGGED_IN_COOKIE]=$oldCookie;
$version=get_option('swimlog_db_version');update_option('swimlog_db_version','1.4');$check(!S::ready()&&is_wp_error(S::publicWorkout((string)$id)));update_option('swimlog_db_version',$version);
$marker=D::table('workout_visibility');D::write('update',$marker,array('visibility'=>'pending'),array('workout_id'=>0));$check(!S::ready()&&is_wp_error(S::publicWorkout((string)$id)));D::write('update',$marker,array('visibility'=>'ready'),array('workout_id'=>0));
// Visibility saves never create posts; explicit draft creation never changes visibility.
$postsBefore=count(E::posts($id));$s=V::get_for_owner($id);
$check($postsBefore===0);
$editor=E::load($owner,(string)$id);$draft=array('workout_id'=>(string)$id,'operation'=>'post','nonce'=>wp_create_nonce('standalone_workout_post_'.$id),'revision'=>$editor['revision']);
$check(isset(sharing_submit($draft)['url'])&&count(E::posts($id))===1&&V::get_for_owner($id)===$s);
foreach(E::posts($id)as$post){$check($post->post_status==='draft');wp_delete_post($post->ID,true);}
// Canonical and pending cleanup belongs entirely to the plugin deletion service.
global $wpdb;D::write('insert',D::table('workouts'),array('user_id'=>$owner,'workout_type'=>'pool','workout_start'=>'2026-01-20 08:00:00','created_at'=>'2026-01-20 00:00:00','updated_at'=>'2026-01-20 00:00:00'));$deleteId=(int)$wpdb->insert_id;
$created=V::get_for_owner($deleteId);$granted=V::set_for_owner($deleteId,array('visibility'=>'public','route_public'=>false),wp_create_nonce('swimlog_workout_visibility_'.$deleteId),$created['revision']);$check(!is_wp_error($granted));update_user_meta($owner,'_standalone_workout_public_'.$deleteId,'1');
$editor=E::load($owner,(string)$deleteId);
$delete=array('workout_id'=>(string)$deleteId,'operation'=>'delete','nonce'=>wp_create_nonce('standalone_workout_delete_'.$deleteId),'revision'=>$editor['revision'],'confirm'=>'1');
$check(isset(sharing_submit($delete)['url'])&&is_wp_error(S::publicWorkout((string)$deleteId))&&get_user_meta($owner,'_standalone_workout_public_'.$deleteId,true)==='');
// Reset canonical fixtures for HTTP/browser checks; no app lifecycle/migration helper is called.
echo 'Canonical standalone sharing integration passed: '.$checks." assertions.\n";

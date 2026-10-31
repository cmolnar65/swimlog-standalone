<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable test flag required.');
require_once dirname(__DIR__).'/app/Auth.php';require_once dirname(__DIR__).'/app/StructuredWorkouts.php';
use SwimlogStandalone\StructuredWorkouts as SW;
use SwimLogEvaluation\Structured_Workout_Store as Store;
use SwimLogEvaluation\Planned_Workout as Plans;
use SwimLogEvaluation\Planned_Structure_Link as Links;
$f=json_decode(file_get_contents(getenv('SWIMLOG_WEB_FIXTURE')),true);$_COOKIE[LOGGED_IN_COOKIE]=$f['cookie'];$owner=(int)wp_validate_auth_cookie($f['cookie'],'logged_in');wp_set_current_user($owner);$other=(int)get_user_by('login','synthetic_other')->ID;
$GLOBALS['structured_checks']=0;
function structure_check($v){++$GLOBALS['structured_checks'];if(!$v)throw new RuntimeException('Structured assertion '.$GLOBALS['structured_checks'].' failed.');}
function structure_input($id,$op,$owner){$f=$id==='new'?SW::editor($owner,'new'):SW::item($owner,$id);return array('structure_id'=>(string)$id,'operation'=>$op,'nonce'=>wp_create_nonce('standalone_structure_'.$op.'_'.$id),'revision'=>$f['revision']);}
function structure_submit($owner,$input){return SW::submit($owner,$input,'https://swimlog.example.test','https://swimlog.example.test');}
structure_check(SW::ready($owner)===true);
$form=SW::editor($owner,'new');$input=structure_input('new','save',$owner)+array('title'=>'Synthetic structure integration','document'=>wp_json_encode($form['document']));
$r=structure_submit($owner,$input);structure_check(isset($r['url']));parse_str(parse_url($r['url'],PHP_URL_QUERY),$query);$id=(int)$query['structure'];
structure_check(!is_wp_error(Store::get($id,$owner))&&is_wp_error(Store::get($id,$other)));
structure_check(is_wp_error(SW::submit($owner,$input,'https://evil.example.test','https://swimlog.example.test')));
structure_check(is_wp_error(structure_submit($owner,array_replace($input,array('nonce'=>'bad')))));
structure_check(is_wp_error(structure_submit($owner,$input+array('user_id'=>(string)$other))));
foreach(array(null,0,-1,array('bad'),'1 OR 1=1')as$bad)structure_check(is_wp_error(SW::item($owner,$bad)));
$edit=structure_input($id,'save',$owner)+array('title'=>'Synthetic revised structure','document'=>$input['document']);structure_check(isset(structure_submit($owner,$edit)['url']));
structure_check(is_wp_error(structure_submit($owner,$edit)));
$bad=structure_input($id,'save',$owner)+array('title'=>'Retain title on invalid input','document'=>'{}');$r=structure_submit($owner,$bad);structure_check(isset($r['form'])&&is_wp_error($r['form']['error'])&&$r['form']['title']===$bad['title']);
$hidden=structure_submit($owner,structure_input($id,'hide',$owner));structure_check(isset($hidden['url'])&&Store::is_hidden($id,$owner));
$list=SW::library($owner,array('visibility'=>'hidden'));structure_check(in_array($id,array_column($list['rows'],'id'),true));
structure_check(isset(structure_submit($owner,structure_input($id,'unhide',$owner))['url'])&&!Store::is_hidden($id,$owner));
$plan=Plans::save($owner,array('title'=>'Synthetic structured link target','planned_date'=>wp_date('Y-m-d'),'workout_type'=>'pool','workout_instructions'=>'UNCHANGED_PRIVATE_PLAN_TEXT','notes'=>'PRIVATE_PLAN_NOTES_CANARY'));
structure_check(!is_wp_error($plan));$target=null;foreach(SW::targets($owner)as$row)if($row['id']===$plan)$target=$row;
structure_check($target!==null);$link=structure_input($id,'link',$owner)+array('plan_token'=>$target['id'].':'.$target['revision']);structure_check(isset(structure_submit($owner,$link)['url']));
structure_check(Links::for_plan($plan,$owner)['id']===$id&&Plans::get_for_user($plan,$owner)->workout_instructions==='UNCHANGED_PRIVATE_PLAN_TEXT');
structure_check(is_wp_error(structure_submit($owner,structure_input($id,'delete',$owner)+array('confirm_delete'=>'1'))));
structure_check(isset(structure_submit($owner,structure_input($id,'unlink',$owner))['url'])&&Links::for_plan($plan,$owner)===null);
// Stale plan tokens must not attach to a changed plan.
$target=null;foreach(SW::targets($owner)as$row)if($row['id']===$plan)$target=$row;
Plans::save($owner,array('title'=>'Synthetic changed link target','planned_date'=>wp_date('Y-m-d'),'workout_type'=>'pool'),$plan);
structure_check(is_wp_error(structure_submit($owner,structure_input($id,'link',$owner)+array('plan_token'=>$target['id'].':'.$target['revision']))));
update_post_meta($id,Store::META_RECORDED,$f['workouts']['pool']);structure_check(SW::item($owner,$id)['locked']&&is_wp_error(SW::editor($owner,$id)));
structure_check(is_wp_error(structure_submit($owner,structure_input($id,'save',$owner)+array('title'=>'Forbidden edit','document'=>$input['document']))));
$r=structure_submit($owner,structure_input($id,'duplicate',$owner));parse_str(parse_url($r['url'],PHP_URL_QUERY),$copyQuery);$copy=(int)$copyQuery['structure'];
structure_check($copy!==$id&&!Store::is_locked($copy,$owner)&&!Store::has_history($copy,$owner)&&!Store::is_hidden($copy,$owner));
structure_check(Store::get($id,$owner)['title']==='Synthetic revised structure');
structure_check(is_wp_error(structure_submit($owner,structure_input($id,'delete',$owner)+array('confirm_delete'=>'1'))));
foreach(array('txt','fit')as$format){$out=SW::download($owner,$id,$format,wp_create_nonce('standalone_structure_export_'.$id.'_'.$format));structure_check(!is_wp_error($out)&&strlen($out['bytes'])>10);if($format==='txt')structure_check(strpos($out['bytes'],'PRIVATE_PLAN_NOTES_CANARY')===false);}
structure_check(is_wp_error(SW::download($owner,$id,'txt','bad')));
structure_check(is_wp_error(structure_submit($owner,structure_input($copy,'delete',$owner))));
structure_check(isset(structure_submit($owner,structure_input($copy,'delete',$owner)+array('confirm_delete'=>'1'))['url'])&&is_wp_error(Store::get($copy,$owner)));
$foreign=Store::save($other,'FOREIGN_STRUCTURE_INTEGRATION',$form['document']);structure_check(is_wp_error(SW::item($owner,$foreign)));
$user=get_user_by('id',$owner);$user->add_cap('swimlog_manage_own_workouts',false);wp_set_current_user(0);wp_set_current_user($owner);structure_check(is_wp_error(SW::library($owner,array()))&&is_wp_error(SW::download($owner,$id,'txt','bad')));$user->add_cap('swimlog_manage_own_workouts');wp_set_current_user(0);wp_set_current_user($owner);
$schema=get_option('swimlog_db_version');update_option('swimlog_db_version','0');structure_check(is_wp_error(SW::ready($owner)));update_option('swimlog_db_version',$schema);
// Remove only the synthetic records created in this test; never a production test path.
wp_delete_post($id,true);wp_delete_post($foreign,true);Plans::delete($plan,$owner);
echo 'Structured workout integration passed: '.$GLOBALS['structured_checks']." assertions.\n";

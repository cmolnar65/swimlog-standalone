<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable test flag required.');
require_once dirname(__DIR__).'/app/Auth.php';require_once dirname(__DIR__).'/app/EventWorkflow.php';
use SwimlogStandalone\EventWorkflow as EW;use SwimLogEvaluation\Event;
$f=json_decode(file_get_contents(getenv('SWIMLOG_WEB_FIXTURE')),true);$_COOKIE[LOGGED_IN_COOKIE]=$f['cookie'];$owner=(int)wp_validate_auth_cookie($f['cookie'],'logged_in');wp_set_current_user($owner);$ids=$f['events'];
$GLOBALS['event_checks']=0;$GLOBALS['event_owner']=$owner;
function event_check($v){++$GLOBALS['event_checks'];if(!$v)throw new RuntimeException('Event assertion '.$GLOBALS['event_checks'].' failed.');}
function event_input($form){$fields=$form['operation']==='edit'?EW::FIELDS:array('result_time','placement','notes');return array_intersect_key($form['values'],array_flip($fields))+array('event_id'=>(string)$form['id'],'operation'=>$form['operation'],'nonce'=>wp_create_nonce('swimlog_event_'.$form['operation'].'_'.$form['id']),'revision'=>$form['revision'],'date'=>$form['context']['date'],'view'=>$form['context']['view']);}
function event_submit($input){return EW::submit($GLOBALS['event_owner'],$input,'https://swimlog.example.test','https://swimlog.example.test');}
$d=EW::detail($owner,$ids['own']);event_check(!is_wp_error($d)&&$d['result']['time']==='1:23.456'&&$d['result']['placement']===2);
$html=EW::render($d);event_check(strpos($html,'PRIVATE_EVENT_NOTES_CANARY')!==false&&strpos($html,'PRIVATE_LOCATION_NOTES_CANARY')===false&&strpos($html,'FOREIGN_EVENT_LOCATION')===false);
$foreign=EW::detail($owner,$ids['foreign']);$missing=EW::detail($owner,99999999);event_check(is_wp_error($foreign)&&$foreign->get_error_message()===$missing->get_error_message());
foreach(array(null,array('bad'),0,-1,'1 OR 1=1')as$id)event_check(is_wp_error(EW::detail($owner,$id)));
$input=event_input(EW::open($owner,'new','edit',array('view'=>'week')));$input['event_name']='Synthetic integration event';$input['event_date']='2001-02-03';$input['event_time']='07:30';$input['course_unit']='yd';$input['location_id']=(string)$ids['location'];$input['notes']="Quote \" and backslash \\";
$invalidCreate=event_submit(array_replace($input,array('event_date'=>'2026-02-30')));event_check(isset($invalidCreate['form'])&&$invalidCreate['form']['values']['event_name']===$input['event_name']&&$invalidCreate['form']['error']->get_error_data()['status']===422);
$r=event_submit($input);event_check(isset($r['id'])&&strpos($r['url'],'view=week')!==false);$id=$r['id'];$row=Event::get_for_user($id,$owner);
event_check($row->event_date==='2001-02-03'&&$row->course_unit==='yd'&&$row->notes===$input['notes']);
$form=EW::open($owner,$id,'result');$input=event_input($form);$input['result_time']='1:23.456';$input['placement']='2';$input['notes']='Synthetic result notes';$r=event_submit($input);event_check(isset($r['id']));
$row=Event::get_for_user($id,$owner);event_check((int)$row->result_time_ms===83456&&(int)$row->placement===2&&$row->event_name==='Synthetic integration event');
$edit=event_input(EW::open($owner,$id,'edit'));$edit['event_name']='Synthetic edited event';$r=event_submit($edit);$row=Event::get_for_user($id,$owner);event_check(isset($r['id'])&&(int)$row->result_time_ms===83456&&(int)$row->placement===2&&$row->notes==='Synthetic result notes');
$stale=event_submit($edit);event_check($stale['form']['error']->get_error_data()['status']===409);
$base=event_input(EW::open($owner,$id,'edit'));
foreach(array(array('event_name'=>''),array('event_name'=>str_repeat('x',192)),array('event_date'=>'2026-02-30'),array('event_time'=>'25:00'),array('distance_value'=>'200junk'),array('distance_value'=>'-200'),array('stroke'=>'bad'),array('course_unit'=>'bad'),array('location_id'=>(string)$ids['foreign_location']),array('notes'=>array('bad')),array('user_id'=>'99'),array('result_time_ms'=>'123'))as$bad){$r=event_submit(array_replace($base,$bad));event_check(isset($r['form'])&&is_wp_error($r['form']['error']));}
$base=event_input(EW::open($owner,$id,'result'));
foreach(array(array('result_time'=>'1:99'),array('result_time'=>'0:00'),array('placement'=>'-1'),array('placement'=>'1.5'),array('placement'=>'100001'),array('event_name'=>'forged'))as$bad){$r=event_submit(array_replace($base,$bad));event_check(isset($r['form'])&&is_wp_error($r['form']['error']));}
$clear=$base;$clear['result_time']='';$r=event_submit($clear);event_check($r['form']['error']->get_error_data()['status']===422&&(int)Event::get_for_user($id,$owner)->result_time_ms===83456);
$clear['clear_result']='1';$r=event_submit($clear);$row=Event::get_for_user($id,$owner);event_check(isset($r['id'])&&$row->result_time_ms===null&&(int)$row->placement===2);
event_check(is_wp_error(event_submit(array_replace($base,array('nonce'=>'bad'))))&&is_wp_error(EW::submit($owner,$base,'https://evil.example.test','https://swimlog.example.test')));
$forged=$base;$forged['event_id']=$ids['foreign'];$forged['nonce']=wp_create_nonce('swimlog_event_result_'.$ids['foreign']);event_check(is_wp_error(event_submit($forged)));
$d['title']='<script>synthetic</script>';$d['notes']='<img src=x onerror=alert(1)>';$html=EW::render($d);event_check(strpos($html,'<script>')===false&&strpos($html,'<img')===false&&strpos($html,'&lt;script&gt;')!==false);
$user=get_user_by('id',$owner);$user->add_cap('swimlog_manage_own_workouts',false);wp_set_current_user(0);wp_set_current_user($owner);event_check(!is_wp_error(EW::open($owner,$id,'result')));$user->add_cap('swimlog_manage_own_workouts');wp_set_current_user(0);wp_set_current_user($owner);
$user=get_user_by('id',$owner);$user->add_cap('swimlog_manage_own_events',false);wp_set_current_user(0);wp_set_current_user($owner);$private=EW::detail($owner,$ids['own']);
event_check(!is_wp_error($private)&&$private['notes']===null&&$private['result']===null&&strpos(EW::render($private),'PRIVATE_EVENT_NOTES_CANARY')===false);
event_check(is_wp_error(EW::open($owner,$id,'result'))&&is_wp_error(event_submit($base)));
$user->add_cap('swimlog_manage_own_events');wp_set_current_user(0);wp_set_current_user($owner);
$schema=get_option('swimlog_db_version');update_option('swimlog_db_version','0');event_check(is_wp_error(EW::detail($owner,$id)));update_option('swimlog_db_version',$schema);
Event::delete($id,$owner);
echo 'Event workflow integration passed: '.$GLOBALS['event_checks']." assertions.\n";

<?php
namespace SwimlogStandalone;
use SwimLogEvaluation\Event;
use SwimLogEvaluation\Location;
use SwimLogEvaluation\Database;

/** Owner-scoped event UI; existing Event services own validation and writes. */
final class EventWorkflow {
    const FIELDS=array('event_name','event_date','event_time','location_id','distance_value','course_unit','stroke','notes');
    public static function error($message,$status=400) { return new \WP_Error('event_workflow',$message,array('status'=>$status)); }
    private static function id($id,$new=false) { return ($new&&$id==='new')||(is_scalar($id)&&preg_match('/^[1-9][0-9]{0,17}$/D',(string)$id)&&(float)$id<=PHP_INT_MAX); }
    public static function allowed($owner) { return Auth::cookieUser()===$owner&&Auth::requireCapability($owner)&&current_user_can('swimlog_manage_own_events'); }
    private static function ready($owner,$write=false) {
        if(Auth::cookieUser()!==$owner||!Auth::requireCapability($owner)||($write&&!self::allowed($owner)))return self::error('Your account does not have access to this event operation.',403);
        if(!defined('SWIMLOG_EVALUATION_DIR')||!defined('SWIMLOG_EVALUATION_DB_VERSION')||version_compare((string)get_option('swimlog_db_version','0'),SWIMLOG_EVALUATION_DB_VERSION,'<'))return self::error('The Swim Log installation must be ready before opening events.',503);
        foreach(array('event','location','workout')as$file)require_once SWIMLOG_EVALUATION_DIR.'includes/class-'.$file.'.php';
        require_once __DIR__.'/PlanEditor.php';require_once __DIR__.'/PlanFormValues.php';require_once __DIR__.'/WorkoutDetails.php';require_once __DIR__.'/CalendarView.php';
        return true;
    }
    public static function detail($owner,$id) {
        $ready=self::ready($owner);if(is_wp_error($ready))return $ready;
        if(!self::id($id))return self::error('Choose a valid event.');
        $event=Event::get_for_user((int)$id,$owner);if(!$event)return self::error('Event not found.',404);
        $location=!empty($event->location_id)?Location::get_for_user((int)$event->location_id,$owner):null;
        $summary=array('Date'=>$event->event_date,'Start time'=>$event->event_time?:'Not set','Distance'=>CalendarView::distance($event->distance_value,$event->course_unit),'Stroke'=>WorkoutDetails::stroke($event->stroke),'Course'=>$event->course_unit==='yd'?'Yards':'Meters','Location'=>$location?$location->name:'Not available');
        $manage=self::allowed($owner);
        $result=null;$notes=null;
        if($manage) {
            PlanFormValues::load();
            $result=array('time'=>PlanFormValues::durationText($event->result_time_ms??null),'placement'=>isset($event->placement)?(int)$event->placement:null);
            $notes=(string)($event->notes??'');
        }
        return array('id'=>(int)$id,'title'=>$event->event_name,'date'=>$event->event_date,'summary'=>$summary,'manage'=>$manage,'result'=>$result,'notes'=>$notes);
    }
    public static function render($detail,$returnUrl=null) {
        if($returnUrl===null)$returnUrl='/?'.http_build_query(array('date'=>$detail['date'],'view'=>'month'));
        ob_start();require __DIR__.'/event-content.php';return ob_get_clean();
    }
    public static function open($owner,$id,$operation,$query=array()) {
        $ready=self::ready($owner,true);if(is_wp_error($ready))return $ready;
        if(!is_string($operation)||!in_array($operation,array('edit','result'),true)||!self::id($id,$operation==='edit'))return self::error('Choose a valid event operation.');
        if($operation==='result'&&!is_callable(array('SwimLogEvaluation\\Event','save_result')))return self::error('Race-result entry is unavailable in this installed plugin. Use WordPress tools.',503);
        $context=PlanEditor::context($query);if(is_wp_error($context))return $context;
        $prior=$id==='new'?null:Event::get_for_user((int)$id,$owner);if($id!=='new'&&!$prior)return self::error('Event not found.',404);
        PlanFormValues::load();
        $v=array('event_name'=>'','event_date'=>$context['date'],'event_time'=>'','location_id'=>'0','distance_value'=>'200','course_unit'=>'m','stroke'=>'FR','notes'=>'','result_time'=>'','placement'=>'','clear_result'=>'');
        if($prior) {
            foreach(self::FIELDS as$key)$v[$key]=(string)($prior->$key??'');
            $v['event_time']=substr($v['event_time'],0,5);$v['location_id']=(string)($prior->location_id??0);
            $v['result_time']=PlanFormValues::durationText($prior->result_time_ms??null);$v['placement']=(string)($prior->placement??'');
        } elseif(isset($query['event_date'])) {
            $target=PlanEditor::context(array('date'=>$query['event_date']));if(is_wp_error($target))return self::error('Choose a valid event date.');$v['event_date']=$target['date'];
        }
        $locations=array();
        if($operation==='edit')foreach(Location::all_for_user($owner)as$row)$locations[]=array('id'=>(int)$row->id,'name'=>$row->name);
        // Preserve a stale/deleted reference on unchanged edits; do not silently drop it.
        if($prior&&!empty($prior->location_id)&&!in_array((int)$prior->location_id,array_column($locations,'id'),true)&&$operation==='edit')$locations[]=array('id'=>(int)$prior->location_id,'name'=>'Unavailable location — choose a replacement or No location');
        return array('id'=>$id==='new'?'new':(int)$id,'operation'=>$operation,'values'=>$v,'context'=>$context,'prior'=>$prior,'locations'=>$locations,'distances'=>Event::DISTANCES,'strokes'=>Event::STROKES,'revision'=>hash_hmac('sha256',serialize($prior),wp_salt('nonce')),'error'=>null);
    }
    public static function submit($owner,$input,$origin,$appOrigin) {
        $ready=self::ready($owner,true);if(is_wp_error($ready))return $ready;
        $id=$input['event_id']??null;$op=$input['operation']??null;
        if(!is_string($op)||!in_array($op,array('edit','result'),true)||!self::id($id,$op==='edit'))return self::error('Choose a valid event operation.');
        if($origin!==$appOrigin||!is_string($input['nonce']??null)||!wp_verify_nonce($input['nonce'],'swimlog_event_'.$op.'_'.$id))return self::error('The save request expired or is invalid. Reload the event form.',403);
        global $wpdb;$lock='swimlog_event_'.substr(hash('sha256',DB_NAME.'|'.$wpdb->prefix.'|'.$owner.'|'.$id),0,48);
        if((string)Database::read('get_var',$wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock))!=='1')return self::error('Another event change is being saved. Reload the form in a moment.',409);
        try { return self::save($owner,$id,$op,$input); }
        finally { Database::read('get_var',$wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    private static function save($owner,$id,$op,$input) {
        $form=self::open($owner,$id,$op,array('date'=>$input['date']??wp_date('Y-m-d'),'view'=>$input['view']??'month'));if(is_wp_error($form))return $form;
        $fields=$op==='edit'?self::FIELDS:array('result_time','placement','notes');
        if(array_diff(array_keys($input),array_merge($fields,array('event_id','operation','nonce','revision','date','view'),$op==='result'?array('clear_result'):array())))$form['error']=self::error('This form cannot change ownership or fields from another event operation.',422);
        foreach($fields as$key) {
            if(!isset($input[$key])||!is_string($input[$key])||strlen($input[$key])>60000)$form['error']=self::error('The form contains a missing or invalid event field.',422);
            else $form['values'][$key]=$input[$key];
        }
        if(isset($input['clear_result'])&&$input['clear_result']!=='1')$form['error']=self::error('Choose a valid result-clearing confirmation.',422);
        $form['values']['clear_result']=($input['clear_result']??'')==='1'?'1':'';
        if(!is_string($input['revision']??null)||!hash_equals($form['revision'],$input['revision'])) {
            $form['revision']=is_string($input['revision']??null)?$input['revision']:'';
            $form['error']=self::error('This event or result changed after the form was opened. Reload it before saving.',409);
        }
        if($form['error'])return array('form'=>$form);
        $v=$form['values'];
        if($op==='edit') {
            if(is_wp_error(PlanEditor::context(array('date'=>$v['event_date']))))$form['error']=self::error('Choose a valid event date between 1900 and 2199.',422);
            elseif(strlen($v['event_name'])>191)$form['error']=self::error('The event name must be no longer than 191 bytes.',422);
            elseif(!ctype_digit($v['distance_value'])||!in_array((int)$v['distance_value'],Event::DISTANCES,true))$form['error']=self::error('Select a supported event distance.',422);
            elseif(!preg_match('/^(0|[1-9][0-9]{0,17})$/D',$v['location_id'])||(float)$v['location_id']>PHP_INT_MAX)$form['error']=self::error('Select one of your own locations.',422);
            elseif($form['prior']&&!empty($form['prior']->event_time)&&substr($form['prior']->event_time,-2)!=='00'&&$v['event_time']===substr($form['prior']->event_time,0,5))$form['error']=self::error('This event has a start time with seconds. Choose a new minute-precision start time explicitly before saving.',422);
            if($form['error'])return array('form'=>$form);
            $result=Event::save($owner,array_intersect_key($v,array_flip(self::FIELDS)),$id==='new'?0:(int)$id);
        } else {
            $prior=$form['prior'];
            $clears=(($prior->result_time_ms??null)!==null&&trim($v['result_time'])==='')||(($prior->placement??null)!==null&&trim($v['placement'])==='');
            if($clears&&$v['clear_result']!=='1'){$form['error']=self::error('Confirm clearing an existing race time or placement before saving blank values.',422);return array('form'=>$form);}
            $result=Event::save_result((int)$id,$owner,$v['result_time'],$v['placement'],$v['notes']);
        }
        if(is_wp_error($result)){$form['error']=$result;return array('form'=>$form);}
        $saved=$op==='edit'?(int)$result:(int)$id;
        return array('id'=>$saved,'url'=>$form['context']['url'].'&event='.$saved.'&event_saved='.($op==='result'?'result':'event'));
    }
}

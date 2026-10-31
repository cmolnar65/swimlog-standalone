<?php
namespace SwimlogStandalone;

use SwimLogEvaluation\Planned_Workout;
use SwimLogEvaluation\Admin_UI;
use SwimLogEvaluation\Admin_Planned_Workouts;
use SwimLogEvaluation\Planned_Structure_Link;
use SwimLogEvaluation\Structured_Workout_Store;

/** Narrow write boundary. All business writes go through Planned_Workout::save. */
final class PlanEditor {
    const FIELDS=array('title','planned_date','planned_start_time','workout_type','planned_distance','planned_distance_unit','planned_duration','pool_length_native','pool_course','primary_stroke','workout_instructions');
    private static function error($message,$status=400) { return new \WP_Error('plan_editor',$message,array('status'=>$status)); }
    private static function id($id) {
        return $id==='new'||(is_scalar($id)&&preg_match('/^[1-9][0-9]{0,17}$/D',(string)$id)&&(float)$id<=PHP_INT_MAX);
    }
    public static function allowed($owner) {
        return Auth::cookieUser()===$owner&&Auth::requireCapability($owner)&&current_user_can('swimlog_manage_own_workouts');
    }
    public static function context($query) {
        $date=$query['date']??wp_date('Y-m-d');$view=$query['view']??'month';
        if(!is_string($date)||!preg_match('/^(19|20|21)\d{2}-\d{2}-\d{2}$/D',$date)||!is_string($view)||!in_array($view,array('month','week'),true)) return self::error('Choose a valid calendar date and view.');
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,wp_timezone());
        if(!$parsed||$parsed->format('Y-m-d')!==$date) return self::error('Choose a valid calendar date.');
        return array('date'=>$date,'view'=>$view,'url'=>'/?'.http_build_query(array('date'=>$date,'view'=>$view)));
    }
    public static function open($owner,$id,$query=array()) {
        if(!self::allowed($owner)) return self::error('Your account cannot manage planned workouts.',403);
        if(!self::id($id)) return self::error('Choose a valid planned workout.');
        if(!defined('SWIMLOG_EVALUATION_DIR')||!defined('SWIMLOG_EVALUATION_DB_VERSION')||version_compare((string)get_option('swimlog_db_version','0'),SWIMLOG_EVALUATION_DB_VERSION,'<')) return self::error('The Swim Log installation must be ready before saving planned workouts.',503);
        foreach(array('planned-workout','structured-workout-store','planned-structure-link')as$file) require_once SWIMLOG_EVALUATION_DIR.'includes/class-'.$file.'.php';
        require_once SWIMLOG_EVALUATION_DIR.'admin/class-admin-planned-workouts.php';
        require_once __DIR__.'/CalendarView.php';
        $context=self::context($query);if(is_wp_error($context))return $context;
        $prior=$id==='new'?null:Planned_Workout::get_for_user((int)$id,$owner);
        if($id!=='new'&&!$prior)return self::error('Planned workout not found.',404);
        $linked=$prior?Planned_Structure_Link::for_plan((int)$id,$owner):null;
        if(is_wp_error($linked))return self::error('The linked prescription needs review in WordPress tools before this plan can be edited.',409);
        $locked=$linked?Structured_Workout_Store::is_locked($linked['id'],$owner):false;
        if(($prior&&!empty($prior->completed_workout_id))||is_wp_error($locked)||$locked)return self::error('Matched plans and recorded-swim prescriptions are read-only here. Use WordPress tools to review their history.',409);
        $values=array('title'=>'','planned_date'=>wp_date('Y-m-d'),'planned_start_time'=>'','workout_type'=>'pool','planned_distance'=>'','planned_distance_unit'=>'m','planned_duration'=>'','pool_length_native'=>'','pool_course'=>'','primary_stroke'=>'','workout_instructions'=>'');
        if($prior) {
            foreach(array('title','planned_date','planned_start_time','workout_type','pool_course','primary_stroke','workout_instructions')as$key)$values[$key]=(string)$prior->$key;
            $values['planned_distance_unit']=$prior->pool_course==='yd'?'yd':'m';
            $values['planned_distance']=Admin_UI::native_distance($prior->planned_distance_m,$values['planned_distance_unit']);
            $values['pool_length_native']=Admin_UI::native_distance($prior->pool_length_m,$prior->pool_course);
            $values['planned_duration']=Admin_UI::duration_text($prior->planned_duration_ms);
        } elseif(isset($query['planned_date'])) {
            $target=self::context(array('date'=>$query['planned_date']));
            if(is_wp_error($target)||$query['planned_date']<wp_date('Y-m-d'))return self::error('New planned workouts must be scheduled for today or a future date.');
            $values['planned_date']=$target['date'];
        }
        return array('id'=>$id==='new'?'new':(int)$id,'values'=>$values,'context'=>$context,'prior'=>$prior,'linked'=>$linked,'revision'=>hash_hmac('sha256',serialize(array($prior,$linked)),wp_salt('nonce')),'error'=>null);
    }
    public static function submit($owner,$input,$origin,$appOrigin) {
        if(!self::allowed($owner))return self::error('Your account cannot manage planned workouts.',403);
        $id=$input['plan_id']??null;
        if(!self::id($id))return self::error('Choose a valid planned workout.');
        if($origin!==$appOrigin||!is_string($input['nonce']??null)||!wp_verify_nonce($input['nonce'],'swimlog_plan_save_'.$id))return self::error('The save request expired or is invalid. Reload the form before trying again.',403);
        $form=self::open($owner,$id,array('date'=>$input['date']??wp_date('Y-m-d'),'view'=>$input['view']??'month'));
        if(is_wp_error($form))return $form;
        foreach(self::FIELDS as$key) {
            if(!isset($input[$key])||!is_string($input[$key])||strlen($input[$key])>25000) $form['error']=self::error('The form contains a missing or invalid field.',422);
            else $form['values'][$key]=$input[$key];
        }
        foreach(array('user_id','status','completed_workout_id','notes','structure_id','planned_distance_m','planned_duration_ms','pool_length_m')as$key)if(array_key_exists($key,$input))$form['error']=self::error('This form cannot change ownership, history, private notes or structure links.',422);
        if(!is_string($input['revision']??null)||!hash_equals($form['revision'],$input['revision'])) {
            $form['error']=self::error('This plan changed after the form was opened. Reload the form to review the latest version.',409);
            $form['revision']=is_string($input['revision']??null)?$input['revision']:'';
        }
        if($form['error'])return array('form'=>$form);
        $v=$form['values'];
        $date=self::context(array('date'=>$v['planned_date']));
        if(is_wp_error($date))$form['error']=self::error('Choose a valid planned date between 1900 and 2199.',422);
        elseif(strlen($v['title'])>191)$form['error']=self::error('The title must be no longer than 191 bytes.',422);
        elseif(!in_array($v['planned_distance_unit'],array('m','yd'),true))$form['error']=self::error('Choose meters or yards for planned distance.',422);
        if($form['error'])return array('form'=>$form);
        // Public, pure form helper from the plugin: no admin handlers/hooks are invoked.
        $data=Admin_Planned_Workouts::normalize_form($v);
        if(is_wp_error($data)) { $form['error']=$data;return array('form'=>$form); }
        // Preserve exact canonical distances on unchanged fields (no conversion round-trip).
        if($form['prior']) {
            $p=$form['prior'];$unit=$p->pool_course==='yd'?'yd':'m';
            if($v['planned_distance_unit']===$unit&&$v['planned_distance']===Admin_UI::native_distance($p->planned_distance_m,$unit))$data['planned_distance_m']=$p->planned_distance_m;
            if($v['pool_course']===(string)$p->pool_course&&$v['pool_length_native']===Admin_UI::native_distance($p->pool_length_m,$p->pool_course))$data['pool_length_m']=$p->pool_length_m;
        }
        $data['notes']=$form['prior']?$form['prior']->notes:''; // Never accept notes from the browser.
        $result=Planned_Workout::save($owner,$data,$id==='new'?0:(int)$id);
        if(is_wp_error($result)) { $form['error']=$result;return array('form'=>$form); }
        return array('id'=>(int)$result,'url'=>$form['context']['url'].'&plan='.(int)$result.'&saved=1','review'=>(bool)$form['linked']);
    }
}

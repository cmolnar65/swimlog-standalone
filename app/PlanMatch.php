<?php
namespace SwimlogStandalone;
use SwimLogEvaluation\Planned_Workout;
use SwimLogEvaluation\Workout;
use SwimLogEvaluation\Planned_Structure_Link;
use SwimLogEvaluation\Structured_Workout_Store;
use SwimLogEvaluation\Database;

/** Explicit owner-only matching; the plugin remains the association/history authority. */
final class PlanMatch {
    private static function error($message,$status=400) { return new \WP_Error('plan_match',$message,array('status'=>$status)); }
    private static function id($id) { return is_scalar($id)&&preg_match('/^[1-9][0-9]{0,17}$/D',(string)$id)&&(float)$id<=PHP_INT_MAX; }
    private static function ready($owner) {
        require_once __DIR__.'/PlanEditor.php';
        if(!PlanEditor::allowed($owner))return self::error('Your account cannot match planned workouts.',403);
        if(!defined('SWIMLOG_EVALUATION_DIR')||!defined('SWIMLOG_EVALUATION_DB_VERSION')||version_compare((string)get_option('swimlog_db_version','0'),SWIMLOG_EVALUATION_DB_VERSION,'<'))return self::error('The Swim Log installation must be ready before matching workouts.',503);
        foreach(array('planned-workout','workout','structured-workout-store','planned-structure-link')as$file)require_once SWIMLOG_EVALUATION_DIR.'includes/class-'.$file.'.php';
        require_once __DIR__.'/CalendarView.php';
        if(!is_callable(array('SwimLogEvaluation\\Planned_Workout','completion_candidates'))||!is_callable(array('SwimLogEvaluation\\Planned_Workout','associate_completed_workout')))return self::error('Matching is unavailable in this installed plugin. Use WordPress tools.',503);
        return true;
    }
    public static function open($owner,$id,$query=array()) {
        $ready=self::ready($owner);if(is_wp_error($ready))return $ready;
        if(!self::id($id))return self::error('Choose a valid planned workout.');
        $context=PlanEditor::context($query);if(is_wp_error($context))return $context;
        $plan=Planned_Workout::get_for_user((int)$id,$owner);
        if(!$plan)return self::error('Planned workout not found.',404);
        if($plan->status!=='planned'||!empty($plan->completed_workout_id))return self::error('Only unmatched planned workouts can be matched here. Existing matches remain unchanged.',409);
        $linked=Planned_Structure_Link::for_plan((int)$id,$owner);
        if(is_wp_error($linked))return self::error('Review the linked prescription in WordPress tools before matching this plan.',409);
        $locked=$linked?Structured_Workout_Store::is_locked($linked['id'],$owner):false;
        if(is_wp_error($locked)||$locked)return self::error('This prescription already has recorded history and cannot be matched again here.',409);
        $recordedDate=$query['recorded_date']??$plan->planned_date;
        $parsed=PlanEditor::context(array('date'=>$recordedDate));if(is_wp_error($parsed))return self::error('Choose a valid recorded date.');
        $page=$query['match_page']??'1';if(!is_scalar($page)||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$page))return self::error('Choose a valid candidate page.');
        $used=array();foreach(Planned_Workout::all_for_user($owner)as$other)if(!empty($other->completed_workout_id))$used[(int)$other->completed_workout_id]=true;
        $suggestions=Planned_Workout::completion_candidates((int)$id,$owner,20);
        if(is_wp_error($suggestions))return self::error('Recorded swim suggestions are unavailable.',503);
        $suggested=array_column($suggestions,null,'id');
        $filters=array('workout_type'=>$plan->workout_type,'swim_only'=>true,'date_from'=>$recordedDate,'date_to'=>$recordedDate);
        $result=Workout::query_for_user($owner,$filters,1,20);$pages=max(1,(int)$result['pages']);$page=min((int)$page,$pages);
        if($page!==1)$result=Workout::query_for_user($owner,$filters,$page,20);
        $candidates=array();
        foreach($result['rows']as$row) {
            if(isset($used[(int)$row->id])||$row->workout_type!==$plan->workout_type||!in_array($row->workout_type,array('pool','open_water'),true))continue;
            $token=hash_hmac('sha256',serialize($row),wp_salt('nonce'));
            $candidates[]=array('id'=>(int)$row->id,'choice'=>$row->id.':'.$token,'start'=>$row->workout_start,'distance'=>isset($row->original_distance)&&!empty($row->original_distance_unit)?CalendarView::distance($row->original_distance,$row->original_distance_unit):CalendarView::distance($row->total_distance_m),'elapsed'=>Workout::format_duration($row->elapsed_time_ms),'moving'=>Workout::format_duration($row->moving_time_ms),'suggested'=>isset($suggested[(int)$row->id]));
        }
        return array('id'=>(int)$id,'title'=>$plan->title,'planned_date'=>$plan->planned_date,'type'=>$plan->workout_type,'planned_distance'=>CalendarView::distance($plan->planned_distance_m),'context'=>$context,'recorded_date'=>$recordedDate,'page'=>$page,'pages'=>$pages,'candidates'=>$candidates,'revision'=>hash_hmac('sha256',serialize(array($plan,$linked,$locked)),wp_salt('nonce')),'choice'=>'','error'=>null);
    }
    public static function submit($owner,$input,$origin,$appOrigin) {
        $ready=self::ready($owner);if(is_wp_error($ready))return $ready;
        $id=$input['plan_id']??null;if(!self::id($id))return self::error('Choose a valid planned workout.');
        if($origin!==$appOrigin||!is_string($input['nonce']??null)||!wp_verify_nonce($input['nonce'],'swimlog_plan_match_'.$id))return self::error('The match request expired or is invalid. Reload the form before trying again.',403);
        // Serialize this application's competing matches for one owner. No business-table writes.
        global $wpdb;
        $lock='swimlog_match_'.substr(hash('sha256',DB_NAME.'|'.$wpdb->prefix.'|'.$owner),0,48);
        if((string)Database::read('get_var',$wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock))!=='1')return self::error('Another match is being saved. Wait a moment and reload the form.',409);
        try { return self::confirm($owner,$id,$input); }
        finally { Database::read('get_var',$wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    private static function confirm($owner,$id,$input) {
        $form=self::open($owner,$id,$input);if(is_wp_error($form))return $form;
        if(array_diff(array_keys($input),array('plan_id','nonce','revision','recorded_choice','date','view','recorded_date','match_page')))$form['error']=self::error('This form can only match the selected plan to one recorded swim.',422);
        $choice=$input['recorded_choice']??'';
        if(is_string($choice)&&strlen($choice)<=84)$form['choice']=$choice;
        else $form['error']=self::error('Choose an available recorded swim.',422);
        if(!is_string($input['revision']??null)||!hash_equals($form['revision'],$input['revision'])) {
            $form['revision']=is_string($input['revision']??null)?$input['revision']:'';
            $form['error']=self::error('The plan or prescription changed after this form was opened. Reload it before matching.',409);
        }
        $selected=null;
        foreach($form['candidates']as$candidate)if(hash_equals($candidate['choice'],$form['choice'])){$selected=$candidate;break;}
        if(!$form['error']&&!$selected)$form['error']=self::error('The selected swim is unavailable or has changed. Review the current list and choose again.',422);
        if($form['error'])return array('form'=>$form);
        $result=Planned_Workout::associate_completed_workout((int)$id,$owner,$selected['id']);
        if(is_wp_error($result)){$form['error']=$result;return array('form'=>$form);}
        return array('id'=>(int)$id,'url'=>$form['context']['url'].'&plan='.(int)$id.'&plan_result=matched');
    }
    public static function url($form,$page=1) {
        return $form['context']['url'].'&'.http_build_query(array('match_plan'=>$form['id'],'recorded_date'=>$form['recorded_date'],'match_page'=>$page));
    }
}

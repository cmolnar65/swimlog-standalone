<?php
namespace SwimlogStandalone;
use SwimLogEvaluation\Planned_Workout;
use SwimLogEvaluation\Planned_Structure_Link;
use SwimLogEvaluation\Structured_Workout_Store;

/** Move/copy boundary. The plugin owns scheduling, duplication and structure history. */
final class PlanActions {
    private static function error($message,$status=400) { return new \WP_Error('plan_action',$message,array('status'=>$status)); }
    private static function valid($id,$operation) {
        return is_string($operation)&&in_array($operation,array('move','copy'),true)&&is_scalar($id)&&preg_match('/^[1-9][0-9]{0,17}$/D',(string)$id)&&(float)$id<=PHP_INT_MAX;
    }
    public static function open($owner,$id,$operation,$query=array()) {
        require_once __DIR__.'/PlanEditor.php';
        if(!PlanEditor::allowed($owner))return self::error('Your account cannot manage planned workouts.',403);
        if(!self::valid($id,$operation))return self::error('Choose a valid planned workout action.');
        if(!defined('SWIMLOG_EVALUATION_DIR')||!defined('SWIMLOG_EVALUATION_DB_VERSION')||version_compare((string)get_option('swimlog_db_version','0'),SWIMLOG_EVALUATION_DB_VERSION,'<'))return self::error('The Swim Log installation must be ready before moving or copying plans.',503);
        foreach(array('planned-workout','structured-workout','structured-workout-store','planned-structure-link')as$file)require_once SWIMLOG_EVALUATION_DIR.'includes/class-'.$file.'.php';
        if(!is_callable(array('SwimLogEvaluation\\Planned_Workout',$operation==='copy'?'duplicate':'move')))return self::error('The installed plugin does not support this operation. Use WordPress tools.',503);
        $context=PlanEditor::context($query);if(is_wp_error($context))return $context;
        $prior=Planned_Workout::get_for_user((int)$id,$owner);
        if(!$prior)return self::error('Planned workout not found.',404);
        $linked=Planned_Structure_Link::for_plan((int)$id,$owner);
        if(is_wp_error($linked))return self::error('Review the linked prescription in WordPress tools before moving or copying this plan.',409);
        $locked=$linked?Structured_Workout_Store::is_locked($linked['id'],$owner):false;
        if(is_wp_error($locked))return self::error('The linked prescription is unavailable.',409);
        if($operation==='move'&&($prior->status!=='planned'||!empty($prior->completed_workout_id)||$locked))return self::error('Only unmatched, unlocked planned workouts can be moved. Copy this plan to schedule a new session.',409);
        return array('id'=>(int)$id,'operation'=>$operation,'title'=>$prior->title,'source_date'=>$prior->planned_date,'target_date'=>max(wp_date('Y-m-d'),$prior->planned_date),'context'=>$context,'revision'=>hash_hmac('sha256',serialize(array($prior,$linked,$locked)),wp_salt('nonce')),'error'=>null);
    }
    public static function submit($owner,$input,$origin,$appOrigin) {
        require_once __DIR__.'/PlanEditor.php';
        if(!PlanEditor::allowed($owner))return self::error('Your account cannot manage planned workouts.',403);
        $id=$input['plan_id']??null;$operation=$input['operation']??null;
        if(!self::valid($id,$operation))return self::error('Choose a valid planned workout action.');
        if($origin!==$appOrigin||!is_string($input['nonce']??null)||!wp_verify_nonce($input['nonce'],'swimlog_plan_'.$operation.'_'.$id))return self::error('The request expired or is invalid. Reload the form before trying again.',403);
        $form=self::open($owner,$id,$operation,array('date'=>$input['date']??wp_date('Y-m-d'),'view'=>$input['view']??'month'));
        if(is_wp_error($form))return $form;
        if(is_string($input['target_date']??null)&&strlen($input['target_date'])<=32)$form['target_date']=$input['target_date'];
        else $form['error']=self::error('Choose a valid target date.',422);
        if(array_diff(array_keys($input),array('plan_id','operation','nonce','revision','target_date','date','view')))$form['error']=self::error('This form can only move or copy the selected plan.',422);
        if(!is_string($input['revision']??null)||!hash_equals($form['revision'],$input['revision'])) {
            $form['revision']=is_string($input['revision']??null)?$input['revision']:'';
            $form['error']=self::error('This plan changed after the form was opened. Reload it to review the latest version.',409);
        }
        if(!$form['error']) {
            $date=PlanEditor::context(array('date'=>$form['target_date']));
            if(is_wp_error($date)||$form['target_date']<wp_date('Y-m-d'))$form['error']=self::error('Choose today or a future date between 1900 and 2199.',422);
        }
        if($form['error'])return array('form'=>$form);
        $result=$operation==='move'?Planned_Workout::move((int)$id,$owner,$form['target_date']):Planned_Workout::duplicate((int)$id,$owner,$form['target_date']);
        if(is_wp_error($result)) { $form['error']=$result;return array('form'=>$form); }
        $url='/?'.http_build_query(array('date'=>$form['target_date'],'view'=>$form['context']['view'],'plan'=>(int)$result,'plan_result'=>$operation==='move'?'moved':'copied'));
        return array('id'=>(int)$result,'url'=>$url);
    }
}

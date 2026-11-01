<?php
namespace SwimlogStandalone;
use SwimLogEvaluation\Planned_Workout as Plans;
use SwimLogEvaluation\Planned_Structure_Link as Links;
use SwimLogEvaluation\Structured_Workout_Store as Store;
use SwimLogEvaluation\Workout;
use SwimLogEvaluation\Database;
/** Owner-only association adapter. The plugin owns mutations and historical locks. */
final class WorkoutAssociations {
    private static function error($text,$status=400){return new \WP_Error('association',$text,array('status'=>$status));}
    public static function ready($owner) {
        require_once __DIR__.'/PlanEditor.php';
        if(!PlanEditor::allowed($owner))return self::error('Only the owner may change workout associations.',403);
        if(!defined('SWIMLOG_EVALUATION_DIR')||!defined('SWIMLOG_EVALUATION_DB_VERSION')||version_compare((string)get_option('swimlog_db_version','0'),SWIMLOG_EVALUATION_DB_VERSION,'<'))return self::error('The Swim Log installation must be ready.',503);
        foreach(array('planned-workout','planned-structure-link','structured-workout-store','workout')as$file)require_once SWIMLOG_EVALUATION_DIR.'includes/class-'.$file.'.php';
        if(!is_callable(array(Plans::class,'for_completed_workout'))||!is_callable(array(Links::class,'preserve_recorded_lock')))return self::error('Update the installed Swim Log plugin to enable safe association unlinking.',503);
        return true;
    }
    public static function forPlan($owner,$id) {
        $ready=self::ready($owner);if(is_wp_error($ready))return $ready;
        if(!is_scalar($id)||!preg_match('/^[1-9][0-9]{0,17}$/D',(string)$id)||(float)$id>PHP_INT_MAX)return self::error('Choose a valid plan.');
        $plan=Plans::get_for_user((int)$id,$owner);if(!$plan)return self::error('Planned workout not found.',404);
        $structure=Links::for_plan((int)$id,$owner);if(is_wp_error($structure))return self::error('The prescription association needs review.',409);
        $workout=empty($plan->completed_workout_id)?null:Workout::get_for_user((int)$plan->completed_workout_id,$owner);
        if(!empty($plan->completed_workout_id)&&!$workout)return self::error('The recorded association is unavailable.',409);
        $history=$structure?get_post_meta($structure['id'],Store::META_RECORDED,true):'';
        return array('plan_id'=>(int)$id,'workout_id'=>$workout?(int)$workout->id:0,'title'=>$plan->title,'structure'=>$structure?array('id'=>$structure['id'],'title'=>$structure['title']):null,'revision'=>hash_hmac('sha256',serialize(array($plan,$structure,$history,$workout)),wp_salt('nonce')));
    }
    public static function forWorkout($owner,$id) {
        $ready=self::ready($owner);if(is_wp_error($ready))return $ready;
        $workout=Workout::get_for_user((int)$id,$owner);if(!$workout)return self::error('Workout not found.',404);
        $plan=Plans::for_completed_workout((int)$id,$owner);
        if(is_wp_error($plan))return self::error('The planned association needs review.',409);
        return $plan?self::forPlan($owner,$plan->id):null;
    }
    public static function submit($owner,array $input,$origin,$appOrigin) {
        $ready=self::ready($owner);if(is_wp_error($ready))return $ready;
        if(array_diff(array_keys($input),array('plan_id','workout_id','operation','nonce','revision','return_to','confirm')))return self::error('Unexpected association fields.');
        foreach($input as$value)if(!is_string($value)||strlen($value)>256)return self::error('Invalid association request.');
        $id=$input['plan_id']??'';$op=$input['operation']??'';
        if(!preg_match('/^[1-9][0-9]{0,17}$/D',$id)||!in_array($op,array('match','structure'),true)||!in_array($input['return_to']??'',array('plan','workout'),true))return self::error('Choose a valid association.');
        if($origin!==$appOrigin||!wp_verify_nonce($input['nonce']??'','standalone_unlink_'.$op.'_'.$id))return self::error('The unlink request expired. Reload before trying again.',403);
        if(($input['confirm']??'')!=='1')return self::error('Confirm removal of this association.',422);
        global $wpdb;$lock='swimlog_match_'.substr(hash('sha256',DB_NAME.'|'.$wpdb->prefix.'|'.$owner),0,48);
        if((string)Database::read('get_var',$wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock))!=='1')return self::error('Another association is changing. Reload and try again.',409);
        try {
            $state=self::forPlan($owner,$id);if(is_wp_error($state))return $state;
            if(!hash_equals($state['revision'],$input['revision']??'')||(string)$state['workout_id']!==($input['workout_id']??''))return self::error('The association changed. Reload and review it before unlinking.',409);
            if(($op==='match'&&!$state['workout_id'])||($op==='structure'&&!$state['structure']))return self::error('This association is no longer present.',409);
            if($input['return_to']==='workout'&&!$state['workout_id'])return self::error('Choose the planned workout page.',409);
            $result=$op==='match'?Plans::clear_completed_workout((int)$id,$owner):Links::detach((int)$id,$owner);
            if(is_wp_error($result))return $result;
            $after=self::forPlan($owner,$id);if(is_wp_error($after))return $after;
            $plan=Plans::get_for_user((int)$id,$owner);
            if(($op==='match'&&($after['workout_id']||$plan->status!=='planned'))||($op==='structure'&&$after['structure']))return self::error('The association could not be removed. Reload to review its current state.',503);
            return array('url'=>'/?'.($input['return_to']==='workout'?'workout='.$state['workout_id']:'plan='.(int)$id).'&association_unlinked='.$op);
        } finally {Database::read('get_var',$wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
}

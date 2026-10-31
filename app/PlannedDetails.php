<?php
namespace SwimlogStandalone;

use SwimLogEvaluation\Planned_Workout;
use SwimLogEvaluation\Workout;

/** Read-only owner-scoped plan view. No notes or raw documents leave this adapter. */
final class PlannedDetails {
    public static function load($owner,$id) {
        if(!Auth::requireCapability($owner)||Auth::cookieUser()!==$owner) return new \WP_Error('plan_forbidden','Planned workout access denied.',array('status'=>403));
        if(!is_scalar($id)||!preg_match('/^[1-9][0-9]{0,17}$/D',(string)$id)||(float)$id>PHP_INT_MAX) return new \WP_Error('plan_id','Choose a valid planned workout.',array('status'=>400));
        if(!defined('SWIMLOG_EVALUATION_DIR')||!defined('SWIMLOG_EVALUATION_DB_VERSION')||version_compare((string)get_option('swimlog_db_version','0'),SWIMLOG_EVALUATION_DB_VERSION,'<')) return new \WP_Error('plan_plugin','Planned workout details are unavailable until the Swim Log installation is ready.',array('status'=>503));
        require_once SWIMLOG_EVALUATION_DIR.'includes/class-planned-workout.php';
        require_once SWIMLOG_EVALUATION_DIR.'includes/class-workout.php';
        require_once __DIR__.'/CalendarView.php';
        require_once __DIR__.'/WorkoutDetails.php';
        $plan=Planned_Workout::get_for_user((int)$id,$owner);
        if(!$plan) return new \WP_Error('plan_missing','Planned workout not found.',array('status'=>404));
        $summary=array('Planned date'=>$plan->planned_date,'Start time'=>$plan->planned_start_time?:'Not set','Activity'=>$plan->workout_type==='open_water'?'Open-water swim':'Pool swim','Status'=>ucfirst($plan->status),'Planned distance'=>CalendarView::distance($plan->planned_distance_m),'Planned duration'=>Workout::format_duration($plan->planned_duration_ms));
        if($plan->workout_type==='pool') {
            $summary['Pool length']=CalendarView::distance($plan->pool_length_m);
            $summary['Pool course']=$plan->pool_course==='yd'?'Yards':($plan->pool_course==='m'?'Meters':'Not set');
            $summary['Primary stroke']=WorkoutDetails::stroke($plan->primary_stroke);
        }
        $match=null;
        if(!empty($plan->completed_workout_id)) {
            $recorded=Workout::get_for_user((int)$plan->completed_workout_id,$owner);
            if($recorded&&in_array($recorded->workout_type,array('pool','open_water'),true)&&$recorded->workout_type===$plan->workout_type) $match=array('id'=>(int)$recorded->id,'start'=>$recorded->workout_start,'distance'=>CalendarView::distance($recorded->total_distance_m));
        }
        $editable=false;
        $structure=null; $structureMessage='No structured workout is linked.';
        if(current_user_can('swimlog_manage_own_workouts')) {
            foreach(array('structured-workout','structured-workout-store','planned-structure-link','structured-workout-export') as $file) require_once SWIMLOG_EVALUATION_DIR.'includes/class-'.$file.'.php';
            $linked=\SwimLogEvaluation\Planned_Structure_Link::for_plan((int)$id,$owner);
            if(!is_wp_error($linked)&&empty($plan->completed_workout_id)) {
                $locked=$linked?\SwimLogEvaluation\Structured_Workout_Store::is_locked($linked['id'],$owner):false;
                $editable=!is_wp_error($locked)&&!$locked;
            }
            if(is_wp_error($linked)) $structureMessage='The linked structured workout is unavailable. Your planned instructions remain below.';
            elseif($linked) {
                if(strlen((string)wp_json_encode($linked['document']))>200000) $text=new \WP_Error('structure_large');
                else $text=\SwimLogEvaluation\Structured_Workout_Export::text($linked['title'],$linked['document']);
                if(is_wp_error($text)||strlen($text)>100000) $structureMessage='The linked structured workout cannot be displayed. Use WordPress tools to review it.';
                else $structure=array('title'=>$linked['title'],'text'=>$text);
            }
        } else $structureMessage='Workout-management access is required to view structured prescriptions.';
        return array('id'=>(int)$id,'title'=>$plan->title?:'Planned swim','date'=>$plan->planned_date,'summary'=>$summary,'editable'=>$editable,'movable'=>$editable&&$plan->status==='planned'&&is_callable(array('SwimLogEvaluation\\Planned_Workout','move')),'copyable'=>current_user_can('swimlog_manage_own_workouts')&&!is_wp_error($linked??null)&&is_callable(array('SwimLogEvaluation\\Planned_Workout','duplicate')),'instructions'=>(string)$plan->workout_instructions,'match'=>$match,'match_missing'=>!empty($plan->completed_workout_id)&&!$match,'structure'=>$structure,'structure_message'=>$structureMessage);
    }
    public static function render($detail,$returnUrl=null) {
        if($returnUrl===null) $returnUrl=CalendarView::url(new \DateTimeImmutable($detail['date'],wp_timezone()),'month');
        ob_start();require __DIR__.'/planned-content.php';return ob_get_clean();
    }
}

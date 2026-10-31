<?php
namespace SwimlogStandalone;

use SwimLogEvaluation\Workout;

/** Read-only owner adapter. Never exports source files, notes or raw metadata. */
final class WorkoutDetails {
    public static function load($owner, $id) {
        if (!Auth::requireCapability($owner) || Auth::cookieUser() !== $owner) return new \WP_Error('detail_forbidden','Workout access denied.',array('status'=>403));
        if (!is_scalar($id) || !preg_match('/^[1-9][0-9]{0,17}$/D',(string)$id) || (float)$id > PHP_INT_MAX) return new \WP_Error('detail_id','Choose a valid workout.',array('status'=>400));
        if (!defined('SWIMLOG_EVALUATION_DIR') || !defined('SWIMLOG_EVALUATION_DB_VERSION') || version_compare((string)get_option('swimlog_db_version','0'), SWIMLOG_EVALUATION_DB_VERSION,'<')) return new \WP_Error('detail_plugin','Workout details are unavailable until the Swim Log installation is ready.',array('status'=>503));
        require_once SWIMLOG_EVALUATION_DIR.'includes/class-workout.php';
        $workout=Workout::get_for_user((int)$id,$owner);
        if (!$workout) return new \WP_Error('detail_missing','Workout not found.',array('status'=>404));
        $type=$workout->workout_type??'pool';
        if (!in_array($type,array('pool','open_water','running','walking'),true)) return new \WP_Error('detail_missing','Workout not found.',array('status'=>404));
        if (in_array($type,array('running','walking'),true) && !\SwimLogEvaluation\Land_Activity::authorized($owner)) return new \WP_Error('detail_missing','Workout not found.',array('status'=>404));
        $laps=Workout::laps((int)$id,$owner);
        $lengths=$type==='pool'?Workout::lengths((int)$id,$owner):array();
        $allowedLengths=array_flip(array('sequence_no','length_type','distance_m','stroke','stroke_count','elapsed_time_ms','swolf_score'));
        $lengths=array_map(static function($row)use($allowedLengths){return (object)array_intersect_key(get_object_vars($row),$allowedLengths);},$lengths);
        $points=$type!=='pool'?Workout::trackpoints((int)$id,$owner):array();
        if (count($laps)>2000 || count($lengths)>5000 || count($points)>50000) return new \WP_Error('detail_large','This workout is too large for the pop-up. Please use WordPress tools to view it.',array('status'=>413));
        $labels=array('pool'=>'Pool swim','open_water'=>'Open-water swim','running'=>'Run','walking'=>'Walk');
        require_once __DIR__.'/CalendarView.php';
        $summary=array(
            'Start'=>(string)$workout->workout_start,
            'Distance'=>isset($workout->original_distance)&&!empty($workout->original_distance_unit)?CalendarView::distance($workout->original_distance,$workout->original_distance_unit):CalendarView::distance($workout->total_distance_m),
            'Elapsed time'=>Workout::format_duration($workout->elapsed_time_ms),
            'Recorded moving time'=>Workout::format_duration($workout->moving_time_ms),
        );
        if (!empty($workout->location_name)) $summary['Location']=$workout->location_name;
        if ($type==='pool') {
            $summary['Pool length']=isset($workout->original_pool_length)&&!empty($workout->pool_length_unit)?CalendarView::distance($workout->original_pool_length,$workout->pool_length_unit):CalendarView::distance($workout->pool_length_m);
            $summary['Primary stroke']=self::stroke($workout->primary_stroke);
            $summary['Average SWOLF']=isset($workout->avg_swolf)?number_format_i18n((float)$workout->avg_swolf,2):'—';
        }
        if (in_array($type,array('running','walking'),true)) $summary['Environment']=ucfirst($workout->activity_environment?:'unknown');
        $route='';
        if ($points) {
            // Public pure renderer; no admin request handlers or theme assets are run.
            require_once SWIMLOG_EVALUATION_DIR.'admin/class-admin-workouts.php';
            $route=\SwimLogEvaluation\Admin_Workouts::activity_route_svg($points);
            $route=wp_kses($route,array('svg'=>array('class'=>true,'viewbox'=>true,'role'=>true,'aria-label'=>true),'line'=>array('x1'=>true,'y1'=>true,'x2'=>true,'y2'=>true,'stroke'=>true,'stroke-width'=>true,'opacity'=>true),'polyline'=>array('points'=>true,'fill'=>true,'stroke'=>true,'stroke-width'=>true,'vector-effect'=>true),'circle'=>array('cx'=>true,'cy'=>true,'r'=>true,'fill'=>true,'stroke'=>true,'stroke-width'=>true),'text'=>array('x'=>true,'y'=>true,'font-size'=>true,'font-weight'=>true)));
        }
        return array('id'=>(int)$id,'type'=>$type,'label'=>$labels[$type],'summary'=>$summary,'laps'=>$laps,'lengths'=>$lengths,'route'=>$route,'performances'=>$type==='pool'?Workout::performances((int)$id,$owner):array(),'analytics'=>$type==='open_water'?Workout::open_water_analytics((int)$id,$owner):array());
    }
    /** Presentation only: recorded timed zero-distance rows are rests, not swims. */
    public static function isRestLength($length) {
        return strtolower((string)($length->length_type??''))==='rest'
            || (isset($length->distance_m) && is_numeric($length->distance_m) && (float)$length->distance_m===0.0
                && isset($length->elapsed_time_ms) && is_numeric($length->elapsed_time_ms) && (float)$length->elapsed_time_ms>0);
    }
    public static function stroke($value) {
        return array('FR'=>'Freestyle','BR'=>'Breaststroke','BACK'=>'Backstroke','BK'=>'Backstroke','FLY'=>'Butterfly','BU'=>'Butterfly','MIXED'=>'Mixed','MX'=>'Mixed','IM'=>'Individual medley')[$value??'']??($value?:'—');
    }
    public static function render($detail) {
        ob_start(); require __DIR__.'/workout-content.php'; return ob_get_clean();
    }
}

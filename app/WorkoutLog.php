<?php
namespace SwimlogStandalone;

use SwimLogEvaluation\Workout;

/** Filtered owner-only history using the plugin's queries and summary services. */
final class WorkoutLog {
    const PAGE_SIZE=20;
    const SUMMARY_LIMIT=10000;
    public static function defaults() {
        $today=new \DateTimeImmutable('today',wp_timezone());
        return array('from'=>$today->modify('first day of this month')->modify('-2 months')->format('Y-m-d'),'to'=>$today->format('Y-m-d'),'type'=>'all');
    }
    private static function date($value) {
        if (!is_string($value)) return false;
        if ($value==='') return true;
        if (!preg_match('/^(19|20|21)\d{2}-\d{2}-\d{2}$/D',$value)) return false;
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,wp_timezone());
        return $date && $date->format('Y-m-d')===$value;
    }
    public static function load($owner,$query) {
        if (!Auth::requireCapability($owner) || Auth::cookieUser()!==$owner) return new \WP_Error('log_forbidden','Workout log access denied.');
        if (!defined('SWIMLOG_EVALUATION_DIR') || !defined('SWIMLOG_EVALUATION_DB_VERSION') || version_compare((string)get_option('swimlog_db_version','0'),SWIMLOG_EVALUATION_DB_VERSION,'<')) return new \WP_Error('log_plugin','The Swim Log installation must be ready before opening the workout log.');
        require_once SWIMLOG_EVALUATION_DIR.'includes/class-workout.php';
        require_once SWIMLOG_EVALUATION_DIR.'includes/class-training-trends.php';
        $land=class_exists('SwimLogEvaluation\\Land_Activity') && \SwimLogEvaluation\Land_Activity::authorized($owner);
        $types=array('all'=>'All activities','pool'=>'Pool swimming','open_water'=>'Open-water swimming');
        if ($land) $types+=array('running'=>'Running','walking'=>'Walking');
        $filters=self::defaults();
        foreach(array('from','to','type') as $key) if(array_key_exists($key,$query)) $filters[$key]=$query[$key];
        if (!self::date($filters['from']) || !self::date($filters['to'])) return new \WP_Error('log_dates','Choose valid dates between 1900 and 2199, or leave a date blank.');
        if ($filters['from']!=='' && $filters['to']!=='' && $filters['from']>$filters['to']) return new \WP_Error('log_dates','The From date must be on or before the To date.');
        if (!is_string($filters['type']) || !isset($types[$filters['type']])) return new \WP_Error('log_type','Choose an activity type available to your account.');
        $page=$query['log_page']??'1';
        if (!is_scalar($page) || !preg_match('/^[1-9][0-9]{0,6}$/D',(string)$page)) return new \WP_Error('log_page','Choose a valid workout log page.');
        $serviceFilters=array();
        if($filters['from']!=='') $serviceFilters['date_from']=$filters['from'];
        if($filters['to']!=='') $serviceFilters['date_to']=$filters['to'];
        if($filters['type']!=='all') $serviceFilters['workout_type']=$filters['type'];
        $result=Workout::query_for_user($owner,$serviceFilters,1,self::PAGE_SIZE);
        $pages=max(1,(int)$result['pages']); $page=min((int)$page,$pages);
        if($page!==1) $result=Workout::query_for_user($owner,$serviceFilters,$page,self::PAGE_SIZE);
        $totals=null;
        if((int)$result['total']<=self::SUMMARY_LIMIT) {
            $rows=array();
            for($batch=1;$batch<=max(1,(int)ceil($result['total']/500));$batch++) {
                $portion=Workout::query_for_user($owner,$serviceFilters,$batch,500);
                foreach($portion['rows'] as $row) $rows[]=(object)array('workout_type'=>$row->workout_type,'total_distance_m'=>$row->total_distance_m,'elapsed_time_ms'=>$row->elapsed_time_ms,'moving_time_ms'=>$row->moving_time_ms,'workout_start'=>$row->workout_start,'pool_length_unit'=>$row->pool_length_unit);
            }
            $swims=\SwimLogEvaluation\Training_Trends::summarize_workouts($rows);
            $swimRows=array_filter($rows,static function($row){return in_array($row->workout_type,array('pool','open_water'),true);});
            $known=count(array_filter($swimRows,static function($row){return null!==$row->total_distance_m;}));
            $totals=array('Swimming'=>array('distance'=>$swims['distance_m'],'count'=>$swims['workout_count'],'known'=>$known));
            if($land) {
                $landTotals=\SwimLogEvaluation\Land_Activity::distances($rows);
                foreach(array('running'=>'Running','walking'=>'Walking') as $key=>$label) $totals[$label]=array('distance'=>$landTotals[$key],'count'=>$landTotals[$key.'_count'],'known'=>$landTotals[$key.'_known']);
            }
        }
        return array('rows'=>$result['rows'],'total'=>(int)$result['total'],'page'=>$page,'pages'=>$pages,'filters'=>$filters,'types'=>$types,'totals'=>$totals);
    }
    public static function url($filters,$page=1) {
        return '/?'.http_build_query(array('screen'=>'log','from'=>$filters['from'],'to'=>$filters['to'],'type'=>$filters['type'],'log_page'=>$page));
    }
    public static function label($type) { return array('pool'=>'Pool swim','open_water'=>'Open-water swim','running'=>'Run','walking'=>'Walk')[$type]??'Workout'; }
}

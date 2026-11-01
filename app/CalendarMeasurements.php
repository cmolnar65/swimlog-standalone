<?php
namespace SwimlogStandalone;
use SwimLogEvaluation\Calendar_Measurements as Service;
/** Use only the plugin's private calendar projection, never raw measurement rows. */
final class CalendarMeasurements {
    public static function load($owner,$start,$end){
        if(!Auth::requireCapability($owner)||Auth::cookieUser()!==$owner||!current_user_can('swimlog_manage_own_workouts'))return array();
        $path=SWIMLOG_EVALUATION_DIR.'includes/class-calendar-measurements.php';if(!is_readable($path))return new \WP_Error('calendar_measurements','Private measurements require an updated Swim Log plugin.');require_once $path;
        if(!is_callable(array(Service::class,'for_user'))||!Service::authorized($owner))return new \WP_Error('calendar_measurements','Private measurements are unavailable for this account.');
        $data=Service::for_user($owner,$start,$end);if(is_wp_error($data))return new \WP_Error('calendar_measurements','Private measurements could not be loaded for this range. Try a week or a single day.');
        $days=array();foreach($data['days']as$d){$day=array('reports'=>array(),'readings'=>array(),'aggregates'=>array(),'sleep_periods'=>array());
            foreach(array('reports','readings','aggregates')as$bucket)foreach($d[$bucket]as$r)$day[$bucket][]=array('category'=>$r['category'],'role'=>$r['role'],'values'=>$r['values'],'time_local'=>$r['source_local_time']??null,'time_utc'=>$r['time_utc']??null,'date_basis'=>$r['date_basis']??'provider_calendar','conflict'=>!empty($r['has_conflict']));
            foreach($d['sleep_periods']as$r)$day['sleep_periods'][]=array('duration_seconds'=>$r['duration_seconds'],'start_local'=>$r['start_local'],'end_local'=>$r['end_local'],'start_utc'=>$r['start_utc'],'end_utc'=>$r['end_utc']);
            if(array_filter($day))$days[$d['date']]=$day;
        }return $days;
    }
}

<?php
namespace SwimlogStandalone;
use SwimLogEvaluation\Event;
use SwimLogEvaluation\Workout;
use SwimLogEvaluation\Race_Readiness;
/** Owner-only event evaluation. The plugin owns every evidence calculation. */
final class SwimEvaluation {
    const PAGE_SIZE=20;
    private static function error($text,$status=400){return new \WP_Error('swim_evaluation',$text,array('status'=>$status));}
    public static function load($owner,array $query) {
        if(!$owner||Auth::cookieUser()!==$owner||!Auth::requireCapability($owner)||!current_user_can('swimlog_manage_own_workouts'))return self::error('Swim Evaluation access denied.',403);
        if(!defined('SWIMLOG_EVALUATION_DIR')||!defined('SWIMLOG_EVALUATION_DB_VERSION')||version_compare((string)get_option('swimlog_db_version','0'),SWIMLOG_EVALUATION_DB_VERSION,'<'))return self::error('The installed Swim Log plugin must be ready.',503);
        require_once __DIR__.'/AnalyticsReports.php';
        foreach(array('database','event','workout','race-readiness')as$file){$path=SWIMLOG_EVALUATION_DIR.'includes/class-'.$file.'.php';if(!is_readable($path))return self::error('Swim Evaluation requires an updated Swim Log plugin.',503);require_once $path;}
        if(!is_callable(array(Race_Readiness::class,'for_event')))return self::error('Swim Evaluation requires an updated Swim Log plugin.',503);
        $days=$query['lookback_days']??'42';$page=$query['evaluation_page']??'1';$id=$query['event_id']??null;
        if(!is_string($days)||!preg_match('/^[1-9][0-9]{0,2}$/D',$days)||(int)$days>365)return self::error('Choose a lookback period from 1 to 365 days.');
        if(!is_string($page)||!preg_match('/^[1-9][0-9]{0,5}$/D',$page))return self::error('Choose a valid event page.');
        if($id!==null&&(!is_string($id)||!preg_match('/^[1-9][0-9]{0,17}$/D',$id)||(float)$id>PHP_INT_MAX))return self::error('Choose a valid swim event.');
        $rows=Event::all_for_user($owner,'upcoming');if(is_wp_error($rows))return $rows;
        $total=count($rows);$pages=max(1,(int)ceil($total/self::PAGE_SIZE));$page=min((int)$page,$pages);$rows=array_slice($rows,($page-1)*self::PAGE_SIZE,self::PAGE_SIZE);
        $selected=$id!==null?Event::get_for_user((int)$id,$owner):($rows?$rows[0]:null);
        if($id!==null&&!$selected)return self::error('Swim event not found.',404);
        if($selected&&((int)$selected->user_id!==$owner||$selected->event_date<wp_date('Y-m-d')))return self::error('Choose an upcoming or today’s swim event. Past results remain available under Events.',404);
        $event=null;$ready=null;$evidence=array();
        if($selected){
            $event=self::project($selected);$ready=Race_Readiness::for_event($owner,$selected,(int)$days);
            if(!$ready)return self::error('This event does not have a supported swimming distance, stroke and course.',422);
            foreach(array('lifetime_pb_workout_id'=>'Lifetime PB workout','recent_best_workout_id'=>'Recent best workout','longest_recent_workout_id'=>'Longest recent performance workout')as$key=>$label){$workout=empty($ready[$key])?null:Workout::get_for_user((int)$ready[$key],$owner);if($workout&&($workout->workout_type??'pool')==='pool')$evidence[]=array('id'=>(int)$workout->id,'label'=>$label);}
        }
        $events=array_map(array(__CLASS__,'project'),$rows);
        if($event&&!in_array($event['id'],array_column($events,'id'),true))$events[]=$event;
        return array('event'=>$event,'events'=>$events,'ready'=>$ready,'evidence'=>$evidence,'days'=>(int)$days,'page'=>$page,'pages'=>$pages,'total'=>$total,'strokes'=>array('FR'=>'Freestyle','BR'=>'Breaststroke','BACK'=>'Backstroke','FLY'=>'Butterfly','MIXED'=>'Mixed'));
    }
    public static function project($row){return array('id'=>(int)$row->id,'title'=>(string)$row->event_name,'date'=>(string)$row->event_date,'time'=>(string)$row->event_time,'distance'=>(int)$row->distance_value,'course'=>(string)$row->course_unit,'stroke'=>(string)$row->stroke);}
    public static function url(array $model,array $changes=array()){$args=array('screen'=>'readiness','lookback_days'=>$model['days'],'evaluation_page'=>$model['page']);if($model['event'])$args['event_id']=$model['event']['id'];return '/?'.http_build_query(array_replace($args,$changes));}
}

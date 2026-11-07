<?php
namespace SwimlogStandalone;
use SwimLogEvaluation\Planned_Workout as Plans;
use SwimLogEvaluation\Planned_Structure_Link as Links;
use SwimLogEvaluation\Structured_Workout as Structure;
use SwimLogEvaluation\Workout;
use SwimLogEvaluation\Pool_Analytics;
use SwimLogEvaluation\Manual_Workout;
/** Evidence-only comparison of an explicit owner-scoped plan/workout match. */
final class PlanEvaluation {
    public static function error($text,$status=400){return new \WP_Error('plan_evaluation',$text,array('status'=>$status));}
    public static function load($owner,array $query){
        if(!Auth::requireCapability($owner)||Auth::cookieUser()!==$owner)return self::error('Plan evaluation access denied.',403);
        if(!defined('SWIMLOG_EVALUATION_DIR')||!defined('SWIMLOG_EVALUATION_DB_VERSION')||version_compare((string)get_option('swimlog_db_version','0'),SWIMLOG_EVALUATION_DB_VERSION,'<'))return self::error('The installed Swim Log plugin must be ready.',503);
        foreach(array('planned-workout','planned-structure-link','structured-workout-store','structured-workout','workout','pool-analytics','manual-workout')as$file){$path=SWIMLOG_EVALUATION_DIR.'includes/class-'.$file.'.php';if(!is_readable($path))return self::error('Plan evaluation requires an updated plugin.',503);require_once $path;}
        $page=$query['evaluation_page']??'1';$id=$query['evaluation_plan']??null;if(!is_string($page)||!preg_match('/^[1-9][0-9]{0,5}$/D',$page)||($id!==null&&(!is_string($id)||!preg_match('/^[1-9][0-9]{0,17}$/D',$id)||(float)$id>PHP_INT_MAX)))return self::error('Choose a valid plan and page.');
        $all=array_values(array_filter(Plans::all_for_user($owner),static function($p){return in_array($p->workout_type,array('pool','open_water'),true);}));$pages=max(1,(int)ceil(count($all)/20));$page=min((int)$page,$pages);$rows=array_slice($all,($page-1)*20,20);$choices=array();foreach($rows as$p)$choices[]=array('id'=>(int)$p->id,'title'=>$p->title,'date'=>$p->planned_date,'matched'=>!empty($p->completed_workout_id));
        $id=$id??($choices?(string)$choices[0]['id']:null);$out=array('choices'=>$choices,'page'=>$page,'pages'=>$pages,'plan'=>null,'actual'=>null,'structure'=>null,'strokes'=>array(),'warnings'=>array());if($id===null)return $out;
        $p=Plans::get_for_user((int)$id,$owner);if(!$p||!in_array($p->workout_type,array('pool','open_water'),true))return self::error('Swimming plan not found.',404);
        if(!in_array((int)$id,array_column($choices,'id'),true))$out['choices'][]=array('id'=>(int)$id,'title'=>$p->title,'date'=>$p->planned_date,'matched'=>!empty($p->completed_workout_id));
        $out['plan']=array('id'=>(int)$p->id,'title'=>$p->title,'date'=>$p->planned_date,'type'=>$p->workout_type,'status'=>$p->status,'distance'=>$p->planned_distance_m===null?null:(float)$p->planned_distance_m,'duration'=>$p->planned_duration_ms===null?null:(int)$p->planned_duration_ms,'course'=>$p->pool_course,'pool'=>$p->pool_length_m===null?null:(float)$p->pool_length_m);
        $linked=Links::for_plan((int)$id,$owner);if(is_wp_error($linked))return self::error('The prescription association needs review.',409);
        if($linked){$summary=Structure::summarize($linked['document']);if(is_wp_error($summary))return self::error('The linked prescription cannot be evaluated.',409);$factor=$summary['unit']==='yd'?0.9144:1;$by=array();foreach($summary['totals']['by_stroke']as$stroke=>$distance)$by[array('BK'=>'BACK','FL'=>'FLY','MX'=>'MIXED')[$stroke]??$stroke]=$distance*$factor;$out['structure']=array('id'=>$linked['id'],'title'=>$linked['title'],'distance'=>$summary['totals']['distance']*$factor,'steps'=>$summary['totals']['expanded_steps'],'strokes'=>$by,'unit'=>$summary['unit']);}
        if(empty($p->completed_workout_id))return $out;
        $w=Workout::get_for_user((int)$p->completed_workout_id,$owner);if(!$w||$w->workout_type!==$p->workout_type)return self::error('The matched swimming record is unavailable. Review the association.',409);
        $lengths=$w->workout_type==='pool'?Workout::lengths((int)$w->id,$owner):array();$laps=Workout::laps((int)$w->id,$owner);if(count($lengths)>10000||count($laps)>2000)return self::error('This matched swim exceeds the evaluation limit.',413);
        $actual=array('id'=>(int)$w->id,'date'=>$w->workout_start,'distance'=>$w->total_distance_m===null?null:(float)$w->total_distance_m,'elapsed'=>$w->elapsed_time_ms===null?null:(int)$w->elapsed_time_ms,'moving'=>$w->moving_time_ms===null?null:(int)$w->moving_time_ms,'repetitions'=>null,'lap_count'=>count(array_filter($laps,static function($l){return (float)$l->distance_m>0;})),'strokes'=>array(),'stroke_evidence'=>false);
        $projected=array();foreach($lengths as$l)$projected[]=(object)array('workout_id'=>(int)$w->id,'workout_start'=>$w->workout_start,'pool_length_unit'=>$w->pool_length_unit,'original_pool_length'=>$w->original_pool_length,'pool_length_m'=>$w->pool_length_m,'length_type'=>$l->length_type,'distance_m'=>$l->distance_m,'elapsed_time_ms'=>$l->elapsed_time_ms,'stroke'=>$l->stroke,'stroke_count'=>$l->stroke_count);
        foreach(Pool_Analytics::aggregate_rows($projected)as$g){$actual['stroke_evidence']=true;$actual['strokes'][$g['stroke']]=($actual['strokes'][$g['stroke']]??0)+$g['distance_native']*($g['course_unit']==='yd'?0.9144:1);}
        if($w->source_type==='manual'){$doc=Manual_Workout::get_document((int)$w->id,$owner);if(is_wp_error($doc))return self::error('The authoritative manual record needs review.',409);$doc=Manual_Workout::validate_document($doc);if(is_wp_error($doc))return self::error('The authoritative manual record needs review.',409);$actual['repetitions']=0;foreach($doc['segments']as$s)if($s['type']==='active')$actual['repetitions']+=$s['repetitions'];}
        $out['actual']=$actual;$strokes=array_unique(array_merge(array_keys($out['structure']['strokes']??array()),array_keys($actual['strokes'])));sort($strokes);foreach($strokes as$stroke){if(($out['structure']['strokes'][$stroke]??0)==0&&($actual['strokes'][$stroke]??0)==0)continue;$out['strokes'][]=array('stroke'=>$stroke,'planned'=>$out['structure']?($out['structure']['strokes'][$stroke]??0):null,'actual'=>$actual['stroke_evidence']?($actual['strokes'][$stroke]??0):null);}
        if($p->workout_type==='pool'&&($p->pool_course!==$w->pool_length_unit||$p->pool_length_m===null||$w->pool_length_m===null||abs((float)$p->pool_length_m-(float)$w->pool_length_m)>0.001))$out['warnings'][]='Pool course/length differs or is unspecified. Volume is expressed in meters; no pace or efficiency equivalence is assumed.';
        if($out['structure']&&$out['plan']['distance']!==null&&abs($out['structure']['distance']-$out['plan']['distance'])>0.01)$out['warnings'][]='The plan distance and current linked prescription total differ; both are shown separately.';
        if($linked&&!empty($w->structured_workout_id)&&(int)$w->structured_workout_id!==(int)$linked['id'])$out['warnings'][]='The recorded swim references a different prescription. This report uses the plan’s current linked prescription.';
        return $out;
    }
}

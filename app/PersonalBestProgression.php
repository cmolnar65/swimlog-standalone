<?php
namespace SwimlogStandalone;
use SwimLogEvaluation\Records;
use SwimLogEvaluation\Workout;
use SwimLogEvaluation\Evaluator;
/** Compatibility transport for existing evaluated performances, never a new eligibility evaluator. */
final class PersonalBestProgression {
    public static function load($owner,$distance,$course,$stroke){
        if(!Auth::requireCapability($owner)||Auth::cookieUser()!==$owner)return new \WP_Error('progression_access','Progression access denied.',array('status'=>403));
        if(!in_array($distance,Evaluator::DISTANCES,true)||!in_array($course,array('m','yd'),true)||!in_array($stroke,array('FR','BR','BACK','FLY','MIXED'),true))return new \WP_Error('progression_filter','Choose a valid progression distance, course and stroke.',array('status'=>400));
        try{$rows=Records::progression($owner,$distance,$course,$stroke);}
        catch(\Throwable $error){
            // Known plugin typo: returnDatabase::read instead of return Database::read.
            // Do not hide unrelated service failures or modify the installed plugin at runtime.
            if(!($error instanceof \Error)||strpos($error->getMessage(),'returnDatabase')===false)return new \WP_Error('progression_service','Progression could not be loaded. Retry or review the installed plugin.',array('status'=>503));
            $rows=self::fromWorkouts($owner,$distance,$course,$stroke);if(is_wp_error($rows))return $rows;
        }
        if(!is_array($rows))return new \WP_Error('progression_service','The installed progression service returned invalid data.',array('status'=>503));
        $safe=array();foreach($rows as$r){$w=Workout::get_for_user((int)$r->workout_id,$owner);if(!$w||$w->workout_type!=='pool')continue;$safe[]=self::project($r);}
        return $safe;
    }
    private static function fromWorkouts($owner,$distance,$course,$stroke){
        $filters=array('workout_type'=>'pool');$batch=Workout::query_for_user($owner,$filters,1,500);
        if($batch['total']>5000)return new \WP_Error('progression_limit','Progression exceeds the 5,000-workout compatibility limit. The plugin history service needs correction; no partial history is shown.',array('status'=>413));
        $rows=array();$count=0;for($page=1;$page<=max(1,$batch['pages']);$page++){
            if($page>1)$batch=Workout::query_for_user($owner,$filters,$page,500);
            foreach($batch['rows']as$w)foreach(Workout::performances((int)$w->id,$owner)as$r){if(++$count>100000)return new \WP_Error('progression_limit','Too many evaluated performances for the compatibility reader. No partial history is shown.',array('status'=>413));if((int)$r->distance_value===$distance&&$r->course_unit===$course&&$r->stroke===$stroke&&(int)$r->duration_ms>0)$rows[]=self::project($r);}
        }
        usort($rows,static function($a,$b){return array($a->achieved_at,$a->id)<=>array($b->achieved_at,$b->id);});
        $best=null;$out=array();foreach($rows as$r)if($best===null||$r->duration_ms<$best){$best=$r->duration_ms;$out[]=$r;}return $out;
    }
    private static function project($r){return(object)array('id'=>(int)$r->id,'workout_id'=>(int)$r->workout_id,'duration_ms'=>(int)$r->duration_ms,'achieved_at'=>(string)$r->achieved_at);}
}

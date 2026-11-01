<?php
namespace SwimlogStandalone;
use SwimLogEvaluation\Workout;
use SwimLogEvaluation\Pool_Analytics;
/** Owner-only comparison projection; native plugin services own every swim metric. */
final class SwimComparison {
    public static function error($text,$status=400){return new \WP_Error('swim_comparison',$text,array('status'=>$status));}
    public static function load($owner,$ids){
        if(!Auth::requireCapability($owner)||Auth::cookieUser()!==$owner)return self::error('Sign in to compare your own swims.',403);
        if(!defined('SWIMLOG_EVALUATION_DIR')||!defined('SWIMLOG_EVALUATION_DB_VERSION')||version_compare((string)get_option('swimlog_db_version','0'),SWIMLOG_EVALUATION_DB_VERSION,'<'))return self::error('The installed Swim Log plugin must be ready.',503);
        foreach(array('workout','pool-analytics')as$file){$path=SWIMLOG_EVALUATION_DIR.'includes/class-'.$file.'.php';if(!is_readable($path))return self::error('Swim comparison requires an updated plugin.',503);require_once $path;}
        if(!is_callable(array(Pool_Analytics::class,'aggregate_rows')))return self::error('Swim comparison requires an updated plugin.',503);
        if(!is_array($ids)||count($ids)!==2)return self::error('Choose exactly two different pool swims from the Workout Log.');
        foreach($ids as$id)if(!is_string($id)||!preg_match('/^[1-9][0-9]{0,17}$/D',$id)||(float)$id>PHP_INT_MAX)return self::error('Choose valid swim identifiers.');
        $ids=array_values($ids);if($ids[0]===$ids[1])return self::error('Choose two different swims.');
        $swims=array();
        foreach($ids as$id){
            $w=Workout::get_for_user((int)$id,$owner);if(!$w||$w->workout_type!=='pool')return self::error('Swim not found.',404);
            $lengths=Workout::lengths((int)$id,$owner);if(count($lengths)>10000)return self::error('This swim exceeds the 10,000-length comparison limit.',413);
            $course=(string)$w->pool_length_unit;$pool=(float)$w->original_pool_length;
            if($pool<=0&&is_numeric($w->pool_length_m))$pool=$course==='yd'?(float)$w->pool_length_m/0.9144:(float)$w->pool_length_m;
            // Unknown course/pool metadata cannot support reliable efficiency comparisons.
            $valid=in_array($course,array('m','yd'),true)&&$pool>0&&is_finite($pool);$rows=array();
            if($valid)foreach($lengths as$l)$rows[]=(object)array('workout_id'=>(int)$id,'workout_start'=>$w->workout_start,'pool_length_unit'=>$course,'original_pool_length'=>$pool,'pool_length_m'=>$w->pool_length_m,'length_type'=>$l->length_type,'distance_m'=>$l->distance_m,'elapsed_time_ms'=>$l->elapsed_time_ms,'stroke'=>$l->stroke,'stroke_count'=>$l->stroke_count);
            $groups=Pool_Analytics::aggregate_rows($rows);$byStroke=array();foreach($groups as$g)$byStroke[$g['stroke']]=$g;
            $swims[]=array('id'=>(int)$id,'start'=>$w->workout_start,'distance_m'=>$w->total_distance_m,'elapsed_ms'=>$w->elapsed_time_ms,'course'=>$course,'pool'=>$valid?round($pool,3):null,'groups'=>$byStroke);
        }
        $a=$swims[0];$b=$swims[1];$sameCourse=in_array($a['course'],array('m','yd'),true)&&$a['course']===$b['course'];$samePool=$sameCourse&&$a['pool']!==null&&$b['pool']!==null&&abs($a['pool']-$b['pool'])<0.001;
        $warnings=array();if(!$sameCourse)$warnings[]='Course units differ or are unknown. Pace deltas and paired charts are unavailable; no implicit meter/yard conversion is applied.';
        if(!$samePool)$warnings[]='Pool lengths differ or are unknown. SWOLF and strokes per length have no direct delta or paired chart.';
        if($a['distance_m']===null||$b['distance_m']===null||abs((float)$a['distance_m']-(float)$b['distance_m'])>0.01)$warnings[]='Session distances differ or are unavailable. These are similar sessions, not identical workloads.';
        $strokes=array_values(array_unique(array_merge(array_keys($a['groups']),array_keys($b['groups']))));sort($strokes);
        $ak=array_keys($a['groups']);$bk=array_keys($b['groups']);sort($ak);sort($bk);if($ak!==$bk)$warnings[]='Stroke mixes differ. Only strokes with recorded evidence in both swims receive deltas.';
        return array('swims'=>$swims,'strokes'=>$strokes,'same_course'=>$sameCourse,'same_pool'=>$samePool,'warnings'=>$warnings);
    }
    public static function value($value,$metric){if($value===null)return '—';return $metric==='avg_pace_100_ms'?Workout::format_duration($value):number_format_i18n((float)$value,2);}
    public static function delta($a,$b,$metric,$allowed){if(!$allowed||$a===null||$b===null)return 'Not comparable';$d=$b-$a;if(abs($d)<0.00001)return 'No change';return ($d>0?'+':'−').self::value(abs($d),$metric).' (B − A)';}
    /** Presentation only: bars use plugin values; precise values and samples remain in the table. */
    public static function chart($a,$b,$metric,$title){
        if($a===null||$b===null||max($a,$b)<=0)return '<p>Paired chart unavailable: both swims need recorded samples.</p>';
        $max=max($a,$b);$out='<svg class="comparison-chart" viewBox="0 0 440 110" role="img" aria-label="'.esc_attr($title).'"><title>'.esc_html($title).'</title>';
        foreach(array($a,$b)as$i=>$v){$y=12+$i*48;$out.='<text x="0" y="'.($y+21).'">'.($i===0?'A':'B').'</text><rect x="26" y="'.$y.'" width="'.esc_attr(280*$v/$max).'" height="30" fill="'.($i===0?'#245c9c':'#946000').'"></rect><text x="320" y="'.($y+21).'">'.esc_html(self::value($v,$metric)).'</text>';}
        return $out.'</svg>';
    }
}

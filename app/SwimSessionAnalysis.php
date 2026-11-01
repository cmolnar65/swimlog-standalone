<?php
namespace SwimlogStandalone;
use SwimLogEvaluation\Workout;
use SwimLogEvaluation\Pool_Analytics as Analytics;
use SwimLogEvaluation\Manual_Workout as Manual;
/** Read-only session evidence; native plugin aggregation owns pace and variation. */
final class SwimSessionAnalysis {
    public static function error($text,$status=400){return new \WP_Error('session_analysis',$text,array('status'=>$status));}
    private static function positive($n){return is_numeric($n)&&is_finite((float)$n)&&(float)$n>0;}
    public static function load($owner,array $query){
        if(!Auth::requireCapability($owner)||Auth::cookieUser()!==$owner)return self::error('Swimming analysis access denied.',403);
        if(!defined('SWIMLOG_EVALUATION_DIR')||!defined('SWIMLOG_EVALUATION_DB_VERSION')||version_compare((string)get_option('swimlog_db_version','0'),SWIMLOG_EVALUATION_DB_VERSION,'<'))return self::error('The installed Swim Log plugin must be ready.',503);
        foreach(array('workout','pool-analytics','manual-workout')as$file){$path=SWIMLOG_EVALUATION_DIR.'includes/class-'.$file.'.php';if(!is_readable($path))return self::error('Swimming analysis requires an updated plugin.',503);require_once $path;}
        $page=$query['analysis_page']??'1';$id=$query['swim_id']??null;
        if(!is_string($page)||!preg_match('/^[1-9][0-9]{0,5}$/D',$page)||($id!==null&&(!is_string($id)||!preg_match('/^[1-9][0-9]{0,17}$/D',$id)||(float)$id>PHP_INT_MAX)))return self::error('Choose a valid swim and page.');
        $list=Workout::query_for_user($owner,array('workout_type'=>'pool'),1,20);$page=min((int)$page,max(1,$list['pages']));if($page!==1)$list=Workout::query_for_user($owner,array('workout_type'=>'pool'),$page,20);$choices=array();foreach($list['rows']as$r)$choices[]=array('id'=>(int)$r->id,'date'=>$r->workout_start);
        $id=$id??($choices?(string)$choices[0]['id']:null);$base=array('choices'=>$choices,'page'=>(int)$page,'pages'=>max(1,$list['pages']),'swim'=>null);
        if($id===null)return $base;
        $w=Workout::get_for_user((int)$id,$owner);if(!$w||$w->workout_type!=='pool')return self::error('Swim not found.',404);
        if(!in_array((int)$id,array_column($choices,'id'),true))$base['choices'][]=array('id'=>(int)$id,'date'=>$w->workout_start);
        $unit=$w->pool_length_unit;$pool=(float)$w->original_pool_length;if($pool<=0&&self::positive($w->pool_length_m))$pool=$unit==='yd'?$w->pool_length_m/0.9144:(float)$w->pool_length_m;
        if(!in_array($unit,array('m','yd'),true)||!self::positive($pool))return self::error('This swim needs a known meter/yard course and pool length for reliable analysis.',422);
        $lengths=Workout::lengths((int)$id,$owner);$laps=Workout::laps((int)$id,$owner);if(count($lengths)>10000||count($laps)>2000)return self::error('This swim exceeds the bounded analysis limit.',413);
        $rows=array();$samples=array();foreach($lengths as$l){$r=self::row($w,$pool,$l->distance_m,$l->elapsed_time_ms,$l->stroke,$l->length_type,$l->stroke_count);$rows[]=$r;$one=Analytics::aggregate_rows(array($r));if($one&&$one[0]['avg_pace_100_ms']!==null)$samples[$one[0]['stroke']][]=array('sequence'=>(int)$l->sequence_no,'pace'=>$one[0]['avg_pace_100_ms']);}
        $groups=Analytics::aggregate_rows($rows);$sets=array();$omitted=0;
        if($w->source_type==='manual'){
            $doc=Manual::get_document((int)$id,$owner);if(is_wp_error($doc))return $doc;$doc=Manual::validate_document($doc);if(is_wp_error($doc))return $doc;
            $repCount=0;foreach($doc['segments']as$s)$repCount+=(int)$s['repetitions'];if(count($doc['segments'])>1000||$repCount>10000)return self::error('This manual document exceeds the bounded analysis limit.',413);
            foreach($doc['segments']as$i=>$s){if($s['type']!=='active')continue;if($s['timing_type']!=='measured_rep'||$s['repetitions']<2){$omitted++;continue;}$reps=array();foreach($s['rep_times_ms']as$j=>$ms)$reps[]=array('number'=>$j+1,'distance'=>(float)$s['distance_per_rep'],'ms'=>$ms,'rest'=>null,'basis'=>'Explicitly measured repetition');$set=self::set($w,$pool,$s['stroke'],$reps,'Manual set '.($i+1),'Per-repetition rest is not individually recorded');$set['entered_rest']=$s['rest_time_ms'];$sets[]=$set;}
        }else{
            // Candidate sets preserve recorded lap order; never split a long lap into invented repeats.
            $runs=array();$run=array();$key=null;
            foreach($laps as$l){
                if($l->distance_m!==null&&(float)$l->distance_m===0){if($run){$last=count($run)-1;$ms=$l->elapsed_time_ms;$run[$last]['rest_seen']=true;if(is_numeric($ms)&&(float)$ms>=0&&empty($run[$last]['rest_unknown']))$run[$last]['rest']=($run[$last]['rest']??0)+(int)$ms;else{$run[$last]['rest_unknown']=true;$run[$last]['rest']=null;}}continue;}
                $stroke=strtoupper((string)$l->stroke);$distance=$unit==='yd'?(float)$l->distance_m/0.9144:(float)$l->distance_m;
                if(!self::positive($distance)||!in_array($stroke,array('FR','BR','BACK','FLY'),true)){if($run)$runs[]=$run;$run=array();$key=null;continue;}
                $basis=self::positive($l->moving_time_ms)?'Recorded lap moving time':'Recorded lap elapsed time (may include in-lap rest)';$ms=self::positive($l->moving_time_ms)?(int)$l->moving_time_ms:(self::positive($l->elapsed_time_ms)?(int)$l->elapsed_time_ms:null);
                $next=$stroke.'|'.number_format($distance,3,'.','').'|'.$basis;if($key!==null&&$next!==$key){$runs[]=$run;$run=array();}$key=$next;$run[]=array('number'=>(int)$l->sequence_no,'distance'=>round($distance,3),'ms'=>$ms,'rest'=>null,'basis'=>$basis,'stroke'=>$stroke);
            }
            if($run)$runs[]=$run;foreach($runs as$run){if(count($run)<2){$omitted++;continue;}$sets[]=self::set($w,$pool,$run[0]['stroke'],$run,'Candidate repeated lap group','Recorded zero-distance rest laps after repetition');}
        }
        $base['swim']=array('id'=>(int)$id,'date'=>$w->workout_start,'course'=>$unit,'pool'=>$pool,'source'=>$w->source_type==='manual'?'manual':'imported');$base['groups']=$groups;$base['samples']=$samples;$base['sets']=$sets;$base['omitted']=$omitted;return $base;
    }
    private static function row($w,$pool,$distance,$ms,$stroke,$type='active',$count=null){return(object)array('workout_id'=>(int)$w->id,'workout_start'=>$w->workout_start,'pool_length_unit'=>$w->pool_length_unit,'original_pool_length'=>$pool,'pool_length_m'=>$w->pool_length_m,'length_type'=>$type,'distance_m'=>$distance,'elapsed_time_ms'=>$ms,'stroke'=>$stroke,'stroke_count'=>$count);}
    private static function set($w,$pool,$stroke,$reps,$label,$restLabel){$rows=array();foreach($reps as$i=>$r){$row=self::row($w,$pool,$r['distance']*($w->pool_length_unit==='yd'?0.9144:1),$r['ms'],$stroke);$rows[]=$row;$one=Analytics::aggregate_rows(array($row));$reps[$i]['pace']=$one[0]['avg_pace_100_ms']??null;}$groups=Analytics::aggregate_rows($rows);$times=array_column(array_filter($reps,static function($r){return $r['ms']!==null;}),'ms');$first=$reps[0]['ms'];$last=$reps[count($reps)-1]['ms'];return array('label'=>$label,'stroke'=>$stroke,'distance'=>$reps[0]['distance'],'reps'=>$reps,'metrics'=>$groups[0]??null,'fastest'=>$times?min($times):null,'slowest'=>$times?max($times):null,'change'=>$first!==null&&$last!==null?$last-$first:null,'rest_label'=>$restLabel);}
    public static function chart($samples,$label){if(!$samples)return '<p>No timed samples available.</p>';$max=max(array_column($samples,'pace'));if($max<=0)return '';$points=array();$out='<svg class="comparison-chart" viewBox="0 0 640 220" role="img" aria-label="'.esc_attr($label).'"><title>'.esc_html($label).'</title><text x="12" y="18">Higher means slower pace</text>';foreach($samples as$i=>$s){$x=25+590*$i/max(1,count($samples)-1);$y=190-150*$s['pace']/$max;$points[]=$x.','.$y;$out.='<circle cx="'.esc_attr($x).'" cy="'.esc_attr($y).'" r="3" fill="#245c9c"><title>'.esc_html('Sample '.$s['sequence'].' · '.Workout::format_duration($s['pace']).' per 100').'</title></circle>';}$out.='<polyline fill="none" stroke="#245c9c" stroke-width="2" points="'.esc_attr(implode(' ',$points)).'"/><text x="12" y="215">Recorded order → (timed samples only)</text></svg>';return $out;}
}

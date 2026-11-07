<?php
namespace SwimlogStandalone;
use SwimLogEvaluation\Workout;
use SwimLogEvaluation\Manual_Workout as Manual;
/** Native swim totals plus descriptive source-distance histograms; no training-zone inference. */
final class TrainingBalance {
    public static function error($text,$status=400){return new \WP_Error('training_balance',$text,array('status'=>$status));}
    public static function load($owner,array $query){
        require_once __DIR__.'/AnalyticsReports.php';$r=AnalyticsReports::load($owner,'trends',$query);if(is_wp_error($r))return $r;
        $window=$r['data']['window'];$end=(new \DateTimeImmutable($window['end'],wp_timezone()))->modify('+1 second')->format('Y-m-d H:i:s');
        $rows=Workout::range_for_user($owner,$window['current_start'],$end,false);if(count($rows)>1000)return self::error('More than 1,000 swims match. Narrow the period; no partial balance is shown.',413);
        $path=SWIMLOG_EVALUATION_DIR.'includes/class-manual-workout.php';if(!is_readable($path))return self::error('Training Balance requires an updated plugin.',503);require_once $path;
        $coverage=array('pool'=>array('known'=>0,'missing'=>0),'open_water'=>array('known'=>0,'missing'=>0));$profiles=array();$unclassified=0;$totalLaps=0;$totalReps=0;
        foreach($rows as$w){$type=$w->workout_type;if(!isset($coverage[$type]))continue;$coverage[$type][$w->total_distance_m===null?'missing':'known']++;
            if($type!=='pool')continue;$course=$w->pool_length_unit;if(!in_array($course,array('m','yd'),true)){$unclassified++;continue;}
            if($w->source_type==='manual'){
                $doc=Manual::get_document((int)$w->id,$owner);if(is_wp_error($doc))return self::error('An authoritative manual document needs review. No partial profile is shown.',409);$doc=Manual::validate_document($doc);if(is_wp_error($doc))return self::error('An authoritative manual document needs review. No partial profile is shown.',409);
                $found=false;foreach($doc['segments']as$s)if($s['type']==='active'){$n=(int)$s['repetitions'];$totalReps+=$n;if($totalReps>50000)return self::error('Too many documented repetitions. Narrow the period.',413);self::add($profiles,'manual',$course,(float)$s['distance_per_rep'],$n);$found=true;}if(!$found)$unclassified++;
            }else{
                $laps=Workout::laps((int)$w->id,$owner);$totalLaps+=count($laps);if(count($laps)>2000||$totalLaps>50000)return self::error('Too many recorded laps. Narrow the period; no partial profile is shown.',413);$found=false;
                foreach($laps as$l)if(is_numeric($l->distance_m)&&is_finite((float)$l->distance_m)&&(float)$l->distance_m>0){$distance=$course==='yd'?$l->distance_m/0.9144:(float)$l->distance_m;self::add($profiles,'laps',$course,$distance,1);$found=true;}if(!$found)$unclassified++;
            }
        }
        return array('months'=>$r['months'],'strokes'=>$r['strokes'],'window'=>$window,'summary'=>$r['data']['current'],'stroke_volume'=>$r['data']['stroke_volume'],'pool_course'=>$r['data']['pool_course'],'coverage'=>$coverage,'profiles'=>$profiles,'unclassified'=>$unclassified);
    }
    private static function add(&$groups,$source,$course,$distance,$count){$key=$source.'_'.$course;if(!isset($groups[$key]))$groups[$key]=array('source'=>$source,'course'=>$course,'bins'=>array_fill(0,3,array('count'=>0,'distance'=>0.0)));$distance=round($distance,3);$bin=$distance<=100?0:($distance<=400?1:2);$groups[$key]['bins'][$bin]['count']+=$count;$groups[$key]['bins'][$bin]['distance']+=$distance*$count;}
    public static function share($value,$total){return $total>0?number_format_i18n(100*$value/$total,1).'%':'Unavailable';}
    public static function chart(array $rows,$label){$total=array_sum(array_column($rows,'value'));if($total<=0)return '<p>No positive recorded distance for a chart.</p>';$height=45*count($rows)+20;$out='<svg class="balance-chart" viewBox="0 0 660 '.$height.'" role="img" aria-label="'.esc_attr($label).'"><title>'.esc_html($label).'</title>';foreach($rows as$i=>$row){$y=$i*45+8;$out.='<text x="0" y="'.($y+21).'">'.esc_html($row['label']).'</text><rect x="190" y="'.$y.'" height="28" width="'.esc_attr(350*$row['value']/$total).'" fill="#245c9c"/><text x="550" y="'.($y+21).'">'.esc_html(self::share($row['value'],$total)).'</text>'; }return $out.'</svg>';}
}

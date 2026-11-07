<?php
namespace SwimlogStandalone;
require_once __DIR__.'/ChartPoints.php';
/** Presentation over native monthly pool analytics; no separate metric engine. */
final class EfficiencyTrends {
    public static function load($owner,array $query){require_once __DIR__.'/AnalyticsReports.php';return AnalyticsReports::load($owner,'pool',$query);}
    public static function chart(array $months,$key,$label,array $context=array()){
        $values=array_filter(array_column($months,$key),static function($v){return $v!==null;});if(!$values)return '<p>No recorded samples for this metric.</p>';
        $max=max($values);$first=new \DateTimeImmutable($months[0]['month'].'-01');$last=new \DateTimeImmutable($months[count($months)-1]['month'].'-01');$span=(int)$first->diff($last)->format('%y')*12+(int)$first->diff($last)->format('%m');
        $out='<svg class="comparison-chart" viewBox="0 0 640 220" role="group" aria-label="'.esc_attr($label).'"><title>'.esc_html($label).'</title>';$prev=null;$prevIndex=null;
        foreach($months as$row){$d=new \DateTimeImmutable($row['month'].'-01');$diff=$first->diff($d);$index=(int)$diff->y*12+(int)$diff->m;$v=$row[$key];if($v===null){$prev=null;continue;}$x=25+590*$index/max(1,$span);$y=180-140*$v/max(1,$max);if($prev!==null&&$index===$prevIndex+1)$out.='<line x1="'.esc_attr($prev[0]).'" y1="'.esc_attr($prev[1]).'" x2="'.esc_attr($x).'" y2="'.esc_attr($y).'" stroke="#245c9c" stroke-width="2"/>';$value=$key==='avg_pace_100_ms'?\SwimLogEvaluation\Workout::format_duration($v):number_format_i18n($v,2);$metric=array('avg_pace_100_ms'=>'Pace','avg_swolf'=>'SWOLF','avg_stroke_count'=>'Strokes per length')[$key]??$key;$sampleKey=array('avg_pace_100_ms'=>'timed_lengths','avg_swolf'=>'swolf_lengths','avg_stroke_count'=>'stroke_count_lengths')[$key]??'';$text='Month: '.$row['month'].' · '.$metric.': '.$value.($key==='avg_pace_100_ms'?' / 100 '.($context['course_unit']??'native units'):'').' · Samples: '.($row[$sampleKey]??0).' / '.($row['active_lengths']??0).' active lengths · Swims: '.($row['workout_count']??0);if($context)$text.=' · '.($context['stroke']??'').' · '.($context['pool_length']??'').' '.($context['course_unit']??'').' pool';$out.=ChartPoints::point($x,$y,$text);$prev=array($x,$y);$prevIndex=$index;}
        return $out.'<text x="12" y="210">'.esc_html($months[0]['month'].' → '.$months[count($months)-1]['month']).'</text></svg>';
    }
}

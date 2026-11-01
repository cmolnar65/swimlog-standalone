<?php
namespace SwimlogStandalone;
use SwimLogEvaluation\Training_Trends;
use SwimLogEvaluation\Pool_Analytics;
/** Private owner report adapters; all metrics are calculated by plugin services. */
final class AnalyticsReports {
    public static function load($owner,$screen,array $query) {
        if(!$owner||Auth::cookieUser()!==$owner||!Auth::requireCapability($owner)||!current_user_can('swimlog_manage_own_workouts'))return new \WP_Error('analytics_access','Swimming report access denied.',array('status'=>403));
        if(!in_array($screen,array('trends','pool'),true))return new \WP_Error('analytics_screen','Unknown swimming report.',array('status'=>400));
        if(!defined('SWIMLOG_EVALUATION_DIR')||!defined('SWIMLOG_EVALUATION_DB_VERSION')||version_compare((string)get_option('swimlog_db_version','0'),SWIMLOG_EVALUATION_DB_VERSION,'<'))return new \WP_Error('analytics_ready','The installed Swim Log plugin must be ready.',array('status'=>503));
        $file=$screen==='trends'?'training-trends':'pool-analytics';
        if(!is_file(SWIMLOG_EVALUATION_DIR.'includes/class-'.$file.'.php'))return new \WP_Error('analytics_service','This swimming report requires an updated plugin.',array('status'=>503));
        foreach(array('database','workout',$file)as$part)require_once SWIMLOG_EVALUATION_DIR.'includes/class-'.$part.'.php';
        $service=$screen==='trends'?Training_Trends::class:Pool_Analytics::class;
        if(!is_callable(array($service,'for_user')))return new \WP_Error('analytics_service','This swimming report requires an updated plugin.',array('status'=>503));
        $months=$query['months']??(string)$service::DEFAULT_LOOKBACK_MONTHS;
        if(!is_string($months)||!preg_match('/^(?:[1-9]|1[0-9]|2[0-4])$/D',$months))return new \WP_Error('analytics_months','Choose a period from 1 to 24 months.',array('status'=>400));
        $strokes=array('FR'=>'Freestyle','BR'=>'Breaststroke','BACK'=>'Backstroke','FLY'=>'Butterfly','MIXED'=>'Mixed','UNKNOWN'=>'Unknown');
        $course=$query['course']??get_option('swimlog_default_course_unit','m');$stroke=$query['stroke']??'';$additional=$query['additional_metrics']??'0';
        if($screen==='pool'&&(!is_string($course)||!in_array($course,array('m','yd'),true)||!is_string($stroke)||($stroke!==''&&!in_array($stroke,Pool_Analytics::STROKES,true))||!is_string($additional)||!in_array($additional,array('0','1'),true)))return new \WP_Error('analytics_filters','Choose a valid course, stroke and metrics option.',array('status'=>400));
        $additional=$additional==='1';
        $data=$screen==='trends'?Training_Trends::for_user($owner,(int)$months):Pool_Analytics::for_user($owner,array('course'=>$course,'stroke'=>$stroke,'months'=>(int)$months));
        return compact('screen','months','course','stroke','additional','strokes','data');
    }
    public static function distance($value,$unit='m') {return null===$value?'—':number_format_i18n((float)$value,2).' '.$unit;}
    /** Presentation-only bars; native weekly values and periods remain in the table. */
    public static function chart(array $rows) {
        if(!$rows)return '';
        $max=max(array_column($rows,'distance_m'));if($max<=0)return '';
        $width=600/count($rows);$out='<svg class="swim-trend-chart" viewBox="0 0 640 200" role="img" aria-label="Swimming distance by recorded week; exact values in Weekly Trend table"><title>Weekly swimming distance in meters</title>';
        foreach($rows as$i=>$row){$height=160*max(0,(float)$row['distance_m'])/$max;$x=20+$i*$width;$out.='<rect x="'.esc_attr($x).'" y="'.esc_attr(180-$height).'" width="'.esc_attr(max(1,$width-2)).'" height="'.esc_attr($height).'" fill="#5277c5"><title>'.esc_html($row['period'].' · '.self::distance($row['distance_m'])).'</title></rect>';}
        return $out.'</svg>';
    }
}

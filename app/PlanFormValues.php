<?php
namespace SwimlogStandalone;

/** Form-boundary compatibility; canonical validation/persistence stays in the plugin. */
final class PlanFormValues {
    public static function load() {
        // Admin presentation methods are optional: earlier plugin builds omit them.
        foreach(array('class-admin-ui.php','class-admin-planned-workouts.php')as$file) {
            $path=SWIMLOG_EVALUATION_DIR.'admin/'.$file;
            if(is_readable($path)&&($file==='class-admin-ui.php'||class_exists('SwimLogEvaluation\\Admin_UI')))require_once $path;
        }
    }
    public static function nativeDistance($meters,$unit) {
        if(is_callable(array('SwimLogEvaluation\\Admin_UI','native_distance')))return \SwimLogEvaluation\Admin_UI::native_distance($meters,$unit);
        if($meters===null||$meters==='')return '';
        return rtrim(rtrim(number_format((float)$meters/($unit==='yd'?0.9144:1),9,'.',''),'0'),'.');
    }
    public static function durationText($ms) {
        if(is_callable(array('SwimLogEvaluation\\Admin_UI','duration_text')))return \SwimLogEvaluation\Admin_UI::duration_text($ms);
        if($ms===null||$ms==='')return '';
        $ms=(int)$ms;$seconds=intdiv($ms,1000);$hours=intdiv($seconds,3600);
        $text=$hours>0?sprintf('%d:%02d:%02d',$hours,intdiv($seconds%3600,60),$seconds%60):sprintf('%d:%02d',intdiv($seconds,60),$seconds%60);
        return $text.($ms%1000?'.'.rtrim(sprintf('%03d',$ms%1000),'0'):'');
    }
    public static function durationMs($text) {
        if(is_callable(array('SwimLogEvaluation\\Admin_UI','duration_ms')))return \SwimLogEvaluation\Admin_UI::duration_ms($text);
        if(!is_string($text))return self::durationError();
        $text=trim($text);if($text==='')return null;
        if(!preg_match('/^(?:(\d{1,6}):)?(\d{1,6}):([0-5]\d)(?:\.(\d{1,3}))?$/D',$text,$m)||($m[1]!==''&&(int)$m[2]>59))return self::durationError();
        $ms=((int)$m[1]*3600+(int)$m[2]*60+(int)$m[3])*1000+(int)str_pad($m[4]??'',3,'0');
        return $ms>0?$ms:self::durationError();
    }
    private static function durationError() {
        return new \WP_Error('plan_duration','Enter duration as M:SS or H:MM:SS. Seconds must be 00–59; duration must be positive or left blank.');
    }
    public static function normalize($data) {
        if(is_callable(array('SwimLogEvaluation\\Admin_Planned_Workouts','normalize_form'))&&is_callable(array('SwimLogEvaluation\\Admin_UI','duration_ms')))return \SwimLogEvaluation\Admin_Planned_Workouts::normalize_form($data);
        $duration=self::durationMs($data['planned_duration']);if(is_wp_error($duration))return $duration;
        $data['planned_duration_ms']=$duration;
        foreach(array('planned_distance'=>'planned_distance_m','pool_length_native'=>'pool_length_m')as$input=>$canonical) {
            $unit=$input==='planned_distance'?$data['planned_distance_unit']:$data['pool_course'];$value=$data[$input];
            if(!is_scalar($value))return new \WP_Error('plan_distance','Enter a valid positive distance and choose meters or yards.');
            if(trim((string)$value)==='') { $data[$canonical]=null;continue; }
            if(!in_array($unit,array('m','yd'),true)||!is_numeric($value)||!is_finite((float)$value)||(float)$value<=0)return new \WP_Error('plan_distance','Enter a valid positive distance and choose meters or yards.');
            $data[$canonical]=(float)$value*($unit==='yd'?0.9144:1);
        }
        return $data;
    }
}

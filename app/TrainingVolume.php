<?php
namespace SwimlogStandalone;
use SwimLogEvaluation\Workout;
/** Read-only owner adapter for native active-pool-length volume. */
final class TrainingVolume {
    public static function load($owner,array $query) {
        if(!$owner||Auth::cookieUser()!==$owner||!Auth::requireCapability($owner)||!current_user_can('swimlog_manage_own_workouts'))return new \WP_Error('volume_access','Training Volume access denied.',array('status'=>403));
        if(!defined('SWIMLOG_EVALUATION_DIR')||!defined('SWIMLOG_EVALUATION_DB_VERSION')||version_compare((string)get_option('swimlog_db_version','0'),SWIMLOG_EVALUATION_DB_VERSION,'<'))return new \WP_Error('volume_ready','The installed Swim Log plugin must be ready.',array('status'=>503));
        foreach(array('database','workout')as$file)require_once SWIMLOG_EVALUATION_DIR.'includes/class-'.$file.'.php';
        if(!is_callable(array(Workout::class,'stroke_volume_for_user')))return new \WP_Error('volume_service','Training Volume is unavailable in this installed plugin.',array('status'=>503));
        $saved=(int)get_user_meta($owner,'swimlog_training_volume_months',true);$default=$saved>=1&&$saved<=24?$saved:3;
        $months=$query['months']??(string)$default;$course=$query['course']??'';$stroke=$query['stroke']??'';
        $strokes=array('FR'=>'Freestyle','BR'=>'Breaststroke','BACK'=>'Backstroke','FLY'=>'Butterfly','MIXED'=>'Mixed','UNKNOWN'=>'Unknown');
        if(!is_string($months)||!preg_match('/^(?:[1-9]|1[0-9]|2[0-4])$/D',$months)||!is_string($course)||!in_array($course,array('','m','yd'),true)||!is_string($stroke)||($stroke!==''&&!isset($strokes[$stroke])))return new \WP_Error('volume_filters','Choose a period from 1 to 24 months, a valid course and stroke.',array('status'=>400));
        $rows=Workout::stroke_volume_for_user($owner,(int)$months);
        $rows=array_values(array_filter($rows,static function($row)use($course,$stroke){return in_array($row->course_unit,array('m','yd'),true)&&($course===''||$course===$row->course_unit)&&($stroke===''||$stroke===$row->stroke);}));
        $groups=array('m'=>array(),'yd'=>array());foreach($rows as$row)$groups[$row->course_unit][]=$row;
        return compact('months','course','stroke','strokes','rows','groups');
    }
}

<?php
namespace SwimlogStandalone;

/** Private, owner-scoped goals backed by the installed plugin goals table. */
final class Goals {
    private const SINGLE = 'single_performance';
    private const PERIOD = 'period_distance';
    private const SINGLE_WORKOUT = 'single_workout_distance';
    private const YARDS_TO_METERS = 0.9144;

    private static function ready($owner) {
        if (!Auth::requireCapability($owner) || Auth::cookieUser() !== (int)$owner || !current_user_can('swimlog_manage_own_workouts')) return new \WP_Error('goals_forbidden', 'Goal access denied.', array('status'=>403));
        if (!defined('SWIMLOG_EVALUATION_DIR') || !defined('SWIMLOG_EVALUATION_DB_VERSION') || version_compare((string)get_option('swimlog_db_version','0'), SWIMLOG_EVALUATION_DB_VERSION, '<')) return new \WP_Error('goals_plugin', 'The Swim Log installation must be updated before goals can be used.', array('status'=>503));
        foreach (array('class-database.php','class-swim-goals.php','class-evaluator.php') as $file) {
            $path=SWIMLOG_EVALUATION_DIR.'includes/'.$file;
            if (!is_readable($path)) return new \WP_Error('goals_service', 'A WordPress Swim Log goals service is unavailable.', array('status'=>503));
            require_once $path;
        }
        return true;
    }

    public static function load($owner) {
        $ready=self::ready($owner); if (is_wp_error($ready)) return $ready;
        global $wpdb;
        $table=\SwimLogEvaluation\Database::table('goals');
        $rows=\SwimLogEvaluation\Database::read('get_results',$wpdb->prepare('SELECT * FROM %i WHERE user_id=%d ORDER BY start_date DESC,id DESC LIMIT 200',$table,(int)$owner));
        $rows=array_map(static function($row){return self::progress(self::normalizeRow($row));},(array)$rows);
        return array('goals'=>$rows,'default_unit'=>self::defaultUnit($owner),'single_workout_supported'=>self::singleWorkoutSupported());
    }

    public static function submit($owner,$input,$origin,$appOrigin) {
        $ready=self::ready($owner); if (is_wp_error($ready)) return $ready;
        if ($origin!==$appOrigin || !is_string($input['nonce']??null) || !wp_verify_nonce($input['nonce'],'standalone_goal_save')) return self::error('This goal request expired. Reload the page and try again.',403);
        $allowed=array('nonce','goal_type','title','course_unit','target_distance_value','target_pace','target_distance','single_workout','start_date','end_date');
        if (array_diff(array_keys($input),$allowed)) return self::error('This form cannot change goal ownership or unrelated data.');
        foreach($input as $key=>$value) if (!is_string($value) || strlen($value)>191) return self::error('Invalid goal field.');
        $type=$input['goal_type']??''; $unit=$input['course_unit']??'';
        if (!in_array($unit,array('m','yd'),true)) return self::error('Choose meters or yards.');
        $title=sanitize_text_field(trim($input['title']??''));
        $today=wp_date('Y-m-d',null,wp_timezone());
        if ($type===self::SINGLE) {
            $distance=trim($input['target_distance_value']??'');
            $pace=self::parsePace($input['target_pace']??'');
            if ($distance!=='' && (!ctype_digit($distance) || !in_array((int)$distance,\SwimLogEvaluation\Evaluator::DISTANCES,true))) return self::error('Choose a supported evaluated swim distance.');
            if (($distance==='' && $pace===null) || ($input['target_pace']??'')!=='' && $pace===null) return self::error('Enter a distance, a target pace, or both; pace must use minutes:seconds per 100.');
            $due=self::validDate($input['end_date']??'');
            if (!$due || $due<$today) return self::error('Choose a valid due date that is today or later.');
            $normalized=\SwimLogEvaluation\Swim_Goals::normalize_input(array('goal_type'=>self::SINGLE,'title'=>$title,'course_unit'=>$unit,'target_distance_value'=>$distance===''?'100':$distance,'target_pace_ms'=>$pace===null?1:$pace,'start_date'=>$today,'end_date'=>$due));
            if(is_wp_error($normalized))return self::error($normalized->get_error_message());
            $goal=$normalized;$goal['target_distance_value']=$distance===''?null:(int)$distance;$goal['target_pace_ms']=$pace;
            if($title==='')$goal['title']=$distance!==''?(int)$distance.' '.$unit.' swim goal':$unit.' pace goal';
        } elseif ($type===self::PERIOD) {
            if(isset($input['single_workout'])&&$input['single_workout']!=='1')return self::error('Invalid Single Workout selection.');
            $singleWorkout=($input['single_workout']??'')==='1';
            if($singleWorkout&&!self::singleWorkoutSupported())return self::error('Update the WordPress Swim Log plugin before creating Single Workout goals.',503);
            $amount=trim($input['target_distance']??''); $start=self::validDate($input['start_date']??''); $end=self::validDate($input['end_date']??'');
            if ($amount==='' || !is_numeric($amount) || !is_finite((float)$amount) || (float)$amount<=0 || (float)$amount>100000000 || !$start || !$end || $end<$start) return self::error('Enter a positive distance and a valid inclusive date range.');
            $native=(float)$amount;$normalized=\SwimLogEvaluation\Swim_Goals::normalize_input(array('goal_type'=>self::PERIOD,'single_workout'=>$singleWorkout?'1':'','title'=>$title,'course_unit'=>$unit,'target_distance'=>$amount,'start_date'=>$start,'end_date'=>$end));
            if(is_wp_error($normalized))return self::error($normalized->get_error_message());
            $goal=$normalized;if($title==='')$goal['title']=self::formatNumber($native).' '.$unit.($singleWorkout?' single-workout goal':' swimming goal');
        } else return self::error('Choose a supported goal type.');
        global $wpdb;
        $now=current_time('mysql'); $goal['user_id']=(int)$owner; $goal['created_at']=$now; $goal['updated_at']=$now;
        if (false===\SwimLogEvaluation\Database::write('insert',\SwimLogEvaluation\Database::table('goals'),$goal)) return self::error('The goal could not be saved.',503);
        return array('url'=>'/?screen=goals&goal_saved=1');
    }

    public static function delete($owner,$input,$origin,$appOrigin) {
        $ready=self::ready($owner); if (is_wp_error($ready)) return $ready;
        if ($origin!==$appOrigin || !is_string($input['nonce']??null) || !wp_verify_nonce($input['nonce'],'standalone_goal_delete')) return self::error('This goal request expired. Reload the page and try again.',403);
        if(array_diff(array_keys($input),array('nonce','goal_id')))return self::error('This form cannot change unrelated data.');
        $id=$input['goal_id']??'';
        if (!is_string($id)||!preg_match('/^[1-9][0-9]{0,17}$/D',$id)) return self::error('Choose a valid goal.');
        if (!\SwimLogEvaluation\Swim_Goals::delete_for_user((int)$id,$owner)) return self::error('Goal not found.',404);
        return array('url'=>'/?screen=goals&goal_deleted=1');
    }

    /** Keep one-time evaluations course-specific and stop range totals at completion. */
    private static function progress($goal) {
        if (in_array($goal['goal_type'],array(self::PERIOD,self::SINGLE_WORKOUT),true)) {
            global $wpdb;
            $rows=\SwimLogEvaluation\Database::table('workouts');
            $next=(new \DateTimeImmutable($goal['end_date'],wp_timezone()))->modify('+1 day')->format('Y-m-d');
            $workouts=\SwimLogEvaluation\Database::read('get_results',$wpdb->prepare("SELECT id,workout_start,total_distance_m FROM %i WHERE user_id=%d AND workout_type IN ('pool','open_water') AND total_distance_m IS NOT NULL AND workout_start >= %s AND workout_start < %s ORDER BY workout_start ASC,id ASC",$rows,(int)$goal['user_id'],$goal['start_date'].' 00:00:00',$next.' 00:00:00'));
            $singleWorkout=$goal['goal_type']===self::SINGLE_WORKOUT;
            $targetMeters=max(0.001,(float)($goal['target_distance_m']??0)); $currentMeters=0.0; $bestSingleWorkout=0.0; $completionDate=null;
            foreach((array)$workouts as $workout) {
                $distance=(float)$workout->total_distance_m;
                if(!is_finite($distance)||$distance<=0)continue;
                if($singleWorkout) {
                    $bestSingleWorkout=max($bestSingleWorkout,$distance);
                    if($distance >= $targetMeters) {
                        $currentMeters=$targetMeters;
                        $completionDate=substr((string)$workout->workout_start,0,10);
                        break;
                    }
                    continue;
                }
                $currentMeters+=$distance;
                if($currentMeters >= $targetMeters) {
                    $currentMeters=$targetMeters;
                    $completionDate=substr((string)$workout->workout_start,0,10);
                    break;
                }
            }
            if($singleWorkout&&$completionDate===null)$currentMeters=$bestSingleWorkout;
            $currentNative=$goal['course_unit']==='yd'?$currentMeters/self::YARDS_TO_METERS:$currentMeters; $target=self::periodTarget($goal);
            $percent=$targetMeters>0?round(100*$currentMeters/$targetMeters,1):0;
            $goal['progress_native']=$currentNative; $goal['target_native']=$target; $goal['progress_percent']=$percent; $goal['progress_meters']=$currentMeters;
            $goal['completion_date']=$completionDate;
            $goal['status']=self::status($goal,$completionDate!==null);
            return $goal;
        }
        global $wpdb;
        $performances=\SwimLogEvaluation\Database::table('performances'); $workouts=\SwimLogEvaluation\Database::table('workouts');
        $where=array('p.user_id=%d','p.course_unit=%s','p.achieved_at >= %s','p.achieved_at < %s',"w.workout_type='pool'"); $args=array((int)$goal['user_id'],$goal['course_unit'],$goal['start_date'].' 00:00:00',(new \DateTimeImmutable($goal['end_date'],wp_timezone()))->modify('+1 day')->format('Y-m-d').' 00:00:00');
        if ($goal['target_distance_value']!==null) {$where[]='p.distance_value=%d';$args[]=(int)$goal['target_distance_value'];}
        $sql=$wpdb->prepare('SELECT p.distance_value,p.duration_ms,p.achieved_at FROM %i p INNER JOIN %i w ON w.id=p.workout_id AND w.user_id=p.user_id WHERE '.implode(' AND ',$where).' ORDER BY p.achieved_at ASC,p.id ASC',$performances,$workouts,...$args);
        $results=\SwimLogEvaluation\Database::read('get_results',$sql); $best=null; $completionDate=null;
        foreach((array)$results as $row) {
            $distance=(int)$row->distance_value; if($distance<=0)continue;
            $pace=(int)round((int)$row->duration_ms*100/$distance);
            if($best===null||$pace<$best['pace_ms'])$best=array('pace_ms'=>$pace,'achieved_at'=>(string)$row->achieved_at,'distance_value'=>$distance);
            if($completionDate===null&&($goal['target_pace_ms']===null||$pace<=(int)$goal['target_pace_ms']))$completionDate=substr((string)$row->achieved_at,0,10);
        }
        $goal['best_pace_ms']=$best['pace_ms']??null; $goal['achieved_at']=$best['achieved_at']??null; $goal['progress_distance_value']=$best['distance_value']??null;
        $goal['completion_date']=$completionDate;
        $goal['progress_percent']=$goal['target_pace_ms']&&$best?round(min(100,100*(int)$goal['target_pace_ms']/max(1,$best['pace_ms'])),1):($completionDate!==null?100:0);
        $goal['status']=self::status($goal,$completionDate!==null);
        return $goal;
    }

    public static function calendarMarkers($owner,$start,$end) {
        $ready=self::ready($owner); if(is_wp_error($ready))return $ready;
        global $wpdb;
        $table=\SwimLogEvaluation\Database::table('goals');
        $rows=\SwimLogEvaluation\Database::read('get_results',$wpdb->prepare("SELECT * FROM %i WHERE user_id=%d AND ((goal_type=%s AND end_date BETWEEN %s AND %s) OR (goal_type IN (%s,%s) AND (start_date BETWEEN %s AND %s OR end_date BETWEEN %s AND %s))) ORDER BY start_date ASC,id ASC",$table,(int)$owner,self::SINGLE,$start,$end,self::PERIOD,self::SINGLE_WORKOUT,$start,$end,$start,$end));
        $markers=array();
        foreach((array)$rows as$row) {
            $goal=self::progress(self::normalizeRow($row));
            if($goal['goal_type']===self::SINGLE) {$markers[]=self::calendarRow($goal,$goal['end_date'],'goal_due');continue;}
            if($goal['start_date']===$goal['end_date'])$markers[]=self::calendarRow($goal,$goal['start_date'],'goal_start_end');
            else {
                if($goal['start_date']>=$start&&$goal['start_date']<=$end)$markers[]=self::calendarRow($goal,$goal['start_date'],'goal_start');
                if($goal['end_date']>=$start&&$goal['end_date']<=$end)$markers[]=self::calendarRow($goal,$goal['end_date'],'goal_end');
            }
        }
        return $markers;
    }

    public static function formatPace($milliseconds) {
        $milliseconds=max(0,(int)$milliseconds); $seconds=(int)floor($milliseconds/1000); $hundredths=(int)round(($milliseconds%1000)/10);
        if($hundredths===100){$seconds++;$hundredths=0;}
        return sprintf('%d:%02d.%02d',(int)floor($seconds/60),$seconds%60,$hundredths);
    }
    public static function singleWorkoutSupported() { return defined('SwimLogEvaluation\\Swim_Goals::SINGLE_WORKOUT'); }
    public static function statusLabel($status) { return array('upcoming'=>'Upcoming','in_progress'=>'In progress','achieved'=>'Achieved','missed'=>'Not achieved by date')[$status]??'Unknown'; }
    public static function formatCompletionDate($date) {
        if(!is_string($date)||!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D',$date))return '';
        $local=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,wp_timezone());
        return $local?wp_date('M j, Y',$local->setTime(12,0)->getTimestamp(),wp_timezone()):'';
    }
    public static function singleTarget($goal) {
        $parts=array();
        if($goal['target_distance_value']!==null)$parts[]=(int)$goal['target_distance_value'].' '.$goal['course_unit'].' performance';
        if($goal['target_pace_ms']!==null)$parts[]='pace at or faster than '.self::formatPace($goal['target_pace_ms']).' / 100 '.$goal['course_unit'];
        if(!$parts)$parts[]='evaluated performance in '.$goal['course_unit'];
        return implode(' · ',$parts);
    }
    public static function periodTarget($goal) { $stored=(float)($goal['target_distance_m']??0); return $goal['course_unit']==='yd'?$stored/self::YARDS_TO_METERS:$stored; }
    public static function formatNumber($value) { return rtrim(rtrim(number_format((float)$value,3,'.',''),'0'),'.'); }
    private static function parsePace($value) { if(!preg_match('/^(\d{1,3}):([0-5]\d)(?:\.(\d{1,2}))?$/',trim((string)$value),$m))return null;$fraction=isset($m[3])?(int)str_pad($m[3],2,'0'):0;return(((int)$m[1]*60+(int)$m[2])*1000)+$fraction*10; }
    private static function validDate($value) { if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value))return false;$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,wp_timezone());$errors=\DateTimeImmutable::getLastErrors();return $date&&($errors===false||(!$errors['warning_count']&&!$errors['error_count']))&&$date->format('Y-m-d')===$value?$value:false; }
    private static function status($goal,$achieved) { if($achieved)return 'achieved';$today=wp_date('Y-m-d',null,wp_timezone());if($today<$goal['start_date'])return 'upcoming';if($today>$goal['end_date'])return 'missed';return 'in_progress'; }
    private static function normalizeRow($row) {
        $goal=is_object($row)?get_object_vars($row):(array)$row;
        $goal['id']=(int)($goal['id']??0);$goal['user_id']=(int)($goal['user_id']??0);
        foreach(array('target_distance_value','target_pace_ms')as$key)$goal[$key]=($goal[$key]??null)===null?null:(int)$goal[$key];
        $goal['target_distance_m']=($goal['target_distance_m']??null)===null?null:(float)$goal['target_distance_m'];
        return $goal;
    }
    private static function calendarRow($goal,$date,$marker) { $goal['date']=$date;$goal['marker']=$marker;return $goal; }
    private static function defaultUnit($owner) { $class='SwimLogEvaluation\\Unit_Preferences';if(!class_exists($class,false)){ $path=SWIMLOG_EVALUATION_DIR.'includes/class-unit-preferences.php';if(is_readable($path))require_once $path;}if(class_exists($class,false)){$prefs=$class::get($owner);if(in_array($prefs['swim_course']??null,array('m','yd'),true))return $prefs['swim_course'];}return 'm'; }
    private static function error($message,$status=400) { return new \WP_Error('standalone_goal', $message, array('status'=>$status)); }
}

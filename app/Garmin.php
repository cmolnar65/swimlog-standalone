<?php
namespace SwimlogStandalone;
use SwimLogEvaluation\Garmin_Personal_API as API;
use SwimLogEvaluation\Garmin_History_Jobs as Jobs;
use SwimLogEvaluation\Garmin_Measurement_Import as Measurements;
final class Garmin {
    public static function ready($owner,$screen){$ok=Settings::ready($owner);if(is_wp_error($ok))return $ok;if(!Settings::verified($owner))return Settings::error('Save and successfully verify your Garmin connection in Settings → Garmin Setup first.',403);
        if(!current_user_can($screen==='garmin_activities'?'swimlog_upload_workouts':'swimlog_manage_own_workouts'))return Settings::error('Your account cannot perform this Garmin operation.',403);
        foreach(array('garmin-history-jobs','garmin-measurement-import')as$file)require_once SWIMLOG_EVALUATION_DIR.'includes/class-'.$file.'.php';return true;}
    private static function key($owner,$token){return 'standalone_garmin_'.hash('sha256',$owner.'|'.wp_get_session_token().'|'.$token);}
    public static function range($input,$max){$start=$input['start']??'';$end=$input['end']??'';if(!is_string($start)||!is_string($end))return Settings::error('Choose valid dates.');$a=\DateTimeImmutable::createFromFormat('!Y-m-d',$start,wp_timezone());$b=\DateTimeImmutable::createFromFormat('!Y-m-d',$end,wp_timezone());if(!$a||!$b||$a->format('Y-m-d')!==$start||$b->format('Y-m-d')!==$end||$b<$a||$a->diff($b)->days>$max||$end>wp_date('Y-m-d'))return Settings::error('Choose valid dates ending no later than today within the displayed range.');return array($start,$end);}
    public static function submit($owner,$input,$origin,$appOrigin){
        $op=$input['operation']??null;$screens=array('activities_browse'=>'garmin_activities','activities_import'=>'garmin_activities','measurements_import'=>'garmin_measurements','history_start'=>'garmin_history','history_pause'=>'garmin_history','history_resume'=>'garmin_history','history_cancel'=>'garmin_history','history_retry'=>'garmin_history');
        if(!is_string($op)||!isset($screens[$op]))return Settings::error('Unknown Garmin operation.');$screen=$screens[$op];$ok=self::ready($owner,$screen);if(is_wp_error($ok))return $ok;
        if($origin!==$appOrigin||!is_string($input['nonce']??null)||!wp_verify_nonce($input['nonce'],'standalone_'.$op))return Settings::error('This Garmin request expired. Reload the screen.',403);
        $fields=array('activities_browse'=>array('start','end','types'),'activities_import'=>array('selection_token','activity_ids','location_id'),'measurements_import'=>array('start','end','categories'),'history_start'=>array('start','end','categories'))[$op]??array('job');
        if(array_diff(array_keys($input),array_merge(array('operation','nonce'),$fields)))return Settings::error('This form cannot change ownership or unrelated fields.');
        foreach($input as$key=>$value){if(in_array($key,array('types','activity_ids','categories'),true)){if(!is_array($value)||count($value)>25)return Settings::error('Invalid selection.');foreach($value as$v)if(!is_string($v)||strlen($v)>50)return Settings::error('Invalid selection.');}elseif(!is_string($value)||strlen($value)>200)return Settings::error('Invalid Garmin field.');}
        if($op==='activities_browse') {
            $dates=self::range($input,30);if(is_wp_error($dates))return $dates;$types=array_values(array_unique($input['types']??array()));if(!$types||array_diff($types,array_keys(Settings::activities($owner))))return Settings::error('Choose authorized activity types.');
            $rows=API::recent_activities($owner,100,$dates[0],$dates[1],$types);if(is_wp_error($rows))return $rows;$activities=array();
            foreach(array_slice($rows,0,100)as$row){$id=(string)($row['activityId']??'');$type=$row['activityType']['typeKey']??'';if(!preg_match('/^[1-9][0-9]{0,17}$/D',$id)||!in_array($type,$types,true))continue;$activities[]=array('id'=>$id,'name'=>(string)($row['activityName']??'Garmin activity'),'date'=>(string)($row['startTimeLocal']??''),'type'=>$type,'distance'=>isset($row['distance'])&&is_numeric($row['distance'])?(float)$row['distance']:null);}
            $token=bin2hex(random_bytes(24));set_transient(self::key($owner,$token),array_column($activities,'id'),1800);return array('activities'=>$activities,'selection_token'=>$token);
        }
        if($op==='activities_import') {
            $token=$input['selection_token']??'';if(!preg_match('/^[a-f0-9]{48}$/D',$token))return Settings::error('Reload the activity list.');$allowed=get_transient(self::key($owner,$token));$ids=array_values(array_unique($input['activity_ids']??array()));
            if(!is_array($allowed)||!$ids||count($ids)>25||array_diff($ids,$allowed))return Settings::error('Select at most 25 activities from your current list.');
            require_once __DIR__.'/WorkoutEntry.php';$location=WorkoutEntry::location($owner,$input['location_id']??'0');if(is_wp_error($location))return $location;$storage=WorkoutEntry::storage();if(is_wp_error($storage))return $storage;
            $out=array();foreach($ids as$id){$detail=API::activity($owner,(int)$id);$type=is_array($detail)?($detail['activityTypeDTO']['typeKey']??''):'';
                if(is_wp_error($detail)||!isset(Settings::activities($owner)[$type])){$out[]=array('activity'=>$id,'status'=>'Unavailable or unauthorized activity');continue;}
                $result=WorkoutEntry::withRoot(static function()use($owner,$id,$type,$location){return API::import_activity($owner,(int)$id,$type==='open_water_swimming'?$location:0);});
                $out[]=is_wp_error($result)?array('activity'=>$id,'status'=>$result->get_error_message()):array('activity'=>$id,'status'=>(string)($result['status']??'processed'),'workout_id'=>(int)($result['workout_id']??0));
            }return array('activity_results'=>$out);
        }
        if($op==='measurements_import'){$range=self::range($input,6);if(is_wp_error($range))return $range;$categories=$input['categories']??array();$valid=Measurements::range($range[0],$range[1],$categories);if(is_wp_error($valid))return $valid;$rows=Measurements::run($owner,$range[0],$range[1],$categories);if(is_wp_error($rows))return $rows;
            $out=array();foreach($rows as$row)$out[]=array_intersect_key($row,array_flip(array('date','category','status','added','updated','skipped','failed','excluded','reports_added','reports_updated','reports_skipped')));return array('measurement_results'=>$out);}
        if($op==='history_start'){$range=self::range($input,36500);if(is_wp_error($range))return $range;$cats=$input['categories']??array();$allowed=array_merge(array_keys(Settings::activities($owner)),array_keys(Settings::measurements()));if(!$cats||array_diff($cats,$allowed))return Settings::error('Choose authorized import categories.');$job=Jobs::start($range[0],$range[1],$cats);}
        else {$id=$input['job']??'';if(!preg_match('/^[a-f0-9]{32}$/D',$id))return Settings::error('Choose a valid historical job.');$job=Jobs::get($id);if(!is_array($job))return Settings::error('Historical job not found.',404);$job=Jobs::control($id,substr($op,8));}
        if(is_wp_error($job))return $job;return array('url'=>'/?screen=garmin_history&job='.$job['id'].'&garmin_saved=1');
    }
}

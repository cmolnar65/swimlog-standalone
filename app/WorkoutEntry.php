<?php
namespace SwimlogStandalone;
use SwimLogEvaluation\Manual_Workout as Manual;
use SwimLogEvaluation\Importer;
use SwimLogEvaluation\Location;
use SwimLogEvaluation\Workout;

/** Form/transport boundary only; canonical records and import sources stay in WordPress. */
final class WorkoutEntry {
    const FIELDS=array('workout_date','workout_time','location_id','pool_length','course_unit','total_distance','duration','notes');
    const SET_FIELDS=array('type','repetitions','distance','stroke','timing','rep_times','set_time','interval','rest','notes');
    public static function error($text,$status=422){return new \WP_Error('workout_entry',$text,array('status'=>$status));}
    public static function ready($owner,$mode) {
        if(Auth::cookieUser()!==$owner||!Auth::requireCapability($owner)||!current_user_can($mode==='manual'?'swimlog_manage_own_workouts':'swimlog_upload_workouts'))return self::error('Your account cannot perform this workout operation.',403);
        if(!defined('SWIMLOG_EVALUATION_DIR')||!defined('SWIMLOG_EVALUATION_DB_VERSION')||version_compare((string)get_option('swimlog_db_version','0'),SWIMLOG_EVALUATION_DB_VERSION,'<'))return self::error('The Swim Log installation must be updated before adding workouts.',503);
        foreach(array('manual-workout','importer','location','workout')as$file)require_once SWIMLOG_EVALUATION_DIR.'includes/class-'.$file.'.php';
        require_once __DIR__.'/PlanFormValues.php';PlanFormValues::load();
        return true;
    }
    public static function blankSet(){return array('type'=>'active','repetitions'=>'','distance'=>'','stroke'=>'UNKNOWN','timing'=>'unknown','rep_times'=>'','set_time'=>'','interval'=>'','rest'=>'','notes'=>'');}
    private static function key($owner,$token){return 'standalone_entry_'.hash('sha256',$owner.'|'.wp_get_session_token().'|'.$token);}
    public static function token($owner,$mode){$token=bin2hex(random_bytes(24));set_transient(self::key($owner,$token),array('mode'=>$mode,'done'=>false),7200);return $token;}
    public static function receipt($owner,$token){if(!is_string($token)||!preg_match('/^[a-f0-9]{48}$/D',$token))return false;return get_transient(self::key($owner,$token));}
    public static function form($owner){return array('token'=>self::token($owner,'manual'),'values'=>array('workout_date'=>wp_date('Y-m-d'),'workout_time'=>'','location_id'=>'0','pool_length'=>'25','course_unit'=>'m','total_distance'=>'','duration'=>'','notes'=>''),'sets'=>array(self::blankSet()),'error'=>null);}
    public static function locations($owner){$rows=array();foreach(Location::all_for_user($owner)as$row)$rows[]=array('id'=>(int)$row->id,'name'=>$row->name);return $rows;}
    public static function location($owner,$value){if(!is_string($value)||!preg_match('/^(0|[1-9][0-9]{0,17})$/D',$value)||(float)$value>PHP_INT_MAX||($value!=='0'&&!Location::get_for_user((int)$value,$owner)))return self::error('Select one of your own locations.');return (int)$value;}
    public static function time($text) {
        if($text==='')return null;
        if(preg_match('/^[0-9]{1,6}(?:\.[0-9]{1,3})?$/D',$text))return (int)round((float)$text*1000);
        if(preg_match('/^0:00(?:\.0{1,3})?$/D',$text))return 0;
        return PlanFormValues::durationMs($text);
    }
    public static function document($owner,$v,$sets) {
        foreach(self::FIELDS as$key)if(!isset($v[$key])||!is_string($v[$key])||strlen($v[$key])>6000)return self::error('The manual workout contains a missing or invalid field.');
        if(!preg_match('/^(19|20|21)\d{2}-\d{2}-\d{2}$/D',$v['workout_date'])||($v['workout_time']!==''&&!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$v['workout_time'])))return self::error('Enter a valid workout date and start time.');
        $loc=self::location($owner,$v['location_id']);if(is_wp_error($loc))return $loc;
        foreach(array('pool_length','total_distance')as$key)if($v[$key]!==''&&(!is_numeric($v[$key])||!is_finite((float)$v[$key])))return self::error('Enter a valid numeric pool length and distance.');
        $duration=self::time(trim($v['duration']));if(is_wp_error($duration))return $duration;
        if(!is_array($sets)||count($sets)>100)return self::error('Use at most 100 sets per workout.');
        $doc=array('workout'=>array('workout_start'=>$v['workout_date'].' '.($v['workout_time']===''?'12:00:00':$v['workout_time'].':00'),'location_id'=>$loc,'original_pool_length'=>$v['pool_length'],'pool_length_unit'=>$v['course_unit'],'original_distance'=>$v['total_distance']===''?null:$v['total_distance'],'elapsed_time_ms'=>$duration,'notes'=>$v['notes']),'segments'=>array());
        foreach(array_values($sets)as$row) {
            if(!is_array($row)||array_diff(array_keys($row),self::SET_FIELDS))return self::error('A workout set contains an unsupported field.');
            foreach(self::SET_FIELDS as$key)if(!isset($row[$key])||!is_string($row[$key])||strlen($row[$key])>6000)return self::error('A workout set contains a missing or invalid value.');
            $content=array_diff_key($row,array_flip(array('type','stroke','timing')));if(!array_filter($content,static function($value){return trim($value)!=='';}))continue;
            if($row['type']==='active'&&(!preg_match('/^[1-9][0-9]{0,3}$/D',$row['repetitions'])||!is_numeric($row['distance'])||!is_finite((float)$row['distance'])))return self::error('Each active set needs positive whole repetitions and a numeric distance.');
            $repTimes=array();
            if($row['timing']==='measured_rep'&&trim($row['rep_times'])!=='')foreach(preg_split('/[\s,]+/',trim($row['rep_times']))as$time){$ms=self::time($time);if(is_wp_error($ms)||$ms===null||$ms<=0)return self::error('Every measured repetition needs a valid positive time.');$repTimes[]=$ms;}
            $times=array('set_time'=>null,'interval'=>null,'rest'=>null);
            foreach($times as$key=>$unused)if($key==='rest'||($key==='set_time'&&$row['timing']==='measured_set')||($key==='interval'&&$row['timing']==='interval')){$times[$key]=self::time(trim($row[$key]));if(is_wp_error($times[$key]))return $times[$key];}
            $doc['segments'][]=array('type'=>$row['type'],'repetitions'=>$row['repetitions'],'distance_per_rep'=>$row['distance'],'stroke'=>$row['stroke'],'timing_type'=>$row['timing'],'rep_times_ms'=>$repTimes,'set_time_ms'=>$times['set_time'],'interval_ms'=>$times['interval'],'rest_time_ms'=>$times['rest'],'notes'=>$row['notes']);
        }
        $valid=Manual::validate_document($doc);if(is_wp_error($valid))return $valid;
        // Bound normalization work before the service expands repetitions into lengths.
        $count=0;foreach($valid['segments']as$segment){$count+=$segment['type']==='rest'?1:$segment['repetitions']*(ceil($segment['distance_per_rep']/$valid['workout']['original_pool_length'])+1);if($count>10000)return self::error('This workout exceeds the 10,000-length entry limit. Split it into smaller sessions.');}
        return $valid;
    }
    public static function submit($owner,$mode,$input,$files,$origin,$appOrigin) {
        $ready=self::ready($owner,$mode);if(is_wp_error($ready))return $ready;
        $token=$input['token']??null;$receipt=self::receipt($owner,$token);
        if(!$receipt||$receipt['mode']!==$mode||$origin!==$appOrigin||!is_string($input['nonce']??null)||!wp_verify_nonce($input['nonce'],'standalone_entry_'.$mode.'_'.$token))return self::error('This form expired or is invalid. Reload it before trying again.',403);
        $locked=Importer::owner_lock($owner);if(is_wp_error($locked))return self::error('Another workout change or import is running. Try again shortly.',409);
        try {
            $key=self::key($owner,$token);wp_cache_delete($key,'transient');wp_cache_delete('_transient_'.$key,'options');
            $receipt=self::receipt($owner,$token);if(!$receipt)return self::error('This form expired. Reload it.',403);
            if($receipt['done'])return array('url'=>$receipt['url']);
            $allowed=$mode==='manual'?array_merge(self::FIELDS,array('token','nonce','sets','payload_complete')):array('token','nonce','location_id','import_id','choice','revision');
            if(array_diff(array_keys($input),$allowed))return self::error('This form cannot change ownership, existing workouts or unrelated fields.');
            if($mode==='manual') {
                if(($input['payload_complete']??null)!=='1')return self::error('The workout form was truncated. Reduce the number of sets and try again. Nothing was saved.');
                $form=array('token'=>$token,'values'=>array_intersect_key($input,array_flip(self::FIELDS)),'sets'=>$input['sets']??array(),'error'=>null);
                $doc=self::document($owner,$form['values'],$form['sets']);
                $out=is_wp_error($doc)?$doc:Manual::save($owner,$doc);
                if(is_wp_error($out)){$form['error']=$out;return array('form'=>$form);}
                $url='/?workout='.(int)$out['workout_id'].'&date='.$form['values']['workout_date'].'&entry_saved=manual';
                set_transient(self::key($owner,$token),array('mode'=>$mode,'done'=>true,'url'=>$url),7200);return array('url'=>$url);
            }
            $loc=self::location($owner,$input['location_id']??'0');if(is_wp_error($loc))return $loc;
            $outcomes=array();
            if(isset($input['import_id'])) {
                if(!is_string($input['import_id'])||!preg_match('/^[1-9][0-9]{0,17}$/D',$input['import_id'])||!is_string($input['choice']??null)||!in_array($input['choice'],array('attach','separate'),true))return self::error('Choose a valid pending import action.');
                $preview=self::review($owner,(int)$input['import_id']);if(is_wp_error($preview))return $preview;
                if(!is_string($input['revision']??null)||!hash_equals($preview['revision'],$input['revision']))return self::error('This import or candidate changed. Reload the review before confirming.',409);
                // Check incoming private land authorization again, not only the candidate type.
                if(\SwimLogEvaluation\Land_Activity::is_land($preview['incoming_type'])&&!\SwimLogEvaluation\Land_Activity::authorized($owner))return self::error('Your account cannot import this private activity.',403);
                $out=Importer::resolve_pending((int)$input['import_id'],$owner,$input['choice'],$loc);$outcomes[]=self::outcome($out,'Reviewed source');
            } else {
                if(array_diff(array_keys($input),array('token','nonce','location_id')))return self::error('Unsupported upload field.');
                $uploads=self::uploads($files);if(is_wp_error($uploads))return $uploads;
                $storage=self::storage();if(is_wp_error($storage))return $storage;
                // Use the WordPress environment for its existing default private-storage derivation.
                foreach($uploads as$file){$out=self::withRoot(static function()use($owner,$file,$loc){return Importer::import_upload($owner,$file,$loc);});$outcomes[]=self::outcome($out,sanitize_file_name($file['name']));}
            }
            $url='/?screen=import&receipt='.$token;
            set_transient(self::key($owner,$token),array('mode'=>$mode,'done'=>true,'url'=>$url,'outcomes'=>$outcomes),7200);return array('url'=>$url);
        } finally {Importer::owner_lock($owner,true);}
    }
    public static function uploads($files) {
        if(array_keys($files)!==array('workout_files')||!is_array($files['workout_files']))return self::error('Choose at least one workout file.');
        $u=$files['workout_files'];foreach(array('name','type','tmp_name','error','size')as$key)if(!isset($u[$key])||!is_array($u[$key]))return self::error('Invalid upload form.');
        $count=count($u['name']);if($count<1||$count>10)return self::error('Choose between 1 and 10 files per upload.');
        foreach($u as$values)if(!is_array($values)||array_keys($values)!==array_keys($u['name']))return self::error('The upload was incomplete. Reselect your files.');
        $rows=array();foreach($u['name']as$i=>$name){foreach(array('name','type','tmp_name')as$key)if(!is_string($u[$key][$i]))return self::error('Invalid upload metadata.');if(!is_int($u['error'][$i])||!is_int($u['size'][$i])||$u['size'][$i]<0)return self::error('Invalid upload size or error status.');$rows[]=array('name'=>$name,'type'=>$u['type'][$i],'tmp_name'=>$u['tmp_name'][$i],'error'=>$u['error'][$i],'size'=>$u['size'][$i]);}
        // Same source priority as WordPress: process FIT before supplemental files.
        usort($rows,static function($a,$b){$af=(bool)preg_match('/\.fit(?:\.gz)?$/i',$a['name']);$bf=(bool)preg_match('/\.fit(?:\.gz)?$/i',$b['name']);return $af===$bf?0:($af?-1:1);});return $rows;
    }
    private static function outcome($result,$name){if(is_wp_error($result))return array('name'=>$name,'error'=>$result->get_error_message());return array('name'=>$name,'pending'=>!empty($result['pending']),'workout_id'=>(int)($result['workout_id']??0),'attached'=>!empty($result['attached']));}
    private static function withRoot($callback){$old=$_SERVER['DOCUMENT_ROOT']??null;$_SERVER['DOCUMENT_ROOT']=rtrim(ABSPATH,'/\\');try{return $callback();}finally{if($old===null)unset($_SERVER['DOCUMENT_ROOT']);else $_SERVER['DOCUMENT_ROOT']=$old;}}
    public static function storage() {
        $dir=defined('SWIMLOG_PRIVATE_STORAGE_DIR')?(string)SWIMLOG_PRIVATE_STORAGE_DIR:(string)get_option('swimlog_private_storage_dir','');
        if($dir==='')$dir=dirname(realpath(ABSPATH)?:ABSPATH).'/chriss-swim-training-progress-evaluation-private';
        // Do not create or relocate storage. Reuse the current installation's existing directory.
        $app=Importer::validate_storage_directory($dir,false);if(is_wp_error($app))return self::error('Existing private workout storage is unavailable. Review Private Workout Storage Directory in WordPress settings.',503);
        $wp=self::withRoot(static function()use($dir){return Importer::validate_storage_directory($dir,false);});if(is_wp_error($wp))return self::error('Private source storage must remain outside both application web roots.',503);
        return $dir;
    }
    public static function review($owner,$id) {
        $row=Importer::pending_for_user($id,$owner);if(!$row)return self::error('Pending import not found.',404);
        if(!is_file($row->stored_path)||filesize($row->stored_path)>Importer::MAX_BYTES)return self::error('The private source is missing or cannot be reviewed. Restore it through WordPress tools.');
        $parsed=Importer::parse_source($row->source_type,$row->stored_path);if(is_wp_error($parsed))return $parsed;
        $valid=Importer::validate($parsed);if(is_wp_error($valid))return $valid;
        $w=$parsed['workout'];$candidate=Workout::get_for_user((int)$row->workout_id,$owner);if(!$candidate)return self::error('The candidate workout is unavailable.',409);
        $type=$w['workout_type']??'pool';if(\SwimLogEvaluation\Land_Activity::is_land($type)&&!\SwimLogEvaluation\Land_Activity::authorized($owner))return self::error('This private activity is unavailable.',403);
        $data=array('id'=>(int)$id,'candidate_id'=>(int)$candidate->id,'source'=>strtoupper($row->source_type),'incoming_type'=>$type,'location'=>(int)Importer::pending_location($owner,$id),'rows'=>array('Start'=>array($candidate->workout_start,$w['workout_start']),'Type'=>array($candidate->workout_type,$type),'Distance (m)'=>array($candidate->total_distance_m,$w['total_distance_m']??null),'Elapsed'=>array(Workout::format_duration($candidate->elapsed_time_ms),Workout::format_duration($w['elapsed_time_ms']??null)),'Pool length'=>array(($candidate->original_pool_length??'').' '.($candidate->pool_length_unit??''),($w['original_pool_length']??'').' '.($w['pool_length_unit']??''))));
        $data['revision']=hash_hmac('sha256',serialize(array($row,$candidate,$w,hash_file('sha256',$row->stored_path))),wp_salt('nonce'));return $data;
    }
}

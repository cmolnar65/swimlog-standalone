<?php
namespace SwimlogStandalone;
require_once __DIR__.'/Sharing.php';
use SwimLogEvaluation\Workout;
use SwimLogEvaluation\Importer;
use SwimLogEvaluation\Recorded_Workout_Structure as Conversion;
/** Authorized UI adapter; recorded data, rebuilds, conversion and PBs remain plugin-owned. */
final class WorkoutEditor {
    public static function publicPreference($owner,$id){if(Auth::cookieUser()!==$owner||!Auth::requireCapability($owner))return false;$state=Sharing::ownerState($id);return !is_wp_error($state)&&in_array($state['visibility'],array('public','legacy_public'),true);}
    public static function error($text,$status=422){return new \WP_Error('workout_editor',$text,array('status'=>$status));}
    public static function load($owner,$id){
        require_once __DIR__.'/WorkoutEntry.php';
        $ready=WorkoutEntry::ready($owner,'manual');if(is_wp_error($ready))return $ready;
        if(!is_string($id)||!preg_match('/^[1-9][0-9]{0,17}$/D',$id)||(float)$id>PHP_INT_MAX)return self::error('Choose a valid workout.',400);
        $w=Workout::get_for_user((int)$id,$owner);
        if(!$w||!in_array($w->workout_type,array('pool','open_water','running','walking'),true))return self::error('Workout not found.',404);
        if(in_array($w->workout_type,array('running','walking'),true)&&!\SwimLogEvaluation\Land_Activity::authorized($owner))return self::error('Workout not found.',404);
        $photos=Workout::photos((int)$id,$owner);$visibility=Sharing::ownerState($id);
        $revision=hash_hmac('sha256',serialize(array($w,$photos,is_wp_error($visibility)?'sharing_unavailable':$visibility,Workout::imports((int)$id,$owner))),wp_salt('nonce'));
        return array('workout'=>$w,'photos'=>$photos,'visibility'=>$visibility,'revision'=>$revision);
    }
    public static function preview($id,$owner){require_once __DIR__.'/StructuredWorkouts.php';$ready=StructuredWorkouts::ready($owner);if(is_wp_error($ready))return $ready;require_once SWIMLOG_EVALUATION_DIR.'includes/class-recorded-workout-structure.php';return Conversion::preview((int)$id,$owner);}
    public static function posts($id){return get_posts(array('post_type'=>'post','post_status'=>array('draft','pending','future','publish','private'),'posts_per_page'=>2,'meta_key'=>'_swimlog_workout_id','meta_value'=>(string)$id,'suppress_filters'=>false));}
    public static function submit($owner,$input,$files,$origin,$appOrigin){
        $id=$input['workout_id']??null;$item=self::load($owner,$id);if(is_wp_error($item))return $item;
        $op=$input['operation']??null;if(!is_string($op)||!in_array($op,array('metadata','post','rebuild','delete','convert','visibility','adopt'),true))return self::error('Choose a valid workout action.');
        if($origin!==$appOrigin||!is_string($input['nonce']??null)||!wp_verify_nonce($input['nonce'],'standalone_workout_'.$op.'_'.$id))return self::error('This workout request expired or is invalid. Reload the workout.',403);
        $extra=array('metadata'=>array('location_id','notes','delete_photo'),'convert'=>array('source_hash','title','planned_date','confirm'),'rebuild'=>array('confirm'),'delete'=>array('confirm'),'post'=>array(),'visibility'=>array('visibility_nonce','visibility_revision','make_public','share_route'),'adopt'=>array('visibility_nonce','visibility_revision'))[$op];
        if(array_diff(array_keys($input),array_merge(array('workout_id','operation','nonce','revision'),$extra)))return self::error('This form cannot change ownership, recorded metrics or unrelated fields.');
        foreach($input as$key=>$value){if($key==='delete_photo'){if(!is_array($value)||count($value)>3||count(array_filter($value,static function($slot){return is_string($slot)&&in_array($slot,array('0','1','2'),true);}))!==count($value))return self::error('Choose valid photo slots.');}elseif(!is_string($value)||strlen($value)>12000)return self::error('Invalid workout field.');}
        if($op!=='metadata'&&$files)return self::error('Files are accepted only by the workout editor.');
        $lock=Importer::owner_lock($owner);if(is_wp_error($lock))return self::error('Another workout operation is running. Retry shortly.',409);
        try{
            $item=self::load($owner,$id);if(is_wp_error($item))return $item;
            if(!is_string($input['revision']??null)||!hash_equals($item['revision'],$input['revision']))return self::error('This workout changed. Reload it before saving.',409);
            $w=$item['workout'];$url='/?edit_workout='.$id;
            if(in_array($op,array('visibility','adopt'),true)){
                $result=Sharing::command($id,$op,$input);
                if(is_wp_error($result))return $result;
                // PRG reloads the current owner state; never enable copying from submitted fields.
                return array('url'=>$url.'&workout_saved=visibility');
            }
            if(!in_array($w->workout_type,array('pool','open_water'),true))return self::error('Only sharing controls are available here for land activities.',403);

            if($op==='metadata'){$result=self::metadata($owner,$item,$input,$files);$url.='&workout_saved=metadata';}
            elseif($op==='post'){
                if(!current_user_can('edit_posts'))return self::error('Your account cannot create posts.',403);
                $posts=self::posts((int)$id);$post=$posts?$posts[0]:null;
                if($post&&(!current_user_can('edit_post',$post->ID)||(int)$post->post_author!==$owner))return self::error('The existing linked post cannot be edited by this account.',403);
                if(!$post){
                    $title='Swim — '.mysql2date('F j, Y',$w->workout_start,false).' — '.($w->location_name?:($w->workout_type==='open_water'?'Open Water Swim':'Pool Swim'));
                    $postId=wp_insert_post(array('post_type'=>'post','post_status'=>'draft','post_author'=>$owner,'post_title'=>$title,'post_content'=>'<!-- wp:swimlog/workout {"workoutId":'.(int)$id.'} /-->'),true);
                    if(is_wp_error($postId))return $postId;
                    if(!update_post_meta($postId,'_swimlog_workout_id',(int)$id)){wp_delete_post($postId,true);return self::error('The draft link could not be saved. Retry.',503);}
                }
                $result=true;$url.='&workout_saved=post';
            }elseif($op==='convert'){
                if(($input['confirm']??'')!=='1')return self::error('Confirm creation of the private prescription.');
                $receipt='standalone_conversion_'.hash('sha256',$owner.'|'.$id.'|'.$item['revision']);
                $done=get_transient($receipt);if(is_array($done)&&isset($done['url']))return $done;
                $preview=self::preview($id,$owner);if(is_wp_error($preview))return $preview;
                if(!isset($input['source_hash'])||!hash_equals($preview['hash'],$input['source_hash']))return self::error('The recorded swim changed. Review the conversion again.',409);
                $result=Conversion::confirm((int)$id,$owner,$input['source_hash'],$input['title']??'',$input['planned_date']??'');
                if(!is_wp_error($result)){$out=array('url'=>'/?screen=structures&structure='.(int)$result['structure_id']);set_transient($receipt,$out,7200);return $out;}
            }else{
                if(($input['confirm']??'')!=='1')return self::error('Confirm this workout operation.');
                if($op==='rebuild'){$result=WorkoutEntry::withRoot(static function()use($id,$owner){return Importer::rebuild_workout((int)$id,$owner);});$url.='&workout_saved=rebuild';}
                else{$result=Workout::delete((int)$id,$owner);$url='/?screen=log&workout_deleted=1';}
            }
            if(is_wp_error($result))return $result;if($result===false)return self::error('The workout operation could not be saved.',503);
            return array('url'=>$url);
        }finally{Importer::owner_lock($owner,true);}
    }
    private static function metadata($owner,$item,$input,$files){
        $loc=WorkoutEntry::location($owner,$input['location_id']??'0');if(is_wp_error($loc))return $loc;
        if(!isset($input['notes']))return self::error('Workout notes are missing.');
        $allowed=array('workout_photo_0','workout_photo_1','workout_photo_2');if(array_diff(array_keys($files),$allowed))return self::error('Choose at most three workout photos.');
        $old=$item['photos'];foreach($old as$id)if((int)get_post_field('post_author',$id)!==$owner)return self::error('A photo ownership conflict needs review in WordPress.',409);
        $mimes=array('jpg|jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp');$uploads=array();
        foreach($files as$field=>$file){
            if(!is_array($file)||!isset($file['error'])||!is_int($file['error']))return self::error('Invalid photo upload.');
            if($file['error']===UPLOAD_ERR_NO_FILE)continue;
            if($file['error']!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name'])||!is_string($file['name']??null))return self::error('A photo upload failed. Reselect your photos.');
            $check=wp_check_filetype_and_ext($file['tmp_name'],sanitize_file_name($file['name']),$mimes);
            if(empty($check['type'])||!in_array($check['type'],array_values($mimes),true))return self::error('Workout photos must be JPEG, PNG, GIF, or WebP images.');
            $uploads[$field]=$file;
        }
        require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/media.php';require_once ABSPATH.'wp-admin/includes/image.php';
        $next=$old;$created=array();$committed=false;$savedFiles=$_FILES;
        try{
            // WordPress media API requires genuine PHP upload metadata in this scoped global.
            $_FILES=$uploads;
            foreach($uploads as$field=>$file){
                $new=media_handle_upload($field,0,array('post_title'=>'Workout '.(int)$item['workout']->id.' photo','post_author'=>$owner),array('test_form'=>false,'mimes'=>$mimes));
                if(is_wp_error($new))return $new;$created[]=$new;$next[(int)substr($field,-1)]=$new;
            }
            foreach($input['delete_photo']??array()as$slot)if(!isset($uploads['workout_photo_'.$slot]))unset($next[(int)$slot]);
            ksort($next);$next=array_values($next);$w=$item['workout'];
            $result=Workout::update_metadata($w->id,$owner,$loc,$input['notes']);if(is_wp_error($result))return $result;
            $result=Workout::set_photos($w->id,$owner,$next);
            if(is_wp_error($result)){Workout::update_metadata($w->id,$owner,(int)$w->location_id,$w->notes??'');return $result;}
            $committed=true;
            foreach(array_diff($old,$next)as$id)wp_delete_attachment($id,true);
            return true;
        }finally{$_FILES=$savedFiles;if(!$committed)foreach($created as$id)wp_delete_attachment($id,true);}
    }
}

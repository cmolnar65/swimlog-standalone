<?php
namespace SwimlogStandalone;
use SwimLogEvaluation\Garmin_Personal_API as API;
use SwimLogEvaluation\Location;
use SwimLogEvaluation\Garmin_Measurement_Sync as Sync;
use SwimLogEvaluation\Plugin;
use SwimLogEvaluation\Importer;
final class Settings {
    const PREFS=array('birthdate','calendar_first_day','training_volume_months','public_results','public_ows_routes','rest_api_access');
    const LOCATION=array('name','pool_length','pool_unit','latitude','longitude','notes');
    public static function error($text,$status=422){return new \WP_Error('standalone_settings',$text,array('status'=>$status));}
    public static function ready($owner){
        if(!Auth::requireCapability($owner)||Auth::cookieUser()!==$owner)return self::error('Settings access denied.',403);
        if(!defined('SWIMLOG_EVALUATION_DIR')||!defined('SWIMLOG_EVALUATION_DB_VERSION')||version_compare((string)get_option('swimlog_db_version','0'),SWIMLOG_EVALUATION_DB_VERSION,'<'))return self::error('The Swim Log installation must be ready before opening settings.',503);
        foreach(array('location','workout','land-activity','garmin-personal-api','garmin-measurement-sync','importer','plugin')as$file)require_once SWIMLOG_EVALUATION_DIR.'includes/class-'.$file.'.php';
        return true;
    }
    public static function verified($owner){return !is_wp_error(self::ready($owner))&&API::connection_ready($owner);}
    public static function preferences($owner){$v=array();foreach(self::PREFS as$key)$v[$key]=(string)get_user_meta($owner,'swimlog_'.$key,true);if($v['public_results']==='')$v['public_results']=(string)get_option('swimlog_public_results_default','0');if(!in_array($v['calendar_first_day'],array('saturday','sunday','monday'),true))$v['calendar_first_day']='sunday';if((int)$v['training_volume_months']<1||(int)$v['training_volume_months']>24)$v['training_volume_months']='3';return $v;}
    public static function activities($owner){$choices=array('lap_swimming'=>'Pool swims','open_water_swimming'=>'Open-water swims');if(\SwimLogEvaluation\Land_Activity::authorized($owner)&&\SwimLogEvaluation\Land_Activity::enabled())$choices+=array('running'=>'Running','walking'=>'Walking');return $choices;}
    public static function measurements(){return array('weight'=>'Weight','body_composition'=>'Body composition (BMI only)','spo2'=>'SpO2 provider summaries');}
    public static function location($owner,$id){if($id==='new')return null;if(!is_string($id)||!preg_match('/^[1-9][0-9]{0,17}$/D',$id)||(float)$id>PHP_INT_MAX)return self::error('Choose a valid location.');$row=Location::get_for_user((int)$id,$owner);return $row?:self::error('Location not found.',404);}
    public static function revision($owner,$screen,$id='new'){
        if($screen==='swimmer')$value=self::preferences($owner);
        elseif($screen==='locations'){$value=self::location($owner,(string)$id);if(is_wp_error($value))return $value;}
        else $value=array(get_user_meta($owner,API::META_KEY,true),get_user_meta($owner,API::TESTED_KEY,true),get_user_meta($owner,'swimlog_garmin_sync_enabled',true),API::scheduled_types($owner),Sync::categories($owner));
        return hash_hmac('sha256',serialize(array($screen,$id,$value)),wp_salt('nonce'));
    }
    public static function submit($owner,$input,$origin,$appOrigin){
        $ready=self::ready($owner);if(is_wp_error($ready))return $ready;
        $op=$input['operation']??null;$ops=array('swimmer_save'=>'swimmer','location_save'=>'locations','location_delete'=>'locations','key_save'=>'garmin_setup','key_test'=>'garmin_setup','key_remove'=>'garmin_setup','activity_sync'=>'garmin_setup','measurement_sync'=>'garmin_setup');
        if(!is_string($op)||!isset($ops[$op]))return self::error('Unknown settings operation.');$screen=$ops[$op];$id=$screen==='locations'?($input['location_id']??'new'):'new';
        if(!is_string($id)||($id!=='new'&&!preg_match('/^[1-9][0-9]{0,17}$/D',$id)))return self::error('Choose a valid location.');
        if($origin!==$appOrigin||!is_string($input['nonce']??null)||!wp_verify_nonce($input['nonce'],'standalone_settings_'.$op.'_'.$id))return self::error('This settings request expired. Reload the screen.',403);
        if($screen==='locations'&&!current_user_can('swimlog_manage_own_locations'))return self::error('Your account cannot manage locations.',403);
        if(in_array($op,array('activity_sync','measurement_sync'),true)&&(!self::verified($owner)||($op==='measurement_sync'&&!current_user_can('swimlog_manage_own_workouts'))))return self::error('Verify your Garmin connection and workout permissions before configuring synchronization.',403);
        $extra=array('swimmer_save'=>self::PREFS,'location_save'=>array_merge(self::LOCATION,array('location_id')),'location_delete'=>array('location_id','confirm_delete'),'key_save'=>array('api_key'),'key_remove'=>array('confirm_remove'),'key_test'=>array(),'activity_sync'=>array('enabled','types'),'measurement_sync'=>array('categories'))[$op];
        if(array_diff(array_keys($input),array_merge(array('operation','nonce','revision'),$extra)))return self::error('This form cannot change ownership or unrelated settings.');
        foreach($input as$key=>$value){if(in_array($key,array('types','categories'),true)){if(!is_array($value)||count($value)>4)return self::error('Choose supported synchronization categories.');foreach($value as$v)if(!is_string($v))return self::error('Invalid synchronization selection.');}elseif(!is_string($value)||strlen($value)>12000)return self::error('Invalid settings field.');}
        $lock=Importer::owner_lock($owner);if(is_wp_error($lock))return self::error('Another owner operation is running. Retry shortly.',409);
        try {
            $revision=self::revision($owner,$screen,$id);if(is_wp_error($revision))return $revision;
            if(!is_string($input['revision']??null)||!hash_equals($revision,$input['revision']))return self::error('These settings changed. Reload before saving.',409);
            $result=true;
            if($op==='swimmer_save') {
                foreach(array('birthdate','calendar_first_day','training_volume_months')as$key)if(!isset($input[$key]))return self::error('A swimmer configuration field is missing.');
                foreach(array('public_results','public_ows_routes','rest_api_access')as$key)if(isset($input[$key])&&$input[$key]!=='1')return self::error('Choose valid privacy settings.');
                require_once SWIMLOG_EVALUATION_DIR.'admin/class-admin-ui.php';require_once SWIMLOG_EVALUATION_DIR.'admin/class-admin-settings.php';require_once ABSPATH.'wp-admin/includes/template.php';
                // The plugin owns preference validation and persistence; suppress its admin presentation.
                $post=$_POST;$request=$_REQUEST;$level=ob_get_level();
                try {$_POST=wp_slash(array_intersect_key($input,array_flip(self::PREFS))+array('swimlog_privacy_action'=>'save','_wpnonce'=>wp_create_nonce('swimlog_save_privacy')));$_REQUEST=$_POST;ob_start();\SwimLogEvaluation\Admin_Settings::privacy();$markup=ob_get_clean();}
                finally {while(ob_get_level()>$level)ob_end_clean();$_POST=$post;$_REQUEST=$request;}
                if(strpos($markup,'notice notice-error')!==false)return self::error('Enter a valid birth date that is not in the future. Your settings were not saved.');
                $expected=array('birthdate'=>$input['birthdate'],'calendar_first_day'=>in_array($input['calendar_first_day'],array('saturday','sunday','monday'),true)?$input['calendar_first_day']:'sunday','training_volume_months'=>(string)max(1,min(24,absint($input['training_volume_months']))));
                foreach(array('public_results','public_ows_routes','rest_api_access')as$key)$expected[$key]=isset($input[$key])?'1':'0';
                if(self::preferences($owner)!=$expected)return self::error('The configuration could not be confirmed. Reload and check your settings.',503);
            } elseif($op==='location_save') {
                foreach(self::LOCATION as$key)if(!isset($input[$key]))return self::error('A location field is missing.');
                foreach(array('pool_length','latitude','longitude')as$key)if($input[$key]!==''&&(!is_numeric($input[$key])||!is_finite((float)$input[$key])))return self::error('Enter valid numeric location measurements.');
                $result=Location::save($owner,array_intersect_key($input,array_flip(self::LOCATION)),$id==='new'?0:(int)$id);if(!is_wp_error($result))return array('url'=>'/?screen=locations&edit_location='.(int)$result.'&settings_saved=1');
            } elseif($op==='location_delete') {if($id==='new'||($input['confirm_delete']??'')!=='1')return self::error('Confirm deletion of this location.');$result=Location::delete((int)$id,$owner);}
            elseif($op==='key_save'){$key=trim($input['api_key']??'');if($key==='')return self::error('Enter a Garmin Personal API key.');$result=API::save_key($owner,$key);}
            elseif($op==='key_test')$result=API::test_connection($owner);
            elseif($op==='key_remove'){if(($input['confirm_remove']??'')!=='1')return self::error('Confirm removing the saved API key.');API::remove_key($owner);}
            elseif($op==='activity_sync') {
                $types=array_values(array_unique($input['types']??array()));$choices=self::activities($owner);if(array_diff($types,array_keys($choices)))return self::error('Choose authorized activity types.');
                $enabled=($input['enabled']??'')==='1';if(isset($input['enabled'])&&$input['enabled']!=='1')return self::error('Invalid sync setting.');if($enabled&&!$types)return self::error('Select at least one activity to synchronize.');
                // Owner-scoped configuration metadata; scheduling remains plugin-owned.
                update_user_meta($owner,'swimlog_garmin_sync_types',$types);Plugin::set_garmin_sync_schedule($owner,$enabled);
                if($enabled&&!wp_next_scheduled('swimlog_garmin_scheduled_sync',array($owner)))return self::error('The schedule could not be confirmed. Save again to repair it.',503);
            } elseif($op==='measurement_sync'){$cats=array_values(array_unique($input['categories']??array()));if(array_diff($cats,array_keys(self::measurements())))return self::error('Choose supported measurement categories.');$result=Sync::configure($cats);}
            if(is_wp_error($result))return $result;if($result===false)return self::error('The settings could not be saved.');
            return array('url'=>'/?screen='.$screen.'&settings_saved=1');
        } finally {Importer::owner_lock($owner,true);}
    }
}

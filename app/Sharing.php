<?php
namespace SwimlogStandalone;
use SwimLogEvaluation\Workout_Visibility as V;
/** No visibility storage or raw public lookups: the installed plugin owns both. */
final class Sharing {
    public static function unavailable(){return new \WP_Error('sharing_upgrade','Public sharing requires the updated Swim Log plugin and its completed visibility migration.',array('status'=>503));}
    public static function ready(){
        if(!defined('SWIMLOG_EVALUATION_DIR'))return false;
        if(!class_exists(V::class,false)){
            $file=SWIMLOG_EVALUATION_DIR.'includes/class-workout-visibility.php';
            if(!is_readable($file))return false;
            require_once $file;
        }
        foreach(array('ready','get_for_owner','set_for_owner','adopt_standalone_preference','get_public','query_public','is_public','personal_bests','progression')as$method)if(!is_callable(array(V::class,$method)))return false;
        return version_compare((string)get_option('swimlog_db_version','0'),'1.5','>=')&&V::ready();
    }
    public static function ownerState($id){return self::ready()?V::get_for_owner((int)$id):self::unavailable();}
    public static function integer($value){
        if(!is_string($value)||!preg_match('/^(0|[1-9][0-9]*)$/D',$value))return null;
        $max=(string)PHP_INT_MAX;
        if(strlen($value)>strlen($max)||(strlen($value)===strlen($max)&&strcmp($value,$max)>0))return null;
        return (int)$value;
    }
    public static function id($value){$id=self::integer($value);return $id!==null&&$id>0?$id:null;}
    public static function url($origin,$id){return $origin.'/?public_workout='.(int)$id;}
    public static function command($id,$op,$input){
        if(!self::ready())return self::unavailable();
        $revision=self::integer($input['visibility_revision']??null);
        if($revision===null)return new \WP_Error('sharing_revision','Reload the workout: its canonical revision is missing or invalid.',array('status'=>400));
        $nonce=$input['visibility_nonce']??null;
        if(!is_string($nonce))return new \WP_Error('sharing_nonce','Reload the workout before changing sharing.',array('status'=>403));
        if($op==='adopt')return V::adopt_standalone_preference((int)$id,$nonce,$revision);
        foreach(array('make_public','share_route')as$key)if(isset($input[$key])&&$input[$key]!=='1')return new \WP_Error('sharing_checkbox','Choose valid sharing options.',array('status'=>400));
        $public=isset($input['make_public']);$route=$public&&isset($input['share_route']);
        return V::set_for_owner((int)$id,array('visibility'=>$public?'public':'private','route_public'=>$route),$nonce,$revision);
    }
    public static function publicWorkout($id){
        // The same projection and 404 apply to all viewers, including owners/admins.
        if(self::id($id)===null||!self::ready())return self::notFound();
        $data=V::get_public((int)$id);
        return is_wp_error($data)?self::notFound():$data;
    }
    public static function notFound(){return new \WP_Error('public_not_found','Workout not found.',array('status'=>404));}
}

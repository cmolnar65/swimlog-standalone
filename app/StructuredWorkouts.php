<?php
namespace SwimlogStandalone;
use SwimLogEvaluation\Structured_Workout_Store as Store;
use SwimLogEvaluation\Structured_Workout as Model;
use SwimLogEvaluation\Structured_Workout_Export as Export;
use SwimLogEvaluation\Structured_Workout_FIT as Fit;
use SwimLogEvaluation\Planned_Workout as Plans;
use SwimLogEvaluation\Planned_Structure_Link as Links;
use SwimLogEvaluation\Database;

/** UI adapter only: private storage, validation, history and calculations belong to the plugin. */
final class StructuredWorkouts {
    public static function error($text,$status=400) {return new \WP_Error('standalone_structure',$text,array('status'=>$status));}
    public static function ready($owner) {
        if(!Auth::requireCapability($owner)||Auth::cookieUser()!==$owner||!current_user_can('swimlog_manage_own_workouts'))return self::error('Your account cannot manage structured workouts.',403);
        if(!defined('SWIMLOG_EVALUATION_DIR')||!defined('SWIMLOG_EVALUATION_DB_VERSION')||version_compare((string)get_option('swimlog_db_version','0'),SWIMLOG_EVALUATION_DB_VERSION,'<'))return self::error('The Swim Log installation must be updated before opening structured workouts.',503);
        foreach(array('structured-workout','structured-workout-store','structured-workout-export','structured-workout-fit','planned-workout','planned-structure-link','training-plan-collection')as$file)require_once SWIMLOG_EVALUATION_DIR.'includes/class-'.$file.'.php';
        return true;
    }
    public static function id($id,$new=false) {return ($new&&$id==='new')||(is_scalar($id)&&preg_match('/^[1-9][0-9]{0,17}$/D',(string)$id)&&(float)$id<=PHP_INT_MAX);}
    public static function url($id=null,$edit=false) {return '/?screen=structures'.($id!==null?'&'.($edit?'edit_structure':'structure').'='.rawurlencode((string)$id):'');}
    public static function item($owner,$id) {
        $ready=self::ready($owner);if(is_wp_error($ready))return $ready;
        if(!self::id($id))return self::error('Choose a valid structured workout.');
        $item=Store::get((int)$id,$owner);if(is_wp_error($item))return self::error('Structured workout not found.',404);
        $item['hidden']=Store::is_hidden($id,$owner);$item['locked']=Store::is_locked($id,$owner);$item['history']=Store::has_history($id,$owner);
        foreach(array('hidden','locked','history')as$key)if(is_wp_error($item[$key]))return self::error('The prescription history needs review in WordPress tools.',409);
        $planId=(int)get_post_meta((int)$id,Links::META_KEY,true);$plan=$planId?Plans::get_for_user($planId,$owner):null;
        // Do not serialize another owner's ID, title or notes through corrupt legacy metadata.
        $item['plan']=$plan?array('id'=>(int)$plan->id,'title'=>$plan->title,'date'=>$plan->planned_date):null;
        $item['unavailable_link']=$planId&&!$plan;
        $item['revision']=hash_hmac('sha256',serialize(array($item,$planId,$plan)),wp_salt('nonce'));
        return $item;
    }
    public static function library($owner,$query) {
        $ready=self::ready($owner);if(is_wp_error($ready))return $ready;
        $filter=$query['visibility']??'active';$page=$query['structure_page']??'1';
        if(!is_string($filter)||!in_array($filter,array('active','hidden','all'),true)||!is_string($page)||!preg_match('/^[1-9][0-9]{0,5}$/D',$page))return self::error('Choose a valid structured workout filter and page.');
        $all=Store::all($owner,$filter);$pages=max(1,(int)ceil(count($all)/20));$page=min((int)$page,$pages);$rows=array();
        foreach(array_slice($all,($page-1)*20,20)as$post){$summary=Model::summarize(json_decode($post->post_content,true));$rows[]=array('id'=>(int)$post->ID,'title'=>$post->post_title,'distance'=>is_wp_error($summary)?'Invalid definition':number_format_i18n($summary['totals']['distance'],3).' '.$summary['unit']);}
        return array('rows'=>$rows,'filter'=>$filter,'page'=>$page,'pages'=>$pages,'count'=>count($all));
    }
    public static function editor($owner,$id) {
        $ready=self::ready($owner);if(is_wp_error($ready))return $ready;
        if(!self::id($id,true))return self::error('Choose a valid structured workout.');
        $item=$id==='new'?null:self::item($owner,$id);if(is_wp_error($item))return $item;
        if($item&&$item['locked'])return self::error('Recorded-swim prescriptions are locked. Duplicate the workout to edit a new copy.',409);
        return array('id'=>$id,'title'=>$item?$item['title']:'','document'=>$item?$item['document']:array('version'=>1,'unit'=>'m','sections'=>array(array('type'=>'section','label'=>'Main Set','children'=>array(array('type'=>'step','distance'=>100,'stroke'=>'FR'))))),'revision'=>$item?$item['revision']:hash_hmac('sha256','new|'.$owner,wp_salt('nonce')),'error'=>null);
    }
    public static function targets($owner) {
        $rows=array();
        foreach(Plans::all_for_user($owner)as$plan) {
            if(!empty($plan->completed_workout_id)||$plan->status!=='planned'||!in_array($plan->workout_type,array('pool','open_water'),true))continue;
            $existing=Links::for_plan($plan->id,$owner);if(is_wp_error($existing)||$existing)continue;
            $rows[]=array('id'=>(int)$plan->id,'label'=>$plan->planned_date.' · '.$plan->title,'revision'=>hash_hmac('sha256',serialize($plan),wp_salt('nonce')));
        }
        return $rows;
    }
    public static function submit($owner,$input,$origin,$appOrigin) {
        $ready=self::ready($owner);if(is_wp_error($ready))return $ready;
        $id=$input['structure_id']??null;$op=$input['operation']??null;
        if(!self::id($id,true)||!is_string($op)||!in_array($op,array('save','duplicate','hide','unhide','delete','link','unlink'),true)||($id==='new'&&$op!=='save'))return self::error('Choose a valid structured workout operation.');
        if($origin!==$appOrigin||!is_string($input['nonce']??null)||!wp_verify_nonce($input['nonce'],'standalone_structure_'.$op.'_'.$id))return self::error('This request expired. Reload the workout before trying again.',403);
        $fields=array_merge(array('structure_id','operation','nonce','revision'),$op==='save'?array('title','document'):($op==='link'?array('plan_token'):($op==='delete'?array('confirm_delete'):array())));
        if(array_diff(array_keys($input),$fields))return self::error('This operation cannot change ownership, history or unrelated fields.',422);
        foreach($input as$value)if(!is_string($value)||strlen($value)>200000)return self::error('The workout form contains an invalid or oversized value.',422);
        global $wpdb;$lock='standalone_structure_'.substr(hash('sha256',DB_NAME.'|'.$wpdb->prefix.'|'.$owner),0,42);
        if((string)Database::read('get_var',$wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock))!=='1')return self::error('Another structured workout change is being saved. Try again.',409);
        try {
            $item=$id==='new'?null:self::item($owner,$id);if(is_wp_error($item))return $item;
            $revision=$item?$item['revision']:hash_hmac('sha256','new|'.$owner,wp_salt('nonce'));
            if(!isset($input['revision'])||!hash_equals($revision,$input['revision']))return self::error('This workout or its history changed. Reload it before saving.',409);
            if($op==='save') {
                $form=self::editor($owner,$id);if(is_wp_error($form))return $form;
                $form['title']=$input['title']??'';$doc=json_decode($input['document']??'',true);$form['document']=$doc;$form['raw_document']=$input['document']??'';
                $result=Store::save($owner,$form['title'],$doc,$id==='new'?0:(int)$id);
                if(is_wp_error($result)){$form['error']=$result;return array('form'=>$form);}
            } elseif($op==='duplicate') {
                // Save a new private document through the store, intentionally excluding all source metadata.
                $result=Store::save($owner,wp_check_invalid_utf8(substr($item['title'],0,180),true).' (copy)',$item['document']);
            } elseif(in_array($op,array('hide','unhide','delete'),true)) {
                if($op==='delete'&&($input['confirm_delete']??'')!=='1')return self::error('Confirm permanent deletion of this unused workout.',422);
                $result=Store::lifecycle((int)$id,$owner,$op);
                if(!is_wp_error($result)&&!$result&&$op==='hide'&&$item['hidden'])$result=true;
                if(!is_wp_error($result)&&!$result)return self::error('The workout could not be updated.',409);
                if(!is_wp_error($result))return array('url'=>self::url().($op==='hide'?'&visibility=hidden':'').'&structure_saved='.$op);
            } else {
                if($item['locked']||$item['unavailable_link'])return self::error('Recorded or unavailable prescription links cannot be changed here.',409);
                if($op==='unlink') {
                    if(!$item['plan'])return self::error('This workout is not linked to a plan.',409);
                    $result=Links::detach($item['plan']['id'],$owner);
                } else {
                    if($item['plan'])return self::error('Unlink the current plan first, or duplicate the prescription.',409);
                    $selected=null;foreach(self::targets($owner)as$target)if(hash_equals($target['id'].':'.$target['revision'],$input['plan_token']??''))$selected=$target;
                    if(!$selected)return self::error('The selected plan is unavailable or changed. Reload before linking.',409);
                    $result=Links::attach($selected['id'],(int)$id,$owner);
                }
                if(!is_wp_error($result))return array('url'=>self::url($id).'&structure_saved='.$op);
            }
            if(is_wp_error($result))return $result;
            return array('url'=>self::url((int)$result).'&structure_saved='.$op);
        } finally {Database::read('get_var',$wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    public static function download($owner,$id,$format,$nonce) {
        $item=self::item($owner,$id);if(is_wp_error($item))return $item;
        if(!is_string($format)||!in_array($format,array('txt','fit','pdf'),true)||!is_string($nonce)||!wp_verify_nonce($nonce,'standalone_structure_export_'.$id.'_'.$format))return self::error('The download request expired or is invalid.',403);
        $bytes=$format==='fit'?Fit::encode($item['title'],$item['document']):($format==='pdf'?Export::pdf($item['title'],$item['document']):Export::text($item['title'],$item['document']));
        if(is_wp_error($bytes))return $bytes;
        return array('bytes'=>$bytes,'type'=>array('txt'=>'text/plain; charset=UTF-8','fit'=>'application/octet-stream','pdf'=>'application/pdf')[$format],'filename'=>'swim-workout-'.(int)$id.'.'.$format);
    }
}

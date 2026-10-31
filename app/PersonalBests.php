<?php
namespace SwimlogStandalone;
use SwimLogEvaluation\Records;
use SwimLogEvaluation\Age_Group;
use SwimLogEvaluation\Evaluator;
/** Private owner-only swim records. Calculations remain plugin-owned. */
final class PersonalBests {
    public static function load($owner,array $query) {
        if(!$owner||Auth::cookieUser()!==$owner||get_current_user_id()!==$owner||!Auth::requireCapability($owner)||!current_user_can('swimlog_view_own_results'))return new \WP_Error('pb_access','Personal Bests access denied.',array('status'=>403));
        if(!defined('SWIMLOG_EVALUATION_DIR')||!defined('SWIMLOG_EVALUATION_DB_VERSION')||version_compare((string)get_option('swimlog_db_version','0'),SWIMLOG_EVALUATION_DB_VERSION,'<'))return new \WP_Error('pb_ready','The installed Swim Log plugin must be ready.',array('status'=>503));
        foreach(array('database','evaluator','records','workout','age-group')as$file)require_once SWIMLOG_EVALUATION_DIR.'includes/class-'.$file.'.php';
        foreach(array('course','period','age_group','history_distance','history_stroke')as$key)if(isset($query[$key])&&!is_string($query[$key]))return new \WP_Error('pb_filter','Choose valid Personal Bests filters.',array('status'=>400));
        $course=$query['course']??get_option('swimlog_default_course_unit','m');
        $group=Age_Group::for_user($owner);$current=$group?$group['label']:'';
        $period=$query['period']??($group?'current':'lifetime');
        if(!in_array($course,array('m','yd'),true)||!in_array($period,array('current','lifetime','historical'),true))return new \WP_Error('pb_filter','Choose a valid course and record period.',array('status'=>400));
        $groups=Records::age_groups_with_performances($owner,$course);
        $historical=array_values(array_filter($groups,static function($label)use($current){return $label!==$current;}));
        $selected=$query['age_group']??($historical[0]??'');
        if($period==='current'&&!$group)$period='lifetime';
        if($period==='historical'&&!in_array($selected,$historical,true))return new \WP_Error('pb_group','Choose an available historical age group.',array('status'=>400));
        $distance=$query['history_distance']??'0';$stroke=$query['history_stroke']??'';
        $strokes=array('OVERALL'=>'Overall','FR'=>'Freestyle','BR'=>'Breaststroke','BACK'=>'Backstroke','FLY'=>'Butterfly','MIXED'=>'Mixed');
        if(!in_array($distance,array_map('strval',array_merge(array(0),Evaluator::DISTANCES)),true)||($stroke!==''&&(!isset($strokes[$stroke])||$stroke==='OVERALL')))return new \WP_Error('pb_history','Choose a valid progression distance and stroke.',array('status'=>400));
        $lifetime=Records::personal_bests($owner,$course);
        $pbs=$period==='lifetime'?$lifetime:Records::age_group_personal_bests($owner,$course,$period==='current'?$current:$selected);
        $progression=array();$progressionUnavailable=false;
        if($distance!=='0'&&$stroke!=='') {
            try {$progression=Records::progression($owner,(int)$distance,$course,$stroke);}
            catch(\Throwable $error) {$progressionUnavailable=true;}
        }
        return compact('course','period','current','historical','selected','distance','stroke','strokes','lifetime','pbs','progression','progressionUnavailable');
    }
    public static function url(array $state,array $changes=array()) {
        return '/?'.http_build_query(array_replace(array('screen'=>'bests','course'=>$state['course'],'period'=>$state['period'],'age_group'=>$state['selected']),$changes));
    }
}

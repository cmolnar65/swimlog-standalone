<?php
namespace SwimlogStandalone;

/** Owner-scoped projection of the plugin's event list; no private notes in list markup. */
final class EventList {
    public static function load($owner,$query) {
        if(!Auth::requireCapability($owner)||Auth::cookieUser()!==$owner) return new \WP_Error('events_access','Event access denied.');
        if(!defined('SWIMLOG_EVALUATION_DIR')||!defined('SWIMLOG_EVALUATION_DB_VERSION')||version_compare((string)get_option('swimlog_db_version','0'),SWIMLOG_EVALUATION_DB_VERSION,'<')) return new \WP_Error('events_plugin','Activate the Swim Log plugin to view events.');
        require_once SWIMLOG_EVALUATION_DIR.'includes/class-event.php';
        require_once __DIR__.'/EventWorkflow.php';
        $view=$query['event_view']??'upcoming';
        if(!is_string($view)||!in_array($view,array('upcoming','past','all'),true)) return new \WP_Error('events_filter','Choose Upcoming, Past or All events.');
        $page=$query['event_page']??'1';
        if(!is_string($page)||!preg_match('/^[1-9][0-9]{0,5}$/D',$page)) return new \WP_Error('events_page','Choose a valid event page.');
        $rows=\SwimLogEvaluation\Event::all_for_user($owner,$view);
        if(is_wp_error($rows)) return $rows;
        $pages=max(1,(int)ceil(count($rows)/20));$page=min((int)$page,$pages);$items=array();
        foreach(array_slice($rows,($page-1)*20,20)as$row) {
            $detail=EventWorkflow::detail($owner,$row->id);
            if(is_wp_error($detail)) return $detail;
            $items[]=array('id'=>$detail['id'],'title'=>$detail['title'],'summary'=>$detail['summary'],'result'=>$detail['result'],'manage'=>$detail['manage']);
        }
        return array('items'=>$items,'view'=>$view,'page'=>$page,'pages'=>$pages,'total'=>count($rows));
    }
    public static function url($view,$page=1) { return '/?screen=events&event_view='.rawurlencode($view).'&event_page='.(int)$page; }
}

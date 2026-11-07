<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable test flag required.');
add_filter('pre_http_request',static function($pre,$args,$url){
    if(strpos($url,'https://personal.connectapi.garmin.com/')!==0)return $pre;
    // Never send any synthetic Garmin credential to a real network endpoint.
    if(wp_get_current_user()->user_login!=='synthetic_settings')return new WP_Error('synthetic_network_blocked','Garmin network disabled in synthetic tests.');
    $status=200;$body='[]';
    if(strpos($url,'personal-api-key/token')!==false){$body='synthetic.settings.token';if(strpos($args['headers']['Authorization']??'','invalid')!==false){$status=401;$body='';}}
    elseif(strpos($url,'activitylist-service/')!==false)$body=wp_json_encode(array(array('activityId'=>999,'activityName'=>'Synthetic Garmin pool','startTimeLocal'=>'2026-10-01 08:00:00','activityType'=>array('typeKey'=>'lap_swimming'),'distance'=>100,'privateRoute'=>'PRIVATE_GARMIN_ROUTE_CANARY')));
    elseif(strpos($url,'activity-service/activity/999')!==false)$body=wp_json_encode(array('activityId'=>999,'activityName'=>'Synthetic Garmin pool','activityTypeDTO'=>array('typeKey'=>'lap_swimming'),'summaryDTO'=>array('startTimeLocal'=>'2026-10-01 08:00:00','distance'=>100,'duration'=>120)));
    elseif(strpos($url,'spo2acclimation/')!==false)$body='{}';
    elseif(strpos($url,'weight-service/')!==false)$body='[]';
    else{$status=404;$body='Synthetic unavailable file';}
    return array('response'=>array('code'=>$status),'headers'=>array(),'body'=>$body);
},10,3);

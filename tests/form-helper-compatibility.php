<?php
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable test flag required.');
require_once dirname(__DIR__).'/app/PlanFormValues.php';
use SwimlogStandalone\PlanFormValues as FV;
FV::load();
foreach(array(array('SwimLogEvaluation\\Admin_UI','native_distance'),array('SwimLogEvaluation\\Admin_UI','duration_text'),array('SwimLogEvaluation\\Admin_UI','duration_ms'),array('SwimLogEvaluation\\Admin_Planned_Workouts','normalize_form'))as$method)if(is_callable($method))throw new RuntimeException('Legacy helper path was not exercised.');
if(FV::nativeDistance(22.86,'yd')!=='25'||FV::durationText(62345)!=='1:02.345'||FV::durationMs('1:02.345')!==62345||!is_wp_error(FV::durationMs('10:99')))throw new RuntimeException('Legacy form helper compatibility failed.');
echo "Legacy admin helper compatibility passed: all four newer helpers absent; exact units/duration preserved.\n";

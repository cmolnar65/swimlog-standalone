<?php
// Fresh process, no WordPress/plugin state: fail closed for absent or partial services.
if(getenv('SWIMLOG_SYNTHETIC_TEST')!=='1')throw new RuntimeException('Disposable test flag required.');
define('SWIMLOG_EVALUATION_DIR','/tmp/no-such-synthetic-plugin/');
class WP_Error {public function __construct(...$args){}}
function is_wp_error($value){return $value instanceof WP_Error;}
function update_user_meta(...$args){throw new RuntimeException('Provisional writes are forbidden.');}
class IncompleteVisibility {public static function ready(){return true;}}
if(($argv[1]??'')==='partial')class_alias(IncompleteVisibility::class,'SwimLogEvaluation\Workout_Visibility');
require dirname(__DIR__).'/app/Sharing.php';
use SwimlogStandalone\Sharing as S;
if(S::ready()||!is_wp_error(S::ownerState(1))||!is_wp_error(S::publicWorkout('1'))||!is_wp_error(S::command(1,'visibility',array())))throw new RuntimeException('Missing service must fail closed.');
echo "Absent/partial visibility service fails closed without storage or raw reads.\n";

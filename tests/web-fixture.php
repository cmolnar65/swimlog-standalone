<?php
if (getenv('SWIMLOG_SYNTHETIC_TEST') !== '1') throw new RuntimeException('Disposable test flag required.');
$owner = get_user_by('login', 'synthetic_swimmer');
$other = get_user_by('login', 'synthetic_other');
$other->add_cap('swimlog_view_own_results', false);
wp_set_password('synthetic-login-only', $owner->ID);
global $wpdb;
$wpdb->update($wpdb->users, array('display_name' => '<script>synthetic</script>'), array('ID' => $owner->ID));
clean_user_cache($owner->ID);
// Repeated browser/HTTP matching flows reuse one synthetic recorded swim.
// Reset only plans created by those flows in this disposable database.
if(defined('SWIMLOG_EVALUATION_DIR')) {
    require_once SWIMLOG_EVALUATION_DIR.'includes/class-planned-workout.php';
    require_once SWIMLOG_EVALUATION_DIR.'includes/class-structured-workout-store.php';
    require_once SWIMLOG_EVALUATION_DIR.'includes/class-planned-structure-link.php';
    wp_set_current_user($owner->ID);
    foreach(\SwimLogEvaluation\Planned_Workout::all_for_user($owner->ID)as$plan) {
        if(strpos($plan->title,'Synthetic match ' )===0&&!empty($plan->completed_workout_id)) {
            $reset=\SwimLogEvaluation\Planned_Workout::clear_completed_workout($plan->id,$owner->ID);
            if(is_wp_error($reset))throw new RuntimeException('Synthetic browser match reset failed.');
        }
    }
}
$expiry = time() + 3600;
$cookie = wp_generate_auth_cookie($owner->ID, $expiry, 'logged_in');
$otherCookie = wp_generate_auth_cookie($other->ID, $expiry, 'logged_in');
file_put_contents(getenv('SWIMLOG_WEB_FIXTURE'), json_encode(array('name' => LOGGED_IN_COOKIE, 'cookie' => $cookie, 'settings_cookie'=>wp_generate_auth_cookie(get_user_by('login','synthetic_settings')->ID,$expiry,'logged_in'), 'entry_cookie'=>wp_generate_auth_cookie(get_user_by('login','synthetic_entry')->ID,$expiry,'logged_in'), 'other' => $otherCookie, 'events'=>get_user_meta($owner->ID,'standalone_event_fixture',true), 'plans'=>get_user_meta($owner->ID,'standalone_planned_fixture',true), 'workouts' => get_user_meta($owner->ID,'standalone_detail_fixture',true))));
chmod(getenv('SWIMLOG_WEB_FIXTURE'), 0600);

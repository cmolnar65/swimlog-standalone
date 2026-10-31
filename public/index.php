<?php
use SwimlogStandalone\Auth;
try {
    $config = require dirname(__DIR__) . '/app/bootstrap.php';
    Auth::headers();
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'self'; script-src 'self'; connect-src 'self'; form-action 'self' " . $config['wordpress_origin'] . "; frame-ancestors 'none'; base-uri 'none'");
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host !== parse_url($config['app_origin'], PHP_URL_HOST)) { http_response_code(400); exit('Invalid host.'); }
    if (!is_ssl()) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { http_response_code(400); exit('HTTPS required.'); }
        header('Location: ' . $config['app_origin'] . '/', true, 303); exit;
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    $action = $_GET['action'] ?? '';
    if (!is_string($action)) { http_response_code(400); exit('Invalid request.'); }
    if ($action === 'callback') {
        if ($method !== 'POST') { http_response_code(405); header('Allow: POST'); exit('POST required.'); }
        if (($_SERVER['HTTP_ORIGIN'] ?? '') !== $config['wordpress_origin']) Auth::loginFailure($config['app_origin']);
        $cookie = Auth::redeem($_POST['code'] ?? '', $_COOKIE[Auth::STATE_COOKIE] ?? '');
        Auth::cookie(LOGGED_IN_COOKIE, $cookie); // Same signed session, no lifetime extension; browser-session persistence.
        Auth::cookie(Auth::STATE_COOKIE, '', time() - 3600);
        header('Location: ' . $config['app_origin'] . '/', true, 303); exit;
    }
    if ($action === 'login') {
        if ($method !== 'POST') { http_response_code(405); header('Allow: POST'); exit('POST required.'); }
        if (($_SERVER['HTTP_ORIGIN'] ?? '') !== $config['app_origin']) { http_response_code(403); exit('Invalid origin.'); }
        $verifier = Auth::random();
        Auth::cookie(Auth::STATE_COOKIE, $verifier, time() + 600);
        $url = $config['wordpress_origin'] . '/?swimlog_standalone_auth=1&challenge=' . hash('sha256', $verifier);
        header('Location: ' . $url, true, 303); exit;
    }
    $owner = Auth::cookieUser();
    wp_set_current_user($owner); // Refuse Application Password or other non-cookie identity.
    if ($action === 'logout') {
        if ($method !== 'POST') { http_response_code(405); header('Allow: POST'); exit('POST required.'); }
        if (!$owner || ($_SERVER['HTTP_ORIGIN'] ?? '') !== $config['app_origin'] || !wp_verify_nonce(is_string($_POST['nonce'] ?? null) ? $_POST['nonce'] : '', 'swimlog_standalone_logout')) { http_response_code(403); exit('Logout request expired.'); }
        wp_logout();
        Auth::cookie(LOGGED_IN_COOKIE, '', time() - 3600);
        Auth::cookie(Auth::STATE_COOKIE, '', time() - 3600);
        header('Location: ' . $config['app_origin'] . '/?signed_out=1', true, 303); exit;
    }
    if ($action === 'plan_transfer') {
        if ($method !== 'POST') { http_response_code(405); header('Allow: POST'); exit('POST required.'); }
        if (!$owner) { http_response_code(401); exit('Sign in again before moving or copying a plan.'); }
        require_once dirname(__DIR__).'/app/PlanActions.php';
        $out=\SwimlogStandalone\PlanActions::submit($owner,wp_unslash($_POST),$_SERVER['HTTP_ORIGIN']??'',$config['app_origin']);
        if (is_wp_error($out)) { http_response_code((int)($out->get_error_data()['status']??400)); exit(esc_html($out->get_error_message())); }
        if (isset($out['id'])) { header('Location: '.$config['app_origin'].$out['url'],true,303); exit; }
        $planActionForm=$out['form'];
        http_response_code((int)($planActionForm['error']->get_error_data()['status']??422));
    }
    if ($action === 'plan_save') {
        if ($method !== 'POST') { http_response_code(405); header('Allow: POST'); exit('POST required.'); }
        if (!$owner) { http_response_code(401); exit('Sign in again before saving a planned workout.'); }
        require_once dirname(__DIR__).'/app/PlanEditor.php';
        $out=\SwimlogStandalone\PlanEditor::submit($owner,wp_unslash($_POST),$_SERVER['HTTP_ORIGIN']??'',$config['app_origin']);
        if (is_wp_error($out)) { http_response_code((int)($out->get_error_data()['status']??400)); exit(esc_html($out->get_error_message())); }
        if (isset($out['id'])) { header('Location: '.$config['app_origin'].$out['url'],true,303); exit; }
        $planForm=$out['form'];
        http_response_code((int)($planForm['error']->get_error_data()['status']??422));
    }
    if ($action === 'planned_detail') {
        if (!in_array($method,array('GET','HEAD'),true)) { header('Allow: GET, HEAD'); wp_send_json(array('error'=>'Read-only endpoint.'),405); }
        if (!$owner) wp_send_json(array('error'=>'Sign in to view planned workout details.'),401);
        if (!Auth::requireCapability($owner) || !wp_verify_nonce((string)($_SERVER['HTTP_X_SWIMLOG_NONCE']??''),'swimlog_planned_detail')) wp_send_json(array('error'=>'Planned workout access denied. Refresh the calendar and try again.'),403);
        require_once dirname(__DIR__).'/app/PlannedDetails.php';
        $detail=\SwimlogStandalone\PlannedDetails::load($owner,$_GET['id']??null);
        if (is_wp_error($detail)) wp_send_json(array('error'=>$detail->get_error_message()),(int)($detail->get_error_data()['status']??400));
        wp_send_json(array('html'=>\SwimlogStandalone\PlannedDetails::render($detail)),200);
    }
    if ($action === 'workout_detail') {
        if (!in_array($method,array('GET','HEAD'),true)) { header('Allow: GET, HEAD'); wp_send_json(array('error'=>'Read-only endpoint.'),405); }
        if (!$owner) wp_send_json(array('error'=>'Sign in to view workout details.'),401);
        if (!Auth::requireCapability($owner) || !wp_verify_nonce((string)($_SERVER['HTTP_X_SWIMLOG_NONCE']??''),'swimlog_workout_detail')) wp_send_json(array('error'=>'Workout access denied. Refresh the calendar and try again.'),403);
        require_once dirname(__DIR__).'/app/WorkoutDetails.php';
        $detail=\SwimlogStandalone\WorkoutDetails::load($owner,$_GET['id']??null);
        if (is_wp_error($detail)) wp_send_json(array('error'=>$detail->get_error_message()),(int)($detail->get_error_data()['status']??400));
        wp_send_json(array('html'=>\SwimlogStandalone\WorkoutDetails::render($detail)),200);
    }
    if (!isset($planForm) && !isset($planActionForm) && ($action !== '' || !in_array($method, array('GET', 'HEAD'), true))) { http_response_code(404); exit('Not found.'); }
    if ($owner && !Auth::requireCapability($owner)) { http_response_code(403); exit('Your account does not have Swim Log access.'); }
    $signedIn = $owner > 0 && (isset($planForm)||isset($planActionForm)||!isset($_GET['login_error']));
    if ($signedIn) {
        $screen=(isset($planForm)||isset($planActionForm))?'calendar':($_GET['screen']??'calendar');
        if (!is_string($screen)||!in_array($screen,array('calendar','log'),true)) { http_response_code(400); exit('Unknown screen.'); }
        require_once dirname(__DIR__).'/app/CalendarView.php';
        if (isset($planActionForm)) {
            $returnUrl=$planActionForm['context']['url'];
        } elseif (isset($planForm)) {
            $returnUrl=$planForm['context']['url'];
        } elseif ($screen==='log') {
            require_once dirname(__DIR__).'/app/WorkoutLog.php';
            $log=\SwimlogStandalone\WorkoutLog::load($owner,$_GET);
            $returnUrl=is_wp_error($log)?'/?screen=log':\SwimlogStandalone\WorkoutLog::url($log['filters'],$log['page']);
        } else {
            $calendar=\SwimlogStandalone\CalendarView::load($owner,$_GET);
            $returnUrl=is_wp_error($calendar)?'/':\SwimlogStandalone\CalendarView::url($calendar['date'],$calendar['view']);
        }
        if (isset($_GET['plan_action']) && !isset($planActionForm)) {
            if ($screen!=='calendar'||isset($_GET['plan'])||isset($_GET['workout'])||isset($_GET['edit_plan'])) { http_response_code(400); exit('Choose one planned workout action.'); }
            require_once dirname(__DIR__).'/app/PlanActions.php';
            $planActionForm=\SwimlogStandalone\PlanActions::open($owner,$_GET['source_plan']??null,$_GET['plan_action'],$_GET);
            if (is_wp_error($planActionForm)) { http_response_code((int)($planActionForm->get_error_data()['status']??400)); exit(esc_html($planActionForm->get_error_message())); }
        }
        if (isset($_GET['edit_plan']) && !isset($planForm)) {
            if ($screen!=='calendar'||isset($_GET['plan'])||isset($_GET['workout'])) { http_response_code(400); exit('Choose one planned workout.'); }
            require_once dirname(__DIR__).'/app/PlanEditor.php';
            $planForm=\SwimlogStandalone\PlanEditor::open($owner,$_GET['edit_plan'],$_GET);
            if (is_wp_error($planForm)) { http_response_code((int)($planForm->get_error_data()['status']??400)); exit(esc_html($planForm->get_error_message())); }
        }
        if (isset($_GET['plan'],$_GET['workout'])) { http_response_code(400); exit('Choose one workout.'); }
        if (isset($_GET['plan'])) {
            require_once dirname(__DIR__).'/app/PlannedDetails.php';
            $plannedDetail=\SwimlogStandalone\PlannedDetails::load($owner,$_GET['plan']);
            if (is_wp_error($plannedDetail)) http_response_code((int)($plannedDetail->get_error_data()['status']??400));
        }
        if (isset($_GET['workout'])) {
            require_once dirname(__DIR__).'/app/WorkoutDetails.php';
            $workoutDetail=\SwimlogStandalone\WorkoutDetails::load($owner,$_GET['workout']);
            if (is_wp_error($workoutDetail)) http_response_code((int)($workoutDetail->get_error_data()['status']??400));
        }
    }
    require dirname(__DIR__) . '/app/home.php';
} catch (Throwable $error) {
    if (isset($action, $config['app_origin']) && $action === 'callback') Auth::loginFailure($config['app_origin']);
    http_response_code(isset($action) && $action === 'callback' ? 403 : 503);
    header('Cache-Control: no-store');
    // Never log exception text containing cookie, grant or credentials.
    error_log('Swimlog standalone: '.get_class($error).' at '.basename($error->getFile()).':'.$error->getLine().'.');
    echo 'Swim Log is unavailable or the login request expired. Return to the home page and try again.';
}

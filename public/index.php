<?php
use SwimlogStandalone\Auth;
try {
    $config = require dirname(__DIR__) . '/app/bootstrap.php';
    Auth::headers();
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'self'; form-action 'self' " . $config['wordpress_origin'] . "; frame-ancestors 'none'; base-uri 'none'");
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
    if ($action !== '' || !in_array($method, array('GET', 'HEAD'), true)) { http_response_code(404); exit('Not found.'); }
    if ($owner && !Auth::requireCapability($owner)) { http_response_code(403); exit('Your account does not have Swim Log access.'); }
    $signedIn = $owner > 0 && !isset($_GET['login_error']);
    if ($signedIn) {
        require_once dirname(__DIR__) . '/app/CalendarView.php';
        $calendar = \SwimlogStandalone\CalendarView::load($owner, $_GET);
    }
    require dirname(__DIR__) . '/app/home.php';
} catch (Throwable $error) {
    if (isset($action, $config['app_origin']) && $action === 'callback') Auth::loginFailure($config['app_origin']);
    http_response_code(isset($action) && $action === 'callback' ? 403 : 503);
    header('Cache-Control: no-store');
    // Never log exception text containing cookie, grant or credentials.
    error_log('Swimlog standalone: request could not complete.');
    echo 'Swim Log is unavailable or the login request expired. Return to the home page and try again.';
}

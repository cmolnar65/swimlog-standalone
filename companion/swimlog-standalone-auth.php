<?php
/** Plugin Name: Swim Log Standalone Authentication
 * Description: Browser-bound WordPress session handoff. Contains no Swim Log business logic.
 * Version: 0.1.0-dev
 * License: GPL-3.0-or-later
 */
if (!defined('ABSPATH')) exit;
if (!class_exists('SwimlogStandalone\\Auth', false)) require_once dirname(__DIR__) . '/app/Auth.php';
add_action('swimlog_sso_cleanup', array('SwimlogStandalone\\Auth', 'cleanup'));
add_action('init', function () {
    if (isset($_GET['swimlog_standalone_auth']) && $_GET['swimlog_standalone_auth'] === '1') swimlog_standalone_auth_handoff();
}, 20);
function swimlog_standalone_auth_handoff() {
    try {
        $file = getenv('SWIMLOG_CONFIG') ?: '/var/www/swimlog.cmolnar.com/shared/config/app.php';
        if (!is_readable($file)) throw new RuntimeException('Configuration unavailable.');
        $config = require $file;
        $main = \SwimlogStandalone\Auth::origin($config['wordpress_origin']);
        $app = \SwimlogStandalone\Auth::origin($config['app_origin']);
        if (!is_ssl() || ($_SERVER['HTTP_HOST'] ?? '') !== parse_url($main, PHP_URL_HOST) || rtrim(home_url(), '/') !== $main || is_multisite() || (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN)) throw new RuntimeException('Unsupported environment.');
        \SwimlogStandalone\Auth::headers();
        $script_nonce = base64_encode(random_bytes(18));
        header("Content-Security-Policy: script-src 'nonce-" . $script_nonce . "'; default-src 'none'; form-action " . $app . " " . $main . "; frame-ancestors 'none'; base-uri 'none'");
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if (!in_array($method, array('GET', 'POST'), true)) { status_header(405); header('Allow: GET, POST'); exit; }
        $challenge = $method === 'POST' ? ($_POST['challenge'] ?? '') : ($_GET['challenge'] ?? '');
        if (!\SwimlogStandalone\Auth::valid($challenge)) throw new RuntimeException('Invalid challenge.');
        $owner = \SwimlogStandalone\Auth::cookieUser();
        wp_set_current_user($owner);
        $endpoint = $main . '/?swimlog_standalone_auth=1&challenge=' . $challenge;
        if (!$owner) { wp_safe_redirect(wp_login_url($endpoint)); exit; }
        if (!\SwimlogStandalone\Auth::requireCapability($owner)) { \SwimlogStandalone\Auth::loginFailure($app); }
        header('Content-Type: text/html; charset=UTF-8');
        if ($method === 'GET') {
            swimlog_standalone_auto_post($endpoint, array('challenge'=>$challenge, 'nonce'=>wp_create_nonce('swimlog_handoff_' . $challenge)), $script_nonce);
        } else {
            if (($_SERVER['HTTP_ORIGIN'] ?? '') !== $main || !wp_verify_nonce(is_string($_POST['nonce'] ?? null) ? $_POST['nonce'] : '', 'swimlog_handoff_' . $challenge)) { \SwimlogStandalone\Auth::loginFailure($app); }
            $code = \SwimlogStandalone\Auth::issue($challenge, $_COOKIE[LOGGED_IN_COOKIE] ?? '');
            // POST delivery keeps the one-time code out of access logs and history.
            swimlog_standalone_auto_post($app . '/?action=callback', array('code'=>$code), $script_nonce);
        }
        exit;
    } catch (Throwable $error) {
        if (isset($app)) \SwimlogStandalone\Auth::loginFailure($app);
        status_header(403); header('Cache-Control: no-store'); exit('Login could not complete. Return to Swim Log and try again.');
    }
}

/** Browser POST navigation preserves Origin checks and keeps secrets out of URLs. */
function swimlog_standalone_auto_post($action, $fields, $script_nonce) {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Signing in to Swim Log</title></head><body><p role="status">Signing you in…</p><form id="swimlog-handoff" method="post" action="' . esc_url($action) . '">';
    foreach ($fields as $name=>$value) echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
    echo '<noscript><p>JavaScript is disabled. Continue to finish signing in.</p><button>Continue to Swim Log</button></noscript></form><script nonce="' . esc_attr($script_nonce) . '">document.getElementById("swimlog-handoff").submit();</script></body></html>';
}

// Affect only login attempts whose return target is this companion's fixed route.
add_action('wp_login_failed', function ($username, $error = null) {
    swimlog_standalone_failed_login($error instanceof WP_Error ? $error : new WP_Error('login_failed', 'Login failed.'));
}, 10, 2);
add_filter('wp_login_errors', function ($errors, $redirect_to) {
    swimlog_standalone_failed_login($errors);
    return $errors;
}, 10, 2);
function swimlog_standalone_failed_login($errors) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_wp_error($errors) || !$errors->has_errors()) return;
    $target = $_POST['redirect_to'] ?? '';
    $action = $_REQUEST['action'] ?? 'login';
    if (!is_string($target) || $action !== 'login') return;
    try {
        $file = getenv('SWIMLOG_CONFIG') ?: '/var/www/swimlog.cmolnar.com/shared/config/app.php';
        if (!is_readable($file)) return;
        $config = require $file;
        $main = \SwimlogStandalone\Auth::origin($config['wordpress_origin']);
        $app = \SwimlogStandalone\Auth::origin($config['app_origin']);
        $parts = parse_url(wp_unslash($target));
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== parse_url($main, PHP_URL_HOST) || ($parts['path'] ?? '') !== '/' || (isset($parts['user']) || isset($parts['pass'])) || (isset($parts['port']) && $parts['port'] !== 443)) return;
        $query = array(); parse_str($parts['query'] ?? '', $query);
        if (($query['swimlog_standalone_auth'] ?? '') !== '1' || !\SwimlogStandalone\Auth::valid($query['challenge'] ?? '')) return;
        \SwimlogStandalone\Auth::loginFailure($app);
    } catch (Throwable $error) { return; }
}

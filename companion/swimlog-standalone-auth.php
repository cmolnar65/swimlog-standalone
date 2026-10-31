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
        header("Content-Security-Policy: default-src 'none'; form-action " . $app . " " . $main . "; frame-ancestors 'none'; base-uri 'none'");
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if (!in_array($method, array('GET', 'POST'), true)) { status_header(405); header('Allow: GET, POST'); exit; }
        $challenge = $method === 'POST' ? ($_POST['challenge'] ?? '') : ($_GET['challenge'] ?? '');
        if (!\SwimlogStandalone\Auth::valid($challenge)) throw new RuntimeException('Invalid challenge.');
        $owner = \SwimlogStandalone\Auth::cookieUser();
        wp_set_current_user($owner);
        $endpoint = $main . '/?swimlog_standalone_auth=1&challenge=' . $challenge;
        if (!$owner) { wp_safe_redirect(wp_login_url($endpoint)); exit; }
        if (!\SwimlogStandalone\Auth::requireCapability($owner)) { status_header(403); exit('Your account does not have Swim Log access.'); }
        header('Content-Type: text/html; charset=UTF-8');
        if ($method === 'GET') {
            echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Continue to Swim Log</title><h1>Continue to Swim Log</h1><p>Signed in as ' . esc_html(wp_get_current_user()->display_name) . '.</p><form method="post" action="' . esc_url($endpoint) . '"><input type="hidden" name="challenge" value="' . esc_attr($challenge) . '"><input type="hidden" name="nonce" value="' . esc_attr(wp_create_nonce('swimlog_handoff_' . $challenge)) . '"><button>Continue to ' . esc_html(parse_url($app, PHP_URL_HOST)) . '</button></form></html>';
        } else {
            if (($_SERVER['HTTP_ORIGIN'] ?? '') !== $main || !wp_verify_nonce(is_string($_POST['nonce'] ?? null) ? $_POST['nonce'] : '', 'swimlog_handoff_' . $challenge)) { status_header(403); exit('Invalid confirmation.'); }
            $code = \SwimlogStandalone\Auth::issue($challenge, $_COOKIE[LOGGED_IN_COOKIE] ?? '');
            // POST delivery keeps the one-time code out of access logs and history.
            echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Open Swim Log</title><h1>Ready to open Swim Log</h1><form method="post" action="' . esc_url($app . '/?action=callback') . '"><input type="hidden" name="code" value="' . esc_attr($code) . '"><button>Open Swim Log</button></form></html>';
        }
        exit;
    } catch (Throwable $error) {
        status_header(403); header('Cache-Control: no-store'); exit('Login could not complete. Return to Swim Log and try again.');
    }
}

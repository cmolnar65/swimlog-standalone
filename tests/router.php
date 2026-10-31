<?php
// Disposable HTTP transport shim only. NEVER package or deploy this router.
if (getenv('SWIMLOG_SYNTHETIC_TEST') !== '1') { http_response_code(404); exit; }
$_SERVER['HTTPS'] = ($_SERVER['HTTP_X_SYNTHETIC_INSECURE'] ?? '') === '1' ? 'off' : 'on';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (($_SERVER['HTTP_HOST'] ?? '') === 'swimlog.example.test') {
    if (in_array($path, array('/assets/brand/icon.png','/assets/brand/banner.png'), true)) { header('Content-Type: image/png'); readfile(dirname(__DIR__).'/public'.$path); exit; }
    if (in_array($path,array('/assets/workout-details.js','/assets/calendar-drag.js','/assets/calendar-day.js','/assets/navigation.js','/assets/manual-entry.js','/assets/sharing.js','/assets/public-workout.js'),true)) { header('Content-Type: application/javascript'); readfile(dirname(__DIR__).'/public'.$path); exit; }
    if ($path === '/assets/app.css') { header('Content-Type: text/css'); readfile(dirname(__DIR__) . '/public/assets/app.css'); exit; }
    require dirname(__DIR__) . '/public/index.php';
} elseif (($_SERVER['HTTP_HOST'] ?? '') === 'wordpress.example.test' && in_array($path, array('/','/wp-login.php'), true)) {
    require getenv('SWIMLOG_TEST_WP') . ($path === '/wp-login.php' ? '/wp-login.php' : '/index.php');
} else { http_response_code(404); }

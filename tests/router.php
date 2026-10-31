<?php
// Disposable HTTP transport shim only. NEVER package or deploy this router.
if (getenv('SWIMLOG_SYNTHETIC_TEST') !== '1') { http_response_code(404); exit; }
$_SERVER['HTTPS'] = ($_SERVER['HTTP_X_SYNTHETIC_INSECURE'] ?? '') === '1' ? 'off' : 'on';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (($_SERVER['HTTP_HOST'] ?? '') === 'swimlog.example.test') {
    require dirname(__DIR__) . '/public/index.php';
} elseif (($_SERVER['HTTP_HOST'] ?? '') === 'wordpress.example.test' && $path === '/') {
    require getenv('SWIMLOG_TEST_WP') . '/index.php';
} else { http_response_code(404); }

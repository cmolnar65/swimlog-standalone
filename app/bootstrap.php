<?php
// Apache workers may retain a previous resolution of the current symlink.
// Make the MU loader use the exact release already executing this request.
if (!defined('SWIMLOG_STANDALONE_RELEASE_DIR')) define('SWIMLOG_STANDALONE_RELEASE_DIR', dirname(__DIR__));
require_once __DIR__ . '/Auth.php';
$configFile = getenv('SWIMLOG_CONFIG') ?: '/var/www/swimlog.cmolnar.com/shared/config/app.php';
if (!is_readable($configFile)) throw new RuntimeException('Application configuration unavailable.');
$config = require $configFile;
foreach (array('wordpress_load', 'wordpress_origin', 'app_origin') as $key) {
    if (empty($config[$key]) || !is_string($config[$key])) throw new RuntimeException('Incomplete application configuration.');
}
$config['wordpress_origin'] = \SwimlogStandalone\Auth::origin($config['wordpress_origin']);
$config['app_origin'] = \SwimlogStandalone\Auth::origin($config['app_origin']);
if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
require_once $config['wordpress_load'];
if (rtrim(home_url(), '/') !== $config['wordpress_origin'] || is_multisite()) throw new RuntimeException('Unsupported WordPress configuration.');
if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) throw new RuntimeException('Parent-domain cookies require review before enabling this application.');
if (!extension_loaded('openssl')) throw new RuntimeException('OpenSSL is required.');
return $config;

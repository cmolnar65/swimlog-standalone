<?php
// Installed as a WordPress MU-plugin. Pin standalone requests to their own release.
$swimlog_release = defined('SWIMLOG_STANDALONE_RELEASE_DIR')
    ? SWIMLOG_STANDALONE_RELEASE_DIR
    : '/var/www/swimlog.cmolnar.com/current';
$swimlog_companion = $swimlog_release . '/companion/swimlog-standalone-auth.php';
if (is_readable($swimlog_companion)) require_once $swimlog_companion;
unset($swimlog_release, $swimlog_companion);

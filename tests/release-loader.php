<?php
// Reproduce different canonical release paths in fresh PHP processes.
$root = sys_get_temp_dir() . '/swimlog-loader-' . bin2hex(random_bytes(8));
mkdir($root);
foreach (array('old', 'new') as $release) {
    mkdir($root . '/' . $release); mkdir($root . '/' . $release . '/app'); mkdir($root . '/' . $release . '/companion');
    copy(dirname(__DIR__) . '/app/Auth.php', $root . '/' . $release . '/app/Auth.php');
    copy(dirname(__DIR__) . '/companion/swimlog-standalone-auth.php', $root . '/' . $release . '/companion/swimlog-standalone-auth.php');
}
// The legacy companion really would redeclare Auth if the pin were ignored.
file_put_contents($root . '/old/companion/swimlog-standalone-auth.php', '<?php require_once dirname(__DIR__) . "/app/Auth.php";');
$loader = str_replace('/var/www/swimlog.cmolnar.com/current', $root . '/current', file_get_contents(dirname(__DIR__) . '/companion/loader.php'));
file_put_contents($root . '/loader.php', $loader);
file_put_contents($root . '/runner.php', <<<'RUNNER'
<?php
function add_action() {}
define('ABSPATH', __DIR__ . '/');
if ($argv[1] === 'pin') {
    define('SWIMLOG_STANDALONE_RELEASE_DIR', __DIR__ . '/new');
    require __DIR__ . '/new/app/Auth.php';
}
require __DIR__ . '/loader.php';
$reflection = new ReflectionClass('SwimlogStandalone\\Auth');
if ($reflection->getFileName() !== __DIR__ . '/new/app/Auth.php') exit(2);
echo 'ok';
RUNNER
);
symlink($root . '/old', $root . '/current');
try {
    foreach (array('pin', 'main') as $mode) {
        if ($mode === 'main') { unlink($root . '/current'); symlink($root . '/new', $root . '/current'); }
        $output = array(); $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($root . '/runner.php') . ' ' . $mode . ' 2>&1', $output, $status);
        if ($status !== 0 || implode('', $output) !== 'ok') throw new RuntimeException('Release loader regression failed: ' . $mode);
    }
    echo "Release loader regression passed: pinned new app with stale old current; normal WordPress current release.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname()); else unlink($entry->getPathname());
    }
    rmdir($root);
}

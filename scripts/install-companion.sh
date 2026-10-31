#!/usr/bin/env bash
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo 'Run with sudo.'; exit 1; }
root=/var/www/swimlog.cmolnar.com
wp=/var/www/cmolnar.com
[[ -f "$root/current/companion/swimlog-standalone-auth.php" && -f "$wp/wp-load.php" ]] || { echo 'Prepare and select an application release first.'; exit 1; }
[[ -r "$root/shared/config/app.php" ]] || exit 1
install -d -m 0755 "$wp/wp-content/mu-plugins"
loader="$wp/wp-content/mu-plugins/swimlog-standalone-loader.php"
[[ ! -e $loader ]] || { echo 'Loader already exists; left unchanged.'; exit 0; }
cat > "$loader" <<'PHP'
<?php
// Standalone companion loader. Does not modify the Swim Log plugin.
$file = '/var/www/swimlog.cmolnar.com/current/companion/swimlog-standalone-auth.php';
if (is_readable($file)) require_once $file;
PHP
chmod 0644 "$loader"
php -l "$loader"
echo 'Companion installed. Rollback to bootstrap makes this loader inert.'

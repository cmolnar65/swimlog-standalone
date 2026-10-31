#!/usr/bin/env bash
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo 'Run with sudo.'; exit 1; }
root=/var/www/swimlog.cmolnar.com
wp=/var/www/cmolnar.com
[[ -f "$root/current/companion/swimlog-standalone-auth.php" && -f "$wp/wp-load.php" ]] || { echo 'Prepare and select an application release first.'; exit 1; }
[[ -r "$root/shared/config/app.php" ]] || exit 1
install -d -m 0755 "$wp/wp-content/mu-plugins"
loader="$wp/wp-content/mu-plugins/swimlog-standalone-loader.php"
source_file="$(cd "$(dirname "$0")/.." && pwd)/companion/loader.php"
php -l "$source_file"
if [[ -e $loader ]]; then
    backup=$(mktemp "$root/deploy/companion-loader-backup.XXXXXXXX")
    cp -p "$loader" "$backup"
    echo "Previous loader backed up: $backup"
fi
# Rename in the same directory so WordPress never sees a partial loader.
staged=$(mktemp "$wp/wp-content/mu-plugins/.swimlog-loader.XXXXXXXX")
trap 'test ! -f "$staged" || unlink "$staged"' EXIT
install -o root -g root -m 0644 "$source_file" "$staged"
mv -f "$staged" "$loader"
trap - EXIT
systemctl reload apache2
echo 'Release-aware companion loader installed; Apache reloaded.'

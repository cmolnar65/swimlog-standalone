#!/usr/bin/env bash
# Run as cmolnar. Prepare by full commit SHA; activation is a separate operation.
set -euo pipefail
root=/var/www/swimlog.cmolnar.com
repo=/home/cmolnar/code/swimlog-standalone
command=${1:-status}
exec 9>"$root/deploy/update.lock"
flock -n 9 || { echo 'Another deployment is running.'; exit 1; }
case "$command" in
prepare)
    sha=${2:?Supply the full approved commit SHA}
    [[ $sha =~ ^[a-f0-9]{40}$ ]] || { echo 'A full commit SHA is required.'; exit 1; }
    git -C "$repo" fetch origin
    git -C "$repo" cat-file -e "$sha^{commit}"
    destination="$root/releases/$sha"
    [[ ! -e $destination ]] || { echo 'Release already exists.'; exit 1; }
    temporary=$(mktemp -d "$root/releases/.prepare.XXXXXXXX")
    trap 'rm -rf -- "$temporary"' EXIT
    git -C "$repo" archive "$sha" app public companion | tar -x -C "$temporary"
    find "$temporary" -type f -name '*.php' -exec php -l {} \; > "$root/deploy/lint.log"
    # find does not propagate individual -exec failures; run a strict loop too.
    while IFS= read -r -d '' file; do php -l "$file" >/dev/null; done < <(find "$temporary" -type f -name '*.php' -print0)
    printf '%s\n' "$sha" > "$temporary/COMMIT"
    find "$temporary" -type d -exec chmod 0755 {} +
    find "$temporary" -type f -exec chmod 0644 {} +
    mv "$temporary" "$destination"
    trap - EXIT
    echo "Prepared $sha; not activated."
    ;;
activate)
    sha=${2:?Supply the prepared commit SHA}
    [[ $sha =~ ^[a-f0-9]{40}$ && -f "$root/releases/$sha/public/index.php" ]] || exit 1
    [[ -r "$root/shared/config/app.php" ]] || { echo 'Install configuration first.'; exit 1; }
    [[ -L $root/current && ! -e $root/.current-next ]] || exit 1
    loader=/var/www/cmolnar.com/wp-content/mu-plugins/swimlog-standalone-loader.php
    if [[ -f $loader ]] && ! grep -q 'SWIMLOG_STANDALONE_RELEASE_DIR' "$loader"; then
        echo 'Update the companion loader first: sudo bash scripts/install-companion.sh'; exit 1
    fi
    previous=$(readlink "$root/current")
    [[ $previous =~ ^releases/(bootstrap|[a-f0-9]{40})$ ]] || exit 1
    printf '%s\n' "$previous" > "$root/deploy/previous"
    ln -s "releases/$sha" "$root/.current-next"
    mv -Tf "$root/.current-next" "$root/current"
    # Flush Apache/mod_php OPcache and worker realpath caches before health checks.
    if ! sudo systemctl reload apache2; then
        ln -s "$previous" "$root/.current-next"
        mv -Tf "$root/.current-next" "$root/current"
        sudo systemctl reload apache2 || true
        echo 'Apache reload failed; previous release restored.'; exit 1
    fi
    # No redirect following: the app must return its own expected login page.
    if ! curl --fail --silent --show-error --max-time 20 https://swimlog.cmolnar.com/ | grep -q 'data-swimlog-login'; then
        ln -s "$previous" "$root/.current-next"
        mv -Tf "$root/.current-next" "$root/current"
        sudo systemctl reload apache2 || true
        echo 'Health check failed; previous release restored.'; exit 1
    fi
    printf '%s activated %s\n' "$(date -u +%FT%TZ)" "$sha" >> "$root/deploy/history.log"
    echo "Activated $sha. Test login before accepting deployment."
    ;;
rollback)
    previous=$(cat "$root/deploy/previous")
    [[ $previous =~ ^releases/(bootstrap|[a-f0-9]{40})$ && -d "$root/$previous/public" && ! -e $root/.current-next ]] || exit 1
    ln -s "$previous" "$root/.current-next"
    mv -Tf "$root/.current-next" "$root/current"
    sudo systemctl reload apache2
    printf '%s rollback %s\n' "$(date -u +%FT%TZ)" "$previous" >> "$root/deploy/history.log"
    echo "Restored $previous."
    ;;
status) readlink -f "$root/current" ;;
*) echo 'Usage: bash scripts/deploy.sh prepare SHA | activate SHA | rollback | status'; exit 1 ;;
esac

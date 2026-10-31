# Installation and rollback

Run from `/home/cmolnar/code/swimlog-standalone` on the web host. First fetch and
check out the approved implementation branch/commit. No changes to the existing
Swim Log plugin are required. Commands below are not an automatic production deployment.

## Prerequisites

- Both virtual hosts already point at `/var/www/swimlog.cmolnar.com/current/public`.
- Confirm Apache executes PHP through the existing configured PHP-FPM handler.
  Do not expose application PHP until that is verified; do not guess a socket path.
- The PHP-FPM identity needs read access to WordPress and the application config.
  The examples assume `www-data`. Reuse the actual pool identity if different.
- CLI PHP, OpenSSL, curl, git and flock must be installed. Confirm cookie Domain is
  currently host-only, HTTPS is correctly recognized, and WordPress cron works.
- `cmolnar` owns releases/deploy directories. PHP gets read-only code/config access.
- If `opcache.validate_timestamps=0`, establish an explicit PHP-FPM reload step for
  deployment/rollback before use. Symlink switching alone is not sufficient for every
  OPcache/realpath-cache configuration. Do not guess a service name.

## Configuration

```bash
sudo install -o cmolnar -g www-data -m 0640 config/example.php \
  /var/www/swimlog.cmolnar.com/shared/config/app.php
vi /var/www/swimlog.cmolnar.com/shared/config/app.php
```

Review the three paths/origins. Do not add database passwords or copied salts.
`SWIMLOG_CONFIG` may override the config path, but must be configured consistently
for both the application and WordPress PHP requests. No forwarded headers are trusted
by the application: configure HTTPS recognition at the trusted server/proxy boundary.

## Prepare and activate

```bash
bash scripts/deploy.sh prepare FULL_APPROVED_COMMIT_SHA
bash scripts/deploy.sh activate FULL_APPROVED_COMMIT_SHA
sudo bash scripts/install-companion.sh
```

The first activation health check checks only the public login page. The companion
is installed after that activation and is necessary before testing sign-in. Its
loader is `/var/www/cmolnar.com/wp-content/mu-plugins/swimlog-standalone-loader.php`;
it loads the companion from `current` and does nothing if bootstrap is selected.
It does not edit the original Swim Log plugin or WordPress core.

Activation does not delete old releases, upgrade WordPress/schema, run importers or
create a tag/release. It records the prior target and automatically restores it if
the public health request fails. Run login acceptance before accepting installation.

```bash
bash scripts/deploy.sh status
bash scripts/deploy.sh rollback
```

Rollback restores the previous application target, including `bootstrap`. It does
not reverse WordPress user/session changes or revoke all previously issued sessions.
To remove the companion explicitly:

```bash
sudo rm /var/www/cmolnar.com/wp-content/mu-plugins/swimlog-standalone-loader.php
```

Allow pending `swimlog_sso_cleanup` cron events to finish before permanently removing
its loader; otherwise expired encrypted options need a separately reviewed cleanup.

## Acceptance checklist

Use test accounts without private activity/measurement records first. Check both
main-site already-signed-in and signed-out entry, login security plugins, correct
home name, denied missing capability, exact host-only cookies, refresh/expiry,
wrong-browser handoff rejection, repeated callback denial, logout on either host,
HTTP-to-HTTPS enforcement for the application, no-store responses and mobile layout.
No private data, cookie, handoff payload or request body belongs in Git or CI logs.

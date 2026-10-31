# Swim Log standalone — authentication proof of concept

Dedicated interface for https://swimlog.cmolnar.com. WordPress at
`/var/www/cmolnar.com/wp-load.php` remains the identity/session and data authority.
No Swim Log business data is read or changed in this phase. No plugin schema,
Swim Log source, main-plugin release, SVN or production database copy is involved.

## Layout

- `public/`: the only Apache document root; CSS and front controller.
- `app/`: authentication library, bootstrap and protected placeholder template.
- `companion/`: small WordPress companion, loaded by a separate MU-plugin loader.
- `config/example.php`: server configuration example, no credentials.
- `scripts/`: explicit commit preparation, activation/rollback and companion installer.
- `tests/`: disposable real-WordPress tests; synthetic identities only.

PHP 7.4–8.5, OpenSSL, and a single-site WordPress installation are the intended
compatibility range. CI verifies the PHP matrix against a fresh current WordPress.
This phase uses only the existing `swimlog_view_own_results` capability; there is
no dependency on a particular Swim Log schema because no business records are queried.
Missing capabilities fail closed. Later features need explicit plugin/schema checks.

## Authentication boundary

1. Public welcome page posts a same-origin login request; a Secure, HttpOnly,
   SameSite=Lax, host-only browser verifier cookie is created.
2. The browser visits the companion on cmolnar.com. Existing WordPress login handles
   passwords, password reset, login security plugins and authentication.
3. The signed-in user confirms with a session/action-bound WordPress nonce.
4. An encrypted, 120-second grant is stored in non-autoloaded WordPress options.
   The browser posts an opaque one-time code to the fixed application callback.
   This deliberate two-button confirmation needs no inline JavaScript.
5. The callback checks exact Origin, browser verifier, grant expiry, capability and
   the original session. An atomic compare-and-delete permits exactly one redemption.
6. Only the original WordPress logged-in cookie is set on the application host,
   with Secure/HttpOnly, path `/`, no Domain and no expiry extension. It is a browser
   session cookie even if the main WordPress login used Remember Me.
7. Every protected request validates that actual cookie, rejects core's POST expiry
   grace period, sets current user from it and checks the existing capability.
8. Logout is POST + exact Origin + session nonce; WordPress revokes the shared
   current session. Main-host cookies cannot be cleared from the subdomain but
   their revoked session is rejected on the next request. Other device sessions remain.

No arbitrary return URL is accepted: successful login always returns to `/`.
No Application Password/JWT/second password database/CORS expansion is introduced.
Grants and original session cookies are never placed in URLs or logs. Encrypted
abandoned grants are deleted by WordPress cron; normal cron operation is required.
Disable request-body logging for these authentication endpoints in any WAF/APM.
Parent-domain authentication cookies and multisite deliberately fail preflight until
reviewed. Existing host-only cookies must be confirmed before enabling the companion.

Full `wp-load.php` still runs active plugins and theme PHP; it bypasses theme template
rendering, not all theme initialization. Configuration, unexpected output, cache,
canonical/security plugin redirects and PHP-FPM handling need live acceptance.
No global URL, cookie, theme, REST or CORS filters are installed.

## Server setup and deployment

See [installation instructions](docs/INSTALL.md). No server operation is performed
by CI. `prepare` and `activate` are intentionally separate. This initial deployment
uses an explicitly approved commit, not an unreviewed branch HEAD or automatic release.
Tags, release packaging and unattended deployment remain future work.

## Verification

The GitHub Authentication workflow lints PHP/shell and runs real WordPress integration
checks in disposable MySQL for PHP 7.4 and 8.0–8.5. It creates synthetic users, checks
capabilities, encrypted storage, wrong-browser denial, single use, unchanged expiry,
revocation, expired POST cookies and strict origin configuration. No personal fixtures.

Live acceptance remains required: existing/new WordPress login; confirmation; home
name escaping; no-capability account; session expiry; logout from each host; replay;
HTTPS; host-only cookie flags/path; installed login/security/cache plugins; physical
mobile devices. Automated integration success is not live-site acceptance.

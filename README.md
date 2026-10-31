# Swim Log standalone

Dedicated interface for https://swimlog.cmolnar.com. WordPress at
`/var/www/cmolnar.com/wp-load.php` remains the identity/session and data authority.
The calendar and workout log read owner-scoped records through the existing plugin services. Planned workouts can be created/edited through the existing plugin service. No plugin schema,
Swim Log source, main-plugin release, SVN or production database copy is involved.

## Layout

- `public/`: the only Apache document root; CSS and front controller.
- `app/`: authentication, bootstrap and protected view adapters/templates.
- `companion/`: small WordPress companion, loaded by a separate MU-plugin loader.
- `config/example.php`: server configuration example, no credentials.
- `scripts/`: explicit commit preparation, activation/rollback and companion installer.
- `tests/`: disposable real-WordPress tests; synthetic identities only.

PHP 7.4–8.5, OpenSSL, and a single-site WordPress installation are the intended
compatibility range. CI verifies the PHP matrix against a fresh current WordPress.
Authentication requires the existing `swimlog_view_own_results` capability.
Calendar reads additionally honor private-land capabilities and require the installed
schema to be at least the active plugin runtime schema. The tested plugin checkpoint
is recorded in CI and branding provenance. Missing capabilities fail closed.

## Authentication boundary

1. Public welcome page posts a same-origin login request; a Secure, HttpOnly,
   SameSite=Lax, host-only browser verifier cookie is created.
2. The browser visits a narrowly scoped companion route on cmolnar.com. Existing WordPress login handles
   passwords, password reset, login security plugins and authentication.
3. The browser automatically posts a session/action-bound WordPress nonce.
4. An encrypted, 120-second grant is stored in non-autoloaded WordPress options.
   The browser posts an opaque one-time code to the fixed application callback.
   Both handoff forms submit automatically using a per-response CSP nonce;
   JavaScript-disabled browsers retain a manual fallback button.
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
canonical/security plugin redirects and PHP handler behavior need live acceptance.
The handoff route runs during `init`, before template rendering, without invoking
`wp-admin` or `admin_init` maintenance/migration hooks. No global URL, cookie,
theme, REST or CORS filters are installed.

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

### Browser-origin regression

Authentication pages use `strict-origin-when-cross-origin`: `no-referrer` can turn
real form POST Origin headers into `null`. Exact-origin checks still reject absent,
null and foreign origins. The app CSP allows the fixed WordPress origin for the
login redirect. Chromium, Firefox and WebKit exercise actual form submissions in
the disposable environment using real local HTTPS hosts with an ephemeral test
certificate and a local WordPress backend. This tests browser header/CSP/redirect
behavior; production certificates, proxies and site plugins still need acceptance.

### Release-switch regression

The application pins its executing release before WordPress loads. The MU loader
honors that pin instead of resolving `current` a second time. A fresh-process test
loads a new application with `current` deliberately pointing at a different copy
of Auth; it also checks ordinary WordPress loading. Activation/rollback reload
Apache to invalidate mod_php/realpath caches before health checking. Existing
installations must update the companion loader once with the backed-up installer.

## Branded calendar home

The signed-in home now reads the existing plugin Calendar service directly, with
current-owner cookie/capability checks and no external REST opt-in requirement.
Month/week navigation respects the swimmer's week start and site timezone. Desktop
uses a seven-column grid; phones use a date agenda with native expandable entries.
Swimming, running and walking totals remain separate and come from the plugin's
weekly summary. No copied calculation/persistence layer or database migration is
introduced. An incomplete installed schema produces a controlled error; the app
does not run upgrades. Measurements and editing are later phases, with existing
WordPress pages linked from a capability-filtered tools menu in the meantime.

See [branding provenance](docs/BRANDING.md) and [carried-forward requirements](docs/ROADMAP.md).
Tests activate the pinned plugin only in a disposable WordPress database. Synthetic
mixed-owner/swim/land/event/plan fixtures cover owner isolation, separate totals,
null/zero distances, leap February, all week starts and schema errors. Browser tests
cover real authentication plus calendar navigation, touch details and no horizontal
overflow at 1440, 768, 390 and 320 CSS pixels in Chromium, Firefox and WebKit.

## Automatic authentication handoff

After standard WordPress login, the main-site companion automatically submits its
nonce-protected confirmation and the one-time callback POST. Users arrive directly
at the calendar without confirmation buttons when JavaScript is enabled. This does
not remove the real-cookie, capability, expiry, Origin, nonce or browser-verifier
checks. Each script has a cryptographically random CSP nonce; unsafe-inline is not
allowed. No-script browsers retain a manual continuation fallback.

Failed credentials (including an empty submission) on this application's WordPress
login return to the standalone sign-in screen with a generic retry message. The
hooks recognize only the fixed companion return route and leave ordinary WordPress
login attempts unchanged. Rejected/expired handoffs also return to that screen;
no account-existence, credential or session details are shown.

## Workout details from the calendar

Recorded activity cards open a native modal on desktop and mobile. Close, Escape,
outside click/tap and repeated trigger activation dismiss it; focus and calendar
scroll position are restored. The body scrolls separately, wide tables pan inside
the dialog, and the close control remains visible. Requests are aborted on close;
a generation guard prevents a late response from replacing a different workout.
Private content is removed from the closed dialog. Loading, retry and expired-session
states are explicit. Without JavaScript/dialog support, real links open a protected
standalone detail page with a return link preserving calendar date and view.

The same-origin read-only detail endpoint requires the real WordPress cookie,
own-results capability and a session-bound `swimlog_workout_detail` nonce. It derives
the owner server-side and ignores client owner overrides; unknown and foreign IDs
return the same 404. Land activities retain the existing additional owner capability
checks. Responses are private/no-store. No notes, raw import metadata, source files,
credentials or personal measurements are exposed by this surface.

Summary/laps/lengths/SWOLF/performances use existing plugin services. Open-water pace
and split calculations use the existing analytics service, retaining estimated labels.
Routes reuse the plugin's public conservative SVG renderer (including sequence/time/
missing-point gaps), sanitized for this page's CSP. No map tile service is contacted.
The conservative renderer also requires recorded timestamps for open-water points;
records without a usable connected segment show an explicit no-route state. Missing
moving time, GPS, distances or stroke counts are not invented. No recorded data is
modified. Large detail payloads fail explicitly rather than silently truncating.

## Workout Log

The top-level Workout Log is a read-only, newest-first history with inclusive From/To
dates, an activity filter and 20 workouts per page. The default range begins two
months before the current month and ends today; All history clears both boundaries.
Filters survive pagination and the protected detail-page return link. Desktop tables
become labelled cards on narrow screens; details reuse the calendar modal, including
tap-on/tap-off, Close, Escape and focus/scroll restoration. WordPress tools retains
Manage workouts for existing editing/import features.

`Workout::query_for_user` supplies owner-scoped queries and stable date/ID ordering.
`Training_Trends::summarize_workouts` and `Land_Activity::distances` supply separate
swimming/running/walking totals over all matching pages. Missing distances remain
unknown, recorded zero remains zero, and individual rows retain original units.
Land rows, filters and totals require the plugin's existing private-land authorization.
No owner parameter, notes, measurements, Garmin jobs or credentials are exposed.

Totals aggregate up to 10,000 matching workouts in bounded service-query batches.
Larger histories remain paginated and ask for a narrower range for totals, rather
than showing a partial total. This avoids an unbounded aggregation on every page
without introducing table queries or duplicated calculations. Synthetic integration
and browser checks cover pagination, filter boundaries, ownership/capability denial,
unknown/zero/yard distances, all-page totals, empty/error states and mobile details.

Pool details also show total identified rest and calculated moving time (elapsed
minus rest). Positive, complete rest-lap timing takes precedence; otherwise complete
rest-length timing is used. Overlapping lap/length representations are never summed.
The caption identifies the chosen source and notes that unrecorded pauses can remain.
Missing/invalid rest durations, absent elapsed time or rest exceeding elapsed leave
the calculation unavailable. Device-recorded moving time is displayed separately.
This display calculation does not alter stored times, analytics or performances and
is not applied to land/open-water activities.

## Planned workout details

Planned calendar cards now open the shared desktop/mobile dialog, with protected
full-page links as a no-JavaScript fallback. The view shows date/time, activity,
status, normalized planned distance/duration, pool course/stroke, and full written
instructions. Empty instructions, unlinked/corrupt structures and unavailable
recorded matches have explicit states. Calendar date/view survive fallback return
and matched-session navigation.

The adapter uses `Planned_Workout::get_for_user`, `Planned_Structure_Link::for_plan`,
`Structured_Workout_Store` and `Structured_Workout_Export::text`; the existing
exporter validates and formats prescriptions with their own declared units.
Structured prescriptions additionally require workout-management capability.
Matched sessions are independently resolved through `Workout::get_for_user`,
with same-owner and same-swim-type checks; invalid/foreign links reveal no IDs.
Notes and raw structured documents are never serialized. The read-only endpoint
requires a real cookie, own-results capability and a separate session-bound
`swimlog_planned_detail` nonce; foreign and absent IDs share the same 404.
No plan status, prescription, match or history is changed. Creation and editing are now available in the standalone calendar; moving and
copying remain in WordPress tools for the next implementation phase.

## Create and edit planned workouts

Plan workout is available in the calendar toolbar and on today/future day cards.
An editable plan's details link to a standalone form that also works without
JavaScript. The form retains submitted values on validation errors, supports
meters/yards and exact fractional durations, and uses Post/Redirect/Get after
success. Calendar date/view are preserved. Past unassociated plans can keep their
existing date when edited; the plugin rejects newly scheduled past dates.

`PlanEditor` is the write boundary. POST requires HTTPS, the real WordPress cookie,
own-results and manage-own-workouts capabilities, exact configured Origin, and a
plan-specific WordPress nonce. IDs are strictly validated and owner-scoped; no
client owner/status/match/notes/structure IDs or canonical numeric overrides are
accepted. Form values are unslashed once, scalar/size checked and escaped on output.
The public pure `Admin_Planned_Workouts::normalize_form` helper and `Admin_UI`
formatters preserve existing units/duration behavior; `Planned_Workout::save` owns
validation and every business write. No admin request handlers are invoked.

Existing private notes are supplied server-side and never enter the form. Status,
recorded matches and structure posts/links are not written. Matched plans and
recorded-swim/locked prescriptions are read-only in this interface. Unlocked linked
prescriptions are preserved even when written instructions change, with a visible
review reminder. A keyed revision detects stale forms (including notes, matches
and linked document changes) and requires reloading. It is an optimistic check,
not an atomic lock against simultaneous writes from other WordPress interfaces;
no transactional/CAS contract is added to the unchanged plugin service.

Synthetic service, HTTP and Chromium/Firefox/WebKit tests exercise creation, editing,
yard conversion, millisecond precision, invalid-input retention, stale/foreign/
unauthorized requests, CSRF/Origin failures, existing notes/history/structure
preservation, responsive forms and cancellation. No production database is used.

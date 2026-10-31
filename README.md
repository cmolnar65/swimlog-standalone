# Swim Log standalone

Dedicated interface for https://swimlog.cmolnar.com. WordPress at
`/var/www/cmolnar.com/wp-load.php` remains the identity/session and data authority.
The calendar and workout log read owner-scoped records through the existing plugin services. Planned workouts and events can be created/edited, and race results recorded, through existing plugin services. No plugin schema,
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
copying are also available through protected standalone date-selection forms.

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

### Older admin helper compatibility

The editor prefers plugin form-formatting helpers when they are callable. Older
installed builds omit `Admin_UI::native_distance`, `duration_text`, `duration_ms`
and `Admin_Planned_Workouts::normalize_form`. A small standalone form adapter
provides equivalent unit entry and exact millisecond formatting/parsing when those
optional helpers are absent. Canonical business validation and all writes still
use `Planned_Workout::save`; this does not duplicate persistence or account logic.

CI additionally replaces only the disposable installation's admin helper layer
with files from the released v1.2 checkpoint, asserts all four methods are absent,
and repeats editor integration and HTTP checks on every PHP version, plus Chromium
flows. This tests the legacy helper interface, not the entire application against
a v1.2 runtime/schema. Caught failures log only exception class, source basename and
line number; messages, traces, credentials, submitted values and cookies are omitted.

## Move and copy plans

Planned details provide Move and Copy controls that open responsive, no-JavaScript
date-selection forms. Targets must be today or a future date. Move is restricted to
unmatched, unlocked plans with status `planned`; it calls `Planned_Workout::move`
and retains the same plan ID, instructions, notes and structure link. Copy calls
`Planned_Workout::duplicate`, including its existing structured-document validation
and rollback behavior. Matched/locked plans can be copied as independent new plans;
the original, recorded match and locked prescription remain unchanged. The plugin
creates a separate unlocked structure for the copy. No ad hoc table writes occur.

Both operations require the real session, own-results/manage-own capabilities,
exact Origin and operation/plan-specific WordPress nonces. Strict field allowlists
reject ownership/history overrides; keyed revisions include the source plan,
structure and lock state. Stale forms require reloading. As with editing, this is
an optimistic check rather than a transaction spanning other WordPress interfaces.
Unsupported plugin service methods fail explicitly. Cancellation performs no write.
Success redirects to the resulting plan on the target calendar date, retaining
month/week view. Source controls retain calendar context on desktop and mobile.

Synthetic integration, HTTP and browser coverage includes moved IDs/dates, copied
structure independence, original notes/matches/lock preservation, invalid documents
without orphan plans, stale/foreign/CSRF rejection, target navigation and cancellation.
The legacy-admin-helper compatibility run also exercises these operations.

## Desktop calendar drag/drop

On desktop-width calendars, unmatched planned cards support native HTML drag/drop
onto a different day that is today or later, in month and week views. A highlighted
day shows a valid target. Recorded sessions and matched/cancelled/skipped plans
are not draggable. Locked plans fail the server preflight without changing data.
Touch-width calendars retain normal scrolling and Move/Copy date-selection forms.

Drag start performs a private, cookie/capability/nonce-protected read preflight
through `PlanActions::open`. Only a move nonce, opaque revision and calendar context
are returned; no notes/documents are serialized. Drop submits a native same-origin
POST to the existing `plan_transfer` handler. Ownership, lock/status, CSRF, Origin,
staleness and target-date validation are checked again before the plugin move.
The fixed `return_to=calendar` flag returns to the target day/view; no arbitrary
return URLs are accepted. Cancelled/rejected drags do not write, and preparation
failures remain visible without automatic retries. Existing detail links remain
usable without JavaScript; native anchor dragging is disabled until enhanced.

## Match a recorded swim

Unmatched, unlocked planned swims expose Match recorded workout from their details.
The responsive, no-JavaScript form defaults to the planned date, allows another
recorded date and paginates the owner's same-type swims. Already assigned swims
are omitted; suggestions reuse `Planned_Workout::completion_candidates`. No choice
is preselected. The swimmer explicitly selects a recorded swim and confirms.
Empty dates and cancellation perform no write.

`PlanMatch` derives the owner from the real session, requires own-results/manage-own
capabilities, exact Origin and a plan-specific WordPress nonce, and rejects field
overrides. Keyed plan/structure/lock and selected-record revisions prevent stale
confirmation. An owner-scoped MySQL/MariaDB advisory lock serializes competing
standalone matching requests; it writes no business tables and is released in a
finally block. Other WordPress interfaces do not participate in this lock; globally
atomic matching across those interfaces would require an approved plugin change.

All association writes call `Planned_Workout::associate_completed_workout`, retaining
its owner/type and duplicate-match checks. Existing matches cannot be replaced or
cleared from this surface. Matching marks the plan completed and causes the existing
structured-store service to lock the linked prescription. Notes, prescription
content, recorded swims and performances are not rewritten. The plugin's existing
clear/delete paths preserve recorded prescription history. Calendar highlighting
uses this owner's explicit associations even across different planned/recorded
dates or views. No land activities, measurements, files or Garmin credentials are
listed. Success returns to the matched plan and preserves calendar date/view.

Synthetic service/HTTP/browser checks cover date browsing, pagination, no preselected
choice, cancellation, stale and modified records, cross-owner/type rejection,
duplicate matches, competing match locks, preserved notes/documents/history and
completed/highlighted states in desktop and mobile layouts.

## Events and race results

Calendar events open the shared desktop/mobile dialog and have protected full-page
fallback links. Add event, Edit event and Record race result use responsive forms
that also work without JavaScript. Event name/date/time, distance, course, stroke
and owned location choices reuse `Event::save`; historical dates remain supported.
Race time (including milliseconds), placement and shared event notes use
`Event::save_result`. Metadata edits leave results intact; result edits leave race
metadata intact. Clearing an existing time/placement requires an explicit checkbox.
No event-delete operation is introduced. Event start times use the plugin's minute
precision; unchanged legacy second-precision times are not silently rounded.

Reads require a real cookie and own-results access; event notes/results and all
writes additionally require the plugin's `swimlog_manage_own_events` capability.
Location selectors and names are owner-scoped and never serialize location notes
or coordinates. Notes are the existing shared event notes field, not a new store.
The detail endpoint requires a separate session nonce and is private/no-store.
Foreign and missing IDs share the same 404. POST writes require exact Origin,
operation/ID-specific nonces, scalar/size allowlists, canonical IDs, and a keyed
row revision covering results and notes. An event-scoped advisory lock serializes
standalone edits/results; other WordPress interfaces do not participate in it.
All business writes remain in plugin services, with no database copy or migration.

Tests use synthetic events/locations only and cover owned/private reads, foreign
locations, XSS escaping, historical creation, result precision, stale/invalid/
CSRF requests, preserved results and metadata, explicit clearing, cancellation,
desktop/mobile pop-ups and forms, and older admin-helper compatibility.

Calendar day overflow uses the plugin’s 180px desktop / 200px mobile item budget. The “…” opens a full day dialog with all original detail links; nested detail dialogs retain editing, moving, copying and matching actions. A real day URL provides a non-JavaScript fallback. The Events menu provides List Events and Add Event; the owner-scoped list supports upcoming/past/all filters and 20-item pages. Notes remain in the authorized details/editor only.

Structured Workouts lives under Workouts → Structured Workouts. The standalone adapter uses the installed plugin’s structured editor JavaScript/CSS through authenticated fixed-path asset responses, and its Structured_Workout_Store, Structured_Workout, Planned_Structure_Link and export services. Supported here: active/hidden/all library (the service’s 500-record bound, paginated 20/page), creation, editing, clean duplication, hide/unhide, explicitly confirmed deletion of unreferenced prescriptions, planned-swim link/unlink, FORM text, and text/FIT/PDF exports. Recorded prescriptions stay locked. Source PDF files, import evidence, notes and Garmin data are never copied into duplicates or exports. Manual entry, recorded-file imports and PDF training-plan import/review remain available in WordPress tools. No plugin or schema change is required. Standalone structured writes use owner-specific advisory locking and stale-form checks; other WordPress interfaces are not covered by that app lock, so the plugin remains the final access/validation boundary.

Manual pool entry and recorded-file import now live at Workouts → Add Workout / Import Workout. Manual_Workout::save owns normalization, private manual source history, calculations and measured-repetition PB eligibility. The form supports totals and up to 100 optional swim/rest sets with measured-rep, measured-set, interval, derived or unknown timing; transport conversion uses the existing duration helper. Complexity is bounded before service expansion; a trailing form marker detects PHP input truncation. Session-bound WordPress transient receipts plus the plugin owner lock prevent repeat submissions from duplicating records. This scope creates new manual records only; it never rewrites imported or existing recorded workouts.

File uploads always use Importer::import_upload and genuine PHP-upload validation, never the trusted FIT-provider entry point. FIT priority, supported archives, upload-type settings, exact duplicate/match handling, private source preservation and land-activity permissions remain plugin-owned. Probable matches are displayed for explicit attach/separate confirmation using owner-scoped pending services, fresh source/candidate snapshots and the existing owner lock. Up to 10 uploads and 10 pending comparisons per page are shown. Existing private storage must be present and writable outside both application roots; the adapter reuses the WordPress installation’s configured/default directory and does not create or relocate production source storage. PHP upload/post limits remain server-owned and are displayed on the form. Source paths, routes, notes and parser payloads are not serialized in reviews or receipts. No plugin/schema change is required. Menu trees are mutually exclusive, including mouse, touch, keyboard, Escape and outside dismissal.

Desktop/tablet dropdowns align to their own trigger and clamp only at the viewport edge; their vertical position remains below all wrapped navigation rows. Phone menus remain in normal flow. Opening one menu closes all other trees and their nested disclosures. WordPress tools now lists only Personal bests, Garmin Connect and Preferences; duplicate workout/planned/structured shortcuts were removed. The structured submenu retains its WordPress PDF import link.


### Settings and Garmin

Settings contains Swimmer Configuration, owner-scoped Locations and Garmin Setup.
Garmin Setup saves/verifies/removes the existing encrypted Personal API credential
and configures the plugin's automatic activity and private measurement schedules.
The Garmin operational menu appears only for a successfully verified connection:
Import Activities, Import Measurements and Historical Imports. Direct requests
also enforce verification, current WordPress session, operation capabilities,
ownership, same-origin POST and WordPress nonces.

All persistence/import/calculation work uses installed plugin services. Swimmer
preferences use the existing admin settings validator inside a temporary,
restored POST/REQUEST context; its markup is discarded and standalone markup is
rendered. No plugin changes or schema changes are required. Automatic activity
configuration retains the plugin's own-results permission; measurement operations
require workout management, and activity imports require upload permission.
Land activity selections additionally require the existing private-land rules.
Credentials, routes and provider payloads are never serialized into page data.
Existing history/jobs survive credential replacement/removal.

WordPress tools now retains Personal bests only. Preferences and Garmin Connect
are replaced by Settings/Garmin; prior duplicated workout/planned/structured links
remain removed. Training-plan PDF import still links to its WordPress tool inside
Workouts → Structured Workouts.

Synthetic integration, HTTP and Chromium/Firefox/WebKit tests cover these screens.
Garmin network calls are intercepted only in the disposable test installation;
test helpers are excluded from deployment archives. Live Garmin provider behavior
and physical-device acceptance still require user testing.

Installed plugin builds without Garmin's newer connection_ready helper are
supported. The standalone adapter records only an owner/key-bound HMAC after a
successful existing plugin connection test; it does not store credentials again.
Replacing/removing the key or a failed test invalidates verification. The native
plugin verifier remains authoritative whenever available. Legacy installations
must verify once in Settings → Garmin Setup before standalone Garmin operations.

### Recorded workout editing

Calendar/log workout details include Edit Workout for owner-managed pool/open-water
swims. The standalone editor saves location/notes and three photo slots through
Workout::update_metadata, Workout::set_photos and WordPress media APIs. Recorded
distance and pool snapshots are not editable through this metadata form. Rebuild
and confirmed deletion use Importer::rebuild_workout and Workout::delete, including
the plugin's recalculation and preserved-source rules. Source-less manual workouts
receive the existing service's no-source explanation on rebuild.

Recorded-swim conversion calls Recorded_Workout_Structure::preview/confirm with
explicit confirmation and the current source hash. It creates a private
prescription and optionally schedules it through the plugin. Create Post requires
edit_posts and creates only an owner-authored draft with the existing workout block;
editing/publishing that post remains in WordPress. Post/media transport is adapted
because the equivalent plugin handlers are private or tied to admin redirects.
No plugin changes or direct writes to workout tables are introduced.

Public sharing now uses the plugin-owned Workout_Visibility service; the old
_standalone_workout_public_<workout_id> key is only a pending request. The app never directly
writes/removes that key and never adopts it automatically. Separate sharing POSTs
require the owner cookie/capabilities, exact origin, operation nonce, editor HMAC,
canonical nonce and strict PHP integer revision. A conflict disables actions until
explicit reload/review. Metadata/photo saves cannot change sharing.

Workout photos retain the existing WordPress attachment storage semantics; the
editor serves previews through authenticated owner-only application requests.


### Canonical public workout sharing

Tested plugin checkpoint: 702f2e51cb9ffbc67e54d12e25de9316dd0ee534
(1.3-dev, schema 1.5). Runtime feature detection checks every required visibility
method, installed schema version and Workout_Visibility::ready(). No app migration,
lifecycle command or provisional fallback runs. Missing services disable sharing;
public lookups fail closed with the same 404 as private/missing/unsupported records.
Installing files alone is insufficient: the normal plugin administrator upgrade must
complete before sharing is available. The test-server operator reports current
develop installed; CI is not an independent inspection of that server.

Owner controls are available from calendar/log details → Edit Workout (sharing-only
controls for running/walking). Make Workout Public reflects canonical public or
legacy_public, independently of effective_public. Share route publicly reflects
route_public and is cleared/disabled when making private. Neither operation enables
Public Results or route consent. Global controls are explained and linked to the
WordPress Swimmer Configuration screen. The pre-existing standalone preferences
adapter preserves the newer all-sport route setting when saving unrelated fields.
Explicit pending-request confirmation calls adopt_standalone_preference, with
routes off. Canonical saves consume pending preferences through plugin services.

Copy Public Link becomes available only after refreshed canonical effective_public.
Unsaved checkbox changes disable copying. Copy revalidates the editor state and
current canonical permission on an authenticated same-origin request; stale/revoked
grants cannot use that control. Clipboard failure selects the link for manual
copying with accessible feedback. Other applications/tabs require reload after
conflicts; no newer revision is silently substituted on retry.

Public URL: https://<configured-app-host>/?public_workout=<workout-id>.
This GET/HEAD-only route runs before protected dispatch and renders exclusively
Workout_Visibility::get_public. Owner/admin cookies do not bypass public policy.
Mixed public/management selectors never dispatch mutations. The public template is
separate from all owner templates and emits no management forms/nonces, notes,
photos, location labels, imports/paths, device/raw metadata, HR/cadence or health data.
Swimming, running and walking are labelled separately; this view shows one activity,
not mixed totals. No public history listing, totals, PB rankings or progression UI
is added. Eligible counts/pagination/PBs/progression remain in the canonical plugin
query_public/personal_bests/progression services and are covered by regression tests;
stored private-history PB flags are never displayed in this app's public page.

Only returned latitude/longitude segments can become SVG paths; gaps are independent
paths. A null route emits no geometry, JSON, hidden coordinates, preloads or map
requests. No tiles/provider receives routes. Route consent is independent: old
swimlog_public_ows_routes authorizes swimming only; swimlog_public_routes covers
supported sports. Cycling is deferred. Private calendar/measurement access is
unchanged; a public land projection grants no private-calendar or mutation access.

Every public response (including 404s) is private, no-store; public DTOs/geometry
are never stored in app transients or persistent caches. Pages restored from browser
back/forward caches reload policy and clear retained content. Upstream Apache,
reverse proxy/CDN and WordPress page caches MUST bypass all application HTML,
especially requests containing public_workout, and honor no-store. Static assets
may be cached. Purge pre-existing HTML caches when deploying this feature or
revoking content if any upstream cache previously ignored these directives.
Third-party social previews, screenshots, copied static posts and existing WordPress
media URLs cannot be recalled by dynamic revocation.

Sharing never creates/publishes a post. Existing explicit WordPress draft creation
requires its own capability/nonce and keeps the dynamic Swim Log block. Deletion
uses Workout::delete, which owns canonical/provisional cleanup. Canonical visibility
writes occur only in set_for_owner/adopt_standalone_preference. No plugin repository,
main/release, server or SVN changes are performed by this app work.

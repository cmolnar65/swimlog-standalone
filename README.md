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

Plan Workout is available from the Workouts menu and on today/future day cards.
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
Common social tracking parameters are ignored without rendering their values;
mixed public/management selectors never dispatch mutations. The public template is
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

## Evaluation → Personal Bests

The signed-in Evaluation menu opens the owner's private swimming Personal Bests
at `/?screen=bests`. It uses the installed plugin's Records, Evaluator and
Age_Group services for lifetime/current/historical age-group records, separate
meters/yards courses, and progression. Running and walking do not generate swim
performances; no land records or measurements are included. No public aggregate
page is added. Record links open existing owner-protected workout details with
keyboard and touch dismissal. Missing progression support fails gracefully while
retaining the bests screen; the current plugin's `Records::history()` contains a
`returnDatabase::read` typo requiring a separately approved plugin correction.

The WordPress Tools dropdown and its duplicate PB link have been removed.
Main Site links to the configured `wordpress_origin` root, without a hard-coded
hostname. Explicit WordPress links for functions not yet ported (such as PDF
training-plan import and post editing) remain available in their existing flows.
Earlier menu descriptions above record historical implementation checkpoints.

## Unlink recorded-workout associations

Owner-only recorded workout details (including calendar/log pop-ups) now show
separate confirmed Unlink planned workout and Unlink structured workout forms.
The planned workout page provides the corresponding actions, including detaching
its structured prescription after its recorded match has been removed. Both
records remain intact. Native Planned_Workout::clear_completed_workout returns
the plan to Planned; Planned_Structure_Link::detach preserves the immutable
recorded-history marker. A historical prescription stays locked even after both
associations are gone. Imported sources, performances, photos, sharing settings
and the prescription document are not rewritten by this adapter.

These operations require the actual cookie owner, results and workout-management
capabilities, exact origin, operation-specific nonce, explicit confirmation and a
fresh association HMAC. Competing standalone matches and structured edits use their existing owner locks;
other plugin interfaces retain their native boundaries. GET never unlinks, and
stale requests require reload/review. The installed plugin must expose
for_completed_workout and preserve_recorded_lock (tested against plugin develop
8aa564cc8935cd742a996815fdc92b8a37681656); older services fail closed. No plugin
or schema modification is made by this standalone change.

## Training Volume and planned-workout deletion

Evaluation → Training Volume (`/?screen=volume`) is a private owner report using
`Workout::stroke_volume_for_user()`. It matches the plugin's pool report: active,
positive-distance pool lengths only; separate meter/yard courses; stroke,
workout/length counts, native distance and active swim time; rolling 1–24 month
period. The saved swimmer preference supplies the default (otherwise 3 months).
Read-only filters do not change it. Rest, open water, running, walking and
measurements never enter this report. The plugin's existing management capability
is required. This page adds no public aggregate or separate calculations.

Delete Planned Workout is available on unmatched plan details/pop-ups and eligible
existing plan editors. A matched plan must first be unlinked. Deletion is a separate
confirmed POST with owner/capability/origin/nonce checks, fresh association HMAC,
and the existing match/structure owner locks. It uses native detach/delete services,
keeps recorded workouts and structured prescriptions, and preserves recorded-history
locks. It never deletes the underlying recorded workout. If deletion fails after a
successful detach, the plan remains and the user must reload/review the current
association state before retrying. No plugin/schema change or deployment is included.

## Evaluation → Training Trends and Pool Analytics

`/?screen=trends` calls `Training_Trends::for_user()` for current/previous rolling
period comparisons, frequency, recorded-week/month summaries, pool/open-water
breakdowns, and native course/stroke volume. Overall distance uses normalized
meters; course/stroke tables retain native units. The bar chart is presentation
of returned weekly values only: absent weeks are not filled or estimated. The
plugin owns its rolling-average definition and moving-time/elapsed fallback.
These reports do not infer physiological load, recovery, fitness or injury risk.

`/?screen=pool` calls `Pool_Analytics::for_user()` for recorded active pool lengths.
Course, pool length and stroke remain separate. Summary/monthly metrics include
pace and SWOLF, optional consistency, stroke counts/coverage and normalized
strokes per 25. Missing values stay missing; metric help explains limitations.
The app adapts the plugin's report presentation without executing wp-admin
handlers, loading admin/theme assets, or duplicating calculation logic.

Both pages require the actual cookie owner, existing results and workout-management
capabilities, valid installed schema/services, and strict 1–24 month filters.
They remain private/no-store and exclude running, walking and measurements.
Only Training Trends includes open-water swims. Filters do not write preferences.
Related-report links carry applicable period/course/stroke filters. Responsive
scroll regions and tables remain available beside the weekly visualization.
No plugin/schema change, public aggregate, release or deployment is included.


## Browser checks require explicit authorization

Automatic push and pull-request checks run syntax, PHP/WordPress integrations,
HTTP transport/security tests and compatibility regressions. They do not install
Playwright/browsers or run browser suites. Browser execution is restricted to a
manual workflow dispatch with `run_browser=true`; its default is false. Request
browser checks explicitly when they are needed. HTTP tests use direct requests
to a disposable PHP server and do not launch a browser. No live site is tested
or deployed by these checks.

### Swim Evaluation / Race Readiness

Evaluation → Swim Evaluation (`/?screen=readiness`) provides private event-specific swimming evidence using the installed plugin’s `Race_Readiness::for_event()` service. Select an upcoming/today’s event and a 1–365 calendar-day lookback (default 42). Event selection is paginated, with direct event-detail links into evaluation. Matching pool training, exact-distance recent performances, lifetime PB, recent changes and supporting swim details come from plugin services. Course units and strokes remain separate; land activities and measurements do not contribute. This is descriptive evidence, not a race prediction or readiness score. Past event results remain under Events.

The screen requires the authenticated owner’s results and workout-management capabilities, a completed plugin migration and the Race Readiness service. It adds no writes, schema changes or new calculation/storage system. All event/workout lookups remain owner-scoped; admin privileges do not expose another swimmer’s evaluation. Responses use the existing authenticated no-store policy. The synthetic integration/HTTP suites cover native evidence equivalence, course/stroke/window boundaries, pagination, invalid inputs, private/foreign access and missing migration. Browser checks remain explicit opt-in.

### Manual workout editing and PDF training plans

Existing manual pool workouts have an **Edit manual workout totals and sets** control on Edit Workout. This opens the authoritative plugin document and updates the same workout using `Manual_Workout::save(owner, document, id)`. Imported workouts cannot be converted to manual records. Exact millisecond timing is retained, measured repetitions remain distinct from estimated/interval timing, and the plugin regenerates normalized lengths and eligible performances. Owner locking and stale document/workout/sharing revisions prevent silent overwrites. Photos, associations, visibility and recorded identifiers are retained.

Workouts → Structured Workouts → **Import Training Plan PDF** now opens the standalone upload/preview/review workflow. The plugin must include its production Composer dependencies. Selectable-text PDFs up to 10 MB use the plugin extractor, parser, review and bulk-import services; scanned PDFs require OCR. Preview text is private, scoped to the authenticated owner/session, and expires after 24 hours. Review original instructions and flags, edit proposed structures, enter confirmed distances/units and explicitly approve selected workouts. Import creates a private collection and structured workouts, retaining review evidence and source fingerprint, without scheduling plans or recording swims. The plugin handles duplicate prevention and rollback; successful imports remove extracted text from the app preview. Truncated requests are rejected. PDF originals are never placed in the public directory.

Synthetic multipart HTTP tests exercise actual PDF extraction and review/import, invalid CSRF/origins, expired/stale forms, duplicate imports and manual edit conflicts. CI installs the plugin’s pinned production dependencies for this coverage. Browser checks remain opt-in. No standalone database migration or plugin modification is required.

### Compare two pool swims

Workout Log includes selection checkboxes for pool swims and enables **Compare** for exactly two selections on the current page. Selection resets when changing filters/pages; use the date/type filters to find the two sessions together. The authenticated read-only comparison URL is `/?screen=compare&compare[]=ID_A&compare[]=ID_B`. Every request validates both records against the current owner; public visibility and manage-all privileges do not grant access.

The comparison uses `Workout::get_for_user()`, `Workout::lengths()` and the plugin’s public `Pool_Analytics::aggregate_rows()` service. No separate metric engine, database writes or plugin changes are introduced. Stroke tables show native-course pace per 100, SWOLF, strokes per length, workload and actual sample coverage; rest and zero-distance rows follow the native exclusion rules, and missing measurements stay missing. SVG paired bars accompany exact values and B-minus-A differences. Course mismatch suppresses pace deltas/bars; different or unknown pool lengths suppress efficiency deltas/bars. Workload/stroke-mix differences are flagged without inventing a winner or readiness score. Unknown course/pool metadata cannot provide reliable grouped metrics. Private notes, photos, GPS, measurements and raw source/device data are excluded from the comparison model.

Synthetic PHP/HTTP tests cover metrics, missing data, mismatches, privacy, validation, owner/admin isolation and read-only routing. A lightweight Node DOM stub tests selection behavior without running a browser. Browser checks remain explicitly opt-in; phone/desktop styles use stacked summaries and responsive charts with scrollable exact-value tables.

### Compare Personal Bests

Evaluation → **Compare Personal Bests** (`/?screen=compare_bests`) compares two independent periods from your own pool-swim history: calendar year, calendar month, inclusive custom dates, or inclusive chronological age ranges. Age uses the plugin’s `Age_Group::for_user()` on each workout’s local date and requires a valid configured birth date. Date comparisons still work without it. Future workouts are excluded.

The adapter reads owner-scoped workouts and their existing plugin-evaluated performances, selecting the fastest within each period rather than using lifetime PB flags. It does not manufacture candidates or recalculate eligibility. Matches use exact distance, stroke and selected native course; meters and yards remain separate. Ties use earliest swim date then performance ID. Missing results remain visible; paired SVG charts, signed time/percentage changes and owner-only supporting swim links appear when both groups have a result. Overlapping periods can share a swim. Notes, photos, birth date, routes, measurements and land data are excluded from the result model.

Reads are bounded to 5,000 matching pool workouts and 100,000 inspected performance records; over-limit requests fail explicitly instead of showing partial bests. No database writes or plugin changes are required. This reports bests among currently evaluated records; missing evaluation records must be rebuilt through existing plugin tools. Non-browser regression coverage includes birthday boundaries, years/months/date ranges, native courses, ties, missing results, no birth date, owner/admin isolation and unchanged metadata.

### Pace consistency and repeat-set analysis

Evaluation now includes **Pace Consistency** (`/?screen=pace`) and **Repeat-set Analysis** (`/?screen=repeats`). Select a pool swim with the paginated chooser, or follow the analysis links on recorded pool workout details. Both are private, read-only, owner-scoped reports; public visibility and manage-all privileges do not authorize them.

Pace Consistency uses the plugin’s `Pool_Analytics::aggregate_rows()` for stroke-specific active-length pace, fastest pace, timed coverage and population coefficient of variation. Recorded-order charts and exact tables expose timed samples; untimed lengths remain missing, and rest/zero-distance rows are excluded by native rules. Manual measured repetitions are not split into fabricated length times.

Repeat-set Analysis uses explicitly measured repetitions from the authoritative manual document. Send-offs, derived times, untimed and measured-whole-set segments are not invented repetition times. Imported candidates group consecutive equal-distance (rounded to three native decimals), same-known-stroke laps with the same timing basis; recorded zero-distance rest laps may intervene. They are labeled candidates, not confirmed prescribed sets. Moving lap time is used when recorded; otherwise elapsed lap time is explicitly identified as potentially including in-lap rest. Missing times/rest stay unknown. A continuous lap is never split into guessed repeats. Native aggregation supplies repeat pace and CV; the report shows recorded fastest/slowest times and first-to-last difference without diagnosing fatigue.

No plugin or database schema changes are required. The reports inspect at most 10,000 lengths and 2,000 laps per swim, exclude land/measurements and private notes/raw metadata, and use existing no-store responses. SVG charts scale with the existing mobile layout; exact tables remain scrollable. Synthetic integration/HTTP coverage tests native CV, rest/missing data, measured versus send-off timing, private source projection, owner/admin isolation and read-only access. Browser checks remain opt-in.

### Efficiency trends and planned versus completed

Evaluation → **Efficiency Trends** (`/?screen=efficiency`) uses the plugin’s monthly `Pool_Analytics::for_user()` buckets through the existing report adapter. Choose 1–24 months, course and stroke. Pace, SWOLF and strokes-per-length have separate charts and exact monthly tables with metric-specific sample counts. Strokes, courses and pool lengths are never pooled. Missing metrics/calendar months break chart lines; zero or missing measurements are not invented. The current month can be partial, and reduced SWOLF alone does not prove improved technique. Existing report capabilities apply.

Evaluation → **Planned versus Completed** (`/?screen=plan_evaluation`) selects an owner’s swimming plan, with a paginated chooser and a direct link from plan details. It compares only the explicit matched recorded swim, including open-water summary totals; it never guesses a match. Plan distance and duration are compared with recorded distance and elapsed time; recorded moving time remains separate. Linked structured totals and stroke distances use the native structure summary, with meter conversion stated explicitly. Recorded pool stroke distances come from native active-length analytics; unknown evidence remains unknown. Manual documented repetition counts and imported positive-distance lap counts are labeled separately from prescribed expanded steps. No one-to-one alignment, automatic compliance grade, pace-target success or drill/equipment compliance is inferred.

Free-text instructions are not reparsed into guessed targets. Plan and current prescription totals are shown separately if inconsistent; the current linked prescription is not presented as a new historical snapshot. Missing/unmatched records have explicit states; foreign/corrupt associations fail closed. Reports do not change matches, prescriptions, history locks, notes, photos or visibility. Session reads are bounded to 10,000 lengths/2,000 laps and retain authenticated no-store headers. No plugin/schema changes are required. Synthetic integration/HTTP coverage verifies metric equivalence, monthly gaps, unit conversion, match/unmatch behavior, missing targets, private-data projection and owner/admin isolation. Browser checks remain opt-in.

### Training Balance

Evaluation → **Training Balance** (`/?screen=balance`) provides a private 1–24 month swimming distribution report. Pool/open-water session counts and known summary-distance shares use native Training Trends calculations, with missing-distance coverage shown separately. Native active-length stroke volumes retain meter/yard course separation and unknown strokes; they have a different denominator from workout summaries. Land activities, measurements, rest and zero-distance lengths do not contribute to stroke volumes.

Separate source-distance profiles show documented manual repetition distances and positive-distance imported lap distances in native units: up to 100, over 100 through 400, and over 400 (rounded to three decimals). These descriptive bands are not training zones, evidence of prescribed sets, or proof of continuous swimming. Untimed manual repetitions still provide distance evidence. Missing source breakdowns remain unclassified. Profiles, active-length totals and summaries are not silently reconciled. SVG share charts accompany exact counts, distances, denominators and missing-data explanations. No ideal-balance, fitness or recovery score is invented.

The report reuses the authenticated owner-only analytics permissions, WordPress data and no-store policy. It adds no writes, plugin changes or schema changes. Source inspection is bounded to 1,000 swims, 2,000 laps per workout and 50,000 total laps/repetitions; over-limit reports fail explicitly instead of showing partial distributions. Synthetic PHP/HTTP tests cover native metric equivalence, source bins, untimed manual evidence, missing distances, zero/rest/future/land exclusions, privacy, capabilities and owner/admin isolation. Browser checks remain opt-in.

### PB progression compatibility

The PB page now requires actual chronological improvement rows in its regression tests. Plugin checkpoint `8aa564cc8935cd742a996815fdc92b8a37681656` has a typo in both `Records::history()` branches (`returnDatabase::read`), causing native progression to throw. The standalone app tries the native service first and uses a narrowly scoped compatibility reader only for that known error. It reuses owner-scoped `Workout::query_for_user()` / `Workout::performances()` reads of existing evaluated pool performances, filters exact distance/stroke/course, sorts by achievement timestamp then performance ID and displays strict improvements. It never recalculates eligibility, uses lifetime PB flags as history, writes tables or changes installed plugin files. Equal and slower swims are omitted; course, land and owner boundaries remain enforced.

The compatibility reader fails explicitly over 5,000 pool workouts or 100,000 inspected performances rather than presenting incomplete progression. Unrelated native failures are reported rather than silently swallowed. Workout links retain the progression filters. Plugin-side cleanup remains a separate change: correct both typo occurrences to `return Database::read`. No plugin installation is required for the standalone compatibility fix.

### Chart point tooltips

Pace Consistency and Repeat-set Analysis points now show the swim date, length or repetition/lap number, and pace per 100 native course units. The date is the workout’s local date, not a guessed per-length timestamp. All Efficiency Trends metrics show the recorded month (monthly aggregate, not an invented day), metric value, stroke/pool context and metric-specific sample coverage.

Hover, keyboard focus or tap shows an accessible tooltip; tap again/outside or Escape closes it. Tooltips reposition inside the viewport and close on scrolling/resizing. Values come from the already-authorized page without new requests or storage. Native SVG titles remain a no-JavaScript fallback, and existing exact-value tables remain available. DOM-stub and HTTP tests cover tooltip behavior and rendered values without running browser checks.

### Private calendar measurements

The standalone calendar now loads the plugin’s separate `Calendar_Measurements::for_user()` projection after workouts, plans and events. A compact **Private measurements** entry opens readings, provider summaries, labeled aggregates and verified sleep periods in the full day view. The same data is available in month/week calendars and the non-JavaScript `day` fallback. Existing day overflow and tap-off/close controls remain in use.

Measurement access requires the actual owner’s valid WordPress login cookie, results capability and workout-management capability, matching the plugin service. Manage-all access and public workout sharing do not grant measurement access. No REST opt-in is broadened, new database writes are added or raw health rows are read by the app. The display uses only the canonical projection, removes reading IDs/provenance and keeps source-calendar dates, UTC/local time labels, provider averages, conflicts and unknown times distinct. Hours slept use the plugin’s verified UTC-derived duration, including DST boundaries. Failures leave workouts visible with a private measurement status message. Authenticated no-store headers cover the page. Synthetic PHP/HTTP tests cover owner/capability isolation, month/week/day views, source-date placement, conflicts, averages, privacy and derived sleep. Browser checks remain opt-in.

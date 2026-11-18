# mai-analytics alignment

- Date: 2026-11-18
- Status: Checked by mai-coordinator (2026-11-18). It decided four of the five open questions (see "Decided by mai-coordinator"). The code names are still Mike's, in his walk with mai-coordinator.
- Scope: mai-analytics' part of the 2026-11-18 alignment pass (Mike's option "B": the safe fixes and plugin error codes). Fleet rules: `~/LocalPackages/maiexpowp/CONVENTIONS.md`. Coordinator's file: `ALIGNMENT.md` on the shared production-copy site.
- Code read: `develop` at 243b90d, version 1.3.7, db version 1.0.2, `Requires PHP: 8.1` (`mai-analytics.php:7-8`, `:26-27`). Core cited from the shared test site's WordPress 7.1.4 (`wp-includes/version.php:19`).
- Release: one point release after 1.3.7. No version, tag or release is decided here.

## What "fixed" means on a site running Mai Publisher

Mai Publisher bundles mai-analytics (1.3.7 today) and loads its own copy whenever no standalone mai-analytics is active (`mai-publisher.php:128-132`). So a site running only the bundled copy gets nothing from this release until Mai Publisher updates its bundled version and ships. Mai Publisher's releases are David's, not ours.

Even after that update, the bundled copy's activation, deactivation and uninstall hooks never fire, because WordPress registers them against a vendor file it never activates or uninstalls. On those sites:

- **Can never reach them through the bundled copy:** the cron cleanup on deactivation and uninstall (change 1), activation scheduling the daily prune (change 1), the uninstall wildcard fix and everything else in `uninstall.php`.
- **Reaches them once Mai Publisher ships the new bundled version:** everything that runs while the plugin loads. That is the db version check (change 3), the hidden-item fix (change 4), the quiet limits (change 5), the em-dash rewrites (change 6), the 100-ID limits and the error codes (changes 2 and 7). It is the whole REST layer and the schema check.

A standalone install gets all of it on update.

## Rules this follows

Already decided, so not asked again:

- Nothing a released plugin exposes is renamed: no filter, option, meta key, route, param or response field. Changes only tighten or add.
- `mai_views` and `mai_trending` stay (Mike).
- A bounded input over its limit is refused with a 400, never clamped. An ID list takes at most 100 IDs.
- Error codes start with `mai_analytics_`. Core's argument errors (`rest_invalid_param`, `rest_missing_callback_param`, `rest_invalid_json`, `rest_invalid_params`) become 400 `mai_analytics_invalid_request`, and a caller who fails the route's permission still gets the permission error first.
- Every 429 and 503 sends `Retry-After`.
- Deactivation clears cron and keeps data. Uninstall removes everything the plugin made.
- The db version is recorded only after checking the table exists.

Not changing: the table shape (`object_key`, `viewed_at`), no rate limit, no `Cache-Control` on public count routes, singular `POST /view` against plural `GET /views`, no per-person data. Nothing here blocks per-person views for "For You" later: no change touches the table, the view POST's body or the content-client rule.

## Who calls the plugin

Read in full before deciding what is safe.

- **Website beacon** (`src/Tracker.php:31-36`, URL from `:75-136`). Sends `navigator.sendBeacon(url)` with no body, so `source` is `web` by default. Reads nothing back. Any status or body is ignored.
- **Admin dashboard** (`assets/js/admin-dashboard.js`). Every call goes through `apiFetch()` (`:853-861`): `GET` with `X-WP-Nonce` to `admin/top/{posts,terms,authors,archives}`, `admin/filters` and `admin/search`. On success it reads `items`, `total`, `pages`, `totals` (`:272-283`), `post_types`, `taxonomies` (`:225-230`), and the bare `[{ id, name }]` array from search (`:686`, `:695`). On any non-2xx it reads only `response.status`, throws, and shows "Could not load the data. Reload the page to try again." (`:284-291`), or an empty dropdown for search (`:687`). It never reads an error body. It builds `term_id` and `author` by joining every picked ID (`:253-254`), and Tom Select has `maxItems: null` (`:661`), so the picks have no limit.
- **Admin settings** (`assets/js/admin-settings.js`).
  - Sync Now (`bindButton`, `:11`, `:55-87`): `POST admin/sync-now`, no body. Reads the JSON body whatever the status. Shows `data.message` (or "Done."), red when `!data.message || data.code` or the message matches `/error|can't access|not available|not found|failed/i` (`:77`). So a body with a `code` already reads as an error, and the status is never checked.
  - Warm Stats (`:137-200`): `POST admin/warm` with JSON `{ cursor, total_updated, total_iterated, force }`. When `!res.ok` it shows `data.message` or "Warm failed." and stops (`:153-158`). On success it reads `total_updated`, `total_iterated`, `done`, `message`, `total`, `batch`, `next_cursor`.
  - Health (`:218-235`): `POST admin/health`. Reads `checks`, `pass`, `fail`, `warn`. No error handling beyond "Request failed."
  - Dismiss error (inline script printed by `src/Admin.php:362-375` onward): `POST admin/dismiss-error`.
- **The app, through maiexpowp's `useView`** (`~/LocalPackages/maiexpowp/views/useView.ts:61-65`). `POST mai-analytics/v1/view/{post|term|user|post_type}/{id}` with JSON `{ source: 'app' }`, through the content client, `quiet: true`, no retry (it does not use `withRetry`). On failure it only asks `isNoRoute()`, which is true for status 404 with code `rest_no_route` (`api/errors.ts:52-53`); then it turns views off for the session. Every other error is dropped. `toMaiApiError()` reads `code`, `message` and `data.retry_after` from a body that has a string `code` (`api/errors.ts:86-99`), and calls anything else `unknown`. The app never calls `GET /views/*` or `/views/trending` (`rg "views/|trending"` in maiexpowp finds only test imports). The mock answers `{ success: true }` for every view and `rest_no_route` for anything else (`api/mock/views.ts:14-17`). The tests pin: a 500 is sent once and stays quiet, a 404 `{ success: false }` stays quiet and does not turn views off, `rest_no_route` turns views off (`__tests__/views/useView.test.tsx:170-219`).
- **Mai Publisher.** Its analytics tracker block (`~/Plugins/mai-publisher/blocks/analytics-tracker/block.php`) only adds Matomo content-tracking attributes to its inner blocks (`:56-66`), and its script is editor-only (`src/js/mai-analytics-tracker.js`, 51 lines, no request). It never calls mai-analytics' REST routes. Mai Publisher does three other things with mai-analytics:
  - It bundles mai-analytics 1.3.7 through Composer (`composer.json:22` `^1.2`, `vendor/composer/installed.php:31-33`) and loads the bundled copy on `plugins_loaded` priority 0 when no standalone copy is active (`mai-publisher.php:128-132`).
  - Its `/v1/seller` endpoint reads `\Mai\Analytics\Settings::get_reporting_snapshot()`, including `sync_interval` as `views_interval` (`classes/class-endpoint.php:69-78`).
  - It reads `mai_views` and `mai_trending` meta (`classes/class-compatibility.php:39-58`, `classes/class-author-views.php:12-19`).
- **Unknown third parties.** `GET /views/*` and `/views/trending` are public. Theme or site code may read them. Nothing in this stack does.

## Changes

Each change says what the code does today, what it will do, who notices, the test that pins it, and whether it is safe for a live plugin.

### 1. Cron cleanup

- **Today:** four hooks exist. `mai_analytics_cron_sync` (recurring, `mai-analytics.php:58-60`, `src/Cron.php:56-58`), `mai_analytics_provider_catchup` (single, `src/ProviderSync.php:402-406`), `mai_analytics_prune_trending` (recurring, `src/Cron.php:116-128`), `mai_analytics_prune_trending_now` (single, `src/Cron.php:210`, `src/Upgrade.php:33-35`). Deactivation clears only the first (`mai-analytics.php:64-66`). Uninstall clears the first two (`uninstall.php:26-27`). The two prune hooks are never cleared, which is what left stray events on the shared test site. Verified.
- **Will do:** `Cron::HOOKS` lists all four. Deactivation clears every hook in it. `uninstall.php` clears the same four, written out by name, because WordPress includes only `uninstall.php` at uninstall and never loads the plugin or its autoloader (`wp-admin/includes/plugin.php:1323-1333`). Activation also calls `Cron::schedule_daily_prune()`, so a reactivated site gets its daily prune back without waiting for an admin page view. Data stays on deactivation.
- **Who notices:** nobody, except that `wp cron event list` is clean after deactivation.
- **Tests:** deactivation leaves none of `Cron::HOOKS` scheduled. Uninstall (run `uninstall.php` with `WP_UNINSTALL_PLUGIN` defined) leaves none scheduled. A test fails if `Cron::HOOKS` and the list in `uninstall.php` differ, by scheduling every hook in `Cron::HOOKS`, running uninstall, and asserting all are gone.
- **Safe:** yes. Clearing a stray event changes nothing a caller receives.
- **Limit:** when mai-analytics runs as Mai Publisher's bundled copy, its activation, deactivation and uninstall hooks never fire, because they are registered against a vendor file WordPress never activates. Fixing that is Mai Publisher's work (see "Bugs that are not alignment").

### 2. 100-ID limits

- **Today:** three REST ID lists have no bound and are never refused: trending `terms` (`src/RestApi.php:115-118`, split at `:324`), admin `term_id` and `author` (`src/AdminRestApi.php:63-70`, split at `:274-275`). Verified. CLI `warm --ids` has no bound either (`src/CLI.php:620-622`).
- **Will do:** each REST list gets a `validate_callback` that counts the comma-separated entries and returns `false` over 100, so core answers `rest_invalid_param` and change 7 maps it to 400 `mai_analytics_invalid_request`. 100 passes. How each entry is parsed stays as it is (`absint`, zeros dropped), because refusing a stray non-number would break a caller that works today and is not part of the agreed rule. The admin dashboard sets Tom Select's `maxItems` to 100 for terms and authors, so its own UI can never build a 101-ID request.
- **CLI `warm --ids`:** left unbounded. The 100-ID rule is a REST rule (CONVENTIONS "Lists": "A batch read of several items takes at most 100 at once"). `warm` is an operator command that already batches by the provider's batch size (`src/Providers/Matomo.php:34-36`, `SiteKit.php:82-84`, `Jetpack.php:49-51`), and a hard 100 would only make a long warm take several commands.
- **Who notices:** a caller sending 101 or more IDs, which today works and from now gets a 400. Nobody in the stack does: the dashboard is capped by the same change, and the app and Mai Publisher never send these params.
- **Tests:** 100 `terms`, `term_id` and `author` IDs answer 200. 101 answer 400 `mai_analytics_invalid_request` with `data.params` naming the arg. Signed out, 101 `term_id` IDs answer 401 `rest_forbidden` (change 7).
- **Safe:** yes for every known caller. It is the agreed rule.

### 3. db version only after the table exists

- **Today:** `Database::create_table()` calls `dbDelta()` and then `update_option( 'mai_analytics_db_version', ... )` with no check (`src/Database.php:43-46`). A failed `CREATE TABLE` still records the current version, so `maybe_update()` (`:54-62`) never tries again and every view insert fails quietly. Also, `maybe_migrate_old_table()` copies the old `mai_views_db_version` into the new option without checking that the rename worked (`:114-120`). Verified.
- **Will do:** after `dbDelta()`, `create_table()` checks the table with `SHOW TABLES LIKE` and records the version only when it is there. When it is not, it logs an error through `mai_analytics_logger()` and sets the transient `mai_analytics_db_retry` for one hour, and `maybe_update()` skips `create_table()` while it is set, so a broken database is not hit with `dbDelta()` on every page load. The old-version copy runs only when the new table exists. `create_table()` returns `bool` so activation and `health --fix` can say whether it worked.
- **Who notices:** nobody on a healthy site. On a site whose table could not be made, the plugin tries again each hour instead of never.
- **Tests:** with the table creation forced to fail (drop the table and make `dbDelta()` a no-op through the `dbdelta_queries` filter returning `[]`), `create_table()` returns `false`, the option is not written, and the transient is set. With it working, the option equals `MAI_ANALYTICS_DB_VERSION`.
- **Safe:** yes. One new transient name, covered by uninstall's transient delete.

### 4. Counts for items a caller may not see

- **Today:** `GET /views/{post|term|user}/{id}` reads the meta by ID with no check (`src/RestApi.php:182-203`), so a signed-out caller gets the counts of a draft, private, pending, trashed or deleted post, a term in a private taxonomy, or a missing user (zeros for the last). `POST /view/...` checks first (`:142`, `validate_object()` at `:420-437`). Verified. `GET /views/post_type/{post_type}` also has no public check (`:259-269`); a private post type has no stored count, because the POST refuses it (`:220`), so it answers zeros.
- **Found in the same area:** `GET /views/trending` lists buffer rows from the last 6 hours to 7 days with each post's title and permalink (`:278-343`, `:373-376`). A post viewed while public and then made private, draft or trashed stays listed until its rows age out, with "Private: {title}" and its link. Deleted posts show an empty title. Terms moved to a private taxonomy and deleted users are listed too.
- **Found in the same area: user IDs.** For users, `validate_object()` only asks whether the user exists (`:434`). Once a hidden item answers 404, `GET /views/user/{id}` would tell a signed-out caller which user IDs exist, including people with no published posts, whom core's own `wp/v2/users` hides from signed-out callers. `POST /view/user/{id}` already tells them today (404 for a missing user, 200 for one who exists). So a user is visible only when they have a published post in a public post type, checked with `count_user_posts( $id, get_post_types( [ 'public' => true ] ), true ) > 0`. That is close to core's rule, not the same: core's `wp/v2/users` shows signed-out callers authors with a published post in a post type shown in REST (`wp-includes/rest-api/endpoints/class-wp-rest-users-controller.php:322`), and this uses public post types, to match the rest of the plugin. This changes `validate_object()`, so it applies to the POST and trending too. Decided by mai-coordinator as part of the 404 ruling. Mike is told and can reopen it.
- **The one case this stops counting: a profile view for a member with no published posts.** WordPress shows an author archive, not a 404, for any member of the site even with no posts (`wp-includes/class-wp.php:787-795`), and the beacon posts a view from every author archive (`src/Tracker.php:125-133`). From this release those views are refused with 404 and not counted, and that member's existing count is no longer readable from `GET /views/user/{id}`. A site that uses author archives as member profiles, for people who don't publish, loses those counts. Their stored meta stays and still shows in the admin dashboard. The member is counted again from their first published post.
- **Core already has the visibility check, for posts, terms and post types.** `validate_object()` writes its own rule: status `publish` and a post type registered `public`, or a term in a `public` taxonomy (`src/RestApi.php:420-437`). The tracker uses the same `public` test (`src/Tracker.php:84`, `:100`, `:117`). Core's own words for "may a signed-out visitor see this" are `is_post_publicly_viewable()` (`wp-includes/post.php:2542`, which combines `is_post_type_viewable()` at `:2447` and `is_post_status_viewable()` at `:2491`), `is_term_publicly_viewable()` (`wp-includes/taxonomy.php:5168`) and `is_post_type_viewable()`. Proposed: use those in `validate_object()`, the post type check and the tracker, so the beacon, the POST and the GETs agree with core. Core has no such helper for users, so the user rule above stays. `is_term_publicly_viewable()` reads `->taxonomy` without checking for a `WP_Error` from `get_term()` (`taxonomy.php:5169-5175`), so the plugin checks `is_wp_error()` first. They differ from today's rule only on unusual registrations, and each difference changes what a live site counts, so this goes to mai-coordinator and Mike:
  - A post status registered `publicly_queryable` (a custom status) would be counted. Today only `publish` is.
  - A post type or taxonomy registered `public: false` but `publicly_queryable: true` would be counted. Today it is not.
  - A post type or taxonomy registered `public: true` but `publicly_queryable: false` would no longer be counted. Such a type has no front-end page of its own, so the beacon rarely fires for it.
  - Built-in types and statuses (`post`, `page`, `publish`, `private`, `draft`) give the same answer either way.
- **Other count reads, checked:** the `[mai_views]` shortcode and `mai_analytics_get_count()` (`includes/functions.php:47-130`, `:146-195`) read any ID with no visibility check, but they run in PHP, so only someone who can put a shortcode in published content or write theme code can call them. That is the site's own staff, not a signed-out caller. Left as they are and noted in `TODO.md`. The meta registered with `show_in_rest` (`src/Meta.php:20-34`) is only returned by core's `wp/v2` routes for items the caller may read, because those routes check read access before they return meta. Not a leak.
- **Will do:** the single-item GETs use the same visibility rule as the POST, `validate_object()`, and the post type GET uses the POST's public post type check. An item that fails answers 404 `mai_analytics_not_found` (decided by mai-coordinator: CONVENTIONS says 404 is "the item doesn't exist or isn't visible", the POST already answers it, and a missing and a hidden item answer the same). Trending drops every row that fails `validate_object()`, and always over-fetches five times `per_page` (today only when `taxonomy` is set, `:288`) before cutting to `per_page`, so dropped rows rarely leave the list short. No signed-in exception: an editor sees counts for unpublished items in the dashboard, and one rule for everyone keeps the public answer the same for every caller, which matters because these routes send no `Cache-Control`.
- **Who notices:** a caller asking for a hidden item. Nobody in the stack calls these GETs except the plugin's own health check, which asks about a published post (`src/Health.php:139-152`). A third party listing trending sees hidden items drop out.
- **Tests:** signed out, a draft, a private post, a trashed post, a post in a non-public type, a term in a non-public taxonomy, a missing user and a user with no published posts each answer 404 `mai_analytics_not_found` with `success: false`, and the same for a signed-in editor. A post type that is not public answers the same. A published post answers its counts. Trending with a viewed post that is then made private does not list it, and still returns `per_page` rows when enough visible ones exist.
- **Safe:** it closes a leak. A caller that asks for a deleted or unpublished item and expected 200 zeros now gets a 404. No caller in the stack does that. The one view a live site stops counting is a member profile with no published posts, described above.

### 5. Quiet limits

- **`/admin/search` cuts at 50 with no word.** Today both queries end in `LIMIT 50` (`src/AdminRestApi.php:755`, `:780`), the body is a bare array, and the docblock says "Up to 20" (`:724`). Verified. It will take `page` (default 1) and `per_page` (default 50, at most 100, refused over 100), keep the bare array body, and send `X-WP-Total` and `X-WP-TotalPages`, as CONVENTIONS "Lists" asks. The dashboard sends neither param, so it still gets the first 50. The docblock is fixed. Tests: `per_page=101` answers 400; 60 matching terms answer 50 rows with `X-WP-Total: 60` and `X-WP-TotalPages: 2`; `page=2` answers the last 10. Safe: the body is unchanged, headers only add.
- **`/admin/warm` declares `minimum: 0` that nothing enforces.** Today `cursor`, `total_updated` and `total_iterated` set `type`, `minimum` and `sanitize_callback => absint` but no `validate_callback` (`src/AdminRestApi.php:173-190`). Core only validates an arg that has a `validate_callback` (`class-wp-rest-request.php:946`), so `absint` turns `-5` into `5`. Verified. Each gets `validate_callback => rest_validate_request_arg`, so a negative value answers 400 `mai_analytics_invalid_request`. The settings page only sends values the server gave it (`assets/js/admin-settings.js:146-150`, `:190`), never negative. Test: `cursor=-1` answers 400. Safe.

### 6. Em-dashes in text a person reads

Mike allows none in anything a person reads. Verified in UI text, and more found in log lines and the README:

- **Admin and CLI text:** the dash before the provider error notice's age (`src/Admin.php:359`); the Site Kit module error (`src/Providers/SiteKit.php:404`, a translatable string); the Matomo error joiner, stored and shown in the notice (`src/Providers/Matomo.php:332`); the CLI health detail joiner (`src/CLI.php:60`).
- **Log lines** (read by a person in the log): `src/ProviderSync.php:200`, `:667`; `src/Stats.php:420`, `:422`, `:462`.
- **README.md:** 30 lines (`rg -n '\x{2014}' README.md`).

Each sentence is rewritten, not re-punctuated. For example the notice becomes "Provider error: {message} ({age})" and the Site Kit message becomes "Could not build the Site Kit Analytics module. Check for a Mai Analytics update. Details: %s". Code comments are left alone. Earlier `CHANGES.md` entries are history and stay. Test: a test fails if `rg '\x{2014}'` finds the glyph (U+2014) in any string outside a comment in `src/` and `includes/`. Safe: the only translatable string that changes is the Site Kit one, and the plugin ships no translations.

### 7. Error codes

#### What answers today

Every error the plugin can answer, from the code:

- **View routes** (`src/RestApi.php`), bodies built by hand:
  - `{ "success": false }` 400 when the route has no type (`:138`, `:187`). Unreachable, since every route has one.
  - `{ "success": false }` 404 when the item is missing or not public (`:143`, `:221`).
  - `{ "success": false }` 403 when bot filtering is on in self-hosted mode and the user agent matches or is empty (`:153`, `:230`).
  - `{ "success": true }` 200 when the insert failed. `Database::insert_view()` returns `false` (`src/Database.php:135-148`) and both POSTs ignore it (`:162`, `:166`, `:239`, `:243`). Found in this pass.
- **Admin routes** (`src/AdminRestApi.php`):
  - `{ "message": reason }` 500 from `sync-now` when no provider is set up or it is unavailable (`:808-814`).
  - `{ "message": error }` 500 from `sync-now` when the test call or the sync stores a provider error (`:820-824`, `:838-842`).
  - `{ "message": "Warm is only available in provider mode." }` 400 from `warm` in self-hosted mode (`:882-884`).
  - The progress payload plus `message`, 500, from `warm` when a provider error is on file (`:899-911`, `:936-941`).
- **From core, passed through:**
  - `rest_forbidden` 401 signed out or 403 signed in, from every admin route's permission callback (`class-wp-rest-server.php:1259-1270`).
  - `rest_invalid_param` 400 from a failed `validate_callback` (`class-wp-rest-request.php:946-969`).
  - `rest_missing_callback_param` 400 when `/admin/search` has no `type`.
  - `rest_invalid_json` 400 for a malformed JSON body on any POST.
  - `rest_no_route` 404 when a path segment fails the route pattern, such as `/view/post/abc`.

No 409, 429 or 503 exists today. The plugin registers no error filter.

#### What will answer

Every plugin error body is `{ "code", "message", "data": { "status" } }`, the shape core gives a `WP_Error` (`rest_convert_error_to_response()`, reached from `class-wp-rest-server.php:213-215`) and the shape maiexpowp reads. Fields callers read today stay:

- View route errors keep `"success": false` at the top level.
- Admin errors keep `message` at the top level, which the core shape already has.
- The warm error keeps its progress fields (`done`, `total_updated`, `total_iterated`, and the batch fields when present) at the top level.

So the view and warm errors are built as a `WP_REST_Response` with that body and status, not a `WP_Error`, because core's conversion would drop the extra fields. One helper, `Errors::response( ErrorCode $code, string $message, array $data = [], array $legacy = [] )`, builds them, with `ErrorCode` an enum of the codes below (the pattern mai-collections uses in `inc/classes/ErrorCode.php`).

The new codes. Names are Mike's to approve (see "Questions for Mike"):

- 400 `mai_analytics_invalid_request`: core's four argument errors (below), a list over 100, and the unreachable no-type case.
- 404 `mai_analytics_not_found`: a view POST or a single-item GET for a missing or hidden item.
- 403 `mai_analytics_bot`: a view refused as a bot.
- 409 `mai_analytics_provider_unavailable`: `sync-now` with no provider set up or the provider unavailable. Today 500. It is a setup problem, not a server fault, and 409 says "fix the state, then try again".
- 409 `mai_analytics_provider_mode_required`: `warm` in self-hosted mode. Today 400.
- 502 `mai_analytics_provider_error`: `sync-now` and `warm` when the provider call failed. Today 500. The fault is upstream, which is what 502 means.
- 503 `mai_analytics_database_error` with `data.retry_after` 1 and a `Retry-After: 1` header (1 second, as mai-collections sends): a view POST whose insert failed. Today 200 `{ "success": true }`. Decided by mai-coordinator: it is the fleet's rule for a database failure.
- Unchanged: `rest_forbidden` from the admin permission callbacks, as core builds it (mai-items-core's spec does the same).

The 409, the 502 and keeping `rest_forbidden` follow rules mai-coordinator is adding to CONVENTIONS.md in this pass, not rules that exist there today: 409 `{slug}_...` for a request refused because of the site's current setup, fixed by changing a setting and trying again, never retried by a client; 502 `{slug}_provider_error` for a failure in a service the plugin called; and capability-guarded admin routes keep core's `rest_forbidden`, with `{slug}_login_required` only for the gated tap. `rest_no_route`, which core answers before any plugin code runs, and which maiexpowp relies on to switch views off.

#### Core's argument errors, with permission first

Core validates args before it asks the permission callback (`class-wp-rest-server.php:1114-1124`, then `:1256-1260`), so today a signed-out admin request with `per_page=500` gets 400, not 401. `RestErrors::map()` adds one `rest_request_before_callbacks` filter at priority 99. For a route under `mai-analytics/v1` (matched without case, as core matches routes) whose response so far is a `WP_Error` with a code in `RestErrors::CORE_CODES`:

1. It calls the route's permission callback once. A `WP_Error` is returned as it is. `false` becomes core's own `rest_forbidden` with `rest_authorization_required_code()`, built as core builds it (`class-wp-rest-server.php:1262-1270`). Core then skips its own check because the response is already an error, so the callback runs once either way. mai-analytics' callbacks return `bool`, so this step matters here; mai-collections' `Rest::own_errors()` only handles a `WP_Error` (`inc/classes/Rest.php:363-379`).
2. If permission passes, the error becomes 400 `mai_analytics_invalid_request`, keeping core's message, `params` and `details`.

Every other error passes through. View routes are public (`__return_true`), so there step 1 always passes.

#### Copied here, not waiting for mai-items-core

The mapping is copied into mai-analytics as `src/RestErrors.php` (`Mai\Analytics\RestErrors`), with the same `CORE_CODES`, method name and steps as `Mai\Items\RestErrors` (`~/LocalPackages/mai-items-core/docs/specs/2026-11-18-mai-items-core.md`, "Error mapping"). Reasons:

- mai-items-core needs PHP 8.2 (its spec, line 102). mai-analytics declares 8.1 (`mai-analytics.php:8`), and raising a live plugin's floor is Mike's call, not part of this pass.
- mai-items-core has no tagged release, and mai-analytics is live and also bundled inside Mai Publisher, so a dependency would reach client sites through two plugins at once.
- The copy is about 40 lines, so swapping to the library later is a small change once the floor allows it. That later swap goes in `TODO.md`.

What the copy must not drift from, until the swap:

- The same `CORE_CODES`, in the same order: `rest_invalid_param`, `rest_missing_callback_param`, `rest_invalid_json`, `rest_invalid_params`.
- The same set it remaps: those four codes and the plugin's own `{slug}_invalid_request`, as the library does (its spec, "Error mapping").
- The same steps: one `rest_request_before_callbacks` filter at priority 99, the namespace matched without case, the permission callback called once, `false` turned into core's `rest_forbidden`, a `WP_Error` passed through as it is, and a passing request's core error turned into 400 `{slug}_invalid_request` keeping core's message, `params` and `details`.
- Pinned by the library's contract test cases for error mapping (its spec, "Contract tests": a bad JSON body is 400 `{slug}_invalid_request`; a signed-out caller with a bad arg gets the permission error and the callback ran once; a route matched in other case is still mapped; a route outside the namespace and any other error code pass through), rewritten by hand into `tests/test-rest-errors.php` against mai-analytics' own routes, since the library's `contract/` suite cannot be loaded on 8.1. When the library's spec changes those cases, this copy changes with them.

#### Who notices

- **Beacon:** nothing. It reads nothing.
- **App (`useView`):** nothing. Every new answer is still dropped quietly; none is `rest_no_route`, so views never switch off by mistake. A 503 is not retried, because `useView` does not use `withRetry`.
- **Admin dashboard:** nothing. It reads only `response.ok` on errors.
- **Admin Sync Now:** the message is the same. It now reads as red through `data.code` too, as every error already did.
- **Admin Warm:** the same. `res.ok` is still false on 409 and 502, and `message` is still there.
- **Mai Publisher:** nothing. It calls no route.
- **Third parties:** an error body gains `code` and `data`, keeps `success` or `message`, and four statuses change (sync-now 500 to 409 or 502, warm 400 to 409, warm 500 to 502, insert failure 200 to 503).

#### Tests

Contract tests against the real routes, no mocks, one per row above:

- Each plugin error answers its code, status and `data.status`, and keeps `success: false` (view routes), `message` (admin routes) and the progress fields (warm).
- An insert failure (the table dropped) answers 503 `mai_analytics_database_error` with `data.retry_after` 1 and a `Retry-After: 1` header.
- `per_page=0` on trending, `source=bad` on a view POST, `/admin/search` with no `type`, and a malformed JSON body on a view POST each answer 400 `mai_analytics_invalid_request` with core's `params`.
- Signed out, `per_page=500` on `/admin/top/posts` answers 401 `rest_forbidden`; as a subscriber, 403 `rest_forbidden`; as an editor, 400 `mai_analytics_invalid_request`. The permission callback ran once (counted with a `user_has_cap` filter).
- `/view/post/abc` still answers 404 `rest_no_route`.
- The existing status tests (`tests/test-rest-api.php:51-72`, `tests/test-admin-rest-api.php:25-58`) stay and gain code assertions.

#### Safe

Yes for every known caller, checked against each one's code above. The only behaviour a third party could feel is the status changes listed under "Third parties".

## Bugs that are not alignment

Each is marked in or out of this release.

- **`mai_analytics_sync_interval` drives nothing.** Only `Settings::get_reporting_snapshot()` reads it (`src/Settings.php:24`, `:97`). Cron is fixed at 15 minutes (`src/Cron.php:139`). The README calls it "Sync transient TTL" (`README.md:181`). Out for code: Mai Publisher's `/v1/seller` sends it on as `views_interval` (`classes/class-endpoint.php:77`), so removing it changes another plugin's payload. In for docs: the README line says what it does today, "Reported to Mai Publisher only. Does not change the sync schedule."
- **`mai_analytics_matomo_bulk_chunk` runs twice.** Once as the dynamic `mai_analytics_{$key}` filter inside `Settings::get()` (`src/Settings.php:49`) and again in Matomo (`src/Providers/Matomo.php:171-173`). A callback that changes the value relative to its input, such as doubling it, applies twice. Out: fixing it changes what an existing mu-plugin gets, and nobody has reported it. Goes in `TODO.md`.
- **Only Site Kit reports a stored error, partly.** Corrected from the inventory: every provider stores its error through `Sync::set_provider_error()` (`src/Providers/Matomo.php:402-405`, `SiteKit.php:582-585`), and the dashboard notice reads that for all of them (`src/Admin.php:342`). Only the settings page notice and row (`src/AdminSettings.php:50`, `:308`) and the Health check (`src/Health.php:124`) ask `method_exists( $provider, 'get_last_error' )`, which only Site Kit has (`SiteKit.php:595-597`). Out: not alignment, and the fix (read `Sync::get_last_error()` in those three places) is small enough for its own patch. Goes in `TODO.md`.
- **`/admin/sync-now` answers 500 for a setup problem.** In, as part of change 7, because that change rewrites this exact response anyway.
- **CLI `reset` and uninstall remove different sets.** `reset` skips `mai_views_synced_at` and three `mai_analytics_post_type_*` options, and deletes a transient `mai_analytics_sync_lock` that nothing sets (`src/CLI.php:540-556`). Out: `reset` is meant to keep settings, so "the same set" is not obviously right. Goes in `TODO.md` with that question.
- **Uninstall's `LIKE 'mai_analytics_%'` treats `_` as a wildcard** (`uninstall.php:13`, `:16`), so it also matches names with any one character where the underscores are, such as another plugin's `maiXanalytics_settings`. The same class as mai-auth's uninstall finding in `ALIGNMENT.md`. In: it is in the uninstall file change 1 edits, and the fix is `$wpdb->esc_like()`. Test: an option named `maiXanalytics_keep` survives uninstall. The only other unescaped `LIKE`s on these names are read-only, `src/Migration.php:123` (the legacy meta-key probe) and `src/Health.php:54` (a count). A stray match there can only make a probe or a count wrong, so they are out and go in `TODO.md`.
- **Uninstall runs for one site only on multisite, and deletes options with raw SQL,** so a persistent object cache keeps stale copies until they expire. Out: no client site runs multisite today. Goes in `TODO.md`.
- **The bundled copy in Mai Publisher never clears its cron or data,** because its activation, deactivation and uninstall hooks never fire (see change 1). Out: Mai Publisher's releases are David's. mai-analytics can make it possible by exposing the deactivation work as a public static `Plugin::deactivate()` that change 1 uses anyway, so Mai Publisher can call it later. Goes in `TODO.md`.
- **The Health REST probe writes a real view** for the first published post each time it runs (`src/Health.php:154-157`). Out. Goes in `TODO.md`.
- **Docblocks that disagree with code:** `/admin/search` "Up to 20" (`src/AdminRestApi.php:724`, fixed in change 5), `get_source_breakdown` "max 25" (`:948`), `seed --days` default 7 against 30 (`src/CLI.php:407`, `:432`). In: comment-only.

## Decided here

Mine, with the reason, so Mike can overrule any of them:

- Copy the error mapping, don't wait for mai-items-core (reasons under change 7).
- Keep core's `rest_forbidden` on admin routes rather than invent `mai_analytics_login_required`. These are capability checks in wp-admin, not the gated tap CONVENTIONS' `*_login_required` describes, and mai-items-core keeps core's error too.
- CLI `warm --ids` stays unbounded (change 2).
- ID list entries keep today's parsing; only the count is checked (change 2).
- The dashboard's Tom Select gets `maxItems: 100` (change 2).
- Trending drops hidden items and always over-fetches five times (change 4).
- One visibility rule for every caller, no signed-in exception (change 4).
- A one-hour retry transient when the table can't be made (change 3).
- Activation also schedules the daily prune (change 1).
- The bugs marked in or out above.

## Decided by mai-coordinator

On 2026-11-18, following Mike's choice of "the safe fixes and the error codes now" and the fleet's written rules. Mike can reopen any.

- **Items a caller may not see** answer 404 `mai_analytics_not_found` from `GET /views/{post|term|user}/{id}` and `GET /views/post_type/{post_type}` (change 4).
- **Error bodies gain `code` and `data`,** keep `success` or `message`, and four statuses change: sync-now 500 to 409 (setup) or 502 (provider), warm 400 to 409, warm 500 to 502 (change 7).
- **A failed view insert answers 503 `mai_analytics_database_error`** with `data.retry_after` and a `Retry-After` header (change 7).
- **`/admin/search` gains `page`, `per_page` (default 50, at most 100) and the two total headers,** with the body unchanged (change 5).

## Questions for Mike

Naming, so his. It goes into his one walk with mai-coordinator, not a second walk.

1. **The code names.** Each is checked against what WordPress core calls the same thing (Mike's rule: don't guess a name core already has).
   - **Fleet names, matching core or the fleet:**
     - `mai_analytics_invalid_request` (400): the fleet's code, mapped from core's `rest_invalid_param` family (`wp-includes/rest-api/class-wp-rest-request.php:881`).
     - `mai_analytics_not_found` (404): core's newer routes use `*_not_found`, such as `rest_template_not_found` (`wp-includes/rest-api/endpoints/class-wp-rest-templates-controller.php:341`). Its post, term and user routes use `rest_post_invalid_id` and the like (`class-wp-rest-posts-controller.php:555`), one code per object type. One `not_found` fits a plugin whose routes take four object types.
     - `mai_analytics_database_error` (503): the fleet's code. Core's nearest are `db_insert_error` and `db_update_error` (`wp-includes/post.php:5035`, `:5003`).
     - `mai_analytics_provider_error` (502): the name mai-coordinator is writing into CONVENTIONS for a failed call to an outside service. Core's word for that is `http_request_failed` (`wp-includes/class-wp-http.php:295`). The other choice is `mai_analytics_provider_request_failed`, which uses core's words.
   - **New, so Mike's to pick:**
     - **The bot refusal** (403). Core has no code for a bot or crawler. Choices: `mai_analytics_bot` (proposed), `mai_analytics_bot_refused`, `mai_analytics_not_counted`.
     - **Sync with no working provider** (409). Core's word for a missing service or feature is "unavailable" or "disabled": `fs_unavailable` (`wp-admin/includes/class-wp-upgrader.php:255`), and `application_passwords_disabled` from `wp_is_application_passwords_available()` (`wp-includes/rest-api/endpoints/class-wp-rest-application-passwords-controller.php:682-686`). `mai_analytics_provider_unavailable` (proposed) uses core's word.
     - **Warm in self-hosted mode** (409). Core has no "mode" word. Choices: `mai_analytics_provider_mode_required` (proposed), or `mai_analytics_warm_unavailable`, which uses core's "unavailable" for a feature this site's setup turns off.
   - Note on status: core answers 501, not 409, when a feature is off on this site (`application_passwords_disabled` above). mai-coordinator is writing the 409 rule; this is for it to weigh.

# Changelog

## Unreleased

* Added: Nest the admin page under Mai Ads when Mai Publisher is active. Falls back to Mai Theme (Mai Engine) and then to Settings.
* Added: Cross-link to Mai Publisher's Matomo Tracking section from the Matomo settings, only visible when Mai Publisher is active.
* Added: Warning notice when Mai Publisher and Mai Analytics are both configured for Matomo with mismatched URL, Site ID, or Token.
* Added: [Developers] `Settings::detect_publisher_matomo_mismatch()` returns the list of mismatched Matomo field keys between Mai Publisher and Mai Analytics, or empty when in sync.
* Added: [Developers] `Settings::get_reporting_snapshot()` returns a normalized array of analytics settings for external reporting consumers (e.g. Mai Publisher's `/v1/seller` REST endpoint).
* Changed: Switched hardcoded `admin.php?page=mai-analytics` URLs to `menu_page_url()` so links remain correct regardless of which parent menu the page is nested under.
* Fixed: `admin-dashboard.js` no longer throws "Cannot read properties of null (reading 'getAttribute')" on the Settings tab. The script now early-returns when the dashboard's required select isn't present.

## 1.1.4 (5/1/26)

* Added: Idempotent Warm Stats — objects synced within the last hour are skipped by default; check "Force re-warm even if recently checked" next to the Warm Stats button or pass `--force` to override.
* Added: `mai_views_synced_at` per-object timestamp on posts, terms, and authors; archive timestamps live in the new `mai_analytics_post_type_views_synced_at` option.
* Added: `mai_analytics_warm_skip_threshold` filter (default 1 hour; set to `0` to disable the skip entirely).
* Added: `force` boolean on the warm REST endpoint and `wp mai-analytics warm --force` flag for one-off bypass from the CLI.
* Added: Circuit breaker — `ProviderSync::sync()` and warm short-circuit when the provider failed within `mai_analytics_provider_error_backoff` seconds (default 5 minutes; `0` disables).
* Added: Per-batch breaker check inside the `sync()` foreach loop — a single sync run during an outage now hits the provider once instead of once per batch.
* Added: Auto-recovery — when the breaker fires, the catchup is rescheduled for shortly after the backoff window expires, so resume happens in minutes instead of waiting for the next 15-minute cron tick.
* Added: `wp mai-analytics sync --force` flag to bypass the breaker for operator investigation.
* Added: Settings UI field for `matomo_bulk_chunk` next to the other Matomo credentials.
* Added: Dismissible admin notice surfacing the most recent provider error with a relative-age label (e.g. "12 minutes ago").
* Added: `has_app` flag on `/admin/summary` REST so dashboards on app-less sites can drop the redundant Web/App columns.
* Added: [Developers] `Sync::set_provider_error()`, `Sync::clear_provider_error()`, `Sync::is_provider_error_fresh()`, and `Sync::seconds_until_provider_error_clear()` static helpers — single owner of provider error state shape.
* Added: [Developers] `ProviderSync::sync( bool $force = false )` parameter and `ProviderSync::CATCHUP_HOOK` public constant.
* Added: [Developers] phpunit coverage for skip-recent, force bypass, breaker (top-level + per-batch), option durability across cache flush, and provider-failure-no-mark.
* Changed: Matomo all-time window switched from `period=week, date=last260` to `period=year, date=last5` — ~50× less Matomo memory per bulk request, removes the HTTP 500 "Allowed memory size exhausted" failure mode on high-traffic sites. First sync after upgrade corrects `mai_views_web` upward by ~10–15% on high-traffic URLs (yearly is more accurate than the weekly sum, which loses ~13% to year-boundary rollup drift).
* Changed: Default Matomo bulk chunk raised from 5 to 10.
* Changed: Move `mai_analytics_provider_error` from a transient with 1-hour TTL to a regular option (autoload off) so error state survives `wp transient delete-all`, cache flushes, and plugin activations — persists until naturally cleared by a successful sync.
* Changed: Floor lifetime total at the current trending value when writing `mai_views`, so `total >= trending` holds across syncs (applies to ProviderSync and self-hosted Sync).
* Changed: Hide Web and App dashboard columns on app-less sites; sites with any app traffic still see the breakdown.
* Changed: [Performance] Skip-recent filter for warm runs at the SQL level via `LEFT JOIN` so excluded rows never reach PHP — avoids 24K-row loads on sites with mostly-fresh data.
* Changed: `wp mai-analytics sync` emits `WP_CLI::warning` (exit code 0 preserved) instead of misleading "Sync complete" output when the breaker bails — keeps monitoring wrappers green.
* Changed: Centralized catchup scheduling so both call sites dedupe via `wp_next_scheduled` against `ProviderSync::CATCHUP_HOOK`.
* Changed: [Developers] `mai_analytics_provider_error` payload stores `{message, time}` JSON.
* Fixed: Provider failures during warm no longer silently advance `mai_views_synced_at` — failed objects correctly retry on the next warm rather than being hidden by the skip-recent filter.
* Fixed: Catchup chain on the second tick during a provider outage — the top-level breaker now schedules a post-window catchup, so recovery happens in ~6 minutes instead of waiting up to ~14 minutes for the next cron tick.
* Fixed: Mid-run catchup scheduling is now guarded by `wp_next_scheduled` — prevents stacked catchup events under rapid back-to-back syncs.
* Fixed: Breaker bailing inside `sync()` no longer updates `mai_analytics_provider_last_sync` — otherwise the next cron tick would see "fresh sync" and skip the buffer rebuild even after the provider recovers.

## 1.1.3

* Fixed: External-provider sync was a silent no-op since 1.1.0. `ProviderSync::sync()` overwrote `mai_analytics_provider_last_sync` at the top of the run and re-read it as the buffer-boundary cutoff, so `Database::get_distinct_objects_since()` always queried for `viewed_at > now` and found zero rows — `mai_views_web` and `mai_trending` never updated for any object on Matomo/GA/Jetpack-backed sites. Capture the previous timestamp into a local before overwriting, mirroring self-hosted `Sync::sync()`.
* Fixed: `Cron::ensure_healthy()` and `Cron::maybe_fallback_sync()` read `mai_analytics_synced` unconditionally — that option is only written by self-hosted `Sync`, so external-provider sites had a permanently-stale staleness check that hammered the fallback sync on every beacon. Both helpers now branch on `data_source` and read the option each sync path actually updates.
* Fixed: `wp mai-analytics sync` reporting "Last sync: never" in external-provider mode for the same reason — the CLI now branches on data source.
* Fixed: Sync boundary race window in both `ProviderSync::sync()` and self-hosted `Sync::sync()` — both wrote `time()` to the last-sync option at start AND finish, so buffer rows inserted during the run fell behind the next sync's boundary and were stranded permanently. Capture `$started_at` once at the top and write that same value at finish.

## 1.1.2

* Fixed: Posts-tab Author filter populates from authors of viewed posts (not from `mai_views` in `usermeta`) — was empty on most sites because author archives are rarely linked, often disabled by SEO plugins, and excluded from tracking when the visitor has `edit_posts`.
* Changed: Author filter is now a Tom Select multi with ajax search via `/admin/search?type=author` so big multi-author sites can pick any combination of authors without scrolling a 100+ option dropdown.
* Changed: Gate Post Type and Taxonomy filter dropdowns on "has views > 0" so dashboards never offer filters that would return empty tables.
* Added: [Performance] 5-minute transient cache for the `get_filters` payload — filter membership changes far slower than the underlying view counts.
* Added: `?subtab=posts|terms|authors|archives` URL parameter for deep-linkable dashboard sub-tabs; the active sub-tab ships with a real `href` and is server-rendered with the correct active class.
* Fixed: Active-tab class swap is scoped to `.mai-analytics-tabs` so clicking a sub-tab no longer strips the active state from the top-level Dashboard tab.
* Changed: Dropped the dashboard's top-10 horizontal bar chart and its Chart.js dependency — it duplicated the precise sortable table directly below it without surfacing anything new (~100 lines of code + CDN script removed).
* Changed: Dropped the "Filtered by:" tag row — duplicated the dropdowns themselves and toggling its `display:none` on every filter change caused visible CLS as the table jumped up and down.
* Changed: Convert every filter dropdown to Tom Select for visual uniformity (post type, taxonomy, term, author, publish dates) and rework the filter row as a CSS grid (`repeat(auto-fit, minmax(180px, 1fr))`) so cells share width, fill row height, and wrap naturally.
* Changed: Disable search input on static Tom Selects (`controlInput: null`); placeholder restored via a `data-placeholder` ::before so the empty state still reads "All Post Types" / "All Taxonomies" / "All Publish Dates."
* Fixed: Hide the Tom Select chevron when an item is selected so the clear-button × no longer overlaps it.
* Changed: Nest the Custom Days input inside the Publish Dates grid cell so it shares space instead of consuming a full column.
* Changed: [Developers] Extract the Publish Dates filter into a `PublishedDaysFilter` class with `getValue()` / `clear()` API — replaces two top-level functions, an inline listener, and a module-level integer.
* Changed: Switch the dashboard filter markup to BEM (`.mai-analytics-filters__field`, `--posts` / `--terms` modifiers, `__custom-days`, `__published`) so visibility logic reads the active-tab modifier directly.

## 1.1.1

* Fixed: Validate that Site Kit's `googlesitekit_owner_id` resolves to a real user before `wp_set_current_user()` — stale-owner sites now surface "Site Kit owner user (ID N) does not exist" with remediation guidance instead of a confusing `rest_forbidden`.
* Fixed: `SiteKit::get_unavailable_reason()` now returns an empty string when the provider is available (was falling through to the "GA4 not connected" string).
* Added: `--chunk=N` flag on `wp mai-analytics warm` so one-off CLI runs can scale the Matomo bulk chunk without registering a `mai_analytics_matomo_bulk_chunk` filter in a mu-plugin.
* Changed: Distinguish `iterated` from `updated` in warm progress — admin Warm Stats button and CLI verbose output show "N updated of M" when a batch's provider call fails and meta is preserved.
* Changed: [Developers] Replace in-tree `error_log()` calls in all three providers with the shared `maithemewp/mai-logger` Composer package — gains Ray and WP-CLI routing for free; new `mai_analytics_logger()` helper is the public entry point.
* Fixed: Decode HTML entities when capturing Matomo HTTP error response snippets so transient and debug-log output read as `›` and `«` instead of raw `&rsaquo;` / `&laquo;`.
* Changed: Refine the Warm Stats button hint copy so "leave this window open" reads as a parenthetical aside, not a peer status item.

## 1.1.0

* Fixed: Matomo provider returning empty data so `mai_views` and `mai_trending` write real counts instead of `0` on Matomo-backed sites — provider now expands paths to full URLs via `home_url()` (with `rawurldecode()` for Unicode-dash slug safety) and uses `period=day` / `period=week` against pre-built archives instead of `period=range`.
* Added: `mai_analytics_views_years` filter (default `5`) for the Matomo all-time window; migrates the matching value from the `mai_publisher` option.
* Changed: [Developers] Refactor `WebViewProvider::get_views()` to take an array of named windows and return per-window per-path counts — collapses ProviderSync's two calls per batch into one bulk request (Matomo `API.getBulkRequest`, SiteKit per-window loop with single user-switch, Jetpack window loop over the cached per-post dataset).
* Added: `mai_analytics_matomo_bulk_chunk` filter (default `5`) — Matomo provider chunks bulk requests by path count to stay under `API_bulk_request_limit` and per-request memory budgets.
* Added: Cursor-based admin Warm Stats button — processes one batch per request via `ProviderSync::warm_batch()`, so large-site warms no longer hit Cloudflare's 524 timeout. Polls the new endpoint, shows per-batch progress, registers a `beforeunload` warning while running, and processes most-recent posts and terms first.
* Changed: Matomo provider enforces the math invariant `all_time >= trending` per path before returning — works around the current-incomplete-week gap in Matomo's weekly archives.
* Changed: Matomo failures populate the `mai_analytics_provider_error` transient and surface response-body snippets in error messages, matching SiteKit and Jetpack's existing error-surfacing contract. See #5.

## 1.0.4

* Fixed: Skip provider-unavailable admin notice when view tracking is set to disabled.

## 1.0.3

* Fixed: Bail gracefully when installed alongside old Mai Publisher versions that still have the built-in `Mai_Publisher_Views` class — shows an admin notice prompting the user to update Mai Publisher or deactivate Mai Analytics. Prevents double-tracking.

## 1.0.2

* Changed: Standalone installs now override Mai Publisher's bundled copy. Composer's `files` autoload no longer auto-runs `mai-analytics.php`; the standalone plugin prepends its Composer `ClassLoader` so `Mai\Analytics\*` resolves to its `src/` even when Mai Publisher's autoloader ran first. Mai Publisher loads the bundled bootstrap only if no standalone is active.

## 1.0.1

* Fixed: `Last sync recent` health check reporting stale on external-provider sites — now reads `mai_analytics_provider_last_sync` for Matomo/GA/Jetpack and `mai_analytics_synced` for self-hosted, matching the option each sync path actually writes to.

## 1.0.0

* Added: Initial release as Mai Analytics. View tracking extracted from Mai Publisher and expanded with self-hosted tracking, Google Analytics (via Site Kit), Matomo, and Jetpack Stats support.

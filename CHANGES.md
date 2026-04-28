# Changelog

## 1.1.1

Validate that Site Kit's `googlesitekit_owner_id` resolves to a real user before `wp_set_current_user()` so the GA4 REST `permission_callback` can't silently return `rest_forbidden` against a phantom user. Stale-owner sites now surface "Site Kit owner user (ID N) does not exist" with remediation guidance. Fix `SiteKit::get_unavailable_reason()` falling through to the "GA4 not connected" string even when the provider was available — it now matches its docblock and returns an empty string in the available case. Add `--chunk=N` to `wp mai-analytics warm` so one-off CLI runs can scale the Matomo bulk chunk without registering a `mai_analytics_matomo_bulk_chunk` filter in a mu-plugin. Distinguish `iterated` from `updated` in warm progress so the admin Warm Stats button and the CLI's verbose output show "N updated of M" when a batch's provider call fails and meta is preserved (the old "M updated" misled admins into thinking failed batches had succeeded). Replace the in-tree `error_log()` calls in all three providers with the shared `maithemewp/mai-logger` Composer package, gaining Ray and WP-CLI routing for free; the new `mai_analytics_logger()` helper is the public entry point. Decode HTML entities when capturing Matomo HTTP error response snippets so transient and debug-log output read as `›` and `«` instead of raw `&rsaquo;` / `&laquo;`. Refine the Warm Stats button hint copy so "leave this window open" reads as a parenthetical aside, not a peer status item.

## 1.1.0

Fix Matomo provider returning empty data so `mai_views` and `mai_trending` write real counts instead of `0` on Matomo-backed sites. The provider now expands paths to full URLs via `home_url()` (with `rawurldecode()` for Unicode-dash slug safety) and uses `period=day` / `period=week` against pre-built archives instead of `period=range`, mirroring the proven strategy from pre-bundle Mai Publisher's views class. Adds a `mai_analytics_views_years` filter (default `5`) for the all-time window and migrates the matching value from the `mai_publisher` option. Refactors `WebViewProvider::get_views()` to take an array of named windows and return per-window per-path counts, collapsing ProviderSync's two calls per batch into one bulk request (Matomo `API.getBulkRequest`, SiteKit per-window loop with single user-switch, Jetpack window loop over the cached per-post dataset). The Matomo provider chunks its bulk requests by path count to stay under `API_bulk_request_limit` and per-request memory budgets; chunk size is filterable via `mai_analytics_matomo_bulk_chunk` (default `5`). The admin Warm Stats button is now cursor-based and processes one batch per request via `ProviderSync::warm_batch()`, so large-site warms no longer hit Cloudflare's 524 timeout; it polls the new endpoint, shows per-batch progress, registers a `beforeunload` warning while running, and processes most-recent posts and terms first. The Matomo provider also enforces the math invariant `all_time >= trending` per path before returning, working around the current-incomplete-week gap in Matomo's weekly archives. Matomo failures now populate the `mai_analytics_provider_error` transient and surface response-body snippets in error messages, matching SiteKit and Jetpack's existing error-surfacing contract. See #5.

## 1.0.4

Skip provider-unavailable admin notice when view tracking is set to disabled.

## 1.0.3

Bail gracefully when installed alongside old Mai Publisher versions that still have the built-in `Mai_Publisher_Views` class. Shows an admin notice prompting the user to update Mai Publisher or deactivate Mai Analytics. Prevents double-tracking.

## 1.0.2

Standalone installs now override Mai Publisher's bundled copy. Composer's `files` autoload no longer auto-runs `mai-analytics.php`; the standalone plugin prepends its Composer `ClassLoader` so `Mai\Analytics\*` resolves to its `src/` even when Mai Publisher's autoloader ran first. Mai Publisher loads the bundled bootstrap only if no standalone is active. Drop a new Mai Analytics onto a Mai Publisher site and it takes over cleanly.

## 1.0.1

Fix `Last sync recent` health check reporting stale on external-provider sites. The check now reads `mai_analytics_provider_last_sync` for Matomo/GA/Jetpack and `mai_analytics_synced` for self-hosted, matching the option each sync path actually writes to.

## 1.0.0

Initial release as Mai Analytics. View tracking extracted from Mai Publisher and expanded with self-hosted tracking, Google Analytics (via Site Kit), Matomo, and Jetpack Stats support.

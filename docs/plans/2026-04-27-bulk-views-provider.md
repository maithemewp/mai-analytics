# Plan: Single-call bulk views provider interface + chunked admin warm

## Context

`WebViewProvider::get_views()` previously took one date range and returned `['/path/' => N]`. To get both "all-time" and "trending" counts, every batch sync called the provider **twice** — and on Matomo that was two `API.getBulkRequest` HTTP roundtrips per batch.

Pre-bundle Mai Publisher's `class-views.php` (commit `9952922` in `maithemewp/mai-publisher`, removed in `58dc02b` during the bundle work) put both windows in a single bulk request. Its source carried this comment:

> "Add trending first incase views times out."

That pattern was lost during the Mai Analytics extraction without being noted, and we caught it (along with several Matomo correctness regressions vs. that file) while debugging zero-valued post meta on `ontapsportsnet.com`. There were no third-party providers extending the interface, so a hard contract change was preferred over an additive opt-in path.

This plan changes the contract to take an array of named windows, returns per-window per-path counts, collapses ProviderSync's two calls into one, and switches the admin Warm Stats button to cursor-based progressive REST so it stops hitting Cloudflare's 524 timeout on large sites. Bundled with the period-strategy fix (path expansion via `home_url()`, `period=day`/`period=week` instead of `period=range`, `mai_analytics_views_years` filter) as a single `1.1.0` release.

## Implementation notes (deviations from review)

- **SiteKit uses a per-window loop, not GA4's native `dateRanges`.** The reviewed plan called for one report carrying multiple `dateRanges`. We implemented a per-window loop that issues one `rest_do_request` per window and impersonates the Site Kit owner once for the whole call. Reason: we couldn't confirm every supported Site Kit version forwards the `dateRanges` parameter through to GA4's `runReport`, and a silent fall-through would have returned default-window data with no error. Native `dateRanges` is left as a future optimization, documented in-line in `Providers/SiteKit.php`.
- **SiteKit empty start_date omits the date range entirely** — matches the deliberate behavior established by commit `f5199c6`: "GA4 returns all data since the property was created. No hardcoded start date needed." We do not reintroduce a hardcoded floor.
- **SiteKit failure semantics are all-or-nothing.** If any window errors, `get_views()` returns `[]` and sets the provider error transient. ProviderSync's `empty( $web_views )` check then trips and existing meta is preserved. Returning a partial result (only the successful windows) would let the caller's `?? 0` fall through to zero on the missing window and silently overwrite real meta values.
- **Warm endpoint accepts `total_updated` as a passthrough.** The reviewed wire shape was `{ cursor }`. The implementation also accepts `total_updated` so the running cumulative count survives across requests without server-side state.
- **Warm endpoint calls a new `ProviderSync::warm_batch( int $cursor )` static method, not the existing generator.** Calling `ProviderSync::warm()` and advancing it to the cursor would be O(T²): every batch request would re-execute every preceding batch's provider HTTP call and per-object DB writes before reaching the requested batch. `warm_batch()` instead re-runs only the prelude (`prepare_warm_state()` — bounded by site size, dominated by a single SQL collection) and processes exactly the requested batch. The legacy `warm()` generator stays unchanged for the CLI.

## Interface

```php
// src/WebViewProvider.php

/**
 * Fetches pageview counts for the given paths across one or more named date windows.
 *
 * @param array<string>                            $paths   URL paths.
 * @param array<string, array{0:string,1:string}>  $windows Map of window name to
 *     [start_date, end_date]. Empty start_date means "all-time".
 *
 * @return array<string, array<string, int>> Map of path => (window name => view count).
 */
public function get_views( array $paths, array $windows ): array;
```

Caller:

```php
$result = $provider->get_views( $paths, [
    'trending' => [ $trend_start, $today ],
    'all_time' => [ '', $today ],
] );
// $result['/some-post/']['trending'] → 12
// $result['/some-post/']['all_time'] → 100
```

`ProviderSync` passes trending first because Matomo processes bulk sub-queries in order and trending is the cheaper / more time-sensitive of the two.

## Files modified

- `src/WebViewProvider.php` — interface signature + docblock.
- `src/Providers/Matomo.php` — bulk request packs paths × windows; per-(path, window) period/date translation; response index → (path, window) mapping; keeps `home_url()` + `rawurldecode()` URL normalization. Inline comments explain why `period=range`/year/month don't work on this site type and why all windows share one HTTP roundtrip.
- `src/Providers/SiteKit.php` — per-window loop with single user-switch. Empty start_date omits the GA4 date range (preserves f5199c6 behavior). All-or-nothing failure semantics. Native `dateRanges` documented as future optimization.
- `src/Providers/Jetpack.php` — both windows for one path computed from a single `fetch_post_views()` cached result. `$cache` property docblock rewritten — rationale moved from "dedup across two `get_views()` calls" to "dedup across paths within one batch."
- `src/ProviderSync.php` — `process_batch()` and `warm()` collapse two `get_views()` calls into one. `$provider_failed` simplifies to `empty( $web_views )`. Adds `WINDOW_TRENDING`/`WINDOW_ALL_TIME` constants and a `build_default_windows()` helper so both call sites share one window-shape definition. `warm()` is decomposed into three private helpers — `prepare_warm_state()`, `process_warm_batch()`, `persist_warm_pt_options()` — and a new public `warm_batch( int $index )` method processes exactly one batch by index for the chunked admin endpoint.
- `src/AdminRestApi.php`:
  - Health-check call updated to new signature; result still discarded.
  - `/admin/warm` route now declares `cursor` and `total_updated` args.
  - `warm()` handler calls `ProviderSync::warm_batch( $cursor )` directly and returns `{ batch, total, updated, total_updated, done, next_cursor }`.
- `assets/js/admin-settings.js` — Warm Stats button replaced with `bindWarmButton()` that polls `/admin/warm` with the cursor until `done`. Inline batch progress (`Batch X of Y · N updated`). On error, abort and show the message; partial progress is preserved server-side.
- `tests/test-provider-sync.php` — mock provider updated to new signature.
- `tests/test-admin-settings.php` — one-line mock signature update.
- `tests/test-providers-matomo.php` — new file, four wire-level tests pinning the bulk format, per-window period/date translation, and response index mapping.
- `CHANGES.md` — replaces `## Unreleased` with `## 1.1.0` covering the period-strategy fix, the bulk-interface refactor, and the chunked Warm Stats button. Single paragraph in the existing house style.
- `mai-analytics.php` — `Version:` bumps to `1.1.0` only after live-site verification passes.

## Verification

Run after deploying via Mai Publisher (which Composer-pulls `mai-analytics` from its `develop` branch).

```bash
wp eval 'echo json_encode((new Mai\Analytics\Providers\Matomo())->get_views(
  ["/some-post/"],
  [
    "trending" => ["2026-03-28", "2026-04-27"],
    "all_time" => ["", "2026-04-27"],
  ]
));'
```

Expected: `{"\/some-post\/":{"trending":N,"all_time":M}}` with both numbers non-zero.

```bash
wp mai-analytics warm --ids=<id> --verbose
wp post meta get <id> mai_views
wp post meta get <id> mai_views_web
wp post meta get <id> mai_trending
```

All populated and consistent (trending ≤ all-time).

```bash
cd /path/to/mai-analytics
composer test
```

`test-provider-sync.php`, `test-providers-matomo.php`, and `test-admin-settings.php` all pass.

In the WP admin: click **Warm Stats** on a large site. Status text updates per batch (`Batch X of Y · N updated`). No CF 524. Final state shows `Warm complete. Updated N objects.`

## Sequencing

The verification path runs through Mai Publisher (the real site uses the bundled build), so the sequence is:

1. Apply all code edits + new tests + save this plan into `docs/plans/`, locally in `mai-analytics`. No version bump.
2. Commit + push to `mai-analytics` `develop`. `## Unreleased` heading in `CHANGES.md`. `Version:` unchanged. Makes the changes available to Mai Publisher's `dev-develop` Composer constraint.
3. In `mai-publisher` working copy: `composer update maithemewp/mai-analytics`, commit the regenerated `vendor-prefixed/` + `composer.lock` to `mai-publisher` `develop`, deploy the build, run the verification checklist.
4. On verification pass — in `mai-analytics`: bump `mai-analytics.php` `Version:` to `1.1.0`, replace `## Unreleased` with `## 1.1.0` in `CHANGES.md`, commit, push to `develop`.
5. Merge `mai-analytics` `develop` → `main` (explicit consent given in plan approval). Push `main`. No tag.
6. Update `mai-publisher` `composer.json` constraint from `"dev-develop"` to `"dev-main"`, `composer update`, commit to `mai-publisher` `develop`. Confirms the released code path is what mai-publisher actually pulls.

## Out of scope

- Logger upgrade (the pre-bundle structured logger). Separate concern.
- Settings UI for `views_years`. The filter + migration are sufficient for now.
- Native GA4 `dateRanges` in SiteKit (see Implementation notes).

# Plan: Mai Analytics 1.1.0 follow-ups

## Approval status

All five items below were walked through and explicitly approved by the user via the `plan-approval` skill on 2026-04-27. A future session picking this up does **not** need to re-confirm each step — execute them in the order under "Sequencing" below. The release sequencing at the bottom of this doc (commit, push, verify, vbump, merge to main, mai-publisher constraint flip) **does** still need explicit confirmation as you reach those gates, per the user's durable instructions about pushing to main.

## Context

`mai-analytics` `1.1.0` shipped (commit `c9613c6` on `main`) bundling four big things: Matomo zero-views fix, named-windows provider interface, chunked admin Warm Stats endpoint, and recent-first warm ordering. Verified end-to-end on `ontapsportsnet.com` (Matomo) and on a separate Mai Pub site (Site Kit / GA4).

During verification we hit five small things that didn't block the release but are worth a short polish pass. They group naturally into one follow-up release (`1.1.1`). Nothing here is urgent; nothing here is mysterious; pick this up when you have a clear hour.

Companion to `docs/plans/2026-04-27-bulk-views-provider.md` (the as-built record of `1.1.0`).

## Why these specifically

OnTapSports's SiteKit verification surfaced auth confusion because `googlesitekit_owner_id` pointed at a deleted user. Our provider blindly `wp_set_current_user`'d to a non-existent ID, the REST permission gate 401'd, and the failure surfaced as silent zeros after a "successful" warm. Items 1, 2, and 3 below all stem from that incident. Items 4 and 5 are quality-of-life noted during the same session.

## Items

### 1. SiteKit: validate owner user exists before switching

**Problem.** `SiteKit::get_views()` does:

```php
$owner_id = (int) get_option( 'googlesitekit_owner_id', 0 );
if ( ! $owner_id ) {
	$owner_id = (int) get_option( 'googlesitekit_first_admin', 0 );
}
if ( ! $owner_id ) {
	self::set_last_error( ... );
	return [];
}
wp_set_current_user( $owner_id );
```

If the owner user was deleted, `$owner_id` is non-zero but `wp_set_current_user( $owner_id )` silently sets `current_user_id` to a phantom user (no caps, no auth state). Site Kit's REST `permission_callback` then returns `rest_forbidden`, which lands in our error transient as a confusing "Sorry, you are not allowed to do that." that doesn't explain the root cause.

**Fix.** After resolving `$owner_id`, validate the user actually exists.

```php
// After the existing $owner_id resolution:
$owner = $owner_id ? get_user_by( 'id', $owner_id ) : false;

if ( ! $owner ) {
	self::set_last_error( sprintf(
		/* translators: %d is the stale owner user ID. */
		__( 'Site Kit owner user (ID %d) does not exist. Have an existing admin re-sign-in via Site Kit, or update googlesitekit_owner_id.', 'mai-analytics' ),
		$owner_id
	) );
	return [];
}

wp_set_current_user( $owner->ID );
```

**Files.** `src/Providers/SiteKit.php` only.

**Verification.** On a site with the owner user deleted (or `googlesitekit_owner_id` set to a non-existent ID), `wp eval` calling `get_views()` should return `[]` AND populate `mai_analytics_provider_error` with the new explicit message (instead of `rest_forbidden`).

### 2. `SiteKit::get_unavailable_reason()` always returns the wrong reason when available

**Problem.** When `is_available()` returns `true`, `get_unavailable_reason()` still returns `'Google Analytics 4 is not connected in Site Kit.'` because that string is the unconditional fallback at the bottom of the cascade. Confused us during verification — we saw `is_available: yes` paired with an "unavailable" reason and lost time chasing a non-issue.

**Fix.** Either return an empty string when `is_available()` is true, or have callers gate `get_unavailable_reason()` behind `! is_available()`. The cleaner fix is the former — match the docblock that says "or empty string if available".

```php
public function get_unavailable_reason(): string {
	if ( $this->is_available() ) {
		return '';
	}
	if ( ! defined( 'GOOGLESITEKIT_VERSION' ) ) {
		return __( ... );
	}
	if ( version_compare( GOOGLESITEKIT_VERSION, self::MIN_SITE_KIT_VERSION, '<' ) ) {
		return sprintf( ... );
	}
	return __( 'Google Analytics 4 is not connected in Site Kit.', 'mai-analytics' );
}
```

Apply the same pattern to `Matomo::get_unavailable_reason()` and `Jetpack::get_unavailable_reason()` if they have the same shape — quick check.

**Files.** `src/Providers/SiteKit.php`, possibly `src/Providers/Matomo.php` and `src/Providers/Jetpack.php`.

**Verification.** `wp eval 'echo (new Mai\Analytics\Providers\SiteKit())->get_unavailable_reason();'` returns empty string on a site where Site Kit is properly configured.

### 3. Verify cron-driven Site Kit syncs actually work

**Problem.** During verification we proved `Mai\Analytics\Providers\SiteKit::get_views()` works from an in-browser admin context (Warm Stats button, `Sync Now` button — both succeeded against GA4 on a properly-configured site). We did **not** prove it works from `wp-cron` or `wp-cli`. Both contexts call the same code but lack the browser session that satisfies Site Kit's REST `permission_callback`. The `wp_set_current_user( $owner_id )` switch may not be enough.

This matters because the cron-driven `ProviderSync::sync()` is the routine update path. If it silently 401s on Site Kit, the data_source value is essentially "warm-only" — fine for periodic full warms, broken for ongoing real-time updates.

**Investigation steps.** On a known-working SiteKit site (the one used to verify item 1):

```bash
# Capture meta values for a recent post
ID=<id of post that just got traffic>
wp post meta get $ID mai_views_web

# Force a sync via CLI (same code path cron uses)
wp mai-analytics sync

# Check if the value updated
wp post meta get $ID mai_views_web

# Tail debug log for any Site Kit errors
tail -50 wp-content/debug.log | grep -i "site kit\|googlesitekit\|mai analytics"
```

Or wait for the 15-minute cron window and recheck.

**Outcomes and fixes.**

- **Cron sync writes real GA4 data** → no fix needed; Site Kit's permission_callback IS satisfied by `wp_set_current_user` alone, and the OnTapSports failure was specifically about the deleted owner user (item 1 covers it).
- **Cron sync silently writes zeros / 401s** → real architectural problem. Options to investigate:
  1. Use Site Kit's internal PHP API (e.g. via `Modules` / `Analytics_4` services) instead of `rest_do_request` — bypasses the REST permission_callback entirely. Most robust, but couples us to Site Kit internals.
  2. Set up a server-to-server credential (service account JSON) via Site Kit's Service Account flow if it exists, and use it for non-browser contexts.
  3. Document the limitation: SiteKit data_source requires admin-initiated warms; cron sync cannot run reliably.

**Files.** Investigation only initially. If a fix is needed, likely `src/Providers/SiteKit.php`.

**Verification.** Cron-triggered sync results in updated `mai_views_web` meta on a SiteKit-backed test site.

### 4. `warm` REST response: distinguish "iterated" from "actually wrote real data"

**Problem.** `ProviderSync::process_warm_batch()` increments `$updated++` for every object it iterates, regardless of whether the provider call actually returned data. When the provider fails for a batch, `$provider_failed = true`, web meta is not overwritten — but `updated` still includes that object in its count. The admin status text reads `Batch X · 100 updated` even when nothing was actually fetched.

Cosmetic only — meta isn't corrupted, just the count is misleading.

**Fix.** Have `process_warm_batch()` track two counters:

```php
$iterated = 0;
$updated  = 0;

foreach ( $path_map as $path => $obj ) {
	// ...
	$iterated++;
	if ( null !== $web_total ) {
		// We actually wrote new web data this iteration.
		$updated++;
	}
}

return [
	'batch'    => $batch_index + 1,
	'total'    => count( $state['batches'] ),
	'updated'  => $updated,
	'iterated' => $iterated,
	'type'     => $current_type,
];
```

Update the JS in `assets/js/admin-settings.js` to display either or both, and update the wire-shape comment in `AdminRestApi::warm()`.

Apply the same pattern in `process_batch()` for symmetry, even though it's not surfaced via REST.

**Files.** `src/ProviderSync.php`, `src/AdminRestApi.php` (docblock only), `assets/js/admin-settings.js`.

**Verification.** Trigger a Warm Stats run on a site where the provider intermittently fails (or with a stubbed broken provider). The status text should show `iterated > updated` for the failing batches.

### 5. CLI `--chunk=N` flag for `wp mai-analytics warm`

**Problem.** Today, increasing the Matomo bulk chunk size means dropping a mu-plugin file that filters `mai_analytics_matomo_bulk_chunk`. Awkward for one-off CLI runs on beefy infra.

**Fix.** Add a `--chunk=N` option to the CLI command. When set, register a per-call filter before invoking `ProviderSync::warm()`:

```php
// src/CLI.php inside the warm() method, after parsing other flags:
$chunk = isset( $assoc_args['chunk'] ) ? (int) $assoc_args['chunk'] : 0;

if ( $chunk > 0 ) {
	add_filter( 'mai_analytics_matomo_bulk_chunk', fn() => $chunk );
}
```

Update the inline `## OPTIONS` doc and `## EXAMPLES` block.

**Files.** `src/CLI.php`. Optionally `README.md` if it has a CLI section.

**Verification.** `wp mai-analytics warm --chunk=25 --verbose` runs without error and the request body to Matomo contains 25 paths × N windows of `urls[]` entries (verifiable via `error_log` instrumentation or by adding a temporary `error_log( count( $body['urls'] ) )` in `Matomo::fetch_chunk()`).

## Sequencing

All five items are independent. Recommended order if doing them as one PR:

1. Item 1 (SiteKit owner validation) — smallest, real correctness bug.
2. Item 2 (unavailable reason cascade fix).
3. Item 5 (CLI `--chunk` flag) — small UX win.
4. Item 4 (`updated` vs `iterated`) — cosmetic but touches three files.
5. Item 3 (cron Site Kit verification) — investigation; only becomes a code change if cron actually 401s.

Bundle as `1.1.1` if all five ship; if item 3 reveals a deeper Site Kit auth issue, that's `1.2.0` material on its own.

## Out of scope

- The pre-existing N+1 in `process_batch()` / `process_warm_batch()` (per-object `Sync::get_meta` × 2 + per-object `wpdb->get_var` count). Worth a separate optimization pass if warm or sync becomes a performance issue.
- Native GA4 `dateRanges` in SiteKit (collapse the per-window loop to one report). Documented as future optimization in `Providers/SiteKit.php`. Not urgent.
- Logger upgrade (the structured `Mai_Publisher_Logger` from pre-bundle Mai Pub).
- Settings UI field for `views_years`. Filter is sufficient.

## Sequencing reminder for releases

Same flow as `1.1.0`:

1. Apply changes on `mai-analytics` `develop`. Push.
2. In `mai-publisher` working copy, temporarily flip `composer.json` to `dev-develop`, `composer update`, commit, push, deploy, verify.
3. On verification pass, bump `mai-analytics.php` `Version:` to `1.1.1`, replace `## Unreleased` with `## 1.1.1` in `CHANGES.md`, commit, push to `mai-analytics develop`.
4. Merge `mai-analytics` `develop` → `main`, push. No tag.
5. Flip `mai-publisher` constraint back to `dev-main`, `composer update`, commit, push.

Per durable user instructions: develop only; merging to main requires explicit consent (re-confirm at the time, even though plan approval gives general consent for the pattern); no tags or GH releases unless asked.

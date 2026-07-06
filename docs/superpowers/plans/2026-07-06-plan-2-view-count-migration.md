# Plan 2: View-Count Migration Through the Stats Store Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the remaining view-meta writes (`mai_views_web`, `mai_views_app`, `mai_views`, `mai_views_synced_at`) out of the two sync engines and into the `Stats` store that already owns `mai_trending`, so all view-meta logic lives in one tested place. Ships as 1.3.0.

**Architecture:** Add four write methods to `Mai\Analytics\Stats` that wrap the existing `Sync::update_meta`/`get_meta` calls, then re-point both engines (`Sync::sync()` for self-hosted, `ProviderSync::process_batch()` + `process_warm_batch()` for providers) at them — one meta key at a time, each behind a test proving the stored counts are byte-identical before and after. This is a behavior-preserving refactor, not a feature.

**Tech Stack:** PHP (WordPress plugin), PHPUnit 9.6 + `WP_UnitTestCase`, WP-CLI, `wpdb`.

**Spec:** `docs/superpowers/specs/2026-07-03-view-stats-lifecycle-design.md` (rollout sequencing step 3; the method names come from its "High-level methods" list).

## Global Constraints

- **Prerequisite:** the suite must be green first (`2026-07-06-cleanup-b-test-suite.md`), so "counts unchanged" is measured against a trustworthy baseline.
- **NO view count may change.** This is the entire risk. Every task ends by proving the existing count-asserting tests still pass, plus a dedicated equivalence test. If any count moves, the routing is wrong — stop and fix.
- The `total = max( web + app, trending )` floor invariant must be preserved exactly (it lets total stay >= trending even when the lifetime counters are momentarily stale).
- The `set_`/`add_` asymmetry is intentional: `web` is an authoritative provider total (replace); `app` is a delta of newly-buffered views since last sync (increment). Do not "simplify" `add_app` into a `set_app`.
- New methods get `@since 1.3.0` docblocks (thorough PHPDoc: `@param`, blank line, `@return` — see [[feedback_docblocks]]).
- This is trending-independent: `mai_trending` already routes through `Stats::set_trending` (1.2.0); do not touch it here.
- Run command: `WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit`.
- Release mechanics (version in two spots, CHANGES.md, PUC on `main`, tag `v1.3.0`): see [[reference_mai_analytics_release]]. Ask before tagging.

---

### Task 1: `Stats::set_web()` + route web writes

**Files:**
- Modify: `src/Stats.php` (add method)
- Modify: `src/Sync.php` (self-hosted web increment), `src/ProviderSync.php` (provider web replace, two sites)
- Test: `tests/test-stats.php`, and the existing `tests/test-sync.php` / `tests/test-provider-sync.php` count assertions are the regression guard

**Interfaces:**
- Produces: `Stats::set_web( int $object_id, string $object_type, int $value ): void` — replaces `mai_views_web` (provider path).
- Produces: `Stats::add_web( int $object_id, string $object_type, int $delta ): void` — increments `mai_views_web` (self-hosted path).

- [ ] **Step 1: Write the failing test** in `tests/test-stats.php`
```php
public function test_set_web_replaces_value(): void {
    $post_id = self::factory()->post->create();
    update_post_meta( $post_id, 'mai_views_web', 3 );
    Stats::set_web( $post_id, 'post', 10 );
    $this->assertSame( '10', get_post_meta( $post_id, 'mai_views_web', true ) );
}
```
- [ ] **Step 2: Run it, verify it fails** (`Error: Call to undefined method Stats::set_web`).
- [ ] **Step 3: Add the method** to `src/Stats.php`
```php
/**
 * Replaces the web view count for an object.
 *
 * @since 1.3.0
 *
 * @param int    $object_id   The post, term, or user ID.
 * @param string $object_type The object type: 'post', 'term', or 'user'.
 * @param int    $value       The authoritative web total.
 *
 * @return void
 */
public static function set_web( int $object_id, string $object_type, int $value ): void {
    Sync::update_meta( $object_id, $object_type, 'mai_views_web', 'replace', $value );
}
```
- [ ] **Step 4: Run it, verify it passes.**
- [ ] **Step 5: Add `Stats::add_web()` (the self-hosted increment sibling, mirroring `add_app`)**

Provider web is a replace (`set_web`); self-hosted web is an increment. Both go through the store — add the increment helper:
```php
/**
 * Adds newly-counted web views to an object's running web total (self-hosted path).
 *
 * @since 1.3.0
 *
 * @param int    $object_id   The post, term, or user ID.
 * @param string $object_type The object type: 'post', 'term', or 'user'.
 * @param int    $delta       New web views counted since the last sync.
 *
 * @return void
 */
public static function add_web( int $object_id, string $object_type, int $delta ): void {
    Sync::update_meta( $object_id, $object_type, 'mai_views_web', 'increment', $delta );
}
```

- [ ] **Step 6: Route the engines.** Provider path (`src/ProviderSync.php`): replace the two `Sync::update_meta( $id, $type, 'mai_views_web', 'replace', $web_total );` calls (`process_batch` ~line 293, `process_warm_batch` ~line 651) with `Stats::set_web( $id, $type, $web_total );` (keep the `if ( null !== $web_total )` guard). Self-hosted path (`src/Sync.php` `sync()` source-split loop ~line 75): the `mai_views_web` increment branch routes through `Stats::add_web( $id, $type, $cnt )` (Task 2 handles the `mai_views_app` branch via `add_app`). No count changes: provider still replaces, self-hosted still increments.
- [ ] **Step 7: Run the full suite; confirm every count-asserting test still passes** (`test_sync_splits_views_by_source`, `test_sync_computes_total_from_web_and_app`, provider-sync web assertions). Counts must be identical.
- [ ] **Step 8: Commit** `git commit -m "refactor: route web-view writes through Stats::set_web / add_web"`.

---

### Task 2: `Stats::add_app()` + route app writes

**Interfaces:**
- Produces: `Stats::add_app( int $object_id, string $object_type, int $delta ): void` — increments `mai_views_app`.

- [ ] **Step 1: Failing test**
```php
public function test_add_app_increments_value(): void {
    $post_id = self::factory()->post->create();
    update_post_meta( $post_id, 'mai_views_app', 5 );
    Stats::add_app( $post_id, 'post', 3 );
    $this->assertSame( '8', get_post_meta( $post_id, 'mai_views_app', true ) );
}
```
- [ ] **Step 2: Verify fail.**
- [ ] **Step 3: Add method**
```php
/**
 * Adds newly-buffered app views to an object's running app total.
 *
 * @since 1.3.0
 *
 * @param int    $object_id   The post, term, or user ID.
 * @param string $object_type The object type: 'post', 'term', or 'user'.
 * @param int    $delta       New app views counted since the last sync.
 *
 * @return void
 */
public static function add_app( int $object_id, string $object_type, int $delta ): void {
    Sync::update_meta( $object_id, $object_type, 'mai_views_app', 'increment', $delta );
}
```
- [ ] **Step 4: Verify pass.**
- [ ] **Step 5: Route the engines.** Replace `Sync::update_meta( $id, $type, 'mai_views_app', 'increment', $app_new );` in `ProviderSync::process_batch` (~line 295) and `process_warm_batch` with `Stats::add_app( $id, $type, $app_new );`. In self-hosted `Sync::sync()`, the source-split loop increments `mai_views_web`/`mai_views_app` via `$source_key`; route the app branch through `add_app` (and per Task 1's note, decide the web-increment branch).
- [ ] **Step 6: Full suite; counts identical.**
- [ ] **Step 7: Commit** `git commit -m "refactor: route app-view increments through Stats::add_app"`.

---

### Task 3: `Stats::recompute_total()` + route total writes

**Interfaces:**
- Produces: `Stats::recompute_total( int $object_id, string $object_type ): void` — sets `mai_views` to `max( web + app, trending )`.

- [ ] **Step 1: Failing test**
```php
public function test_recompute_total_floors_at_trending(): void {
    $post_id = self::factory()->post->create();
    update_post_meta( $post_id, 'mai_views_web', 4 );
    update_post_meta( $post_id, 'mai_views_app', 3 );
    update_post_meta( $post_id, 'mai_trending', 20 ); // trending > web+app
    Stats::recompute_total( $post_id, 'post' );
    $this->assertSame( '20', get_post_meta( $post_id, 'mai_views', true ) ); // floored at trending
}
```
- [ ] **Step 2: Verify fail.**
- [ ] **Step 3: Add method**
```php
/**
 * Recomputes an object's total views as max( web + app, trending ), preserving
 * the floor invariant that total is never below the trending value.
 *
 * @since 1.3.0
 *
 * @param int    $object_id   The post, term, or user ID.
 * @param string $object_type The object type: 'post', 'term', or 'user'.
 *
 * @return void
 */
public static function recompute_total( int $object_id, string $object_type ): void {
    $web      = (int) Sync::get_meta( $object_id, $object_type, 'mai_views_web' );
    $app      = (int) Sync::get_meta( $object_id, $object_type, 'mai_views_app' );
    $trending = (int) Sync::get_meta( $object_id, $object_type, 'mai_trending' );
    Sync::update_meta( $object_id, $object_type, 'mai_views', 'replace', max( $web + $app, $trending ) );
}
```
- [ ] **Step 4: Verify pass.**
- [ ] **Step 5: Route the engines.** Replace the inline `$total = max(...); update_meta( ..., 'mai_views', 'replace', $total );` blocks in `Sync::sync()` (~line 82-86), `ProviderSync::process_batch` (~line 309-312), and `process_warm_batch` with `Stats::recompute_total( $id, $type );`. Confirm each site read the same three metas immediately before — they do.
- [ ] **Step 6: Full suite; `mai_views` totals identical everywhere.**
- [ ] **Step 7: Commit** `git commit -m "refactor: route mai_views total recompute through Stats::recompute_total"`.

---

### Task 4: `Stats::mark_synced()` + route synced_at writes

**Interfaces:**
- Produces: `Stats::mark_synced( int $object_id, string $object_type, int $now ): void` — replaces `mai_views_synced_at`.

- [ ] **Step 1: Failing test**
```php
public function test_mark_synced_writes_timestamp(): void {
    $post_id = self::factory()->post->create();
    Stats::mark_synced( $post_id, 'post', 1234567890 );
    $this->assertSame( '1234567890', get_post_meta( $post_id, 'mai_views_synced_at', true ) );
}
```
- [ ] **Step 2: Verify fail.**
- [ ] **Step 3: Add method**
```php
/**
 * Records the provider-sync timestamp for an object (provider success marker).
 *
 * @since 1.3.0
 *
 * @param int    $object_id   The post, term, or user ID.
 * @param string $object_type The object type: 'post', 'term', or 'user'.
 * @param int    $now         The sync timestamp.
 *
 * @return void
 */
public static function mark_synced( int $object_id, string $object_type, int $now ): void {
    Sync::update_meta( $object_id, $object_type, 'mai_views_synced_at', 'replace', $now );
}
```
- [ ] **Step 4: Verify pass.**
- [ ] **Step 5: Route the engines.** Replace `Sync::update_meta( $id, $type, 'mai_views_synced_at', 'replace', $now );` in `ProviderSync::process_batch` (~line 316) and `process_warm_batch` (~line 671) with `Stats::mark_synced( $id, $type, $now );` (keep the `if ( null !== $web_total )` guard). Self-hosted does not write synced_at.
- [ ] **Step 6: Full suite; the `test_warm_writes_synced_at_timestamp` and provider synced_at assertions still pass. IMPORTANT: this meta gates the trending prune — confirm the prune tests (`test_prune_provider_deletes_stale_synced_at`, `test_prune_provider_keeps_trending_with_missing_synced_at`) still pass.**
- [ ] **Step 7: Commit** `git commit -m "refactor: route mai_views_synced_at writes through Stats::mark_synced"`.

---

### Task 5: Release 1.3.0

- [ ] **Step 1:** Bump `mai-analytics.php` header `Version:` and `MAI_ANALYTICS_VERSION` to `1.3.0` (two spots).
- [ ] **Step 2:** Add the `## 1.3.0 (M/D/YY)` entry to `CHANGES.md` (top), format per [[feedback_changelog_format]]. Two `* Changed: [Developers]` lines:
  - the view-count writes (`mai_views_web`/`mai_views_app`/`mai_views`/`mai_views_synced_at`) now route through the `Stats` store (no behavior change; counts identical);
  - re-vendored mai-logger 0.1.1 (CLI-safe autoload so PHPUnit runs on a clean clone / CI) — this carries the mai-logger re-vendor committed during the mai-logger plan (decision 1A).
- [ ] **Step 3:** Commit `Release 1.3.0: route view counts through the Stats store`, push develop, fast-forward `main` (`git push origin develop:main`), tag `v1.3.0`. **Ask before tagging** ([[feedback_no_release_tagging]]); promote only your own commits to main ([[feedback_no_push_main]]).

## Self-review note
- Coverage vs spec: this implements the spec's rollout step 3 (`views_web`/`views_app`/`views`/`synced_at` migration) — the one deferred item. `set_trending`/`prune_trending` already shipped in 1.2.0.
- Resolved (approved): self-hosted `Sync::sync()` INCREMENTS `mai_views_web`, the provider path REPLACES it. Both go through the store — provider via `set_web` (replace), self-hosted via `add_web` (increment, mirroring `add_app`). Do NOT force self-hosted web through a replace; that would change counts. Every view-meta write ends up behind a `Stats` method.

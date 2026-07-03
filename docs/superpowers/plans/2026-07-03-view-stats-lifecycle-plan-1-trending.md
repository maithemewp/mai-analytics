# Trending Lifecycle (Plan 1 of 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give `mai_trending` an owned lifecycle so it stays bounded to actually-trending posts on every site, in both sync modes, and clean up existing fleet bloat automatically. This is the permanent fix for the LBS outage.

**Architecture:** Introduce a `Mai\Analytics\Stats` store that owns trending writes (`set_trending`, with `0 => delete`) and pruning (`prune_trending`, mode-aware, batched, dry-run-able). Route both sync engines' trending writes through it. Add a daily prune cron, a `wp mai-analytics prune-trending` CLI command, and an update trigger that schedules an immediate out-of-band prune. This plan is Phase 0 (tooling) + Phase 1 (trending lifecycle) from the spec; Plan 2 covers the `views`/`views_web`/`views_app`/`synced_at` migration.

**Tech Stack:** PHP (WordPress plugin), PHPUnit 9.6 + WP test suite (`WP_UnitTestCase`), WP-CLI, `wpdb`.

**Spec:** `docs/superpowers/specs/2026-07-03-view-stats-lifecycle-design.md`

## Global Constraints

- Trending is only ever written or deleted from a confirmed result, never on a failure. Provider write-time keeps the `null !== $web_trending` guard; provider prune-time `synced_at` decay runs only when the circuit breaker is not fresh (`Sync::seconds_until_provider_error_clear() === 0`).
- View-count values (`mai_views`, `mai_views_web`, `mai_views_app`) must not change in this plan. Only `mai_trending` behavior changes here.
- Prune deletes in batches; default batch size 5000, filter `mai_analytics_prune_batch_size`. Prune cron runs daily.
- Follow existing code style (tabs, WordPress naming). No em-dashes in code comments or commit messages; use commas/periods/parens.
- `mai_trending` is registered with a default of `0` (`src/Meta.php` `register_post_meta(..., ['default' => 0])`), so WP's default-metadata filter makes `get_post_meta($id, 'mai_trending', true)` return `0` (not `''`) and `get_post_meta(..., false)` return `[0]` (not `[]`) AFTER the row is deleted. Therefore: to assert a trending row was DELETED, use `$this->assertFalse( metadata_exists( 'post', $id, 'mai_trending' ) );` (it checks the DB row, bypassing the default). To assert PRESENT-with-value `N`, `get_post_meta($id, 'mai_trending', true)` returns the stored value when the row exists, so `assertSame( 'N', ... )` is correct. Replace every `assertSame( '', get_post_meta(...) )` / `assertSame( [], get_post_meta(...) )` deletion check in the task tests below with the `metadata_exists` form. The production `meta_value+0` filesort queries the postmeta table directly, so the registered default does not affect it — deletion still shrinks the sort.
- Local test command (referenced as `TEST` below), run from the mai-analytics repo root:
  ```
  WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_TESTS_PHPUNIT_POLYFILLS_PATH="$PWD/vendor/yoast/phpunit-polyfills" "$HOME/Library/Application Support/Herd/bin/php83" vendor/bin/phpunit
  ```
  Append `--filter '<Class>::<method>'` to run one test. PHPUnit 9.6 requires PHP <= 8.3; Herd's `php83` is used because local default PHP is 8.5.

---

## File Structure

- `composer.json` — add `require-dev` (phpunit, polyfills) and a `test` script. (Task 1)
- `src/Sync.php` — add low-level `delete_meta` next to `update_meta`/`get_meta`. (Task 2)
- `src/Stats.php` — NEW. The lifecycle store: `set_trending`, `prune_trending`, private helpers. (Tasks 3, 5, 6)
- `src/ProviderSync.php` — route the trending write through `Stats::set_trending`. (Task 4)
- `src/Sync.php` — route the self-hosted trending write and inline decay through `Stats::set_trending`. (Task 4)
- `src/Cron.php` — register + schedule the daily `mai_analytics_prune_trending` event and its callback. (Task 7)
- `src/Upgrade.php` — NEW. `maybe_upgrade` version-compare that schedules the immediate prune + ensures the daily event. (Task 8)
- `src/Plugin.php` — instantiate `Upgrade` in `init`. (Task 8)
- `src/CLI.php` — add the `prune-trending` command. (Task 9)
- `tests/test-stats.php` — NEW. Unit tests for `Stats`. (Tasks 3, 5, 6)
- `tests/test-provider-sync.php`, `tests/test-sync.php` — add routing/decay tests. (Task 4)
- `tests/test-cron.php`, `tests/test-upgrade.php` — cron + upgrade tests. (Tasks 7, 8)

---

## Task 1: Test tooling

**Files:**
- Modify: `composer.json`

**Interfaces:**
- Produces: a runnable PHPUnit suite (`TEST` command) and `composer test`. All later tasks depend on this.

- [ ] **Step 1: Add dev dependencies and a test script to `composer.json`**

Add a `require-dev` block and a `scripts.test` entry (merge with the existing `scripts`):

```json
"require-dev": {
    "phpunit/phpunit": "^9.6",
    "yoast/phpunit-polyfills": "^2.0"
},
"scripts": {
    "update-bots": "php bin/update-bot-patterns.php",
    "post-update-cmd": [
        "@update-bots"
    ],
    "test": "phpunit"
}
```

- [ ] **Step 2: Install with PHP 8.3**

Run:
```
"$HOME/Library/Application Support/Herd/bin/php83" $(command -v composer) update phpunit/phpunit yoast/phpunit-polyfills --with-all-dependencies
```
Expected: `vendor/bin/phpunit` exists and `vendor/yoast/phpunit-polyfills` exists.

- [ ] **Step 3: Run the existing suite to confirm the harness works**

Run: `WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_TESTS_PHPUNIT_POLYFILLS_PATH="$PWD/vendor/yoast/phpunit-polyfills" "$HOME/Library/Application Support/Herd/bin/php83" vendor/bin/phpunit`
Expected: PASS (existing tests green). If the WP test DB is missing, run `bin/install-wp-tests.sh` first per the repo README.

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock
git commit -m "test: add phpunit + polyfills dev deps and composer test script"
```

---

## Task 2: `Sync::delete_meta`

**Files:**
- Modify: `src/Sync.php` (add method after `update_meta`, ~line 322)
- Test: `tests/test-sync.php`

**Interfaces:**
- Produces: `Sync::delete_meta( int $object_id, string $object_type, string $key ): void`

- [ ] **Step 1: Write the failing test**

Add to `tests/test-sync.php`:

```php
public function test_delete_meta_removes_post_meta(): void {
    $post_id = self::factory()->post->create();
    update_post_meta( $post_id, 'mai_trending', 42 );

    \Mai\Analytics\Sync::delete_meta( $post_id, 'post', 'mai_trending' );

    $this->assertSame( '', get_post_meta( $post_id, 'mai_trending', true ) );
    $this->assertSame( [], get_post_meta( $post_id, 'mai_trending', false ) );
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `TEST --filter 'Test_Sync::test_delete_meta_removes_post_meta'`
Expected: FAIL with "Call to undefined method Mai\Analytics\Sync::delete_meta".

- [ ] **Step 3: Implement `delete_meta`**

In `src/Sync.php`, immediately after the `update_meta` method's closing brace:

```php
	/**
	 * Deletes a meta value for a post, term, or user.
	 *
	 * @param int    $object_id   The post, term, or user ID.
	 * @param string $object_type The object type: 'post', 'term', or 'user'.
	 * @param string $key         The meta key to delete.
	 *
	 * @return void
	 */
	public static function delete_meta( int $object_id, string $object_type, string $key ): void {
		match ( $object_type ) {
			'post' => delete_post_meta( $object_id, $key ),
			'term' => delete_term_meta( $object_id, $key ),
			'user' => delete_user_meta( $object_id, $key ),
			default => null,
		};
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `TEST --filter 'Test_Sync::test_delete_meta_removes_post_meta'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Sync.php tests/test-sync.php
git commit -m "feat: add Sync::delete_meta low-level helper"
```

---

## Task 3: `Stats` store + `set_trending`

**Files:**
- Create: `src/Stats.php`
- Test: `tests/test-stats.php`

**Interfaces:**
- Consumes: `Sync::update_meta`, `Sync::delete_meta` (Task 2).
- Produces: `Stats::set_trending( int $object_id, string $object_type, int $value ): void` — writes `mai_trending` when `$value > 0`, otherwise deletes it.

- [ ] **Step 1: Write the failing tests**

Create `tests/test-stats.php`:

```php
<?php

use Mai\Analytics\Stats;

class Test_Stats extends WP_UnitTestCase {

	public function test_set_trending_writes_positive_value(): void {
		$post_id = self::factory()->post->create();

		Stats::set_trending( $post_id, 'post', 7 );

		$this->assertSame( '7', get_post_meta( $post_id, 'mai_trending', true ) );
	}

	public function test_set_trending_zero_deletes_the_row(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'mai_trending', 99 );

		Stats::set_trending( $post_id, 'post', 0 );

		$this->assertSame( '', get_post_meta( $post_id, 'mai_trending', true ) );
		$this->assertSame( [], get_post_meta( $post_id, 'mai_trending', false ) );
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `TEST --filter Test_Stats`
Expected: FAIL with "Class Mai\Analytics\Stats not found".

- [ ] **Step 3: Implement `Stats::set_trending`**

Create `src/Stats.php`:

```php
<?php

namespace Mai\Analytics;

/**
 * Owns the view-meta lifecycle. This plan implements the trending portion:
 * writes (with 0 => delete) and pruning. The mai_views/web/app migration lands
 * in Plan 2.
 */
class Stats {

	/**
	 * Default number of rows deleted per prune batch.
	 */
	public const PRUNE_BATCH = 5000;

	/**
	 * Sets a post/term/user trending value. A value of 0 (or less) deletes the
	 * row rather than storing a zero, so the trending meta stays bounded to
	 * posts actually trending in the window and the meta_value+0 sort that
	 * orders trending grids never has to scan dead rows.
	 *
	 * Callers must only pass a value derived from a confirmed result, never a
	 * failure sentinel (see the provider null-guard in ProviderSync).
	 *
	 * @param int    $object_id   The post, term, or user ID.
	 * @param string $object_type The object type: 'post', 'term', or 'user'.
	 * @param int    $value       The trending count.
	 *
	 * @return void
	 */
	public static function set_trending( int $object_id, string $object_type, int $value ): void {
		if ( $value > 0 ) {
			Sync::update_meta( $object_id, $object_type, 'mai_trending', 'replace', $value );
		} else {
			Sync::delete_meta( $object_id, $object_type, 'mai_trending' );
		}
	}
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `TEST --filter Test_Stats`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Stats.php tests/test-stats.php
git commit -m "feat: add Stats store with set_trending (0 deletes)"
```

---

## Task 4: Route both engines' trending writes through `Stats::set_trending`

**Files:**
- Modify: `src/ProviderSync.php:300` (trending write)
- Modify: `src/Sync.php:116` (self-hosted trending write) and `src/Sync.php:148` (self-hosted inline decay)
- Test: `tests/test-provider-sync.php`, `tests/test-sync.php`

**Interfaces:**
- Consumes: `Stats::set_trending` (Task 3).

- [ ] **Step 1: Write the failing tests**

Add to `tests/test-provider-sync.php`:

```php
public function test_sync_deletes_trending_when_zero(): void {
    $this->register_mock_provider( 0 ); // provider returns 0 for the trending window

    $post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
    update_post_meta( $post_id, 'mai_trending', 99 ); // stale value that must be pruned

    // A web buffer row picks the object up for sync without adding app-window views,
    // so the computed trending is 0.
    Database::insert_view( $post_id, 'post', 'web' );

    ProviderSync::sync();

    $this->assertSame( '', get_post_meta( $post_id, 'mai_trending', true ) );
}

public function test_sync_preserves_trending_on_provider_failure(): void {
    // Provider is available but returns an empty payload (failure), so web_trending is null.
    $this->register_mock_provider( 0, true, 50, function () { return []; } );

    $post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
    update_post_meta( $post_id, 'mai_trending', 50 );
    Database::insert_view( $post_id, 'post', 'web' );

    ProviderSync::sync();

    // Failed read must not delete or zero the existing value.
    $this->assertSame( '50', get_post_meta( $post_id, 'mai_trending', true ) );
}
```

Add to `tests/test-sync.php` (Test_Sync; it seeds the buffer and calls `Sync::sync()` like the existing self-hosted tests):

```php
public function test_self_hosted_decay_deletes_not_zeroes(): void {
    update_option( 'mai_analytics_settings', [ 'data_source' => 'self_hosted' ] );

    $post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
    update_post_meta( $post_id, 'mai_trending', 20 ); // previously trending

    // A view 10 days ago: outside the 7-day trending window but inside 14-day retention,
    // so the inline decay loop sees it and must delete (not zero) the trending value.
    global $wpdb;
    $table = \Mai\Analytics\Database::get_table_name();
    $wpdb->query( $wpdb->prepare(
        "INSERT INTO $table (object_id, object_type, object_key, source, viewed_at)
         VALUES (%d, 'post', '', 'web', DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 DAY))",
        $post_id
    ) );

    \Mai\Analytics\Sync::sync();

    $this->assertSame( '', get_post_meta( $post_id, 'mai_trending', true ), 'decayed post should have no trending row' );
    $this->assertSame( [], get_post_meta( $post_id, 'mai_trending', false ) );
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `TEST --filter 'test_sync_deletes_trending_when_zero|test_sync_preserves_trending_on_provider_failure|test_self_hosted_decay_deletes_not_zeroes'`
Expected: FAIL (`test_sync_deletes_trending_when_zero` sees `'0'` not `''`; `test_self_hosted_decay_deletes_not_zeroes` sees `'0'` not `''`). `test_sync_preserves_trending_on_provider_failure` may already pass (the null-guard exists); that is fine, it is a regression guard.

- [ ] **Step 3: Route the provider trending write**

In `src/ProviderSync.php`, replace the single line at ~300:

```php
			Sync::update_meta( $id, $type, 'mai_trending', 'replace', $new_trending );
```

with:

```php
			// Route through the store so 0 deletes the row instead of bloating the
			// meta_value+0 sort. The null-guard above means a failed provider read
			// preserves the existing value, so this never deletes off a failure.
			Stats::set_trending( $id, $type, $new_trending );
```

- [ ] **Step 4: Route the self-hosted trending write and inline decay**

In `src/Sync.php`, replace the line at ~116:

```php
					self::update_meta( (int) $row->object_id, $row->object_type, 'mai_trending', 'replace', (int) $row->trending_count );
```

with:

```php
					Stats::set_trending( (int) $row->object_id, $row->object_type, (int) $row->trending_count );
```

and replace the inline-decay line at ~148:

```php
					self::update_meta( (int) $row->object_id, $row->object_type, 'mai_trending', 'replace', 0 );
```

with:

```php
					// Delete rather than store a zero. The buffer is authoritative, so a
					// post absent from the current window genuinely is not trending.
					Stats::set_trending( (int) $row->object_id, $row->object_type, 0 );
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `TEST --filter 'test_sync_deletes_trending_when_zero|test_sync_preserves_trending_on_provider_failure|test_self_hosted_decay_deletes_not_zeroes'`
Expected: PASS.

- [ ] **Step 6: Run the full suite to confirm no regressions**

Run: `TEST`
Expected: PASS (existing `test_sync_merges_trending` etc. still green).

- [ ] **Step 7: Commit**

```bash
git add src/ProviderSync.php src/Sync.php tests/test-provider-sync.php tests/test-sync.php
git commit -m "feat: route trending writes through Stats::set_trending (stop writing zeros)"
```

---

## Task 5: `Stats::prune_trending` (zero-delete + provider stale decay, gated + batched + dry-run)

**Files:**
- Modify: `src/Stats.php`
- Test: `tests/test-stats.php`

**Interfaces:**
- Consumes: `Settings::get('data_source')`, `Settings::get('retention')`, `Sync::seconds_until_provider_error_clear()`.
- Produces: `Stats::prune_trending( int $window_days, array $opts = [] ): int` — deletes stale/zero `mai_trending` rows; `$opts['dry_run']` counts without deleting; `$opts['batch_size']` overrides the batch. Returns the number of rows deleted (or that would be deleted, for dry-run). This task implements the universal zero-delete and the provider (`synced_at`) branch; Task 6 adds the self-hosted branch.

- [ ] **Step 1: Write the failing tests**

Add to `tests/test-stats.php`:

```php
public function test_prune_deletes_zero_rows(): void {
    $keep = self::factory()->post->create();
    $zero = self::factory()->post->create();
    update_post_meta( $keep, 'mai_trending', 5 );
    update_post_meta( $zero, 'mai_trending', 0 );

    $deleted = Stats::prune_trending( 7 );

    $this->assertGreaterThanOrEqual( 1, $deleted );
    $this->assertSame( '', get_post_meta( $zero, 'mai_trending', true ) );
    $this->assertSame( '5', get_post_meta( $keep, 'mai_trending', true ) );
}

public function test_prune_provider_deletes_stale_synced_at(): void {
    update_option( 'mai_analytics_settings', [ 'data_source' => 'matomo' ] );
    delete_option( 'mai_analytics_provider_error' ); // breaker not fresh

    $stale = self::factory()->post->create();
    $fresh = self::factory()->post->create();
    update_post_meta( $stale, 'mai_trending', 5 );
    update_post_meta( $fresh, 'mai_trending', 5 );
    update_post_meta( $stale, 'mai_views_synced_at', time() - ( 10 * DAY_IN_SECONDS ) ); // older than 7-day window
    update_post_meta( $fresh, 'mai_views_synced_at', time() ); // fresh

    Stats::prune_trending( 7 );

    $this->assertSame( '', get_post_meta( $stale, 'mai_trending', true ) );
    $this->assertSame( '5', get_post_meta( $fresh, 'mai_trending', true ) );
}

public function test_prune_provider_skips_stale_when_breaker_fresh(): void {
    update_option( 'mai_analytics_settings', [ 'data_source' => 'matomo' ] );
    // Record a fresh provider error so the circuit breaker is active.
    update_option( 'mai_analytics_provider_error', wp_json_encode( [ 'message' => 'down', 'time' => time() ] ) );

    $stale = self::factory()->post->create();
    $zero  = self::factory()->post->create();
    update_post_meta( $stale, 'mai_trending', 5 );
    update_post_meta( $stale, 'mai_views_synced_at', time() - ( 10 * DAY_IN_SECONDS ) );
    update_post_meta( $zero, 'mai_trending', 0 );

    Stats::prune_trending( 7 );

    // Outage must not wipe a stale-but-real value...
    $this->assertSame( '5', get_post_meta( $stale, 'mai_trending', true ) );
    // ...but zeros are always safe to delete.
    $this->assertSame( '', get_post_meta( $zero, 'mai_trending', true ) );
}

public function test_prune_dry_run_counts_without_deleting(): void {
    update_option( 'mai_analytics_settings', [ 'data_source' => 'matomo' ] );
    $zero = self::factory()->post->create();
    update_post_meta( $zero, 'mai_trending', 0 );

    $would = Stats::prune_trending( 7, [ 'dry_run' => true ] );

    $this->assertGreaterThanOrEqual( 1, $would );
    $this->assertSame( '0', get_post_meta( $zero, 'mai_trending', true ) ); // still there
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `TEST --filter 'Test_Stats::test_prune'`
Expected: FAIL with "Call to undefined method Mai\Analytics\Stats::prune_trending".

- [ ] **Step 3: Implement `prune_trending` (zero + provider branch)**

Add to `src/Stats.php`. The delete works on collected `post_id`s per object type (posts dominate; terms/users use the same helper with their meta table). Uses `_get_meta_table()` for the correct table and id column.

```php
	/**
	 * Deletes stale and zero mai_trending rows so the trending index stays bounded.
	 *
	 * Zero rows are always deleted. Stale rows are mode-specific: in provider mode a
	 * row is stale when its mai_views_synced_at predates the window, and only when the
	 * provider circuit breaker is not fresh (an outage must never wipe trending). The
	 * self-hosted branch is added in a later task.
	 *
	 * @param int   $window_days Trending window in days.
	 * @param array $opts        'dry_run' (bool), 'batch_size' (int).
	 *
	 * @return int Rows deleted (or, for dry_run, that would be deleted).
	 */
	public static function prune_trending( int $window_days, array $opts = [] ): int {
		$dry_run = ! empty( $opts['dry_run'] );
		$batch   = (int) apply_filters( 'mai_analytics_prune_batch_size', $opts['batch_size'] ?? self::PRUNE_BATCH );
		$batch   = max( 1, $batch );
		$source  = Settings::get( 'data_source' );

		$total = 0;

		foreach ( [ 'post', 'term', 'user' ] as $type ) {
			// Always: zero rows.
			$total += self::delete_trending_ids( $type, self::zero_trending_ids( $type ), $batch, $dry_run );

			// Provider mode: stale-by-synced_at, only when the breaker is clear.
			if ( 'self_hosted' !== $source && 0 === Sync::seconds_until_provider_error_clear() ) {
				$total += self::delete_trending_ids( $type, self::stale_provider_ids( $type, $window_days ), $batch, $dry_run );
			}
		}

		return $total;
	}

	/**
	 * Object ids whose mai_trending value is 0.
	 */
	private static function zero_trending_ids( string $type ): array {
		global $wpdb;
		[ $table, $id_col ] = self::meta_table( $type );
		if ( ! $table ) {
			return [];
		}
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT {$id_col} FROM {$table} WHERE meta_key = 'mai_trending' AND meta_value + 0 = 0"
		) ) );
	}

	/**
	 * Object ids with a mai_trending value whose mai_views_synced_at predates the window
	 * (or is missing). Provider mode only.
	 */
	private static function stale_provider_ids( string $type, int $window_days ): array {
		global $wpdb;
		[ $table, $id_col ] = self::meta_table( $type );
		if ( ! $table ) {
			return [];
		}
		$cutoff = time() - ( $window_days * DAY_IN_SECONDS );
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT t.{$id_col}
			 FROM {$table} t
			 LEFT JOIN {$table} s ON s.{$id_col} = t.{$id_col} AND s.meta_key = 'mai_views_synced_at'
			 WHERE t.meta_key = 'mai_trending'
			   AND t.meta_value + 0 > 0
			   AND ( s.meta_value IS NULL OR s.meta_value + 0 < %d )",
			$cutoff
		) ) );
	}

	/**
	 * Deletes the mai_trending rows for the given object ids, in batches.
	 * For dry_run, returns the count without deleting.
	 */
	private static function delete_trending_ids( string $type, array $ids, int $batch, bool $dry_run ): int {
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		if ( $dry_run ) {
			return count( $ids );
		}

		$deleted = 0;
		foreach ( array_chunk( $ids, $batch ) as $chunk ) {
			$deleted += self::delete_trending_chunk( $type, $chunk );
		}
		return $deleted;
	}

	/**
	 * Deletes mai_trending for one chunk of object ids.
	 */
	private static function delete_trending_chunk( string $type, array $ids ): int {
		$deleted = 0;
		foreach ( $ids as $id ) {
			Sync::delete_meta( (int) $id, $type, 'mai_trending' );
			$deleted++;
		}
		return $deleted;
	}

	/**
	 * Returns [ meta_table, id_column ] for an object type, or [ null, null ].
	 */
	private static function meta_table( string $type ): array {
		global $wpdb;
		return match ( $type ) {
			'post' => [ $wpdb->postmeta, 'post_id' ],
			'term' => [ $wpdb->termmeta, 'term_id' ],
			'user' => [ $wpdb->usermeta, 'user_id' ],
			default => [ null, null ],
		};
	}
```

Note for the implementer: `delete_trending_chunk` loops `Sync::delete_meta` so WordPress meta caches stay correct; the batch bound keeps memory/locks in check. If profiling on a very large table shows this is too slow, swap the chunk body for a single `DELETE ... WHERE {$id_col} IN (...) AND meta_key='mai_trending'` plus `wp_cache_delete` per id, but keep the tests green.

- [ ] **Step 4: Run tests to verify they pass**

Run: `TEST --filter 'Test_Stats::test_prune'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Stats.php tests/test-stats.php
git commit -m "feat: add Stats::prune_trending (zero-delete + provider stale decay, gated, batched, dry-run)"
```

---

## Task 6: `Stats::prune_trending` self-hosted branch

**Files:**
- Modify: `src/Stats.php`
- Test: `tests/test-stats.php`

**Interfaces:**
- Consumes: `Database::get_table_name()`, the buffer window query.
- Produces: self-hosted branch of `prune_trending` — deletes `mai_trending` for objects not present in the current trending-window buffer set.

- [ ] **Step 1: Write the failing test**

Add to `tests/test-stats.php`:

```php
public function test_prune_self_hosted_deletes_posts_not_in_window(): void {
    update_option( 'mai_analytics_settings', [ 'data_source' => 'self_hosted' ] );
    global $wpdb;
    $table = \Mai\Analytics\Database::get_table_name();

    $in_window  = self::factory()->post->create();
    $out_window = self::factory()->post->create();
    update_post_meta( $in_window, 'mai_trending', 3 );
    update_post_meta( $out_window, 'mai_trending', 8 ); // stale, beyond retention (no buffer rows)

    // in_window has a buffer view inside the 7-day window.
    $wpdb->query( $wpdb->prepare(
        "INSERT INTO $table (object_id, object_type, object_key, source, viewed_at)
         VALUES (%d, 'post', '', 'web', UTC_TIMESTAMP())",
        $in_window
    ) );

    Stats::prune_trending( 7 );

    $this->assertSame( '3', get_post_meta( $in_window, 'mai_trending', true ) );
    $this->assertSame( '', get_post_meta( $out_window, 'mai_trending', true ) );
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `TEST --filter 'Test_Stats::test_prune_self_hosted_deletes_posts_not_in_window'`
Expected: FAIL (`out_window` still has `'8'` because the self-hosted branch is not implemented).

- [ ] **Step 3: Implement the self-hosted branch**

In `Stats::prune_trending`, replace the provider-only `if` with a mode branch:

```php
			if ( 'self_hosted' === $source ) {
				$total += self::delete_trending_ids( $type, self::self_hosted_stale_ids( $type, $window_days ), $batch, $dry_run );
			} elseif ( 0 === Sync::seconds_until_provider_error_clear() ) {
				$total += self::delete_trending_ids( $type, self::stale_provider_ids( $type, $window_days ), $batch, $dry_run );
			}
```

And add the helper:

```php
	/**
	 * Self-hosted stale ids: objects with a mai_trending value that are NOT in the
	 * current trending-window buffer set. The buffer is authoritative, so any trending
	 * row without a windowed view is stale (this also catches beyond-retention posts
	 * whose buffer rows were pruned, which the inline decay never sees).
	 */
	private static function self_hosted_stale_ids( string $type, int $window_days ): array {
		global $wpdb;
		[ $meta_table, $id_col ] = self::meta_table( $type );
		if ( ! $meta_table ) {
			return [];
		}
		$buffer = Database::get_table_name();

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT m.{$id_col}
			 FROM {$meta_table} m
			 WHERE m.meta_key = 'mai_trending'
			   AND m.meta_value + 0 > 0
			   AND m.{$id_col} NOT IN (
			       SELECT DISTINCT b.object_id
			       FROM {$buffer} b
			       WHERE b.object_type = %s
			         AND b.viewed_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
			   )",
			$type,
			$window_days
		) ) );
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `TEST --filter 'Test_Stats::test_prune_self_hosted_deletes_posts_not_in_window'`
Expected: PASS.

- [ ] **Step 5: Run the full suite**

Run: `TEST`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Stats.php tests/test-stats.php
git commit -m "feat: add self-hosted branch to Stats::prune_trending (buffer recompute decay)"
```

---

## Task 7: Daily prune cron

**Files:**
- Modify: `src/Cron.php`
- Test: `tests/test-cron.php`

**Interfaces:**
- Consumes: `Stats::prune_trending`, `Settings::get('trending_window')`.
- Produces: the `mai_analytics_prune_trending` action + a `Cron::prune_trending()` callback; the daily event is scheduled by `ensure_healthy`.

- [ ] **Step 1: Write the failing test**

Add to `tests/test-cron.php`:

```php
public function test_ensure_healthy_schedules_daily_prune(): void {
    wp_clear_scheduled_hook( 'mai_analytics_prune_trending' );

    ( new \Mai\Analytics\Cron() )->ensure_healthy();

    $this->assertNotFalse( wp_next_scheduled( 'mai_analytics_prune_trending' ) );
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `TEST --filter 'Test_Cron::test_ensure_healthy_schedules_daily_prune'`
Expected: FAIL (`wp_next_scheduled` returns false).

- [ ] **Step 3: Register and schedule the prune event**

In `src/Cron.php` constructor, add the action:

```php
		add_action( 'mai_analytics_prune_trending', [ $this, 'prune_trending' ] );
```

In `ensure_healthy()`, after the existing sync-event reschedule block, add:

```php
		if ( ! wp_next_scheduled( 'mai_analytics_prune_trending' ) ) {
			$schedule = (string) apply_filters( 'mai_analytics_prune_schedule', 'daily' );
			wp_schedule_event( time(), $schedule, 'mai_analytics_prune_trending' );
		}
```

Add the callback method:

```php
	/**
	 * Daily trending prune. Deletes stale/zero trending rows so the trending
	 * index stays bounded. Skipped when tracking is disabled.
	 *
	 * @return void
	 */
	public function prune_trending(): void {
		if ( 'disabled' === Settings::get( 'data_source' ) ) {
			return;
		}
		Stats::prune_trending( (int) Settings::get( 'trending_window' ) );
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `TEST --filter 'Test_Cron::test_ensure_healthy_schedules_daily_prune'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Cron.php tests/test-cron.php
git commit -m "feat: schedule daily trending prune cron"
```

---

## Task 8: Update trigger (`Upgrade::maybe_upgrade`)

**Files:**
- Create: `src/Upgrade.php`
- Modify: `src/Plugin.php` (instantiate in `init`)
- Test: `tests/test-upgrade.php`

**Interfaces:**
- Consumes: `MAI_ANALYTICS_VERSION`, the `mai_analytics_prune_trending` event (Task 7).
- Produces: `Upgrade::maybe_upgrade(): void` — on version change, schedules an immediate one-off prune and updates the stored version.

- [ ] **Step 1: Write the failing tests**

Create `tests/test-upgrade.php`:

```php
<?php

use Mai\Analytics\Upgrade;

class Test_Upgrade extends WP_UnitTestCase {

	public function test_maybe_upgrade_schedules_immediate_prune_on_version_change(): void {
		delete_option( 'mai_analytics_version' );
		wp_clear_scheduled_hook( 'mai_analytics_prune_trending' );

		Upgrade::maybe_upgrade();

		$this->assertNotFalse( wp_next_scheduled( 'mai_analytics_prune_trending' ) );
		$this->assertSame( MAI_ANALYTICS_VERSION, get_option( 'mai_analytics_version' ) );
	}

	public function test_maybe_upgrade_is_noop_when_version_matches(): void {
		update_option( 'mai_analytics_version', MAI_ANALYTICS_VERSION );
		wp_clear_scheduled_hook( 'mai_analytics_prune_trending' );

		Upgrade::maybe_upgrade();

		// No immediate prune scheduled when nothing changed.
		$this->assertFalse( wp_next_scheduled( 'mai_analytics_prune_trending' ) );
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `TEST --filter Test_Upgrade`
Expected: FAIL with "Class Mai\Analytics\Upgrade not found".

- [ ] **Step 3: Implement `Upgrade`**

Create `src/Upgrade.php`:

```php
<?php

namespace Mai\Analytics;

/**
 * Fires one-time work when the plugin code version changes. Deploy-agnostic:
 * runs on first load of new code whether updated via the WP updater, WP-CLI,
 * or a git pull, because it compares a stored option against the code constant.
 */
class Upgrade {

	/**
	 * Compares the stored version against the code version. On a change, schedules
	 * an immediate out-of-band trending prune (a separate wp-cron request, never
	 * during the upgrade itself) so existing bloat is cleaned up right away, then
	 * records the new version.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$stored = get_option( 'mai_analytics_version', '' );

		if ( MAI_ANALYTICS_VERSION === $stored ) {
			return;
		}

		if ( ! wp_next_scheduled( 'mai_analytics_prune_trending' ) ) {
			wp_schedule_single_event( time(), 'mai_analytics_prune_trending' );
		}

		update_option( 'mai_analytics_version', MAI_ANALYTICS_VERSION, false );
	}
}
```

- [ ] **Step 4: Wire it into the bootstrap**

In `src/Plugin.php` `init()`, alongside `new Cron();` (~line 36), add:

```php
		Upgrade::maybe_upgrade();
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `TEST --filter Test_Upgrade`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Upgrade.php src/Plugin.php tests/test-upgrade.php
git commit -m "feat: schedule an immediate trending prune on plugin version change"
```

---

## Task 9: CLI `prune-trending` command

**Files:**
- Modify: `src/CLI.php`
- Test: manual (WP-CLI commands are integration-tested by hand; the logic lives in `Stats` and is unit-tested in Tasks 5-6)

**Interfaces:**
- Consumes: `Stats::prune_trending`, `Settings::get('trending_window')`.
- Produces: `wp mai-analytics prune-trending [--dry-run]`.

- [ ] **Step 1: Register the command**

In `src/CLI.php` constructor, add:

```php
		WP_CLI::add_command( 'mai-analytics prune-trending', [ $this, 'prune_trending' ] );
```

- [ ] **Step 2: Implement the command method**

Add to `src/CLI.php`:

```php
	/**
	 * Prune stale and zero mai_trending rows.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report how many rows would be deleted without deleting them.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mai-analytics prune-trending --dry-run
	 *     wp mai-analytics prune-trending
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments: --dry-run.
	 *
	 * @return void
	 */
	public function prune_trending( array $args, array $assoc_args ): void {
		$dry_run = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$window  = (int) Settings::get( 'trending_window' );

		$count = Stats::prune_trending( $window, [ 'dry_run' => $dry_run ] );

		if ( $dry_run ) {
			WP_CLI::success( sprintf( '%s trending rows would be deleted.', number_format( $count ) ) );
		} else {
			WP_CLI::success( sprintf( '%s trending rows deleted.', number_format( $count ) ) );
		}
	}
```

- [ ] **Step 3: Manual verification**

Run on a dev site (or LBS with `--dry-run` first):
```
wp mai-analytics prune-trending --dry-run
```
Expected: `Success: N trending rows would be deleted.` and no change to the row count. Then run without `--dry-run` and confirm the count drops.

- [ ] **Step 4: Commit**

```bash
git add src/CLI.php
git commit -m "feat: add wp mai-analytics prune-trending command"
```

---

## Final verification

- [ ] Run the full suite: `TEST` — all green.
- [ ] Confirm the plugin version constant `MAI_ANALYTICS_VERSION` is bumped for the release (this is what makes `maybe_upgrade` fire the fleet cleanup). Coordinate the exact version with the release step.
- [ ] Manual: on a staging or LBS copy, `wp mai-analytics prune-trending --dry-run` reports a sane count; a full run drops `SELECT COUNT(*) FROM wp_postmeta WHERE meta_key='mai_trending'` to the nonzero set.

## Self-review notes (author)

- Spec coverage: `set_trending` 0-delete (Task 3), routing both engines incl. self-hosted inline decay (Task 4), provider stale decay gated on the breaker (Task 5), self-hosted recompute decay (Task 6), daily cron (Task 7), update trigger (Task 8), CLI (Task 9), tooling (Task 1). The `views`/`web`/`app`/`synced_at` migration is intentionally deferred to Plan 2.
- Guard coverage: write-time null-guard is preserved in Task 4 (kept the existing `null !== $web_trending` line above the changed write); prune-time breaker gate is in Tasks 5-6 and its test `test_prune_provider_skips_stale_when_breaker_fresh`.
- Type consistency: `Stats::set_trending(int,string,int):void`, `Stats::prune_trending(int,array):int`, `Sync::delete_meta(int,string,string):void` are used consistently across tasks.

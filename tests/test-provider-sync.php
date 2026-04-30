<?php

use Mai\Analytics\Database;
use Mai\Analytics\ProviderSync;
use Mai\Analytics\Settings;
use Mai\Analytics\Sync;

class Test_Provider_Sync extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Database::create_table();
		delete_option( 'mai_analytics_provider_last_sync' );
		delete_option( 'mai_analytics_settings' );
		delete_option( 'mai_analytics_post_type_views' );
		delete_option( 'mai_analytics_post_type_views_web' );
		delete_option( 'mai_analytics_post_type_views_app' );
		delete_option( 'mai_analytics_post_type_trending' );
		delete_option( 'mai_analytics_post_type_views_synced_at' );
		delete_option( 'mai_analytics_provider_error' );

		// Remove any previously registered provider filters.
		remove_all_filters( 'mai_analytics_providers' );
		remove_all_filters( 'mai_analytics_warm_skip_threshold' );
		remove_all_filters( 'mai_analytics_provider_error_backoff' );
	}

	public function tearDown(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::get_table_name() );
		delete_transient( 'mai_analytics_provider_syncing' );
		remove_all_filters( 'mai_analytics_providers' );
		remove_all_filters( 'mai_analytics_warm_skip_threshold' );
		remove_all_filters( 'mai_analytics_provider_error_backoff' );
		delete_option( 'mai_analytics_settings' );
		delete_option( 'mai_analytics_provider_last_sync' );
		delete_option( 'mai_analytics_post_type_views' );
		delete_option( 'mai_analytics_post_type_views_web' );
		delete_option( 'mai_analytics_post_type_views_app' );
		delete_option( 'mai_analytics_post_type_trending' );
		delete_option( 'mai_analytics_post_type_views_synced_at' );
		delete_option( 'mai_analytics_provider_error' );
		parent::tearDown();
	}

	/**
	 * Registers a mock provider with predictable view counts.
	 *
	 * @param int  $views_per_path Views returned per path.
	 * @param bool $available      Whether the provider reports as available.
	 *
	 * @return void
	 */
	private function register_mock_provider( int $views_per_path = 100, bool $available = true ): void {
		$mock_views = $views_per_path;
		$mock_avail = $available;

		add_filter( 'mai_analytics_providers', function () use ( $mock_views, $mock_avail ) {
			return [ new class( $mock_views, $mock_avail ) implements \Mai\Analytics\WebViewProvider {
				private int $views;
				private bool $avail;

				public function __construct( int $views, bool $avail ) {
					$this->views = $views;
					$this->avail = $avail;
				}

				public function get_slug(): string { return 'test_provider'; }
				public function get_label(): string { return 'Test'; }
				public function is_available(): bool { return $this->avail; }
				public function get_batch_size(): int { return 50; }
				public function get_settings_fields(): array { return []; }

				public function get_views( array $paths, array $windows ): array {
					$out = [];
					foreach ( $paths as $path ) {
						foreach ( $windows as $name => $_range ) {
							$out[ $path ][ $name ] = $this->views;
						}
					}
					return $out;
				}
			} ];
		} );

		update_option( 'mai_analytics_settings', [
			'data_source' => 'test_provider',
			'sync_user'   => 1,
		] );
	}

	public function test_sync_reads_distinct_objects_from_buffer(): void {
		$this->register_mock_provider( 100 );

		$post_a = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$post_b = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		// Insert buffer rows for both posts (web source, used for triggering sync).
		Database::insert_view( $post_a, 'post', 'web' );
		Database::insert_view( $post_a, 'post', 'web' );
		Database::insert_view( $post_b, 'post', 'web' );

		ProviderSync::sync();

		// Both posts should have received web views from the provider.
		$this->assertGreaterThan( 0, (int) get_post_meta( $post_a, 'mai_views_web', true ) );
		$this->assertGreaterThan( 0, (int) get_post_meta( $post_b, 'mai_views_web', true ) );
	}

	public function test_sync_replaces_web_meta_from_provider(): void {
		$this->register_mock_provider( 250 );

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		// Pre-set a web meta value that the provider should replace.
		update_post_meta( $post_id, 'mai_views_web', 10 );

		// Insert a buffer row to trigger the object being picked up.
		Database::insert_view( $post_id, 'post', 'web' );

		ProviderSync::sync();

		// Provider returns 250, so web meta should be replaced (not incremented).
		$this->assertEquals( 250, (int) get_post_meta( $post_id, 'mai_views_web', true ) );
	}

	public function test_sync_increments_app_meta_from_buffer(): void {
		$this->register_mock_provider( 0 );

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		// Pre-set existing app views.
		update_post_meta( $post_id, 'mai_views_app', 5 );

		// Insert app buffer rows since last sync.
		Database::insert_view( $post_id, 'post', 'app' );
		Database::insert_view( $post_id, 'post', 'app' );
		Database::insert_view( $post_id, 'post', 'app' );

		// Also insert a web row so the object is picked up by distinct objects.
		Database::insert_view( $post_id, 'post', 'web' );

		ProviderSync::sync();

		// App meta should be incremented: 5 + 3 = 8.
		$this->assertEquals( 8, (int) get_post_meta( $post_id, 'mai_views_app', true ) );
	}

	public function test_sync_computes_total(): void {
		$this->register_mock_provider( 200 );

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		// Insert app buffer rows.
		Database::insert_view( $post_id, 'post', 'app' );
		Database::insert_view( $post_id, 'post', 'app' );

		// Insert a web row to trigger the object being picked up.
		Database::insert_view( $post_id, 'post', 'web' );

		ProviderSync::sync();

		$web   = (int) get_post_meta( $post_id, 'mai_views_web', true );
		$app   = (int) get_post_meta( $post_id, 'mai_views_app', true );
		$total = (int) get_post_meta( $post_id, 'mai_views', true );

		// Total should equal web + app.
		$this->assertEquals( $web + $app, $total );
		$this->assertEquals( 200, $web );
		$this->assertGreaterThanOrEqual( 2, $app );
	}

	public function test_sync_merges_trending(): void {
		$this->register_mock_provider( 50 );

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		// Insert some app buffer rows within the trending window.
		Database::insert_view( $post_id, 'post', 'app' );
		Database::insert_view( $post_id, 'post', 'app' );
		Database::insert_view( $post_id, 'post', 'app' );

		// Insert a web row to trigger the object.
		Database::insert_view( $post_id, 'post', 'web' );

		ProviderSync::sync();

		$trending = (int) get_post_meta( $post_id, 'mai_trending', true );

		// Trending should be web trending (50) + app trending (3 buffer rows in window).
		$this->assertEquals( 53, $trending );
	}

	public function test_sync_concurrent_lock(): void {
		$this->register_mock_provider( 100 );

		// Set the concurrency lock.
		set_transient( 'mai_analytics_provider_syncing', 1, 60 );

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		Database::insert_view( $post_id, 'post', 'web' );

		ProviderSync::sync();

		// Views should NOT be synced because the lock was held.
		$this->assertEquals( 0, (int) get_post_meta( $post_id, 'mai_views_web', true ) );
	}

	public function test_sync_bails_when_provider_unavailable(): void {
		$this->register_mock_provider( 100, false );

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		Database::insert_view( $post_id, 'post', 'web' );

		ProviderSync::sync();

		// Views should NOT be synced because the provider is unavailable.
		$this->assertEquals( 0, (int) get_post_meta( $post_id, 'mai_views_web', true ) );
	}

	public function test_warm_chunks_objects(): void {
		$this->register_mock_provider( 75 );

		// Create enough posts to require multiple batches (batch size = 50).
		$post_ids = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$post_ids[] = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		}

		$batches_yielded = 0;
		$total_updated   = 0;

		foreach ( ProviderSync::warm( [ 'type' => 'post' ] ) as $progress ) {
			$batches_yielded++;
			$total_updated += $progress['updated'];

			// Each yield should have the expected shape.
			$this->assertArrayHasKey( 'batch', $progress );
			$this->assertArrayHasKey( 'total', $progress );
			$this->assertArrayHasKey( 'updated', $progress );
			$this->assertArrayHasKey( 'type', $progress );
		}

		$this->assertGreaterThanOrEqual( 1, $batches_yielded );
		$this->assertGreaterThanOrEqual( 3, $total_updated );

		// Verify meta was written for all posts.
		foreach ( $post_ids as $pid ) {
			$this->assertEquals( 75, (int) get_post_meta( $pid, 'mai_views_web', true ) );
			$this->assertEquals( 75, (int) get_post_meta( $pid, 'mai_views', true ) );
		}
	}

	/**
	 * Registers a mock provider that always returns an empty array from
	 * get_views(), simulating a provider HTTP failure for skip-recent tests.
	 *
	 * @return void
	 */
	private function register_failing_provider(): void {
		add_filter( 'mai_analytics_providers', function () {
			return [ new class implements \Mai\Analytics\WebViewProvider {
				public function get_slug(): string { return 'test_provider'; }
				public function get_label(): string { return 'Test'; }
				public function is_available(): bool { return true; }
				public function get_batch_size(): int { return 50; }
				public function get_settings_fields(): array { return []; }
				public function get_views( array $paths, array $windows ): array { return []; }
			} ];
		} );

		update_option( 'mai_analytics_settings', [
			'data_source' => 'test_provider',
			'sync_user'   => 1,
		] );
	}

	/**
	 * Drains a generator. Used by tests that don't care about per-batch
	 * progress, just the side effects.
	 *
	 * @param \Generator $gen Generator to consume.
	 *
	 * @return int Total `iterated` count across all batches.
	 */
	private function drain_warm( \Generator $gen ): int {
		$total = 0;

		foreach ( $gen as $progress ) {
			$total += (int) ( $progress['iterated'] ?? 0 );
		}

		return $total;
	}

	public function test_warm_writes_synced_at_timestamp(): void {
		$this->register_mock_provider( 100 );

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$before = time();
		$this->drain_warm( ProviderSync::warm( [ 'type' => 'post' ] ) );
		$after  = time();

		$synced_at = (int) get_post_meta( $post_id, 'mai_views_synced_at', true );

		$this->assertGreaterThanOrEqual( $before, $synced_at );
		$this->assertLessThanOrEqual( $after, $synced_at );
	}

	public function test_warm_twice_skips_recent_objects_by_default(): void {
		$this->register_mock_provider( 100 );

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$first  = $this->drain_warm( ProviderSync::warm( [ 'type' => 'post' ] ) );
		$second = $this->drain_warm( ProviderSync::warm( [ 'type' => 'post' ] ) );

		// First pass warms the post; second pass should skip it because
		// `mai_views_synced_at` is now within the default 1-hour threshold.
		$this->assertGreaterThanOrEqual( 1, $first );
		$this->assertSame( 0, $second );
	}

	public function test_warm_force_bypasses_skip_recent(): void {
		$this->register_mock_provider( 100 );

		self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$first  = $this->drain_warm( ProviderSync::warm( [ 'type' => 'post' ] ) );
		$forced = $this->drain_warm( ProviderSync::warm( [ 'type' => 'post', 'force' => true ] ) );

		$this->assertSame( $first, $forced );
		$this->assertGreaterThanOrEqual( 1, $forced );
	}

	public function test_warm_skip_threshold_zero_disables_skip(): void {
		$this->register_mock_provider( 100 );
		add_filter( 'mai_analytics_warm_skip_threshold', fn() => 0 );

		self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$first  = $this->drain_warm( ProviderSync::warm( [ 'type' => 'post' ] ) );
		$second = $this->drain_warm( ProviderSync::warm( [ 'type' => 'post' ] ) );

		// Threshold 0 disables skip entirely, so the second pass walks the
		// same objects as the first.
		$this->assertSame( $first, $second );
	}

	public function test_warm_does_not_mark_synced_on_provider_failure(): void {
		$this->register_failing_provider();

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$this->drain_warm( ProviderSync::warm( [ 'type' => 'post' ] ) );

		// Provider returned [], so the batch is treated as failed: existing
		// meta is preserved and no `mai_views_synced_at` row is written.
		// Otherwise the next warm would silently skip this object, masking
		// the failure. Use metadata_exists() rather than get_post_meta() to
		// avoid the registered default (0) masquerading as a real value.
		$this->assertFalse( metadata_exists( 'post', $post_id, 'mai_views_synced_at' ) );

		// And a follow-up warm should still process the object — not skip it.
		$retry = $this->drain_warm( ProviderSync::warm( [ 'type' => 'post' ] ) );
		$this->assertGreaterThanOrEqual( 1, $retry );
	}

	public function test_provider_error_round_trip_via_helpers(): void {
		Sync::set_provider_error( 'boom' );

		$decoded = Sync::get_last_error();

		$this->assertSame( 'boom', $decoded['message'] );
		$this->assertGreaterThan( 0, $decoded['time'] );

		Sync::clear_provider_error();

		$this->assertSame( '', Sync::get_last_error()['message'] );
	}

	public function test_provider_error_survives_transient_clear(): void {
		Sync::set_provider_error( 'persistent' );

		// Transients live in the same wp_options table as options but under
		// `_transient_<name>` keys. Verify our error survives a transient
		// nuke — that's the whole reason for moving off transient storage.
		delete_transient( 'mai_analytics_provider_error' );

		$this->assertSame( 'persistent', Sync::get_last_error()['message'] );
	}

	public function test_sync_short_circuits_within_backoff_window(): void {
		$this->register_mock_provider( 100 );

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		Database::insert_view( $post_id, 'post', 'web' );

		// Pre-seed a fresh error: sync() should bail without touching the
		// provider AND without updating mai_analytics_provider_last_sync
		// (so the next cron tick still attempts the work after recovery).
		Sync::set_provider_error( 'just failed' );
		$last_sync_before = (int) get_option( 'mai_analytics_provider_last_sync', 0 );

		ProviderSync::sync();

		// Provider would have written 100 views had it run.
		$this->assertSame( 0, (int) get_post_meta( $post_id, 'mai_views_web', true ) );
		$this->assertSame( $last_sync_before, (int) get_option( 'mai_analytics_provider_last_sync', 0 ) );
	}

	public function test_warm_short_circuits_within_backoff_window(): void {
		$this->register_mock_provider( 100 );
		Sync::set_provider_error( 'just failed' );

		self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$iterated = $this->drain_warm( ProviderSync::warm( [ 'type' => 'post' ] ) );

		$this->assertSame( 0, $iterated );
	}

	public function test_warm_force_bypasses_circuit_breaker(): void {
		$this->register_mock_provider( 100 );
		Sync::set_provider_error( 'just failed' );

		self::factory()->post->create( [ 'post_status' => 'publish' ] );

		// Force should override the breaker — admin clicked "Force re-warm"
		// knowing the provider may still be sick.
		$iterated = $this->drain_warm( ProviderSync::warm( [ 'type' => 'post', 'force' => true ] ) );

		$this->assertGreaterThanOrEqual( 1, $iterated );
	}

	public function test_provider_error_backoff_filter_zero_disables_breaker(): void {
		$this->register_mock_provider( 100 );
		Sync::set_provider_error( 'just failed' );
		add_filter( 'mai_analytics_provider_error_backoff', fn() => 0 );

		self::factory()->post->create( [ 'post_status' => 'publish' ] );

		// Filter to 0 disables the breaker entirely — warm proceeds despite
		// the recent error.
		$iterated = $this->drain_warm( ProviderSync::warm( [ 'type' => 'post' ] ) );

		$this->assertGreaterThanOrEqual( 1, $iterated );
	}
}

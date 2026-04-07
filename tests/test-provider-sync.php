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

		// Remove any previously registered provider filters.
		remove_all_filters( 'mai_analytics_providers' );
	}

	public function tearDown(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::get_table_name() );
		delete_transient( 'mai_analytics_provider_syncing' );
		remove_all_filters( 'mai_analytics_providers' );
		delete_option( 'mai_analytics_settings' );
		delete_option( 'mai_analytics_provider_last_sync' );
		delete_option( 'mai_analytics_post_type_views' );
		delete_option( 'mai_analytics_post_type_views_web' );
		delete_option( 'mai_analytics_post_type_views_app' );
		delete_option( 'mai_analytics_post_type_trending' );
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

				public function get_views( array $paths, string $start_date, string $end_date ): array {
					$views = [];
					foreach ( $paths as $path ) {
						$views[ $path ] = $this->views;
					}
					return $views;
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
}

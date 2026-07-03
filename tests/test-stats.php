<?php

use Mai\Analytics\Stats;

class Test_Stats extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( 'mai_analytics_settings' );
		delete_option( 'mai_analytics_provider_error' );
	}

	public function tearDown(): void {
		delete_option( 'mai_analytics_settings' );
		delete_option( 'mai_analytics_provider_error' );
		parent::tearDown();
	}

	public function test_set_trending_writes_positive_value(): void {
		$post_id = self::factory()->post->create();

		Stats::set_trending( $post_id, 'post', 7 );

		$this->assertSame( '7', get_post_meta( $post_id, 'mai_trending', true ) );
	}

	public function test_set_trending_zero_deletes_the_row(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'mai_trending', 99 );

		Stats::set_trending( $post_id, 'post', 0 );

		$this->assertFalse( metadata_exists( 'post', $post_id, 'mai_trending' ) );
	}

	public function test_prune_deletes_zero_rows(): void {
		update_option( 'mai_analytics_settings', [ 'data_source' => 'self_hosted' ] );
		$keep = self::factory()->post->create();
		$zero = self::factory()->post->create();
		update_post_meta( $keep, 'mai_trending', 5 );
		update_post_meta( $zero, 'mai_trending', 0 );
		// $keep is current: a recent windowed buffer view keeps it in the self-hosted set.
		\Mai\Analytics\Database::insert_view( $keep, 'post' );

		$deleted = Stats::prune_trending( 7 );

		$this->assertGreaterThanOrEqual( 1, $deleted );
		$this->assertFalse( metadata_exists( 'post', $zero, 'mai_trending' ) );
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

		$this->assertFalse( metadata_exists( 'post', $stale, 'mai_trending' ) );
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
		$this->assertFalse( metadata_exists( 'post', $zero, 'mai_trending' ) );
	}

	public function test_prune_dry_run_counts_without_deleting(): void {
		update_option( 'mai_analytics_settings', [ 'data_source' => 'matomo' ] );
		$zero = self::factory()->post->create();
		update_post_meta( $zero, 'mai_trending', 0 );

		$would = Stats::prune_trending( 7, [ 'dry_run' => true ] );

		$this->assertGreaterThanOrEqual( 1, $would );
		$this->assertTrue( metadata_exists( 'post', $zero, 'mai_trending' ) ); // still there
	}

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
		$this->assertFalse( metadata_exists( 'post', $out_window, 'mai_trending' ) );
	}
}

<?php

use Mai\Analytics\Stats;
use Mai\Analytics\Database;

class Test_Stats extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Database::create_table();
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

		// Exactly the one zero row — nothing else — so this also catches over-deletion.
		$this->assertSame( 1, $deleted );
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

	public function test_prune_provider_skips_stale_when_error_on_file(): void {
		update_option( 'mai_analytics_settings', [ 'data_source' => 'matomo' ] );
		// An unresolved provider error on file means the provider is unhealthy.
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

		$would = Stats::prune_trending( 7, true );

		$this->assertSame( 1, $would );
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

	public function test_prune_provider_keeps_trending_with_missing_synced_at(): void {
		update_option( 'mai_analytics_settings', [ 'data_source' => 'matomo' ] );
		delete_option( 'mai_analytics_provider_error' ); // provider healthy

		// Positive trending but NO mai_views_synced_at — e.g. an app-only object the
		// web provider can't map. The provider has never measured it, so it must be
		// kept, never deleted on an absence of data. (A dropped `IS NULL` guard would
		// silently regress this into permanent bloat.)
		$orphan = self::factory()->post->create();
		update_post_meta( $orphan, 'mai_trending', 5 );

		Stats::prune_trending( 7 );

		$this->assertSame( '5', get_post_meta( $orphan, 'mai_trending', true ) );
	}

	public function test_prune_provider_skips_stale_when_error_is_old(): void {
		update_option( 'mai_analytics_settings', [ 'data_source' => 'matomo' ] );
		// Error recorded long ago: the time-windowed circuit breaker would read
		// "clear", but an unresolved error on file means the outage is ongoing, so
		// stale deletion must still be skipped (the sustained-outage hole).
		update_option( 'mai_analytics_provider_error', wp_json_encode( [ 'message' => 'down', 'time' => time() - HOUR_IN_SECONDS ] ) );

		$stale = self::factory()->post->create();
		update_post_meta( $stale, 'mai_trending', 5 );
		update_post_meta( $stale, 'mai_views_synced_at', time() - ( 10 * DAY_IN_SECONDS ) );

		Stats::prune_trending( 7 );

		$this->assertSame( '5', get_post_meta( $stale, 'mai_trending', true ) );
	}

	public function test_set_trending_zero_deletes_term_and_user_rows(): void {
		$term_id = self::factory()->term->create();
		$user_id = self::factory()->user->create();
		update_term_meta( $term_id, 'mai_trending', 9 );
		update_user_meta( $user_id, 'mai_trending', 9 );

		Stats::set_trending( $term_id, 'term', 0 );
		Stats::set_trending( $user_id, 'user', 0 );

		$this->assertFalse( metadata_exists( 'term', $term_id, 'mai_trending' ) );
		$this->assertFalse( metadata_exists( 'user', $user_id, 'mai_trending' ) );
	}

	public function test_prune_deletes_zero_rows_for_term_and_user(): void {
		update_option( 'mai_analytics_settings', [ 'data_source' => 'matomo' ] );
		delete_option( 'mai_analytics_provider_error' );

		$term_id = self::factory()->term->create();
		$user_id = self::factory()->user->create();
		update_term_meta( $term_id, 'mai_trending', 0 );
		update_user_meta( $user_id, 'mai_trending', 0 );

		Stats::prune_trending( 7 );

		$this->assertFalse( metadata_exists( 'term', $term_id, 'mai_trending' ) );
		$this->assertFalse( metadata_exists( 'user', $user_id, 'mai_trending' ) );
	}

	public function test_prune_respects_max_batches_budget(): void {
		update_option( 'mai_analytics_settings', [ 'data_source' => 'matomo' ] );
		delete_option( 'mai_analytics_provider_error' );

		for ( $i = 0; $i < 3; $i++ ) {
			$id = self::factory()->post->create();
			update_post_meta( $id, 'mai_trending', 0 );
		}

		// batch_size 1, max_batches 1 => at most one row deleted this call.
		$deleted = Stats::prune_trending( 7, false, 1, 1 );
		$this->assertSame( 1, $deleted );

		// Two zero rows still remain for a later (rescheduled) run to clear.
		$this->assertSame( 2, Stats::prune_trending( 7, true ) );
	}
}

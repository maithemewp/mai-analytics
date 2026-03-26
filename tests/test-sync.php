<?php

use Mai\Analytics\Database;
use Mai\Analytics\Sync;

class Test_Sync extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Database::create_table();
		delete_option( 'mai_analytics_synced' );
	}

	public function tearDown(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::get_table_name() );
		delete_transient( 'mai_analytics_sync_lock' );
		delete_transient( 'mai_analytics_syncing' );
		parent::tearDown();
	}

	public function test_sync_increments_lifetime_views(): void {
		$post_id = self::factory()->post->create();

		for ( $i = 0; $i < 3; $i++ ) { Database::insert_view( $post_id, 'post' ); }

		Sync::sync();

		$this->assertEquals( 3, (int) get_post_meta( $post_id, 'mai_analytics_views', true ) );
	}

	public function test_sync_increments_existing_views(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'mai_analytics_views', 10 );

		Database::insert_view( $post_id, 'post' );
		Sync::sync();

		$this->assertEquals( 11, (int) get_post_meta( $post_id, 'mai_analytics_views', true ) );
	}

	public function test_sync_calculates_trending(): void {
		$post_id = self::factory()->post->create();

		for ( $i = 0; $i < 5; $i++ ) { Database::insert_view( $post_id, 'post' ); }

		Sync::sync();

		$this->assertEquals( 5, (int) get_post_meta( $post_id, 'mai_analytics_trending', true ) );
	}

	public function test_sync_updates_term_meta(): void {
		$term_id = self::factory()->category->create();

		Database::insert_view( $term_id, 'term' );
		Database::insert_view( $term_id, 'term' );
		Sync::sync();

		$this->assertEquals( 2, (int) get_term_meta( $term_id, 'mai_analytics_views', true ) );
		$this->assertEquals( 2, (int) get_term_meta( $term_id, 'mai_analytics_trending', true ) );
	}

	public function test_sync_updates_user_meta(): void {
		$user_id = self::factory()->user->create();

		Database::insert_view( $user_id, 'user' );
		Sync::sync();

		$this->assertEquals( 1, (int) get_user_meta( $user_id, 'mai_analytics_views', true ) );
	}

	public function test_sync_prunes_old_rows(): void {
		global $wpdb;
		$table   = Database::get_table_name();
		$post_id = self::factory()->post->create();

		$wpdb->insert( $table, [
			'object_id'   => $post_id,
			'object_type' => 'post',
			'viewed_at'   => gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) ),
			'source'      => 'web',
		] );

		Database::insert_view( $post_id, 'post' );
		Sync::sync();

		$this->assertEquals( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) );
	}

	public function test_sync_updates_synced_option(): void {
		$post_id = self::factory()->post->create();
		Database::insert_view( $post_id, 'post' );

		$before = time();
		Sync::sync();

		$synced = (int) get_option( 'mai_analytics_synced' );
		$this->assertGreaterThanOrEqual( $before, $synced );
	}

	public function test_concurrent_sync_blocked(): void {
		set_transient( 'mai_analytics_syncing', 1, 60 );

		$post_id = self::factory()->post->create();
		Database::insert_view( $post_id, 'post' );
		Sync::sync();

		// Views should NOT be synced because the lock was held.
		$this->assertEquals( 0, (int) get_post_meta( $post_id, 'mai_analytics_views', true ) );
	}

	public function test_sync_splits_views_by_source(): void {
		$post_id = self::factory()->post->create();

		// Insert web and app views.
		Database::insert_view( $post_id, 'post', 'web' );
		Database::insert_view( $post_id, 'post', 'web' );
		Database::insert_view( $post_id, 'post', 'web' );
		Database::insert_view( $post_id, 'post', 'app' );
		Database::insert_view( $post_id, 'post', 'app' );

		Sync::sync();

		$web   = (int) get_post_meta( $post_id, 'mai_analytics_views_web', true );
		$app   = (int) get_post_meta( $post_id, 'mai_analytics_views_app', true );
		$total = (int) get_post_meta( $post_id, 'mai_analytics_views', true );

		$this->assertEquals( 3, $web );
		$this->assertEquals( 2, $app );
		$this->assertEquals( 5, $total );
	}

	public function test_sync_computes_total_from_web_and_app(): void {
		$post_id = self::factory()->post->create();

		// Pre-set some existing meta from a previous sync.
		update_post_meta( $post_id, 'mai_analytics_views_web', 10 );
		update_post_meta( $post_id, 'mai_analytics_views_app', 5 );
		update_post_meta( $post_id, 'mai_analytics_views', 15 );

		// Insert new views of each source.
		Database::insert_view( $post_id, 'post', 'web' );
		Database::insert_view( $post_id, 'post', 'app' );
		Database::insert_view( $post_id, 'post', 'app' );

		Sync::sync();

		$web   = (int) get_post_meta( $post_id, 'mai_analytics_views_web', true );
		$app   = (int) get_post_meta( $post_id, 'mai_analytics_views_app', true );
		$total = (int) get_post_meta( $post_id, 'mai_analytics_views', true );

		// Web: 10 + 1 = 11, App: 5 + 2 = 7, Total: 11 + 7 = 18.
		$this->assertEquals( 11, $web );
		$this->assertEquals( 7, $app );
		$this->assertEquals( $web + $app, $total );
	}

	public function test_trending_uses_days_not_hours(): void {
		global $wpdb;
		$table   = Database::get_table_name();
		$post_id = self::factory()->post->create();

		// Insert a view 2 days ago (within the default 7-day window).
		$wpdb->insert( $table, [
			'object_id'   => $post_id,
			'object_type' => 'post',
			'object_key'  => '',
			'viewed_at'   => gmdate( 'Y-m-d H:i:s', strtotime( '-2 days' ) ),
			'source'      => 'web',
		] );

		// Insert a view 10 days ago (outside the default 7-day window).
		$wpdb->insert( $table, [
			'object_id'   => $post_id,
			'object_type' => 'post',
			'object_key'  => '',
			'viewed_at'   => gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) ),
			'source'      => 'web',
		] );

		// Insert a recent view to also test the current window.
		Database::insert_view( $post_id, 'post', 'web' );

		Sync::sync();

		$trending = (int) get_post_meta( $post_id, 'mai_analytics_trending', true );

		// Only the 2-day-old and the current view should count (2 within the 7-day window).
		// The 10-day-old view is outside the window.
		$this->assertEquals( 2, $trending );
	}
}

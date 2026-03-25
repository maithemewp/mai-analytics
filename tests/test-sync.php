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
}

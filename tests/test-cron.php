<?php

use Mai\Views\Cron;
use Mai\Views\Database;

class Test_Cron extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Database::create_table();
	}

	public function tearDown(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::get_table_name() );
		delete_option( 'mai_views_synced' );
		parent::tearDown();
	}

	public function test_custom_schedule_registered(): void {
		new Cron();
		$schedules = wp_get_schedules();

		$this->assertArrayHasKey( 'mai_views_15min', $schedules );
		$this->assertEquals( 15 * MINUTE_IN_SECONDS, $schedules['mai_views_15min']['interval'] );
	}

	public function test_cron_skips_recent_sync(): void {
		update_option( 'mai_views_synced', time() );

		$cron    = new Cron();
		$post_id = self::factory()->post->create();
		Database::insert_view( $post_id, 'post' );

		$cron->maybe_sync();

		$this->assertEquals( 0, (int) get_post_meta( $post_id, 'mai_views', true ) );
	}

	public function test_cron_runs_when_stale(): void {
		update_option( 'mai_views_synced', time() - ( 15 * MINUTE_IN_SECONDS ) );

		$cron    = new Cron();
		$post_id = self::factory()->post->create();
		Database::insert_view( $post_id, 'post' );

		$cron->maybe_sync();

		$this->assertEquals( 1, (int) get_post_meta( $post_id, 'mai_views', true ) );
	}
}

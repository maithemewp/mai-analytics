<?php

require_once __DIR__ . '/stubs/wp-cli.php';

use Mai\Analytics\CLI;
use Mai\Analytics\Database;

class Test_CLI extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Database::create_table();
		WP_CLI::$lines  = [];
		WP_CLI::$errors = [];
	}

	public function test_health_fix_reports_a_failed_reschedule_and_exits_1(): void {
		wp_clear_scheduled_hook( 'mai_analytics_cron_sync' );
		add_filter( 'pre_schedule_event', '__return_false' );

		$exit = null;

		try {
			( new CLI() )->health( [], [ 'fix' => true ] );
		} catch ( RuntimeException $e ) {
			$exit = $e->getMessage();
		}

		$this->assertContains( '  %R✗%n Cron could not be rescheduled.', WP_CLI::$lines );
		$this->assertNotContains( "  %C\u{2192} Fixed: rescheduled cron%n", WP_CLI::$lines );
		$this->assertSame( 'WP-CLI exit 1', $exit );
		$this->assertCount( 1, WP_CLI::$errors );
		$this->assertTrue( WP_CLI::$errors[0][1] );
	}

	public function test_health_fix_reschedules(): void {
		wp_clear_scheduled_hook( 'mai_analytics_cron_sync' );

		try {
			( new CLI() )->health( [], [ 'fix' => true ] );
		} catch ( RuntimeException $e ) {
			// The missing cron is still counted as a failed check.
		}

		$this->assertContains( "  %C\u{2192} Fixed: rescheduled cron%n", WP_CLI::$lines );
		$this->assertNotFalse( wp_next_scheduled( 'mai_analytics_cron_sync' ) );
	}
}

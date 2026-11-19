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

		$before = $this->failed_checks();

		try {
			( new CLI() )->health( [], [ 'fix' => true ] );
		} catch ( RuntimeException $e ) {
			// Other checks may fail in the test site. Only the count matters here.
		}

		$this->assertContains( "  %C\u{2192} Fixed: rescheduled cron%n", WP_CLI::$lines );
		$this->assertNotFalse( wp_next_scheduled( 'mai_analytics_cron_sync' ) );

		$summary = WP_CLI::$errors ? WP_CLI::$errors[0][0] : (string) end( WP_CLI::$lines );

		$this->assertStringContainsString( sprintf( '%d failed', $before - 1 ), $summary );
		$this->assertStringContainsString( '(1 fixed)', $summary );
	}

	/**
	 * Runs `wp mai-analytics sync` and returns the exit it ended with, or null.
	 */
	private function run_sync(): ?string {
		try {
			( new CLI() )->sync( [], [] );
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		}

		return null;
	}

	public function test_sync_says_complete_when_it_ran(): void {
		update_option( 'mai_analytics_settings', [ 'data_source' => 'self_hosted' ] );

		$this->assertNull( $this->run_sync() );
		$this->assertStringStartsWith( 'Sync complete.', (string) end( WP_CLI::$lines ) );
	}

	public function test_sync_busy_exits_1(): void {
		update_option( 'mai_analytics_settings', [ 'data_source' => 'self_hosted' ] );
		set_transient( 'mai_analytics_syncing', 1, 60 );

		$exit = $this->run_sync();
		delete_transient( 'mai_analytics_syncing' );

		$this->assertSame( 'WP-CLI exit 1', $exit );
		$this->assertSame( 'A sync is already running. Try again in a few minutes.', WP_CLI::$errors[0][0] );
		$this->assertEmpty( preg_grep( '/complete/', WP_CLI::$lines ) );
	}

	public function test_sync_read_failure_exits_1(): void {
		global $wpdb;

		update_option( 'mai_analytics_settings', [ 'data_source' => 'self_hosted' ] );

		$table      = Database::get_table_name();
		$suppressed = $wpdb->suppress_errors( true );
		$break      = fn( $query ) => str_contains( $query, 'COUNT(*) as cnt' ) ? str_replace( $table, 'mai_analytics_missing_table', $query ) : $query;

		add_filter( 'query', $break );
		$exit = $this->run_sync();
		remove_filter( 'query', $break );
		$wpdb->suppress_errors( $suppressed );
		delete_transient( 'mai_analytics_syncing' );

		$this->assertSame( 'WP-CLI exit 1', $exit );
		$this->assertStringStartsWith( 'The sync could not read the buffer table', WP_CLI::$errors[0][0] );
		$this->assertEmpty( preg_grep( '/complete/', WP_CLI::$lines ) );
	}

	/**
	 * Counts the failed health checks with the cron hook missing.
	 */
	private function failed_checks(): int {
		$results = \Mai\Analytics\Health::run();

		wp_clear_scheduled_hook( 'mai_analytics_cron_sync' );

		return $results['fail'];
	}
}

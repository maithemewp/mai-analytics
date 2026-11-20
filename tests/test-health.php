<?php

use Mai\Analytics\Database;
use Mai\Analytics\Health;

class Test_Health extends WP_UnitTestCase {

	/**
	 * Runs the health checks without the REST probes and returns one by label.
	 *
	 * @param string $label The check's label.
	 *
	 * @return array The check: section, label, status and detail.
	 */
	private function check( string $label ): array {
		foreach ( Health::run( false )['checks'] as $check ) {
			if ( $label === $check['label'] ) {
				return $check;
			}
		}

		$this->fail( "No health check named $label." );
	}

	public function test_old_views_table_passes_once_moved(): void {
		update_option( 'mai_analytics_table_migrated', true );

		$check = $this->check( 'Old views table' );

		$this->assertSame( 'pass', $check['status'] );
		$this->assertSame( 'Moved. Nothing left to do.', $check['detail'] );
	}

	public function test_old_views_table_fails_while_one_is_waiting(): void {
		global $wpdb;

		delete_option( 'mai_analytics_table_migrated' );

		$old  = $wpdb->prefix . 'mai_views_buffer';
		$fake = fn( $query ) => "SHOW TABLES LIKE '$old'" === $query ? "SELECT '$old'" : $query;

		add_filter( 'query', $fake );
		$check = $this->check( 'Old views table' );
		remove_filter( 'query', $fake );

		$this->assertSame( 'fail', $check['status'] );
		$this->assertSame( $old . ' is still here. Moving its views failed, and it is tried again every hour. The error log has the reason.', $check['detail'] );
	}

	public function test_old_views_table_passes_with_none_left(): void {
		delete_option( 'mai_analytics_table_migrated' );

		$this->assertSame( 'pass', $this->check( 'Old views table' )['status'] );
	}

	public function test_waiting_items_pass_when_none_are_listed(): void {
		delete_option( 'mai_analytics_provider_unreached' );

		$check = $this->check( 'Items waiting for the provider' );

		$this->assertSame( 'pass', $check['status'] );
		$this->assertSame( 'None.', $check['detail'] );
	}

	public function test_waiting_items_warn_with_their_count(): void {
		update_option( 'mai_analytics_provider_unreached', [ [ 'post', 1, '' ], [ 'term', 2, '' ] ], false );

		$check = $this->check( 'Items waiting for the provider' );
		delete_option( 'mai_analytics_provider_unreached' );

		$this->assertSame( 'warn', $check['status'] );
		$this->assertSame( "2 didn't sync on the last run. The next provider sync fetches up to 500 of them.", $check['detail'] );
	}

	public function test_waiting_items_fail_when_the_list_cannot_be_read(): void {
		update_option( 'mai_analytics_provider_unreached', 'not a list', false );

		$check = $this->check( 'Items waiting for the provider' );
		delete_option( 'mai_analytics_provider_unreached' );

		$this->assertSame( 'fail', $check['status'] );
		$this->assertSame( "The list can't be read, so provider syncs stop until it can. The error log has the reason.", $check['detail'] );
	}

	public function test_legacy_table_list_matches_the_migration(): void {
		$this->assertSame( [ 'mai_views_buffer', 'mai_analytics_views' ], Database::LEGACY_TABLES );
	}
}

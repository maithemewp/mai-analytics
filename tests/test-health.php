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

	public function test_legacy_table_list_matches_the_migration(): void {
		$this->assertSame( [ 'mai_views_buffer', 'mai_analytics_views' ], Database::LEGACY_TABLES );
	}
}

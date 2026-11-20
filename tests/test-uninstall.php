<?php

use Mai\Analytics\Cron;
use Mai\Analytics\Database;

class Test_Uninstall extends WP_UnitTestCase {

	public function test_uninstall_removes_what_the_plugin_made(): void {
		global $wpdb;

		Database::create_table();

		foreach ( Cron::HOOKS as $hook ) {
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, $hook );
		}

		add_option( 'maiXanalytics_keep', 'yes' );
		update_option( 'mai_analytics_settings', [ 'data_source' => 'self_hosted' ] );
		update_option( 'mai_analytics_provider_unreached', [ [ 'post', 1, '' ] ], false );
		set_transient( 'mai_analytics_admin_filters', [ 'x' ] );
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'mai_views', 3 );

		// Priority 1 sees each query before the test library turns DROP TABLE
		// into DROP TEMPORARY TABLE.
		$queries = [];
		add_filter(
			'query',
			function ( $query ) use ( &$queries ) {
				$queries[] = $query;
				return $query;
			},
			1
		);

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'mai-analytics/mai-analytics.php' );
		}

		include dirname( __DIR__ ) . '/uninstall.php';

		// Every hook in Cron::HOOKS is gone, so uninstall.php's list matches it.
		foreach ( Cron::HOOKS as $hook ) {
			$this->assertFalse( wp_next_scheduled( $hook ), $hook );
		}

		$table = Database::get_table_name();
		$drops = array_filter( $queries, fn( $q ) => str_starts_with( $q, 'DROP TABLE IF EXISTS' ) && str_contains( $q, $table ) );
		$this->assertNotEmpty( $drops );

		$count = fn( string $name ): int => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->options WHERE option_name = %s", $name ) );

		$this->assertSame( 0, $count( 'mai_analytics_settings' ) );
		$this->assertSame( 0, $count( 'mai_analytics_provider_unreached' ) );
		$this->assertSame( 0, $count( '_transient_mai_analytics_admin_filters' ) );
		$this->assertSame( 1, $count( 'maiXanalytics_keep' ) );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key = 'mai_views'" ) );
	}
}

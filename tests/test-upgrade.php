<?php

use Mai\Analytics\Upgrade;

class Test_Upgrade extends WP_UnitTestCase {

	public function test_maybe_upgrade_schedules_immediate_prune_on_version_change(): void {
		delete_option( 'mai_analytics_version' );
		wp_clear_scheduled_hook( 'mai_analytics_prune_trending' );

		Upgrade::maybe_upgrade();

		$this->assertNotFalse( wp_next_scheduled( 'mai_analytics_prune_trending' ) );
		$this->assertSame( MAI_ANALYTICS_VERSION, get_option( 'mai_analytics_version' ) );
	}

	public function test_maybe_upgrade_is_noop_when_version_matches(): void {
		update_option( 'mai_analytics_version', MAI_ANALYTICS_VERSION );
		wp_clear_scheduled_hook( 'mai_analytics_prune_trending' );

		Upgrade::maybe_upgrade();

		// No immediate prune scheduled when nothing changed.
		$this->assertFalse( wp_next_scheduled( 'mai_analytics_prune_trending' ) );
	}
}

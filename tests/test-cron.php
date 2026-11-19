<?php

use Mai\Analytics\Cron;
use Mai\Analytics\Database;
use Mai\Analytics\Plugin;
use Mai\Analytics\Stats;

class Test_Cron extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Database::create_table();
	}

	public function tearDown(): void {
		delete_option( 'mai_analytics_synced' );
		delete_option( 'mai_analytics_settings' );
		delete_option( 'mai_analytics_provider_error' );
		wp_clear_scheduled_hook( Cron::PRUNE_HOOK );
		wp_clear_scheduled_hook( Cron::PRUNE_NOW_HOOK );
		parent::tearDown();
	}

	public function test_custom_schedule_registered(): void {
		new Cron();
		$schedules = wp_get_schedules();

		$this->assertArrayHasKey( 'mai_analytics_15min', $schedules );
		$this->assertEquals( 15 * MINUTE_IN_SECONDS, $schedules['mai_analytics_15min']['interval'] );
	}

	/**
	 * Before after_setup_theme, the label is not translated, so core does not warn
	 * about loading the domain too early. The update checker schedules its event
	 * on plugins_loaded, which runs this filter that early.
	 */
	public function test_schedule_label_untranslated_before_after_setup_theme(): void {
		global $wp_actions;

		$cron          = new Cron();
		$translations  = 0;
		$count         = function ( $translation ) use ( &$translations ) {
			$translations++;
			return $translation;
		};
		$saved_actions = $wp_actions;

		add_filter( 'gettext_mai-analytics', $count );
		unset( $wp_actions['after_setup_theme'] );

		try {
			$schedules = $cron->add_schedule( [] );
		} finally {
			$wp_actions = $saved_actions;
		}

		$this->assertSame( 'Every 15 Minutes', $schedules['mai_analytics_15min']['display'] );
		$this->assertSame( 0, $translations );
	}

	/**
	 * After after_setup_theme, the label goes through the plugin's text domain.
	 */
	public function test_schedule_label_translated_after_after_setup_theme(): void {
		$cron         = new Cron();
		$translations = 0;
		$count        = function ( $translation ) use ( &$translations ) {
			$translations++;
			return $translation;
		};

		add_filter( 'gettext_mai-analytics', $count );

		$schedules = $cron->add_schedule( [] );

		$this->assertGreaterThan( 0, did_action( 'after_setup_theme' ) );
		$this->assertSame( 'Every 15 Minutes', $schedules['mai_analytics_15min']['display'] );
		$this->assertSame( 1, $translations );
	}

	public function test_cron_skips_recent_sync(): void {
		// Set the marker a bit into the future (not just "now") so the view
		// inserted below is unambiguously <= the sync boundary even if a
		// wall-clock second ticks between this line and Database::insert_view().
		// A same-second marker is boundary-fragile: viewed_at uses its own
		// current_time() call, so it can land one second after this time()
		// call, flipping the strict `viewed_at > $last_sync_date` comparison
		// in Sync::sync() from excluded to included and failing the test.
		update_option( 'mai_analytics_synced', time() + MINUTE_IN_SECONDS );

		$cron    = new Cron();
		$post_id = self::factory()->post->create();
		Database::insert_view( $post_id, 'post' );

		$cron->maybe_sync();

		$this->assertEquals( 0, (int) get_post_meta( $post_id, 'mai_views', true ) );
	}

	public function test_cron_runs_when_stale(): void {
		update_option( 'mai_analytics_synced', time() - ( 15 * MINUTE_IN_SECONDS ) );

		$cron    = new Cron();
		$post_id = self::factory()->post->create();
		Database::insert_view( $post_id, 'post' );

		$cron->maybe_sync();

		$this->assertEquals( 1, (int) get_post_meta( $post_id, 'mai_views', true ) );
	}

	public function test_ensure_healthy_schedules_daily_prune(): void {
		wp_clear_scheduled_hook( Cron::PRUNE_HOOK );

		( new Cron() )->ensure_healthy();

		$this->assertNotFalse( wp_next_scheduled( Cron::PRUNE_HOOK ) );
	}

	public function test_schedule_daily_prune_is_idempotent(): void {
		wp_clear_scheduled_hook( Cron::PRUNE_HOOK );

		Cron::schedule_daily_prune();
		$first = wp_next_scheduled( Cron::PRUNE_HOOK );
		Cron::schedule_daily_prune();

		$this->assertNotFalse( $first );
		// Second call must not stack a second event.
		$this->assertSame( $first, wp_next_scheduled( Cron::PRUNE_HOOK ) );
	}

	private function schedule_every_hook(): void {
		foreach ( Cron::HOOKS as $hook ) {
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, $hook );
		}
	}

	public function test_deactivate_clears_every_hook(): void {
		update_option( 'mai_analytics_settings', [ 'data_source' => 'self_hosted' ] );
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'mai_views', 5 );
		$this->schedule_every_hook();

		Plugin::deactivate();

		foreach ( Cron::HOOKS as $hook ) {
			$this->assertFalse( wp_next_scheduled( $hook ), $hook );
		}

		$this->assertTrue( Database::table_exists() );
		$this->assertNotFalse( get_option( 'mai_analytics_settings' ) );
		$this->assertSame( '5', get_post_meta( $post_id, 'mai_views', true ) );
	}

	public function test_activate_schedules_sync_and_prune(): void {
		foreach ( Cron::HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		// A real activation runs before Plugin::init(), so the 15-minute schedule is unknown.
		remove_all_filters( 'cron_schedules' );

		Plugin::activate();

		$this->assertNotFalse( wp_next_scheduled( 'mai_analytics_cron_sync' ) );
		$this->assertNotFalse( wp_next_scheduled( Cron::PRUNE_HOOK ) );

		wp_clear_scheduled_hook( 'mai_analytics_cron_sync' );
	}

	public function test_prune_trending_is_noop_when_disabled(): void {
		update_option( 'mai_analytics_settings', [ 'data_source' => 'disabled' ] );

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'mai_trending', 0 ); // a zero that would prune if enabled

		( new Cron() )->prune_trending();

		// Disabled tracking => the prune is a no-op, even for zero rows.
		$this->assertTrue( metadata_exists( 'post', $post_id, 'mai_trending' ) );
	}

	public function test_prune_trending_reschedules_when_backlog_remains(): void {
		update_option( 'mai_analytics_settings', [ 'data_source' => 'matomo' ] );
		delete_option( 'mai_analytics_provider_error' ); // provider healthy
		wp_clear_scheduled_hook( Cron::PRUNE_NOW_HOOK );

		// Cap the run at one row so a backlog is left after it.
		$one = static function () { return 1; };
		add_filter( 'mai_analytics_prune_batch_size', $one );
		add_filter( 'mai_analytics_prune_max_batches', $one );

		$a = self::factory()->post->create();
		$b = self::factory()->post->create();
		update_post_meta( $a, 'mai_trending', 0 );
		update_post_meta( $b, 'mai_trending', 0 );

		( new Cron() )->prune_trending();

		remove_filter( 'mai_analytics_prune_batch_size', $one );
		remove_filter( 'mai_analytics_prune_max_batches', $one );

		// One row deleted, a backlog remains => a one-off continuation is scheduled.
		$this->assertNotFalse( wp_next_scheduled( Cron::PRUNE_NOW_HOOK ) );
	}

	public function test_prune_trending_does_not_reschedule_when_drained(): void {
		update_option( 'mai_analytics_settings', [ 'data_source' => 'matomo' ] );
		delete_option( 'mai_analytics_provider_error' );
		wp_clear_scheduled_hook( Cron::PRUNE_NOW_HOOK );

		$a = self::factory()->post->create();
		update_post_meta( $a, 'mai_trending', 0 );

		// Default cap drains the backlog in one run, so nothing prunable remains.
		( new Cron() )->prune_trending();

		// Drained => the loop terminates, no continuation scheduled.
		$this->assertFalse( wp_next_scheduled( Cron::PRUNE_NOW_HOOK ) );
	}
}

<?php

namespace Mai\Analytics;

class Cron {

	/**
	 * Registers cron schedule, sync action, catchup action, and self-healing admin check.
	 */
	public function __construct() {
		add_filter( 'cron_schedules', [ $this, 'add_schedule' ] );
		add_action( 'mai_analytics_cron_sync', [ $this, 'maybe_sync' ] );
		add_action( 'mai_analytics_provider_catchup', [ $this, 'maybe_provider_sync' ] );

		// Self-heal: re-schedule if it got deleted, but only check on admin loads.
		add_action( 'admin_init', [ $this, 'ensure_scheduled' ] );
	}

	/**
	 * Verifies the cron event is scheduled. Runs only on admin pages.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( 'mai_analytics_cron_sync' ) ) {
			wp_schedule_event( time(), 'mai_analytics_15min', 'mai_analytics_cron_sync' );
		}
	}

	/**
	 * Adds a custom 15-minute cron schedule.
	 *
	 * @param array $schedules The existing WordPress cron schedules.
	 *
	 * @return array The modified schedules with the 15-minute interval added.
	 */
	public function add_schedule( array $schedules ): array {
		$schedules['mai_analytics_15min'] = [
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 Minutes', 'mai-analytics' ),
		];

		return $schedules;
	}

	/**
	 * Safety-net sync: branches on data source setting.
	 *
	 * In self-hosted mode, calls Sync::sync(). In external provider mode, calls ProviderSync::sync().
	 *
	 * @return void
	 */
	public function maybe_sync(): void {
		if ( 'self_hosted' === Settings::get( 'data_source' ) ) {
			$last_sync = get_option( 'mai_analytics_synced', 0 );

			if ( $last_sync && ( time() - $last_sync ) < 10 * MINUTE_IN_SECONDS ) {
				return;
			}

			Sync::sync();
		} else {
			$this->maybe_provider_sync();
		}
	}

	/**
	 * Runs the provider sync if enough time has passed since the last run.
	 *
	 * @return void
	 */
	public function maybe_provider_sync(): void {
		$last_sync = (int) get_option( 'mai_analytics_provider_last_sync', 0 );

		if ( $last_sync && ( time() - $last_sync ) < 10 * MINUTE_IN_SECONDS ) {
			return;
		}

		ProviderSync::sync();
	}
}

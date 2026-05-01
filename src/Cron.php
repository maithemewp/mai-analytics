<?php

namespace Mai\Analytics;

class Cron {

	/**
	 * Registers cron schedule, sync action, catchup action, and self-healing admin check.
	 */
	public function __construct() {
		add_filter( 'cron_schedules', [ $this, 'add_schedule' ] );
		add_action( 'mai_analytics_cron_sync', [ $this, 'maybe_sync' ] );
		add_action( ProviderSync::CATCHUP_HOOK, [ $this, 'maybe_provider_sync' ] );

		// Self-heal: re-schedule cron if deleted, force sync if stale.
		add_action( 'admin_init', [ $this, 'ensure_healthy' ] );
	}

	/**
	 * Verifies cron is scheduled and sync is not stale. Runs only on admin pages.
	 *
	 * If cron is missing, re-schedules it. If sync hasn't run in 30+ minutes
	 * (indicating cron is not firing), forces a sync directly.
	 *
	 * @return void
	 */
	public function ensure_healthy(): void {
		// Clean up legacy cron hook from when plugin was named Mai Views.
		if ( wp_next_scheduled( 'mai_views_cron_sync' ) ) {
			wp_clear_scheduled_hook( 'mai_views_cron_sync' );
		}

		if ( ! wp_next_scheduled( 'mai_analytics_cron_sync' ) ) {
			wp_schedule_event( time(), 'mai_analytics_15min', 'mai_analytics_cron_sync' );
		}

		$data_source = Settings::get( 'data_source' );

		if ( 'disabled' === $data_source ) {
			return;
		}

		// Self-hosted Sync writes to mai_analytics_synced; ProviderSync writes to
		// mai_analytics_provider_last_sync. Read whichever the active mode updates.
		$option_key = ( 'self_hosted' === $data_source ) ? 'mai_analytics_synced' : 'mai_analytics_provider_last_sync';
		$last_sync  = (int) get_option( $option_key, 0 );

		// If sync has never run or hasn't run in 30+ minutes, force it now.
		if ( ! $last_sync || ( time() - $last_sync ) > 30 * MINUTE_IN_SECONDS ) {
			$this->maybe_sync();
		}
	}

	/**
	 * Checks if sync is stale and triggers it in a shutdown callback.
	 *
	 * Called from the REST view-recording endpoint as a fallback for when cron
	 * is not firing. The beacon response is returned immediately; sync runs
	 * after the response via shutdown. Only triggers when sync is 1+ hour stale.
	 *
	 * @return void
	 */
	public function maybe_fallback_sync(): void {
		$data_source = Settings::get( 'data_source' );

		if ( 'disabled' === $data_source ) {
			return;
		}

		// Self-hosted Sync writes to mai_analytics_synced; ProviderSync writes to
		// mai_analytics_provider_last_sync. Read whichever the active mode updates.
		$option_key = ( 'self_hosted' === $data_source ) ? 'mai_analytics_synced' : 'mai_analytics_provider_last_sync';
		$last_sync  = (int) get_option( $option_key, 0 );

		if ( $last_sync && ( time() - $last_sync ) < HOUR_IN_SECONDS ) {
			return;
		}

		register_shutdown_function( [ $this, 'maybe_sync' ] );
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
		$data_source = Settings::get( 'data_source' );

		if ( 'disabled' === $data_source ) {
			return;
		}

		if ( 'self_hosted' === $data_source ) {
			Sync::sync();
		} else {
			ProviderSync::sync();
		}
	}
}

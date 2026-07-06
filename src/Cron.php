<?php

namespace Mai\Analytics;

class Cron {

	/**
	 * Recurring daily trending-prune event. One source of truth for the hook name,
	 * shared by the scheduler and its listener so they can't drift.
	 *
	 * @since 1.2.0
	 */
	public const PRUNE_HOOK = 'mai_analytics_prune_trending';

	/**
	 * One-off trending-prune event, distinct from the recurring hook so a pending
	 * one-off can't block schedule_daily_prune() from registering the recurring
	 * event. Also used to continue a bounded prune out-of-band.
	 *
	 * @since 1.2.0
	 */
	public const PRUNE_NOW_HOOK = 'mai_analytics_prune_trending_now';

	/**
	 * Registers cron schedule, sync action, catchup action, and self-healing admin check.
	 */
	public function __construct() {
		add_filter( 'cron_schedules', [ $this, 'add_schedule' ] );
		add_action( 'mai_analytics_cron_sync', [ $this, 'maybe_sync' ] );
		add_action( ProviderSync::CATCHUP_HOOK, [ $this, 'maybe_provider_sync' ] );
		add_action( self::PRUNE_HOOK, [ $this, 'prune_trending' ] );

		// One-off prune scheduled by Upgrade::maybe_upgrade() on a distinct hook so
		// it doesn't block schedule_daily_prune() (called from ensure_healthy()) from
		// registering the recurring PRUNE_HOOK event.
		add_action( self::PRUNE_NOW_HOOK, [ $this, 'prune_trending' ] );

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

		self::schedule_daily_prune();

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
	 * Ensures the recurring daily trending prune is scheduled. Idempotent —
	 * guards on wp_next_scheduled() so calling it from both ensure_healthy()
	 * and Upgrade::maybe_upgrade() never stacks duplicate events.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function schedule_daily_prune(): void {
		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			/**
			 * Filters the recurring trending-prune schedule.
			 *
			 * @since 1.2.0
			 *
			 * @param string $schedule A registered cron schedule name. Default 'daily'.
			 */
			$schedule = (string) apply_filters( 'mai_analytics_prune_schedule', 'daily' );
			wp_schedule_event( time(), $schedule, self::PRUNE_HOOK );
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

	/**
	 * Trending prune. Deletes stale/zero trending rows so the trending index stays
	 * bounded. Skipped when tracking is disabled.
	 *
	 * Bounded per request: a single wp-cron invocation prunes at most
	 * `mai_analytics_prune_max_batches` batches, then — if prunable rows still
	 * remain under the current gates and this run made progress — schedules a
	 * one-off continuation rather than draining a large backlog in one long
	 * request. On a maintained site nothing (or little) is left, so no
	 * continuation is scheduled. Serves both the recurring daily event and the
	 * one-off `mai_analytics_prune_trending_now` hook.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function prune_trending(): void {
		if ( 'disabled' === Settings::get( 'data_source' ) ) {
			return;
		}

		$window = (int) Settings::get( 'trending_window' );

		/**
		 * Filters the max number of delete batches a single prune request performs
		 * before scheduling an out-of-band continuation. Default 5 (≈25k rows/request
		 * at the default batch size) stays well under a typical max_execution_time;
		 * raise it on fast hosts to drain large backlogs in fewer runs.
		 *
		 * @since 1.2.0
		 *
		 * @param int $max_batches Batches per request. Default 5.
		 */
		$max     = max( 1, (int) apply_filters( 'mai_analytics_prune_max_batches', 5 ) );
		$deleted = Stats::prune_trending( $window, false, null, $max );

		// Continue out-of-band only when this run actually deleted rows (so a
		// counted-but-undeletable row can never drive an endless reschedule loop)
		// and prunable rows still remain under the current gates.
		if ( $deleted > 0
			&& ! wp_next_scheduled( self::PRUNE_NOW_HOOK )
			&& Stats::prune_trending( $window, true ) > 0
		) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::PRUNE_NOW_HOOK );
		}
	}
}

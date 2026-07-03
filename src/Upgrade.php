<?php

namespace Mai\Analytics;

/**
 * Fires one-time work when the plugin code version changes. Deploy-agnostic:
 * runs on first load of new code whether updated via the WP updater, WP-CLI,
 * or a git pull, because it compares a stored option against the code constant.
 */
class Upgrade {

	/**
	 * Compares the stored version against the code version. On a change, schedules
	 * an immediate out-of-band trending prune (a separate wp-cron request, never
	 * during the upgrade itself) on its own one-off hook so existing bloat is
	 * cleaned up right away, ensures the recurring daily prune is scheduled
	 * (rather than waiting on admin traffic to hit Cron::ensure_healthy()), then
	 * records the new version.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$stored = get_option( 'mai_analytics_version', '' );

		if ( MAI_ANALYTICS_VERSION === $stored ) {
			return;
		}

		if ( ! wp_next_scheduled( 'mai_analytics_prune_trending_now' ) ) {
			wp_schedule_single_event( time(), 'mai_analytics_prune_trending_now' );
		}

		Cron::schedule_daily_prune();

		update_option( 'mai_analytics_version', MAI_ANALYTICS_VERSION, false );
	}
}

<?php

namespace Mai\Analytics;

class Settings {

	/**
	 * Gets a plugin setting value. All settings are filterable with sensible defaults.
	 *
	 * @param string $key The setting key.
	 *
	 * @return mixed The setting value, or null if the key is not recognized.
	 */
	public static function get( string $key ): mixed {
		$defaults = [
			'trending_window' => apply_filters( 'mai_analytics_trending_window', 6 ),
			'retention'       => apply_filters( 'mai_analytics_retention', 7 ),
			'sync_interval'   => apply_filters( 'mai_analytics_sync_interval', 5 ),
			'exclude_bots'    => apply_filters( 'mai_analytics_exclude_bots', true ),
		];

		return $defaults[ $key ] ?? null;
	}
}

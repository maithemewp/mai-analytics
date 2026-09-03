<?php

namespace Mai\Analytics;

class Publisher {

	/**
	 * Checks whether Mai Publisher is loaded and exposing its settings accessor.
	 *
	 * @since 1.3.5
	 *
	 * @return bool True when maipub_get_option() is available.
	 */
	public static function is_active(): bool {
		return function_exists( 'maipub_get_option' );
	}

	/**
	 * Gets Mai Publisher's Matomo configuration, normalized.
	 *
	 * Reads through maipub_get_option() rather than the raw mai_publisher option.
	 * Sites commonly supply these values via constants or filters that only the
	 * accessor resolves, so a raw option read returns the stored placeholders
	 * instead of the live config. The placeholders Mai Publisher stores for unset
	 * values ('/' for the URL, 0 for the site ID) are normalized to empty strings.
	 *
	 * @since 1.3.5
	 *
	 * @return array {
	 *     @type string $matomo_url     Matomo instance URL, or '' when unset.
	 *     @type string $matomo_site_id Matomo site ID, or '' when unset.
	 *     @type string $matomo_token   Matomo API token, or '' when unset.
	 * }
	 */
	public static function get_matomo_settings(): array {
		$empty = [
			'matomo_url'     => '',
			'matomo_site_id' => '',
			'matomo_token'   => '',
		];

		if ( ! self::is_active() ) {
			return $empty;
		}

		$url     = trim( (string) maipub_get_option( 'matomo_url', '' ) );
		$site_id = absint( maipub_get_option( 'matomo_site_id', 0 ) );
		$token   = trim( (string) maipub_get_option( 'matomo_token', '' ) );

		// Mai Publisher stores '/' when the URL has never been set.
		if ( '/' === $url ) {
			$url = '';
		}

		return [
			'matomo_url'     => $url ? trailingslashit( $url ) : '',
			'matomo_site_id' => $site_id ? (string) $site_id : '',
			'matomo_token'   => $token,
		];
	}

	/**
	 * Checks whether Mai Publisher has enough Matomo config to be worth copying.
	 *
	 * Requires a URL and a site ID. The token is optional here because Mai
	 * Publisher's client-side tracking does not need one, so it is commonly empty
	 * even on a fully working setup.
	 *
	 * @since 1.3.5
	 *
	 * @return bool
	 */
	public static function has_matomo_settings(): bool {
		$settings = self::get_matomo_settings();

		return '' !== $settings['matomo_url'] && '' !== $settings['matomo_site_id'];
	}

	/**
	 * Gets the Matomo settings that copying from Mai Publisher would actually change.
	 *
	 * Compares Mai Publisher's values against what Mai Analytics currently has.
	 * Empty values on Mai Publisher's side are skipped, since there is nothing to
	 * copy from them. URLs are compared with a trailing slash so the same URL
	 * written two ways doesn't read as a difference.
	 *
	 * @since 1.3.5
	 *
	 * @return array Settings keys whose value would change, empty when in sync.
	 */
	public static function get_copyable_matomo_settings(): array {
		$copyable = [];

		foreach ( self::get_matomo_settings() as $key => $value ) {
			if ( '' === $value ) {
				continue;
			}

			$current = trim( (string) Settings::get( $key ) );

			if ( 'matomo_url' === $key && '' !== $current ) {
				$current = trailingslashit( $current );
			}

			if ( $current !== $value ) {
				$copyable[ $key ] = $value;
			}
		}

		return $copyable;
	}
}

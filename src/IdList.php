<?php

declare( strict_types=1 );

namespace Mai\Analytics;

// Prevent direct file access.
defined( 'ABSPATH' ) || die;

/**
 * Checks comma-separated ID lists against the fleet's 100-ID limit.
 *
 * Only the count is checked. How each entry is parsed stays with the route.
 *
 * @since 1.3.8
 */
final class IdList {

	/**
	 * The most IDs one list may hold.
	 *
	 * @since 1.3.8
	 */
	public const MAX = 100;

	/**
	 * Checks that a comma-separated list holds at most MAX entries.
	 *
	 * Used as a REST `validate_callback`, so a list over the limit answers 400.
	 *
	 * @since 1.3.8
	 *
	 * @param mixed $value The raw param value.
	 *
	 * @return bool True for a scalar with MAX or fewer non-empty entries.
	 */
	public static function within_limit( mixed $value ): bool {
		if ( ! is_scalar( $value ) ) {
			return false;
		}

		$entries = array_filter( explode( ',', (string) $value ), fn( string $entry ): bool => '' !== trim( $entry ) );

		return count( $entries ) <= self::MAX;
	}
}

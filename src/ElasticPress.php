<?php

namespace Mai\Analytics;

/**
 * ElasticPress integration. Allowlists analytics meta keys so view/trending
 * ordering keeps working on EP-integrated post queries.
 *
 * @since 1.1.6
 */
class ElasticPress {

	/**
	 * Post meta keys that must be available in the ES index for
	 * `meta_key`/`orderby = meta_value_num` queries (e.g., Mai Grid views/
	 * trending ordering) to work when ElasticPress integrates the query.
	 *
	 * Term and user meta are not listed here: ElasticPress indexes all public
	 * (non-`_`-prefixed) term and user meta by default, so our keys are
	 * already covered there.
	 *
	 * @since 1.1.6
	 */
	private const POST_KEYS = [
		'mai_views',
		'mai_views_web',
		'mai_views_app',
		'mai_trending',
	];

	/**
	 * Registers EP allowlist filters when ElasticPress is active.
	 *
	 * @since 1.1.6
	 */
	public function __construct() {
		if ( ! defined( 'EP_VERSION' ) ) {
			return;
		}

		add_filter( 'ep_prepare_meta_allowed_keys', [ $this, 'allow_post_keys' ] );
	}

	/**
	 * Adds analytics meta keys to ElasticPress's allowlist for post meta.
	 *
	 * Required for EP 5.0+ "manual" meta mode (the default since 5.0), where
	 * a meta key is only indexed if it was selected in the Weighting Dashboard
	 * or returned by this filter. Without this, post grids that combine
	 * `ep_integrate => true` with `orderby => meta_value_num` against
	 * `mai_views` / `mai_trending` would silently produce wrong ordering.
	 *
	 * @since 1.1.6
	 *
	 * @param array $keys Existing allowlisted post meta keys.
	 *
	 * @return array
	 */
	public function allow_post_keys( $keys ): array {
		return array_values( array_unique( array_merge( (array) $keys, self::POST_KEYS ) ) );
	}
}

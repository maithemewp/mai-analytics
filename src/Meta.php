<?php

namespace Mai\Analytics;

class Meta {

	/**
	 * Hooks into WordPress init to register analytics meta keys.
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'register_meta' ] );
	}

	/**
	 * Registers view and trending meta for posts, terms, and users.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		$meta_args = [
			'type'              => 'integer',
			'default'           => 0,
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'absint',
		];

		$keys = [ 'mai_analytics_views', 'mai_analytics_views_web', 'mai_analytics_views_app', 'mai_analytics_trending' ];

		foreach ( $keys as $key ) {
			register_post_meta( '', $key, $meta_args );
			register_term_meta( '', $key, $meta_args );
			register_meta( 'user', $key, $meta_args );
		}
	}
}

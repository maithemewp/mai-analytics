<?php

namespace Mai\Analytics;

class Tracker {

	/**
	 * Hooks into wp_footer to output the tracking beacon script.
	 */
	public function __construct() {
		add_action( 'wp_footer', [ $this, 'output_beacon' ] );
	}

	/**
	 * Outputs the sendBeacon inline script in wp_footer.
	 *
	 * @return void
	 */
	public function output_beacon(): void {
		if ( ! self::is_tracking_enabled() ) {
			return;
		}

		if ( current_user_can( 'edit_posts' ) ) {
			return;
		}

		$url = $this->get_beacon_url();

		if ( ! $url ) {
			return;
		}

		printf(
			"<script>if('sendBeacon' in navigator){navigator.sendBeacon(%s);}</script>\n",
			wp_json_encode( $url )
		);
	}

	/**
	 * Checks whether beacon tracking should be active.
	 *
	 * Disabled on non-production environments to prevent staging/dev buffer pollution.
	 * Override with MAI_ANALYTICS_ENABLE_TRACKING constant or mai_analytics_tracking_enabled filter.
	 *
	 * @return bool True if tracking is enabled.
	 */
	public static function is_tracking_enabled(): bool {
		// Explicit override: always enable if constant is set.
		if ( defined( 'MAI_ANALYTICS_ENABLE_TRACKING' ) && MAI_ANALYTICS_ENABLE_TRACKING ) {
			return true;
		}

		// Disabled via settings.
		if ( 'disabled' === Settings::get( 'data_source' ) ) {
			return false;
		}

		// Default: only track on production.
		$enabled = 'production' === wp_get_environment_type();

		/**
		 * Filters whether beacon tracking is enabled.
		 *
		 * @param bool $enabled Whether tracking is active.
		 */
		return (bool) apply_filters( 'mai_analytics_tracking_enabled', $enabled );
	}

	/**
	 * Determines the REST endpoint URL based on the current page.
	 *
	 * @return string|false The beacon REST URL, or false if the page is not trackable.
	 */
	private function get_beacon_url(): string|false {
		// Singular posts/pages (any public post type, including static front page).
		if ( is_singular() ) {
			$post = get_post();

			if ( ! $post ) {
				return false;
			}

			if ( ! in_array( $post->post_type, get_post_types( [ 'public' => true ] ), true ) ) {
				return false;
			}

			return rest_url( 'mai-analytics/v1/view/post/' . $post->ID );
		}

		// Blog page (post type archive for 'post').
		if ( is_home() ) {
			return rest_url( 'mai-analytics/v1/view/post_type/post' );
		}

		// Custom post type archives.
		if ( is_post_type_archive() ) {
			$post_type_obj = get_queried_object();

			if ( $post_type_obj && isset( $post_type_obj->name ) && $post_type_obj->public ) {
				return rest_url( 'mai-analytics/v1/view/post_type/' . $post_type_obj->name );
			}

			return false;
		}

		// Taxonomy archives.
		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();

			if ( ! $term || ! isset( $term->term_id ) ) {
				return false;
			}

			$taxonomy = get_taxonomy( $term->taxonomy );

			if ( ! $taxonomy || ! $taxonomy->public ) {
				return false;
			}

			return rest_url( 'mai-analytics/v1/view/term/' . $term->term_id );
		}

		// Author archives.
		if ( is_author() ) {
			$author = get_queried_object();

			if ( ! $author || ! isset( $author->ID ) ) {
				return false;
			}

			return rest_url( 'mai-analytics/v1/view/user/' . $author->ID );
		}

		return false;
	}
}

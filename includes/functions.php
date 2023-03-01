<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Function to return the static instance for the tracker.
 *
 * @since 0.1.0
 *
 * @return object
 */
function mai_analytics_tracker() {
	static $cache = null;

	if ( ! is_null( $cache ) ) {
		return $cache;
	}

	// Bail if not using Matomo Analytics (defined in wp-config.php).
	if ( ! ( defined( 'MAI_ANALYTICS' ) && MAI_ANALYTICS ) ) {
		$cache = false;
		return $cache;
	}

	// Bail if Matamo PHP library is not available.
	if ( ! class_exists( 'MatomoTracker' ) ) {
		$cache = false;
		return $cache;
	}

	// Set vars.
	$site_id = defined( 'MAI_ANALYTICS_SITE_ID' ) ? (int) MAI_ANALYTICS_SITE_ID : 0;
	$url     = defined( 'MAI_ANALYTICS_URL' ) ? esc_url( MAI_ANALYTICS_URL ) : 'https://analytics.bizbudding.com';
	$token   = defined( 'MAI_ANALYTICS_TOKEN' ) ? MAI_ANALYTICS_TOKEN : '';

	// Bail if we don't have the data we need.
	if ( ! ( $site_id && $url && $token ) ) {
		$cache = false;
		return $cache;
	}

	// Instantiate the Matomo object.
	$tracker = new MatomoTracker( $site_id, $url );

	// Set authentication token.
	$tracker->setTokenAuth( $token );

	// Set cache.
	$cache = $tracker;

	return $cache;
}

/**
 * Gets current page title.
 *
 * @since 0.1.0
 *
 * @return string
 */
function mai_analytics_get_title() {
	$title = '';

	if ( is_singular() ) {
		$title = get_the_title();

	} elseif ( is_front_page() ) {
		// This would only run if front page is not a static page, since is_singular() is first.
		$title = apply_filters( 'genesis_latest_posts_title', esc_html__( 'Latest Posts', 'mai-engine' ) );

	} elseif ( is_home() ) {
		// This would only run if front page and blog page are static pages, since is_front_page() is first.
		$title = get_the_title( get_option( 'page_for_posts' ) );

	} elseif ( class_exists( 'WooCommerce' ) && is_shop() ) {
		$title = get_the_title( wc_get_page_id( 'shop' ) );

	} elseif ( is_post_type_archive() && genesis_has_post_type_archive_support( mai_get_post_type() ) ) {
		$title = genesis_get_cpt_option( 'headline' );

		if ( ! $title ) {
			$title = post_type_archive_title( '', false );
		}
	} elseif ( is_category() || is_tag() || is_tax() ) {
		/**
		 * WP Query.
		 *
		 * @var WP_Query $wp_query WP Query object.
		 */
		global $wp_query;

		$term = is_tax() ? get_term_by( 'slug', get_query_var( 'term' ), get_query_var( 'taxonomy' ) ) : $wp_query->get_queried_object();

		if ( $term ) {
			$title = get_term_meta( $term->term_id, 'headline', true );

			if ( ! $title ) {
				$title = $term->name;
			}
		}
	} elseif ( is_search() ) {
		$title = apply_filters( 'genesis_search_title_text', esc_html__( 'Search results for: ', 'mai-engine' ) . get_search_query() );

	} elseif ( is_author() ) {
		$title = get_the_author_meta( 'headline', (int) get_query_var( 'author' ) );

		if ( ! $title ) {
			$title = get_the_author_meta( 'display_name', (int) get_query_var( 'author' ) );
		}
	} elseif ( is_date() ) {
		$title = __( 'Archives for ', 'mai-engine' );

		if ( is_day() ) {
			$title .= get_the_date();

		} elseif ( is_month() ) {
			$title .= single_month_title( ' ', false );

		} elseif ( is_year() ) {
			$title .= get_query_var( 'year' );
		}
	} elseif ( is_404() ) {
		$title = apply_filters( 'genesis_404_entry_title', esc_html__( 'Not found, error 404', 'mai-engine' ) );
	}

	$title = apply_filters( 'mai_analytics_page_title', $title );

	return esc_attr( $title );
}
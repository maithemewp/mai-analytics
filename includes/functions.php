<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Function to return the static instance for the tracker.
 *
 * @since 0.1.0
 *
 * @return object|MatomoTracker
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
	$site_id = mai_analytics_site_id();
	$url     = mai_analytics_url();
	$token   = mai_analytics_token();

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
 * Gets the site ID for Matomo.
 *
 * @access private
 *
 * @since 0.1.0
 *
 * @return int
 */
function mai_analytics_site_id() {
	return defined( 'MAI_ANALYTICS_SITE_ID' ) ? (int) MAI_ANALYTICS_SITE_ID : 0;
}

/**
 * Gets the URL for Matomo.
 *
 * @access private
 *
 * @since 0.1.0
 *
 * @return string
 */
function mai_analytics_url() {
	return defined( 'MAI_ANALYTICS_URL' ) ? esc_url( MAI_ANALYTICS_URL ) : 'https://analytics.bizbudding.com';
}

/**
 * Gets the token for Matomo.
 *
 * @access private
 *
 * @since 0.1.0
 *
 * @return string
 */
function mai_analytics_token() {
	return defined( 'MAI_ANALYTICS_TOKEN' ) ? MAI_ANALYTICS_TOKEN : '';
}
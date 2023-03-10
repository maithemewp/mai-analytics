<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Function to return the static instance for the tracker.
 * This apparently does not authenticate the tracker,
 * and still returns the object.
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

	// Bail if not using Matomo Analytics.
	if ( ! mai_analytics_get_option( 'enabled' ) ) {
		$cache = false;
		return $cache;
	}

	// Bail if Matamo PHP library is not available.
	if ( ! class_exists( 'MatomoTracker' ) ) {
		$cache = false;
		return $cache;
	}

	// Set vars.
	$site_id = mai_analytics_get_option( 'site_id' );
	$url     = mai_analytics_get_option( 'url' );
	$token   = mai_analytics_get_option( 'token' );

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
 * Gets a single option value by key.
 *
 * @since TBD
 *
 * @param string $key
 * @param mixed  $default
 *
 * @return mixed
 */
function mai_analytics_get_option( $key, $default = null ) {
	$options = mai_analytics_get_options();
	return isset( $options[ $key ] ) ? $options[ $key ] : $default;
}

/**
 * Gets all options.
 *
 * @since TBD
 *
 * @return array
 */
function mai_analytics_get_options() {
	static $cache = null;

	if ( ! is_null( $cache ) ) {
		return $cache;
	}

	// Get all options, with defaults.
	$options   = (array) get_option( 'mai_analytics', mai_analytics_get_options_defaults() );

	// Setup keys and constants.
	$constants = [
		'enabled'       => 'MAI_ANALYTICS',
		'enabled_admin' => 'MAI_ANALYTICS_ADMIN',
		'debug'         => 'MAI_ANALYTICS_DEBUG',
		'site_id'       => 'MAI_ANALYTICS_SITE_ID',
		'url'           => 'MAI_ANALYTICS_URL',
		'token'         => 'MAI_ANALYTICS_TOKEN',
	];

	// Override any existing constants.
	foreach ( $constants as $key => $constant ) {
		if ( defined( $constant ) ) {
			$options[ $key ] = constant( $constant );
		}
	}

	// Sanitize.
	$cache = mai_analytics_sanitize_options( $options );

	return $cache;
}

/**
 * Gets default options.
 *
 * @since TBD
 *
 * @return array
 */
function mai_analytics_get_options_defaults() {
	static $cache = null;

	if ( ! is_null( $cache ) ) {
		return $cache;
	}

	$cache = [
		'enabled'       => defined( 'MAI_ANALYTICS' ) ? MAI_ANALYTICS : 0,
		'enabled_admin' => defined( 'MAI_ANALYTICS_ADMIN' ) ? MAI_ANALYTICS_ADMIN : 0,
		'debug'         => defined( 'MAI_ANALYTICS_DEBUG' ) ? MAI_ANALYTICS_DEBUG : 0,
		'site_id'       => defined( 'MAI_ANALYTICS_SITE_ID' ) ? MAI_ANALYTICS_SITE_ID : 0,
		'url'           => defined( 'MAI_ANALYTICS_URL' ) ? MAI_ANALYTICS_URL : '',
		'token'         => defined( 'MAI_ANALYTICS_TOKEN' ) ? MAI_ANALYTICS_TOKEN : '',
	];

	return $cache;
}

/**
 * Parses and sanitize all options.
 * Not cached for use when saving values in settings page.
 *
 * @since TBD
 *
 * @return array
 */
function mai_analytics_sanitize_options( $options ) {
	$options = wp_parse_args( $options, mai_analytics_get_options_defaults() );

	// Sanitize.
	$options['enabled']       = rest_sanitize_boolean( $options['enabled'] );
	$options['enabled_admin'] = rest_sanitize_boolean( $options['enabled_admin'] );
	$options['debug']   = rest_sanitize_boolean( $options['debug'] );
	$options['site_id']       = absint( $options['site_id'] );
	$options['url']           = trailingslashit( esc_url( $options['url'] ) );
	$options['token']         = sanitize_key( $options['token'] );

	return $options;
}

/**
 * If on a page that we should be tracking.
 *
 * @access private
 *
 * @since TBD
 *
 * @return bool
 */
function mai_analytics_should_track() {
	static $cache = null;

	if ( ! is_null( $cache ) ) {
		return $cache;
	}

	$cache = false;

	// Bail if we are in an ajax call.
	if ( wp_doing_ajax() ) {
		return $cache;
	}

	// Bail if this is a JSON request.
	if ( wp_is_json_request() ) {
		return $cache;
	}

	// Bail if this running via a CLI command.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return $cache;
	}

	// Bail if admin page and we're not tracking.
	if ( ! mai_analytics_get_option( 'enabled_admin' ) && is_admin() ) {
		return $cache;
	}

	// We got here, set cache and let's track it.
	$cache = true;

	return $cache;
}

/**
 * Push a debug message to Spatie Ray and the Console.
 *
 * @since TBD
 *
 * @param string $log The log string.
 * @param bool   $script Whether to add script tags if logging in console.
 *
 * @return void
 */
function mai_analytics_debug( $log, $script = true ) {
	if ( ! mai_analytics_get_option( 'debug' ) ) {
		return;
	}

	mai_analytics_ray( $log );

	$console_log = sprintf( 'console.log( %s )', json_encode( "Mai Analytics / {$log}", JSON_HEX_TAG ) );

	if ( $script ) {
		$console_log = '<script>' .  $console_log . '</script>';
	}

	echo $console_log;
}

/**
 * Debug via Spatie Ray.
 *
 * @link https://spatie.be/docs/ray/v1/the-ray-desktop-app/discovering-the-ray-app#content-connecting-to-remote-servers
 *
 * @since TBD
 *
 * @param mixed $log
 *
 * @return void
 */
function mai_analytics_ray( $log ) {
	if ( ! function_exists( 'ray' ) ) {
		return;
	}

	ray( $log );
}

/**
 * Gets DOMDocument object.
 * Copies mai_get_dom_document() in Mai Engine, but without dom->replaceChild().
 *
 * @access private
 *
 * @since TBD
 *
 * @param string $html Any given HTML string.
 *
 * @return DOMDocument
 */
function mai_analytics_get_dom_document( $html ) {
	// Create the new document.
	$dom = new DOMDocument();

	// Modify state.
	$libxml_previous_state = libxml_use_internal_errors( true );

	// Load the content in the document HTML.
	$dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ) );

	// Remove <!DOCTYPE.
	$dom->removeChild( $dom->doctype );

	// Remove <html><body></body></html>.
	// $dom->replaceChild( $dom->firstChild->firstChild->firstChild, $dom->firstChild ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// Handle errors.
	libxml_clear_errors();

	// Restore.
	libxml_use_internal_errors( $libxml_previous_state );

	return $dom;
}

/**
 * Gets membership plan IDs.
 * Cached incase we need to call this again later on same page load.
 *
 * @since TBD
 *
 * @param int $user_id The logged in user ID.
 *
 * @return array|int[]
 */
function mai_analytics_get_membership_plan_ids( $user_id ) {
	static $cache = [];

	if ( isset( $cache[ $user_id ] ) ) {
		return $cache[ $user_id ];
	}

	$cache[ $user_id ] = [];

	// Bail if Woo Memberships is not active.
	if ( ! ( class_exists( 'WooCommerce' ) && function_exists( 'wc_memberships_get_user_memberships' ) ) ) {
		return $cache[ $user_id ];
	}

	// Get active memberships.
	$memberships = wc_memberships_get_user_memberships( $user_id, array( 'status' => 'active' ) );

	if ( $memberships ) {
		// Get active membership IDs.
		$cache[ $user_id ] = wp_list_pluck( $memberships, 'plan_id' );
	}

	return $cache[ $user_id ];
}

/**
 * Gets user taxonomies.
 * Cached incase we need to call this again later on same page load.
 *
 * Returns:
 * [
 *   'taxonomy_one' => [
 *     123 => 'Term Name 1',
 *     321 => 'Term Name 2',
 *   ],
 *   'taxonomy_two' => [
 *     456 => 'Term Name 3',
 *     654 => 'Term Name 4',
 *   ],
 * ]
 *
 * @since TBD
 *
 * @return array
 */
function mai_analytics_get_user_taxonomies( $user_id = 0 ) {
	static $cache = [];

	if ( isset( $cache[ $user_id ] ) ) {
		return $cache[ $user_id ];
	}

	$cache[ $user_id ] = [];
	$taxonomies        = get_object_taxonomies( 'user' );

	// Bail if no taxonomies registered on users.
	if ( ! $taxonomies ) {
		return $cache[ $user_id ];
	}

	foreach ( $taxonomies as $taxonomy ) {
		$terms = wp_get_object_terms( $user_id, $taxonomy );

		if ( $terms && ! is_wp_error( $terms ) ) {
			$cache[ $user_id ][ $taxonomy ] = wp_list_pluck( $terms, 'name', 'term_id' );
		}
	}

	return $cache[ $user_id ];
}
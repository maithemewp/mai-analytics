<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Adds element attributes.
 *
 * If you set the same attribute or the same class on multiple elements within one block,
 * the first element found will always win. Nested content blocks are currently not supported in Matomo.
 * This would happen if a Mai Ad block was used inside of a Mai CCA,
 * the CCA would take precedence and the Ad links will have the content piece.
 *
 * @since 0.3.0
 *
 * @param string $content The content.
 * @param string $name    The name.
 * @param bool   $force   Whether to force override existing tracking attributes, if they already exist.
 *
 * @return string
 */
function mai_analytics_add_attributes( $content, $name ) {
	// Bail if no content.
	if ( ! $content ) {
		return $content;
	}

	$dom      = mai_analytics_get_dom_document( $content );
	$children = $dom->childNodes;

	// Bail if no nodes.
	if ( ! $children->length ) {
		return $content;
	}

	// Remove trackers from children.
	$xpath   = new DOMXPath( $dom );
	$tracked = $xpath->query( '//*[@data-track-content] | //*[@data-tcontent-name]' );

	if ( $tracked->length ) {
		foreach ( $tracked as $node ) {
			$node->removeAttribute( 'data-track-content' );
			$node->removeAttribute( 'data-content-name' );
			$node->normalize();
		}
	}

	if ( 1 === $children->length ) {
		// Get first element and set main attributes.
		$first = $children->item(0);

		$first->setAttribute( 'data-track-content', '' );
		$first->setAttribute( 'data-content-name', esc_attr( $name ) );

	} else {
		foreach ( $children as $node ) {
			// Skip if not an element we can add attributes to.
			if ( 'DOMElement' !== get_class( $node ) ) {
				continue;
			}

			// Set main attributes to all top level child elements.
			$node->setAttribute( 'data-track-content', '' );
			$node->setAttribute( 'data-content-name', esc_attr( $name ) );
		}
	}

	// Query elements.
	$xpath   = new DOMXPath( $dom );
	$actions = $xpath->query( '//a | //button | //input[@type="submit"]' );

	if ( $actions->length ) {
		foreach ( $actions as $node ) {
			$piece = 'input' === $node->tagName ? $node->getAttribute( 'value' ) : $node->textContent;
			$piece = trim( esc_attr( $piece ) );

			if ( $piece ) {
				if ( ! $node->hasAttribute( 'data-content-piece' ) ) {
					$node->setAttribute( 'data-content-piece', $piece );
				}
			}

			// Disabled, because target should happen automatically via href in Matomo.
			// $target = 'a' === $node->tagName ? $node->getAttribute( 'href' ) : '';
			// if ( $target ) {
			// 	$node->setAttribute( 'data-content-target', $target );
			// }
		}
	}

	// Save new content.
	$content = $dom->saveHTML();

	return $content;
}

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
 * @since 0.1.0
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
 * @since 0.1.0
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
		'enabled'        => 'MAI_ANALYTICS',
		'enabled_admin'  => 'MAI_ANALYTICS_ADMIN',
		'debug'          => 'MAI_ANALYTICS_DEBUG',
		'site_id'        => 'MAI_ANALYTICS_SITE_ID',
		'url'            => 'MAI_ANALYTICS_URL',
		'token'          => 'MAI_ANALYTICS_TOKEN',
		'trending_days'  => 'MAI_ANALYTICS_TRENDING_DAYS',
		'views_days'     => 'MAI_ANALYTICS_VIEWS_DAYS',
		'views_interval' => 'MAI_ANALYTICS_VIEWS_INTERVAL',
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
 * @since 0.1.0
 *
 * @return array
 */
function mai_analytics_get_options_defaults() {
	static $cache = null;

	if ( ! is_null( $cache ) ) {
		return $cache;
	}

	$cache = [
		'enabled'        => defined( 'MAI_ANALYTICS' ) ? MAI_ANALYTICS : 0,
		'enabled_admin'  => defined( 'MAI_ANALYTICS_ADMIN' ) ? MAI_ANALYTICS_ADMIN : 0,
		'debug'          => defined( 'MAI_ANALYTICS_DEBUG' ) ? MAI_ANALYTICS_DEBUG : 0,
		'site_id'        => defined( 'MAI_ANALYTICS_SITE_ID' ) ? MAI_ANALYTICS_SITE_ID : 0,
		'url'            => defined( 'MAI_ANALYTICS_URL' ) ? MAI_ANALYTICS_URL : '',
		'token'          => defined( 'MAI_ANALYTICS_TOKEN' ) ? MAI_ANALYTICS_TOKEN : '',
		'trending_days'  => defined( 'MAI_ANALYTICS_TRENDING_DAYS' ) ? MAI_ANALYTICS_TRENDING_DAYS : 30,
		'views_days'     => defined( 'MAI_ANALYTICS_VIEWS_DAYS' ) ? MAI_ANALYTICS_VIEWS_DAYS : 365,
		'views_interval' => defined( 'MAI_ANALYTICS_VIEWS_INTERVAL' ) ? MAI_ANALYTICS_VIEWS_INTERVAL : 60,
	];

	return $cache;
}

/**
 * Parses and sanitize all options.
 * Not cached for use when saving values in settings page.
 *
 * @since 0.1.0
 *
 * @return array
 */
function mai_analytics_sanitize_options( $options ) {
	$options = wp_parse_args( $options, mai_analytics_get_options_defaults() );

	// Sanitize.
	$options['enabled']        = rest_sanitize_boolean( $options['enabled'] );
	$options['enabled_admin']  = rest_sanitize_boolean( $options['enabled_admin'] );
	$options['debug']          = rest_sanitize_boolean( $options['debug'] );
	$options['site_id']        = absint( $options['site_id'] );
	$options['url']            = trailingslashit( esc_url( $options['url'] ) );
	$options['token']          = sanitize_key( $options['token'] );
	$options['trending_days']  = absint( $options['trending_days'] );
	$options['views_days']     = absint( $options['views_days'] );
	$options['views_interval'] = absint( $options['views_interval'] );

	return $options;
}

/**
 * If on a page that we should be tracking.
 *
 * @access private
 *
 * @since 0.1.0
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
 * Get processed content.
 * Take from mai_get_processed_content() in Mai Engine.
 *
 * @since 0.1.0
 *
 * @return string
 */
function mai_analytics_get_processed_content( $content ) {
	if ( function_exists( 'mai_get_processed_content' ) ) {
		return mai_get_processed_content( $content );
	}

	/**
	 * Embed.
	 *
	 * @var WP_Embed $wp_embed Embed object.
	 */
	global $wp_embed;

	$blocks  = has_blocks( $content );
	$content = $wp_embed->autoembed( $content );           // WP runs priority 8.
	$content = $wp_embed->run_shortcode( $content );       // WP runs priority 8.
	$content = $blocks ? do_blocks( $content ) : $content; // WP runs priority 9.
	$content = wptexturize( $content );                    // WP runs priority 10.
	$content = ! $blocks ? wpautop( $content ) : $content; // WP runs priority 10.
	$content = shortcode_unautop( $content );              // WP runs priority 10.
	$content = function_exists( 'wp_filter_content_tags' ) ? wp_filter_content_tags( $content ) : wp_make_content_images_responsive( $content ); // WP runs priority 10. WP 5.5 with fallback.
	$content = do_shortcode( $content );                   // WP runs priority 11.
	$content = convert_smilies( $content );                // WP runs priority 20.

	return $content;
}


/**
 * Gets views for display.
 *
 * @since 0.4.0
 *
 * @param array $atts The shortcode atts.
 * @param int   $id   The post or term ID to get views from.
 *
 * @return string
 */
function mai_analytics_get_views( $atts = [] ) {
	global $mai_term;

	// Atts.
	$atts = shortcode_atts(
		[
			'object'             => ! is_null( $mai_term ) ? 'term' : 'post',  // Either 'post'/'' or 'term'.
			'id'                 => '',      // The post/term ID.
			'views'               => '',      // Empty for all, and 'trending' to view trending views.
			'min'                => 20,      // Minimum number of views before displaying.
			'format'             => 'short', // Use short format (2k+) or show full number (2,143). Currently accepts 'short', '', or a falsey value.
			'style'              => 'display:inline-flex;align-items:center;',
			'icon'               => 'heart',
			'icon_style'         => 'solid',
			'icon_size'          => '0.85em',
			'icon_margin_top'    => '0',
			'icon_margin_right'  => '0.25em',
			'icon_margin_bottom' => '0',
			'icon_margin_left'   => '0',
		],
		$atts,
		'mai_views'
	);

	// Sanitize.
	$atts = [
		'object'             => sanitize_key( $atts['object'] ),
		'id'                 => absint( $atts['id'] ),
		'views'               => sanitize_key( $atts['views'] ),
		'min'                => absint( $atts['min'] ),
		'format'             => esc_html( $atts['format'] ),
		'style'              => esc_attr( $atts['style'] ),
		'icon'               => sanitize_key( $atts['icon'] ),
		'icon_style'         => sanitize_key( $atts['icon_style'] ),
		'icon_size'          => esc_attr( $atts['icon_size'] ),
		'icon_margin_top'    => esc_attr( $atts['icon_margin_top'] ),
		'icon_margin_right'  => esc_attr( $atts['icon_margin_right'] ),
		'icon_margin_bottom' => esc_attr( $atts['icon_margin_bottom'] ),
		'icon_margin_left'   => esc_attr( $atts['icon_margin_left'] ),
	];

	// Get views.
	$views = mai_analytics_get_view_count( $atts );

	// Bail if no views or not over the minimum.
	if ( ! $views || $views < $atts['min'] ) {
		return;
	}

	// Get markup/values.
	$views = 'short' === $atts['format'] ? mai_analytics_get_short_number( $views ) : number_format_i18n( $views );
	$style = $atts['style'] ? sprintf( ' style="%s"', $atts['style'] ) : '';
	$icon  = $atts['icon'] && function_exists( 'mai_get_icon' ) ? mai_get_icon(
		[
			'icon'          => $atts['icon'],
			'style'         => $atts['icon_style'],
			'size'          => $atts['icon_size'],
			'margin_top'    => $atts['icon_margin_top'],
			'margin_right'  => $atts['icon_margin_right'],
			'margin_bottom' => $atts['icon_margin_bottom'],
			'margin_left'   => $atts['icon_margin_left'],
		]
	) : '';

	// Build markup.
	$html = sprintf( '<span class="mai-views"%s>%s<span class="mai-views__count">%s</span></span>', $style, $icon, $views );

	// Allow filtering of markup.
	$views = apply_filters( 'mai_analytics_entry_views', $html );

	return $views;
}

/**
 * Retrieve view count for a post.
 *
 * @since 0.4.0
 *
 * @param array $args The view args.
 *
 * @return int $views Post View.
 */
function mai_analytics_get_view_count( $args ) {
	global $mai_term;

	$args = wp_parse_args( $args,
		[
			'object' => ! is_null( $mai_term ) ? 'term' : 'post',
			'id'     => '',
			'views'   => '',
		]
	);

	$args['object'] = sanitize_key( $args['object'] );
	$args['views']   = sanitize_key( $args['views'] );
	$args['id']     = ! $args['id'] && 'term' === $args['object'] && ! is_null( $mai_term ) ? $mai_term->term_id : get_the_ID();

	if ( ! $args['id'] ) {
		return 0;
	}

	$key   = 'trending' === $args['views'] ? 'mai_trending' : 'mai_views';
	$count = 'term' === $args['object'] ? get_term_meta( $args['id'], $key, true ) : get_post_meta( $args['id'], $key, true );

	return absint( $count );
}

/**
 * Gets a shortened number value for number.
 *
 * @since 0.4.0
 *
 * @param int $number The number.
 *
 * @return string
 */
function mai_analytics_get_short_number( int $number ) {
	if ( $number < 1000 ) {
		return sprintf( '%d', $number );
	}

	if ( $number < 1000000 ) {
		return sprintf( '%d%s', floor( $number / 1000 ), 'K+' );
	}

	if ( $number >= 1000000 && $number < 1000000000 ) {
		return sprintf( '%d%s', floor( $number / 1000000 ), 'M+' );
	}

	if ( $number >= 1000000000 && $number < 1000000000000 ) {
		return sprintf( '%d%s', floor( $number / 1000000000 ), 'B+' );
	}

	return sprintf( '%d%s', floor( $number / 1000000000000 ), 'T+' );
};

/**
 * Push a debug message to Spatie Ray and the Console.
 *
 * @since 0.1.0
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
 * @since 0.1.0
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
 * @since 0.1.0
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

	// Encode.
	$html = mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' );

	// Load the content in the document HTML.
	$dom->loadHTML( "<div>$html</div>" );

	// Handle wraps.
	$container = $dom->getElementsByTagName('div')->item(0);
	$container = $container->parentNode->removeChild( $container );

	while ( $dom->firstChild ) {
		$dom->removeChild( $dom->firstChild );
	}

	while ( $container->firstChild ) {
		$dom->appendChild( $container->firstChild );
	}

	// Handle errors.
	libxml_clear_errors();

	// Restore.
	libxml_use_internal_errors( $libxml_previous_state );

	return $dom;
}

/**
 * Get current page data.
 *
 * @since 0.4.0
 *
 * @param string $key
 *
 * @return array|string
 */
function mai_analytics_get_current_page( $key = '' ) {
	static $data = null;

	if ( ! is_null( $data ) ) {
		return $key ? $data[ $key ] : $data;
	}

	$data = [
		'type' => '',
		'name' => '',
		'id'   => '',
		'url'  => '',
	];

	// Single post.
	if ( is_singular() ) {
		$object = get_post_type_object( get_post_type() );

		if ( $object ) {
			$data['type'] = 'post';
			$data['name'] = $object->labels->singular_name; // Singular name.
			$data['id']   = get_the_ID();
			$data['url']  = get_permalink();
		}
	}
	// Post type archive.
	elseif ( is_home() ) {
		$object = get_post_type_object( 'post' );

		if ( $object ) {
			$post_id      = absint( get_option( 'page_for_posts' ) );
			$data['name'] = $object->label; // Plural name.
			$data['id']   = $post_id;
			$data['url']  = $post_id ? get_permalink( $post_id ) : get_home_url();
		}
	}
	// Custom post type archive.
	elseif ( is_post_type_archive() ) {
		$object = get_post_type_object( get_post_type() );

		if ( $object ) {
			$data['name'] = $object->label; // Plural name.
			$data['url']  = get_post_type_archive_link( $object->name );
		}
	}
	// Taxonomy archive.
	elseif ( is_category() || is_tag() || is_tax() ) {
		$object = get_queried_object();

		if ( $object  ) {
			$taxonomy = get_taxonomy( $object->taxonomy );

			if ( $taxonomy ) {
				$data['type'] = 'term';
				$data['name'] = $taxonomy->labels->singular_name; // Singular name.
				$data['id']   = $object->term_id;
				$data['url']  = get_term_link( $object );
			}
		}
	}
	// Date archives.
	elseif ( is_date() || is_year() || is_month() || is_day() || is_time() ) {
		$data['name'] = 'Date';
	}
	// Author archives.
	elseif ( is_author() ) {
		$data['name'] = 'Author';
	}
	// Search results.
	elseif ( is_search() ) {
		$data['name'] = 'Search';
	}

	return $key ? $data[ $key ] : $data;
}

/**
 * Gets membership plan IDs.
 * Cached incase we need to call this again later on same page load.
 *
 * @since 0.1.0
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
 * @since 0.1.0
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
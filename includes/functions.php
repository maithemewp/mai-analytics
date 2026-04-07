<?php

// Prevent direct file access.
defined( 'ABSPATH' ) || die;

/**
 * Gets formatted views HTML for display.
 *
 * Supports posts and terms. Uses the [mai_views] shortcode atts format.
 *
 * @param array $atts {
 *     Shortcode attributes.
 *
 *     @type string     $object             Object type: 'post', 'term', 'user', or 'post_type'. Default auto-detected.
 *     @type int|string $id                 The object ID (int for post/term/user, string slug for post_type). Default current.
 *     @type string     $views              Empty for all-time, 'trending' for trending. Default ''.
 *     @type int        $min                Minimum views before displaying. Default 20.
 *     @type string     $format             'short' for abbreviated (2K+) or '' for full (2,143). Default 'short'.
 *     @type string     $style              Inline CSS for wrapper. Default 'display:inline-flex;align-items:center;'.
 *     @type string     $before             HTML before the icon. Default ''.
 *     @type string     $after              HTML after the count. Default ''.
 *     @type string     $icon               Icon name for mai_get_icon(). Default 'heart'.
 *     @type string     $icon_style         Icon style (solid, light, etc.). Default 'solid'.
 *     @type string     $icon_size          Icon font-size. Default '0.85em'.
 *     @type string     $icon_margin_top    Icon margin-top. Default '0'.
 *     @type string     $icon_margin_right  Icon margin-right. Default '0.25em'.
 *     @type string     $icon_margin_bottom Icon margin-bottom. Default '0'.
 *     @type string     $icon_margin_left   Icon margin-left. Default '0'.
 * }
 *
 * @return string The views HTML, or empty string if below minimum.
 */
function mai_analytics_get_views( $atts = [] ) {
	$atts = shortcode_atts(
		[
			'object'             => '',
			'id'                 => '',
			'views'              => '',
			'min'                => 20,
			'format'             => 'short',
			'style'              => 'display:inline-flex;align-items:center;',
			'before'             => '',
			'after'              => '',
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

	$atts = [
		'object'             => sanitize_key( $atts['object'] ),
		'id'                 => absint( $atts['id'] ),
		'views'              => sanitize_key( $atts['views'] ),
		'min'                => absint( $atts['min'] ),
		'format'             => esc_html( $atts['format'] ),
		'style'              => esc_attr( $atts['style'] ),
		'before'             => esc_html( $atts['before'] ),
		'after'              => esc_html( $atts['after'] ),
		'icon'               => sanitize_key( $atts['icon'] ),
		'icon_style'         => sanitize_key( $atts['icon_style'] ),
		'icon_size'          => esc_attr( $atts['icon_size'] ),
		'icon_margin_top'    => esc_attr( $atts['icon_margin_top'] ),
		'icon_margin_right'  => esc_attr( $atts['icon_margin_right'] ),
		'icon_margin_bottom' => esc_attr( $atts['icon_margin_bottom'] ),
		'icon_margin_left'   => esc_attr( $atts['icon_margin_left'] ),
	];

	$count = mai_analytics_get_count( $atts );

	if ( ! $count || $count < $atts['min'] ) {
		return '';
	}

	$display = 'short' === $atts['format'] ? mai_analytics_get_short_number( $count ) : number_format_i18n( $count );
	$style   = $atts['style'] ? sprintf( ' style="%s"', $atts['style'] ) : '';
	$icon    = $atts['icon'] && function_exists( 'mai_get_icon' ) ? mai_get_icon(
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

	$html = sprintf(
		'<span class="mai-analytics"%s>%s%s<span class="mai-analytics__count">%s</span>%s</span>',
		$style,
		$atts['before'],
		$icon,
		$display,
		$atts['after']
	);

	$html = apply_filters( 'mai_analytics_entry_views', $html );

	// Backward compat: fire old filter with deprecation notice.
	if ( has_filter( 'mai_publisher_entry_views' ) ) {
		$html = apply_filters_deprecated(
			'mai_publisher_entry_views',
			[ $html ],
			'1.0.0',
			'mai_analytics_entry_views'
		);
	}

	return $html;
}

/**
 * Gets the view count for a post, term, user, or post type archive.
 *
 * Auto-detects the current context when no arguments are provided:
 * singular pages, term/author archives, blog page, and CPT archives.
 *
 * @param array $args {
 *     @type string     $object Object type: 'post', 'term', 'user', or 'post_type'. Default auto-detected.
 *     @type int|string $id     The object ID (int for post/term/user, string slug for post_type). Default current.
 *     @type string     $views  Empty for all-time, 'trending' for trending. Default ''.
 * }
 *
 * @return int The view count.
 */
function mai_analytics_get_count( $args = [] ) {
	global $mai_term;

	$args = wp_parse_args( $args, [
		'object' => '',
		'id'     => '',
		'views'  => '',
	] );

	$args['views'] = sanitize_key( $args['views'] );

	// Auto-detect object type and ID when not explicitly provided.
	if ( ! $args['object'] && ! $args['id'] ) {
		$detected       = mai_analytics_detect_context();
		$args['object'] = $detected['object'];
		$args['id']     = $detected['id'];
	}

	$args['object'] = sanitize_key( $args['object'] );

	// Fallback for legacy callers passing only 'object' without 'id'.
	if ( ! $args['id'] ) {
		if ( 'term' === $args['object'] && ! is_null( $mai_term ) ) {
			$args['id'] = $mai_term->term_id;
		} elseif ( 'post' === $args['object'] ) {
			$args['id'] = get_the_ID();
		}
	}

	if ( ! $args['id'] ) {
		return 0;
	}

	// Post type archives store counts in options, not meta.
	if ( 'post_type' === $args['object'] ) {
		$option = 'trending' === $args['views'] ? 'mai_analytics_post_type_trending' : 'mai_analytics_post_type_views';
		$values = get_option( $option, [] );
		return absint( $values[ $args['id'] ] ?? 0 );
	}

	$key = 'trending' === $args['views'] ? 'mai_trending' : 'mai_views';

	$count = match ( $args['object'] ) {
		'term' => get_term_meta( $args['id'], $key, true ),
		'user' => get_user_meta( $args['id'], $key, true ),
		default => get_post_meta( $args['id'], $key, true ),
	};

	return absint( $count );
}

/**
 * Detects the current page context for view count lookups.
 *
 * Returns the object type and ID based on WordPress conditional tags.
 * Used by mai_analytics_get_count() when no explicit args are provided.
 *
 * @return array { object: string, id: int|string }
 */
function mai_analytics_detect_context() {
	global $mai_term;

	// Term grid loop context (Mai Theme global).
	if ( ! is_null( $mai_term ) ) {
		return [ 'object' => 'term', 'id' => $mai_term->term_id ];
	}

	// Inside a post loop — use the post's ID.
	if ( in_the_loop() ) {
		return [ 'object' => 'post', 'id' => get_the_ID() ];
	}

	// Singular post/page.
	if ( is_singular() ) {
		return [ 'object' => 'post', 'id' => get_the_ID() ];
	}

	// Blog page (posts archive).
	if ( is_home() ) {
		return [ 'object' => 'post_type', 'id' => 'post' ];
	}

	// CPT archive.
	if ( is_post_type_archive() ) {
		$obj = get_queried_object();
		if ( $obj && isset( $obj->name ) ) {
			return [ 'object' => 'post_type', 'id' => $obj->name ];
		}
	}

	// Taxonomy archive.
	if ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();
		if ( $term && isset( $term->term_id ) ) {
			return [ 'object' => 'term', 'id' => $term->term_id ];
		}
	}

	// Author archive.
	if ( is_author() ) {
		$author = get_queried_object();
		if ( $author && isset( $author->ID ) ) {
			return [ 'object' => 'user', 'id' => $author->ID ];
		}
	}

	// Fallback.
	return [ 'object' => 'post', 'id' => get_the_ID() ];
}

/**
 * Formats a number into a shortened display string.
 *
 * @param int $number The number to format.
 *
 * @return string Formatted string (e.g., '2K+', '1M+').
 */
function mai_analytics_get_short_number( int $number ) {
	if ( $number < 1000 ) {
		return sprintf( '%d', $number );
	}

	if ( $number < 1000000 ) {
		return sprintf( '%d%s', floor( $number / 1000 ), 'K+' );
	}

	if ( $number < 1000000000 ) {
		return sprintf( '%d%s', floor( $number / 1000000 ), 'M+' );
	}

	if ( $number < 1000000000000 ) {
		return sprintf( '%d%s', floor( $number / 1000000000 ), 'B+' );
	}

	return sprintf( '%d%s', floor( $number / 1000000000000 ), 'T+' );
}

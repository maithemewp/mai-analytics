<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The content tracking class.
 */
class Mai_Analytics_Content_Tracking {
	/**
	 * Construct the class.
	 *
	 * @return void
	 */
	function __construct() {
		$this->hooks();
	}

	/**
	 * Runs frontend hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	function hooks() {
		add_filter( 'wp_nav_menu',    [ $this, 'add_menu_attributes' ], 10, 2 );
		add_filter( 'maicca_content', [ $this, 'add_cca_attributes' ], 12, 2 );
		add_filter( 'maiam_ad',       [ $this, 'add_ad_attributes' ], 12, 2 );
		add_filter( 'render_block',   [ $this, 'render_navigation_block' ], 10, 2 );
		add_filter( 'render_block',   [ $this, 'render_post_preview_block' ], 10, 2 );
	}

	/**
	 * Add attributes to menu.
	 *
	 * @since 0.4.0
	 *
	 * @param string   $nav_menu The HTML content for the navigation menu.
	 * @param stdClass $args     An object containing wp_nav_menu() arguments.
	 *
	 * @return string
	 */
	function add_menu_attributes( $nav_menu, $args ) {
		// Bail if not tracking.
		if ( ! $this->should_track() ) {
			return $nav_menu;
		}

		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $nav_menu;
		}

		$slug = $args->menu instanceof WP_Term ? $args->menu->slug : $args->menu;

		if ( ! $slug ) {
			return $nav_menu;
		}

		return mai_analytics_add_attributes( $nav_menu, 'mai-menu-' . $this->get_menu_slug( $slug ) );
	}

	/**
	 * Maybe add attributes to Mai CCA.
	 *
	 * @since 0.1.0
	 *
	 * @param string $content The CCA content.
	 * @param array  $args    The CCA args.
	 *
	 * @return string
	 */
	function add_cca_attributes( $content, $args ) {
		// Bail if not tracking.
		if ( ! $this->should_track() ) {
			return $content;
		}

		// Bail if no name.
		if ( ! isset( $args['id'] ) || empty( $args['id'] ) ) {
			return $content;
		}

		return mai_analytics_add_attributes( $content, get_the_title( $args['id'] ) );
	}

	/**
	 * Maybe add attributes to Mai Ad.
	 *
	 * @since 0.1.0
	 *
	 * @param string $content The CCA content.
	 * @param string $args    The CCA args.
	 *
	 * @return string
	 */
	function add_ad_attributes( $content, $args ) {
		// Bail if not tracking.
		if ( ! $this->should_track() ) {
			return $content;
		}

		// Bail if no name.
		if ( ! isset( $args['name'] ) || empty( $args['name'] ) ) {
			return $content;
		}

		return mai_analytics_add_attributes( $content, trim( $args['name'] ) );
	}

	/**
	 * Add attributes to Navigation menu block.
	 *
	 * @since 0.4.0
	 *
	 * @param string $block_content The existing block content.
	 * @param array  $block         The button block object.
	 *
	 * @return string
	 */
	function render_navigation_block( $block_content, $block ) {
		// Bail if not tracking.
		if ( ! $this->should_track() ) {
			return $block_content;
		}

		// Bail if no content.
		if ( ! $block_content ) {
			return $block_content;
		}

		// Bail if not the block(s) we want.
		if ( 'core/navigation' !== $block['blockName'] ) {
			return $block_content;
		}

		// Bail if no ref.
		if (  ! isset( $block['attrs']['ref'] ) || ! $block['attrs']['ref'] ) {
			return $block_content;
		}

		// Get nav menu slug.
		$menu = get_post( $block['attrs']['ref'] );
		$slug = $menu && $menu instanceof WP_Post ? $menu->post_name : '';

		// Bail if no slug.
		if ( ! $slug ) {
			return $block_content;
		}

		return mai_analytics_add_attributes( $block_content, 'mai-menu-' . $this->get_menu_slug( $slug ) );
	}

	/**
	 * Maybe add attributes to Mai Post Preview block.
	 *
	 * @since 0.4.0
	 *
	 * @param string $block_content The existing block content.
	 * @param array  $block         The button block object.
	 *
	 * @return string
	 */
	function render_post_preview_block( $block_content, $block ) {
		// Bail if not tracking.
		if ( ! $this->should_track() ) {
			return $block_content;
		}

		// Bail if no content.
		if ( ! $block_content ) {
			return $block_content;
		}

		// Bail if not the block(s) we want.
		if ( 'acf/mai-post-preview' !== $block['blockName'] ) {
			return $block_content;
		}

		// Get url from attributes.
		$url = isset( $block['attrs']['data']['url'] ) && ! empty( $block['attrs']['data']['url'] ) ? $block['attrs']['data']['url'] : '';

		// Bail if no url.
		if ( ! $url ) {
			return $block_content;
		}

		// Get name from url.
		$url  = wp_parse_url( $url );
		$url  = isset( $url['host'] ) ? $url['host'] : '' . $url['path'];
		$name = 'Mai Post Preview | ' . $url;

		return mai_analytics_add_attributes( $block_content, $name );
	}

	/**
	 * Get incremented menu slug.
	 *
	 * @since 0.4.0
	 *
	 * @param string $slug The menu slug.
	 *
	 * @return string
	 */
	function get_menu_slug( $slug ) {
		$slugs = $this->get_menus( $slug );
		$slug  = $slugs[ $slug ] > 2 ? $slug . '-' . ( $slugs[ $slug ] - 1 ) : $slug;

		return $slug;
	}

	/**
	 * Get current page menus to increment.
	 *
	 * @since 0.4.0
	 *
	 * @param string $slug The menu slug.
	 *
	 * @return array
	 */
	function get_menus( $slug = '' ) {
		static $menus = [];

		if ( $slug ) {
			if ( isset( $menus[ $slug ] ) ) {
				$menus[ $slug ]++;
			} else {
				$menus[ $slug ] = 1;
			}
		}

		return $menus;
	}

	/**
	 * Checks if we should track.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	function should_track() {
		return ! is_admin() && mai_analytics_should_track();
	}
}

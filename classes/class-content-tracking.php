<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The content tracking class.
 * This requires Matomo tracking code on the site:
 *
 * @link https://developer.matomo.org/guides/tracking-javascript-guide
 *
 * 1. Matomo with your admin or Super User account.
 * 2. Click on the "administration" (cog icon) in the top right menu.
 * 3. Click on "Tracking Code" in the left menu (under the "Measurables" or "Websites" menu).
 * 4. Click on "JavaScript Tracking" section.
 * 5. Select the website you want to track.
 * 6. Copy and paste the JavaScript tracking code into your pages, just after the opening <body> tag (or within the <head> section).
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
		add_filter( 'maicca_content', [ $this, 'add_cca_attributes' ], 12, 2 );
		add_filter( 'maiam_ad',       [ $this, 'add_ad_attributes' ], 12, 2 );
		add_filter( 'render_block',   [ $this, 'render_block' ], 10, 2 );
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
	 * Maybe add attributes to blocks.
	 *
	 * @since TBD
	 *
	 * @param string $block_content The existing block content.
	 * @param array  $block         The button block object.
	 *
	 * @return string
	 */
	function render_block( $block_content, $block ) {
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

		$url  = wp_parse_url( $url );
		$url  = $url['host'] . $url['path'];
		$name = 'Mai Post Preview | ' . $url;

		return mai_analytics_add_attributes( $block_content, $name );
	}

	/**
	 * Checks if we should track.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	function should_track() {
		return ! is_admin() && mai_analytics_should_track() && mai_analytics_tracker();
	}
}

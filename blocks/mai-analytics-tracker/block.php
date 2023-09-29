<?php

// Prevent direct file access.
defined( 'ABSPATH' ) || die;

add_action( 'acf/init', 'mai_register_analytics_tracker_block' );
/**
 * Register Mai UPC block.
 *
 * @since 0.1.0
 *
 * @return void
 */
function mai_register_analytics_tracker_block() {
	register_block_type( __DIR__ . '/block.json' );
}

/**
 * Callback function to render the block.
 *
 * @since 0.1.0
 *
 * @param array    $attributes The block attributes.
 * @param string   $content The block content.
 * @param bool     $is_preview Whether or not the block is being rendered for editing preview.
 * @param int      $post_id The current post being edited or viewed.
 * @param WP_Block $wp_block The block instance (since WP 5.5).
 * @param array    $context The block context array.
 *
 * @return void
 */
function mai_do_analytics_tracker_block( $attributes, $content, $is_preview, $post_id, $wp_block, $context ) {
	if ( $is_preview ) {
		$template = [ [ 'core/paragraph', [], [] ] ];
		$inner    = sprintf( '<InnerBlocks template="%s" />', esc_attr( wp_json_encode( $template ) ) );

		echo $inner;
		return;
	}

	echo mai_analytics_add_attributes( $content, (string) get_field( 'name' ) );
}

add_action( 'acf/init', 'mai_register_analytics_tracker_field_group' );
/**
 * Register field group.
 *
 * @since 0.1.0
 *
 * @return void
 */
function mai_register_analytics_tracker_field_group() {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	acf_add_local_field_group(
		[
			'key'    => 'mai_analytics_tracker_field_group',
			'title'  => __( 'Mai Analytics Tracker', 'mai-analytics' ),
			'fields' => [
				[
					'key'   => 'mai_analytics_tracker_name',
					'label' => __( 'Content Name', 'mai-analytics' ),
					'name'  => 'name',
					'type'  => 'text',
				]
			],
			'location' => [
				[
					[
						'param'    => 'block',
						'operator' => '==',
						'value'    => 'acf/mai-analytics-tracker',
					],
				],
			],
		]
	);
}

add_action( 'admin_init', 'mai_analytics_register_block_script' );
/**
 * Enqueue JS.
 *
 * @since 0.3.0
 *
 * @return void
 */
function mai_analytics_register_block_script() {
	$file      = 'blocks/mai-analytics-tracker/block.js';
	$file_path = MAI_ANALYTICS_PLUGIN_DIR . $file;
	$file_url  = MAI_ANALYTICS_PLUGIN_URL . $file;

	if ( file_exists( $file_path ) ) {
		$version = MAI_ANALYTICS_VERSION . '.' . date( 'njYHi', filemtime( $file_path ) );
		wp_register_script( 'mai-analytics-block', $file_url, [], $version, true );
	}
}
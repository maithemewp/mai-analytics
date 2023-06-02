<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

class Mai_Analytics_Trending {
	/**
	 * Construct the class.
	 *
	 * @return void
	 */
	function __construct() {
		$this->hooks();
	}

	/**
	 * Runs hooks.
	 *
	 * @since TBD
	 *
	 * @return void
	 */
	function hooks() {
		add_filter( 'acf/load_field/key=mai_grid_block_query_by',                 [ $this, 'add_trending_choice' ] );
		add_filter( 'acf/load_field/key=mai_grid_block_post_taxonomies',          [ $this, 'add_show_conditional_logic' ] );
		add_filter( 'acf/load_field/key=mai_grid_block_post_taxonomies_relation', [ $this, 'add_show_conditional_logic' ] );
		add_filter( 'acf/load_field/key=mai_grid_block_posts_orderby',            [ $this, 'add_hide_conditional_logic' ] );
		add_filter( 'acf/load_field/key=mai_grid_block_posts_order',              [ $this, 'add_hide_conditional_logic' ] );

		// Mai Trending Post is priorty 20. This takes over for any legacy sites still running that plugin.
		add_filter( 'mai_post_grid_query_args', [ $this, 'edit_query' ], 30, 2 );

		// Update.
		add_action( 'wp_ajax_mai_analytics_trending',        [ $this, 'update_trending' ] );
		add_action( 'wp_ajax_nopriv_mai_analytics_trending', [ $this, 'update_trending' ] );
	}


	/**
	 * Adds Trending as an "Get Entries By" choice.
	 *
	 * @since TBD
	 *
	 * @param array $field The existing field array.
	 *
	 * @return array
	 */
	function add_trending_choice( $field ) {
		$field['choices'][ 'trending' ] = __( 'Trending', 'mai-analytics' ) . ' (Mai Analytics)';

		return $field;
	}

	/**
	 * Adds conditional logic to show if query by is trending.
	 * This duplicates existing conditions and changes query_by from 'tax_meta' to 'trending'.
	 *
	 * @since TBD
	 *
	 * @param array $field The existing field array.
	 *
	 * @return array
	 */
	function add_show_conditional_logic( $field ) {
		$conditions = [];

		foreach ( $field['conditional_logic'] as $index => $values ) {
			$condition = $values;

			if ( isset( $condition['field'] ) && 'mai_grid_block_query_by' == $condition['field'] ) {
				$condition['value']    = 'trending';
				$condition['operator'] = '==';
			}

			$conditions[] = $condition;
		};

		$field['conditional_logic'] = $conditions ? [ $field['conditional_logic'], $conditions ] : $field['conditional_logic'];

		return $field;
	}

	/**
	 * Adds conditional logic to hide if query by is trending.
	 *
	 * @since TBD
	 *
	 * @param array $field The existing field array.
	 *
	 * @return array
	 */
	function add_hide_conditional_logic( $field ) {
		$field['conditional_logic'][] = [
			'field'    => 'mai_grid_block_query_by',
			'operator' => '!=',
			'value'    => 'trending',
		];

		return $field;
	}

	/**
	 * Modify Mai Post Grid query args.
	 *
	 * @since TBD
	 *
	 * @return array
	 */
	function edit_query( $query_args, $args ) {
		if ( isset( $args['query_by'] ) && $args['query_by'] && 'trending' === $args['query_by'] ) {
			$query_args['meta_key'] = 'mai_trending';
			$query_args['orderby']  = 'meta_value_num';
			$query_args['order']    = 'DESC';
		}

		return $query_args;
	}

	/**
	 * Update post/term trending view counts via ajax.
	 *
	 * @since TBD
	 *
	 * @return void
	 */
	function update_trending() {
		if ( false === check_ajax_referer( 'mai_analytics_trending_nonce', 'nonce' ) ) {
			wp_send_json_error();
			exit();
		}

		$site_id  = mai_analytics_get_option( 'site_id' );
		$site_url = mai_analytics_get_option( 'url' );
		$token    = mai_analytics_get_option( 'token' );
		$days     = mai_analytics_get_option( 'trending_days' );

		if ( ! ( $site_id && $site_url && $token && $days ) ) {
			wp_send_json_error();
			exit();
		}

		$type     = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
		$id       = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : '';
		$url      = isset( $_POST['url'] ) ? esc_url( $_POST['url'] ) : '';
		$current  = isset( $_POST['current'] ) ? absint( $_POST['current'] ) : '';

		if ( ! ( $type && $id && $url && $current ) ) {
			wp_send_json_error();
			exit();
		}

		// Build the API URL.
		$api_url = trailingslashit( $site_url ) . 'index.php';
		$api_url = add_query_arg(
			[
				'module'     => 'API',
				'method'     => 'Actions.getPageUrl',
				'idSite'     => $site_id,
				'token_auth' => $token,
				'pageUrl'    => urlencode( $url ),
				'period'     => 'range',
				'date'       => 'last' . $days,
				'format'     => 'json',
				// 'columns'    => 'nb_visits', // Not working.
			],
			$api_url
		);

		// Send a GET request to the Matomo API.
		$response = wp_remote_get( $api_url );

		// Check for a successful request.
		if ( is_wp_error( $response ) ) {
			wp_send_json_error();
			exit();
		}

		// Bail if not a successful response.
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			wp_send_json_error();
			exit();
		}

		// Get the data.
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );
		$data   = reset( $data ); // Get first. It was returning an array of 1 result.
		$visits = isset( $data['nb_visits'] ) ? absint( $data['nb_visits'] ) : null;

		// Bail if visits are not returned.
		if ( is_null( $visits ) ) {
			wp_send_json_error();
			exit();
		}

		// Update meta.
		switch ( $type ) {
			case 'post':
				update_post_meta( $id, 'mai_trending', $visits );
				update_post_meta( $id, 'mai_trending_updated', $current );
			break;
			case 'term':
				update_term_meta( $id, 'mai_trending', $visits );
				update_term_meta( $id, 'mai_trending_updated', $current );
			break;
		}

		wp_send_json_success( [ 'views' => $visits ] );
		exit();
	}
}
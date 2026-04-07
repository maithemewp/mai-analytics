<?php

namespace Mai\Analytics;

class MaiGrid {

	/**
	 * Registers ACF field modifications and query filters for Mai Grid integration.
	 */
	public function __construct() {
		// Mai Post Grid.
		add_filter( 'acf/load_field/key=mai_grid_block_query_by',                 [ $this, 'add_trending_choice' ] );
		add_filter( 'acf/load_field/key=mai_grid_block_posts_orderby',            [ $this, 'add_views_choice' ] );
		add_filter( 'acf/load_field/key=mai_grid_block_post_taxonomies',          [ $this, 'add_show_conditional_logic' ] );
		add_filter( 'acf/load_field/key=mai_grid_block_post_taxonomies_relation', [ $this, 'add_show_conditional_logic' ] );
		add_filter( 'acf/load_field/key=mai_grid_block_posts_orderby',            [ $this, 'add_hide_conditional_logic' ] );
		add_filter( 'acf/load_field/key=mai_grid_block_posts_order',              [ $this, 'add_hide_conditional_logic' ] );

		// Mai Tax Grid.
		add_filter( 'acf/load_field/key=mai_grid_block_tax_query_by',  [ $this, 'add_trending_choice' ] );
		add_filter( 'acf/load_field/key=mai_grid_block_tax_orderby',   [ $this, 'add_views_choice' ] );
		add_filter( 'acf/load_field/key=mai_grid_block_tax_orderby',   [ $this, 'add_hide_conditional_logic' ] );
		add_filter( 'acf/load_field/key=mai_grid_block_tax_order',     [ $this, 'add_hide_conditional_logic' ] );

		// Query modifications (priority 30, after Mai Trending Post at 20).
		add_filter( 'mai_post_grid_query_args', [ $this, 'handle_query' ], 30, 2 );
		add_filter( 'mai_term_grid_query_args', [ $this, 'handle_query' ], 30, 2 );
	}

	/**
	 * Adds "Trending (Mai Analytics)" as a "Get Entries By" choice.
	 *
	 * @param array $field The ACF field configuration.
	 *
	 * @return array The modified field with the trending choice added.
	 */
	public function add_trending_choice( array $field ): array {
		if ( ! is_admin() ) {
			return $field;
		}

		$field['choices']['trending'] = __( 'Trending', 'mai-analytics' ) . ' (Mai Analytics)';

		return $field;
	}

	/**
	 * Adds "Views (Mai Analytics)" as an "Order By" choice.
	 *
	 * @param array $field The ACF field configuration.
	 *
	 * @return array The modified field with the views choice added.
	 */
	public function add_views_choice( array $field ): array {
		if ( ! is_admin() ) {
			return $field;
		}

		$field['choices'] = array_merge(
			[ 'views' => __( 'Views', 'mai-analytics' ) . ' (Mai Analytics)' ],
			$field['choices']
		);

		return $field;
	}

	/**
	 * Adds conditional logic to show taxonomy fields when trending is selected.
	 *
	 * @param array $field The ACF field configuration.
	 *
	 * @return array The modified field with additional conditional logic rules.
	 */
	public function add_show_conditional_logic( array $field ): array {
		if ( ! is_admin() || empty( $field['conditional_logic'] ) ) {
			return $field;
		}

		$conditions = [];

		foreach ( $field['conditional_logic'] as $values ) {
			$condition = $values;

			if ( isset( $condition['field'] ) && 'mai_grid_block_query_by' === $condition['field'] ) {
				$condition['value']    = 'trending';
				$condition['operator'] = '==';
			}

			$conditions[] = $condition;
		}

		if ( $conditions ) {
			$field['conditional_logic'] = [ $field['conditional_logic'], $conditions ];
		}

		return $field;
	}

	/**
	 * Adds conditional logic to hide orderby/order when trending is selected.
	 *
	 * @param array $field The ACF field configuration.
	 *
	 * @return array The modified field with hide-when-trending conditional logic.
	 */
	public function add_hide_conditional_logic( array $field ): array {
		if ( ! is_admin() ) {
			return $field;
		}

		$key = str_contains( $field['key'], '_tax_' ) ? 'mai_grid_block_tax_query_by' : 'mai_grid_block_query_by';

		$field['conditional_logic'][] = [
			'field'    => $key,
			'operator' => '!=',
			'value'    => 'trending',
		];

		return $field;
	}

	/**
	 * Modifies Mai Grid query args for views/trending ordering.
	 *
	 * @param array $query_args The existing WP_Query or WP_Term_Query arguments.
	 * @param array $args       The Mai Grid block settings.
	 *
	 * @return array The modified query arguments.
	 */
	public function handle_query( array $query_args, array $args ): array {
		if ( isset( $args['query_by'] ) && 'trending' === $args['query_by'] ) {
			$query_args['meta_key'] = 'mai_trending';
			$query_args['orderby']  = 'meta_value_num';
			$query_args['order']    = 'DESC';
		}

		if ( isset( $args['orderby'] ) && 'views' === $args['orderby'] ) {
			$query_args['meta_key'] = 'mai_views';
			$query_args['orderby']  = 'meta_value_num';
		}

		return $query_args;
	}
}

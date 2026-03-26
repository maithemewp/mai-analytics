<?php

namespace Mai\Analytics;

use WP_REST_Request;
use WP_REST_Response;

class AdminRestApi {

	private const NAMESPACE = 'mai-analytics/v1';

	/**
	 * Registers admin REST routes on rest_api_init.
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Registers all admin dashboard REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$permission = fn() => current_user_can( 'edit_others_posts' );

		$page_args = [
			'page' => [
				'default'           => 1,
				'validate_callback' => fn( $p ) => is_numeric( $p ) && (int) $p > 0,
				'sanitize_callback' => 'absint',
			],
			'per_page' => [
				'default'           => 25,
				'validate_callback' => fn( $p ) => is_numeric( $p ) && (int) $p > 0 && (int) $p <= 100,
				'sanitize_callback' => 'absint',
			],
			'orderby' => [
				'default'           => 'views',
				'validate_callback' => fn( $p ) => in_array( $p, [ 'views', 'trending' ], true ),
				'sanitize_callback' => 'sanitize_key',
			],
		];

		register_rest_route( self::NAMESPACE, '/admin/summary', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_summary' ],
			'permission_callback' => $permission,
		] );

		register_rest_route( self::NAMESPACE, '/admin/chart', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_chart' ],
			'permission_callback' => $permission,
			'args'                => [
				'metric' => [
					'default'           => 'total',
					'validate_callback' => fn( $p ) => in_array( $p, [ 'total', 'trending' ], true ),
				],
				'source' => [
					'default'           => 'all',
					'validate_callback' => fn( $p ) => in_array( $p, [ 'all', 'web', 'app' ], true ),
				],
			],
		] );

		register_rest_route( self::NAMESPACE, '/admin/top/posts', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_top_posts' ],
			'permission_callback' => $permission,
			'args'                => array_merge( $page_args, [
				'post_type' => [
					'default'           => '',
					'sanitize_callback' => 'sanitize_key',
				],
				'taxonomy' => [
					'default'           => '',
					'sanitize_callback' => 'sanitize_key',
				],
				'term_id' => [
					'default'           => 0,
					'sanitize_callback' => 'absint',
				],
				'author' => [
					'default'           => 0,
					'sanitize_callback' => 'absint',
				],
			] ),
		] );

		register_rest_route( self::NAMESPACE, '/admin/top/terms', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_top_terms' ],
			'permission_callback' => $permission,
			'args'                => array_merge( $page_args, [
				'taxonomy' => [
					'default'           => '',
					'sanitize_callback' => 'sanitize_key',
				],
			] ),
		] );

		register_rest_route( self::NAMESPACE, '/admin/top/authors', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_top_authors' ],
			'permission_callback' => $permission,
			'args'                => $page_args,
		] );

		register_rest_route( self::NAMESPACE, '/admin/top/archives', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_top_archives' ],
			'permission_callback' => $permission,
			'args'                => [
				'orderby' => [
					'default'           => 'views',
					'validate_callback' => fn( $p ) => in_array( $p, [ 'views', 'trending' ], true ),
				],
			],
		] );

		register_rest_route( self::NAMESPACE, '/admin/filters', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_filters' ],
			'permission_callback' => $permission,
		] );

		// Provider sync/warm endpoints (require manage_options).
		$admin_permission = fn() => current_user_can( 'manage_options' );

		register_rest_route( self::NAMESPACE, '/admin/sync-now', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'sync_now' ],
			'permission_callback' => $admin_permission,
		] );

		register_rest_route( self::NAMESPACE, '/admin/warm', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'warm' ],
			'permission_callback' => $admin_permission,
		] );
	}

	/**
	 * Returns summary card data for the dashboard.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 *
	 * @return WP_REST_Response Summary data with total views, views today, trending count, buffer info.
	 */
	public function get_summary( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$table          = Database::get_table_name();
		$trending_hours = Settings::get( 'trending_window' );

		$total_views = 0;
		$total_views += (int) $wpdb->get_var( "SELECT COALESCE(SUM(meta_value), 0) FROM $wpdb->postmeta WHERE meta_key = 'mai_analytics_views'" );
		$total_views += (int) $wpdb->get_var( "SELECT COALESCE(SUM(meta_value), 0) FROM $wpdb->termmeta WHERE meta_key = 'mai_analytics_views'" );
		$total_views += (int) $wpdb->get_var( "SELECT COALESCE(SUM(meta_value), 0) FROM $wpdb->usermeta WHERE meta_key = 'mai_analytics_views'" );

		$pt_views = get_option( 'mai_analytics_post_type_views', [] );

		if ( is_array( $pt_views ) ) {
			$total_views += array_sum( $pt_views );
		}

		$views_today = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM $table WHERE viewed_at >= UTC_DATE()"
		);

		$trending_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT CONCAT(object_id, '-', object_type, '-', object_key))
				 FROM $table
				 WHERE viewed_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)",
				$trending_hours
			)
		);

		$buffer_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		$last_sync   = get_option( 'mai_analytics_synced', 0 );

		$data_source     = Settings::get( 'data_source' );
		$is_external     = 'self_hosted' !== $data_source;
		$provider_sync   = $is_external ? get_option( 'mai_analytics_provider_last_sync', 0 ) : 0;

		return new WP_REST_Response( [
			'total_views'    => $total_views,
			'views_today'    => $is_external ? null : $views_today,
			'trending_count' => $trending_count,
			'buffer_rows'    => $is_external ? null : $buffer_rows,
			'last_sync'      => $is_external
				? ( $provider_sync ? wp_date( 'Y-m-d H:i:s', $provider_sync ) : null )
				: ( $last_sync ? wp_date( 'Y-m-d H:i:s', $last_sync ) : null ),
			'data_source'    => $data_source,
		] );
	}

	/**
	 * Returns time-series chart data (last 7 days, daily buckets).
	 *
	 * @param WP_REST_Request $request The incoming request with metric and source params.
	 *
	 * @return WP_REST_Response Chart data with labels and datasets.
	 */
	public function get_chart( WP_REST_Request $request ): WP_REST_Response {
		// Chart is disabled in external provider mode (buffer only has app events).
		if ( 'self_hosted' !== Settings::get( 'data_source' ) ) {
			return new WP_REST_Response( [ 'disabled' => true ] );
		}

		global $wpdb;

		$table  = Database::get_table_name();
		$metric = $request->get_param( 'metric' );
		$source = $request->get_param( 'source' );

		// Build WHERE clauses.
		$where = [ 'viewed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)' ];

		if ( 'trending' === $metric ) {
			$trending_hours = Settings::get( 'trending_window' );
			$where          = [ $wpdb->prepare( 'viewed_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)', $trending_hours ) ];
		}

		if ( 'all' !== $source ) {
			$where[] = $wpdb->prepare( 'source = %s', $source );
		}

		$where_sql = implode( ' AND ', $where );

		// Query daily counts.
		if ( 'all' === $source ) {
			// Two datasets: web and app.
			$rows = $wpdb->get_results(
				"SELECT DATE(viewed_at) as day, source, COUNT(*) as cnt
				 FROM $table WHERE $where_sql
				 GROUP BY DATE(viewed_at), source
				 ORDER BY day ASC"
			);
		} else {
			$rows = $wpdb->get_results(
				"SELECT DATE(viewed_at) as day, COUNT(*) as cnt
				 FROM $table WHERE $where_sql
				 GROUP BY DATE(viewed_at)
				 ORDER BY day ASC"
			);
		}

		// Build 7-day label array, filling gaps with zeros.
		$labels   = [];
		$days_map = [];

		for ( $i = 6; $i >= 0; $i-- ) {
			$date              = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
			$labels[]          = wp_date( 'M j', strtotime( $date ) );
			$days_map[ $date ] = [ 'web' => 0, 'app' => 0, 'total' => 0 ];
		}

		foreach ( $rows as $row ) {
			if ( ! isset( $days_map[ $row->day ] ) ) {
				continue;
			}

			if ( isset( $row->source ) ) {
				$days_map[ $row->day ][ $row->source ] = (int) $row->cnt;
				$days_map[ $row->day ]['total']        += (int) $row->cnt;
			} else {
				$days_map[ $row->day ]['total'] = (int) $row->cnt;
			}
		}

		// Build datasets.
		$datasets = [];

		if ( 'all' === $source ) {
			$datasets[] = [
				'label' => 'Web',
				'data'  => array_column( array_values( $days_map ), 'web' ),
			];
			$datasets[] = [
				'label' => 'App',
				'data'  => array_column( array_values( $days_map ), 'app' ),
			];
		} else {
			$datasets[] = [
				'label' => ucfirst( $source ) . ' Views',
				'data'  => array_column( array_values( $days_map ), 'total' ),
			];
		}

		return new WP_REST_Response( [
			'labels'   => $labels,
			'datasets' => $datasets,
		] );
	}

	/**
	 * Returns paginated top posts ranked by views or trending.
	 *
	 * @param WP_REST_Request $request The incoming request with filter and pagination params.
	 *
	 * @return WP_REST_Response Paginated post data with source breakdown.
	 */
	public function get_top_posts( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$orderby   = $request->get_param( 'orderby' );
		$post_type = $request->get_param( 'post_type' );
		$taxonomy  = $request->get_param( 'taxonomy' );
		$term_id   = (int) $request->get_param( 'term_id' );
		$author    = (int) $request->get_param( 'author' );
		$page      = (int) $request->get_param( 'page' );
		$per_page  = (int) $request->get_param( 'per_page' );
		$offset    = ( $page - 1 ) * $per_page;
		$meta_key  = 'trending' === $orderby ? 'mai_analytics_trending' : 'mai_analytics_views';
		$other_key = 'trending' === $orderby ? 'mai_analytics_views' : 'mai_analytics_trending';

		$public_types = get_post_types( [ 'public' => true ] );
		$type_list    = implode( "','", array_map( 'esc_sql', $public_types ) );

		// Build query.
		$joins  = '';
		$wheres = [
			"p.post_status = 'publish'",
		];

		if ( $post_type && in_array( $post_type, $public_types, true ) ) {
			$wheres[] = $wpdb->prepare( 'p.post_type = %s', $post_type );
		} else {
			$wheres[] = "p.post_type IN ('{$type_list}')";
		}

		if ( $author ) {
			$wheres[] = $wpdb->prepare( 'p.post_author = %d', $author );
		}

		if ( $taxonomy && $term_id ) {
			$joins   .= " INNER JOIN $wpdb->term_relationships tr ON p.ID = tr.object_id";
			$joins   .= " INNER JOIN $wpdb->term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";
			$wheres[] = $wpdb->prepare( 'tt.taxonomy = %s AND tt.term_id = %d', $taxonomy, $term_id );
		} elseif ( $taxonomy ) {
			$joins   .= " INNER JOIN $wpdb->term_relationships tr ON p.ID = tr.object_id";
			$joins   .= " INNER JOIN $wpdb->term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";
			$wheres[] = $wpdb->prepare( 'tt.taxonomy = %s', $taxonomy );
		}

		$where_sql = implode( ' AND ', $wheres );

		// Count total.
		$count_sql = "SELECT COUNT(DISTINCT p.ID)
		              FROM $wpdb->posts p
		              INNER JOIN $wpdb->postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '{$meta_key}'
		              {$joins}
		              WHERE {$where_sql} AND CAST(pm.meta_value AS UNSIGNED) > 0";

		$total = (int) $wpdb->get_var( $count_sql );
		$pages = (int) ceil( $total / $per_page );

		// Fetch page.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID, p.post_title, p.post_type, p.post_author,
				        CAST(pm.meta_value AS UNSIGNED) as primary_count,
				        COALESCE(CAST(pm2.meta_value AS UNSIGNED), 0) as secondary_count
				 FROM $wpdb->posts p
				 INNER JOIN $wpdb->postmeta pm ON p.ID = pm.post_id AND pm.meta_key = %s
				 LEFT JOIN $wpdb->postmeta pm2 ON p.ID = pm2.post_id AND pm2.meta_key = %s
				 {$joins}
				 WHERE {$where_sql} AND CAST(pm.meta_value AS UNSIGNED) > 0
				 ORDER BY primary_count DESC
				 LIMIT %d OFFSET %d",
				$meta_key,
				$other_key,
				$per_page,
				$offset
			)
		);

		// Source breakdown from buffer for this page's IDs.
		$source_map = $this->get_source_breakdown(
			array_map( fn( $r ) => (int) $r->ID, $results ),
			'post'
		);

		$items = [];

		foreach ( $results as $row ) {
			$id = (int) $row->ID;

			$items[] = [
				'id'        => $id,
				'title'     => $row->post_title,
				'url'       => get_permalink( $id ),
				'post_type' => $row->post_type,
				'views'     => 'trending' === $orderby ? (int) $row->secondary_count : (int) $row->primary_count,
				'trending'  => 'trending' === $orderby ? (int) $row->primary_count : (int) $row->secondary_count,
				'web'       => $source_map[ $id ]['web'] ?? 0,
				'app'       => $source_map[ $id ]['app'] ?? 0,
			];
		}

		return new WP_REST_Response( [
			'items' => $items,
			'total' => $total,
			'pages' => $pages,
		] );
	}

	/**
	 * Returns paginated top terms ranked by views or trending.
	 *
	 * @param WP_REST_Request $request The incoming request with filter and pagination params.
	 *
	 * @return WP_REST_Response Paginated term data with source breakdown.
	 */
	public function get_top_terms( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$orderby   = $request->get_param( 'orderby' );
		$taxonomy  = $request->get_param( 'taxonomy' );
		$page      = (int) $request->get_param( 'page' );
		$per_page  = (int) $request->get_param( 'per_page' );
		$offset    = ( $page - 1 ) * $per_page;
		$meta_key  = 'trending' === $orderby ? 'mai_analytics_trending' : 'mai_analytics_views';
		$other_key = 'trending' === $orderby ? 'mai_analytics_views' : 'mai_analytics_trending';

		$public_taxonomies = get_taxonomies( [ 'public' => true ] );
		$tax_list          = implode( "','", array_map( 'esc_sql', $public_taxonomies ) );

		$wheres = [ "txn.taxonomy IN ('{$tax_list}')" ];

		if ( $taxonomy && in_array( $taxonomy, $public_taxonomies, true ) ) {
			$wheres = [ $wpdb->prepare( 'txn.taxonomy = %s', $taxonomy ) ];
		}

		$where_sql = implode( ' AND ', $wheres );

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT t.term_id)
			 FROM $wpdb->terms t
			 INNER JOIN $wpdb->term_taxonomy txn ON t.term_id = txn.term_id
			 INNER JOIN $wpdb->termmeta tm ON t.term_id = tm.term_id AND tm.meta_key = '{$meta_key}'
			 WHERE {$where_sql} AND CAST(tm.meta_value AS UNSIGNED) > 0"
		);

		$pages = (int) ceil( $total / $per_page );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT t.term_id, t.name, t.slug, txn.taxonomy,
				        CAST(tm.meta_value AS UNSIGNED) as primary_count,
				        COALESCE(CAST(tm2.meta_value AS UNSIGNED), 0) as secondary_count
				 FROM $wpdb->terms t
				 INNER JOIN $wpdb->term_taxonomy txn ON t.term_id = txn.term_id
				 INNER JOIN $wpdb->termmeta tm ON t.term_id = tm.term_id AND tm.meta_key = %s
				 LEFT JOIN $wpdb->termmeta tm2 ON t.term_id = tm2.term_id AND tm2.meta_key = %s
				 WHERE {$where_sql} AND CAST(tm.meta_value AS UNSIGNED) > 0
				 ORDER BY primary_count DESC
				 LIMIT %d OFFSET %d",
				$meta_key,
				$other_key,
				$per_page,
				$offset
			)
		);

		$source_map = $this->get_source_breakdown(
			array_map( fn( $r ) => (int) $r->term_id, $results ),
			'term'
		);

		$items = [];

		foreach ( $results as $row ) {
			$id   = (int) $row->term_id;
			$term = get_term( $id );

			$items[] = [
				'id'       => $id,
				'name'     => $row->name,
				'url'      => ( $term && ! is_wp_error( $term ) ) ? get_term_link( $term ) : '',
				'taxonomy' => $row->taxonomy,
				'views'    => 'trending' === $orderby ? (int) $row->secondary_count : (int) $row->primary_count,
				'trending' => 'trending' === $orderby ? (int) $row->primary_count : (int) $row->secondary_count,
				'web'      => $source_map[ $id ]['web'] ?? 0,
				'app'      => $source_map[ $id ]['app'] ?? 0,
			];
		}

		return new WP_REST_Response( [
			'items' => $items,
			'total' => $total,
			'pages' => $pages,
		] );
	}

	/**
	 * Returns paginated top authors ranked by views or trending.
	 *
	 * @param WP_REST_Request $request The incoming request with pagination params.
	 *
	 * @return WP_REST_Response Paginated author data with source breakdown.
	 */
	public function get_top_authors( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$orderby   = $request->get_param( 'orderby' );
		$page      = (int) $request->get_param( 'page' );
		$per_page  = (int) $request->get_param( 'per_page' );
		$offset    = ( $page - 1 ) * $per_page;
		$meta_key  = 'trending' === $orderby ? 'mai_analytics_trending' : 'mai_analytics_views';
		$other_key = 'trending' === $orderby ? 'mai_analytics_views' : 'mai_analytics_trending';

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT u.ID)
			 FROM $wpdb->users u
			 INNER JOIN $wpdb->usermeta um ON u.ID = um.user_id AND um.meta_key = '{$meta_key}'
			 WHERE CAST(um.meta_value AS UNSIGNED) > 0"
		);

		$pages = (int) ceil( $total / $per_page );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID, u.display_name,
				        CAST(um.meta_value AS UNSIGNED) as primary_count,
				        COALESCE(CAST(um2.meta_value AS UNSIGNED), 0) as secondary_count
				 FROM $wpdb->users u
				 INNER JOIN $wpdb->usermeta um ON u.ID = um.user_id AND um.meta_key = %s
				 LEFT JOIN $wpdb->usermeta um2 ON u.ID = um2.user_id AND um2.meta_key = %s
				 WHERE CAST(um.meta_value AS UNSIGNED) > 0
				 ORDER BY primary_count DESC
				 LIMIT %d OFFSET %d",
				$meta_key,
				$other_key,
				$per_page,
				$offset
			)
		);

		$source_map = $this->get_source_breakdown(
			array_map( fn( $r ) => (int) $r->ID, $results ),
			'user'
		);

		$items = [];

		foreach ( $results as $row ) {
			$id = (int) $row->ID;

			$items[] = [
				'id'       => $id,
				'name'     => $row->display_name,
				'url'      => get_author_posts_url( $id ),
				'views'    => 'trending' === $orderby ? (int) $row->secondary_count : (int) $row->primary_count,
				'trending' => 'trending' === $orderby ? (int) $row->primary_count : (int) $row->secondary_count,
				'web'      => $source_map[ $id ]['web'] ?? 0,
				'app'      => $source_map[ $id ]['app'] ?? 0,
			];
		}

		return new WP_REST_Response( [
			'items' => $items,
			'total' => $total,
			'pages' => $pages,
		] );
	}

	/**
	 * Returns post type archive view counts (no pagination — typically few rows).
	 *
	 * @param WP_REST_Request $request The incoming request with orderby param.
	 *
	 * @return WP_REST_Response Archive data with source breakdown.
	 */
	public function get_top_archives( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$orderby  = $request->get_param( 'orderby' );
		$table    = Database::get_table_name();
		$views    = get_option( 'mai_analytics_post_type_views', [] );
		$trending = get_option( 'mai_analytics_post_type_trending', [] );

		// Source breakdown from buffer.
		$source_rows = $wpdb->get_results(
			"SELECT object_key, source, COUNT(*) as cnt
			 FROM $table
			 WHERE object_type = 'post_type'
			 GROUP BY object_key, source"
		);

		$source_map = [];

		foreach ( $source_rows as $row ) {
			$source_map[ $row->object_key ][ $row->source ] = (int) $row->cnt;
		}

		// Build items from all known post types with views.
		$all_keys = array_unique( array_merge( array_keys( $views ), array_keys( $trending ) ) );
		$items    = [];

		foreach ( $all_keys as $key ) {
			$pt_obj = get_post_type_object( $key );

			if ( ! $pt_obj ) {
				continue;
			}

			$items[] = [
				'post_type' => $key,
				'name'      => $pt_obj->labels->name,
				'url'       => get_post_type_archive_link( $key ) ?: '',
				'views'     => (int) ( $views[ $key ] ?? 0 ),
				'trending'  => (int) ( $trending[ $key ] ?? 0 ),
				'web'       => $source_map[ $key ]['web'] ?? 0,
				'app'       => $source_map[ $key ]['app'] ?? 0,
			];
		}

		// Sort by requested orderby.
		usort( $items, fn( $a, $b ) => $b[ $orderby ] <=> $a[ $orderby ] );

		return new WP_REST_Response( [
			'items' => $items,
			'total' => count( $items ),
			'pages' => 1,
		] );
	}

	/**
	 * Returns filter options for the dashboard dropdowns.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 *
	 * @return WP_REST_Response Available post types, taxonomies, and authors.
	 */
	public function get_filters( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		// Public post types.
		$post_types = [];

		foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $pt ) {
			$post_types[] = [
				'slug'  => $pt->name,
				'label' => $pt->labels->name,
			];
		}

		// Public taxonomies.
		$taxonomies = [];

		foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $tax ) {
			$taxonomies[] = [
				'slug'  => $tax->name,
				'label' => $tax->labels->name,
			];
		}

		// Authors who have views.
		$authors = $wpdb->get_results(
			"SELECT u.ID, u.display_name
			 FROM $wpdb->users u
			 INNER JOIN $wpdb->usermeta um ON u.ID = um.user_id
			 WHERE um.meta_key = 'mai_analytics_views' AND CAST(um.meta_value AS UNSIGNED) > 0
			 ORDER BY u.display_name ASC"
		);

		$author_list = array_map( fn( $a ) => [
			'id'   => (int) $a->ID,
			'name' => $a->display_name,
		], $authors );

		// Terms grouped by taxonomy (for taxonomy term filter).
		$terms = [];

		foreach ( get_taxonomies( [ 'public' => true ] ) as $tax_name ) {
			$tax_terms = get_terms( [
				'taxonomy'   => $tax_name,
				'hide_empty' => true,
				'number'     => 100,
				'orderby'    => 'name',
			] );

			if ( ! is_wp_error( $tax_terms ) && $tax_terms ) {
				$terms[ $tax_name ] = array_map( fn( $t ) => [
					'id'   => $t->term_id,
					'name' => $t->name,
				], $tax_terms );
			}
		}

		return new WP_REST_Response( [
			'post_types' => $post_types,
			'taxonomies' => $taxonomies,
			'authors'    => $author_list,
			'terms'      => $terms,
		] );
	}

	/**
	 * Triggers an immediate provider sync.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 *
	 * @return WP_REST_Response Status message.
	 */
	public function sync_now( WP_REST_Request $request ): WP_REST_Response {
		if ( 'self_hosted' === Settings::get( 'data_source' ) ) {
			Sync::sync();
			return new WP_REST_Response( [ 'message' => 'Self-hosted sync complete.' ] );
		}

		ProviderSync::sync();

		return new WP_REST_Response( [ 'message' => 'Provider sync complete.' ] );
	}

	/**
	 * Triggers a warm/seed operation for all objects via the active provider.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 *
	 * @return WP_REST_Response Status message with counts.
	 */
	public function warm( WP_REST_Request $request ): WP_REST_Response {
		if ( 'self_hosted' === Settings::get( 'data_source' ) ) {
			return new WP_REST_Response( [ 'message' => 'Warm is only available in provider mode.' ], 400 );
		}

		$total_updated = 0;

		foreach ( ProviderSync::warm() as $progress ) {
			$total_updated += $progress['updated'] ?? 0;
		}

		return new WP_REST_Response( [
			'message' => sprintf( 'Warm complete. Updated %d objects.', $total_updated ),
			'updated' => $total_updated,
		] );
	}

	/**
	 * Gets web/app source breakdown for a set of object IDs from the buffer table.
	 * Only queries the current page's IDs (max 25) to avoid expensive joins.
	 *
	 * @param int[]  $ids         The object IDs to get source counts for.
	 * @param string $object_type The object type: 'post', 'term', or 'user'.
	 *
	 * @return array Associative array of [ id => [ 'web' => N, 'app' => N ] ].
	 */
	private function get_source_breakdown( array $ids, string $object_type ): array {
		if ( empty( $ids ) ) {
			return [];
		}

		global $wpdb;

		$table        = Database::get_table_name();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT object_id, source, COUNT(*) as cnt
				 FROM $table
				 WHERE object_id IN ($placeholders) AND object_type = %s
				 GROUP BY object_id, source",
				array_merge( $ids, [ $object_type ] )
			)
		);

		$map = [];

		foreach ( $rows as $row ) {
			$map[ (int) $row->object_id ][ $row->source ] = (int) $row->cnt;
		}

		return $map;
	}
}

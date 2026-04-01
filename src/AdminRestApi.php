<?php

namespace Mai\Views;

use WP_REST_Request;
use WP_REST_Response;

class AdminRestApi {

	private const NAMESPACE = 'mai-views/v1';

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
			'order' => [
				'default'           => 'desc',
				'validate_callback' => fn( $p ) => in_array( strtolower( $p ), [ 'asc', 'desc' ], true ),
				'sanitize_callback' => fn( $p ) => strtoupper( sanitize_key( $p ) ),
			],
		];

		register_rest_route( self::NAMESPACE, '/admin/summary', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_summary' ],
			'permission_callback' => $permission,
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
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'author' => [
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'search' => [
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'published_days' => [
					'default'           => 0,
					'validate_callback' => fn( $p ) => is_numeric( $p ) && (int) $p >= 0 && (int) $p <= 365,
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
				'search' => [
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				],
			] ),
		] );

		register_rest_route( self::NAMESPACE, '/admin/top/authors', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_top_authors' ],
			'permission_callback' => $permission,
			'args'                => array_merge( $page_args, [
				'search' => [
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				],
			] ),
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
				'order' => [
					'default'           => 'desc',
					'validate_callback' => fn( $p ) => in_array( strtolower( $p ), [ 'asc', 'desc' ], true ),
					'sanitize_callback' => fn( $p ) => strtoupper( sanitize_key( $p ) ),
				],
			],
		] );

		register_rest_route( self::NAMESPACE, '/admin/filters', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_filters' ],
			'permission_callback' => $permission,
		] );

		register_rest_route( self::NAMESPACE, '/admin/search', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'search' ],
			'permission_callback' => $permission,
			'args'                => [
				'type' => [
					'required'          => true,
					'validate_callback' => fn( $p ) => in_array( $p, [ 'author', 'term' ], true ),
					'sanitize_callback' => 'sanitize_key',
				],
				'taxonomy' => [
					'default'           => '',
					'sanitize_callback' => 'sanitize_key',
				],
				'search' => [
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
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

		register_rest_route( self::NAMESPACE, '/admin/health', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'run_health_check' ],
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

		$total_views = 0;
		$total_views += (int) $wpdb->get_var( "SELECT COALESCE(SUM(meta_value), 0) FROM $wpdb->postmeta WHERE meta_key = 'mai_views'" );
		$total_views += (int) $wpdb->get_var( "SELECT COALESCE(SUM(meta_value), 0) FROM $wpdb->termmeta WHERE meta_key = 'mai_views'" );
		$total_views += (int) $wpdb->get_var( "SELECT COALESCE(SUM(meta_value), 0) FROM $wpdb->usermeta WHERE meta_key = 'mai_views'" );

		$pt_views = get_option( 'mai_views_post_type_views', [] );

		if ( is_array( $pt_views ) ) {
			$total_views += array_sum( $pt_views );
		}

		$trending_count = 0;
		$trending_count += (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key = 'mai_trending' AND CAST(meta_value AS UNSIGNED) > 0" );
		$trending_count += (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->termmeta WHERE meta_key = 'mai_trending' AND CAST(meta_value AS UNSIGNED) > 0" );
		$trending_count += (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->usermeta WHERE meta_key = 'mai_trending' AND CAST(meta_value AS UNSIGNED) > 0" );

		$pt_trending = get_option( 'mai_views_post_type_trending', [] );

		if ( is_array( $pt_trending ) ) {
			$trending_count += count( array_filter( $pt_trending ) );
		}

		$trending_views = 0;
		$trending_views += (int) $wpdb->get_var( "SELECT COALESCE(SUM(meta_value), 0) FROM $wpdb->postmeta WHERE meta_key = 'mai_trending'" );
		$trending_views += (int) $wpdb->get_var( "SELECT COALESCE(SUM(meta_value), 0) FROM $wpdb->termmeta WHERE meta_key = 'mai_trending'" );
		$trending_views += (int) $wpdb->get_var( "SELECT COALESCE(SUM(meta_value), 0) FROM $wpdb->usermeta WHERE meta_key = 'mai_trending'" );

		if ( is_array( $pt_trending ) ) {
			$trending_views += array_sum( $pt_trending );
		}

		$data_source   = Settings::get( 'data_source' );
		$is_external   = 'self_hosted' !== $data_source;
		$last_sync     = $is_external
			? get_option( 'mai_views_provider_last_sync', 0 )
			: get_option( 'mai_views_synced', 0 );

		return new WP_REST_Response( [
			'total_views'    => $total_views,
			'trending_views' => $trending_views,
			'trending_count' => $trending_count,
			'last_sync'      => $last_sync ? wp_date( 'Y-m-d H:i:s', $last_sync ) : null,
			'data_source'    => $data_source,
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

		$orderby        = $request->get_param( 'orderby' );
		$order          = $request->get_param( 'order' );
		$post_type      = $request->get_param( 'post_type' );
		$taxonomy       = $request->get_param( 'taxonomy' );
		$term_ids       = array_filter( array_map( 'absint', explode( ',', (string) $request->get_param( 'term_id' ) ) ) );
		$authors        = array_filter( array_map( 'absint', explode( ',', (string) $request->get_param( 'author' ) ) ) );
		$search         = $request->get_param( 'search' );
		$published_days = (int) $request->get_param( 'published_days' );
		$page           = (int) $request->get_param( 'page' );
		$per_page       = (int) $request->get_param( 'per_page' );
		$offset         = ( $page - 1 ) * $per_page;
		$meta_key       = 'trending' === $orderby ? 'mai_trending' : 'mai_views';
		$other_key = 'trending' === $orderby ? 'mai_views' : 'mai_trending';

		$public_types = get_post_types( [ 'public' => true ] );
		$type_list    = implode( "','", array_map( 'esc_sql', $public_types ) );

		// Build query.
		$joins  = '';
		$wheres = [
			"p.post_status = 'publish'",
		];

		if ( $search ) {
			$wheres[] = $wpdb->prepare( 'p.post_title LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
		}

		if ( $post_type && in_array( $post_type, $public_types, true ) ) {
			$wheres[] = $wpdb->prepare( 'p.post_type = %s', $post_type );
		} else {
			$wheres[] = "p.post_type IN ('{$type_list}')";
		}

		if ( $authors ) {
			$author_placeholders = implode( ', ', array_fill( 0, count( $authors ), '%d' ) );
			$wheres[]            = $wpdb->prepare( "p.post_author IN ($author_placeholders)", $authors );
		}

		if ( $taxonomy && $term_ids ) {
			$joins              .= " INNER JOIN $wpdb->term_relationships tr ON p.ID = tr.object_id";
			$joins              .= " INNER JOIN $wpdb->term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";
			$term_placeholders   = implode( ', ', array_fill( 0, count( $term_ids ), '%d' ) );
			$wheres[]            = $wpdb->prepare( "tt.taxonomy = %s AND tt.term_id IN ($term_placeholders)", array_merge( [ $taxonomy ], $term_ids ) );
		} elseif ( $taxonomy ) {
			$joins   .= " INNER JOIN $wpdb->term_relationships tr ON p.ID = tr.object_id";
			$joins   .= " INNER JOIN $wpdb->term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";
			$wheres[] = $wpdb->prepare( 'tt.taxonomy = %s', $taxonomy );
		}

		if ( $published_days > 0 ) {
			$wheres[] = $wpdb->prepare( 'p.post_date > DATE_SUB(NOW(), INTERVAL %d DAY)', $published_days );
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
				 ORDER BY primary_count {$order}
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
		$order     = $request->get_param( 'order' );
		$taxonomy  = $request->get_param( 'taxonomy' );
		$search    = $request->get_param( 'search' );
		$page      = (int) $request->get_param( 'page' );
		$per_page  = (int) $request->get_param( 'per_page' );
		$offset    = ( $page - 1 ) * $per_page;
		$meta_key  = 'trending' === $orderby ? 'mai_trending' : 'mai_views';
		$other_key = 'trending' === $orderby ? 'mai_views' : 'mai_trending';

		$public_taxonomies = get_taxonomies( [ 'public' => true ] );
		$tax_list          = implode( "','", array_map( 'esc_sql', $public_taxonomies ) );

		$wheres = [ "txn.taxonomy IN ('{$tax_list}')" ];

		if ( $taxonomy && in_array( $taxonomy, $public_taxonomies, true ) ) {
			$wheres = [ $wpdb->prepare( 'txn.taxonomy = %s', $taxonomy ) ];
		}

		if ( $search ) {
			$wheres[] = $wpdb->prepare( 't.name LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
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
				 ORDER BY primary_count {$order}
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
		$order     = $request->get_param( 'order' );
		$search    = $request->get_param( 'search' );
		$page      = (int) $request->get_param( 'page' );
		$per_page  = (int) $request->get_param( 'per_page' );
		$offset    = ( $page - 1 ) * $per_page;
		$meta_key  = 'trending' === $orderby ? 'mai_trending' : 'mai_views';
		$other_key = 'trending' === $orderby ? 'mai_views' : 'mai_trending';

		$wheres = [ 'CAST(um.meta_value AS UNSIGNED) > 0' ];

		if ( $search ) {
			$wheres[] = $wpdb->prepare( 'u.display_name LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
		}

		$where_sql = implode( ' AND ', $wheres );

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT u.ID)
			 FROM $wpdb->users u
			 INNER JOIN $wpdb->usermeta um ON u.ID = um.user_id AND um.meta_key = '{$meta_key}'
			 WHERE {$where_sql}"
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
				 WHERE {$where_sql}
				 ORDER BY primary_count {$order}
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
		$orderby   = $request->get_param( 'orderby' );
		$order     = $request->get_param( 'order' );
		$views     = get_option( 'mai_views_post_type_views', [] );
		$trending  = get_option( 'mai_views_post_type_trending', [] );
		$views_web = get_option( 'mai_views_post_type_views_web', [] );
		$views_app = get_option( 'mai_views_post_type_views_app', [] );

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
				'web'       => (int) ( $views_web[ $key ] ?? 0 ),
				'app'       => (int) ( $views_app[ $key ] ?? 0 ),
			];
		}

		// Sort by requested orderby and order.
		usort( $items, fn( $a, $b ) => 'DESC' === $order
			? $b[ $orderby ] <=> $a[ $orderby ]
			: $a[ $orderby ] <=> $b[ $orderby ]
		);

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
	 * @return WP_REST_Response Available post types and taxonomies.
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

		// Authors with views.
		$authors = [];
		$author_rows = $wpdb->get_results(
			"SELECT u.ID, u.display_name
			 FROM $wpdb->users u
			 INNER JOIN $wpdb->usermeta um ON u.ID = um.user_id
			 WHERE um.meta_key = 'mai_views' AND CAST(um.meta_value AS UNSIGNED) > 0
			 ORDER BY u.display_name ASC"
		);

		foreach ( $author_rows as $author ) {
			$authors[] = [
				'id'   => (int) $author->ID,
				'name' => $author->display_name,
			];
		}

		return new WP_REST_Response( [
			'post_types' => $post_types,
			'taxonomies' => $taxonomies,
			'authors'    => $authors,
		] );
	}

	/**
	 * Searches authors or terms by name for the autocomplete filters.
	 *
	 * @param WP_REST_Request $request The incoming request with type, taxonomy, and search params.
	 *
	 * @return WP_REST_Response Up to 20 matching results.
	 */
	public function search( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$type     = $request->get_param( 'type' );
		$search   = $request->get_param( 'search' );
		$taxonomy = $request->get_param( 'taxonomy' );
		$has_search = strlen( $search ) >= 2;

		if ( 'author' === $type ) {
			$where = "um.meta_key = 'mai_views' AND CAST(um.meta_value AS UNSIGNED) > 0";

			if ( $has_search ) {
				$where .= $wpdb->prepare( ' AND u.display_name LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
			}

			$results = $wpdb->get_results(
				"SELECT u.ID as id, u.display_name as name
				 FROM $wpdb->users u
				 INNER JOIN $wpdb->usermeta um ON u.ID = um.user_id
				 WHERE {$where}
				 ORDER BY u.display_name ASC
				 LIMIT 50"
			);
		} else {
			if ( ! $taxonomy ) {
				return new WP_REST_Response( [] );
			}

			$public_taxonomies = get_taxonomies( [ 'public' => true ] );

			if ( ! in_array( $taxonomy, $public_taxonomies, true ) ) {
				return new WP_REST_Response( [] );
			}

			$where = $wpdb->prepare( 'tt.taxonomy = %s AND tt.count > 0', $taxonomy );

			if ( $has_search ) {
				$where .= $wpdb->prepare( ' AND t.name LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
			}

			$results = $wpdb->get_results(
				"SELECT t.term_id as id, t.name
				 FROM $wpdb->terms t
				 INNER JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id
				 WHERE {$where}
				 ORDER BY t.name ASC
				 LIMIT 50"
			);
		}

		$items = array_map( fn( $r ) => [
			'id'   => (int) $r->id,
			'name' => $r->name,
		], $results );

		return new WP_REST_Response( $items );
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

		$provider = ProviderSync::get_provider();

		// Check provider health before attempting sync.
		if ( ! $provider || ! $provider->is_available() ) {
			$reason = ( $provider && method_exists( $provider, 'get_unavailable_reason' ) )
				? $provider->get_unavailable_reason()
				: __( 'No provider configured.', 'mai-views' );

			return new WP_REST_Response( [ 'message' => $reason ], 500 );
		}

		// Quick health check: try a small provider query to verify auth works.
		$test = $provider->get_views( [ '/' ], gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) );
		$error = get_transient( 'mai_views_provider_error' );

		if ( $error ) {
			return new WP_REST_Response( [ 'message' => $error ], 500 );
		}

		$last_sync  = get_option( 'mai_views_provider_last_sync', 0 );
		$since      = $last_sync ? gmdate( 'Y-m-d H:i:s', $last_sync ) : '1970-01-01 00:00:00';
		$queue_size = count( Database::get_distinct_objects_since( $since ) );

		if ( 0 === $queue_size ) {
			return new WP_REST_Response( [ 'message' => __( 'Provider connected. No pages in the queue — try "Warm Stats" to fetch all stats.', 'mai-views' ) ] );
		}

		delete_transient( 'mai_views_provider_error' );

		ProviderSync::sync();

		$error = get_transient( 'mai_views_provider_error' );

		if ( $error ) {
			return new WP_REST_Response( [ 'message' => $error ], 500 );
		}

		return new WP_REST_Response( [ 'message' => sprintf( __( 'Sync complete. Processed %d objects.', 'mai-views' ), $queue_size ) ] );
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

		delete_transient( 'mai_views_provider_error' );

		$total_updated = 0;

		foreach ( ProviderSync::warm() as $progress ) {
			$total_updated += $progress['updated'] ?? 0;
		}

		$error = get_transient( 'mai_views_provider_error' );

		if ( $error ) {
			return new WP_REST_Response( [ 'message' => $error ], 500 );
		}

		return new WP_REST_Response( [
			'message' => sprintf( 'Warm complete. Updated %d objects.', $total_updated ),
			'updated' => $total_updated,
		] );
	}

	/**
	 * Gets web/app source breakdown for a set of object IDs from meta.
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

		$meta_table = match ( $object_type ) {
			'post' => $wpdb->postmeta,
			'term' => $wpdb->termmeta,
			'user' => $wpdb->usermeta,
			default => '',
		};

		if ( ! $meta_table ) {
			return [];
		}

		$id_column    = 'user' === $object_type ? 'user_id' : ( 'term' === $object_type ? 'term_id' : 'post_id' );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT $id_column as object_id, meta_key, meta_value
				 FROM $meta_table
				 WHERE $id_column IN ($placeholders)
				   AND meta_key IN ('mai_views_web', 'mai_views_app')",
				$ids
			)
		);

		$map = [];

		foreach ( $rows as $row ) {
			$source = 'mai_views_web' === $row->meta_key ? 'web' : 'app';
			$map[ (int) $row->object_id ][ $source ] = (int) $row->meta_value;
		}

		return $map;
	}

	/**
	 * Runs health checks and returns structured results.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 *
	 * @return WP_REST_Response Health check results.
	 */
	public function run_health_check( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( Health::run() );
	}
}

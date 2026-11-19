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
			'order' => [
				'default'           => 'desc',
				'validate_callback' => fn( $p ) => in_array( strtolower( $p ), [ 'asc', 'desc' ], true ),
				'sanitize_callback' => fn( $p ) => strtoupper( sanitize_key( $p ) ),
			],
		];

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
					'validate_callback' => [ IdList::class, 'within_limit' ],
					'sanitize_callback' => 'sanitize_text_field',
				],
				'author' => [
					'default'           => '',
					'validate_callback' => [ IdList::class, 'within_limit' ],
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
				'search' => [
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
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
				'page' => [
					'type'              => 'integer',
					'default'           => 1,
					'minimum'           => 1,
					'validate_callback' => 'rest_validate_request_arg',
				],
				'per_page' => [
					'type'              => 'integer',
					'default'           => 50,
					'minimum'           => 1,
					'maximum'           => 100,
					'validate_callback' => 'rest_validate_request_arg',
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
			'args'                => [
				'cursor' => [
					'type'              => 'integer',
					'default'           => 0,
					'minimum'           => 0,
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => 'absint',
				],
				'total_updated' => [
					'type'              => 'integer',
					'default'           => 0,
					'minimum'           => 0,
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => 'absint',
				],
				'total_iterated' => [
					'type'              => 'integer',
					'default'           => 0,
					'minimum'           => 0,
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => 'absint',
				],
				'force' => [
					'type'              => 'boolean',
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
				],
			],
		] );

		register_rest_route( self::NAMESPACE, '/admin/health', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'run_health_check' ],
			'permission_callback' => $admin_permission,
		] );

		register_rest_route( self::NAMESPACE, '/admin/dismiss-error', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'dismiss_provider_error' ],
			'permission_callback' => $admin_permission,
		] );
	}

	/**
	 * Clears the stored provider error so the dashboard notice disappears
	 * and the circuit breaker re-opens. The error will repopulate on the
	 * next provider failure if one occurs.
	 *
	 * @return WP_REST_Response
	 */
	public function dismiss_provider_error(): WP_REST_Response {
		Sync::clear_provider_error();
		return new WP_REST_Response( [ 'dismissed' => true ] );
	}

	/**
	 * Builds the card totals for a dashboard table from a meta-key sum query.
	 *
	 * The query sums `mai_views` and `mai_trending` meta rows (aliased `m`)
	 * across every row the table's filters match, not just the current page.
	 * Zero rows add nothing, so they're skipped before the join. Most objects
	 * sit at zero, and on a 146k-post site that cut the query from about
	 * 1.3s to 0.3s.
	 *
	 * @since 1.3.6
	 *
	 * @param string $meta_table The meta table to read, e.g. `$wpdb->postmeta`.
	 * @param string $from_sql   The FROM/JOIN clause that joins the meta rows (as `m`) to the object table.
	 * @param string $where_sql  The table's filter conditions, already prepared.
	 *
	 * @return array{views: int, trending_views: int, trending_count: int}
	 */
	private function get_meta_totals( string $meta_table, string $from_sql, string $where_sql ): array {
		global $wpdb;

		$row = $wpdb->get_row(
			"SELECT COALESCE(SUM(CASE WHEN m.meta_key = 'mai_views' THEN CAST(m.meta_value AS UNSIGNED) ELSE 0 END), 0) AS views,
			        COALESCE(SUM(CASE WHEN m.meta_key = 'mai_trending' THEN CAST(m.meta_value AS UNSIGNED) ELSE 0 END), 0) AS trending_views,
			        COUNT(CASE WHEN m.meta_key = 'mai_trending' AND CAST(m.meta_value AS UNSIGNED) > 0 THEN 1 END) AS trending_count
			 FROM {$meta_table} m
			 {$from_sql}
			 WHERE m.meta_key IN ('mai_views', 'mai_trending') AND m.meta_value NOT IN ('', '0') AND {$where_sql}"
		);

		return [
			'views'          => (int) ( $row->views ?? 0 ),
			'trending_views' => (int) ( $row->trending_views ?? 0 ),
			'trending_count' => (int) ( $row->trending_count ?? 0 ),
		];
	}

	/**
	 * Counts the queries that have failed so far in this request.
	 *
	 * wpdb adds every failed query to this global, even with errors suppressed
	 * (wp-includes/class-wpdb.php, print_error()), so a later count above an
	 * earlier one means a query between them failed. last_error only holds the
	 * most recent query's error, and a dashboard route runs several.
	 *
	 * @since 1.3.8
	 *
	 * @return int
	 */
	private function failed_query_count(): int {
		global $EZSQL_ERROR;

		return is_array( $EZSQL_ERROR ) ? count( $EZSQL_ERROR ) : 0;
	}

	/**
	 * Builds the error for dashboard data that could not be read.
	 *
	 * @since 1.3.8
	 *
	 * @return WP_REST_Response
	 */
	private function read_error(): WP_REST_Response {
		return Errors::response( ErrorCode::DatabaseError, __( 'The dashboard data could not be read. Try again.', 'mai-analytics' ) );
	}

	/**
	 * Returns paginated top posts ranked by views or trending.
	 *
	 * @since 1.3.8 Answers 503 mai_analytics_database_error when a query fails.
	 *
	 * @param WP_REST_Request $request The incoming request with filter and pagination params.
	 *
	 * @return WP_REST_Response Paginated post data with source breakdown, or a 503.
	 */
	public function get_top_posts( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$failed = $this->failed_query_count();

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
		$other_key      = 'trending' === $orderby ? 'mai_views' : 'mai_trending';

		$public_types = get_post_types( [ 'public' => true ] );
		$type_list    = implode( "','", array_map( 'esc_sql', $public_types ) );

		// Build query. The taxonomy filter is an EXISTS subquery rather than a
		// join so a post in several matching terms is still one row, which
		// keeps the card totals from counting it once per term.
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

		if ( $taxonomy ) {
			$term_sql = '';

			if ( $term_ids ) {
				$term_placeholders = implode( ', ', array_fill( 0, count( $term_ids ), '%d' ) );
				$term_sql          = $wpdb->prepare( " AND tt.term_id IN ($term_placeholders)", $term_ids );
			}

			$wheres[] = $wpdb->prepare(
				"EXISTS (
					SELECT 1 FROM $wpdb->term_relationships tr
					INNER JOIN $wpdb->term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
					WHERE tr.object_id = p.ID AND tt.taxonomy = %s",
				$taxonomy
			) . $term_sql . ')';
		}

		// Compare against GMT. `post_date` is in the site's time zone while
		// the database clock can be in any zone, so mixing them shifted the
		// window by the difference.
		if ( $published_days > 0 ) {
			$wheres[] = $wpdb->prepare( 'p.post_date_gmt > %s', gmdate( 'Y-m-d H:i:s', time() - ( $published_days * DAY_IN_SECONDS ) ) );
		}

		$where_sql = implode( ' AND ', $wheres );

		// Count total.
		$count_sql = "SELECT COUNT(*)
		              FROM $wpdb->posts p
		              INNER JOIN $wpdb->postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '{$meta_key}'
		              WHERE {$where_sql} AND CAST(pm.meta_value AS UNSIGNED) > 0";

		$total  = (int) $wpdb->get_var( $count_sql );
		$pages  = (int) ceil( $total / $per_page );
		$totals = $this->get_meta_totals( $wpdb->postmeta, "INNER JOIN $wpdb->posts p ON p.ID = m.post_id", $where_sql );

		// Fetch page.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_type, p.post_author,
				        CAST(pm.meta_value AS UNSIGNED) as primary_count,
				        COALESCE(CAST(pm2.meta_value AS UNSIGNED), 0) as secondary_count
				 FROM $wpdb->posts p
				 INNER JOIN $wpdb->postmeta pm ON p.ID = pm.post_id AND pm.meta_key = %s
				 LEFT JOIN $wpdb->postmeta pm2 ON p.ID = pm2.post_id AND pm2.meta_key = %s
				 WHERE {$where_sql} AND CAST(pm.meta_value AS UNSIGNED) > 0
				 ORDER BY primary_count {$order}
				 LIMIT %d OFFSET %d",
				$meta_key,
				$other_key,
				$per_page,
				$offset
			)
		);

		if ( $this->failed_query_count() > $failed ) {
			return $this->read_error();
		}

		// Source breakdown from buffer for this page's IDs.
		$source_map = $this->get_source_breakdown(
			array_map( fn( $r ) => (int) $r->ID, $results ),
			'post'
		);

		if ( $this->failed_query_count() > $failed ) {
			return $this->read_error();
		}

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
			'items'  => $items,
			'total'  => $total,
			'pages'  => $pages,
			'totals' => $totals,
		] );
	}

	/**
	 * Returns paginated top terms ranked by views or trending.
	 *
	 * @since 1.3.8 Answers 503 mai_analytics_database_error when a query fails.
	 *
	 * @param WP_REST_Request $request The incoming request with filter and pagination params.
	 *
	 * @return WP_REST_Response Paginated term data with source breakdown, or a 503.
	 */
	public function get_top_terms( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$failed = $this->failed_query_count();

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

		$pages  = (int) ceil( $total / $per_page );
		$totals = $this->get_meta_totals(
			$wpdb->termmeta,
			"INNER JOIN $wpdb->terms t ON t.term_id = m.term_id INNER JOIN $wpdb->term_taxonomy txn ON t.term_id = txn.term_id",
			$where_sql
		);

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

		if ( $this->failed_query_count() > $failed ) {
			return $this->read_error();
		}

		$source_map = $this->get_source_breakdown(
			array_map( fn( $r ) => (int) $r->term_id, $results ),
			'term'
		);

		if ( $this->failed_query_count() > $failed ) {
			return $this->read_error();
		}

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
			'items'  => $items,
			'total'  => $total,
			'pages'  => $pages,
			'totals' => $totals,
		] );
	}

	/**
	 * Returns paginated top authors ranked by views or trending.
	 *
	 * @since 1.3.8 Answers 503 mai_analytics_database_error when a query fails.
	 *
	 * @param WP_REST_Request $request The incoming request with pagination params.
	 *
	 * @return WP_REST_Response Paginated author data with source breakdown, or a 503.
	 */
	public function get_top_authors( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$failed = $this->failed_query_count();

		$orderby   = $request->get_param( 'orderby' );
		$order     = $request->get_param( 'order' );
		$search    = $request->get_param( 'search' );
		$page      = (int) $request->get_param( 'page' );
		$per_page  = (int) $request->get_param( 'per_page' );
		$offset    = ( $page - 1 ) * $per_page;
		$meta_key  = 'trending' === $orderby ? 'mai_trending' : 'mai_views';
		$other_key = 'trending' === $orderby ? 'mai_views' : 'mai_trending';

		$search_sql = $search
			? $wpdb->prepare( 'u.display_name LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' )
			: '1=1';
		$where_sql  = 'CAST(um.meta_value AS UNSIGNED) > 0 AND ' . $search_sql;

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT u.ID)
			 FROM $wpdb->users u
			 INNER JOIN $wpdb->usermeta um ON u.ID = um.user_id AND um.meta_key = '{$meta_key}'
			 WHERE {$where_sql}"
		);

		$pages  = (int) ceil( $total / $per_page );
		$totals = $this->get_meta_totals( $wpdb->usermeta, "INNER JOIN $wpdb->users u ON u.ID = m.user_id", $search_sql );

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

		if ( $this->failed_query_count() > $failed ) {
			return $this->read_error();
		}

		$source_map = $this->get_source_breakdown(
			array_map( fn( $r ) => (int) $r->ID, $results ),
			'user'
		);

		if ( $this->failed_query_count() > $failed ) {
			return $this->read_error();
		}

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
			'items'  => $items,
			'total'  => $total,
			'pages'  => $pages,
			'totals' => $totals,
		] );
	}

	/**
	 * Returns post type archive view counts (no pagination — typically few rows).
	 *
	 * @param WP_REST_Request $request The incoming request with orderby, order and search params.
	 *
	 * @return WP_REST_Response Archive data with source breakdown.
	 */
	public function get_top_archives( WP_REST_Request $request ): WP_REST_Response {
		$orderby   = $request->get_param( 'orderby' );
		$order     = $request->get_param( 'order' );
		$search    = (string) $request->get_param( 'search' );
		$views     = (array) get_option( 'mai_analytics_post_type_views', [] );
		$trending  = (array) get_option( 'mai_analytics_post_type_trending', [] );
		$views_web = (array) get_option( 'mai_analytics_post_type_views_web', [] );
		$views_app = (array) get_option( 'mai_analytics_post_type_views_app', [] );

		// Build items from all known post types with views.
		$all_keys = array_unique( array_merge( array_keys( $views ), array_keys( $trending ) ) );
		$items    = [];
		$totals   = [ 'views' => 0, 'trending_views' => 0, 'trending_count' => 0 ];

		foreach ( $all_keys as $key ) {
			$pt_obj = is_string( $key ) ? get_post_type_object( $key ) : null;

			if ( ! $pt_obj ) {
				continue;
			}

			if ( '' !== $search && false === stripos( $pt_obj->labels->name, $search ) && false === stripos( $key, $search ) ) {
				continue;
			}

			$totals['views']          += (int) ( $views[ $key ] ?? 0 );
			$totals['trending_views'] += (int) ( $trending[ $key ] ?? 0 );
			$totals['trending_count'] += (int) ( $trending[ $key ] ?? 0 ) > 0 ? 1 : 0;

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
			'items'  => $items,
			'total'  => count( $items ),
			'pages'  => 1,
			'totals' => $totals,
		] );
	}

	/**
	 * Returns filter options for the dashboard dropdowns.
	 *
	 * Each list is gated on "has views > 0" so we don't surface filters that
	 * would yield empty tables. Wrapped in a 5-minute transient because filter
	 * options change much more slowly than the views numbers themselves and
	 * this endpoint fires on every dashboard page load.
	 *
	 * @since 1.3.8 Answers 503 mai_analytics_database_error when a query fails,
	 *              and caches nothing then.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 *
	 * @return WP_REST_Response Available post types and taxonomies that have data, or a 503.
	 */
	public function get_filters( WP_REST_Request $request ): WP_REST_Response {
		$cached = get_transient( 'mai_analytics_admin_filters' );

		if ( is_array( $cached ) ) {
			return new WP_REST_Response( $cached );
		}

		global $wpdb;

		$failed = $this->failed_query_count();

		$public_types     = get_post_types( [ 'public' => true ] );
		$public_taxonomies = get_taxonomies( [ 'public' => true ] );
		$type_list        = implode( "','", array_map( 'esc_sql', $public_types ) );
		$tax_list         = implode( "','", array_map( 'esc_sql', $public_taxonomies ) );

		// Post types that have published posts with views > 0.
		$post_types     = [];
		$post_type_rows = $wpdb->get_col(
			"SELECT DISTINCT p.post_type
			 FROM $wpdb->posts p
			 INNER JOIN $wpdb->postmeta pm ON p.ID = pm.post_id
			 WHERE pm.meta_key = 'mai_views'
			   AND CAST(pm.meta_value AS UNSIGNED) > 0
			   AND p.post_status = 'publish'
			   AND p.post_type IN ('{$type_list}')"
		);

		foreach ( $post_type_rows as $slug ) {
			$pt = get_post_type_object( $slug );

			if ( $pt ) {
				$post_types[] = [
					'slug'  => $pt->name,
					'label' => $pt->labels->name,
				];
			}
		}

		// Taxonomies that have terms with views > 0. Shared by Posts and Terms
		// tabs; "any term in this taxonomy has views" is a fair proxy for both.
		$taxonomies     = [];
		$taxonomy_rows  = $wpdb->get_col(
			"SELECT DISTINCT tt.taxonomy
			 FROM $wpdb->termmeta tm
			 INNER JOIN $wpdb->term_taxonomy tt ON tm.term_id = tt.term_id
			 WHERE tm.meta_key = 'mai_views'
			   AND CAST(tm.meta_value AS UNSIGNED) > 0
			   AND tt.taxonomy IN ('{$tax_list}')"
		);

		// A failed read is never cached, so the next page load asks again.
		if ( $this->failed_query_count() > $failed ) {
			return $this->read_error();
		}

		foreach ( $taxonomy_rows as $slug ) {
			$tax = get_taxonomy( $slug );

			if ( $tax ) {
				$taxonomies[] = [
					'slug'  => $tax->name,
					'label' => $tax->labels->name,
				];
			}
		}

		$payload = [
			'post_types' => $post_types,
			'taxonomies' => $taxonomies,
		];

		set_transient( 'mai_analytics_admin_filters', $payload, 5 * MINUTE_IN_SECONDS );

		return new WP_REST_Response( $payload );
	}

	/**
	 * Searches authors or terms by name for the autocomplete filters.
	 *
	 * @since 1.3.8 Takes page and per_page, sends the total headers, and answers
	 *              503 when a query fails.
	 *
	 * @param WP_REST_Request $request The incoming request with type, taxonomy, search, page and per_page params.
	 *
	 * @return WP_REST_Response Up to `per_page` matching results (50 by default), with
	 *                          X-WP-Total and X-WP-TotalPages headers, or 503
	 *                          mai_analytics_database_error when a query fails.
	 */
	public function search( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$type       = $request->get_param( 'type' );
		$search     = $request->get_param( 'search' );
		$taxonomy   = $request->get_param( 'taxonomy' );
		$has_search = strlen( $search ) >= 2;
		$per_page   = (int) $request->get_param( 'per_page' );
		$offset     = ( (int) $request->get_param( 'page' ) - 1 ) * $per_page;

		if ( 'author' === $type ) {
			// Match get_filters() — surface authors of viewed posts, not authors
			// whose own /author/ archive was tracked (often empty in practice).
			$public_types = get_post_types( [ 'public' => true ] );
			$type_list    = implode( "','", array_map( 'esc_sql', $public_types ) );
			$where        = "pm.meta_key = 'mai_views'
			                 AND CAST(pm.meta_value AS UNSIGNED) > 0
			                 AND p.post_status = 'publish'
			                 AND p.post_type IN ('{$type_list}')";

			if ( $has_search ) {
				$where .= $wpdb->prepare( ' AND u.display_name LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
			}

			$from = "FROM $wpdb->users u
				 INNER JOIN $wpdb->posts p ON u.ID = p.post_author
				 INNER JOIN $wpdb->postmeta pm ON p.ID = pm.post_id
				 WHERE {$where}";

			$count_sql = "SELECT COUNT(DISTINCT u.ID) {$from}";
			$results   = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT u.ID as id, u.display_name as name
					 {$from}
					 ORDER BY u.display_name ASC
					 LIMIT %d OFFSET %d",
					$per_page,
					$offset
				)
			);
		} else {
			if ( ! $taxonomy ) {
				return $this->search_response( [], 0, $per_page );
			}

			$public_taxonomies = get_taxonomies( [ 'public' => true ] );

			if ( ! in_array( $taxonomy, $public_taxonomies, true ) ) {
				return $this->search_response( [], 0, $per_page );
			}

			$where = $wpdb->prepare( 'tt.taxonomy = %s AND tt.count > 0', $taxonomy );

			if ( $has_search ) {
				$where .= $wpdb->prepare( ' AND t.name LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
			}

			$from = "FROM $wpdb->terms t
				 INNER JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id
				 WHERE {$where}";

			$count_sql = "SELECT COUNT(DISTINCT t.term_id) {$from}";
			$results   = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT t.term_id as id, t.name
					 {$from}
					 ORDER BY t.name ASC
					 LIMIT %d OFFSET %d",
					$per_page,
					$offset
				)
			);
		}

		if ( $wpdb->last_error ) {
			return $this->search_error();
		}

		$items = array_map( fn( $r ) => [
			'id'   => (int) $r->id,
			'name' => $r->name,
		], is_array( $results ) ? $results : [] );

		// A short first page already holds every match, so skip the second
		// pass over the join. The dashboard asks on every keystroke.
		if ( 0 === $offset && count( $items ) < $per_page ) {
			$total = count( $items );
		} else {
			$total = (int) $wpdb->get_var( $count_sql );

			if ( $wpdb->last_error ) {
				return $this->search_error();
			}
		}

		return $this->search_response( $items, $total, $per_page );
	}

	/**
	 * Builds the error for a search whose query failed.
	 *
	 * @since 1.3.8
	 *
	 * @return WP_REST_Response
	 */
	private function search_error(): WP_REST_Response {
		return Errors::response( ErrorCode::DatabaseError, __( 'The search could not be run. Try again.', 'mai-analytics' ) );
	}

	/**
	 * Builds a search response: the bare array body plus the total headers.
	 *
	 * @since 1.3.8
	 *
	 * @param array $items    The rows for this page.
	 * @param int   $total    The number of matching rows across every page.
	 * @param int   $per_page The page size.
	 *
	 * @return WP_REST_Response
	 */
	private function search_response( array $items, int $total, int $per_page ): WP_REST_Response {
		$response = new WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / max( 1, $per_page ) ) );

		return $response;
	}

	/**
	 * Runs a sync now: the self-hosted sync, or a provider sync after a quick
	 * connection check.
	 *
	 * @since 1.3.8 A sync that did not run answers 429 mai_analytics_busy or
	 *              503 mai_analytics_database_error instead of "complete".
	 *
	 * @param WP_REST_Request $request The incoming request.
	 *
	 * @return WP_REST_Response Status message, or 429, 501, 502 or 503 when the
	 *                          sync did not run or the provider failed.
	 */
	public function sync_now( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		if ( 'self_hosted' === Settings::get( 'data_source' ) ) {
			$not_run = Sync::sync();

			if ( $not_run ) {
				return $this->sync_error( $not_run );
			}

			return new WP_REST_Response( [ 'message' => 'Self-hosted sync complete.' ] );
		}

		$provider = ProviderSync::get_provider();

		// Check provider health before attempting sync.
		if ( ! $provider || ! $provider->is_available() ) {
			$reason = ( $provider && method_exists( $provider, 'get_unavailable_reason' ) )
				? $provider->get_unavailable_reason()
				: __( 'No provider configured.', 'mai-analytics' );

			return Errors::response( ErrorCode::ProviderUnavailable, (string) $reason );
		}

		// Quick health check: try a small provider query to verify auth works.
		// Result is intentionally discarded — we only need to populate (or not)
		// the mai_analytics_provider_error option that the next read consults.
		$test  = $provider->get_views( [ '/' ], [ 'check' => [ gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) ] ] );
		$error = Sync::get_last_error()['message'];

		if ( $error ) {
			return Errors::response( ErrorCode::ProviderRequestFailed, (string) $error );
		}

		$last_sync  = get_option( 'mai_analytics_provider_last_sync', 0 );
		$since      = $last_sync ? gmdate( 'Y-m-d H:i:s', $last_sync ) : '1970-01-01 00:00:00';
		$queue_size = count( Database::get_distinct_objects_since( $since ) );

		if ( $wpdb->last_error ) {
			return $this->sync_error( ErrorCode::DatabaseError );
		}

		if ( 0 === $queue_size ) {
			return new WP_REST_Response( [ 'message' => __( 'Provider connected. No pages in the queue. Try "Warm Stats" to fetch all stats.', 'mai-analytics' ) ] );
		}

		Sync::clear_provider_error();

		$not_run = ProviderSync::sync();

		if ( in_array( $not_run, [ ErrorCode::Busy, ErrorCode::DatabaseError, ErrorCode::ProviderUnavailable ], true ) ) {
			return $this->sync_error( $not_run );
		}

		$error = Sync::get_last_error()['message'];

		if ( $error ) {
			return Errors::response( ErrorCode::ProviderRequestFailed, (string) $error );
		}

		return new WP_REST_Response( [ 'message' => sprintf( __( 'Sync complete. Processed %d objects.', 'mai-analytics' ), $queue_size ) ] );
	}

	/**
	 * Builds the error for a sync that did not run.
	 *
	 * @since 1.3.8
	 *
	 * @param ErrorCode $code Why it did not run, as Sync::sync() or ProviderSync::sync() returned it.
	 *
	 * @return WP_REST_Response
	 */
	private function sync_error( ErrorCode $code ): WP_REST_Response {
		$message = match ( $code ) {
			ErrorCode::Busy                => __( 'A sync is already running. Try again in a few minutes.', 'mai-analytics' ),
			ErrorCode::DatabaseError       => __( 'The sync could not read the saved views, so nothing was changed. Try again.', 'mai-analytics' ),
			ErrorCode::ProviderUnavailable => __( 'The provider is not available.', 'mai-analytics' ),
			default                        => __( 'The sync did not run. Try again.', 'mai-analytics' ),
		};

		return Errors::response( $code, $message );
	}

	/**
	 * Processes ONE batch of a warm operation, returning progress for the client.
	 *
	 * Why cursor-based progressive REST instead of one synchronous request:
	 *   The previous implementation iterated `ProviderSync::warm()` to completion
	 *   inside a single REST request, which on large sites (~18K posts) exceeded
	 *   Cloudflare's 100-second gateway timeout (524). This endpoint processes
	 *   one batch per request via `ProviderSync::warm_batch()`, so the client
	 *   drives the loop. No new infrastructure required (no Action Scheduler,
	 *   no WP-cron, no background processes).
	 *
	 * Partial-progress survival: per-batch meta writes commit independently
	 * inside `ProviderSync::warm_batch()`, so if a request fails mid-loop the
	 * already-warmed posts retain their values and the client can resume from
	 * `next_cursor`.
	 *
	 * Wire shape:
	 *   Request : POST /mai-analytics/v1/admin/warm
	 *             { cursor?: int (default 0),
	 *               total_updated?: int (default 0),
	 *               total_iterated?: int (default 0) }
	 *   Response: { batch, total, updated, iterated, total_updated,
	 *               total_iterated, done, next_cursor, message? }
	 *   Error   : 501 mai_analytics_provider_required in self-hosted mode, or
	 *             502 mai_analytics_provider_request_failed with the progress
	 *             fields above, each with code, message and data.status.
	 *
	 * `iterated` and `updated` diverge when a batch's provider call fails:
	 * iterated still counts the objects walked over, updated only counts
	 * those for which the provider returned data. Surfacing both lets the
	 * client report honest progress instead of treating preserved-meta
	 * batches as successes.
	 *
	 * @since 1.3.8 Errors carry code, message and data.status.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 *
	 * @return WP_REST_Response Progress payload for the client to act on, or a 501 or 502.
	 */
	public function warm( WP_REST_Request $request ): WP_REST_Response {
		if ( 'self_hosted' === Settings::get( 'data_source' ) ) {
			return Errors::response( ErrorCode::ProviderRequired, 'Warm is only available in provider mode.' );
		}

		$cursor         = (int) $request->get_param( 'cursor' );
		$total_updated  = (int) $request->get_param( 'total_updated' );
		$total_iterated = (int) $request->get_param( 'total_iterated' );
		$force          = (bool) $request->get_param( 'force' );

		// Clear stale error only on the first batch — once mid-run, a fresh
		// error from a per-batch failure is meaningful and should be kept.
		if ( 0 === $cursor ) {
			Sync::clear_provider_error();
		}

		$progress = ProviderSync::warm_batch( $cursor, [ 'force' => $force ] );

		if ( null === $progress ) {
			$error = Sync::get_last_error()['message'];

			$payload = [
				'done'           => true,
				'total_updated'  => $total_updated,
				'total_iterated' => $total_iterated,
			];

			if ( $error ) {
				return Errors::response( ErrorCode::ProviderRequestFailed, (string) $error, [], $payload );
			}

			$payload['message'] = sprintf( 'Warm complete. Updated %d objects.', $total_updated );

			return new WP_REST_Response( $payload );
		}

		$batch_updated   = (int) ( $progress['updated'] ?? 0 );
		$batch_iterated  = (int) ( $progress['iterated'] ?? $batch_updated );
		$total_updated  += $batch_updated;
		$total_iterated += $batch_iterated;
		$total_batches   = (int) ( $progress['total'] ?? 0 );
		$done            = $cursor + 1 >= $total_batches;

		$payload = [
			'batch'          => $cursor + 1,
			'total'          => $total_batches,
			'updated'        => $batch_updated,
			'iterated'       => $batch_iterated,
			'total_updated'  => $total_updated,
			'total_iterated' => $total_iterated,
			'done'           => $done,
			'next_cursor'    => $cursor + 1,
		];

		if ( $done ) {
			$payload['message'] = sprintf( 'Warm complete. Updated %d objects.', $total_updated );
		}

		$error = Sync::get_last_error()['message'];

		if ( $error ) {
			unset( $payload['message'] );

			return Errors::response( ErrorCode::ProviderRequestFailed, (string) $error, [], $payload );
		}

		return new WP_REST_Response( $payload );
	}

	/**
	 * Gets web/app source breakdown for a set of object IDs from meta.
	 * Only queries the current page's IDs (at most 100) to avoid expensive joins.
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

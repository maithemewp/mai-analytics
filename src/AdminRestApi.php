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
					'sanitize_callback' => 'absint',
				],
				'total_updated' => [
					'type'              => 'integer',
					'default'           => 0,
					'minimum'           => 0,
					'sanitize_callback' => 'absint',
				],
				'total_iterated' => [
					'type'              => 'integer',
					'default'           => 0,
					'minimum'           => 0,
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
			'items'  => $items,
			'total'  => $total,
			'pages'  => $pages,
			'totals' => $totals,
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
			'items'  => $items,
			'total'  => $total,
			'pages'  => $pages,
			'totals' => $totals,
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
	 * @param WP_REST_Request $request The incoming request.
	 *
	 * @return WP_REST_Response Available post types and taxonomies that have data.
	 */
	public function get_filters( WP_REST_Request $request ): WP_REST_Response {
		$cached = get_transient( 'mai_analytics_admin_filters' );

		if ( is_array( $cached ) ) {
			return new WP_REST_Response( $cached );
		}

		global $wpdb;

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

			$results = $wpdb->get_results(
				"SELECT DISTINCT u.ID as id, u.display_name as name
				 FROM $wpdb->users u
				 INNER JOIN $wpdb->posts p ON u.ID = p.post_author
				 INNER JOIN $wpdb->postmeta pm ON p.ID = pm.post_id
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
				: __( 'No provider configured.', 'mai-analytics' );

			return new WP_REST_Response( [ 'message' => $reason ], 500 );
		}

		// Quick health check: try a small provider query to verify auth works.
		// Result is intentionally discarded — we only need to populate (or not)
		// the mai_analytics_provider_error option that the next read consults.
		$test  = $provider->get_views( [ '/' ], [ 'check' => [ gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) ] ] );
		$error = Sync::get_last_error()['message'];

		if ( $error ) {
			return new WP_REST_Response( [ 'message' => $error ], 500 );
		}

		$last_sync  = get_option( 'mai_analytics_provider_last_sync', 0 );
		$since      = $last_sync ? gmdate( 'Y-m-d H:i:s', $last_sync ) : '1970-01-01 00:00:00';
		$queue_size = count( Database::get_distinct_objects_since( $since ) );

		if ( 0 === $queue_size ) {
			return new WP_REST_Response( [ 'message' => __( 'Provider connected. No pages in the queue. Try "Warm Stats" to fetch all stats.', 'mai-analytics' ) ] );
		}

		Sync::clear_provider_error();

		ProviderSync::sync();

		$error = Sync::get_last_error()['message'];

		if ( $error ) {
			return new WP_REST_Response( [ 'message' => $error ], 500 );
		}

		return new WP_REST_Response( [ 'message' => sprintf( __( 'Sync complete. Processed %d objects.', 'mai-analytics' ), $queue_size ) ] );
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
	 *
	 * `iterated` and `updated` diverge when a batch's provider call fails:
	 * iterated still counts the objects walked over, updated only counts
	 * those for which the provider returned data. Surfacing both lets the
	 * client report honest progress instead of treating preserved-meta
	 * batches as successes.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 *
	 * @return WP_REST_Response Progress payload for the client to act on.
	 */
	public function warm( WP_REST_Request $request ): WP_REST_Response {
		if ( 'self_hosted' === Settings::get( 'data_source' ) ) {
			return new WP_REST_Response( [ 'message' => 'Warm is only available in provider mode.' ], 400 );
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
				'message'        => $error
					? $error
					: sprintf( 'Warm complete. Updated %d objects.', $total_updated ),
			];

			return new WP_REST_Response( $payload, $error ? 500 : 200 );
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
			$payload['message'] = $error;
			return new WP_REST_Response( $payload, 500 );
		}

		return new WP_REST_Response( $payload );
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

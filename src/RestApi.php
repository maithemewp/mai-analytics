<?php

namespace Mai\Analytics;

use WP_REST_Request;
use WP_REST_Response;

class RestApi {

	private const NAMESPACE = 'mai-analytics/v1';

	/**
	 * Hooks into rest_api_init to register analytics REST routes.
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Registers all REST routes for recording and retrieving views.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$id_args = [
			'id' => [
				'required'          => true,
				'validate_callback' => fn( $param ) => is_numeric( $param ) && (int) $param > 0,
				'sanitize_callback' => 'absint',
			],
		];

		$types = [ 'post', 'term', 'user' ];

		// POST — Record views.
		foreach ( $types as $type ) {
			register_rest_route( self::NAMESPACE, "/view/{$type}/(?P<id>\\d+)", [
				'methods'             => 'POST',
				'callback'            => [ $this, 'record_view' ],
				'permission_callback' => '__return_true',
				'args'                => array_merge( $id_args, [
					'source' => [
						'default'           => 'web',
						'validate_callback' => fn( $param ) => in_array( $param, [ 'web', 'app' ], true ),
						'sanitize_callback' => 'sanitize_key',
					],
				] ),
			] );
		}

		// GET — Individual counts.
		foreach ( $types as $type ) {
			register_rest_route( self::NAMESPACE, "/views/{$type}/(?P<id>\\d+)", [
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_views' ],
				'permission_callback' => '__return_true',
				'args'                => $id_args,
			] );
		}

		// POST — Record post type archive views.
		register_rest_route( self::NAMESPACE, '/view/post_type/(?P<post_type>[a-z0-9_-]+)', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'record_post_type_view' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'post_type' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				],
				'source' => [
					'default'           => 'web',
					'validate_callback' => fn( $param ) => in_array( $param, [ 'web', 'app' ], true ),
					'sanitize_callback' => 'sanitize_key',
				],
			],
		] );

		// GET — Post type archive counts.
		register_rest_route( self::NAMESPACE, '/views/post_type/(?P<post_type>[a-z0-9_-]+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_post_type_views' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'post_type' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				],
			],
		] );

		// GET — Trending.
		register_rest_route( self::NAMESPACE, '/views/trending', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_trending' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'type' => [
					'default'           => 'post',
					'validate_callback' => fn( $param ) => in_array( $param, [ 'post', 'term', 'user', 'post_type' ], true ),
				],
				'period' => [
					'default'           => '6h',
					'validate_callback' => fn( $param ) => in_array( $param, [ '6h', '24h', '7d' ], true ),
				],
				'per_page' => [
					'default'           => 10,
					'validate_callback' => fn( $param ) => is_numeric( $param ) && (int) $param > 0 && (int) $param <= 100,
					'sanitize_callback' => 'absint',
				],
				'taxonomy' => [
					'default'           => '',
					'sanitize_callback' => 'sanitize_key',
				],
				'terms' => [
					'default'           => '',
					'validate_callback' => [ IdList::class, 'within_limit' ],
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );
	}

	/**
	 * Records a view for a post, term, or user.
	 *
	 * In external provider mode, web views are deduplicated in the buffer (one row per
	 * unique object per sync cycle). App views always insert every row for counting.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 *
	 * @return WP_REST_Response The response indicating success or failure.
	 */
	public function record_view( WP_REST_Request $request ): WP_REST_Response {
		$id   = (int) $request->get_param( 'id' );
		$type = $this->get_type_from_route( $request->get_route() );

		if ( ! $type ) {
			return $this->invalid_request();
		}

		// Validate the object exists and is publicly viewable.
		$viewable = $this->validate_object( $id, $type );

		if ( null === $viewable ) {
			return $this->database_error( __( 'The view could not be checked. Try again.', 'mai-analytics' ) );
		}

		if ( ! $viewable ) {
			return $this->not_found();
		}

		$source      = $request->get_param( 'source' ) ?: 'web';
		$data_source = Settings::get( 'data_source' );

		// Bot filtering — only in self-hosted mode. In provider mode, the buffer row
		// is just a signal that gets deleted; the provider has its own bot filtering.
		if ( 'self_hosted' === $data_source && Settings::get( 'exclude_bots' ) ) {
			if ( BotFilter::is_bot( $request->get_header( 'user_agent' ) ) ) {
				return $this->bot();
			}
		}

		$inserted = null;

		// External provider mode + web visit: dedup check before INSERT.
		if ( 'self_hosted' !== $data_source && 'web' === $source ) {
			$last_sync = (int) get_option( 'mai_analytics_provider_last_sync', 0 );

			if ( ! Database::is_queued( $id, $type, $last_sync ) ) {
				$inserted = Database::insert_view( $id, $type, $source );
			}
		} else {
			// Self-hosted (any source) OR external + app → buffer INSERT (every view counted).
			$inserted = Database::insert_view( $id, $type, $source );
		}

		if ( false === $inserted ) {
			return $this->database_error();
		}

		// Fallback sync: if cron is failing, trigger sync after response.
		( new Cron() )->maybe_fallback_sync();

		return new WP_REST_Response( [ 'success' => true ] );
	}

	/**
	 * Gets view counts for a single object.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 *
	 * @return WP_REST_Response The response containing view and trending counts.
	 */
	public function get_views( WP_REST_Request $request ): WP_REST_Response {
		$id   = (int) $request->get_param( 'id' );
		$type = $this->get_type_from_route( $request->get_route() );

		if ( ! $type ) {
			return $this->invalid_request();
		}

		// Same rule as the POST: a missing or hidden item answers 404.
		$viewable = $this->validate_object( $id, $type );

		if ( null === $viewable ) {
			return $this->read_error();
		}

		if ( ! $viewable ) {
			return $this->not_found();
		}

		[ $views, $trending ] = match ( $type ) {
			'post' => [ (int) get_post_meta( $id, 'mai_views', true ), (int) get_post_meta( $id, 'mai_trending', true ) ],
			'term' => [ (int) get_term_meta( $id, 'mai_views', true ), (int) get_term_meta( $id, 'mai_trending', true ) ],
			'user' => [ (int) get_user_meta( $id, 'mai_views', true ), (int) get_user_meta( $id, 'mai_trending', true ) ],
			default => [ 0, 0 ],
		};

		return new WP_REST_Response( [
			'id'       => $id,
			'type'     => $type,
			'views'    => $views,
			'trending' => $trending,
		] );
	}

	/**
	 * Records a view for a post type archive.
	 *
	 * In external provider mode, web views are deduplicated in the buffer.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 *
	 * @return WP_REST_Response The response indicating success or failure.
	 */
	public function record_post_type_view( WP_REST_Request $request ): WP_REST_Response {
		$post_type = $request->get_param( 'post_type' );

		// Validate the post type exists and is public.
		if ( ! $this->is_public_post_type( (string) $post_type ) ) {
			return $this->not_found();
		}

		$source      = $request->get_param( 'source' ) ?: 'web';
		$data_source = Settings::get( 'data_source' );

		// Bot filtering — only in self-hosted mode.
		if ( 'self_hosted' === $data_source && Settings::get( 'exclude_bots' ) ) {
			if ( BotFilter::is_bot( $request->get_header( 'user_agent' ) ) ) {
				return $this->bot();
			}
		}

		$inserted = null;

		// External provider mode + web visit: dedup check before INSERT.
		if ( 'self_hosted' !== $data_source && 'web' === $source ) {
			$last_sync = (int) get_option( 'mai_analytics_provider_last_sync', 0 );

			if ( ! Database::is_queued( 0, 'post_type', $last_sync ) ) {
				$inserted = Database::insert_view( 0, 'post_type', $source, $post_type );
			}
		} else {
			// Self-hosted (any source) OR external + app → buffer INSERT.
			$inserted = Database::insert_view( 0, 'post_type', $source, $post_type );
		}

		if ( false === $inserted ) {
			return $this->database_error();
		}

		// Fallback sync: if cron is failing, trigger sync after response.
		( new Cron() )->maybe_fallback_sync();

		return new WP_REST_Response( [ 'success' => true ] );
	}

	/**
	 * Gets view counts for a post type archive.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 *
	 * @return WP_REST_Response The response containing view and trending counts.
	 */
	public function get_post_type_views( WP_REST_Request $request ): WP_REST_Response {
		$post_type = $request->get_param( 'post_type' );

		if ( ! $this->is_public_post_type( (string) $post_type ) ) {
			return $this->not_found();
		}

		$counts    = get_option( 'mai_analytics_post_type_views', [] );
		$trending  = get_option( 'mai_analytics_post_type_trending', [] );

		return new WP_REST_Response( [
			'post_type' => $post_type,
			'views'     => (int) ( $counts[ $post_type ] ?? 0 ),
			'trending'  => (int) ( $trending[ $post_type ] ?? 0 ),
		] );
	}

	/**
	 * Gets trending objects from the buffer table.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 *
	 * @return WP_REST_Response The response containing trending objects with view counts.
	 */
	public function get_trending( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$type     = $request->get_param( 'type' );
		$period   = $request->get_param( 'period' );
		$per_page = (int) $request->get_param( 'per_page' );
		$taxonomy = $request->get_param( 'taxonomy' );
		$terms    = $request->get_param( 'terms' );
		$hours    = $this->parse_period( $period );
		$table    = Database::get_table_name();

		// Over-fetch so rows dropped below (taxonomy filter, hidden items)
		// rarely leave the list short.
		$limit = $per_page * 5;

		// Post type archives group by object_key; everything else by object_id.
		if ( 'post_type' === $type ) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT object_key, object_type, COUNT(*) as view_count
					 FROM $table
					 WHERE object_type = 'post_type'
					 AND viewed_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)
					 GROUP BY object_key
					 ORDER BY view_count DESC
					 LIMIT %d",
					$hours,
					$limit
				)
			);
		} else {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT object_id, object_type, COUNT(*) as view_count
					 FROM $table
					 WHERE object_type = %s
					 AND viewed_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)
					 GROUP BY object_id, object_type
					 ORDER BY view_count DESC
					 LIMIT %d",
					$type,
					$hours,
					$limit
				)
			);
		}

		if ( $wpdb->last_error ) {
			return $this->read_error();
		}

		$results = is_array( $results ) ? $results : [];

		// Apply taxonomy filtering.
		if ( 'post' === $type && $taxonomy && $terms ) {
			$term_ids = array_map( 'absint', explode( ',', $terms ) );
			$results  = array_filter( $results, fn( $row ) => has_term( $term_ids, $taxonomy, (int) $row->object_id ) );
		} elseif ( 'term' === $type && $taxonomy ) {
			$results = array_filter( $results, function( $row ) use ( $taxonomy ) {
				$term = get_term( (int) $row->object_id );
				return $term && ! is_wp_error( $term ) && $term->taxonomy === $taxonomy;
			} );
		}

		// Drop items a caller may not see, checked in bulk.
		$key     = 'post_type' === $type ? 'object_key' : 'object_id';
		$visible = $this->visible_ids( $type, array_map( fn( $r ) => $r->{$key}, array_values( $results ) ) );

		if ( null === $visible ) {
			return $this->read_error();
		}

		$visible = array_flip( $visible );
		$results = array_filter( $results, fn( $row ) => isset( $visible[ 'post_type' === $type ? $row->object_key : (int) $row->object_id ] ) );

		$results = array_slice( array_values( $results ), 0, $per_page );

		$this->prime_for_output( $type, array_map( fn( $r ) => (int) ( $r->object_id ?? 0 ), $results ) );

		$data = array_map( fn( $row ) => $this->enrich_result( $row, $type ), $results );

		return new WP_REST_Response( $data );
	}

	/**
	 * Enriches a trending result with title/name and URL.
	 *
	 * @param object $row  The database row with object_id/object_key, object_type, and view_count.
	 * @param string $type The object type: 'post', 'term', 'user', or 'post_type'.
	 *
	 * @return array The enriched result with id/key, type, view_count, title/name, and URL.
	 */
	private function enrich_result( object $row, string $type ): array {
		if ( 'post_type' === $type ) {
			$pt_obj = get_post_type_object( $row->object_key );

			return [
				'post_type'  => $row->object_key,
				'type'       => 'post_type',
				'view_count' => (int) $row->view_count,
				'name'       => $pt_obj ? $pt_obj->labels->name : $row->object_key,
				'url'        => 'post' === $row->object_key ? get_post_type_archive_link( 'post' ) : get_post_type_archive_link( $row->object_key ),
			];
		}

		$item = [
			'id'         => (int) $row->object_id,
			'type'       => $row->object_type,
			'view_count' => (int) $row->view_count,
		];

		match ( $type ) {
			'post' => $item += [
				'title' => get_the_title( $row->object_id ),
				'url'   => get_permalink( $row->object_id ),
			],
			'term' => ( function() use ( &$item, $row ) {
				$term = get_term( (int) $row->object_id );
				if ( $term && ! is_wp_error( $term ) ) {
					$item['name'] = $term->name;
					$item['url']  = get_term_link( $term );
				}
			} )(),
			'user' => ( function() use ( &$item, $row ) {
				$user = get_userdata( (int) $row->object_id );
				if ( $user ) {
					$item['name'] = $user->display_name;
					$item['url']  = get_author_posts_url( $row->object_id );
				}
			} )(),
			default => null,
		};

		return $item;
	}

	/**
	 * Extracts the object type from the route path.
	 *
	 * @param string $route The REST route path.
	 *
	 * @return string|false The object type ('post', 'term', or 'user'), or false if not found.
	 */
	private function get_type_from_route( string $route ): string|false {
		// Core matches routes without case, so this does too.
		if ( preg_match( '#/views?/(post|term|user)/#i', $route, $matches ) ) {
			return strtolower( $matches[1] );
		}

		return false;
	}

	/**
	 * Builds the error for a request the route can't place.
	 *
	 * @since 1.3.8
	 *
	 * @return WP_REST_Response
	 */
	private function invalid_request(): WP_REST_Response {
		return Errors::response( ErrorCode::InvalidRequest, __( 'Invalid request.', 'mai-analytics' ), [], [ 'success' => false ] );
	}

	/**
	 * Builds the error for an item that is missing or hidden.
	 *
	 * @since 1.3.8
	 *
	 * @return WP_REST_Response
	 */
	private function not_found(): WP_REST_Response {
		return Errors::response( ErrorCode::NotFound, __( 'Nothing to count here.', 'mai-analytics' ), [], [ 'success' => false ] );
	}

	/**
	 * Builds the error for a view refused as a bot.
	 *
	 * @since 1.3.8
	 *
	 * @return WP_REST_Response
	 */
	private function bot(): WP_REST_Response {
		return Errors::response( ErrorCode::Bot, __( 'Views from bots are not counted.', 'mai-analytics' ), [], [ 'success' => false ] );
	}

	/**
	 * Builds the error for a view that could not be saved or checked.
	 *
	 * @since 1.3.8
	 *
	 * @param string $message The message. Defaults to the one for a view that could not be saved.
	 *
	 * @return WP_REST_Response
	 */
	private function database_error( string $message = '' ): WP_REST_Response {
		$message = $message ?: __( 'The view could not be saved. Try again.', 'mai-analytics' );

		return Errors::response( ErrorCode::DatabaseError, $message, [], [ 'success' => false ] );
	}

	/**
	 * Builds the error for counts that could not be read.
	 *
	 * @since 1.3.8
	 *
	 * @return WP_REST_Response
	 */
	private function read_error(): WP_REST_Response {
		return $this->database_error( __( 'The views could not be read. Try again.', 'mai-analytics' ) );
	}

	/**
	 * Validates that an object exists and is publicly viewable.
	 *
	 * @param int    $id   The object ID.
	 * @param string $type The object type: 'post', 'term', or 'user'.
	 *
	 * @return bool|null True if the object exists and is publicly viewable, or
	 *                   null when the check for a user could not be read.
	 */
	private function validate_object( int $id, string $type ): ?bool {
		return match ( $type ) {
			// Core's rules for what a signed-out visitor may see. Attachments
			// were never counted (they are `inherit`, which core resolves through
			// the parent), and stay uncounted.
			'post' => (bool) get_post( $id ) && 'attachment' !== get_post_type( $id ) && is_post_publicly_viewable( $id ),
			'term' => ( function() use ( $id ) {
				$term = get_term( $id );

				// is_term_publicly_viewable() reads ->taxonomy without checking for a WP_Error.
				return $term instanceof \WP_Term && is_term_publicly_viewable( $term );
			} )(),
			// Core has no helper for users. A user is visible only with a post
			// this rule would count.
			'user' => ( function() use ( $id ) {
				$found = $this->users_with_viewable_posts( [ $id ] );

				return null === $found ? null : [] !== $found;
			} )(),
			default => false,
		};
	}

	/**
	 * Checks whether a post type's archive may be counted and shown.
	 *
	 * @since 1.3.8
	 *
	 * @param string $post_type The post type name.
	 *
	 * @return bool
	 */
	private function is_public_post_type( string $post_type ): bool {
		$post_type_obj = get_post_type_object( $post_type );

		return $post_type_obj && is_post_type_viewable( $post_type_obj );
	}

	/**
	 * Returns the IDs from a list that pass the visibility rule, checked in bulk.
	 *
	 * Primes only what the checks read, so the five-fold over-fetch loads no
	 * meta or terms. prime_for_output() loads those for the rows returned.
	 *
	 * @since 1.3.8
	 *
	 * @param string $type The object type: 'post', 'term', 'user' or 'post_type'.
	 * @param array  $ids  Object IDs, or post type names for 'post_type'.
	 *
	 * @return array|null The visible IDs (ints), or names for 'post_type', or null when a query failed.
	 */
	private function visible_ids( string $type, array $ids ): ?array {
		if ( 'post_type' === $type ) {
			return array_values( array_filter( array_map( 'strval', $ids ), fn( $name ) => $this->is_public_post_type( $name ) ) );
		}

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( ! $ids ) {
			return [];
		}

		switch ( $type ) {
			case 'post':
				_prime_post_caches( $ids, false, false );
				return array_values( array_filter( $ids, fn( $id ) => $this->validate_object( $id, 'post' ) ) );

			case 'term':
				_prime_term_caches( $ids, false );
				return array_values( array_filter( $ids, fn( $id ) => $this->validate_object( $id, 'term' ) ) );

			case 'user':
				return $this->users_with_viewable_posts( $ids );
		}

		return [];
	}

	/**
	 * Returns the user IDs that exist and have at least one post the post rule
	 * would count, in one query.
	 *
	 * Uses the same sets as validate_object()'s post rule: post types and
	 * statuses core calls viewable (is_post_type_viewable(),
	 * is_post_status_viewable()), without attachments. Reading from the users
	 * table drops IDs whose user was deleted, so no user objects or meta are
	 * loaded. The check for each user stops at its first matching post.
	 *
	 * @since 1.3.8
	 *
	 * @param int[] $ids User IDs.
	 *
	 * @return int[]|null The IDs that pass, in the order given, or null when the query failed.
	 */
	private function users_with_viewable_posts( array $ids ): ?array {
		global $wpdb;

		$ids      = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$types    = array_values( array_filter( get_post_types(), fn( $type ) => 'attachment' !== $type && is_post_type_viewable( $type ) ) );
		$statuses = array_values( array_filter( get_post_stati(), 'is_post_status_viewable' ) );

		if ( ! $ids || ! $types || ! $statuses ) {
			return [];
		}

		$id_holders     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$type_holders   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$status_holders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$found = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT u.ID
				 FROM $wpdb->users u
				 WHERE u.ID IN ($id_holders)
				 AND EXISTS (
					SELECT 1 FROM $wpdb->posts p
					WHERE p.post_author = u.ID
					AND p.post_type IN ($type_holders)
					AND p.post_status IN ($status_holders)
					LIMIT 1
				 )",
				array_merge( $ids, $types, $statuses )
			)
		);

		if ( $wpdb->last_error ) {
			return null;
		}

		$found = array_flip( array_map( 'intval', $found ) );

		return array_values( array_filter( $ids, fn( $id ) => isset( $found[ $id ] ) ) );
	}

	/**
	 * Primes the caches enrich_result() reads, for the rows being returned only.
	 *
	 * @since 1.3.8
	 *
	 * @param string $type The object type: 'post', 'term', 'user' or 'post_type'.
	 * @param int[]  $ids  The object IDs being returned.
	 *
	 * @return void
	 */
	private function prime_for_output( string $type, array $ids ): void {
		$ids = array_values( array_filter( $ids ) );

		if ( ! $ids ) {
			return;
		}

		if ( 'post' === $type ) {
			// The posts are cached already, so _prime_post_caches() would skip
			// them. Load their terms and meta for get_permalink() directly.
			$posts = array_filter( array_map( 'get_post', $ids ) );
			update_post_caches( $posts, 'any', true, true );
		} elseif ( 'user' === $type ) {
			cache_users( $ids );
		}
	}

	/**
	 * Converts a period string to hours.
	 *
	 * @param string $period The period string: '6h', '24h', or '7d'.
	 *
	 * @return int The number of hours represented by the period.
	 */
	private function parse_period( string $period ): int {
		return match ( $period ) {
			'24h' => 24,
			'7d'  => 168,
			default => 6,
		};
	}
}

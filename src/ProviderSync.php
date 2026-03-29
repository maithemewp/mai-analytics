<?php

namespace Mai\Views;

class ProviderSync {

	/**
	 * Gets the active web view provider matching the current data_source setting.
	 *
	 * Calls the 'mai_views_providers' filter to collect registered provider instances,
	 * then returns the one whose slug matches Settings::get('data_source').
	 *
	 * @return WebViewProvider|null The matched provider, or null if none found.
	 */
	public static function get_provider(): ?WebViewProvider {
		$providers   = apply_filters( 'mai_views_providers', [] );
		$data_source = Settings::get( 'data_source' );

		foreach ( $providers as $provider ) {
			if ( $provider instanceof WebViewProvider && $provider->get_slug() === $data_source ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * Main sync entry point called by cron.
	 *
	 * Fetches web views from the external provider for objects that have appeared in the
	 * buffer since last sync, merges with app buffer counts, and writes totals to meta.
	 *
	 * @return void
	 */
	public static function sync(): void {
		// Concurrency lock.
		if ( get_transient( 'mai_views_provider_syncing' ) ) {
			return;
		}

		set_transient( 'mai_views_provider_syncing', 1, 15 * MINUTE_IN_SECONDS );

		$provider = self::get_provider();

		if ( ! $provider || ! $provider->is_available() ) {
			delete_transient( 'mai_views_provider_syncing' );
			return;
		}

		// Provider handles its own API auth (e.g., SiteKit uses googlesitekit_owner_id).
		// We just need a user context for meta writes during cron.
		if ( ! get_current_user_id() ) {
			$sync_user = Settings::get( 'sync_user' );

			if ( $sync_user ) {
				wp_set_current_user( (int) $sync_user );
			}
		}

		global $wpdb;

		$table          = Database::get_table_name();
		$last_sync      = (int) get_option( 'mai_views_provider_last_sync', 0 );
		$last_sync_date = $last_sync ? gmdate( 'Y-m-d H:i:s', $last_sync ) : '1970-01-01 00:00:00';
		$trending_days  = (int) Settings::get( 'trending_window' );
		$retention_days = (int) Settings::get( 'retention' );

		// Ensure retention covers the trending window.
		if ( $retention_days < $trending_days ) {
			$retention_days = $trending_days;
		}

		// Get distinct objects from buffer since last sync.
		$objects = Database::get_distinct_objects_since( $last_sync_date );

		if ( ! $objects ) {
			self::finish_sync( $wpdb, $table, $retention_days );
			return;
		}

		$batch_size = $provider->get_batch_size();
		$batches    = array_chunk( $objects, $batch_size );
		$start_time = time();
		$processed  = 0;

		foreach ( $batches as $batch ) {
			// Stop if we've been running for 10 minutes.
			if ( ( time() - $start_time ) >= 600 ) {
				break;
			}

			self::process_batch( $provider, $batch, $wpdb, $table, $last_sync_date, $trending_days );
			$processed++;
		}

		// Schedule catchup if batches remain.
		if ( $processed < count( $batches ) ) {
			wp_schedule_single_event( time() + 60, 'mai_views_provider_catchup' );
		}

		self::finish_sync( $wpdb, $table, $retention_days );
	}

	/**
	 * Processes a single batch of objects: fetches provider views and merges with app buffer.
	 *
	 * @param WebViewProvider $provider        The active provider instance.
	 * @param array           $batch           Array of buffer objects with object_id, object_type, object_key.
	 * @param \wpdb           $wpdb            The WordPress database instance.
	 * @param string          $table           The buffer table name.
	 * @param string          $last_sync_date  MySQL datetime of the last sync.
	 * @param int             $trending_days   Number of days for the trending window.
	 *
	 * @return void
	 */
	private static function process_batch( WebViewProvider $provider, array $batch, \wpdb $wpdb, string $table, string $last_sync_date, int $trending_days ): void {
		// Build path → object map.
		$path_map = [];

		foreach ( $batch as $obj ) {
			$path = self::get_object_path( $obj );

			if ( ! $path ) {
				continue;
			}

			$path_map[ $path ] = $obj;
		}

		if ( ! $path_map ) {
			return;
		}

		$paths     = array_keys( $path_map );
		$today     = gmdate( 'Y-m-d' );
		$trend_start = gmdate( 'Y-m-d', strtotime( "-{$trending_days} days" ) );

		// Fetch all-time web views from provider.
		$web_views_all = $provider->get_views( $paths, '', $today );

		// Fetch trending-window web views from provider.
		$web_views_trending = $provider->get_views( $paths, $trend_start, $today );

		// If both calls returned empty, the provider likely failed. Skip web meta writes
		// to preserve existing data. App views still get processed.
		$provider_failed = empty( $web_views_all ) && empty( $web_views_trending );

		// Options for post_type archives.
		$pt_views     = get_option( 'mai_views_post_type_views', [] );
		$pt_views_web = get_option( 'mai_views_post_type_views_web', [] );
		$pt_views_app = get_option( 'mai_views_post_type_views_app', [] );
		$pt_trending  = get_option( 'mai_views_post_type_trending', [] );

		$pt_options_dirty = false;

		foreach ( $batch as $obj ) {
			$id   = (int) $obj->object_id;
			$type = $obj->object_type;
			$key  = $obj->object_key;
			$path = self::get_object_path( $obj );

			// Web views — only update if provider call succeeded.
			$web_total    = ( ! $provider_failed && $path ) ? (int) ( $web_views_all[ $path ] ?? 0 ) : null;
			$web_trending = ( ! $provider_failed && $path ) ? (int) ( $web_views_trending[ $path ] ?? 0 ) : null;

			// App views: count new buffer rows since last sync.
			$app_new = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					 FROM $table
					 WHERE object_id = %d
					   AND object_type = %s
					   AND object_key = %s
					   AND source = 'app'
					   AND viewed_at > %s",
					$id,
					$type,
					$key,
					$last_sync_date
				)
			);

			// App trending: count buffer rows in trending window.
			$app_trending = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					 FROM $table
					 WHERE object_id = %d
					   AND object_type = %s
					   AND object_key = %s
					   AND source = 'app'
					   AND viewed_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
					$id,
					$type,
					$key,
					$trending_days
				)
			);

			if ( 'post_type' === $type ) {
				// Post type archives use options.
				if ( null !== $web_total ) {
					$pt_views_web[ $key ] = $web_total;
				}
				$pt_views_app[ $key ] = ( $pt_views_app[ $key ] ?? 0 ) + $app_new;
				$pt_views[ $key ]     = ( $pt_views_web[ $key ] ?? 0 ) + ( $pt_views_app[ $key ] ?? 0 );
				$pt_trending[ $key ]  = ( $web_trending ?? 0 ) + $app_trending;
				$pt_options_dirty     = true;
			} else {
				// Posts, terms, users use meta.
				if ( null !== $web_total ) {
					Sync::update_meta( $id, $type, 'mai_views_web', 'replace', $web_total );
				}
				Sync::update_meta( $id, $type, 'mai_views_app', 'increment', $app_new );

				// Recompute total.
				$current_web = (int) Sync::get_meta( $id, $type, 'mai_views_web' );
				$current_app = (int) Sync::get_meta( $id, $type, 'mai_views_app' );
				Sync::update_meta( $id, $type, 'mai_views', 'replace', $current_web + $current_app );

				// Trending: only update web portion if provider succeeded.
				$current_web_trending = ( null !== $web_trending ) ? $web_trending : (int) Sync::get_meta( $id, $type, 'mai_trending' );
				Sync::update_meta( $id, $type, 'mai_trending', 'replace', $current_web_trending + $app_trending );
			}

			// Delete processed web buffer rows for this object.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM $table
					 WHERE object_id = %d
					   AND object_type = %s
					   AND object_key = %s
					   AND source = 'web'",
					$id,
					$type,
					$key
				)
			);
		}

		if ( $pt_options_dirty ) {
			update_option( 'mai_views_post_type_views', $pt_views, false );
			update_option( 'mai_views_post_type_views_web', $pt_views_web, false );
			update_option( 'mai_views_post_type_views_app', $pt_views_app, false );
			update_option( 'mai_views_post_type_trending', $pt_trending, false );
		}
	}

	/**
	 * Prunes old app buffer rows, updates last sync time, and releases the concurrency lock.
	 *
	 * @param \wpdb  $wpdb           The WordPress database instance.
	 * @param string $table          The buffer table name.
	 * @param int    $retention_days Number of days to retain app buffer rows.
	 *
	 * @return void
	 */
	private static function finish_sync( \wpdb $wpdb, string $table, int $retention_days ): void {
		// Prune old app buffer rows beyond retention.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table WHERE source = 'app' AND viewed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				$retention_days
			)
		);

		update_option( 'mai_views_provider_last_sync', time(), false );
		delete_transient( 'mai_views_provider_syncing' );
	}

	/**
	 * Gets the URL path for a buffer object.
	 *
	 * Resolves the object's permalink and extracts just the path component.
	 * Returns null if the object type is unrecognized or the URL cannot be resolved.
	 *
	 * @param object $obj Buffer row with object_id, object_type, and object_key properties.
	 *
	 * @return string|null The URL path (e.g., '/some-post/'), or null on failure.
	 */
	private static function get_object_path( object $obj ): ?string {
		$url = match ( $obj->object_type ) {
			'post'      => get_permalink( (int) $obj->object_id ),
			'term'      => get_term_link( (int) $obj->object_id ),
			'user'      => get_author_posts_url( (int) $obj->object_id ),
			'post_type' => get_post_type_archive_link( $obj->object_key ),
			default     => null,
		};

		if ( ! $url || is_wp_error( $url ) ) {
			return null;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );

		return $path ?: '/';
	}

	/**
	 * Warm cache by fetching provider views for all (or filtered) objects and writing to meta.
	 *
	 * Yields progress arrays for each processed batch, suitable for CLI output or admin display.
	 *
	 * @param array $args Optional filters: 'type' (post|term|user|archive), 'ids' (int[]),
	 *                    'post_type' (string), 'taxonomy' (string).
	 *
	 * @return \Generator Yields arrays: ['batch' => int, 'total' => int, 'updated' => int, 'type' => string].
	 */
	public static function warm( array $args = [] ): \Generator {
		$provider = self::get_provider();

		if ( ! $provider || ! $provider->is_available() ) {
			return;
		}

		// Provider handles its own API auth (e.g., SiteKit uses googlesitekit_owner_id).
		// We just need a user context for meta writes during cron.
		if ( ! get_current_user_id() ) {
			$sync_user = Settings::get( 'sync_user' );

			if ( $sync_user ) {
				wp_set_current_user( (int) $sync_user );
			}
		}

		global $wpdb;

		$table         = Database::get_table_name();
		$trending_days = (int) Settings::get( 'trending_window' );
		$batch_size    = $provider->get_batch_size();
		$type_filter   = $args['type'] ?? null;
		$ids_filter    = $args['ids'] ?? [];
		$post_type     = $args['post_type'] ?? null;
		$taxonomy      = $args['taxonomy'] ?? null;

		// Collect all objects to warm, grouped by type.
		$object_groups = self::collect_warm_objects( $type_filter, $ids_filter, $post_type, $taxonomy );

		// Flatten into a single list with type tracking.
		$all_objects   = [];
		$object_types  = [];

		foreach ( $object_groups as $group_type => $items ) {
			foreach ( $items as $item ) {
				$all_objects[]  = $item;
				$object_types[] = $group_type;
			}
		}

		if ( ! $all_objects ) {
			return;
		}

		$batches       = array_chunk( $all_objects, $batch_size );
		$type_batches  = array_chunk( $object_types, $batch_size );
		$total_batches = count( $batches );

		$today       = gmdate( 'Y-m-d' );
		$trend_start = gmdate( 'Y-m-d', strtotime( "-{$trending_days} days" ) );

		// Options for post_type archives.
		$pt_views     = get_option( 'mai_views_post_type_views', [] );
		$pt_views_web = get_option( 'mai_views_post_type_views_web', [] );
		$pt_trending  = get_option( 'mai_views_post_type_trending', [] );

		foreach ( $batches as $batch_index => $batch ) {
			// Build path map.
			$path_map = [];

			foreach ( $batch as $obj ) {
				$path = self::get_object_path( $obj );

				if ( ! $path ) {
					continue;
				}

				$path_map[ $path ] = $obj;
			}

			$updated      = 0;
			$current_type = $type_batches[ $batch_index ][0] ?? 'unknown';

			if ( $path_map ) {
				$paths = array_keys( $path_map );

				// Fetch all-time web views.
				$web_views_all = $provider->get_views( $paths, '', $today );

				// Fetch trending-window web views.
				$web_views_trending = $provider->get_views( $paths, $trend_start, $today );

				// If both calls returned empty, the provider likely failed. Skip web writes.
				$provider_failed = empty( $web_views_all ) && empty( $web_views_trending );

				foreach ( $path_map as $path => $obj ) {
					$id   = (int) $obj->object_id;
					$type = $obj->object_type;
					$key  = $obj->object_key;

					$web_total    = $provider_failed ? null : (int) ( $web_views_all[ $path ] ?? 0 );
					$web_trending = $provider_failed ? null : (int) ( $web_views_trending[ $path ] ?? 0 );

					// App trending from buffer.
					$app_trending = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*)
							 FROM $table
							 WHERE object_id = %d
							   AND object_type = %s
							   AND object_key = %s
							   AND source = 'app'
							   AND viewed_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
							$id,
							$type,
							$key,
							$trending_days
						)
					);

					if ( 'post_type' === $type ) {
						if ( null !== $web_total ) {
							$pt_views_web[ $key ] = $web_total;
						}
						$app_count            = (int) ( get_option( 'mai_views_post_type_views_app', [] )[ $key ] ?? 0 );
						$pt_views[ $key ]     = ( $pt_views_web[ $key ] ?? 0 ) + $app_count;
						$pt_trending[ $key ]  = ( $web_trending ?? 0 ) + $app_trending;
					} else {
						if ( null !== $web_total ) {
							Sync::update_meta( $id, $type, 'mai_views_web', 'replace', $web_total );
						}

						// Recompute total from current values.
						$current_web = (int) Sync::get_meta( $id, $type, 'mai_views_web' );
						$current_app = (int) Sync::get_meta( $id, $type, 'mai_views_app' );
						Sync::update_meta( $id, $type, 'mai_views', 'replace', $current_web + $current_app );

						// Trending total — only update web portion if provider succeeded.
						$effective_web_trending = ( null !== $web_trending ) ? $web_trending : (int) Sync::get_meta( $id, $type, 'mai_trending' );
						Sync::update_meta( $id, $type, 'mai_trending', 'replace', $effective_web_trending + $app_trending );
					}

					$updated++;
				}
			}

			// Persist post_type options after each batch.
			update_option( 'mai_views_post_type_views', $pt_views, false );
			update_option( 'mai_views_post_type_views_web', $pt_views_web, false );
			update_option( 'mai_views_post_type_trending', $pt_trending, false );

			yield [
				'batch'   => $batch_index + 1,
				'total'   => $total_batches,
				'updated' => $updated,
				'type'    => $current_type,
			];
		}
	}

	/**
	 * Collects objects to warm, optionally filtered by type, IDs, post type, or taxonomy.
	 *
	 * When no arguments are provided, returns all public posts, public taxonomy terms,
	 * authors with published posts, and public post type archives.
	 *
	 * @param string|null $type_filter Object type filter: 'post', 'term', 'user', or 'archive'.
	 * @param array       $ids_filter  Optional array of specific IDs to limit to.
	 * @param string|null $post_type   Optional post type slug to filter posts or archives.
	 * @param string|null $taxonomy    Optional taxonomy slug to filter terms.
	 *
	 * @return array Associative array keyed by type label, each containing arrays of stdClass objects
	 *               with object_id, object_type, and object_key properties.
	 */
	private static function collect_warm_objects( ?string $type_filter, array $ids_filter, ?string $post_type, ?string $taxonomy ): array {
		global $wpdb;

		$groups = [];

		// Posts.
		if ( ! $type_filter || 'post' === $type_filter ) {
			$post_types = $post_type ? [ $post_type ] : get_post_types( [ 'public' => true ] );

			foreach ( $post_types as $pt ) {
				$where = $wpdb->prepare(
					"WHERE post_type = %s AND post_status = 'publish'",
					$pt
				);

				if ( $ids_filter ) {
					$placeholders = implode( ',', array_fill( 0, count( $ids_filter ), '%d' ) );
					$where       .= $wpdb->prepare( " AND ID IN ($placeholders)", ...$ids_filter );
				}

				$posts = $wpdb->get_results(
					"SELECT ID FROM {$wpdb->posts} $where ORDER BY ID ASC"
				);

				foreach ( $posts as $row ) {
					$obj              = new \stdClass();
					$obj->object_id   = $row->ID;
					$obj->object_type = 'post';
					$obj->object_key  = '';

					$groups['post'][] = $obj;
				}
			}
		}

		// Terms.
		if ( ! $type_filter || 'term' === $type_filter ) {
			$taxonomies = $taxonomy ? [ $taxonomy ] : get_taxonomies( [ 'public' => true ] );

			foreach ( $taxonomies as $tax ) {
				$where = $wpdb->prepare(
					"WHERE tt.taxonomy = %s",
					$tax
				);

				if ( $ids_filter ) {
					$placeholders = implode( ',', array_fill( 0, count( $ids_filter ), '%d' ) );
					$where       .= $wpdb->prepare( " AND t.term_id IN ($placeholders)", ...$ids_filter );
				}

				$terms = $wpdb->get_results(
					"SELECT t.term_id
					 FROM {$wpdb->terms} t
					 INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
					 $where
					 ORDER BY t.term_id ASC"
				);

				foreach ( $terms as $row ) {
					$obj              = new \stdClass();
					$obj->object_id   = $row->term_id;
					$obj->object_type = 'term';
					$obj->object_key  = '';

					$groups['term'][] = $obj;
				}
			}
		}

		// Users (authors with published posts).
		if ( ! $type_filter || 'user' === $type_filter ) {
			$where = '';

			if ( $ids_filter ) {
				$placeholders = implode( ',', array_fill( 0, count( $ids_filter ), '%d' ) );
				$where        = $wpdb->prepare( "AND post_author IN ($placeholders)", ...$ids_filter );
			}

			$authors = $wpdb->get_results(
				"SELECT DISTINCT post_author
				 FROM {$wpdb->posts}
				 WHERE post_status = 'publish'
				   AND post_type IN ('" . implode( "','", array_map( 'esc_sql', get_post_types( [ 'public' => true ] ) ) ) . "')
				   $where
				 ORDER BY post_author ASC"
			);

			foreach ( $authors as $row ) {
				$obj              = new \stdClass();
				$obj->object_id   = $row->post_author;
				$obj->object_type = 'user';
				$obj->object_key  = '';

				$groups['user'][] = $obj;
			}
		}

		// Post type archives.
		if ( ! $type_filter || 'archive' === $type_filter ) {
			$archive_types = $post_type ? [ $post_type ] : get_post_types( [ 'public' => true, 'has_archive' => true ] );

			foreach ( $archive_types as $pt ) {
				$obj              = new \stdClass();
				$obj->object_id   = 0;
				$obj->object_type = 'post_type';
				$obj->object_key  = $pt;

				$groups['archive'][] = $obj;
			}
		}

		return $groups;
	}
}

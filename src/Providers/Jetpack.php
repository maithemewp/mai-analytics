<?php

namespace Mai\Analytics\Providers;

use Mai\Analytics\Settings;
use Mai\Analytics\WebViewProvider;

class Jetpack implements WebViewProvider {

	/**
	 * Cached raw Jetpack Stats responses keyed by post ID.
	 *
	 * Dedupes API calls when the same post appears under multiple paths within
	 * one batch — that's rare but free. Under the bulk get_views() shape both
	 * windows for a single path are computed from the same cached dataset, so
	 * the cache no longer dedupes "all-time vs trending" calls (those are now
	 * the same call); it dedupes across paths within a single batch.
	 *
	 * @var array<int, array|null>
	 */
	private array $cache = [];

	/**
	 * Gets the provider slug identifier.
	 *
	 * @return string The provider slug.
	 */
	public function get_slug(): string {
		return 'jetpack';
	}

	/**
	 * Gets the human-readable provider label.
	 *
	 * @return string The provider display name.
	 */
	public function get_label(): string {
		return 'Jetpack Stats';
	}

	/**
	 * Gets the maximum number of paths to include in a single batch.
	 *
	 * Jetpack fetches per-post (no batch API), so keep this small.
	 *
	 * @return int The batch size limit.
	 */
	public function get_batch_size(): int {
		return 20;
	}

	/**
	 * Gets the settings fields specific to this provider.
	 *
	 * Jetpack handles its own configuration, so no additional fields are needed.
	 *
	 * @return array Empty array since Jetpack manages its own settings.
	 */
	public function get_settings_fields(): array {
		return [];
	}

	/**
	 * Gets a human-readable reason why the provider is not available.
	 *
	 * @return string The reason, or empty string if available.
	 */
	public function get_unavailable_reason(): string {
		if ( ! class_exists( 'Jetpack' ) ) {
			return __( 'Jetpack plugin is not installed or activated.', 'mai-analytics' );
		}

		if ( ! \Jetpack::is_module_active( 'stats' ) ) {
			return __( 'Jetpack Stats module is not active.', 'mai-analytics' );
		}

		if ( ! class_exists( 'Automattic\Jetpack\Stats\WPCOM_Stats' ) ) {
			return __( 'Jetpack Stats WPCOM_Stats class is not available.', 'mai-analytics' );
		}

		return '';
	}

	/**
	 * Checks whether Jetpack is installed, active, and the Stats module is available.
	 *
	 * @return bool True if Jetpack Stats can be used.
	 */
	public function is_available(): bool {
		return class_exists( 'Jetpack' )
			&& \Jetpack::is_module_active( 'stats' )
			&& class_exists( 'Automattic\Jetpack\Stats\WPCOM_Stats' );
	}

	/**
	 * Fetches pageview counts for the given URL paths across one or more named windows.
	 *
	 * Jetpack Stats has no bulk API — one HTTP call per post regardless. The
	 * win from the multi-window interface here is that both windows for a
	 * single post are computed from the same cached `fetch_post_views()`
	 * result: an empty start_date reads `data['views']` (all-time field);
	 * a non-empty start_date sums the daily array via `sum_trending()`.
	 *
	 * Only posts are supported — paths for terms, users, or archives return
	 * no data because Jetpack Stats indexes by post ID.
	 *
	 * @param array<string>                            $paths   URL paths.
	 * @param array<string, array{0:string,1:string}>  $windows Map of window name to [start, end].
	 *
	 * @return array<string, array<string, int>> Map of path => (window name => view count).
	 */
	public function get_views( array $paths, array $windows ): array {
		if ( ! $paths || ! $windows ) {
			return [];
		}

		$views = [];

		foreach ( $paths as $path ) {
			$url     = home_url( $path );
			$post_id = url_to_postid( $url );

			if ( ! $post_id ) {
				continue;
			}

			$data = $this->fetch_post_views( $post_id );

			if ( null === $data ) {
				continue;
			}

			// Every window for this path comes out of the same raw dataset.
			foreach ( $windows as $window_name => $range ) {
				[ $start_date, $end_date ] = $range;

				if ( '' === $start_date ) {
					$count = (int) ( $data['views'] ?? 0 );
				} else {
					$days  = (int) round( ( strtotime( $end_date ) - strtotime( $start_date ) ) / DAY_IN_SECONDS );
					$count = $this->sum_trending( $data, max( 1, $days ) );
				}

				if ( $count > 0 ) {
					$views[ $path ][ (string) $window_name ] = $count;
				}
			}
		}

		if ( $views ) {
			delete_transient( 'mai_analytics_provider_error' );
		}

		return $views;
	}

	/**
	 * Fetches raw Jetpack Stats data for a single post, with caching.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return array|null The raw stats data, or null on failure.
	 */
	private function fetch_post_views( int $post_id ): ?array {
		if ( array_key_exists( $post_id, $this->cache ) ) {
			return $this->cache[ $post_id ];
		}

		$stats = new \Automattic\Jetpack\Stats\WPCOM_Stats();
		$data  = $stats->get_post_views( $post_id );

		if ( is_wp_error( $data ) || ! is_array( $data ) ) {
			$message = is_wp_error( $data ) ? $data->get_error_message() : 'Invalid response from Jetpack Stats.';
			mai_analytics_logger()->error( 'Jetpack Stats error for post ' . $post_id . ': ' . $message );
			set_transient( 'mai_analytics_provider_error', $message, HOUR_IN_SECONDS );

			$this->cache[ $post_id ] = null;
			return null;
		}

		$this->cache[ $post_id ] = $data;
		return $data;
	}

	/**
	 * Sums views from the daily data array for the last N days.
	 *
	 * @param array $data          Raw Jetpack Stats response with 'data' key.
	 * @param int   $trending_days Number of recent days to sum.
	 *
	 * @return int Total views in the trending window.
	 */
	private function sum_trending( array $data, int $trending_days ): int {
		if ( empty( $data['data'] ) || ! is_array( $data['data'] ) ) {
			return 0;
		}

		$days    = array_reverse( $data['data'] );
		$total   = 0;
		$counted = 0;

		foreach ( $days as $day ) {
			if ( $counted >= $trending_days ) {
				break;
			}

			if ( ! isset( $day[1] ) ) {
				continue;
			}

			$total += absint( $day[1] );
			$counted++;
		}

		return $total;
	}
}

<?php

namespace Mai\Analytics;

interface WebViewProvider {

	/**
	 * Gets the provider slug identifier.
	 *
	 * @return string The provider slug (e.g., 'site_kit', 'matomo').
	 */
	public function get_slug(): string;

	/**
	 * Gets the human-readable provider label.
	 *
	 * @return string The provider display name.
	 */
	public function get_label(): string;

	/**
	 * Checks whether this provider is available and properly configured.
	 *
	 * @return bool True if the provider can be used.
	 */
	public function is_available(): bool;

	/**
	 * Gets the maximum number of paths to include in a single API call.
	 *
	 * @return int The batch size limit.
	 */
	public function get_batch_size(): int;

	/**
	 * Gets the settings fields specific to this provider.
	 *
	 * Each field is an associative array with keys: 'key', 'label', 'type', 'description'.
	 *
	 * @return array Array of field definitions, or empty if no extra settings are needed.
	 */
	public function get_settings_fields(): array;

	/**
	 * Fetches pageview counts for the given URL paths within a date range.
	 *
	 * @param array  $paths      Array of URL paths (e.g., ['/some-post/', '/category/news/']).
	 * @param string $start_date Start date in 'Y-m-d' format.
	 * @param string $end_date   End date in 'Y-m-d' format.
	 *
	 * @return array Associative array of path => view count. Missing paths are omitted.
	 */
	public function get_views( array $paths, string $start_date, string $end_date ): array;
}

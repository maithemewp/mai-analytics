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
	 * Fetches pageview counts for the given URL paths across one or more named date windows.
	 *
	 * Callers pass every window they need in a single call so providers can bulk-fetch.
	 * For example, a sync pulling both "all-time" and "trending" totals sends them together
	 * and the provider returns both per path. The window names are caller-defined keys —
	 * implementations must preserve them in the response.
	 *
	 * An empty start_date in a window's range means "all-time" and is each provider's
	 * responsibility to interpret (Matomo: a long weekly range; Site Kit: omit/far-back
	 * startDate; Jetpack: use the all-time `views` field).
	 *
	 * @param array<string> $paths URL paths (e.g., ['/some-post/', '/category/news/']).
	 * @param array<string, array{0:string,1:string}> $windows Map of window name to
	 *     [start_date, end_date]. Dates are 'Y-m-d' or empty string.
	 *
	 * @return array<string, array<string, int>> Map of path => (window name => view count).
	 *     Paths or windows with no data may be omitted.
	 */
	public function get_views( array $paths, array $windows ): array;
}

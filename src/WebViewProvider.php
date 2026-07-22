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
	 * responsibility to interpret (Matomo: a long weekly range; Site Kit: a far-back
	 * startDate; Jetpack: use the all-time `views` field).
	 *
	 * ALL-OR-NOTHING CONTRACT. Implementations MUST return `null` if any part of the
	 * request could not be completed, rather than the paths that did succeed. A
	 * returned array is treated by callers as complete and authoritative for every
	 * path they asked about: `ProviderSync` reads a missing path or window as a
	 * genuine zero and writes that zero to meta. A partial array therefore erases
	 * real view counts for the paths that failed. See `Matomo::get_views()`, which
	 * abandons the whole call when a single chunk fails.
	 *
	 * Because absence means zero, implementations should omit paths and windows with
	 * no views rather than returning explicit zeros — both are read the same way.
	 *
	 * @since 1.3.3 Return type widened to `?array` so "no views" and "request failed"
	 *     are distinguishable. Previously both were `[]`, which made a site with no
	 *     traffic look like an outage: the caller skipped its synced-at stamp, so
	 *     those objects were re-fetched on every pass forever and a stale provider
	 *     error could never clear.
	 *
	 * @param array<string> $paths URL paths (e.g., ['/some-post/', '/category/news/']).
	 * @param array<string, array{0:string,1:string}> $windows Map of window name to
	 *     [start_date, end_date]. Dates are 'Y-m-d' or empty string.
	 *
	 * @return array<string, array<string, int>>|null Map of path => (window name => view
	 *     count), omitting anything with no views. An empty array means the request
	 *     succeeded and nothing has views. Null means the request failed and callers
	 *     must preserve existing data.
	 */
	public function get_views( array $paths, array $windows ): ?array;
}

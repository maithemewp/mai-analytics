# Changelog

## 1.0.0

Rebranded from Mai Analytics to Mai Views. Complete rename of namespace, constants, meta keys, REST endpoints, and all internal references.

* Added: Backward-compatible meta keys (`mai_views`, `mai_trending`) matching Mai Publisher for zero-migration on existing sites.
* Added: Web/app source split keys (`mai_views_web`, `mai_views_app`).
* Added: Jetpack Stats as a data source provider (posts only).
* Added: `disabled` option for data source to fully disable tracking and sync.
* Added: Automatic settings migration from Mai Publisher's `mai_publisher` option.
* Added: Automatic migration of old `mai_analytics_*` options and meta keys.
* Added: `[mai_views]` shortcode with `mai_views_get_views()`, `mai_views_get_count()`, `mai_views_get_short_number()` template functions.
* Added: `wp mai-views doctor` CLI command with 33 health checks including REST endpoint tests and provider connectivity.
* Added: Dual-load constant guard (`MAI_VIEWS_VERSION`) for standalone + Composer coexistence.
* Added: Conditional plugin updater — skips when loaded from `vendor/`.
* Added: Environment-aware beacon tracking — disabled on non-production by default.
* Added: `MAI_VIEWS_ENABLE_TRACKING` constant and `mai_views_tracking_enabled` filter for override.
* Added: Deprecated filter bridge — `mai_publisher_entry_views` fires via `apply_filters_deprecated()` for backward compat.
* Changed: Buffer table renamed to `wp_mai_views_buffer`.
* Changed: REST namespace changed to `mai-views/v1`.
* Changed: Composer package name set to `maithemewp/mai-views`.
* Changed: Version reset to 1.0.0.

# Changelog

## 1.0.4

Skip provider-unavailable admin notice when view tracking is set to disabled.

## 1.0.3

Bail gracefully when installed alongside old Mai Publisher versions that still have the built-in `Mai_Publisher_Views` class. Shows an admin notice prompting the user to update Mai Publisher or deactivate Mai Analytics. Prevents double-tracking.

## 1.0.2

Standalone installs now override Mai Publisher's bundled copy. Composer's `files` autoload no longer auto-runs `mai-analytics.php`; the standalone plugin prepends its Composer `ClassLoader` so `Mai\Analytics\*` resolves to its `src/` even when Mai Publisher's autoloader ran first. Mai Publisher loads the bundled bootstrap only if no standalone is active. Drop a new Mai Analytics onto a Mai Publisher site and it takes over cleanly.

## 1.0.1

Fix `Last sync recent` health check reporting stale on external-provider sites. The check now reads `mai_analytics_provider_last_sync` for Matomo/GA/Jetpack and `mai_analytics_synced` for self-hosted, matching the option each sync path actually writes to.

## 1.0.0

Initial release as Mai Analytics. View tracking extracted from Mai Publisher and expanded with self-hosted tracking, Google Analytics (via Site Kit), Matomo, and Jetpack Stats support.

# Changelog

## 1.0.1

Fix `Last sync recent` health check reporting stale on external-provider sites. The check now reads `mai_analytics_provider_last_sync` for Matomo/GA/Jetpack and `mai_analytics_synced` for self-hosted, matching the option each sync path actually writes to.

## 1.0.0

Initial release as Mai Analytics. View tracking extracted from Mai Publisher and expanded with self-hosted tracking, Google Analytics (via Site Kit), Matomo, and Jetpack Stats support.

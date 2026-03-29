# Mai Views — Architecture

## Overview

WordPress plugin for view tracking across posts, terms, and authors. Handles web visitors (JS beacon) and app users (REST API). Designed for high-traffic sites with page caching, CDN, and Varnish. Uses the same meta keys as Mai Publisher (`mai_views`, `mai_trending`) for backward compatibility. Can run standalone or as a Composer dependency inside Mai Publisher.

## Meta Keys

| Key | Stored on | Value |
|-----|-----------|-------|
| `mai_views` | post_meta, term_meta, user_meta | Lifetime total views (web + app) |
| `mai_views_web` | post_meta, term_meta, user_meta | Lifetime web-only views |
| `mai_views_app` | post_meta, term_meta, user_meta | Lifetime app-only views |
| `mai_trending` | post_meta, term_meta, user_meta | Views in trending window (default 7 days) |

All registered with `show_in_rest: true` so they appear in `wp/v2/posts` responses.

Post type archive counts stored in options: `mai_views_post_type_views`, `mai_views_post_type_views_web`, `mai_views_post_type_views_app`, `mai_views_post_type_trending`.

## Data Sources

| Source | Slug | How it works |
|--------|------|-------------|
| Disabled | `disabled` | No tracking, no sync. Dashboard shows existing data. |
| Self-Hosted | `self_hosted` | Beacon records every view in buffer. Sync aggregates to meta. |
| Site Kit (GA4) | `site_kit` | Beacon deduplicates in buffer. Provider sync fetches from GA4 via Site Kit REST API. |
| Matomo | `matomo` | Same dedup pattern. Fetches via Matomo Bulk API. |
| Jetpack Stats | `jetpack` | Same dedup pattern. Fetches via `WPCOM_Stats::get_post_views()`. Posts only. |

## Counting Flow

### Self-Hosted Mode

```
WEB: Page loads from cache (PHP never runs)
  -> Inline JS in wp_footer: navigator.sendBeacon('/wp-json/mai-views/v1/view/post/{id}')

APP: Article screen opens
  -> POST to mai-views/v1/view/post/{id} with source=app

SERVER (REST endpoint):
  1. Bot filter check (user-agent)
  2. INSERT INTO wp_mai_views_buffer (object_id, object_type, viewed_at, source)
  3. Transient-gated shutdown sync trigger
  4. Return { success: true }

SHUTDOWN SYNC (after response sent):
  5. Aggregate buffer rows -> increment mai_views, mai_views_web, mai_views_app meta
  6. Recalculate mai_trending from buffer rows in trending window
  7. Prune old buffer rows beyond retention

CRON BACKUP (every 15 min):
  If last sync > 10 min ago -> sync (safety net)
```

### Provider Mode (Site Kit / Matomo / Jetpack)

```
WEB: Beacon fires same as self-hosted
  -> REST endpoint deduplicates: only INSERT if object not already in buffer since last provider sync

PROVIDER SYNC (cron, every 15 min):
  1. Get distinct objects from buffer since last sync
  2. Resolve object URLs to paths
  3. Batch-fetch pageview counts from provider API (all-time + trending window)
  4. Count app buffer rows per object
  5. Write: mai_views_web = provider total, mai_views_app += buffer app count, mai_views = web + app
  6. Write: mai_trending = provider trending + app trending
  7. Delete processed web buffer rows
  8. Prune old app buffer rows
```

## Database Table: `wp_mai_views_buffer`

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT AUTO_INCREMENT | PK |
| `object_id` | BIGINT | Post ID, term ID, user ID, or 0 for post_type archives |
| `object_type` | VARCHAR(20) | `'post'`, `'term'`, `'user'`, or `'post_type'` |
| `object_key` | VARCHAR(50) | Archive key for post_type objects |
| `viewed_at` | DATETIME | UTC |
| `source` | VARCHAR(10) | `'web'` or `'app'` |

**Indexes:**
- Primary: `(id)`
- `(object_id, object_type, viewed_at)` — aggregate queries
- `(object_key, object_type, viewed_at)` — archive lookups

## REST Endpoints

### Public (no auth)

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `mai-views/v1/view/post/{id}` | POST | Record a post view |
| `mai-views/v1/view/term/{id}` | POST | Record a term view |
| `mai-views/v1/view/user/{id}` | POST | Record an author view |
| `mai-views/v1/view/post_type/{type}` | POST | Record a post type archive view |
| `mai-views/v1/views/post/{id}` | GET | Get counts for a post |
| `mai-views/v1/views/term/{id}` | GET | Get counts for a term |
| `mai-views/v1/views/user/{id}` | GET | Get counts for an author |
| `mai-views/v1/views/post_type/{type}` | GET | Get counts for a post type archive |
| `mai-views/v1/views/trending` | GET | Top objects by trending views |

### Admin (`edit_others_posts` required)

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `mai-views/v1/admin/summary` | GET | Dashboard card data |
| `mai-views/v1/admin/top/posts` | GET | Paginated post list with filters |
| `mai-views/v1/admin/top/terms` | GET | Paginated term list |
| `mai-views/v1/admin/top/authors` | GET | Paginated author list |
| `mai-views/v1/admin/top/archives` | GET | Post type archive list |
| `mai-views/v1/admin/filters` | GET | Dropdown options (post types, taxonomies, authors) |
| `mai-views/v1/admin/search` | GET | Search posts/terms/authors |
| `mai-views/v1/admin/sync-now` | POST | Trigger manual sync |
| `mai-views/v1/admin/warm` | POST | Trigger provider warm |

## Plugin Settings

### DB-backed (Settings page)

| Key | Default | Description |
|-----|---------|-------------|
| `data_source` | `self_hosted` | `disabled`, `self_hosted`, `site_kit`, `matomo`, `jetpack` |
| `sync_user` | `0` | User ID for provider API auth context during cron |
| `matomo_url` | `''` | Matomo instance URL |
| `matomo_site_id` | `''` | Matomo site/app ID |
| `matomo_token` | `''` | Matomo API token |

Stored in `mai_views_settings` option.

### Filter-only

| Setting | Default | Filter |
|---------|---------|--------|
| `trending_window` | `7` (days) | `mai_views_trending_window` |
| `retention` | `14` (days) | `mai_views_retention` |
| `sync_interval` | `5` (minutes) | `mai_views_sync_interval` |
| `exclude_bots` | `true` | `mai_views_exclude_bots` |

## Environment Handling

Beacon tracking disabled on non-production (`wp_get_environment_type() !== 'production'`). Also disabled when `data_source` is `disabled`. Override with `MAI_VIEWS_ENABLE_TRACKING` constant or `mai_views_tracking_enabled` filter.

Provider sync, dashboard, CLI, and all read operations work on any environment.

## Dual-Load Prevention

```php
if ( defined( 'MAI_VIEWS_VERSION' ) ) {
    return;
}
```

When loaded both as a standalone plugin and via Composer inside Mai Publisher, whichever loads first wins. The plugin update checker skips when running from `vendor/`.

## Migration

`src/Migration.php` handles one-time migrations:

1. **From Mai Publisher:** Reads `mai_publisher` option, maps `views_api` -> `data_source`, carries over Matomo credentials and filter defaults.
2. **From Mai Analytics (pre-rename):** Renames `mai_analytics_*` options and meta keys to new names. Keeps higher count where both exist.

## File Structure

```
mai-views.php              — Entry point, constants, dual-load guard, activation/deactivation
composer.json              — PSR-4 autoload (Mai\Views\ -> src/), files autoload (includes/functions.php)
includes/
  functions.php            — Global functions: mai_views_get_views(), mai_views_get_count(), mai_views_get_short_number()
src/
  Plugin.php               — Bootstrap, provider registration, updater
  Database.php             — Buffer table schema, insert, dedup, migration
  Tracker.php              — Beacon output, environment detection
  RestApi.php              — Public REST endpoints (view recording + reading)
  AdminRestApi.php         — Admin dashboard REST endpoints
  Sync.php                 — Buffer-to-meta aggregation
  ProviderSync.php         — External provider fetch + merge
  Meta.php                 — Meta key registration
  Settings.php             — Config management
  Migration.php            — One-time settings/meta migration
  Cron.php                 — 15-min schedule, sync triggers
  MaiGrid.php              — Mai Post/Term Grid integration
  BotFilter.php            — User-agent bot detection
  Admin.php                — Menu, assets, dashboard shell
  AdminSettings.php        — WP Settings API
  CLI.php                  — WP-CLI commands (doctor, migrate, sync, stats, etc.)
  WebViewProvider.php      — Provider interface
  Providers/
    SiteKit.php            — Google Analytics 4 via Site Kit
    Matomo.php             — Self-hosted Matomo
    Jetpack.php            — Jetpack Stats
```

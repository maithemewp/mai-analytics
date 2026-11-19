# Mai Analytics

View tracking for WordPress posts, terms, and authors. Supports self-hosted tracking, Google Analytics (via Site Kit), Matomo, and Jetpack Stats.

## Features

- First-party view tracking via JS beacon (`navigator.sendBeacon`)
- Tracks every publicly viewable post type (attachments excepted), post type archives, taxonomy archives, and author archives automatically. An author archive counts only when the author has a publicly viewable post.
- Web + app source split (`mai_views_web`, `mai_views_app`)
- Trending views with configurable window (default 7 days)
- External provider support: Google Analytics 4 (via Site Kit), Matomo, Jetpack Stats
- Admin dashboard with summary cards, chart, and filterable tables
- Mai Post Grid / Mai Term Grid integration (order by views or filter by trending)
- Bot filtering via user-agent patterns
- WP-CLI commands for migration, sync, stats, seeding, and diagnostics
- Works standalone or as a Composer dependency inside Mai Publisher
- Backward compatible with Mai Publisher's `mai_views` / `mai_trending` meta keys

## Installation

### Standalone plugin
Download and activate in `wp-content/plugins/mai-analytics/`.

### Via Composer (inside Mai Publisher)
```json
{
    "repositories": [{"type": "vcs", "url": "https://github.com/maithemewp/mai-analytics"}],
    "require": {"maithemewp/mai-analytics": "^1.0"}
}
```

When both the standalone plugin and Mai Publisher's bundled copy are active, the standalone always wins. Mai Publisher defers loading its bundled copy until after all plugins have loaded, and the standalone plugin re-prepends its Composer autoloader so `Mai\Analytics\*` classes and helper functions resolve to the standalone's files. Drop a newer standalone plugin onto a Mai Publisher site to override the bundled version without touching Mai Publisher.

## Settings

Navigate to **Settings > Mai Analytics** (or **Mai Theme > Mai Analytics** when using Mai Theme) to configure:

- **Disabled:** no tracking or syncing. The dashboard shows existing data.
- **Self-Hosted:** built-in beacon tracking with buffer table aggregation.
- **Google Analytics (via Site Kit):** fetches pageview data from GA4. Requires the Site Kit plugin with GA4 set up.
- **Matomo:** fetches from a self-hosted Matomo instance.
- **Jetpack Stats:** fetches from Jetpack's built-in stats (posts only).

## Meta Keys

| Key | Stored on | Description |
|-----|-----------|-------------|
| `mai_views` | post_meta, term_meta, user_meta | Lifetime total views (web + app) |
| `mai_views_web` | post_meta, term_meta, user_meta | Lifetime web-only views |
| `mai_views_app` | post_meta, term_meta, user_meta | Lifetime app-only views |
| `mai_trending` | post_meta, term_meta, user_meta | Views in the trending window |

All meta keys are registered with `show_in_rest: true`.

## Shortcode

```
[mai_views]
```

Displays the view count for the current post or term. Attributes:

```
views              '' for all-time, 'trending' for trending views
min                Minimum views before displaying (default: 20)
format             'short' for abbreviated (2K+), '' for full (2,143)
icon               Icon name for Mai Engine (default: 'heart')
icon_style         solid, light, etc. (default: 'solid')
```

## Template Functions

```php
// Get formatted HTML with icon and count.
mai_analytics_get_views( $atts );

// Get raw integer count.
mai_analytics_get_count( [ 'id' => 123, 'views' => 'trending' ] );

// Format a number as 2K+, 1M+, etc.
mai_analytics_get_short_number( 2500 ); // "2K+"
```

## WP-CLI

### `wp mai-analytics health`

Run 33 health checks: plugin state, database, meta keys, cron, settings, provider connectivity, REST endpoint tests (GET and POST), and Mai Publisher coexistence.

```
wp mai-analytics health          # Full health check
wp mai-analytics health --fix    # Attempt to auto-fix issues (re-create table, reschedule cron)
```

### `wp mai-analytics stats`

Show current stats summary: data source, buffer row count, total lifetime views, last sync times.

```
wp mai-analytics stats           # All object types
wp mai-analytics stats --type=post   # Posts only
wp mai-analytics stats --type=term   # Terms only
wp mai-analytics stats --type=user   # Users only
```

### `wp mai-analytics sync`

Force a sync immediately. It follows the data source setting. In self-hosted mode it aggregates the buffer into meta. In Site Kit, Matomo or Jetpack mode it fetches from the provider.

```
wp mai-analytics sync
wp mai-analytics sync --verbose  # Show data source and buffer row counts
```

### `wp mai-analytics warm`

Bulk-fetch stats from the active provider for all objects (or a filtered subset). Unlike provider-sync which only fetches objects in the buffer, warm fetches everything. Use it after switching providers, or on staging sites that need production data.

```
wp mai-analytics warm                                    # All objects
wp mai-analytics warm --type=post                        # Posts only
wp mai-analytics warm --type=post --post_type=portfolio  # Specific post type
wp mai-analytics warm --type=term --taxonomy=category    # Specific taxonomy
wp mai-analytics warm --type=post --ids=1,2,3            # Specific IDs
wp mai-analytics warm --verbose                          # Show per-batch progress
```

### `wp mai-analytics migrate`

Migrate settings from Mai Publisher (views_api, Matomo credentials, trending_days, views_interval) and/or old Mai Analytics options/meta keys.

```
wp mai-analytics migrate         # Run migration (skips if already done)
wp mai-analytics migrate --force # Clear migration flags and re-run
```

### `wp mai-analytics seed`

Generate fake view data in the buffer table for testing. Picks random published posts and inserts view rows spread across a time range, then runs a sync.

```
wp mai-analytics seed                                    # 50 posts, up to 200 views each, 30 days
wp mai-analytics seed --posts=100 --views=500 --days=14  # Custom parameters
wp mai-analytics seed --include-terms --include-authors   # Also seed terms and authors
```

### `wp mai-analytics prune`

Manually prune old buffer rows. By default uses the retention setting (14 days). Useful for cleanup after testing.

```
wp mai-analytics prune                    # Prune using retention setting
wp mai-analytics prune --older-than=48h   # Prune rows older than 48 hours
wp mai-analytics prune --older-than=7d    # Prune rows older than 7 days
wp mai-analytics prune --dry-run          # Show what would be pruned
```

### `wp mai-analytics reset`

Delete ALL Mai Analytics data: truncate buffer table, delete all view/trending meta from posts/terms/users, clear all options and transients. Requires confirmation.

```
wp mai-analytics reset           # Prompts for confirmation
wp mai-analytics reset --yes     # Skip confirmation
```

### `wp mai-analytics update-bots`

Fetch the latest bot user-agent patterns from Matomo's device-detector repository. Same script that runs on `composer update`.

```
wp mai-analytics update-bots
```

## Filters

| Filter | Default | Description |
|--------|---------|-------------|
| `mai_analytics_trending_window` | `7` (days) | Trending calculation window |
| `mai_analytics_retention` | `14` (days) | Buffer row retention |
| `mai_analytics_sync_interval` | `5` (minutes) | Reported to Mai Publisher only. Does not change the sync schedule. |
| `mai_analytics_exclude_bots` | `true` | Filter bot user-agents |
| `mai_analytics_tracking_enabled` | `true` on production | Override beacon tracking per environment |

## REST API

Every route lives under `/wp-json/mai-analytics/v1`.

### Public routes

- `POST /view/{post|term|user}/{id}` and `POST /view/post_type/{name}` record a view. They take `source` (`web` or `app`, default `web`) and answer `{ "success": true }`.
- `GET /views/{post|term|user}/{id}` and `GET /views/post_type/{name}` answer the item's `views` and `trending` counts.
- `GET /views/trending` lists the most viewed items. It takes `type` (`post`, `term`, `user` or `post_type`), `period` (`6h`, `24h` or `7d`), `per_page` (1 to 100), `taxonomy`, and `terms` (at most 100 IDs).

### Admin routes

- `GET /admin/top/posts`, `/admin/top/terms`, `/admin/top/authors`, `/admin/top/archives`, `/admin/filters` and `/admin/search` need the `edit_others_posts` capability. `/admin/search` takes `page` and `per_page` (at most 100) and sends `X-WP-Total` and `X-WP-TotalPages`.
- `POST /admin/sync-now`, `/admin/warm`, `/admin/health` and `/admin/dismiss-error` need `manage_options`.

### Errors

Every error carries `code`, `message` and `data.status`. Errors on the view routes also keep `"success": false`, and Warm errors keep its progress fields.

- **400 `mai_analytics_invalid_request`:** an argument is missing or wrong. A caller who fails the permission check gets core's 401 or 403 `rest_forbidden` first.
- **403 `mai_analytics_bot`:** in self-hosted mode, a view from a bot user agent.
- **404 `mai_analytics_not_found`:** the item is missing or not publicly viewable.
- **501 `mai_analytics_provider_unavailable`:** Sync Now has no working provider set up.
- **501 `mai_analytics_provider_required`:** Warm was called in self-hosted mode.
- **502 `mai_analytics_provider_request_failed`:** the provider answered with an error.
- **503 `mai_analytics_database_error`:** a query failed. The response sends `Retry-After: 1`, so try again.

## How It Works

### Recording views

Every page load outputs a single inline script in `wp_footer`:

```html
<script>if('sendBeacon' in navigator){navigator.sendBeacon('/wp-json/mai-analytics/v1/view/post/123');}</script>
```

`navigator.sendBeacon` is fire-and-forget. It needs no response, doesn't block, and works during page unload. The visitor never waits. On cached pages (Varnish, nginx, etc.), PHP doesn't run at all. Only the beacon JS fires from the browser.

The beacon hits a REST endpoint that does **one INSERT** into a buffer table (`wp_mai_analytics_buffer`). Before it, the endpoint reads the post, term or user row to check that it is publicly viewable, and in provider mode it checks whether the item is already queued. No meta reads, no meta writes, no aggregation.

Logged-in users with `edit_posts` capability are excluded. Bots are filtered by user-agent. Non-production environments are excluded automatically.

### The buffer table

Append-only. No UPDATEs, no DELETEs during normal traffic. MySQL handles append-only INSERTs extremely well, with no row locking contention.

At 2M views/month with 14-day retention: ~940K rows, ~75MB. Trivial for MySQL.

### Why buffer rows are kept after sync

**Self-hosted mode:** Total views (`mai_views`) are an incrementing counter. Each sync aggregates new rows into meta. But trending views (`mai_trending`) are recalculated every sync by counting raw rows in the sliding window:

```sql
SELECT object_id, COUNT(*) FROM wp_mai_analytics_buffer
WHERE viewed_at > NOW() - INTERVAL 7 DAY
GROUP BY object_id, object_type
```

If rows were deleted after aggregating totals, the next sync would calculate trending as 0 for everything. The buffer IS the trending data. Rows are pruned only when they're older than the retention period (default 14 days), which must be >= the trending window (default 7 days).

Row lifecycle in self-hosted mode:
1. **Inserted:** counts toward the next sync's total increment AND the trending count
2. **3 days old:** already in the total, still counted for trending (within the 7-day window)
3. **8 days old:** outside the trending window, kept until the retention cutoff
4. **15 days old:** pruned

**Provider mode (Site Kit, Matomo, Jetpack):** The provider supplies both total AND trending web view counts from its own data. Web buffer rows are **deleted immediately** during provider sync. They were only signals for which objects to fetch from the provider. Only app buffer rows are kept for trending (since providers don't track app traffic). The buffer is much smaller in provider mode.

### Syncing buffer to meta

A WP-Cron job runs every 15 minutes and aggregates buffer rows into post/term/user meta:

1. `SELECT ... GROUP BY object_id, object_type, source` counts new views since the last sync
2. Increment `mai_views_web`, `mai_views_app`, recompute `mai_views` total
3. Recalculate `mai_trending` from all rows in the trending window
4. Prune old rows beyond retention

**Meta writes are batched.** Instead of updating `mai_views` on every single view (which would cause lock contention on popular posts with thousands of concurrent visitors), the buffer collects views and one sync process aggregates them. One process, one set of UPDATEs, no race conditions.

### Provider mode (Site Kit, Matomo, Jetpack)

When using an external provider, the buffer serves as a **signaling mechanism**. It records which pages were visited, then provider sync fetches the real counts from GA4/Matomo/Jetpack. The buffer rows aren't the final data; the provider is the source of truth.

Web buffer rows are deduplicated (one per object per sync cycle) to keep the buffer small. App views are always counted from the buffer since external providers don't track app traffic.

#### Matomo configuration notes

The all-time window queries `Actions.getPageUrl` with `period=year, date=last5` (five pre-archived yearly buckets). Trending uses `period=day, date=lastN`. Both rely on Matomo's archiver having pre-built archives. If your Matomo install runs `console core:archive` daily (the recommended setup), responses are fast and memory usage is minimal. If you're seeing 5xx errors during sync:

- **Raise PHP memory** for Matomo's PHP-FPM pool to **at least 512 MB** (`memory_limit = 512M` in `php.ini`). 256 MB is the historical default and isn't enough for high-traffic sites.
- **Set `pm.max_requests`** in PHP-FPM to **100–200** so workers respawn periodically and reclaim memory rather than holding onto archive data across many requests.
- **Lower the bulk chunk size** in **Mai Analytics > Settings > Bulk Chunk Size** if Matomo still struggles. Default is 10 URLs per bulk request (= 20 sub-queries with 2 windows). Drop to 5 or even 1 if your Matomo can't handle the default. The `mai_analytics_matomo_bulk_chunk` filter still works for overrides in code.
- **Make sure `console core:archive` runs daily.** Without it, every API call has to compute archives on-demand, which is slow and memory-intensive.

When provider calls fail, the error is captured (with a timestamp) in the `mai_analytics_provider_error` option and surfaced as a dismissible notice on the Mai Analytics dashboard, so you can tell at a glance whether you're looking at a fresh failure or hours-old residue.

### Sync reliability

Three layers ensure sync always runs, even if WP-Cron fails:

| Layer | Trigger | Stale threshold | Where it runs |
|-------|---------|----------------|---------------|
| WP-Cron | Every 15 min | None | Cron process |
| Admin fallback | Dashboard visit | 30 min stale | Inline on admin_init |
| Beacon fallback | Every view POST | 1 hour stale | Shutdown callback |

Both `Sync::sync()` and `ProviderSync::sync()` have transient-based concurrency locks that prevent stacking. The last-sync timestamp is written at the START of sync so fallback triggers don't re-fire while sync is running.

### Why this scales

- **Page load: zero DB writes.** Cached pages serve the beacon script without PHP.
- **Beacon POST: one INSERT.** No locks, no contention, no cascading writes.
- **Meta writes: batched every 15 min.** One process, no race conditions.
- **Sync runs outside the request lifecycle.** It runs in cron or a shutdown callback, so visitors never wait.
- **Trending is a COUNT, not a running total.** Fast indexed range scan on the buffer table.

At 5K concurrent users all loading a page in the same second, that's 5K INSERTs. MySQL handles 10K+ simple INSERTs/sec easily. The visitors' browsers send the beacon after paint; the response is ~50 bytes of JSON.

### Rate limiting and DDoS

Mai Analytics does not implement application-level rate limiting. The beacon endpoint is intentionally lightweight (one INSERT, ~1ms) to minimize server load, and adding per-request checks would negate that.

**In provider mode**, the dedup logic already prevents abuse. It keeps only one buffer row per object per sync cycle, regardless of how many times the endpoint is hit.

**In self-hosted mode**, the bot filter blocks known user-agents, but a determined attacker could inflate counts by looping POST requests. This is an infrastructure concern:

- **Cloudflare** (free tier) provides rate limiting, bot management, and DDoS protection
- **nginx** `limit_req_zone` can cap requests per IP to the `/wp-json/` path
- **Managed WordPress hosts** (WP Engine, Flywheel, Kinsta) include DDoS protection

This is the standard approach. Google Analytics, Matomo, and Plausible all rely on infrastructure-level protection for their collection endpoints rather than application-level rate limiting.

## Environment Handling

Beacon tracking is automatically disabled on non-production environments (`wp_get_environment_type() !== 'production'`) to prevent buffer pollution on staging/dev sites. Override with:

```php
// wp-config.php
define( 'MAI_ANALYTICS_ENABLE_TRACKING', true );
```

Or via filter:
```php
add_filter( 'mai_analytics_tracking_enabled', '__return_true' );
```

Provider sync, dashboard, CLI, and all read operations work on any environment.

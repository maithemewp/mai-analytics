# Mai Analytics

View tracking for WordPress posts, terms, and authors. Supports self-hosted tracking, Google Analytics (via Site Kit), Matomo, and Jetpack Stats.

## Features

- First-party view tracking via JS beacon (`navigator.sendBeacon`)
- Tracks all public post types, taxonomy archives, and author archives automatically
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

A constant guard (`MAI_ANALYTICS_VERSION`) prevents double-loading if both standalone and Composer versions are present.

## Settings

Navigate to **Settings > Mai Analytics** (or **Mai Theme > Mai Analytics** when using Mai Theme) to configure:

- **Disabled** — No tracking or syncing. Dashboard shows existing data.
- **Self-Hosted** — Built-in beacon tracking with buffer table aggregation.
- **Google Analytics (via Site Kit)** — Fetches pageview data from GA4. Requires Site Kit plugin with GA4 configured.
- **Matomo** — Fetches from a self-hosted Matomo instance.
- **Jetpack Stats** — Fetches from Jetpack's built-in stats (posts only).

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
views              — '' for all-time, 'trending' for trending views
min                — Minimum views before displaying (default: 20)
format             — 'short' for abbreviated (2K+), '' for full (2,143)
icon               — Icon name for Mai Engine (default: 'heart')
icon_style         — solid, light, etc. (default: 'solid')
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

Force a sync immediately. Automatically routes based on the data source setting — runs buffer-to-meta aggregation in self-hosted mode, or fetches from the external provider in Site Kit/Matomo/Jetpack mode.

```
wp mai-analytics sync
wp mai-analytics sync --verbose  # Show data source and buffer row counts
```

### `wp mai-analytics warm`

Bulk-fetch stats from the active provider for all objects (or a filtered subset). Unlike provider-sync which only fetches objects in the buffer, warm fetches everything — useful after switching providers or on staging sites that need production data.

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
| `mai_analytics_sync_interval` | `5` (minutes) | Sync transient TTL |
| `mai_analytics_exclude_bots` | `true` | Filter bot user-agents |
| `mai_analytics_tracking_enabled` | `true` on production | Override beacon tracking per environment |

## How It Works

### Recording views

Every page load outputs a single inline script in `wp_footer`:

```html
<script>if('sendBeacon' in navigator){navigator.sendBeacon('/wp-json/mai-analytics/v1/view/post/123');}</script>
```

`navigator.sendBeacon` is fire-and-forget — no response needed, non-blocking, works during page unload. The visitor never waits. On cached pages (Varnish, nginx, etc.), PHP doesn't run at all — only the beacon JS fires from the browser.

The beacon hits a REST endpoint that does **one INSERT** into a buffer table (`wp_mai_analytics_buffer`). No meta reads, no meta writes, no aggregation. One row, ~1ms, done.

Logged-in users with `edit_posts` capability are excluded. Bots are filtered by user-agent. Non-production environments are excluded automatically.

### The buffer table

Append-only. No UPDATEs, no DELETEs during normal traffic. MySQL handles append-only INSERTs extremely well — no row locking contention.

At 2M views/month with 14-day retention: ~940K rows, ~75MB. Trivial for MySQL.

### Why buffer rows are kept after sync

**Self-hosted mode:** Total views (`mai_views`) are an incrementing counter — each sync aggregates new rows into meta. But trending views (`mai_trending`) are recalculated every sync by counting raw rows in the sliding window:

```sql
SELECT object_id, COUNT(*) FROM wp_mai_analytics_buffer
WHERE viewed_at > NOW() - INTERVAL 7 DAY
GROUP BY object_id, object_type
```

If rows were deleted after aggregating totals, the next sync would calculate trending as 0 for everything. The buffer IS the trending data. Rows are pruned only when they're older than the retention period (default 14 days), which must be >= the trending window (default 7 days).

Row lifecycle in self-hosted mode:
1. **Inserted** — contributes to next sync's total increment AND trending count
2. **3 days old** — already aggregated into total, still counted for trending (within 7-day window)
3. **8 days old** — outside trending window, kept until retention cutoff
4. **15 days old** — pruned

**Provider mode (Site Kit, Matomo, Jetpack):** The provider supplies both total AND trending web view counts from its own data. Web buffer rows are **deleted immediately** during provider sync — they were only used as signals to know which objects to fetch from the provider. Only app buffer rows are kept for trending (since providers don't track app traffic). The buffer is much smaller in provider mode.

### Syncing buffer to meta

A WP-Cron job runs every 15 minutes and aggregates buffer rows into post/term/user meta:

1. `SELECT ... GROUP BY object_id, object_type, source` — count new views since last sync
2. Increment `mai_views_web`, `mai_views_app`, recompute `mai_views` total
3. Recalculate `mai_trending` from all rows in the trending window
4. Prune old rows beyond retention

**Meta writes are batched** — instead of updating `mai_views` on every single view (which would cause lock contention on popular posts with thousands of concurrent visitors), the buffer collects views and one sync process aggregates them. One process, one set of UPDATEs, no race conditions.

### Provider mode (Site Kit, Matomo, Jetpack)

When using an external provider, the buffer serves as a **signaling mechanism** — it records which pages were visited, then provider sync fetches the real counts from GA4/Matomo/Jetpack. The buffer rows aren't the final data; the provider is the source of truth.

Web buffer rows are deduplicated (one per object per sync cycle) to keep the buffer small. App views are always counted from the buffer since external providers don't track app traffic.

### Sync reliability

Three layers ensure sync always runs, even if WP-Cron fails:

| Layer | Trigger | Stale threshold | Where it runs |
|-------|---------|----------------|---------------|
| WP-Cron | Every 15 min | — | Cron process |
| Admin fallback | Dashboard visit | 30 min stale | Inline on admin_init |
| Beacon fallback | Every view POST | 1 hour stale | Shutdown callback |

Both `Sync::sync()` and `ProviderSync::sync()` have transient-based concurrency locks that prevent stacking. The last-sync timestamp is written at the START of sync so fallback triggers don't re-fire while sync is running.

### Why this scales

- **Page load: zero DB writes.** Cached pages serve the beacon script without PHP.
- **Beacon POST: one INSERT.** No locks, no contention, no cascading writes.
- **Meta writes: batched every 15 min.** One process, no race conditions.
- **Sync runs outside request lifecycle.** Cron or shutdown callback — visitors never wait.
- **Trending is a COUNT, not a running total.** Fast indexed range scan on the buffer table.

At 5K concurrent users all loading a page in the same second, that's 5K INSERTs — MySQL handles 10K+ simple INSERTs/sec easily. The visitors' browsers send the beacon after paint; the response is ~50 bytes of JSON.

### Rate limiting and DDoS

Mai Analytics does not implement application-level rate limiting. The beacon endpoint is intentionally lightweight (one INSERT, ~1ms) to minimize server load, and adding per-request checks would negate that.

**In provider mode**, the dedup logic already prevents abuse — only one buffer row per object per sync cycle, regardless of how many times the endpoint is hit.

**In self-hosted mode**, the bot filter blocks known user-agents, but a determined attacker could inflate counts by looping POST requests. This is an infrastructure concern:

- **Cloudflare** (free tier) provides rate limiting, bot management, and DDoS protection
- **nginx** `limit_req_zone` can cap requests per IP to the `/wp-json/` path
- **Managed WordPress hosts** (WP Engine, Flywheel, Kinsta) include DDoS protection

This is the standard approach — Google Analytics, Matomo, and Plausible all rely on infrastructure-level protection for their collection endpoints rather than application-level rate limiting.

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

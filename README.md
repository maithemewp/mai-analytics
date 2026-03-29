# Mai Views

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
Download and activate in `wp-content/plugins/mai-views/`.

### Via Composer (inside Mai Publisher)
```json
{
    "repositories": [{"type": "vcs", "url": "https://github.com/maithemewp/mai-views"}],
    "require": {"maithemewp/mai-views": "^1.0"}
}
```

A constant guard (`MAI_VIEWS_VERSION`) prevents double-loading if both standalone and Composer versions are present.

## Settings

Navigate to **Mai Theme > Mai Views > Settings** to configure:

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
mai_views_get_views( $atts );

// Get raw integer count.
mai_views_get_count( [ 'id' => 123, 'views' => 'trending' ] );

// Format a number as 2K+, 1M+, etc.
mai_views_get_short_number( 2500 ); // "2K+"
```

## WP-CLI

```
wp mai-views doctor          # Run diagnostics (health checks + endpoint tests)
wp mai-views stats           # Show current stats summary
wp mai-views sync            # Force buffer-to-meta sync
wp mai-views provider-sync   # Force external provider sync
wp mai-views warm            # Bulk-fetch all objects from provider
wp mai-views migrate         # Migrate settings from Mai Publisher
wp mai-views seed            # Generate test data
wp mai-views prune           # Remove old buffer rows
wp mai-views reset           # Wipe all Mai Views data
```

## Filters

| Filter | Default | Description |
|--------|---------|-------------|
| `mai_views_trending_window` | `7` (days) | Trending calculation window |
| `mai_views_retention` | `14` (days) | Buffer row retention |
| `mai_views_sync_interval` | `5` (minutes) | Sync transient TTL |
| `mai_views_exclude_bots` | `true` | Filter bot user-agents |
| `mai_views_tracking_enabled` | `true` on production | Override beacon tracking per environment |

## Environment Handling

Beacon tracking is automatically disabled on non-production environments (`wp_get_environment_type() !== 'production'`) to prevent buffer pollution on staging/dev sites. Override with:

```php
// wp-config.php
define( 'MAI_VIEWS_ENABLE_TRACKING', true );
```

Or via filter:
```php
add_filter( 'mai_views_tracking_enabled', '__return_true' );
```

Provider sync, dashboard, CLI, and all read operations work on any environment.

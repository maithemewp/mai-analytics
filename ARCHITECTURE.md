# Mai Analytics — Architecture Plan

## Overview
Standalone WordPress plugin for self-hosted analytics, starting with view tracking for posts, terms, and authors. Handles both web visitors (via JS beacon) and app users (via REST API). Designed for high-traffic sites with page caching, CDN, and Varnish. Coexists with mai-publisher (different meta keys).

## Meta keys

Uses `mai_analytics_` prefix to avoid collision with mai-publisher's `mai_views` / `mai_trending`.

| Key | Stored on | Value |
|-----|-----------|-------|
| `mai_analytics_views` | post_meta, term_meta, user_meta | Lifetime view count |
| `mai_analytics_trending` | post_meta, term_meta, user_meta | Views in trending window (default 6h) |
| `mai_analytics_synced` | option | Last sync timestamp |

Both `mai_analytics_views` and `mai_analytics_trending` registered with `show_in_rest: true` so they are automatically included in `wp/v2/posts` responses (no separate endpoint call needed from apps using maiexpowp).

## Counting flow

```
WEB: Page loads from cache (PHP never runs)
  → Inline JS in wp_footer: navigator.sendBeacon('/wp-json/mai-analytics/v1/view/post/{id}')
  → Fire and forget, no response needed

APP: Article screen opens
  → fetch() fire-and-forget POST to mai-analytics/v1/view/post/{id}
  → No await, no response needed

SERVER (REST endpoint):
  1. INSERT INTO wp_mai_analytics_views (object_id, object_type, viewed_at, source)
  2. get_transient('mai_analytics_sync_lock')
     → Expired: set_transient (5 min TTL), register shutdown sync
     → Set: skip
  3. Return { success: true }

SHUTDOWN (after response sent, via PHP-FPM):
  4. Aggregate table → update post/term/user meta
  5. Prune old rows

WP CRON BACKUP (every 15 min):
  If last sync > 10 min ago → sync (safety net)
```

## What gets tracked

- **All public post types** — auto-detected via `get_post_types(['public' => true])`
- **All taxonomy archives** — auto-detected via `get_taxonomies(['public' => true])`
- **Author/user archives** — tracked as `object_type = 'user'`

No configuration needed for which types to track — if it's public, it's tracked.

## Database table: `wp_mai_analytics_views`

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT AUTO_INCREMENT | PK |
| `object_id` | BIGINT | Post ID, term ID, or user ID |
| `object_type` | VARCHAR(20) | `'post'`, `'term'`, or `'user'` |
| `viewed_at` | DATETIME | UTC |
| `source` | VARCHAR(10) | `'web'` or `'app'` |

**Index:** `(object_id, object_type, viewed_at)` for aggregate queries.
Append-only INSERTs. 7-day default retention, auto-pruned by flush.

## Sync logic (shutdown callback)

```
1. Aggregate new views since last sync:
   SELECT object_id, object_type, COUNT(*) as cnt
   FROM wp_mai_analytics_views
   WHERE viewed_at > {last_flush}
   GROUP BY object_id, object_type

2. For each: increment mai_analytics_views (post_meta, term_meta, or user_meta)

3. Recalculate trending:
   SELECT object_id, object_type, COUNT(*) as cnt
   FROM wp_mai_analytics_views
   WHERE viewed_at > NOW() - INTERVAL {trending_window}
   GROUP BY object_id, object_type
   → Replace mai_analytics_trending for each

4. Prune: DELETE WHERE viewed_at < NOW() - INTERVAL {retention}

5. update_option('mai_analytics_synced', time())
```

## REST endpoints

| Endpoint | Method | Auth | Purpose |
|----------|--------|------|---------|
| `mai-analytics/v1/view/post/{id}` | POST | None | Record a post view |
| `mai-analytics/v1/view/term/{id}` | POST | None | Record a term view |
| `mai-analytics/v1/view/user/{id}` | POST | None | Record an author archive view |
| `mai-analytics/v1/views/post/{id}` | GET | None | Get counts for a post |
| `mai-analytics/v1/views/term/{id}` | GET | None | Get counts for a term |
| `mai-analytics/v1/views/user/{id}` | GET | None | Get counts for an author |
| `mai-analytics/v1/views/trending` | GET | None | Top objects by views |

### `GET mai-analytics/v1/views/trending` params
- `type` — `post`, `term`, `user` (default `post`)
- `period` — `6h`, `24h`, `7d`
- `per_page` — default 10
- `taxonomy` — filter by taxonomy (when type=post or type=term)
- `terms` — comma-separated term IDs (when type=post)

## Web tracking

Inline script output via `wp_footer`, auto-detected based on current page:

**Singular posts/pages (any public post type):**
```html
<script>
if('sendBeacon' in navigator){
  navigator.sendBeacon('/wp-json/mai-analytics/v1/view/post/<?php echo get_the_ID(); ?>');
}
</script>
```

**Taxonomy archives:**
```html
<script>
if('sendBeacon' in navigator){
  navigator.sendBeacon('/wp-json/mai-analytics/v1/view/term/<?php echo get_queried_object_id(); ?>');
}
</script>
```

**Author archives:**
```html
<script>
if('sendBeacon' in navigator){
  navigator.sendBeacon('/wp-json/mai-analytics/v1/view/user/<?php echo get_queried_object_id(); ?>');
}
</script>
```

Detection logic: `is_singular()`, `is_tax() || is_category() || is_tag()`, `is_author()`.

Bot filtering: `sendBeacon` inherently filters bots on the web (bots don't execute JS). REST endpoint also checks user-agent against known bot list.

Logged-in user filtering: The beacon script is not output for any user with `edit_posts` capability (`current_user_can('edit_posts')`). This excludes Editors, Admins, Authors, and Contributors from inflating counts.

## Plugin settings (filterable, sensible defaults)

| Setting | Default | Filter | Purpose |
|---------|---------|--------|---------|
| `trending_window` | `6` (hours) | `mai_analytics_trending_window` | Trending calculation window |
| `retention` | `7` (days) | `mai_analytics_retention` | Raw view row retention (must be >= trending_window) |
| `sync_interval` | `5` (minutes) | `mai_analytics_sync_interval` | Transient TTL |
| `exclude_bots` | `true` | `mai_analytics_exclude_bots` | Filter bot user-agents |

Tracking is automatic for all public post types, public taxonomies, and authors. No UI settings needed for what to track — if it's public, it's tracked.

## Mai Post/Term Grid integration

Same pattern as mai-publisher's `class-views.php`:

- Add "Views (Mai Analytics)" as an `orderby` choice via `acf/load_field/key=mai_grid_block_posts_orderby`
- Add "Trending (Mai Analytics)" as a `query_by` choice via `acf/load_field/key=mai_grid_block_query_by`
- Same for term grid equivalents
- Hook `mai_post_grid_query_args` / `mai_term_grid_query_args` to set `meta_key` to `mai_analytics_views` or `mai_analytics_trending` with `orderby: meta_value_num`

## Cache compatibility

- **Page cache / CDN / Varnish:** Views counted via JS beacon POST (bypasses all page caches)
- **Object cache (Redis):** Not in critical write path. Speeds up meta reads if available.
- **wp cache flush:** No data loss — views live in DB table, not cache
- **Deploys / restarts:** Table persists, flush catches up on next view

## Scale

- 500 views/sec = 1 INSERT each (~1ms). MySQL handles this easily.
- Buffer table at 500/sec sustained, 7d retention = ~300K rows. Trivial for MySQL with proper indexing.
- Sync every 5 min = ~200-500 meta UPDATEs per batch.
- Retention auto-enforced: must be >= trending_window. Changing trending from 6h to 7d works immediately if retention covers it.

---

## CLI commands (WP-CLI)

### `wp mai-analytics migrate`
Import view data from Mai Publisher. Compares values and keeps the higher count.

```
wp mai-analytics migrate [--dry-run] [--post-types=post,page] [--verbose]
```

**Logic:**
1. Query all posts/terms with `mai_views` meta (mai-publisher key)
2. For each, compare `mai_views` vs `mai_analytics_views` (mai-analytics key)
3. If mai-publisher value is higher → write it to `mai_analytics_views`
4. Same for `mai_trending` vs `mai_analytics_trending`
5. Report: "Migrated X posts, Y terms. Skipped Z (mai-analytics already higher)."

### `wp mai-analytics sync`
Force a manual sync of the buffer table to meta.

```
wp mai-analytics sync [--verbose]
```

### `wp mai-analytics stats`
Show current stats summary.

```
wp mai-analytics stats [--type=post|term|user]
```

Output: total tracked objects, total views, buffer table row count, last flush time.

### `wp mai-analytics prune`
Manually prune old buffer rows.

```
wp mai-analytics prune [--older-than=48h] [--dry-run]
```

---

## Testing

PHPUnit test suite covering:
- Table creation on activation
- REST endpoint view recording (post/term/user)
- Bot filtering on REST endpoint
- Flush logic (aggregation, trending calculation, pruning)
- Transient-gated sync trigger
- WP Cron backup sync
- Meta registration and `show_in_rest`
- WP-CLI commands
- Edge cases (invalid IDs, non-public post types, concurrent flush safety)

---

## Phases summary

| Phase | Scope |
|-------|-------|
| **1** | Core plugin: buffer table, REST endpoints, web beacon, transient-gated flush, cron backup, meta storage, Mai Grid integration, PHPUnit tests |
| **1b** | CLI commands: migrate, sync, stats, prune |
| **2** | Admin reports screen: top posts/terms/authors, filters, source breakdown |
| **2b** | Charts and visualizations (optional) |

# TODO — Reconsider the buffer table (daily-bucket counter refactor)

Status: **idea / not scheduled.** Captures a possible architecture change so we don't lose the reasoning. Decide later whether it's worth doing.

## Why this came up

Designing a Statamic/Laravel sibling (`motif-analytics`) raised the question: does mai-analytics
*need* the per-view buffer table, or could a custom counter table be simpler? Worth examining
because the buffer is the most complex part of the plugin (write path + cron aggregation +
pruning + concurrency locks + the largest single slice of LOC).

## Current architecture (as of v1.1.6)

```
view fires
  → Database::insert_view()      one append-only INSERT per view into wp_mai_analytics_buffer
                                 (id, object_id, object_type, object_key, viewed_at, source)

cron every 15 min (Sync::sync())
  → aggregate buffer rows since last sync → increment mai_views_web / mai_views_app postmeta
  → recompute total (mai_views) postmeta
  → recompute mai_trending postmeta = COUNT(*) over buffer WHERE viewed_at > now - N days
  → prune buffer rows older than retention
  → transient lock (mai_analytics_syncing) prevents concurrent double-count

reads (MaiGrid, [mai_views], ElasticPress)
  → read/sort postmeta (mai_views, mai_trending)
```

## Why the buffer exists — it does THREE jobs

1. **Write absorption.** Append-only `INSERT` per view never touches postmeta on the hot path.
   This matters because of #4 below.
2. **Trending sliding-window source.** `Sync::sync()` recomputes "last N days" with
   `COUNT(*) ... WHERE viewed_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL N DAY)`. This *requires*
   per-view timestamped rows — a plain lifetime counter cannot reproduce a moving window.
3. **Provider sync work-list.** `Database::get_distinct_objects_since()` tells `ProviderSync`
   which objects changed since the last external fetch.
4. **(The real driver) WordPress postmeta cannot be incremented atomically.** `Sync::update_meta()`
   does `get_post_meta()` then `update_post_meta()` — a classic read-then-write race. Two
   concurrent views would both read N and write N+1, losing a count. The buffer + single-threaded
   cron sidesteps this: only the cron writes meta, so there is never a concurrent writer. The
   buffer is, in large part, a workaround for postmeta's lack of atomic increment.

## The refactor idea — daily-bucket counter table with atomic increment

Replace the per-VIEW buffer with a per-DAY counter table, incremented atomically:

```sql
CREATE TABLE wp_mai_analytics_counts (
  object_id    bigint unsigned NOT NULL DEFAULT 0,
  object_type  varchar(20)     NOT NULL,
  object_key   varchar(50)     NOT NULL DEFAULT '',
  day          date            NOT NULL,
  source       varchar(10)     NOT NULL DEFAULT 'web',
  count        int unsigned    NOT NULL DEFAULT 0,
  UNIQUE KEY uniq (object_id, object_type, object_key, day, source)
);
```

View fires → one atomic statement, no read-then-write, no race:

```sql
INSERT INTO wp_mai_analytics_counts (object_id, object_type, object_key, day, source, count)
VALUES (%d, %s, %s, UTC_DATE(), %s, 1)
ON DUPLICATE KEY UPDATE count = count + 1;
```

`INSERT ... ON DUPLICATE KEY UPDATE count = count + 1` is a **MySQL feature** (the DB engine
guarantees atomicity of the single statement), not a framework feature — `$wpdb->query()` runs
it fine. It is the same primitive Laravel exposes as `->increment()` / `upsert()`; we are not
reaching for anything Laravel-only.

### What this buys

- **Solves the atomicity problem at the source** (job #4). No more get-then-set race, so views
  *could* update counts without the single-threaded-cron constraint.
- **Bounded row count.** One row per object per day per source instead of one row per view.
  A viral post = 1 row/day, not 50k rows/day. Buffer pruning pressure largely disappears.
- **Trending stays answerable** (job #2): `SUM(count) WHERE day >= UTC_DATE() - INTERVAL N DAY`
  over daily buckets — far fewer rows to scan than per-view aggregation.
- **Lifetime stays answerable**: `SUM(count)` over all days, or keep a denormalized lifetime row.
- **Provider work-list stays answerable** (job #3): `SELECT DISTINCT object_id ... WHERE day >= last_sync_day`.

### The honest tradeoff (this is why it may NOT be worth it)

- **Hot-row write contention.** The append-only buffer's great virtue is that every INSERT is a
  *new* row — zero lock contention even when a post goes viral. The daily-bucket approach hammers
  ONE row (same object+day+source) with `ON DUPLICATE KEY UPDATE`, which takes a row lock. On a
  genuinely viral post doing thousands of views/sec, that single row becomes a contention point.
  The current buffer specifically avoids this. **For a high-traffic WP publisher — mai-analytics'
  core audience — this is the scenario that matters most, and it argues for keeping the buffer.**
- **postmeta still required as the read/sort surface.** WP_Query `orderby => meta_value_num`,
  ElasticPress indexing, and MaiGrid all read postmeta. The counts table can't replace postmeta
  for sorting inside WP_Query without custom JOINs WP doesn't natively support. So a cron to
  denormalize bucket sums → postmeta is still needed; we'd simplify the buffer, not remove the
  cron. (This constraint is WP-specific; the Statamic sibling has no equivalent, which is why it
  can go counter-only and skip the buffer entirely.)

### Net assessment

The daily-bucket table is **cleaner and not hacky** — it's a standard pattern. But it trades the
buffer's zero-contention writes for hot-row contention exactly where mai-analytics is most likely
to be stressed (viral posts on big sites). It also doesn't eliminate the cron or postmeta, only
shrinks the aggregation workload. So it's a **moderate simplification with a real downside**, not
a clear win.

**Recommendation:** do NOT rush this. Two lower-risk middle paths if we revisit:

1. **Keep the buffer, fix only the atomicity** — if we ever want views to update counts outside
   the cron, switch the buffer's downstream meta writes to a counts table with atomic increment,
   keeping the append-only buffer as the write-absorption layer. Smallest change, keeps the
   zero-contention write.
2. **Hybrid** — append-only buffer for write absorption (unchanged), daily-bucket counts table as
   the aggregation *target* instead of postmeta-by-cron, postmeta denormalized from buckets only
   for sort/ElasticPress. More moving parts, not obviously better.

If neither proves worth it, leave the current architecture alone — it works, it's battle-tested on
high-traffic sites, and the buffer's contention-free write is a genuine asset there.

## Open questions to resolve before doing anything

- Measure: what view rate do current high-traffic sites actually hit? If no site is near hot-row
  contention limits, the daily-bucket simplification is safe and the tradeoff is moot.
- Is there appetite to drop the 15-min trending lag (move to near-real-time counts)? Only the
  atomic-increment paths enable that; if the lag is fine, there's less reason to change.
- ElasticPress + WP_Query sort dependency on postmeta — confirm it can't be satisfied another way
  before assuming postmeta must stay.

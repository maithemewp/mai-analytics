# View-Stats Lifecycle — Design Spec

- Date: 2026-07-03
- Status: Proposed (awaiting review)
- Scope: mai-analytics only. The mai-engine Mai Post Grid over-fetch and the "storing-off gates trending grids" question are separate specs.

## Background

larrybrownsports.com (LBS) went down on 2026-07-03 (Cloudflare timeouts starting ~5:10am ET) from database overload. Root cause: mai-analytics writes `mai_trending` post meta for every synced object including a value of `0`, and never prunes it, so the meta grew to 141,483 rows (only 6,829 nonzero, and of those only a sliver current). A Mai Post Grid block orders trending by `ORDER BY wp_postmeta.meta_value+0 DESC`, a numeric filesort that no index can serve, so every cold execution built a temp table and filesorted the entire ~141k keyspace (~1s). Because that grid runs on single posts with `exclude_current` (and `exclude_displayed`), `post__not_in` is baked into mai-engine's grid cache key, making each post URL a unique, unwarmable key; crawler and long-tail traffic then produced a stream of cold 141k-row sorts that saturated MySQL. We pruned the zeros on LBS as an emergency fix (141,483 -> 6,829; query now sub-millisecond).

Two structural problems remain, and this spec addresses both:

1. `mai_trending` has no owned lifecycle. Two separate sync engines write it directly via `Sync::update_meta` and walk away: `Sync::sync()` for self-hosted mode and `ProviderSync::sync()` for external providers (Matomo/GA/Jetpack), branched in `Cron::maybe_sync()`. Both engines write zeros: the provider engine for every processed object whose count is `0`, and the self-hosted engine at `Sync.php:148` when it decays posts that fell out of the window (it writes `0` instead of deleting). Both also leave stale nonzeros: the provider engine when a post is never re-synced, and the self-hosted engine for posts last viewed longer ago than `retention_days`, whose buffer rows are pruned so its inline decay never sees them. Stale nonzeros are a silent correctness bug: an old post can rank as "trending" indefinitely, which quietly degrades trending widgets on news sites.

2. The two engines duplicate all view-meta write logic with subtle, correctness-critical invariants (the `total = max(web + app, trending)` floor, increment-vs-replace semantics, "only overwrite on provider success" null guards). That duplication is where the missing lifecycle hides.

## Goals

- `mai_trending` is bounded to posts actually trending in the window, on every site, in both sync modes, without manual intervention.
- No stored zeros; no persistent stale nonzeros.
- Existing fleet bloat is cleaned up automatically and safely (out-of-band, batched), plus an on-demand CLI lever.
- View-count values (`mai_views`, `mai_views_web`, `mai_views_app`) are unchanged in behavior. This work must not alter any count.
- The write logic lives in one tested place shared by both engines.
- The test suite actually runs on our machines/CI.

## Non-goals (explicit follow-ups)

- `mai_views` (all-time) unbounded growth and the author-views `meta_value_num` filesort. All-time views legitimately exist for every viewed post, so they are not pruned; that perf issue needs indexing/query-scoping and is a separate effort.
- mai-engine Mai Post Grid over-fetch to de-fragment the grid cache for `exclude_current`/`exclude_displayed` grids. Separate spec; the pruned query is cheap enough that this is no longer urgent.
- Gating trending grids when view storing is disabled. Separate mai-engine/publisher decision.

## Architecture

### The `Stats` store (single lifecycle owner)

Introduce `Mai\Analytics\Stats`: the one place that owns the view-meta lifecycle for posts, terms, and users. Both engines route their writes through it; the cron and CLI route pruning through it. It uses the existing low-level `Sync::update_meta` / `Sync::get_meta` helpers plus a new `Sync::delete_meta` (see below).

High-level methods (names indicative):

- `set_web( int $id, string $type, int $value ): void` — replace `mai_views_web`.
- `add_app( int $id, string $type, int $delta ): void` — increment `mai_views_app`.
- `set_trending( int $id, string $type, int $value ): void` — the only home for the `0 => delete` rule: write `mai_trending` when `$value > 0`, otherwise `Sync::delete_meta`.
- `recompute_total( int $id, string $type ): void` — set `mai_views` to `max( web + app, trending )`, preserving the existing floor invariant.
- `mark_synced( int $id, string $type, int $now ): void` — replace `mai_views_synced_at` (provider success marker; unchanged semantics).
- `prune_trending( int $window_days, array $opts = [] ): int` — decay + zero-delete, mode-aware, batched; `$opts['dry_run']` returns the would-delete count without deleting; covers post/term/user; returns affected count.

The `set_`/`add_` asymmetry is deliberate and encodes the existing write semantics. `web` is an authoritative total from the provider, so it is replaced (`set_web`); `app` is accumulated from newly-buffered views counted since the last sync, so the delta is added (`add_app`). Keeping the increment inside the store, rather than a symmetric `set_app` fed an absolute value the engine computes, keeps the read-modify-write in one owned place. If `app` ever became an absolute count, `set_app` would be correct; today it is a delta-accumulated running total.

`Sync::delete_meta( int $id, string $type, string $key ): void` is added next to the existing `update_meta`/`get_meta`, matching their `post`/`term`/`user` dispatch (`delete_post_meta` / `delete_term_meta` / `delete_user_meta`).

Naming note: the low-level meta CRUD stays in `Sync` (which is also the self-hosted engine, an existing overload we are not refactoring here). `Stats` is the new lifecycle/semantics layer on top.

### Both engines refactored to call the store

`ProviderSync::process_batch` and `Sync::sync()` stop calling `Sync::update_meta(..., 'mai_trending', ...)` (and the other view-meta writes) directly and instead call the corresponding `Stats` methods. The engines keep their job — figure out the numbers (from the provider or the local buffer) — and hand them to the store, which owns how they are persisted. This is the DRY win and the safety boundary: the invariants live in one tested place.

### Decay is mode-specific by design (not duplication)

The two modes' data lives in different places, so the correct decay signal differs. This is real, not accidental duplication; `prune_trending()` hides it behind one call by branching on `data_source`.

- Self-hosted: it already decays posts inline. `Sync::sync()` recomputes windowed counts (`Sync.php:96`) and already zeroes posts that fell out of the window but remain within retention (`Sync.php:132-151`). The fix is to make that write **delete** rather than store `0`, by routing it through `Stats::set_trending( ..., 0 )`. That keeps the prompt per-sync decay that already works and stops it creating zeros. Its one gap is posts last viewed longer ago than `retention_days` (buffer rows pruned, so the inline loop never sees them); the daily `prune_trending` recompute covers those by deleting `mai_trending` for any post not in the current window's buffer set. No timestamp needed; the buffer is local and authoritative, so a self-hosted `0`/absence is a real result, never a failure sentinel.
- Provider: use the per-post `mai_views_synced_at` the provider engine already writes (`ProviderSync.php:312`). A post whose `synced_at` predates the trending window cannot reflect current-window activity, so it is stale — delete it. Combined with `set_trending`'s `0 => delete`, this covers both "re-synced to zero" and "never re-synced."
- Universal (both modes): delete rows where `mai_trending = 0` (defensive; the writer already prevents new ones).

Only ever delete on a real result, never on a failure. Two guards enforce this: (1) at write time the provider engine keeps its existing `null !== $web_trending` check, so a failed provider read preserves the existing value instead of computing `0`; (2) at prune time the `synced_at` staleness decay runs only when the provider circuit breaker is not fresh (`Sync::seconds_until_provider_error_clear()` returns 0), so a provider outage cannot wipe trending. The universal zero-delete is always safe and runs regardless. Self-hosted has no failure mode (the buffer is local).

Transition is clean with no "trending blackout": both decay signals already exist for pre-existing rows (provider rows already carry `synced_at`; self-hosted reads the buffer), so the first prune keeps currently-trending posts and removes only stale/zero rows. No backfill migration required.

### Delivery

- Daily recurring cron event `mai_analytics_prune_trending` -> `Stats::prune_trending( trending_window )`. Engine-agnostic; runs regardless of provider health or buffer state (unlike `finish_sync`, which is provider-only and skipped on early error-returns). The daily interval and the batch size are both filter-overridable.
- CLI `wp mai-analytics prune-trending [--dry-run]` -> `Stats::prune_trending(...)`, printing counts. The on-demand and preview lever (LBS today, any site anytime).
- Update trigger: a `maybe_upgrade()` routine on `plugins_loaded` compares a stored `mai_analytics_db_version` option against the plugin version. On mismatch (first load of new code) it (a) `wp_schedule_single_event( time(), 'mai_analytics_prune_trending' )` to kick an immediate out-of-band prune, and (b) ensures the recurring daily event is scheduled, then updates the stored version. The single event fires on the next wp-cron tick — a separate request — so it never runs during the file-copy/upgrade, but starts "right away." Deploy-agnostic: fires whether the site updated via the WP updater, WP-CLI, or a git pull (important for git-deployed sites). This is also the fleet-wide existing-bloat cleanup, so no separate migration DELETE and nothing to remember per site. Caveat: "right away" means next wp-cron trigger, so a zero-traffic site without system cron could lag — irrelevant for the news sites in question.

Batching: `prune_trending` deletes in bounded batches of 5,000 rows (filterable) to avoid long locks / replication lag on large tables, mirroring the emergency prune we ran on LBS.

## Component boundaries

- `Stats` — owns the view-meta lifecycle: write semantics, the `0 => delete` rule, and `prune_trending`. Write methods are pure WP-meta operations, testable in isolation. `prune_trending` branches on `data_source`: the self-hosted branch reads the view buffer (via `Database`) to determine the current window set, and the provider branch reads `mai_views_synced_at`. So `Stats` depends on `Database` for the self-hosted decay path; that dependency is exercised in tests by seeding the buffer, exactly as the existing sync tests do.
- `Sync` (low-level) — `update_meta` / `get_meta` / `delete_meta` dispatch for post/term/user. Unchanged except the new `delete_meta`.
- Sync engines (`Sync::sync`, `ProviderSync`) — compute numbers, call `Stats`. Self-hosted keeps its existing inline post decay (`Sync.php:132-151`), switched from writing `0` to deleting via `Stats::set_trending( ..., 0 )`; the daily `prune_trending` is the backstop for beyond-retention stragglers and legacy zeros. Provider has no inline post decay, so it relies on write-time delete-on-zero plus the daily `prune_trending` (`synced_at` staleness, gated on provider health).
- `Cron` — schedules the daily prune event and the existing sync events; existing self-heal (`ensure_healthy`) covers a deleted schedule.
- `CLI` — `prune-trending` command.
- `Upgrade` (`maybe_upgrade`) — version-option compare, schedules the immediate + recurring prune.

## Rollout sequencing (de-risking B)

The trending lifecycle is low-risk and is the outage fix. Centralizing the view-count writes is where subtlety lives, so counts never move without a green test on each side:

1. Fix the test runner (mandatory; see Testing). Existing suite green first.
2. Build `Stats` + `Sync::delete_meta`; route trending through `Stats::set_trending`; add decay (`prune_trending`), the daily cron, the CLI, and the update trigger. Fully tested. This ships the outage fix; safe to stop here if the day runs out.
3. Migrate `views_web` / `views_app` / `views` / `synced_at` through `Stats`, one meta at a time, each behind a test asserting counts are identical before/after. Not a big-bang rewrite of both engines at once.

Release mai-analytics (its own release process) once tested; the teammate who owns mai-publisher then bumps the mai-analytics dependency on mai-publisher's `develop`.

## Testing

Tooling (the suite does not currently run — the repo has no phpunit dev dependency, and local PHP is 8.5 while the borrowed runner is pinned to PHPUnit 9):

- Add `phpunit/phpunit` (^9.6, which runs on PHP 8.3 via Herd's `php83`) and `yoast/phpunit-polyfills` (^2) to `require-dev`; add a `composer test` script; document the run command. Get the existing suite green before touching engine code.

New/updated tests (WP_UnitTestCase, mirroring `tests/test-provider-sync.php` patterns with the mock provider):

- `Stats::set_trending`: `0` deletes the row (assert `get_post_meta` returns `''` and `[]`); nonzero writes.
- `Stats::set_web` replace; `Stats::add_app` increment; `Stats::recompute_total` preserves the `max(web+app, trending)` floor.
- `prune_trending` provider mode: deletes rows whose `mai_views_synced_at` predates the window; keeps fresh ones.
- `prune_trending` self-hosted mode: recompute decay deletes posts not in the window's buffer result; keeps windowed ones.
- `prune_trending` provider mode does NOT delete stale rows while the provider circuit breaker is fresh (simulated outage), so an outage cannot wipe trending; it still deletes zeros.
- Self-hosted sync deletes (not zeroes) trending for a post that fell out of the window but is within retention (the `Sync.php:132-151` path).
- `prune_trending` universal zero-delete; `dry_run` returns counts without deleting; batching deletes everything across multiple batches.
- CLI `prune-trending` dry-run vs actual delete.
- `maybe_upgrade` schedules the single immediate event and ensures the recurring daily event; is idempotent (no duplicate scheduling; version option advances).
- Regression safety net: existing `test-provider-sync` and `test-sync` stay green; add count-unchanged assertions around each views/web/app migration step (step 3).

## Risks and mitigations

- View-count regression during centralization -> migrate one meta at a time, each with a count-unchanged test; the existing suite is the backstop.
- Large first prune -> batched and run out-of-band via the single scheduled event, never in the upgrade request.
- Provider outage wiping trending via the prune -> the `synced_at` staleness decay runs only when the provider circuit breaker is not fresh, so an outage cannot delete trending; write-time keeps the `null !== $web_trending` guard so a failed read preserves the existing value. Trending is only ever written or deleted from a confirmed provider response.
- Cron not firing -> existing `ensure_healthy` self-heal reschedules; `maybe_upgrade` re-registers the daily event.

## Decisions

- Prune batch size is 5,000 rows and the prune cron runs daily. Both are filter-overridable.
- Self-hosted trending decay stays inline in `Sync::sync()` (its existing loop at `Sync.php:132-151`), changed from writing `0` to deleting via `Stats::set_trending`. The daily `prune_trending` is the backstop for posts beyond `retention_days` and any legacy zeros. We chose fix-in-place over routing all self-hosted decay through the daily prune, to avoid removing working code; the ~24h decay lag applies only to the beyond-retention backstop.
- Trending is only ever written or deleted from a confirmed result: provider write-time keeps the `null !== $web_trending` guard, and provider prune-time `synced_at` decay runs only when the circuit breaker is not fresh. A provider outage never deletes trending.

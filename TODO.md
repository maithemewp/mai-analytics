# TODO

## Review fixes (2026-11-19)

- Merged into `develop` on 2026-11-19, after the alignment pass. Waiting on the release decision.

## End-to-end tests (2026-11-20)

- `bin/e2e.js` on `feat/e2e` ran fully green on the shared production-copy site at 69dfa56 (2026-11-20): 237 checks, against develop 24d8ab5.
- `fix/provider-unreached`, cut from `feat/e2e`: a stopped or failed provider sync lists the objects it did not sync, and the catchup syncs them. Web rows past retention are pruned. Waits on its own e2e claim, then a merge grant.
- Site Kit and Jetpack have unit tests only. Nothing runs them end to end, because both need a connected account. This is a known gap, not coverage.
- Uninstall has unit tests only (`tests/test-uninstall.php`). The e2e leaves it out on purpose: on a shared site it would delete the site's own options and table.

## Do at the release

- Commit the no-dev `vendor/composer/installed.php` and `installed.json`. The committed copies were written by a dev install (`'dev' => true`, dev packages listed). Keep both tracked (mai-coordinator, 2026-11-18), because mai-package-loader reads `installed.php` at runtime. Steps and the loader lines are in STATE.md, "Vendor".

## Later

- Swap `src/RestErrors.php` for `Mai\Items\RestErrors` from mai-items-core once that library is tagged and the PHP floor question (8.1 here, 8.2 there) is settled with Mike.
- `mai_analytics_matomo_bulk_chunk` runs twice: in `Settings::get()` (`src/Settings.php:49`) and in Matomo (`src/Providers/Matomo.php:173`).
- The settings page notice and row and the Health check only see a stored provider error for Site Kit (`src/AdminSettings.php:50`, `:308`, `src/Health.php:124`). Read `Sync::get_last_error()` there instead.
- CLI `reset` and uninstall remove different sets (`src/CLI.php:540-556`, `uninstall.php`). First decide whether `reset` should keep settings.
- Uninstall covers one site only on multisite, and its raw SQL deletes leave stale object cache entries.
- Mai Publisher's bundled copy never clears cron or data, because its activation hooks never fire. Expose `Plugin::deactivate()` so Mai Publisher can call it, then raise it with David.
- The Health REST probe writes a real view for the first published post each run (`src/Health.php:154-157`).
- Health reads `data_source` from the raw option, skipping the `mai_analytics_data_source` filter (`src/Health.php:83-84`).
- The legacy meta-key probe only looks in postmeta (`src/Migration.php:122-129`).
- Per-person views for "For You": a separate design later (Mike, 2026-11-18). Nothing built for it.
- Two read-only `LIKE`s on meta names don't escape `_`: `src/Migration.php:123` and `src/Health.php:54`. A stray match can only make a probe or a count wrong.
- The `SHOW TABLES LIKE` checks don't escape `_` either: `Database::table_exists()` (on purpose, so a test's `query` filter can match the plain name), `maybe_migrate_old_table()` and `src/Health.php:45`. Core escapes its own (`wp-admin/includes/upgrade.php:2687`). A stray match could only report a table that isn't there.
- The tracker still sends a beacon from the author archive of a member with no countable posts, and that view now always answers 404. Skipping it would cost one query per author archive render (the same `post_author` check `RestApi::users_with_viewable_posts()` runs), on a page that may be cached anyway. Today the cost is one wasted POST per such visit.
- The `[mai_views]` shortcode and `mai_analytics_get_count()` read any ID with no visibility check. Only staff can call them today.
- `maybe_migrate_old_table()` copies with `INSERT INTO new SELECT * FROM old`, `id` included, so it can fail on a duplicate key or a column-count mismatch once the new table has rows. It now keeps the old table and retries hourly, but would keep failing. Copying named columns without `id` needs the legacy schemas, which weren't checked.
- `RestApi::visible_ids()` drops posts and terms whose prime query failed instead of answering 503, as trending users now do.
- The single-item routes do the same (review-pr, 2026-11-19, coordinator's ruling: later). `validate_object()` turns a failed `get_post()` or `get_term()` into 404, while a user already answers 503 (`src/RestApi.php`, `validate_object()`). `get_views()` turns a failed meta read into 200 with zeros. A cached getter runs no query, so `last_error` can't tell; count `$EZSQL_ERROR` around the read, as `Database::create_table()` does. The app ignores both 404 and 503 from `useView`.
- A failed meta read during a sync overwrites lifetime counts (review-pr, 2026-11-19, coordinator's ruling: later). `Sync::update_meta()` in increment mode reads, adds and writes (`src/Sync.php`, `update_meta()`). When core's meta read fails, `update_meta_cache()` gets no rows (`wp-includes/meta.php:1204`) and caches an empty list for the object anyway (`:1226-1232`), so `$current` is 0 and the run's delta replaces the lifetime count. Then `Stats::recompute_total()` writes `mai_views` from it, which Mai Publisher reads. Checked in WordPress 7.1.4; still to check in 6.1, the plugin's floor. It needs the read to fail and the write after it to work. Fix with an atomic SQL increment, or by counting `$EZSQL_ERROR` around the read and stopping the sync with the boundary put back.
- Log lines can't be tested: `mai_analytics_logger()` returns a shared `Mai_Logger` (`includes/functions.php:15-18`) with no filter or other seam to watch it. mai-logger is shared across the stack, so a seam is a change there, not here (mai-coordinator, 2026-11-19). Until then the activation, create-table and sync-read messages are checked by reading only.
- No unit-level JS tests exist for the admin screens. `bin/e2e.js` now drives the real dashboard in headless Chrome on a site, filters notice included.
- The web rows of objects a stopped provider sync didn't reach still count toward `GET /views/trending` for up to a week, its longest period, since they stay behind the boundary until retention prunes them. The list now syncs those objects, but their rows are not deleted then. Deleting them would change what trending answers, so it is the coordinator's call.
- A provider that is unavailable still moves the provider sync's boundary (`ProviderSync::sync()`, written before the availability check), so app views since the last sync are skipped. Found while fixing the failed-read case; not walked yet.

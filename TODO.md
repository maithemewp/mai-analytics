# TODO

## Alignment pass (2026-11-18)

- Built on `feat/alignment`, Tasks 0 to 8. Waiting on mai-coordinator's review and merge grant, then Mike's walk of the code names and the release decision.

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

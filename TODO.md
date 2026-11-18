# TODO

## Alignment pass (2026-11-18)

- Spec `docs/specs/2026-11-18-alignment.md` (checked), plan `docs/plans/2026-11-18-alignment.md`. Waiting on mai-coordinator's plan review and Mike's answer on the code names, then a build prompt.

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
- The `[mai_views]` shortcode and `mai_analytics_get_count()` read any ID with no visibility check. Only staff can call them today.

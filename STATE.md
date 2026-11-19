# State
Updated: 2026-11-19 by Claude (mai-analytics review fixes build session)

## Now
- `docs/plans/2026-11-19-review-fixes.md` is built on `feat/review-fixes`, in the worktree `~/Plugins/_wip/analytics-review-fixes`, cut from develop 378b295. All eight tasks are done, one commit each, plus two commits from an independent review. The suite passes with 298 tests; develop had 267. Version stays 1.3.7, and nothing is versioned or released.
- The independent review found four issues, all fixed:
  - **Fresh install:** dbDelta()'s DESCRIBE probe was counted as a failure, so every fresh activation would have reported failure.
  - **Migration retries:** a failed migration retried on every request. It now waits an hour behind `mai_analytics_migrate_retry`.
  - **Failed sync read:** fallbacks would rerun a failing sync on every page load. The lock now expires on its own. This differs from the plan's "release the lock", but the views are still counted on the next run.
  - **`health --fix`:** it exited 1 after fixing everything. Fixed checks now come off the count.
- Branches (`origin` is GitHub maithemewp/mai-analytics):
  - **`develop`:** the main checkout `~/Plugins/mai-analytics` at 378b295, on origin. The shared production-copy site loads it.
  - **`feat/review-fixes`:** this branch, pushed to origin. It is not merged.
  - **`main`:** bf3ba96, Release 1.3.7.
  - **`settings` and `trending`:** old 2023 branches. Leave them alone.

## Next
1. Get mai-coordinator's grant to merge `feat/review-fixes` into `develop` (a fast-forward from 378b295).
2. After the merge, ask Mike before pushing `develop`. His 2026-11-19 yes covered that one push only.
3. Remove the worktree once it is merged: `git -C ~/Plugins/mai-analytics worktree remove ~/Plugins/_wip/analytics-review-fixes`.
4. Release only when Mike asks, after the whole stack passes. No tags and no tag talk before then.

## Blocked / waiting on
- mai-coordinator's merge grant. Its files (`STATE.md`, `SITE-LOCK.md`, `ALIGNMENT.md`) are in the shared production-copy site's folder; find it with `ls ~/Herd/*/SITE-LOCK.md`. Never edit them.

## Verify
- Before any suite, read the Status section of that folder's `SITE-LOCK.md`. Don't run during a claimed device run, or with `uptime` showing a 1-minute load of 10 or more. Never use the site itself without a claim.
- Run the suite in a worktree after `composer install`: `WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit`. 298 tests.
- A reboot clears `/tmp`. If `/tmp/wordpress-tests-lib/includes/functions.php` is missing, run `bin/install-wp-tests.sh wp_maianalytics_tests root '' localhost latest true` from the worktree.
- Then restore `vendor/`: `composer install --no-dev`, `git checkout -- vendor/composer/installed.json vendor/composer/installed.php`, then `php ~/Plugins/mai-analytics/vendor/bin/deployable-guard check --root=<path relative to the main checkout>`. It must print "OK: committed autoloader is deployable as-is."
- Commit no `vendor/` change on a feature branch.

## Draft changelog, for `CHANGES.md` on the release commit only
* Changed: [Developers] REST errors now carry a mai_analytics_* code and data.status. View errors keep "success": false and admin errors keep "message".
* Changed: [Developers] Four error statuses changed: Sync Now answers 501 for a setup problem and 502 for a provider failure (was 500), Warm answers 501 mai_analytics_provider_required in self-hosted mode (was 400) and 502 for a provider failure (was 500), and a view that fails to save answers 503 with Retry-After (was 200).
* Changed: [Developers] Core's argument errors answer 400 mai_analytics_invalid_request, after the permission check, so a signed-out request still gets 401.
* Changed: [Developers] ID lists (trending terms, the dashboard's term and author filters) take at most 100 IDs.
* Changed: [Developers] The dashboard search takes page and per_page and sends X-WP-Total and X-WP-TotalPages.
* Changed: [Developers] Warm refuses a negative cursor, total_updated or total_iterated with 400 instead of turning it positive.
* Changed: [Developers] A database failure while checking an author, reading trending or running the dashboard search answers 503 instead of a 404 or an empty list.
* Changed: Views of an author archive for someone with no published posts are no longer counted, and their count is no longer public, so the counts can't be used to find which user IDs exist.
* Changed: Which posts, terms and archives are counted now follows WordPress's own "publicly viewable" rule, which only differs from before for post types, taxonomies or statuses registered with unusual settings. Attachment pages are still not counted.
* Changed: The plugin header now says it needs WordPress 6.1, which it already did.
* Fixed: View counts for drafts, private posts and other hidden items could be read by anyone. They now answer 404, and trending no longer lists them.
* Fixed: A view that failed to save was reported as saved.
* Fixed: A sync that could not read new views skipped them. They are now counted on the next run.
* Fixed: Moving views from an old table could drop that table after a failed copy.
* Fixed: A failed change to the views table during an update was reported as success.
* Fixed: `wp mai-analytics health` exited 0 when a check failed, and `--fix` said the cron was rescheduled even when it wasn't.
* Fixed: Deactivating left three scheduled tasks behind, and uninstalling left two.
* Fixed: The database version could be recorded when the table had not been created.
* Fixed: Sync Now answered a server error for a setup problem.
* Fixed: The first page load after activation could print a "translation loading triggered too early" notice.

## Gotchas
- The shared production-copy site loads the main checkout through a symlink. Keep it on `develop` with docs-only commits, and do code work in a worktree on a feature branch.
- Mai Publisher bundles this plugin, and its releases are David's. A site running only the bundled copy never gets the activation, deactivation or uninstall fixes.
- Every `mai_analytics_*` code string lives in `src/ErrorCode.php`, except `{slug}_invalid_request`, which `RestErrors` builds. `tests/test-error-codes.php` pins them all as literals.
- maiexpowp switches views off on a 404 `rest_no_route`, so plugin code must never answer that code.
- The WP test library turns `CREATE` and `DROP TABLE` into `TEMPORARY` forms before a test's own `query` filter sees them, and restores hooks after every test. Make queries fail with a `query` filter.
- wpdb records every failed query in `$EZSQL_ERROR`, even with errors suppressed. dbDelta()'s DESCRIBE probe fails on a fresh install, and `create_table()` leaves it out on purpose.
- The commit hook refuses private site and client names, so write "the shared production-copy site".

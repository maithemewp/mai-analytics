# State
Updated: 2026-11-20 by Claude (mai-analytics e2e session)

## Now
- `bin/e2e.js` ran fully green on the shared production-copy site on 2026-11-20: `feat/e2e` 69dfa56 against develop 24d8ab5, 237 checks, no retries, teardown clean. Earlier runs found only harness bugs, no plugin failure. Logs and saved option rows are in the coordinator's shared notes folder.
- `fix/provider-unreached`, in `~/Plugins/_wip/analytics-unreached`, cut from `feat/e2e` 69dfa56. It holds the stopped-sync fix ruled by the coordinator:
  - The provider sync prunes web rows past retention, not only app rows.
  - Every object a run did not sync goes in the option `mai_analytics_provider_unreached`: the objects of a batch the provider failed, and those a stopped run never reached. The next sync takes up to 500 of them first, whether or not they were viewed again. An entry leaves only when its sync succeeded or the object is gone. A failed read stops the run with nothing changed. Writes are read back and tried twice.
  - Health shows how many items are on the list ("Items waiting for the provider"): pass when none, warn with the count, fail when the list can't be read. Uninstall deletes the option, and deactivation keeps it.
  - The unreached items' web rows stay and keep counting toward trending until retention (mai-coordinator, 2026-11-20).
  - When items are listed after a failed batch, the catchup is booked for after the provider backoff, as the breaker path does (mai-coordinator, 2026-11-20).
  - 367 unit tests pass. The e2e ran GREEN on the shared production-copy site at 01af9d8 by symlink swap (2026-11-20): 242 checks, no retries. The catchup alone synced the 101 listed posts and left the list empty.
- Branches (`origin` is GitHub maithemewp/mai-analytics):
  - **`develop`:** the main checkout `~/Plugins/mai-analytics`, which the shared production-copy site loads. On origin at 24d8ab5: the review-pr fixes and the filters notice.
  - **`feat/e2e`:** the e2e script, at 69dfa56. Don't delete the worktree or the branch.
  - **`fix/provider-unreached`:** the stopped-sync fix, on top of `feat/e2e`.
  - **`feat/filters-notice`:** merged into develop.
  - **`main`:** bf3ba96, Release 1.3.7. **`settings`** and **`trending`:** old 2023 branches. Leave them alone.

## Next
1. Merge `feat/e2e` and `fix/provider-unreached` into `develop` only on the coordinator's grant.
2. Release only when Mike asks, after the whole stack passes. No tags and no tag talk before then. The draft changelog below goes into `CHANGES.md` on that commit.

## Blocked / waiting on
- The coordinator's merge of `feat/e2e` and `fix/provider-unreached` into develop. mai-coordinator's files (`STATE.md`, `SITE-LOCK.md`, `ALIGNMENT.md`) are in the shared production-copy site's folder; find it with `ls ~/Herd/*/SITE-LOCK.md`. Never edit them.

## Verify
- Before any suite, read the Status section of that folder's `SITE-LOCK.md`. Don't run during a claimed device run. The load-under-10 rule is for device runs only (mai-coordinator, 2026-11-19), so a suite may run under load; rerun a timing-sensitive failure alone before calling it real. Never use the site itself without a claim.
- Run the suite in a worktree after `composer install`: `WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit`. 367 tests on `fix/provider-unreached`.
- The e2e, under a claim only: `MAI_AUTH_SITE_OK=1 NODE_PATH=~/node_modules node bin/e2e.js --base https://example.test --wp /path/to/wordpress`. The README's Testing section says what it covers and what it leaves out.
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
* Changed: [Developers] A database failure while checking an author, reading trending, or reading any dashboard table, filter or search answers 503 instead of a 404, an empty list or zeros.
* Changed: [Developers] Sync Now answers 429 mai_analytics_busy when a sync is already running and 503 when it could not read the views, instead of saying the sync is complete. `wp mai-analytics sync` exits 1 in both cases.
* Changed: [Developers] Trending with a taxonomy that is unknown or not publicly viewable answers an empty list.
* Changed: Views of an author archive for someone with no published posts are no longer counted, and their count is no longer public, so the counts can't be used to find which user IDs exist.
* Changed: Which posts, terms and archives are counted now follows WordPress's own "publicly viewable" rule, which only differs from before for post types, taxonomies or statuses registered with unusual settings. Attachment pages are still not counted.
* Changed: The plugin header now says it needs WordPress 6.1, which it already did.
* Fixed: View counts for drafts, private posts and other hidden items could be read by anyone. They now answer 404, and trending no longer lists them.
* Fixed: A view that failed to save was reported as saved.
* Fixed: A sync that could not read new views skipped them, in both modes. They are now counted on the next run.
* Fixed: A failed trending read during a sync cleared trending counts.
* Fixed: In provider mode, a view of one post type archive kept every other archive from syncing until the next run.
* Fixed: A provider sync could count an app view twice when it was saved during a long run, and lost the app views of pages it had not reached when it stopped early.
* Fixed: A provider sync that stopped early, or whose provider failed for a batch, left those pages without their provider counts until each was viewed again. They are now listed, and the next sync fetches them. Their queued rows also stayed in the views table for good, and are now removed after the retention period.
* Fixed: The provider sync's catchup event failed with a fatal error each time it fired, so a sync that stopped early was not continued until the next scheduled sync.
* Fixed: Moving views from an old table could drop that table after a failed copy, and the move failed on WordPress 6.1.
* Fixed: A failed change to the views table during an update was reported as success.
* Added: A health check that fails while a views table from an older version is still waiting to be moved.
* Added: The dashboard says so when its filters can't load, instead of leaving the dropdowns empty.
* Fixed: `wp mai-analytics health` exited 0 when a check failed, and `--fix` said the cron was rescheduled even when it wasn't.
* Fixed: Deactivating left three scheduled tasks behind, and uninstalling left two.
* Fixed: The database version could be recorded when the table had not been created.
* Fixed: Sync Now answered a server error for a setup problem.
* Fixed: The first page load after activation could print a "translation loading triggered too early" notice.

## Gotchas
- The tracker is off on a non-production site. The e2e turns it on with a must-use file, only for requests carrying its run's cookie.
- The shared production-copy site sets WP_HTTP_BLOCK_EXTERNAL. Core always lets `localhost` through (`wp-includes/class-wp-http.php`, `block_request()`), so the fake Matomo URL must use `localhost`, not `127.0.0.1`.
- A view saved in the same second a sync started falls on its boundary and is skipped by the next sync (`viewed_at > boundary`). The e2e waits a second before such a view.
- The shared production-copy site loads the main checkout through a symlink. Keep it on `develop` with docs-only commits, and do code work in a worktree on a feature branch.
- Mai Publisher bundles this plugin, and its releases are David's. A site running only the bundled copy never gets the activation, deactivation or uninstall fixes.
- Every `mai_analytics_*` code string lives in `src/ErrorCode.php`, except `{slug}_invalid_request`, which `RestErrors` builds. `tests/test-error-codes.php` pins them all as literals.
- maiexpowp switches views off on a 404 `rest_no_route`, so plugin code must never answer that code.
- The WP test library turns `CREATE` and `DROP TABLE` into `TEMPORARY` forms before a test's own `query` filter sees them, and restores hooks after every test. Make queries fail with a `query` filter.
- wpdb records every failed query in `$EZSQL_ERROR`, even with errors suppressed. dbDelta()'s DESCRIBE probe fails on a fresh install, and `create_table()` leaves it out on purpose. Routes that run several queries count that list.
- `%i` in `prepare()` needs WordPress 6.2. `tests/test-plain-text.php` refuses it, because on 6.2 and later it builds the same query.
- The commit hook refuses private site and client names, so write "the shared production-copy site".

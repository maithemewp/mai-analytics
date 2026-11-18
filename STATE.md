# State
Updated: 2026-11-18 by Claude (mai-analytics build session)

## Now
The alignment pass is built on branch `feat/alignment` (worktree `~/Plugins/_wip/analytics-alignment`), plan Tasks 0 to 8, one commit each. The suite passes: 256 tests, against a develop baseline of 205. Nothing is merged, versioned or released. Version stays 1.3.7.

Draft changelog lines, for `CHANGES.md` on the release commit only, with its version and date:

* Changed: [Developers] REST errors now carry a mai_analytics_* code and data.status. View errors keep "success": false and admin errors keep "message".
* Changed: [Developers] Four error statuses changed: Sync Now answers 501 for a setup problem and 502 for a provider failure (was 500), Warm answers 501 in self-hosted mode (was 400) and 502 for a provider failure (was 500), and a view that fails to save answers 503 with Retry-After (was 200).
* Changed: [Developers] Core's argument errors answer 400 mai_analytics_invalid_request, after the permission check, so a signed-out request still gets 401.
* Changed: [Developers] ID lists (trending terms, the dashboard's term and author filters) take at most 100 IDs.
* Changed: [Developers] The dashboard search takes page and per_page and sends X-WP-Total and X-WP-TotalPages.
* Changed: [Developers] Warm refuses a negative cursor, total_updated or total_iterated with 400 instead of turning it positive.
* Changed: Views of an author archive for someone with no published posts are no longer counted, and their count is no longer public, so the counts can't be used to find which user IDs exist.
* Changed: Which posts, terms and archives are counted now follows WordPress's own "publicly viewable" rule, which only differs from before for post types, taxonomies or statuses registered with unusual settings.
* Fixed: View counts for drafts, private posts and other hidden items could be read by anyone. They now answer 404, and trending no longer lists them.
* Fixed: A view that failed to save was reported as saved.
* Fixed: Deactivating left three scheduled tasks behind, and uninstalling left two.
* Fixed: The database version could be recorded when the table had not been created.
* Fixed: Sync Now answered a server error for a setup problem.

## Next
1. mai-coordinator reviews the branch and grants the merge to `develop`.
2. Mike walks the code names. A rename changes only the `ErrorCode` case values.
3. Mike decides the point release after the whole stack passes its tests.

## Blocked / waiting on
- mai-coordinator's review and merge grant.

## Verify
- `WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit` after `composer install`. Read the Status of the shared production-copy site's `SITE-LOCK.md` and `uptime` first. Never run it during a device run or with the 1-minute load at 10 or more.
- Afterwards put `vendor/` back with `composer install --no-dev`, then `git checkout -- vendor/composer/installed.json vendor/composer/installed.php`, because the committed copies were written by a dev install. Then `php vendor/bin/deployable-guard check --root=.`.

## Vendor: dev install against no-dev install
Checked 2026-11-18 on this branch. `.gitignore` tracks only `vendor/autoload.php`, `vendor/composer/`, `vendor/maithemewp/` and `vendor/yahnis-elsts/`.

- **Committed today:** the four autoloader files (`vendor/composer/autoload_static.php`, `autoload_psr4.php`, `autoload_classmap.php`, `autoload_files.php`) are production-only, with no dev package in them. `vendor/composer/installed.php` and `installed.json` are dev-install metadata: `'dev' => true` and every dev package listed (phpunit, yoast polyfills, deployable-guard, sebastian/*). These two are Composer's install record, read by `Composer\InstalledVersions`, not by class loading.
- **`composer install` (with dev)** rewrites all four autoloader files to add the dev packages, and changes `installed.php` (its reference hash). That is the state that must never be committed.
- **`composer install --no-dev`** leaves the four autoloader files equal to the committed ones, and rewrites `installed.php` and `installed.json` to the production record (`'dev' => false`, no dev packages).

What a release must do:
1. Run `composer install --no-dev` in the release checkout.
2. Run `git status --short vendor`. Only `vendor/composer/installed.php` and `installed.json` may show, and only until the TODO decision below is made. Any `autoload_*.php` in that list means a dev autoloader. Stop.
3. Run `php vendor/bin/deployable-guard check --root=.` from a checkout that still has the guard (the no-dev install removes it from this one). It must print "OK: committed autoloader is deployable as-is."
4. Commit the no-dev `vendor/composer/installed.php` and `installed.json` on the release commit. Decided by mai-coordinator, 2026-11-18: keep both tracked, in their no-dev form. On a feature branch, restore them with `git checkout -- vendor/composer/installed.json vendor/composer/installed.php` and commit no `vendor/` change.

Why `installed.php` stays tracked: mai-package-loader, which loads the bundled mai-logger, reads it at runtime (`vendor/maithemewp/mai-package-loader/init.php:603`). It takes each `maithemewp/*` copy and its install path from the record (`:647-654`), and a newer copy of the loader's version from it too (`:660-664`). Without the file the loader still works: it lists `vendor/maithemewp/*/` on disk instead (`:605-628`). But then it can only see a newer loader copy that ships `takeover.php`, and misses any package installed outside the default path.

## Gotchas
- For Tasks 1 to 4 and 6 to 8 the code was written before the tests ran, because a device run on the shared site blocked every suite for about 50 minutes. Task 5 went red, then green. All 256 tests pass at 7b1270b.
- The shared production-copy site loads the main checkout. Keep it on `develop` with docs-only commits. Code work goes in a worktree on a feature branch.
- Mai Publisher bundles this plugin. A site running only the bundled copy gets fixes when Mai Publisher ships a new bundled version, and never gets activation, deactivation or uninstall fixes. `Plugin::deactivate()` is public so Mai Publisher can call it.
- Every `mai_analytics_*` code string lives only in `src/ErrorCode.php`. Tests use `ErrorCode::X->value`.
- maiexpowp switches views off on 404 `rest_no_route`, so never answer that code from plugin code.
- The WordPress test library turns `CREATE` and `DROP TABLE` in a test into `TEMPORARY` forms, and restores hooks after every test. Fail queries with a `query` filter, and never guard a hook with a static "already added" flag.
- `register_post_status()` makes a status internal unless one of `public`, `internal`, `protected` or `private` is given (`wp-includes/post.php:1486-1488`), so a status with only `publicly_queryable` is not viewable.

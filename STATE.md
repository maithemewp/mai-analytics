# State
Updated: 2026-11-19 by Claude (mai-analytics build session, handing over to a fresh session)

## Now
- The 2026-11-18 alignment pass is built, reviewed and merged into local `develop`. The suite passes with 267 tests; the develop baseline was 205. Version stays 1.3.7, and nothing is versioned or released.
- A five-agent review of the unpushed commits found nothing critical. Mike walked it on 2026-11-19. Its fixes are planned and **not built**.
- Mike's decisions from the walk:
  - The 404 stays `mai_analytics_not_found`, because the whole stack moves to `{slug}_not_found` for 404 and keeps `*_invalid_*` for 400.
  - `provider_disabled` becomes `mai_analytics_provider_required`.
  - The older v1.3.7 issues get fixed in the same branch.
- Branches (`origin` is GitHub maithemewp/mai-analytics):
  - **`develop`:** the main checkout `~/Plugins/mai-analytics`, pushed to `origin/develop` on 2026-11-19 with Mike's yes. Its head is the commit that wrote this file.
  - **`main`:** bf3ba96, Release 1.3.7, on origin.
  - **Merged into `develop` and on origin:** `feat/alignment` e52b825, `fix/cron-label-translation` 7aeb681, `feat/view-stats-lifecycle`, `fix/provider-null-contract` and `fix/site-kit-owner-bound-client`.
  - **`settings` and `trending`:** old local-only branches from 2023, not part of this work. Leave them alone.
  - There are no worktrees. `feat/review-fixes` doesn't exist yet.

## Next
1. Build the review fixes. Paste `docs/plans/2026-11-19-review-fixes-build-prompt.md` into a fresh session. It builds `docs/plans/2026-11-19-review-fixes.md` in a worktree on `feat/review-fixes`.
2. Get the coordinator's grant (through Mike) to merge `feat/review-fixes` into `develop`.
3. Release only when Mike asks, after the whole stack passes. There are no tags and no tag talk before then.

To catch up, read these in order: `AGENTS.md`, `~/LocalPackages/maiexpowp/CONVENTIONS.md`, `docs/specs/2026-11-18-alignment.md`, `docs/plans/2026-11-18-alignment.md`, `docs/plans/2026-11-19-review-fixes.md`.

## Blocked / waiting on
- The coordinator's grant for any merge into `develop`. The coordinator keeps its files (`STATE.md`, `SITE-LOCK.md` and `ALIGNMENT.md`) in the shared production-copy site's folder. Never edit them. If you can't message the coordinator, ask Mike to relay.

## Verify
- Before any suite, read the Status section of that folder's `SITE-LOCK.md`. Don't run during a claimed device run, or with `uptime` showing a 1-minute load of 10 or more. Never use the site itself without a claim.
- Run the suite in a worktree after `composer install`: `WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit`.
- Then restore `vendor/`:
  1. `composer install --no-dev`
  2. `git checkout -- vendor/composer/installed.json vendor/composer/installed.php`
  3. `php vendor/bin/deployable-guard check --root=.`, from a checkout that still has the guard. It must print "OK: committed autoloader is deployable as-is."
- Commit no `vendor/` change on a feature branch. A release commits the no-dev `installed.php` and `installed.json`, which mai-package-loader reads at runtime (`vendor/maithemewp/mai-package-loader/init.php:603`).

## Draft changelog, for `CHANGES.md` on the release commit only
* Changed: [Developers] REST errors now carry a mai_analytics_* code and data.status. View errors keep "success": false and admin errors keep "message".
* Changed: [Developers] Four error statuses changed: Sync Now answers 501 for a setup problem and 502 for a provider failure (was 500), Warm answers 501 in self-hosted mode (was 400) and 502 for a provider failure (was 500), and a view that fails to save answers 503 with Retry-After (was 200).
* Changed: [Developers] Core's argument errors answer 400 mai_analytics_invalid_request, after the permission check, so a signed-out request still gets 401.
* Changed: [Developers] ID lists (trending terms, the dashboard's term and author filters) take at most 100 IDs.
* Changed: [Developers] The dashboard search takes page and per_page and sends X-WP-Total and X-WP-TotalPages.
* Changed: [Developers] Warm refuses a negative cursor, total_updated or total_iterated with 400 instead of turning it positive.
* Changed: Views of an author archive for someone with no published posts are no longer counted, and their count is no longer public, so the counts can't be used to find which user IDs exist.
* Changed: Which posts, terms and archives are counted now follows WordPress's own "publicly viewable" rule, which only differs from before for post types, taxonomies or statuses registered with unusual settings. Attachment pages are still not counted.
* Fixed: View counts for drafts, private posts and other hidden items could be read by anyone. They now answer 404, and trending no longer lists them.
* Fixed: A view that failed to save was reported as saved.
* Fixed: Deactivating left three scheduled tasks behind, and uninstalling left two.
* Fixed: The database version could be recorded when the table had not been created.
* Fixed: Sync Now answered a server error for a setup problem.
* Fixed: The first page load after activation could print a "translation loading triggered too early" notice.
* The review fixes will add lines of their own.

## Gotchas
- The shared production-copy site loads this checkout through a symlink. Keep it on `develop` with docs-only commits, and do code work in a worktree on a feature branch.
- Mai Publisher bundles this plugin, and its releases are David's. A site running only the bundled copy never gets the activation, deactivation or uninstall fixes. `Plugin::deactivate()` is public so Mai Publisher can call it.
- Every `mai_analytics_*` code string lives only in `src/ErrorCode.php`, apart from `{slug}_invalid_request`, which `RestErrors` builds.
- maiexpowp switches views off on a 404 `rest_no_route`, so plugin code must never answer that code.
- The WP test library turns `CREATE` and `DROP TABLE` into `TEMPORARY` forms and restores hooks after every test. Make queries fail with a `query` filter, and never guard a hook with a static flag.
- `register_post_status()` makes a status internal unless it is given `public`, `internal`, `protected` or `private` (`wp-includes/post.php:1486-1488`).
- The commit hook refuses private site and client names, so write "the shared production-copy site".

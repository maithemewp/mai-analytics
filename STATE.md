# State
Updated: 2026-11-18 by Claude (mai-analytics session)

## Now
Version 1.3.7 is released and live. The alignment spec `docs/specs/2026-11-18-alignment.md` is checked by mai-coordinator, which decided four of its five questions: 404 for hidden items, the new error bodies and four status changes, 503 for a failed view insert, and paging on `/admin/search`. The plan is `docs/plans/2026-11-18-alignment.md`. Nothing is built.

## Next
1. mai-coordinator reviews the plan.
2. Mike answers the error code names in his walk with mai-coordinator. A rename touches only `ErrorCode`.
3. Mike gets a build prompt for a fresh session, which builds in the worktree `~/Plugins/_wip/analytics-alignment` on branch `feat/alignment`.

## Blocked / waiting on
- mai-coordinator's review of the plan.
- Mike's answer on the code names.

## Verify
- `WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit` after `composer install`. Read the shared production-copy site's `SITE-LOCK.md` first, and never run it while another session has a device run claimed.
- `php vendor/bin/deployable-guard check --root=.` before any commit that touches `vendor/`, and put `vendor/` back with `composer install --no-dev` first.

## Gotchas
- The shared production-copy site loads this checkout. Keep it on `develop` with docs-only commits; code work goes in a worktree on a feature branch.
- Mai Publisher bundles this plugin. A site running only the bundled copy gets fixes when Mai Publisher ships a new bundled version, and never gets activation, deactivation or uninstall fixes.
- Core validates REST args before the permission callback, so a signed-out bad request gets a 400 unless the plugin maps it.
- maiexpowp switches views off on 404 `rest_no_route`, so never answer that code from plugin code.
- The WordPress test library turns `CREATE` and `DROP TABLE` in a test into `TEMPORARY` forms, so fail queries with a `query` filter, not by dropping the table.

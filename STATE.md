# State
Updated: 2026-11-18 by Claude (mai-analytics session)

## Now
Version 1.3.7 is released and live. The alignment spec `docs/specs/2026-11-18-alignment.md` and plan `docs/plans/2026-11-18-alignment.md` are done. An independent reviewer checked the plan against the code and core, and every finding was fixed. mai-coordinator decided every open question, all open to Mike: 404 for hidden items, a user visible only with a published post, core's viewable helpers, the new error bodies, 501 for setup problems and 502 for provider failures, 503 for a failed view insert, paging on `/admin/search`, and the code names. Nothing is built.

## Next
1. Mike pastes the build prompt into a fresh session, which builds in the worktree `~/Plugins/_wip/analytics-alignment` on branch `feat/alignment` and stops at the plan's Task 9 gate.
2. mai-coordinator grants the merge to `develop` and any use of the shared production-copy site.
3. Mike decides the point release after the whole stack passes its tests.

## Blocked / waiting on
- Mike starting the build session.

## Verify
- `WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit` after `composer install`. Read the shared production-copy site's `SITE-LOCK.md` first, and never run it while another session has a device run claimed.
- `php vendor/bin/deployable-guard check --root=.` before any commit that touches `vendor/`, and put `vendor/` back with `composer install --no-dev` first.

## Gotchas
- The shared production-copy site loads this checkout. Keep it on `develop` with docs-only commits. Code work goes in a worktree on a feature branch.
- Mai Publisher bundles this plugin. A site running only the bundled copy gets fixes when Mai Publisher ships a new bundled version, and never gets activation, deactivation or uninstall fixes.
- Core validates REST args before the permission callback, so a signed-out bad request gets a 400 unless the plugin maps it.
- maiexpowp switches views off on 404 `rest_no_route`, so never answer that code from plugin code.
- The WordPress test library turns `CREATE` and `DROP TABLE` in a test into `TEMPORARY` forms, and restores hooks after every test. Fail queries with a `query` filter, and never guard a hook with a static "already added" flag.
- A real activation loads the plugin after `plugins_loaded`, so `Plugin::init()` hasn't run. Activation code must register anything it needs itself, like the 15-minute cron schedule.

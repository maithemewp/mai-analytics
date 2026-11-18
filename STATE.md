# State
Updated: 2026-11-18 by Claude (mai-analytics session)

## Now
Version 1.3.7 is released and live. The alignment spec is written: `docs/specs/2026-11-18-alignment.md`. It covers cron cleanup, 100-ID limits, the db version check, the hidden-item count leak, quiet limits, em-dashes and plugin error codes, with the bugs that are not alignment marked in or out. Nothing is built.

## Next
1. mai-coordinator checks the spec.
2. Mike walks its "Questions for Mike", one at a time, when mai-coordinator says so.
3. Write the plan in `docs/plans/`, review it, then hand Mike a build prompt for a fresh session working in a worktree.

## Blocked / waiting on
- mai-coordinator's check of the spec.
- Mike's answers, first of all 404 or zeros for items a caller may not see.

## Verify
- `composer test` runs PHPUnit. Read the shared production-copy site's `SITE-LOCK.md` first, and never run it while another session has a device run claimed.
- `php vendor/bin/deployable-guard check --root=.` before any commit that touches `vendor/`.

## Gotchas
- The shared test site loads this checkout. Keep it on `develop` with docs-only commits; code work goes in a worktree on a feature branch.
- Mai Publisher bundles this plugin, so a release reaches sites two ways, and the bundled copy's activation hooks never fire.
- Core validates REST args before the permission callback, so a signed-out bad request gets a 400 unless the plugin maps it.
- maiexpowp switches views off on 404 `rest_no_route`, so never answer that code from plugin code.

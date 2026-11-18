# Mai Analytics

A WordPress plugin that counts views of posts, terms, authors and post type archives, from its own beacon or from Site Kit, Matomo or Jetpack, and stores the totals in meta. Released and live on client sites. `README.md` covers how it works; `CHANGES.md` is the changelog.

## Shared rules for the mai-auth stack

mai-auth, mai-collections, mai-reactions, mai-analytics, maiexpowp and the apps share one set of conventions in `~/LocalPackages/maiexpowp/CONVENTIONS.md`. Read it before changing anything a person sees or calls. The 2026-11-18 alignment pass for this plugin is `docs/specs/2026-11-18-alignment.md`.

## Released, so nothing exposed is renamed

No filter, option, meta key, route, param or response field is renamed or removed. Changes only tighten or add. The short meta keys `mai_views`, `mai_views_web`, `mai_views_app`, `mai_trending` and `mai_views_synced_at` stay, by Mike's choice and for Mai Publisher, which reads them.

## Who depends on it

- Mai Publisher bundles this plugin through Composer and loads its copy when no standalone copy is active (`mai-publisher.php:128-132`). Its `/v1/seller` endpoint reads `Settings::get_reporting_snapshot()`. Mai Publisher's releases are David's: never tag or touch its `main`.
- The app counts views through maiexpowp's `useView`, `POST /view/{type}/{id}` with `source=app`, and treats 404 `rest_no_route` as the feature being off.
- The website beacon and the admin screens in this repo.
- Not maiexpowp-plugin. It is kept only for one older live app, gets bug and security fixes only, and is removed once that app is rebuilt on mai-auth. mai-analytics never accounts for it.

## Layout

- PHP in `src/` (PSR-4 `Mai\Analytics\`), global helpers in `includes/functions.php`, admin JS in `assets/js/`.
- `Requires PHP: 8.1`. Raising it is Mike's decision.
- `vendor/` is committed, production only, checked by deployable-guard.

## Writing code here

- Thorough PHPDoc with `@param`, a blank line, then `@return`. Add `@since x.y.z` to new classes, methods and hooks.
- Prefer `WP_Query` with explicit flags or direct SQL over `get_posts()`.
- Check `$wpdb->last_error` after a query that can fail quietly.
- No em-dashes in any string a person reads, logs included.

## Tool calls

Mike gets approval prompts for anything the permission check can't read, so (Mike, through mai-coordinator, 2026-11-18):

- Edit files with the Read, Edit and Write tools, never with python, sed or heredocs in Bash.
- Keep each Bash call to one plain command with full paths: no `&&` chains, no heredocs, no shell variables, no `$(...)`.
- Commit with `git -C <full path> commit -q -m "..."` (one `-m` per paragraph), and push as its own call.

## Names

Don't guess a name when WordPress already names the thing another way (Mike, 2026-11-18: "Similar to how we went from cap to max"). Before proposing a name for a hook, arg, error code, function or option, check what core calls the same thing and cite the file and line. Use core's word, or say there is none.

## Waiting on Mike

When work is waiting on Mike, end the turn with the `wrap_up` tool carrying one question in `ask`, one or two buttons in `actions`, and `blocked: true`, so the session shows as waiting on him instead of sitting idle. Don't set `blocked` when waiting on another session.

## Releases

- The changelog is `CHANGES.md`: `## X.Y.Z (M/D/YY)` and bulleted `* Added:` / `* Changed:` / `* Fixed:` entries.
- The version lives in two places in `mai-analytics.php`: the header and `MAI_ANALYTICS_VERSION`.
- Point releases only, and only when asked. Tags are `vX.Y.Z`, and the plugin updater ships from `main`.
- Feature branches may be pushed without asking (Mike's rule, through mai-coordinator, 2026-11-18). Never push, merge into or tag `main` without Mike's yes, and no tag questions until the whole stack passes its tests. While the alignment pass runs, merging into `develop` needs mai-coordinator's grant, because the shared production-copy site loads it.

## Shared test site

The shared production-copy site loads this checkout through a symlink. Keep this checkout on `develop`, and do code work in a git worktree on a feature branch. Read the Status section of that site's `SITE-LOCK.md` before any test suite, benchmark or build, and never use the site without a claim from `mai-coordinator`.

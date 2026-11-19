# Review fixes before 1.3.8

Made from the five-agent review of the 25 unpushed `develop` commits (head 90c29c7), walked with Mike on 2026-11-19. Build it in a worktree on `feat/review-fixes` cut from local `develop`. Each task is one commit, with a failing test first where a test can show the bug.

## Decided by Mike

- **404 code stays `mai_analytics_not_found`.** The whole stack moves to `{slug}_not_found` for 404, missing or hidden alike, and `*_invalid_*` is always 400 (except `invalid_token` at 401). mai-coordinator updates `CONVENTIONS.md` and the other plugins. No change here.
- **Rename `ErrorCode::ProviderDisabled` to `mai_analytics_provider_required`.** It is the 501 Warm sends when `data_source` is `self_hosted` (`src/AdminRestApi.php:942-943`). It is unreleased: v1.3.7 sent 400 with no code. "Disabled" would read as `data_source = disabled`, a different real state (`src/Tracker.php:55`). Rename the case to `ProviderRequired` too. Update the tests and the STATE.md draft changelog line if it names the code.
- **Fix the older issues in this branch too**, since all are small (Task 8).

## Tasks

1. **User visibility query** (`RestApi::users_with_viewable_posts()`, around `src/RestApi.php:595-609`).
   - Check `$wpdb->last_error` after the query. On error, answer 503 `mai_analytics_database_error` through the existing helper instead of treating the user as hidden (404) or dropping them from trending.
   - Stop at the first match: `EXISTS ( ... LIMIT 1 )` per user, or the equivalent, instead of `SELECT DISTINCT` over every post the author has.
   - Tests: a failing query (use a `query` filter) gives 503 on POST and GET `/view(s)/user`; trending for users with a failing query gives 503, not `[]`.
2. **Trending reads** (`RestApi::get_trending()`, around `src/RestApi.php:316-344`). Check `last_error` after each query and answer 503. Guard the `array_values()` call so a `null` result can't TypeError. Same for the admin search count query (`src/AdminRestApi.php:826`): on error answer 503 rather than sending `X-WP-Total: 0`. Tests for each.
3. **`RestErrors` drift.** Match mai-items-core's `RestErrors` (it wraps the permission callback in try/catch `Throwable` and refuses with 403, `:93-114` in that library). Read the library copy and bring ours in line, including the two smaller differences the review saw. Fix the header comment so it is true. Test: a permission callback that throws gets 403.
4. **`ErrorCode` cleanup.** Remove the unused `ErrorCode::error()`. Fix the `src/ErrorCode.php:13` comment: `RestErrors` builds `{slug}_invalid_request` from the slug, so say so. Add one data-provider test that pins every code as a literal string with its status, so a rename can't pass silently.
5. **Comments and README.**
   - `src/RestApi.php:630-631`: `_prime_post_caches()` still primes meta and terms for cached posts (core `post.php:8502-8524`). Rewrite the comment to say what the code really relies on.
   - "Public" wording in `validate_object()` and `src/Tracker.php:76`: say "publicly viewable".
   - README line 8 ("all public post types") and line 253 (provider error is an option, not a transient). Recheck "No meta reads".
   - Add a short README section on the REST routes and their error codes and statuses.
6. **Test gaps.** Search `page=2` headers; `Cron::HOOKS` against a fixed list of hook names; the tracker's archive and term rules; trending for post type archives drops hidden types.
7. **The `provider_required` rename** from the decision above.
8. **Older issues, already in v1.3.7:**
   - `Database::maybe_migrate_old_table()` (`src/Database.php:144-148`): drop the old table only when the `INSERT ... SELECT` succeeded. On failure, log it and don't set `mai_analytics_table_migrated`.
   - `Sync::sync()` (`src/Sync.php:29-31`, `:165`): it writes `$started_at` at the start. If the new views query fails (`last_error`), put `$last_sync` back, log it, release the lock and return, so those views are counted next run.
   - `CLI::health --fix` (`src/CLI.php:84-87`): check what `wp_schedule_event()` returns and say "could not reschedule" when it fails.
   - `CLI::health` (`src/CLI.php:93-94`): `WP_CLI::error( $summary, false )` doesn't exit. Make a failed check exit 1. Verify how WP-CLI's `error()` behaves first, from the phar or its source, and cite the line.
   - `Database::create_table()`: check `$wpdb->last_error` after `dbDelta()` so a failed `ALTER` on an existing table isn't reported as success. `Plugin::activate()` should log when `create_table()` returns false.
   - Add `Requires at least: 6.1` to the plugin header, since `is_post_publicly_viewable()` needs it. That records a floor the code already has; it doesn't raise one.
   - Not doing: making `maybe_update()` check that the table exists on every request. That costs a query on every page load, and `health --fix` already rebuilds it.

## Verify

Suite: `WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit` from the worktree, after `composer install`. 267 tests pass at 90c29c7. Then restore `vendor/` (see STATE.md Verify) and run `php vendor/bin/deployable-guard check --root=.` before the no-dev install removes it.

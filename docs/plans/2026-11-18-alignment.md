# mai-analytics Alignment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship mai-analytics' part of the 2026-11-18 alignment pass: cron cleanup, 100-ID limits, a checked db version, no counts for hidden items, honest limits, no em-dashes, and `mai_analytics_*` error codes with core's argument errors mapped after the permission check.

**Architecture:** Three new classes carry the error work: `ErrorCode` (an enum holding every code and its status), `Errors` (builds error responses that keep today's top-level fields) and `RestErrors` (maps core's argument errors, a copy of `Mai\Items\RestErrors`'s design). The view and admin routes switch their hand-built bodies to `Errors`. The cron, schema and uninstall fixes are local edits to `Cron`, `Plugin`, `Database` and `uninstall.php`.

**Tech Stack:** PHP 8.1, WordPress 7.1 REST API, PHPUnit 9.6 with the WordPress test library (`WP_UnitTestCase`), Tom Select in the admin dashboard.

**Spec:** `docs/specs/2026-11-18-alignment.md` (checked by mai-coordinator). Read it first. Every "today" line and file reference below comes from it.

## Global Constraints

- PHP floor stays 8.1 (`mai-analytics.php:8`). No 8.2-only syntax: no `readonly` classes, no DNF types, no constants in traits.
- `declare( strict_types=1 );` at the top of each new PHP file. Existing files are left as they are.
- Nothing exposed is renamed or removed: no filter, option, meta key, route, param or response field. Changes only tighten or add.
- An input over its limit is refused with 400, never clamped. An ID list takes at most 100 IDs.
- The error code names below are the spec's proposals. Mike may rename them in his walk with mai-coordinator, so every code string lives only in `ErrorCode`. No other file writes a `mai_analytics_*` code as a literal string, tests included (tests use `ErrorCode::X->value`).
- No em-dash (U+2014) in any string a person reads, logs included. Rewrite the sentence, don't swap in a semicolon or colon.
- New classes, methods and constants get thorough PHPDoc: `@since 1.3.8`, `@param` lines, a blank line, then `@return`. (1.3.8 is the next point release. Nothing in this plan bumps the version.)
- Never answer `rest_no_route` from plugin code. maiexpowp switches views off on it.
- No `vendor/` change is committed. `composer install` in the worktree adds dev packages and rewrites `vendor/composer/installed.php`; Task 9 puts it back.
- Every shell command writes paths out in full, with no shell variables. An environment variable in front of a command, such as `WP_TESTS_DIR=/tmp/wordpress-tests-lib`, is fine.
- Tests never `DROP` the buffer table to make something fail. The WordPress test library turns `CREATE TABLE` and `DROP TABLE` inside a test into their `TEMPORARY` forms (`_create_temporary_tables()` and `_drop_temporary_tables()` in `includes/abstract-testcase.php`; check them in `/tmp/wordpress-tests-lib` before writing these tests, this plan's author could not, the library was not installed), so a drop does not remove the real table and a later check still sees it. Fail a query with a `query` filter instead, as each task below says.
- Before running the test suite, a benchmark or a build, read the Status section of the shared production-copy site's `SITE-LOCK.md` (in the site's root, `~/Herd/` plus the site's folder). Do not start while another session has a device run claimed. Suites running during a device run pushed the machine's load to 145 on 2026-11-18 and broke that run. PHPUnit here uses its own test database, never the shared site.
- Two security checks every session was asked for on 2026-11-18 are folded into Task 3 (counts for hidden items) and Task 6 (unescaped `LIKE`). Fix what the spec covers, and list anything else in `TODO.md` and the report.

## Review Focus

- **A signed-out caller with a bad arg on an admin route** expects 401 `rest_forbidden`, not 400. Pinned in Task 1.
- **The app's `useView`** expects nothing it does today to change: a hidden or missing item, a bot and a database failure must never answer `rest_no_route`. Pinned in Task 2 by asserting the code on each.
- **The admin Warm button** reads `message` and `!res.ok` on errors, and the progress fields on success. A 502 must still carry `message`, `done`, `total_updated` and `total_iterated` at the top level. Pinned in Task 4.
- **A trending list where most viewed posts went private** still returns `per_page` visible rows when enough exist. Pinned in Task 3.
- **Uninstall run after the plugin's files are loaded in the same process** (the test suite) must not fatal on a redefined constant or function. Pinned in Task 6 by running `uninstall.php` through `include` in its own test.

---

### Task 0: Worktree and baseline

**Files:** none changed.

- [ ] **Step 1: Make the worktree.** This checkout stays on `develop`, because the shared production-copy site loads it.

```bash
git -C ~/Plugins/mai-analytics worktree add -b feat/alignment ~/Plugins/_wip/analytics-alignment develop
```

Expected: `Preparing worktree (new branch 'feat/alignment')`. Every later step runs in `~/Plugins/_wip/analytics-alignment`.

- [ ] **Step 2: Install dev dependencies there.**

```bash
composer install -d ~/Plugins/_wip/analytics-alignment
```

Expected: `vendor/bin/phpunit` exists. `git status --short` shows `vendor/composer/installed.php` and friends modified; that is expected until Task 9.

- [ ] **Step 3: Check the WordPress test library.** `/tmp/wordpress-tests-lib/includes/functions.php` must exist. If not, run `bin/install-wp-tests.sh` with a local test database name, user and password (it never touches the shared site), and say which database you made.

- [ ] **Step 3b: Prove how the test library treats `DROP TABLE`** before any task relies on it. Read `_create_temporary_tables()` and `_drop_temporary_tables()` in `/tmp/wordpress-tests-lib/includes/abstract-testcase.php` (`rg -n "temporary" /tmp/wordpress-tests-lib/includes/abstract-testcase.php`), and cite the lines in your report. If they do not exist or do not rewrite `DROP TABLE`, tell mai-coordinator before Task 2, since Tasks 2, 6 and 7 assume they do. The `query`-filter approach works either way, so keep it.

- [ ] **Step 4: Baseline run** (after the `SITE-LOCK.md` check).

```bash
WP_TESTS_DIR=/tmp/wordpress-tests-lib ~/Plugins/_wip/analytics-alignment/vendor/bin/phpunit -c ~/Plugins/_wip/analytics-alignment/phpunit.xml.dist
```

Expected: `OK`. Record the test count. If anything fails before you change code, stop and report it to mai-coordinator; do not fix unrelated tests in this plan.

---

### Task 1: Error codes and core's argument errors

**Files:**
- Create: `src/ErrorCode.php`, `src/Errors.php`, `src/RestErrors.php`
- Modify: `src/Plugin.php` (register the mapping)
- Test: `tests/test-rest-errors.php`

**Interfaces:**
- Produces:
  - `enum ErrorCode: string` in `Mai\Analytics`, with `status(): int`. Cases and statuses:
    - `InvalidRequest = 'mai_analytics_invalid_request'`, 400
    - `NotFound = 'mai_analytics_not_found'`, 404
    - `Bot = 'mai_analytics_bot'`, 403
    - `ProviderUnavailable = 'mai_analytics_provider_unavailable'`, 409
    - `ProviderModeRequired = 'mai_analytics_provider_mode_required'`, 409
    - `ProviderError = 'mai_analytics_provider_error'`, 502
    - `DatabaseError = 'mai_analytics_database_error'`, 503
  - `ErrorCode::error( string $message, array $data = [] ): \WP_Error`, data gets `status`, and `DatabaseError` also gets `retry_after` 1.
  - `final class Errors` with `public static function response( ErrorCode $code, string $message, array $data = [], array $legacy = [] ): \WP_REST_Response`. Body: `$legacy + [ 'code' => ..., 'message' => ..., 'data' => $data + [ 'status' => ... ] ]`, with the code's status. `DatabaseError` adds `data.retry_after` 1 and the header `Retry-After: 1`.
  - `final class RestErrors` with `public const CORE_CODES = [ 'rest_invalid_param', 'rest_missing_callback_param', 'rest_invalid_json', 'rest_invalid_params' ];`, `public static function map( string $namespace, string $slug ): void` (the library's signature, so the later swap is one line; called with `'mai-analytics/v1'` and `'mai_analytics'`, and the code it builds, `{$slug}_invalid_request`, must equal `ErrorCode::InvalidRequest->value`, which a test asserts) and the filter callback `public static function filter( mixed $response, array $handler, \WP_REST_Request $request ): mixed`.

- [ ] **Step 1: Write the failing tests** in `tests/test-rest-errors.php` (class `Test_REST_Errors`, same `setUp` and `tearDown` as `tests/test-rest-api.php`). The case list mirrors mai-items-core's error-mapping contract cases (spec, change 7, "Copied here"):
  - `test_signed_out_bad_arg_on_admin_route_gets_permission_error`: `GET /mai-analytics/v1/admin/top/posts?per_page=500` signed out answers 401, code `rest_forbidden`.
  - `test_subscriber_bad_arg_gets_403`: same request as a subscriber answers 403 `rest_forbidden`.
  - `test_editor_bad_arg_gets_invalid_request`: as an editor answers 400, code `ErrorCode::InvalidRequest->value`, and `data.params` has the key `per_page`.
  - `test_permission_callback_runs_once`: count calls with a `user_has_cap` filter that increments when `edit_others_posts` is checked. Signed out with `per_page=500`, the count is 1.
  - `test_public_route_bad_arg_gets_invalid_request`: `GET /views/trending?per_page=0` answers 400 `InvalidRequest`.
  - `test_missing_param_gets_invalid_request`: `GET /admin/search` as an editor with no `type` answers 400 `InvalidRequest`.
  - `test_bad_json_gets_invalid_request`: `POST /view/post/{id}` with `Content-Type: application/json` and body `{` answers 400 `InvalidRequest`.
  - `test_route_in_other_case_is_mapped`: `GET /MAI-ANALYTICS/v1/views/trending?per_page=0` answers 400 `InvalidRequest`.
  - `test_other_namespace_passes_through`: register `mai-analytics-test/v1/echo` with a `validate_callback` returning false; it answers `rest_invalid_param`.
  - `test_no_route_is_untouched`: `POST /view/post/abc` answers 404 `rest_no_route`.
  - `test_database_error_response_shape`: `Errors::response( ErrorCode::DatabaseError, 'x', [], [ 'success' => false ] )` has status 503, header `Retry-After` `1`, `data.retry_after` 1, `success` false.

- [ ] **Step 2: Run them to see them fail.** `WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit --filter Test_REST_Errors`. Expected: errors for the missing classes.

- [ ] **Step 3: Write `ErrorCode`, `Errors` and `RestErrors`.** `RestErrors::filter()` follows the spec's two steps exactly: only for a route whose lowercased path starts with `/mai-analytics/v1/` and a `WP_Error` whose code is in `CORE_CODES`; call `$handler['permission_callback']` once; a `WP_Error` is returned as it is; `false` or `null` becomes `new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.' ), [ 'status' => rest_authorization_required_code() ] )`, which is core's own message and data (`class-wp-rest-server.php:1262-1270`); otherwise return `ErrorCode::InvalidRequest->error( $response->get_error_message(), $data )`, where `$data` is the core error's data without `status`. `map()` adds the filter once at priority 99 with three accepted args.

- [ ] **Step 4: Register it.** In `Plugin::init()`, call `RestErrors::map( 'mai-analytics/v1', 'mai_analytics' )` on `rest_api_init`. Add `test_slug_builds_the_enum_code` asserting `'mai_analytics_invalid_request' === ErrorCode::InvalidRequest->value` is what the mapping returns (read it from a mapped response's code, not a literal).

- [ ] **Step 5: Run the tests.** Expected: `Test_REST_Errors` passes, and the whole suite still passes.

- [ ] **Step 6: Commit.**

```bash
git add src/ErrorCode.php src/Errors.php src/RestErrors.php src/Plugin.php tests/test-rest-errors.php
git commit -m "Add plugin error codes and map core's argument errors after the permission check"
```

---

### Task 2: View POST errors

**Files:**
- Modify: `src/RestApi.php` (`record_view()` `:133-173`, `record_post_type_view()` `:214-250`, the unreachable 400s at `:138` and `:187`)
- Test: `tests/test-rest-api.php`

**Interfaces:**
- Consumes: `ErrorCode`, `Errors::response()` from Task 1.

- [ ] **Step 1: Write the failing tests** in `tests/test-rest-api.php`. Extend the existing `404` and `403` tests (`:51-72`) to also assert the code, and add:
  - missing post and draft post: 404, code `ErrorCode::NotFound->value`, `success` false, `data.status` 404.
  - bot user agent in self-hosted mode: 403, code `ErrorCode::Bot->value`, `success` false.
  - non-public post type on `POST /view/post_type/{slug}`: 404 `NotFound`, `success` false.
  - `test_insert_failure_answers_503`: add a `query` filter that rewrites any query starting `INSERT INTO \`{buffer table}\`` to target a table that does not exist, with `$wpdb->suppress_errors()`, so `Database::insert_view()` returns false. Then `POST /view/post/{id}` for a published post answers 503, code `ErrorCode::DatabaseError->value`, `success` false, `data.retry_after` 1, header `Retry-After` `1`. Same for `POST /view/post_type/post`.
  - In provider mode with the item already queued, `POST /view/post/{id}` still answers 200 `{ success: true }` (dedup skips the insert, which is not a failure).

- [ ] **Step 2: Run to see them fail.** `--filter Test_REST_API`.

- [ ] **Step 3: Switch the bodies.** Each `new WP_REST_Response( [ 'success' => false ], N )` becomes `Errors::response( ErrorCode::X, $message, [], [ 'success' => false ] )`. Messages (no em-dashes): not found "Nothing to count here.", bot "Views from bots are not counted.", no type "Invalid request.", database "The view could not be saved. Try again." Where `Database::insert_view()` returns `false`, return the `DatabaseError` response. The dedup path, which inserts nothing on purpose, keeps `{ success: true }`.

- [ ] **Step 4: Run the tests.** Expected: pass, and the full suite passes.

- [ ] **Step 5: Commit.**

```bash
git add src/RestApi.php tests/test-rest-api.php
git commit -m "Give view errors plugin codes and report a failed view insert"
```

---

### Task 3: No counts for hidden items

**Files:**
- Modify: `src/RestApi.php` (`get_views()` `:182-203`, `get_post_type_views()` `:259-269`, `get_trending()` `:278-343`)
- Test: `tests/test-rest-api.php`

**Interfaces:**
- Consumes: `validate_object( int $id, string $type ): bool` (`src/RestApi.php:420-437`), `ErrorCode::NotFound`, `Errors::response()`. Its `user` branch changes (spec, change 4, "user IDs"): a user is visible only when `count_user_posts( $id, get_post_types( [ 'public' => true ] ), true ) > 0`.
- Produces: `private function is_public_post_type( string $post_type ): bool` in `RestApi`, used by both post type routes (move the check at `:218-222` into it).

- [ ] **Step 1: Write the failing tests:**
  - `test_get_views_hides_unpublished`: signed out, `GET /views/post/{id}` for a draft, a private, a pending and a trashed post, and a post of a registered non-public type, each answer 404 `NotFound` with `success` false. Same as an editor.
  - `test_get_views_missing_items`: a missing post ID, a term in a non-public taxonomy, a missing term, a missing user and a user with no published posts each answer 404 `NotFound`. A user with one published post answers 200.
  - `test_view_post_for_user_without_posts`: `POST /view/user/{id}` for a user with no published posts answers 404 `NotFound` and writes no buffer row.
  - `test_get_post_views` (`:74-84`) keeps passing unchanged: the post factory's default status is `publish`.
  - `test_get_post_type_views_hides_non_public`: a registered non-public post type answers 404 `NotFound`; `post` answers 200.
  - `test_trending_drops_hidden_posts`: publish 12 posts, give each views with `Database::insert_view()`, make the 3 most viewed private, then `GET /views/trending?type=post&per_page=5` returns 5 rows and none of the private IDs.
  - `test_trending_drops_hidden_terms_and_users`: a viewed term whose taxonomy is then made non-public and a viewed user who is then deleted do not appear.

- [ ] **Step 2: Run to see them fail.**

- [ ] **Step 3: Implement.** `get_views()` returns the `NotFound` response when `validate_object()` is false. `get_post_type_views()` returns it when `is_public_post_type()` is false. In `get_trending()`, the SQL limit is always `$per_page * 5`; after the existing taxonomy filter, drop rows that fail `validate_object()` (rows of type `post_type` use `is_public_post_type()` on `object_key`), then cut to `$per_page`. Prime the post cache before the visibility filter, so `get_post()` costs no extra queries.

- [ ] **Step 4: Run the tests.** Expected: pass, and the full suite passes. The Health check's REST probe asks about a published post (`src/Health.php:139-152`), so it is not affected.

- [ ] **Step 5: Check the other count reads.** Confirm what the spec found: `rg -n "mai_views|mai_trending" ~/Plugins/_wip/analytics-alignment/src ~/Plugins/_wip/analytics-alignment/includes` and read every hit that answers a request. Only the shortcode and `mai_analytics_get_count()` read counts outside these routes, and both are PHP-only. Leave them, and add one `TODO.md` line if it is not there yet: "The `[mai_views]` shortcode and `mai_analytics_get_count()` read any ID with no visibility check. Only staff can call them today." Anything else that answers a signed-out caller with a hidden item's count is in scope: fix it here with a test, and say so in the report.

- [ ] **Step 6: Commit.**

```bash
git add src/RestApi.php tests/test-rest-api.php TODO.md
git commit -m "Stop showing view counts for items a caller may not see"
```

---

### Task 4: Admin errors and quiet limits

**Files:**
- Modify: `src/AdminRestApi.php` (warm args `:173-190`, search args `:138-157`, `search()` `:726-790`, `sync_now()` `:799-845`, `warm()` `:881-944`, docblocks `:724`, `:948`)
- Modify: `src/CLI.php:407` (the `seed --days` docblock says 7; the code uses 30)
- Test: `tests/test-admin-rest-api.php`

**Interfaces:**
- Consumes: `ErrorCode`, `Errors::response()`.

- [ ] **Step 1: Write the failing tests** (as an administrator unless said):
  - `test_sync_now_without_provider`: `data_source` set to a slug no provider has, `POST /admin/sync-now` answers 409 `ErrorCode::ProviderUnavailable->value` with a non-empty top-level `message`.
  - `test_sync_now_provider_error`: register a test provider through `mai_analytics_providers` whose `get_views()` calls `Sync::set_provider_error( 'down' )` and returns null; answers 502 `ErrorCode::ProviderError->value`, `message` `down`.
  - `test_warm_in_self_hosted_mode`: answers 409 `ErrorCode::ProviderModeRequired->value`, `message` "Warm is only available in provider mode."
  - `test_warm_provider_error_keeps_progress`: with the failing provider, answers 502 `ProviderError` and the body still has top-level `message`, `done`, `total_updated` and `total_iterated`.
  - `test_warm_refuses_negative_cursor`: `cursor=-1` answers 400 `InvalidRequest`. Same for `total_updated=-1` and `total_iterated=-1`.
  - `test_search_pages`: 60 terms named `Alpha 01` to `Alpha 60` in `category`, each with a post; `GET /admin/search?type=term&taxonomy=category&search=Alpha` answers 50 rows, `X-WP-Total` `60`, `X-WP-TotalPages` `2`, and a bare array body; `page=2` answers 10 rows; `per_page=101` answers 400 `InvalidRequest`; `per_page=100` answers 60 rows.
  - The same paging and headers for `type=author`.

- [ ] **Step 2: Run to see them fail.** `--filter Test_Admin_REST_API`.

- [ ] **Step 3: Implement.**
  - Warm's three integer args get `'validate_callback' => 'rest_validate_request_arg'`, which enforces their declared `minimum`.
  - Search gets `page` (`type` integer, `minimum` 1, `default` 1) and `per_page` (`type` integer, `minimum` 1, `maximum` 100, `default` 50), each with `rest_validate_request_arg`. Each query is paged with a `COUNT(DISTINCT ...)` on the same `WHERE`, then `LIMIT %d OFFSET %d` (not `SQL_CALC_FOUND_ROWS`, which MySQL deprecates). Set `X-WP-Total` and `X-WP-TotalPages` on the response. A `term` search with no or a non-public taxonomy still answers `[]`, with `X-WP-Total` `0`.
  - `sync_now()`: the no-provider or unavailable branch answers `ProviderUnavailable` with today's reason as the message. Both stored-error branches answer `ProviderError` with the stored message. Success bodies are unchanged.
  - `warm()`: self-hosted answers `ProviderModeRequired` with today's message. Both error branches answer `ProviderError`, passing today's payload (without `message`) as `$legacy`, so the progress fields stay top level.
  - Fix the three docblocks: search "Up to `per_page` matching results (50 by default)", `get_source_breakdown` "Only queries the current page's IDs (at most 100)", `seed --days` default 30.

- [ ] **Step 4: Run the tests.** Expected: pass, and the full suite passes.

- [ ] **Step 5: Commit.**

```bash
git add src/AdminRestApi.php src/CLI.php tests/test-admin-rest-api.php
git commit -m "Give admin errors plugin codes, page the search, enforce warm minimums"
```

---

### Task 5: 100-ID limits

**Files:**
- Modify: `src/RestApi.php` (trending `terms` arg `:115-118`), `src/AdminRestApi.php` (`term_id` and `author` args `:63-70`), `assets/js/admin-dashboard.js:661`
- Test: `tests/test-rest-api.php`, `tests/test-admin-rest-api.php`

**Interfaces:**
- Produces: `final class IdList` in `src/IdList.php` with `public const MAX = 100;` and `public static function within_limit( mixed $value ): bool`. True when the value is a string whose comma-separated, non-empty entries number 100 or fewer. Entries are not otherwise checked (spec, change 2).

- [ ] **Step 1: Write the failing tests:**
  - trending `terms` with 100 IDs answers 200; with 101 answers 400 `InvalidRequest` and `data.params` has `terms`.
  - `/admin/top/posts` as an editor with 100 `term_id` IDs (and `taxonomy=category`) answers 200, with 101 answers 400; the same for `author`.
  - signed out, 101 `term_id` IDs answer 401 `rest_forbidden`.
  - a list with a trailing comma, `1,2,`, counts 2.

- [ ] **Step 2: Run to see them fail.**

- [ ] **Step 3: Implement.** Add `'validate_callback' => [ IdList::class, 'within_limit' ]` to the three args. In `admin-dashboard.js`, change `maxItems: null` to `maxItems: 100` in `initTomSelect`, which both the term and author selects use.

- [ ] **Step 4: Run the tests.** Expected: pass, and the full suite passes.

- [ ] **Step 5: Commit.**

```bash
git add src/IdList.php src/RestApi.php src/AdminRestApi.php assets/js/admin-dashboard.js tests/test-rest-api.php tests/test-admin-rest-api.php
git commit -m "Refuse ID lists over 100"
```

---

### Task 6: Cron cleanup and uninstall

**Files:**
- Modify: `src/Cron.php`, `src/Plugin.php`, `mai-analytics.php:55-66`, `uninstall.php`
- Test: `tests/test-cron.php`, create `tests/test-uninstall.php`

**Interfaces:**
- Produces:
  - `Cron::HOOKS`, a public const list: `'mai_analytics_cron_sync'`, `ProviderSync::CATCHUP_HOOK` (`'mai_analytics_provider_catchup'`), `self::PRUNE_HOOK`, `self::PRUNE_NOW_HOOK`.
  - `public static function Plugin::deactivate(): void`, which clears every hook in `Cron::HOOKS` and nothing else. Public so Mai Publisher can call it later (spec, "Bugs that are not alignment").
  - `public static function Plugin::activate(): void`, which runs today's activation body plus `Cron::schedule_daily_prune()`.

- [ ] **Step 1: Write the failing tests:**
  - `tests/test-cron.php`, `test_deactivate_clears_every_hook`: schedule all four hooks, call `Plugin::deactivate()`, assert `wp_next_scheduled()` is false for each in `Cron::HOOKS`, and the table, `mai_analytics_settings` and a `mai_views` meta row are still there.
  - `test_activate_schedules_sync_and_prune`: clear all, call `Plugin::activate()`, assert `mai_analytics_cron_sync` and `Cron::PRUNE_HOOK` are scheduled.
  - `tests/test-uninstall.php` (class `Test_Uninstall`): schedule every hook in `Cron::HOOKS`, add an option `maiXanalytics_keep`, an option `mai_analytics_settings`, a transient `mai_analytics_admin_filters` and `mai_views` meta on a post, then `define( 'WP_UNINSTALL_PLUGIN', 'mai-analytics/mai-analytics.php' )` if not defined and `include dirname( __DIR__ ) . '/uninstall.php'`. Assert: every hook in `Cron::HOOKS` is unscheduled, a `query` filter saw `DROP TABLE IF EXISTS` for the buffer table (the drop itself is turned temporary by the test library, see Global Constraints), `mai_analytics_settings` and the transient are gone, the meta row is gone, and `maiXanalytics_keep` is still there. This test is the drift check between `Cron::HOOKS` and the list written out in `uninstall.php`.

- [ ] **Step 2: Run to see them fail.**

- [ ] **Step 3: Implement.** The activation and deactivation closures in `mai-analytics.php` call `Plugin::activate()` and `Plugin::deactivate()`. `uninstall.php` lists the four hook names as literal strings (it runs without the plugin's autoloader, `wp-admin/includes/plugin.php:1323-1333`), and builds both `LIKE` patterns with `$wpdb->esc_like( 'mai_analytics_' ) . '%'` and `$wpdb->esc_like( '_transient_mai_analytics_' ) . '%'` (and the timeout one) through `$wpdb->prepare()`.

- [ ] **Step 4: Run the tests.** Expected: pass, and the full suite passes. Run `Test_Uninstall` last or alone if it leaves the table dropped for later tests; each other class's `setUp` calls `Database::create_table()`, so order should not matter, but check.

- [ ] **Step 5: Check for other unescaped `LIKE`.** Run `rg -n "LIKE" ~/Plugins/_wip/analytics-alignment/src ~/Plugins/_wip/analytics-alignment/includes ~/Plugins/_wip/analytics-alignment/uninstall.php` and read every hit that matches option, transient or meta names without `esc_like()`. On 2026-11-18 there were two besides uninstall, both read-only: `src/Migration.php:123` (`meta_key LIKE 'mai_analytics_views%'`, the legacy-key probe) and `src/Health.php:54` (`meta_key LIKE 'mai_analytics_%'`, a count). A stray match there can only make a probe or a count wrong, never delete anything, so they stay out of this release. Add one `TODO.md` line for both. Any `LIKE` that deletes or updates is in scope: escape it here, with a test.

- [ ] **Step 6: Commit.**

```bash
git add src/Cron.php src/Plugin.php mai-analytics.php uninstall.php tests/test-cron.php tests/test-uninstall.php TODO.md
git commit -m "Clear every cron hook on deactivate and uninstall, escape uninstall's LIKE"
```

---

### Task 7: db version only after the table exists

**Files:**
- Modify: `src/Database.php` (`create_table()` `:25-47`, `maybe_update()` `:54-62`, `maybe_migrate_old_table()` `:114-120`), `src/CLI.php` (`health --fix`, `:81-84` area, reads the return value)
- Test: `tests/test-database.php`

**Interfaces:**
- Produces: `Database::create_table(): bool` (true when the table exists after `dbDelta()`), `public const RETRY_TRANSIENT = 'mai_analytics_db_retry';`, `public static function table_exists(): bool`.

- [ ] **Step 1: Write the failing tests:**
  - `test_create_table_records_version`: delete the option, `create_table()` returns true and the option equals `MAI_ANALYTICS_DB_VERSION`.
  - `test_failed_create_does_not_record_version`: make the table look missing with a `query` filter that rewrites `SHOW TABLES LIKE '{buffer table}'` to `SHOW TABLES LIKE 'mai_analytics_none'`, delete the option, add `add_filter( 'dbdelta_queries', '__return_empty_array' )`, then `create_table()` returns false, the option is absent, and the transient `Database::RETRY_TRANSIENT` is set.
  - `test_maybe_update_waits_while_retry_is_set`: with the transient set and the option absent, `maybe_update()` does not call `dbDelta()` (assert with a `dbdelta_queries` filter that counts calls: 0).
  - `test_old_version_not_copied_without_table`: set `mai_views_db_version` to `1.0.2`, delete `mai_analytics_table_migrated` and the new option, make the new table look missing and block `dbDelta()` as above, run `maybe_update()`; `mai_analytics_db_version` is not `1.0.2`.

- [ ] **Step 2: Run to see them fail.**

- [ ] **Step 3: Implement.** `table_exists()` uses `$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::get_table_name() ) )`, the same check as `maybe_migrate_old_table()` (`:88`), so the test's `query` filter can match the plain table name. On failure, `create_table()` logs with `mai_analytics_logger()->error( 'Could not create the mai_analytics_buffer table. Will try again in an hour.' )` and sets the transient for `HOUR_IN_SECONDS`. `maybe_update()` returns early while the transient exists. The old-version copy in `maybe_migrate_old_table()` runs only when `table_exists()`. `health --fix` reports "Table could not be created." when `create_table()` returns false.

- [ ] **Step 4: Run the tests.** Expected: pass, and the full suite passes.

- [ ] **Step 5: Commit.**

```bash
git add src/Database.php src/CLI.php tests/test-database.php
git commit -m "Record the db version only after the table exists"
```

---

### Task 8: Em-dashes and the README

**Files:**
- Modify: `src/Admin.php:359`, `src/Providers/SiteKit.php:404`, `src/Providers/Matomo.php:332`, `src/CLI.php:60`, `src/ProviderSync.php:200`, `:667`, `src/Stats.php:420`, `:422`, `:462`, `README.md` (30 lines, and the `mai_analytics_sync_interval` row at `:181`)
- Test: create `tests/test-plain-text.php`

- [ ] **Step 1: Write the failing test** `Test_Plain_Text::test_no_em_dash_outside_comments`: walk every `.php` file under `src/` and `includes/` with `token_get_all()`, and fail on any token that is not `T_COMMENT` or `T_DOC_COMMENT` and contains `"\u{2014}"`, naming the file and line.

- [ ] **Step 2: Run it to see it fail.** Expected: it lists the nine lines above.

- [ ] **Step 3: Rewrite each string.** Exact copy for the ones a person sees in wp-admin or the CLI:
  - `Admin.php:359`: the age goes in brackets, `(<?php echo esc_html( $age ); ?>)`, keeping the `<em style="opacity:.7;">` wrapper.
  - `SiteKit.php:404`: `'Could not build the Site Kit Analytics module. Check for a Mai Analytics update. Details: %s'`.
  - `Matomo.php:332`: `$message .= ' Response: ' . $snippet;`
  - `CLI.php:60`: `" ({$c['detail']})"`.
  - Log lines: `ProviderSync.php:200` "... object_id=%d. Skipping the provider fetch for this object.", `:667` "... object_id=%d. Skipping this object.", `Stats.php:420` `' [provider unhealthy, so the stale prune was skipped]'`, `:422` `' [buffer not live, so the stale prune was skipped]'`, `:462` "... via staleness in one run. Check this was intended."
  - README: rewrite each of the 30 lines as plain sentences. The `mai_analytics_sync_interval` row's description becomes "Reported to Mai Publisher only. Does not change the sync schedule."

- [ ] **Step 4: Run the test and the suite.** Also `rg -n '\x{2014}' README.md` prints nothing. Expected: pass. Existing tests that assert the old message text (check `rg -n "check for a Mai Analytics update|Matomo API returned HTTP" tests`) are updated to the new text.

- [ ] **Step 5: Commit.**

```bash
git add src/Admin.php src/Providers/SiteKit.php src/Providers/Matomo.php src/CLI.php src/ProviderSync.php src/Stats.php README.md tests/test-plain-text.php
git commit -m "Remove em-dashes from text people read"
```

If Step 4 changed other test files, add them by name too.

---

### Task 9: Clean branch and hand-over

**Files:**
- Modify: `STATE.md`, `TODO.md`

- [ ] **Step 1: Full suite** (after the `SITE-LOCK.md` check). Expected: `OK`, with the baseline count from Task 0 plus the new tests.

- [ ] **Step 2: Put `vendor/` back to production only.**

```bash
composer install --no-dev -d ~/Plugins/_wip/analytics-alignment
```

```bash
git -C ~/Plugins/_wip/analytics-alignment status --short vendor
```

Expected: no output. Then, from the worktree folder, `php ~/Plugins/mai-analytics/vendor/bin/deployable-guard check --root=.` (the binary is a dev package, so use the main checkout's copy) prints `OK: committed autoloader is deployable as-is.` Composer's classmap is PSR-4, so the three new classes need no autoloader change; the guard confirms it.

- [ ] **Step 3: Rewrite `STATE.md`** on the branch: what was built, the test count, the open naming question, and the draft changelog lines below for whoever cuts the release. Add to `TODO.md`'s "Later" any follow-up you hit. Draft changelog lines (they go into `CHANGES.md` only on the release commit, with its version and date):
  - `* Changed: [Developers] REST errors now carry a mai_analytics_* code and data.status. View errors keep "success": false and admin errors keep "message".`
  - `* Changed: [Developers] Core's argument errors answer 400 mai_analytics_invalid_request, after the permission check, so a signed-out request still gets 401.`
  - `* Changed: [Developers] ID lists (trending terms, the dashboard's term and author filters) take at most 100 IDs.`
  - `* Changed: [Developers] The dashboard search takes page and per_page and sends X-WP-Total and X-WP-TotalPages.`
  - `* Fixed: View counts for drafts, private posts and other hidden items could be read by anyone. They now answer 404, and trending no longer lists them.`
  - `* Fixed: A view that failed to save was reported as saved.`
  - `* Fixed: Deactivating or uninstalling left two scheduled tasks behind.`
  - `* Fixed: The database version could be recorded when the table had not been created.`
  - `* Fixed: Sync Now answered a server error for a setup problem.`

- [ ] **Step 4: Commit.**

```bash
git add STATE.md TODO.md
git commit -m "State after the alignment build"
```

- [ ] **Step 5: Gate. Stop here.**
  - Pushing `feat/alignment` needs no one's grant (Mike's standing rule: pushes to branches never need asking). Never push `develop` or `main` from here.
  - Do not use the shared production-copy site in any way (no activation, WP-CLI, e2e or browser) without a claim from mai-coordinator.
  - No version bump, tag or release. A point release happens only on Mike's yes, and no tag questions until the whole stack passes its tests.
  - If Mike renames any code in his walk, change only the `ErrorCode` case values and rerun the suite.
  - Report to mai-coordinator: the branch, its last commit, the test count, and anything that did not go as this plan says.

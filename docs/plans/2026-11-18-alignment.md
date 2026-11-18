# mai-analytics Alignment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship mai-analytics' part of the 2026-11-18 alignment pass: cron cleanup, 100-ID limits, a checked db version, no counts for hidden items, honest limits, no em-dashes, and `mai_analytics_*` error codes with core's argument errors mapped after the permission check.

**Architecture:** Three new classes carry the error work: `ErrorCode` (an enum holding every code and its status), `Errors` (builds error responses that keep today's top-level fields) and `RestErrors` (maps core's argument errors, a copy of `Mai\Items\RestErrors`'s design). The view and admin routes switch their hand-built bodies to `Errors`. The cron, schema and uninstall fixes are local edits to `Cron`, `Plugin`, `Database` and `uninstall.php`.

**Tech Stack:** PHP 8.1, WordPress 7.1 REST API, PHPUnit 9.6 with the WordPress test library (`WP_UnitTestCase`), Tom Select in the admin dashboard.

**Spec:** `docs/specs/2026-11-18-alignment.md` (checked by mai-coordinator). Read it first. Every "today" line and file reference below comes from it.

**Reviewed:** 2026-11-18 by an independent reviewer against the spec, the code and WordPress 7.1 core, and every finding was checked again before this version. Where a step rests on core behaviour, the core file and line are given.

## Global Constraints

- PHP floor stays 8.1 (`mai-analytics.php:8`). No 8.2-only syntax: no `readonly` classes, no DNF types, no constants in traits.
- `declare( strict_types=1 );` at the top of each new PHP file. Existing files are left as they are.
- Nothing exposed is renamed or removed: no filter, option, meta key, route, param or response field. Changes only tighten or add.
- An input over its limit is refused with 400, never clamped. An ID list takes at most 100 IDs.
- The error code names below are the spec's proposals. Mike may rename them in his walk with mai-coordinator, so every code string lives only in `ErrorCode`. No other file writes a `mai_analytics_*` code as a literal string, tests included (tests use `ErrorCode::X->value`).
- Names follow core. Before naming anything new that this plan does not already name, check what WordPress core calls the same thing and cite the file and line.
- No em-dash (U+2014) in any string a person reads, logs included. Rewrite the sentence, don't swap in a semicolon or colon.
- New classes, methods and constants get thorough PHPDoc: `@since 1.3.8`, `@param` lines, a blank line, then `@return`. (1.3.8 is the next point release. Nothing in this plan bumps the version.)
- Never answer `rest_no_route` from plugin code. maiexpowp switches views off on it.
- No `vendor/` change is committed. `composer install` in the worktree adds dev packages and rewrites `vendor/composer/installed.php`. Task 9 puts it back.
- Tool calls: edit files with the Read, Edit and Write tools, never with python, sed or heredocs in Bash. Each Bash call is one plain command with full paths: no `&&` chains, no heredocs, no shell variables, no `$(...)`. An environment variable in front of a command, such as `WP_TESTS_DIR=/tmp/wordpress-tests-lib`, is fine. The Bash tool's folder resets to the main checkout on `develop`, so a relative path lands in the wrong place.
- **The test command.** Every "run" step means this, with `--filter <Class>` added when it names a class:

```bash
WP_TESTS_DIR=/tmp/wordpress-tests-lib ~/Plugins/_wip/analytics-alignment/vendor/bin/phpunit -c ~/Plugins/_wip/analytics-alignment/phpunit.xml.dist
```

- **Commits.** Each commit step is two calls: `git -C ~/Plugins/_wip/analytics-alignment add <files>`, then `git -C ~/Plugins/_wip/analytics-alignment commit -q -m "<subject>"`. File paths in `add` are relative to the worktree, which `-C` makes them. Push as its own call.
- **Test library facts, checked by the reviewer** in a copy of the WordPress test library (`wp-phpunit/includes/abstract-testcase.php`). Task 0 confirms them in the installed copy:
  - A test's `DROP TABLE` becomes `DROP TEMPORARY TABLE`, which leaves the real buffer table in place (the `_drop_temporary_tables` `query` filter, added in `start_transaction()` at priority 10). So never drop the table to make something fail. Fail a query with a `query` filter instead.
  - Hooks are backed up before the first test and restored after every test (`_backup_hooks()` and `_restore_hooks()`). A filter a test or `rest_api_init` adds is gone after that test.
  - The buffer table is a real table made when the suite boots, so `SHOW TABLES LIKE` sees it (`tests/test-database.php:12-18` passes today).
- New POST tests send `User-Agent: Mozilla/5.0`, as the existing tests do (`tests/test-rest-api.php:27`). Self-hosted mode with `exclude_bots` on is the default, and an empty user agent counts as a bot (`src/BotFilter.php:39-42`), which answers 403 before anything else runs.
- Before running the test suite, a benchmark or a build, read the Status section of the shared production-copy site's `SITE-LOCK.md` (in the site's root, `~/Herd/` plus the site's folder). Do not start while another session has a device run claimed. Suites during a device run pushed the machine's load to 145 on 2026-11-18 and broke that run. PHPUnit here uses its own test database, never the shared site.
- Two security checks every session was asked for on 2026-11-18 are folded into Task 3 (counts for hidden items) and Task 6 (unescaped `LIKE`). Fix what the spec covers, and list anything else in `TODO.md` and the report.

## Review Focus

- **A signed-out caller with a bad arg on an admin route** expects 401 `rest_forbidden`, not 400. Pinned in Task 1.
- **The app's `useView`** expects nothing it does today to change: a hidden or missing item, a bot and a database failure must never answer `rest_no_route`. Pinned in Task 2 by asserting the code on each.
- **The admin Warm button** reads `message` and `!res.ok` on errors, and the progress fields on success. A 502 must still carry `message`, `done`, `total_updated` and `total_iterated` at the top level. Pinned in Task 4.
- **A real activation**, where the plugin file is loaded after `plugins_loaded` has fired (`wp-admin/includes/plugin.php:679`), so `Plugin::init()` has not run and the 15-minute schedule is unknown. Pinned in Task 6.
- **A trending list where most viewed items went hidden** still returns `per_page` visible rows when enough exist, without one query per row. Pinned in Task 3.

---

### Task 0: Worktree and baseline

**Files:** none changed.

- [ ] **Step 1: Make the worktree.** The main checkout stays on `develop`, because the shared production-copy site loads it.

```bash
git -C ~/Plugins/mai-analytics worktree add -b feat/alignment ~/Plugins/_wip/analytics-alignment develop
```

Expected: `Preparing worktree (new branch 'feat/alignment')`.

- [ ] **Step 2: Install dev dependencies there.**

```bash
composer install -d ~/Plugins/_wip/analytics-alignment
```

Expected: `~/Plugins/_wip/analytics-alignment/vendor/bin/phpunit` exists. `git -C ~/Plugins/_wip/analytics-alignment status --short` shows `vendor/composer/installed.php` and friends modified. That stays until Task 9. deployable-guard's pre-commit hook only acts when `vendor/` or `.gitignore` is staged, so it does not block the task commits.

- [ ] **Step 3: Check the WordPress test library.** `/tmp/wordpress-tests-lib/includes/functions.php` must exist. If not, run `~/Plugins/_wip/analytics-alignment/bin/install-wp-tests.sh` with a local test database name, user and password (it never touches the shared site), and say which database you made.

- [ ] **Step 4: Confirm the test library facts** in Global Constraints against the installed copy: `rg -n "temporary|_backup_hooks|_restore_hooks" /tmp/wordpress-tests-lib/includes/abstract-testcase.php`. Cite the lines in your report. If either differs, tell mai-coordinator before Task 1. Task 6's uninstall test is the one that depends on the drop rewrite.

- [ ] **Step 5: Baseline run** (after the `SITE-LOCK.md` check), with the test command. Expected: `OK`. Record the test count. If anything fails before you change code, stop and report it to mai-coordinator. Do not fix unrelated tests in this plan.

---

### Task 1: Error codes and core's argument errors

**Files:**
- Create: `src/ErrorCode.php`, `src/Errors.php`, `src/RestErrors.php`
- Modify: `src/Plugin.php` (register the mapping), `tests/test-admin-rest-api.php` (code assertions on the existing status tests, `:25-58`)
- Test: create `tests/test-rest-errors.php`

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
  - `ErrorCode::error( string $message, array $data = [] ): \WP_Error`. It always sets `status` from the case, overwriting any caller value, as mai-collections does (`~/Plugins/mai-collections/inc/classes/ErrorCode.php:46`).
  - `final class Errors` with `public static function response( ErrorCode $code, string $message, array $data = [], array $legacy = [] ): \WP_REST_Response`. Body: `$legacy` plus `code`, `message` and `data`, where `data` is `$data` with `status` set from the case (overwriting, like `ErrorCode::error()`). Response status is the case's status. For `DatabaseError` it also sets `data.retry_after` 1 and the header `Retry-After: 1`. A database error is always answered through `Errors::response()`, never `ErrorCode::error()`, because only the response can carry the header.
  - `final class RestErrors` with `public const CORE_CODES = [ 'rest_invalid_param', 'rest_missing_callback_param', 'rest_invalid_json', 'rest_invalid_params' ];`, `public static function map( string $namespace, string $slug ): void` and `public static function filter( mixed $response, array $handler, \WP_REST_Request $request ): mixed`. Same signature as `Mai\Items\RestErrors::map()`, so the later swap is one line. It is called with `'mai-analytics/v1'` and `'mai_analytics'`.

- [ ] **Step 1: Write the failing tests** in `tests/test-rest-errors.php` (class `Test_REST_Errors`, the same `setUp` and `tearDown` as `tests/test-rest-api.php`). The list mirrors mai-items-core's error-mapping contract cases (spec, change 7, "Copied here"):
  - `test_signed_out_bad_arg_on_admin_route_gets_permission_error`: `GET /mai-analytics/v1/admin/top/posts?per_page=500` signed out answers 401, code `rest_forbidden`.
  - `test_subscriber_bad_arg_gets_403`: the same request as a subscriber answers 403 `rest_forbidden`.
  - `test_editor_bad_arg_gets_invalid_request`: as an editor it answers 400, code `ErrorCode::InvalidRequest->value`, and `data.params` has the key `per_page`.
  - `test_permission_callback_runs_once`: count calls with a `user_has_cap` filter that increments when `edit_others_posts` is asked for. It fires for a signed-out caller too (`wp-includes/capabilities.php:1015-1021`, `wp-includes/class-wp-user.php:824`). Signed out with `per_page=500`, the count is 1.
  - `test_public_route_bad_arg_gets_invalid_request`: `GET /views/trending?per_page=0` answers 400 `InvalidRequest`.
  - `test_bad_source_gets_invalid_request`: `POST /view/post/{id}` for a published post with `source=bad` answers 400 `InvalidRequest`, and `data.params` has `source`.
  - `test_missing_param_gets_invalid_request`: `GET /admin/search` as an editor with no `type` answers 400 `InvalidRequest`.
  - `test_bad_json_gets_invalid_request`: `POST /view/post/{id}` with `Content-Type: application/json` and body `{` answers 400 `InvalidRequest`.
  - `test_route_in_other_case_is_mapped`: `GET /MAI-ANALYTICS/v1/views/trending?per_page=0` answers 400 `InvalidRequest`. Core matches routes without case (`wp-includes/rest-api/class-wp-rest-server.php:1172`).
  - `test_other_namespace_passes_through`: register `mai-analytics-test/v1/echo` with `'permission_callback' => '__return_true'` (without one, core raises `_doing_it_wrong`, `wp-includes/rest-api.php:122-129`) and an arg whose `validate_callback` returns false. It answers `rest_invalid_param`.
  - `test_no_route_is_untouched`: `POST /view/post/abc` answers 404 `rest_no_route`.
  - `test_mapped_code_comes_from_the_slug`: the code in `test_editor_bad_arg_gets_invalid_request`'s response equals `ErrorCode::InvalidRequest->value`. This pins that `map()`'s `{$slug}_invalid_request` and the enum agree.
  - `test_mapping_survives_between_tests`: a second test in the class that repeats the `per_page=500` editor request and still gets `InvalidRequest`. It fails if `map()` guards itself with a static flag, because the test library removes the filter after each test.
  - `test_database_error_response_shape`: `Errors::response( ErrorCode::DatabaseError, 'x', [ 'status' => 200 ], [ 'success' => false ] )` has status 503, `data.status` 503, header `Retry-After` `1`, `data.retry_after` 1 and `success` false.
- In `tests/test-admin-rest-api.php`, the existing 401 and 403 tests (`:25-41`) also assert code `rest_forbidden`.

- [ ] **Step 2: Run them to see them fail** (test command, `--filter Test_REST_Errors`). Expected: errors for the missing classes.

- [ ] **Step 3: Write `ErrorCode`, `Errors` and `RestErrors`.** `RestErrors::filter()` acts only when the request's route, lowercased, starts with a recorded namespace plus `/`, and the response is a `WP_Error` whose code is in `CORE_CODES` or is that namespace's `{$slug}_invalid_request` (the library remaps both). Then:
  1. Call `$handler['permission_callback']` once. A `WP_Error` is returned as it is. `false` or `null` becomes `new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.' ), [ 'status' => rest_authorization_required_code() ] )`, core's own message and data (`class-wp-rest-server.php:1262-1270`). Core then skips its own check because the response is already an error (`:1259`).
  2. Otherwise return a `WP_Error` with code `{$slug}_invalid_request`, core's message, and core's data without `status`, plus `status` 400.

  `map()` records the namespace and slug in a static array on every call, and adds the filter with `add_filter( 'rest_request_before_callbacks', [ self::class, 'filter' ], 99, 3 )` on every call. Never a static "already added" flag: the test library removes hooks after each test, and adding the same callback at the same priority twice is harmless.

- [ ] **Step 4: Register it.** In `Plugin::init()`, call `RestErrors::map( 'mai-analytics/v1', 'mai_analytics' )` on `rest_api_init`.

- [ ] **Step 5: Run the tests.** Expected: `Test_REST_Errors` and `Test_Admin_REST_API` pass, and the whole suite passes.

- [ ] **Step 6: Commit.** Add `src/ErrorCode.php src/Errors.php src/RestErrors.php src/Plugin.php tests/test-rest-errors.php tests/test-admin-rest-api.php`. Subject: "Add plugin error codes and map core's argument errors after the permission check".

---

### Task 2: View POST errors

**Files:**
- Modify: `src/RestApi.php` (`record_view()` `:133-173`, `record_post_type_view()` `:214-250`, the no-type 400s at `:138` and `:187`, `get_type_from_route()` `:404-410`)
- Test: `tests/test-rest-api.php`

**Interfaces:**
- Consumes: `ErrorCode`, `Errors::response()` from Task 1.

- [ ] **Step 1: Write the failing tests** in `tests/test-rest-api.php`. Extend the existing 404 and 403 tests (`:51-72`) to also assert the code, and add (each POST with `User-Agent: Mozilla/5.0` unless it tests a bot):
  - a missing post and a draft post: 404, code `ErrorCode::NotFound->value`, `success` false, `data.status` 404.
  - a bot user agent in self-hosted mode: 403, code `ErrorCode::Bot->value`, `success` false.
  - a non-public post type on `POST /view/post_type/{slug}`: 404 `NotFound`, `success` false.
  - `test_insert_failure_answers_503`: call `$wpdb->suppress_errors( true )` and keep its return value, then add a `query` filter that rewrites a query starting with `INSERT INTO \`` plus the buffer table name (`wp-includes/class-wpdb.php:2613` builds it that way) to target a table that does not exist, so `Database::insert_view()` returns false. `POST /view/post/{id}` for a published post answers 503, code `ErrorCode::DatabaseError->value`, `success` false, `data.retry_after` 1 and header `Retry-After` `1`. The same for `POST /view/post_type/post`. Put `suppress_errors()` back to the kept value at the end.
  - In provider mode with the item already queued, `POST /view/post/{id}` still answers 200 `{ success: true }` (dedup skips the insert on purpose).
  - `test_route_in_other_case_counts`: `POST /mai-analytics/v1/VIEW/post/{id}` for a published post answers 200 and writes a buffer row. Today it reaches the no-type branch, because core matches routes without case (`class-wp-rest-server.php:1172`) but `get_type_from_route()` does not (`src/RestApi.php:405`).

- [ ] **Step 2: Run to see them fail** (`--filter Test_REST_API`).

- [ ] **Step 3: Switch the bodies.** Each `new WP_REST_Response( [ 'success' => false ], N )` becomes `Errors::response( ErrorCode::X, $message, [], [ 'success' => false ] )`. Messages: not found "Nothing to count here.", bot "Views from bots are not counted.", no type "Invalid request.", database "The view could not be saved. Try again." Where `Database::insert_view()` returns `false`, return the `DatabaseError` response. The dedup path keeps `{ success: true }`. Add the `i` flag to the regex in `get_type_from_route()` and lowercase the match it returns.

- [ ] **Step 4: Run the tests.** Expected: pass, and the full suite passes.

- [ ] **Step 5: Commit.** Add `src/RestApi.php tests/test-rest-api.php`. Subject: "Give view errors plugin codes and report a failed view insert".

---

### Task 3: No counts for hidden items

**Files:**
- Modify: `src/RestApi.php` (`get_views()` `:182-203`, `get_post_type_views()` `:259-269`, `get_trending()` `:278-343`, `validate_object()` `:420-437`)
- Modify: `src/Tracker.php` (only if mai-coordinator chooses core's viewable helpers, see Step 3)
- Test: `tests/test-rest-api.php`

**Interfaces:**
- Consumes: `ErrorCode::NotFound`, `Errors::response()`.
- Produces, in `RestApi`:
  - `private function is_public_post_type( string $post_type ): bool`, used by both post type routes (move the check at `:218-222` into it).
  - `private function visible_ids( string $type, array $ids ): array`, which returns the IDs from `$ids` that pass the visibility rule, checked in bulk. Trending uses it.
- `validate_object()`'s `user` branch changes (spec, change 4, "user IDs"): a user is visible only with a published post in a public post type, `count_user_posts( $id, get_post_types( [ 'public' => true ] ), true ) > 0`. That function takes an array of types and `true` means published only (`wp-includes/user.php:619`, `wp-includes/post.php:7717-7732`).

- [ ] **Step 1: Write the failing tests:**
  - `test_get_views_hides_unpublished`: signed out, `GET /views/post/{id}` for a draft, a private, a pending and a trashed post, and a post of a registered non-public type, each answer 404 `NotFound` with `success` false. The same as an editor.
  - `test_get_views_missing_items`: a missing post ID, a term in a non-public taxonomy, a missing term, a missing user and a user with no published posts each answer 404 `NotFound`. A user with one published post answers 200.
  - `test_view_post_for_user_without_posts`: `POST /view/user/{id}` for a user with no published posts answers 404 `NotFound` and writes no buffer row.
  - **Change the existing `test_record_user_view`** (`tests/test-rest-api.php:43-49`): it posts a view for a user with no posts and expects 200, which this task makes a 404. Give its user one published post first. The 404 for a user with no posts is pinned by the test above.
  - `test_get_post_views` (`:74-84`) passes unchanged, because the post factory's default status is `publish`.
  - `test_get_post_type_views_hides_non_public`: a registered non-public post type answers 404 `NotFound`. `post` answers 200.
  - `test_trending_drops_hidden_posts`: publish 12 posts, give each views with `Database::insert_view()`, make the 3 most viewed private, then `GET /views/trending?type=post&per_page=5` returns 5 rows and none of the private IDs.
  - `test_trending_drops_hidden_terms_and_users`: a viewed term whose taxonomy is then made non-public, a viewed user who is then deleted, and a viewed user with no published posts do not appear.
  - `test_trending_checks_in_bulk`: 20 viewed users, each with a published post. `GET /views/trending?type=user&per_page=20`, counted with `$wpdb->num_queries` before and after, runs fewer queries than the number of rows.

- [ ] **Step 2: Run to see them fail.**

- [ ] **Step 3: Implement.**
  - `get_views()` returns the `NotFound` response when `validate_object()` is false. `get_post_type_views()` returns it when `is_public_post_type()` is false.
  - **Which visibility rule.** The spec proposes core's helpers in place of the plugin's own rule: `is_post_publicly_viewable()` (`wp-includes/post.php:2542`), `is_term_publicly_viewable()` (`wp-includes/taxonomy.php:5168`, after checking `get_term()` is not a `WP_Error`) and `is_post_type_viewable()` (`post.php:2447`), in `validate_object()`, `is_public_post_type()` and the tracker's three checks (`src/Tracker.php:84`, `:100`, `:117`). That is waiting on mai-coordinator and Mike. If they have said yes before you reach this step, use core's helpers and add a test for each difference the spec lists. If not, keep today's `publish` and `public` rule, and say so in the report.
  - In `get_trending()`, the SQL limit is always `$per_page * 5`. After the existing taxonomy filter, keep only rows whose IDs `visible_ids()` returns, then cut to `$per_page`. `visible_ids()` loads in bulk: `_prime_post_caches()` for posts, `_prime_term_caches()` for terms, and for users `cache_users()` plus one `count_many_users_posts( $ids, $types, true )` call (`wp-includes/user.php:661`, which takes an array of types at `:691`) instead of `count_user_posts()` per row. Rows of type `post_type` use `is_public_post_type()` on `object_key`.

- [ ] **Step 4: Run the tests.** Expected: pass, and the full suite passes. The Health check's REST probe asks about a published post (`src/Health.php:139-152`), so it is not affected.

- [ ] **Step 5: Check the other count reads.** Confirm what the spec found: `rg -n "mai_views|mai_trending" ~/Plugins/_wip/analytics-alignment/src ~/Plugins/_wip/analytics-alignment/includes`, and read every hit that answers a request. Only the shortcode and `mai_analytics_get_count()` read counts outside these routes, both PHP-only, and `TODO.md` already lists them. Anything else that hands a signed-out caller a hidden item's count is in scope: fix it here with a test, and say so in the report.

- [ ] **Step 6: Commit.** Add `src/RestApi.php tests/test-rest-api.php`, plus `src/Tracker.php` if Step 3 changed it. Subject: "Stop showing view counts for items a caller may not see".

---

### Task 4: Admin errors and quiet limits

**Files:**
- Modify: `src/AdminRestApi.php` (warm args `:173-190`, search args `:138-157`, `search()` `:726-790`, `sync_now()` `:799-845`, `warm()` `:881-944`, docblocks `:724`, `:948`)
- Modify: `src/CLI.php:407` (the `seed --days` docblock says 7, the code uses 30 at `:432`)
- Test: `tests/test-admin-rest-api.php`

**Interfaces:**
- Consumes: `ErrorCode`, `Errors::response()`.

- [ ] **Step 1: Write the failing tests** (as an administrator unless said):
  - `test_sync_now_without_provider`: `data_source` set to a slug no provider has, `POST /admin/sync-now` answers 409 `ErrorCode::ProviderUnavailable->value` with a non-empty top-level `message`.
  - `test_sync_now_provider_error`: a test provider added through `mai_analytics_providers` (one array arg, `src/ProviderSync.php:50-57`). It implements all six `WebViewProvider` methods (`src/WebViewProvider.php`), its slug matches `data_source`, and its `get_views()` calls `Sync::set_provider_error( 'down' )` and returns null. Copy the pattern at `tests/test-provider-sync.php:68-107`. Answers 502 `ErrorCode::ProviderError->value`, `message` `down`.
  - `test_warm_in_self_hosted_mode`: answers 409 `ErrorCode::ProviderModeRequired->value`, `message` "Warm is only available in provider mode."
  - `test_warm_provider_error_keeps_progress`: with the failing provider, answers 502 `ProviderError`, and the body still has top-level `message`, `done`, `total_updated` and `total_iterated`. There is always something to warm, because the default Uncategorized term is collected (`src/ProviderSync.php:903-906`).
  - `test_warm_refuses_negative_args`: `cursor=-1` answers 400 `InvalidRequest`. The same for `total_updated=-1` and `total_iterated=-1`.
  - `test_search_terms_pages`: 60 terms named `Alpha 01` to `Alpha 60` in `category`, each with a published post. `GET /admin/search?type=term&taxonomy=category&search=Alpha` answers a bare array of 50 rows, `X-WP-Total` `'60'` and `X-WP-TotalPages` `'2'` (set as strings). `page=2` answers 10 rows. `per_page=101` answers 400 `InvalidRequest`. `per_page=100` answers 60 rows.
  - `test_search_authors_pages`: 60 users with display names `Alpha 01` to `Alpha 60`, each with a published post whose `mai_views` meta is above 0, because the author search only returns those (`src/AdminRestApi.php:739-742`). The same paging and headers as the terms test.

- [ ] **Step 2: Run to see them fail** (`--filter Test_Admin_REST_API`).

- [ ] **Step 3: Implement.**
  - Warm's three integer args get `'validate_callback' => 'rest_validate_request_arg'`, which enforces their declared `minimum` for `type: integer` (`wp-includes/rest-api.php:1452-1459`). Core validates before it sanitizes, so `absint` cannot hide `-1` (`class-wp-rest-server.php:1115-1121`).
  - Search gets `page` (`type` integer, `minimum` 1, `default` 1) and `per_page` (`type` integer, `minimum` 1, `maximum` 100, `default` 50), each with `rest_validate_request_arg`. Each query is paged with a `COUNT(DISTINCT ...)` on the same `WHERE`, then `LIMIT %d OFFSET %d` (not `SQL_CALC_FOUND_ROWS`, which MySQL deprecates). Set `X-WP-Total` and `X-WP-TotalPages` as strings. A `term` search with no or a non-public taxonomy still answers `[]`, with `X-WP-Total` `'0'`.
  - `sync_now()`: the no-provider or unavailable branch answers `ProviderUnavailable` with today's reason as the message. Both stored-error branches answer `ProviderError` with the stored message. Success bodies are unchanged.
  - `warm()`: self-hosted answers `ProviderModeRequired` with today's message. Both error branches answer `ProviderError`, passing today's payload (without `message`) as `$legacy`, so the progress fields stay top level.
  - Fix the three docblocks: search "Up to `per_page` matching results (50 by default)", `get_source_breakdown` "Only queries the current page's IDs (at most 100)", `seed --days` default 30.

- [ ] **Step 4: Run the tests.** Expected: pass, and the full suite passes.

- [ ] **Step 5: Commit.** Add `src/AdminRestApi.php src/CLI.php tests/test-admin-rest-api.php`. Subject: "Give admin errors plugin codes, page the search, enforce warm minimums".

---

### Task 5: 100-ID limits

**Files:**
- Create: `src/IdList.php`
- Modify: `src/RestApi.php` (trending `terms` arg `:115-118`), `src/AdminRestApi.php` (`term_id` and `author` args `:63-70`), `assets/js/admin-dashboard.js:661`
- Test: `tests/test-rest-api.php`, `tests/test-admin-rest-api.php`

**Interfaces:**
- Produces: `final class IdList` with `public const MAX = 100;` and `public static function within_limit( mixed $value ): bool`. A scalar is read as a string (`(string) $value`), split on commas, empty entries dropped, and true when 100 or fewer remain. Anything else (an array, null) is false. Entries are not otherwise checked (spec, change 2).

- [ ] **Step 1: Write the failing tests:**
  - trending `terms` with 100 IDs answers 200, with 101 answers 400 `InvalidRequest`, and `data.params` has `terms`.
  - `/admin/top/posts` as an editor with 100 `term_id` IDs (and `taxonomy=category`) answers 200, with 101 answers 400. The same for `author`.
  - signed out, 101 `term_id` IDs answer 401 `rest_forbidden`.
  - `IdList::within_limit( '1,2,' )` is true, and counts 2. `IdList::within_limit( 5 )` is true.

- [ ] **Step 2: Run to see them fail.**

- [ ] **Step 3: Implement.** Add `'validate_callback' => [ IdList::class, 'within_limit' ]` to the three args. In `assets/js/admin-dashboard.js`, change `maxItems: null` to `maxItems: 100` in `initTomSelect`, which both the term and author selects use.

- [ ] **Step 4: Run the tests.** Expected: pass, and the full suite passes.

- [ ] **Step 5: Commit.** Add `src/IdList.php src/RestApi.php src/AdminRestApi.php assets/js/admin-dashboard.js tests/test-rest-api.php tests/test-admin-rest-api.php`. Subject: "Refuse ID lists over 100".

---

### Task 6: Cron cleanup and uninstall

**Files:**
- Modify: `src/Cron.php`, `src/Plugin.php`, `mai-analytics.php:55-66`, `uninstall.php`
- Test: `tests/test-cron.php`, create `tests/test-uninstall.php`

**Interfaces:**
- Produces:
  - `Cron::HOOKS`, a public const list: `'mai_analytics_cron_sync'`, `ProviderSync::CATCHUP_HOOK` (`'mai_analytics_provider_catchup'`), `self::PRUNE_HOOK`, `self::PRUNE_NOW_HOOK`.
  - `public static function Cron::add_schedule_filter(): void`, which adds the existing `add_schedule()` on `cron_schedules` (a `Cron` instance's method, as the constructor does at `src/Cron.php:28`).
  - `public static function Plugin::deactivate(): void`, which clears every hook in `Cron::HOOKS` and nothing else. Public so Mai Publisher can call it later (spec, "Bugs that are not alignment").
  - `public static function Plugin::activate(): void`, which calls `Cron::add_schedule_filter()` first, then runs today's activation body (`Database::create_table()` and the `mai_analytics_cron_sync` schedule), then `Cron::schedule_daily_prune()`. The filter is needed because a real activation loads the plugin file after `plugins_loaded` has fired (`wp-admin/includes/plugin.php:679`), so `Plugin::init()` never ran, and `wp_schedule_event()` refuses an unknown schedule (`wp-includes/cron.php:265-275`).

- [ ] **Step 1: Write the failing tests:**
  - In `tests/test-cron.php`, `test_deactivate_clears_every_hook`: schedule all four hooks, call `Plugin::deactivate()`, assert `wp_next_scheduled()` is false for each in `Cron::HOOKS`, and that the table, `mai_analytics_settings` and a `mai_views` meta row are still there.
  - `test_activate_schedules_sync_and_prune`: clear all hooks, call `remove_all_filters( 'cron_schedules' )` to reproduce a real activation, call `Plugin::activate()`, and assert `mai_analytics_cron_sync` and `Cron::PRUNE_HOOK` are scheduled.
  - `tests/test-uninstall.php` (class `Test_Uninstall`): schedule every hook in `Cron::HOOKS`. Add the options `maiXanalytics_keep` and `mai_analytics_settings`, the transient `mai_analytics_admin_filters`, and `mai_views` meta on a post. Add a `query` filter at **priority 1** that records each query, so it sees the query before the test library turns the drop temporary. Then `define( 'WP_UNINSTALL_PLUGIN', 'mai-analytics/mai-analytics.php' )` if not defined, and `include dirname( __DIR__ ) . '/uninstall.php'`. Assert, with direct `$wpdb->get_var( "SELECT COUNT(*) ..." )` queries because uninstall deletes with raw SQL and the object cache still holds the old values:
    - every hook in `Cron::HOOKS` is unscheduled. This is the drift check between `Cron::HOOKS` and the names written out in `uninstall.php`.
    - a recorded query starts with `DROP TABLE IF EXISTS` and names the buffer table.
    - no `wp_options` row named `mai_analytics_settings` or `_transient_mai_analytics_admin_filters` remains.
    - no `wp_postmeta` row with `meta_key = 'mai_views'` remains.
    - the `maiXanalytics_keep` row remains.

- [ ] **Step 2: Run to see them fail.**

- [ ] **Step 3: Implement.** The activation and deactivation closures in `mai-analytics.php` call `Plugin::activate()` and `Plugin::deactivate()`. `uninstall.php` lists the four hook names as literal strings (it runs without the plugin's autoloader, `wp-admin/includes/plugin.php:1323-1333`), and builds every `LIKE` pattern with `$wpdb->esc_like()` through `$wpdb->prepare()`: `$wpdb->esc_like( 'mai_analytics_' ) . '%'`, `$wpdb->esc_like( '_transient_mai_analytics_' ) . '%'` and `$wpdb->esc_like( '_transient_timeout_mai_analytics_' ) . '%'`.

- [ ] **Step 4: Run the tests.** Expected: pass, and the full suite passes.

- [ ] **Step 5: Check for other unescaped `LIKE`.** Run `rg -n "LIKE" ~/Plugins/_wip/analytics-alignment/src ~/Plugins/_wip/analytics-alignment/includes ~/Plugins/_wip/analytics-alignment/uninstall.php`, and read every hit that matches option, transient or meta names without `esc_like()`. On 2026-11-18 there were two besides uninstall, both read-only and already in `TODO.md`: `src/Migration.php:123` and `src/Health.php:54`. Task 7's `table_exists()` adds a third read-only one, on purpose. Any `LIKE` that deletes or updates is in scope: escape it here, with a test.

- [ ] **Step 6: Commit.** Add `src/Cron.php src/Plugin.php mai-analytics.php uninstall.php tests/test-cron.php tests/test-uninstall.php`. Subject: "Clear every cron hook on deactivate and uninstall, escape uninstall's LIKE".

---

### Task 7: db version only after the table exists

**Files:**
- Modify: `src/Database.php` (`create_table()` `:25-47`, `maybe_update()` `:54-62`, `maybe_migrate_old_table()` `:114-120`), `src/CLI.php` (`health --fix`, the `create_table()` call at `:76-79`)
- Modify: `TODO.md` (one line)
- Test: `tests/test-database.php`

**Interfaces:**
- Produces: `Database::create_table(): bool` (true when the table exists after `dbDelta()`), `public const RETRY_TRANSIENT = 'mai_analytics_db_retry';`, `public static function table_exists(): bool`.

- [ ] **Step 1: Write the failing tests:**
  - `test_create_table_records_version`: delete the option. `create_table()` returns true and the option equals `MAI_ANALYTICS_DB_VERSION`.
  - `test_failed_create_does_not_record_version`: make the table look missing with a `query` filter that rewrites `SHOW TABLES LIKE '` plus the buffer table name to `SHOW TABLES LIKE 'mai_analytics_none'`, delete the option, and add `add_filter( 'dbdelta_queries', '__return_empty_array' )`, which makes `dbDelta()` run nothing (`wp-admin/includes/upgrade.php:2956-2986`). `create_table()` returns false, the option is absent, and `Database::RETRY_TRANSIENT` is set.
  - `test_maybe_update_waits_while_retry_is_set`: with the transient set and the option absent, `maybe_update()` does not call `dbDelta()` (a `dbdelta_queries` filter that counts calls stays at 0).
  - `test_old_version_not_copied_without_table`: set `mai_views_db_version` to `1.0.2`, delete `mai_analytics_table_migrated` and the new option, make the new table look missing and block `dbDelta()` as above, then run `maybe_update()`. `mai_analytics_db_version` is not `1.0.2`.

- [ ] **Step 2: Run to see them fail.**

- [ ] **Step 3: Implement.** `table_exists()` uses `$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::get_table_name() ) )`, the same unescaped check as `maybe_migrate_old_table()` (`:88`), so the test's `query` filter can match the plain name. Core escapes it in its own check (`wp-admin/includes/upgrade.php:2687`); add a `TODO.md` line beside the other read-only `LIKE`s. On failure, `create_table()` logs with `mai_analytics_logger()->error( 'Could not create the mai_analytics_buffer table. Will try again in an hour.' )` and sets the transient for `HOUR_IN_SECONDS`. `maybe_update()` returns early while the transient exists. The old-version copy in `maybe_migrate_old_table()` runs only when `table_exists()`. `health --fix` reports "Table could not be created." when `create_table()` returns false.

- [ ] **Step 4: Run the tests.** Expected: pass, and the full suite passes.

- [ ] **Step 5: Commit.** Add `src/Database.php src/CLI.php tests/test-database.php TODO.md`. Subject: "Record the db version only after the table exists".

---

### Task 8: Em-dashes and the README

**Files:**
- Modify: `src/Admin.php:359`, `src/Providers/SiteKit.php:404`, `src/Providers/Matomo.php:332`, `src/CLI.php:60`, `src/ProviderSync.php:200`, `:667`, `src/Stats.php:420`, `:422`, `:462`, `README.md` (30 lines, and the `mai_analytics_sync_interval` row at `:181`)
- Test: create `tests/test-plain-text.php`

- [ ] **Step 1: Write the failing test** `Test_Plain_Text::test_no_em_dash_outside_comments`: walk every `.php` file under `src/` and `includes/` with `token_get_all()`, and fail on any token that is not `T_COMMENT` or `T_DOC_COMMENT` and contains `"\u{2014}"`, naming the file and line.

- [ ] **Step 2: Run it to see it fail.** Expected: it lists the nine lines above.

- [ ] **Step 3: Rewrite each string.** Exact copy:
  - `Admin.php:359`: the age goes in brackets, `(<?php echo esc_html( $age ); ?>)`, keeping the `<em style="opacity:.7;">` wrapper.
  - `SiteKit.php:404`: `'Could not build the Site Kit Analytics module. Check for a Mai Analytics update. Details: %s'`.
  - `Matomo.php:332`: `$message .= ' Response: ' . $snippet;`
  - `CLI.php:60`: `" ({$c['detail']})"`.
  - Log lines: `ProviderSync.php:200` "... object_id=%d. Skipping the provider fetch for this object.", `:667` "... object_id=%d. Skipping this object.", `Stats.php:420` `' [provider unhealthy, so the stale prune was skipped]'`, `:422` `' [buffer not live, so the stale prune was skipped]'`, `:462` "... via staleness in one run. Check this was intended."
  - README: rewrite each of the 30 lines as plain sentences. The `mai_analytics_sync_interval` row's description becomes "Reported to Mai Publisher only. Does not change the sync schedule."

- [ ] **Step 4: Run the test and the suite.** Also `rg -n '\x{2014}' ~/Plugins/_wip/analytics-alignment/README.md` prints nothing. No existing test asserts these messages (the reviewer checked), so none should need changing. If one does, change it and add it to the commit.

- [ ] **Step 5: Commit.** Add `src/Admin.php src/Providers/SiteKit.php src/Providers/Matomo.php src/CLI.php src/ProviderSync.php src/Stats.php README.md tests/test-plain-text.php`. Subject: "Remove em-dashes from text people read".

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

Expected: no output. Then run the guard from the main checkout, since the worktree's copy was a dev package and is now gone. It only takes `--root=PATH` with an equals sign, where the shell does not expand `~`, so give the path relative to the main checkout, which is where the Bash tool's folder resets to:

```bash
php ~/Plugins/mai-analytics/vendor/bin/deployable-guard check --root=../_wip/analytics-alignment
```

Expected: `OK: committed autoloader is deployable as-is.` The new classes need no autoloader change, because autoload is PSR-4 only (`composer.json`).

- [ ] **Step 3: Rewrite `STATE.md`** on the branch: what was built, the test count, the open naming question, which visibility rule Task 3 used, and the draft changelog lines below for whoever cuts the release. Add to `TODO.md`'s "Later" any follow-up you hit. Draft changelog lines (they go into `CHANGES.md` only on the release commit, with its version and date):
  - `* Changed: [Developers] REST errors now carry a mai_analytics_* code and data.status. View errors keep "success": false and admin errors keep "message".`
  - `* Changed: [Developers] Four error statuses changed: Sync Now answers 409 for a setup problem and 502 for a provider failure (was 500), Warm answers 409 in self-hosted mode (was 400) and 502 for a provider failure (was 500), and a view that fails to save answers 503 with Retry-After (was 200).`
  - `* Changed: [Developers] Core's argument errors answer 400 mai_analytics_invalid_request, after the permission check, so a signed-out request still gets 401.`
  - `* Changed: [Developers] ID lists (trending terms, the dashboard's term and author filters) take at most 100 IDs.`
  - `* Changed: [Developers] The dashboard search takes page and per_page and sends X-WP-Total and X-WP-TotalPages.`
  - `* Changed: [Developers] Warm refuses a negative cursor, total_updated or total_iterated with 400 instead of turning it positive.`
  - `* Changed: Views of an author archive for someone with no published posts are no longer counted, and their count is no longer public, so the counts can't be used to find which user IDs exist.`
  - `* Fixed: View counts for drafts, private posts and other hidden items could be read by anyone. They now answer 404, and trending no longer lists them.`
  - `* Fixed: A view that failed to save was reported as saved.`
  - `* Fixed: Deactivating left three scheduled tasks behind, and uninstalling left two.`
  - `* Fixed: The database version could be recorded when the table had not been created.`
  - `* Fixed: Sync Now answered a server error for a setup problem.`

- [ ] **Step 4: Commit.** Add `STATE.md TODO.md`. Subject: "State after the alignment build".

- [ ] **Step 5: Gate. Stop here.**
  - Pushing `feat/alignment` is fine without asking (Mike's rule, through mai-coordinator, 2026-11-18: pushes to branches never need asking). Never push `develop` or `main` from here.
  - Do not merge `feat/alignment` into `develop` without mai-coordinator's grant, because the shared production-copy site loads `develop`.
  - Do not use the shared production-copy site in any way (no activation, WP-CLI, e2e or browser) without a claim from mai-coordinator.
  - No version bump, tag or release. Those need Mike's yes, and no tag questions until the whole stack passes its tests.
  - If Mike renames any code in his walk, change only the `ErrorCode` case values and rerun the suite.
  - Report to mai-coordinator: the branch, its last commit, the test count, the test library check from Task 0, which visibility rule Task 3 used, and anything that did not go as this plan says.

# Cleanup B: Green the Test Suite Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Take the mai-analytics PHPUnit suite from 28 failures + 1 error to green, so it is a trustworthy safety net that can be gated in CI. Two root causes: a `tearDown` `TRUNCATE` isolation bug and drifted stale-expectation tests.

**Architecture:** Fix the isolation bug first (it inflates the failure count and masks which failures are real), re-run to see what genuinely remains, then triage-and-fix the actually-stale tests file by file. Test-only changes — no production code changes expected.

**Tech Stack:** PHP, PHPUnit 9.6 + WP test suite (`WP_UnitTestCase`), `wpdb`.

**Spec:** `docs/superpowers/specs/2026-07-03-view-stats-lifecycle-design.md`

## Global Constraints

- **Prerequisite:** `2026-07-06-mai-logger-0.1.1.md` must be done first (committed vendored 0.1.1), or a clean clone / CI can't run the suite at all.
- **Test-only.** If fixing a "stale" test seems to require changing `src/`, STOP — that means the test found a real bug, not drift. Surface it, don't silently change production code under cover of a test fix.
- **The isolation bug:** a `tearDown` that runs `TRUNCATE TABLE` executes DDL, which forces an implicit COMMIT, which defeats `WP_UnitTestCase`'s per-test transaction rollback, leaking one test's rows into the next. The proven fix (already applied to `tests/test-stats.php` this release): keep `Database::create_table()` in `setUp()` (idempotent no-op when the table exists, so no DDL, so the transaction stays intact) and DROP the `TRUNCATE` from `tearDown()`, letting rollback clean up. Keep any `delete_option()` / `wp_clear_scheduled_hook()` cleanup.
- **6 files carry the TRUNCATE teardown:** `test-rest-api.php`, `test-admin-rest-api.php`, `test-database.php`, `test-provider-sync.php`, `test-cron.php`, `test-sync.php`. (`test-stats.php` is already fixed.)
- Run command: `WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit` (append `--filter '<Class>'` for one class). PHPUnit 9.6 needs PHP <= 8.3.
- Assert trending DELETION with `metadata_exists( 'post', $id, 'mai_trending' )` (registered default of 0 masks `get_post_meta`). See [[reference_mai_analytics_release]] is unrelated; the deletion-assertion convention is in the Plan 1 doc.

---

### Task 1: Capture the baseline failure list

- [ ] **Step 1: Run the full suite and save the exact failing tests**

Run: `WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit 2>&1 | grep -E '^[0-9]+\) ' | sed -E 's/^[0-9]+\) //' | sort > /tmp/fails-before.txt`
Expected (as of 7/6/26): 29 lines — Test_Admin_REST_API (7), Test_Bot_Filter::test_detects_curl, Test_Meta (6), Test_Provider_Sync (7), Test_Providers_Matomo (2), Test_REST_API::test_reject_bot_user_agent, Test_Sync (5).

- [ ] **Step 2: Commit the baseline note** (optional) — record the starting count in the PR/commit body so the delta is visible.

---

### Task 2: Apply rollback-based isolation to the 5 DML test files

These five insert buffer rows (DML) and only `TRUNCATE` for cleanup, so rollback covers them. `test-database.php` is handled separately in Task 3 (it does table DDL as its subject).

**Files:**
- Modify: `tests/test-rest-api.php`, `tests/test-admin-rest-api.php`, `tests/test-provider-sync.php`, `tests/test-cron.php`, `tests/test-sync.php` (setUp/tearDown only)

- [ ] **Step 1: For each of the 5 files, ensure `setUp()` has `Database::create_table()` and remove the `TRUNCATE` line from `tearDown()`**

Pattern (matching the already-fixed `test-stats.php`):
```php
public function setUp(): void {
    parent::setUp();
    \Mai\Analytics\Database::create_table(); // idempotent; no DDL when the table exists
}

public function tearDown(): void {
    // NO `TRUNCATE` here — it implicitly commits and breaks per-test rollback.
    // Keep only non-DDL cleanup:
    delete_option( 'mai_analytics_settings' );      // keep whatever options the file set
    delete_option( 'mai_analytics_provider_error' );
    // wp_clear_scheduled_hook(...) as needed
    parent::tearDown();
}
```
Preserve each file's existing option/hook cleanup lines; only the `$wpdb->query('TRUNCATE ...')` line is removed.

- [ ] **Step 2: Run each file in isolation to confirm it still passes alone**

Run: `WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit --filter 'Test_Sync'` (repeat for each class)
Expected: each class passes on its own (matches pre-change behavior — isolation runs were already green).

- [ ] **Step 3: Run the FULL suite and diff against baseline**

Run: `WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit 2>&1 | grep -E '^[0-9]+\) ' | sed -E 's/^[0-9]+\) //' | sort > /tmp/fails-after.txt; diff /tmp/fails-before.txt /tmp/fails-after.txt`
Expected: strictly fewer failures. Record which tests flipped green (isolation victims) vs. which remain (genuinely stale). The remaining set is the input to Tasks 4+.

- [ ] **Step 4: Commit**
```bash
git add tests/test-rest-api.php tests/test-admin-rest-api.php tests/test-provider-sync.php tests/test-cron.php tests/test-sync.php
git commit -m "test: rollback-based isolation (drop committing TRUNCATE teardowns)"
```

---

### Task 3: Handle `test-database.php` isolation

`test-database.php` legitimately exercises table create/drop (DDL), so its own DDL commits and it can leak into later files regardless of its teardown.

- [ ] **Step 1: Read `tests/test-database.php`** and determine whether its `TRUNCATE`/DDL is (a) the test subject (keep, but isolate) or (b) just cleanup (remove like Task 2).
- [ ] **Step 2:** If it must do DDL, move table setup to `setUpBeforeClass()` / `tearDownAfterClass()` so per-test transactions aren't broken, OR confirm it runs last / is self-contained enough not to leak. Re-run the full suite to confirm no new cross-file failures.
- [ ] **Step 3: Commit** the test-database change if any.

---

### Task 4+: Triage and fix the genuinely-stale tests (one task per file/cluster)

After Task 2–3, whatever still fails is drift. Create one task per remaining file from `/tmp/fails-after.txt`. Likely clusters (confirm against the post-isolation list): **Test_Meta** (6), **Test_Sync** (remaining), **Test_Provider_Sync** (remaining), **Test_Admin_REST_API** (7), **Test_Providers_Matomo** (2), **Test_Bot_Filter**, **Test_REST_API**.

For EACH remaining failing test, the procedure (no fabricated expectations — this is genuinely per-test investigation):

- [ ] **Step 1: Read the failing test and the code it exercises.** Run it alone with `-v` to see the exact assertion diff: `vendor/bin/phpunit --filter '<Class>::<method>'`.
- [ ] **Step 2: Decide drift vs. real bug.** If the current `src/` behavior is correct and the test asserts old behavior → drift (update the test). If the code is actually wrong → STOP and surface it (do not fix production under a test task).
- [ ] **Step 3: Update the test's expectation** to match the correct current behavior. Use `metadata_exists()` for trending-deletion assertions.
- [ ] **Step 4: Run the file to confirm green:** `vendor/bin/phpunit --filter '<Class>'`.
- [ ] **Step 5: Commit per file:** `git commit -m "test: update stale expectations in <Class>"`.

---

### Task Final: Whole-suite green + CI gate

- [ ] **Step 1:** `WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit` → expect `OK` (0 failures, 0 errors).
- [ ] **Step 2 (approved):** add a minimal GitHub Actions workflow (`.github/workflows/tests.yml`) that installs the WP test lib + runs `composer test` on PHP 8.3, triggered on PRs to `develop` and `main`, so the suite can't silently die again. Requires mai-logger 0.1.1 to be vendored (the prereq plan) or CI can't boot PHPUnit either.
- [ ] **Step 3: Commit** and open a PR to develop titled "Cleanup B: green test suite."

## Self-review note
The exact count of "stale vs isolation-victim" is unknown until Task 2 runs — the PR estimated 26 stale + 2 isolation-victims, but several failures in the full-suite run were cross-file leakage that Task 2/3 should clear. Do NOT assume all 28 are stale; let the post-isolation diff drive Tasks 4+.

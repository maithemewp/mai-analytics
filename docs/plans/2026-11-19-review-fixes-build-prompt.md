# Build prompt: review fixes

Paste everything in the code block into a fresh session. It is the only context the builder gets.

```
Build the review fixes for mai-analytics.

Repo: ~/Plugins/mai-analytics (use the full absolute path in commands). Read AGENTS.md (CLAUDE.md links to it), STATE.md, and git log --oneline -15 first. The plan is docs/plans/2026-11-19-review-fixes.md. Build all of it. Every naming decision in it is already made by Mike.

Where to work: the main checkout stays on develop with docs-only commits, because the shared production-copy site loads it through a symlink. Never switch its branch or put code in it. Make a git worktree on a new branch feat/review-fixes cut from local develop (its head, not origin/develop, which is behind) and do all code work there. Commit one task at a time, with a failing test first where a test can show the bug.

Rules:
- Edit files with Read, Edit and Write, never python, sed or heredocs.
- One plain Bash command per call, with full paths: no &&, no shell variables, no $(...). An env var in front of a command, like WP_TESTS_DIR=/tmp/wordpress-tests-lib, is fine.
- Stage with git -C <path> add <files>, then commit with git -C <path> commit -q -m "..." (one -m per paragraph), each as its own call. End commits with: Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
- PHP floor stays 8.1. declare( strict_types=1 ) in new files only. Thorough PHPDoc with @since 1.3.8. Nothing released is renamed. No em-dashes in any text a person reads, logs and commit messages included. Search with rg.
- Never commit vendor/ changes. The commit hook refuses private site names, so write "the shared production-copy site".
- Before running the suite, read the Status section of SITE-LOCK.md in the shared production-copy site's folder (ask Mike for the path if you don't have it), and check that uptime shows a 1-minute load under 10. Don't run while a device run is claimed. Never touch that site itself (no WP-CLI, activation or browser).
- Pushing feat/review-fixes is fine. Never merge into develop, and never push develop or main: merging needs the coordinator's grant, which Mike will relay. No version bump, tag or release, and no tag talk until the whole stack passes its tests.
- Nothing may take the foreground.

Verify: WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit in the worktree after composer install. 267 tests pass at the start. Then restore vendor/ as STATE.md's Verify section says and run php vendor/bin/deployable-guard check --root=. before the no-dev install removes it.

When done: rewrite STATE.md in the worktree branch, push feat/review-fixes, and give Mike the branch head and test count to pass to the coordinator for a merge grant.
```

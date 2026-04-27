# Bundle Mai Analytics in Mai Publisher — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bundle Mai Analytics into Mai Publisher as a Composer dependency and remove Mai Publisher's redundant built-in views system.

**Architecture:** Mai Analytics is added to Mai Publisher's `composer.json` as a VCS repo pulling `dev-main` from GitHub. Mai Publisher's views class, deprecated function wrappers, views settings, and AJAX view scheduling are removed entirely. The unused `wp-background-processing` dependency is also cleaned up.

**Tech Stack:** WordPress, Composer, PHP 8.2+, GitHub (VCS repository)

**Repos:**
- Mai Analytics: `~/Plugins/mai-views/` (local) → `github.com/maithemewp/mai-analytics` (remote)
- Mai Publisher: `~/Plugins/mai-publisher/` (local) → `github.com/bizbudding/mai-publisher` (remote)

---

## File Structure

### Mai Publisher files to modify
- `~/Plugins/mai-publisher/composer.json` — Add VCS repo + require for mai-analytics, remove wp-background-processing + Strauss config
- `~/Plugins/mai-publisher/mai-publisher.php:152-155` — Remove Views instantiation + gate
- `~/Plugins/mai-publisher/classes/class-settings.php:169-174, 482-520, 706-708, 1316-1397` — Remove views settings section, fields, and callbacks
- `~/Plugins/mai-publisher/classes/class-tracking.php:132-185` — Remove views API / AJAX scheduling block

### Mai Publisher files to delete
- `~/Plugins/mai-publisher/classes/class-views.php`
- `~/Plugins/mai-publisher/includes/functions-views.php`
- `~/Plugins/mai-publisher/vendor-prefixed/deliciousbrains/` (entire directory)

### Mai Publisher files to keep unchanged
- `~/Plugins/mai-publisher/classes/class-author-views.php`
- `~/Plugins/mai-publisher/classes/class-compatibility.php`

---

### Task 1: Push Mai Analytics rename to GitHub

The rename work (Mai Views → Mai Analytics) was done earlier in this session but hasn't been pushed. This must happen before Composer can pull from the repo.

**Repo:** `~/Plugins/mai-views/`

- [ ] **Step 1: Verify rename is clean**

Run from `~/Plugins/mai-views/`:
```bash
grep -rn 'MAI_VIEWS\|Mai\\Views' src/ includes/ mai-analytics.php tests/ | grep -v vendor/
```
Expected: Zero results.

- [ ] **Step 2: Commit the rename**

```bash
cd ~/Plugins/mai-views
git add -A
git commit -m "Rename Mai Views to Mai Analytics

Renames plugin from Mai Views to Mai Analytics. All code-level identifiers
(namespace, constants, hooks, functions, CSS, JS, REST namespace, WP-CLI,
admin slugs) change to mai_analytics. Meta keys (mai_views, mai_views_web,
mai_views_app, mai_trending) and [mai_views] shortcode stay unchanged.

Adds uninstall.php for clean plugin removal.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 3: Push to develop**

```bash
git push origin develop
```

- [ ] **Step 4: Rename GitHub repo**

The user renames `maithemewp/mai-views` to `maithemewp/mai-analytics` via GitHub Settings > General > Repository name. This is a manual step.

- [ ] **Step 5: Merge develop to main**

```bash
git checkout main
git merge develop
git push origin main
git checkout develop
```

---

### Task 2: Remove views class and deprecated functions from Mai Publisher

**Repo:** `~/Plugins/mai-publisher/`

- [ ] **Step 1: Delete class-views.php**

```bash
cd ~/Plugins/mai-publisher
git rm classes/class-views.php
```

- [ ] **Step 2: Delete functions-views.php**

```bash
git rm includes/functions-views.php
```

- [ ] **Step 3: Remove Views instantiation from mai-publisher.php**

In `~/Plugins/mai-publisher/mai-publisher.php`, remove lines 152-155:

```php
		// Mai Analytics handles view tracking when available (standalone or via Composer).
		if ( ! defined( 'MAI_ANALYTICS_VERSION' ) ) {
			new Mai_Publisher_Views;
		}
```

Leave the surrounding code (`new Mai_Publisher_Output;` above, `new Mai_Publisher_Tracking;` below) intact.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "Remove built-in views class and deprecated wrappers

Mai Analytics replaces this functionality entirely. Removes class-views.php,
functions-views.php (maipub_get_views, maipub_get_view_count,
maipub_get_short_number), and the MAI_ANALYTICS_VERSION gate in
mai-publisher.php.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Remove views settings from Mai Publisher

**Repo:** `~/Plugins/mai-publisher/`

- [ ] **Step 1: Remove the views settings section registration**

In `~/Plugins/mai-publisher/classes/class-settings.php`, remove lines 169-174:

```php
		add_settings_section(
			'maipub_settings_views', // id
			'', // title
			[ $this, 'maipub_section_views' ], // callback
			'mai-publisher-section' // page
		);
```

- [ ] **Step 2: Remove the views settings fields block**

In the same file, remove lines 482-520 (the entire `if ( ! defined( 'MAI_ANALYTICS_VERSION' ) )` block including the comment above it):

```php
		/**
		 * Views
		 *
		 * When Mai Analytics is active, view tracking settings live in its own settings page.
		 */

		if ( ! defined( 'MAI_ANALYTICS_VERSION' ) ) {
			add_settings_field(
				'views_api', // id
				...
			);

			add_settings_field(
				'views_years', // id
				...
			);

			add_settings_field(
				'trending_days', // id
				...
			);

			add_settings_field(
				'views_interval', // id
				...
			);
		}
```

- [ ] **Step 3: Remove the section callback**

Remove the `maipub_section_views()` method (around line 706-708):

```php
	function maipub_section_views() {
		printf( '<h3 class="heading-tab"><span class="heading-tab__text">%s</span></h3>', __( 'Views', 'mai-publisher' ) );
	}
```

- [ ] **Step 4: Remove the four field callbacks**

Remove these four methods (around lines 1316-1397):
- `views_api_callback()` (lines 1316-1335)
- `views_years_callback()` (lines 1344-1356)
- `trending_days_callback()` (lines 1365-1377)
- `views_interval_callback()` (lines 1386-1397)

- [ ] **Step 5: Commit**

```bash
git add classes/class-settings.php
git commit -m "Remove views settings section from Mai Publisher settings

Views settings (views_api, views_years, trending_days, views_interval)
now live in Mai Analytics' own settings page.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Remove views API logic from tracking class

**Repo:** `~/Plugins/mai-publisher/`

- [ ] **Step 1: Remove the views API block from class-tracking.php**

In `~/Plugins/mai-publisher/classes/class-tracking.php`, remove lines 132-185 — everything from the `// Get views API.` comment through the closing `}` of the `if ( $is_matomo || $is_jetpack )` block, up to but not including the `// Add site analytics.` comment:

```php
		// Get views API.
		// Skip AJAX-based view update scheduling when Mai Analytics handles this via beacon + cron.
		$views_api  = defined( 'MAI_ANALYTICS_VERSION' ) ? 'disabled' : maipub_get_option( 'views_api' );
		$is_matomo  = 'matomo'  === $views_api;
		$is_jetpack = 'jetpack' === $views_api;

		// If an api we can use.
		if ( $is_matomo || $is_jetpack ) {
			... (entire block through line 185)
		}
```

Leave the `// Add site analytics.` block (line 187+) intact.

- [ ] **Step 2: Commit**

```bash
git add classes/class-tracking.php
git commit -m "Remove AJAX-based view scheduling from tracking class

The views API logic scheduled AJAX calls to maipub_views which no longer
exists. Mai Analytics handles view tracking via beacon + cron instead.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Remove wp-background-processing dependency

**Repo:** `~/Plugins/mai-publisher/`

- [ ] **Step 1: Update composer.json**

In `~/Plugins/mai-publisher/composer.json`, remove `"deliciousbrains/wp-background-processing": "^1.4"` from `require`, and remove the entire `extra.strauss` block.

The result should be:

```json
{
    "require": {
        "yahnis-elsts/plugin-update-checker": "^5.5",
        "matomo/matomo-php-tracker": "^3.2"
    },
    "scripts": {
        "prefix-namespaces": [
            "sh -c 'test -f ./bin/strauss.phar || curl -o bin/strauss.phar -L -C - https://github.com/BrianHenryIE/strauss/releases/latest/download/strauss.phar'",
            "@php bin/strauss.phar",
            "@composer dump-autoload"
        ],
        "post-install-cmd": [
            "@prefix-namespaces"
        ],
        "post-update-cmd": [
            "@prefix-namespaces"
        ],
        "post-autoload-dump": [
            "@php bin/strauss.phar include-autoloader"
        ]
    }
}
```

Note: Keep the Strauss scripts — they may be used for future dependencies. Only the `extra.strauss` config block and the `deliciousbrains` require are removed.

- [ ] **Step 2: Delete the vendor-prefixed deliciousbrains directory**

```bash
rm -rf ~/Plugins/mai-publisher/vendor-prefixed/deliciousbrains/
```

- [ ] **Step 3: Regenerate vendor-prefixed autoload**

```bash
cd ~/Plugins/mai-publisher
composer dump-autoload
```

This should regenerate `vendor-prefixed/composer/autoload_classmap.php` and `autoload_static.php` without the `Mai_Publisher_WP_Async_Request` / `Mai_Publisher_WP_Background_Process` entries.

- [ ] **Step 4: Verify no references remain**

```bash
grep -rn 'WP_Background_Process\|WP_Async_Request\|deliciousbrains' ~/Plugins/mai-publisher/classes/ ~/Plugins/mai-publisher/includes/
```

Expected: Zero results.

- [ ] **Step 5: Commit**

```bash
cd ~/Plugins/mai-publisher
git add -A
git commit -m "Remove unused wp-background-processing dependency

Package was required but never referenced in any class. Removes composer
require, Strauss config, and vendor-prefixed output.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Add Mai Analytics as Composer dependency

**Repo:** `~/Plugins/mai-publisher/`

**Prerequisite:** Task 1 must be complete (Mai Analytics pushed to GitHub and repo renamed).

- [ ] **Step 1: Add VCS repository and require**

In `~/Plugins/mai-publisher/composer.json`, add the `repositories` key and add `maithemewp/mai-analytics` to `require`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/maithemewp/mai-analytics"
        }
    ],
    "require": {
        "yahnis-elsts/plugin-update-checker": "^5.5",
        "matomo/matomo-php-tracker": "^3.2",
        "maithemewp/mai-analytics": "dev-main"
    },
    "scripts": { ... }
}
```

- [ ] **Step 2: Run composer update**

```bash
cd ~/Plugins/mai-publisher
composer update maithemewp/mai-analytics
```

Expected: Composer clones the repo and installs into `vendor/maithemewp/mai-analytics/`.

- [ ] **Step 3: Verify installation**

```bash
ls ~/Plugins/mai-publisher/vendor/maithemewp/mai-analytics/mai-analytics.php
```

Expected: File exists.

```bash
grep 'Mai\\\\Analytics' ~/Plugins/mai-publisher/vendor/composer/autoload_psr4.php
```

Expected: Shows `'Mai\\Analytics\\' => array(...)`.

- [ ] **Step 4: Remove the vendor-prefixed autoload require**

In `~/Plugins/mai-publisher/mai-publisher.php`, line 122 loads the Strauss vendor-prefixed autoload. Since we removed the only Strauss-managed package (wp-background-processing), check if `vendor-prefixed/autoload.php` still exists and is needed. If the directory is empty or only has the autoloader with no classes, remove:

```php
require_once __DIR__ . '/vendor-prefixed/autoload.php';
```

If other prefixed packages still exist, keep it.

- [ ] **Step 5: Commit**

```bash
cd ~/Plugins/mai-publisher
git add -A
git commit -m "Bundle Mai Analytics as Composer dependency

Adds maithemewp/mai-analytics (dev-main) via VCS repository. Mai Analytics
self-bootstraps on plugins_loaded via Composer's files autoload. The
MAI_ANALYTICS_VERSION constant guard prevents double-loading if also
installed standalone.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Verify integration

**Repo:** `~/Plugins/mai-publisher/`

- [ ] **Step 1: Verify no stale views references**

```bash
grep -rn 'Mai_Publisher_Views\|maipub_views\|MAI_VIEWS_VERSION' ~/Plugins/mai-publisher/classes/ ~/Plugins/mai-publisher/includes/ ~/Plugins/mai-publisher/mai-publisher.php | grep -v vendor/
```

Expected: Zero results. (The `mai_views` meta key references in `class-author-views.php` and `class-compatibility.php` are expected and correct — they are NOT `Mai_Publisher_Views` or `MAI_VIEWS_VERSION`.)

- [ ] **Step 2: Verify Mai Analytics loads from vendor**

```bash
grep -c 'mai-analytics' ~/Plugins/mai-publisher/vendor/composer/autoload_files.php
```

Expected: 1 (the `mai-analytics.php` file autoload entry).

- [ ] **Step 3: Spot-check on a local WordPress site**

Activate Mai Publisher on a local WordPress site. Verify:
1. Settings > Mai Analytics page loads (from bundled Mai Analytics)
2. No PHP fatal errors in the error log
3. `[author_views]` shortcode renders (from Mai Publisher — reads `mai_views` meta)
4. `[mai_views]` shortcode renders (from Mai Analytics)

This step is manual and depends on having a local WordPress environment.

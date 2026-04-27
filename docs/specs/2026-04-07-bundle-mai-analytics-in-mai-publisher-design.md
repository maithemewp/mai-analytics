# Bundle Mai Analytics into Mai Publisher

## Context

Mai Analytics (formerly Mai Views) is a standalone view-tracking plugin that will be bundled inside Mai Publisher as a Composer dependency. This replaces Mai Publisher's built-in views system (`class-views.php`) with the more capable Mai Analytics plugin. The bundling makes deployment atomic — sites get both changes in one Mai Publisher update.

## Composer Integration

Add `maithemewp/mai-analytics` to Mai Publisher's `composer.json` as a VCS dependency pulling from `dev-main`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/maithemewp/mai-analytics"
        }
    ],
    "require": {
        "maithemewp/mai-analytics": "dev-main"
    }
}
```

Mai Publisher commits `vendor/` to git, so `composer require` + commit is the full deploy pipeline. No CI/CD or build step needed.

Mai Analytics self-bootstraps via Composer's `files` autoload — its `mai-analytics.php` hooks into `plugins_loaded`. Existing guards handle edge cases:

- **Double-load prevention:** `MAI_ANALYTICS_VERSION` constant guard in `mai-analytics.php` returns early if already defined.
- **Updater skip:** `Plugin::setup_updater()` bails when the plugin path contains `/vendor/`.
- **Standalone wins:** If installed as both standalone plugin and Composer dependency, the standalone version loads first (WordPress loads plugins before `vendor/autoload.php`), so it defines the constant and the bundled copy skips.

## Code Removal from Mai Publisher

### Remove entirely

| File/Code | Reason |
|-----------|--------|
| `classes/class-views.php` | Fully replaced by Mai Analytics |
| `includes/functions-views.php` | Deprecated wrappers (`maipub_get_views`, etc.) — Mai Analytics provides the functions directly |
| `new Mai_Publisher_Views` + `MAI_ANALYTICS_VERSION` gate in `mai-publisher.php` | Class is deleted, gate is unnecessary |
| Views settings section in `classes/class-settings.php` | The entire `maipub_settings_views` section (`views_api`, `views_years`, `trending_days`, `views_interval` fields and their callbacks) — now in Mai Analytics' settings page |
| Views API logic in `classes/class-tracking.php` | The `$views_api` / AJAX scheduling block — the AJAX handler it targets no longer exists |
| `deliciousbrains/wp-background-processing` from `composer.json` | Confirmed unused — no classes in `classes/` reference it |
| Strauss config for `wp-background-processing` in `composer.json` `extra.strauss` | No longer needed without the package |
| `vendor-prefixed/deliciousbrains/` directory | Generated files from the removed package |

### Keep unchanged

| File/Code | Reason |
|-----------|--------|
| `classes/class-author-views.php` | Frontend `[author_views]` shortcode — reads `mai_views`/`mai_trending` meta. Stays until replaced by Mai Analytics dashboard feature (follow-up). |
| `classes/class-compatibility.php` | ElasticPress indexing for `mai_views`/`mai_trending` meta keys. Still needed. |

## GitHub Prerequisites

Before `composer require` works:

1. **Rename GitHub repo** from `maithemewp/mai-views` to `maithemewp/mai-analytics` (was originally that name). GitHub auto-redirects old URL.
2. **Push the rename work** to `main` branch — the rename from Mai Views to Mai Analytics that was done in the current session.

No tag needed. Composer pulls `dev-main` directly.

## Verification

After bundling, verify on a local/staging WordPress site:

1. Mai Analytics dashboard loads at **Settings > Mai Analytics**
2. `wp mai-analytics health` passes all checks
3. Beacon tracking fires on the frontend (`/wp-json/mai-analytics/v1/view/...`)
4. `[mai_views]` shortcode renders view counts
5. `[author_views]` shortcode still works (from Mai Publisher)
6. No `wp_ajax_maipub_views` hook registered (old views class gone)
7. **Double-load test:** Install Mai Analytics standalone alongside bundled — standalone wins, no fatal errors
8. **Uninstall standalone test:** Remove standalone — bundled version takes over

## Follow-ups (Out of Scope)

- **Author performance dashboard:** Add per-author post drill-down to Mai Analytics admin dashboard, replacing the `[author_views]` shortcode. Then remove `class-author-views.php` from Mai Publisher.

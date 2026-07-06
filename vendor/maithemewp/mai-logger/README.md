# Mai Logger

A tiny, versioned logger for WordPress plugins. Drop-in via Composer. The newest installed version across all active plugins wins at runtime — no Strauss prefixing required.

## Install

This package is distributed via GitHub, not Packagist. Add it as a VCS repository in your plugin's `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/maithemewp/mai-logger" }
    ],
    "require": {
        "maithemewp/mai-logger": "^0.1"
    }
}
```

Then `composer install`. Composer will fetch the latest tagged release.

### Local development of mai-logger itself

If you're hacking on this package locally and want a consuming plugin to pull from your working copy:

```json
{
    "repositories": [
        { "type": "path", "url": "/path/to/local/mai-logger", "options": { "symlink": false } }
    ],
    "require": {
        "maithemewp/mai-logger": "@dev"
    }
}
```

Use `"symlink": false` (mirror mode), not symlink mode. Strauss has a known bug that deletes any `vendor/` subdirectory whose only contents are symlinks, which would nuke `vendor/maithemewp/` on every install. Mirror mode copies real files and avoids the problem. Run `composer update maithemewp/mai-logger` after each edit to propagate changes into the consumer's `vendor/`.

## Use

In your plugin's main bootstrap, make sure Composer's autoloader runs:

```php
require_once __DIR__ . '/vendor/autoload.php';
```

Then add a per-plugin helper. The function name should be unique to your plugin so it can't collide with helpers in other plugins:

```php
// includes/functions.php
function my_plugin_logger(): Mai_Logger {
    static $logger;
    return $logger ??= new Mai_Logger( 'my-plugin' );
}
```

Call it anywhere:

```php
my_plugin_logger()->info( 'Hello' );
my_plugin_logger()->error( 'Something broke', $context_array );
```

The constructor accepts either a plugin slug (used verbatim as the log-line prefix) or a file path like `__FILE__` (the slug is derived via `plugin_basename( dirname( $path ) )`). The slug form is recommended — it's explicit, predictable, and shows up in every log line.

## Logging behavior

| Level | Always logs | When `WP_DEBUG` on | Where |
|---|---|---|---|
| `error()` | Yes | — | Ray + WP-CLI + `debug.log` |
| `warning()` | No | Yes | Ray + WP-CLI + `debug.log` |
| `info()` | No | Yes | Ray + WP-CLI **only** |
| `success()` | No | Yes | Ray + WP-CLI **only** |

`info` and `success` deliberately never go to `debug.log` — they're for development output (Ray, WP-CLI), not production logs.

## How version negotiation works

Each plugin Composer-installs its own copy of `mai-logger` into its `vendor/`. When a plugin's `vendor/autoload.php` runs, this package's `init.php` is included automatically (via Composer's `"files"` autoload). That registers the bundled version into `Mai_Logger_Bootstrap`'s static registry.

The actual `Mai_Logger` class is **not** loaded via Composer's autoloader. It's loaded lazily by a custom autoloader that picks the highest registered version on first reference.

Result:
- Plugin A bundles v0.1, Plugin B bundles v0.2 → `new Mai_Logger()` always uses v0.2.
- Bug fixes propagate the moment any plugin on the site is updated.
- Logging works during activation and early boot — no hook timing required.

## API stability contract

This contract exists because all consuming plugins share one loaded class at runtime.

**`Mai_Logger` (the class):**
- Public methods are **additive only**. Never rename or remove.
- Constructor signature is frozen: `( string $name_or_file )`.
- If you ever truly need a breaking change, fork to a new class name (`Mai_Logger_V2`) and leave this one untouched.

**`Mai_Logger_Bootstrap` (the registration class):**
- The signature `register( string $version, string $path ): void` is frozen forever.
- Older plugins out in the wild will keep calling this exact signature. Don't change it.

**Versioning:**
- Strict semver. Patch = bug fix only. Minor = additive only. Major = … see "fork to new class name" above.
- Always tag releases and tell consumers to require a tagged constraint (e.g. `^0.1`). Tracking `dev-main` is fine for local development but ships unreleased code to production.
- The version string registered with `Mai_Logger_Bootstrap` is the literal value hardcoded in this package's `init.php` — bump it in the same commit as any change to `Mai_Logger.php`. Otherwise the bootstrap will register a stale version and the negotiation will pick the wrong file.

## Edge cases

- **Same version registered twice** (two plugins bundle v0.1.0): second registration overwrites first with the same path. Harmless.
- **Two plugins, same version string, different files** (someone forked): registration order decides. Fix: bump the version when you fork.
- **One plugin requires another that requires mai-logger** (plain Composer, e.g. `mai-publisher` bundling `mai-analytics`): Composer flattens the dep tree, so a single copy lands in the parent plugin's `vendor/`. The shared registry works as designed — both plugins see the same loaded class. No isolation, no special handling needed.
- **Consumer uses Strauss to prefix `vendor/`:** the prefixed copy lives in its own namespace and never registers with `Mai_Logger_Bootstrap`. That copy is fully isolated. Working as intended — Strauss exists specifically to enforce isolation. The maithemewp plugins do **not** use Strauss for inter-plugin bundling, so this case doesn't apply to them.

## License

GPL-2.0-or-later

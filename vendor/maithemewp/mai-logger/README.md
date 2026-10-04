# Mai Logger

A tiny logger for WordPress plugins, installed with Composer. Each plugin can bundle its own copy, and [maithemewp/mai-package-loader](https://github.com/maithemewp/mai-package-loader) loads the newest one on the site, whichever plugin loads first.

## Install

This package is distributed through GitHub, not Packagist. Add its repository, and the loader's, to your plugin's `composer.json`. Composer only reads repository lists from the plugin itself, so both are listed:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/maithemewp/mai-logger" },
        { "type": "vcs", "url": "https://github.com/maithemewp/mai-package-loader" }
    ],
    "require": {
        "maithemewp/mai-logger": "^0.2"
    }
}
```

Then `composer install`. Needs PHP 8.1 or later.

### Local development of mai-logger itself

If you're hacking on this package locally and want a consuming plugin to pull from your working copy:

```json
{
    "repositories": [
        { "type": "path", "url": "/path/to/local/mai-logger", "options": { "symlink": false } },
        { "type": "path", "url": "/path/to/local/mai-package-loader", "options": { "symlink": false } }
    ],
    "require": {
        "maithemewp/mai-logger": "@dev",
        "maithemewp/mai-package-loader": "@dev"
    }
}
```

The loader is listed too: Composer only honours `@dev` on the plugin's own requirements.

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

- **`error()`** logs even with `WP_DEBUG` off.
  - Goes to Ray and WP-CLI.
  - Goes to `debug.log` only when `WP_DEBUG_LOG` is on.
- **`warning()`** needs `WP_DEBUG` on.
  - Goes to Ray and WP-CLI.
  - Goes to `debug.log` when `WP_DEBUG_LOG` is on too.
- **`info()` and `success()`** need `WP_DEBUG` on.
  - Go to Ray and WP-CLI only.

Nothing is written to the log while `WP_DEBUG_LOG` is off, so a production site that has not turned on its debug log stays quiet. Under WP-CLI, every level goes to the console instead of `debug.log`.

`info` and `success` deliberately never go to `debug.log` — they're for development output (Ray, WP-CLI), not production logs.

## Several plugins bundling it

Each plugin installs its own copy into its `vendor/`. Every copy ships a `mai-package.php` declaring its version, and mai-package-loader loads `Mai_Logger` from the newest copy on the site, whichever plugin loads first. `tests/coexistence.sh` proves it with real Composer installs, against a 0.1.2 copy.

Up to 0.1.2, copies used their own bootstrap, which in practice always loaded the first plugin's copy, because Composer runs a package's `files` entry only once per request. Those older copies still work alongside this one. The loader answers before their bootstrap does, so this copy wins wherever both are installed, unless an older plugin creates a logger while its own file is loading.

## API stability contract

This contract exists because all consuming plugins share one loaded class at runtime.

**`Mai_Logger` (the class):**
- Public methods are **additive only**. Never rename or remove.
- Constructor signature is frozen: `( string $name_or_file )`.
- If you ever truly need a breaking change, fork to a new class name (`Mai_Logger_V2`) and leave this one untouched.

**Versioning:**
- Strict semver. Patch = bug fix only. Minor = additive only. Major = … see "fork to new class name" above.
- Always tag releases and tell consumers to require a tagged constraint (e.g. `^0.2`). Tracking `dev-main` is fine for local development but ships unreleased code to production.
- Bump the version in `mai-package.php` and `Mai_Logger::VERSION` together, in the same commit as any change to `Mai_Logger.php`. The loader picks copies by the `mai-package.php` version.

## Edge cases

- **Two plugins bundle the same version:** either copy loads. They are the same code.
- **One plugin requires another that requires mai-logger** (plain Composer, such as mai-publisher bundling mai-analytics): Composer flattens the dependency tree, so one copy lands in the parent plugin's `vendor/`. No special handling needed.
- **A consumer prefixes its `vendor/` with Strauss:** exclude `maithemewp/*` from prefixing, or Strauss renames the loader and starts a second one. The maithemewp plugins do not use Strauss for these libraries.

## License

GPL-2.0-or-later

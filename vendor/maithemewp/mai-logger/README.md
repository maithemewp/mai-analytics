# Mai Logger

A tiny, versioned logger for WordPress plugins. Drop-in via Composer. The newest installed version across all active plugins wins at runtime — no Strauss prefixing required.

## Install

```bash
composer require maithemewp/mai-logger
```

During local development:

```json
{
    "repositories": [
        { "type": "path", "url": "/Users/you/LocalPackages/mai-logger", "options": { "symlink": true } }
    ],
    "require": {
        "maithemewp/mai-logger": "*"
    }
}
```

## Use

In your plugin's main bootstrap, make sure Composer's autoloader runs:

```php
require_once __DIR__ . '/vendor/autoload.php';
```

Then add a per-plugin helper:

```php
// includes/functions.php
function maisdio_logger(): Mai_Logger {
    static $logger;
    return $logger ??= new Mai_Logger( __FILE__ );
}
```

Call it anywhere:

```php
maisdio_logger()->info( 'Hello' );
maisdio_logger()->error( 'Something broke', $context_array );
```

The constructor accepts either a plugin slug string or `__FILE__`. With `__FILE__`, the slug is derived via `plugin_basename( dirname( __FILE__ ) )`.

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
- Always tag releases. Never instruct consumers to install `dev-main` — `version_compare` ranks non-numeric versions unpredictably.

## Edge cases

- **Same version registered twice** (two plugins bundle v0.1.0): second registration overwrites first with the same path. Harmless.
- **Two plugins, same version string, different files** (someone forked): registration order decides. Fix: bump the version when you fork.
- **Strauss-prefixed by a third-party plugin:** that plugin gets a fully isolated copy and opts out of the shared registry. Working as intended.

## License

GPL-2.0-or-later

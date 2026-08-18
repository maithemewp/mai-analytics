# Changelog

All notable changes to `mai-logger` are documented here.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) · Versioning: [Semantic Versioning](https://semver.org/).

## [0.1.2] - 2026-07-08

### Changed

- Added a `.gitattributes` with `export-ignore` so dev-only paths are stripped from the Composer dist archive (preventive; the package currently ships only its PHP sources).

## [0.1.1] - 2026-07-06

### Fixed

- Allow the class to load under CLI (PHPUnit) without `ABSPATH` defined, so a bundling plugin can run its test suite on a clean checkout or in CI. Added `@param`/`@return` docblocks across the public methods.

## [0.1.0] - 2026-04-27

### Added

- Initial release. `Mai_Logger`, a lightweight logger for WordPress plugins, loaded via a bootstrap autoloader that selects the newest registered version across all installed plugins.

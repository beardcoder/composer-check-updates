# Copilot Instructions

## Project Overview

Composer plugin (type `composer-plugin`) that checks for outdated dependencies in `composer.json` beyond version constraints — inspired by npm-check-updates. Registered via `Plugin.php` implementing `PluginInterface` + `Capable`, which exposes a Composer command `check-updates` (alias `ccu`).

## Architecture

The codebase follows a layered structure under `Beardcoder\ComposerCheckUpdates`:

- **Plugin** → registers the `CommandProvider` capability with Composer
- **Command/CheckUpdatesCommand** → orchestrates the CLI: collects packages, fetches versions, displays results, handles interactive mode and JSON output
- **Service/PackageVersionChecker** → fetches versions from the Packagist API (`repo.packagist.org/p2/`), uses `curl_multi` for parallel requests (batches of 10), with in-memory cache
- **Service/VersionComparator** → determines update type (major/minor/patch) and whether a version is newer
- **Service/ComposerJsonUpdater** → reads/writes `composer.json` and `composer.lock`, preserving trailing newlines
- **Model/PackageUpdate** → immutable value object with constraint-prefix-preserving logic in `getNewConstraint()` (handles `^`, `~`, `>=`, version precision)

## Key Conventions

- PHP 8.1+ with `declare(strict_types=1)` in all files
- All classes are `final`
- PSR-4 autoloading: `Beardcoder\ComposerCheckUpdates\` → `src/`
- Constructor-promoted `readonly` properties on value objects and service constructors
- No test suite currently exists
- No CI/CD pipeline configured

## Releases

Use `./release.sh [major|minor|patch|x.y.z]` — it updates `CHANGELOG.md` from git log, commits, tags, and prints push instructions.

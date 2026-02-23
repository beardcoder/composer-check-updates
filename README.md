# Composer Check Updates

[![Latest Version](https://img.shields.io/packagist/v/beardcoder/composer-check-updates.svg?style=flat-square)](https://packagist.org/packages/beardcoder/composer-check-updates)
[![PHP Version](https://img.shields.io/packagist/php-v/beardcoder/composer-check-updates.svg?style=flat-square)](https://packagist.org/packages/beardcoder/composer-check-updates)
[![License](https://img.shields.io/packagist/l/beardcoder/composer-check-updates.svg?style=flat-square)](LICENSE)

Upgrade your `composer.json` dependencies to the **latest** versions, ignoring specified version constraints.

Inspired by [npm-check-updates](https://github.com/raineorshine/npm-check-updates).

## Features

- 🔍 Check for updates beyond your version constraints
- 🎨 Color-coded output (red=major, cyan=minor, green=patch)
- 🖱️ Interactive mode to select which packages to update
- 🎯 Filter packages by name pattern
- 📦 Supports both `require` and `require-dev` dependencies

## Installation

Install globally to use `composer check-updates` or `composer ccu` from any project:

```bash
composer global require beardcoder/composer-check-updates
```

## Usage

### Check for updates

```bash
# In any project directory with composer.json
composer check-updates

# Or use the alias
composer ccu
```

Example output:

```
Checking 15 packages...

Major Upgrades
  symfony/console              ^5.4    →  ^7.0
  doctrine/orm                 ^2.14   →  ^3.0

Minor Upgrades
  monolog/monolog              ^3.4    →  ^3.5

Patch Updates
  guzzlehttp/guzzle            ^7.8.0  →  ^7.8.1

Run composer ccu -u to upgrade composer.json
Run composer ccu -i for interactive mode
```

### Update composer.json

```bash
# Update all packages to latest versions
composer ccu -u

# Then install the new versions
composer update
```

### Interactive Mode

Choose which packages to update interactively:

```bash
composer ccu -i
```

Use arrow keys to navigate, Space to toggle selection, Enter to confirm.

### Filter Packages

```bash
# Only check symfony packages
composer ccu --filter "symfony/*"

# Check multiple patterns
composer ccu --filter "symfony/*" --filter "doctrine/*"

# Exclude packages
composer ccu --reject "phpunit/*"

# Only dev dependencies
composer ccu --dev-only

# Only production dependencies
composer ccu --prod-only
```

### Limit Update Types

```bash
# Only show minor and patch updates (skip major)
composer ccu --minor-only

# Only show patch updates
composer ccu --patch-only
```

### Target Version

Control which version to upgrade to:

```bash
# Upgrade to latest version (default)
composer ccu --target latest

# Only upgrade to latest minor version (no major upgrades)
composer ccu --target minor

# Only upgrade to latest patch version (no minor/major upgrades)
composer ccu --target patch

# Short form
composer ccu -t minor
```

### JSON Output

```bash
composer ccu --json
```

### Specify Working Directory

```bash
composer ccu -d /path/to/project
```

### Recursive Mode

Check all `composer.json` files in subdirectories (skips `vendor/`, `node_modules/`, `.git/`):

```bash
# Scan all subdirectories
composer ccu --recursive

# Limit scan depth
composer ccu -r --depth 2

# Combine with other options
composer ccu -r --minor-only --json
```

## Options

| Option | Alias | Description |
|--------|-------|-------------|
| `--upgrade` | `-u` | Update composer.json with new versions |
| `--interactive` | `-i` | Interactive mode to select packages |
| `--filter` | `-f` | Filter packages by name (supports wildcards) |
| `--reject` | `-x` | Exclude packages by name (supports wildcards) |
| `--target` | `-t` | Target version: latest, minor, patch (default: latest) |
| `--dev-only` | | Only check dev dependencies |
| `--prod-only` | | Only check production dependencies |
| `--minor-only` | | Only show minor and patch updates |
| `--patch-only` | | Only show patch updates |
| `--json` | | Output results as JSON |
| `--recursive` | `-r` | Recursively check all composer.json in subdirectories |
| `--depth` | | Max directory depth for --recursive |
| `--working-dir` | `-d` | Use the given directory as working directory |

## Color Legend

- **Red**: Major upgrade (breaking changes possible)
- **Cyan**: Minor upgrade (new features, backwards compatible)
- **Green**: Patch update (bug fixes)

## License

MIT

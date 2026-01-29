# Changelog

All notable changes to this project will be documented in this file.

## [1.0.0] - 2026-01-29

### ✨ Features

- Initial release
- Check for package updates beyond composer.json constraints
- Interactive mode (`-i`) to select which packages to update
- Parallel version checking using curl_multi for fast performance
- Preserves constraint styles (`^`, `~`, `>=`, etc.)
- Color-coded output (red=major, cyan=minor, green=patch)
- Filter packages by name pattern (`--filter`)
- Exclude packages by name pattern (`--reject`)
- Target specific version types (`--target latest|minor|patch`)
- Check only dev or prod dependencies (`--dev-only`, `--prod-only`)
- JSON output support (`--json`)

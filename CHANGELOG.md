# Changelog

All notable changes to this project will be documented in this file.

## [1.0.0] - 2026-02-23

- feat: add --recursive/-r option for scanning subdirectories
- feat: add viewport scrolling to interactive selector
- refactor: improve terminal UI with Symfony ProgressBar and extracted Output layer
- docs: add .github/copilot-instructions.md
- chore: release v.1.0
- Initial release: Composer Check Updates plugin
- Initial release: Composer Check Updates plugin


## [.1.0] - 2026-01-29

- Initial release: Composer Check Updates plugin


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

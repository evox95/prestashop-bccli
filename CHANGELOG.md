# Changelog

All notable changes to this project are documented in this file.
This project follows [Semantic Versioning](https://semver.org/).

## [1.0.1] - 2026-08-05

First published release. Same functionality as 1.0.0, which was never
released; this version carries the licence change and the CI corrections
made while publishing.

### Changed
- Licensed under the Academic Free License 3.0, matching the convention used
  by PrestaShop core and most modules, instead of MIT.

### Fixed
- The integration suite now creates the fixture images it needs when a shop
  does not carry them, so it passes on a freshly installed PrestaShop.
- CI waits for the installer to finish populating image formats before
  running the suite.

## [1.0.0] - 2026-08-05

### Added
- `prestashop:thumbnails:regenerate` console command for PrestaShop 8.x,
  matching the interface PrestaShop 9.1 provides natively.
- Run lock preventing overlapping regenerations.
- Silent operation suitable for cron; failures report on stderr with exit 1.

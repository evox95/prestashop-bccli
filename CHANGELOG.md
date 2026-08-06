# Changelog

All notable changes to this project are documented in this file.
This project follows [Semantic Versioning](https://semver.org/).

## [1.1.1] - 2026-08-06

### Fixed
- `prestashop:feature-flag` failed on PrestaShop 8.0/8.1 with a raw
  `ServiceNotFoundException` for
  `PrestaShopBundle\Entity\Repository\FeatureFlagRepository`: that build
  registers the same repository under a different service id
  (`prestashop.core.admin.feature_flag.repository`), not under its own FQCN
  the way PrestaShop 8.2 does. `FeatureFlagManager` now tries the service id
  first and falls back to resolving the identical repository through
  `doctrine.orm.entity_manager` (public on every 8.x this module targets)
  when the id is not registered, and raises this module's own
  `UnsupportedPlatformException` - never a raw `ServiceNotFoundException` -
  if neither is available.

### Changed
- Releases are now gated on the integration suite across PrestaShop 8.0, 8.1
  and 8.2, not on unit tests alone. The bug above shipped in 1.1.0 precisely
  because the version matrix that catches it did not block the release.

## [1.1.0] - 2026-08-06

### Added
- `prestashop:search:index` console command for PrestaShop 8.x, matching the
  interface PrestaShop 9.1 provides natively (`--full`, `--shop-id`,
  `--shop-group-id`, `--product-id`). Withdraws itself when PrestaShop 9's own
  command is present.
- Guard against `--full` combined with `--shop-group-id`: core's
  `Search::indexation()` truncates the *entire* installation's search index in
  that combination, not just the given group, so this option pairing is
  rejected outright rather than silently doing something dangerous.
- `prestashop:feature-flag` console command for PrestaShop 8.x, matching the
  `<action> [<feature_flag>]` interface PrestaShop 9.1 provides natively
  (`enable`, `disable`, `list`). Withdraws itself when PrestaShop 9's own
  command is present. `enable`/`disable` are silent on success like the rest
  of this module; `list` is the one command that always prints, since there is
  no useful silent form of "show me the flags". An unknown flag name exits 1
  and lists the valid names instead of silently doing nothing, unlike the
  underlying `FeatureFlagRepository`, which no-ops on an unknown name.

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

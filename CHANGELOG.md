# Changelog

All notable changes to this project are documented in this file.
This project follows [Semantic Versioning](https://semver.org/).

## [1.3.0] - 2026-10-29

### Added
- PrestaShop 1.7.8 support for thumbnail regeneration and search indexing.
- Integration coverage for regenerating one product image format after a size change.

### Changed
- Feature-flag commands are unavailable on PrestaShop 1.7.
- Both test and release workflows now cover PrestaShop 1.7.8, with PHP 7.1
  unit tests and full source syntax checks.

## [1.2.0] - 2026-08-06

### Changed
- `prestashop:thumbnails:regenerate -v` no longer draws a shared progress bar.
  The bar only advanced once a whole domain (categories, manufacturers,
  suppliers, products, stores) finished, so on a real run it sat motionless at
  "0/5" while the slow `products` pass ran - typically over 90% of the total
  time - and then jumped to 100% in milliseconds once the fast domains that
  follow it finished; a user watching it read that as a hang, not progress.
  Core's own thumbnail regeneration has no finer-grained progress hook to
  drive a smoother bar with. `-v` now prints one line per domain instead: the
  domain's name is written the moment it starts, flushed immediately, so a
  long-running domain is visibly in progress rather than silent; the same
  line is completed with its elapsed time once that domain finishes. This
  also reads sensibly for a single-domain run
  (`thumbnails:regenerate products -v`), which the old bar - sized to the
  domain count of whatever was requested - technically also handled, but as a
  bar that filled in one jump regardless of how long the single domain took.
  The summary line printed after all domains finish is unchanged.

## [1.1.2] - 2026-08-06

### Fixed
- `prestashop:feature-flag enable`/`disable` silently wrote nothing to the
  database on PrestaShop 8.0/8.1: core's own
  `FeatureFlagRepository::enable()`/`disable()` mutate the entity in memory
  and call `persist()`/`flush()` on PrestaShop 8.2, but on 8.0/8.1 they only
  mutate the entity - the change dies with the process. `FeatureFlagManager`
  no longer trusts those methods to persist; it now sets the entity's state
  itself and flushes through `doctrine.orm.entity_manager` directly, which
  is correct on every PrestaShop 8.x version this module targets.
- 1.1.1 never released (the release workflow saw no version change on the
  push that merged it), so this is the first release carrying both the
  8.0/8.1 repository-service-id fix and this persistence fix.

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

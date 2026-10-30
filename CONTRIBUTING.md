# Contributing

## Running the tests

### Unit tests

No PrestaShop installation required:

```bash
php tests/run-unit.php
```

This runs every `*Test.php` file in `tests/` except `*IntegrationTest.php`
files, and exits non-zero on any failure.

### Integration tests

The integration suite exercises the real `AdminImagesController` API against
a running PrestaShop shop, so it needs one. Inside a shop container (or any
host with a working PrestaShop installation):

```bash
# Use a disposable shop: the suite modifies image files and the search index.
# Copy or symlink the module into modules/bccli first, then:
php bin/console prestashop:module install bccli --env=prod --no-debug
php bin/console cache:clear --env=prod --no-debug
php modules/bccli/tests/run-integration.php
```

The runner boots the shop via `config/config.inc.php`, using the admin
bootstrap path so a broken front-office module cannot block the run. Set
`PS_ROOT` if the shop root is not `/var/www/html`. The suite also runs the real
`bin/console` entry point to verify service discovery and filtered product
regeneration after an image type size change. Feature-flag tests are skipped
on PrestaShop 1.7, but their absence from the command list is asserted.

One assertion — the "unwritable directory fails loudly" permission test — is
skipped when running as `root`, because a root process can write through a
directory with permissions that would block any other user, which would make
the assertion pass without proving anything. **This is not just a local
caveat: the CI workflow (`.github/workflows/tests.yml`) also runs the
integration containers as root, so that specific test is expected to `SKIP`
on every CI run, not just locally.** It is exercised manually as a non-root
user instead; do not "fix" the CI matrix to make it pass without addressing
that root-vs-non-root gap first.

## PHP 7.1 syntax floor

All PHP in this repository must use PHP 7.1-compatible syntax so the same
source runs on PrestaShop 1.7.8 and 8.x. Runtime PHP requirements follow the
shop's PrestaShop release; see the Requirements section of `README.md`.
Concretely, this rules out:

- typed properties (PHP 7.4)
- arrow functions (PHP 7.4)
- constructor property promotion (PHP 8.0)
- union types, `match`, named arguments, and anything else from PHP 8.0+

Scalar type hints, return type declarations, and `declare(strict_types=1)`
are all fine — they were already available in PHP 7.1.

## Pull requests

Every pull request needs a test: a new unit test for logic that does not
need a live shop, or an integration test for anything that talks to core's
`AdminImagesController` API. Changes without one will not be merged.

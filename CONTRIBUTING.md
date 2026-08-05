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
# Copy or symlink the module into modules/bccli first, then:
php modules/bccli/tests/run-integration.php
```

The runner boots the shop via `config/config.inc.php`, using the admin
bootstrap path so a broken front-office module cannot block the run. Set
`PS_ROOT` if the shop root is not `/var/www/html`.

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

All PHP in this repository must run on PHP 7.1, one minor version below
PrestaShop 8.0's actual minimum supported PHP version, 7.2.5 (this module
supports PrestaShop 8.0–8.2 and requires 7.2.5+ at runtime - see the
Requirements section of `README.md`). The lower syntax floor is a deliberate
choice, not a requirement: keeping the source itself 7.1-compatible leaves the
door open to a future PrestaShop 1.7 port, which does run on 7.1. Concretely,
this rules out:

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

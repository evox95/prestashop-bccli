# bccli

Console commands for PrestaShop 1.7.8 and 8 that PrestaShop 9 provides natively.

If you run a large shop, you have probably watched the back office give up
half-way through regenerating thumbnails, or through reindexing search after a
bulk import. The work needs more time than a web request gets. These commands do
the same work from the command line, where there is no timeout, and they are
quiet enough to put in cron.

```bash
php bin/console prestashop:thumbnails:regenerate all
php bin/console prestashop:search:index --full
php bin/console prestashop:feature-flag list
```

Every command uses the same name and options as its PrestaShop 9 counterpart, so
your crontab keeps working after you upgrade. On PrestaShop 9 the module steps
aside and lets the core command run.

## What it fixes

Regenerating 1,000 product images across 5 formats, measured on PrestaShop 8.2.7:

| Where | Result |
|---|---|
| Back office, 30-second PHP limit | Timed out after 26 seconds, 129 of 1,000 images done |
| Command line | Finished in 175 seconds, no errors |

Search indexing hits the same wall after a large import, and toggling feature
flags has no command-line path at all in PrestaShop 8.

## Install

Download the ZIP from [Releases](https://github.com/evox95/prestashop-bccli/releases),
then either upload it in **Modules → Module Manager → Upload a module**, or
unzip it into your shop's `modules/` directory and install it from the module
list.

There is nothing to configure and no back office page. Installing it just makes
the commands available. If they do not appear in `php bin/console list`, clear
the Symfony cache for the environment you use, for example:

```bash
php bin/console cache:clear --env=prod --no-debug
```

## Requirements

PrestaShop 1.7.8 (tested on PrestaShop 1.7.8.11) or 8.x, with a PHP version
supported by that PrestaShop release (PHP 7.1–7.4 for 1.7.8; PHP 7.2.5+ for 8.0,
with the upper limit depending on the 8.x release). The 1.7 port was verified
with PHP 7.4. It does not add PHP 8 support to PrestaShop 1.7 itself.

For production scripts, append `--env=prod --no-debug` (or set
`SYMFONY_ENV=prod`). PrestaShop 1.7 defaults to the development environment;
at `-v` its Symfony logger can print core deprecation notices on stderr even
when regeneration succeeds.

The test and release CI matrices cover PrestaShop 1.7.8, 8.0, 8.1, and 8.2;
later 8.x releases are expected to work but are unverified. PrestaShop 1.7.8.11
has feature-flag entities and a back office page, but lacks the
`FeatureFlagRepository` API this command needs, so the command is not registered
on 1.7. Not on the Addons marketplace.

## Commands

### Regenerate thumbnails

```bash
# Everything
php bin/console prestashop:thumbnails:regenerate all

# One image domain: categories, manufacturers, suppliers, products, or stores
php bin/console prestashop:thumbnails:regenerate products

# One image format, deleting old thumbnails first, printing a line per domain
php bin/console prestashop:thumbnails:regenerate all 3 --delete -v

# Products only, using image type ID 13 (replace it with your shop's format ID)
php bin/console prestashop:thumbnails:regenerate products 13 --delete -v
```

Use `--delete` after changing an image type's dimensions: core otherwise skips
thumbnails that already exist. The ID selects a format, not a product; all
product images are processed for that format.

On PrestaShop 1.7.8.11, core deletes only the selected format's `.jpg` files
and leaves `-<format>.webp`, `-<format>.avif`, and `2x` variants in place.
PrestaShop 8.0 also removes the `2x` files but still keeps `.webp` and `.avif`;
only 8.1 and later delete all of them. Regenerate or remove those files separately after a size change; for
example, a WebP tool that writes only missing files will otherwise keep serving
the old dimensions. This module preserves that core deletion behaviour.

### Index products for search

```bash
# Rebuild the whole index
php bin/console prestashop:search:index --full

# Only products that are not indexed yet
php bin/console prestashop:search:index

# A single product, or a single shop
php bin/console prestashop:search:index --product-id=42
php bin/console prestashop:search:index --full --shop-id=1
```

`--full` together with `--shop-group-id` is rejected. PrestaShop's own indexer
would empty the search index for *every* shop in that combination, not just the
group you named, so the command refuses rather than doing something surprising.

### Feature flags

This command is available on PrestaShop 8, not on 1.7.

```bash
php bin/console prestashop:feature-flag list
php bin/console prestashop:feature-flag enable product_page_v2
php bin/console prestashop:feature-flag disable product_page_v2
```

`list` prints a table of every flag and its state. `enable` and `disable` say
nothing when they succeed.

## Running from cron

Success is silent. Nothing goes to stdout or stderr and the exit code is 0, so
cron sends no mail. When something fails, the message goes to stderr and the
exit code is 1, so you hear about it. (`feature-flag list` is the exception —
printing its table is the whole point of running it.)

```cron
# Nightly, quiet unless something breaks.
17 3 * * * /usr/bin/php /var/www/html/bin/console prestashop:thumbnails:regenerate all
41 3 * * * /usr/bin/php /var/www/html/bin/console prestashop:search:index --full
```

Add `-v` when you run a command by hand and want to see progress and a summary.
`thumbnails:regenerate -v` prints one line per image domain (`categories`,
`products`, ...), shown as soon as that domain starts and completed with its
elapsed time once it finishes - useful because `products` is typically nearly
all of the run. Errors print even with `-q`, because a silent failure is worse
than noise.

If a second `thumbnails:regenerate` or `search:index` starts while the first is
still going, it exits quietly instead of competing for the same files. Feature
flag toggles take no lock — they are a single row update.

## Things worth knowing

**`<image-type>` behaves like PrestaShop 9, not like the PrestaShop 8 back
office.** The back office ignores the format ID when you regenerate everything;
this command honours it, matching PrestaShop 9.

**Multistore support varies by command.** `search:index` takes `--shop-id` and
`--shop-group-id` and sets the context accordingly. `thumbnails:regenerate` does
not: image files are shared between shops while format settings are per-shop, so
it uses whichever shop context PrestaShop establishes at startup. Feature flags
are installation-wide.

**Two quirks come from PrestaShop itself**, not from this module: `--delete`
does not remove `_thumb`-derived thumbnails (fixed in PrestaShop 9), and every
resize writes a small `fileType` marker file next to the images.

## Missing a command? Found a bug?

Both are worth an [issue](https://github.com/evox95/prestashop-bccli/issues).

PrestaShop 9 added several commands that 8 never got, and this module covers
only the ones that solve a real operational problem. If you are working around a
missing one — maintenance mode, cache clearing, or anything in PrestaShop 9 you
miss on 8 — open an issue and describe what you are trying to do. That is more
useful than a feature name, because it often turns out there is a simpler path.

For bugs, the fastest fix comes from an issue that includes your PrestaShop and
PHP versions, the command you ran, and what happened instead. If a command
printed something, paste it verbatim.

Pull requests are welcome. [CONTRIBUTING.md](CONTRIBUTING.md) covers running the
tests and the PHP 7.1 syntax rule that keeps the same source compatible with
PrestaShop 1.7.8 and 8.x.

## How it works

PrestaShop 9 moved thumbnail regeneration into a service and wrapped it in a
command. PrestaShop 1.7 and 8 keep the same logic locked inside `protected`
methods of a back office controller, with no adapter to reuse.

So this module drives that controller directly instead of reimplementing image
resizing. The thumbnails it produces are therefore what the back office
produces, and there is no second copy of the resize logic to drift out of sync.
The riskiest part of that coupling is isolated in
`src/Thumbnails/LegacyControllerBridge.php`, which checks that every core class,
method and constant it needs exists before touching anything, and reports a
clear error instead of a PHP crash when a version does not match. The search and
feature-flag commands touch core too, through documented public APIs
(`Search::indexation()` and PrestaShop's own feature-flag repository) rather than
through private internals.

The design notes in [`docs/`](docs/) record what was measured and why each
decision went the way it did.

## Licence

[Academic Free License 3.0](LICENSE), the licence most PrestaShop modules use.
(PrestaShop core itself is OSL-3.0.) You can modify it, redistribute it, and
sell it, as long as the copyright notice stays.

Copyright (c) 2026 Mateusz Bartocha

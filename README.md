# bccli

A console command for PrestaShop 8.x that regenerates product, category,
manufacturer, supplier and store image thumbnails — the same job as
**Design → Images → Regenerate thumbnails** in the back office, run from the
command line instead, with no execution time limit.

## The problem

The back office regeneration page runs inside a normal HTTP request, so it is
bound by PHP's `max_execution_time`. On a catalogue of any size that limit is
reached before the job finishes, and re-running starts from the beginning —
large catalogues can never complete this way.

Measured on PrestaShop 8.2.7, 1000 product images × 5 formats (5000
thumbnails total):

| Run | Result |
|---|---|
| Back office, `max_execution_time = 30` (a typical hosting default) | timed out after 26.2 s, having processed 129 of 1000 images (13%) |
| CLI, no effective time limit | completed in 175 s, 0 errors, all 5000 thumbnails written |

PrestaShop 9.1 fixed this by shipping `prestashop:thumbnails:regenerate` as a
core console command. **bccli backports that same command to PrestaShop 8.x**,
with the same name and arguments, so a crontab entry keeps working unchanged
across an 8 → 9 upgrade.

## Requirements

- PrestaShop 8.0, 8.1 or 8.2 — these are the versions tested in CI. Other 8.x
  point releases are expected to work but are not verified.
- PHP 7.1 or later.

**PrestaShop 9.x already ships this command natively.** Installing bccli
there is harmless — the module detects the native command and withdraws its
own — but pointless, since there is nothing left for it to add.

## Installation

bccli is **not distributed through the PrestaShop Addons marketplace**. Install
it one of two ways:

1. **ZIP.** Download the latest release ZIP from the repository's Releases
   page and upload it through **Modules → Upload a module** in the back
   office, the same way as any other module ZIP.
2. **Git clone.** Clone the repository directly into `modules/bccli` in your
   PrestaShop installation:

   ```bash
   git clone <repository-url> modules/bccli
   ```

Either way, install the module from **Modules → Module Manager** (search for
"BC CLI") once the files are in place. Installation adds no database tables
and registers no hooks — there is nothing to configure.

## Usage

```
php bin/console prestashop:thumbnails:regenerate <image> [<image-type>] [-d|--delete] [-v]
```

| Argument / option | Meaning |
|---|---|
| `<image>` | one of `all`, `categories`, `manufacturers`, `suppliers`, `products`, `stores` |
| `<image-type>` | optional image format ID; omit or pass `0` for every configured format |
| `-d`, `--delete` | erase existing thumbnails before regenerating |
| `-v` | show a progress bar and a summary when the run finishes |

Examples:

```bash
# Regenerate every thumbnail for every domain.
php bin/console prestashop:thumbnails:regenerate all

# Regenerate only product thumbnails.
php bin/console prestashop:thumbnails:regenerate products

# Regenerate a single image format (its numeric ID) across every domain,
# deleting existing thumbnails first, with progress output.
php bin/console prestashop:thumbnails:regenerate all 3 --delete -v
```

### Output is silent on success

The command is designed to run from cron, which normally mails any output it
produces. **A successful run without `-v` writes zero bytes to both stdout
and stderr and exits `0`.** A failing run always writes a short message to
stderr and exits `1`, whether or not `-v` or `-q` was passed. With `-v`, a
successful run shows a progress bar (on stderr) and a one-line summary (on
stdout); nothing about the silence guarantee changes for failures.

If another regeneration is already running, a second invocation exits `0`
having done nothing — that is normal operation for two overlapping cron
firings, not an error, and it does not generate mail either.

### Cron example

```cron
# Regenerate every night at 03:17. Silent unless something fails.
17 3 * * * /usr/bin/php /var/www/html/bin/console prestashop:thumbnails:regenerate all
```

Adjust the PHP binary path and the path to `bin/console` for your
installation.

## Difference from the PrestaShop 8 back office

`<image-type>` is honoured even when regenerating `all`. The PrestaShop 8
back office ignores the format filter in that case and always regenerates
every configured format; PrestaShop 9's native command applies the filter
regardless of which domain is selected. Since this module's goal is interface
parity with PrestaShop 9, it follows PrestaShop 9's behaviour — this is the
one place it deliberately does not match the PrestaShop 8 back office.

## Multistore

Multistore is out of scope for v1. Image *files* are shared across all shops
in a PrestaShop installation, but image *format settings* (which sizes are
generated, at what dimensions) are configured per shop. A correct multistore
implementation needs to decide which shop's format configuration to run
against and is left for a future version. v1 runs against the default shop
context PrestaShop establishes at bootstrap — the same context a plain
`bin/console` invocation always gets.

## Known limitations inherited from core

These are pre-existing PrestaShop 8 behaviours, not bugs in this module. They
are documented here so they are not mistaken for one.

- **`--delete` does not remove `_thumb`-derived thumbnails.** Core's deletion
  logic matches filenames with a pattern that misses thumbnails generated
  from a `_thumb` source image (used for the `medium` format on category
  images). Those files are left behind and are not refreshed by `--delete`.
  PrestaShop 9 fixed this.
- **Every resize writes a `fileType` marker file** into the destination
  directory, alongside the thumbnail itself. This is core behaviour
  (`ImageManager`), not something this module adds or can suppress.

## Development

See [`CONTRIBUTING.md`](CONTRIBUTING.md) for running the test suite and the
PHP 7.1 syntax floor, and [`SECURITY.md`](SECURITY.md) for reporting a
vulnerability.

## Licence

MIT — see [`LICENSE`](LICENSE).

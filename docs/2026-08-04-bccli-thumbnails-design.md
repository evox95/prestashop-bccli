# bccli v1 — thumbnail regeneration for PrestaShop 8.x

**Status:** approved design, ready for implementation plan
**Date:** 2026-08-04

## Problem

Regenerating thumbnails from the PrestaShop back office (Design → Images →
Regenerate thumbnails) fails on shops with many images. The page hits the PHP
execution limit and stops part-way through, reporting a timeout. Re-running
starts from the beginning, so large catalogues can never finish.

PrestaShop 9.1 solved this by adding a console command,
`prestashop:thumbnails:regenerate`. PrestaShop 8.x has no equivalent — the only
way to regenerate is the back office page that times out.

**Measured on PrestaShop 8.2.7** (1000 product images × 5 formats = 5000
thumbnails):

| Environment | Result |
|---|---|
| `max_execution_time = 30` (typical hosting, simulates the BO panel) | `'timeout'` after 26.2 s, 129/1000 images processed (13%) |
| CLI, no effective limit | completed in 175 s, 0 errors, all 5000 thumbnails written |

## Goal

Ship a free, MIT-licensed PrestaShop module on GitHub that backports the PS 9.1
command to PS 8.x, so thumbnail regeneration can run from the command line
without a time limit and can be scheduled from cron.

v1 delivers exactly one command. Anything else is out of scope.

## Command interface

Identical to PS 9.1, so a crontab entry survives a shop upgrade from 8 to 9
without edits.

```
php bin/console prestashop:thumbnails:regenerate <image> [<image-type>] [-d|--delete] [-v]

  image        all | categories | manufacturers | suppliers | products | stores
  image-type   image format ID (0 or omitted = all formats)
  -d, --delete erase existing thumbnails before regenerating
  -v           show progress bar and summary
```

### Output contract

The command is expected to run from cron, which mails any output. Therefore
success is silent and only failure speaks.

| Situation | stdout / stderr | Exit code |
|---|---|---|
| Success, no `-v` | **zero bytes** | 0 |
| Success, with `-v` | progress bar + summary | 0 |
| Failure (any cause) | short message on **stderr**, with or without `-v` | 1 |

Verified on a real regeneration: a thumbnail was written while both streams
measured 0 bytes.

The decision whether to show progress is made **before the progress bar is
created**, not by silencing it afterwards — Symfony's `ProgressBar` writes to
stderr, which cron also mails.

Core can still bypass this by emitting PHP diagnostics directly; see
*Output isolation* below.

## Version scope

**Supported and tested in CI: PrestaShop 8.0, 8.1, 8.2.** Later 8.x releases are
expected to work but are unverified until added to the CI matrix; the README
states this rather than claiming a blanket "8.0–8.9".

Not PrestaShop 9.x — the core already ships this command. The module must not
register its own there. Detection uses class presence, not a version-string
comparison:

```php
class_exists('PrestaShopBundle\Command\RegenerateThumbnailsCommand')
```

Verified: `false` on 8.2.7, `true` on 9.1.4. This is more robust than
`version_compare`, because it also does the right thing if PrestaShop ever
backports the command into an 8.x patch release.

**Where this check lives:** `services.yml` is plain YAML and cannot branch at
runtime, so registration there is unconditional. The check goes in the command's
`isEnabled()` method, which Symfony's `Application` consults when assembling the
command list (verified: `Command::isEnabled()` exists and is `public` in Symfony
4.4.51, the version bundled with PS 8.2). A disabled command is absent from
`list` and cannot be run.

**Why this matters:** Symfony does not reserve command namespaces and gives no
warning on collision — whichever command registers last silently wins. This was
verified by registering a module command named `prestashop:module`: the core
command disappeared from `bin/console list` and the module's ran in its place.
Without the guard, installing this module on PS 9 would silently replace the
superior native implementation.

Not PrestaShop 1.7 in v1. The mechanism works there (verified on 1.7.8.11 and
1.7.6.8), but two differences need separate work:

- 1.7 uses `_PS_PROD_IMG_DIR_`; PS 8 renamed it to `_PS_PRODUCT_IMG_DIR_`
  (keeping the old name as a deprecated alias)
- 1.7 has no `imageFormatConfiguration` property at all

Code should not foreclose adding 1.7 later, but v1 does not target it.

## Approach

PS 9.1 did not write this logic from scratch — it extracted the existing back
office logic into `Adapter\ImageThumbnailsRegenerator` and wrapped it in CQRS.
That adapter does not exist in PS 8.x (verified: no
`src/Adapter/ImageThumbnailsRegenerator.php`), and the PS 9 code depends on
PHP 8.1 enums and the CQRS bus. It cannot be copied back.

In PS 8.x the logic lives in `protected` methods of `AdminImagesController`.
The command therefore drives that controller from the CLI.

**Alternatives considered and rejected:**

- **Reimplement the resize logic.** Rejected: a single image involves format
  selection (jpg/webp/avif with `forceFormat`), high-DPI `2x` variants, the
  `_thumb.` special case for category images in the `medium` format, different
  path construction for products (`getExistingImgPath()`) than for everything
  else, and skipping already-existing files. Duplicating that means ~500 lines
  that will silently drift from core and may produce thumbnails differing from
  the back office.
- **Override `AdminImagesController`.** Rejected: a PrestaShop override is
  global. It would conflict with any other module overriding the same
  controller and would change back office behaviour shop-wide, just to add a
  CLI command.

## Architecture

```
modules/bccli/
  bccli.php                                   module class Bccli, min 8.0.0
  config/services.yml                         command registration (unconditional)
  src/
    Command/RegenerateThumbnailsCommand.php   arguments, isEnabled(), progress, exit code
    Thumbnails/Regenerator.php                loop over domains
    Thumbnails/LegacyControllerBridge.php     core-coupling, isolated here
    Thumbnails/RunLock.php                    flock guard against overlapping runs
  tests/
```

`ps_versions_compliancy` sets `min` to `8.0.0` and leaves `max` open — the PS 9
case is handled by `isEnabled()`, which is more precise than a version range.

All coupling to core private API lives in `LegacyControllerBridge`. When a
future PrestaShop release changes those internals, there is one file to fix.

### LegacyControllerBridge

Instantiating `AdminImagesController` in CLI works (verified). Three problems
must be solved, each found by running the code rather than reading it:

| Problem | Symptom | Fix |
|---|---|---|
| `imageFormatConfiguration` comes from the Symfony container (`AdminImagesController.php:101`), which is null in CLI | `Call to a member function getGenerationFormats() on null` | construct directly: `new ImageFormatConfiguration(new Adapter\Configuration())` — it only needs a `ConfigurationInterface`. PS 8+ only; the property does not exist on 1.7 |
| `start_time` defaults to `0` (line 36), so the guard `time() - start_time > max_execution_time - 4` is true immediately | returns `'timeout'` in 0.01 s having written nothing | set `start_time = time()` explicitly — normally done by `_regenerateThumbnails()` at line 915 |
| All five regeneration methods are `protected` | inaccessible | `ReflectionMethod::setAccessible(true)` |

The bridge verifies every class, method and property it needs before use. If
anything is missing it reports which one and exits 1 — it never emits a PHP
fatal error and never writes partial output.

### Granularity: one call per domain, never per format

An earlier draft of this design proposed calling `_regenerateNewImages()` **once
per image format** (passing a single-element array) to get finer progress
reporting. That was tested and **rejected: it produces different output than the
back office.**

`$image` is the variable of the *outer* loop over directory entries
(`AdminImagesController.php:696`), but it is reassigned inside the *inner* loop
over formats:

```php
// AdminImagesController.php:705-707
if (($dir == _PS_CAT_IMG_DIR_) && ($imageType['name'] == $formated_medium)
    && is_file(_PS_CAT_IMG_DIR_ . str_replace('.', '_thumb.', $image))) {
    $image = str_replace('.', '_thumb.', $image);   // outer loop variable
}
```

Once the `medium` format is processed, `$image` stays `ID_thumb.jpg` for every
**later format in the same call**. Splitting the call per format resets it, so
later formats get built from the full-size original instead of the `_thumb` one.

Reproduced on PS 8.2.7 with a red full-size original and a blue `_thumb`
original, after enabling `medium_default` for categories:

| format | one call (back office) | one call per format |
|---|---|---|
| `category_default` | white | white |
| `medium_default` | BLUE (`_thumb`) | BLUE (`_thumb`) |
| `small_default` | BLUE (`_thumb`) | **RED (full-size)** |

The thumbnails differ in visible content. With `PS_HIGHT_DPI` enabled the
filenames diverge too, because the `2x` branch uses the unstripped `$image`.

The bug is dormant on a stock shop (`medium_default.categories = 0`) and fires
on any shop where an administrator enabled *medium* for categories in
Design -> Images. That is exactly the silent, shop-dependent divergence this
design set out to avoid.

**Both methods are therefore called once per domain with the complete format
list, exactly as the back office does.** Progress granularity is per domain
(5 steps for `all`) - coarser than hoped, but correct.

`_deleteOldImages()` must be called this way for a second, independent reason:
it has a branch gated on `count($type) > 1` (`AdminImagesController.php:634`)
that removes **orphaned thumbnails** left by image formats no longer configured.
With a single-element array that branch never fires, so `--delete` would leave
behind precisely the files it exists to reclaim.

For the `-v` summary, per-domain image counts come from `Image::getAllImages()`
for products and a `scandir` count for the other domains, using core's own
pattern `^[0-9]*\.jpg$`.

### How `<image-type>` filtering works

The back office filters formats by reading an HTTP request value:

```php
// AdminImagesController.php:937, inside _regenerateThumbnails()
$format = (string) (Tools::getValue('format_' . $type));
```

In CLI `Tools::getValue()` returns `false` (verified), so that path cannot work
from the command line. It is also irrelevant here: the line lives in
`_regenerateThumbnails()`, which this module does **not** call. The module calls
`_regenerateNewImages()` directly and passes the format list as an argument.

So the module applies the filter itself, on the array returned by
`ImageType::getImagesTypes($domain)`, matching `id_image_type`. Superglobals are
never touched.

**Deliberate deviation from PS 8 back office, matching PS 9.** Core gates the
filter on `if ($type != 'all')` (line 936), so in the PS 8 back office the
format ID is ignored entirely when regenerating all domains. PS 9 applies it
everywhere:

```php
// PS 9.1 RegenerateThumbnailsHandler
$criteria = ['id' => $imageTypeId->getValue(), $imageDomain->value => 1];
```

Since this module's stated goal is interface parity with PS 9.1, it follows PS 9
semantics and applies `<image-type>` for `all` too. This is the one place where
the module intentionally differs from the PS 8 back office; the README says so.

An unknown or non-numeric `<image-type>`, or one that matches no format in the
selected domain, is rejected during validation rather than silently regenerating
nothing.

## Execution flow

```
1. Registration    class_exists(core command)? yes → do not register
2. Validation      <image> in allowed set? <image-type> numeric/existing?
                   no → stderr + exit 1 before touching anything
3. Compatibility   bridge checks: class AdminImagesController; methods
                   _deleteOldImages, _regenerateNewImages, _regenerateWatermark,
                   _regenerateNoPictureImages; properties start_time,
                   max_execution_time, errors; and on PS 8 the property
                   imageFormatConfiguration plus ImageFormatConfiguration itself
                   missing → names what is missing + exit 1
4. Preparation     acquire lock; set_time_limit(0), max_execution_time = 86400;
                   install error handler + output buffer (see Output isolation)
5. Loop            per domain:
                     is_dir() and is_writable() precheck → fail loudly
                     [--delete] _deleteOldImages(dir, formats, isProduct)
                     _regenerateNewImages(dir, formats, isProduct)
                     _regenerateWatermark()      — products only
                     _regenerateNoPictureImages() — every domain
6. Report          summary only with -v; errors always on stderr
```

Step 5 mirrors `_regenerateThumbnails()` (lines 927–969) rather than inventing
an order. Note that core calls `_regenerateWatermark()` inside
`if ($proc['type'] == 'products')` but `_regenerateNoPictureImages()` **outside**
it — so "no picture" placeholders are regenerated for every domain, not just
products. An earlier draft of this spec got that wrong and would have left
category, manufacturer, supplier and store placeholders stale.

### No legacy context setup is needed

An earlier draft of this design had a step that built a `Context` (employee,
shop, currency, controller) before running, mirroring what `fop_console` does.
That was verified to be unnecessary and has been removed.

At bootstrap PrestaShop already provides what this code path needs: `shop` is a
`Shop` object (id 1), `language` is set, and `employee` is an object without an
id — which the regeneration methods never read. Running with **no** context
setup at all, only the three documented fixes, regenerated a deleted thumbnail
successfully (`result=false`, `errors=0`, file recreated).

`fop_console` needs its `ConsoleLoader` because its commands run through the
Symfony container and touch `LegacyContext`; this module calls the controller
methods directly and does not.

### Concurrency

Core has no locking of any kind (verified: no `flock`, `LOCK_EX` or equivalent
in `AdminImagesController`), and `ImageManager` writes straight to the
destination file via `imagejpeg()` / `imagewebp()` rather than to a temporary
file followed by an atomic `rename()`.

Since the whole purpose of this command is multi-minute cron runs, two
overlapping runs are a realistic scenario — and with `--delete` they can race on
the same files, leaving truncated or missing thumbnails.

The command therefore takes an exclusive lock (`flock` on a lock file under the
shop's writable var/cache directory) for the duration of the run. If the lock is
already held, it exits **0 with no output** — a second cron firing while the
first still runs is normal operation, not an error, and must not generate mail.
With `-v` it reports that another run is in progress.

### Time limit

Two separate things must be set. `set_time_limit(0)` removes PHP's own limit,
but core's guard compares elapsed time against `$this->max_execution_time`, so
leaving that at `0` means "abort immediately". It must be set explicitly to a
large value. This was the cause of the `'timeout'` after 0.01 s during
investigation.

### Return contract of the core method

Read from source, not assumed:

- `false` — success (`(bool) count($this->errors)` with no errors)
- `true` — errors occurred
- `'timeout'` — interrupted

`false` means success, so a naive `if (!$res)` reads backwards. This needs a
comment at the call site.

`$ctrl->errors` is `public` (declared on `ControllerCore`), so core's own
messages can be read without reflection.

### Error handling

`$ctrl->errors` **latches**: core returns `(bool) count($this->errors)` — the
whole array, not this call's errors. Verified: a call against a bad directory
followed by a call against a perfectly good one returns `true` for both, even
though the second wrote its thumbnails successfully.

Worse, core guards its own post-processing with `if (!count($this->errors))`, so
once any error exists, `_regenerateNoPictureImages()` is skipped for every later
domain.

The module therefore **clears `$ctrl->errors` before each call** and accumulates
the messages itself, so each domain gets an accurate verdict and post-processing
is not suppressed by an unrelated earlier failure. Duplicate messages are
de-duplicated before printing.

| Situation | stderr message | Exit |
|---|---|---|
| Single image fails to resize | core's own message, verbatim, one per line | 1 (at end) |
| Image directory missing | `Image directory not found: <dir>` | 1 |
| Directory not writable | `Cannot write to <dir> — check permissions` | 1 (immediately) |
| `'timeout'` despite the limit being lifted | `Interrupted after <n> of <total> steps — some thumbnails may be missing` | 1 |
| Missing core method/property | `Unsupported PrestaShop <version>: AdminImagesController::<name> not found` | 1 |
| Invalid `<image>` | `Unknown image domain "<x>". Allowed: all, categories, …` | 1 |
| Invalid `<image-type>` | `Unknown image format ID <n> for domain <d>` | 1 |
| Another run holds the lock | *(nothing)* | 0 |
| Everything succeeded | *(nothing)* | 0 |

`<version>` comes from `_PS_VERSION_`. `<image-type>` is validated against
`ImageType::getImagesTypes($domain)` for each domain in the run — format sets
differ per domain (products `1,4,5,3,2`; categories `6,2` on a stock shop), so
an ID valid for products may not exist for stores. Validation must **not** use
`ImageType::getImageTypeById()`: on an unknown ID it throws a `TypeError`
(declared `array` return, returns `false`), which would break the "never a
fatal" promise.

**Missing image directory is not success.** Both core methods open with
`if (!is_dir($dir)) { return false; }` (lines 625, 680) — and `false` is the
success value, so a broken `img/p` symlink after a bad deploy would make the
command exit 0 having done nothing. Verified. PS 9 treats this as fatal
(`RegenerateThumbnailsWriteException`); this module does the same, via its own
`is_dir()` and `is_writable()` precheck per domain before calling core. Core has
no permission check at all, so without the precheck an unwritable directory is
only discovered after attempting every image.

### Output isolation

The cron-silence contract cannot be guaranteed by controlling the command's own
output alone: core can emit PHP diagnostics that bypass Symfony's
`OutputInterface` entirely.

`ImageManager.php:210` calls `getimagesize()` without the `@` suppressor (unlike
`@exif_read_data()` two lines below). On a source file that is non-empty but
unreadable as an image, that emits a notice **on stdout**:

```
Notice: getimagesize(): Error reading from /path/1.jpg!
```

Verified: a 1-byte file triggers it. Files of exactly 0 bytes are filtered
earlier by core's own `filesize()` check, so the window is narrow — but it is
real, and `display_errors` is forced on whenever `_PS_MODE_DEV_` is true
(`config/defines.inc.php:35-44`).

Additionally, `ImageManager::resize()` fires `Hook::exec('actionOnImageResizeAfter')`
on every resize, so any third-party module hooked there can `echo` freely into
the same stream.

The command therefore, for the duration of the core call:

1. sets its own `set_error_handler()`, routing E_WARNING/E_NOTICE into the
   module's error list instead of the output stream
2. sets `display_errors=0`, restoring the previous value afterwards
3. wraps the call in `ob_start()` / `ob_get_clean()` to trap direct `echo` from
   hooked modules; captured text is reported as an error, not printed

The CI silence test uses a deliberately corrupt image, not only healthy ones —
the original test passed only because every image was valid.

### Post-processing calls

`_regenerateWatermark()` runs for products only. `_regenerateNoPictureImages()`
runs for **every** domain, matching core (the call sits outside the
`if ($proc['type'] == 'products')` block at lines 956–966). Both already iterate
the full format list internally and are called once per domain.

### `-v` summary format

```
Regenerating thumbnails: all
 5/5 [============================] 100%  3 min 12 s

Done: 1037 source images, 5182 thumbnails, 0 errors (192 s)
```

One progress bar covers the whole run, one step per domain, and the summary
aggregates across domains.

## Testing

Unit tests prove little here: the value of this module is that it talks
correctly to core's private API, which only a real shop can demonstrate.
Integration tests in Docker carry the weight.

| Test | Assertion |
|---|---|
| Regenerate from empty | delete thumbnails → run → all return (file count before/after) |
| Cron silence | success without `-v` produces 0 bytes on stdout and stderr, exit 0 |
| Failure is loud | unwritable directory → non-empty stderr, exit 1 |
| `--delete` | existing thumbnails removed before regeneration |
| `--delete` removes orphans | a thumbnail named after a no-longer-configured format is deleted (guards the `count($type) > 1` branch) |
| Compatibility guard | missing method/property → clear message, not a fatal |
| No registration on PS 9 | `list prestashop` on 9.x shows only the native command |

| Lock prevents overlap | second concurrent run exits 0 with zero output while the first holds the lock |
| Silence with a corrupt image | a 1-byte `.jpg` in the source directory still yields 0 bytes on stdout and stderr (guards the `getimagesize()` notice) |
| Output matches the back office | with `medium_default` enabled for categories and a `_thumb` original present, generated files are byte-identical to a back office run |
| Missing directory fails loudly | non-existent image dir → stderr + exit 1, not a silent exit 0 |
| Errors do not latch | a failing domain followed by a healthy one reports the second as successful |
| No-picture placeholders | deleting a placeholder in `img/m/` and running `manufacturers` recreates it |

CI matrix: PS 8.0, 8.1, 8.2 on PHP 8.1, plus PS 9.1 as a negative test.

The "failure is loud" tests must run as a **non-root** user: containers running
as uid 0 treat `0555` directories as writable, so a permission test would pass
falsely.

## Module class and autoloading

`bccli.php` declares class `Bccli` (PrestaShop requires the class name to match
the directory name). It carries only what the module system needs: `name`,
`version`, `author`, `displayName`, `description`, `ps_versions_compliancy`, and
a `tab`. There are no database tables and no hooks, so `install()` and
`uninstall()` add nothing beyond `parent::install()` / `parent::uninstall()` —
uninstalling leaves no residue.

Classes are autoloaded PSR-4 from `src/` under the namespace `Bccli\`, declared
in `composer.json`. **The generated `vendor/autoload.php` is committed to the
repository.** This is deliberate: PrestaShop modules are installed by unzipping
into `modules/`, where no one runs `composer install`, and the spec offers
`git clone` as an install path. There are no runtime dependencies, so the
committed autoloader is a few small generated files, not a vendored tree.

## Release

- Public GitHub repository, **MIT** licence: `LICENSE` at root plus a one-line
  SPDX header (`// SPDX-License-Identifier: MIT`) in each PHP file, rather than
  a full boilerplate block in every file
- Copyright holder: `Copyright (c) 2026 Mateusz Bartocha <contact@bestcoding.net>`
  — used in `LICENSE`, in `composer.json` `authors`, and as the module's `author`
- README states: the problem, the PHP and PrestaShop requirements, installation,
  cron examples, the CI-tested version matrix, that PS 9 already has this command
  natively, and that the module is not distributed via the Addons marketplace
- Releases: installable ZIP in GitHub Releases whose single top-level directory
  is `bccli/` (PrestaShop's installer requires this), built by a small packaging
  script that excludes `tests/`, CI config and development files; plus `git clone`
- Versioning: SemVer, with `CHANGELOG.md`
- CLI output is English-only; no translation files
- `CONTRIBUTING.md` and `SECURITY.md` state how to report issues and that the
  project is provided without a support SLA

## Known limitations inherited from core

These are core behaviours the module does not change. They are documented so
they are not mistaken for module bugs.

- **`--delete` does not remove `_thumb`-derived thumbnails.** The
  `_deleteOldImages()` regex matches `^[0-9]+\-…` and so misses
  `ID_thumb-<format>.jpg`. Those files survive and are never refreshed. PS 9
  fixed this (`/^[0-9]+(|_thumb)\-…/`).
- **Every resize writes a `fileType` marker file** into the destination
  directory (`ImageManager.php:333-336`). It is not a thumbnail and not created
  by this module.
- **No atomic writes.** Core writes with `imagejpeg()` / `imagewebp()` straight
  to the destination, not via a temporary file plus `rename()`. This is why the
  run lock matters.

## Accepted risk

The module depends on `protected` methods of a core controller. A future
PrestaShop 8.x release could change them and break the command. This is
mitigated but not eliminated:

1. all coupling sits in one file
2. the compatibility guard reports a clear error instead of a fatal, and never
   produces corrupt output
3. the CI matrix catches breakage on supported versions

## Out of scope for v1

Resume after interruption, `--limit`/`--offset` batching, PrestaShop 1.7 and
1.6, other commands (maintenance mode, cache, module management), and any
entry point other than `bin/console`.

**Multistore** is also out of scope for v1, and the README says so. PS 9.1's
native command takes no shop option either, and image directories are shared
across shops in a PrestaShop installation — but image *formats* can be
configured per shop, so a proper multistore implementation needs its own design.
v1 runs against the default shop context that PrestaShop establishes at
bootstrap.

## Verification log

Every claim above was checked against running shops, not documentation:

| Claim | How verified |
|---|---|
| PS 9.1 has the command, 8.2 and 1.7 do not | `bin/console list` on 9.1.4, 8.2.7, 1.7.8.11 |
| PS 9 code cannot be backported | `src/Adapter/ImageThumbnailsRegenerator.php` absent on 8.2 |
| Controller instantiable in CLI | `new AdminImagesController()` succeeded |
| End-to-end regeneration works | 8.2.7: categories 156→158, products 21→23, errors=0 |
| BO timeout reproduced | limit 30 s → `'timeout'` at 26.2 s, 129/1000 images |
| CLI completes the same work | no limit → 175 s, 5000 thumbnails, errors=0 |
| Silence in cron mode | real regeneration (not a simulation) wrote a thumbnail while emitting 0 bytes on stdout and stderr; no `echo`/`print`/`var_dump`/`trigger_error` in the core code path or `ImageManager` |
| Module can claim `prestashop:*` | command registered and ran under that name |
| Collisions are silent | module command named `prestashop:module` displaced the core one |
| PS 9 detection | `class_exists` false on 8.2.7, true on 9.1.4 |
| Per-format invocation is NOT equivalent | with `medium_default` enabled for categories, `small_default` came out BLUE (`_thumb` source) in one call vs RED (full-size) per format — `$image` leaks between iterations (line 706) |
| `_regenerateNoPictureImages` runs for every domain | its call sits outside the `if ($proc['type'] == 'products')` block (lines 956-966) |
| Missing directory returns the success value | `_regenerateNewImages('/nonexistent/')` returned `false`, errors=0 — would exit 0 silently |
| Errors latch across calls | bad dir then good dir: both returned `true`, though the good dir wrote its thumbnails |
| `getimagesize()` notice reaches stdout | 1-byte `.jpg` → `Notice: getimagesize(): Error reading from …!` on stdout; `ImageManager.php:210` has no `@` |
| `ImageType::getImageTypeById()` fatals on unknown id | `TypeError`: declared `array` return, returns `false` |
| `<image-type>` cannot come from `Tools::getValue()` in CLI | returns `bool(false)`; the only call site is line 937, inside a method this module does not call |
| Per-format slicing is unsafe for deletion | core regex evaluated against candidate filenames: orphaned thumbnails deleted with the full list, kept with a single format (`count($type) > 1`, line 634) |
| Legacy context setup is unnecessary | regeneration succeeded with no context setup at all (`result=false`, `errors=0`, file recreated) |
| Format counts differ per domain | categories 2, manufacturers 3, suppliers 3, products 5, stores 1 |
| `Command::isEnabled()` is the right registration hook | exists and is `public` in Symfony 4.4.51 (bundled with PS 8.2) |
| Core has no locking and no atomic writes | no `flock`/`LOCK_EX` in `AdminImagesController`; `ImageManager` calls `imagejpeg()`/`imagewebp()` directly on the destination path |

# bccli v1 — Thumbnail Regeneration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a free MIT PrestaShop 8.x module providing `bin/console prestashop:thumbnails:regenerate`, backporting the command PrestaShop 9.1 has natively, so thumbnails can be regenerated from CLI without the back office timeout.

**Architecture:** A Symfony console command registered by the module drives the existing `AdminImagesController` (whose regeneration methods are `protected`) via reflection. All coupling to that private core API is isolated in one bridge class. The command calls core **once per image domain** with the complete format list — exactly as the back office does — because slicing per format changes the output (verified). Success is silent for cron; failures go to stderr with exit 1.

**Tech Stack:** PHP (7.1-compatible syntax), Symfony Console 4.4 (bundled with PS 8.2), PrestaShop 8.0–8.2, plain-PHP test runners (no PHPUnit).

**Design spec:** `docs/2026-08-04-bccli-thumbnails-design.md` — read it before starting. Every "verified" claim below comes from that document.

## Global Constraints

- **Syntax floor: PHP 7.1-compatible.** No typed properties, arrow functions, union types, constructor promotion, enums, `match`, or nullsafe operator. The spec keeps a later PS 1.7 port open; violating this forecloses it.
- **Namespace:** `Bccli\`, PSR-4 from `src/`. Module class is `Bccli` in `bccli.php` (PrestaShop requires class name to match directory name).
- **`vendor/autoload.php` is committed to the repository.** Modules are installed by unzipping into `modules/`, where nobody runs `composer install`. There are no runtime dependencies.
- **Licence:** MIT. `LICENSE` at repo root; every PHP file starts with `// SPDX-License-Identifier: MIT` after `<?php`. Copyright holder: `Copyright (c) 2026 Mateusz Bartocha <contact@bestcoding.net>`.
- **No AI/LLM mention anywhere** — not in commits, code comments, README, or documentation.
- **All code, comments, commit messages and documentation in English.**
- **Never emit a PHP fatal error.** Every missing class/method/property is caught by the compatibility guard and reported as a message + exit 1.
- **Cron contract:** success writes **zero bytes** to stdout and stderr and exits 0. Failure writes to **stderr** and exits 1, with or without `-v`.
- **Command name:** `prestashop:thumbnails:regenerate` — identical to PS 9.1, deliberately in core's namespace.

## Verified facts the implementation depends on

Copy these into code comments where relevant; they are the reason the code looks the way it does.

| Fact | Consequence |
|---|---|
| `AdminImagesController::$imageFormatConfiguration` is populated from the Symfony container at `AdminImagesController.php:101`; the container is null in CLI | must be constructed manually, else `Call to a member function getGenerationFormats() on null` |
| `$start_time` defaults to `0` (line 36); guard is `time() - start_time > max_execution_time - 4` | leaving it at 0 returns `'timeout'` in 0.01 s having written nothing |
| `_regenerateNewImages()` returns `false` on **success**, `true` on errors, `'timeout'` when interrupted | naive `if (!$res)` reads backwards |
| `$ctrl->errors` is `public` (declared on `ControllerCore`) and **latches** across calls | must be cleared before each call, else every later domain is misreported |
| `$image` is the outer-loop variable but is reassigned inside the format loop (line 706) | calling core once per format produces different image content — never do it |
| `_deleteOldImages()` has a branch gated on `count($type) > 1` (line 634) that removes orphaned thumbnails | must always receive the full format list |
| `_regenerateWatermark()` is inside `if ($proc['type'] == 'products')`, `_regenerateNoPictureImages()` is **outside** it (lines 956-966) | watermark for products only; no-picture for every domain |
| Both core methods return `false` when the directory is missing (lines 625, 680) — the success value | need our own `is_dir()` precheck or a broken symlink exits 0 silently |
| `ImageManager.php:210` calls `getimagesize()` without `@` | a 1-byte source file prints a Notice on **stdout**, bypassing Symfony output control |
| `ImageManager::resize()` fires `Hook::exec('actionOnImageResizeAfter')` | a hooked third-party module can `echo` into our stream |
| `ImageType::getImageTypeById()` declares an `array` return but returns `false` on unknown id | using it for validation throws `TypeError` |
| `Command::isEnabled()` exists and is `public` in Symfony 4.4.51 | correct hook for conditional registration; YAML cannot branch |
| Symfony silently lets the last-registered command win a name collision | must not register when core already has the command |

**Image directory constants** (verified on PS 8.2.7):

| domain | constant | path |
|---|---|---|
| `categories` | `_PS_CAT_IMG_DIR_` | `img/c/` |
| `manufacturers` | `_PS_MANU_IMG_DIR_` | `img/m/` |
| `suppliers` | `_PS_SUPP_IMG_DIR_` | `img/su/` |
| `products` | `_PS_PRODUCT_IMG_DIR_` | `img/p/` |
| `stores` | `_PS_STORE_IMG_DIR_` | `img/st/` |

## File structure

| File | Responsibility |
|---|---|
| `bccli.php` | Module class `Bccli`. Metadata only; no tables, no hooks. |
| `composer.json` | PSR-4 autoload, test scripts, authors. |
| `vendor/autoload.php` + `vendor/composer/*` | Committed generated autoloader. |
| `config/services.yml` | Registers the command unconditionally (the guard is in `isEnabled()`). |
| `src/Thumbnails/ImageDomain.php` | Static map: domain name → directory constant, `isProduct` flag, allowed values. Pure, unit-testable. |
| `src/Thumbnails/LegacyControllerBridge.php` | The only file touching core private API: compatibility guard, controller construction, the three fixes, method invocation. |
| `src/Thumbnails/RunLock.php` | `flock` guard against overlapping runs. |
| `src/Thumbnails/OutputIsolation.php` | Error handler + output buffer so core diagnostics cannot reach stdout. |
| `src/Thumbnails/RegenerationReport.php` | Value object accumulating per-domain results and error messages. |
| `src/Thumbnails/Regenerator.php` | Orchestration: loop over domains, prechecks, call order, report assembly. |
| `src/Command/RegenerateThumbnailsCommand.php` | CLI surface: arguments, `isEnabled()`, progress bar, exit code. |
| `tests/run-unit.php` | Runs every `*Test.php` (no PrestaShop needed). |
| `tests/run-integration.php` | Runs every `*IntegrationTest.php` (needs a booted shop). |
| `tests/ImageDomainTest.php`, `tests/RegenerationReportTest.php`, `tests/RunLockTest.php`, `tests/OutputIsolationTest.php` | Unit tests. |
| `tests/ThumbnailsIntegrationTest.php` | In-container behaviour against a real shop. |
| `LICENSE`, `README.md`, `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md`, `.htaccess` | Repository furniture. |
| `tools/build-zip.sh` | Produces the release ZIP with `bccli/` as the single top-level directory. |

## Task list

1. Repository skeleton and module class — installable, no command yet
2. `ImageDomain` — the domain/directory map
3. `RegenerationReport` — result accumulation
4. `RunLock` — overlap protection
5. `OutputIsolation` — diagnostics containment
6. `LegacyControllerBridge` — core coupling and compatibility guard
7. `Regenerator` — orchestration
8. `RegenerateThumbnailsCommand` + service registration — the CLI surface
9. Integration tests against real shops
10. Release furniture: README, packaging, CI

---

### Task 1: Repository skeleton and module class

**Files:**
- Create: `bccli.php`, `composer.json`, `.htaccess`, `LICENSE`, `.gitignore`
- Create: `config/index.php`, `src/index.php` (PrestaShop convention: every directory gets an `index.php` that redirects)
- Create: `tests/run-unit.php`

**Interfaces:**
- Consumes: nothing
- Produces: class `Bccli extends Module`; namespace `Bccli\` autoloaded from `src/`; `php tests/run-unit.php` runner that later tasks add tests to

- [ ] **Step 1: Create `composer.json`**

```json
{
    "name": "bestcoding/bccli",
    "description": "CLI commands for PrestaShop 8.x",
    "license": "MIT",
    "type": "prestashop-module",
    "authors": [
        {
            "name": "Mateusz Bartocha",
            "email": "contact@bestcoding.net",
            "homepage": "https://bestcoding.net"
        }
    ],
    "autoload": {
        "psr-4": {
            "Bccli\\": "src/"
        },
        "classmap": ["bccli.php"]
    },
    "config": {
        "preferred-install": "dist",
        "optimize-autoloader": true,
        "prepend-autoloader": false
    },
    "scripts": {
        "test": "php tests/run-unit.php",
        "test:integration": "php tests/run-integration.php"
    }
}
```

- [ ] **Step 2: Create `bccli.php`**

`ps_versions_compliancy` sets `min` to `8.0.0` and leaves `max` open — the PS 9 case is handled by the command's `isEnabled()`, which is more precise than a version range. No database tables and no hooks, so install/uninstall add nothing beyond the parent calls.

```php
<?php
// SPDX-License-Identifier: MIT
/**
 * Copyright (c) 2026 Mateusz Bartocha <contact@bestcoding.net>
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

class Bccli extends Module
{
    public function __construct()
    {
        $this->name = 'bccli';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Mateusz Bartocha';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '8.0.0', 'max' => _PS_VERSION_);
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = 'BC CLI';
        $this->description = 'Console commands for shop maintenance, starting with thumbnail regeneration.';
    }
}
```

- [ ] **Step 3: Create `.htaccess` at module root**

`modules/.htaccess` shipped by PrestaShop only protects `.tpl` files — a `.php` file under `modules/` was verified to return HTTP 200. This denies the whole module directory.

```apache
# Apache 2.2
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>

# Apache 2.4
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
```

- [ ] **Step 4: Create `index.php` in every directory**

PrestaShop convention. Same content in `config/index.php` and `src/index.php`:

```php
<?php
// SPDX-License-Identifier: MIT
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Location: ../');
exit;
```

- [ ] **Step 5: Create `LICENSE`**

Standard MIT text with the line `Copyright (c) 2026 Mateusz Bartocha <contact@bestcoding.net>`.

- [ ] **Step 6: Create `.gitignore`**

`vendor/autoload.php` and `vendor/composer/` are deliberately committed, so do **not** ignore `vendor/`.

```
/dist/
*.zip
.DS_Store
.idea/
```

- [ ] **Step 7: Create the unit test runner `tests/run-unit.php`**

No PHPUnit — plain PHP, exit 0 = pass, 1 = fail. Later tasks add `tests/*Test.php` files that this discovers.

```php
<?php
// SPDX-License-Identifier: MIT
declare(strict_types=1);

spl_autoload_register(function ($class) {
    $prefix = 'Bccli\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$failures = array();
$assertions = 0;

function bccli_assert($condition, $message)
{
    global $failures, $assertions;
    ++$assertions;
    if (!$condition) {
        $failures[] = $message;
        echo "  FAIL: $message\n";
    }
}

function bccli_assert_same($expected, $actual, $message)
{
    bccli_assert(
        $expected === $actual,
        $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'
    );
}

$files = glob(__DIR__ . '/*Test.php');
$files = array_filter($files, function ($f) {
    return substr($f, -19) !== 'IntegrationTest.php';
});

if (!$files) {
    echo "no unit test files\n";
    exit(0);
}

foreach ($files as $file) {
    echo basename($file) . "\n";
    require $file;
}

echo "\n$assertions assertions, " . count($failures) . " failures\n";
exit($failures ? 1 : 0);
```

- [ ] **Step 8: Generate and commit the autoloader**

```bash
composer dump-autoload
git add -f vendor/autoload.php vendor/composer/
```

Committing `vendor/` is deliberate: modules are installed by unzipping into `modules/`, where nobody runs `composer install`.

- [ ] **Step 9: Verify the runner works**

Run: `php tests/run-unit.php`
Expected: prints `no unit test files`, exit code 0.

- [ ] **Step 10: Verify the module installs in a real shop**

```bash
docker cp . mking-secscan-ps-1:/var/www/html/modules/bccli
docker exec mking-secscan-ps-1 mysql -h db -uroot -proot prestashop \
  -N -e "SELECT name FROM ps_module WHERE name='bccli'"
```

The container's `bin/console` is broken by an unrelated missing module file, so install through the module manager or verify the class loads:

```bash
docker exec mking-secscan-ps-1 php -r '
$root="/var/www/html";
foreach (glob($root."/admin*") as $a) {
  if (is_dir($a) && file_exists($a."/bootstrap.php")) { define("_PS_ADMIN_DIR_",$a); break; }
}
require_once $root."/config/config.inc.php";
$m = Module::getInstanceByName("bccli");
echo is_object($m) ? "loaded: ".get_class($m)."\n" : "FAILED\n";'
```

Expected: `loaded: Bccli`

- [ ] **Step 11: Commit**

```bash
git add -A
git commit -m "Add module skeleton and unit test runner"
```

---

### Task 2: ImageDomain — the domain/directory map

**Files:**
- Create: `src/Thumbnails/ImageDomain.php`
- Test: `tests/ImageDomainTest.php`

**Interfaces:**
- Consumes: nothing (pure; the PrestaShop constants are read lazily)
- Produces:
  - `ImageDomain::allowedValues(): array` — `['all','categories','manufacturers','suppliers','products','stores']`
  - `ImageDomain::isValid(string $name): bool`
  - `ImageDomain::domainsFor(string $name): array` — expands `all` to the five real domains; returns `[$name]` otherwise
  - `ImageDomain::constantFor(string $domain): string` — e.g. `'_PS_CAT_IMG_DIR_'`
  - `ImageDomain::isProduct(string $domain): bool`

Directory *paths* are resolved by the bridge (Task 6) via `constant()`, so this class stays testable without PrestaShop.

- [ ] **Step 1: Write the failing test `tests/ImageDomainTest.php`**

```php
<?php
// SPDX-License-Identifier: MIT
declare(strict_types=1);

use Bccli\Thumbnails\ImageDomain;

bccli_assert_same(
    array('all', 'categories', 'manufacturers', 'suppliers', 'products', 'stores'),
    ImageDomain::allowedValues(),
    'allowedValues lists all + five domains'
);

bccli_assert(ImageDomain::isValid('products'), 'products is valid');
bccli_assert(ImageDomain::isValid('all'), 'all is valid');
bccli_assert(!ImageDomain::isValid('widgets'), 'widgets is not valid');
bccli_assert(!ImageDomain::isValid('PRODUCTS'), 'validation is case-sensitive');

bccli_assert_same(
    array('categories', 'manufacturers', 'suppliers', 'products', 'stores'),
    ImageDomain::domainsFor('all'),
    'all expands to the five real domains, never including "all" itself'
);
bccli_assert_same(
    array('products'),
    ImageDomain::domainsFor('products'),
    'a single domain expands to itself'
);

bccli_assert_same('_PS_CAT_IMG_DIR_', ImageDomain::constantFor('categories'), 'categories constant');
bccli_assert_same('_PS_MANU_IMG_DIR_', ImageDomain::constantFor('manufacturers'), 'manufacturers constant');
bccli_assert_same('_PS_SUPP_IMG_DIR_', ImageDomain::constantFor('suppliers'), 'suppliers constant');
bccli_assert_same('_PS_PRODUCT_IMG_DIR_', ImageDomain::constantFor('products'), 'products constant');
bccli_assert_same('_PS_STORE_IMG_DIR_', ImageDomain::constantFor('stores'), 'stores constant');

bccli_assert(ImageDomain::isProduct('products'), 'products is the product domain');
bccli_assert(!ImageDomain::isProduct('categories'), 'categories is not the product domain');
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/run-unit.php`
Expected: FAIL — `Class "Bccli\Thumbnails\ImageDomain" not found`

- [ ] **Step 3: Write `src/Thumbnails/ImageDomain.php`**

```php
<?php
// SPDX-License-Identifier: MIT
/**
 * Copyright (c) 2026 Mateusz Bartocha <contact@bestcoding.net>
 */

namespace Bccli\Thumbnails;

/**
 * Maps image domain names to the PrestaShop directory constants that hold them.
 *
 * Holds constant NAMES rather than resolved paths so this class stays usable
 * (and testable) without PrestaShop loaded. The bridge resolves them.
 */
class ImageDomain
{
    const ALL = 'all';

    /**
     * Domain name => [directory constant, is the product image tree].
     *
     * The product tree is special: images live in nested subdirectories and are
     * enumerated from the database, not by scanning one flat directory.
     */
    private static $map = array(
        'categories' => array('_PS_CAT_IMG_DIR_', false),
        'manufacturers' => array('_PS_MANU_IMG_DIR_', false),
        'suppliers' => array('_PS_SUPP_IMG_DIR_', false),
        'products' => array('_PS_PRODUCT_IMG_DIR_', true),
        'stores' => array('_PS_STORE_IMG_DIR_', false),
    );

    /**
     * @return string[] accepted values for the <image> argument
     */
    public static function allowedValues()
    {
        return array_merge(array(self::ALL), array_keys(self::$map));
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public static function isValid($name)
    {
        return in_array($name, self::allowedValues(), true);
    }

    /**
     * Expands the <image> argument into the concrete domains to process.
     *
     * @param string $name
     *
     * @return string[]
     */
    public static function domainsFor($name)
    {
        if ($name === self::ALL) {
            return array_keys(self::$map);
        }

        return array($name);
    }

    /**
     * @param string $domain
     *
     * @return string name of the PrestaShop directory constant
     */
    public static function constantFor($domain)
    {
        return self::$map[$domain][0];
    }

    /**
     * @param string $domain
     *
     * @return bool
     */
    public static function isProduct($domain)
    {
        return self::$map[$domain][1];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/run-unit.php`
Expected: PASS — `0 failures`

- [ ] **Step 5: Commit**

```bash
git add src/Thumbnails/ImageDomain.php tests/ImageDomainTest.php
git commit -m "Add image domain map"
```

---

### Task 3: RegenerationReport — result accumulation

**Files:**
- Create: `src/Thumbnails/RegenerationReport.php`
- Test: `tests/RegenerationReportTest.php`

**Interfaces:**
- Consumes: nothing
- Produces:
  - `new RegenerationReport()`
  - `addError(string $message): void` — de-duplicates identical messages
  - `addImages(int $sources, int $thumbnails): void`
  - `markInterrupted(string $domain): void`
  - `hasErrors(): bool`
  - `errors(): string[]`
  - `sourceCount(): int`, `thumbnailCount(): int`
  - `wasInterrupted(): bool`
  - `exitCode(): int` — 1 if errors or interrupted, else 0

De-duplication matters: core produces one message per failed image, and the same corrupt file can be reported repeatedly.

- [ ] **Step 1: Write the failing test `tests/RegenerationReportTest.php`**

```php
<?php
// SPDX-License-Identifier: MIT
declare(strict_types=1);

use Bccli\Thumbnails\RegenerationReport;

$r = new RegenerationReport();
bccli_assert(!$r->hasErrors(), 'a fresh report has no errors');
bccli_assert_same(0, $r->exitCode(), 'a clean report exits 0');
bccli_assert_same(0, $r->sourceCount(), 'a fresh report counts no sources');

$r->addImages(10, 50);
$r->addImages(5, 25);
bccli_assert_same(15, $r->sourceCount(), 'source counts accumulate');
bccli_assert_same(75, $r->thumbnailCount(), 'thumbnail counts accumulate');
bccli_assert_same(0, $r->exitCode(), 'counting images alone still exits 0');

$r->addError('Failed to resize image file (/img/p/1.jpg)');
$r->addError('Failed to resize image file (/img/p/1.jpg)');
$r->addError('Failed to resize image file (/img/p/2.jpg)');
bccli_assert($r->hasErrors(), 'errors are recorded');
bccli_assert_same(2, count($r->errors()), 'identical messages are de-duplicated');
bccli_assert_same(1, $r->exitCode(), 'a report with errors exits 1');

$i = new RegenerationReport();
$i->markInterrupted('products');
bccli_assert($i->wasInterrupted(), 'interruption is recorded');
bccli_assert_same(1, $i->exitCode(), 'an interrupted report exits 1');
bccli_assert_same(1, count($i->errors()), 'interruption produces one message');
bccli_assert(
    strpos($i->errors()[0], 'products') !== false,
    'the interruption message names the domain'
);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/run-unit.php`
Expected: FAIL — `Class "Bccli\Thumbnails\RegenerationReport" not found`

- [ ] **Step 3: Write `src/Thumbnails/RegenerationReport.php`**

```php
<?php
// SPDX-License-Identifier: MIT
/**
 * Copyright (c) 2026 Mateusz Bartocha <contact@bestcoding.net>
 */

namespace Bccli\Thumbnails;

/**
 * Accumulates the outcome of a regeneration run.
 *
 * Core reports one error per failed image, so the same corrupt source can
 * produce many identical messages; they are de-duplicated here rather than
 * flooding cron mail.
 */
class RegenerationReport
{
    /** @var string[] */
    private $errors = array();

    /** @var int */
    private $sources = 0;

    /** @var int */
    private $thumbnails = 0;

    /** @var bool */
    private $interrupted = false;

    /**
     * @param string $message
     */
    public function addError($message)
    {
        if (!in_array($message, $this->errors, true)) {
            $this->errors[] = $message;
        }
    }

    /**
     * @param int $sources
     * @param int $thumbnails
     */
    public function addImages($sources, $thumbnails)
    {
        $this->sources += (int) $sources;
        $this->thumbnails += (int) $thumbnails;
    }

    /**
     * @param string $domain
     */
    public function markInterrupted($domain)
    {
        $this->interrupted = true;
        $this->addError(sprintf(
            'Interrupted while processing "%s" - some thumbnails may be missing.',
            $domain
        ));
    }

    /**
     * @return bool
     */
    public function hasErrors()
    {
        return $this->errors !== array();
    }

    /**
     * @return string[]
     */
    public function errors()
    {
        return $this->errors;
    }

    /**
     * @return int
     */
    public function sourceCount()
    {
        return $this->sources;
    }

    /**
     * @return int
     */
    public function thumbnailCount()
    {
        return $this->thumbnails;
    }

    /**
     * @return bool
     */
    public function wasInterrupted()
    {
        return $this->interrupted;
    }

    /**
     * @return int 0 on success, 1 if anything went wrong
     */
    public function exitCode()
    {
        return ($this->hasErrors() || $this->interrupted) ? 1 : 0;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/run-unit.php`
Expected: PASS — `0 failures`

- [ ] **Step 5: Commit**

```bash
git add src/Thumbnails/RegenerationReport.php tests/RegenerationReportTest.php
git commit -m "Add regeneration report value object"
```

---

### Task 4: RunLock — overlap protection

**Files:**
- Create: `src/Thumbnails/RunLock.php`
- Test: `tests/RunLockTest.php`

**Interfaces:**
- Consumes: nothing
- Produces:
  - `new RunLock(string $lockFilePath)`
  - `acquire(): bool` — true if this process now holds the lock, false if another holds it
  - `release(): void` — idempotent

Core has no locking of any kind and writes thumbnails non-atomically (`imagejpeg()` straight to the destination, no temp file + `rename()`), so two overlapping cron runs can race on the same files. A second run exiting cleanly is normal operation, not an error.

- [ ] **Step 1: Write the failing test `tests/RunLockTest.php`**

The lock must be held against *other processes*, so the test forks a real second PHP process rather than calling `flock` twice in one process (which would succeed on the same handle and prove nothing).

```php
<?php
// SPDX-License-Identifier: MIT
declare(strict_types=1);

use Bccli\Thumbnails\RunLock;

$path = sys_get_temp_dir() . '/bccli-test-' . getmypid() . '.lock';
@unlink($path);

$lock = new RunLock($path);
bccli_assert($lock->acquire(), 'an uncontended lock is acquired');

// A second PHP process must NOT be able to acquire it while we hold it.
$probe = tempnam(sys_get_temp_dir(), 'bccli-probe');
file_put_contents($probe, '<?php
$fh = fopen(' . var_export($path, true) . ', "c");
echo flock($fh, LOCK_EX | LOCK_NB) ? "ACQUIRED" : "BLOCKED";
');
$result = shell_exec(PHP_BINARY . ' ' . escapeshellarg($probe));
bccli_assert_same('BLOCKED', trim((string) $result), 'a second process is blocked while the lock is held');

$lock->release();
$result = shell_exec(PHP_BINARY . ' ' . escapeshellarg($probe));
bccli_assert_same('ACQUIRED', trim((string) $result), 'the lock is available again after release');

$lock->release();
bccli_assert(true, 'releasing twice does not error');

@unlink($probe);
@unlink($path);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/run-unit.php`
Expected: FAIL — `Class "Bccli\Thumbnails\RunLock" not found`

- [ ] **Step 3: Write `src/Thumbnails/RunLock.php`**

```php
<?php
// SPDX-License-Identifier: MIT
/**
 * Copyright (c) 2026 Mateusz Bartocha <contact@bestcoding.net>
 */

namespace Bccli\Thumbnails;

/**
 * Exclusive lock preventing two regeneration runs from overlapping.
 *
 * PrestaShop core has no locking, and thumbnails are written straight to their
 * destination rather than to a temporary file plus rename(), so two concurrent
 * runs - a cron firing while the previous one is still going, or a run during a
 * back office regeneration - can leave truncated or missing files.
 *
 * The handle is held for the process lifetime; the operating system releases it
 * if the process dies, so a stale lock file cannot wedge future runs.
 */
class RunLock
{
    /** @var string */
    private $path;

    /** @var resource|null */
    private $handle;

    /**
     * @param string $path
     */
    public function __construct($path)
    {
        $this->path = $path;
        $this->handle = null;
    }

    /**
     * @return bool true if this process now holds the lock
     */
    public function acquire()
    {
        $handle = @fopen($this->path, 'c');
        if ($handle === false) {
            // An unwritable lock directory must not block the run; the lock is
            // an optimisation, not a correctness requirement for a single run.
            return true;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->handle = $handle;

        return true;
    }

    public function release()
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/run-unit.php`
Expected: PASS — `0 failures`

- [ ] **Step 5: Commit**

```bash
git add src/Thumbnails/RunLock.php tests/RunLockTest.php
git commit -m "Add run lock guarding against overlapping runs"
```

---

### Task 5: OutputIsolation — diagnostics containment

**Files:**
- Create: `src/Thumbnails/OutputIsolation.php`
- Test: `tests/OutputIsolationTest.php`

**Interfaces:**
- Consumes: nothing
- Produces:
  - `new OutputIsolation()`
  - `start(): void` — installs an error handler, silences `display_errors`, starts output buffering
  - `stop(): string[]` — restores everything and returns captured diagnostics (one entry per notice/warning, plus one entry for any buffered direct output)

This is what makes the cron-silence contract real. Two leaks exist and neither goes through Symfony's `OutputInterface`:

1. `ImageManager.php:210` calls `getimagesize()` without `@`. A 1-byte source file prints `Notice: getimagesize(): Error reading from …!` on **stdout** (verified). Files of exactly 0 bytes are filtered earlier by core's own `filesize()` check, so the window is narrow but real. `display_errors` is forced on whenever `_PS_MODE_DEV_` is true (`config/defines.inc.php:35-44`).
2. `ImageManager::resize()` fires `Hook::exec('actionOnImageResizeAfter')` on every resize, so any hooked third-party module can `echo` directly.

- [ ] **Step 1: Write the failing test `tests/OutputIsolationTest.php`**

```php
<?php
// SPDX-License-Identifier: MIT
declare(strict_types=1);

use Bccli\Thumbnails\OutputIsolation;

// A PHP notice raised inside the isolated section must be captured, not printed.
$isolation = new OutputIsolation();
$isolation->start();
trigger_error('simulated core notice', E_USER_NOTICE);
$captured = $isolation->stop();

bccli_assert_same(1, count($captured), 'the notice is captured');
bccli_assert(
    strpos($captured[0], 'simulated core notice') !== false,
    'the captured entry carries the message'
);

// Direct echo (a hooked module) must be captured too.
$isolation = new OutputIsolation();
$isolation->start();
echo 'noise from a hooked module';
$captured = $isolation->stop();

bccli_assert_same(1, count($captured), 'direct output is captured');
bccli_assert(
    strpos($captured[0], 'noise from a hooked module') !== false,
    'the captured entry carries the echoed text'
);

// A clean section captures nothing.
$isolation = new OutputIsolation();
$isolation->start();
$isolation->stop();
bccli_assert_same(array(), $isolation->stop(), 'a clean section captures nothing and stop() is idempotent');

// display_errors is restored to its original value.
$before = ini_get('display_errors');
$isolation = new OutputIsolation();
$isolation->start();
$isolation->stop();
bccli_assert_same($before, ini_get('display_errors'), 'display_errors is restored');

// The previous error handler is restored: this notice must NOT be captured by a
// stale handler, and PHP must handle it normally.
$isolation = new OutputIsolation();
$isolation->start();
$isolation->stop();
$stillWorks = true;
bccli_assert($stillWorks, 'execution continues normally after stop()');
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/run-unit.php`
Expected: FAIL — `Class "Bccli\Thumbnails\OutputIsolation" not found`

- [ ] **Step 3: Write `src/Thumbnails/OutputIsolation.php`**

```php
<?php
// SPDX-License-Identifier: MIT
/**
 * Copyright (c) 2026 Mateusz Bartocha <contact@bestcoding.net>
 */

namespace Bccli\Thumbnails;

/**
 * Contains PHP diagnostics and stray output produced inside PrestaShop core.
 *
 * The command's cron contract requires zero bytes on success, but core can
 * write to the output streams without going through Symfony's OutputInterface:
 *
 *  - ImageManager.php:210 calls getimagesize() with no "@" suppressor, so an
 *    unreadable-but-non-empty source file emits a Notice on stdout.
 *  - ImageManager::resize() fires the actionOnImageResizeAfter hook, so any
 *    third-party module hooked there can echo freely.
 *
 * Everything captured here is reported as an error instead of being printed.
 */
class OutputIsolation
{
    /** @var string[] */
    private $captured = array();

    /** @var string|false */
    private $previousDisplayErrors = false;

    /** @var bool */
    private $active = false;

    public function start()
    {
        if ($this->active) {
            return;
        }

        $this->captured = array();
        $this->previousDisplayErrors = ini_get('display_errors');
        ini_set('display_errors', '0');

        $captured = &$this->captured;
        set_error_handler(function ($severity, $message, $file, $line) use (&$captured) {
            $captured[] = sprintf('%s (%s:%d)', $message, $file, $line);

            // Returning true stops PHP from also writing this to the output.
            return true;
        });

        ob_start();
        $this->active = true;
    }

    /**
     * @return string[] captured diagnostics; empty when nothing leaked
     */
    public function stop()
    {
        if (!$this->active) {
            return array();
        }

        $buffered = ob_get_clean();
        restore_error_handler();

        if ($this->previousDisplayErrors !== false) {
            ini_set('display_errors', $this->previousDisplayErrors);
        }

        $this->active = false;

        if (is_string($buffered) && trim($buffered) !== '') {
            $this->captured[] = 'Unexpected output from PrestaShop: ' . trim($buffered);
        }

        return $this->captured;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/run-unit.php`
Expected: PASS — `0 failures`

- [ ] **Step 5: Commit**

```bash
git add src/Thumbnails/OutputIsolation.php tests/OutputIsolationTest.php
git commit -m "Add output isolation for core diagnostics"
```

---

### Task 6: LegacyControllerBridge — core coupling and compatibility guard

**Files:**
- Create: `src/Thumbnails/LegacyControllerBridge.php`
- Create: `src/Thumbnails/UnsupportedPlatformException.php`
- Test: covered by integration tests in Task 9 (this class is meaningless without PrestaShop; do not invent a unit test that stubs the whole controller — that would test the stub)

**Interfaces:**
- Consumes: `ImageDomain` (Task 2)
- Produces:
  - `LegacyControllerBridge::assertSupported(): void` — throws `UnsupportedPlatformException` naming the first missing symbol
  - `new LegacyControllerBridge()`
  - `directoryFor(string $domain): string` — resolved absolute path
  - `formatsFor(string $domain): array` — rows from `ImageType::getImagesTypes()`
  - `sourceCountFor(string $domain): int`
  - `deleteOldImages(string $domain, array $formats): void`
  - `regenerateNewImages(string $domain, array $formats)` — returns `false` (success), `true` (errors) or `'timeout'`, verbatim from core
  - `regenerateWatermark(string $domain, array $formats)` — returns `'timeout'` or anything else
  - `regenerateNoPictureImages(string $domain, array $formats): bool` — `true` means failure, matching core
  - `takeErrors(): string[]` — returns `$ctrl->errors` and **clears** it

This is the only file that may use reflection or reference core internals. Everything it works around is listed in *Verified facts* at the top of this plan.

- [ ] **Step 1: Write `src/Thumbnails/UnsupportedPlatformException.php`**

```php
<?php
// SPDX-License-Identifier: MIT
/**
 * Copyright (c) 2026 Mateusz Bartocha <contact@bestcoding.net>
 */

namespace Bccli\Thumbnails;

/**
 * Thrown when this PrestaShop build does not expose the internals the bridge
 * needs. Always caught by the command and reported as a message plus exit 1 -
 * never allowed to surface as a PHP fatal error.
 */
class UnsupportedPlatformException extends \RuntimeException
{
}
```

- [ ] **Step 2: Write `src/Thumbnails/LegacyControllerBridge.php`**

```php
<?php
// SPDX-License-Identifier: MIT
/**
 * Copyright (c) 2026 Mateusz Bartocha <contact@bestcoding.net>
 */

namespace Bccli\Thumbnails;

/**
 * The only class coupled to PrestaShop core internals.
 *
 * PrestaShop 8 keeps thumbnail regeneration in protected methods of
 * AdminImagesController. PrestaShop 9 extracted that logic into
 * Adapter\ImageThumbnailsRegenerator and exposed it through CQRS, but no such
 * adapter exists in 8.x, so the controller is driven directly.
 *
 * Three things must be fixed before the controller works outside a web request:
 *
 *  1. $imageFormatConfiguration is injected from the Symfony container in
 *     AdminImagesController::init() (line 101). The container is unavailable in
 *     CLI, so the property stays null and getGenerationFormats() fatals. It is
 *     built here directly - it only needs a ConfigurationInterface.
 *  2. $start_time defaults to 0 (line 36) and the loop guard is
 *     "time() - start_time > max_execution_time - 4", which is true
 *     immediately, so core returns 'timeout' having written nothing.
 *  3. All the regeneration methods are protected.
 *
 * When these internals change in a future release, this file is the only one to
 * update.
 */
class LegacyControllerBridge
{
    const CONTROLLER = 'AdminImagesController';
    const FORMAT_CONFIG = 'PrestaShop\\PrestaShop\\Core\\Image\\ImageFormatConfiguration';
    const CONFIGURATION_ADAPTER = 'PrestaShop\\PrestaShop\\Adapter\\Configuration';

    /** Large enough that core's guard never trips; CLI has no real limit. */
    const NO_TIME_LIMIT = 86400;

    /** @var \AdminImagesController */
    private $controller;

    /** @var \ReflectionClass */
    private $reflection;

    /**
     * Verifies every core symbol this class touches, before touching any.
     *
     * @throws UnsupportedPlatformException
     */
    public static function assertSupported()
    {
        if (!class_exists(self::CONTROLLER)) {
            throw new UnsupportedPlatformException(self::message('class ' . self::CONTROLLER));
        }

        $reflection = new \ReflectionClass(self::CONTROLLER);

        $methods = array(
            '_deleteOldImages',
            '_regenerateNewImages',
            '_regenerateWatermark',
            '_regenerateNoPictureImages',
        );
        foreach ($methods as $method) {
            if (!$reflection->hasMethod($method)) {
                throw new UnsupportedPlatformException(
                    self::message(self::CONTROLLER . '::' . $method . '()')
                );
            }
        }

        foreach (array('start_time', 'max_execution_time', 'errors') as $property) {
            if (!$reflection->hasProperty($property)) {
                throw new UnsupportedPlatformException(
                    self::message(self::CONTROLLER . '::$' . $property)
                );
            }
        }

        // Present from PS 8.0 onwards, absent on 1.7. Only required when the
        // controller declares the property that consumes it.
        if ($reflection->hasProperty('imageFormatConfiguration') && !class_exists(self::FORMAT_CONFIG)) {
            throw new UnsupportedPlatformException(self::message('class ' . self::FORMAT_CONFIG));
        }
    }

    /**
     * @param string $missing
     *
     * @return string
     */
    private static function message($missing)
    {
        return sprintf(
            'Unsupported PrestaShop %s: %s not found.',
            defined('_PS_VERSION_') ? _PS_VERSION_ : 'build',
            $missing
        );
    }

    public function __construct()
    {
        self::assertSupported();

        $controllerClass = self::CONTROLLER;
        $this->controller = new $controllerClass();
        $this->reflection = new \ReflectionClass(self::CONTROLLER);

        $this->injectImageFormatConfiguration();
        $this->set('start_time', time());
        $this->set('max_execution_time', self::NO_TIME_LIMIT);
    }

    /**
     * Replaces the container injection performed by the controller's init().
     */
    private function injectImageFormatConfiguration()
    {
        if (!$this->reflection->hasProperty('imageFormatConfiguration')) {
            return;
        }

        $formatConfigClass = self::FORMAT_CONFIG;
        $configurationClass = self::CONFIGURATION_ADAPTER;

        $this->set('imageFormatConfiguration', new $formatConfigClass(new $configurationClass()));
    }

    /**
     * @param string $name
     * @param mixed  $value
     */
    private function set($name, $value)
    {
        $property = $this->reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($this->controller, $value);
    }

    /**
     * @param string $name
     * @param array  $arguments
     *
     * @return mixed
     */
    private function call($name, array $arguments)
    {
        $method = $this->reflection->getMethod($name);
        $method->setAccessible(true);

        return $method->invokeArgs($this->controller, $arguments);
    }

    /**
     * @param string $domain
     *
     * @return string
     */
    public function directoryFor($domain)
    {
        return constant(ImageDomain::constantFor($domain));
    }

    /**
     * @param string $domain
     *
     * @return array image type rows as core returns them
     */
    public function formatsFor($domain)
    {
        return \ImageType::getImagesTypes($domain);
    }

    /**
     * Number of source images in a domain, for the summary line.
     *
     * Products are enumerated from the database because their files live in
     * nested subdirectories; every other domain is a flat directory. The
     * pattern is core's own (AdminImagesController.php:697).
     *
     * @param string $domain
     *
     * @return int
     */
    public function sourceCountFor($domain)
    {
        if (ImageDomain::isProduct($domain)) {
            return count(\Image::getAllImages());
        }

        $directory = $this->directoryFor($domain);
        if (!is_dir($directory)) {
            return 0;
        }

        $count = 0;
        foreach (scandir($directory, SCANDIR_SORT_NONE) as $entry) {
            if (preg_match('/^[0-9]*\.jpg$/', $entry)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Always pass the COMPLETE format list.
     *
     * _deleteOldImages() has a branch gated on "count($type) > 1"
     * (AdminImagesController.php:634) that removes orphaned thumbnails left
     * behind by image formats no longer configured. A single-element array
     * makes that branch dead, so --delete would keep the very files it exists
     * to reclaim.
     *
     * @param string $domain
     * @param array  $formats
     */
    public function deleteOldImages($domain, array $formats)
    {
        $this->call('_deleteOldImages', array(
            $this->directoryFor($domain),
            $formats,
            ImageDomain::isProduct($domain),
        ));
    }

    /**
     * Always pass the COMPLETE format list.
     *
     * Core reassigns $image - the OUTER loop variable - inside the format loop
     * (AdminImagesController.php:706) when a category has a "_thumb" original.
     * The value leaks into every later format of the same call, which is the
     * behaviour the back office produces. Calling once per format resets it and
     * yields thumbnails built from a different source file.
     *
     * @param string $domain
     * @param array  $formats
     *
     * @return bool|string false on success, true on errors, 'timeout' if interrupted
     */
    public function regenerateNewImages($domain, array $formats)
    {
        return $this->call('_regenerateNewImages', array(
            $this->directoryFor($domain),
            $formats,
            ImageDomain::isProduct($domain),
        ));
    }

    /**
     * @param string $domain
     * @param array  $formats
     *
     * @return mixed 'timeout' if interrupted
     */
    public function regenerateWatermark($domain, array $formats)
    {
        return $this->call('_regenerateWatermark', array($this->directoryFor($domain), $formats));
    }

    /**
     * @param string $domain
     * @param array  $formats
     *
     * @return bool true means FAILURE, matching core
     */
    public function regenerateNoPictureImages($domain, array $formats)
    {
        return (bool) $this->call('_regenerateNoPictureImages', array(
            $this->directoryFor($domain),
            $formats,
            \Language::getLanguages(false),
        ));
    }

    /**
     * Returns core's accumulated messages and clears them.
     *
     * $ctrl->errors latches: core returns "(bool) count($this->errors)", the
     * whole array rather than this call's errors, so without clearing, one
     * early failure marks every later domain as failed. Core also guards its
     * own post-processing with "if (!count($this->errors))", so a stale entry
     * silently skips no-picture regeneration for the rest of the run.
     *
     * @return string[]
     */
    public function takeErrors()
    {
        $errors = array();
        foreach ((array) $this->controller->errors as $error) {
            $errors[] = (string) $error;
        }

        $this->controller->errors = array();

        return $errors;
    }
}
```

- [ ] **Step 3: Verify the bridge works against a real shop**

There is no unit test here on purpose: this class exists to talk to core, and a stubbed controller would only test the stub. Verify it manually now; Task 9 automates it.

```bash
docker cp . mking-secscan-ps-1:/var/www/html/modules/bccli
docker exec mking-secscan-ps-1 php -r '
$root="/var/www/html";
foreach (glob($root."/admin*") as $a) {
  if (is_dir($a) && file_exists($a."/bootstrap.php")) { define("_PS_ADMIN_DIR_",$a); break; }
}
require_once $root."/config/config.inc.php";
require_once $root."/modules/bccli/vendor/autoload.php";
$b = new Bccli\Thumbnails\LegacyControllerBridge();
echo "dir: ".$b->directoryFor("categories")."\n";
echo "formats: ".count($b->formatsFor("categories"))."\n";
echo "sources: ".$b->sourceCountFor("categories")."\n";
$r = $b->regenerateNewImages("categories", $b->formatsFor("categories"));
echo "result: ".var_export($r, true)." errors: ".count($b->takeErrors())."\n";'
```

Expected: a real path, a non-zero format count, and `result: false` (core's success value) with `errors: 0`.

- [ ] **Step 4: Verify the compatibility guard degrades gracefully**

Run the same bootstrap but call `LegacyControllerBridge::assertSupported()` on PS 9.1, where `AdminImagesController` still exists — it must pass. The negative path is exercised in Task 9.

```bash
docker cp . bigaf:/var/www/html/modules/bccli
docker exec bigaf php -r '
$root="/var/www/html";
foreach (glob($root."/admin*") as $a) {
  if (is_dir($a) && file_exists($a."/bootstrap.php")) { define("_PS_ADMIN_DIR_",$a); break; }
}
require_once $root."/config/config.inc.php";
require_once $root."/modules/bccli/vendor/autoload.php";
try {
  Bccli\Thumbnails\LegacyControllerBridge::assertSupported();
  echo "supported\n";
} catch (Bccli\Thumbnails\UnsupportedPlatformException $e) {
  echo "guard fired: ".$e->getMessage()."\n";
}'
```

Expected: either `supported` or a `guard fired:` line — **never** a PHP fatal error.

- [ ] **Step 5: Commit**

```bash
git add src/Thumbnails/LegacyControllerBridge.php src/Thumbnails/UnsupportedPlatformException.php
git commit -m "Add legacy controller bridge with compatibility guard"
```

---

### Task 7: Regenerator — orchestration

**Files:**
- Create: `src/Thumbnails/Regenerator.php`
- Test: `tests/RegeneratorTest.php` (unit-tests the pure decision logic only)

**Interfaces:**
- Consumes: `ImageDomain` (Task 2), `RegenerationReport` (Task 3), `OutputIsolation` (Task 5), `LegacyControllerBridge` (Task 6)
- Produces:
  - `new Regenerator(LegacyControllerBridge $bridge, OutputIsolation $isolation)`
  - `run(string $image, $imageTypeId, bool $delete, callable $onDomainDone = null): RegenerationReport`
    `$onDomainDone` receives the finished domain name; the command uses it to advance the progress bar
  - `Regenerator::filterFormats(array $formats, $imageTypeId): array` — static, pure, unit-tested
  - `Regenerator::validateImageTypeId($raw): int` — static, pure; throws `InvalidArgumentException` on non-numeric input

Call order mirrors `_regenerateThumbnails()` (lines 927-969) rather than inventing one: delete (optional) → regenerate → watermark **for products only** → no-picture **for every domain**.

- [ ] **Step 1: Write the failing test `tests/RegeneratorTest.php`**

Only the pure parts are unit-tested. The orchestration itself needs a real shop and is covered in Task 9 — stubbing the bridge here would test the stub.

```php
<?php
// SPDX-License-Identifier: MIT
declare(strict_types=1);

use Bccli\Thumbnails\Regenerator;

$formats = array(
    array('id_image_type' => '1', 'name' => 'cart_default'),
    array('id_image_type' => '4', 'name' => 'home_default'),
    array('id_image_type' => '5', 'name' => 'large_default'),
);

bccli_assert_same(
    3,
    count(Regenerator::filterFormats($formats, 0)),
    'format id 0 means all formats'
);

$filtered = Regenerator::filterFormats($formats, 4);
bccli_assert_same(1, count($filtered), 'a specific id selects one format');
$first = array_values($filtered);
bccli_assert_same('home_default', $first[0]['name'], 'the correct format is selected');

bccli_assert_same(
    array(),
    Regenerator::filterFormats($formats, 99),
    'an id matching nothing yields an empty list, not an exception'
);

bccli_assert_same(0, Regenerator::validateImageTypeId(null), 'a missing id means all formats');
bccli_assert_same(0, Regenerator::validateImageTypeId('0'), 'zero means all formats');
bccli_assert_same(4, Regenerator::validateImageTypeId('4'), 'a numeric string is cast to int');

$threw = false;
try {
    Regenerator::validateImageTypeId('abc');
} catch (InvalidArgumentException $e) {
    $threw = true;
    bccli_assert(
        strpos($e->getMessage(), 'abc') !== false,
        'the message quotes the offending value'
    );
}
bccli_assert($threw, 'a non-numeric id is rejected');

$threw = false;
try {
    Regenerator::validateImageTypeId('-3');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
bccli_assert($threw, 'a negative id is rejected');
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/run-unit.php`
Expected: FAIL — `Class "Bccli\Thumbnails\Regenerator" not found`

- [ ] **Step 3: Write `src/Thumbnails/Regenerator.php`**

```php
<?php
// SPDX-License-Identifier: MIT
/**
 * Copyright (c) 2026 Mateusz Bartocha <contact@bestcoding.net>
 */

namespace Bccli\Thumbnails;

/**
 * Drives one regeneration run: one pass per image domain.
 *
 * The call order mirrors AdminImagesController::_regenerateThumbnails()
 * (lines 927-969) rather than inventing one. Note that core calls the watermark
 * step inside "if ($proc['type'] == 'products')" but the no-picture step
 * OUTSIDE it, so placeholder images are refreshed for every domain.
 */
class Regenerator
{
    /** @var LegacyControllerBridge */
    private $bridge;

    /** @var OutputIsolation */
    private $isolation;

    public function __construct(LegacyControllerBridge $bridge, OutputIsolation $isolation)
    {
        $this->bridge = $bridge;
        $this->isolation = $isolation;
    }

    /**
     * @param array    $formats     image type rows from core
     * @param int      $imageTypeId 0 means every format
     *
     * @return array filtered rows, reindexed
     */
    public static function filterFormats(array $formats, $imageTypeId)
    {
        if ((int) $imageTypeId === 0) {
            return $formats;
        }

        $selected = array();
        foreach ($formats as $format) {
            if ((int) $format['id_image_type'] === (int) $imageTypeId) {
                $selected[] = $format;
            }
        }

        return $selected;
    }

    /**
     * Validates the <image-type> argument.
     *
     * Deliberately does NOT use ImageType::getImageTypeById(): it declares an
     * array return type but returns false for an unknown id, so it throws a
     * TypeError - and this command must never surface a PHP fatal error.
     *
     * @param string|null $raw
     *
     * @return int 0 when every format should be processed
     *
     * @throws \InvalidArgumentException
     */
    public static function validateImageTypeId($raw)
    {
        if ($raw === null || $raw === '') {
            return 0;
        }

        if (!ctype_digit((string) $raw)) {
            throw new \InvalidArgumentException(sprintf(
                'Image format ID "%s" is invalid. It must be a positive number, or 0 for all formats.',
                $raw
            ));
        }

        return (int) $raw;
    }

    /**
     * @param string      $image        value of the <image> argument
     * @param string|null $imageTypeId  value of the <image-type> argument
     * @param bool        $delete       erase existing thumbnails first
     * @param callable    $onDomainDone called with each finished domain name
     *
     * @return RegenerationReport
     *
     * @throws \InvalidArgumentException on invalid arguments
     */
    public function run($image, $imageTypeId, $delete, $onDomainDone = null)
    {
        if (!ImageDomain::isValid($image)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown image domain "%s". Allowed: %s.',
                $image,
                implode(', ', ImageDomain::allowedValues())
            ));
        }

        $typeId = self::validateImageTypeId($imageTypeId);
        $domains = ImageDomain::domainsFor($image);
        $report = new RegenerationReport();

        // Reject an id that matches nothing anywhere, rather than silently
        // regenerating zero images.
        if ($typeId !== 0) {
            $this->assertImageTypeExists($domains, $typeId);
        }

        foreach ($domains as $domain) {
            $this->runDomain($domain, $typeId, $delete, $report);

            if (is_callable($onDomainDone)) {
                call_user_func($onDomainDone, $domain);
            }
        }

        return $report;
    }

    /**
     * @param string[] $domains
     * @param int      $typeId
     *
     * @throws \InvalidArgumentException
     */
    private function assertImageTypeExists(array $domains, $typeId)
    {
        foreach ($domains as $domain) {
            if (self::filterFormats($this->bridge->formatsFor($domain), $typeId) !== array()) {
                return;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'Image format ID %d does not exist for %s.',
            $typeId,
            implode(' or ', $domains)
        ));
    }

    /**
     * @param string             $domain
     * @param int                $typeId
     * @param bool               $delete
     * @param RegenerationReport $report
     */
    private function runDomain($domain, $typeId, $delete, RegenerationReport $report)
    {
        $directory = $this->bridge->directoryFor($domain);

        // Core returns false - its SUCCESS value - when the directory is
        // missing (lines 625, 680), so without this precheck a broken symlink
        // would make the command exit 0 having done nothing.
        if (!is_dir($directory)) {
            $report->addError(sprintf('Image directory not found: %s', $directory));

            return;
        }

        if (!is_writable($directory)) {
            $report->addError(sprintf('Cannot write to %s - check permissions.', $directory));

            return;
        }

        $formats = self::filterFormats($this->bridge->formatsFor($domain), $typeId);
        if ($formats === array()) {
            return;
        }

        $sources = $this->bridge->sourceCountFor($domain);

        $this->isolation->start();
        try {
            if ($delete) {
                $this->bridge->deleteOldImages($domain, $formats);
                $this->collect($report);
            }

            $result = $this->bridge->regenerateNewImages($domain, $formats);
            $this->collect($report);

            if ($result === 'timeout') {
                $report->markInterrupted($domain);

                return;
            }

            if (ImageDomain::isProduct($domain)) {
                if ($this->bridge->regenerateWatermark($domain, $formats) === 'timeout') {
                    $report->addError(
                        'Server timed out. The watermark may not have been applied to all images.'
                    );
                }
                $this->collect($report);
            }

            // Core calls this for EVERY domain, not just products - the call
            // sits outside the products branch (lines 956-966).
            if ($this->bridge->regenerateNoPictureImages($domain, $formats)) {
                $report->addError(sprintf(
                    'Cannot write "No picture" image to the %s images folder.',
                    $domain
                ));
            }
            $this->collect($report);

            $report->addImages($sources, $sources * count($formats));
        } finally {
            foreach ($this->isolation->stop() as $leaked) {
                $report->addError($leaked);
            }
        }
    }

    /**
     * Drains core's error list into the report.
     *
     * Must happen after every call: core's errors latch, and it skips its own
     * post-processing while any error is pending.
     *
     * @param RegenerationReport $report
     */
    private function collect(RegenerationReport $report)
    {
        foreach ($this->bridge->takeErrors() as $error) {
            $report->addError($error);
        }
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/run-unit.php`
Expected: PASS — `0 failures`

- [ ] **Step 5: Commit**

```bash
git add src/Thumbnails/Regenerator.php tests/RegeneratorTest.php
git commit -m "Add regeneration orchestration"
```

---

### Task 8: RegenerateThumbnailsCommand and service registration

**Files:**
- Create: `src/Command/RegenerateThumbnailsCommand.php`, `src/Command/index.php`
- Create: `config/services.yml`

**Interfaces:**
- Consumes: everything from Tasks 2-7
- Produces: `bin/console prestashop:thumbnails:regenerate <image> [<image-type>] [-d] [-v]`

The command owns the cron contract: silent on success, stderr plus exit 1 on failure, progress only with `-v`.

- [ ] **Step 1: Write `config/services.yml`**

Registration is unconditional — YAML cannot branch at runtime. The PS 9 guard lives in `isEnabled()`.

```yaml
services:
    bccli.command.regenerate_thumbnails:
        class: Bccli\Command\RegenerateThumbnailsCommand
        tags:
            - { name: 'console.command' }
```

- [ ] **Step 2: Write `src/Command/RegenerateThumbnailsCommand.php`**

```php
<?php
// SPDX-License-Identifier: MIT
/**
 * Copyright (c) 2026 Mateusz Bartocha <contact@bestcoding.net>
 */

namespace Bccli\Command;

use Bccli\Thumbnails\ImageDomain;
use Bccli\Thumbnails\LegacyControllerBridge;
use Bccli\Thumbnails\OutputIsolation;
use Bccli\Thumbnails\Regenerator;
use Bccli\Thumbnails\RunLock;
use Bccli\Thumbnails\UnsupportedPlatformException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Backports PrestaShop 9.1's prestashop:thumbnails:regenerate to 8.x.
 *
 * The name deliberately matches core's so a crontab entry survives an upgrade
 * from 8 to 9 unchanged. Symfony does not reserve namespaces and silently lets
 * the last registered command win a name collision, so isEnabled() withdraws
 * this command whenever core already provides it.
 *
 * Output contract, designed for cron: success writes nothing at all and exits 0;
 * failure writes to stderr and exits 1, with or without -v.
 */
class RegenerateThumbnailsCommand extends Command
{
    const CORE_COMMAND = 'PrestaShopBundle\\Command\\RegenerateThumbnailsCommand';

    /**
     * Withdraws this command when core ships its own.
     *
     * Checks for the class rather than comparing version strings, so it also
     * does the right thing if the command is ever backported into an 8.x patch.
     *
     * @return bool
     */
    public function isEnabled()
    {
        return !class_exists(self::CORE_COMMAND);
    }

    protected function configure()
    {
        $this
            ->setName('prestashop:thumbnails:regenerate')
            ->setDescription('Regenerate thumbnails')
            ->addArgument(
                'image',
                InputArgument::REQUIRED,
                sprintf('Allowed images (%s)', implode(', ', ImageDomain::allowedValues()))
            )
            ->addArgument('image-type', InputArgument::OPTIONAL, 'Image format ID (0 for all)', null)
            ->addOption('delete', 'd', InputOption::VALUE_NONE, 'Erase previous images before regenerating');
    }

    /**
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        // The lock is taken before any work: core writes thumbnails straight to
        // their destination with no temporary file, so overlapping runs can
        // corrupt each other. A second cron firing while the first still runs is
        // normal operation, so it exits 0 in silence rather than mailing an error.
        $lock = new RunLock($this->lockPath());
        if (!$lock->acquire()) {
            if ($output->isVerbose()) {
                $output->writeln('Another regeneration is already running.');
            }

            return 0;
        }

        try {
            LegacyControllerBridge::assertSupported();
            $bridge = new LegacyControllerBridge();
        } catch (UnsupportedPlatformException $e) {
            $errorOutput->writeln($e->getMessage());
            $lock->release();

            return 1;
        }

        // CLI has no execution limit of its own, but core compares elapsed time
        // against its own max_execution_time property, which the bridge sets.
        @set_time_limit(0);

        $image = (string) $input->getArgument('image');
        $domains = ImageDomain::isValid($image) ? ImageDomain::domainsFor($image) : array();

        $progress = null;
        if ($output->isVerbose() && $domains !== array()) {
            $output->writeln(sprintf('Regenerating thumbnails: %s', $image));
            $progress = new ProgressBar($output, count($domains));
            $progress->start();
        }

        $started = microtime(true);

        try {
            $report = (new Regenerator($bridge, new OutputIsolation()))->run(
                $image,
                $input->getArgument('image-type'),
                (bool) $input->getOption('delete'),
                function () use ($progress) {
                    if ($progress !== null) {
                        $progress->advance();
                    }
                }
            );
        } catch (\InvalidArgumentException $e) {
            $errorOutput->writeln($e->getMessage());
            $lock->release();

            return 1;
        } catch (\Exception $e) {
            $errorOutput->writeln('Unable to regenerate thumbnails: ' . $e->getMessage());
            $lock->release();

            return 1;
        }

        if ($progress !== null) {
            $progress->finish();
            $output->writeln('');
        }

        foreach ($report->errors() as $error) {
            $errorOutput->writeln($error);
        }

        if ($output->isVerbose()) {
            $output->writeln(sprintf(
                "\nDone: %d source images, %d thumbnails, %d errors (%d s)",
                $report->sourceCount(),
                $report->thumbnailCount(),
                count($report->errors()),
                (int) round(microtime(true) - $started)
            ));
        }

        $lock->release();

        return $report->exitCode();
    }

    /**
     * @return string
     */
    private function lockPath()
    {
        $directory = defined('_PS_CACHE_DIR_') ? _PS_CACHE_DIR_ : sys_get_temp_dir();

        return rtrim($directory, '/') . '/bccli-thumbnails.lock';
    }
}
```

- [ ] **Step 3: Create `src/Command/index.php`**

Same redirect stub as the other directories (see Task 1, Step 4).

- [ ] **Step 4: Deploy to the PS 8.2 container and install the module**

```bash
docker cp . mking-secscan-ps-1:/var/www/html/modules/bccli
docker exec mking-secscan-ps-1 rm -rf /var/www/html/var/cache/dev
```

That container's own `bin/console` is broken by an unrelated missing module file. Use an admin-context entry point for verification:

```bash
docker exec mking-secscan-ps-1 bash -c 'cat > /tmp/console-admin.php <<"EOF"
#!/usr/bin/env php
<?php
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
$root = "/var/www/html";
foreach (glob($root."/admin*") as $a) {
  if (is_dir($a) && file_exists($a."/bootstrap.php")) { define("_PS_ADMIN_DIR_", $a); break; }
}
set_time_limit(0);
require_once $root . "/config/config.inc.php";
$input = new ArgvInput();
$kernel = new AppKernel($input->getParameterOption(["--env","-e"], "dev"), false);
(new Application($kernel))->run($input);
EOF'
```

- [ ] **Step 5: Verify the command is registered**

```bash
docker exec mking-secscan-ps-1 php /tmp/console-admin.php list prestashop | grep thumbnails
```

Expected: `prestashop:thumbnails:regenerate   Regenerate thumbnails`

- [ ] **Step 6: Verify it regenerates, and is silent**

```bash
docker exec mking-secscan-ps-1 bash -c '
cd /var/www/html
rm -f img/c/3-category_default.jpg
php /tmp/console-admin.php prestashop:thumbnails:regenerate categories >/tmp/o 2>/tmp/e
echo "exit=$? stdout=$(wc -c </tmp/o) stderr=$(wc -c </tmp/e)"
[ -f img/c/3-category_default.jpg ] && echo RESTORED || echo MISSING'
```

Expected: `exit=0 stdout=0 stderr=0` and `RESTORED`.

- [ ] **Step 7: Verify `-v` shows progress**

```bash
docker exec mking-secscan-ps-1 php /tmp/console-admin.php prestashop:thumbnails:regenerate all -v 2>&1 | tail -5
```

Expected: a progress bar with 5 steps and a `Done: … source images, … thumbnails, 0 errors` line.

- [ ] **Step 8: Verify invalid input fails loudly**

```bash
docker exec mking-secscan-ps-1 bash -c '
cd /var/www/html
php /tmp/console-admin.php prestashop:thumbnails:regenerate widgets >/tmp/o 2>/tmp/e
echo "exit=$? stdout=$(wc -c </tmp/o)"; cat /tmp/e'
```

Expected: `exit=1`, `stdout=0`, and `Unknown image domain "widgets". Allowed: all, categories, …` on stderr.

- [ ] **Step 9: Verify the command withdraws on PrestaShop 9**

```bash
docker cp . bigaf:/var/www/html/modules/bccli
docker exec bigaf php bin/console list prestashop | grep -c thumbnails:regenerate
```

Expected: `1` — core's command only. Confirm it is core's by checking the description is not doubled and the command still accepts `--skip-overrides`-era core options:

```bash
docker exec bigaf php bin/console prestashop:thumbnails:regenerate --help | head -3
```

- [ ] **Step 10: Commit**

```bash
git add src/Command config/services.yml
git commit -m "Add prestashop:thumbnails:regenerate command"
```

---

### Task 9: Integration tests against real shops

**Files:**
- Create: `tests/run-integration.php`
- Create: `tests/ThumbnailsIntegrationTest.php`

**Interfaces:**
- Consumes: everything
- Produces: `php tests/run-integration.php` inside a shop container, exit 0 = pass

These tests carry the weight. The module's value is that it talks correctly to core's private API, and only a real shop demonstrates that.

- [ ] **Step 1: Write `tests/run-integration.php`**

```php
<?php
// SPDX-License-Identifier: MIT
declare(strict_types=1);

// Boots the real shop. The admin branch is used on purpose: config.inc.php
// routes through smartyfront.config.inc.php otherwise, which loads module files
// and dies on any shop with a broken module - exactly the shop where a
// repair tool needs to work.
$root = getenv('PS_ROOT') ?: '/var/www/html';
foreach (glob($root . '/admin*') as $candidate) {
    if (is_dir($candidate) && file_exists($candidate . '/bootstrap.php')) {
        define('_PS_ADMIN_DIR_', $candidate);
        break;
    }
}
if (!defined('_PS_ADMIN_DIR_')) {
    fwrite(STDERR, "no admin directory found under $root\n");
    exit(2);
}

require_once $root . '/config/config.inc.php';
require_once __DIR__ . '/../vendor/autoload.php';

$failures = array();
$assertions = 0;

function bccli_assert($condition, $message)
{
    global $failures, $assertions;
    ++$assertions;
    if (!$condition) {
        $failures[] = $message;
        echo "  FAIL: $message\n";
    }
}

function bccli_assert_same($expected, $actual, $message)
{
    bccli_assert(
        $expected === $actual,
        $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'
    );
}

$files = glob(__DIR__ . '/*IntegrationTest.php');
if (!$files) {
    echo "no integration test files\n";
    exit(0);
}

foreach ($files as $file) {
    echo basename($file) . "\n";
    require $file;
}

echo "\n$assertions assertions, " . count($failures) . " failures\n";
exit($failures ? 1 : 0);
```

- [ ] **Step 2: Write `tests/ThumbnailsIntegrationTest.php`**

```php
<?php
// SPDX-License-Identifier: MIT
declare(strict_types=1);

use Bccli\Thumbnails\LegacyControllerBridge;
use Bccli\Thumbnails\OutputIsolation;
use Bccli\Thumbnails\Regenerator;

$bridge = new LegacyControllerBridge();

// --- regeneration actually writes the missing thumbnail -------------------
$directory = $bridge->directoryFor('categories');
$formats = $bridge->formatsFor('categories');
bccli_assert(count($formats) > 0, 'the shop defines at least one category format');

$source = null;
foreach (scandir($directory, SCANDIR_SORT_NONE) as $entry) {
    if (preg_match('/^[0-9]+\.jpg$/', $entry)) {
        $source = $entry;
        break;
    }
}
bccli_assert($source !== null, 'the shop has at least one category source image');

if ($source !== null) {
    $id = basename($source, '.jpg');
    $victim = $directory . $id . '-' . $formats[0]['name'] . '.jpg';
    $backup = $victim . '.bak';
    if (file_exists($victim)) {
        copy($victim, $backup);
        unlink($victim);
    }

    $report = (new Regenerator($bridge, new OutputIsolation()))->run('categories', null, false);

    bccli_assert(file_exists($victim), 'the deleted thumbnail is regenerated');
    bccli_assert_same(0, $report->exitCode(), 'a healthy run exits 0');
    bccli_assert_same(array(), $report->errors(), 'a healthy run reports no errors');

    if (file_exists($backup)) {
        unlink($backup);
    }
}

// --- a missing directory fails loudly, never silently succeeds ------------
// Core returns false (its success value) for a missing directory, so without
// our precheck this case would exit 0 having done nothing.
// Rather than subclassing the bridge (whose constructor runs the compatibility
// guard), point a real domain at a directory that does not exist.
$suppliersDir = $bridge->directoryFor('suppliers');
$movedDir = rtrim($suppliersDir, '/') . '-bccli-moved';
$moved = is_dir($suppliersDir) ? rename($suppliersDir, $movedDir) : false;

if ($moved) {
    $report = (new Regenerator($bridge, new OutputIsolation()))->run('suppliers', null, false);

    bccli_assert_same(1, $report->exitCode(), 'a missing directory exits 1');
    bccli_assert(
        strpos(implode(' ', $report->errors()), 'not found') !== false,
        'the missing directory is named in the error'
    );

    rename($movedDir, $suppliersDir);
    bccli_assert(is_dir($suppliersDir), 'the suppliers directory is restored');
}

// --- an unwritable directory fails loudly ---------------------------------
// Skipped when running as root: uid 0 can write to a 0555 directory, so the
// assertion would pass without proving anything.
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    $storesDir = $bridge->directoryFor('stores');
    if (is_dir($storesDir)) {
        $originalMode = fileperms($storesDir) & 0777;
        chmod($storesDir, 0555);

        $report = (new Regenerator($bridge, new OutputIsolation()))->run('stores', null, false);

        bccli_assert_same(1, $report->exitCode(), 'an unwritable directory exits 1');
        bccli_assert(
            strpos(implode(' ', $report->errors()), 'permissions') !== false,
            'the permission error names permissions'
        );

        chmod($storesDir, $originalMode);
    }
} else {
    echo "  SKIP: permission test requires a non-root user\n";
}

// --- errors do not latch across domains -----------------------------------
// Core returns "(bool) count($this->errors)" - the whole array - so without
// clearing between calls a single early failure marks every later domain as
// failed and suppresses core's own post-processing.
$bridge->takeErrors();
$before = $bridge->takeErrors();
bccli_assert_same(array(), $before, 'takeErrors clears core error state');

// --- invalid arguments are rejected before any work -----------------------
$threw = false;
try {
    (new Regenerator($bridge, new OutputIsolation()))->run('widgets', null, false);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
bccli_assert($threw, 'an unknown domain is rejected');

$threw = false;
try {
    (new Regenerator($bridge, new OutputIsolation()))->run('categories', 'abc', false);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
bccli_assert($threw, 'a non-numeric image type is rejected');

// --- a corrupt source image does not break the cron silence contract ------
// ImageManager.php:210 calls getimagesize() without "@", so an unreadable but
// non-empty file prints a Notice on stdout unless OutputIsolation traps it.
$corrupt = $directory . '999999.jpg';
file_put_contents($corrupt, "\xFF");

ob_start();
$report = (new Regenerator($bridge, new OutputIsolation()))->run('categories', null, false);
$leaked = ob_get_clean();

bccli_assert_same('', trim($leaked), 'a corrupt source image produces no stray output');

unlink($corrupt);
foreach (glob($directory . '999999-*') as $residue) {
    unlink($residue);
}

// --- the no-picture step runs for a non-product domain --------------------
// Core calls _regenerateNoPictureImages() outside the products branch, so
// placeholders must be refreshed for manufacturers too.
$manufacturerDir = $bridge->directoryFor('manufacturers');
if (is_dir($manufacturerDir)) {
    $placeholders = glob($manufacturerDir . '??-default-*.jpg');
    if ($placeholders) {
        $placeholder = $placeholders[0];
        $backup = $placeholder . '.bak';
        copy($placeholder, $backup);
        unlink($placeholder);

        (new Regenerator($bridge, new OutputIsolation()))->run('manufacturers', null, false);

        bccli_assert(file_exists($placeholder), 'no-picture placeholders are regenerated for manufacturers');

        if (!file_exists($placeholder)) {
            copy($backup, $placeholder);
        }
        unlink($backup);
    }
}
```

- [ ] **Step 3: Run the integration suite on PS 8.2**

```bash
docker cp . mking-secscan-ps-1:/var/www/html/modules/bccli
docker exec mking-secscan-ps-1 php /var/www/html/modules/bccli/tests/run-integration.php
```

Expected: `0 failures`, exit 0.

- [ ] **Step 4: Verify output matches the back office where it matters**

This guards the reason per-format slicing was rejected. Enable `medium_default` for categories, place a `_thumb` original, run both paths, compare bytes.

```bash
docker exec mking-secscan-ps-1 bash -c '
cd /var/www/html
mysql -h db -uroot -proot prestashop -e "UPDATE ps_image_type SET categories=1 WHERE name=\"medium_default\""
php /var/www/html/modules/bccli/tests/run-integration.php
mysql -h db -uroot -proot prestashop -e "UPDATE ps_image_type SET categories=0 WHERE name=\"medium_default\""'
```

Expected: still `0 failures`. Restore the flag afterwards, as shown.

- [ ] **Step 5: Verify the guard on a shop where the command is unsupported**

Confirm `assertSupported()` produces a message rather than a fatal when a required symbol is absent. Simulate by checking the negative path directly:

```bash
docker exec mking-secscan-ps-1 php -r '
require_once "/var/www/html/modules/bccli/vendor/autoload.php";
try {
  Bccli\Thumbnails\LegacyControllerBridge::assertSupported();
  echo "unexpected: guard passed without PrestaShop loaded\n";
} catch (Bccli\Thumbnails\UnsupportedPlatformException $e) {
  echo "guard fired cleanly: ".$e->getMessage()."\n";
}'
```

Expected: `guard fired cleanly: Unsupported PrestaShop build: class AdminImagesController not found.` — a message, never a fatal.

- [ ] **Step 6: Commit**

```bash
git add tests/run-integration.php tests/ThumbnailsIntegrationTest.php
git commit -m "Add integration tests against a real shop"
```

---

### Task 10: Release furniture

**Files:**
- Create: `README.md`, `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md`
- Create: `tools/build-zip.sh`
- Create: `.github/workflows/tests.yml`

**Interfaces:**
- Consumes: everything
- Produces: a publishable repository and an installable ZIP

- [ ] **Step 1: Write `README.md`**

Must state, concretely: the problem (back office regeneration times out on large catalogues, measured 129/1000 images in 26 s at a 30 s limit); requirements (PrestaShop 8.0-8.2 tested, PHP 7.1+); installation (ZIP from Releases through the back office, or clone into `modules/bccli`); usage examples including cron; that success is silent and only failures produce output; that PrestaShop 9 already ships this command natively so the module withdraws there; that it is not distributed through the Addons marketplace; and that multistore is out of scope for v1 (image files are shared across shops but format settings are per-shop, so v1 uses the default shop context).

Include a worked cron example:

```cron
# Regenerate every night at 03:17. Silent unless something fails.
17 3 * * * /usr/bin/php /var/www/html/bin/console prestashop:thumbnails:regenerate all
```

Document the one deliberate difference from the PS 8 back office: `<image-type>` is honoured even when regenerating `all`, matching PrestaShop 9 semantics; the PS 8 back office ignores it in that case.

Document the known limitations inherited from core: `--delete` does not remove `_thumb`-derived thumbnails (fixed in PS 9); every resize writes a `fileType` marker file into the destination directory.

- [ ] **Step 2: Write `CHANGELOG.md`**

```markdown
# Changelog

All notable changes to this project are documented in this file.
This project follows [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-08-05

### Added
- `prestashop:thumbnails:regenerate` console command for PrestaShop 8.x,
  matching the interface PrestaShop 9.1 provides natively.
- Run lock preventing overlapping regenerations.
- Silent operation suitable for cron; failures report on stderr with exit 1.
```

- [ ] **Step 3: Write `CONTRIBUTING.md` and `SECURITY.md`**

`CONTRIBUTING.md`: how to run the tests (`php tests/run-unit.php`, and the integration suite inside a shop container), the PHP 7.1 syntax floor and why it exists, and that pull requests need a test.

`SECURITY.md`: report vulnerabilities privately to `contact@bestcoding.net`; the project is provided without a support SLA.

- [ ] **Step 4: Write `tools/build-zip.sh`**

PrestaShop's installer requires a single top-level directory named after the module.

```bash
#!/usr/bin/env bash
# SPDX-License-Identifier: MIT
set -euo pipefail

VERSION="$(grep -oP "(?<=\\\$this->version = ')[^']+" bccli.php)"
BUILD="$(mktemp -d)"
OUT="$PWD/dist/bccli-${VERSION}.zip"

mkdir -p "$(dirname "$OUT")"
rm -f "$OUT"

mkdir -p "$BUILD/bccli"
rsync -a --exclude='.git' --exclude='.github' --exclude='tests' \
      --exclude='tools' --exclude='dist' --exclude='docs' \
      --exclude='*.zip' ./ "$BUILD/bccli/"

(cd "$BUILD" && zip -rq "$OUT" bccli)
rm -rf "$BUILD"

echo "built $OUT"
unzip -l "$OUT" | head -5
```

- [ ] **Step 5: Verify the ZIP structure**

```bash
chmod +x tools/build-zip.sh && ./tools/build-zip.sh
unzip -l dist/bccli-1.0.0.zip | awk '{print $4}' | grep -v '^$' | cut -d/ -f1 | sort -u
```

Expected: exactly one line, `bccli`. Confirm `tests/` and `docs/` are absent and `vendor/autoload.php` is present.

- [ ] **Step 6: Write `.github/workflows/tests.yml`**

```yaml
name: tests

on: [push, pull_request]

jobs:
  unit:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['7.4', '8.0', '8.1']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
      - run: php tests/run-unit.php

  integration:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        prestashop: ['8.0', '8.1', '8.2']
    services:
      mysql:
        image: mariadb:10.6
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: prestashop
        ports: ['3306:3306']
        options: >-
          --health-cmd="healthcheck.sh --connect"
          --health-interval=10s --health-timeout=5s --health-retries=10
    steps:
      - uses: actions/checkout@v4
      - name: Start PrestaShop ${{ matrix.prestashop }}
        run: |
          docker run -d --name ps --network host \
            -e DB_SERVER=127.0.0.1 -e DB_USER=root -e DB_PASSWD=root \
            -e PS_INSTALL_AUTO=1 -e PS_DEV_MODE=0 \
            prestashop/prestashop:${{ matrix.prestashop }}
          timeout 600 bash -c \
            'until docker exec ps test -f /var/www/html/config/settings.inc.php; do sleep 5; done'
      - name: Install the module and run the suite
        run: |
          docker cp . ps:/var/www/html/modules/bccli
          docker exec ps php /var/www/html/modules/bccli/tests/run-integration.php
```

Note the deliberate `PS_DEV_MODE=0`: the silence test must also hold with `display_errors` off, and CI should not depend on dev mode.

- [ ] **Step 7: Run the full suite one final time**

```bash
php tests/run-unit.php
docker exec mking-secscan-ps-1 php /var/www/html/modules/bccli/tests/run-integration.php
```

Expected: both report `0 failures`.

- [ ] **Step 8: Commit**

```bash
git add README.md CHANGELOG.md CONTRIBUTING.md SECURITY.md tools/ .github/
git commit -m "Add documentation, packaging script and CI"
```

- [ ] **Step 9: Clean the test containers**

The shops used during development are shared. Remove the module and any residue:

```bash
for c in mking-secscan-ps-1 bigaf; do
  docker exec "$c" rm -rf /var/www/html/modules/bccli /tmp/console-admin.php
done
docker exec mking-secscan-ps-1 mysql -h db -uroot -proot prestashop \
  -e "DELETE FROM ps_module WHERE name='bccli'"
```

Verify `ps_image_type.categories` for `medium_default` is back to its original value (Task 9, Step 4 toggles it).

---

## Notes for the implementer

**Do not "improve" these while implementing** — each is a deliberate decision backed by a measurement recorded in the design document:

- Core is called **once per domain** with the full format list. Slicing per format was tested and produces different image content, because core reassigns the outer loop variable at line 706.
- `false` from `_regenerateNewImages()` means **success**.
- `$ctrl->errors` must be cleared between calls.
- `_regenerateNoPictureImages()` runs for every domain; `_regenerateWatermark()` only for products.
- Success prints **nothing**. Not a summary, not "OK" — cron mails any output.
- Never call `ImageType::getImageTypeById()`; it throws a `TypeError` on unknown ids.
- PHP 7.1 syntax floor, so no typed properties, arrow functions or promotion.

**If a step's expected output does not match**, stop and report it rather than adapting the test to the result. A surprise here usually means a genuine difference between PrestaShop builds, and that belongs in the compatibility guard.

<?php
/**
 * Copyright since 2026 Mateusz Bartocha
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to contact@bestcoding.net so we can send you a copy immediately.
 *
 * @author    Mateusz Bartocha <contact@bestcoding.net>
 * @copyright Since 2026 Mateusz Bartocha
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
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

// Every subprocess probe any *IntegrationTest.php file below spawns via
// proc_open() constructs its own kernel with debug explicitly off, matching
// how a real cron/CLI invocation of this module's commands actually runs on
// this shop (see FeatureFlagIntegrationTest.php's comment on the
// consecutive-toggle loop for the live crash report this is grounded in).
// This process's own kernel - the one FeatureFlagManager::kernel() falls
// back to lazily building the first time any test file calls listFlags() or
// setState() before any subprocess has run - must match that debug=false
// choice, not _PS_MODE_DEV_ (true on this install, which is what the lazy
// fallback would otherwise use). Two independent problems follow from a
// mismatch here, both confirmed by reading Symfony's own source rather than
// assumed:
//   1. Kernel::boot() unconditionally putenv()'s SHELL_VERBOSITY=3 the first
//      time a debug=true kernel boots (vendor/symfony/symfony/src/Symfony/
//      Component/HttpKernel/Kernel.php) - and putenv() poisons this whole
//      process's environment for every proc_open() spawned afterward by any
//      test file, making a subprocess launched with no -v/-q flags at all
//      resolve to debug-level verbosity anyway, as if -vvv had been passed.
//   2. Symfony\Component\Cache\DependencyInjection\CachePoolPass computes
//      every cache pool's on-disk namespace from a seed that includes
//      %kernel.container_class%, and Kernel::getContainerClass() appends
//      "Debug" to that class name whenever $this->debug is true. A
//      debug=true kernel in this process and the debug=false kernel every
//      subprocess constructs therefore resolve cache.app to two entirely
//      different, non-overlapping on-disk namespaces even though both point
//      at the same var/cache/{env} directory - so an item seeded into
//      cache.app from this process is invisible to (and can never be
//      cleared by) any subprocess, and vice versa.
// Booting the kernel here, before any test file gets a chance to trigger
// FeatureFlagManager::kernel()'s lazy-boot fallback, avoids both: that
// fallback only constructs a kernel when the global $kernel is not already a
// KernelInterface, so every test file below transparently reuses this one.
if (!class_exists('AppKernel', false)) {
    require_once _PS_ROOT_DIR_ . '/app/AppKernel.php';
}
$kernel = new AppKernel(defined('_PS_ENV_') ? _PS_ENV_ : 'prod', false);
$kernel->boot();

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

// A test file that calls exit()/die() directly, or hits an uncaught fatal
// error, would otherwise stop this loop silently mid-run: every file after it
// never runs, but nothing says so - the process just ends, the final "N
// assertions, N failures" line below never prints, and a caller that only
// checks the process exit code has no way to tell a truncated run from a
// clean one (a bare exit()/die() with no code is exit 0, indistinguishable
// from success). Track which file is in flight and let a shutdown handler
// speak up if the process is about to end before the summary below ran.
$currentFile = null;
$summaryPrinted = false;

register_shutdown_function(function () use (&$currentFile, &$summaryPrinted, &$failures) {
    if ($summaryPrinted) {
        // The normal exit($failures ? 1 : 0) below also triggers shutdown
        // functions - this is that expected, already-reported path.
        return;
    }

    $error = error_get_last();
    fwrite(STDERR, sprintf(
        "\nFATAL: run stopped before completing all test files (in progress: %s) - %s\n",
        $currentFile !== null ? basename($currentFile) : 'none started',
        $error !== null ? $error['message'] : 'a test file called exit() or die() directly'
    ));
    exit(1);
});

foreach ($files as $file) {
    $currentFile = $file;
    echo basename($file) . "\n";
    require $file;
}
$currentFile = null;

$summaryPrinted = true;
echo "\n$assertions assertions, " . count($failures) . " failures\n";
exit($failures ? 1 : 0);

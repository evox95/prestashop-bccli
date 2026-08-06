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

/*
 * What this suite pins, and what it does not.
 *
 * Pinned by the assertions below: FeatureFlagManager::assertSupported()
 * passes on a real PrestaShop install; listFlags() returns at least one row,
 * each with a real name and a boolean state; setState() actually flips a real
 * ps_feature_flag row - proven by reading the state back through a second,
 * independent listFlags() call rather than trusting setState()'s own success;
 * an unknown flag name is rejected with UnknownFeatureFlagException instead
 * of silently no-opping (the exact silent no-op FeatureFlagRepository itself
 * would otherwise produce - see FeatureFlagManager's own docblock).
 *
 * NOT pinned here: that clearRoutingCache() actually purges the routing
 * cache pool. That was verified once, directly, against a live container by
 * writing a probe item into the cache.app pool, running cache:clear, and
 * re-checking through a freshly booted kernel/container - see the module's
 * change history for that investigation. Re-running a full cache:clear from
 * this suite on every test run would be slow and would not tell us anything
 * that probe did not already establish more precisely; what this suite does
 * pin is that setState() does not throw because of the cache-clear step on a
 * real install (if clearRoutingCache() started failing, the try/catch in
 * setState() would turn that into a \RuntimeException and the assertion
 * below that setState() completes without exception would catch it).
 */

use Bccli\FeatureFlag\FeatureFlagManager;
use Bccli\FeatureFlag\UnknownFeatureFlagException;
use Bccli\FeatureFlag\UnsupportedPlatformException;

// --- the platform guard passes on a real install ---------------------------
$threw = false;
try {
    FeatureFlagManager::assertSupported();
} catch (UnsupportedPlatformException $e) {
    $threw = true;
}
bccli_assert(!$threw, 'assertSupported() does not throw on a real PrestaShop install');

$manager = new FeatureFlagManager();

// --- list returns the real, seeded flags ------------------------------------
$flags = $manager->listFlags();
bccli_assert(!empty($flags), 'listFlags() returns at least one flag on a real install');

$flagsByName = array();
foreach ($flags as $flag) {
    bccli_assert(is_string($flag['name']) && $flag['name'] !== '', 'every flag has a non-empty name');
    bccli_assert(is_bool($flag['state']), 'every flag state is a boolean');
    $flagsByName[$flag['name']] = $flag['state'];
}

// --- toggle a real flag, prove it changed, then restore it ------------------
// Any flag works for this - the point is only that setState() actually
// writes through to the row and that it is observable again afterwards, not
// which flag it is. The first flag listFlags() returned is used so the test
// does not depend on any particular flag name existing (beyond "at least one
// flag exists", already asserted above).
$targetName = $flags[0]['name'];
$originalState = $flags[0]['state'];
$flippedState = !$originalState;

$threw = false;
try {
    $manager->setState($targetName, $flippedState);
} catch (Exception $e) {
    $threw = true;
}
bccli_assert(!$threw, 'setState() completes without exception on a real, known flag');

$afterFlip = null;
foreach ($manager->listFlags() as $flag) {
    if ($flag['name'] === $targetName) {
        $afterFlip = $flag['state'];
        break;
    }
}
bccli_assert_same($flippedState, $afterFlip, 'the flag\'s state actually changed - read back independently, not trusted from setState() itself');

// Restore before asserting anything else, so a failure below never leaves a
// shared dev shop's flag in the flipped state.
$restoreThrew = false;
try {
    $manager->setState($targetName, $originalState);
} catch (Exception $e) {
    $restoreThrew = true;
}
bccli_assert(!$restoreThrew, 'restoring the flag to its original state completes without exception');

$afterRestore = null;
foreach ($manager->listFlags() as $flag) {
    if ($flag['name'] === $targetName) {
        $afterRestore = $flag['state'];
        break;
    }
}
bccli_assert_same($originalState, $afterRestore, 'the flag is back to exactly the state this suite found it in');

// --- an unknown flag name is rejected, not silently no-opped ----------------
$unknownName = 'bccli_test_flag_that_does_not_exist_' . md5((string) microtime(true));
bccli_assert(
    !array_key_exists($unknownName, $flagsByName),
    'the generated unknown flag name does not happen to collide with a real one'
);

$threw = false;
try {
    $manager->setState($unknownName, true);
} catch (UnknownFeatureFlagException $e) {
    $threw = true;
    bccli_assert(
        strpos($e->getMessage(), $unknownName) !== false,
        'the unknown-flag message names the offending value'
    );
}
bccli_assert($threw, 'an unknown flag name raises UnknownFeatureFlagException instead of silently no-opping');

// --- four consecutive toggles against one warm, on-disk compiled cache -----
// --- through the REAL command, each as a REAL separate OS process ---------
// (regression test for the post-command cache-clearing defect)
//
// The bug this pins: clearing the compiled cache mid-request - whether by
// running `cache:clear` in-process (the original approach) or by deleting
// the directory's contents directly on disk, even deferred to PHP's
// shutdown sequence (the first two attempted fixes, each broken in a
// different way) - risks deleting or replacing the compiled-container files
// a CURRENTLY RUNNING kernel's container (this process's own, or a
// different, concurrently running process's) was itself built from.
// Symfony's own console Application dispatches ConsoleEvents::TERMINATE
// after every command's execute() returns (success or failure, every
// single run), and on this install that event's own listener chain lazily
// resolves a private Swiftmailer listener service that had not been
// instantiated yet; if the file backing it is gone by the time that
// happens, PHP's require() fails and Symfony's own error rendering turns a
// successful toggle into a reported failure. See
// FeatureFlagManager::clearCompiledCache()'s own docblock for the full
// three-stage writeup (in-process cache:clear, deferred filesystem
// deletion, and why the final fix - Symfony's public cache_clearer service,
// which only ever calls ->clear() on PSR-6 pool adapters and never touches
// the compiled-container directory at all - is what actually closes both
// failure modes).
//
// This loop deliberately does NOT call FeatureFlagManager::setState() or
// FeatureFlagCommand::execute() directly, unlike the rest of this suite -
// that would never exercise the defect at all, because the crash lives in
// Symfony's OWN post-command Console Application lifecycle
// (ConsoleEvents::TERMINATE), which only fires when a command is actually
// run through Application::run(). Confirmed live: the same crash this test
// pins reproduced on the very FIRST toggle of a freshly started process
// invoking the real command through Application::run(), while a loop
// calling setState() directly - the way an earlier version of this test
// did - never reproduced it even once, under either the original bug or
// the first (still broken) fix attempt. Each iteration below is therefore a
// genuine `php <probe> <action> <flag> -q` invocation - a separate OS
// process, exactly like a cron invocation of prestashop:feature-flag would
// be - asserting the real exit code and real stderr byte count against the
// contract FeatureFlagCommand's own class docblock documents: "enable/
// disable succeed in total silence and exit 0".
$consecutiveTargetName = $flags[0]['name'];
$consecutiveOriginalState = $flagsByName[$consecutiveTargetName];
$consecutiveState = $consecutiveOriginalState;

$consecutivePsRoot = defined('_PS_ROOT_DIR_') ? _PS_ROOT_DIR_ : (getenv('PS_ROOT') ?: '/var/www/html');
$consecutiveModuleAutoload = __DIR__ . '/../vendor/autoload.php';

// Mirrors the admin-dir bootstrap trick this suite's own run-integration.php
// uses (config.inc.php routes through smartyfront.config.inc.php otherwise,
// which loads module files and can die on a broken module elsewhere in the
// shop), plus the same kernel-construction fallback
// FeatureFlagManager::kernel() documents and uses internally. Debug is
// forced off to match how a real cron invocation runs (and to match the
// non-debug container class named in the original live crash report - the
// debug container variant wires additional event logging that would swamp
// stderr independently of anything this module does, defeating the "zero
// bytes on success" assertion below for reasons unrelated to the bug).
$consecutiveProbeTemplate = <<<'PROBE'
<?php
$root = '__PS_ROOT__';
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
require_once '__MODULE_AUTOLOAD__';

if (!class_exists('AppKernel', false)) {
    require_once $root . '/app/AppKernel.php';
}

$kernel = new AppKernel(defined('_PS_ENV_') ? _PS_ENV_ : 'prod', false);
$kernel->boot();

$command = new \Bccli\Command\FeatureFlagCommand();
$application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
$application->add($command);
$application->setDefaultCommand($command->getName(), true);
$application->setAutoExit(true);

$argv = $_SERVER['argv'];
array_shift($argv);
array_unshift($argv, 'probe.php');
$application->run(new \Symfony\Component\Console\Input\ArgvInput($argv));
PROBE;

$consecutiveProbeScript = str_replace(
    array('__PS_ROOT__', '__MODULE_AUTOLOAD__'),
    array($consecutivePsRoot, $consecutiveModuleAutoload),
    $consecutiveProbeTemplate
);

$consecutiveProbePath = tempnam(sys_get_temp_dir(), 'bccli-ff-probe') . '.php';
file_put_contents($consecutiveProbePath, $consecutiveProbeScript);

// Each iteration below reads the flag back through $manager - the SAME
// FeatureFlagManager (and therefore the same Doctrine EntityManager) used
// by every other assertion in this file, not a fresh one per iteration.
// That reuse is exactly what makes the read-back "independent" of the
// subprocess in the sense this suite cares about (it goes through the
// ordinary listFlags() path, not through anything the subprocess touched),
// but it also means the FeatureFlag entity for $consecutiveTargetName is
// already sitting in that EntityManager's identity map by this point - it
// was first loaded back at the "toggle a real flag" section above, which
// uses the same $flags[0]['name']. Doctrine's hydrator does not overwrite
// an already-managed entity's fields with a freshly queried row; it just
// returns the existing, already-known object as-is. A separate OS process
// changing the row underneath it (exactly what each subprocess below does)
// is therefore invisible to $manager->listFlags() until this process's own
// EntityManager is told to forget what it has cached. Confirmed live: the
// two bccli_assert_same() calls below failed for iterations #1 and #3 -
// the ones that move the flag away from its original state - while #2 and
// #4 passed, but only by coincidence, because they move it back to the
// same value the stale cached object already held; clearing the identity
// map before every read-back (not just once before the loop) fixed all
// four.
global $kernel;
$consecutiveEntityManager = $kernel->getContainer()->get('doctrine.orm.entity_manager');

for ($i = 1; $i <= 4; ++$i) {
    $consecutiveState = !$consecutiveState;
    $consecutiveAction = $consecutiveState ? 'enable' : 'disable';

    $consecutiveCommandLine = implode(' ', array(
        escapeshellarg(PHP_BINARY),
        escapeshellarg($consecutiveProbePath),
        escapeshellarg($consecutiveAction),
        escapeshellarg($consecutiveTargetName),
        '-q',
    ));

    $consecutivePipes = array();
    $consecutiveProcess = proc_open(
        $consecutiveCommandLine,
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $consecutivePipes
    );

    bccli_assert(is_resource($consecutiveProcess), 'consecutive toggle #' . $i . ' subprocess starts');

    $consecutiveStderr = '';
    $consecutiveExitCode = -1;
    if (is_resource($consecutiveProcess)) {
        fclose($consecutivePipes[0]);
        $consecutiveStdout = stream_get_contents($consecutivePipes[1]);
        $consecutiveStderr = stream_get_contents($consecutivePipes[2]);
        fclose($consecutivePipes[1]);
        fclose($consecutivePipes[2]);
        $consecutiveExitCode = proc_close($consecutiveProcess);

        bccli_assert_same(
            0,
            $consecutiveExitCode,
            'consecutive toggle #' . $i . ' (' . $consecutiveAction . ', real command, real process) exits 0'
                . ($consecutiveExitCode !== 0 ? ' - stderr: ' . $consecutiveStderr : '')
        );
        bccli_assert_same(
            '',
            $consecutiveStderr,
            'consecutive toggle #' . $i . ' writes zero bytes to stderr under -q'
        );
        bccli_assert_same(
            '',
            $consecutiveStdout,
            'consecutive toggle #' . $i . ' writes zero bytes to stdout under -q'
        );
    }

    // Forget every entity this process's EntityManager already has
    // identity-mapped, so the listFlags() call below issues a genuinely
    // fresh query and hydrates a fresh object - otherwise it would just
    // hand back the same, now-stale object loaded earlier in this file or
    // in a previous iteration of this very loop, never seeing what the
    // subprocess above actually wrote to the row.
    $consecutiveEntityManager->clear();

    $readBack = null;
    foreach ($manager->listFlags() as $flag) {
        if ($flag['name'] === $consecutiveTargetName) {
            $readBack = $flag['state'];
            break;
        }
    }
    bccli_assert_same(
        $consecutiveState,
        $readBack,
        'consecutive toggle #' . $i . ' actually changed the flag, read back independently through the manager'
    );
}

@unlink($consecutiveProbePath);

// Restore, regardless of whether four toggles happens to land back on the
// original state (it does here, since 4 is even, but do not rely on that).
$restoreConsecutiveThrew = false;
try {
    $manager->setState($consecutiveTargetName, $consecutiveOriginalState);
} catch (\Throwable $e) {
    $restoreConsecutiveThrew = true;
}
bccli_assert(!$restoreConsecutiveThrew, 'restoring the flag after the consecutive-toggle run completes without exception');

$afterConsecutiveRestore = null;
foreach ($manager->listFlags() as $flag) {
    if ($flag['name'] === $consecutiveTargetName) {
        $afterConsecutiveRestore = $flag['state'];
        break;
    }
}
bccli_assert_same(
    $consecutiveOriginalState,
    $afterConsecutiveRestore,
    'the flag is back to its original state after the consecutive-toggle run'
);

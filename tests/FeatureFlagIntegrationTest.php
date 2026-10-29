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
 * Also pinned, in the cache-pool-probe section at the very end of this file:
 * that clearRoutingCache() (renamed from an earlier, wrongly-named
 * clearCompiledCache() once its fix actually landed on the right service)
 * really does clear the cache.app pool
 * PrestaShopBundle\Routing\Converter\CacheProvider reads from, not merely
 * avoid crashing. That distinction matters because a wrong fix once passed
 * every OTHER assertion in this file: Symfony's own public "cache_clearer"
 * chain service exits 0 in total silence on every toggle, exactly like a
 * correct fix, while never actually touching cache.app - see
 * FeatureFlagManager::clearRoutingCache()'s own docblock for the full
 * CachePoolPass-level trace of why. The cache-pool-probe section below seeds
 * a distinguishable item directly into cache.app, runs one real toggle
 * through the real command as a genuine subprocess, and asserts the item is
 * gone afterward - the only kind of assertion that can tell a correct
 * clearer apart from one that merely completes without error, which is
 * exactly why it is not folded into the ordinary toggle assertions above:
 * those only ever proved setState() does not throw because of the
 * cache-clear step, never that the step did anything.
 */

use Bccli\FeatureFlag\FeatureFlagManager;
use Bccli\FeatureFlag\UnknownFeatureFlagException;
use Bccli\FeatureFlag\UnsupportedPlatformException;

if (version_compare(_PS_VERSION_, '8.0.0', '<')) {
    echo "  SKIP: feature flags are unavailable on PrestaShop 1.7\n";

    return;
}

// --- the platform guard passes on a real install ---------------------------
$threw = false;
try {
    FeatureFlagManager::assertSupported();
} catch (UnsupportedPlatformException $e) {
    $threw = true;
}
bccli_assert(!$threw, 'assertSupported() does not throw on a real PrestaShop install');

// --- the manager can actually resolve its repository -------------------------
// Deliberately its own assertion, separate from and before every other one in
// this file. FeatureFlagManager::repository() reaches
// PrestaShopBundle\Entity\Repository\FeatureFlagRepository through one of two
// independent mechanisms: by its own service id - public on PrestaShop 8.2+,
// where it is registered directly under that id (confirmed by reading
// src/PrestaShopBundle/Resources/config/services/bundle/repository.yml on
// PrestaShop's own 8.2.7 tag) - or, when that id is not registered at all
// (PrestaShop 8.0/8.1: the identical repository is instead registered only
// under a different id, prestashop.core.admin.feature_flag.repository -
// confirmed by reading the same file on the 8.0.5 tag), by asking
// doctrine.orm.entity_manager for it instead. class_exists() alone (what
// assertSupported() above checks) cannot see this gap - the entity and
// repository classes ship on every 8.x either way - only actually asking the
// container can, which is exactly what shipped broken in 1.1.0: PrestaShop
// 8.0 CI failed with a raw ServiceNotFoundException that aborted this whole
// file before a single assertion below ran, unreproducible locally because
// the only shop reachable for manual verification runs 8.2.7. This assertion
// turns any future drift in either service id into one readable failure
// naming which mechanism was expected, instead of a stack trace that kills
// the run.
global $kernel;
$repositoryMechanism = $kernel->getContainer()->has(FeatureFlagManager::REPOSITORY_SERVICE)
    ? 'service id "' . FeatureFlagManager::REPOSITORY_SERVICE . '"'
    : ($kernel->getContainer()->has('doctrine.orm.entity_manager')
        ? 'doctrine.orm.entity_manager fallback'
        : 'neither the service id nor doctrine.orm.entity_manager is available');

// --- shared infra for every genuine-subprocess check in this file ----------
// Hoisted here (rather than defined once at first use, as an earlier version
// of this file did) because the very first single-toggle check below now
// needs it too, not just the consecutive-toggle loop and the cache-pool
// probe further down. Mirrors the admin-dir bootstrap trick this suite's own
// run-integration.php uses (config.inc.php routes through
// smartyfront.config.inc.php otherwise, which loads module files and can die
// on a broken module elsewhere in the shop), plus the same kernel-
// construction fallback FeatureFlagManager::kernel() documents and uses
// internally. Debug is forced off to match how a real cron invocation runs
// (and to match the non-debug container class named in the original live
// crash report - the debug container variant wires additional event logging
// that would swamp stderr independently of anything this module does,
// defeating any "zero bytes on success" assertion for reasons unrelated to
// whatever bug is actually being tested).
$consecutivePsRoot = defined('_PS_ROOT_DIR_') ? _PS_ROOT_DIR_ : (getenv('PS_ROOT') ?: '/var/www/html');
$consecutiveModuleAutoload = __DIR__ . '/../vendor/autoload.php';

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

$manager = new FeatureFlagManager();
$threw = false;
$flags = array();
try {
    $flags = $manager->listFlags();
} catch (UnsupportedPlatformException $e) {
    $threw = true;
}
bccli_assert(!$threw, 'FeatureFlagManager resolves its repository (expected mechanism: ' . $repositoryMechanism . ')');

// --- list returns the real, seeded flags ------------------------------------
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

// --- prove the toggle survived process death, not just entity-manager ------
// --- staleness (regression test for the PS 8.0/8.1 non-persisting bug) -----
//
// The same-process read-back just above is not the whole story: a same-
// process read can be satisfied by an in-memory FeatureFlag entity that was
// mutated but never actually flushed to the database. That is exactly what
// PrestaShop 8.0/8.1's own FeatureFlagRepository::enable()/disable() used to
// leave this module trusting - see FeatureFlagManager::entityManager()'s own
// docblock for the full enable()/disable() 8.0-vs-8.2 source comparison
// (8.2 flushes, 8.0/8.1 mutate the entity and stop). This suite passed on
// PS 8.2 (where the repository itself calls flush()) while the identical
// code this file exercises silently wrote nothing on PS 8.0 - a same-
// process check like $afterFlip above cannot tell "written to the row"
// apart from "mutated in memory, never written", because both look
// identical from inside the same process's own EntityManager, no matter how
// many times it re-reads. A genuinely separate OS process has no access to
// this process's identity map or any unflushed change at all - it can only
// observe what actually reached the row - so it is the one read-back an
// unflushed mutation cannot fool.
$freshProcessProbePath = tempnam(sys_get_temp_dir(), 'bccli-ff-fresh') . '.php';
file_put_contents($freshProcessProbePath, $consecutiveProbeScript);

$freshProcessCommandLine = implode(' ', array(
    escapeshellarg(PHP_BINARY),
    escapeshellarg($freshProcessProbePath),
    escapeshellarg('list'),
));

$freshProcessPipes = array();
$freshProcessHandle = proc_open(
    $freshProcessCommandLine,
    array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
    $freshProcessPipes
);

bccli_assert(is_resource($freshProcessHandle), 'the fresh-process read-back subprocess starts');

$freshProcessState = null;
if (is_resource($freshProcessHandle)) {
    fclose($freshProcessPipes[0]);
    $freshProcessStdout = stream_get_contents($freshProcessPipes[1]);
    $freshProcessStderr = stream_get_contents($freshProcessPipes[2]);
    fclose($freshProcessPipes[1]);
    fclose($freshProcessPipes[2]);
    $freshProcessExitCode = proc_close($freshProcessHandle);

    bccli_assert_same(
        0,
        $freshProcessExitCode,
        'the fresh-process "list" read-back exits 0'
            . ($freshProcessExitCode !== 0 ? ' - stderr: ' . $freshProcessStderr : '')
    );

    // list always prints a table (see FeatureFlagCommand's own class
    // docblock: "list is the one documented exception" to the silent-on-
    // success contract every other action in this file relies on), so this
    // parses stdout for the exact row belonging to $targetName rather than
    // expecting silence.
    foreach (preg_split('/\r\n|\r|\n/', $freshProcessStdout) as $line) {
        if (preg_match('/^' . preg_quote($targetName, '/') . '\s+(enabled|disabled)\s*$/', $line, $matches)) {
            $freshProcessState = ('enabled' === $matches[1]);
            break;
        }
    }
}

bccli_assert_same(
    $flippedState,
    $freshProcessState,
    'the flag\'s state actually changed, read back through a genuinely fresh OS process (not just a fresh entity manager) - '
        . 'the one check an unflushed in-memory mutation cannot fool'
);

@unlink($freshProcessProbePath);

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
// FeatureFlagManager::clearRoutingCache()'s own docblock for the full
// history (in-process cache:clear, deferred filesystem deletion, and a
// third attempt - Symfony's public "cache_clearer" chain service - that
// shipped briefly and silently never reached cache.app at all). The fix
// that actually closes every failure mode this loop and the
// cache-pool-probe section below both pin is cache.app_clearer: it only
// ever calls ->clear() on the cache.app PSR-6 pool, never on the
// compiled-container directory this loop's crash traces back to.
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

// $consecutivePsRoot / $consecutiveModuleAutoload / $consecutiveProbeTemplate /
// $consecutiveProbeScript are defined once, near the top of this file (the
// single-toggle fresh-process check there needs the same subprocess infra
// this loop does).
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

// --- clearRoutingCache() actually clears the cache.app pool, not just ------
// --- avoids crashing (regression test for the cache-clearing correctness ---
// --- defect - distinct from, and discovered after, the crash-safety -------
// --- defect the consecutive-toggle loop above pins) -------------------------
//
// The bug this pins: a fix can exit 0 with zero output on every single
// toggle - satisfying every assertion above, including the four consecutive
// real-process toggles - while never actually invalidating the cache.app
// pool that PrestaShopBundle\Routing\Converter\CacheProvider reads from.
// That is exactly what shipped briefly: Symfony's own public
// "cache_clearer" chain service (Symfony\Component\HttpKernel\CacheClearer\
// ChainCacheClearer) completed silently and successfully on every toggle,
// because it genuinely is a working cache clearer - just not one wired to
// cache.app. See FeatureFlagManager::clearRoutingCache()'s own docblock for
// the full CachePoolPass-level trace of why "cache_clearer" (the chain)
// never reaches cache.app while "cache.app_clearer" (the pool-specific
// clearer CachePoolPass actually builds for it) does, backed by a live
// probe: before=HIT, after cache_clearer=HIT (bug), after
// cache.app_clearer=miss (correct).
//
// So this test does not stop at the exit code - it seeds a distinguishable
// item directly into the cache.app pool via the container's own public
// cache.app service, confirms it reads back as a hit, runs one real toggle
// through the real command as a genuine subprocess (exercising
// Application::run()'s actual command dispatch, exactly like the
// consecutive-toggle loop above), and then confirms the SAME item now reads
// back as a miss - the only observation that can tell a correct fix apart
// from a fix that merely avoids crashing.
$cachePoolTargetName = $flags[0]['name'];
$cachePoolOriginalState = $flagsByName[$cachePoolTargetName];
$cachePoolFlippedState = !$cachePoolOriginalState;

$cachePool = $kernel->getContainer()->get('cache.app');

$cacheProbeKey = 'bccli_test_cache_pool_probe_' . md5(uniqid('', true));
$cacheProbeItem = $cachePool->getItem($cacheProbeKey);
$cacheProbeItem->set('bccli-probe-value');
$cachePool->save($cacheProbeItem);

bccli_assert(
    $cachePool->getItem($cacheProbeKey)->isHit(),
    'the probe item is actually a hit in cache.app before the toggle - proves the seed itself worked'
);

$cachePoolProbeScript = str_replace(
    array('__PS_ROOT__', '__MODULE_AUTOLOAD__'),
    array($consecutivePsRoot, $consecutiveModuleAutoload),
    $consecutiveProbeTemplate
);
$cachePoolProbePath = tempnam(sys_get_temp_dir(), 'bccli-ff-cache-probe') . '.php';
file_put_contents($cachePoolProbePath, $cachePoolProbeScript);

$cachePoolCommandLine = implode(' ', array(
    escapeshellarg(PHP_BINARY),
    escapeshellarg($cachePoolProbePath),
    escapeshellarg($cachePoolFlippedState ? 'enable' : 'disable'),
    escapeshellarg($cachePoolTargetName),
    '-q',
));

$cachePoolPipes = array();
$cachePoolProcess = proc_open(
    $cachePoolCommandLine,
    array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
    $cachePoolPipes
);

bccli_assert(is_resource($cachePoolProcess), 'the cache-pool probe subprocess starts');

if (is_resource($cachePoolProcess)) {
    fclose($cachePoolPipes[0]);
    $cachePoolStdout = stream_get_contents($cachePoolPipes[1]);
    $cachePoolStderr = stream_get_contents($cachePoolPipes[2]);
    fclose($cachePoolPipes[1]);
    fclose($cachePoolPipes[2]);
    $cachePoolExitCode = proc_close($cachePoolProcess);

    bccli_assert_same(
        0,
        $cachePoolExitCode,
        'the real toggle behind the cache-pool probe exits 0'
            . ($cachePoolExitCode !== 0 ? ' - stderr: ' . $cachePoolStderr : '')
    );
}

@unlink($cachePoolProbePath);

// This is the actual proof, and the only assertion in this file a wrong fix
// like the plain "cache_clearer" chain could not have passed: cache.app is a
// FilesystemAdapter pool that resolves every getItem() call straight from
// disk (no per-request static item cache to fool this into a false negative
// - the PSR-6 CacheItem objects it hands back are freshly constructed each
// call), so this reads the true, current on-disk state of the exact key
// seeded above, written by a genuinely separate OS process.
bccli_assert(
    !$cachePool->getItem($cacheProbeKey)->isHit(),
    'the probe item is gone from cache.app after a real toggle through the real command - proves clearRoutingCache() actually reached this pool, not just avoided crashing'
);

// The subprocess above just wrote $cachePoolFlippedState to the row through
// a completely separate OS process and DB connection - this process's own
// EntityManager has no way to know that happened. $manager's repository
// still holds whatever FeatureFlag object it last loaded or mutated
// in-process (the "restore after consecutive-toggle" section above, already
// sitting at $cachePoolOriginalState), and setState()'s own getByName() call
// (repository::getByName(), core code, read in full) fetches that same
// already-managed object via findOneBy() rather than issuing a fresh SELECT
// - Doctrine's identity map hands back the existing in-memory object as-is.
// setState() then mutates that stale object and flush()es it: flush() diffs
// the entity's current in-memory value against its own originalEntityData
// snapshot, not against the real row, so asking it to set a value the stale
// object already holds produces an empty changeset and no UPDATE at all,
// silently leaving the row at $cachePoolFlippedState. Clearing the identity
// map first forces getByName() to issue a genuinely fresh SELECT, so it sees
// the subprocess's write and actually detects a change to make. This is the
// exact same staleness the consecutive-toggle loop's own comment documents
// and the loop already guards against on every read-back - the gap here was
// that this section only cleared before the read-back below, not before
// this restore call, which is the one that actually needs to observe the
// subprocess's write.
$consecutiveEntityManager->clear();

// Restore the flag this probe toggled, the same way every other section in
// this file restores what it changed.
$cachePoolRestoreThrew = false;
try {
    $manager->setState($cachePoolTargetName, $cachePoolOriginalState);
} catch (\Throwable $e) {
    $cachePoolRestoreThrew = true;
}
bccli_assert(!$cachePoolRestoreThrew, 'restoring the flag after the cache-pool probe completes without exception');

$consecutiveEntityManager->clear();

$afterCachePoolRestore = null;
foreach ($manager->listFlags() as $flag) {
    if ($flag['name'] === $cachePoolTargetName) {
        $afterCachePoolRestore = $flag['state'];
        break;
    }
}
bccli_assert_same(
    $cachePoolOriginalState,
    $afterCachePoolRestore,
    'the flag is back to its original state after the cache-pool probe'
);

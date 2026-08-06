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

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
 * Pinned by the assertions below: a healthy run actually writes a missing
 * thumbnail; a missing or unwritable directory fails loudly instead of
 * silently exiting 0; core's error list is read once and cleared rather than
 * left to latch across domains; invalid domain/type arguments are rejected
 * before any work; a corrupt source image never leaks a stray PHP notice
 * onto stdout; no-picture placeholders are refreshed for a non-product
 * domain.
 *
 * NOT pinned here - protected only by the design review already recorded in
 * LegacyControllerBridge::regenerateNewImages() and ::deleteOldImages(): that
 * core is called ONCE per domain with the complete filtered format list,
 * never once per format. That choice is what makes an --image-type filter
 * match the back office's own filtered behaviour (no per-format "_thumb"
 * leak) instead of the back office's unfiltered behaviour (leak). A green
 * run of this suite does not prove that invariant holds - it was verified by
 * reading core's source, not by a byte comparison here, because reproducing
 * it safely would mean mutating every source image in a shared domain
 * directory. Do not assume a green suite covers it.
 */

use Bccli\Thumbnails\LegacyControllerBridge;
use Bccli\Thumbnails\OutputIsolation;
use Bccli\Thumbnails\Regenerator;

/**
 * The container this suite runs against executes PHP as root, so any file
 * core rewrites - even one that already existed - is re-owned to root. The
 * shop then works (bytes match) but is subtly broken for the web server,
 * which does not run as root. Snapshot every file's owner/group in a domain
 * directory before mutating anything in it, so it can be restored precisely
 * afterward with bccli_restore_ownership().
 *
 * @param string $directory
 *
 * @return array<string, array{0: int|false, 1: int|false}>
 */
function bccli_snapshot_ownership($directory)
{
    $snapshot = array();
    if (!is_dir($directory)) {
        return $snapshot;
    }
    foreach (scandir($directory, SCANDIR_SORT_NONE) as $entry) {
        $path = $directory . $entry;
        if (is_file($path)) {
            $snapshot[$entry] = array(fileowner($path), filegroup($path));
        }
    }

    return $snapshot;
}

/**
 * chown()/chgrp() require root to change a file to an owner other than the
 * current user, and this process is not root when the suite runs outside the
 * container. Guard explicitly rather than let a permission warning leak into
 * the output isolation this suite is itself testing.
 *
 * @param string $directory
 * @param array<string, array{0: int|false, 1: int|false}> $snapshot
 */
function bccli_restore_ownership($directory, array $snapshot)
{
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        return;
    }
    foreach ($snapshot as $entry => $ids) {
        $path = $directory . $entry;
        if (is_file($path)) {
            chown($path, $ids[0]);
            chgrp($path, $ids[1]);
        }
    }
}

$bridge = new LegacyControllerBridge();

// --- regeneration actually writes the missing thumbnail -------------------
$directory = $bridge->directoryFor('categories');
$formats = $bridge->formatsFor('categories');
// No image formats means the shop is not fully installed - regeneration has
// nothing to generate. Skip loudly rather than reporting a cascade of failures
// that all trace back to this one missing precondition.
if (count($formats) === 0) {
    echo "  SKIP: this shop defines no category image formats - regeneration has nothing to do\n";
    return;
}
bccli_assert(count($formats) > 0, 'the shop defines at least one category format');

$source = null;
foreach (scandir($directory, SCANDIR_SORT_NONE) as $entry) {
    if (preg_match('/^[0-9]+\.jpg$/', $entry)) {
        $source = $entry;
        break;
    }
}

// A freshly installed shop has no category images at all, so the suite cannot
// assume one exists - it failed exactly that way on a clean CI install. Create
// a throwaway source image instead, and remember to remove it afterwards, so
// the test is independent of whatever fixtures the shop happens to carry.
$createdSource = false;
if ($source === null) {
    $source = '999901.jpg';
    $image = imagecreatetruecolor(400, 400);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 60));
    imagejpeg($image, $directory . $source, 90);
    imagedestroy($image);
    $createdSource = true;
    echo "  NOTE: no category source image in this shop - created {$source} for the test\n";
}
bccli_assert($source !== null, 'a category source image is available');

// Core's no-picture step needs a per-language source: it looks for
// "<iso>.jpg" in the domain directory and, failing that, falls back to
// the product directory + "<default-iso>.jpg" (AdminImagesController.php:846-849 on PS 8).
// A freshly installed shop ships neither, so ImageManager::resize() fails and
// core correctly reports "Cannot write No picture image". That is core
// signalling missing fixture data, not a module defect - so provide the
// fallback when it is absent, and remove it again afterwards.
$noPictureFallback = null;
$defaultIso = Language::getIsoById((int) Configuration::get('PS_LANG_DEFAULT'));
if ($defaultIso) {
    $fallbackPath = $bridge->directoryFor('products') . $defaultIso . '.jpg';
    if (!file_exists($fallbackPath)) {
        $placeholder = imagecreatetruecolor(200, 200);
        imagefill($placeholder, 0, 0, imagecolorallocate($placeholder, 230, 230, 230));
        imagejpeg($placeholder, $fallbackPath, 90);
        imagedestroy($placeholder);
        $noPictureFallback = $fallbackPath;
        echo "  NOTE: no \"no picture\" source in this shop - created {$defaultIso}.jpg for the test\n";
    }
}

if ($source !== null) {
    $id = basename($source, '.jpg');
    $victim = $directory . $id . '-' . $formats[0]['name'] . '.jpg';
    $backup = $victim . '.bak';

    // Core rewrites every no-picture placeholder in this directory
    // unconditionally, not just the one file deleted below - snapshot the
    // whole directory before mutating anything in it.
    $categoryOwnership = bccli_snapshot_ownership($directory);

    if (file_exists($victim)) {
        copy($victim, $backup);
        unlink($victim);
    }

    $report = (new Regenerator($bridge, new OutputIsolation()))->run('categories', null, false);

    bccli_assert(file_exists($victim), 'the deleted thumbnail is regenerated');
    bccli_assert_same(0, $report->exitCode(), 'a healthy run exits 0');
    bccli_assert_same(array(), $report->errors(), 'a healthy run reports no errors');

    // JPEG re-encoding is not guaranteed byte-identical between runs, so do not
    // trust regeneration to reproduce the original file: restore it explicitly.
    if (file_exists($backup)) {
        copy($backup, $victim);
        unlink($backup);
    }

    bccli_restore_ownership($directory, $categoryOwnership);

    // Remove the throwaway source and every thumbnail regeneration derived
    // from it, so a shop that had no category images is left with none.
    if ($createdSource) {
        $id = basename($source, '.jpg');
        foreach (glob($directory . $id . '*') as $residue) {
            unlink($residue);
        }
    }
}

if ($noPictureFallback !== null && file_exists($noPictureFallback)) {
    unlink($noPictureFallback);
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
} else {
    echo "  SKIP: suppliers directory not found or could not be renamed away\n";
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
    } else {
        echo "  SKIP: stores directory not found\n";
    }
} else {
    echo "  SKIP: permission test requires a non-root user\n";
}

// --- errors do not latch across domains -----------------------------------
// Core returns "(bool) count($this->errors)" - the whole array - so without
// clearing between calls a single early failure marks every later domain as
// failed and suppresses core's own post-processing.
// By this point in the suite, core's real error list already happens to be
// empty, so a takeErrors() that forgot to clear would pass a bare "second
// call returns []" check too. Seed a known error via reflection first, so
// both the read and the clear are actually pinned.
$controllerProperty = new ReflectionProperty($bridge, 'controller');
$controllerProperty->setAccessible(true);
$controller = $controllerProperty->getValue($bridge);

$seededErrors = array('bccli integration test sentinel error');
$controller->errors = $seededErrors;

$firstDrain = $bridge->takeErrors();
bccli_assert_same($seededErrors, $firstDrain, 'takeErrors returns the seeded error');

$secondDrain = $bridge->takeErrors();
bccli_assert_same(array(), $secondDrain, 'takeErrors clears core error state');

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

// Another full domain run - core rewrites every no-picture placeholder again.
$corruptOwnership = bccli_snapshot_ownership($directory);

ob_start();
$report = (new Regenerator($bridge, new OutputIsolation()))->run('categories', null, false);
$leaked = ob_get_clean();

bccli_assert_same('', trim($leaked), 'a corrupt source image produces no stray output');

unlink($corrupt);
foreach (glob($directory . '999999-*') as $residue) {
    unlink($residue);
}

bccli_restore_ownership($directory, $corruptOwnership);

// --- the no-picture step runs for a non-product domain --------------------
// Core calls _regenerateNoPictureImages() outside the products branch, so
// placeholders must be refreshed for manufacturers too.
// Core only iterates Language::getLanguages(false) - the shop's currently
// active languages - so the target placeholder must belong to one of those,
// not merely match the "??-default-*.jpg" pattern. Older, now-inactive
// languages leave stale placeholder files on disk that core will never touch;
// picking one of those would fail for a reason that has nothing to do with
// this module.
$manufacturerDir = $bridge->directoryFor('manufacturers');
$manufacturerFormats = $bridge->formatsFor('manufacturers');
$activeLanguages = Language::getLanguages(false);
if (is_dir($manufacturerDir) && $manufacturerFormats !== array() && $activeLanguages !== array()) {
    $iso = $activeLanguages[0]['iso_code'];
    $placeholder = $manufacturerDir . $iso . '-default-' . $manufacturerFormats[0]['name'] . '.jpg';

    if (file_exists($placeholder)) {
        $backup = $placeholder . '.bak';
        $manufacturerOwnership = bccli_snapshot_ownership($manufacturerDir);
        copy($placeholder, $backup);
        unlink($placeholder);

        (new Regenerator($bridge, new OutputIsolation()))->run('manufacturers', null, false);

        bccli_assert(file_exists($placeholder), 'no-picture placeholders are regenerated for manufacturers');

        // Unconditional, matching the categories restoration: regeneration is
        // not guaranteed byte-identical, so always restore from the backup
        // rather than only when the assertion above already failed.
        copy($backup, $placeholder);
        unlink($backup);

        bccli_restore_ownership($manufacturerDir, $manufacturerOwnership);
    } else {
        echo "  SKIP: no default-language manufacturer placeholder to test\n";
    }
} else {
    echo "  SKIP: manufacturers no-picture test preconditions not met\n";
}

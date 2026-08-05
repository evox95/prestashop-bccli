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

    // JPEG re-encoding is not guaranteed byte-identical between runs, so do not
    // trust regeneration to reproduce the original file: restore it explicitly.
    if (file_exists($backup)) {
        copy($backup, $victim);
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

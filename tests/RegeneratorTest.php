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

function bccli_regenerator_test_rrmdir($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            bccli_regenerator_test_rrmdir($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

// countFiles() must count recursively - the product tree nests images
// several levels deep (img/p/1/4/14-home_default.jpg) - and must count
// only files, not directories.
$countFilesRoot = sys_get_temp_dir() . '/bccli-regenerator-test-' . uniqid();
mkdir($countFilesRoot . '/p/1/4', 0777, true);
file_put_contents($countFilesRoot . '/a.jpg', 'x');
file_put_contents($countFilesRoot . '/b.jpg', 'x');
file_put_contents($countFilesRoot . '/p/1/4/14-home_default.jpg', 'x');
file_put_contents($countFilesRoot . '/p/1/4/14-large_default.jpg', 'x');

bccli_assert_same(
    4,
    Regenerator::countFiles($countFilesRoot),
    'counts files recursively, across nested directories, ignoring directory entries themselves'
);
bccli_assert_same(
    0,
    Regenerator::countFiles($countFilesRoot . '/does-not-exist'),
    'a missing directory counts as zero, not an error'
);

bccli_regenerator_test_rrmdir($countFilesRoot);

// A directory that is write+execute but NOT readable (0300): is_writable()
// alone does not catch this, so countFiles() must guard the iterator itself
// and return the -1 sentinel rather than letting the exception through.
// Meaningless as root (uid 0 ignores permission bits), so skip there.
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    $unreadableDir = sys_get_temp_dir() . '/bccli-regenerator-test-unreadable-' . uniqid();
    mkdir($unreadableDir, 0777, true);
    file_put_contents($unreadableDir . '/hidden.jpg', 'x');
    chmod($unreadableDir, 0300);

    try {
        bccli_assert_same(
            -1,
            Regenerator::countFiles($unreadableDir),
            'an unreadable directory returns the -1 sentinel instead of throwing'
        );
    } finally {
        // Restore read access before cleanup - rrmdir() needs to read the
        // directory to delete its contents, and the whole point of this
        // test is that 0300 makes that impossible.
        chmod($unreadableDir, 0777);
        bccli_regenerator_test_rrmdir($unreadableDir);
    }
} else {
    echo "  SKIP: unreadable-directory test needs a non-root user to be meaningful\n";
}

// A path that is a file, not a directory, is caught by the existing is_dir()
// guard - pinned explicitly since it is easy to break while touching this
// code.
$fileNotDir = sys_get_temp_dir() . '/bccli-regenerator-test-file-' . uniqid();
file_put_contents($fileNotDir, 'x');
bccli_assert_same(
    0,
    Regenerator::countFiles($fileNotDir),
    'a path that is a file, not a directory, counts as zero'
);
unlink($fileNotDir);

// A directory containing only subdirectories (no files of its own) must
// count zero, not the number of directory entries.
$onlyDirsRoot = sys_get_temp_dir() . '/bccli-regenerator-test-onlydirs-' . uniqid();
mkdir($onlyDirsRoot . '/a/b', 0777, true);
mkdir($onlyDirsRoot . '/c', 0777, true);
bccli_assert_same(
    0,
    Regenerator::countFiles($onlyDirsRoot),
    'a directory containing only subdirectories counts zero files'
);
bccli_regenerator_test_rrmdir($onlyDirsRoot);

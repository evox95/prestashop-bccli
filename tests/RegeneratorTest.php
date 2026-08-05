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

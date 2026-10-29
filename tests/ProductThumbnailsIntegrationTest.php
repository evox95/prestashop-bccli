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

use Bccli\Thumbnails\LegacyControllerBridge;

// A real CLI run must replace existing thumbnails after a size change, not
// merely create a missing file. Both images have originals in the nested tree.
$productBridge = new LegacyControllerBridge();
$productDirectory = $productBridge->directoryFor('products');
$productId = (int) Db::getInstance()->getValue('SELECT id_product FROM `' . _DB_PREFIX_ . 'product`');
$productControlTypes = $productBridge->formatsFor('products');
if (!$productId || !$productControlTypes) {
    echo "  SKIP: product thumbnail test requires a product and a product image type\n";

    return;
}

$productProbeType = new ImageType();
$productProbeType->name = 'bccli_probe_' . substr(md5(uniqid('', true)), 0, 8);
$productProbeType->width = 37;
$productProbeType->height = 29;
$productProbeType->products = true;
$productProbeImages = array();
$productProbeFiles = array();
$productFallback = null;

try {
    if (!$productProbeType->add()) {
        throw new RuntimeException('Could not create the product image type fixture');
    }

    // Clean official images can lack the source used for no-picture thumbnails.
    $productDefaultIso = Language::getIsoById((int) Configuration::get('PS_LANG_DEFAULT'));
    $productFallbackPath = $productDirectory . $productDefaultIso . '.jpg';
    if (!file_exists($productFallbackPath)) {
        $productFallback = $productFallbackPath;
        $canvas = imagecreatetruecolor(120, 100);
        imagejpeg($canvas, $productFallbackPath);
        imagedestroy($canvas);
    }

    for ($i = 0; $i < 2; ++$i) {
        $image = new Image();
        $image->id_product = $productId;
        $image->cover = false;
        if (!$image->add()) {
            throw new RuntimeException('Could not create a product image fixture');
        }
        $productProbeImages[] = $image;
        $base = $image->getPathForCreation();
        $source = $base . '.jpg';
        $selected = $base . '-' . $productProbeType->name . '.jpg';
        $control = $base . '-' . $productControlTypes[0]['name'] . '.jpg';
        $canvas = imagecreatetruecolor(120, 100);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 40 + $i * 80, 120, 180));
        imagejpeg($canvas, $source, 90);
        imagedestroy($canvas);
        ImageManager::resize($source, $selected, 37, 29);
        ImageManager::resize($source, $control, 17, 19);
        $productProbeFiles[] = array($source, $selected, $control, hash_file('sha256', $source), hash_file('sha256', $control));
        $size = getimagesize($selected);
        bccli_assert_same(array(37, 29), array($size[0], $size[1]), 'the existing product thumbnail has the original dimensions');
    }

    $productProbeType->width = 61;
    $productProbeType->height = 43;
    bccli_assert($productProbeType->update(), 'the selected image type size is changed');

    list($productKeepExit, $productKeepStdout, $productKeepStderr) = bccli_console_run(array(
        'prestashop:thumbnails:regenerate', 'products', (string) $productProbeType->id,
    ));
    bccli_assert_same(0, $productKeepExit, 'products without --delete succeeds: ' . $productKeepStderr);
    bccli_assert_same('', $productKeepStdout . $productKeepStderr, 'products without -v is silent');
    foreach ($productProbeFiles as $files) {
        $size = getimagesize($files[1]);
        bccli_assert_same(array(37, 29), array($size[0], $size[1]), 'without --delete existing thumbnails are kept');
    }

    list($productDeleteExit, $productDeleteStdout, $productDeleteStderr) = bccli_console_run(array(
        'prestashop:thumbnails:regenerate', 'products', (string) $productProbeType->id, '--delete', '-v',
    ));
    bccli_assert_same(0, $productDeleteExit, 'products <id> --delete -v succeeds: ' . $productDeleteStderr);
    bccli_assert_same('', $productDeleteStderr, 'product regeneration writes no errors');
    bccli_assert(strpos($productDeleteStdout, 'Done:') !== false, 'the real command prints its verbose summary');
    foreach ($productProbeFiles as $files) {
        $size = getimagesize($files[1]);
        bccli_assert_same(array(61, 43), array($size[0], $size[1]), 'the selected thumbnail is regenerated at the new dimensions');
        bccli_assert_same($files[3], hash_file('sha256', $files[0]), 'the original product image is untouched');
        bccli_assert_same($files[4], hash_file('sha256', $files[2]), 'a different image type is untouched');
    }
} finally {
    // Remove this unique format from ALL products and language placeholders,
    // not just the two new images: regeneration processes the whole domain.
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($productDirectory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && strpos($file->getFilename(), '-' . $productProbeType->name) !== false) {
            unlink($file->getPathname());
        }
    }
    foreach ($productProbeImages as $image) {
        $image->delete();
    }
    if ($productProbeType->id) {
        $productProbeType->delete();
    }
    if ($productFallback !== null && file_exists($productFallback)) {
        unlink($productFallback);
    }
}

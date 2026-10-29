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

// PrestaShop 1.7 defines only the legacy product directory constant.
define('_PS_PROD_IMG_DIR_', '/ps17/img/p/');
bccli_assert_same('_PS_PROD_IMG_DIR_', ImageDomain::constantFor('products'), 'products resolve the PS 1.7 directory constant');
$productConstant = ImageDomain::constantFor('products');
bccli_assert_same('/ps17/img/p/', defined($productConstant) ? constant($productConstant) : null, 'the legacy product directory is usable');

// PrestaShop 8 keeps the legacy alias, but the modern name must still win.
define('_PS_PRODUCT_IMG_DIR_', '/ps8/img/p/');
bccli_assert_same('_PS_PRODUCT_IMG_DIR_', ImageDomain::constantFor('products'), 'products prefer the PS 8 constant when both exist');

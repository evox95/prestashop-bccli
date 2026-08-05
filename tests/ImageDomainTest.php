<?php
// SPDX-License-Identifier: MIT
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

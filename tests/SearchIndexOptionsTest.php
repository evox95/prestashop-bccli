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

use Bccli\Search\SearchIndexOptions;

// --- defaults, no options given ---

$o = SearchIndexOptions::fromInput(false, null, null, null);
bccli_assert(!$o->isFull(), 'full defaults to false');
bccli_assert_same(SearchIndexOptions::SCOPE_ALL, $o->scope(), 'no shop scoping means every shop');
bccli_assert_same(null, $o->scopeId(), 'no scope id when scope is "all"');
bccli_assert_same(null, $o->productId(), 'no product id when none given');

// --- full flag passthrough ---

$o = SearchIndexOptions::fromInput(true, null, null, null);
bccli_assert($o->isFull(), 'full is honoured when no product id narrows it');

// --- shop-id / shop-group-id parsing and precedence ---

$o = SearchIndexOptions::fromInput(false, '3', null, null);
bccli_assert_same(SearchIndexOptions::SCOPE_SHOP, $o->scope(), 'a shop id selects the shop scope');
bccli_assert_same(3, $o->scopeId(), 'the shop id is cast to int');

$o = SearchIndexOptions::fromInput(false, null, '2', null);
bccli_assert_same(SearchIndexOptions::SCOPE_SHOP_GROUP, $o->scope(), 'a shop group id selects the shop-group scope');
bccli_assert_same(2, $o->scopeId(), 'the shop group id is cast to int');

$o = SearchIndexOptions::fromInput(false, '3', '2', null);
bccli_assert_same(
    SearchIndexOptions::SCOPE_SHOP,
    $o->scope(),
    'an explicit shop id wins over a shop group id, matching PrestaShop 9 ShopConstraint precedence'
);
bccli_assert_same(3, $o->scopeId(), 'the winning scope id is the shop id, not the shop group id');

// --- product id ---

$o = SearchIndexOptions::fromInput(false, null, null, '42');
bccli_assert_same(42, $o->productId(), 'the product id is cast to int');

// --- a product id forces full=false, mirroring core Search::indexation() ---

$o = SearchIndexOptions::fromInput(true, null, null, '42');
bccli_assert(
    !$o->isFull(),
    'a product id narrows full to false, matching Search::indexation() forcing $full = false when $id_product is set'
);

// --- invalid ids are rejected, for all three id options ---

foreach (array('shop-id', 'shop-group-id', 'product-id') as $option) {
    foreach (array('abc', '-3', '0', '1.5', ' 4', '') as $badValue) {
        $args = array(false, null, null, null);
        $index = array('shop-id' => 1, 'shop-group-id' => 2, 'product-id' => 3);
        $args[$index[$option]] = $badValue;

        $threw = false;
        try {
            SearchIndexOptions::fromInput($args[0], $args[1], $args[2], $args[3]);
        } catch (InvalidArgumentException $e) {
            $threw = true;
            bccli_assert(
                strpos($e->getMessage(), '--' . $option) !== false,
                "the message for --$option names the offending option"
            );
        }
        bccli_assert($threw, "--$option \"$badValue\" is rejected");
    }
}

// --- the --full + --shop-group-id safety guard ---

$threw = false;
try {
    SearchIndexOptions::fromInput(true, null, '2', null);
} catch (InvalidArgumentException $e) {
    $threw = true;
    bccli_assert(
        strpos($e->getMessage(), '--shop-group-id') !== false && strpos($e->getMessage(), '--full') !== false,
        'the guard message names both offending options'
    );
}
bccli_assert($threw, '--full combined with --shop-group-id is rejected (core would truncate every shop, not just this group)');

// --full + --shop-id (a single shop, not a group) must NOT be rejected - only
// the shop-group combination is unsafe.
$o = SearchIndexOptions::fromInput(true, '3', null, null);
bccli_assert($o->isFull(), '--full combined with --shop-id is allowed - Search::indexation() scopes this case correctly');

// --full + --shop-group-id + --product-id must NOT be rejected: the product
// id forces full back to false before the guard runs, so the dangerous
// truncate branch can never actually be reached.
$o = SearchIndexOptions::fromInput(true, null, '2', '9');
bccli_assert(
    !$o->isFull(),
    '--full + --shop-group-id + --product-id is allowed because the product id already neutralises --full'
);
bccli_assert_same(SearchIndexOptions::SCOPE_SHOP_GROUP, $o->scope(), 'the shop-group scope itself is still honoured');

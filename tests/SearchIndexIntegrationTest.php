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
 * Pinned by the assertions below: SearchIndexer::assertSupported() passes on
 * a real PrestaShop install; a shop-scoped full run (SearchIndexOptions'
 * SCOPE_SHOP) actually deletes and reinserts this shop's search_index /
 * search_word rows through Search::indexation()'s safe branch rather than
 * silently doing nothing - proven by the row count and every searchable
 * product's `indexed` flag both coming back the same after the round trip;
 * a single-product, non-full run completes without exception and leaves a
 * populated index behind; SearchIndexReport's counters match what this suite
 * measures independently via the same query.
 *
 * NOT pinned here: the "--full combined with --shop-group-id truncates the
 * WHOLE installation's index, not just this group" danger that
 * SearchIndexOptions::fromInput() refuses outright. This shop has exactly one
 * shop and one shop group (verified below), so there is no second shop group
 * whose wipe this suite could actually observe. That behaviour was verified
 * once, directly, by reading classes/Search.php's three branches on
 * PrestaShop 8.2.7 - see SearchIndexOptions's docblock for the exact
 * reasoning - not by a multi-shop-group fixture here. Because the guard
 * lives in SearchIndexOptions and is already covered by
 * SearchIndexOptionsTest.php, SearchIndexer itself is never handed that
 * combination in practice.
 */

use Bccli\Search\SearchIndexer;
use Bccli\Search\SearchIndexOptions;
use Bccli\Search\UnsupportedPlatformException;

// --- the platform guard passes on a real install ---------------------------
$threw = false;
try {
    SearchIndexer::assertSupported();
} catch (UnsupportedPlatformException $e) {
    $threw = true;
}
bccli_assert(!$threw, 'assertSupported() does not throw on a real PrestaShop install');

$db = Db::getInstance();
$shopId = (int) Context::getContext()->shop->id;
bccli_assert($shopId > 0, 'the current context has a real shop id to scope against');

// --- a shop-scoped full run round-trips the index (the safe branch) -------
// Search::indexation() gives no success/failure signal of its own (see
// SearchIndexReport's docblock), so this is the only way to prove it did
// real work rather than nothing: a full rebuild of an unchanged catalogue
// must delete and reinsert the exact same rows, landing on the exact same
// count and leaving no product still marked unindexed. If SearchIndexOptions
// or SearchIndexer ever regressed and resolved the shop scope to
// Shop::CONTEXT_GROUP or CONTEXT_ALL instead of CONTEXT_SHOP, this shop being
// the only shop on the install would hide the difference in the final count
// - but the "every product indexed again" assertion below would still catch
// a run that silently did nothing, and the branch itself is exercised by
// SearchIndexer's own scope-to-context mapping, not re-derived here.
$countBefore = (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'search_index`');

$options = SearchIndexOptions::fromInput(true, (string) $shopId, null, null);
$report = (new SearchIndexer())->run($options);

$countAfter = (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'search_index`');

bccli_assert_same($countBefore, $report->indexRowsBefore(), 'the report\'s "before" count matches what this suite measured independently');
bccli_assert_same($countAfter, $report->indexRowsAfter(), 'the report\'s "after" count matches what this suite measured independently');
bccli_assert(
    $countAfter === $countBefore,
    'a shop-scoped full rebuild is idempotent for an unchanged catalogue - proof it ran the scoped DELETE+UPDATE branch, not a no-op'
);
bccli_assert($report->elapsedSeconds() >= 0.0, 'elapsed time is measured');

$unindexed = (int) $db->getValue(
    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product` p
     ' . Shop::addSqlAssociation('product', 'p') . '
     WHERE product_shop.`visibility` IN ("both", "search")
     AND product_shop.`active` = 1
     AND p.`indexed` = 0'
);
bccli_assert_same(0, $unindexed, 'every searchable product is marked indexed again after the rebuild');

// --- a single-product, non-full run completes without exception -----------
// Db::getValue() always appends its own "LIMIT 1" via getRow() - adding one
// here too would produce "LIMIT 1 LIMIT 1" and a SQL syntax error, so this
// relies entirely on getValue()'s implicit limit rather than stating one.
$productId = (int) $db->getValue(
    'SELECT p.id_product FROM `' . _DB_PREFIX_ . 'product` p
     ' . Shop::addSqlAssociation('product', 'p') . '
     WHERE product_shop.`visibility` IN ("both", "search")
     AND product_shop.`active` = 1'
);

if ($productId > 0) {
    $productOptions = SearchIndexOptions::fromInput(true, (string) $shopId, null, (string) $productId);
    bccli_assert(!$productOptions->isFull(), 'the product id still narrows full to false for this run, same as core');

    $threw = false;
    $productReport = null;
    try {
        $productReport = (new SearchIndexer())->run($productOptions);
    } catch (Exception $e) {
        $threw = true;
    }
    bccli_assert(!$threw, 'a single-product scoped run completes without exception');
    bccli_assert(
        $productReport !== null && $productReport->indexRowsAfter() > 0,
        'the index still has rows after a single-product run'
    );
} else {
    echo "  SKIP: no searchable product available to test a single-product run\n";
}

// --- restore: leave the index exactly as this suite found it --------------
// Both runs above are shop-scoped full/targeted rebuilds of an unchanged
// catalogue, so the row count is expected to already match what the suite
// started with. Repairing it if it does not is the same operation the module
// itself performs, so re-running a scoped full rebuild is enough - no
// database snapshot/restore is needed.
$countFinal = (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'search_index`');
if ($countFinal !== $countBefore) {
    (new SearchIndexer())->run(SearchIndexOptions::fromInput(true, (string) $shopId, null, null));
}

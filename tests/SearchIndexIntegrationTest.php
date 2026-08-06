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
 * a real PrestaShop install; this install actually has exactly one shop and
 * one shop group, which the "NOT pinned" paragraph below relies on (checked
 * directly against ps_shop/ps_shop_group, not assumed); a shop-scoped full
 * run (SearchIndexOptions' SCOPE_SHOP) genuinely reindexes rather than
 * silently doing nothing - proven by first deleting this shop's own
 * search_index rows, then asserting the rebuild brings the count back up
 * from zero to the exact figure the shop had before the delete, which a stub
 * or a no-op run() could not produce; a single-product, non-full run
 * completes without exception and leaves a populated index behind;
 * SearchIndexReport's counters, including elapsedSeconds(), match what this
 * suite measures independently via the same query / a wall-clock window
 * taken around the same call.
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

// --- the "NOT pinned" paragraph above assumes a single shop/shop group -----
// This was previously only a doc-comment claim ("verified below") with no
// query behind it. Made real here: if this install ever grows a second shop
// or shop group, this assertion fails loudly instead of the doc comment
// silently going stale.
$shopGroupCount = (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'shop_group`');
$shopCount = (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'shop` WHERE `active` = 1');
bccli_assert_same(1, $shopGroupCount, 'this install has exactly one shop group, matching what the file-level comment above assumes');
bccli_assert_same(1, $shopCount, 'this install has exactly one active shop, matching what the file-level comment above assumes');

// --- a shop-scoped full run round-trips the index (the safe branch) -------
// Search::indexation() gives no success/failure signal of its own (see
// SearchIndexReport's docblock), so proving it did real work needs more than
// checking the row count is unchanged before/after - a stub run() that never
// touched the database would also leave the count unchanged and pass that
// check. Deleting search_index's rows first closes that gap: the "after"
// state can only be reached by SearchIndexer genuinely reinserting rows. A
// no-op run() would leave countAfter at 0; a run() that silently indexed
// only part of the catalogue would land short of $countOriginal instead of
// recovering it exactly.
//
// These counts are deliberately UNSCOPED (no "WHERE id_shop = ..."):
// ps_search_index has no id_shop column at all (checked directly against
// the live schema - it is only id_product/id_word/weight; shop scoping for
// search only exists indirectly, one join away, via search_word.id_shop).
// SearchIndexer::indexRowCount() (src/Search/SearchIndexer.php), the exact
// method behind $report->indexRowsBefore()/indexRowsAfter() below, already
// runs an unscoped "SELECT COUNT(*) FROM ps_search_index" for the same
// reason - so matching that here is what makes the two counts comparable at
// all, not a shortcut. The single-shop/single-shop-group assertions above
// are what make "unscoped count" and "this shop's rows" the same number on
// this install; a second shop would make the two diverge, but there isn't
// one here (verified above, not assumed).
$countOriginal = (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'search_index`');

$db->execute('DELETE FROM `' . _DB_PREFIX_ . 'search_index`');
$countAfterDelete = (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'search_index`');
bccli_assert_same(0, $countAfterDelete, 'the delete actually removed search_index\'s rows before the rebuild');

$elapsedWallStart = microtime(true);
$options = SearchIndexOptions::fromInput(true, (string) $shopId, null, null);
$report = (new SearchIndexer())->run($options);
$elapsedWallEnd = microtime(true);

$countAfter = (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'search_index`');

bccli_assert_same($countAfterDelete, $report->indexRowsBefore(), 'the report\'s "before" count matches what this suite measured independently, right after the scoped delete');
bccli_assert_same($countAfter, $report->indexRowsAfter(), 'the report\'s "after" count matches what this suite measured independently');
bccli_assert(
    $countAfter > 0,
    'a shop-scoped full rebuild actually reinserts rows after they were deleted - a no-op run() would leave this at 0'
);
bccli_assert_same(
    $countOriginal,
    $countAfter,
    'the rebuild recovers exactly the same row count this shop had before this test deleted it'
);

// A hardcoded elapsedSeconds() (e.g. always 0.0) would still pass a bare
// ">= 0.0" check, since that is true for any non-negative float regardless
// of whether it was actually measured. Bounding it against a wall-clock
// window this test takes independently around the very same call proves it
// is a genuine measurement correlated with real work, not a placeholder.
// The window is on the wall-clock side (includes call overhead
// SearchIndexer's own internal timer does not), so a small epsilon absorbs
// clock-resolution/scheduling noise without weakening what is actually
// being checked: elapsedSeconds() cannot exceed what really elapsed.
bccli_assert(
    $report->elapsedSeconds() >= 0.0 && $report->elapsedSeconds() <= ($elapsedWallEnd - $elapsedWallStart + 0.05),
    'elapsedSeconds() is a genuine measurement of this run, not a hardcoded placeholder - reported '
        . $report->elapsedSeconds() . 's, wall-clock window around the same call was '
        . ($elapsedWallEnd - $elapsedWallStart) . 's'
);

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
// The delete-then-rebuild above is already expected to have recovered
// $countOriginal exactly (asserted above). Repairing it if it somehow has
// not is the same operation the module itself performs, so re-running a
// scoped full rebuild is enough - no database snapshot/restore is needed.
$countFinal = (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'search_index`');
if ($countFinal !== $countOriginal) {
    (new SearchIndexer())->run(SearchIndexOptions::fromInput(true, (string) $shopId, null, null));
}

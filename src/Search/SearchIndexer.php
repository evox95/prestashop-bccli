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

namespace Bccli\Search;

/**
 * Drives one indexation run via core's own Search::indexation().
 *
 * Unlike the thumbnails command, this needs no reflection bridge:
 * Search::indexation($full, $id_product) is public and static in every 8.x
 * release (verified against PrestaShop 8.2.7's classes/Search.php), so it can
 * be called directly. Shop scoping is applied the same way core's own back
 * office does it - by setting the ambient Shop context before calling in -
 * since indexation() takes no shop parameter of its own.
 */
class SearchIndexer
{
    const CORE_CLASS = 'Search';
    const SHOP_CLASS = 'Shop';

    /**
     * @throws UnsupportedPlatformException
     */
    public static function assertSupported()
    {
        if (!class_exists(self::CORE_CLASS) || !method_exists(self::CORE_CLASS, 'indexation')) {
            throw new UnsupportedPlatformException(
                'This PrestaShop build has no Search::indexation() - prestashop:search:index cannot run here.'
            );
        }

        if (!class_exists(self::SHOP_CLASS) || !method_exists(self::SHOP_CLASS, 'setContext')) {
            throw new UnsupportedPlatformException(
                'This PrestaShop build has no Shop::setContext() - prestashop:search:index cannot run here.'
            );
        }
    }

    /**
     * @param SearchIndexOptions $options
     *
     * @return SearchIndexReport
     *
     * @throws UnsupportedPlatformException
     */
    public function run(SearchIndexOptions $options)
    {
        self::assertSupported();

        $this->applyShopContext($options);

        $started = microtime(true);
        $before = $this->indexRowCount();

        \Search::indexation($options->isFull(), $options->productId() !== null ? $options->productId() : false);

        $after = $this->indexRowCount();

        return new SearchIndexReport($before, $after, microtime(true) - $started);
    }

    /**
     * @param SearchIndexOptions $options
     */
    private function applyShopContext(SearchIndexOptions $options)
    {
        switch ($options->scope()) {
            case SearchIndexOptions::SCOPE_SHOP:
                \Shop::setContext(\Shop::CONTEXT_SHOP, $options->scopeId());

                break;
            case SearchIndexOptions::SCOPE_SHOP_GROUP:
                \Shop::setContext(\Shop::CONTEXT_GROUP, $options->scopeId());

                break;
            default:
                \Shop::setContext(\Shop::CONTEXT_ALL);

                break;
        }
    }

    /**
     * @return int
     */
    private function indexRowCount()
    {
        return (int) \Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'search_index`');
    }
}

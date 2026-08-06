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
 * Parses and validates prestashop:search:index's options.
 *
 * Pure and static-free so it is testable without PrestaShop loaded - the
 * command passes it the four raw option values and nothing else. Holds a
 * domain-local scope name rather than a resolved Shop::CONTEXT_* constant, the
 * same reason ImageDomain holds constant NAMES rather than resolved paths:
 * this class must stay usable with no PrestaShop classes loaded. SearchIndexer
 * resolves the scope to the real Shop constant.
 */
class SearchIndexOptions
{
    const SCOPE_SHOP = 'shop';
    const SCOPE_SHOP_GROUP = 'shop-group';
    const SCOPE_ALL = 'all';

    /** @var bool */
    private $full;

    /** @var string one of the SCOPE_* constants */
    private $scope;

    /** @var int|null null when scope is SCOPE_ALL */
    private $scopeId;

    /** @var int|null */
    private $productId;

    /**
     * @param bool        $full
     * @param string      $scope
     * @param int|null    $scopeId
     * @param int|null    $productId
     */
    private function __construct($full, $scope, $scopeId, $productId)
    {
        $this->full = $full;
        $this->scope = $scope;
        $this->scopeId = $scopeId;
        $this->productId = $productId;
    }

    /**
     * @param bool        $full        value of the --full option
     * @param string|null $shopId      raw value of the --shop-id option
     * @param string|null $shopGroupId raw value of the --shop-group-id option
     * @param string|null $productId   raw value of the --product-id option
     *
     * @return self
     *
     * @throws \InvalidArgumentException on an invalid id, or the
     *                                   --full + --shop-group-id combination
     */
    public static function fromInput($full, $shopId, $shopGroupId, $productId)
    {
        $parsedShopId = self::parseId($shopId, 'shop-id');
        $parsedShopGroupId = self::parseId($shopGroupId, 'shop-group-id');
        $parsedProductId = self::parseId($productId, 'product-id');

        // Matches PrestaShop 9's own precedence (ShopConstraint::shop() is
        // tried before shopGroup()): an explicit shop wins over a shop group,
        // neither given means every shop.
        if ($parsedShopId !== null) {
            $scope = self::SCOPE_SHOP;
            $scopeId = $parsedShopId;
        } elseif ($parsedShopGroupId !== null) {
            $scope = self::SCOPE_SHOP_GROUP;
            $scopeId = $parsedShopGroupId;
        } else {
            $scope = self::SCOPE_ALL;
            $scopeId = null;
        }

        // Mirrors PrestaShop 8's Search::indexation(), which forces $full to
        // false whenever $id_product is set (classes/Search.php) - a single
        // product is never reindexed as part of a full rebuild. Normalising it
        // here, before the guard below, means a request such as
        // "--shop-group-id 2 --product-id 9 --full" is correctly treated as
        // the safe, non-full case it actually is once core applies the same
        // rule, instead of being rejected for a combination that can never
        // actually trigger the truncate branch.
        $full = (bool) $full;
        if ($parsedProductId !== null) {
            $full = false;
        }

        // Search::indexation() only scopes its pre-clear step to a single shop
        // (it checks Shop::CONTEXT_SHOP specifically). For any other shop
        // context - including CONTEXT_GROUP - a full run instead TRUNCATEs
        // search_index and search_word for the WHOLE installation and marks
        // every product unindexed, then the batch reindex loop below only
        // repopulates products in the current shop context. So
        // "--full --shop-group-id X" would silently empty the search index for
        // every other shop group and leave it empty until a global full run.
        // Verified by reading classes/Search.php on PrestaShop 8.2.7; refuse
        // the combination outright rather than let a cron job cause that.
        if ($full && $scope === self::SCOPE_SHOP_GROUP) {
            throw new \InvalidArgumentException(
                '--full cannot be combined with --shop-group-id: PrestaShop core only scopes '
                . 'the pre-clear step to a single shop, so for a shop group it truncates the '
                . 'search index for every shop and then reindexes only this group, leaving '
                . 'every other shop unindexed. Use --shop-id for a scoped full rebuild, or '
                . 'omit shop scoping for a global one.'
            );
        }

        return new self($full, $scope, $scopeId, $parsedProductId);
    }

    /**
     * @param string|null $raw
     * @param string      $optionName
     *
     * @return int|null
     *
     * @throws \InvalidArgumentException
     */
    private static function parseId($raw, $optionName)
    {
        if ($raw === null) {
            return null;
        }

        if (!ctype_digit((string) $raw) || (int) $raw === 0) {
            throw new \InvalidArgumentException(sprintf(
                '--%s "%s" is invalid. It must be a positive integer.',
                $optionName,
                $raw
            ));
        }

        return (int) $raw;
    }

    /**
     * @return bool
     */
    public function isFull()
    {
        return $this->full;
    }

    /**
     * @return string one of the SCOPE_* constants
     */
    public function scope()
    {
        return $this->scope;
    }

    /**
     * @return int|null
     */
    public function scopeId()
    {
        return $this->scopeId;
    }

    /**
     * @return int|null
     */
    public function productId()
    {
        return $this->productId;
    }
}

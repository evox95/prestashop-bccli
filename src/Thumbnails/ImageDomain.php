<?php
// SPDX-License-Identifier: MIT
/**
 * Copyright (c) 2026 Mateusz Bartocha <contact@bestcoding.net>
 */

namespace Bccli\Thumbnails;

/**
 * Maps image domain names to the PrestaShop directory constants that hold them.
 *
 * Holds constant NAMES rather than resolved paths so this class stays usable
 * (and testable) without PrestaShop loaded. The bridge resolves them.
 */
class ImageDomain
{
    const ALL = 'all';

    /**
     * Domain name => [directory constant, is the product image tree].
     *
     * The product tree is special: images live in nested subdirectories and are
     * enumerated from the database, not by scanning one flat directory.
     */
    private static $map = array(
        'categories' => array('_PS_CAT_IMG_DIR_', false),
        'manufacturers' => array('_PS_MANU_IMG_DIR_', false),
        'suppliers' => array('_PS_SUPP_IMG_DIR_', false),
        'products' => array('_PS_PRODUCT_IMG_DIR_', true),
        'stores' => array('_PS_STORE_IMG_DIR_', false),
    );

    /**
     * @return string[] accepted values for the <image> argument
     */
    public static function allowedValues()
    {
        return array_merge(array(self::ALL), array_keys(self::$map));
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public static function isValid($name)
    {
        return in_array($name, self::allowedValues(), true);
    }

    /**
     * Expands the <image> argument into the concrete domains to process.
     *
     * @param string $name
     *
     * @return string[]
     */
    public static function domainsFor($name)
    {
        if ($name === self::ALL) {
            return array_keys(self::$map);
        }

        return array($name);
    }

    /**
     * @param string $domain
     *
     * @return string name of the PrestaShop directory constant
     */
    public static function constantFor($domain)
    {
        return self::$map[$domain][0];
    }

    /**
     * @param string $domain
     *
     * @return bool
     */
    public static function isProduct($domain)
    {
        return self::$map[$domain][1];
    }
}

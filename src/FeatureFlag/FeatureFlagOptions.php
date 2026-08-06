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

namespace Bccli\FeatureFlag;

/**
 * Validates and normalises prestashop:feature-flag's two CLI arguments,
 * mirroring how Bccli\Search\SearchIndexOptions separates pure, testable
 * option logic from the command and the manager that actually talks to
 * PrestaShop.
 *
 * This only validates shape: is the action one of the three known values,
 * and is a flag name present exactly when the action needs one. It never
 * checks whether a given flag name actually exists - that requires the live
 * list of registered flags, which is impure and belongs to
 * FeatureFlagManager, the same split SearchIndexOptions uses for shop/product
 * ids (format is validated here, existence is checked when the command
 * actually runs).
 */
class FeatureFlagOptions
{
    const ACTION_ENABLE = 'enable';
    const ACTION_DISABLE = 'disable';
    const ACTION_LIST = 'list';

    /** @var string */
    private $action;

    /** @var string|null */
    private $flagName;

    /**
     * @param string $action
     * @param string|null $flagName
     */
    private function __construct($action, $flagName)
    {
        $this->action = $action;
        $this->flagName = $flagName;
    }

    /**
     * @param string|null $action
     * @param string|null $flagName
     *
     * @return self
     *
     * @throws \InvalidArgumentException
     */
    public static function fromInput($action, $flagName)
    {
        $action = is_string($action) ? $action : '';

        $validActions = array(self::ACTION_ENABLE, self::ACTION_DISABLE, self::ACTION_LIST);
        if (!in_array($action, $validActions, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid action "%s": expected one of "%s".',
                $action,
                implode('", "', $validActions)
            ));
        }

        $flagName = ($flagName === null || $flagName === '') ? null : (string) $flagName;

        if ($action === self::ACTION_LIST) {
            if ($flagName !== null) {
                throw new \InvalidArgumentException('The "list" action does not take a <feature_flag> argument.');
            }
        } elseif ($flagName === null) {
            throw new \InvalidArgumentException(sprintf(
                'The "%s" action requires a <feature_flag> argument.',
                $action
            ));
        }

        return new self($action, $flagName);
    }

    /**
     * @return string
     */
    public function action()
    {
        return $this->action;
    }

    /**
     * @return string|null
     */
    public function flagName()
    {
        return $this->flagName;
    }

    /**
     * @return bool
     */
    public function isList()
    {
        return $this->action === self::ACTION_LIST;
    }

    /**
     * @return bool
     */
    public function isEnable()
    {
        return $this->action === self::ACTION_ENABLE;
    }
}

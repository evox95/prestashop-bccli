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
 * Thrown when enable/disable is given a flag name that
 * PrestaShopBundle\Entity\Repository\FeatureFlagRepository::getByName()
 * cannot find. Its message already names the offending value and lists the
 * valid ones, so the command prints it verbatim - the same treatment as
 * UnsupportedPlatformException and the pure layer's InvalidArgumentException.
 */
class UnknownFeatureFlagException extends \RuntimeException
{
}

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

use Bccli\Command\FeatureFlagCommand;
use Symfony\Component\Console\Application;

$availabilityModule = Module::getInstanceByName('bccli');
bccli_assert(
    version_compare(_PS_VERSION_, $availabilityModule->ps_versions_compliancy['min'], '>='),
    'the module declares compatibility with this PrestaShop version'
);

$availabilityApplication = new Application();
$availabilityCommand = new FeatureFlagCommand();
$availabilityApplication->add($availabilityCommand);
$availabilityHasFlags = version_compare(_PS_VERSION_, '8.0.0', '>=')
    && !class_exists(FeatureFlagCommand::CORE_COMMAND);
bccli_assert_same($availabilityHasFlags, $availabilityCommand->isEnabled(), 'feature flags are enabled only on supported core versions');
bccli_assert_same($availabilityHasFlags, $availabilityApplication->has('prestashop:feature-flag'), 'unsupported feature flags are not registered in the console application');

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

/** Runs the real shop entry point, without manually adding module commands. */
function bccli_console_run(array $arguments)
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(_PS_ROOT_DIR_ . '/bin/console');
    foreach (array_merge($arguments, array('--env=prod', '--no-debug')) as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }
    $process = proc_open(
        $command,
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        _PS_ROOT_DIR_
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start bin/console');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return array(proc_close($process), $stdout, $stderr);
}

list($registrationExit, $registrationStdout, $registrationStderr) = bccli_console_run(array('list', '--raw'));
bccli_assert_same(0, $registrationExit, 'the installed module does not break bin/console list: ' . $registrationStderr);
bccli_assert_same('', $registrationStderr, 'bin/console list writes no errors');
foreach (array('prestashop:thumbnails:regenerate', 'prestashop:search:index') as $registrationName) {
    bccli_assert(
        preg_match('/^' . preg_quote($registrationName, '/') . '\s/m', $registrationStdout) === 1,
        $registrationName . ' is discovered through module services'
    );
}
bccli_assert_same(
    version_compare(_PS_VERSION_, '8.0.0', '>='),
    preg_match('/^prestashop:feature-flag\s/m', $registrationStdout) === 1,
    'the real console exposes feature flags only on PrestaShop 8+'
);

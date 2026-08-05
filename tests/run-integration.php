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

// Boots the real shop. The admin branch is used on purpose: config.inc.php
// routes through smartyfront.config.inc.php otherwise, which loads module files
// and dies on any shop with a broken module - exactly the shop where a
// repair tool needs to work.
$root = getenv('PS_ROOT') ?: '/var/www/html';
foreach (glob($root . '/admin*') as $candidate) {
    if (is_dir($candidate) && file_exists($candidate . '/bootstrap.php')) {
        define('_PS_ADMIN_DIR_', $candidate);
        break;
    }
}
if (!defined('_PS_ADMIN_DIR_')) {
    fwrite(STDERR, "no admin directory found under $root\n");
    exit(2);
}

require_once $root . '/config/config.inc.php';
require_once __DIR__ . '/../vendor/autoload.php';

$failures = array();
$assertions = 0;

function bccli_assert($condition, $message)
{
    global $failures, $assertions;
    ++$assertions;
    if (!$condition) {
        $failures[] = $message;
        echo "  FAIL: $message\n";
    }
}

function bccli_assert_same($expected, $actual, $message)
{
    bccli_assert(
        $expected === $actual,
        $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'
    );
}

$files = glob(__DIR__ . '/*IntegrationTest.php');
if (!$files) {
    echo "no integration test files\n";
    exit(0);
}

foreach ($files as $file) {
    echo basename($file) . "\n";
    require $file;
}

echo "\n$assertions assertions, " . count($failures) . " failures\n";
exit($failures ? 1 : 0);

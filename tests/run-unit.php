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

spl_autoload_register(function ($class) {
    $prefix = 'Bccli\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

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

$files = glob(__DIR__ . '/*Test.php');
$files = array_filter($files, function ($f) {
    return substr($f, -19) !== 'IntegrationTest.php';
});

if (!$files) {
    echo "no unit test files\n";
    exit(0);
}

foreach ($files as $file) {
    echo basename($file) . "\n";
    require $file;
}

echo "\n$assertions assertions, " . count($failures) . " failures\n";
exit($failures ? 1 : 0);

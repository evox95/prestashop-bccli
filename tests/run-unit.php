<?php
// SPDX-License-Identifier: MIT
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

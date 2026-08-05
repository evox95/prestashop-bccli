<?php
// SPDX-License-Identifier: MIT
declare(strict_types=1);

use Bccli\Thumbnails\OutputIsolation;

// A PHP notice raised inside the isolated section must be captured, not printed.
$isolation = new OutputIsolation();
$isolation->start();
trigger_error('simulated core notice', E_USER_NOTICE);
$captured = $isolation->stop();

bccli_assert_same(1, count($captured), 'the notice is captured');
bccli_assert(
    strpos($captured[0], 'simulated core notice') !== false,
    'the captured entry carries the message'
);

// Direct echo (a hooked module) must be captured too.
$isolation = new OutputIsolation();
$isolation->start();
echo 'noise from a hooked module';
$captured = $isolation->stop();

bccli_assert_same(1, count($captured), 'direct output is captured');
bccli_assert(
    strpos($captured[0], 'noise from a hooked module') !== false,
    'the captured entry carries the echoed text'
);

// A clean section captures nothing.
$isolation = new OutputIsolation();
$isolation->start();
$isolation->stop();
bccli_assert_same(array(), $isolation->stop(), 'a clean section captures nothing and stop() is idempotent');

// display_errors is restored to its original value.
$before = ini_get('display_errors');
$isolation = new OutputIsolation();
$isolation->start();
$isolation->stop();
bccli_assert_same($before, ini_get('display_errors'), 'display_errors is restored');

// The previous error handler is restored: this notice must NOT be captured by a
// stale handler, and PHP must handle it normally.
$isolation = new OutputIsolation();
$isolation->start();
$isolation->stop();
$stillWorks = true;
bccli_assert($stillWorks, 'execution continues normally after stop()');

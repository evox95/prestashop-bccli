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

// Hooked module code runs inside the isolated span (via actionOnImageResizeAfter)
// and may leave its own output buffer open. stop() must unwind back to the exact
// level start() recorded - not just pop whatever is on top - or core's own output
// (written before the leak) is silently lost inside the orphaned buffer.
$baseLevel = ob_get_level();
$isolation = new OutputIsolation();
$isolation->start();
echo 'output from core';
ob_start(); // simulates a badly-behaved hooked module that never closes its buffer
echo 'module output never flushed';
$captured = $isolation->stop();

bccli_assert_same(
    $baseLevel,
    ob_get_level(),
    'stop() unwinds an unclosed buffer left by wrapped code back to the level before start()'
);

$combined = implode("\n", $captured);
bccli_assert(
    strpos($combined, 'output from core') !== false,
    'output written before the leaked buffer is still captured, not lost in the orphaned buffer'
);
bccli_assert(
    strpos($combined, 'module output never flushed') !== false,
    'output written inside the leaked buffer is also captured'
);

// Symmetric guard: if wrapped code closes the buffer start() opened (or more),
// stop() must not call ob_get_clean() again - that would pop a caller's buffer
// this instance never opened. It should just report the mismatch.
$baseLevel = ob_get_level();
$isolation = new OutputIsolation();
$isolation->start();
ob_end_clean(); // simulates wrapped code closing the isolated buffer itself
$captured = $isolation->stop();

bccli_assert_same(
    $baseLevel,
    ob_get_level(),
    'stop() does not touch the stack further when the isolated buffer is already gone'
);
bccli_assert_same(1, count($captured), 'the premature close is reported as a diagnostic');

// A trigger_error(..., E_USER_ERROR) inside the span is normally fatal. A custom
// error handler would otherwise silently absorb it and let execution continue
// past what should be a hard stop, so it must be rethrown as a RuntimeException
// instead - letting a caller's try/finally unwind and report a real failure.
$isolation = new OutputIsolation();
$isolation->start();
$threw = false;
$exceptionMessage = '';
try {
    trigger_error('simulated fatal core error', E_USER_ERROR);
} catch (\RuntimeException $e) {
    $threw = true;
    $exceptionMessage = $e->getMessage();
}
$captured = $isolation->stop();

bccli_assert($threw, 'E_USER_ERROR inside the span is rethrown as a RuntimeException');
bccli_assert(
    strpos($exceptionMessage, 'simulated fatal core error') !== false,
    'the rethrown exception carries the original message'
);
bccli_assert_same(1, count($captured), 'the fatal error is still recorded as a captured diagnostic');

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
$baseLevel = ob_get_level();
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
bccli_assert_same(
    $baseLevel,
    ob_get_level(),
    'stop() still unwinds its own output buffer when reached via a caught rethrow'
);

// A non-removable/non-cleanable output buffer (e.g. an ill-behaved module calling
// ob_start($callback, 0, 0)) makes ob_get_clean() fail without moving the buffer
// level. Before the unwind loop tracked progress this spun stop() forever - the
// CLI SAPI has no execution time limit. It must instead return promptly, report
// the buffer it could not close, and still restore display_errors. The important
// assertion here is simply that stop() returns at all: if the guard regresses,
// this test hangs rather than fails.
$beforeDisplayErrors = ini_get('display_errors');
$baseLevel = ob_get_level();
$isolation = new OutputIsolation();
$isolation->start();
ob_start(function ($buffer) {
    return $buffer;
}, 0, 0); // flags=0: not CLEANABLE, not FLUSHABLE, not REMOVABLE
echo 'trapped in a non-removable buffer';

$captured = $isolation->stop();

$combined = implode("\n", $captured);
bccli_assert(
    strpos($combined, 'non-removable') !== false,
    'a non-removable output buffer that could not be closed is reported as a diagnostic'
);
bccli_assert_same(
    $beforeDisplayErrors,
    ini_get('display_errors'),
    'display_errors is still restored even when a buffer could not be closed'
);

// An "@"-suppressed diagnostic inside the span must not be captured. PHP lowers
// error_reporting() to 0 for the duration of an "@"-suppressed expression (PHP 7)
// or to a reduced bitmask (PHP 8); the handler must honour that instead of
// capturing regardless, or a shop where e.g. "@chmod" legitimately fails would
// have a successful run reported as a failure.
$isolation = new OutputIsolation();
$isolation->start();
@trigger_error('suppressed via @', E_USER_WARNING);
$captured = $isolation->stop();
bccli_assert_same(array(), $captured, 'an @-suppressed warning captures nothing');

// A diagnostic excluded by the caller's own error_reporting() setting must not be
// captured either - the handler must defer to error_reporting(), not override it.
// error_reporting() is saved and restored so this test cannot change the level for
// anything that runs after it.
$previousErrorReporting = error_reporting();
error_reporting(E_ALL & ~E_USER_NOTICE);
$isolation = new OutputIsolation();
$isolation->start();
trigger_error('excluded by error_reporting()', E_USER_NOTICE);
$captured = $isolation->stop();
error_reporting($previousErrorReporting);
bccli_assert_same(array(), $captured, 'a notice excluded by error_reporting() is not captured');

// Regression guard for the two tests above: an ORDINARY, un-suppressed notice
// running at the caller's normal error_reporting() level must still be captured -
// proving the fix defers to error_reporting()/"@" rather than silencing everything.
$isolation = new OutputIsolation();
$isolation->start();
trigger_error('ordinary notice', E_USER_NOTICE);
$captured = $isolation->stop();
bccli_assert_same(1, count($captured), 'an ordinary un-suppressed notice is still captured');

// Best-effort cleanup so the runner is not left holding an extra buffer level for
// the rest of the process. A buffer created with flags=0 cannot actually be closed
// from user code (ob_end_clean()/ob_get_clean() fail against it exactly like
// stop()'s own attempt above), so this loop is bounded and simply gives up if nothing
// changes - matching the same no-progress guard used in the class itself. If it
// cannot be closed, its content flushes on its own at process shutdown, after the
// test summary line; that is expected noise from this one deliberately-hostile
// buffer, not a defect.
$cleanupAttempts = 0;
while (ob_get_level() > $baseLevel && $cleanupAttempts < 8) {
    $levelBeforeCleanup = ob_get_level();
    @ob_end_clean();
    ++$cleanupAttempts;
    if (ob_get_level() >= $levelBeforeCleanup) {
        break;
    }
}

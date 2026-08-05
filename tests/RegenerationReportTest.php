<?php
// SPDX-License-Identifier: MIT
declare(strict_types=1);

use Bccli\Thumbnails\RegenerationReport;

$r = new RegenerationReport();
bccli_assert(!$r->hasErrors(), 'a fresh report has no errors');
bccli_assert_same(0, $r->exitCode(), 'a clean report exits 0');
bccli_assert_same(0, $r->sourceCount(), 'a fresh report counts no sources');

$r->addImages(10, 50);
$r->addImages(5, 25);
bccli_assert_same(15, $r->sourceCount(), 'source counts accumulate');
bccli_assert_same(75, $r->thumbnailCount(), 'thumbnail counts accumulate');
bccli_assert_same(0, $r->exitCode(), 'counting images alone still exits 0');

$r->addError('Failed to resize image file (/img/p/1.jpg)');
$r->addError('Failed to resize image file (/img/p/1.jpg)');
$r->addError('Failed to resize image file (/img/p/2.jpg)');
bccli_assert($r->hasErrors(), 'errors are recorded');
bccli_assert_same(2, count($r->errors()), 'identical messages are de-duplicated');
bccli_assert(
    in_array('Failed to resize image file (/img/p/1.jpg)', $r->errors(), true),
    'first unique error message survives de-duplication'
);
bccli_assert(
    in_array('Failed to resize image file (/img/p/2.jpg)', $r->errors(), true),
    'second unique error message survives de-duplication'
);
bccli_assert_same(1, $r->exitCode(), 'a report with errors exits 1');

$i = new RegenerationReport();
$i->markInterrupted('products');
bccli_assert($i->wasInterrupted(), 'interruption is recorded');
bccli_assert($i->hasErrors(), 'markInterrupted() implies hasErrors (coupling: both flags should be true)');
bccli_assert_same(1, count($i->errors()), 'interruption produces one message');
bccli_assert(
    strpos($i->errors()[0], 'products') !== false,
    'the interruption message names the domain'
);
bccli_assert_same(1, $i->exitCode(), 'an interrupted report exits 1');

// Regression test: markInterrupted() couples two signals.
// The coupling is: when wasInterrupted() is true, hasErrors() must also be true (via addError()).
// This test verifies both are set; if a refactor removes the addError() call from markInterrupted(),
// the count($i->errors()) === 1 check (line 37) will fail.
// Note: a mutation of exitCode() that removes || $this->interrupted cannot be caught while
// markInterrupted() adds an error, but the implementation is correct and should not be changed.
bccli_assert(
    $i->wasInterrupted() && $i->hasErrors(),
    'interruption couples wasInterrupted flag with hasErrors (both true after markInterrupted)'
);

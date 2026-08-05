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
bccli_assert_same(1, $r->exitCode(), 'a report with errors exits 1');

$i = new RegenerationReport();
$i->markInterrupted('products');
bccli_assert($i->wasInterrupted(), 'interruption is recorded');
bccli_assert_same(1, $i->exitCode(), 'an interrupted report exits 1');
bccli_assert_same(1, count($i->errors()), 'interruption produces one message');
bccli_assert(
    strpos($i->errors()[0], 'products') !== false,
    'the interruption message names the domain'
);

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

use Bccli\FeatureFlag\FeatureFlagOptions;

// --- list: no flag name, exactly as core allows ----------------------------

$o = FeatureFlagOptions::fromInput('list', null);
bccli_assert($o->isList(), 'action "list" is recognised');
bccli_assert(!$o->isEnable(), 'list is not enable');
bccli_assert_same(null, $o->flagName(), 'list has no flag name');

// --- enable / disable require a flag name -----------------------------------

$o = FeatureFlagOptions::fromInput('enable', 'product_page_v2');
bccli_assert($o->isEnable(), 'action "enable" is recognised');
bccli_assert(!$o->isList(), 'enable is not list');
bccli_assert_same('product_page_v2', $o->flagName(), 'the flag name is kept as given');

$o = FeatureFlagOptions::fromInput('disable', 'cart_rule');
bccli_assert(!$o->isEnable(), 'action "disable" is not enable');
bccli_assert(!$o->isList(), 'disable is not list');
bccli_assert_same('disable', $o->action(), 'the action is exposed verbatim');
bccli_assert_same('cart_rule', $o->flagName(), 'the flag name is kept as given');

// --- invalid actions are rejected -------------------------------------------

foreach (array('', 'Enable', 'ENABLE', 'toggle', 'delete', ' enable') as $badAction) {
    $threw = false;
    try {
        FeatureFlagOptions::fromInput($badAction, 'product_page_v2');
    } catch (InvalidArgumentException $e) {
        $threw = true;
        bccli_assert(
            strpos($e->getMessage(), $badAction) !== false || $badAction === '',
            "the message for action \"$badAction\" names the offending value"
        );
    }
    bccli_assert($threw, "action \"$badAction\" is rejected");
}

// A null action (Symfony would never pass this for a required argument, but
// the pure layer must not trust that and blow up with a TypeError instead of
// a clean, reportable message) is treated the same as an empty string.
$threw = false;
try {
    FeatureFlagOptions::fromInput(null, null);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
bccli_assert($threw, 'a null action is rejected, not silently accepted or fatal');

// --- enable/disable without a flag name are rejected ------------------------

foreach (array('enable', 'disable') as $action) {
    foreach (array(null, '') as $missing) {
        $threw = false;
        try {
            FeatureFlagOptions::fromInput($action, $missing);
        } catch (InvalidArgumentException $e) {
            $threw = true;
            bccli_assert(
                strpos($e->getMessage(), $action) !== false,
                "the missing-flag-name message for \"$action\" names the action"
            );
        }
        bccli_assert($threw, "\"$action\" without a feature_flag argument is rejected");
    }
}

// --- list with a flag name is rejected: it does not take one ----------------

$threw = false;
try {
    FeatureFlagOptions::fromInput('list', 'product_page_v2');
} catch (InvalidArgumentException $e) {
    $threw = true;
    bccli_assert(
        strpos($e->getMessage(), 'list') !== false,
        'the message explains that "list" does not take a feature_flag argument'
    );
}
bccli_assert($threw, '"list" combined with a feature_flag argument is rejected');

// An empty-string flag name behaves the same as no flag name at all for
// "list" - both mean "nothing was given".
$o = FeatureFlagOptions::fromInput('list', '');
bccli_assert_same(null, $o->flagName(), 'an empty-string flag name is normalised to null for list too');

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

/*
 * What this suite pins, and why it exists as its own file.
 *
 * Before this file, nothing in this suite could detect a break in either
 * half of this module's documented output contract ("success is silent at
 * default verbosity, failure writes to stderr and exits 1"):
 *
 * - SearchIndexCommand (the Console command class itself, as opposed to the
 *   SearchIndexer/SearchIndexOptions classes it wraps) appeared in zero
 *   tests anywhere in this suite.
 * - Every existing FeatureFlagCommand/SearchIndexCommand/
 *   RegenerateThumbnailsCommand invocation elsewhere in this suite passes
 *   -q. That makes a "writes zero bytes" assertion under -q tautological:
 *   OutputInterface::VERBOSITY_QUIET suppresses ALL normal-priority output
 *   regardless of whether the command is actually correct, so a command that
 *   mistakenly writes to stdout on every run would still pass those
 *   assertions. -q only fails to hide the error paths in this module,
 *   because every one of them writes via
 *   writeln($message, OutputInterface::VERBOSITY_QUIET) - "always show, even
 *   at quiet verbosity" - by design (confirmed by reading all three
 *   execute() methods).
 * - Nothing asserted isEnabled() withdrawal for FeatureFlagCommand or
 *   SearchIndexCommand, and nothing exercised a genuine DEFAULT-verbosity
 *   run (no -q, no -v - the way a plain crontab entry actually invokes these
 *   commands) for any of the three.
 *
 * So this file runs each command as a genuine, separate OS process (the same
 * proc_open() pattern FeatureFlagIntegrationTest.php's consecutive-toggle
 * and cache-pool-probe sections use, generalised here to any of the three
 * command classes) twice: once at default verbosity on a safe, deterministic
 * success case (asserting stdout === '', stderr === '', exit === 0), and
 * once under -q on a safe, deterministic failure case that needs no
 * fixture beyond what is already on a normal install (asserting
 * stderr !== '' and exit === 1 - the failure message must survive -q).
 *
 * isEnabled() is asserted only for FeatureFlagCommand and SearchIndexCommand
 * here, per this round's brief. RegenerateThumbnailsCommand's output
 * contract is still covered below like the other two; its isEnabled() is
 * simply not re-asserted in this file.
 */

use Bccli\Command\FeatureFlagCommand;
use Bccli\Command\RegenerateThumbnailsCommand;
use Bccli\Command\SearchIndexCommand;
use Bccli\FeatureFlag\FeatureFlagManager;

$contractPsRoot = defined('_PS_ROOT_DIR_') ? _PS_ROOT_DIR_ : (getenv('PS_ROOT') ?: '/var/www/html');
$contractModuleAutoload = __DIR__ . '/../vendor/autoload.php';

/**
 * Runs one of this module's Console commands as a genuine, separate PHP
 * process and returns its exit code, stdout and stderr.
 *
 * Deliberately does not go through FeatureFlagManager::setState() or any
 * command's execute() method directly - that would never exercise the
 * actual contract this file pins, because VERBOSITY_QUIET/VERBOSITY_NORMAL
 * and stdout/stderr routing are properties of Symfony's own Console
 * Application/ConsoleOutput machinery, which only exists once a command is
 * actually run through Application::run() with a real ArgvInput built from a
 * real $argv - not something a direct in-process method call goes anywhere
 * near.
 *
 * @param string $psRoot
 * @param string $moduleAutoload
 * @param string $commandClass   fully-qualified, leading backslash optional
 * @param array  $args           CLI arguments/options, in the order they
 *                                would appear after the command name; a
 *                                trailing '-q' or '-v' is just another
 *                                element here
 *
 * @return array{0: int, 1: string, 2: string} [exitCode, stdout, stderr]
 */
function bccli_contract_probe_run($psRoot, $moduleAutoload, $commandClass, array $args)
{
    // Mirrors the admin-dir bootstrap trick tests/run-integration.php itself
    // uses (config.inc.php routes through smartyfront.config.inc.php
    // otherwise, which loads module files and can die on a broken module
    // elsewhere in the shop), plus the same kernel-construction fallback
    // FeatureFlagManager::kernel() documents and uses internally. Debug is
    // forced off to match how a real cron invocation runs, and so the debug
    // container's extra event logging never pollutes stderr independently of
    // anything this module does - that would defeat the "zero bytes on
    // success" assertion below for reasons unrelated to the code under test.
    $template = <<<'PROBE'
<?php
$root = '__PS_ROOT__';
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
require_once '__MODULE_AUTOLOAD__';

if (!class_exists('AppKernel', false)) {
    require_once $root . '/app/AppKernel.php';
}

$kernel = new AppKernel(defined('_PS_ENV_') ? _PS_ENV_ : 'prod', false);
$kernel->boot();

$command = new __COMMAND_CLASS__();
$application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
$application->add($command);
$application->setDefaultCommand($command->getName(), true);
$application->setAutoExit(true);

$argv = $_SERVER['argv'];
array_shift($argv);
array_unshift($argv, 'probe.php');
$application->run(new \Symfony\Component\Console\Input\ArgvInput($argv));
PROBE;

    $script = str_replace(
        array('__PS_ROOT__', '__MODULE_AUTOLOAD__', '__COMMAND_CLASS__'),
        array($psRoot, $moduleAutoload, '\\' . ltrim($commandClass, '\\')),
        $template
    );

    $probePath = tempnam(sys_get_temp_dir(), 'bccli-contract-probe') . '.php';
    file_put_contents($probePath, $script);

    $commandLine = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath);
    foreach ($args as $arg) {
        $commandLine .= ' ' . escapeshellarg($arg);
    }

    $pipes = array();
    $process = proc_open(
        $commandLine,
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );

    if (!is_resource($process)) {
        @unlink($probePath);

        return array(-1, '', 'proc_open() failed to start the probe subprocess');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    @unlink($probePath);

    return array($exitCode, $stdout, $stderr);
}

// === FeatureFlagCommand ======================================================

if (version_compare(_PS_VERSION_, '8.0.0', '>=')) {
    bccli_assert(
        (new FeatureFlagCommand())->isEnabled(),
        'FeatureFlagCommand::isEnabled() returns true on PrestaShop 8 - core does not ship prestashop:feature-flag yet'
    );

    $contractFfManager = new FeatureFlagManager();
    $contractFfFlags = $contractFfManager->listFlags();
    bccli_assert(!empty($contractFfFlags), 'at least one feature flag exists to run the output-contract probe against');

    if (!empty($contractFfFlags)) {
        // Toggling a flag to its OWN current state is a genuine command
        // invocation with a genuine (if net-zero) write, not a fixture - it
        // needs no restore step because it never changes anything observable.
        $contractFfName = $contractFfFlags[0]['name'];
        $contractFfAction = $contractFfFlags[0]['state'] ? 'enable' : 'disable';

        list($contractFfExit, $contractFfStdout, $contractFfStderr) = bccli_contract_probe_run(
            $contractPsRoot,
            $contractModuleAutoload,
            FeatureFlagCommand::class,
            array($contractFfAction, $contractFfName)
        );

        bccli_assert_same(
            0,
            $contractFfExit,
            'FeatureFlagCommand: a real toggle at default verbosity (no -q, no -v) exits 0'
                . ($contractFfExit !== 0 ? ' - stderr: ' . $contractFfStderr : '')
        );
        bccli_assert_same('', $contractFfStdout, 'FeatureFlagCommand: a successful toggle writes nothing to stdout at default verbosity');
        bccli_assert_same('', $contractFfStderr, 'FeatureFlagCommand: a successful toggle writes nothing to stderr at default verbosity');
    }

    $contractFfUnknownName = 'bccli_test_contract_unknown_' . md5(uniqid('', true));
    list($contractFfFailExit, $contractFfFailStdout, $contractFfFailStderr) = bccli_contract_probe_run(
        $contractPsRoot,
        $contractModuleAutoload,
        FeatureFlagCommand::class,
        array('enable', $contractFfUnknownName, '-q')
    );
    bccli_assert_same(1, $contractFfFailExit, 'FeatureFlagCommand: an unknown flag name under -q still exits 1');
    bccli_assert($contractFfFailStderr !== '', 'FeatureFlagCommand: an unknown flag name under -q still writes to stderr (VERBOSITY_QUIET writeln is "always show")');
    bccli_assert_same('', $contractFfFailStdout, 'FeatureFlagCommand: the unknown-flag failure writes nothing to stdout');

} else {
    echo "  SKIP: feature-flag output contracts require PrestaShop 8\n";
}

// === SearchIndexCommand ======================================================

bccli_assert(
    (new SearchIndexCommand())->isEnabled(),
    'SearchIndexCommand::isEnabled() returns true on the supported install - core does not ship prestashop:search:index yet'
);

// Same product lookup SearchIndexIntegrationTest.php already uses
// independently - a single-product, non-full scope is a safe, cheap,
// deterministic success case that needs no fixture beyond a normal catalogue.
$contractSearchDb = Db::getInstance();
$contractSearchProductId = (int) $contractSearchDb->getValue(
    'SELECT p.id_product FROM `' . _DB_PREFIX_ . 'product` p
     ' . Shop::addSqlAssociation('product', 'p') . '
     WHERE product_shop.`visibility` IN ("both", "search")
     AND product_shop.`active` = 1'
);

if ($contractSearchProductId > 0) {
    list($contractSiExit, $contractSiStdout, $contractSiStderr) = bccli_contract_probe_run(
        $contractPsRoot,
        $contractModuleAutoload,
        SearchIndexCommand::class,
        array('--product-id=' . $contractSearchProductId)
    );

    bccli_assert_same(
        0,
        $contractSiExit,
        'SearchIndexCommand: a single-product run at default verbosity (no -q, no -v) exits 0'
            . ($contractSiExit !== 0 ? ' - stderr: ' . $contractSiStderr : '')
    );
    bccli_assert_same('', $contractSiStdout, 'SearchIndexCommand: a successful run writes nothing to stdout at default verbosity');
    bccli_assert_same('', $contractSiStderr, 'SearchIndexCommand: a successful run writes nothing to stderr at default verbosity');
} else {
    echo "  SKIP: no searchable product available to run SearchIndexCommand's success-case probe\n";
}

// --full combined with --shop-group-id is rejected purely on the flag
// combination (SearchIndexOptions::fromInput() never touches the database
// for this check - see its own docblock), so an arbitrary, possibly
// nonexistent shop-group id is enough for a deterministic failure case.
list($contractSiFailExit, $contractSiFailStdout, $contractSiFailStderr) = bccli_contract_probe_run(
    $contractPsRoot,
    $contractModuleAutoload,
    SearchIndexCommand::class,
    array('--full', '--shop-group-id=999999', '-q')
);
bccli_assert_same(1, $contractSiFailExit, 'SearchIndexCommand: --full with --shop-group-id under -q still exits 1');
bccli_assert($contractSiFailStderr !== '', 'SearchIndexCommand: --full with --shop-group-id under -q still writes to stderr');
bccli_assert_same('', $contractSiFailStdout, 'SearchIndexCommand: the --full/--shop-group-id failure writes nothing to stdout');

// === RegenerateThumbnailsCommand =============================================
// (isEnabled() is not re-asserted here - out of scope for this round's brief,
// see this file's top comment)

// Regenerator::run() validates the domain argument first and returns early,
// with no error, for any domain that has zero configured image formats (read
// from Regenerator's own source) - so "categories" is a safe, deterministic
// success case regardless of whether this particular shop has any category
// image formats configured: either it genuinely regenerates a handful of
// already-existing category thumbnails (a harmless, idempotent overwrite,
// same operation the module documents as its normal job), or it no-ops
// immediately. --delete is intentionally omitted so nothing is ever removed.
list($contractRtExit, $contractRtStdout, $contractRtStderr) = bccli_contract_probe_run(
    $contractPsRoot,
    $contractModuleAutoload,
    RegenerateThumbnailsCommand::class,
    array('categories')
);
bccli_assert_same(
    0,
    $contractRtExit,
    'RegenerateThumbnailsCommand: a categories run at default verbosity (no -q, no -v, no --delete) exits 0'
        . ($contractRtExit !== 0 ? ' - stderr: ' . $contractRtStderr : '')
);
bccli_assert_same('', $contractRtStdout, 'RegenerateThumbnailsCommand: a successful run writes nothing to stdout at default verbosity');
bccli_assert_same('', $contractRtStderr, 'RegenerateThumbnailsCommand: a successful run writes nothing to stderr at default verbosity');

// An unknown image-domain argument is rejected by Regenerator::run() before
// anything else happens (InvalidArgumentException on the very first check),
// so this needs no fixture and touches no real image directory.
list($contractRtFailExit, $contractRtFailStdout, $contractRtFailStderr) = bccli_contract_probe_run(
    $contractPsRoot,
    $contractModuleAutoload,
    RegenerateThumbnailsCommand::class,
    array('totally_bogus_domain_xyz', '-q')
);
bccli_assert_same(1, $contractRtFailExit, 'RegenerateThumbnailsCommand: an unknown image domain under -q still exits 1');
bccli_assert($contractRtFailStderr !== '', 'RegenerateThumbnailsCommand: an unknown image domain under -q still writes to stderr');
bccli_assert_same('', $contractRtFailStdout, 'RegenerateThumbnailsCommand: the unknown-domain failure writes nothing to stdout');

// -v replaced a shared progress bar (which only advanced between domains and
// so sat motionless through the slowest one) with a line per domain, written
// the moment that domain starts and completed with its elapsed time once it
// finishes. Assertions here stay loose on purpose - the domain name must
// appear in stdout, not the exact column width or decimal formatting, so a
// future formatting tweak does not need to touch this file.
list($contractRtVerboseExit, $contractRtVerboseStdout, $contractRtVerboseStderr) = bccli_contract_probe_run(
    $contractPsRoot,
    $contractModuleAutoload,
    RegenerateThumbnailsCommand::class,
    array('categories', '-v')
);
bccli_assert_same(
    0,
    $contractRtVerboseExit,
    'RegenerateThumbnailsCommand: a categories run with -v exits 0'
        . ($contractRtVerboseExit !== 0 ? ' - stderr: ' . $contractRtVerboseStderr : '')
);
bccli_assert(
    strpos($contractRtVerboseStdout, 'Regenerating thumbnails: categories') !== false,
    'RegenerateThumbnailsCommand: -v prints the header line naming the requested domain'
);
bccli_assert(
    strpos($contractRtVerboseStdout, 'categories') !== false
        && strpos($contractRtVerboseStdout, 's' . "\n") !== false,
    'RegenerateThumbnailsCommand: -v prints a per-domain line for "categories" with an elapsed time'
);
bccli_assert(
    strpos($contractRtVerboseStdout, 'Done:') !== false,
    'RegenerateThumbnailsCommand: -v still prints the summary line after the per-domain lines'
);
bccli_assert_same('', $contractRtVerboseStderr, 'RegenerateThumbnailsCommand: a successful -v run writes nothing to stderr');

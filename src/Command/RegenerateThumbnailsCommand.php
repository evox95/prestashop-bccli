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

namespace Bccli\Command;

use Bccli\Thumbnails\ImageDomain;
use Bccli\Thumbnails\LegacyControllerBridge;
use Bccli\Thumbnails\OutputIsolation;
use Bccli\Thumbnails\Regenerator;
use Bccli\Thumbnails\RunLock;
use Bccli\Thumbnails\UnsupportedPlatformException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Backports PrestaShop 9.1's prestashop:thumbnails:regenerate to 1.7.8 and 8.x.
 *
 * The name deliberately matches core's so a crontab entry survives an upgrade
 * from 1.7 or 8 to 9 unchanged. Symfony does not reserve namespaces and silently lets
 * the last registered command win a name collision, so isEnabled() withdraws
 * this command whenever core already provides it.
 *
 * Output contract, designed for cron: success writes nothing at all and exits 0;
 * failure writes to stderr and exits 1, with or without -v.
 */
class RegenerateThumbnailsCommand extends Command
{
    const CORE_COMMAND = 'PrestaShopBundle\\Command\\RegenerateThumbnailsCommand';

    /**
     * Withdraws this command when core ships its own.
     *
     * Checks for the class rather than comparing version strings, so it also
     * does the right thing if the command is ever backported into an 8.x patch.
     *
     * @return bool
     */
    public function isEnabled()
    {
        return !class_exists(self::CORE_COMMAND);
    }

    protected function configure()
    {
        $this
            ->setName('prestashop:thumbnails:regenerate')
            ->setDescription('Regenerate thumbnails')
            ->addArgument(
                'image',
                InputArgument::REQUIRED,
                sprintf('Allowed images (%s)', implode(', ', ImageDomain::allowedValues()))
            )
            ->addArgument('image-type', InputArgument::OPTIONAL, 'Image format ID (0 for all)', null)
            ->addOption('delete', 'd', InputOption::VALUE_NONE, 'Erase previous images before regenerating');
    }

    /**
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        // The lock is taken before any work: core writes thumbnails straight to
        // their destination with no temporary file, so overlapping runs can
        // corrupt each other. A second cron firing while the first still runs is
        // normal operation, so it exits 0 in silence rather than mailing an error.
        // This early return must stay outside the try/finally below: it never
        // acquired the lock, so it must never call release().
        $lock = new RunLock($this->lockPath());
        if (!$lock->acquire()) {
            if ($output->isVerbose()) {
                $output->writeln('Another regeneration is already running.');
            }

            return 0;
        }

        // Everything from here on holds the lock, so it all runs inside one
        // try/finally that releases it exactly once on every exit path
        // (success, validation failure, or an unexpected exception).
        try {
            try {
                LegacyControllerBridge::assertSupported();
                $bridge = new LegacyControllerBridge();
            } catch (UnsupportedPlatformException $e) {
                // Message printed at VERBOSITY_QUIET so a -q run does not turn a
                // real failure into a silent exit 1 - see the class docblock's
                // cron contract.
                $errorOutput->writeln($e->getMessage(), OutputInterface::VERBOSITY_QUIET);

                return 1;
            }

            // CLI has no execution limit of its own, but core compares elapsed
            // time against its own max_execution_time property, which the
            // bridge sets. set_time_limit() is commonly present in
            // disable_functions on shared hosting; calling a disabled function
            // throws \Error on PHP 8, which "@" cannot suppress (it only
            // silences diagnostics, not thrown errors), so the call must be
            // guarded explicitly.
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }

            $image = (string) $input->getArgument('image');
            $domains = ImageDomain::isValid($image) ? ImageDomain::domainsFor($image) : array();

            // One line per domain instead of a shared progress bar: the bar only
            // advanced between domains, so it sat motionless at "0/5" while the
            // slow "products" pass ran (94% of a typical run, per the measurements
            // that prompted this change) and then jumped to 100% in milliseconds -
            // technically accurate, but read by anyone watching as a hang. Each
            // domain's name is written to $output the moment it starts, flushed
            // immediately (Symfony's StreamOutput flushes on every write), so a
            // long-running domain is visibly in progress; the same line is then
            // completed with its elapsed time once the domain finishes. The two
            // writes never have a newline between them, so together they always
            // form exactly one line per domain, whether it took milliseconds or
            // minutes - there is nothing terminal-specific (no \r, no redraws)
            // involved, so the output is identical whether read live or from a
            // redirected log. Both lines go to $output (stdout), matching the
            // header and summary lines around them; the old bar's stderr routing
            // was Symfony's ProgressBar auto-redirecting itself whenever given a
            // ConsoleOutputInterface, not a deliberate choice, and does not carry
            // over now that ProgressBar is gone.
            $showProgress = $output->isVerbose() && $domains !== array();
            if ($showProgress) {
                $output->writeln(sprintf('Regenerating thumbnails: %s', $image));
            }

            // Width shared by every domain line, computed from the full domain
            // list rather than just the domains this run touches, so a
            // single-domain run (e.g. "regenerate products -v") lines up exactly
            // like "all" does.
            $domainLabelWidth = max(array_map('strlen', ImageDomain::domainsFor(ImageDomain::ALL))) + 2;
            $domainStartedAt = null;
            $domainLineOpen = false;

            $started = microtime(true);

            try {
                try {
                    $report = (new Regenerator($bridge, new OutputIsolation()))->run(
                        $image,
                        $input->getArgument('image-type'),
                        (bool) $input->getOption('delete'),
                        function () use ($output, $showProgress, &$domainStartedAt, &$domainLineOpen) {
                            if (!$showProgress) {
                                return;
                            }
                            $output->writeln(sprintf('%.2fs', microtime(true) - $domainStartedAt));
                            $domainLineOpen = false;
                        },
                        function ($domain) use ($output, $showProgress, $domainLabelWidth, &$domainStartedAt, &$domainLineOpen) {
                            if (!$showProgress) {
                                return;
                            }
                            $output->write(sprintf('  %-' . $domainLabelWidth . 's', $domain));
                            $domainStartedAt = microtime(true);
                            $domainLineOpen = true;
                        }
                    );
                } finally {
                    // Mirrors the old bar's forced newline, adapted to this
                    // design: if a domain's line was opened but never completed -
                    // an exception or fatal error interrupted work mid-domain, so
                    // its "done" callback above never ran - close it before
                    // anything else is printed, so an error message never lands
                    // glued onto a half-written progress line. On every normal
                    // path (success, or a domain that merely reported an error
                    // and moved on) the last domain's own "done" callback already
                    // closed its line, so this is a no-op there.
                    if ($domainLineOpen) {
                        $output->writeln('');
                        $domainLineOpen = false;
                    }
                }
            } catch (\InvalidArgumentException $e) {
                $errorOutput->writeln($e->getMessage(), OutputInterface::VERBOSITY_QUIET);

                return 1;
            } catch (\Exception $e) {
                $errorOutput->writeln('Unable to regenerate thumbnails: ' . $e->getMessage(), OutputInterface::VERBOSITY_QUIET);

                return 1;
            } catch (\Throwable $e) {
                // \Error (e.g. a TypeError raised inside core) is not an \Exception,
                // so the catch above does not see it; left uncaught it would surface
                // as a raw, unhandled PHP fatal instead of the documented "stderr
                // message, exit 1" contract. \Throwable must be caught after
                // \Exception, not before or instead of it: \Exception does not
                // extend \Throwable's sibling \Error, but both implement
                // \Throwable, so an earlier \Throwable arm would swallow the
                // \Exception case above too and this arm would never run.
                $errorOutput->writeln('Unable to regenerate thumbnails: ' . $e->getMessage(), OutputInterface::VERBOSITY_QUIET);

                return 1;
            }

            foreach ($report->errors() as $error) {
                $errorOutput->writeln($error, OutputInterface::VERBOSITY_QUIET);
            }

            if ($output->isVerbose()) {
                $errorCount = count($report->errors());

                $output->writeln(sprintf(
                    "\nDone: %d source images, %d thumbnails, %d %s%s (%d s)",
                    $report->sourceCount(),
                    $report->thumbnailCount(),
                    $errorCount,
                    $errorCount === 1 ? 'error' : 'errors',
                    // markInterrupted() adds its own message via addError(), so an
                    // interrupted run's error count already includes it; flag it
                    // explicitly rather than let a bare count imply something more
                    // mundane happened.
                    $report->wasInterrupted() ? ' (interrupted)' : '',
                    (int) round(microtime(true) - $started)
                ));
            }

            return $report->exitCode();
        } finally {
            $lock->release();
        }
    }

    /**
     * @return string
     */
    private function lockPath()
    {
        if (defined('_PS_CACHE_DIR_')) {
            return rtrim(_PS_CACHE_DIR_, '/') . '/bccli-thumbnails.lock';
        }

        // _PS_CACHE_DIR_ is defined by config/defines.inc.php before any
        // command can run, so this branch is unreachable in practice - but an
        // unqualified name here would let two separate installations sharing
        // a temp directory collide on the same lock file, so the install root
        // is folded into the name anyway.
        $name = 'bccli-thumbnails-' . md5(defined('_PS_ROOT_DIR_') ? _PS_ROOT_DIR_ : __DIR__) . '.lock';

        return rtrim(sys_get_temp_dir(), '/') . '/' . $name;
    }
}

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

use Bccli\Search\SearchIndexer;
use Bccli\Search\SearchIndexOptions;
use Bccli\Search\UnsupportedPlatformException;
use Bccli\Thumbnails\RunLock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Backports PrestaShop 9.1's prestashop:search:index to 8.x.
 *
 * The name and options deliberately match core's so a crontab entry survives
 * an upgrade from 8 to 9 unchanged. Symfony does not reserve namespaces and
 * silently lets the last registered command win a name collision, so
 * isEnabled() withdraws this command whenever core already provides it.
 *
 * PrestaShop 9's own command always returns self::SUCCESS even after catching
 * a handler exception and reporting it via $io->error() - confirmed by
 * reading PrestaShop 9.1.4's own source. This command does not repeat that:
 * any failure exits 1, with or without -v, matching the rest of this module.
 *
 * Output contract, designed for cron: success writes nothing at all and exits
 * 0; failure writes to stderr and exits 1, with or without -v.
 */
class SearchIndexCommand extends Command
{
    const CORE_COMMAND = 'PrestaShopBundle\\Command\\SearchIndexationCommand';

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
            ->setName('prestashop:search:index')
            ->setDescription('Index products for search')
            ->addOption('full', 'f', InputOption::VALUE_NONE, 'Rebuild whole index')
            ->addOption('shop-id', 's', InputOption::VALUE_REQUIRED, 'Shop ID to index', null)
            ->addOption('shop-group-id', 'g', InputOption::VALUE_REQUIRED, 'Shop group ID to index', null)
            ->addOption('product-id', 'p', InputOption::VALUE_REQUIRED, 'Product ID to index', null);
    }

    /**
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        // The lock is taken before any work: an overlapping run would race on
        // the same DELETE/TRUNCATE + reinsert sequence in Search::indexation().
        // A second cron firing while the first still runs is normal operation,
        // so it exits 0 in silence rather than mailing an error. This early
        // return must stay outside the try/finally below: it never acquired
        // the lock, so it must never call release().
        $lock = new RunLock($this->lockPath());
        if (!$lock->acquire()) {
            if ($output->isVerbose()) {
                $output->writeln('Another search indexation is already running.');
            }

            return 0;
        }

        // Everything from here on holds the lock, so it all runs inside one
        // try/finally that releases it exactly once on every exit path
        // (success, validation failure, or an unexpected exception).
        try {
            try {
                $options = SearchIndexOptions::fromInput(
                    $input->getOption('full'),
                    $input->getOption('shop-id'),
                    $input->getOption('shop-group-id'),
                    $input->getOption('product-id')
                );
            } catch (\InvalidArgumentException $e) {
                $errorOutput->writeln($e->getMessage(), OutputInterface::VERBOSITY_QUIET);

                return 1;
            }

            // CLI has no execution limit of its own, but a large catalogue's
            // full reindex can run long; nothing here relies on it, this only
            // removes a limit a shared-hosting php.ini might still impose.
            // set_time_limit() is commonly present in disable_functions on
            // shared hosting; calling a disabled function throws \Error on
            // PHP 8, which "@" cannot suppress (it only silences diagnostics,
            // not thrown errors), so the call must be guarded explicitly.
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }

            if ($output->isVerbose()) {
                $output->writeln('Indexing products for search...');
            }

            try {
                $report = (new SearchIndexer())->run($options);
            } catch (UnsupportedPlatformException $e) {
                $errorOutput->writeln($e->getMessage(), OutputInterface::VERBOSITY_QUIET);

                return 1;
            } catch (\Exception $e) {
                $errorOutput->writeln('Unable to index search: ' . $e->getMessage(), OutputInterface::VERBOSITY_QUIET);

                return 1;
            } catch (\Throwable $e) {
                // \Error (e.g. a TypeError raised inside core) is not an
                // \Exception, so the catch above does not see it; left
                // uncaught it would surface as a raw, unhandled PHP fatal
                // instead of the documented "stderr message, exit 1" contract.
                // \Throwable must be caught after \Exception, not before or
                // instead of it: \Exception does not extend \Throwable's
                // sibling \Error, but both implement \Throwable, so an earlier
                // \Throwable arm would swallow the \Exception case above too
                // and this arm would never run.
                $errorOutput->writeln('Unable to index search: ' . $e->getMessage(), OutputInterface::VERBOSITY_QUIET);

                return 1;
            }

            if ($output->isVerbose()) {
                $output->writeln(sprintf(
                    'Search indexation complete: %d search_index rows (%s%d), %d s',
                    $report->indexRowsAfter(),
                    $report->indexRowsDelta() >= 0 ? '+' : '',
                    $report->indexRowsDelta(),
                    (int) round($report->elapsedSeconds())
                ));
            }

            return 0;
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
            return rtrim(_PS_CACHE_DIR_, '/') . '/bccli-search-index.lock';
        }

        // _PS_CACHE_DIR_ is defined by config/defines.inc.php before any
        // command can run, so this branch is unreachable in practice - but an
        // unqualified name here would let two separate installations sharing
        // a temp directory collide on the same lock file, so the install root
        // is folded into the name anyway.
        $name = 'bccli-search-index-' . md5(defined('_PS_ROOT_DIR_') ? _PS_ROOT_DIR_ : __DIR__) . '.lock';

        return rtrim(sys_get_temp_dir(), '/') . '/' . $name;
    }
}

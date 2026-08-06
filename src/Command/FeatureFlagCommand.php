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

use Bccli\FeatureFlag\FeatureFlagManager;
use Bccli\FeatureFlag\FeatureFlagOptions;
use Bccli\FeatureFlag\UnknownFeatureFlagException;
use Bccli\FeatureFlag\UnsupportedPlatformException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Backports PrestaShop 9.1's prestashop:feature-flag to 8.x.
 *
 * The name, arguments and actions deliberately match core's so a habit or a
 * script survives an upgrade from 8 to 9 unchanged. Symfony does not reserve
 * namespaces and silently lets the last registered command win a name
 * collision, so isEnabled() withdraws this command whenever core already
 * provides it.
 *
 * Output contract, designed for cron: enable/disable succeed in total silence
 * and exit 0, matching the rest of this module. list is the one documented
 * exception - it always has something to show, so it prints a table on
 * stdout and exits 0. Any failure writes to stderr and exits 1, with or
 * without -v.
 *
 * Unlike SearchIndexCommand and RegenerateThumbnailsCommand, this command
 * does not take a RunLock. Those two protect a bulk write (a full reindex, a
 * directory of regenerated thumbnails) where an overlapping run can corrupt
 * output that is read mid-write. This command changes at most one Doctrine
 * row per invocation; the worst case of two overlapping runs is ordinary
 * last-write-wins, not corruption, so the extra lock file would guard
 * against a risk that is not actually there.
 */
class FeatureFlagCommand extends Command
{
    const CORE_COMMAND = 'PrestaShopBundle\\Command\\FeatureFlagCommand';

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
            ->setName('prestashop:feature-flag')
            ->setDescription('Enable, disable or list PrestaShop feature flags')
            ->addArgument('action', InputArgument::REQUIRED, 'Action to perform: enable, disable or list')
            ->addArgument('feature_flag', InputArgument::OPTIONAL, 'Name of the feature flag');
    }

    /**
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        try {
            $options = FeatureFlagOptions::fromInput(
                $input->getArgument('action'),
                $input->getArgument('feature_flag')
            );
        } catch (\InvalidArgumentException $e) {
            $errorOutput->writeln($e->getMessage(), OutputInterface::VERBOSITY_QUIET);

            return 1;
        }

        $manager = new FeatureFlagManager();

        if ($options->isList()) {
            try {
                $flags = $manager->listFlags();
            } catch (UnsupportedPlatformException $e) {
                $errorOutput->writeln($e->getMessage(), OutputInterface::VERBOSITY_QUIET);

                return 1;
            }

            // list is the one command in this module allowed to print on
            // success without -v: there is no useful silent form of "show me
            // the flags".
            $this->printFlagsTable($output, $flags);

            return 0;
        }

        try {
            $manager->setState($options->flagName(), $options->isEnable());
        } catch (UnknownFeatureFlagException $e) {
            $errorOutput->writeln($e->getMessage(), OutputInterface::VERBOSITY_QUIET);

            return 1;
        } catch (UnsupportedPlatformException $e) {
            $errorOutput->writeln($e->getMessage(), OutputInterface::VERBOSITY_QUIET);

            return 1;
        } catch (\Exception $e) {
            $errorOutput->writeln('Unable to update feature flag: ' . $e->getMessage(), OutputInterface::VERBOSITY_QUIET);

            return 1;
        } catch (\Throwable $e) {
            // \Error (e.g. a TypeError raised inside core) is not an
            // \Exception, so the catch above does not see it; left uncaught
            // it would surface as a raw, unhandled PHP fatal instead of the
            // documented "stderr message, exit 1" contract. \Throwable must
            // be caught after \Exception, not before or instead of it:
            // \Exception does not extend \Throwable's sibling \Error, but
            // both implement \Throwable, so an earlier \Throwable arm would
            // swallow the \Exception case above too and this arm would never
            // run.
            $errorOutput->writeln('Unable to update feature flag: ' . $e->getMessage(), OutputInterface::VERBOSITY_QUIET);

            return 1;
        }

        if ($output->isVerbose()) {
            $output->writeln(sprintf(
                'Feature flag "%s" %s.',
                $options->flagName(),
                $options->isEnable() ? 'enabled' : 'disabled'
            ));
        }

        return 0;
    }

    /**
     * @param OutputInterface $output
     * @param array $flags list of array('name' => string, 'state' => bool)
     */
    private function printFlagsTable(OutputInterface $output, array $flags)
    {
        if (empty($flags)) {
            $output->writeln('No feature flags found.');

            return;
        }

        $nameWidth = 4;
        foreach ($flags as $flag) {
            $nameWidth = max($nameWidth, strlen($flag['name']));
        }

        $output->writeln(str_pad('Name', $nameWidth) . '  State');
        $output->writeln(str_repeat('-', $nameWidth) . '  -----');
        foreach ($flags as $flag) {
            $output->writeln(str_pad($flag['name'], $nameWidth) . '  ' . ($flag['state'] ? 'enabled' : 'disabled'));
        }
    }
}

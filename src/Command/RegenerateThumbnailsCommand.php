<?php
// SPDX-License-Identifier: MIT
/**
 * Copyright (c) 2026 Mateusz Bartocha <contact@bestcoding.net>
 */

namespace Bccli\Command;

use Bccli\Thumbnails\ImageDomain;
use Bccli\Thumbnails\LegacyControllerBridge;
use Bccli\Thumbnails\OutputIsolation;
use Bccli\Thumbnails\Regenerator;
use Bccli\Thumbnails\RunLock;
use Bccli\Thumbnails\UnsupportedPlatformException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Backports PrestaShop 9.1's prestashop:thumbnails:regenerate to 8.x.
 *
 * The name deliberately matches core's so a crontab entry survives an upgrade
 * from 8 to 9 unchanged. Symfony does not reserve namespaces and silently lets
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
        $lock = new RunLock($this->lockPath());
        if (!$lock->acquire()) {
            if ($output->isVerbose()) {
                $output->writeln('Another regeneration is already running.');
            }

            return 0;
        }

        try {
            LegacyControllerBridge::assertSupported();
            $bridge = new LegacyControllerBridge();
        } catch (UnsupportedPlatformException $e) {
            $errorOutput->writeln($e->getMessage());
            $lock->release();

            return 1;
        }

        // CLI has no execution limit of its own, but core compares elapsed time
        // against its own max_execution_time property, which the bridge sets.
        @set_time_limit(0);

        $image = (string) $input->getArgument('image');
        $domains = ImageDomain::isValid($image) ? ImageDomain::domainsFor($image) : array();

        $progress = null;
        if ($output->isVerbose() && $domains !== array()) {
            $output->writeln(sprintf('Regenerating thumbnails: %s', $image));
            $progress = new ProgressBar($output, count($domains));
            $progress->start();
        }

        $started = microtime(true);

        try {
            $report = (new Regenerator($bridge, new OutputIsolation()))->run(
                $image,
                $input->getArgument('image-type'),
                (bool) $input->getOption('delete'),
                function () use ($progress) {
                    if ($progress !== null) {
                        $progress->advance();
                    }
                }
            );
        } catch (\InvalidArgumentException $e) {
            $errorOutput->writeln($e->getMessage());
            $lock->release();

            return 1;
        } catch (\Exception $e) {
            $errorOutput->writeln('Unable to regenerate thumbnails: ' . $e->getMessage());
            $lock->release();

            return 1;
        }

        if ($progress !== null) {
            $progress->finish();
            $output->writeln('');
        }

        foreach ($report->errors() as $error) {
            $errorOutput->writeln($error);
        }

        if ($output->isVerbose()) {
            $output->writeln(sprintf(
                "\nDone: %d source images, %d thumbnails, %d errors (%d s)",
                $report->sourceCount(),
                $report->thumbnailCount(),
                count($report->errors()),
                (int) round(microtime(true) - $started)
            ));
        }

        $lock->release();

        return $report->exitCode();
    }

    /**
     * @return string
     */
    private function lockPath()
    {
        $directory = defined('_PS_CACHE_DIR_') ? _PS_CACHE_DIR_ : sys_get_temp_dir();

        return rtrim($directory, '/') . '/bccli-thumbnails.lock';
    }
}

<?php
// SPDX-License-Identifier: MIT
/**
 * Copyright (c) 2026 Mateusz Bartocha <contact@bestcoding.net>
 */

namespace Bccli\Thumbnails;

/**
 * Contains PHP diagnostics and stray output produced inside PrestaShop core.
 *
 * The command's cron contract requires zero bytes on success, but core can
 * write to the output streams without going through Symfony's OutputInterface:
 *
 *  - ImageManager.php:210 calls getimagesize() with no "@" suppressor, so an
 *    unreadable-but-non-empty source file emits a Notice on stdout.
 *  - ImageManager::resize() fires the actionOnImageResizeAfter hook, so any
 *    third-party module hooked there can echo freely.
 *
 * Everything captured here is reported as an error instead of being printed.
 */
class OutputIsolation
{
    /** @var string[] */
    private $captured = array();

    /** @var string|false */
    private $previousDisplayErrors = false;

    /** @var bool */
    private $active = false;

    public function start()
    {
        if ($this->active) {
            return;
        }

        $this->captured = array();
        $this->previousDisplayErrors = ini_get('display_errors');
        ini_set('display_errors', '0');

        $captured = &$this->captured;
        set_error_handler(function ($severity, $message, $file, $line) use (&$captured) {
            $captured[] = sprintf('%s (%s:%d)', $message, $file, $line);

            // Returning true stops PHP from also writing this to the output.
            return true;
        });

        ob_start();
        $this->active = true;
    }

    /**
     * @return string[] captured diagnostics; empty when nothing leaked
     */
    public function stop()
    {
        if (!$this->active) {
            return array();
        }

        $buffered = ob_get_clean();
        restore_error_handler();

        if ($this->previousDisplayErrors !== false) {
            ini_set('display_errors', $this->previousDisplayErrors);
        }

        $this->active = false;

        if (is_string($buffered) && trim($buffered) !== '') {
            $this->captured[] = 'Unexpected output from PrestaShop: ' . trim($buffered);
        }

        return $this->captured;
    }
}

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
 * Hooked module code runs inside the isolated span (via that hook) and is not
 * required to be well-behaved: it may open its own output buffer and never
 * close it, close the one this class opened, or open one that cannot be
 * closed at all (e.g. ob_start($callback, 0, 0), which is neither cleanable
 * nor removable). stop() therefore tracks the exact buffer level start() left
 * the stack at, unwinds relative to that level rather than assuming its own
 * buffer is still on top, and requires each pop to make progress before
 * continuing - see stop() for the failure modes this guards against.
 *
 * A trigger_error(..., E_USER_ERROR) (or E_RECOVERABLE_ERROR) inside the span
 * would normally halt execution. Because a custom error handler suppresses
 * that default behaviour, this class re-throws it as a RuntimeException so a
 * caller's try/finally still unwinds and the run is reported as a real
 * failure instead of continuing past what should have been a hard stop. It
 * has no way to see engine fatals or parse errors - those never reach a
 * registered error handler - so this containment is necessarily partial.
 *
 * Everything captured here is reported as an error instead of being printed -
 * except what error_reporting() would already exclude, or what the caller
 * suppressed with "@". Core suppresses several calls deliberately because
 * their failure is expected and harmless on some shops (e.g. three "@unlink"
 * calls and an "@chmod" in ImageManager.php, an "@exif_read_data" in
 * AdminImagesController.php); capturing those anyway would turn an ordinary,
 * successful run into a false failure.
 */
class OutputIsolation
{
    /** @var string[] */
    private $captured = array();

    /** @var string|false */
    private $previousDisplayErrors = false;

    /** @var bool */
    private $active = false;

    /** @var int output buffer level right after start() opens its own buffer */
    private $bufferLevel = 0;

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
            if (!(error_reporting() & $severity)) {
                // Suppressed by "@" (error_reporting() reads 0 for the duration of the
                // suppressed expression on PHP 7, a reduced bitmask on PHP 8) or excluded
                // by the caller's own error_reporting() setting. Core relies on "@" being
                // honoured - ImageManager.php and AdminImagesController.php both suppress
                // calls that are expected to fail on some shops (chmod, exif_read_data,
                // unlink) - so a handler that captured regardless of this would flip a
                // successful, merely-noisy run into a false failure. This must run before
                // the fatal-severity rethrow below too, so a suppressed E_USER_ERROR is
                // also left alone rather than turned into an exception.
                return true;
            }

            $captured[] = sprintf('%s (%s:%d)', $message, $file, $line);

            if ($severity === E_USER_ERROR || $severity === E_RECOVERABLE_ERROR) {
                // Normally fatal. A custom error handler would otherwise silently
                // swallow it and let execution continue past what should be a hard
                // stop, so turn it back into one via an exception.
                throw new \RuntimeException($message);
            }

            // Returning true stops PHP from also writing this to the output.
            return true;
        });

        ob_start();
        $this->bufferLevel = ob_get_level();
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

        $currentLevel = ob_get_level();
        $buffered = '';

        if ($currentLevel < $this->bufferLevel) {
            // Wrapped code already closed the buffer start() opened (and possibly a
            // caller's buffer above it). Do not touch the stack any further - calling
            // ob_get_clean() now would pop a buffer this instance never opened.
            $this->captured[] = 'OutputIsolation: the isolated output buffer was closed by '
                . 'wrapped code before stop() ran (expected buffer level ' . $this->bufferLevel
                . ', found ' . $currentLevel . ')';
        } else {
            $leakedLevels = $currentLevel - $this->bufferLevel;

            // Unwind everything down to (and including) the buffer this instance
            // opened, not just the top of the stack - a hooked module may have left
            // its own buffer(s) open above it. Buffers are popped innermost first, so
            // collect chunks in pop order and reverse them back into write order.
            //
            // Progress is a precondition of continuing: a non-removable/non-cleanable
            // buffer (e.g. ob_start($callback, 0, 0)) makes ob_get_clean() fail
            // without moving the level, which would otherwise spin forever - the CLI
            // SAPI has no execution time limit. A small iteration cap is a second,
            // unconditional guard against any other edge case that keeps the level
            // moving but never actually reaches $this->bufferLevel.
            $chunks = array();
            $maxIterations = 64;
            $iterations = 0;
            $incomplete = false;

            while (ob_get_level() >= $this->bufferLevel) {
                if ($iterations >= $maxIterations) {
                    $this->captured[] = 'OutputIsolation: gave up unwinding output buffers '
                        . 'after ' . $maxIterations . ' iterations (buffer level still '
                        . ob_get_level() . ')';
                    $incomplete = true;
                    break;
                }

                $levelBeforePop = ob_get_level();
                $chunk = ob_get_clean();
                ++$iterations;

                if (ob_get_level() >= $levelBeforePop) {
                    // ob_get_clean() failed to close this buffer (it lacks the
                    // CLEANABLE/REMOVABLE flags) and the level did not move. Stop
                    // unwinding here instead of retrying forever.
                    $this->captured[] = 'OutputIsolation: a non-removable output buffer '
                        . 'at level ' . $levelBeforePop . ' could not be closed and was '
                        . 'left open';
                    $incomplete = true;
                    break;
                }

                if (is_string($chunk) && $chunk !== '') {
                    $chunks[] = $chunk;
                }
            }
            $buffered = implode("\n", array_reverse($chunks));

            if (!$incomplete && $leakedLevels > 0) {
                $this->captured[] = 'OutputIsolation: wrapped code left ' . $leakedLevels
                    . ' output buffer(s) open (unbalanced ob_start())';
            }
        }

        restore_error_handler();

        if ($this->previousDisplayErrors !== false) {
            ini_set('display_errors', $this->previousDisplayErrors);
        }

        $this->active = false;

        if (trim($buffered) !== '') {
            $this->captured[] = 'Unexpected output from PrestaShop: ' . trim($buffered);
        }

        return $this->captured;
    }
}

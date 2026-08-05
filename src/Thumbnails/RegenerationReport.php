<?php
// SPDX-License-Identifier: MIT
/**
 * Copyright (c) 2026 Mateusz Bartocha <contact@bestcoding.net>
 */

namespace Bccli\Thumbnails;

/**
 * Accumulates the outcome of a regeneration run.
 *
 * Core reports one error per failed image, so the same corrupt source can
 * produce many identical messages; they are de-duplicated here rather than
 * flooding cron mail.
 */
class RegenerationReport
{
    /** @var string[] */
    private $errors = array();

    /** @var int */
    private $sources = 0;

    /** @var int */
    private $thumbnails = 0;

    /** @var bool */
    private $interrupted = false;

    /**
     * @param string $message
     */
    public function addError($message)
    {
        if (!in_array($message, $this->errors, true)) {
            $this->errors[] = $message;
        }
    }

    /**
     * @param int $sources
     * @param int $thumbnails
     */
    public function addImages($sources, $thumbnails)
    {
        $this->sources += (int) $sources;
        $this->thumbnails += (int) $thumbnails;
    }

    /**
     * @param string $domain
     */
    public function markInterrupted($domain)
    {
        $this->interrupted = true;
        $this->addError(sprintf(
            'Interrupted while processing "%s" - some thumbnails may be missing.',
            $domain
        ));
    }

    /**
     * @return bool
     */
    public function hasErrors()
    {
        return $this->errors !== array();
    }

    /**
     * @return string[]
     */
    public function errors()
    {
        return $this->errors;
    }

    /**
     * @return int
     */
    public function sourceCount()
    {
        return $this->sources;
    }

    /**
     * @return int
     */
    public function thumbnailCount()
    {
        return $this->thumbnails;
    }

    /**
     * @return bool
     */
    public function wasInterrupted()
    {
        return $this->interrupted;
    }

    /**
     * @return int 0 on success, 1 if anything went wrong
     */
    public function exitCode()
    {
        return ($this->hasErrors() || $this->interrupted) ? 1 : 0;
    }
}

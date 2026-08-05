<?php
// SPDX-License-Identifier: MIT
/**
 * Copyright (c) 2026 Mateusz Bartocha <contact@bestcoding.net>
 */

namespace Bccli\Thumbnails;

/**
 * Best-effort exclusive lock preventing two bccli regeneration runs from
 * overlapping.
 *
 * PrestaShop core has no locking, and thumbnails are written straight to their
 * destination rather than to a temporary file plus rename(), so two concurrent
 * bccli runs - e.g. a cron firing while the previous one is still going - can
 * leave truncated or missing files. This lock only ever excludes OTHER bccli
 * invocations sharing the same lock file; it has no way to detect, and does
 * not guard against, a back office regeneration running at the same time.
 *
 * The handle is held for the process lifetime; the operating system releases it
 * if the process dies, so a stale lock file cannot wedge future runs. It is a
 * best-effort optimisation, not a correctness guarantee: acquire() returns
 * true (proceed unlocked) when the lock file cannot even be opened, since an
 * unwritable cache directory must not block a legitimate run.
 */
class RunLock
{
    /** @var string */
    private $path;

    /** @var resource|null */
    private $handle;

    /**
     * @param string $path
     */
    public function __construct($path)
    {
        $this->path = $path;
        $this->handle = null;
    }

    /**
     * @return bool true if this process now holds the lock
     */
    public function acquire()
    {
        $handle = @fopen($this->path, 'c');
        if ($handle === false) {
            // An unwritable lock directory must not block the run; the lock is
            // an optimisation, not a correctness requirement for a single run.
            return true;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->handle = $handle;

        return true;
    }

    public function release()
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }
}

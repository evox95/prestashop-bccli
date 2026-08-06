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

namespace Bccli\Search;

/**
 * Outcome of one indexation run.
 *
 * Search::indexation() returns no granular success/failure signal at all - it
 * always returns true (verified against PrestaShop 8.2.7's classes/Search.php).
 * The only thing worth reporting is measured, not asked for: the search_index
 * row count before and after, which is exactly the metric the module's README
 * already uses to show a run actually did something.
 */
class SearchIndexReport
{
    /** @var int */
    private $indexRowsBefore;

    /** @var int */
    private $indexRowsAfter;

    /** @var float */
    private $elapsedSeconds;

    /**
     * @param int   $indexRowsBefore
     * @param int   $indexRowsAfter
     * @param float $elapsedSeconds
     */
    public function __construct($indexRowsBefore, $indexRowsAfter, $elapsedSeconds)
    {
        $this->indexRowsBefore = (int) $indexRowsBefore;
        $this->indexRowsAfter = (int) $indexRowsAfter;
        $this->elapsedSeconds = (float) $elapsedSeconds;
    }

    /**
     * @return int
     */
    public function indexRowsBefore()
    {
        return $this->indexRowsBefore;
    }

    /**
     * @return int
     */
    public function indexRowsAfter()
    {
        return $this->indexRowsAfter;
    }

    /**
     * @return int can be negative - e.g. a --full run first clears rows for
     *             products that are no longer visible/active before reindexing
     */
    public function indexRowsDelta()
    {
        return $this->indexRowsAfter - $this->indexRowsBefore;
    }

    /**
     * @return float
     */
    public function elapsedSeconds()
    {
        return $this->elapsedSeconds;
    }
}

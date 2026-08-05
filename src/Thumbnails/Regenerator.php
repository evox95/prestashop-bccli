<?php
// SPDX-License-Identifier: MIT
/**
 * Copyright (c) 2026 Mateusz Bartocha <contact@bestcoding.net>
 */

namespace Bccli\Thumbnails;

/**
 * Drives one regeneration run: one pass per image domain.
 *
 * The call order mirrors AdminImagesController::_regenerateThumbnails()
 * (lines 927-969) rather than inventing one. Note that core calls the watermark
 * step inside "if ($proc['type'] == 'products')" but the no-picture step
 * OUTSIDE it, so placeholder images are refreshed for every domain.
 */
class Regenerator
{
    /** @var LegacyControllerBridge */
    private $bridge;

    /** @var OutputIsolation */
    private $isolation;

    public function __construct(LegacyControllerBridge $bridge, OutputIsolation $isolation)
    {
        $this->bridge = $bridge;
        $this->isolation = $isolation;
    }

    /**
     * @param array    $formats     image type rows from core
     * @param int      $imageTypeId 0 means every format
     *
     * @return array filtered rows, reindexed from 0
     */
    public static function filterFormats(array $formats, $imageTypeId)
    {
        if ((int) $imageTypeId === 0) {
            return array_values($formats);
        }

        $selected = array();
        foreach ($formats as $format) {
            if ((int) $format['id_image_type'] === (int) $imageTypeId) {
                $selected[] = $format;
            }
        }

        return $selected;
    }

    /**
     * Validates the <image-type> argument.
     *
     * Deliberately does NOT use ImageType::getImageTypeById(): it declares an
     * array return type but returns false for an unknown id, so it throws a
     * TypeError - and this command must never surface a PHP fatal error.
     *
     * @param string|null $raw
     *
     * @return int 0 when every format should be processed
     *
     * @throws \InvalidArgumentException
     */
    public static function validateImageTypeId($raw)
    {
        if ($raw === null || $raw === '') {
            return 0;
        }

        if (!ctype_digit((string) $raw)) {
            throw new \InvalidArgumentException(sprintf(
                'Image format ID "%s" is invalid. It must be a positive number, or 0 for all formats.',
                $raw
            ));
        }

        return (int) $raw;
    }

    /**
     * Counts regular files under a directory, recursively.
     *
     * Most domains are a single flat directory, but the product tree
     * (_PS_PRODUCT_IMG_DIR_) nests images several levels deep (e.g.
     * img/p/1/4/14-home_default.jpg), so a flat scandir() would miss almost
     * everything there. One recursive walk handles both cases; recursing a
     * flat directory is harmless.
     *
     * Pure and static so it is testable without PrestaShop loaded.
     *
     * @param string $directory
     *
     * @return int the file count, or -1 if the directory could not be read
     *             (e.g. write+execute but no read permission - is_writable()
     *             alone does not catch that). -1 is a sentinel, not a count:
     *             0 is a legitimate "empty directory" result and callers must
     *             be able to tell the two apart.
     */
    public static function countFiles($directory)
    {
        if (!is_dir($directory)) {
            return 0;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
            );

            $count = 0;
            foreach ($iterator as $entry) {
                if ($entry->isFile()) {
                    ++$count;
                }
            }

            return $count;
        } catch (\Exception $e) {
            // RecursiveDirectoryIterator throws UnexpectedValueException when
            // it cannot open the directory (e.g. is_writable() was true but
            // the directory is not readable). This must never propagate: it
            // is a filesystem permission fact, not a bug.
            return -1;
        }
    }

    /**
     * @param string      $image        value of the <image> argument
     * @param string|null $imageTypeId  value of the <image-type> argument
     * @param bool        $delete       erase existing thumbnails first
     * @param callable    $onDomainDone called with each finished domain name
     *
     * @return RegenerationReport
     *
     * @throws \InvalidArgumentException on invalid arguments
     */
    public function run($image, $imageTypeId, $delete, $onDomainDone = null)
    {
        if (!ImageDomain::isValid($image)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown image domain "%s". Allowed: %s.',
                $image,
                implode(', ', ImageDomain::allowedValues())
            ));
        }

        $typeId = self::validateImageTypeId($imageTypeId);
        $domains = ImageDomain::domainsFor($image);
        $report = new RegenerationReport();

        // Reject an id that matches nothing anywhere, rather than silently
        // regenerating zero images.
        if ($typeId !== 0) {
            $this->assertImageTypeExists($domains, $typeId);
        }

        foreach ($domains as $domain) {
            $this->runDomain($domain, $typeId, $delete, $report);

            if (is_callable($onDomainDone)) {
                call_user_func($onDomainDone, $domain);
            }
        }

        return $report;
    }

    /**
     * @param string[] $domains
     * @param int      $typeId
     *
     * @throws \InvalidArgumentException
     */
    private function assertImageTypeExists(array $domains, $typeId)
    {
        foreach ($domains as $domain) {
            if (self::filterFormats($this->bridge->formatsFor($domain), $typeId) !== array()) {
                return;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'Image format ID %d does not exist for %s.',
            $typeId,
            implode(' or ', $domains)
        ));
    }

    /**
     * @param string             $domain
     * @param int                $typeId
     * @param bool               $delete
     * @param RegenerationReport $report
     */
    private function runDomain($domain, $typeId, $delete, RegenerationReport $report)
    {
        $directory = $this->bridge->directoryFor($domain);

        // Core returns false - its SUCCESS value - when the directory is
        // missing (lines 625, 680), so without this precheck a broken symlink
        // would make the command exit 0 having done nothing.
        if (!is_dir($directory)) {
            $report->addError(sprintf('Image directory not found: %s', $directory));

            return;
        }

        if (!is_readable($directory)) {
            $report->addError(sprintf('Cannot read %s - check permissions.', $directory));

            return;
        }

        if (!is_writable($directory)) {
            $report->addError(sprintf('Cannot write to %s - check permissions.', $directory));

            return;
        }

        $formats = self::filterFormats($this->bridge->formatsFor($domain), $typeId);
        if ($formats === array()) {
            return;
        }

        $sources = $this->bridge->sourceCountFor($domain);
        $filesBefore = self::countFiles($directory);

        $this->isolation->start();
        try {
            if ($delete) {
                $this->bridge->deleteOldImages($domain, $formats);
                $this->collect($report);
            }

            $result = $this->bridge->regenerateNewImages($domain, $formats);
            $this->collect($report);

            if ($result === 'timeout') {
                $report->markInterrupted($domain);

                return;
            }

            if (ImageDomain::isProduct($domain)) {
                if ($this->bridge->regenerateWatermark($domain, $formats) === 'timeout') {
                    $report->addError(
                        'Server timed out. The watermark may not have been applied to all images.'
                    );
                }
                $this->collect($report);
            }

            // Core calls this for EVERY domain, not just products - the call
            // sits outside the products branch (lines 956-966).
            if ($this->bridge->regenerateNoPictureImages($domain, $formats)) {
                $report->addError(sprintf(
                    'Cannot write "No picture" image to the %s images folder.',
                    $domain
                ));
            }
            $this->collect($report);

            // Core only writes a thumbnail when it does not already exist
            // (AdminImagesController.php:714), so counting sources * formats
            // counts files that were already there as if this run had just
            // produced them - highest exactly when a run (e.g. a repeated
            // cron run) did the least work. Count what actually changed on
            // disk instead. With --delete the delta can go negative (an
            // unfiltered delete can remove more than a filtered regenerate
            // writes back); a negative thumbnail count is nonsensical, so
            // clamp at 0 and let the source count carry the meaning.
            $filesAfter = self::countFiles($directory);
            if ($filesBefore === -1 || $filesAfter === -1) {
                // The precheck above passed (directory looked readable at the
                // time) but the count could not be taken anyway - report
                // "no thumbnails counted" rather than doing arithmetic on a
                // sentinel; $sources still tells the caller the domain was
                // processed.
                $thumbnails = 0;
            } else {
                $thumbnails = max(0, $filesAfter - $filesBefore);
            }
            $report->addImages($sources, $thumbnails);
        } finally {
            foreach ($this->isolation->stop() as $leaked) {
                $report->addError($leaked);
            }
        }
    }

    /**
     * Drains core's error list into the report.
     *
     * Must happen after every call: core's errors latch, and it skips its own
     * post-processing while any error is pending.
     *
     * @param RegenerationReport $report
     */
    private function collect(RegenerationReport $report)
    {
        foreach ($this->bridge->takeErrors() as $error) {
            $report->addError($error);
        }
    }
}

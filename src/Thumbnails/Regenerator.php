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
     * @return array filtered rows, reindexed
     */
    public static function filterFormats(array $formats, $imageTypeId)
    {
        if ((int) $imageTypeId === 0) {
            return $formats;
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

        if (!is_writable($directory)) {
            $report->addError(sprintf('Cannot write to %s - check permissions.', $directory));

            return;
        }

        $formats = self::filterFormats($this->bridge->formatsFor($domain), $typeId);
        if ($formats === array()) {
            return;
        }

        $sources = $this->bridge->sourceCountFor($domain);

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

            $report->addImages($sources, $sources * count($formats));
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

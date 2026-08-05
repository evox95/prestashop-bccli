<?php
// SPDX-License-Identifier: MIT
/**
 * Copyright (c) 2026 Mateusz Bartocha <contact@bestcoding.net>
 */

namespace Bccli\Thumbnails;

/**
 * The only class coupled to PrestaShop core internals.
 *
 * PrestaShop 8 keeps thumbnail regeneration in protected methods of
 * AdminImagesController. PrestaShop 9 extracted that logic into
 * Adapter\ImageThumbnailsRegenerator and exposed it through CQRS, but no such
 * adapter exists in 8.x, so the controller is driven directly.
 *
 * Three things must be fixed before the controller works outside a web request:
 *
 *  1. $imageFormatConfiguration is injected from the Symfony container in
 *     AdminImagesController::init() (line 101). The container is unavailable in
 *     CLI, so the property stays null and getGenerationFormats() fatals. It is
 *     built here directly - it only needs a ConfigurationInterface.
 *  2. $start_time defaults to 0 (line 36) and the loop guard is
 *     "time() - start_time > max_execution_time - 4", which is true
 *     immediately, so core returns 'timeout' having written nothing.
 *  3. All the regeneration methods are protected.
 *
 * When these internals change in a future release, this file is the only one to
 * update.
 */
class LegacyControllerBridge
{
    const CONTROLLER = 'AdminImagesController';
    const FORMAT_CONFIG = 'PrestaShop\\PrestaShop\\Core\\Image\\ImageFormatConfiguration';
    const CONFIGURATION_ADAPTER = 'PrestaShop\\PrestaShop\\Adapter\\Configuration';

    /** Large enough that core's guard never trips; CLI has no real limit. */
    const NO_TIME_LIMIT = 86400;

    /** @var \AdminImagesController */
    private $controller;

    /** @var \ReflectionClass */
    private $reflection;

    /**
     * Verifies every core symbol this class touches, before touching any.
     *
     * @throws UnsupportedPlatformException
     */
    public static function assertSupported()
    {
        if (!class_exists(self::CONTROLLER)) {
            throw new UnsupportedPlatformException(self::message('class ' . self::CONTROLLER));
        }

        $reflection = new \ReflectionClass(self::CONTROLLER);

        $methods = array(
            '_deleteOldImages',
            '_regenerateNewImages',
            '_regenerateWatermark',
            '_regenerateNoPictureImages',
        );
        foreach ($methods as $method) {
            if (!$reflection->hasMethod($method)) {
                throw new UnsupportedPlatformException(
                    self::message(self::CONTROLLER . '::' . $method . '()')
                );
            }
        }

        foreach (array('start_time', 'max_execution_time', 'errors') as $property) {
            if (!$reflection->hasProperty($property)) {
                throw new UnsupportedPlatformException(
                    self::message(self::CONTROLLER . '::$' . $property)
                );
            }
        }

        // Present from PS 8.0 onwards, absent on 1.7. Only required when the
        // controller declares the property that consumes it.
        if ($reflection->hasProperty('imageFormatConfiguration') && !class_exists(self::FORMAT_CONFIG)) {
            throw new UnsupportedPlatformException(self::message('class ' . self::FORMAT_CONFIG));
        }
    }

    /**
     * @param string $missing
     *
     * @return string
     */
    private static function message($missing)
    {
        return sprintf(
            'Unsupported PrestaShop %s: %s not found.',
            defined('_PS_VERSION_') ? _PS_VERSION_ : 'build',
            $missing
        );
    }

    public function __construct()
    {
        self::assertSupported();

        $controllerClass = self::CONTROLLER;
        $this->controller = new $controllerClass();
        $this->reflection = new \ReflectionClass(self::CONTROLLER);

        $this->injectImageFormatConfiguration();
        $this->set('start_time', time());
        $this->set('max_execution_time', self::NO_TIME_LIMIT);
    }

    /**
     * Replaces the container injection performed by the controller's init().
     */
    private function injectImageFormatConfiguration()
    {
        if (!$this->reflection->hasProperty('imageFormatConfiguration')) {
            return;
        }

        $formatConfigClass = self::FORMAT_CONFIG;
        $configurationClass = self::CONFIGURATION_ADAPTER;

        $this->set('imageFormatConfiguration', new $formatConfigClass(new $configurationClass()));
    }

    /**
     * @param string $name
     * @param mixed  $value
     */
    private function set($name, $value)
    {
        $property = $this->reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($this->controller, $value);
    }

    /**
     * @param string $name
     * @param array  $arguments
     *
     * @return mixed
     */
    private function call($name, array $arguments)
    {
        $method = $this->reflection->getMethod($name);
        $method->setAccessible(true);

        return $method->invokeArgs($this->controller, $arguments);
    }

    /**
     * @param string $domain
     *
     * @return string
     */
    public function directoryFor($domain)
    {
        return constant(ImageDomain::constantFor($domain));
    }

    /**
     * @param string $domain
     *
     * @return array image type rows as core returns them
     */
    public function formatsFor($domain)
    {
        return \ImageType::getImagesTypes($domain);
    }

    /**
     * Number of source images in a domain, for the summary line.
     *
     * Products are enumerated from the database because their files live in
     * nested subdirectories; every other domain is a flat directory. The
     * pattern is core's own (AdminImagesController.php:697).
     *
     * @param string $domain
     *
     * @return int
     */
    public function sourceCountFor($domain)
    {
        if (ImageDomain::isProduct($domain)) {
            return count(\Image::getAllImages());
        }

        $directory = $this->directoryFor($domain);
        if (!is_dir($directory)) {
            return 0;
        }

        $count = 0;
        foreach (scandir($directory, SCANDIR_SORT_NONE) as $entry) {
            if (preg_match('/^[0-9]*\.jpg$/', $entry)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Always pass the COMPLETE format list.
     *
     * _deleteOldImages() has a branch gated on "count($type) > 1"
     * (AdminImagesController.php:634) that removes orphaned thumbnails left
     * behind by image formats no longer configured. A single-element array
     * makes that branch dead, so --delete would keep the very files it exists
     * to reclaim.
     *
     * @param string $domain
     * @param array  $formats
     */
    public function deleteOldImages($domain, array $formats)
    {
        $this->call('_deleteOldImages', array(
            $this->directoryFor($domain),
            $formats,
            ImageDomain::isProduct($domain),
        ));
    }

    /**
     * Always pass the COMPLETE format list.
     *
     * Core reassigns $image - the OUTER loop variable - inside the format loop
     * (AdminImagesController.php:706) when a category has a "_thumb" original.
     * The value leaks into every later format of the same call, which is the
     * behaviour the back office produces. Calling once per format resets it and
     * yields thumbnails built from a different source file.
     *
     * @param string $domain
     * @param array  $formats
     *
     * @return bool|string false on success, true on errors, 'timeout' if interrupted
     */
    public function regenerateNewImages($domain, array $formats)
    {
        return $this->call('_regenerateNewImages', array(
            $this->directoryFor($domain),
            $formats,
            ImageDomain::isProduct($domain),
        ));
    }

    /**
     * @param string $domain
     * @param array  $formats
     *
     * @return mixed 'timeout' if interrupted
     */
    public function regenerateWatermark($domain, array $formats)
    {
        return $this->call('_regenerateWatermark', array($this->directoryFor($domain), $formats));
    }

    /**
     * @param string $domain
     * @param array  $formats
     *
     * @return bool true means FAILURE, matching core
     */
    public function regenerateNoPictureImages($domain, array $formats)
    {
        return (bool) $this->call('_regenerateNoPictureImages', array(
            $this->directoryFor($domain),
            $formats,
            \Language::getLanguages(false),
        ));
    }

    /**
     * Returns core's accumulated messages and clears them.
     *
     * $ctrl->errors latches: core returns "(bool) count($this->errors)", the
     * whole array rather than this call's errors, so without clearing, one
     * early failure marks every later domain as failed. Core also guards its
     * own post-processing with "if (!count($this->errors))", so a stale entry
     * silently skips no-picture regeneration for the rest of the run.
     *
     * @return string[]
     */
    public function takeErrors()
    {
        $errors = array();
        foreach ((array) $this->controller->errors as $error) {
            $errors[] = (string) $error;
        }

        $this->controller->errors = array();

        return $errors;
    }
}

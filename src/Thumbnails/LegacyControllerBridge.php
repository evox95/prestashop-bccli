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
 * The only class coupled to PrestaShop core internals.
 *
 * PrestaShop 1.7 and 8 keep thumbnail regeneration in protected methods of
 * AdminImagesController. PrestaShop 9 extracted that logic into
 * Adapter\ImageThumbnailsRegenerator and exposed it through CQRS, but no such
 * adapter exists in 1.7/8.x, so the controller is driven directly.
 *
 * Four things must be fixed before the controller works outside a web request:
 *
 *  1. $imageFormatConfiguration is injected from the Symfony container in
 *     AdminImagesController::init() (line 101). The container is unavailable in
 *     CLI, so the property stays null and getGenerationFormats() fatals. It is
 *     built here directly - it only needs a ConfigurationInterface.
 *  2. $start_time defaults to 0 (line 36) and the loop guard is
 *     "time() - start_time > max_execution_time - 4", which is true
 *     immediately, so core returns 'timeout' having written nothing.
 *  3. All the regeneration methods are protected.
 *  4. PS 1.7's bin/console boots the front-office context, with no employee.
 *     AdminController's constructor dereferences that employee. Supply an empty,
 *     unauthenticated Employee, never an existing administrator's identity.
 *
 * Constructing the controller also runs core's own Controller::__construct(),
 * which sets Context::getContext()->controller = $this (classes/controller/
 * Controller.php:261). That is a real mutation of the global context, harmless
 * for a single-shot CLI process but worth knowing if anything downstream reads
 * Context::getContext()->controller during a run.
 *
 * When these internals change in a future release, this file is the only one to
 * update.
 */
class LegacyControllerBridge
{
    const CONTROLLER = 'AdminImagesController';
    const FORMAT_CONFIG = 'PrestaShop\\PrestaShop\\Core\\Image\\ImageFormatConfiguration';
    const CONFIGURATION_ADAPTER = 'PrestaShop\\PrestaShop\\Adapter\\Configuration';

    /**
     * Core's timeout guard is "time() - start_time > max_execution_time - 4"
     * (AdminImagesController.php:745, 813, 904); PHP_INT_MAX - 4 is as close to
     * "this guard never trips" as an int comparison can get, which is the
     * actual intent - the CLI has no real limit of its own. An earlier, smaller
     * value here (86400, one day) still left the guard tripping after
     * 23h59m56s of continuous runtime, which no run should ever approach but
     * was not actually "never".
     */
    const NO_TIME_LIMIT = \PHP_INT_MAX;

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

        // Used by the CLI context, formatsFor(), sourceCountFor() and placeholders.
        foreach (array('Context', 'Employee', 'ImageType', 'Image', 'Language') as $class) {
            if (!class_exists($class)) {
                throw new UnsupportedPlatformException(self::message('class ' . $class));
            }
        }

        // Present from PS 8.0 onwards, absent on 1.7. Only required when the
        // controller declares the property that consumes it. Both classes are
        // needed to build the replacement value (see injectImageFormatConfiguration()).
        if ($reflection->hasProperty('imageFormatConfiguration')) {
            if (!class_exists(self::FORMAT_CONFIG)) {
                throw new UnsupportedPlatformException(self::message('class ' . self::FORMAT_CONFIG));
            }
            if (!class_exists(self::CONFIGURATION_ADAPTER)) {
                throw new UnsupportedPlatformException(self::message('class ' . self::CONFIGURATION_ADAPTER));
            }
        }

        // directoryFor() resolves these with constant(), which throws \Error - not
        // catchable as an Exception - on an undefined constant. Verify every domain
        // directory constant is defined before any caller can reach that path.
        foreach (ImageDomain::allowedValues() as $domain) {
            if ($domain === ImageDomain::ALL) {
                continue;
            }

            $constantName = ImageDomain::constantFor($domain);
            if (!defined($constantName)) {
                throw new UnsupportedPlatformException(self::message('constant ' . $constantName));
            }
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

        $context = \Context::getContext();
        if ($context->employee === null) {
            $context->employee = new \Employee();
        }

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
     * For an unfiltered run, callers must pass the COMPLETE format list.
     *
     * _deleteOldImages() has a branch gated on "count($type) > 1"
     * (AdminImagesController.php:634) that removes orphaned thumbnails left
     * behind by image formats no longer configured. A single-element array
     * makes that branch dead, so --delete would keep the very files it exists
     * to reclaim.
     *
     * When a caller narrows $formats to one image type (e.g. an --image-type
     * filter), the orphan-cleanup branch above goes dead on purpose: core's own
     * back office does exactly the same thing. It computes $formats once per
     * domain, filters it down to the chosen type when one is selected, and
     * passes that same filtered array into _deleteOldImages()
     * (AdminImagesController.php:934-948). Matching that is correct - diverging
     * would make this command behave differently from the back office it
     * mirrors. Do not special-case a filtered list here.
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
     * For an unfiltered run, callers must pass the COMPLETE format list.
     *
     * Core reassigns $image - the OUTER loop variable - inside the format loop
     * (AdminImagesController.php:706) when a category has a "_thumb" original.
     * The value leaks into every later format of the same call, which is the
     * behaviour the back office produces. Calling once per format resets it and
     * yields thumbnails built from a different source file.
     *
     * When a caller narrows $formats to one image type (e.g. an --image-type
     * filter), passing the filtered list here still matches core: the back
     * office computes $formats once per domain, filters it to the chosen type,
     * and passes that same array into both _deleteOldImages() and
     * _regenerateNewImages() in the same iteration
     * (AdminImagesController.php:934-950). A filtered call therefore never hits
     * the "_thumb" leak above, since there is nothing after the single format
     * for it to leak into. Do not special-case a filtered list here.
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
            $errors[] = self::stringifyError($error);
        }

        $this->controller->errors = array();

        return $errors;
    }

    /**
     * A method whose only job is collecting error messages must not itself
     * fail on one: (string) $error throws \Error for an object without
     * __toString() and emits a notice for an array. Substitute a readable
     * placeholder instead of ever throwing or warning here.
     *
     * @param mixed $error
     *
     * @return string
     */
    private static function stringifyError($error)
    {
        if (is_scalar($error) || (is_object($error) && method_exists($error, '__toString'))) {
            return (string) $error;
        }

        return sprintf('[unreadable %s error]', is_object($error) ? get_class($error) : gettype($error));
    }
}

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

namespace Bccli\FeatureFlag;

/**
 * Talks to PrestaShop 8.2's real feature flag stack.
 *
 * PrestaShopBundle\Entity\Repository\FeatureFlagRepository (Doctrine) is the
 * same repository core's own back office uses - confirmed by reading
 * PrestaShopBundle\Controller\Admin\Configure\AdvancedParameters\FeatureFlagController
 * and PrestaShopBundle\Form\Admin\AdvancedParameters\FeatureFlagsFormDataProvider,
 * and by fetching it live from a running PrestaShop 8.2.7 container, where it
 * returned the same 14 rows / names the ps_feature_flag table holds. It is
 * registered as a public service, so it can be resolved directly; nothing
 * here reads or writes ps_feature_flag with raw SQL.
 *
 * getByName() returns null rather than throwing on an unknown name, and
 * enable()/disable() silently no-op when the flag does not exist (verified by
 * reading FeatureFlagRepository's own source) - so this class always looks
 * the flag up itself first and turns a miss into UnknownFeatureFlagException,
 * rather than letting an unknown name pass through as a silent no-op.
 *
 * This command has no constructor-injected services (matching every other
 * command in this module, and config/services.yml's plain
 * `{ name: 'console.command' }` tag), so a Symfony kernel/container is
 * resolved lazily in kernel(), the same way core's own
 * PrestaShopBundle\Service\Command\AbstractCommand does it: reuse the
 * `$kernel` global if bin/console or admin/index.php already set one,
 * otherwise boot a fresh AppKernel. That fallback is not just theoretical -
 * this module's own tests/run-integration.php boots PrestaShop through
 * config/config.inc.php directly (deliberately skipping admin/index.php, to
 * avoid smartyfront routing that dies on a broken module - see that file's
 * own comment), which never sets `$kernel` either, so the fallback runs on
 * every integration-test invocation of this class, not just on a
 * from-scratch shop.
 */
class FeatureFlagManager
{
    const ENTITY_CLASS = 'PrestaShopBundle\\Entity\\FeatureFlag';
    const REPOSITORY_SERVICE = 'PrestaShopBundle\\Entity\\Repository\\FeatureFlagRepository';

    /**
     * @throws UnsupportedPlatformException
     */
    public static function assertSupported()
    {
        if (!class_exists(self::ENTITY_CLASS) || !class_exists(self::REPOSITORY_SERVICE)) {
            throw new UnsupportedPlatformException(
                'This PrestaShop build has no ' . self::REPOSITORY_SERVICE . ' - prestashop:feature-flag cannot run here.'
            );
        }
    }

    /**
     * @return array list of array('name' => string, 'state' => bool), sorted by name
     *
     * @throws UnsupportedPlatformException
     */
    public function listFlags()
    {
        self::assertSupported();

        $flags = array();
        foreach ($this->repository()->findAll() as $flag) {
            $flags[] = array('name' => $flag->getName(), 'state' => $flag->isEnabled());
        }

        usort($flags, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        return $flags;
    }

    /**
     * @param string $flagName
     * @param bool $enabled
     *
     * @throws UnsupportedPlatformException
     * @throws UnknownFeatureFlagException
     */
    public function setState($flagName, $enabled)
    {
        self::assertSupported();

        $repository = $this->repository();
        $flag = $repository->getByName($flagName);

        if (null === $flag) {
            throw new UnknownFeatureFlagException($this->unknownFlagMessage($flagName, $repository));
        }

        if ($enabled) {
            $repository->enable($flagName);
        } else {
            $repository->disable($flagName);
        }

        try {
            $this->clearRoutingCache();
        } catch (\Exception $e) {
            throw new \RuntimeException(sprintf(
                'Feature flag "%s" was %s, but clearing the routing cache failed (%s). Legacy routes '
                . 'gated by this flag may not reflect the change until the cache is cleared.',
                $flagName,
                $enabled ? 'enabled' : 'disabled',
                $e->getMessage()
            ), 0, $e);
        }
    }

    /**
     * @param string $flagName
     * @param object $repository FeatureFlagRepository
     *
     * @return string
     */
    private function unknownFlagMessage($flagName, $repository)
    {
        $names = array();
        foreach ($repository->findAll() as $flag) {
            $names[] = $flag->getName();
        }
        sort($names);

        return sprintf(
            'Unknown feature flag "%s". Valid flags are: %s.',
            $flagName,
            implode(', ', $names)
        );
    }

    /**
     * @return object PrestaShopBundle\Entity\Repository\FeatureFlagRepository
     *
     * @throws UnsupportedPlatformException
     */
    private function repository()
    {
        return $this->kernel()->getContainer()->get(self::REPOSITORY_SERVICE);
    }

    /**
     * Returns a booted Symfony kernel, building one if nothing set the
     * `$kernel` global already.
     *
     * bin/console and admin/index.php both do `$kernel = new AppKernel(...)`
     * at top scope before running anything that needs Symfony services, so
     * in a normal cron/CLI or back-office run this only has to reuse that
     * global. Where nothing set it - this module's own
     * tests/run-integration.php included - this falls back to exactly the
     * pattern AbstractCommand::__construct() uses for the same problem:
     * `global $kernel; if (null === $kernel) { require AppKernel.php;
     * $kernel = new AppKernel(...); }`. Kernel::boot() is a no-op once a
     * kernel is already booted (confirmed by reading
     * Symfony\Component\HttpKernel\Kernel::boot(): it returns immediately
     * after an already-booted check, aside from a harmless per-request
     * services reset), so calling it unconditionally here is safe whether
     * $kernel came in already booted or was just constructed above.
     *
     * @return \Symfony\Component\HttpKernel\KernelInterface
     *
     * @throws UnsupportedPlatformException
     */
    private function kernel()
    {
        global $kernel;

        if (!($kernel instanceof \Symfony\Component\HttpKernel\KernelInterface)) {
            if (!defined('_PS_ROOT_DIR_')) {
                throw new UnsupportedPlatformException(
                    '_PS_ROOT_DIR_ is not defined - prestashop:feature-flag cannot run here.'
                );
            }

            if (!class_exists('AppKernel', false)) {
                $appKernelFile = _PS_ROOT_DIR_ . '/app/AppKernel.php';
                if (!is_file($appKernelFile)) {
                    throw new UnsupportedPlatformException(
                        'No app/AppKernel.php found - prestashop:feature-flag cannot run here.'
                    );
                }
                require_once $appKernelFile;
            }

            $kernel = new \AppKernel(
                defined('_PS_ENV_') ? _PS_ENV_ : 'prod',
                defined('_PS_MODE_DEV_') ? _PS_MODE_DEV_ : false
            );
        }

        $kernel->boot();

        return $kernel;
    }

    /**
     * Invalidates the legacy-routes cache that
     * PrestaShopBundle\Routing\Converter\CacheProvider feeds, the same cache
     * core's own FeatureFlagsFormDataProvider::setData() clears after every
     * flag change ("Clear cache of legacy routes since they can depend on an
     * associated feature flag when the attribute `_legacy_feature_flag` is
     * used" - its own comment). Skipping this would be exactly the trap a
     * feature-flag toggle command must not set: the row changes, but a route
     * gated on the old state keeps resolving the old way in production,
     * where RoutingCacheKeyGenerator's key never changes on its own (per its
     * own docblock: "you need to use cache:clear if you need to update the
     * cache").
     *
     * The service that does this narrowly (CacheProvider::clearCache(), via
     * the prestashop.bundle.routing.converter.cache_provider id) is declared
     * private in PrestaShopBundle's own service definitions, and so are its
     * two constructor dependencies - confirmed empirically: fetching any of
     * the three from a live container throws ServiceNotFoundException, not
     * just by reading the yaml. Reimplementing its cache-key algorithm here
     * would require duplicating RoutingCacheKeyGenerator's exact
     * environment-dependent logic and staying in sync with it, so instead
     * this runs the same `cache:clear` console command that core's own
     * PrestaShopBundle\Service\Command\AbstractCommand::addCacheClear() runs
     * for the same "a change needs to be visible immediately" reason
     * elsewhere in core.
     *
     * This clears more than the one routing-cache entry - it is not as
     * surgical as CacheProvider::clearCache() itself - but it is core's own
     * precedented tool for this job, and it was confirmed empirically, not
     * assumed: an item written into the underlying "cache.app" pool (what the
     * private cache alias resolves to - also confirmed via debug:container)
     * was gone after this exact command ran, checked through a freshly booted
     * kernel/container so no in-memory copy could hide a stale on-disk cache.
     *
     * @throws \RuntimeException
     */
    private function clearRoutingCache()
    {
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($this->kernel());
        $application->setAutoExit(false);

        // Symfony's Application::run() catches internal command exceptions
        // and turns them into a non-zero exit code rather than letting them
        // propagate, so the exit code - not a try/catch alone - is what
        // actually detects a failed clear here.
        $exitCode = $application->run(
            new \Symfony\Component\Console\Input\ArrayInput(array(
                'command' => 'cache:clear',
                '--no-warmup' => true,
            )),
            new \Symfony\Component\Console\Output\NullOutput()
        );

        if (0 !== $exitCode) {
            throw new \RuntimeException(sprintf('cache:clear exited with code %d', $exitCode));
        }
    }
}

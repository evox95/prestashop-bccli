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

        // The toggle above is already committed at this point - everything
        // from here on is best-effort cache maintenance: the toggle itself
        // has already succeeded no matter what happens next. See
        // clearRoutingCache()'s docblock for why this is a plain,
        // synchronous, in-process call rather than deferred (as an earlier
        // version of this fix did) - it only ever touches the cache.app PSR-6
        // pool, never the compiled DI container directory, so there is
        // nothing left for Symfony's own post-command lifecycle - or any
        // other concurrently running process sharing the same cache
        // directory - to trip over.
        try {
            $this->clearRoutingCache();
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf(
                'Feature flag "%s" was %s, but the routing cache could not be cleared (%s). '
                . 'Legacy routes gated by this flag may not reflect the change until "php bin/console '
                . 'cache:clear" is run manually.',
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
     * used" - its own comment, read in full on the live shop). Skipping this
     * would be exactly the trap a feature-flag toggle command must not set:
     * the row changes, but a route gated on the old state keeps resolving
     * the old way in production, where RoutingCacheKeyGenerator's key never
     * changes on its own (per its own docblock: "you need to use cache:clear
     * if you need to update the cache").
     *
     * This went through two unsafe attempts before landing here. Both are
     * kept in this docblock because the exact failure each one reproduced is
     * why the final approach looks the way it does:
     *
     * 1. Running the `cache:clear` console command in-process, the way
     *    core's own PrestaShopBundle\Service\Command\AbstractCommand::
     *    addCacheClear() does (read in full while diagnosing this - its
     *    execute() runs every queued command, cache:clear included, through
     *    the same `$this->application->run($input, $output)` call this
     *    class used to make too). Unsafe because
     *    Symfony\Bundle\FrameworkBundle\Command\CacheClearCommand::execute()
     *    (also read in full) ultimately replaces the compiled-container
     *    directory (var/cache/{env}/ContainerXXXX/) the CURRENTLY RUNNING
     *    kernel's container was itself loaded from - warmed into a sibling
     *    directory first and swapped in via rename(), which narrows but does
     *    not close the window for a container that, unlike the freshly
     *    warmed one, is already loaded in THIS process's memory and may
     *    still lazily `require` a not-yet-instantiated private service's
     *    file. Reproduced live on PS 8.2.7 (mking-secscan-ps-1): four
     *    toggles in a row against one warm cache, the fourth failed exactly
     *    this way (`getSwiftmailer_EmailSender_ListenerService.php: Failed
     *    to open stream`) with the DB row already showing the new state.
     *
     * 2. Deleting the compiled-cache directory's contents directly (not via
     *    the command), deferred to PHP's shutdown sequence via
     *    register_shutdown_function() so the deletion could not possibly run
     *    before Symfony's own post-command ConsoleEvents::TERMINATE dispatch
     *    (confirmed as the true trigger: an earlier, non-deferred version of
     *    this same deletion crashed the very FIRST toggle, not just the
     *    fourth, with the identical signature - console.terminate fires
     *    after Command::execute() returns to Symfony's OWN outer
     *    Application, so nothing running inside execute() can ever be
     *    "after" it). Deferring past that boundary did fix the original
     *    reproduction (verified live: four separate-process toggles, each
     *    exit 0 with 0 bytes of stderr). But var/cache/{env}/ is shared,
     *    on-disk state, not scoped to one process - deleting it from this
     *    process's shutdown callback can, and did, corrupt a DIFFERENT,
     *    concurrently running process's own already-booted container.
     *    Reproduced live: a subprocess-based integration test run (four
     *    toggles, each deleting the directory at its own shutdown) crashed
     *    an unrelated test file running later in the SAME outer test-runner
     *    process, with the same require()-failure signature but for a
     *    different lazily-resolved service
     *    (getTranslation_Loader_PhpService.php). That is a realistic
     *    production hazard, not a test artifact: a cron run of this command
     *    can just as easily delete the directory out from under a live
     *    PHP-FPM worker mid-request.
     *
     * The actual fix does not touch the compiled-container directory at
     * all. Core's own FeatureFlagsFormDataProvider reaches
     * PrestaShopBundle\Routing\Converter\CacheProvider::clearCache(), which
     * (read in full) does exactly one thing:
     * `$cache->deleteItem($cacheKeyGenerator->getCacheKey())` on a PSR-6
     * pool. That exact service is private
     * (config/services/bundle/routing.yml:
     * prestashop.bundle.routing.converter.cache_provider has no `public:
     * true`) - confirmed live, fetching it by id throws
     * ServiceNotFoundException - and its cache-key computation is
     * environment-dependent internal logic
     * (RoutingCacheKeyGenerator::getCacheKey(), also read in full) this
     * module has no business reimplementing.
     *
     * A third attempt - Symfony's public, stable "cache_clearer" service
     * (Symfony\Component\HttpKernel\CacheClearer\ChainCacheClearer, tag
     * kernel.cache_clearer) - shipped briefly and was itself wrong: it does
     * NOT reach cache.app. Confirmed two independent ways on PS 8.2.7
     * (mking-secscan-ps-1):
     *
     * - Live probe: seeded a PSR-6 item into cache.app, ran a real toggle
     *   through this command, re-checked the item. HIT before, still HIT
     *   after $container->get('cache_clearer')->clear(...) - the toggle's DB
     *   row changed but the routing cache was never actually invalidated, a
     *   silent failure worse than the crash it replaced. Calling
     *   $container->get('cache.app_clearer')->clear(...) on the same seeded
     *   item produced a miss, as expected.
     * - Source trace, not just the probe: vendor/symfony/symfony/src/
     *   Symfony/Component/Cache/DependencyInjection/CachePoolPass::process()
     *   is what actually assigns the kernel.cache_clearer tag that
     *   "cache_clearer" (id) consumes via a tagged_iterator
     *   (FrameworkBundle/Resources/config/services.xml:74-75). Reading that
     *   pass in full: every cache.pool's `clearer` attribute is resolved and
     *   used to build a private $pools argument for that specific clearer
     *   service (cache.app's clearer="cache.app_clearer",
     *   FrameworkBundle/Resources/config/cache.xml:11), but the
     *   kernel.cache_clearer tag - the one that actually lands a clearer
     *   inside the "cache_clearer" chain - is added to exactly one of those
     *   pool clearers: `if ($this->cacheSystemClearerId === $id) {
     *   $clearer->addTag($this->cacheSystemClearerTag); }`, where
     *   $cacheSystemClearerId defaults to 'cache.system_clearer'
     *   (cache.xml:49,153). cache.app_clearer - an alias of
     *   cache.default_clearer (cache.xml:156) - never receives that tag. So
     *   "cache_clearer" only ever chains cache.system (plus whatever other,
     *   unrelated services explicitly tag themselves kernel.cache_clearer in
     *   their own bundle config, e.g. Twig/annotations caches) - never
     *   cache.app. This is exactly why the crash-fix regression test in
     *   FeatureFlagIntegrationTest.php could not catch it: nothing in this
     *   module ever asserted the cache entry was actually gone, only that
     *   the toggle exited 0 without a crash - "cache_clearer" satisfies that
     *   just as well as a correct clearer would, while silently leaving the
     *   stale routing cache in place.
     *
     * The fix: cache.app_clearer directly (FrameworkBundle/Resources/config/
     * cache.xml:156, public="true", confirmed reachable live). It is the
     * exact clearer CachePoolPass built for the cache.app pool - the same
     * pool CacheProvider is wired to (prestashop.bundle.routing.converter.
     * cache aliases Symfony\Component\Cache\Adapter\AdapterInterface, and
     * cache.app is the concrete FilesystemAdapter behind that alias,
     * confirmed live and by reading routing.yml + cache.xml directly) - so
     * this is a whole-pool sweep of cache.app instead of one targeted key
     * delete, but it reaches the same entry CacheProvider::clearCache()
     * would have deleted by name. Read together with Psr6CacheClearer, the
     * class both cache.app_clearer and cache.system_clearer are instances
     * of: it operates exclusively on PSR-6 pool adapters via ->clear() -
     * never on the compiled-container directory, confirmed live by calling
     * it and observing getCacheDir()'s "ContainerXXXX" entry untouched
     * afterward. A PSR-6 pool is contractually required to degrade a
     * missing/cleared entry to a cache miss and recompute, never a fatal
     * require() failure - unlike the compiled container's generated class
     * files, which is the actual root of both failures in attempt 1 and 2
     * above. That contract is what makes this safe to call synchronously,
     * inline, immediately after the toggle commits, with no
     * register_shutdown_function() deferral needed: verified live at
     * ~0.02s per call, no exception, and safe under the same
     * four-consecutive-toggle reproduction that broke both earlier attempts.
     *
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     * @throws \Throwable anything the clearer itself throws
     */
    private function clearRoutingCache()
    {
        $container = $this->kernel()->getContainer();
        $clearer = $container->get('cache.app_clearer');
        $clearer->clear($this->kernel()->getCacheDir());
    }
}

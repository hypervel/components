<?php

declare(strict_types=1);

namespace Hypervel\Di\Bootstrap;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Di\Aop\AstVisitorRegistry;
use Hypervel\Di\Aop\ProxyCallVisitor;
use Hypervel\Di\Aop\ProxyManager;
use Hypervel\Support\Composer;

/**
 * Generate AOP proxy classes for registered aspects.
 *
 * Runs after all service providers have been registered (so all
 * aspects() calls have executed) and before boot() (so no targeted
 * classes have been instantiated yet). No-ops when no aspects are
 * registered.
 */
class GenerateProxies
{
    /**
     * The source class map used for proxy generation.
     *
     * @var null|array<string, string>
     */
    protected static ?array $sourceClassMap = null;

    /**
     * Bootstrap the AOP proxy generation.
     */
    public function bootstrap(ApplicationContract $app): void
    {
        if (! AspectCollector::hasAspects()) {
            return;
        }

        if (! AstVisitorRegistry::exists(ProxyCallVisitor::class)) {
            AstVisitorRegistry::insert(ProxyCallVisitor::class);
        }

        $proxyDir = $app->storagePath('framework/aop/');
        $classMap = $this->buildClassMap();

        $proxyManager = new ProxyManager($classMap, $proxyDir);

        Composer::getLoader()->addClassMap($proxyManager->getProxies());
    }

    /**
     * Build a class map that includes both Composer's static class map
     * and any PSR-4 classes referenced by exact aspect rules.
     *
     * Composer's getClassMap() only contains explicitly mapped classes,
     * not PSR-4 classes resolved at runtime. For exact class rules
     * (no wildcards), we resolve the file path via findFile() so that
     * PSR-4 classes are eligible for proxying.
     *
     * Wildcard rules only match against the existing class map.
     *
     * @return array<string, string> className => filePath
     */
    protected function buildClassMap(): array
    {
        $loader = Composer::getLoader();
        $classMap = $this->mergeSourceClassMap($loader->getClassMap());

        foreach (AspectCollector::getRules() as $rule) {
            foreach ($rule['classes'] as $classRule) {
                $className = str_contains($classRule, '::')
                    ? explode('::', $classRule)[0]
                    : $classRule;

                if (str_contains($className, '*')) {
                    continue;
                }

                if (! isset($classMap[$className])) {
                    $file = $loader->findFile($className);
                    if ($file !== false && ! str_ends_with($file, '.proxy.php')) {
                        // A flushed source map can leave a proxied PSR-4 class visible only through Composer's proxy entry.
                        static::$sourceClassMap[$className] = $file;
                        $classMap[$className] = $file;
                    }
                }
            }
        }

        return $classMap;
    }

    /**
     * Merge Composer's current class map into the proxy source map.
     *
     * Runtime class-map overrides are legitimate source entries and may be
     * registered after the first application boot. Generated proxy paths are
     * excluded because they point at boot artifacts, not source files.
     *
     * @param array<string, string> $classMap
     * @return array<string, string>
     */
    protected function mergeSourceClassMap(array $classMap): array
    {
        static::$sourceClassMap ??= [];

        foreach ($classMap as $class => $path) {
            if (! str_ends_with($path, '.proxy.php')) {
                static::$sourceClassMap[$class] = $path;
            }
        }

        return static::$sourceClassMap;
    }

    /**
     * Flush the captured source class map.
     *
     * Tests only. This is for tests that swap Composer's loader before
     * proxy generation; do not register it with the global after-test
     * subscriber because normal test cleanup runs after proxy paths have
     * already been added to the loader. Flushing mid-worker loses accumulated
     * findFile() resolutions for already-proxied PSR-4 classes, so those
     * classes are skipped from regeneration until the loader is swapped or the
     * process restarts.
     */
    public static function flushState(): void
    {
        static::$sourceClassMap = null;
    }
}

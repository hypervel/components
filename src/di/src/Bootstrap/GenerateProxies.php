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
        $classMap = $loader->getClassMap();

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
                    if ($file !== false) {
                        $classMap[$className] = $file;
                    }
                }
            }
        }

        return $classMap;
    }
}

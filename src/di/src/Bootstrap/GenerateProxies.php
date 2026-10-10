<?php

declare(strict_types=1);

namespace Hypervel\Di\Bootstrap;

use Composer\Autoload\ClassLoader;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Di\Aop\Aspect;
use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Di\Aop\AstVisitorRegistry;
use Hypervel\Di\Aop\ProxyCallVisitor;
use Hypervel\Di\Aop\ProxyManager;
use Hypervel\Di\Aop\ProxyMarker;
use Hypervel\Di\Aop\ProxyMethod;
use Hypervel\Di\ClassMap\ClassMapManager;
use Hypervel\Di\Exceptions\InvalidDefinitionException;
use Hypervel\Support\Composer;
use ReflectionClass;

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
    protected static ?ClassLoader $proxyLoader = null;

    /**
     * Bootstrap the AOP proxy generation.
     */
    public function bootstrap(ApplicationContract $app): void
    {
        if (AspectCollector::hasAspects()) {
            $this->generate($app->bootstrapPath('cache/aop'));
        }
    }

    /**
     * Generate and register proxies in the given directory before their targets load.
     *
     * Boot or tests only. Loaded classes cannot be replaced in the running process.
     */
    public function generate(string $proxyDir): void
    {
        if (! AspectCollector::hasAspects()) {
            return;
        }

        if (! AstVisitorRegistry::exists(ProxyCallVisitor::class)) {
            AstVisitorRegistry::insert(ProxyCallVisitor::class);
        }

        $classMap = $this->buildClassMap();

        $replacements = ClassMapManager::getEntries();
        $proxyManager = new ProxyManager($classMap, $proxyDir, $replacements);
        $proxies = $proxyManager->getProxies();

        if ($proxies === []) {
            return;
        }

        foreach (array_keys($proxies) as $class) {
            $this->ensureLoadedProxyCoversRules($class);
        }

        $ordinaryProxies = array_diff_key($proxies, $replacements);

        if ($ordinaryProxies !== []) {
            if (static::$proxyLoader === null) {
                static::$proxyLoader = new ClassLoader;
                static::$proxyLoader->setClassMapAuthoritative(true);
                static::$proxyLoader->register(true);
            }

            static::$proxyLoader->addClassMap($ordinaryProxies);
        }

        ClassMapManager::applyProxies($proxies);
    }

    /**
     * Reject already-loaded targets whose code cannot apply the registered aspects.
     */
    protected function ensureLoadedProxyCoversRules(string $class): void
    {
        if (! class_exists($class, false) && ! trait_exists($class, false)) {
            return;
        }

        $reflection = new ReflectionClass($class);

        if (! in_array(ProxyMarker::class, $reflection->getTraitNames(), true)) {
            throw new InvalidDefinitionException(
                "The AOP target [{$class}] was loaded before proxy generation. "
                . 'Bind a factory in register() and instantiate it after proxy generation, during boot() or later.'
            );
        }

        $rewritten = $helpers = [];

        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getAttributes(ProxyMethod::class) as $attribute) {
                $rewritten[$method->getName()] = true;
                $helpers[$attribute->getArguments()[0]] = true;
            }
        }

        $rules = Aspect::parse($class);

        foreach ($reflection->getMethods() as $method) {
            $name = $method->getName();

            if (
                $method->isAbstract()
                || $method->getDeclaringClass()->getName() !== $class
                || $method->getFileName() !== $reflection->getFileName()
                || isset($helpers[$name])
                || isset($rewritten[$name])
                || ! $rules->shouldRewrite($name)
            ) {
                continue;
            }

            throw new InvalidDefinitionException(
                "The loaded AOP proxy [{$class}::{$name}] does not intercept this method. "
                . 'Register all applicable aspects before the class is first loaded and restart the worker after changing rules.'
            );
        }
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

        foreach (ClassMapManager::getEntries() as $class => $path) {
            $classMap[$class] = $path;
        }

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

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        // Only isolated loader tests may unregister this loader. Normal after-test
        // cleanup must retain the framework proxies prepared before test discovery.
        static::$proxyLoader?->unregister();
        static::$proxyLoader = null;
    }
}

<?php

declare(strict_types=1);

namespace Hypervel\Di\Aop;

use Hypervel\Filesystem\Filesystem;

class ProxyManager
{
    /**
     * Source paths used to generate existing proxy files.
     *
     * @var array<string, string> className => sourceFilePath
     */
    protected static array $generatedFrom = [];

    /**
     * The classes that have been rewritten as proxies.
     *
     * @var array<string, string> className => proxyFilePath
     */
    protected array $proxies = [];

    protected Filesystem $filesystem;

    /**
     * @param array<string, string> $classMap Map of class names to their source file paths
     * @param string $proxyDir Directory where proxy files are written
     */
    public function __construct(
        protected array $classMap = [],
        protected string $proxyDir = ''
    ) {
        $this->filesystem = new Filesystem;
        $this->proxies = $this->generateProxyFiles($this->initProxiesByReflectionClassMap(
            $this->classMap
        ));
    }

    /**
     * Get the generated proxy class map.
     *
     * @return array<string, string> className => proxyFilePath
     */
    public function getProxies(): array
    {
        return $this->proxies;
    }

    /**
     * Get the proxy output directory.
     */
    public function getProxyDir(): string
    {
        return $this->proxyDir;
    }

    /**
     * Get the aspect classes grouped by their targeted proxy classes.
     */
    public function getAspectClasses(): array
    {
        $aspectClasses = [];
        $classesAspects = AspectCollector::get('classes', []);
        foreach ($classesAspects as $aspect => $rules) {
            foreach ($rules as $rule) {
                if (isset($this->proxies[$rule])) {
                    $aspectClasses[$aspect][$rule] = $this->proxies[$rule];
                }
            }
        }
        return $aspectClasses;
    }

    /**
     * Generate proxy files for the given classes.
     */
    protected function generateProxyFiles(array $proxies = []): array
    {
        $proxyFiles = [];
        if (! $proxies) {
            return $proxyFiles;
        }
        if (! file_exists($this->getProxyDir())) {
            mkdir($this->getProxyDir(), 0755, true);
        }
        // Ast must not be a static instance — it reads source files which can trigger coroutine switches.
        $ast = new Ast;
        foreach ($proxies as $className => $aspects) {
            $proxyFiles[$className] = $this->putProxyFile($ast, $className);
        }
        return $proxyFiles;
    }

    /**
     * Write or skip a proxy file based on modification time.
     */
    protected function putProxyFile(Ast $ast, string $className): string
    {
        $proxyFilePath = $this->getProxyFilePath($className);
        $sourceFilePath = $this->classMap[$className];
        $modified = true;
        if (file_exists($proxyFilePath)) {
            $modified = $this->isModified($className, $sourceFilePath, $proxyFilePath);
        }

        if ($modified) {
            $code = $ast->proxy($className, $sourceFilePath);
            file_put_contents($proxyFilePath, $code);
        }

        static::$generatedFrom[$className] = $sourceFilePath;

        return $proxyFilePath;
    }

    /**
     * Determine if the source class has been modified since the proxy was generated.
     */
    protected function isModified(string $className, string $sourceFilePath, ?string $proxyFilePath = null): bool
    {
        $proxyFilePath = $proxyFilePath ?? $this->getProxyFilePath($className);
        if (isset(static::$generatedFrom[$className]) && static::$generatedFrom[$className] !== $sourceFilePath) {
            return true;
        }

        $time = $this->filesystem->lastModified($proxyFilePath);
        if ($time >= $this->filesystem->lastModified($sourceFilePath)) {
            return false;
        }

        return true;
    }

    /**
     * Get the proxy file path for a class.
     */
    protected function getProxyFilePath(string $className): string
    {
        return $this->getProxyDir() . str_replace('\\', '_', $className) . '.proxy.php';
    }

    /**
     * Determine if a rule matches a target class name.
     */
    protected function isMatch(string $rule, string $target): bool
    {
        if (str_contains($rule, '::')) {
            [$rule] = explode('::', $rule);
        }
        if (! str_contains($rule, '*') && $rule === $target) {
            return true;
        }
        $preg = str_replace(['*', '\\'], ['.*', '\\\\'], $rule);
        $pattern = "/^{$preg}$/";

        if (preg_match($pattern, $target)) {
            return true;
        }

        return false;
    }

    /**
     * Determine which classes in the class map need proxy generation
     * based on registered aspect class rules.
     */
    protected function initProxiesByReflectionClassMap(array $reflectionClassMap = []): array
    {
        $proxies = [];
        if (! $reflectionClassMap) {
            return $proxies;
        }
        $classesAspects = AspectCollector::get('classes', []);
        foreach ($classesAspects as $aspect => $rules) {
            foreach ($rules as $rule) {
                foreach ($reflectionClassMap as $class => $path) {
                    if (! $this->isMatch($rule, $class)) {
                        continue;
                    }
                    $proxies[$class][] = $aspect;
                }
            }
        }
        return $proxies;
    }

    /**
     * Flush generated proxy source tracking.
     *
     * Tests only. Do not register this with the global after-test subscriber:
     * proxy files can persist in a worker's runtime skeleton between tests,
     * and clearing this map would hide source-path changes behind mtime checks.
     */
    public static function flushState(): void
    {
        static::$generatedFrom = [];
    }
}

<?php

declare(strict_types=1);

namespace Hypervel\Di\Aop;

use Hypervel\Filesystem\Filesystem;

class ProxyManager
{
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
        $this->filesystem = new Filesystem();
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
        $ast = new Ast();
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
        $modified = true;
        if (file_exists($proxyFilePath)) {
            $modified = $this->isModified($className, $proxyFilePath);
        }

        if ($modified) {
            $code = $ast->proxy($className);
            file_put_contents($proxyFilePath, $code);
        }

        return $proxyFilePath;
    }

    /**
     * Determine if the source class has been modified since the proxy was generated.
     */
    protected function isModified(string $className, ?string $proxyFilePath = null): bool
    {
        $proxyFilePath = $proxyFilePath ?? $this->getProxyFilePath($className);
        $time = $this->filesystem->lastModified($proxyFilePath);
        $origin = $this->classMap[$className];
        if ($time >= $this->filesystem->lastModified($origin)) {
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
}

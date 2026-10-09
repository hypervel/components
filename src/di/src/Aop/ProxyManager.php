<?php

declare(strict_types=1);

namespace Hypervel\Di\Aop;

use Composer\InstalledVersions;
use Hypervel\Di\Exceptions\InvalidDefinitionException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\ClassMetadataCache;
use InvalidArgumentException;
use Throwable;

class ProxyManager
{
    private const string FINGERPRINT_HEADER = '// Hypervel AOP fingerprint: ';

    /**
     * The classes that have been rewritten as proxies.
     *
     * @var array<string, string> className => proxyFilePath
     */
    protected array $proxies = [];

    protected Filesystem $filesystem;

    private ?string $commonFingerprint = null;

    /**
     * Generate proxies from the supplied source map.
     *
     * @param array<string, string> $classMap Map of class names to their source file paths
     * @param string $proxyDir Directory where proxy files are written
     * @param array<string, string> $replacements Source overrides whose proxies need separate files
     */
    public function __construct(
        protected array $classMap = [],
        protected string $proxyDir = '',
        protected array $replacements = []
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
     * Generate proxy files for the given classes.
     *
     * @param array<string, true> $proxies
     * @return array<string, string>
     */
    protected function generateProxyFiles(array $proxies = []): array
    {
        if ($proxies === []) {
            return [];
        }

        if ($this->getProxyDir() === '') {
            throw new InvalidArgumentException('The AOP proxy output directory must not be empty.');
        }

        $this->filesystem->ensureDirectoryExists($this->getProxyDir());

        // Ast must not be a static instance — source reads can trigger coroutine switches.
        $ast = new Ast;
        $proxyFiles = [];

        foreach (array_keys($proxies) as $className) {
            $proxyFiles[$className] = $this->putProxyFile($ast, $className);
        }

        return $proxyFiles;
    }

    /**
     * Generate or reuse a content-addressed proxy file.
     */
    protected function putProxyFile(Ast $ast, string $className): string
    {
        [$sourceFilePath, $sourceCode] = $this->readSource($className, $this->classMap[$className]);

        $proxyFilePath = $this->getProxyFilePath($className);
        $fingerprint = $this->fingerprint($className, $sourceFilePath, $sourceCode);

        if (hash_equals($fingerprint, $this->readEmbeddedFingerprint($proxyFilePath) ?? '')) {
            return $proxyFilePath;
        }

        $proxyCode = $ast->proxy($className, $sourceFilePath, $sourceCode);
        $this->filesystem->replace(
            $proxyFilePath,
            $this->embedFingerprint($proxyCode, $fingerprint)
        );

        return $proxyFilePath;
    }

    /**
     * Get the proxy file path for a class.
     */
    protected function getProxyFilePath(string $className): string
    {
        return rtrim($this->getProxyDir(), '/\\')
            . DIRECTORY_SEPARATOR
            . rawurlencode($className)
            . (isset($this->replacements[$className]) ? '.replacement' : '')
            . '.proxy.php';
    }

    /**
     * Determine which classes in the class map need proxy generation.
     *
     * @param array<string, string> $reflectionClassMap
     * @return array<string, true>
     */
    protected function initProxiesByReflectionClassMap(array $reflectionClassMap = []): array
    {
        if ($reflectionClassMap === []) {
            return [];
        }

        $proxies = [];
        $classNames = null;

        foreach (AspectCollector::getClassRules() as $rules) {
            foreach ($rules as $rule) {
                [$classRule] = explode('::', $rule, 2);

                if (! str_contains($classRule, '*')) {
                    if (isset($reflectionClassMap[$classRule])) {
                        $proxies[$classRule] = true;
                    }

                    continue;
                }

                $pattern = str_replace(['*', '\\'], ['.*', '\\\\'], $classRule);

                foreach (preg_grep("/^{$pattern}$/", $classNames ??= array_keys($reflectionClassMap)) as $class) {
                    $proxies[$class] = true;
                }
            }
        }

        return $proxies;
    }

    /**
     * Read one authoritative canonical source file.
     *
     * @return array{string, string}
     */
    private function readSource(string $className, string $sourceFilePath): array
    {
        $canonicalPath = realpath($sourceFilePath);

        if ($canonicalPath === false) {
            throw new InvalidDefinitionException(
                "Unable to generate an AOP proxy for [{$className}]: source file [{$sourceFilePath}] does not exist."
            );
        }

        try {
            return [$canonicalPath, $this->filesystem->get($canonicalPath)];
        } catch (Throwable $exception) {
            throw new InvalidDefinitionException(
                "Unable to generate an AOP proxy for [{$className}]: source file [{$canonicalPath}] could not be read.",
                previous: $exception
            );
        }
    }

    /**
     * Build the complete proxy-generation fingerprint.
     */
    private function fingerprint(string $className, string $sourceFilePath, string $sourceCode): string
    {
        return hash('xxh128', serialize([
            'common' => $this->commonFingerprint ??= $this->buildCommonFingerprint(),
            'class' => $className,
            'source_path' => $sourceFilePath,
            'source_code' => $sourceCode,
        ]));
    }

    /**
     * Fingerprint every worker-stable input shared by generated proxies.
     */
    private function buildCommonFingerprint(): string
    {
        return hash('xxh128', serialize([
            'aspect_rules' => AspectCollector::getRules(),
            'visitors' => $this->visitorFingerprints(),
            'aop_source' => $this->aopSourceFingerprint(),
            'php_parser' => [
                'version' => InstalledVersions::getPrettyVersion('nikic/php-parser'),
                'reference' => InstalledVersions::getReference('nikic/php-parser'),
            ],
            'php_version_id' => PHP_VERSION_ID,
        ]));
    }

    /**
     * Fingerprint visitors in their effective traversal order.
     *
     * @return array<int, array{class: string, path: string, content: string}>
     */
    private function visitorFingerprints(): array
    {
        $fingerprints = [];

        foreach (AstVisitorRegistry::getQueue()->toArray() as $visitor) {
            $reflection = ClassMetadataCache::reflectClass($visitor);
            $path = $reflection->getFileName();

            if ($path === false || ($path = realpath($path)) === false) {
                throw new InvalidDefinitionException(
                    "Unable to fingerprint AOP visitor [{$visitor}]: its source file is unavailable."
                );
            }

            $fingerprints[] = [
                'class' => $visitor,
                'path' => $path,
                'content' => $this->filesystem->get($path),
            ];
        }

        return $fingerprints;
    }

    /**
     * Fingerprint the framework-owned AOP generator implementation.
     */
    private function aopSourceFingerprint(): string
    {
        $sources = [];

        foreach (glob(__DIR__ . '/*.php') as $path) {
            $sources[basename($path)] = $this->filesystem->get($path);
        }

        return hash('xxh128', serialize($sources));
    }

    /**
     * Read only the fingerprint header from an existing proxy.
     */
    private function readEmbeddedFingerprint(string $proxyFilePath): ?string
    {
        $handle = @fopen($proxyFilePath, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $openingTag = fgets($handle);
            $header = fgets($handle);
        } finally {
            fclose($handle);
        }

        if ($openingTag !== "<?php\n" || ! is_string($header)) {
            return null;
        }

        $fingerprint = trim(substr($header, strlen(self::FINGERPRINT_HEADER)));

        return str_starts_with($header, self::FINGERPRINT_HEADER)
            && preg_match('/^[a-f0-9]{32}$/D', $fingerprint) === 1
                ? $fingerprint
                : null;
    }

    /**
     * Embed a fingerprint header in generated PHP code.
     */
    private function embedFingerprint(string $proxyCode, string $fingerprint): string
    {
        if (! str_starts_with($proxyCode, "<?php\n")) {
            throw new InvalidDefinitionException('The generated AOP proxy does not begin with a PHP opening tag.');
        }

        return "<?php\n"
            . self::FINGERPRINT_HEADER
            . $fingerprint
            . "\n"
            . substr($proxyCode, strlen("<?php\n"));
    }
}

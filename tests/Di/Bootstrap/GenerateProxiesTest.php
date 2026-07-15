<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Bootstrap;

use Composer\Autoload\ClassLoader;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Di\Aop\AstVisitorRegistry;
use Hypervel\Di\Aop\ProxyCallVisitor;
use Hypervel\Di\Aop\VisitorMetadata;
use Hypervel\Di\Bootstrap\GenerateProxies;
use Hypervel\Di\Exceptions\InvalidDefinitionException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Composer;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PhpParser\NodeVisitorAbstract;
use ReflectionMethod;
use Throwable;

class GenerateProxiesTest extends TestCase
{
    private ClassLoader $originalLoader;

    private ClassLoader $loader;

    private Filesystem $filesystem;

    private string $tempDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->tempDirectory = ParallelTesting::tempDir('GenerateProxiesTest');
        $this->filesystem->deleteDirectory($this->tempDirectory);
        $this->filesystem->ensureDirectoryExists($this->tempDirectory);

        $this->originalLoader = Composer::getLoader();
        $this->loader = new ClassLoader;
        $this->loader->register();
        Composer::setLoader($this->loader);
    }

    protected function tearDown(): void
    {
        $this->loader->unregister();
        Composer::setLoader($this->originalLoader);
        GenerateProxies::flushState();
        $this->filesystem->deleteDirectory($this->tempDirectory);

        parent::tearDown();
    }

    public function testNoOpsWhenNoAspectsRegistered(): void
    {
        $app = m::mock(ApplicationContract::class);
        $app->shouldNotReceive('storagePath');

        (new GenerateProxies)->bootstrap($app);

        $this->assertFalse(AstVisitorRegistry::exists(ProxyCallVisitor::class));
    }

    public function testRegistersProxyCallVisitorWhenAspectsExist(): void
    {
        AspectCollector::setAround('SomeAspect', ['SomeNonExistentClass']);

        $this->bootstrapProxies($this->tempDirectory . '/aop');

        $this->assertTrue(AstVisitorRegistry::exists(ProxyCallVisitor::class));
    }

    public function testBuildClassMapResolvesPsr4ClassesViaFindFile(): void
    {
        $testClass = Composer::class;
        $this->loader->addPsr4('Hypervel\Support\\', [__DIR__ . '/../../../src/support/src/']);

        $this->assertArrayNotHasKey($testClass, $this->loader->getClassMap());
        $this->assertNotFalse($this->loader->findFile($testClass));

        AspectCollector::setAround('TestAspect', [$testClass . '::getLoader']);

        $classMap = $this->buildClassMap();

        $this->assertArrayHasKey($testClass, $classMap);
        $this->assertStringContainsString('Composer.php', $classMap[$testClass]);
    }

    public function testBuildClassMapSkipsWildcardRules(): void
    {
        $this->loader->addClassMap(['Existing\ClassName' => '/tmp/existing.php']);
        AspectCollector::setAround('TestAspect', ['App\Services\*']);

        $this->assertSame($this->loader->getClassMap(), $this->buildClassMap());
    }

    public function testBuildClassMapDoesNotDuplicateExistingEntries(): void
    {
        $this->loader->addClassMap(['Existing\ClassName' => '/tmp/existing.php']);
        AspectCollector::setAround('TestAspect', ['Existing\ClassName::method']);

        $this->assertSame('/tmp/existing.php', $this->buildClassMap()['Existing\ClassName']);
    }

    public function testBuildClassMapExtractsClassNameFromMethodRule(): void
    {
        $this->loader->addClassMap([Composer::class => __DIR__ . '/../../../src/support/src/Composer.php']);
        AspectCollector::setAround('TestAspect', [Composer::class . '::getLoader']);

        $this->assertArrayHasKey(Composer::class, $this->buildClassMap());
    }

    public function testBuildClassMapHandlesClassRuleWithoutMethod(): void
    {
        $this->loader->addClassMap([Composer::class => __DIR__ . '/../../../src/support/src/Composer.php']);
        AspectCollector::setAround('TestAspect', [Composer::class]);

        $this->assertArrayHasKey(Composer::class, $this->buildClassMap());
    }

    public function testBuildClassMapSkipsUnresolvableClasses(): void
    {
        AspectCollector::setAround('TestAspect', ['Totally\NonExistent\Class123::method']);

        $this->assertArrayNotHasKey('Totally\NonExistent\Class123', $this->buildClassMap());
    }

    public function testDoesNotRegisterProxyCallVisitorTwice(): void
    {
        AspectCollector::setAround('SomeAspect', ['SomeNonExistentClass']);
        AstVisitorRegistry::insert(ProxyCallVisitor::class);

        $this->bootstrapProxies($this->tempDirectory . '/aop');

        $queue = clone AstVisitorRegistry::getQueue();
        $count = 0;

        foreach ($queue as $item) {
            if ($item === ProxyCallVisitor::class) {
                ++$count;
            }
        }

        $this->assertSame(1, $count);
    }

    public function testRegeneratesDeletedProxyFilesFromTheCapturedSourceMap(): void
    {
        $this->withProxyFixture(function (string $className, string $sourceFile, string $overrideFile, string $proxyDir): void {
            AspectCollector::setAround('TestAspect', [$className . '::value']);

            $this->bootstrapProxies($proxyDir);

            $proxyFile = $this->loader->getClassMap()[$className];
            $this->assertFileExists($proxyFile);

            $this->filesystem->deleteDirectory($proxyDir);
            $this->bootstrapProxies($proxyDir);

            $this->assertFileExists($proxyFile);
            $this->assertStringContainsString('original-source', $this->filesystem->get($proxyFile));
        });
    }

    public function testSkipsProxyPathsReturnedByFindFileAfterTheSourceMapIsFlushed(): void
    {
        $this->withPsr4ProxyFixture(function (string $className, string $proxyDir): void {
            AspectCollector::setAround('TestAspect', [$className . '::value']);

            $this->bootstrapProxies($proxyDir);

            $proxyFile = $this->loader->getClassMap()[$className];
            $this->assertFileExists($proxyFile);

            GenerateProxies::flushState();

            $this->assertArrayNotHasKey($className, $this->buildClassMap());

            $this->filesystem->deleteDirectory($proxyDir);
            $this->bootstrapProxies($proxyDir);

            $this->assertFileDoesNotExist($proxyFile);
        });
    }

    public function testRegeneratesProxyWhenSourceChangesWithoutAnMtimeChange(): void
    {
        $this->withProxyFixture(function (string $className, string $sourceFile, string $overrideFile, string $proxyDir): void {
            AspectCollector::setAround('TestAspect', [$className . '::value']);

            $this->bootstrapProxies($proxyDir);
            $sourceMtime = filemtime($sourceFile);

            $this->writeProxySource($sourceFile, $className, 'same-mtime-source');
            touch($sourceFile, $sourceMtime);
            $this->bootstrapProxies($proxyDir);

            $proxyFile = $this->loader->getClassMap()[$className];
            $this->assertStringContainsString('same-mtime-source', $this->filesystem->get($proxyFile));
        });
    }

    public function testRegeneratesProxyWhenSourcePathChanges(): void
    {
        $this->withProxyFixture(function (string $className, string $sourceFile, string $overrideFile, string $proxyDir): void {
            AspectCollector::setAround('TestAspect', [$className . '::value']);

            $this->bootstrapProxies($proxyDir);
            $this->writeProxySource($overrideFile, $className, 'override-source');
            touch($overrideFile, (int) filemtime($sourceFile) - 100);
            $this->loader->addClassMap([$className => $overrideFile]);

            $this->bootstrapProxies($proxyDir);

            $proxyFile = $this->loader->getClassMap()[$className];
            $this->assertStringContainsString('override-source', $this->filesystem->get($proxyFile));
        });
    }

    public function testRegeneratesProxyWhenAspectRulesChange(): void
    {
        $this->withProxyFixture(function (string $className, string $sourceFile, string $overrideFile, string $proxyDir): void {
            AspectCollector::setAround('FirstAspect', [$className . '::value']);

            $this->bootstrapProxies($proxyDir);
            $proxyFile = $this->loader->getClassMap()[$className];
            $first = $this->filesystem->get($proxyFile);

            AspectCollector::setAround('SecondAspect', [$className . '::value']);
            $this->bootstrapProxies($proxyDir);

            $this->assertNotSame($first, $this->filesystem->get($proxyFile));
        });
    }

    public function testRegeneratesProxyWhenVisitorOrderChanges(): void
    {
        $this->withProxyFixture(function (string $className, string $sourceFile, string $overrideFile, string $proxyDir): void {
            AspectCollector::setAround('TestAspect', [$className . '::value']);
            AstVisitorRegistry::insert(FingerprintVisitorOne::class, 20);
            AstVisitorRegistry::insert(FingerprintVisitorTwo::class, 10);

            $this->bootstrapProxies($proxyDir);
            $proxyFile = $this->loader->getClassMap()[$className];
            $first = $this->filesystem->get($proxyFile);

            AstVisitorRegistry::flushState();
            AstVisitorRegistry::insert(FingerprintVisitorOne::class, 10);
            AstVisitorRegistry::insert(FingerprintVisitorTwo::class, 20);
            $this->bootstrapProxies($proxyDir);

            $this->assertNotSame($first, $this->filesystem->get($proxyFile));
        });
    }

    public function testUsesCollisionFreeEncodedProxyFilenames(): void
    {
        $proxyDir = $this->tempDirectory . '/aop';
        $firstClass = 'Hypervel\Tests\Di\Bootstrap\Fixtures\Encoded\One_Two';
        $secondClass = 'Hypervel\Tests\Di\Bootstrap\Fixtures\Encoded_One\Two';
        $firstSource = $this->tempDirectory . '/One_Two.php';
        $secondSource = $this->tempDirectory . '/Two.php';

        $this->writeProxySource($firstSource, $firstClass, 'first');
        $this->writeProxySource($secondSource, $secondClass, 'second');
        $this->loader->addClassMap([
            $firstClass => $firstSource,
            $secondClass => $secondSource,
        ]);
        AspectCollector::setAround('TestAspect', [$firstClass, $secondClass]);

        $this->bootstrapProxies($proxyDir);

        $firstProxy = $this->loader->getClassMap()[$firstClass];
        $secondProxy = $this->loader->getClassMap()[$secondClass];
        $this->assertNotSame($firstProxy, $secondProxy);
        $this->assertSame(rawurlencode($firstClass) . '.proxy.php', basename($firstProxy));
        $this->assertSame(rawurlencode($secondClass) . '.proxy.php', basename($secondProxy));
        $this->assertFileExists($firstProxy);
        $this->assertFileExists($secondProxy);
    }

    public function testReusesAProxyFromItsFingerprintHeaderWithoutParsingItsBody(): void
    {
        $this->withProxyFixture(function (string $className, string $sourceFile, string $overrideFile, string $proxyDir): void {
            AspectCollector::setAround('TestAspect', [$className . '::value']);
            $this->bootstrapProxies($proxyDir);

            $proxyFile = $this->loader->getClassMap()[$className];
            $lines = file($proxyFile);
            $this->assertIsArray($lines);
            $cachedBody = $lines[0] . $lines[1] . "cached-body-is-not-parsed\n";
            $this->filesystem->put($proxyFile, $cachedBody);

            $this->bootstrapProxies($proxyDir);

            $this->assertSame($cachedBody, $this->filesystem->get($proxyFile));
        });
    }

    public function testGenerationFailureDoesNotPublishAnyProxyClassMapEntries(): void
    {
        $validClass = 'Hypervel\Tests\Di\Bootstrap\Fixtures\ValidProxySource';
        $invalidClass = 'Hypervel\Tests\Di\Bootstrap\Fixtures\InvalidProxySource';
        $validSource = $this->tempDirectory . '/ValidProxySource.php';
        $invalidSource = $this->tempDirectory . '/InvalidProxySource.php';
        $proxyDir = $this->tempDirectory . '/aop';
        $this->writeProxySource($validSource, $validClass, 'valid-source');
        $this->filesystem->put($invalidSource, <<<'PHP'
<?php

namespace Hypervel\Tests\Di\Bootstrap\Fixtures;

class InvalidProxySource {}
class ExtraClass {}
PHP);
        $this->loader->addClassMap([
            $validClass => $validSource,
            $invalidClass => $invalidSource,
        ]);
        AspectCollector::setAround('TestAspect', [$validClass, $invalidClass]);

        $exception = null;

        try {
            $this->bootstrapProxies($proxyDir);
        } catch (Throwable $throwable) {
            $exception = $throwable;
        }

        $this->assertInstanceOf(InvalidDefinitionException::class, $exception);
        $this->assertSame($validSource, $this->loader->getClassMap()[$validClass]);
        $this->assertSame($invalidSource, $this->loader->getClassMap()[$invalidClass]);
        $this->assertFileDoesNotExist(
            $proxyDir . DIRECTORY_SEPARATOR . rawurlencode($invalidClass) . '.proxy.php'
        );
    }

    /**
     * Build the source class map through the bootstrapper's protected boundary.
     *
     * @return array<string, string>
     */
    private function buildClassMap(): array
    {
        return (new ReflectionMethod(GenerateProxies::class, 'buildClassMap'))->invoke(new GenerateProxies);
    }

    /**
     * Run a callback with a controlled class-map proxy fixture.
     */
    private function withProxyFixture(callable $callback): void
    {
        $sourceDirectory = $this->tempDirectory . '/src';
        $proxyDir = $this->tempDirectory . '/aop';
        $className = 'Hypervel\Tests\Di\Bootstrap\Fixtures\AopProxySource' . bin2hex(random_bytes(4));
        $sourceFile = $sourceDirectory . '/AopProxySource.php';
        $overrideFile = $sourceDirectory . '/AopProxySourceOverride.php';

        $this->filesystem->ensureDirectoryExists($sourceDirectory);
        $this->writeProxySource($sourceFile, $className, 'original-source');
        $this->loader->addClassMap([$className => $sourceFile]);

        $callback($className, $sourceFile, $overrideFile, $proxyDir);
    }

    /**
     * Run a callback with a PSR-4-only proxy fixture.
     */
    private function withPsr4ProxyFixture(callable $callback): void
    {
        $sourceDirectory = $this->tempDirectory . '/src/';
        $proxyDir = $this->tempDirectory . '/aop';
        $shortName = 'AopProxyPsr4Source' . bin2hex(random_bytes(4));
        $className = 'Hypervel\Tests\Di\Bootstrap\Fixtures\\' . $shortName;
        $sourceFile = $sourceDirectory . $shortName . '.php';

        $this->filesystem->ensureDirectoryExists($sourceDirectory);
        $this->writeProxySource($sourceFile, $className, 'psr4-source');
        $this->loader->addPsr4('Hypervel\Tests\Di\Bootstrap\Fixtures\\', [$sourceDirectory]);

        $callback($className, $proxyDir);
    }

    /**
     * Bootstrap proxy generation against the given proxy directory.
     */
    private function bootstrapProxies(string $proxyDir): void
    {
        $app = m::mock(ApplicationContract::class);
        $app->shouldReceive('storagePath')
            ->with('framework/aop/')
            ->andReturn($proxyDir);

        (new GenerateProxies)->bootstrap($app);
    }

    /**
     * Write a source file used for proxy generation.
     */
    private function writeProxySource(string $file, string $className, string $marker): void
    {
        $lastSeparator = strrpos($className, '\\');
        $namespace = substr($className, 0, $lastSeparator);
        $shortName = substr($className, $lastSeparator + 1);

        $this->filesystem->put($file, <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

class {$shortName}
{
    public function value(): string
    {
        return '{$marker}';
    }
}
PHP);
    }
}

class FingerprintVisitorOne extends NodeVisitorAbstract
{
    public function __construct(VisitorMetadata $visitorMetadata)
    {
    }
}

class FingerprintVisitorTwo extends NodeVisitorAbstract
{
    public function __construct(VisitorMetadata $visitorMetadata)
    {
    }
}

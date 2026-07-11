<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Bootstrap;

use Composer\Autoload\ClassLoader;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Di\Aop\AstVisitorRegistry;
use Hypervel\Di\Aop\ProxyCallVisitor;
use Hypervel\Di\Aop\ProxyManager;
use Hypervel\Di\Bootstrap\GenerateProxies;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Composer;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionMethod;

class GenerateProxiesTest extends TestCase
{
    private ?ClassLoader $originalLoader = null;

    private ?ClassLoader $registeredLoader = null;

    protected function tearDown(): void
    {
        if ($this->registeredLoader !== null) {
            $this->registeredLoader->unregister();
            $this->registeredLoader = null;
        }

        if ($this->originalLoader !== null) {
            Composer::setLoader($this->originalLoader);
            $this->originalLoader = null;
        }

        GenerateProxies::flushState();
        ProxyManager::flushState();

        parent::tearDown();
    }

    public function testNoOpsWhenNoAspectsRegistered()
    {
        $app = m::mock(\Hypervel\Contracts\Foundation\Application::class);
        // storagePath should NOT be called
        $app->shouldNotReceive('storagePath');

        $bootstrapper = new GenerateProxies;
        $bootstrapper->bootstrap($app);

        $this->assertFalse(AstVisitorRegistry::exists(ProxyCallVisitor::class));
    }

    public function testRegistersProxyCallVisitorWhenAspectsExist()
    {
        AspectCollector::setAround('SomeAspect', ['SomeNonExistentClass']);

        $app = m::mock(\Hypervel\Contracts\Foundation\Application::class);
        $app->shouldReceive('storagePath')
            ->with('framework/aop/')
            ->andReturn(sys_get_temp_dir() . '/hypervel-test-aop-' . uniqid() . '/');

        $bootstrapper = new GenerateProxies;
        $bootstrapper->bootstrap($app);

        $this->assertTrue(AstVisitorRegistry::exists(ProxyCallVisitor::class));
    }

    public function testBuildClassMapResolvesPsr4ClassesViaFindFile()
    {
        // Use a controlled ClassLoader with an empty class map but a PSR-4
        // prefix that can resolve a known class. This avoids dependency on
        // whether the real autoloader is optimized or not.
        $testClass = 'Hypervel\Support\Composer';

        $loader = new ClassLoader;
        // No class map entries — simulates a non-optimized autoloader
        $loader->addPsr4('Hypervel\Support\\', [__DIR__ . '/../../../src/support/src/']);
        $this->registerLoader($loader);

        $this->originalLoader = Composer::setLoader($loader);

        $this->assertArrayNotHasKey($testClass, $loader->getClassMap());
        $this->assertNotFalse($loader->findFile($testClass));

        AspectCollector::setAround('TestAspect', [$testClass . '::getLoader']);

        $bootstrapper = new GenerateProxies;
        $reflection = new ReflectionMethod($bootstrapper, 'buildClassMap');

        $classMap = $reflection->invoke($bootstrapper);

        $this->assertArrayHasKey($testClass, $classMap);
        $this->assertStringContainsString('Composer.php', $classMap[$testClass]);
    }

    public function testBuildClassMapSkipsWildcardRules()
    {
        AspectCollector::setAround('TestAspect', ['App\Services\*']);

        $bootstrapper = new GenerateProxies;
        $reflection = new ReflectionMethod($bootstrapper, 'buildClassMap');

        $classMap = $reflection->invoke($bootstrapper);

        // Wildcard rules should not add any new entries beyond what's already in the class map
        $this->assertSame(
            Composer::getLoader()->getClassMap(),
            $classMap
        );
    }

    public function testBuildClassMapDoesNotDuplicateExistingEntries()
    {
        $loader = Composer::getLoader();
        $existingMap = $loader->getClassMap();

        if (empty($existingMap)) {
            $this->markTestSkipped('No classes in composer class map');
        }

        // Pick a class that's already in the class map
        $existingClass = array_key_first($existingMap);
        $existingPath = $existingMap[$existingClass];

        AspectCollector::setAround('TestAspect', [$existingClass . '::method']);

        $bootstrapper = new GenerateProxies;
        $reflection = new ReflectionMethod($bootstrapper, 'buildClassMap');

        $classMap = $reflection->invoke($bootstrapper);

        // Should use the existing entry, not override it
        $this->assertSame($existingPath, $classMap[$existingClass]);
    }

    public function testBuildClassMapExtractsClassNameFromMethodRule()
    {
        $testClass = Composer::class;

        AspectCollector::setAround('TestAspect', [$testClass . '::getLoader']);

        $bootstrapper = new GenerateProxies;
        $reflection = new ReflectionMethod($bootstrapper, 'buildClassMap');

        $classMap = $reflection->invoke($bootstrapper);

        $this->assertArrayHasKey($testClass, $classMap);
    }

    public function testBuildClassMapHandlesClassRuleWithoutMethod()
    {
        $testClass = Composer::class;

        AspectCollector::setAround('TestAspect', [$testClass]);

        $bootstrapper = new GenerateProxies;
        $reflection = new ReflectionMethod($bootstrapper, 'buildClassMap');

        $classMap = $reflection->invoke($bootstrapper);

        $this->assertArrayHasKey($testClass, $classMap);
    }

    public function testBuildClassMapSkipsUnresolvableClasses()
    {
        AspectCollector::setAround('TestAspect', ['Totally\NonExistent\Class123::method']);

        $bootstrapper = new GenerateProxies;
        $reflection = new ReflectionMethod($bootstrapper, 'buildClassMap');

        $classMap = $reflection->invoke($bootstrapper);

        $this->assertArrayNotHasKey('Totally\NonExistent\Class123', $classMap);
    }

    public function testDoesNotRegisterProxyCallVisitorTwice()
    {
        AspectCollector::setAround('SomeAspect', ['SomeNonExistentClass']);

        // Pre-register the visitor
        AstVisitorRegistry::insert(ProxyCallVisitor::class);

        $app = m::mock(\Hypervel\Contracts\Foundation\Application::class);
        $app->shouldReceive('storagePath')
            ->with('framework/aop/')
            ->andReturn(sys_get_temp_dir() . '/hypervel-test-aop-' . uniqid() . '/');

        $bootstrapper = new GenerateProxies;
        $bootstrapper->bootstrap($app);

        // Count how many times the visitor appears
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
        $this->withProxyFixture(function (string $className, string $sourceFile, string $overrideFile, string $proxyDir, ClassLoader $loader): void {
            AspectCollector::setAround('TestAspect', [$className . '::value']);

            $this->bootstrapProxies($proxyDir);

            $proxyFile = $loader->getClassMap()[$className];
            $this->assertFileExists($proxyFile);

            (new Filesystem)->deleteDirectory($proxyDir);

            $this->bootstrapProxies($proxyDir);

            $this->assertFileExists($proxyFile);
            $this->assertStringContainsString('original-source', (string) file_get_contents($proxyFile));
        });
    }

    public function testSkipsProxyPathsReturnedByFindFileAfterTheSourceMapIsFlushed(): void
    {
        $this->withPsr4ProxyFixture(function (string $className, string $proxyDir, ClassLoader $loader): void {
            AspectCollector::setAround('TestAspect', [$className . '::value']);

            $this->bootstrapProxies($proxyDir);

            $proxyFile = $loader->getClassMap()[$className];
            $this->assertFileExists($proxyFile);

            GenerateProxies::flushState();
            ProxyManager::flushState();

            $bootstrapper = new GenerateProxies;
            $reflection = new ReflectionMethod($bootstrapper, 'buildClassMap');

            $classMap = $reflection->invoke($bootstrapper);

            $this->assertArrayNotHasKey($className, $classMap);

            (new Filesystem)->deleteDirectory($proxyDir);

            $this->bootstrapProxies($proxyDir);

            $this->assertFileDoesNotExist($proxyFile);
        });
    }

    public function testRegeneratesProxyFilesWhenTheSourceFileIsNewer(): void
    {
        $this->withProxyFixture(function (string $className, string $sourceFile, string $overrideFile, string $proxyDir, ClassLoader $loader): void {
            AspectCollector::setAround('TestAspect', [$className . '::value']);

            $this->bootstrapProxies($proxyDir);

            $proxyFile = $loader->getClassMap()[$className];
            $this->writeProxySource($sourceFile, $className, 'newer-source');
            touch($sourceFile, filemtime($proxyFile) + 2);

            $this->bootstrapProxies($proxyDir);

            $this->assertStringContainsString('newer-source', (string) file_get_contents($proxyFile));
        });
    }

    public function testRegeneratesProxyFilesWhenTheSourcePathChanges(): void
    {
        $this->withProxyFixture(function (string $className, string $sourceFile, string $overrideFile, string $proxyDir, ClassLoader $loader): void {
            AspectCollector::setAround('TestAspect', [$className . '::value']);

            $this->bootstrapProxies($proxyDir);

            $proxyFile = $loader->getClassMap()[$className];
            $this->writeProxySource($overrideFile, $className, 'override-source');
            touch($overrideFile, filemtime($proxyFile) - 100);

            $loader->addClassMap([$className => $overrideFile]);

            $this->bootstrapProxies($proxyDir);

            $this->assertStringContainsString('override-source', (string) file_get_contents($proxyFile));
        });
    }

    /**
     * Run a callback with a controlled proxy fixture.
     */
    private function withProxyFixture(callable $callback): void
    {
        $filesystem = new Filesystem;
        $directory = sys_get_temp_dir() . '/hypervel-test-aop-' . getmypid() . '-' . bin2hex(random_bytes(6));
        $sourceDirectory = $directory . '/src';
        $proxyDir = $directory . '/aop/';
        $className = 'Hypervel\Tests\Di\Bootstrap\Fixtures\AopProxySource' . bin2hex(random_bytes(4));
        $sourceFile = $sourceDirectory . '/AopProxySource.php';
        $overrideFile = $sourceDirectory . '/AopProxySourceOverride.php';

        $filesystem->ensureDirectoryExists($sourceDirectory);
        $this->writeProxySource($sourceFile, $className, 'original-source');

        $loader = new ClassLoader;
        $loader->addClassMap([$className => $sourceFile]);
        $this->registerLoader($loader);

        $this->originalLoader = Composer::setLoader($loader);
        GenerateProxies::flushState();
        ProxyManager::flushState();

        try {
            $callback($className, $sourceFile, $overrideFile, $proxyDir, $loader);
        } finally {
            $filesystem->deleteDirectory($directory);
        }
    }

    /**
     * Run a callback with a PSR-4-only proxy fixture.
     */
    private function withPsr4ProxyFixture(callable $callback): void
    {
        $filesystem = new Filesystem;
        $directory = sys_get_temp_dir() . '/hypervel-test-aop-' . getmypid() . '-' . bin2hex(random_bytes(6));
        $sourceDirectory = $directory . '/src/';
        $proxyDir = $directory . '/aop/';
        $shortName = 'AopProxyPsr4Source' . bin2hex(random_bytes(4));
        $className = 'Hypervel\Tests\Di\Bootstrap\Fixtures\\' . $shortName;
        $sourceFile = $sourceDirectory . $shortName . '.php';

        $filesystem->ensureDirectoryExists($sourceDirectory);
        $this->writeProxySource($sourceFile, $className, 'psr4-source');

        $loader = new ClassLoader;
        $loader->addPsr4('Hypervel\Tests\Di\Bootstrap\Fixtures\\', [$sourceDirectory]);
        $this->registerLoader($loader);

        $this->originalLoader = Composer::setLoader($loader);
        GenerateProxies::flushState();
        ProxyManager::flushState();

        try {
            $callback($className, $proxyDir, $loader);
        } finally {
            $filesystem->deleteDirectory($directory);
        }
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
     * Register a controlled Composer loader.
     */
    private function registerLoader(ClassLoader $loader): void
    {
        $loader->register();
        $this->registeredLoader = $loader;
    }

    /**
     * Write a source file used for proxy generation.
     */
    private function writeProxySource(string $file, string $className, string $marker): void
    {
        $lastSeparator = strrpos($className, '\\');
        $namespace = substr($className, 0, $lastSeparator);
        $shortName = substr($className, $lastSeparator + 1);

        file_put_contents($file, <<<PHP
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

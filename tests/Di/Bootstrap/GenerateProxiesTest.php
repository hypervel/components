<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Bootstrap;

use Composer\Autoload\ClassLoader;
use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Di\Aop\AstVisitorRegistry;
use Hypervel\Di\Aop\ProxyCallVisitor;
use Hypervel\Di\Bootstrap\GenerateProxies;
use Hypervel\Support\Composer;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionMethod;

/**
 * @internal
 * @coversNothing
 */
class GenerateProxiesTest extends TestCase
{
    private ?ClassLoader $originalLoader = null;

    protected function tearDown(): void
    {
        if ($this->originalLoader !== null) {
            Composer::setLoader($this->originalLoader);
            $this->originalLoader = null;
        }

        parent::tearDown();
    }

    public function testNoOpsWhenNoAspectsRegistered()
    {
        $app = m::mock(\Hypervel\Contracts\Foundation\Application::class);
        // storagePath should NOT be called
        $app->shouldNotReceive('storagePath');

        $bootstrapper = new GenerateProxies();
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

        $bootstrapper = new GenerateProxies();
        $bootstrapper->bootstrap($app);

        $this->assertTrue(AstVisitorRegistry::exists(ProxyCallVisitor::class));
    }

    public function testBuildClassMapResolvesPsr4ClassesViaFindFile()
    {
        // Use a controlled ClassLoader with an empty class map but a PSR-4
        // prefix that can resolve a known class. This avoids dependency on
        // whether the real autoloader is optimized or not.
        $testClass = 'Hypervel\Support\Composer';

        $loader = new ClassLoader();
        // No class map entries — simulates a non-optimized autoloader
        $loader->addPsr4('Hypervel\Support\\', [__DIR__ . '/../../../src/support/src/']);
        $loader->register();

        $this->originalLoader = Composer::setLoader($loader);

        $this->assertArrayNotHasKey($testClass, $loader->getClassMap());
        $this->assertNotFalse($loader->findFile($testClass));

        AspectCollector::setAround('TestAspect', [$testClass . '::getLoader']);

        $bootstrapper = new GenerateProxies();
        $reflection = new ReflectionMethod($bootstrapper, 'buildClassMap');

        $classMap = $reflection->invoke($bootstrapper);

        $this->assertArrayHasKey($testClass, $classMap);
        $this->assertStringContainsString('Composer.php', $classMap[$testClass]);
    }

    public function testBuildClassMapSkipsWildcardRules()
    {
        AspectCollector::setAround('TestAspect', ['App\Services\*']);

        $bootstrapper = new GenerateProxies();
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

        $bootstrapper = new GenerateProxies();
        $reflection = new ReflectionMethod($bootstrapper, 'buildClassMap');

        $classMap = $reflection->invoke($bootstrapper);

        // Should use the existing entry, not override it
        $this->assertSame($existingPath, $classMap[$existingClass]);
    }

    public function testBuildClassMapExtractsClassNameFromMethodRule()
    {
        $testClass = Composer::class;

        AspectCollector::setAround('TestAspect', [$testClass . '::getLoader']);

        $bootstrapper = new GenerateProxies();
        $reflection = new ReflectionMethod($bootstrapper, 'buildClassMap');

        $classMap = $reflection->invoke($bootstrapper);

        $this->assertArrayHasKey($testClass, $classMap);
    }

    public function testBuildClassMapHandlesClassRuleWithoutMethod()
    {
        $testClass = Composer::class;

        AspectCollector::setAround('TestAspect', [$testClass]);

        $bootstrapper = new GenerateProxies();
        $reflection = new ReflectionMethod($bootstrapper, 'buildClassMap');

        $classMap = $reflection->invoke($bootstrapper);

        $this->assertArrayHasKey($testClass, $classMap);
    }

    public function testBuildClassMapSkipsUnresolvableClasses()
    {
        AspectCollector::setAround('TestAspect', ['Totally\NonExistent\Class123::method']);

        $bootstrapper = new GenerateProxies();
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

        $bootstrapper = new GenerateProxies();
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
}

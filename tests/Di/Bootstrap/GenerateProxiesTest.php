<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Bootstrap;

use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Di\Aop\AspectManager;
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
    protected function tearDown(): void
    {
        AspectCollector::clear();
        AspectManager::clear();
        AstVisitorRegistry::clear();

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
        // Use a class under a PSR-4 prefix registered at test time, so it's
        // guaranteed to never be in the static class map — even with an
        // optimized autoloader (`composer dump-autoload -o`).
        $testClass = 'Hypervel\Tests\Di\Stub\Psr4\UnmappedService';
        $loader = Composer::getLoader();

        $loader->addPsr4(
            'Hypervel\Tests\Di\Stub\Psr4\\',
            [__DIR__ . '/../Stub/Psr4/']
        );

        $this->assertArrayNotHasKey($testClass, $loader->getClassMap(), 'Test class must not be in static class map');
        $this->assertNotFalse($loader->findFile($testClass), 'Test class must be findable via PSR-4');

        AspectCollector::setAround('TestAspect', [$testClass . '::handle']);

        $bootstrapper = new GenerateProxies();
        $reflection = new ReflectionMethod($bootstrapper, 'buildClassMap');

        $classMap = $reflection->invoke($bootstrapper);

        $this->assertArrayHasKey($testClass, $classMap);
        $this->assertStringContainsString('UnmappedService.php', $classMap[$testClass]);
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

<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Di\ClassMap\ClassMapManager;
use Hypervel\Support\ServiceProvider;
use Hypervel\Tests\Di\Fixtures\Aspect\NoPriorityAspect;
use Hypervel\Tests\Di\Fixtures\Aspect\TestAspect;
use Hypervel\Tests\TestCase;
use Mockery as m;

class ServiceProviderDiTest extends TestCase
{
    public function testAspectsRegistersIntoCollector(): void
    {
        $provider = new TestServiceProvider($this->createMockApp());
        $provider->register();

        $this->assertTrue(AspectCollector::hasAspects());

        $rule = AspectCollector::getRule(TestAspect::class);
        $this->assertSame(['App\SomeClass::someMethod', 'App\AnotherClass'], $rule['classes']);
        $this->assertSame(10, $rule['priority']);
    }

    public function testAspectsWithNoPriorityDefaultsToZero(): void
    {
        $provider = new NoPriorityServiceProvider($this->createMockApp());
        $provider->register();

        $this->assertSame(0, AspectCollector::getPriority(NoPriorityAspect::class));
    }

    public function testAspectsAcceptsMultipleArguments(): void
    {
        $provider = new MultiAspectServiceProvider($this->createMockApp());
        $provider->register();

        $this->assertNotEmpty(AspectCollector::getRule(TestAspect::class));
        $this->assertNotEmpty(AspectCollector::getRule(NoPriorityAspect::class));
    }

    public function testClassMapDelegatesToClassMapManager(): void
    {
        $provider = new ClassMapServiceProvider($this->createMockApp());
        $provider->register();

        $this->assertTrue(ClassMapManager::hasEntries());
        $this->assertSame(
            ['Fake\OriginalClass' => '/tmp/replacement.php'],
            ClassMapManager::getEntries()
        );
    }

    /**
     * Create the application contract used by the fixture providers.
     */
    protected function createMockApp(): ApplicationContract
    {
        return m::mock(ApplicationContract::class);
    }
}

class TestServiceProvider extends ServiceProvider
{
    /**
     * Register the fixture aspect.
     */
    public function register(): void
    {
        $this->aspects([TestAspect::class]);
    }
}

class NoPriorityServiceProvider extends ServiceProvider
{
    /**
     * Register an aspect using the default priority.
     */
    public function register(): void
    {
        $this->aspects([NoPriorityAspect::class]);
    }
}

class MultiAspectServiceProvider extends ServiceProvider
{
    /**
     * Register multiple fixture aspects.
     */
    public function register(): void
    {
        $this->aspects(TestAspect::class, NoPriorityAspect::class);
    }
}

class ClassMapServiceProvider extends ServiceProvider
{
    /**
     * Register the fixture replacement.
     */
    public function register(): void
    {
        $this->classMap([
            'Fake\OriginalClass' => '/tmp/replacement.php',
        ]);
    }
}

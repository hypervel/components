<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Attributes\WithConfig;
use Hypervel\Foundation\Testing\Concerns\HandlesAttributes;
use Hypervel\Foundation\Testing\Concerns\InteractsWithTestCase;
use Hypervel\Testbench\Concerns\CreatesApplication;
use Hypervel\Testbench\Concerns\HandlesDatabases;
use Hypervel\Testbench\Concerns\HandlesRoutes;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('testing.testcase_class', 'class_level')]
class TestCaseTest extends TestCase
{
    protected bool $defineEnvironmentCalled = false;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $this->defineEnvironmentCalled = true;
        $app->get('config')->set('testing.define_environment', 'called');
    }

    public function testTestCaseUsesCreatesApplicationTrait(): void
    {
        $uses = class_uses_recursive(static::class);

        $this->assertArrayHasKey(CreatesApplication::class, $uses);
    }

    public function testTestCaseUsesHandlesRoutesTrait(): void
    {
        $uses = class_uses_recursive(static::class);

        $this->assertArrayHasKey(HandlesRoutes::class, $uses);
    }

    public function testTestCaseUsesHandlesDatabasesTrait(): void
    {
        $uses = class_uses_recursive(static::class);

        $this->assertArrayHasKey(HandlesDatabases::class, $uses);
    }

    public function testTestCaseUsesHandlesAttributesTrait(): void
    {
        $uses = class_uses_recursive(static::class);

        $this->assertArrayHasKey(HandlesAttributes::class, $uses);
    }

    public function testTestCaseUsesInteractsWithTestCaseTrait(): void
    {
        $uses = class_uses_recursive(static::class);

        $this->assertArrayHasKey(InteractsWithTestCase::class, $uses);
    }

    public function testDefineEnvironmentIsCalled(): void
    {
        $this->assertTrue($this->defineEnvironmentCalled);
        $this->assertSame('called', $this->app->get('config')->get('testing.define_environment'));
    }

    public function testClassLevelAttributeIsApplied(): void
    {
        // The WithConfig attribute at class level should be applied
        $this->assertSame('class_level', $this->app->get('config')->get('testing.testcase_class'));
    }

    #[WithConfig('testing.method_attribute', 'method_level')]
    public function testMethodLevelAttributeIsApplied(): void
    {
        // The WithConfig attribute at method level should be applied
        $this->assertSame('method_level', $this->app->get('config')->get('testing.method_attribute'));
    }

    public function testReloadApplicationMethodExists(): void
    {
        $this->assertTrue(method_exists($this, 'reloadApplication'));
    }

    public function testStaticLifecycleMethodsExist(): void
    {
        $this->assertTrue(method_exists(static::class, 'setUpBeforeClass'));
        $this->assertTrue(method_exists(static::class, 'tearDownAfterClass'));
    }

    public function testUsesTestingConcernIsAvailable(): void
    {
        $this->assertTrue(static::usesTestingConcern(HandlesAttributes::class));
    }

    public function testAppIsAvailable(): void
    {
        $this->assertNotNull($this->app);
    }
}

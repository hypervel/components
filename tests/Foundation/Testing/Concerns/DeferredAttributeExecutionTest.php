<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hypervel\Testbench\Attributes\DefineDatabase;
use Hypervel\Testbench\TestCase;

/**
 * Tests that deferred Actionable attributes are properly executed during lifecycle.
 *
 * @internal
 * @coversNothing
 */
class DeferredAttributeExecutionTest extends TestCase
{
    protected static bool $deferredMethodWasCalled = false;

    protected function setUp(): void
    {
        static::$deferredMethodWasCalled = false;
        parent::setUp();
    }

    protected function defineDatabaseSetup($app): void
    {
        static::$deferredMethodWasCalled = true;
        $app->get('config')->set('testing.deferred_executed', true);
    }

    #[DefineDatabase('defineDatabaseSetup', defer: true)]
    public function testDeferredDefineDatabaseAttributeIsExecuted(): void
    {
        // The DefineDatabase attribute with defer: true should have its method called
        // during the setUp lifecycle, even though execution is deferred
        $this->assertTrue(
            static::$deferredMethodWasCalled,
            'Deferred DefineDatabase method should be called during setUp'
        );
        $this->assertTrue(
            $this->app->get('config')->get('testing.deferred_executed', false),
            'Deferred DefineDatabase should have set config value'
        );
    }

    #[DefineDatabase('defineDatabaseSetup', defer: false)]
    public function testImmediateDefineDatabaseAttributeIsExecuted(): void
    {
        // The DefineDatabase attribute with defer: false should execute immediately
        $this->assertTrue(
            static::$deferredMethodWasCalled,
            'Immediate DefineDatabase method should be called during setUp'
        );
        $this->assertTrue(
            $this->app->get('config')->get('testing.deferred_executed', false),
            'Immediate DefineDatabase should have set config value'
        );
    }
}

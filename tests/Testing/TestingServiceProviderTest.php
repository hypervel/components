<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing;

use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;

/**
 * @internal
 * @coversNothing
 */
class TestingServiceProviderTest extends TestCase
{
    public function testRegistersParallelTestingSingleton()
    {
        $this->assertTrue($this->app->bound(ParallelTesting::class));
        $this->assertInstanceOf(ParallelTesting::class, $this->app->make(ParallelTesting::class));
    }

    public function testReturnsSameInstance()
    {
        $first = $this->app->make(ParallelTesting::class);
        $second = $this->app->make(ParallelTesting::class);

        $this->assertSame($first, $second);
    }

    public function testCallbacksRegisteredViaServiceAreInvoked()
    {
        $parallelTesting = $this->app->make(ParallelTesting::class);
        $parallelTesting->resolveTokenUsing(fn () => '1');

        $called = false;
        $parallelTesting->setUpTestCase(function () use (&$called) {
            $called = true;
        });

        $parallelTesting->callSetUpTestCaseCallbacks($this);

        $this->assertTrue($called);
    }
}

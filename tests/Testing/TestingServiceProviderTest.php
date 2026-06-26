<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing;

use Hypervel\Contracts\Console\Kernel as ConsoleKernel;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\Console\TestCommand;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Testing\TestingServiceProvider;

class TestingServiceProviderTest extends TestCase
{
    private mixed $originalParallelTesting;

    protected function setUp(): void
    {
        $this->originalParallelTesting = $_SERVER['HYPERVEL_PARALLEL_TESTING'] ?? null;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->originalParallelTesting === null) {
            unset($_SERVER['HYPERVEL_PARALLEL_TESTING']);
        } else {
            $_SERVER['HYPERVEL_PARALLEL_TESTING'] = $this->originalParallelTesting;
        }

        parent::tearDown();
    }

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            TestingServiceProvider::class,
        ];
    }

    public function testRegistersParallelTestingSingleton(): void
    {
        $this->assertTrue($this->app->bound(ParallelTesting::class));
        $this->assertInstanceOf(ParallelTesting::class, $this->app->make(ParallelTesting::class));
    }

    public function testReturnsSameInstance(): void
    {
        $first = $this->app->make(ParallelTesting::class);
        $second = $this->app->make(ParallelTesting::class);

        $this->assertSame($first, $second);
    }

    public function testRegistersApplicationTestCommand(): void
    {
        /** @var array<string, object> $commands */
        $commands = $this->app->make(ConsoleKernel::class)->all();

        $this->assertArrayHasKey('test', $commands);
        $this->assertInstanceOf(TestCommand::class, $commands['test']);
    }

    public function testCallbacksRegisteredViaServiceAreInvoked(): void
    {
        $_SERVER['HYPERVEL_PARALLEL_TESTING'] = true;

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

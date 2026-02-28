<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\SignalRegistry;
use Hypervel\Support\ClassInvoker;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class SignalRegistryTest extends TestCase
{
    public function testRegisterSingleSignalPushesHandler()
    {
        $registry = m::mock(SignalRegistry::class)->makePartial();
        $registry->shouldAllowMockingProtectedMethods();
        $registry->shouldReceive('waitSignal')->with(SIGTERM)->once();

        $callback = fn (int $signo) => null;
        $registry->register(SIGTERM, $callback);

        $invoker = new ClassInvoker($registry);
        $this->assertArrayHasKey(SIGTERM, $invoker->signalHandlers);
        $this->assertCount(1, $invoker->signalHandlers[SIGTERM]);
        $this->assertSame($callback, $invoker->signalHandlers[SIGTERM][0]);
    }

    public function testRegisterMultipleSignalsRegistersEach()
    {
        $registry = m::mock(SignalRegistry::class)->makePartial();
        $registry->shouldAllowMockingProtectedMethods();
        $registry->shouldReceive('waitSignal')->with(SIGTERM)->once();
        $registry->shouldReceive('waitSignal')->with(SIGINT)->once();

        $callback = fn (int $signo) => null;
        $registry->register([SIGTERM, SIGINT], $callback);

        $invoker = new ClassInvoker($registry);
        $this->assertArrayHasKey(SIGTERM, $invoker->signalHandlers);
        $this->assertArrayHasKey(SIGINT, $invoker->signalHandlers);
        $this->assertSame($callback, $invoker->signalHandlers[SIGTERM][0]);
        $this->assertSame($callback, $invoker->signalHandlers[SIGINT][0]);
    }

    public function testRegisterMultipleHandlersForSameSignal()
    {
        $registry = m::mock(SignalRegistry::class)->makePartial();
        $registry->shouldAllowMockingProtectedMethods();
        $registry->shouldReceive('waitSignal')->with(SIGTERM)->twice();

        $callbackA = fn (int $signo) => null;
        $callbackB = fn (int $signo) => null;

        $registry->register(SIGTERM, $callbackA);
        $registry->register(SIGTERM, $callbackB);

        $invoker = new ClassInvoker($registry);
        $this->assertCount(2, $invoker->signalHandlers[SIGTERM]);
        $this->assertSame($callbackA, $invoker->signalHandlers[SIGTERM][0]);
        $this->assertSame($callbackB, $invoker->signalHandlers[SIGTERM][1]);
    }

    public function testUnregisterSingleSignal()
    {
        $registry = m::mock(SignalRegistry::class)->makePartial();
        $registry->shouldAllowMockingProtectedMethods();
        $registry->shouldReceive('waitSignal');

        $registry->register(SIGTERM, fn (int $signo) => null);
        $registry->register(SIGINT, fn (int $signo) => null);

        $registry->unregister(SIGTERM);

        $invoker = new ClassInvoker($registry);
        $this->assertEmpty($invoker->signalHandlers[SIGTERM]);
        $this->assertCount(1, $invoker->signalHandlers[SIGINT]);
    }

    public function testUnregisterMultipleSignals()
    {
        $registry = m::mock(SignalRegistry::class)->makePartial();
        $registry->shouldAllowMockingProtectedMethods();
        $registry->shouldReceive('waitSignal');

        $registry->register(SIGTERM, fn (int $signo) => null);
        $registry->register(SIGINT, fn (int $signo) => null);

        $registry->unregister([SIGTERM, SIGINT]);

        $invoker = new ClassInvoker($registry);
        $this->assertEmpty($invoker->signalHandlers[SIGTERM]);
        $this->assertEmpty($invoker->signalHandlers[SIGINT]);
    }

    public function testUnregisterAllSignals()
    {
        $registry = m::mock(SignalRegistry::class)->makePartial();
        $registry->shouldAllowMockingProtectedMethods();
        $registry->shouldReceive('waitSignal');

        $registry->register(SIGTERM, fn (int $signo) => null);
        $registry->register(SIGINT, fn (int $signo) => null);

        $registry->unregister();

        $invoker = new ClassInvoker($registry);
        $this->assertEmpty($invoker->signalHandlers);
    }

    public function testWaitSignalOnlySpawnsOneCoroutinePerSignal()
    {
        $registry = new class extends SignalRegistry {
            public bool $coroutineSpawned = false;

            protected function waitSignal(int $signo): void
            {
                if (isset($this->handling[$signo])) {
                    return;
                }

                $this->coroutineSpawned = true;
                $this->handling[$signo] = 1;
            }
        };

        // First register spawns
        $registry->register(SIGTERM, fn (int $signo) => null);
        $this->assertTrue($registry->coroutineSpawned);

        // Reset flag and register again — should NOT spawn
        $registry->coroutineSpawned = false;
        $registry->register(SIGTERM, fn (int $signo) => null);
        $this->assertFalse($registry->coroutineSpawned);
    }
}

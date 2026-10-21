<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Generator;
use Hypervel\Console\Concerns\InteractsWithSignals;
use Hypervel\Container\Container;
use Hypervel\Coroutine\SignalRegistry;
use Hypervel\Support\ClassInvoker;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionProperty;

class InteractsWithSignalsTest extends TestCase
{
    public function testTrapCreatesRegistry(): void
    {
        $command = new InteractsWithSignalsTestStub;

        $invoker = new ClassInvoker($command);
        $this->assertNull($invoker->signalRegistry);

        $signalRegistry = m::mock(SignalRegistry::class)->shouldIgnoreMissing();
        $signalRegistry->shouldReceive('register')->once();
        Container::getInstance()->instance(SignalRegistry::class, $signalRegistry);

        $command->callTrap(SIGTERM, fn (int $signo) => null);

        $this->assertSame($signalRegistry, $invoker->signalRegistry);
    }

    public function testTrapReusesExistingRegistry(): void
    {
        $signalRegistry = m::mock(SignalRegistry::class)->shouldIgnoreMissing();
        $signalRegistry->shouldReceive('register')->twice();

        $command = new InteractsWithSignalsTestStub;
        $this->setSignalRegistry($command, $signalRegistry);

        $command->callTrap(SIGTERM, fn (int $signo) => null);
        $command->callTrap(SIGINT, fn (int $signo) => null);
    }

    public function testUntrapDelegatesToRegistry(): void
    {
        $command = new InteractsWithSignalsTestStub;
        $signalRegistry = m::mock(SignalRegistry::class)->shouldIgnoreMissing();
        $signalRegistry->shouldReceive('register')->once();
        $signalRegistry->shouldReceive('unregister')->with($command, SIGTERM)->once();

        $this->setSignalRegistry($command, $signalRegistry);

        $command->callTrap(SIGTERM, fn (int $signo) => null);
        $command->callUntrap(SIGTERM);
    }

    public function testUntrapWithNoRegistryIsNoop(): void
    {
        $command = new InteractsWithSignalsTestStub;

        // Should not throw — signalRegistry is null
        $command->callUntrap();
        $this->assertNull((new ClassInvoker($command))->signalRegistry);
    }

    public function testUntrapAllSignals(): void
    {
        $command = new InteractsWithSignalsTestStub;
        $signalRegistry = m::mock(SignalRegistry::class)->shouldIgnoreMissing();
        $signalRegistry->shouldReceive('register')->once();
        $signalRegistry->shouldReceive('unregister')->with($command, null)->once();

        $this->setSignalRegistry($command, $signalRegistry);

        $command->callTrap(SIGTERM, fn (int $signo) => null);
        $command->callUntrap(null);
        $this->assertNull((new ClassInvoker($command))->signalRegistry);
    }

    public function testTrapResolvesLazyIterableSignals(): void
    {
        $command = new InteractsWithSignalsTestStub;
        $signals = (function (): Generator {
            yield SIGTERM;
            yield SIGINT;
        })();
        $callback = static fn (int $signal): null => null;
        $registry = m::mock(SignalRegistry::class)->shouldIgnoreMissing();
        $registry->expects('register')->with($command, $signals, $callback);
        Container::getInstance()->instance(SignalRegistry::class, $registry);
        $calls = 0;

        $command->trap(signals: function () use ($signals, &$calls): Generator {
            ++$calls;

            return $signals;
        }, callback: $callback);

        $this->assertSame(1, $calls);
    }

    /**
     * Set the registry used by the command.
     */
    private function setSignalRegistry(InteractsWithSignalsTestStub $command, SignalRegistry $registry): void
    {
        $property = new ReflectionProperty($command, 'signalRegistry');
        $property->setValue($command, $registry);
    }
}

class InteractsWithSignalsTestStub
{
    use InteractsWithSignals;

    /**
     * Register handlers through the concern.
     */
    public function callTrap(array|int $signo, callable $callback): void
    {
        $this->trap($signo, $callback);
    }

    /**
     * Remove handlers through the concern.
     */
    public function callUntrap(array|int|null $signo = null): void
    {
        $this->untrap($signo);
    }
}

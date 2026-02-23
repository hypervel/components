<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\Concerns\InteractsWithSignals;
use Hypervel\Console\SignalRegistry;
use Hypervel\Container\Container;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Support\ClassInvoker;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class InteractsWithSignalsTest extends TestCase
{
    use RunTestsInCoroutine;

    public function testTrapCreatesRegistryAndRegistersSignal()
    {
        $signalRegistry = m::mock(SignalRegistry::class)->shouldIgnoreMissing();
        $signalRegistry->shouldReceive('register')->with(SIGTERM, m::type('callable'))->once();

        $container = m::mock(Container::class)->shouldIgnoreMissing();
        $container->shouldReceive('make')->with(SignalRegistry::class)->once()->andReturn($signalRegistry);
        Container::setInstance($container);

        $command = new InteractsWithSignalsTestStub();
        $command->callTrap(SIGTERM, fn (int $signo) => null);

        $invoker = new ClassInvoker($command);
        $this->assertSame($signalRegistry, $invoker->signalRegistry);
    }

    public function testTrapReusesExistingRegistry()
    {
        $signalRegistry = m::mock(SignalRegistry::class)->shouldIgnoreMissing();
        $signalRegistry->shouldReceive('register')->twice();

        $container = m::mock(Container::class)->shouldIgnoreMissing();
        // make() should only be called once — reused on second trap()
        $container->shouldReceive('make')->with(SignalRegistry::class)->once()->andReturn($signalRegistry);
        Container::setInstance($container);

        $command = new InteractsWithSignalsTestStub();
        $command->callTrap(SIGTERM, fn (int $signo) => null);
        $command->callTrap(SIGINT, fn (int $signo) => null);
    }

    public function testUntrapDelegatesToRegistry()
    {
        $signalRegistry = m::mock(SignalRegistry::class)->shouldIgnoreMissing();
        $signalRegistry->shouldReceive('register')->once();
        $signalRegistry->shouldReceive('unregister')->with(SIGTERM)->once();

        $container = m::mock(Container::class)->shouldIgnoreMissing();
        $container->shouldReceive('make')->with(SignalRegistry::class)->once()->andReturn($signalRegistry);
        Container::setInstance($container);

        $command = new InteractsWithSignalsTestStub();
        $command->callTrap(SIGTERM, fn (int $signo) => null);
        $command->callUntrap(SIGTERM);
    }

    public function testUntrapWithNoRegistryIsNoop()
    {
        $command = new InteractsWithSignalsTestStub();

        // Should not throw — signalRegistry is null
        $command->callUntrap();
    }

    public function testUntrapAllSignals()
    {
        $signalRegistry = m::mock(SignalRegistry::class)->shouldIgnoreMissing();
        $signalRegistry->shouldReceive('register')->once();
        $signalRegistry->shouldReceive('unregister')->with(null)->once();

        $container = m::mock(Container::class)->shouldIgnoreMissing();
        $container->shouldReceive('make')->with(SignalRegistry::class)->once()->andReturn($signalRegistry);
        Container::setInstance($container);

        $command = new InteractsWithSignalsTestStub();
        $command->callTrap(SIGTERM, fn (int $signo) => null);
        $command->callUntrap(null);
    }
}

class InteractsWithSignalsTestStub
{
    use InteractsWithSignals;

    public function callTrap(array|int $signo, callable $callback): void
    {
        $this->trap($signo, $callback);
    }

    public function callUntrap(array|int|null $signo = null): void
    {
        $this->untrap($signo);
    }
}

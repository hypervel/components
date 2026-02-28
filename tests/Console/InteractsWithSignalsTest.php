<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\Concerns\InteractsWithSignals;
use Hypervel\Console\SignalRegistry;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Support\ClassInvoker;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionProperty;

/**
 * @internal
 * @coversNothing
 */
class InteractsWithSignalsTest extends TestCase
{
    use RunTestsInCoroutine;

    public function testTrapCreatesRegistry()
    {
        $command = new InteractsWithSignalsTestStub();

        $invoker = new ClassInvoker($command);
        $this->assertNull($invoker->signalRegistry);

        // Inject a mock so trap() doesn't spawn a real signal wait coroutine.
        $signalRegistry = m::mock(SignalRegistry::class)->shouldIgnoreMissing();
        $signalRegistry->shouldReceive('register')->once();
        $this->setSignalRegistry($command, $signalRegistry);

        $command->callTrap(SIGTERM, fn (int $signo) => null);

        $this->assertSame($signalRegistry, $invoker->signalRegistry);
    }

    public function testTrapReusesExistingRegistry()
    {
        $signalRegistry = m::mock(SignalRegistry::class)->shouldIgnoreMissing();
        $signalRegistry->shouldReceive('register')->twice();

        $command = new InteractsWithSignalsTestStub();
        $this->setSignalRegistry($command, $signalRegistry);

        $command->callTrap(SIGTERM, fn (int $signo) => null);
        $command->callTrap(SIGINT, fn (int $signo) => null);
    }

    public function testUntrapDelegatesToRegistry()
    {
        $signalRegistry = m::mock(SignalRegistry::class)->shouldIgnoreMissing();
        $signalRegistry->shouldReceive('register')->once();
        $signalRegistry->shouldReceive('unregister')->with(SIGTERM)->once();

        $command = new InteractsWithSignalsTestStub();
        $this->setSignalRegistry($command, $signalRegistry);

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

        $command = new InteractsWithSignalsTestStub();
        $this->setSignalRegistry($command, $signalRegistry);

        $command->callTrap(SIGTERM, fn (int $signo) => null);
        $command->callUntrap(null);
    }

    private function setSignalRegistry(InteractsWithSignalsTestStub $command, SignalRegistry $registry): void
    {
        $property = new ReflectionProperty($command, 'signalRegistry');
        $property->setValue($command, $registry);
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

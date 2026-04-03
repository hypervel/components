<?php

declare(strict_types=1);

namespace Hypervel\Tests\Signal;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Framework\Events\OnWorkerExit;
use Hypervel\ServerProcess\Events\AfterProcessHandle;
use Hypervel\Signal\SignalDeregisterListener;
use Hypervel\Signal\SignalManager;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class SignalDeregisterListenerTest extends TestCase
{
    public function testHandleOnWorkerExitStopsSignalManager()
    {
        $container = m::mock(ContainerContract::class);
        $manager = m::mock(SignalManager::class);
        $event = m::mock(OnWorkerExit::class);

        $container->shouldReceive('make')
            ->with(SignalManager::class)
            ->once()
            ->andReturn($manager);

        $manager->shouldReceive('setStopped')
            ->with(true)
            ->once();

        $listener = new SignalDeregisterListener($container);
        $listener->handle($event);
    }

    public function testHandleAfterProcessHandleStopsSignalManager()
    {
        $container = m::mock(ContainerContract::class);
        $manager = m::mock(SignalManager::class);
        $event = m::mock(AfterProcessHandle::class);

        $container->shouldReceive('make')
            ->with(SignalManager::class)
            ->once()
            ->andReturn($manager);

        $manager->shouldReceive('setStopped')
            ->with(true)
            ->once();

        $listener = new SignalDeregisterListener($container);
        $listener->handle($event);
    }
}

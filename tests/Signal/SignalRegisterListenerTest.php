<?php

declare(strict_types=1);

namespace Hypervel\Tests\Signal;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Signal\SignalHandler;
use Hypervel\Core\Events\BeforeWorkerStart;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;
use Hypervel\Signal\SignalManager;
use Hypervel\Signal\SignalRegisterListener;
use Hypervel\Tests\TestCase;
use Mockery as m;

class SignalRegisterListenerTest extends TestCase
{
    public function testHandleBeforeWorkerStartListensForWorker(): void
    {
        $container = m::mock(ContainerContract::class);
        $manager = m::mock(SignalManager::class);
        $event = m::mock(BeforeWorkerStart::class);

        $container->shouldReceive('make')
            ->with(SignalManager::class)
            ->once()
            ->andReturn($manager);

        $manager->shouldReceive('listen')
            ->with(SignalHandler::WORKER)
            ->once();

        $listener = new SignalRegisterListener($container);
        $listener->handle($event);
    }

    public function testHandleBeforeProcessHandleListensForServerProcess(): void
    {
        $container = m::mock(ContainerContract::class);
        $manager = m::mock(SignalManager::class);
        $event = m::mock(BeforeProcessHandle::class);

        $container->shouldReceive('make')
            ->with(SignalManager::class)
            ->once()
            ->andReturn($manager);

        $manager->shouldReceive('listen')
            ->with(SignalHandler::SERVER_PROCESS)
            ->once();

        $listener = new SignalRegisterListener($container);
        $listener->handle($event);
    }
}

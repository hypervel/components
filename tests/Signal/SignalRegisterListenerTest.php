<?php

declare(strict_types=1);

namespace Hypervel\Tests\Signal;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Signal\SignalHandlerInterface;
use Hypervel\Framework\Events\BeforeWorkerStart;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;
use Hypervel\Signal\SignalManager;
use Hypervel\Signal\SignalRegisterListener;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class SignalRegisterListenerTest extends TestCase
{
    public function testHandleBeforeWorkerStartInitializesAndListensForWorker()
    {
        $container = m::mock(ContainerContract::class);
        $manager = m::mock(SignalManager::class);
        $event = m::mock(BeforeWorkerStart::class);

        $container->shouldReceive('make')
            ->with(SignalManager::class)
            ->once()
            ->andReturn($manager);

        $manager->shouldReceive('init')->once();
        $manager->shouldReceive('listen')
            ->with(SignalHandlerInterface::WORKER)
            ->once();

        $listener = new SignalRegisterListener($container);
        $listener->handle($event);
    }

    public function testHandleBeforeProcessHandleInitializesAndListensForProcess()
    {
        $container = m::mock(ContainerContract::class);
        $manager = m::mock(SignalManager::class);
        $event = m::mock(BeforeProcessHandle::class);

        $container->shouldReceive('make')
            ->with(SignalManager::class)
            ->once()
            ->andReturn($manager);

        $manager->shouldReceive('init')->once();
        $manager->shouldReceive('listen')
            ->with(SignalHandlerInterface::PROCESS)
            ->once();

        $listener = new SignalRegisterListener($container);
        $listener->handle($event);
    }
}

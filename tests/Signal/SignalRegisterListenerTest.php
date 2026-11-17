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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class SignalRegisterListenerTest extends TestCase
{
    use VerifiesDoubles;

    public function testHandleBeforeWorkerStartListensForWorker(): void
    {
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $manager = Double::for(SignalManager::class)->strict();
        $event = Double::for(BeforeWorkerStart::class)->strict();

        $container->expects('make')
            ->with(SignalManager::class)
            ->returns($manager);

        $manager->expects('listen')
            ->with(SignalHandler::WORKER);

        $listener = new SignalRegisterListener($container->instance());
        $listener->handle($event);
    }

    public function testHandleBeforeProcessHandleListensForServerProcess(): void
    {
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $manager = Double::for(SignalManager::class)->strict();
        $event = Double::for(BeforeProcessHandle::class)->strict();

        $container->expects('make')
            ->with(SignalManager::class)
            ->returns($manager);

        $manager->expects('listen')
            ->with(SignalHandler::SERVER_PROCESS);

        $listener = new SignalRegisterListener($container->instance());
        $listener->handle($event);
    }
}

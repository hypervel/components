<?php

declare(strict_types=1);

namespace Hypervel\Tests\Signal;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Core\Events\OnWorkerExit;
use Hypervel\ServerProcess\Events\AfterProcessHandle;
use Hypervel\Signal\SignalDeregisterListener;
use Hypervel\Signal\SignalManager;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class SignalDeregisterListenerTest extends TestCase
{
    use VerifiesDoubles;

    public function testHandleOnWorkerExitStopsSignalManager(): void
    {
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $manager = Double::for(SignalManager::class)->strict();
        $event = Double::for(OnWorkerExit::class)->strict();

        $container->expects('make')
            ->with(SignalManager::class)
            ->returns($manager);

        $manager->expects('stop');

        $listener = new SignalDeregisterListener($container->instance());
        $listener->handle($event);
    }

    public function testHandleAfterProcessHandleStopsSignalManager(): void
    {
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $manager = Double::for(SignalManager::class)->strict();
        $event = Double::for(AfterProcessHandle::class)->strict();

        $container->expects('make')
            ->with(SignalManager::class)
            ->returns($manager);

        $manager->expects('stop');

        $listener = new SignalDeregisterListener($container->instance());
        $listener->handle($event);
    }
}

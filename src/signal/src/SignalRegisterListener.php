<?php

declare(strict_types=1);

namespace Hypervel\Signal;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Signal\SignalHandlerInterface as SignalHandler;
use Hypervel\Framework\Events\BeforeWorkerStart;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;

class SignalRegisterListener
{
    /**
     * Create a new signal register listener instance.
     */
    public function __construct(protected Container $container)
    {
    }

    /**
     * Handle the event.
     */
    public function handle(BeforeWorkerStart|BeforeProcessHandle $event): void
    {
        $manager = $this->container->make(SignalManager::class);

        $manager->init();

        if ($event instanceof BeforeWorkerStart) {
            $manager->listen(SignalHandler::WORKER);
        } elseif ($event instanceof BeforeProcessHandle) {
            $manager->listen(SignalHandler::PROCESS);
        }
    }
}

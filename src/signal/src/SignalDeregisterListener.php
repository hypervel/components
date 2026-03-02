<?php

declare(strict_types=1);

namespace Hypervel\Signal;

use Hypervel\Contracts\Container\Container;
use Hypervel\Framework\Events\OnWorkerExit;
use Hypervel\ServerProcess\Events\AfterProcessHandle;

class SignalDeregisterListener
{
    /**
     * Create a new signal deregister listener instance.
     */
    public function __construct(protected Container $container)
    {
    }

    /**
     * Handle the event.
     */
    public function handle(OnWorkerExit|AfterProcessHandle $event): void
    {
        $this->container->make(SignalManager::class)->setStopped(true);
    }
}

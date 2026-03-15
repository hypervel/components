<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool\Listeners;

use Hypervel\Contracts\Container\Container;
use Hypervel\Framework\Events\AfterWorkerStart;
use Hypervel\ObjectPool\Contracts\Recycler;

class StartRecycler
{
    public function __construct(
        protected Container $container,
    ) {
    }

    /**
     * Start the object pool recycler after a worker starts.
     */
    public function handle(AfterWorkerStart $event): void
    {
        $this->container->make(Recycler::class)
            ->start();
    }
}

<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool\Listeners;

use Hypervel\Contracts\Container\Container;
use Hypervel\Event\Contracts\ListenerInterface;
use Hypervel\Framework\Events\AfterWorkerStart;
use Hypervel\ObjectPool\Contracts\Recycler;

class StartRecycler implements ListenerInterface
{
    public function __construct(
        protected Container $container,
    ) {
    }

    public function listen(): array
    {
        return [
            AfterWorkerStart::class,
        ];
    }

    public function process(object $event): void
    {
        $this->container->make(Recycler::class)
            ->start();
    }
}

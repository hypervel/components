<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool\Listeners;

use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\AfterWorkerStart;
use Hypervel\ObjectPool\Contracts\Recycler;
use Psr\Container\ContainerInterface;

class StartRecycler implements ListenerInterface
{
    public function __construct(
        protected ContainerInterface $container,
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
        $this->container->get(Recycler::class)
            ->start();
    }
}

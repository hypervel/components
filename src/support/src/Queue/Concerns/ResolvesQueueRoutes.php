<?php

declare(strict_types=1);

namespace Hypervel\Support\Queue\Concerns;

use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Queue\QueueRoutes;
use UnitEnum;

trait ResolvesQueueRoutes
{
    /**
     * Resolve the default connection name for a given queueable instance.
     *
     * @param null|string|UnitEnum $queue the caller-selected queue, overriding the queueable's queue when resolving a forwarded connection
     */
    public function resolveConnectionFromQueueRoute(object $queueable, UnitEnum|string|null $queue = null): ?string
    {
        return $this->queueRoutes()->getConnection($queueable, $queue);
    }

    /**
     * Resolve the default queue name for a given queueable instance.
     */
    public function resolveQueueFromQueueRoute(object $queueable): ?string
    {
        return $this->queueRoutes()->getQueue($queueable);
    }

    /**
     * Get the queue routes manager instance.
     */
    protected function queueRoutes(): QueueRoutes
    {
        $container = $this->queueRoutesContainer();

        // Standalone managers must share the container's registry even without a provider binding.
        return $container->bound('queue.routes')
            ? $container->make('queue.routes')
            : $container->make(QueueRoutes::class);
    }

    /**
     * Get the container that owns the queue routes.
     */
    protected function queueRoutesContainer(): ContainerContract
    {
        return Container::getInstance();
    }
}

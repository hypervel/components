<?php

declare(strict_types=1);

namespace Hypervel\Support\Queue\Concerns;

use Hypervel\Container\Container;
use Hypervel\Queue\QueueRoutes;

trait ResolvesQueueRoutes
{
    /**
     * Resolve the default connection name for a given queueable instance.
     */
    public function resolveConnectionFromQueueRoute(object $queueable): ?string
    {
        return $this->queueRoutes()->getConnection($queueable);
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
        $container = Container::getInstance();

        return $container->bound('queue.routes')
            ? $container->make('queue.routes')
            : new QueueRoutes();
    }
}

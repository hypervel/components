<?php

declare(strict_types=1);

namespace Hypervel\Redis\Listeners;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Redis\RedisManager;
use Throwable;

class RedisConnectionLifecycleListener
{
    public function __construct(
        protected ContainerContract $container,
    ) {
    }

    /**
     * Release connections retained by non-coroutine task execution.
     */
    public function releaseTaskConnections(): void
    {
        if (! $this->container->resolved('redis')) {
            return;
        }

        $manager = $this->container->make('redis');

        if ($manager instanceof RedisManager) {
            $manager->releaseConnections();
        }
    }

    /**
     * Discard inherited connections and close resolved pools.
     */
    public function discardProcessConnections(): void
    {
        $exception = null;

        if ($this->container->resolved('redis')) {
            try {
                $manager = $this->container->make('redis');

                if ($manager instanceof RedisManager) {
                    $manager->discardConnections();
                }
            } catch (Throwable $throwable) {
                $exception = $throwable;
            }
        }

        if ($this->container->resolved(PoolFactory::class)) {
            try {
                $this->container->make(PoolFactory::class)->flushAll();
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($exception !== null) {
            throw $exception;
        }
    }
}

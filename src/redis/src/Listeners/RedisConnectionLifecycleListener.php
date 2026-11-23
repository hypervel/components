<?php

declare(strict_types=1);

namespace Hypervel\Redis\Listeners;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Redis\Pool\PoolManager;
use Hypervel\Redis\RedisManager;
use Throwable;

class RedisConnectionLifecycleListener
{
    /**
     * Create a connection lifecycle listener.
     */
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

        /** @var RedisFactory $manager */
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
                /** @var RedisFactory $manager */
                $manager = $this->container->make('redis');

                if ($manager instanceof RedisManager) {
                    $manager->discardConnections();
                }
            } catch (Throwable $throwable) {
                $exception = $throwable;
            }
        }

        if ($this->container->resolved(PoolManager::class)) {
            try {
                $this->container->make(PoolManager::class)->purgeAll();
            } catch (Throwable $throwable) {
                $exception = Coroutine::preferCancellation($exception, $throwable);
            }
        }

        if ($exception !== null) {
            throw $exception;
        }
    }
}

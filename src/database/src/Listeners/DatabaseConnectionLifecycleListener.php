<?php

declare(strict_types=1);

namespace Hypervel\Database\Listeners;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\Pool\PoolManager;
use Swoole\Coroutine\CanceledException;
use Throwable;

class DatabaseConnectionLifecycleListener
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
        if (! $this->container->resolved('db.resolver')) {
            return;
        }

        $resolver = $this->container->make('db.resolver');

        if ($resolver instanceof ConnectionResolver) {
            $resolver->releaseConnections();
        }
    }

    /**
     * Discard inherited connections and close resolved pools.
     */
    public function discardProcessConnections(): void
    {
        $exception = null;

        if ($this->container->resolved('db.resolver')) {
            try {
                $resolver = $this->container->make('db.resolver');

                if ($resolver instanceof ConnectionResolver) {
                    $resolver->discardConnections();
                }
            } catch (Throwable $throwable) {
                $exception = $throwable;
            }
        }

        if ($this->container->resolved(PoolManager::class)) {
            try {
                $this->container->make(PoolManager::class)->purgeAll();
            } catch (Throwable $throwable) {
                if ($exception === null
                    || ($throwable instanceof CanceledException && ! $exception instanceof CanceledException)
                ) {
                    $exception = $throwable;
                }
            }
        }

        if ($exception !== null) {
            throw $exception;
        }
    }
}

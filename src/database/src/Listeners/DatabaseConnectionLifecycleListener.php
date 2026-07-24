<?php

declare(strict_types=1);

namespace Hypervel\Database\Listeners;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\Pool\PoolFactory;
use Throwable;

class DatabaseConnectionLifecycleListener
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

<?php

declare(strict_types=1);

namespace Hypervel\Database\Pool;

use Hypervel\Contracts\Container\Container;
use Hypervel\Database\ConnectionName;
use Hypervel\Database\Connectors\ConnectionFactory;
use Swoole\Coroutine\CanceledException;
use Throwable;

class PoolManager
{
    /**
     * The cached pool instances.
     *
     * @var array<string, DatabasePool>
     */
    protected array $pools = [];

    /**
     * Create a pool manager.
     */
    public function __construct(
        protected Container $container
    ) {
    }

    /**
     * Get or create a pool for the given connection name.
     */
    public function pool(string $name): DatabasePool
    {
        while (true) {
            if (($pool = $this->pools[$name] ?? null) !== null) {
                if (! $pool->isClosed()) {
                    return $pool;
                }

                unset($this->pools[$name]);
            }

            $poolName = $this->getPoolName($name);

            if (($pool = $this->pools[$poolName] ?? null) !== null) {
                if (! $pool->isClosed()) {
                    return $pool;
                }

                unset($this->pools[$poolName]);
            }

            $pool = $this->container->make(DatabasePool::class, ['name' => $poolName]);

            try {
                $pool->start();
            } catch (Throwable $failure) {
                try {
                    $pool->close();
                } catch (CanceledException $cancellation) {
                    if (! $failure instanceof CanceledException) {
                        throw $cancellation;
                    }
                } catch (Throwable) {
                    // Preserve the activation failure over an ordinary cleanup failure.
                }

                throw $failure;
            }

            $existing = $this->pools[$poolName] ?? null;

            if ($existing === null || $existing->isClosed()) {
                return $this->pools[$poolName] = $pool;
            }

            if ($existing === $pool) {
                return $pool;
            }

            // Cleanup can yield while the registered pool closes or is replaced.
            $pool->close();
        }
    }

    /**
     * Get the existing pools keyed by their physical connection names.
     *
     * @return array<string, DatabasePool>
     */
    public function getPools(): array
    {
        return $this->pools;
    }

    /**
     * Resolve the physical pool name for a requested connection name.
     */
    protected function getPoolName(string $name): string
    {
        $connectionName = ConnectionName::parse($name);

        if (! $connectionName->isRead()) {
            return $connectionName->base;
        }

        $configService = $this->container->make('config');
        $key = sprintf('database.connections.%s', $connectionName->base);

        if (! $configService->has($key)) {
            return $connectionName->base;
        }

        /** @var array<string, mixed> $config */
        $config = $configService->get($key);

        /** @var ConnectionFactory $factory */
        $factory = $this->container->make('db.factory');

        return $factory->hasReadConfig($config)
            ? $connectionName->requested
            : $connectionName->base;
    }

    /**
     * Check if a pool exists for the given connection name.
     */
    public function has(string $name): bool
    {
        return isset($this->pools[$this->getExistingPoolName($name)]);
    }

    /**
     * Remove a pool and close its connections.
     *
     * Boot or tests only. Closes a worker-shared pool; connections already
     * checked out by concurrent coroutines are destroyed on release.
     */
    public function purge(string $name): void
    {
        $poolName = $this->getExistingPoolName($name);
        $pool = $this->pools[$poolName] ?? null;

        if ($pool !== null) {
            unset($this->pools[$poolName]);
            $pool->close();
        }
    }

    /**
     * Resolve an existing pool key for a requested connection name.
     */
    protected function getExistingPoolName(string $name): string
    {
        return isset($this->pools[$name])
            ? $name
            : $this->getPoolName($name);
    }

    /**
     * Remove every pool for a configured connection.
     *
     * Boot or tests only. This closes shared worker pools and affects every
     * coroutine that later resolves the same configured connection.
     */
    public function purgeForConnection(string $name): void
    {
        $base = ConnectionName::parse($name)->base;
        $pools = [];

        foreach ($this->pools as $poolName => $pool) {
            if ($poolName === $base || str_starts_with($poolName, $base . '::')) {
                $pools[$poolName] = $pool;
                unset($this->pools[$poolName]);
            }
        }

        $this->closePools($pools);
    }

    /**
     * Remove all pools and close their connections.
     *
     * Boot or tests only. Closes every worker-shared pool; connections already
     * checked out by concurrent coroutines are destroyed on release.
     */
    public function purgeAll(): void
    {
        $pools = $this->pools;
        $this->pools = [];

        $this->closePools($pools);
    }

    /**
     * Close a finite set of detached pools.
     *
     * @param array<string, DatabasePool> $pools
     */
    private function closePools(array $pools): void
    {
        $firstException = null;
        $firstCancellation = null;

        foreach ($pools as $pool) {
            try {
                $pool->close();
            } catch (CanceledException $exception) {
                $firstCancellation ??= $exception;
            } catch (Throwable $exception) {
                $firstException ??= $exception;
            }
        }

        if ($firstCancellation !== null) {
            throw $firstCancellation;
        }

        if ($firstException !== null) {
            throw $firstException;
        }
    }
}

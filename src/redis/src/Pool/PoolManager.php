<?php

declare(strict_types=1);

namespace Hypervel\Redis\Pool;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Swoole\Coroutine\CanceledException;
use Throwable;

class PoolManager
{
    /**
     * @var array<string, RedisPool>
     */
    protected array $pools = [];

    /**
     * Create a pool manager.
     */
    public function __construct(
        protected ContainerContract $container
    ) {
    }

    /**
     * Remove all pools and close their connections.
     *
     * Boot or tests only. Closes worker-shared pools; connections already
     * checked out by concurrent coroutines are destroyed on release.
     */
    public function purgeAll(): void
    {
        $pools = $this->pools;
        $this->pools = [];
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

    /**
     * Remove a pool and close its connections.
     *
     * Boot or tests only. Closes a worker-shared pool; connections already
     * checked out by concurrent coroutines are destroyed on release.
     */
    public function purge(string $name): void
    {
        $pool = $this->pools[$name] ?? null;

        if ($pool !== null) {
            unset($this->pools[$name]);
            $pool->close();
        }
    }

    /**
     * Get or create a pool for the given connection name.
     */
    public function pool(string $name): RedisPool
    {
        while (true) {
            if (($pool = $this->pools[$name] ?? null) !== null) {
                if (! $pool->isClosed()) {
                    return $pool;
                }

                unset($this->pools[$name]);
            }

            $pool = $this->container->make(RedisPool::class, ['name' => $name]);

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

            $existing = $this->pools[$name] ?? null;

            if ($existing === null || $existing->isClosed()) {
                return $this->pools[$name] = $pool;
            }

            if ($existing === $pool) {
                return $pool;
            }

            // Cleanup can yield while the registered pool closes or is replaced.
            $pool->close();
        }
    }

    /**
     * Get the existing pools keyed by connection name.
     *
     * @return array<string, RedisPool>
     */
    public function getPools(): array
    {
        return $this->pools;
    }
}

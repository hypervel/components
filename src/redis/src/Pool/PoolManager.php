<?php

declare(strict_types=1);

namespace Hypervel\Redis\Pool;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Support\Attributes\BootOrTestsOnly;
use Throwable;

class PoolManager
{
    /**
     * The sequence used to build unique per-manager context key prefixes.
     */
    // Do not reset this; live context entries may still use earlier prefixes.
    private static int $contextKeySequence = 0;

    /**
     * The prefix of this manager's coroutine context keys.
     *
     * Proxies pin connections from this manager's pools in coroutine context.
     * Another manager can own a pool with the same connection name, so the
     * keys include this manager's own prefix.
     */
    private ?string $contextKeyPrefix = null;

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
     * Closes worker-shared pools; connections already checked out by
     * concurrent coroutines are destroyed on release.
     */
    #[BootOrTestsOnly]
    public function purgeAll(): void
    {
        $pools = $this->pools;
        $this->pools = [];
        $exception = null;

        foreach ($pools as $pool) {
            try {
                $pool->close();
            } catch (Throwable $throwable) {
                $exception = Coroutine::preferCancellation($exception, $throwable);
            }
        }

        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Remove a pool and close its connections.
     *
     * Closes a worker-shared pool; connections already checked out by
     * concurrent coroutines are destroyed on release.
     */
    #[BootOrTestsOnly]
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
                } catch (Throwable $cleanupFailure) {
                    $failure = Coroutine::preferCancellation($failure, $cleanupFailure);
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

    /**
     * Get this manager's coroutine context key for the given entry.
     *
     * @internal
     */
    public function contextKey(string $name): string
    {
        return ($this->contextKeyPrefix ??= '__redis.' . ++self::$contextKeySequence . '.') . $name;
    }
}

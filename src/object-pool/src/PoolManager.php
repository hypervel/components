<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Hyperf\Coordinator\Timer;
use Hypervel\ObjectPool\Contracts\TimeRecycleStrategy;
use Psr\Container\ContainerInterface;
use RuntimeException;

class PoolManager
{
    /**
     * Registered object pools managed by this manager.
     *
     * @var ObjectPool[]
     */
    protected array $pools = [];

    /**
     * Timer instance for scheduling recycle operations.
     */
    protected ?Timer $timer = null;

    /**
     * ID of the current timer for recycling.
     */
    protected ?int $timerId = null;

    /**
     * The interval between automatic recycle checks in seconds.
     */
    protected float $recycleInterval;

    /**
     * Creates a new pool manager with the given configuration.
     */
    public function __construct(protected ContainerInterface $container, array $config = [])
    {
        $this->recycleInterval = $config['recycle_interval'] ?? 10;
    }

    /**
     * Gets a managed pool by name.
     */
    public function getPool(string $name): ObjectPool
    {
        return $this->pools[$name];
    }

    /**
     * Creates and registers a new object pool.
     */
    public function createPool(string $name, callable $callback, array $options = []): ObjectPool
    {
        if (isset($this->pools[$name])) {
            throw new RuntimeException("The pool {$name} is already exists.");
        }

        if (isset($options['recycle_strategy']) && $options['recycle_strategy'] instanceof TimeRecycleStrategy) {
            $recycleTime = $options['recycle_strategy']->getRecycleTime();
            if ($recycleTime < $this->recycleInterval) {
                throw new RuntimeException(
                    'The recycle time in the strategy must be greater than the recycle interval.'
                );
            }
        }

        $pool = new SimpleObjectPool(
            $this->container,
            $callback,
            $options
        );

        return $this->pools[$name] = $pool;
    }

    /**
     * Returns all registered pools.
     */
    public function pools(): array
    {
        return $this->pools;
    }

    /**
     * Sets a pool to be managed by this manager.
     */
    public function setPool(string $name, ObjectPool $pool): static
    {
        $this->pools[$name] = $pool;

        return $this;
    }

    /**
     * Sets multiple pools to be managed by this manager.
     */
    public function setPools(array $pools): static
    {
        foreach ($pools as $name => $pool) {
            $this->setPool($name, $pool);
        }

        return $this;
    }

    /**
     * Check if a pool exists.
     */
    public function hasPool(string $name): bool
    {
        return isset($this->pools[$name]);
    }

    /**
     * Remove a pool from the manager.
     */
    public function removePool(string $name): static
    {
        unset($this->pools[$name]);

        return $this;
    }

    /**
     * Flush all pools.
     */
    public function flush(): static
    {
        $this->pools = [];

        return $this;
    }

    /**
     * Gets the timer instance for scheduling recycle operations.
     */
    public function getTimer(): Timer
    {
        if ($this->timer) {
            return $this->timer;
        }

        return $this->timer = new Timer();
    }

    /**
     * Sets the timer instance for scheduling recycle operations.
     */
    public function setTimer(Timer $timer): void
    {
        $this->timer = $timer;
    }

    /**
     * Gets the ID of the current timer for recycling.
     */
    public function getTimerId(): ?int
    {
        return $this->timerId;
    }

    /**
     * Starts automatic recycling of objects in managed pools.
     */
    public function startRecycle(): void
    {
        $this->timerId = $this->getTimer()->tick(
            $this->recycleInterval,
            fn () => $this->recycleObjects()
        );
    }

    /**
     * Stops automatic recycling of objects in managed pools.
     */
    public function stopRecycle(): void
    {
        if ($this->timerId) {
            $this->getTimer()->clear($this->timerId);
        }
        $this->timerId = null;
    }

    /**
     * Gets the timestamp of the last recycling operation for a specific pool.
     */
    public function getLastRecycledTimestamp(string $name): int
    {
        return $this->getPool($name)->getRecycleStrategy()->getLastRecycledTimestamp();
    }

    /**
     * Gets the timestamps of the last recycling operations for all managed pools.
     */
    public function getLastRecycledTimestamps(): array
    {
        $lastRecycledTimestamps = [];
        foreach ($this->pools() as $name => $pool) {
            $lastRecycledTimestamps[$name] = $this->getLastRecycledTimestamp($name);
        }

        return $lastRecycledTimestamps;
    }

    /**
     * Recycles objects in all managed pools that need recycling.
     */
    protected function recycleObjects(): void
    {
        foreach ($this->pools() as $pool) {
            $strategy = $pool->getRecycleStrategy();

            if ($strategy->shouldRecycle($pool)) {
                $strategy->recycle($pool);
            }
        }
    }
}

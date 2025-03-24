<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Hyperf\Coordinator\Timer;
use Hypervel\ObjectPool\Contracts\TimeRecycleStrategyContract;
use Psr\Container\ContainerInterface;
use RuntimeException;

class PoolManager
{
    /** @var ObjectPool[] */
    protected array $pools = [];

    /** @var int[] */
    protected array $lastRecycledTimestamps = [];

    protected ?Timer $timer = null;

    protected ?int $timerId = null;

    protected float $recycleInterval;

    protected float $recycleRatio;

    public function __construct(protected ContainerInterface $container, array $config = [])
    {
        $this->recycleInterval = $config['recycle_interval'] ?? 10;
        $this->recycleRatio = $config['recycle_ratio'] ?? 0.2;
    }

    public function getPool(string $name): ObjectPool
    {
        return $this->pools[$name];
    }

    public function createPool(string $name, callable $callback, array $options = []): ObjectPool
    {
        if (isset($this->pools[$name])) {
            throw new RuntimeException("The pool {$name} is already exists.");
        }

        if (isset($options['recycle_strategy']) && $options['recycle_strategy'] instanceof TimeRecycleStrategyContract) {
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

    public function pools(): array
    {
        return $this->pools;
    }

    public function setPool(string $name, ObjectPool $pool): static
    {
        $this->pools[$name] = $pool;

        return $this;
    }

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

    public function getTimer(): Timer
    {
        if ($this->timer) {
            return $this->timer;
        }

        return $this->timer = new Timer();
    }

    public function setTimer(Timer $timer): void
    {
        $this->timer = $timer;
    }

    public function getTimerId(): ?int
    {
        return $this->timerId;
    }

    public function startRecycle(): void
    {
        $this->timerId = $this->getTimer()->tick(
            $this->recycleInterval,
            fn () => $this->recycleObjects()
        );
    }

    public function stopRecycle(): void
    {
        if ($this->timerId) {
            $this->getTimer()->clear($this->timerId);
        }
        $this->timerId = null;
    }

    public function getLastRecycledTimestamps(): array
    {
        return $this->lastRecycledTimestamps;
    }

    protected function recycleObjects(): void
    {
        foreach ($this->pools() as $name => $pool) {
            $strategy = $pool->getOption()->getRecycleStrategy();

            $context = [
                'last_recycled_timestamp' => $this->lastRecycledTimestamps[$name] ?? 0,
            ];
            if ($strategy->shouldRecycle($pool, $context)) {
                $strategy->recycle($pool);

                if ($strategy instanceof TimeRecycleStrategyContract) {
                    $this->lastRecycledTimestamps[$name] = time();
                }
            }
        }
    }
}

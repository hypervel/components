<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Closure;
use DateTime;
use Hyperf\Contract\StdoutLoggerInterface;
use Hypervel\ObjectPool\Contracts\ObjectPool as ObjectPoolContract;
use Hypervel\ObjectPool\Contracts\RecycleStrategy;
use Hypervel\ObjectPool\Strategies\TimeStrategy;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

use function Hyperf\Support\make;

/**
 * @template T of object
 */
abstract class ObjectPool implements ObjectPoolContract
{
    /**
     * Channel for storing and retrieving objects.
     */
    protected Channel $channel;

    /**
     * Configuration options for the pool.
     */
    protected PoolOption $option;

    /**
     * Current number of objects managed by the pool.
     */
    protected int $currentObjectNumber = 0;

    /**
     * Creation timestamps for each object.
     */
    protected array $creationTimestamps = [];

    /**
     * Callback that is executed when an object is destroyed.
     */
    protected Closure $destroyCallback;

    /**
     * The recycle strategy instance used by the pool.
     */
    protected ?RecycleStrategy $recycleStrategy = null;

    /**
     * The last time the pool was recycled.
     */
    protected null|DateTime|int $lastRecycledAt = null;

    /**
     * Initialize the object pool with the given configuration.
     */
    public function __construct(
        protected ContainerInterface $container,
        array $config = []
    ) {
        $this->initOption($config);
        $this->destroyCallback = fn () => null;

        $this->channel = make(Channel::class, ['size' => $this->option->getMaxObjects()]);
    }

    /**
     * Retrieve an object from the pool.
     *
     * @return T
     */
    public function get(): object
    {
        $object = $this->getObject();

        if (! $this->option->getMaxLifetime()) {
            return $object;
        }

        // destroy and generate new object if exceeds maxLifetime
        if ($this->exceedsMaxLifetime($object)) {
            $this->destroyObject($object);

            return $this->getObject();
        }

        return $object;
    }

    /**
     * Release an object back to the pool.
     */
    public function release(object $object): void
    {
        $this->channel->push($object);
    }

    /**
     * Flush excess objects from the pool down to the minimum.
     */
    public function flush(): void
    {
        $number = $this->getObjectNumberInPool();
        if ($number <= 0) {
            return;
        }

        while ($this->currentObjectNumber > $this->option->getMinObjects() && $object = $this->channel->pop(0.001)) {
            $this->destroyObject($object);
            --$number;

            if ($number <= 0) {
                // Ignore objects queued during flushing.
                break;
            }
        }
    }

    /**
     * Flush a single object from the pool if it meets removal criteria.
     */
    public function flushOne(bool $force = false): void
    {
        if ($this->currentObjectNumber <= $this->option->getMinObjects()) {
            return;
        }

        if ($this->getObjectNumberInPool() <= 0
            || ! $object = $this->channel->pop(0.001)
        ) {
            return;
        }

        if ($force || $this->exceedsMaxLifetime($object)) {
            $this->destroyObject($object);

            return;
        }

        $this->release($object);
    }

    /**
     * Return the current number of objects managed by the pool.
     */
    public function getCurrentObjectNumber(): int
    {
        return $this->currentObjectNumber;
    }

    /**
     * Get the pool's configuration options.
     */
    public function getOption(): PoolOption
    {
        return $this->option;
    }

    /**
     * Return the number of objects currently available in the pool.
     */
    public function getObjectNumberInPool(): int
    {
        return $this->channel->length();
    }

    /**
     * Initialize the pool options from the provided configuration.
     */
    protected function initOption(array $options = []): void
    {
        $this->option = new PoolOption(
            minObjects: $options['min_objects'] ?? 1,
            maxObjects: $options['max_objects'] ?? 10,
            waitTimeout: $options['wait_timeout'] ?? 3.0,
            maxLifetime: $options['max_lifetime'] ?? 60.0,
            recycleRatio: $options['recycle_ratio'] ?? 0.2,
            recycleStrategy: $options['recycle_strategy'] ?? TimeStrategy::class,
        );
    }

    /**
     * Create a new object for the pool.
     *
     * @return T
     */
    abstract protected function createObject(): object;

    /**
     * Get an object from the pool or creates a new one if needed.
     *
     * @return T
     */
    protected function getObject(): object
    {
        $number = $this->getObjectNumberInPool();

        try {
            if ($number === 0 && $this->currentObjectNumber < $this->option->getMaxObjects()) {
                ++$this->currentObjectNumber;
                $object = $this->createObject();
                $this->creationTimestamps[spl_object_hash($object)] = microtime(true);

                return $object;
            }
        } catch (Throwable $throwable) {
            --$this->currentObjectNumber;
            throw $throwable;
        }

        $object = $this->channel->pop($this->option->getWaitTimeout());
        if (! is_object($object)) {
            throw new RuntimeException('Object pool exhausted. Cannot create new object before wait_timeout.');
        }

        return $object;
    }

    /**
     * Get the logger instance if available.
     */
    protected function getLogger(): ?StdoutLoggerInterface
    {
        if (! $this->container->has(StdoutLoggerInterface::class)) {
            return null;
        }

        return $this->container->get(StdoutLoggerInterface::class);
    }

    /**
     * Check if an object has exceeded its maximum lifetime.
     */
    protected function exceedsMaxLifetime(object $object): bool
    {
        if (! $this->option->getMaxLifetime()) {
            return false;
        }

        $creationTime = $this->creationTimestamps[spl_object_hash($object)];

        return $creationTime + $this->option->getMaxLifetime() <= microtime(true);
    }

    /**
     * Destroy an object and cleans up its resources.
     */
    protected function destroyObject(object $object): void
    {
        try {
            call_user_func($this->destroyCallback, $object);
        } catch (Throwable $exception) {
            if ($logger = $this->getLogger()) {
                $logger->error((string) $exception);
            }
        } finally {
            --$this->currentObjectNumber;
            unset($this->creationTimestamps[spl_object_hash($object)], $object);
        }
    }

    /**
     * Set a callback to be executed when an object is destroyed.
     */
    public function setDestroyCallback(Closure $callback): static
    {
        $this->destroyCallback = $callback;

        return $this;
    }

    /**
     * Return statistics about the pool's current state.
     */
    public function getStats(): array
    {
        return [
            'objects_count' => $this->currentObjectNumber,
            'objects_in_pool' => $this->getObjectNumberInPool(),
            'last_recycled_at' => $this->lastRecycledAt,
        ];
    }

    /**
     * Get the recycle strategy instance for this pool.
     */
    public function getRecycleStrategy(): RecycleStrategy
    {
        if ($this->recycleStrategy) {
            return $this->recycleStrategy;
        }

        return $this->recycleStrategy = make(
            $this->option->getStrategy(),
            ['pool' => $this]
        );
    }

    /**
     * Set the recycle strategy for this pool.
     */
    public function setRecycleStrategy(RecycleStrategy $recycleStrategy): static
    {
        $this->option->setStrategy(get_class($recycleStrategy));
        $this->recycleStrategy = $recycleStrategy;

        return $this;
    }

    /**
     * Get the last time the pool was recycled.
     */
    public function getLastRecycledAt(): null|DateTime|int
    {
        return $this->lastRecycledAt;
    }

    /**
     * Set the last time the pool was recycled.
     */
    public function setLastRecycledAt(null|DateTime|int $lastRecycledAt): static
    {
        $this->lastRecycledAt = $lastRecycledAt;

        return $this;
    }
}

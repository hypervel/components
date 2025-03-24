<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Closure;
use Hyperf\Contract\StdoutLoggerInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

use function Hyperf\Support\make;

/**
 * @template T of object
 */
abstract class ObjectPool implements ObjectPoolInterface
{
    protected Channel $channel;

    protected ObjectPoolOption $option;

    protected int $currentObjectNumber = 0;

    protected array $creationTimestamps = [];

    protected Closure $destroyCallback;

    public function __construct(
        protected ContainerInterface $container,
        array $config = []
    ) {
        $this->initOption($config);
        $this->destroyCallback = fn () => null;

        $this->channel = make(Channel::class, ['size' => $this->option->getMaxObjects()]);
    }

    /**
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

    public function release(object $object): void
    {
        $this->channel->push($object);
    }

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

    public function getCurrentObjectNumber(): int
    {
        return $this->currentObjectNumber;
    }

    public function getOption(): ObjectPoolOption
    {
        return $this->option;
    }

    public function getObjectNumberInPool(): int
    {
        return $this->channel->length();
    }

    protected function initOption(array $options = []): void
    {
        $this->option = new ObjectPoolOption(
            minObjects: $options['min_objects'] ?? 1,
            maxObjects: $options['max_objects'] ?? 10,
            waitTimeout: $options['wait_timeout'] ?? 3.0,
            maxLifetime: $options['max_lifetime'] ?? 60.0,
            recycleTime: $options['recycle_time'] ?? 10.0,
        );
    }

    /**
     * @return T
     */
    abstract protected function createObject(): object;

    /**
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

    protected function getLogger(): ?StdoutLoggerInterface
    {
        if (! $this->container->has(StdoutLoggerInterface::class)) {
            return null;
        }

        return $this->container->get(StdoutLoggerInterface::class);
    }

    protected function exceedsMaxLifetime(object $object): bool
    {
        if (! $this->option->getMaxLifetime()) {
            return false;
        }

        $creationTime = $this->creationTimestamps[spl_object_hash($object)];

        return $creationTime + $this->option->getMaxLifetime() <= microtime(true);
    }

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

    public function setDestroyCallback(Closure $callback): static
    {
        $this->destroyCallback = $callback;

        return $this;
    }

    public function getStats(): array
    {
        return [
            'current_objects' => $this->currentObjectNumber,
            'objects_in_pool' => $this->getObjectNumberInPool(),
        ];
    }
}

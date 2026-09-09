<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Closure;
use Hypervel\Contracts\ObjectPool\Factory;
use Hypervel\Contracts\ObjectPool\InvalidatesPool;
use Hypervel\Contracts\ObjectPool\ObjectPool;
use Throwable;

class PoolProxy implements InvalidatesPool
{
    /**
     * Create a proxy that resolves its current pool per operation.
     */
    public function __construct(
        protected PoolDefinition $definition,
        protected Closure $createCallback,
        protected Factory $pools,
        protected ?Closure $releaseCallback = null,
    ) {
    }

    /**
     * Resolve the current pool for this proxy's definition.
     */
    protected function pool(): ObjectPool
    {
        return $this->pools->getOrCreate($this->definition, $this->createCallback);
    }

    /**
     * Borrow an object under an exactly-once lease.
     */
    protected function lease(): Lease
    {
        $pool = $this->pool();
        $object = $pool->borrow();
        $lease = new Lease($pool, $object, $this->releaseCallback);

        try {
            $this->configureBorrowed($object);
        } catch (Throwable $exception) {
            $lease->discardAfterFailure($exception);
        }

        return $lease;
    }

    /**
     * Invoke a synchronous method on a borrowed object.
     */
    protected function invoke(string $method, array $arguments): mixed
    {
        $lease = $this->lease();

        try {
            $result = $lease->get()->{$method}(...$arguments);
        } catch (Throwable $operationException) {
            $lease->releaseAfterFailure($operationException);
        }

        $lease->release();

        return $result;
    }

    /**
     * Apply proxy-held state to a freshly borrowed object.
     */
    protected function configureBorrowed(object $object): void
    {
    }

    /**
     * Get this proxy's immutable pool definition.
     */
    public function getDefinition(): PoolDefinition
    {
        return $this->definition;
    }

    /**
     * Return the pool's fully qualified registry name.
     */
    public function getPoolName(): string
    {
        return $this->definition->identity;
    }

    /**
     * Remove and close this proxy's current shared pool.
     */
    public function invalidatePool(): bool
    {
        return $this->pools->purge($this->definition->identity);
    }
}

<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Closure;
use Hypervel\ObjectPool\Contracts\Factory;
use Hypervel\ObjectPool\Contracts\ObjectPool;
use Throwable;

class PoolProxy
{
    /**
     * Create a proxy that resolves its current pool per operation.
     */
    public function __construct(
        protected PoolDefinition $definition,
        protected Closure $resolver,
        protected Factory $pools,
        protected ?Closure $releaseCallback = null,
    ) {
    }

    /**
     * Resolve the current pool for this proxy's definition.
     */
    protected function pool(): ObjectPool
    {
        return $this->pools->getOrCreate($this->definition, $this->resolver);
    }

    /**
     * Borrow an object under an exactly-once lease.
     */
    protected function lease(): Lease
    {
        $pool = $this->pool();
        $object = $pool->get();

        try {
            $this->configureBorrowed($object);
        } catch (Throwable $exception) {
            try {
                $pool->discard($object);
            } catch (Throwable $discardException) {
                PoolErrorReporter::report($discardException);
            }

            throw $exception;
        }

        return new Lease($pool, $object, $this->releaseCallback);
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
            try {
                $lease->release();
            } catch (Throwable $finalizationException) {
                PoolErrorReporter::report($finalizationException);
            }

            throw $operationException;
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
     * Get this proxy's pool identity.
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
        return $this->pools->remove($this->definition->identity);
    }
}

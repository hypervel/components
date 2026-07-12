<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Closure;
use Hypervel\ObjectPool\Contracts\ObjectPool as ObjectPoolContract;
use RuntimeException;
use Throwable;

class Lease
{
    protected bool $finalized = false;

    /**
     * Create a lease for one checked-out object.
     *
     * @param null|Closure(object): void $releaseCallback
     */
    public function __construct(
        protected ObjectPoolContract $pool,
        protected object $object,
        protected ?Closure $releaseCallback = null,
    ) {
    }

    /**
     * Get the borrowed object.
     */
    public function get(): object
    {
        if ($this->finalized) {
            throw new RuntimeException('The pool lease has already been finalized.');
        }

        return $this->object;
    }

    /**
     * Run reset behavior and return the object to the pool.
     */
    public function release(): void
    {
        if ($this->finalized) {
            return;
        }

        $this->finalized = true;

        try {
            if ($this->releaseCallback !== null) {
                ($this->releaseCallback)($this->object);
            }
        } catch (Throwable $exception) {
            try {
                $this->pool->discard($this->object);
            } catch (Throwable $discardException) {
                PoolErrorReporter::report($discardException);
            }

            throw $exception;
        }

        $this->pool->release($this->object);
    }

    /**
     * Destroy the borrowed object instead of returning it to the pool.
     */
    public function discard(): void
    {
        if ($this->finalized) {
            return;
        }

        $this->finalized = true;
        $this->pool->discard($this->object);
    }

    /**
     * Finalize an abandoned lease without letting cleanup failures escape.
     */
    public function __destruct()
    {
        try {
            $this->release();
        } catch (Throwable $exception) {
            PoolErrorReporter::report($exception);
        }
    }
}

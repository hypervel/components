<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Closure;
use Hypervel\ObjectPool\Contracts\ObjectPool as ObjectPoolContract;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
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
        } catch (CanceledException $cancellation) {
            try {
                $this->pool->discard($this->object);
            } catch (CanceledException) {
            } catch (Throwable $exception) {
                PoolErrorReporter::report($exception);
            }

            throw $cancellation;
        } catch (Throwable $exception) {
            try {
                $this->pool->discard($this->object);
            } catch (CanceledException $cancellation) {
                throw $cancellation;
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
     * Release the object while preserving failure precedence.
     */
    public function releaseAfterFailure(Throwable $failure): never
    {
        try {
            $this->release();
        } catch (CanceledException $cancellation) {
            throw $failure instanceof CanceledException ? $failure : $cancellation;
        } catch (Throwable $exception) {
            PoolErrorReporter::report($exception);
        }

        throw $failure;
    }

    /**
     * Discard the object while preserving failure precedence.
     */
    public function discardAfterFailure(Throwable $failure): never
    {
        try {
            $this->discard();
        } catch (CanceledException $cancellation) {
            throw $failure instanceof CanceledException ? $failure : $cancellation;
        } catch (Throwable $exception) {
            PoolErrorReporter::report($exception);
        }

        throw $failure;
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

<?php

declare(strict_types=1);

namespace Hypervel\Scout\Console;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Exceptions\ChildCancellationException;
use Hypervel\Coroutine\WaitConcurrent;
use Hypervel\Database\ConnectionResolver;
use Throwable;

class ConcurrentImportRunner
{
    protected ?WaitConcurrent $concurrent;

    protected ?Throwable $failure = null;

    public function __construct(int $limit)
    {
        $this->concurrent = $limit === 0 ? null : new WaitConcurrent($limit);
    }

    /**
     * Run an import operation within the concurrency limit.
     */
    public function create(callable $operation): void
    {
        if ($this->concurrent === null) {
            $operation();

            return;
        }

        $this->throwIfFailed();

        $this->concurrent->fork(function () use ($operation): void {
            if ($this->failure !== null) {
                return;
            }

            try {
                try {
                    $operation();
                } finally {
                    // Return idle sessions before another child can take this concurrency slot.
                    ConnectionResolver::releaseIdleConnections();
                }
            } catch (Throwable $exception) {
                $this->failure ??= Coroutine::causedByCancellation($exception)
                    ? new ChildCancellationException(
                        'A child coroutine running a Scout import was canceled while its owner remained active.',
                        previous: $exception,
                    )
                    : $exception;
            }
        }, [ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY]);

        $this->throwIfFailed();
    }

    /**
     * Wait for every active import operation and rethrow the first failure.
     */
    public function wait(): void
    {
        if ($this->concurrent === null) {
            return;
        }

        try {
            // Keep the parent's session while producing chunks, but not while joining children.
            ConnectionResolver::releaseIdleConnections();
        } catch (Throwable $exception) {
            if (Coroutine::causedByCancellation($exception)) {
                $this->concurrent->cancel();
            } else {
                $this->concurrent->wait();
            }

            throw $exception;
        }

        $this->concurrent->wait();

        $this->throwIfFailed();
    }

    /**
     * Rethrow the first import failure.
     */
    protected function throwIfFailed(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}

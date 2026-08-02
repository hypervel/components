<?php

declare(strict_types=1);

namespace Hypervel\Scout\Console;

use Hypervel\Coroutine\WaitConcurrent;
use Throwable;

class ConcurrentImportRunner
{
    protected WaitConcurrent $concurrent;

    protected ?Throwable $failure = null;

    public function __construct(int $limit)
    {
        $this->concurrent = new WaitConcurrent($limit);
    }

    /**
     * Run an import operation within the concurrency limit.
     */
    public function create(callable $operation): void
    {
        $this->throwIfFailed();

        $this->concurrent->create(function () use ($operation): void {
            if ($this->failure !== null) {
                return;
            }

            try {
                $operation();
            } catch (Throwable $exception) {
                $this->failure ??= $exception;
            }
        });

        $this->throwIfFailed();
    }

    /**
     * Wait for every active import operation and rethrow the first failure.
     */
    public function wait(): void
    {
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

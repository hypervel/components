<?php

declare(strict_types=1);

namespace Hypervel\Coroutine;

/**
 * @method bool isFull()
 * @method bool isEmpty()
 */
class WaitConcurrent extends Concurrent
{
    protected WaitGroup $wg;

    public function __construct(
        protected int $limit,
    ) {
        parent::__construct($limit);
        $this->wg = new WaitGroup();
    }

    /**
     * Create a new coroutine with concurrency limiting and wait tracking.
     */
    public function create(callable $callable): void
    {
        $this->wg->add();

        $callable = function () use ($callable) {
            try {
                $callable();
            } finally {
                $this->wg->done();
            }
        };

        parent::create($callable);
    }

    /**
     * Wait for all coroutines to complete.
     *
     * @param float $timeout Timeout in seconds (-1 for unlimited)
     * @return bool True if all completed, false if timed out
     */
    public function wait(float $timeout = -1): bool
    {
        return $this->wg->wait($timeout);
    }
}

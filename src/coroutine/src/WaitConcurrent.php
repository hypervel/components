<?php

declare(strict_types=1);

namespace Hypervel\Coroutine;

use Closure;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * @method bool isFull()
 * @method bool isEmpty()
 */
class WaitConcurrent extends Concurrent
{
    protected WaitGroup $wg;

    /** @var array<int, true> */
    protected array $activeCoroutines = [];

    public function __construct(
        protected int $limit,
    ) {
        parent::__construct($limit);
        $this->wg = new WaitGroup;
    }

    /**
     * Create a new coroutine with concurrency limiting and wait tracking.
     */
    public function create(callable $callable): void
    {
        $this->startAndTrack($callable);
    }

    /**
     * Create a new coroutine with parent context propagation and wait tracking.
     *
     * @param array<string> $keys Context keys to copy (empty = all keys)
     */
    public function fork(callable $callable, array $keys = []): void
    {
        $this->startAndTrack($callable, $keys);
    }

    /**
     * Start a coroutine and track it through deferred cleanup.
     *
     * @param array<string>|false $copyContext
     */
    protected function startAndTrack(callable $callable, array|false $copyContext = false): void
    {
        $this->wg->add();
        $started = false;
        $wrapper = function (Closure $run) use (&$started): void {
            Coroutine::defer(fn () => $this->wg->done());

            $coroutineId = Coroutine::id();

            try {
                $started = true;
                $this->activeCoroutines[$coroutineId] = true;
                $run();
            } finally {
                unset($this->activeCoroutines[$coroutineId]);
            }
        };

        try {
            parent::start($callable, $copyContext, $wrapper);
        } catch (Throwable $exception) {
            if (! $started) {
                $this->wg->done();
            }

            if ($exception instanceof CanceledException) {
                $this->cancel();
            }

            throw $exception;
        }
    }

    /**
     * Wait for all coroutines to complete.
     *
     * @param float $timeout Timeout in seconds (-1 for unlimited)
     * @return bool True if all completed, false if timed out
     */
    public function wait(float $timeout = -1): bool
    {
        try {
            return $this->wg->wait($timeout);
        } catch (CanceledException $exception) {
            $this->cancel();
            throw $exception;
        }
    }

    /**
     * Cancel active coroutine bodies.
     *
     * Completed bodies running deferred cleanup are no longer active and are
     * not interrupted. Each call targets the bodies active at that time.
     */
    public function cancel(): void
    {
        foreach (array_keys($this->activeCoroutines) as $coroutineId) {
            if (isset($this->activeCoroutines[$coroutineId])) {
                EngineCoroutine::cancelById($coroutineId, throwException: true);
            }
        }
    }
}

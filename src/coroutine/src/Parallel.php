<?php

declare(strict_types=1);

namespace Hypervel\Coroutine;

use Closure;
use Hypervel\Coroutine\Exceptions\ChannelClosedException;
use Hypervel\Coroutine\Exceptions\ChildCancellationException;
use Hypervel\Coroutine\Exceptions\ParallelExecutionException;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Engine\Exceptions\RunningInNonCoroutineException;
use Swoole\Coroutine\CanceledException;
use Throwable;

use function sprintf;

class Parallel
{
    /**
     * @var array<array-key, callable>
     */
    protected array $callbacks = [];

    protected ?Channel $concurrentChannel = null;

    /**
     * @var array<array-key, mixed>
     */
    protected array $results = [];

    /**
     * @var array<array-key, Throwable>
     */
    protected array $throwables = [];

    /**
     * Create a new parallel executor.
     *
     * @param int $concurrent Maximum concurrent coroutines (0 = unlimited)
     * @param array<string>|bool $copyContext When set, parent coroutine context is copied to each child.
     *                                        false = fresh context (default), true or empty array = copy all keys, non-empty array = copy listed keys only.
     *                                        Objects stored directly in context are shared by reference by default. Values implementing
     *                                        Hypervel\Context\ReplicableContext are copied via replicate(), while values implementing
     *                                        Hypervel\Context\NonCopyableContext are omitted.
     */
    public function __construct(
        int $concurrent = 0,
        protected bool|array $copyContext = false,
    ) {
        if ($concurrent > 0) {
            $this->concurrentChannel = new Channel($concurrent);
        }
    }

    /**
     * Add a callback to be executed in parallel.
     */
    public function add(callable $callable, int|string|null $key = null): void
    {
        if (is_null($key)) {
            $this->callbacks[] = $callable;
        } else {
            $this->callbacks[$key] = $callable;
        }
    }

    /**
     * Execute all callbacks in parallel and wait for completion.
     *
     * @param bool $throw Whether to throw on errors
     * @return array The results keyed by callback key
     * @throws ParallelExecutionException When $throw is true and errors occurred
     * @throws RunningInNonCoroutineException When running in non-coroutine context
     */
    public function wait(bool $throw = true): array
    {
        if (! Coroutine::inCoroutine()) {
            throw new RunningInNonCoroutineException('Parallel execution requires an active coroutine.');
        }

        // Inspection reflects only a run that completed normally. Children from a canceled
        // run retain their local arrays and cannot publish into a later run.
        $this->results = [];
        $this->throwables = [];

        $results = [];
        $throwables = [];

        $waitGroup = new WaitGroup;
        $coroutineIds = [];
        /** @var array<int, true> $children */
        $children = [];
        $waitGroup->add(count($this->callbacks));

        try {
            foreach ($this->callbacks as $key => $callback) {
                $slotAcquired = false;
                $started = false;

                try {
                    if ($this->concurrentChannel) {
                        if (! $this->concurrentChannel->push(true)) {
                            if ($this->concurrentChannel->isCanceled()) {
                                throw new CanceledException('Waiting to start parallel work was canceled.');
                            }

                            throw new ChannelClosedException('The parallel concurrency channel is closed.');
                        }

                        $slotAcquired = true;
                    }

                    $results[$key] = null;
                    // Keep child exception traces from retaining this Parallel instance.
                    $childCallable = static function () use ($callback, $key, &$results, &$throwables): void {
                        try {
                            $results[$key] = $callback();
                        } catch (CanceledException $exception) {
                            $throwables[$key] = new ChildCancellationException(
                                'A child coroutine managed by Parallel was canceled while its owner remained active.',
                                previous: $exception,
                            );
                            unset($results[$key]);
                        } catch (Throwable $throwable) {
                            $throwables[$key] = $throwable;
                            unset($results[$key]);
                        }
                    };
                    $wrapper = function (Closure $run) use ($waitGroup, &$children, &$started): void {
                        $coroutineId = Coroutine::id();

                        try {
                            $started = true;
                            $children[$coroutineId] = true;
                            $run();
                        } finally {
                            unset($children[$coroutineId]);
                            $this->concurrentChannel?->pop();
                            $waitGroup->done();
                        }
                    };

                    if ($this->copyContext === false) {
                        $coroutineIds[] = Coroutine::createOwned($childCallable, $wrapper);
                    } else {
                        $coroutineIds[] = Coroutine::forkOwned($childCallable, $wrapper, is_array($this->copyContext) ? $this->copyContext : []);
                    }
                } catch (CanceledException $exception) {
                    if (! $started) {
                        if ($slotAcquired) {
                            $this->concurrentChannel?->pop();
                        }

                        $waitGroup->done();
                    }

                    throw $exception;
                } catch (Throwable $throwable) {
                    $throwables[$key] = $throwable;
                    unset($results[$key]);

                    // Once the child starts, its finally block exclusively owns both releases.
                    if (! $started) {
                        if ($slotAcquired) {
                            $this->concurrentChannel?->pop();
                        }

                        $waitGroup->done();
                    }
                }
            }

            $waitGroup->wait();

            // WaitGroup completion precedes the last child's physical teardown.
            if ($coroutineIds !== []) {
                $joined = Coroutine::join($coroutineIds);

                if (! $joined && EngineCoroutine::isCanceled()) {
                    throw new CanceledException('Waiting for parallel child coroutines was canceled.');
                }
            }
        } catch (CanceledException $exception) {
            $this->cancelChildren($children);
            throw $exception;
        }

        $this->results = $results;
        $this->throwables = $throwables;

        // Detach child exception traces from the published run aggregates.
        $results = [];
        $throwables = [];

        if ($throw && ($throwableCount = count($this->throwables)) > 0) {
            $message = 'Detecting ' . $throwableCount . ' throwable occurred during parallel execution:' . PHP_EOL . $this->formatThrowables($this->throwables);
            $executionException = new ParallelExecutionException($message);
            $executionException->setResults($this->results);
            $executionException->setThrowables($this->throwables);
            $this->results = [];
            $this->throwables = [];
            throw $executionException;
        }
        return $this->results;
    }

    /**
     * Get the number of registered callbacks.
     */
    public function count(): int
    {
        return count($this->callbacks);
    }

    /**
     * Get the throwables captured from the most recent wait() call, keyed by callback key.
     *
     * @return array<array-key, Throwable>
     */
    public function getThrowables(): array
    {
        return $this->throwables;
    }

    /**
     * Determine if the most recent wait() call captured any throwables.
     */
    public function hasFailures(): bool
    {
        return count($this->throwables) > 0;
    }

    /**
     * Get the number of throwables captured from the most recent wait() call.
     */
    public function failedCount(): int
    {
        return count($this->throwables);
    }

    /**
     * Clear all callbacks, results, and throwables.
     */
    public function clear(): void
    {
        $this->callbacks = [];
        $this->results = [];
        $this->throwables = [];
    }

    /**
     * Format throwables into a nice list.
     *
     * @param array<array-key, Throwable> $throwables
     */
    private function formatThrowables(array $throwables): string
    {
        $output = '';
        foreach ($throwables as $key => $value) {
            $output .= sprintf('(%s) %s: %s' . PHP_EOL . '%s' . PHP_EOL, $key, get_class($value), $value->getMessage(), $value->getTraceAsString());
        }
        return $output;
    }

    /**
     * Cancel every child that remains active.
     *
     * @param array<int, true> $children
     */
    private function cancelChildren(array &$children): void
    {
        // Throwing cancellation resumes a child synchronously, so each prior
        // cancellation may remove entries before the next child is inspected.
        foreach (array_keys($children) as $coroutineId) {
            if (isset($children[$coroutineId])) {
                EngineCoroutine::cancelById($coroutineId, throwException: true);
            }
        }
    }
}

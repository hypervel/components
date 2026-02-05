<?php

declare(strict_types=1);

namespace Hypervel\Coroutine;

use Hypervel\Coroutine\Exception\ParallelExecutionException;
use Hypervel\Engine\Channel;
use Throwable;

use function sprintf;

class Parallel
{
    /**
     * @var array<array-key, callable>
     */
    protected array $callbacks = [];

    protected ?Channel $concurrentChannel = null;

    protected array $results = [];

    /**
     * @var array<array-key, Throwable>
     */
    protected array $throwables = [];

    /**
     * Create a new parallel executor.
     *
     * @param int $concurrent Maximum concurrent coroutines (0 = unlimited)
     */
    public function __construct(int $concurrent = 0)
    {
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
     */
    public function wait(bool $throw = true): array
    {
        $wg = new WaitGroup();
        $wg->add(count($this->callbacks));
        foreach ($this->callbacks as $key => $callback) {
            $this->concurrentChannel && $this->concurrentChannel->push(true);
            $this->results[$key] = null;
            Coroutine::create(function () use ($callback, $key, $wg) {
                try {
                    $this->results[$key] = $callback();
                } catch (Throwable $throwable) {
                    $this->throwables[$key] = $throwable;
                    unset($this->results[$key]);
                } finally {
                    $this->concurrentChannel && $this->concurrentChannel->pop();
                    $wg->done();
                }
            });
        }
        $wg->wait();
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
}

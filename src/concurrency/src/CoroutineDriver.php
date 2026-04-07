<?php

declare(strict_types=1);

namespace Hypervel\Concurrency;

use Closure;
use Hypervel\Contracts\Concurrency\Driver;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\WaitGroup;
use Hypervel\Support\Arr;
use Hypervel\Support\Defer\DeferredCallback;
use Throwable;

use function Hypervel\Support\defer;

class CoroutineDriver implements Driver
{
    /**
     * Run the given tasks concurrently and return an array containing the results.
     *
     * Each task runs in its own coroutine with the parent's context propagated.
     * Results are keyed to match the input array. If any task throws, the
     * exception is re-thrown after all tasks complete.
     */
    public function run(Closure|array $tasks): array
    {
        $tasks = Arr::wrap($tasks);

        if (empty($tasks)) {
            return [];
        }

        $keys = array_keys($tasks);
        $results = [];
        $exceptions = [];
        $waitGroup = new WaitGroup(count($tasks));

        foreach ($tasks as $key => $task) {
            Coroutine::fork(function () use ($task, $key, &$results, &$exceptions, $waitGroup) {
                try {
                    $results[$key] = $task();
                } catch (Throwable $e) {
                    $exceptions[$key] = $e;
                } finally {
                    $waitGroup->done();
                }
            });
        }

        $waitGroup->wait();

        // Rethrow the first exception in input order.
        foreach ($keys as $key) {
            if (isset($exceptions[$key])) {
                throw $exceptions[$key];
            }
        }

        // Preserve the original key order.
        $ordered = [];
        foreach ($keys as $key) {
            $ordered[$key] = $results[$key];
        }

        return $ordered;
    }

    /**
     * Start the given tasks concurrently in the background after the current task has finished.
     *
     * Uses Hypervel's lifecycle-aware defer system so tasks execute at the
     * appropriate lifecycle point (after HTTP response, after command completion).
     * Each deferred task runs in its own coroutine with context propagated.
     */
    public function defer(Closure|array $tasks): DeferredCallback
    {
        $tasks = Arr::wrap($tasks);

        return defer(function () use ($tasks) {
            $this->run($tasks);
        });
    }
}

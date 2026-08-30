<?php

declare(strict_types=1);

namespace Hypervel\Concurrency;

use Carbon\CarbonInterval;
use Closure;
use Hypervel\Contracts\Concurrency\Driver;
use Hypervel\Coroutine\Parallel;
use Hypervel\Support\Arr;
use Hypervel\Support\Defer\DeferredCallback;

use function Hypervel\Support\defer;

class CoroutineDriver implements Driver
{
    /**
     * Run the given tasks concurrently and return an array containing the results.
     *
     * Each task runs in its own coroutine with the parent's context propagated.
     * Results are keyed to match the input array. If any task throws, the
     * exception is re-thrown after all tasks complete. The timeout argument is
     * accepted for driver compatibility and is not applied to coroutine tasks.
     */
    public function run(Closure|array $tasks, CarbonInterval|int|null $timeout = null): array
    {
        $tasks = Arr::wrap($tasks);

        if (empty($tasks)) {
            return [];
        }

        $keys = array_keys($tasks);
        $parallel = new Parallel(copyContext: true);

        foreach ($tasks as $key => $task) {
            $parallel->add($task, $key);
        }

        $results = $parallel->wait(false);
        $exceptions = $parallel->getThrowables();

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

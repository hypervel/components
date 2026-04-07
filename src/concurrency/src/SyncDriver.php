<?php

declare(strict_types=1);

namespace Hypervel\Concurrency;

use Closure;
use Hypervel\Contracts\Concurrency\Driver;
use Hypervel\Support\Collection;
use Hypervel\Support\Defer\DeferredCallback;

use function Hypervel\Support\defer;

class SyncDriver implements Driver
{
    /**
     * Run the given tasks sequentially and return an array containing the results.
     */
    public function run(Closure|array $tasks): array
    {
        return Collection::wrap($tasks)->map(
            fn ($task) => $task()
        )->all();
    }

    /**
     * Start the given tasks in the background after the current task has finished.
     */
    public function defer(Closure|array $tasks): DeferredCallback
    {
        return defer(fn () => Collection::wrap($tasks)->each(fn ($task) => $task()));
    }
}

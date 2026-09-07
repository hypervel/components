<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Closure;
use Hypervel\Coroutine\Coroutine;

class BackgroundQueue extends CoroutineQueue
{
    /**
     * The name of the default queue.
     */
    protected string $default = 'background';

    /**
     * Execute the given callback in a new coroutine.
     */
    protected function scheduleExecution(Closure $execution): void
    {
        Coroutine::create($execution);
    }
}

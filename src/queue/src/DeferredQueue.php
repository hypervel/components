<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Closure;
use Hypervel\Coroutine\Coroutine;

class DeferredQueue extends CoroutineQueue
{
    /**
     * The name of the default queue.
     */
    protected string $default = 'deferred';

    /**
     * Defer the given execution callback.
     */
    protected function scheduleExecution(Closure $execution): void
    {
        Coroutine::defer($execution);
    }
}

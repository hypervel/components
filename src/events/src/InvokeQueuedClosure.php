<?php

declare(strict_types=1);

namespace Hypervel\Events;

use Hypervel\Support\Collection;
use Laravel\SerializableClosure\SerializableClosure;
use Throwable;

class InvokeQueuedClosure
{
    /**
     * Handle the event.
     */
    public function handle(SerializableClosure $closure, array $arguments): void
    {
        call_user_func($closure->getClosure(), ...$arguments);
    }

    /**
     * Handle a job failure.
     */
    public function failed(SerializableClosure $closure, array $arguments, array $catchCallbacks, Throwable $exception): void
    {
        $arguments[] = $exception;

        (new Collection($catchCallbacks))->each->__invoke(...$arguments);
    }
}

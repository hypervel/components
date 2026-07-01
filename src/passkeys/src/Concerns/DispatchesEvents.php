<?php

declare(strict_types=1);

namespace Hypervel\Passkeys\Concerns;

use Closure;
use Hypervel\Contracts\Events\Dispatcher;

trait DispatchesEvents
{
    /**
     * Dispatch the given event if listeners are registered.
     */
    protected function dispatchIfListening(Dispatcher $events, string $eventClass, Closure $event): void
    {
        if ($events->hasListeners($eventClass)) {
            $events->dispatch($event());
        }
    }
}

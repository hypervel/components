<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Concerns;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;

trait DispatchesEvents
{
    /**
     * Dispatch the given event if listeners are registered.
     */
    protected function dispatchIfListening(string $eventClass, Closure $event): void
    {
        $events = Container::getInstance()->make(Dispatcher::class);

        if ($events->hasListeners($eventClass)) {
            $events->dispatch($event());
        }
    }
}

<?php

declare(strict_types=1);

namespace Hypervel\Event;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Event\Dispatcher;

/**
 * Dispatch an event and call the listeners.
 *
 * @template T of object
 *
 * @param T $event
 *
 * @return T
 */
function event(object $event)
{
    return Container::getInstance()
        ->make(Dispatcher::class)
        ->dispatch($event);
}

function queueable(Closure $closure): QueuedClosure
{
    return new QueuedClosure($closure);
}

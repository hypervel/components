<?php

declare(strict_types=1);

namespace Hypervel\Event;

use Closure;
use Hyperf\Context\ApplicationContext;
use Psr\EventDispatcher\EventDispatcherInterface;

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
    return ApplicationContext::getContainer()
        ->get(EventDispatcherInterface::class)
        ->dispatch($event);
}

function queueable(Closure $closure): QueuedClosure
{
    return new QueuedClosure($closure);
}

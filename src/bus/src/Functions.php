<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher;
use Hypervel\Queue\CallQueuedClosure;

/**
 * Dispatch a job to its appropriate handler.
 *
 * @param mixed $job
 * @return ($job is Closure ? PendingClosureDispatch : PendingDispatch)
 */
function dispatch($job): PendingClosureDispatch|PendingDispatch
{
    return $job instanceof Closure
        ? new PendingClosureDispatch(CallQueuedClosure::create($job))
        : new PendingDispatch($job);
}

/**
 * Dispatch a command to its appropriate handler in the current process.
 *
 * Queueable jobs will be dispatched to the "sync" queue.
 */
function dispatch_sync(mixed $job, mixed $handler = null): mixed
{
    return Container::getInstance()
        ->make(Dispatcher::class)
        ->dispatchSync($job, $handler);
}

<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Bus;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher;
use Hypervel\Queue\CallQueuedClosure;

trait DispatchesJobs
{
    /**
     * Dispatch a job to its appropriate handler.
     */
    protected function dispatch(mixed $job): mixed
    {
        return $job instanceof Closure
            ? new PendingClosureDispatch(CallQueuedClosure::create($job))
            : new PendingDispatch($job);
    }

    /**
     * Dispatch a job to its appropriate handler in the current process.
     *
     * Queueable jobs will be dispatched to the "sync" queue.
     */
    public function dispatchSync(mixed $job): mixed
    {
        return Container::getInstance()
            ->make(Dispatcher::class)
            ->dispatchSync($job);
    }
}

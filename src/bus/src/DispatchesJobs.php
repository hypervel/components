<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Closure;
use Hyperf\Context\ApplicationContext;
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
        return ApplicationContext::getContainer()
            ->get(Dispatcher::class)
            ->dispatchSync($job);
    }
}

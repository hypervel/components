<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Closure;
use Hypervel\Context\ApplicationContext;
use Hyperf\Support\Fluent;
use Hypervel\Contracts\Bus\Dispatcher;

use function Hyperf\Support\value;

trait Dispatchable
{
    /**
     * Dispatch the job with the given arguments.
     */
    public static function dispatch(mixed ...$arguments): PendingDispatch
    {
        return new PendingDispatch(new static(...$arguments));
    }

    /**
     * Dispatch the job with the given arguments if the given truth test passes.
     */
    public static function dispatchIf(bool|Closure $boolean, mixed ...$arguments): Fluent|PendingDispatch
    {
        if ($boolean instanceof Closure) {
            $dispatchable = new static(...$arguments);

            return value($boolean, $dispatchable)
                ? new PendingDispatch($dispatchable)
                : new Fluent();
        }

        return value($boolean)
            ? new PendingDispatch(new static(...$arguments))
            : new Fluent();
    }

    /**
     * Dispatch the job with the given arguments unless the given truth test passes.
     */
    public static function dispatchUnless(bool|Closure $boolean, mixed ...$arguments): Fluent|PendingDispatch
    {
        if ($boolean instanceof Closure) {
            $dispatchable = new static(...$arguments);

            return ! value($boolean, $dispatchable)
                ? new PendingDispatch($dispatchable)
                : new Fluent();
        }

        return ! value($boolean)
            ? new PendingDispatch(new static(...$arguments))
            : new Fluent();
    }

    /**
     * Dispatch a command to its appropriate handler in the current process.
     *
     * Queueable jobs will be dispatched to the "sync" queue.
     */
    public static function dispatchSync(mixed ...$arguments): mixed
    {
        return ApplicationContext::getContainer()
            ->get(Dispatcher::class)
            ->dispatchSync(new static(...$arguments));
    }

    /**
     * Dispatch a command to its appropriate handler after the current process.
     */
    public static function dispatchAfterResponse(mixed ...$arguments): mixed
    {
        return static::dispatch(...$arguments)->afterResponse();
    }

    /**
     * Set the jobs that should run if this job is successful.
     */
    public static function withChain(array $chain): PendingChain
    {
        return new PendingChain(static::class, $chain);
    }
}

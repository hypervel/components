<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Events;

use Hypervel\Broadcasting\PendingBroadcast;

trait Dispatchable
{
    /**
     * Dispatch the event with the given arguments.
     */
    public static function dispatch(): mixed
    {
        return event(new static(...func_get_args()));
    }

    /**
     * Dispatch the event with the given arguments if the given truth test passes.
     */
    public static function dispatchIf(bool $boolean, mixed ...$arguments): mixed
    {
        return $boolean ? event(new static(...$arguments)) : null;
    }

    /**
     * Dispatch the event with the given arguments unless the given truth test passes.
     */
    public static function dispatchUnless(bool $boolean, mixed ...$arguments): mixed
    {
        return $boolean ? null : event(new static(...$arguments));
    }

    /**
     * Broadcast the event with the given arguments.
     */
    public static function broadcast(): PendingBroadcast
    {
        return broadcast(new static(...func_get_args()));
    }
}

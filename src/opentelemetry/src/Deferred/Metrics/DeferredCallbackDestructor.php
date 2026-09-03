<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Deferred\Metrics;

/**
 * Detach deferred callbacks when their bound target is collected.
 *
 * @internal
 */
class DeferredCallbackDestructor
{
    /** @var array<int, DeferredObservableCallback> */
    protected array $callbacks = [];

    /**
     * Retain a callback until the target is collected.
     */
    public function add(DeferredObservableCallback $callback): void
    {
        $this->callbacks[spl_object_id($callback)] = $callback;
    }

    /**
     * Forget a manually detached callback.
     */
    public function forget(DeferredObservableCallback $callback): void
    {
        unset($this->callbacks[spl_object_id($callback)]);
    }

    /**
     * Determine whether any callbacks remain attached.
     */
    public function isEmpty(): bool
    {
        return $this->callbacks === [];
    }

    /**
     * Detach every callback retained for the collected target.
     */
    public function __destruct()
    {
        foreach ($this->callbacks as $callback) {
            $callback->detach();
        }
    }
}

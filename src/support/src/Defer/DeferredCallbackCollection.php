<?php

declare(strict_types=1);

namespace Hypervel\Support\Defer;

use ArrayAccess;
use Closure;
use Countable;

/**
 * @implements ArrayAccess<int, DeferredCallback>
 */
class DeferredCallbackCollection implements ArrayAccess, Countable
{
    /**
     * All of the deferred callbacks.
     *
     * @var array<int, DeferredCallback>
     */
    protected array $callbacks = [];

    /**
     * Get the first callback in the collection.
     */
    public function first(): DeferredCallback
    {
        $this->forgetDuplicates();

        return $this->callbacks[0];
    }

    /**
     * Invoke the deferred callbacks.
     */
    public function invoke(): void
    {
        $this->invokeWhen(fn (): bool => true);
    }

    /**
     * Invoke the deferred callbacks if the given truth test evaluates to true.
     */
    public function invokeWhen(?Closure $when = null): void
    {
        $when ??= fn (): bool => true;

        $this->forgetDuplicates();

        foreach ($this->callbacks as $index => $callback) {
            // Callbacks can remove or reindex pending entries. Match the live entry
            // and remove it before invocation so forgotten callbacks stay canceled
            // and running callbacks cannot be replayed.
            if (($this->callbacks[$index] ?? null) !== $callback) {
                $index = array_search($callback, $this->callbacks, true);

                if ($index === false) {
                    continue;
                }
            }

            unset($this->callbacks[$index]);

            if ($when($callback)) {
                rescue($callback);
            }
        }

        if (! empty($this->callbacks)) {
            $this->invokeWhen($when);
        }
    }

    /**
     * Remove any deferred callbacks with the given name.
     */
    public function forget(string $name): void
    {
        $kept = [];

        foreach ($this->callbacks as $callback) {
            if ($callback->name !== $name) {
                $kept[] = $callback;
            }
        }

        $this->callbacks = $kept;
    }

    /**
     * Remove any duplicate callbacks.
     */
    protected function forgetDuplicates(): static
    {
        // Callback names can change through objects already returned to callers,
        // so every read must check their current names.
        // Walk the reversed array so the last occurrence of each name wins,
        // then reverse the kept slice to restore original insertion order
        // among survivors. foreach-over-array_reverse tolerates sparse keys
        // left by prior offsetUnset() calls, which a count()-bounded for-loop
        // would not.
        $seen = [];
        $keptReversed = [];

        foreach (array_reverse($this->callbacks) as $callback) {
            if (isset($seen[$callback->name])) {
                continue;
            }

            $seen[$callback->name] = true;
            $keptReversed[] = $callback;
        }

        $this->callbacks = array_reverse($keptReversed);

        return $this;
    }

    /**
     * Determine if the collection has a callback with the given key.
     */
    public function offsetExists(mixed $offset): bool
    {
        $this->forgetDuplicates();

        return isset($this->callbacks[$offset]);
    }

    /**
     * Get the callback with the given key.
     */
    public function offsetGet(mixed $offset): ?DeferredCallback
    {
        $this->forgetDuplicates();

        return $this->callbacks[$offset] ?? null;
    }

    /**
     * Set the callback with the given key.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_null($offset)) {
            $this->callbacks[] = $value;
        } else {
            $this->callbacks[$offset] = $value;
        }
    }

    /**
     * Remove the callback with the given key from the collection.
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->forgetDuplicates();

        unset($this->callbacks[$offset]);
    }

    /**
     * Determine how many callbacks are in the collection.
     */
    public function count(): int
    {
        $this->forgetDuplicates();

        return count($this->callbacks);
    }
}

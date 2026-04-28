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
     * Whether the callbacks array may contain duplicates that need collapsing
     * on the next read. Set to true by offsetSet() (the only mutation that
     * can introduce a duplicate); cleared only by forgetDuplicates() after a
     * successful rebuild. forget() and offsetUnset() can only remove items,
     * so they cannot introduce duplicates and deliberately leave the flag
     * alone — see forget() for the reasoning.
     */
    protected bool $needsDedupe = false;

    /**
     * Get the first callback in the collection.
     */
    public function first(): DeferredCallback
    {
        $this->forgetDuplicates();

        return array_values($this->callbacks)[0];
    }

    /**
     * Invoke the deferred callbacks.
     */
    public function invoke(): void
    {
        $this->invokeWhen(fn () => true);
    }

    /**
     * Invoke the deferred callbacks if the given truth test evaluates to true.
     */
    public function invokeWhen(?Closure $when = null): void
    {
        $when ??= fn () => true;

        $this->forgetDuplicates();

        foreach ($this->callbacks as $index => $callback) {
            if ($when($callback)) {
                rescue($callback);
            }

            unset($this->callbacks[$index]);
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

        // Preserve $needsDedupe intentionally: forget() only removes items, so
        // if duplicates of other names were pending before this call they are
        // still pending. Clearing the flag here would cause the next read to
        // short-circuit and observe an undeduplicated view. Letting the flag
        // carry over is always correct — if it was already false, forget()
        // cannot introduce new duplicates, so it stays false.
    }

    /**
     * Remove any duplicate callbacks.
     */
    protected function forgetDuplicates(): static
    {
        if (! $this->needsDedupe) {
            return $this;
        }

        // Walk the reversed array so the LAST occurrence of each name wins,
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
        $this->needsDedupe = false;

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

        $this->needsDedupe = true;
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

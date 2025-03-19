<?php

declare(strict_types=1);

namespace Hypervel\Process;

use ArrayAccess;
use Hyperf\Collection\Collection;

class ProcessPoolResults implements ArrayAccess
{
    /**
     * Create a new process pool result set.
     *
     * @param array<int, ProcessResult> $results the results of the processes
     */
    public function __construct(protected array $results = [])
    {
    }

    /**
     * Determine if all of the processes in the pool were successful.
     */
    public function successful(): bool
    {
        return $this->collect()->every(fn ($p) => $p->successful());
    }

    /**
     * Determine if any of the processes in the pool failed.
     */
    public function failed(): bool
    {
        return ! $this->successful();
    }

    /**
     * Get the results as a collection.
     */
    public function collect(): Collection
    {
        return new Collection($this->results);
    }

    /**
     * Determine if the given array offset exists.
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->results[$offset]);
    }

    /**
     * Get the result at the given offset.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->results[$offset];
    }

    /**
     * Set the result at the given offset.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->results[$offset] = $value;
    }

    /**
     * Unset the result at the given offset.
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->results[$offset]);
    }
}

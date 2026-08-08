<?php

declare(strict_types=1);

namespace Hypervel\Jwt\Contracts;

interface StorageContract
{
    /**
     * Store an item for the given number of minutes.
     */
    public function add(string $key, mixed $value, int $minutes): bool;

    /**
     * Store an item indefinitely.
     */
    public function forever(string $key, mixed $value): bool;

    /**
     * Retrieve an item from storage.
     */
    public function get(string $key): mixed;

    /**
     * Remove an item from storage.
     */
    public function destroy(string $key): bool;

    /**
     * Remove all items from storage.
     */
    public function flush(): bool;
}

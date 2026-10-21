<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Util;

use Closure;
use Generator;
use InvalidArgumentException;

/**
 * @template T
 *
 * @internal
 */
class BoundedOrderedMap
{
    /**
     * @var array<string, T>
     */
    private array $items = [];

    /**
     * @var null|Closure(T): void
     */
    private readonly ?Closure $evictionCallback;

    /**
     * Create a bounded map with an optional eviction callback.
     *
     * @param null|callable(T): void $evictionCallback
     */
    public function __construct(private readonly int $capacity, ?callable $evictionCallback = null)
    {
        if ($capacity <= 0) {
            throw new InvalidArgumentException('BoundedOrderedMap capacity must be greater than 0.');
        }

        $this->evictionCallback = $evictionCallback !== null ? Closure::fromCallable($evictionCallback) : null;
    }

    /**
     * Store a value, evicting the oldest entry when necessary.
     *
     * @param T $value
     */
    public function set(string $key, mixed $value): void
    {
        if (array_key_exists($key, $this->items)) {
            $this->items[$key] = $value;

            return;
        }

        while (count($this->items) >= $this->capacity) {
            $this->evictOldest();
        }

        $this->items[$key] = $value;
    }

    /**
     * Return a value by key.
     *
     * @return null|T
     */
    public function get(string $key): mixed
    {
        return array_key_exists($key, $this->items) ? $this->items[$key] : null;
    }

    /**
     * Remove and return a value by key.
     *
     * @return null|T
     */
    public function pull(string $key): mixed
    {
        if (! array_key_exists($key, $this->items)) {
            return null;
        }

        $value = $this->items[$key];
        unset($this->items[$key]);

        return $value;
    }

    /**
     * Yield entries from newest to oldest.
     *
     * @return Generator<string, T>
     */
    public function newestFirst(): Generator
    {
        foreach (array_reverse($this->items, true) as $key => $value) {
            yield (string) $key => $value;
        }
    }

    /**
     * Remove the oldest entry and notify its owner.
     */
    private function evictOldest(): void
    {
        reset($this->items);
        $oldestKey = key($this->items);

        if ($oldestKey === null) {
            return;
        }

        $oldest = $this->items[$oldestKey];
        unset($this->items[$oldestKey]);

        if ($this->evictionCallback !== null) {
            ($this->evictionCallback)($oldest);
        }
    }
}

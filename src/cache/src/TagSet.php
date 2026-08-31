<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Closure;
use Hypervel\Contracts\Cache\Store;
use Swoole\Coroutine\CanceledException;
use Throwable;

abstract class TagSet
{
    /**
     * The cache store implementation.
     */
    protected Store $store;

    /**
     * The tag names.
     */
    protected array $names = [];

    /**
     * Create a new TagSet instance.
     */
    public function __construct(Store $store, array $names = [])
    {
        $this->store = $store;
        $this->names = $names;
    }

    /**
     * Reset all tags in the set.
     */
    abstract public function reset(): bool;

    /**
     * Flush all the tags in the set.
     */
    abstract public function flush(): bool;

    /**
     * Attempt an operation for every item.
     *
     * @template TItem
     *
     * @param iterable<TItem> $items
     * @param Closure(TItem): bool $operation
     */
    protected function attemptEach(iterable $items, Closure $operation): bool
    {
        $result = true;
        $exception = null;

        foreach ($items as $item) {
            try {
                if (! $operation($item)) {
                    $result = false;
                }
            } catch (CanceledException $throwable) {
                throw $throwable;
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($exception !== null) {
            throw $exception;
        }

        return $result;
    }

    /**
     * Get all of the tag names in the set.
     */
    public function getNames(): array
    {
        return $this->names;
    }
}

<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Contracts\Cache\Store;

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
    abstract public function reset(): void;

    /**
     * Flush all the tags in the set.
     */
    abstract public function flush(): void;

    /**
     * Get all of the tag names in the set.
     */
    public function getNames(): array
    {
        return $this->names;
    }
}

<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Contracts\Cache\Store;

/**
 * Any-mode tag set for the stack store.
 *
 * Delegates tag flushing to every taggable layer. Non-taggable layers
 * above the taggable region are not flushed; their entries expire within
 * their configured layer TTL.
 */
class StackTagSet extends TagSet
{
    /**
     * The cache store implementation.
     *
     * @var StackStore
     */
    protected Store $store;

    /**
     * Create a new StackTagSet instance.
     */
    public function __construct(StackStore $store, array $names = [])
    {
        parent::__construct($store, $names);
    }

    /**
     * Reset all tags in the set.
     */
    public function reset(): void
    {
        $this->flush();
    }

    /**
     * Flush all the tags in the set.
     */
    public function flush(): void
    {
        foreach ($this->store->taggableLayers() as $layer) {
            // Flush via tag sets so the stack-level tagged cache emits events once.
            $layer->tags($this->names)->getTags()->flush();
        }
    }
}

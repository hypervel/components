<?php

declare(strict_types=1);

namespace Hypervel\Cache\Events;

class CacheFlushFailed
{
    /**
     * The name of the cache store.
     */
    public ?string $storeName;

    /**
     * The tags that were assigned to the key.
     */
    public array $tags;

    /**
     * Create a new event instance.
     */
    public function __construct(?string $storeName, array $tags = [])
    {
        $this->storeName = $storeName;
        $this->tags = $tags;
    }

    /**
     * Set the tags for the cache event.
     */
    public function setTags(array $tags): static
    {
        $this->tags = $tags;

        return $this;
    }
}
<?php

declare(strict_types=1);

namespace Hypervel\Cache\Events;

abstract class CacheEvent
{
    /**
     * The key of the event.
     */
    public string $key;

    /**
     * The tags that were assigned to the key.
     */
    public array $tags;

    /**
     * Create a new event instance.
     */
    public function __construct(string $key, array $tags = [])
    {
        $this->key = $key;
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

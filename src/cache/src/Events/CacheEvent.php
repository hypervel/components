<?php

declare(strict_types=1);

namespace Hypervel\Cache\Events;

use UnitEnum;

use function Hypervel\Support\enum_value;

abstract class CacheEvent
{
    /**
     * The name of the cache store.
     */
    public ?string $storeName;

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
    public function __construct(?string $storeName, UnitEnum|string $key, array $tags = [])
    {
        $this->storeName = $storeName;
        $this->key = enum_value($key);
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

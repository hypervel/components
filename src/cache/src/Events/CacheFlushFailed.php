<?php

declare(strict_types=1);

namespace Hypervel\Cache\Events;

use Throwable;

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
     * The exception raised while flushing the cache, if one was thrown.
     */
    public ?Throwable $exception;

    /**
     * Create a new event instance.
     */
    public function __construct(?string $storeName, array $tags = [], ?Throwable $exception = null)
    {
        $this->storeName = $storeName;
        $this->tags = $tags;
        $this->exception = $exception;
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

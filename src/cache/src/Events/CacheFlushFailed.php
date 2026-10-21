<?php

declare(strict_types=1);

namespace Hypervel\Cache\Events;

use Throwable;

class CacheFlushFailed
{
    /**
     * Create a new event instance.
     *
     * @param null|Throwable $exception the exception raised while flushing the cache, if one was thrown
     */
    public function __construct(
        public ?string $storeName,
        public array $tags = [],
        public ?Throwable $exception = null,
    ) {
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

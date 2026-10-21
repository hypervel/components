<?php

declare(strict_types=1);

namespace Hypervel\Cache\Events;

class CacheFlushed
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public ?string $storeName,
        public array $tags = [],
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

<?php

declare(strict_types=1);

namespace Hypervel\Cache\Events;

class RetrievingManyKeys extends CacheEvent
{
    /**
     * The keys that are being retrieved.
     */
    public array $keys;

    /**
     * Create a new event instance.
     */
    public function __construct(?string $storeName, array $keys, array $tags = [])
    {
        parent::__construct($storeName, $keys[0] ?? '', $tags);

        $this->keys = $keys;
    }
}

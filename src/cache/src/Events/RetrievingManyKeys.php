<?php

declare(strict_types=1);

namespace Hypervel\Cache\Events;

class RetrievingManyKeys extends CacheEvent
{
    /**
     * The keys that are being retrieved.
     *
     * @var list<string>
     */
    public array $keys;

    /**
     * Create a new event instance.
     *
     * @param list<string> $keys
     */
    public function __construct(?string $storeName, array $keys, array $tags = [])
    {
        parent::__construct($storeName, $keys[0] ?? '', $tags);

        $this->keys = $keys;
    }
}
